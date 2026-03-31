<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Driver\Interfaces\ForkableDriverInterface;
use Efabrica\HermesExtension\Driver\Interfaces\MessageReliabilityInterface;
use Efabrica\HermesExtension\Driver\Interfaces\QueueAwareInterface;
use Efabrica\HermesExtension\Driver\Traits\ForkableDriverTrait;
use Efabrica\HermesExtension\Driver\Traits\MessageReliabilityTrait;
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

final class RedisProxyListDriver implements DriverInterface, QueueAwareInterface, MessageReliabilityInterface, ForkableDriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use MessageReliabilityTrait;
    use ForkableDriverTrait;
    use ProcessSignalTrait;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    private bool $useTopPriorityFallback = false;

    protected ?PrometheusMetrics $prometheusMetrics = null;

    protected bool $prometheusInit = false;

    public function __construct(RedisProxy $redis, string $key, float $refreshInterval = 1)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->serializer = new MessageSerializer();
    }

    public function setUseTopPriorityFallback(bool $useTopPriorityFallback): void
    {
        $this->useTopPriorityFallback = $useTopPriorityFallback;
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
        return (bool)$this->redis->rpush($key, $this->serializer->serialize($message));
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
        try {
            while (true) {
                if ($this->prometheusMetrics) {
                    $this->prometheusMetrics->workerProcessStep();
                }
                $this->checkShutdown();
                $this->checkToBeKilled();
                if (!$this->shouldProcessNext()) {
                    break;
                }

                if ($this->canContinue()) {
                    $this->recoverMessages();
                }

                $foundPriority = null;

                $this->updateMessageStatus();

                foreach ($queues as $priority => $name) {
                    if (!$this->shouldProcessNext()) {
                        break 2;
                    }
                    if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                        continue;
                    }
                    $key = $this->getKey($priority);
                    $foundPriority = $priority;
                    while (true) {
                        if (!$this->shouldProcessNext()) {
                            break 3;
                        }
                        $this->updateMessageStatus();
                        $messageString = null;
                        if (!$this->hasActiveChildFork() && $this->canContinue()) {
                            $messageString = $this->pop($key);
                        }
                        if ($messageString === null) {
                            break;
                        }
                        $this->ping(HermesProcess::STATUS_PROCESSING);
                        if ($this->prometheusMetrics) {
                            $this->prometheusMetrics->workerProcessStep();
                        }
                        $message = $this->serializer->unserialize($messageString);
                        if ($this->prometheusMetrics) {
                            $this->prometheusMetrics->startProcessingMessage($message->getType());
                        }
                        $accessor->setMessage($message, $foundPriority);
                        $this->doForkProcess(
                            function () use ($callback, $message, $foundPriority) {
                                try {
                                    $result = $this->monitorMessageCallback($callback, $message, $foundPriority);
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
                        $this->incrementProcessedItems();

                        if ($this->canContinue()) {
                            $this->recoverMessages();
                        }

                        if ($this->useTopPriorityFallback) {
                            break 2;
                        }
                    }
                }

                $this->updateMessageStatus();

                if (!$this->canContinue()) {
                    break;
                }

                if ($this->refreshInterval) {
                    $this->checkShutdown();
                    $this->checkToBeKilled();
                    $this->ping(HermesProcess::STATUS_IDLE);
                    usleep(intval($this->refreshInterval * 1000000));
                }
            }
        } finally {
            $this->removeMessageStatus();
            $accessor->clear();
            if ($this->prometheusMetrics) {
                $this->prometheusMetrics->workerProcessStep();
            }
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
        $messageString = $this->redis->lpop($key);
        if (is_string($messageString) && $messageString !== '') {
            return $messageString;
        }

        return null;
    }
}
