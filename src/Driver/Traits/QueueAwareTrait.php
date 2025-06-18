<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver\Traits;

trait QueueAwareTrait
{
    /**
     * @var array<int, string>
     */
    private array $queues = [];

    /**
     * @return array<int, string>
     */
    public function getQueues(): array
    {
        return $this->queues;
    }
}
