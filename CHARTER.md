# idempotency charter

Immutable replay identities, request fingerprints, captured results, state transitions and durable ledger ports.

The package owns portable semantics and contracts. The host owns authentication, authorization, database adapters, transaction coupling, retention, scheduling and external-effect recovery. No service locator, connection, command dispatcher or production canonical encoder is included.
