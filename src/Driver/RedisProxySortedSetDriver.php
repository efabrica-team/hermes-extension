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

final class RedisProxySortedSetDriver implements DriverInterface, QueueAwareInterface, ForkableDriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use ProcessSignalTrait;
    use ForkableDriverTrait;

    private const int FLOAT_TO_MICROSECONDS = 1000000;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    private ?string $scheduleKey;

    private int $scheduleBatchSize;

    public function __construct(RedisProxy $redis, string $key, ?string $scheduleKey = null, float $refreshInterval = 1, int $scheduleBatchSize = 100)
    {
        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);

        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->scheduleKey = $scheduleKey;
        $this->scheduleBatchSize = max(1, $scheduleBatchSize);
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
            if ($this->scheduleKey === null) {
                throw new InvalidArgumentException('Schedule key is not configured');
            }
            $this->redis->zadd($this->scheduleKey, $message->getExecuteAt(), $this->serializer->serialize($message));
        } else {
            $key = $this->getKey($priority);
            $this->redis->zadd($key, $message->getExecuteAt() ?? microtime(true), $this->serializer->serialize($message));
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
        $this->handleSignals();
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

            // check schedule
            if ($this->scheduleKey !== null) {
                if (!$this->canContinue()) {
                    break;
                }
                $this->moveDueScheduledMessages($this->scheduleKey);
            }

            $messageString = null;
            $foundPriority = null;

            foreach ($queues as $priority => $name) {
                if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
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

            if ($messageString === null && !$this->canContinue()) {
                break;
            }

            if ($messageString !== null) {
                $this->ping(HermesProcess::STATUS_PROCESSING);
                $message = $this->serializer->unserialize($messageString);
                $accessor->setMessage($message, $foundPriority);
                $this->doForkProcess(
                    static function () use ($callback, $message, $foundPriority) {
                        $callback($message, $foundPriority);
                    }
                );
                $accessor->clear();
                $this->incrementProcessedItems();
            } elseif ($this->refreshInterval > 0) {
                $this->checkShutdown();
                $this->checkToBeKilled();
                $this->ping(HermesProcess::STATUS_IDLE);
                usleep((int)($this->refreshInterval * self::FLOAT_TO_MICROSECONDS));
            }
        }
    }

    /**
     * Moves up to scheduleBatchSize due messages from schedule to the queue.
     * Only the worker whose ZREM succeeds moves the message, so not-yet-due messages are never popped
     * and concurrent workers don't duplicate them.
     *
     * @throws SerializeException
     * @throws UnknownPriorityException
     * @throws RedisProxyException
     */
    private function moveDueScheduledMessages(string $scheduleKey): void
    {
        /** @var array<int, string> $messageStrings */
        $messageStrings = $this->redis->zrangebyscore($scheduleKey, '-inf', (string) microtime(true), ['limit' => [0, $this->scheduleBatchSize]]);
        foreach ($messageStrings as $messageString) {
            if ($this->redis->zrem($scheduleKey, $messageString) !== 1) {
                continue;
            }
            $this->send($this->serializer->unserialize($messageString));
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
