# Change Log

## [Unreleased][unreleased]

### [0.2.0] - 22.7.2022
#### Changed
- Drivers refreshInterval changed to float (sleep < 1 sec)

#### Fixed
- RedisProxySortedSetDriver - scheduled set pop + remove

### [0.1.0] - 13.5.2022
#### Added
- HermesWorker (symfony command)
- RedisProxySetDriver (driver implementation using RedisProxy)
- RedisProxySortedSetDriver
- DummyDriver (for testing purposes)

[unreleased]: https://github.com/efabrica-team/hermes-extension/compare/0.2.0...HEAD
[0.2.0]: https://github.com/efabrica-team/hermes-extension/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/efabrica-team/hermes-extension/compare/8b055557b0c87b5c52961cf2bfa13340e50915ad...0.1.0
