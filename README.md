# Hermes extension

[![PHPStan level](https://img.shields.io/badge/PHPStan-level:%20max-brightgreen.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![PHP static analysis](https://github.com/efabrica-team/hermes-extension/workflows/PHP%20static%20analysis/badge.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![Latest Stable Version](https://img.shields.io/packagist/v/efabrica/hermes-extension.svg)](https://packagist.org/packages/efabrica/hermes-extension)
[![Total Downloads](https://img.shields.io/packagist/dt/efabrica/hermes-extension.svg?style=flat-square)](https://packagist.org/packages/efabrica/hermes-extension)

Extension for tomaj/hermes. It contains:
- `HermesWorker` (symfony command)
- Drivers (RedisProxy based):
  - `RedisProxySetDriver`
  - `RedisProxySortedSetDriver`
  - `RedisProxyListDriver`
  - `RedisProxyStreamDriver`
- Other drivers:
  - `DummyDriver` (for testing purposes)
- Heartbeat functionality with drivers
  - `RedisProxyStorage`
  - `MemoryStorage` (for testing purposes)

## Installation
```shell
composer require efabrica/hermes-extension
```

## Quick setup

```php
use Efabrica\HermesExtension\Driver\RedisProxySetDriver;
use Efabrica\HermesExtension\Heartbeat\RedisProxyStorage;
use RedisProxy\RedisProxy;
use Tomaj\Hermes\Dispatcher;
use Tomaj\Hermes\Handler\EchoHandler;
use Tomaj\Hermes\Shutdown\SharedFileShutdown;

$redisProxy = new RedisProxy(/*...*/);

// Register driver
$driver = new RedisProxySetDriver($redisProxy, 'hermes');

// Optionally register shutdown and / or heartbeat
$driver->setShutdown(new SharedFileShutdown('/tmp/hermes_shared_file'));
$driver->setHeartbeat(new RedisProxyStorage($redisProxy, 'hermes_heartbeat'));

// Create Dispatcher
$dispatcher = new Dispatcher($driver);

// Register handlers
$dispatcher->registerHandler('event_type', new EchoHandler());
```

Register hermes worker to symfony console application:
```php
// file: bin/command

use Efabrica\HermesExtension\Command\HermesWorker;
use Symfony\Component\Console\Application;


$application = new Application();
$application->add(new HermesWorker($dispatcher));
$application->run();
```

Run command:
```shell
php bin/command hermes:worker
```
Submit message:
```php
use Tomaj\Hermes\Emitter;
use Tomaj\Hermes\Message;

// Create Emitter
$emitter = new Emitter($driver);

// Send message:
$emitter->emit(new Message(
    'event_type',
    ['body' => 'This is a message!']
));
```

## `RedisProxy` drivers

**Common drivers properties:**
- can define one (default) or more priority queues
- can have shutdown defined to stop their work
- can have heartbeat defined to track activity
- can be stopped automatically after processing given number of messages
- are able to be stopped by system signals (`SIGTERM`, `SIGINT`, `SIGQUIT` and `SIGHUP`)
- can be set to be forked before message handler execution (handler runs in child process) [**posix systems only**]
- handlers executed by the drivers can access the message data using HermesDriverAccessor singleton class

### `RedisProxySetDriver`

- Simple driver that uses Redis set(s) to deliver/process messages.
- This driver can't use delayed execution.
- Message acquired by the `Dispatcher` is immediately taken out from the queue and possibly lost when dispatcher crashed or is force-stopped.
- There is no real-time monitoring.

### `RedisProxySortedSetDriver`

- Simple driver that uses Redis sorted set(s) to deliver/process messages.
- This driver can use delayed execution (message is executed after the `executeAt` timestamp at any moment).
- Message acquired by the `Dispatcher` is immediately taken out from the queue and possibly lost when dispatcher crashed or is force-stopped. There is a minimal but still real chance that delayed message may be lost if process is force-stopped.
- There is no real-time monitoring.

### `RedisProxyListDriver`

- More complex driver that uses Redis list(s) to deliver/process messages.
- This driver can't use delayed execution.
- If the message reliability is turned on the messages are re-queued when dispatcher crashes or is force-stopped. 
  Otherwise the message can be lost in these cases.
- If the message reliability is turned on, on the **posix enabled systems** (loaded `pcntl` and `posix` extensions),
  the message is monitored by background heartbeat process (notifying work on the message by the handler approximately
  each 1 second). If the system is not posix enabled, the processing of the message can still be notified by the use
  of `HermesDriverAccessor`. Notifications from heartbeat or `HermesDriverAccessors` resets the message processing
  timer. Each message got a processing protection by the monitor for given `keepAliveTTL`, when this protection expires
  the message is re-queued.
- If priorities are defined, this driver has different behaviour among the other `RedisProxy` drivers. The messages are
  processed from highest to lowest priority, but the driver continues to take message from actual priority level without
  returning to the high priority first. This default behaviour of the driver can be changed to the same behaviour as the
  other drivers uses by calling `setUseTopPriorityFallback(true)` on the driver object during driver setup.

### `RedisProxyStreamDriver`

- More complex driver that uses Redis stream(s) to deliver/process messages.
- This driver can use delayed execution (message is executed after the `executeAt` timestamp at any moment).
- Driver registers consumer inside Redis streams. Due to this, driver is always monitored.
  Monitoring is almost identical to the monitoring implemented in `RedisProxyListDriver` (when reliability is turned on).
  Monitoring here monitors not only messages but the consumers too, clearing outdated consumers from Redis memory.
  The message that is held in the stream group pending list for more than `keepAliveTTL` is claimed by next consumer
  (keeps priority order). Message claiming by other consumer after `keepAliveTTL` protection expires can be restricted to
  number or reclaims (defaults to 3), this can be set to 0 to throw the message away.
  Claimed message is processed immediately, not re-queued for later processing.

## `HermesDriverAccessor`

- Singleton object that carries the message/stream message envelope and priority of the message.
- If handler is executed by dispatcher with RedisProxyListDriver (in reliability mode only) or RedisProxyStreamDriver 
  (always), user code can call:
  - `signalProcessingUpdate()` - manual monitor notification (resets protection expiration to `keepAliveTTL`),
  - `setProcessingStatus($status, $percent)` - manual monitor notification with status or percentage of completion of
    the task set to the monitor, both values are nullable.
- User code can't call setters or clean the data of the accessor.