<?php

namespace Efabrica\HermesExtension\Driver\Traits;

use Closure;
use Efabrica\HermesExtension\Driver\HermesDriverAccessor;
use LogicException;
use RedisProxy\RedisProxy;
use Tomaj\Hermes\Driver\DriverInterface;

trait MessageMultiprocessingTrait
{
    private RedisProxy $redis;

    private function commonMainProcess(Closure $mainProcess, Closure $childProcess, Closure $noForkProcess): void
    {
        if (extension_loaded('pcntl') && extension_loaded('posix')) {
            $flagFile = sys_get_temp_dir() . '/hermes_monitor_' . uniqid() . '-' . $this->myIdentifier . '.flag';
            @unlink($flagFile);

            $pid = pcntl_fork();

            if ($pid == -1) {
                $noForkProcess();
            } elseif ($pid) {
                $this->commonForkMainProcess($mainProcess, $pid, $flagFile);
            } else {
                $this->commonForkChildProcess($childProcess, $flagFile);
            }
        } else {
            $noForkProcess();
        }
    }

    /**
     * @internal Do not use this method outside trait!
     */
    private function commonForkMainProcess(Closure $mainProcess, int $pid, string $flagFile): void
    {
        try {
            $this->redis->resetConnectionPool();
            $mainProcess();
        } finally {
            file_put_contents($flagFile, 'DONE');

            pcntl_waitpid($pid, $status);
            @unlink($flagFile);
        }
    }

    /**
     * @internal Do not use this method outside trait!
     */
    private function commonForkChildProcess(Closure $childProcess, string $flagFile): void
    {
        $parentPid = posix_getppid();
        $this->redis->resetConnectionPool();

        if ($this->isParentDead($parentPid)) {
            exit(0); // Parent process is stopped, so this process ends too ...
        }

        while (true) {
            $childProcess();

            for ($i = 0; $i < 21; $i++) {
                if (file_exists($flagFile)) {
                    $content = @file_get_contents($flagFile);
                    if ($content === 'DONE') {
                        break(2);
                    }
                }

                if ($this->isParentDead($parentPid)) {
                    break(2);
                }

                if ($i < 20) {
                    usleep(50000);
                }
            }
        }

        exit(0);
    }

    /**
     * This tests the call stack if the caller is {@see DriverInterface}.
     *
     * @internal Do not use this method outside trait!
     */
    private function checkWriteAccess(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $trace[1]['class'] ?? '';

        if ($callerClass === '' || !is_a($callerClass, DriverInterface::class, true)) {
            throw new LogicException(sprintf(
                'Method updateMessageStatus can only be called from classes implementing "%s" or from "%s".',
                DriverInterface::class,
                HermesDriverAccessor::class
            ));
        }
    }

    /**
     * @internal Do not use this method outside trait!
     * @phpstan-impure
     */
    private function isParentDead(int $parentPid): bool
    {
        $deadChecks = 0;

        for ($i = 0; $i < 3; $i++) { // 3 test passes
            if (posix_getpgid($parentPid) === false && posix_kill($parentPid, 0) === false) {
                $deadChecks++;
            } else {
                return false; // parent isn't dead
            }

            if ($i < 2) {
                usleep(1000);
            }
        }

        return $deadChecks === 3; // 3× positive check = dead parent
    }
}
