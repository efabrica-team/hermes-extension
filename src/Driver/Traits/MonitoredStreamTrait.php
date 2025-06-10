<?php

namespace Efabrica\HermesExtension\Driver\Traits;

use Closure;
use Efabrica\HermesExtension\Driver\HermesDriverAccessor;
use Efabrica\HermesExtension\Driver\Interfaces\MonitoredStreamInterface;
use Efabrica\HermesExtension\Helpers\RedisResponse;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use RuntimeException;
use Throwable;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tracy\Debugger;

/**
 * @phpstan-type Monitor array{
 *     timestamp: float,
 *     message: null|array{
 *         id: string,
 *         type: string,
 *         payload: mixed,
 *         execute_at: null|float,
 *         retries: int,
 *         created: float,
 *     },
 *     priority: null|int,
 *     id: null|string,
 *     stream: null|string,
 *     group: null|string,
 *     consumer: null|string,
 *     identity: string,
 * }
 * @phpstan-type XCLAIMResponse array<int, array{
 *     0: string,
 *     1: array<int, string>,
 * }>
 */
trait MonitoredStreamTrait
{
    use SerializerAwareTrait;
    use MessageMultiprocessingTrait;

    private string $monitorHashRedisKey;

    private string $myIdentifier;

    private RedisProxy $redis;

    private int $keepAliveTTL = 60;

    private int $maximumMessageClaims = MonitoredStreamInterface::XCLAIM_RETRIES;

    /**
     * @var int|string
     */
    private $myPID = 0;

    /**
     * Sets how many times the message can be claimed when is stuck in pending state.
     * Setting this to <em>0</em> causes all messages to be executed only once and throw away
     * in case of driver crash or force-stop. Value must be non-negative. Defaults to 3 re-claims.
     * @inheritDoc
     */
    public function setMaximumXClaims(int $value = MonitoredStreamInterface::XCLAIM_RETRIES): void
    {
        if ($value < 0) {
            throw new RuntimeException('Maximum message XCLAIMs cannot be negative!');
        }
        $this->maximumMessageClaims = $value;
    }

    private function initMonitoredStream(
        string $monitorHashRedisKey,
        string $myIdentifier,
        int $keepAliveTTL = 60
    ): void {
        $this->myIdentifier = $myIdentifier;
        $this->monitorHashRedisKey = $monitorHashRedisKey;
        $this->keepAliveTTL = $keepAliveTTL;
        $this->myPID = getmypid() !== false ? getmypid() : 'unknown';
    }

    private function monitorEnvelopeCallback(Closure $callback, StreamMessageEnvelope $envelope): void
    {
        $accessor = HermesDriverAccessor::getInstance();

        $accessor->setEnvelope($envelope);
        $accessor->setProcessingStatus();
        try {
            $this->updateEnvelopeStatus($envelope);
            $processMessage = function () use ($callback, $envelope) {
                $callback($envelope->getMessage(), $envelope->getPriority());
            };
            $notify = function () use ($envelope) {
                $this->updateEnvelopeStatus($envelope);
            };
            $this->commonMainProcess($processMessage, $notify, $processMessage);
            $this->updateEnvelopeStatus();
        } finally {
            $accessor->setProcessingStatus();
            $accessor->clear();
        }
    }

    /**
     * Writes data about currently processed envelope
     */
    public function updateEnvelopeStatus(?StreamMessageEnvelope $envelope = null, bool $onlyIfNotExists = false): bool
    {
        $this->checkWriteAccess();

        $key = $this->getMyKey();
        $agentKey = $this->getAgentKey($key);

        if ($onlyIfNotExists && $this->redis->hexists($this->monitorHashRedisKey, $key)) {
            return false;
        }

        $status = (object)[
            'timestamp' => microtime(true),
            'message' => $envelope === null
                ? null
                : [
                    'id' => $envelope->getMessage()->getId(),
                    'type' => $envelope->getMessage()->getType(),
                    'payload' => $envelope->getMessage()->getPayload(),
                    'execute_at' => $envelope->getMessage()->getExecuteAt(),
                    'retries' => $envelope->getMessage()->getRetries(),
                    'created' => $envelope->getMessage()->getCreated(),
                ],
            'priority' => $envelope === null ? null : $envelope->getPriority(),
            'id' => $envelope === null ? null : $envelope->getId(),
            'stream' => $envelope === null ? null : $envelope->getQueue(),
            'group' => $envelope === null ? null : $envelope->getGroup(),
            'consumer' => $envelope === null ? null : $envelope->getConsumer(),
            'identity' => $this->myIdentifier,
        ];

        try {
            $encoded = json_encode($status);
            if ($encoded !== false) {
                $this->redis->hset($this->monitorHashRedisKey, $key, $encoded);
                $this->redis->setex($agentKey, $this->keepAliveTTL, (string)$status->timestamp);
            }
        } catch (Throwable $exception) {
            return false;
        }
        return true;
    }

    /**
     * Write status and percentage of completeness of processing message into redis.
     */
    public function updateEnvelopeProcessingStatus(?string $status = null, ?float $percent = null): void
    {
        $this->checkWriteAccess();

        $key = $this->getMyKey();
        $statusKey = $this->getStatusKey($key);

        if ($status === null) {
            try {
                $this->redis->del($statusKey);
            } catch (Throwable $exception) {
            }
        } else {
            if ($percent !== null) {
                $percent = round($percent, 2);
            }
            try {
                $body = json_encode([
                    'status' => $status,
                    'percent' => $percent === null ? null : max(min($percent, 100.0), 0.0),
                    'timestamp' => microtime(true),
                ]);
                if ($body !== false) {
                    $this->redis->set($statusKey, $body);
                }
            } catch (Throwable $exception) {
            }
        }
    }

    /**
     * @param array<int, string> $queues
     * @throws RedisProxyException
     */
    private function claimPendingMessage(array $queues, string $group): ?StreamMessageEnvelope
    {
        $lockKey = sprintf(
            '%s:lockClaiming',
            $this->monitorHashRedisKey,
        );

        $locked = (bool)$this->redis->rawCommand(
            'SET',
            $lockKey,
            $this->myIdentifier,
            'NX',
            'EX',
            MonitoredStreamInterface::LOCK_TTL,
        );

        if ($locked) {
            try {
                $monitoredConsumers = $this->readMonitorTable();
                $queuedConsumers = $this->getConsumersOfPendingMessages($queues, $group);
                foreach ($queues as $priority => $queue) {
                    foreach ($queuedConsumers[$queue] ?? [] as $consumerData) {
                        $consumer = $consumerData['consumer'];
                        $pending = $consumerData['pending'];
                        $monitorData = $monitoredConsumers[$consumer] ?? null;

                        if ($pending === 0 || $monitorData === null || $monitorData['agent'] === true) {
                            continue;
                        }

                        $id = null;
                        $stream = null;

                        if (isset($monitoredConsumers[$consumer])) {
                            $id = $monitoredConsumers[$consumer]['body']['id'] ?? null;
                            $stream = $monitoredConsumers[$consumer]['body']['stream'] ?? null;
                        }

                        $pendingMessages = $this->getConsumerPendingList($queue, $group, $consumer, $pending + 10, $id);
                        $foundId = false;

                        foreach ($pendingMessages as $pendingMessage) {
                            $messageId = $pendingMessage['id'];
                            $messageDelivered = $pendingMessage['delivered'];
                            if ($id !== null && $stream === $queue && $messageId === $id
                                && $messageDelivered <= $this->maximumMessageClaims
                            ) {
                                $foundId = true;
                                continue;
                            }
                            $this->redis->rawCommand('XACK', $queue, $group, $messageId);
                            $this->redis->rawCommand('XDEL', $queue, $messageId);
                        }

                        $claimedMessage = null;

                        if ($foundId && $id !== null) {
                            /** @var XCLAIMResponse $claimedMessage */
                            $claimedMessage = $this->redis->rawCommand(
                                'XCLAIM',
                                $queue,
                                $group,
                                $this->myIdentifier,
                                0,
                                $id,
                            );
                            $claimedMessage = count($claimedMessage) === 0 ? null : $claimedMessage[0];
                        }

                        $this->redis->rawCommand('XGROUP', 'DELCONSUMER', $queue, $group, $consumer);
                        $this->redis->hdel($this->monitorHashRedisKey, $monitorData['field']);
                        $statusKey = $this->getStatusKey($monitorData['field']);
                        $this->redis->del($statusKey);

                        if ($claimedMessage !== null) {
                            $this->updateEnvelopeStatus();
                            $messageId = $claimedMessage[0];
                            $fields = RedisResponse::readRedisListResponseToArray($claimedMessage[1]);
                            if (!isset($fields['body']) || !is_string($fields['body'])) {
                                // this message is malformed, does not have a proper body, delete it
                                $this->redis->rawCommand('XACK', $queue, $group, $messageId);
                                $this->redis->rawCommand('XDEL', $queue, $messageId);
                                continue;
                            }
                            try {
                                $messageBody = $this->serializer->unserialize($fields['body']);
                            } catch (Throwable $exception) {
                                // body has not a correct message form, delete it
                                Debugger::log($exception, Debugger::EXCEPTION);
                                $this->redis->rawCommand('XACK', $queue, $group, $messageId);
                                $this->redis->rawCommand('XDEL', $queue, $messageId);
                                continue;
                            }

                            return new StreamMessageEnvelope(
                                $queue,
                                $messageId,
                                $group,
                                $this->myIdentifier,
                                $messageBody,
                                $priority,
                            );
                        }
                    }
                }
            } finally {
                try {
                    $this->redis->del($lockKey);
                } catch (Throwable $e) {
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $queues
     * @throws RedisProxyException
     */
    private function doMonitoringTasks(array $queues, string $group): void
    {
        $lockKey = sprintf(
            '%s:lockMonitoring',
            $this->monitorHashRedisKey,
        );

        $locked = (bool)$this->redis->rawCommand(
            'SET',
            $lockKey,
            $this->myIdentifier,
            'NX',
            'EX',
            MonitoredStreamInterface::LOCK_TTL,
        );

        if ($locked) {
            try {
                $allConsumers = $this->getAllRegisteredConsumers($queues, $group);
                $monitoredConsumers = $this->readMonitorTable();
                foreach ($queues as $queue) {
                    $consumers = $allConsumers[$queue] ?? [];
                    foreach ($consumers as $consumerUUID => $consumerData) {
                        $monitorData = $monitoredConsumers[$consumerUUID] ?? null;
                        if ($monitorData === null) {
                            $pendingMessages = $this->getConsumerPendingList(
                                $queue,
                                $group,
                                $consumerUUID,
                                $consumerData['pending'] + 10,
                            );
                            foreach ($pendingMessages as $pendingMessage) {
                                $messageId = $pendingMessage['id'];
                                $this->redis->rawCommand('XACK', $queue, $group, $messageId);
                                $this->redis->rawCommand('XDEL', $queue, $messageId);
                            }
                            $this->redis->rawCommand(
                                'XGROUP',
                                'DELCONSUMER',
                                $queue,
                                $group,
                                $consumerUUID,
                            );
                            continue;
                        }
                        if ($monitorData['agent'] === true) {
                            $id = null;
                            $stream = null;

                            if (isset($monitoredConsumers[$consumerUUID])) {
                                $id = $monitoredConsumers[$consumerUUID]['body']['id'] ?? null;
                                $stream = $monitoredConsumers[$consumerUUID]['body']['stream'] ?? null;
                            }

                            $pendingMessages = $this->getConsumerPendingList(
                                $queue,
                                $group,
                                $consumerUUID,
                                $consumerData['pending'] + 10,
                                $id,
                            );

                            if (count($pendingMessages) > 1) {
                                $hasMonitoredMessage = false;
                                foreach ($pendingMessages as $pendingMessage) {
                                    if ($pendingMessage['id'] === $id && $stream === $queue) {
                                        $hasMonitoredMessage = true;
                                    }
                                }
                                unset($pendingMessage);
                                if ($hasMonitoredMessage) {
                                    foreach ($pendingMessages as $pendingMessage) {
                                        $messageId = $pendingMessage['id'];
                                        if ($messageId === $id && $stream === $queue) {
                                            continue;
                                        }
                                        $this->redis->rawCommand('XACK', $queue, $group, $messageId);
                                        $this->redis->rawCommand('XDEL', $queue, $messageId);
                                    }
                                }
                            }

                            continue;
                        }
                        if ($consumerData['pending'] > 0) {
                            continue;
                        }
                        $this->redis->rawCommand(
                            'XGROUP',
                            'DELCONSUMER',
                            $queue,
                            $group,
                            $consumerUUID,
                        );
                        $this->redis->hdel($this->monitorHashRedisKey, $monitorData['field']);
                        $statusKey = $this->getStatusKey($monitorData['field']);
                        $this->redis->del($statusKey);
                    }
                }
                foreach ($monitoredConsumers as $consumerUUID => $monitorData) {
                    $consumerFound = false;
                    foreach ($queues as $queue) {
                        if (isset($allConsumers[$queue][$consumerUUID])) {
                            $consumerFound = true;
                            break;
                        }
                    }
                    if ($consumerFound) {
                        continue;
                    }
                    $message = $monitorData['body']['message'] ?? null;
                    $priority = $monitorData['body']['priority'] ?? null;
                    if ($message !== null && $priority !== null) {
                        $encodedMessage = json_encode(['message' => $message]);
                        if ($encodedMessage !== false) {
                            try {
                                $message = $this->serializer->unserialize($encodedMessage);
                                $this->send($message, $priority);
                            } catch (Throwable $exception) {
                                Debugger::log($exception, Debugger::EXCEPTION);
                            }
                        }
                    }
                    $this->redis->hdel($this->monitorHashRedisKey, $monitorData['field']);
                    $statusKey = $this->getStatusKey($monitorData['field']);
                    $this->redis->del($statusKey);
                }
            } finally {
                try {
                    $this->redis->del($lockKey);
                } catch (Throwable $e) {
                }
            }
        }
    }

    /**
     * @internal Do not use this method outside trait!
     * @param array<int, string> $queues
     * @return array<string, array<array{consumer: string, pending: int}>>
     */
    private function getConsumersOfPendingMessages(array $queues, string $group): array
    {
        $output = [];
        foreach ($queues as $queue) {
            /** @var array{
             *     0: int|string,
             *     1: null|string,
             *     2: null|string,
             *     3: null|array<array{0: string, 1: int|string}>,
             * } $pending */
            $pending = $this->redis->rawCommand('XPENDING', $queue, $group);

            if ((int)$pending[0] === 0) {
                continue;
            }

            $consumers = $pending[3];

            foreach ($consumers ?? [] as $consumer) {
                $output[$queue][] = ['consumer' => $consumer[0], 'pending' => (int)$consumer[1]];
            }
        }
        return $output;
    }

    /**
     * @internal Do not use this method outside trait!
     * @return array<array{id: string, consumer: string, pending: int, delivered: int}>
     */
    private function getConsumerPendingList(
        string $queue,
        string $group,
        string $consumer,
        int $count,
        ?string $id = null
    ): array {
        /**
         * @var array<array{
         *     0: string,
         *     1: string,
         *     2: int|string,
         *     3: int|string,
         * }> $pending
         */
        $pending = $this->redis->rawCommand(
            'XPENDING',
            $queue,
            $group,
            '-',
            '+',
            $count,
            $consumer,
        );
        $output = [];
        foreach ($pending as $message) {
            $output[] = [
                'id' => $message[0],
                'consumer' => $message[1],
                'pending' => (int)$message[2],
                'delivered' => (int)$message[3],
            ];
        }
        if ($id !== null) {
            usort($output, function ($a, $b) use ($id) {
                if ($a['id'] === $id) {
                    return -1;
                }
                if ($b['id'] === $id) {
                    return 1;
                }
                return 0;
            });
        }
        return $output;
    }

    /**
     * @internal Do not use this method outside trait!
     * @return array<string, array{field: string, body: Monitor, agent: bool}>
     */
    private function readMonitorTable(): array
    {
        $table = [];
        $cursor = null;
        $pattern = '/^\[(?P<uuid>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]/i';

        do {
            try {
                /** @var array<string, string>|bool|null $fields */
                $fields = $this->redis->hscan($this->monitorHashRedisKey, $cursor, null, 1000);
                if (!is_array($fields)) {
                    continue;
                }
                foreach ($fields as $field => $value) {
                    if (!preg_match($pattern, $field, $matches)) {
                        continue;
                    }
                    $consumerUUID = $matches['uuid'];
                    $agentKey = $this->getAgentKey($field);
                    $agent = $this->redis->exists($agentKey);
                    /** @var Monitor $valueArray */
                    $valueArray = json_decode($value, true);
                    $table[$consumerUUID] = [
                        'field' => $field,
                        'body' => $valueArray,
                        'agent' => $agent,
                    ];
                }
            } catch (Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
            }
        } while ($cursor !== 0);

        return $table;
    }

    /**
     * @internal Do not use this method outside trait!
     * @param array<int, string> $queues
     * @return array<string, array<string, array{name: string, pending: int, idle: int|null, inactive: int}>>
     */
    private function getAllRegisteredConsumers(array $queues, string $group): array
    {
        $output = [];
        foreach ($queues as $queue) {
            /** @var array<array<int, string|int>> $consumers */
            $consumers = $this->redis->rawCommand(
                'XINFO',
                'CONSUMERS',
                $queue,
                $group,
            );

            foreach ($consumers as $consumer) {
                $parsedConsumer = RedisResponse::readRedisListResponseToArray($consumer);
                $consumerData = [
                    'name' => isset($parsedConsumer['name']) ? (string)$parsedConsumer['name'] : null,
                    'pending' => isset($parsedConsumer['pending']) ? (int)$parsedConsumer['pending'] : null,
                    'inactive' => isset($parsedConsumer['inactive']) ? (int)$parsedConsumer['inactive'] : null,
                    'idle' => isset($parsedConsumer['idle']) ? (int)$parsedConsumer['idle'] : null,
                ];
                if (!isset($parsedConsumer['inactive']) && isset($parsedConsumer['idle'])) {
                    // Redis < 7.2.0, idle is inactive
                    $consumerData['inactive'] = (int)$parsedConsumer['idle'];
                    $consumerData['idle'] = null;
                }
                if (!isset($consumerData['name']) || !isset($consumerData['pending']) || !isset($consumerData['inactive'])) {
                    continue;
                }
                $output[$queue][$consumerData['name']] = $consumerData;
            }
        }
        return $output;
    }

    /**
     * @internal Do not use this method outside trait!
     */
    private function getMyKey(): string
    {
        return sprintf(
            '[%s][%s][%s]',
            $this->myIdentifier,
            $this->myPID,
            gethostname() ?: 'unknown',
        );
    }

    /**
     * @internal Do not use this method outside trait!
     */
    private function getAgentKey(string $key): string
    {
        return $this->monitorHashRedisKey . ':agent' . $key;
    }

    /**
     * @internal Do not use this method outside trait!
     */
    private function getStatusKey(string $key): string
    {
        return $this->monitorHashRedisKey . ':status' . $key;
    }
}
