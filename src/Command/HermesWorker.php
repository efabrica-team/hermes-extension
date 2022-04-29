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

    private string $name;

    public function __construct(DispatcherInterface $dispatcher, string $name = 'hermes:worker')
    {
        parent::__construct();
        $this->dispatcher = $dispatcher;
        $this->name = $name;
    }

    protected function configure(): void
    {
        $this->setName($this->name)
            ->setDescription('Handle hermes messages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dispatcher->handle();
        $output->writeln('Hermes worker end.');
        return 0;
    }
}
