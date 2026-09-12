# Kumwe Idempotency

[![Packagist version][version-badge]][package]
[![Idempotency CI][ci-badge]][ci]
[![PHP requirement][php-badge]][package]
[![License][license-badge]](LICENSE)

[version-badge]: https://img.shields.io/packagist/v/kumwe/idempotency
[package]: https://packagist.org/packages/kumwe/idempotency
[ci-badge]: https://github.com/kumwe/idempotency/actions/workflows/ci.yml/badge.svg?branch=main
[ci]: https://github.com/kumwe/idempotency/actions/workflows/ci.yml
[php-badge]: https://img.shields.io/packagist/dependency-v/kumwe/idempotency/php
[license-badge]: https://img.shields.io/packagist/l/kumwe/idempotency

Immutable replay identities, request fingerprints, captured results, state transitions and durable ledger ports
under `Kumwe\Idempotency`. Requires PHP 8.5, JSON and exact Canonical JSON 0.1.1.

## Installation and use

Install the published package with an exact pre-1.0 pin:

```sh
composer require kumwe/idempotency:0.1.2
```

```php
<?php

require 'vendor/autoload.php';

use Kumwe\Idempotency\IdempotencyKey;

$key = IdempotencyKey::fromString('request-001');
assert($key->value() === 'request-001');
assert($key->equals(IdempotencyKey::fromHeader(' request-001 ')));
```

Record and result operations require an explicitly supplied `Kumwe\CanonicalJson\CanonicalEncoder` conforming to
`kumwe-canonical-json/generic-v1`. There is no encoder fallback. The
[typed consumer example](examples/typed-consumer.php) demonstrates claim collision and stable replay with test adapters.

## Core integration

Core binds IdempotencyLedger, SecretOnceIdempotencyLedger and IdempotencyPurger to durable adapters. Values are
constructed directly; no ConfigProvider is needed. Core supplies authenticated actor/site/organization scope, current
authorization fingerprints, atomic ownership writes, transaction coupling, lease/recovery policy and secret-safe replay.
See the [Core contract](docs/core-contract.md), [integration guide](docs/integration.md) and
[adapter conformance](docs/test-ownership.md).

[Public API](docs/public-api.md), [architecture](docs/architecture.md), [charter](CHARTER.md) and
[release record](docs/release-record.md) describe the maintained public boundary and consumer compatibility.

## Development and releases

```sh
composer install
composer governance:install
composer check
composer examples
```

The complete gate validates public schemas, source/documentation agreement, ownership, behavior and an isolated
no-dev authoritative archive consumer. Local fixture composition does not establish durable concurrency or release
provenance. Published versions and CI status are linked above; Core validates its actual retained integration suites.
[Release guidance](docs/releasing.md) describes exact dependency identity and independent evidence requirements.
Licensed under [Apache-2.0](LICENSE).
