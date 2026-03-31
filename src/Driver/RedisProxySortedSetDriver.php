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
use InvalidArgumentException;
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
use function intval;
use function is_string;
use function krsort;
use function usleep;

final class RedisProxySortedSetDriver implements DriverInterface, QueueAwareInterface, ForkableDriverInterface
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

    private ?string $scheduleKey;

    protected ?PrometheusMetrics $prometheusMetrics = null;

    protected bool $prometheusInit = false;

    public function __construct(RedisProxy $redis, string $key, ?string $scheduleKey = null, float $refreshInterval = 1)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->scheduleKey = $scheduleKey;
        $this->serializer = new MessageSerializer();
    }

    public function setPrometheusMetrics(PrometheusMetrics $prometheusMetrics): void
    {
        $this->prometheusMetrics = $prometheusMetrics;
    }

    /**
     * @throws SerializeException
     * @throws UnknownPriorityException
     * @throws RedisProxyException
     */
    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        if ($message->getExecuteAt() !== null && $message->getExecuteAt() > microtime(true)) {
            if (!$this->scheduleKey) {
                throw new InvalidArgumentException('Schedule key is not configured');
            }
            $this->redis->zadd($this->scheduleKey, $message->getExecuteAt(), $this->serializer->serialize($message));
        } else {
            $key = $this->getKey($priority);
            $this->redis->zadd($key, $message->getExecuteAt() === null ? microtime(true) : $message->getExecuteAt(), $this->serializer->serialize($message));
        }
        return true;
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        $this->queues[$priority] = $name;
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
     * @throws ShutdownException
     * @throws UnknownPriorityException
     * @throws SerializeException
     * @throws RedisProxyException
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
        while (true) {
            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStep();
            }
            $this->checkShutdown();
            $this->checkToBeKilled();
            if (!$this->shouldProcessNext()) {
                break;
            }

            // check schedule
            if ($this->scheduleKey) {
                $microTime = microtime(true);
                $messageStrings = $this->redis->zrangebyscore($this->scheduleKey, '-inf', (string) $microTime, ['limit' => [0, 1]]);
                for ($i = 1; $i <= count($messageStrings); $i++) {
                    if (!$this->canContinue()) {
                        break 2;
                    }

                    $messageString = $this->pop($this->scheduleKey);
                    if (!$messageString) {
                        break;
                    }
                    $scheduledMessage = $this->serializer->unserialize($messageString);
                    $this->send($scheduledMessage);

                    if ($scheduledMessage->getExecuteAt() > $microTime) {
                        break;
                    }
                }
            }

            $messageString = null;
            $foundPriority = null;

            foreach ($queues as $priority => $name) {
                if (count($priorities) > 0 && !in_array($priority, $priorities)) {
                    continue;
                }
                if ($messageString !== null) {
                    break;
                }

                if ($this->canContinue() && !$this->hasActiveChildFork()) {
                    $messageString = $this->pop($this->getKey($priority));
                    $foundPriority = $priority;
                }
            }
            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStep();
            }

            if (!$this->canContinue() && $messageString === null) {
                break;
            }

            if ($messageString !== null) {
                $this->ping(HermesProcess::STATUS_PROCESSING);
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
                $this->ping(HermesProcess::STATUS_IDLE);
                usleep(intval($this->refreshInterval * 1000000));
            }
        }
        if ($this->prometheusMetrics) {
            $this->prometheusMetrics->workerProcessStep();
        }
    }

    private function pop(string $key): ?string
    {
        $messageArray = $this->redis->zpopmin($key);
        foreach ($messageArray as $messageString => $score) {
            if (is_string($messageString) && $messageString !== '') {
                return $messageString;
            }
            return null;
        }
        return null;
    }
}
