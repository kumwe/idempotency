# kumwe/idempotency

Immutable replay identities, request fingerprints, captured results, state transitions and durable ledger ports.

Requires PHP 8.5 and an explicitly supplied `Kumwe\CanonicalJson\CanonicalEncoder` conforming to `kumwe-canonical-json/generic-v1`. CanonicalEncoder is supplied by the published `kumwe/canonical-json` 0.1.1 dependency. Normal publication verifies stable dependency versions and source commits; independent App adoption verification remains separate.

Run `composer install` and `composer check`. `composer clean-consumer` builds isolated ZIPs from this checkout and its installed declared Kumwe dependencies, installs a fresh no-dev authoritative consumer, and executes the shipped example. That proves candidate composition, not immutable release provenance.

See [public API](docs/public-api.md), [integration](docs/integration.md), and [migration handoff](MIGRATION-HANDOFF.md).
