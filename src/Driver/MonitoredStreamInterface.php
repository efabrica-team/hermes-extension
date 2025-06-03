<?php

namespace Efabrica\HermesExtension\Driver;

use Efabrica\HermesExtension\Message\StreamMessageEnvelope;

interface MonitoredStreamInterface
{
    public function updateEnvelopeStatus(?StreamMessageEnvelope $envelope = null): void;

    public function updateEnvelopeProcessingStatus(?string $status = null, ?float $percent = null): void;
}