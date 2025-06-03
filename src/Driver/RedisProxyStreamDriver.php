<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Efabrica\HermesExtension\Heartbeat\HermesProcess;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\MaxItemsTrait;
use Tomaj\Hermes\Driver\NotSupportedException;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\Driver\UnknownPriorityException;
use Tomaj\Hermes\MessageInterface;
use Tomaj\Hermes\MessageSerializer;
use Tomaj\Hermes\SerializeException;
use Tomaj\Hermes\Shutdown\ShutdownException;
use Tracy\Debugger;

final class RedisProxyStreamDriver implements DriverInterface, QueueAwareInterface, ForkableDriverInterface, MonitoredStreamInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use ForkableDriverTrait;
    use MonitoredStreamTrait;

    private const STREAM_CONSUMERS_GROUP = 'consumers';
    private const MESSAGE_ID_PATTERN = '/[1-9]\d{9,12}-\d+$/';
    const MILLISECONDS_PER_SECOND = 1000;

    /** @var array<int, string> */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    /**
     * @throws NotSupportedException
     */
    public function __construct(RedisProxy $redis, string $key, string $monitorHashRedisKey, int $keepAliveTTL = 60, float $refreshInterval = 1)
    {
        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->initMonitoredStream($monitorHashRedisKey, Uuid::uuid4()->toString(), $keepAliveTTL);
        $this->serializer = new MessageSerializer();

        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);
    }

    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        if ($message->getExecuteAt() === null) {
            $key = $this->getKey($priority);
            $id = $this->redis->rawCommand(
                'XADD',
                $key,
                '*',
                'body',
                $this->serializer->serialize($message),
            );
            return (bool)preg_match(self::MESSAGE_ID_PATTERN, $id);
        }

        $key = $this->getDelayedQueueKey($priority);
        try {
            $result = $this->redis->zadd(
                $key,
                (int)floor($message->getExecuteAt() * 1000000),
                $this->serializer->serialize($message),
            );
            return $result === 1;
        } catch (RedisProxyException $e) {
            return false;
        }
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        $this->queues[$priority] = $name;

        $this->setupStreamAndGroup($name);
    }

    /**
     * @throws ShutdownException
     * @throws SerializeException
     * @throws UnknownPriorityException
     */
    public function wait(Closure $callback, array $priorities): void
    {
        $accessor = HermesDriverAccessor::getInstance();
        $accessor->setDriver($this);

        $queues = $this->queues;
        krsort($queues);

        $this->prepareConsumer($priorities);

        try {
            while (true) {
                $this->updateEnvelopeStatus();
                $this->checkShutdown();
                $this->checkToBeKilled();
                if (!$this->shouldProcessNext()) {
                    break;
                }

                $this->processDelayedTasks($priorities);

                $envelope = $this->receiveMessage($queues, $priorities);
                if ($envelope === null) {
                    continue;
                }
                $this->ping(HermesProcess::STATUS_PROCESSING);
                $this->doForkProcess(
                    function () use ($callback, $envelope) {
                        $this->monitorEnvelopeCallback($callback, $envelope);
                    }
                );
                $this->incrementProcessedItems();
                $this->finishMessage($envelope);
            }
        } finally {
            $accessor->clearTransmissionInfo();
            $this->removeConsumer($priorities);
        }
    }

    /**
     * @param array<int, string> $queues
     * @param int[] $priorities
     * @throws SerializeException
     * @throws UnknownPriorityException
     */
    private function receiveMessage(array $queues, array $priorities): ?StreamMessageEnvelope
    {
        $activeQueues = [];
        foreach ($queues as $priority => $queue) {
            if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                continue;
            }
            $activeQueues[$priority] = $queue;
        }
        $streams = [];
        if ($this->refreshInterval > 0) {
            $streams = ['BLOCK', (int)ceil($this->refreshInterval * self::MILLISECONDS_PER_SECOND)];
        }
        $streams = [...$streams, 'STREAMS', ...$activeQueues, ...array_fill(0, count($activeQueues), '>')];

        $message = $this->redis->rawCommand(
            'XREADGROUP',
            'GROUP',
            self::STREAM_CONSUMERS_GROUP,
            $this->myIdentifier,
            'COUNT',
            1,
            ...$streams,
        );

        if (!is_array($message) || count($message) !== 1) {
            return null;
        }

        $message = $message[0];
        $currentStream = $message[0];
        $currentId = $message[1][0][0];
        $currentBody = $message[1][0][1][1];

        return new StreamMessageEnvelope(
            $currentStream,
            $currentId,
            self::STREAM_CONSUMERS_GROUP,
            $this->myIdentifier,
            $this->serializer->unserialize($currentBody),
            $this->keyToPriority($currentStream),
        );
    }

    private function finishMessage(StreamMessageEnvelope $envelope): void
    {
        $queue = $envelope->getQueue();
        $group = $envelope->getGroup();
        $id = $envelope->getId();

        try {
            $this->redis->rawCommand('XACK', $queue, $group, $id);
            $this->redis->rawCommand('XDEL', $queue, $id);
        } catch (\Throwable $exception) {
            Debugger::log($exception, Debugger::EXCEPTION);
        }
    }

    private function setupStreamAndGroup(string $key): void
    {
        $scriptFile = __DIR__ . '/../Scripts/initiateStreamAndGroup.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        $keys = [$key, self::STREAM_CONSUMERS_GROUP];

        [$result, $message] = $this->redis->rawCommand(
            'EVALSHA',
            $scriptSha,
            count($keys),
            ...$keys,
        );
    }

    /**
     * @param int[] $priorities
     */
    private function prepareConsumer(array $priorities): void
    {
        foreach ($this->queues as $priority => $queue) {
            if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                continue;
            }
            $result = $this->redis->rawCommand(
                'XGROUP',
                'CREATECONSUMER',
                $queue,
                self::STREAM_CONSUMERS_GROUP,
                $this->myIdentifier,
            );
            if ((int)$result === 0) {
                throw new \RuntimeException(
                    sprintf(
                        'Consumer %s already exists in stream %s and group %s!',
                        $this->myIdentifier,
                        $queue,
                        self::STREAM_CONSUMERS_GROUP,
                    ),
                );
            }
        }
    }

    /**
     * @param int[] $priorities
     */
    private function removeConsumer(array $priorities): void
    {
        $scriptFile = __DIR__ . '/../Scripts/deleteConsumer.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        foreach ($this->queues as $priority => $queue) {
            if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                continue;
            }
            try {
                $keys = [$queue, self::STREAM_CONSUMERS_GROUP, $this->myIdentifier];
                $this->redis->rawCommand(
                    'EVALSHA',
                    $scriptSha,
                    count($keys),
                    ...$keys,
                );
            } catch (\Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
            }
        }
    }

    /**
     * @param int[] $priorities
     */
    private function processDelayedTasks(array $priorities): void
    {
        $scriptFile = __DIR__ . '/../Scripts/delayedMessages.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        $time = (int)floor(microtime(true) * 1000000);

        foreach ($this->queues as $priority => $queue) {
            if (count($priorities) > 0 && !in_array($priority, $priorities, true)) {
                continue;
            }
            try {
                $keys = [$this->getDelayedQueueKey($priority), $this->getKey($priority)];
                $this->redis->rawCommand(
                    'EVALSHA',
                    $scriptSha,
                    count($keys),
                    ...$keys,
                    ...[$time],
                );
            } catch (\Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
            }
        }
    }

    private function getScriptSha(string $scriptFile): string
    {
        $scriptSha = sha1_file($scriptFile);

        $result = (bool)(int)$this->redis->rawCommand('SCRIPT', 'EXISTS', $scriptSha)[0];

        if (!$result) {
            $scriptSha = $this->redis->rawCommand('SCRIPT', 'LOAD', file_get_contents($scriptFile));
        }

        return $scriptSha;
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
     * @throws UnknownPriorityException
     */
    private function getDelayedQueueKey(int $priority): string
    {
        if (!isset($this->queues[$priority])) {
            throw new UnknownPriorityException("Unknown priority {$priority}");
        }
        return $this->queues[$priority] . '[delayed]';
    }

    /**
     * @throws UnknownPriorityException
     */
    private function keyToPriority(string $key): int
    {
        foreach ($this->queues as $priority => $queue) {
            if ($queue === $key) {
                return $priority;
            }
        }

        throw new UnknownPriorityException("Unknown queue {$key}, priority can't be determined");
    }
}