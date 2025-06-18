<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver\Interfaces;

use Tomaj\Hermes\MessageInterface;

interface MessageReliabilityInterface
{
    const LOCK_TTL = 60;
    const LOCK_TIME_DIFF_MAX = 59000000000;
    const REQUEUE_REPEATS = 3;

    public function enableReliableMessageHandling(string $monitorKeyPrefix, int $keepAliveTTL): void;

    public function updateMessageStatus(?MessageInterface $message = null, ?int $priority = null): void;

    public function updateMessageProcessingStatus(?string $status = null, ?float $percent = null): void;
}
