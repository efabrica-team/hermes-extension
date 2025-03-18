<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Command;

use Efabrica\HermesExtension\Driver\QueueAwareInterface;
use ReflectionObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
                $queuesHint .= PHP_EOL . "  <info>{$priority}</info>: <comment>{$queue}</comment>";
            }
            $this->addArgument(
                'restrict-priority',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Restricts the priorities to handle by this worker. Accepts multiple integer values of priorities:' . $queuesHint,
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $priorities = [];
        $queues = $this->getRegisteredQueues();

        if ($queues) {
            $prioritiesInput = $input->getArgument('restrict-priority');
            if (is_array($prioritiesInput)) {
                $priorities = array_map('intval', $prioritiesInput);
                $queuePriorities = array_keys($queues);
                foreach ($priorities as $priority) {
                    if (!in_array($priority, $queuePriorities, true)) {
                        $output->writeln('<error>Priority ' . $priority . ' is not defined!</error>');
                        return self::FAILURE;
                    }
                }
            }
        }

        $this->dispatcher->handle($priorities);
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
