<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Efabrica\HermesExtension\Heartbeat\HermesProcess;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
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

final class RedisProxyListDriver implements DriverInterface, QueueAwareInterface, MessageReliabilityInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use MessageReliabilityTrait;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    public function __construct(RedisProxy $redis, string $key, float $refreshInterval = 1)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->serializer = new MessageSerializer();
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
        $accessor = HermesDriverAccessor::getInstance();
        $accessor->setDriver($this);
        $queues = $this->queues;
        krsort($queues);
        while (true) {
            $this->checkShutdown();
            $this->checkToBeKilled();
            if (!$this->shouldProcessNext()) {
                break;
            }

            $this->recoverMessages();

            $messageString = null;
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
                    $messageString = $this->pop($key);
                    if ($messageString === null) {
                        break;
                    }
                    $this->ping(HermesProcess::STATUS_PROCESSING);
                    $message = $this->serializer->unserialize($messageString);
                    $accessor->setMessageInfo($message, $foundPriority);
                    try {
                        $this->monitorCallback($callback, $message, $foundPriority);
                    } finally {
                        $accessor->clearMessageInfo();
                    }
                    $this->incrementProcessedItems();
                    $this->recoverMessages();
                }
            }

            $this->updateMessageStatus();

            if ($this->refreshInterval) {
                $this->checkShutdown();
                $this->checkToBeKilled();
                $this->ping(HermesProcess::STATUS_IDLE);
                usleep(intval($this->refreshInterval * 1000000));
            }
        }
        $this->removeMessageStatus();
        $accessor->clearMessageInfo();
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
