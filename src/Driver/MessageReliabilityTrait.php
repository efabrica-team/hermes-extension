<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use LogicException;
use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use Throwable;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\EmitterInterface;
use Tomaj\Hermes\Message;
use Tomaj\Hermes\MessageInterface;

trait MessageReliabilityTrait
{
    private RedisProxy $redis;

    private ?string $monitorHashRedisKey = null;

    private ?EmitterInterface $myEmitter = null;

    private ?string $myIdentifier = null;

    private ?int $keepAliveTTL = null;

    /**
     * @var int|string
     */
    private $myPID = 0;

    /**
     * Enables or disables (default) reliable messaging.
     *
     *
     */
    public function enableReliableMessageHandling(
        string $storagePrefix,
        EmitterInterface $emitter,
        int $keepAliveTTL
    ): void {
        $this->monitorHashRedisKey = $storagePrefix;
        $this->myEmitter = $emitter;
        $this->keepAliveTTL = max(60, $keepAliveTTL);
        $this->myIdentifier = Uuid::uuid4()->toString();
        $this->myPID = getmypid() !== false ? getmypid() : 'unknown';
    }

    /**
     * Calls the message processing callback in monitored mode if the reliable messaging is enabled,
     * will fall back to the non-monitored mode otherwise.
     */
    private function monitorCallback(Closure $callback, MessageInterface $message, int $foundPriority): void
    {
        $accessor = HermesDriverAccessor::getInstance();

        $accessor->setMessageInfo($message, $foundPriority);
        $accessor->setProcessingStatus();
        try {
            $this->updateMessageStatus($message, $foundPriority);
            if ($this->monitorHashRedisKey !== null && extension_loaded('pcntl')) {
                $flagFile = sys_get_temp_dir() . '/hermes_monitor_' . uniqid() . '-' . $this->myIdentifier . '.flag';
                @unlink($flagFile);

                $pid = pcntl_fork();

                if ($pid === -1) {
                    // ERROR, fallback to non-forked routine
                    $callback($message, $foundPriority);
                } elseif ($pid) {
                    // MAIN PROCESS
                    $this->forkMainProcess($callback, $message, $foundPriority, $pid, $flagFile);
                } else {
                    // CHILD PROCESS
                    $this->forkChildProcess($message, $foundPriority, $flagFile);
                }
            } else {
                $callback($message, $foundPriority);
            }
            $this->updateMessageStatus();
        } finally {
            $accessor->setProcessingStatus();
            $accessor->clearMessageInfo();
        }
    }

    /**
     * If reliable messaging is enabled, writes data about processed message into redis.
     */
    public function updateMessageStatus(?MessageInterface $message = null, ?int $priority = null): void
    {
        $this->checkWriteAccess();

        if ($this->monitorHashRedisKey === null) {
            return;
        }

        $key = $this->getMyKey();
        $agentKey = $this->getAgentKey($key);

        $status = (object)[
            'timestamp' => microtime(true),
            'message' => $message === null
                ? null
                : [
                    'id' => $message->getId(),
                    'type' => $message->getType(),
                    'payload' => $message->getPayload(),
                    'execute_at' => $message->getExecuteAt(),
                    'retries' => $message->getRetries(),
                    'created' => $message->getCreated(),
                ],
            'priority' => $priority,
        ];

        try {
            $encoded = json_encode($status);
            if ($encoded !== false && $this->monitorHashRedisKey !== null && $this->keepAliveTTL !== null) {
                $this->redis->hset($this->monitorHashRedisKey, $key, $encoded);
                $this->redis->setex($agentKey, $this->keepAliveTTL, (string)$status->timestamp);
            }
        } catch (Throwable $exception) {
        }
    }

    /**
     * If reliable messaging is enabled, this will write status and percentage of completeness of processed message
     * into redis.
     */
    public function updateMessageProcessingStatus(?string $status = null, ?float $percent = null): void
    {
        $this->checkWriteAccess();

        if ($this->monitorHashRedisKey === null) {
            return;
        }

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
     * If reliable messaging is enabled, this method will recover lost messages from stopped/killed workers.
     */
    private function recoverMessages(): void
    {
        if ($this->monitorHashRedisKey === null) {
            return;
        }

        if (!$this->myEmitter || !$this->myIdentifier) {
            return;
        }

        $lockKey = sprintf(
            '%s:lock',
            $this->monitorHashRedisKey,
        );

        if ($this->redis->setex($lockKey, MessageReliabilityInterface::LOCK_TTL, $this->myIdentifier)) {
            try {
                $start = hrtime(true);

                $cursor = null;

                do {
                    try {
                        /** @var string[]|bool|null $items */
                        $items = $this->redis->hscan($this->monitorHashRedisKey, $cursor, null, 1000);
                        if (!is_array($items)) {
                            break;
                        }
                        foreach ($items as $field => $value) {
                            $agentKey = $this->getAgentKey($field);
                            $statusKey = $this->getStatusKey($field);
                            if ($this->redis->get($agentKey) !== null) {
                                continue;
                            }
                            /** @var array<string, mixed> $status */
                            $status = json_decode($value, true);
                            if (hrtime(true) - $start >= MessageReliabilityInterface::LOCK_TIME_DIFF_MAX) {
                                return;
                            }
                            $this->redis->hdel($this->monitorHashRedisKey, $field);
                            $this->redis->del($statusKey);
                            if (isset($status['message'])) {
                                /** @var array{
                                 *     type: string,
                                 *     payload: null|array<string, mixed>,
                                 *     id: null|string,
                                 *     created: null|float,
                                 *     execute_at: null|float,
                                 *     retries: int
                                 * } $message
                                 */
                                $message = $status['message'];
                                /** @var int $priority */
                                $priority = $status['priority'] ?? Dispatcher::DEFAULT_PRIORITY;
                                $newMessage = new Message(
                                    $message['type'],
                                    $message['payload'],
                                    $message['id'],
                                    $message['created'],
                                    $message['execute_at'],
                                    $message['retries'],
                                );
                                if (!isset($this->queues[$priority])) {
                                    $priority = Dispatcher::DEFAULT_PRIORITY;
                                }
                                for ($retry = 0; $retry <= MessageReliabilityInterface::REQUEUE_REPEATS; $retry++) {
                                    try {
                                        $this->myEmitter->emit($newMessage, $priority);
                                        break;
                                    } catch (Throwable $exception) {
                                        if ($retry === MessageReliabilityInterface::REQUEUE_REPEATS) {
                                            break;
                                        }
                                        usleep(100000);
                                    }
                                }
                            }
                        }
                    } catch (Throwable $exception) {
                    }
                } while (hrtime(true) - $start < MessageReliabilityInterface::LOCK_TIME_DIFF_MAX && $cursor !== 0);
            } finally {
                try {
                    $this->redis->del($lockKey);
                } catch (Throwable $exception) {
                }
            }
        }
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

    /**
     * Remove all worker data from monitor if and only if the stored message in monitor is `null`.
     *
     * @internal Do not use this method outside trait!
     */
    private function removeMessageStatus(): void
    {
        try {
            if ($this->monitorHashRedisKey === null) {
                return;
            }

            $key = $this->getMyKey();
            $agentKey = $this->getAgentKey($key);
            $statusKey = $this->getStatusKey($key);

            $data = $this->redis->hget($this->monitorHashRedisKey, $key);

            if ($data !== null) {
                /** @var array<string, mixed> $decodedData */
                $decodedData = json_decode($data, true);
                if (isset($decodedData['message'])) {
                    // We leave unprocessed message in monitor
                    return;
                }
            }

            $this->redis->hdel($this->monitorHashRedisKey, $key);
            $this->redis->del($agentKey);
            $this->redis->del($statusKey);
        } catch (Throwable $exception) {
            // Just stop all exceptions
        }
    }

    /**
     * Processes message in main process context.
     *
     * @internal Do not use this method outside trait!
     */
    private function forkMainProcess(
        callable $callback,
        MessageInterface $message,
        int $foundPriority,
        int $pid,
        string $flagFile
    ): void {
        try {
            $this->redis->resetConnectionPool();
            $callback($message, $foundPriority);
        } finally {
            file_put_contents($flagFile, 'DONE');

            pcntl_waitpid($pid, $status);
            @unlink($flagFile);
        }
    }

    /**
     * Periodically signaling message processing until terminated or until parent is stopped/killed.
     *
     * @internal Do not use this method outside trait!
     */
    private function forkChildProcess(MessageInterface $message, int $foundPriority, string $flagFile): void
    {
        $parentPid = posix_getppid();
        $this->redis->resetConnectionPool();

        while (true) {
            if (posix_getpgid($parentPid) === false) {
                exit(0); // Parent process is killed, so this ends too ...
            }

            $this->updateMessageStatus($message, $foundPriority);

            if (file_exists($flagFile)) {
                $content = file_get_contents($flagFile);
                if ($content === 'DONE') {
                    break;
                }
            }

            sleep(1);
        }

        exit(0);
    }

    /**
     * This tests the call stack if the caller is {@see DriverInterface}.
     *
     * @internal Do not use this method outside trait!
     */
    private function checkWriteAccess(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $trace[1]['class'] ?? '';

        if ($callerClass === '' || !is_a($callerClass, DriverInterface::class, true)) {
            throw new LogicException(sprintf(
                'Method updateMessageStatus can only be called from classes implementing "%s" or from "%s".',
                DriverInterface::class,
                HermesDriverAccessor::class
            ));
        }
    }
}
