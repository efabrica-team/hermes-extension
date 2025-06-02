<?php

declare(strict_types=1);

namespace Efabrica\HermesExtension\Driver;

use Closure;
use Efabrica\HermesExtension\Heartbeat\HeartbeatBehavior;
use Efabrica\HermesExtension\Message\StreamMessageEnvelope;
use Ramsey\Uuid\Uuid;
use RedisProxy\RedisProxy;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\MaxItemsTrait;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\Message;
use Tomaj\Hermes\MessageInterface;
use Tomaj\Hermes\MessageSerializer;
use Tracy\Debugger;

final class RedisProxyStreamDriver implements DriverInterface, QueueAwareInterface, ForkableDriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;
    use HeartbeatBehavior;
    use QueueAwareTrait;
    use ForkableDriverTrait;

    private const STREAM_CONSUMERS_GROUP = 'consumers';

    /** @var array<int, string> */
    private array $queues = [];

    private RedisProxy $redis;

    private float $refreshInterval;

    private string $myIdentifier;

    public function __construct(RedisProxy $redis, string $key, float $refreshInterval = 1)
    {
        $this->redis = $redis;
        $this->refreshInterval = $refreshInterval;
        $this->serializer = new MessageSerializer();
        $this->myIdentifier = Uuid::uuid4()->toString();

        $this->setupPriorityQueue($key, Dispatcher::DEFAULT_PRIORITY);
    }

    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        // TODO: Implement send() method.
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        $this->queues[$priority] = $name;

        $this->setupStreamAndGroup($name);
    }

    public function wait(Closure $callback, array $priorities): void
    {
        $accessor = HermesDriverAccessor::getInstance();
        $accessor->setDriver($this);

        $queues = $this->queues;
        krsort($queues);

        try {
            while (true) {
                $this->checkShutdown();
                $this->checkToBeKilled();
                if (!$this->shouldProcessNext()) {
                    break;
                }

                $envelope = $this->receiveMessage($queues);
                break;
            }
        } finally {
            $this->removeConsumer();
            $accessor->clearMessageInfo();
        }
    }

    private function receiveMessage(array $queues): ?StreamMessageEnvelope
    {
        $streams = [];
        if ($this->refreshInterval > 0) {
            $streams = ['BLOCK', ceil($this->refreshInterval * 1000)];
        }
        $streams = [...$streams, 'STREAMS', ...$queues, ...array_fill(0, count($queues), '>')];

        $message = $this->redis->rawCommand(
            'XREADGROUP',
            self::STREAM_CONSUMERS_GROUP,
            $this->myIdentifier,
            'COUNT',
            1,
            ...$streams,
        );

        if (!is_array($message) || count($message) !== 1) {
            return null;
        }

        return new StreamMessageEnvelope(
            $queues[0],
            new Message('type'),
        );
    }

    private function setupStreamAndGroup(string $key): void
    {
        $scriptFile = __DIR__ . '/../Scripts/initiateStreamAndGroup.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        $keys = [$key, self::STREAM_CONSUMERS_GROUP, $this->myIdentifier];

        [$result, $message] = $this->redis->rawCommand(
            'EVALSHA',
            $scriptSha,
            count($keys),
            ...$keys,
        );
    }

    private function removeConsumer(): void
    {
        $scriptFile = __DIR__ . '/../Scripts/deleteConsumer.lua';
        $scriptSha = $this->getScriptSha($scriptFile);

        foreach ($this->queues as $queue) {
            try {
                $keys = [$queue, self::STREAM_CONSUMERS_GROUP, $this->myIdentifier];
                $this->redis->rawCommand(
                    'EVALSHA',
                    $scriptSha,
                    count($keys),
                    ...$keys,
                );
            } catch (\Throwable $exception) {
                Debugger::log($exception, Debugger::EXCEPTION);
            }
        }
    }

    private function getScriptSha(string $scriptFile): string
    {
        $scriptSha = sha1_file($scriptFile);

        $result = (bool)(int)$this->redis->rawCommand('SCRIPT', 'EXISTS', $scriptSha)[0];

        if (!$result) {
            $scriptSha = $this->redis->rawCommand('SCRIPT', 'LOAD', file_get_contents($scriptFile));
        }

        return $scriptSha;
    }
}