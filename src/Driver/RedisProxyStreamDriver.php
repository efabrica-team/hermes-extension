<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Driver\Interfaces\ForkableDriverInterface;
use Efabrica\HermesExtension\Driver\Interfaces\MonitoredStreamInterface;
use Efabrica\HermesExtension\Driver\Interfaces\QueueAwareInterface;
use Efabrica\HermesExtension\Driver\Traits\ForkableDriverTrait;
use Efabrica\HermesExtension\Driver\Traits\MonitoredStreamTrait;
use Efabrica\HermesExtension\Driver\Traits\QueueAwareTrait;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Efabrica\HermesExtension\Heartbeat\HermesProcess;
use Efabrica\HermesExtension\Helpers\RedisResponse;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use RuntimeException;
use Throwable;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\MaxItemsTrait;
use Tomaj\Hermes\Driver\NotSupportedException;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\Driver\UnknownPriorityException;
use Tomaj\Hermes\MessageInterface;
use Tomaj\Hermes\MessageSerializer;
use Tomaj\Hermes\Shutdown\ShutdownException;
use Tracy\Debugger;

/**
 * @phpstan-type StreamMessageResponse array<int, array{
 *     0: string,
 *     1: array<int, array{
 *         0: string,
 *         1: array<int, string|float|int|bool|null>|null,
 *     }>,
 * }>
 */
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
            /** @var string $id */
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
     * @throws UnknownPriorityException
     * @throws RedisProxyException
     */
    public function wait(Closure $callback, array $priorities): void
    {
        $accessor = HermesDriverAccessor::getInstance();
        $accessor->setDriver($this);

        $queues = $this->getFilteredQueues($priorities);

        $this->prepareConsumer($queues);

        try {
            if (count($queues) === 0) {
                return;
            }
            $this->ping(HermesProcess::STATUS_IDLE);
            while (true) {
                $this->updateEnvelopeStatus();
                $this->checkShutdown();
                $this->checkToBeKilled();
                if (!$this->shouldProcessNext()) {
                    break;
                }

                $this->doMonitoringTasks($queues, self::STREAM_CONSUMERS_GROUP);

                $this->processDelayedTasks($queues);

                $envelope = $this->receiveMessage($queues);
                if ($envelope !== null) {
                    $this->ping(HermesProcess::STATUS_PROCESSING);
                    $this->incrementProcessedItems();
                    try {
                        $this->doForkProcess(
                            function () use ($callback, $envelope) {
                                $this->monitorEnvelopeCallback($callback, $envelope);
                            }
                        );
                        $this->finishMessage($envelope);
                        $this->updateEnvelopeStatus();
                    } catch (ShutdownException $exception) {
                        $this->finishMessage($envelope);
                        $this->updateEnvelopeStatus();
                        throw $exception;
                    }
                    $this->ping(HermesProcess::STATUS_IDLE);
                    continue;
                }

                if ($this->refreshInterval) {
                    $this->checkShutdown();
                    $this->checkToBeKilled();
                    $this->ping(HermesProcess::STATUS_IDLE);
                    usleep(intval($this->refreshInterval) * 1000000);
                }
            }
        } catch (ShutdownException $exception) {
            $this->ping(HermesProcess::STATUS_KILLED);
            throw $exception;
        } finally {
            $accessor->clearTransmissionInfo();
            $this->removeConsumer($queues);
        }
    }

    /**
     * @param array<int, string> $queues
     * @throws UnknownPriorityException
     * @throws RedisProxyException
     */
    private function receiveMessage(array $queues): ?StreamMessageEnvelope
    {
        $envelope = $this->claimPendingMessage($queues, self::STREAM_CONSUMERS_GROUP);
        if ($envelope !== null) {
            return $envelope;
        }
        foreach ($queues as $queue) {
            $streams = [];
            $streams = [...$streams, 'STREAMS', $queue, '>'];

            /** @var StreamMessageResponse|null $message */
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
                continue;
            }

            $message = $message[0];
            $currentStream = $message[0];
            $currentId = $message[1][0][0];

            if ($message[1][0][1] === null) {
                // the message has no fields, it is malformed, delete it
                $this->redis->rawCommand('XACK', $currentStream, self::STREAM_CONSUMERS_GROUP, $currentId);
                $this->redis->rawCommand('XDEL', $currentStream, $currentId);
                return null;
            }

            $fields = RedisResponse::readRedisListResponseToArray($message[1][0][1]);

            if (!isset($fields['body'])) {
                // this message does not have a body, it is malformed, delete it
                $this->redis->rawCommand('XACK', $currentStream, self::STREAM_CONSUMERS_GROUP, $currentId);
                $this->redis->rawCommand('XDEL', $currentStream, $currentId);
                return null;
            }

            $currentBody = $fields['body'];

            try {
                $message = $this->serializer->unserialize($currentBody);
            } catch (Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
                $this->redis->rawCommand('XACK', $currentStream, self::STREAM_CONSUMERS_GROUP, $currentId);
                $this->redis->rawCommand('XDEL', $currentStream, $currentId);
                return null;
            }

            return new StreamMessageEnvelope(
                $currentStream,
                $currentId,
                self::STREAM_CONSUMERS_GROUP,
                $this->myIdentifier,
                $message,
                $this->keyToPriority($currentStream),
            );
        }

        return null;
    }

    private function finishMessage(StreamMessageEnvelope $envelope): void
    {
        $queue = $envelope->getQueue();
        $group = $envelope->getGroup();
        $id = $envelope->getId();

        try {
            $this->redis->rawCommand('XACK', $queue, $group, $id);
            $this->redis->rawCommand('XDEL', $queue, $id);
        } catch (Throwable $exception) {
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
     * @param array<int, string> $queues
     */
    private function prepareConsumer(array $queues): void
    {
        if (!$this->updateEnvelopeStatus(null, true)) {
            throw new RuntimeException(
                sprintf(
                    'Consumer %s already exists in driver monitor hash %s!',
                    $this->myIdentifier,
                    $this->monitorHashRedisKey,
                ),
            );
        }
        foreach ($queues as $queue) {
            $result = $this->redis->rawCommand(
                'XGROUP',
                'CREATECONSUMER',
                $queue,
                self::STREAM_CONSUMERS_GROUP,
                $this->myIdentifier,
            );
            if ((int)$result === 0) {
                throw new RuntimeException(
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
     * @param array<int, string> $queues
     */
    private function removeConsumer(array $queues): void
    {
        $scriptFile = __DIR__ . '/../Scripts/deleteConsumer.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        foreach ($queues as $queue) {
            try {
                $keys = [$queue, self::STREAM_CONSUMERS_GROUP, $this->myIdentifier];
                $this->redis->rawCommand(
                    'EVALSHA',
                    $scriptSha,
                    count($keys),
                    ...$keys,
                );
            } catch (Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
            }
        }
    }

    /**
     * @param array<int, string> $queues
     */
    private function processDelayedTasks(array $queues): void
    {
        $scriptFile = __DIR__ . '/../Scripts/delayedMessages.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        $time = (int)floor(microtime(true) * 1000000);

        foreach ($queues as $priority => $queue) {
            try {
                $keys = [$this->getDelayedQueueKey($priority), $queue];
                $this->redis->rawCommand(
                    'EVALSHA',
                    $scriptSha,
                    count($keys),
                    ...$keys,
                    ...[$time],
                );
            } catch (Throwable $exception) {
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

    /**
     * @param int[] $priorities
     * @return array<int, string>
     */
    private function getFilteredQueues(array $priorities): array
    {
        $queues = $this->queues;
        krsort($queues);

        if (count($priorities) === 0) {
            return $queues;
        }

        return array_filter(
            $queues,
            static fn (int $priority): bool => in_array($priority, $priorities, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
