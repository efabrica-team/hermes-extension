<?php
declare(strict_types=1);

namespace Efabrica\HermesExtension\Shutdown;

use DateTime;
use RedisProxy\RedisProxy;
use Tomaj\Hermes\Shutdown\ShutdownInterface;

class RedisProxyShutdown implements ShutdownInterface
{
    /** @var string */
    private $key;

    /** @var RedisProxy */
    private $redisProxy;

    public function __construct(RedisProxy $redisProxy, string $key = 'hermes_shutdown')
    {
        $this->redisProxy = $redisProxy;
        $this->key = $key;
    }

    public function shouldShutdown(DateTime $startTime): bool
    {
        // load UNIX timestamp from redis
        $shutdownTime = $this->redisProxy->get($this->key);
        if ($shutdownTime == null) {
            return false;
        }
        $shutdownTime = (int) $shutdownTime;

        // do not shutdown if shutdown time is in future
        if ($shutdownTime > time()) {
            return false;
        }

        // do not shutdown if hermes started after shutdown time
        if ($shutdownTime < $startTime->getTimestamp()) {
            return false;
        }

        return true;
    }

    public function shutdown(DateTime $shutdownTime = null): bool
    {
        if ($shutdownTime === null) {
            $shutdownTime = new DateTime();
        }

        return $this->redisProxy->set($this->key, $shutdownTime->format('U'));
    }
}
