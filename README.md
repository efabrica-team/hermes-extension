# Hermes extension

[![PHPStan level](https://img.shields.io/badge/PHPStan-level:%20max-brightgreen.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![PHP static analysis](https://github.com/efabrica-team/hermes-extension/workflows/PHP%20static%20analysis/badge.svg)](https://github.com/efabrica-team/hermes-extension/actions?query=workflow%3A"PHP+static+analysis")
[![Latest Stable Version](https://img.shields.io/packagist/v/efabrica/hermes-extension.svg)](https://packagist.org/packages/efabrica/hermes-extension)
[![Total Downloads](https://img.shields.io/packagist/dt/efabrica/hermes-extension.svg?style=flat-square)](https://packagist.org/packages/efabrica/hermes-extension)

Extension for tomaj/hermes. It contains:
- HermesWorker (symfony command)
- Drivers:
  - RedisProxySetDriver (driver implementation using RedisProxy)
  - RedisProxySortedSetDriver 
  - DummyDriver (for testing purposes)
- Heartbeat functionality with drivers
  - RedisProxyStorage
  - MemoryStorage (for testing purposes)

## Installation
```shell
composer require efabrica/hermes-extension
```

## Setup

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
