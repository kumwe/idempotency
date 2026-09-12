# Core contract

Idempotency owns replay keys, immutable request/result snapshots, state transitions, request/body fingerprints and
ledger/purger ports. Core owns authentication, authorization, durable storage, transactions, lease clocks, external
effects, recovery decisions, response secret policy and retention.

## Construction and byte compatibility

Construct values directly; there is no ConfigProvider or exported injected service. Supply the host's conforming
generic-v1 CanonicalEncoder to IdempotencyRecord and IdempotencyResult. The runtime requires exact Canonical JSON
0.1.1. Persist the fingerprint profile, encoder release and corpus identity with deployment evidence.

Generic-v1 object/list order and preserved float fractions determine fingerprint equality. A different profile needs
an explicit versioned fingerprint migration; the definition no-float profile is not interchangeable. Captured results
detach references by round-tripping canonical bytes and hash exactly those bytes. Persist scalars and JSON through
host adapters; serialized encoder objects are not supported storage. See [integration](integration.md).

## Scope, ownership and replay

Core scopes each durable key by subject, operation and key using authenticated actor, site and organization facts.
Store the current credential/site authorization fingerprint separately and prove it again before replay or completion.
The package does not infer or authorize those scopes. Request/response payloads must not contain raw secrets.

Core implements IdempotencyLedger, SecretOnceIdempotencyLedger and IdempotencyPurger. Durable adapters provide unique
first-writer claims, ownership-conditioned writes, transactional rollback, bounded deletion and safe replay bodies.
An expired lease does not prove an external side effect failed. Core reconciles ambiguous effects before takeover
and selects failure/expiry release and retry policy for each use case.

Transitions return new immutable values. Completion and failure are terminal; replay rejects mismatched requests,
in-progress/failed operations and expiry at the exact expiry instant. Core retains live lock and owner protections
when purging. No process-wide durable coordination is implemented by the package's example adapters.

## Test ownership and Core compatibility

The reusable sequential ledger contract suite verifies portable adapter expectations with deterministic fixtures.
Core obtains it from the selected tagged source as a test dependency; tests are excluded from production archives.
[Adapter conformance](test-ownership.md) explains factory/clock hooks and inventory validation.
Core retains concurrent first-writer, database lock/parity, transaction rollback, authorization/trust, worker
termination, recovery and application composition tests.

The [release record](release-record.md) and [consumer inventory](consumer-inventory.json) retain exact source/test
baselines. Reconcile them against current Core/SDK code before import changes or duplicate implementation removal.
Select independently verified exact package versions and preserve the host lockfile. Rollback restores the previously
validated dependency/composition tuple while respecting persisted profile and ledger compatibility.
