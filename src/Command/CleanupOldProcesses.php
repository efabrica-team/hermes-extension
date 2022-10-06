<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Command;

use DateTime;
use Efabrica\HermesExtension\Heartbeat\HeartbeatStorageInterface;
use Efabrica\HermesExtension\Heartbeat\HermesProcess;
use Efabrica\HermesExtension\Ping\PingInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanupOldProcesses extends Command
{
    private HeartbeatStorageInterface $heartbeatStorage;

    public function __construct(HeartbeatStorageInterface $heartbeatStorage, string $name = 'hermes:cleanup')
    {
        parent::__construct($name);
        $this->heartbeatStorage = $heartbeatStorage;
    }

    protected function configure(): void
    {
        $this->setDescription('Cleanup old hermes processes and processes marked to be killed')
            ->addOption('time', null, InputOption::VALUE_REQUIRED, 'Max time to keep processes in storage', '-1 hour')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $time */
        $time = $input->getOption('time');
        $deletedProcesses = $this->heartbeatStorage->deleteByDate(new DateTime($time));
        $output->writeln('Deleted processes: ' . $deletedProcesses);
        return self::SUCCESS;
    }
}
