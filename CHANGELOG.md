# Change Log

## [Unreleased][unreleased]

## [1.0.0] - 2023-09-06
### Added
- redis-proxy 1.0 support

## [0.3.1] - 2022-12-12
### Added
- Support for PHP 8.2

## [0.3.0] - 2022-10-07
### Added
- Heartbeat functionality

## [0.2.0] - 2022-07-22
### Changed
- Drivers refreshInterval changed to float (sleep < 1 sec)

### Fixed
- RedisProxySortedSetDriver - scheduled set pop + remove

## [0.1.0] - 2022-05-13
### Added
- HermesWorker (symfony command)
- RedisProxySetDriver (driver implementation using RedisProxy)
- RedisProxySortedSetDriver
- DummyDriver (for testing purposes)

[unreleased]: https://github.com/efabrica-team/hermes-extension/compare/1.0.0...HEAD
[1.0.0]: https://github.com/efabrica-team/hermes-extension/compare/0.3.1...1.0.0
[0.3.1]: https://github.com/efabrica-team/hermes-extension/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/efabrica-team/hermes-extension/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/efabrica-team/hermes-extension/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/efabrica-team/hermes-extension/compare/8b055557b0c87b5c52961cf2bfa13340e50915ad...0.1.0
