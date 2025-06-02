<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Message;

use Tomaj\Hermes\MessageInterface;

final class StreamMessageEnvelope
{
    private string $queue;

    private MessageInterface $message;

    public function __construct(
        string $queue,
        MessageInterface $message,
    ) {
        $this->queue = $queue;
        $this->message = $message;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getMessage(): MessageInterface
    {
        return $this->message;
    }
}