<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

abstract class AbstractStorage implements HeartbeatStorageInterface
{
    final public function get(int $processId, string $hostName): ?HermesProcess
    {
        $processIdentifier = $this->getProcessIdentifier($processId, $hostName);
        return $this->getByIdentifier($processIdentifier);
    }

    final public function delete(int $processId, string $hostName): bool
    {
        $processIdentifier = $this->getProcessIdentifier($processId, $hostName);
        return $this->deleteByIdentifier($processIdentifier);
    }

    protected function getProcessIdentifier(int $processId, string $hostName): string
    {
        return $hostName . '|' . (string)$processId;
    }

    abstract protected function getByIdentifier(string $processIdentifier): ?HermesProcess;

    abstract protected function deleteByIdentifier(string $processIdentifier): bool;
}
