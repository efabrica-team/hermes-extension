<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\MessageInterface;

final class DummyDriver implements DriverInterface
{
    use ShutdownTrait;
    use HeartbeatBehavior;

    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        return true;
    }

    public function wait(Closure $callback, array $priorities = []): void
    {
        // do nothing
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        // do nothing
    }
}
