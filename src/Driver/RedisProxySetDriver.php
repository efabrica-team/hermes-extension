<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Driver\Interfaces\ForkableDriverInterface;
use Efabrica\HermesExtension\Driver\Interfaces\QueueAwareInterface;
use Efabrica\HermesExtension\Driver\Traits\ForkableDriverTrait;
use Efabrica\HermesExtension\Driver\Traits\ProcessSignalTrait;
use Efabrica\HermesExtension\Driver\Traits\QueueAwareTrait;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Efabrica\HermesExtension\Heartbeat\HermesProcess;
use Efabrica\HermesExtension\Metrics\PrometheusMetrics;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use Throwable;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\MaxItemsTrait;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\Driver\UnknownPriorityException;
use Tomaj\Hermes\MessageInterface;
use Tomaj\Hermes\MessageSerializer;
use Tomaj\Hermes\SerializeException;
use Tomaj\Hermes\Shutdown\ShutdownException;
use function count;
use function in_array;
use function intval;
use function is_string;
use function krsort;
use function usleep;

final class RedisProxySetDriver implements DriverInterface, QueueAwareInterface, ForkableDriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use ProcessSignalTrait;
    use ForkableDriverTrait;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    private int $iterationToPing;

    protected ?PrometheusMetrics $prometheusMetrics = null;

    protected bool $prometheusInit = false;

    public function __construct(RedisProxy $redis, string $key, float $refreshInterval = 1, int $iterationToPing = 10)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->serializer = new MessageSerializer();
        $this->iterationToPing = $iterationToPing;
    }

    public function setPrometheusMetrics(PrometheusMetrics $prometheusMetrics): void
    {
        $this->prometheusMetrics = $prometheusMetrics;
    }

    /**
     * @throws RedisProxyException
     * @throws SerializeException
     * @throws UnknownPriorityException
     */
    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        $key = $this->getKey($priority);
        return (bool)$this->redis->sadd($key, $this->serializer->serialize($message));
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        $this->queues[$priority] = $name;
    }

    /**
     * @throws RedisProxyException
     * @throws SerializeException
     * @throws ShutdownException
     * @throws UnknownPriorityException
     */
    public function wait(Closure $callback, array $priorities = []): void
    {
        if (!$this->prometheusInit) {
            $this->prometheusInit = true;
            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStart();
            }
        }
        $this->handleSignals();
        $accessor = HermesDriverAccessor::getInstance();
        $accessor->setDriver($this);

        $queues = $this->queues;
        krsort($queues);
        $counter = 0;
        while (true) {
            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStep();
            }
            $this->checkShutdown();
            $this->checkToBeKilled();
            $counter++;
            if (!$this->shouldProcessNext()) {
                break;
            }

            $messageString = null;
            $foundPriority = null;

            foreach ($queues as $priority => $name) {
                if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                    continue;
                }

                $messageString = null;
                $foundPriority = null;

                if ($this->canContinue() && !$this->hasActiveChildFork()) {
                    $messageString = $this->pop($this->getKey($priority));
                    $foundPriority = $priority;
                }

                if ($messageString !== null) {
                    break;
                }
            }

            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStep();
            }

            if (!$this->canContinue() && $messageString === null) {
                break;
            }

            if ($messageString !== null) {
                if ($counter % $this->iterationToPing === 0) {
                    $this->ping(HermesProcess::STATUS_PROCESSING);
                    $counter = 0;
                }
                $message = $this->serializer->unserialize($messageString);
                if ($this->prometheusMetrics) {
                    $this->prometheusMetrics->startProcessingMessage($message->getType());
                }
                $accessor->setMessage($message, $foundPriority);
                $this->doForkProcess(
                    function () use ($callback, $message, $foundPriority) {
                        try {
                            $result = $callback($message, $foundPriority);
                        } catch (Throwable $exception) {
                            $result = false;
                            throw $exception;
                        } finally {
                            if ($this->prometheusMetrics) {
                                $this->prometheusMetrics->incrementMessageCounter(
                                    $message->getType(),
                                    $result
                                );
                                $this->prometheusMetrics->finishProcessingMessage($message->getType());
                            }
                        }
                    }
                );
                $accessor->clear();
                $this->incrementProcessedItems();
            } elseif ($this->refreshInterval) {
                $this->checkShutdown();
                $this->checkToBeKilled();
                if ($counter % $this->iterationToPing === 0) {
                    $this->ping(HermesProcess::STATUS_IDLE);
                    $counter = 0;
                }
                usleep(intval($this->refreshInterval * 1000000));
            }
        }
        if ($this->prometheusMetrics) {
            $this->prometheusMetrics->workerProcessStep();
        }
    }

    /**
     * @throws UnknownPriorityException
     */
    private function getKey(int $priority): string
    {
        if (!isset($this->queues[$priority])) {
            throw new UnknownPriorityException("Unknown priority {$priority}");
        }
        return $this->queues[$priority];
    }

    /**
     * @throws RedisProxyException
     */
    private function pop(string $key): ?string
    {
        $messageString = $this->redis->spop($key);
        if (is_string($messageString) && $messageString !== '') {
            return $messageString;
        }

        return null;
    }
}
