<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

use DateTime;

interface HeartbeatStorageInterface
{
    public function ping(int $processId, string $hostName, string $status): void;

    /**
     * Get process
     */
    public function get(int $processId, string $hostName): ?HermesProcess;

    /**
     * Load all processes
     * @return HermesProcess[]
     */
    public function load(): array;

    /**
     * Delete process from storage
     */
    public function delete(int $processId, string $hostName): bool;

    /**
     * Delete all processes from storage older than last ping
     */
    public function deleteByDate(DateTime $lastPing): int;
}
