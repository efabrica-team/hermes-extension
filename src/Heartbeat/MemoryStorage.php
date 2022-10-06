<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

use DateTime;

final class MemoryStorage extends AbstractStorage
{
    /** @var array<string, HermesProcess> */
    private array $processes = [];

    public function ping(int $processId, string $hostName, string $status): void
    {
        $processIdentifier = $this->getProcessIdentifier($processId, $hostName);
        $this->processes[$processIdentifier] = new HermesProcess($processId, $hostName, new DateTime(), $status);
    }

    public function load(): array
    {
        return array_values($this->processes);
    }

    protected function deleteByIdentifier(string $processIdentifier): bool
    {
        unset($this->processes[$processIdentifier]);
        return true;
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

    public function get(int $processId, string $hostName): ?HermesProcess
    {
        $processIdentifier = $this->getProcessIdentifier($processId, $hostName);
        return $this->processes[$processIdentifier] ?? null;
    }
}
