<?php

namespace Efabrica\HermesExtension\Driver;

use Closure;
use RedisProxy\RedisProxy;
use RedisProxy\RedisProxyException;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Driver\DriverInterface;
use Tomaj\Hermes\Driver\MaxItemsTrait;
use Tomaj\Hermes\Driver\SerializerAwareTrait;
use Tomaj\Hermes\Driver\ShutdownTrait;
use Tomaj\Hermes\MessageInterface;
use Tomaj\Hermes\MessageSerializer;
use Tomaj\Hermes\SerializeException;
use Tomaj\Hermes\Shutdown\ShutdownException;

class RedisProxySetDriver implements DriverInterface
{
    use MaxItemsTrait;
    use ShutdownTrait;
    use SerializerAwareTrait;

    /** @var array<int, string>  */
    private array $queues = [];

    private RedisProxy $redis;

    private string $key;

    private int $refreshInterval;

    public function __construct(RedisProxy $redis, string $key = 'hermes', int $refreshInterval = 1)
    {
        $this->redis = $redis;
        $this->key = $key;
        $this->refreshInterval = $refreshInterval;
        $this->serializer = new MessageSerializer();
    }

    /**
     * @throws RedisProxyException
     * @throws SerializeException
     */
    public function send(MessageInterface $message, int $priority = Dispatcher::DEFAULT_PRIORITY): bool
    {
        return (bool)$this->redis->sadd($this->key, $this->serializer->serialize($message));
    }

    /**
     * @throws RedisProxyException
     * @throws SerializeException
     * @throws ShutdownException
     */
    public function wait(Closure $callback, array $priorities = []): void
    {
        $queues = $this->queues;
        krsort($queues);
        while (true) {
            $this->checkShutdown();
            if (!$this->shouldProcessNext()) {
                break;
            }
            while (true) {
                $messageString = $this->redis->spop($this->key);
                if (!$messageString) {
                    break;
                }

                $callback($this->serializer->unserialize($messageString));
                $this->incrementProcessedItems();
            }

            if ($this->refreshInterval) {
                $this->checkShutdown();
                sleep($this->refreshInterval);
            }
        }
    }

    public function setupPriorityQueue(string $name, int $priority): void
    {
        $this->queues[$priority] = $name;
    }
}
