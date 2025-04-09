<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

interface ForkableDriverInterface
{
    public function setForkProcess(bool $status): void;
}
