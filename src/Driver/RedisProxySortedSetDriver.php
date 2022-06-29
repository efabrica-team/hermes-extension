<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use InvalidArgumentException;
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

final class RedisProxySortedSetDriver implements DriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private int $refreshInterval;

    private ?string $scheduleKey;

    public function __construct(RedisProxy $redis, string $key, ?string $scheduleKey = null, int $refreshInterval = 1)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->scheduleKey = $scheduleKey;
        $this->serializer = new MessageSerializer();
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
        $queues = $this->queues;
        krsort($queues);
        while (true) {
            $this->checkShutdown();
            if (!$this->shouldProcessNext()) {
                break;
            }

            // check schedule
            if ($this->scheduleKey) {
                $microTime = microtime(true);
                $messageStrings = $this->redis->zrangebyscore($this->scheduleKey, '-inf', (string) $microTime, ['limit' => [0, 1]]);
                for ($i = 1; $i <= count($messageStrings); $i++) {
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

                $messageString = $this->pop($this->getKey($priority));
                $foundPriority = $priority;
            }

            if ($messageString !== null) {
                $message = $this->serializer->unserialize($messageString);
                $callback($message, $foundPriority);
                $this->incrementProcessedItems();
            } else {
                if ($this->refreshInterval) {
                    $this->checkShutdown();
                    sleep($this->refreshInterval);
                }
            }
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
