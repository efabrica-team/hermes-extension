<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use Throwable;
use Tomaj\Hermes\EmitterInterface;
use Tomaj\Hermes\MessageInterface;

trait MessageReliabilityTrait
{
    private RedisProxy $redis;

    private ?string $currentMessageStoragePrefix = null;

    private ?EmitterInterface $myEmitter = null;

    private ?string $myIdentifier = null;

    public function enableReliableMessageHandling(string $storagePrefix, EmitterInterface $emitter): void
    {
        $this->currentMessageStoragePrefix = $storagePrefix;
        $this->myEmitter = $emitter;
        $this->myIdentifier = Uuid::uuid4()->toString();
    }

    private function isReliableMessageHandlingEnabled(): bool
    {
        return $this->currentMessageStoragePrefix !== null;
    }

    private function updateMessageStatus(?MessageInterface $message = null, ?int $priority = null): void
    {
        if (!$this->isReliableMessageHandlingEnabled()) {
            return;
        }

        $key = sprintf(
            '[%s][%s][%s]',
            $this->myIdentifier,
            getmypid() ?: 'unknown',
            gethostname() ?: 'unknown',
        );

        $status = (object)[
            'timestamp' => microtime(true),
            'message' => $message,
            'priority' => $priority,
        ];

        try {
            $encoded = json_encode($status);
            if ($encoded !== false && $this->currentMessageStoragePrefix !== null) {
                $this->redis->hset($this->currentMessageStoragePrefix, $key, $encoded);
            }
        } catch (Throwable $exception) {
        }
    }
}
