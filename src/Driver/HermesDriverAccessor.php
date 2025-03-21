<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use LogicException;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\MessageInterface;

final class HermesDriverAccessor
{
    private static ?HermesDriverAccessor $instance = null;

    private ?MessageInterface $message = null;

    private ?int $priority = null;

    private ?DriverInterface $driver = null;

    private function __construct()
    {
    }

    public static function getInstance(): HermesDriverAccessor
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setMessageInfo(?MessageInterface $message, ?int $priority): void
    {
        $this->checkWriteAccess();

        $this->message = $message;
        $this->priority = $priority;
    }

    public function clearMessageInfo(): void
    {
        $this->checkWriteAccess();

        $this->message = null;
        $this->priority = null;
    }

    public function setDriver(?DriverInterface $driver): void
    {
        $this->checkWriteAccess();

        $this->driver = $driver;
    }

    public function getMessage(): ?MessageInterface
    {
        return $this->message;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function signalProcessingUpdate(): void
    {
        if (!$this->driver instanceof MessageReliabilityInterface) {
            return;
        }

        if ($this->message === null || $this->priority === null) {
            return;
        }

        $this->driver->updateMessageStatus($this->message, $this->priority);
    }

    private function checkWriteAccess(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $trace[1]['class'] ?? '';

        if ($callerClass === '' || !is_a($callerClass, DriverInterface::class, true)) {
            throw new LogicException(sprintf(
                'Access denied: write access is allowed only from classes implementing "%s" interface.',
                DriverInterface::class,
            ));
        }
    }
}
