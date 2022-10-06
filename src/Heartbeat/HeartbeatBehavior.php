<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Heartbeat;

use Tomaj\Hermes\Shutdown\ShutdownException;

trait HeartbeatBehavior
{
    private ?HeartbeatStorageInterface $heartbeatStorage = null;

    public function setHeartbeat(HeartbeatStorageInterface $heartbeatStorage): void
    {
        $this->heartbeatStorage = $heartbeatStorage;
    }

    public function ping(string $status): void
    {
        if ($this->heartbeatStorage === null) {
            return;
        }
        $this->heartbeatStorage->ping(getmypid(), gethostname(), $status);
    }

    public function checkToBeKilled(): void
    {
        if ($this->heartbeatStorage === null) {
            return;
        }

        $process = $this->heartbeatStorage->get(getmypid(), gethostname());
        if ($process === null) {
            return;
        }

        if ($process->getStatus() === HermesProcess::STATUS_KILLED) {
            $this->heartbeatStorage->delete($process->getProcessId(), $process->getHostName());
            throw new ShutdownException();
        }
    }
}
