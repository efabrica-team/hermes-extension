<?php

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use RedisProxy\RedisProxy;
use Throwable;

trait MonitoredStreamTrait
{
    use MessageMultiprocessingTrait;

    private string $monitorHashRedisKey;

    private string $myIdentifier;

    private RedisProxy $redis;

    private int $keepAliveTTL = 60;

    /**
     * @var int|string
     */
    private $myPID = 0;

    private function initMonitoredStream(string $monitorHashRedisKey, string $myIdentifier, int $keepAliveTTL = 60): void
    {
        $this->myIdentifier = $myIdentifier;
        $this->monitorHashRedisKey = $monitorHashRedisKey;
        $this->keepAliveTTL = $keepAliveTTL;
        $this->myPID = getmypid() !== false ? getmypid() : 'unknown';
    }

    private function monitorEnvelopeCallback(Closure $callback, StreamMessageEnvelope $envelope): void
    {
        $accessor = HermesDriverAccessor::getInstance();

        $accessor->setEnvelopeInfo($envelope);
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
            $accessor->clearTransmissionInfo();
        }
    }

    /**
     * Writes data about currently processed envelope
     */
    public function updateEnvelopeStatus(?StreamMessageEnvelope $envelope = null): void
    {
        $this->checkWriteAccess();

        $key = $this->getMyKey();
        $agentKey = $this->getAgentKey($key);

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
        ];

        try {
            $encoded = json_encode($status);
            if ($encoded !== false) {
                $this->redis->hset($this->monitorHashRedisKey, $key, $encoded);
                $this->redis->setex($agentKey, $this->keepAliveTTL, (string)$status->timestamp);
            }
        } catch (Throwable $exception) {
        }
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