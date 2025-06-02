<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Message;

use Tomaj\Hermes\MessageInterface;

final class StreamMessageEnvelope
{
    private string $queue;

    private string $id;

    private MessageInterface $message;

    public function __construct(
        string $queue,
        string $id,
        MessageInterface $message
    ) {
        $this->queue = $queue;
        $this->id = $id;
        $this->message = $message;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMessage(): MessageInterface
    {
        return $this->message;
    }
}