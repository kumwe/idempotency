# Changelog

## 0.1.2

- Correct capability, service and full migration-handoff metadata to the authoritative version 2 schemas.
- Validate all three package manifests and the full handoff with locked JSON Schema tooling and refusal regressions.
- Generate complete public API documentation and verify source, documentation, handoff and capability ownership agreement.

## 0.1.1

- Add reusable ledger, secret-once and bounded purger conformance with deterministic test-only adapters.
- Enforce complete source/test ownership inventory in the package gate.
- Reconcile the migration handoff and readiness evidence against published baselines.


## 0.1.0

- Extract immutable replay identities, request fingerprints, captured results, state transitions and durable ledger ports.
- Require the explicit generic-v1 CanonicalEncoder port; no encoder fallback.
- Add package behavior and hostile-input tests, API drift checks and archive consumer verification.

Version 0.1.0 was published from 26ec2ac31c493a088dd2bd01983b7428692bd1ea. The 0.1.1 record is a review candidate; publication and independent artifact verification remain separate steps.
