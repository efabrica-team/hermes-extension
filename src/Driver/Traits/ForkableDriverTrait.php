<?php

namespace Efabrica\HermesExtension\Driver\Traits;

trait ForkableDriverTrait
{
    private bool $forkProcess = false;

    public function setForkProcess(bool $forkProcess): void
    {
        $this->forkProcess = $forkProcess;
    }

    private function doForkProcess(callable $callback): void
    {
        if (!$this->forkProcess) {
            $callback();
            return;
        }

        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $callback();
            } elseif ($pid) {
                // MAIN PROCESS
                pcntl_waitpid($pid, $status);
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
}
