<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Tomaj\Hermes\EmitterInterface;

interface MessageReliabilityInterface
{
    public function enableReliableMessageHandling(string $storagePrefix, EmitterInterface $emitter, int $keepAliveTTL): void;
}
