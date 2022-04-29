<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\Hermes\DispatcherInterface;

final class HermesWorker extends Command
{
    private DispatcherInterface $dispatcher;

    public function __construct(DispatcherInterface $dispatcher, string $name = 'hermes:worker')
    {
        parent::__construct($name);
        $this->dispatcher = $dispatcher;
    }

    protected function configure(): void
    {
        $this->setDescription('Handle hermes messages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dispatcher->handle();
        $output->writeln('Hermes worker end.');
        return 0;
    }
}
