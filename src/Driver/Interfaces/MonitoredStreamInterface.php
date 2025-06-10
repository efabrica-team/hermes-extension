<?php

namespace Efabrica\HermesExtension\Driver\Interfaces;

use Efabrica\HermesExtension\Message\StreamMessageEnvelope;

interface MonitoredStreamInterface
{
    const LOCK_TTL = 60;
    const XCLAIM_RETRIES = 3;

    public function updateEnvelopeStatus(?StreamMessageEnvelope $envelope = null, bool $onlyIfNotExists = false): bool;

    public function updateEnvelopeProcessingStatus(?string $status = null, ?float $percent = null): void;

    /**
     * @param int<0, max> $value
     */
    public function setMaximumXClaims(int $value): void;
}
