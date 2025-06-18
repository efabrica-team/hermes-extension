<?php

namespace Efabrica\HermesExtension\Driver\Traits;

trait ForkableDriverTrait
{
    private bool $forkProcess = false;

    private ?int $childPid = null;

    public function setForkProcess(bool $forkProcess): void
    {
        $this->forkProcess = $forkProcess;
    }

    private function doForkProcess(callable $callback): void
    {
        if (!$this->forkProcess) {
            $this->childPid = null;
            $callback();
            return;
        }

        if (extension_loaded('pcntl')) {
            $oldSignals = [];
            pcntl_sigprocmask(SIG_BLOCK, [SIGTERM, SIGINT, SIGQUIT, SIGHUP], $oldSignals);

            $pid = pcntl_fork();

            /** @var array<int, mixed> $oldSignals */
            pcntl_sigprocmask(SIG_SETMASK, $oldSignals);

            if ($pid === -1) {
                $this->childPid = null;
                $callback();
            } elseif ($pid) {
                // MAIN PROCESS
                $this->childPid = $pid;
                while (true) {
                    pcntl_signal_dispatch();
                    $status = pcntl_waitpid($pid, $status, WNOHANG);

                    if ($status === $pid || $status === -1) {
                        $this->childPid = null;
                        break;
                    }

                    usleep(100000);
                }
            } else {
                // CHILD PROCESS
                try {
                    $callback();
                } finally {
                    exit(0);
                }
            }
        } else {
            $callback();
        }
    }

    private function hasActiveChildFork(): bool
    {
        return $this->childPid !== null;
    }
}
