<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Command;

use Efabrica\HermesExtension\Driver\QueueAwareInterface;
use ReflectionObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tomaj\Hermes\DispatcherInterface;
use Tomaj\Hermes\Driver\DriverInterface;

final class HermesWorker extends Command
{
    private DispatcherInterface $dispatcher;

    public function __construct(DispatcherInterface $dispatcher, string $name = 'hermes:worker')
    {
        $this->dispatcher = $dispatcher;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Handle hermes messages');

        $queues = $this->getRegisteredQueues();
        if ($queues) {
            $queuesHint = '';
            foreach ($queues as $priority => $queue) {
                $queuesHint .= PHP_EOL . "<info>{$priority}</info>: <comment>{$queue}</comment>";
            }
            $this->addOption(
                'restrict-priority',
                'p',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Restricts the priorities to handle by this worker. Can be used multiple times with these priorities:' . $queuesHint,
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queues = $this->getRegisteredQueues();
        $prioritiesSet = [];

        if ($queues) {
            $prioritiesInput = $input->getOption('restrict-priority');
            if (is_array($prioritiesInput)) {
                $priorities = array_map(
                    static function ($priority)
                    {
                        return (int)$priority;
                    },
                    $prioritiesInput,
                );
                $queuePriorities = array_keys($queues);
                $io = new SymfonyStyle($input, $output);
                foreach ($priorities as $priority) {
                    if (!in_array($priority, $queuePriorities, true)) {
                        $io->caution('Priority ' . $priority . ' is not defined!');
                        continue;
                    }
                    $prioritiesSet[] = $priority;
                }
            }
        }

        $this->dispatcher->handle($prioritiesSet);
        $output->writeln('Hermes worker end.');
        return self::SUCCESS;
    }

    private function getDriverFromHandler(): ?DriverInterface
    {
        $reflection = new ReflectionObject($this->dispatcher);
        $properties = $reflection->getProperties();
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this->dispatcher);

            if ($value instanceof DriverInterface) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return array<int, string>|null
     */
    private function getRegisteredQueues(): ?array
    {
        $driver = $this->getDriverFromHandler();

        if (!($driver instanceof QueueAwareInterface)) {
            return null;
        }

        return $driver->getQueues();
    }
}
