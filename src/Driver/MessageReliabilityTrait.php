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

    private ?int $keepAliveTTL = null;

    public function enableReliableMessageHandling(
        string $storagePrefix,
        EmitterInterface $emitter,
        int $keepAliveTTL,
    ): void {
        $this->currentMessageStoragePrefix = $storagePrefix;
        $this->myEmitter = $emitter;
        $this->keepAliveTTL = $keepAliveTTL;
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
        $agentKey = $this->currentMessageStoragePrefix . $key;

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
}
