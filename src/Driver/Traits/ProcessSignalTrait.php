<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver\Traits;

trait ProcessSignalTrait
{
    private bool $signalShutdown = false;

    /**
     * Method adds signal handlers.
     */
    private function handleSignals(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        $signals = [SIGTERM, SIGINT, SIGQUIT, SIGHUP];

        foreach ($signals as $signal) {
            $originalHandler = pcntl_signal_get_handler($signal);
            $newHandler = function (int $signal, $signalInfo) use ($originalHandler) {
                $this->internalSignalHandler();
                if (is_callable($originalHandler)) {
                    $originalHandler($signal, $signalInfo);
                }
            };
            pcntl_signal($signal, $newHandler);
        }

        $this->signalShutdown = false;
    }

    /**
     * Tests if driver operations can proceed or not.
     */
    private function canOperate(): bool
    {
        return !$this->signalShutdown;
    }

    /**
     * @internal This method is internal, do not call it directly!
     */
    private function internalSignalHandler(): void
    {
        $this->signalShutdown = true;
    }
}