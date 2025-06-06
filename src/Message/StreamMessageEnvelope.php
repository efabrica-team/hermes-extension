<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Message;

use Tomaj\Hermes\MessageInterface;

final class StreamMessageEnvelope
{
    private string $queue;

    private string $id;

    private string $group;

    private string $consumer;

    private MessageInterface $message;

    private int $priority;

    public function __construct(
        string $queue,
        string $id,
        string $group,
        string $consumer,
        MessageInterface $message,
        int $priority,
    ) {
        $this->queue = $queue;
        $this->id = $id;
        $this->group = $group;
        $this->consumer = $consumer;
        $this->message = $message;
        $this->priority = $priority;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getConsumer(): string
    {
        return $this->consumer;
    }

    public function getMessage(): MessageInterface
    {
        return $this->message;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
