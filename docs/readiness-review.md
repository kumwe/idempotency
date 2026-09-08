# Extraction readiness review — 2026-09-08

Proposed successor: `0.1.1`. Published baseline: [v0.1.0](https://github.com/kumwe/idempotency/releases/tag/v0.1.0) at `26ec2ac31c493a088dd2bd01983b7428692bd1ea`. Review: [PR #4](https://github.com/kumwe/idempotency/pull/4).

The package now exercises all three ledger/purger ports through a reusable sequential conformance suite. It checks scoped collisions, exact lease/expiry boundaries, owner fencing, failed/stale/expired takeover, replay result bytes and integrity, authorization fingerprint matching, legacy secret rewrite, bounded purge continuation and independent ownership/lock protection. No production ledger or automatic retry policy is introduced.

The complete source/test inventory is enforced by `composer ownership`. Abstract suites and adapters stay in test-only autoload and remain outside production archives. [Test ownership](test-ownership.md) explains adapter reuse and the guarantees still requiring real host/database tests.

Local PHP 8.5.10: 40 tests / 154 assertions and ownership gate pass. The final PR must pass the full existing Composer/static/API/security/archive consumer and release automation gates. This proposed successor is not yet published or independently release-verified. A human merge, automated immutable publication and independent artifact/dependency attestation remain the release steps before later core adoption. No App integration or App acceptance result is claimed.
