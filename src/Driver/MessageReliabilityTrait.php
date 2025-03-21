<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use LogicException;
use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use RuntimeException;
use Throwable;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\EmitterInterface;
use Tomaj\Hermes\Message;
use Tomaj\Hermes\MessageInterface;

trait MessageReliabilityTrait
{
    private RedisProxy $redis;

    private ?string $currentMessageStoragePrefix = null;

    private ?EmitterInterface $myEmitter = null;

    private ?string $myIdentifier = null;

    private ?int $keepAliveTTL = null;

    /**
     * @var int|string
     */
    private $myPID = 0;

    public function enableReliableMessageHandling(
        string $storagePrefix,
        EmitterInterface $emitter,
        int $keepAliveTTL
    ): void {
        $this->currentMessageStoragePrefix = $storagePrefix;
        $this->myEmitter = $emitter;
        $this->keepAliveTTL = $keepAliveTTL;
        $this->myIdentifier = Uuid::uuid4()->toString();
        $this->myPID = getmypid() !== false ? getmypid() : 'unknown';
    }

    private function isReliableMessageHandlingEnabled(): bool
    {
        return $this->currentMessageStoragePrefix !== null;
    }

    private function getMyKey(): string
    {
        return sprintf(
            '[%s][%s][%s]',
            $this->myIdentifier,
            $this->myPID,
            gethostname() ?: 'unknown',
        );
    }

    private function getAgentKey(string $key): string
    {
        return $this->currentMessageStoragePrefix . ':agent' . $key;
    }

    public function updateMessageStatus(?MessageInterface $message = null, ?int $priority = null): void
    {
        $this->checkWriteAccess();

        if (!$this->isReliableMessageHandlingEnabled()) {
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
            if ($encoded !== false && $this->currentMessageStoragePrefix !== null && $this->keepAliveTTL !== null) {
                $this->redis->hset($this->currentMessageStoragePrefix, $key, $encoded);
                $this->redis->setex($agentKey, $this->keepAliveTTL, (string)$status->timestamp);
            }
        } catch (Throwable $exception) {
        }
    }

    private function monitorCallback(Closure $callback, MessageInterface $message, int $foundPriority): void
    {
        $this->updateMessageStatus($message, $foundPriority);
        if ($this->isReliableMessageHandlingEnabled() && extension_loaded('pcntl')) {
            $flagFile = sys_get_temp_dir() . '/hermes_monitor_' . uniqid() . '-' . $this->myIdentifier . '.flag';
            @unlink($flagFile);

            $signals = true;
            if (function_exists('pcntl_fork')) {
                $signals = false;
            }

            if ($signals) {
                $oldHandler = pcntl_signal_get_handler(SIGALRM);
                $async = pcntl_async_signals(true);
                pcntl_signal(SIGALRM, function () use ($message, $foundPriority) {
                    try {
                        $this->updateMessageStatus($message, $foundPriority);
                        pcntl_alarm(1);
                    } catch (Throwable $exception) {
                    }
                });
                try {
                    pcntl_alarm(1);
                    $this->updateMessageStatus($message, $foundPriority);
                    $callback($message, $foundPriority);
                } finally {
                    pcntl_alarm(0);
                    if (is_string($oldHandler) && function_exists($oldHandler)) {
                        pcntl_signal(SIGALRM, $oldHandler);
                    } elseif (is_int($oldHandler)) {
                        pcntl_signal(SIGALRM, $oldHandler);
                    } else {
                        pcntl_signal(SIGALRM, SIG_DFL);
                    }
                    pcntl_async_signals($async);
                }
            } else {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    // ERROR
                    $this->updateMessageStatus($message, $foundPriority);
                    throw new RuntimeException('Unable to fork');
                } elseif ($pid) {
                    // MAIN PROCESS
                    try {
                        $this->updateMessageStatus($message, $foundPriority);
                        $callback($message, $foundPriority);
                    } finally {
                        file_put_contents($flagFile, 'DONE');

                        pcntl_waitpid($pid, $status);
                        @unlink($flagFile);
                    }
                } else {
                    // CHILD PROCESS
                    $parentPid = posix_getppid();

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
            }
        } else {
            $callback($message, $foundPriority);
        }
        $this->updateMessageStatus();
    }

    private function recoverMessages(): void
    {
        if (!$this->isReliableMessageHandlingEnabled()) {
            return;
        }

        if (!$this->myEmitter || !$this->myIdentifier || !$this->currentMessageStoragePrefix) {
            return;
        }

        $lockKey = sprintf(
            '%s:lock',
            $this->currentMessageStoragePrefix,
        );

        if ($this->redis->setex($lockKey, MessageReliabilityInterface::LOCK_TTL, $this->myIdentifier)) {
            try {
                $start = hrtime(true);

                $cursor = null;

                do {
                    try {
                        $items = $this->redis->hscan($this->currentMessageStoragePrefix, $cursor, null, 1000);
                        if (!is_array($items)) {
                            break;
                        }
                        foreach ($items as $field => $value) {
                            $agentKey = $this->getAgentKey($field);
                            if ($this->redis->get($agentKey) !== null) {
                                continue;
                            }
                            $status = json_decode($value, true);
                            if (hrtime(true) - $start >= MessageReliabilityInterface::LOCK_TIME_DIFF_MAX) {
                                return;
                            }
                            $this->redis->hdel($this->currentMessageStoragePrefix, $field);
                            if (isset($status['message'])) {
                                $message = $status['message'];
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
