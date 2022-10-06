<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

use DateTime;
use RedisProxy\RedisProxy;

final class RedisProxyStorage extends AbstractStorage
{
    private RedisProxy $redis;

    private string $key;

    public function __construct(RedisProxy $redis, string $key)
    {
        $this->redis = $redis;
        $this->key = $key;
    }

    public function ping(int $processId, string $hostName, string $status): void
    {
        $processIdentifier = $this->getProcessIdentifier($processId, $hostName);
        $data = [
            'process_id' => $processId,
            'host_name' => $hostName,
            'last_ping' => (new DateTime())->format('c'),
            'status' => $status,
        ];
        $this->redis->hset($this->key, $processIdentifier, json_encode($data));
    }

    public function load(): array
    {
        $processes = [];
        foreach ($this->redis->hgetall($this->key) as $value) {
            $process = $this->createHermesProcessFromRedisValue($value);
            if ($process) {
                $processes[] = $process;
            }
        }
        return $processes;
    }

    public function deleteByIdentifier(string $processIdentifier): bool
    {
        return (bool)$this->redis->hdel($this->key, $processIdentifier);
    }

    public function deleteByDate(DateTime $lastPing): int
    {
        $deletedProcesses = 0;
        foreach ($this->load() as $hermesProcess) {
            if ($hermesProcess->getLastPing() > $lastPing) {
                continue;
            }
            if ($this->delete($hermesProcess->getProcessId(), $hermesProcess->getHostName())) {
                $deletedProcesses++;
            }
        }
        return $deletedProcesses;
    }

    public function getByIdentifier(string $processIdentifier): ?HermesProcess
    {
        $value = $this->redis->hget($this->key, $processIdentifier);
        return $this->createHermesProcessFromRedisValue($value);
    }

    private function createHermesProcessFromRedisValue(?string $value): ?HermesProcess
    {
        if ($value === null) {
            return null;
        }
        $data = json_decode($value, true) ?: [];
        if ($data === []) {
            return null;
        }
        return new HermesProcess(
            $data['process_id'],
            $data['host_name'],
            new DateTime($data['last_ping'] ?? 'now'),
            $data['status'] ?? HermesProcess::STATUS_UNKNOWN
        );
    }
}
