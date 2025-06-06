<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Efabrica\HermesExtension\Driver\Interfaces\MessageReliabilityInterface;
use Efabrica\HermesExtension\Driver\Interfaces\MonitoredStreamInterface;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use LogicException;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\MessageInterface;

final class HermesDriverAccessor
{
    private static ?HermesDriverAccessor $instance = null;

    private ?MessageInterface $message = null;

    private ?StreamMessageEnvelope $envelope = null;

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
        $this->envelope = null;
        $this->priority = $priority;
    }

    public function setEnvelopeInfo(?StreamMessageEnvelope $envelope): void
    {
        $this->checkWriteAccess();

        $this->message = null;
        $this->envelope = $envelope;
        $this->priority = $envelope !== null ? $envelope->getPriority() : null;
    }

    public function clearTransmissionInfo(): void
    {
        $this->checkWriteAccess();

        $this->message = null;
        $this->envelope = null;
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

    public function getEnvelope(): ?StreamMessageEnvelope
    {
        return $this->envelope;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function signalProcessingUpdate(): void
    {
        if (!$this->driver instanceof MessageReliabilityInterface
            && !$this->driver instanceof MonitoredStreamInterface
        ) {
            return;
        }

        if (($this->message === null && $this->envelope === null) || $this->priority === null) {
            return;
        }

        if ($this->driver instanceof MessageReliabilityInterface) {
            $this->driver->updateMessageStatus($this->message, $this->priority);
        } elseif ($this->driver instanceof MonitoredStreamInterface) {
            $this->driver->updateEnvelopeStatus($this->envelope);
        }
    }

    /**
     * Writes status of processed message to the driver data.
     *
     * @param string|null $status status message, `null` will delete status.
     * @param float|null $percent percentage of task completeness between `0` and `100`, `null` disables completion tracking.
     */
    public function setProcessingStatus(?string $status = null, ?float $percent = null): void
    {
        if (!$this->driver instanceof MessageReliabilityInterface
            && !$this->driver instanceof MonitoredStreamInterface
        ) {
            return;
        }

        if (($this->message === null && $this->envelope === null) || $this->priority === null) {
            return;
        }

        if ($this->driver instanceof MessageReliabilityInterface) {
            $this->driver->updateMessageStatus($this->message, $this->priority);
            $this->driver->updateMessageProcessingStatus($status, $percent);
        } elseif ($this->driver instanceof MonitoredStreamInterface) {
            $this->driver->updateEnvelopeStatus($this->envelope);
            $this->driver->updateEnvelopeProcessingStatus($status, $percent);
        }
    }

    private function checkWriteAccess(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $callerClass = $trace[2]['class'] ?? '';

        if ($callerClass === '' || !is_a($callerClass, DriverInterface::class, true)) {
            throw new LogicException(sprintf(
                'Access denied: write access is allowed only from classes implementing "%s" interface.',
                DriverInterface::class,
            ));
        }
    }
}
