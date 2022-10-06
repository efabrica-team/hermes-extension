<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

use DateTime;

final class HermesProcess
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_KILLED = 'killed';

    private int $processId;

    private string $hostName;

    private DateTime $lastPing;

    private string $status;

    public function __construct(int $processId, string $hostName, DateTime $lastPing, string $status)
    {
        $this->processId = $processId;
        $this->hostName = $hostName;
        $this->lastPing = $lastPing;
        $this->status = $status;
    }

    public function getProcessId(): int
    {
        return $this->processId;
    }

    public function getHostName(): string
    {
        return $this->hostName;
    }

    public function getLastPing(): DateTime
    {
        return $this->lastPing;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
