<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

trait QueueAwareTrait
{
    /**
     * @var array<int, string>
     */
    private array $queue = [];

    /**
     * @return array<int, string>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }
}
