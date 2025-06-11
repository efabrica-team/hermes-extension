# Hermes extension

[![PHPStan level](https://img.shields.io/badge/PHPStan-level:%20max-brightgreen.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![PHP static analysis](https://github.com/efabrica-team/hermes-extension/workflows/PHP%20static%20analysis/badge.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![Latest Stable Version](https://img.shields.io/packagist/v/efabrica/hermes-extension.svg)](https://packagist.org/packages/efabrica/hermes-extension)
[![Total Downloads](https://img.shields.io/packagist/dt/efabrica/hermes-extension.svg?style=flat-square)](https://packagist.org/packages/efabrica/hermes-extension)

Extension for tomaj/hermes. It contains:
- `HermesWorker` (symfony command)
- Drivers (`RedisProxy` based):
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

**Common driver properties:**
- can define one (default) or more priority queues
- can have shutdown functionality defined to stop their work
- can have heartbeat functionality defined to track activity
- can be stopped automatically after processing a given number of messages
- can be stopped by system signals (`SIGTERM`, `SIGINT`, `SIGQUIT` and `SIGHUP`)
- can be configured to fork before message handler execution (handler runs in child process) [**POSIX systems only**]
- handlers executed by drivers can access the message data using the `HermesDriverAccessor` singleton class

### `RedisProxySetDriver`

- Simple driver that uses Redis set(s) to deliver/process messages.
- This driver can't use delayed execution.
- A message acquired by the `Dispatcher` is immediately taken out of the queue and possibly lost when the dispatcher crashes or is force-stopped.
- There is no real-time monitoring.

### `RedisProxySortedSetDriver`

- Simple driver that uses Redis sorted set(s) to deliver/process messages.
- This driver can use delayed execution (message is executed after the `executeAt` timestamp at any moment). The message's priority is lost in this process; a message that moves from the delayed queue to the processing queue will have default priority assigned.
- A message acquired by the `Dispatcher` is immediately taken out of the queue and possibly lost when the dispatcher crashes or is force-stopped. There is a small but real chance that a delayed message may be lost if the process is force-stopped.
- There is no real-time monitoring.

### `RedisProxyListDriver`

- More complex driver that uses Redis list(s) to deliver/process messages.
- This driver can't use delayed execution.
- If message reliability is enabled, the messages are re-queued when the dispatcher crashes or is force-stopped. Otherwise, messages can be lost in these cases.
- If message reliability is enabled, on **POSIX-enabled systems** (loaded `pcntl` and `posix` extensions), the message is monitored by a background heartbeat process (notifying that work is being done on the message approximately every 1 second). If the system is not POSIX-enabled, message processing can still be tracked by using `HermesDriverAccessor`. Notifications from heartbeat or `HermesDriverAccessor` reset the message processing timer. Each message gets processing protection by the monitor for a given `keepAliveTTL`; when this protection expires, the message is re-queued.
- If priorities are defined, this driver has different behavior among the other `RedisProxy` drivers. The messages are processed from highest to lowest priority, but the driver continues to take messages from the current priority level without returning to the high priority first. This default behavior can be changed to match the behavior that other drivers use by calling `setUseTopPriorityFallback(true)` on the driver object during driver setup.

### `RedisProxyStreamDriver`

- More complex driver that uses Redis stream(s) to deliver/process messages.
- This driver can use delayed execution (message is executed after the `executeAt` timestamp at any moment). This driver preserves the message priority through the delayed queue; a message moved to the processing queue retains its original priority.
- The driver registers a consumer inside Redis streams. Due to this, the driver is always monitored.
  Monitoring is almost identical to the monitoring implemented in `RedisProxyListDriver` (when reliability is turned on).
  Monitoring here monitors not only messages but the consumers too, clearing outdated consumers from Redis memory.
  A message that is held in the stream group pending list for more than `keepAliveTTL` is claimed by the next consumer (keeps priority order). Message claiming by another consumer after `keepAliveTTL` protection expires can be restricted to a number of reclaims (defaults to 3); this can be set to 0 to throw the message away.
  Claimed message is processed immediately, not re-queued for later processing.

## `HermesDriverAccessor`

- Singleton object that carries the message/stream message envelope and priority of the message.
- If a handler is executed by a dispatcher with RedisProxyListDriver (in reliability mode only) or RedisProxyStreamDriver (always), user code can call:
  - `signalProcessingUpdate()` - manual monitor notification (resets protection expiration to `keepAliveTTL`),
  - `setProcessingStatus($status, $percent)` - manual monitor notification with status or percentage of completion of the task set to the monitor, both values are optional.
- User code cannot call setters or clear the data of the `HermesDriverAccessor`.