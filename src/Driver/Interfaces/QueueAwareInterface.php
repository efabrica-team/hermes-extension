<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver\Interfaces;

interface QueueAwareInterface
{
    /**
     * @return array<int, string>
     */
    public function getQueues(): array;
}
