---
schema: kumwe-migration-handoff/v2
artifact_kind: framework_php
migration_id: KUMWE-MIG-2026-020
change_set: KUMWE-CS-2026-020
state: draft_pr_open
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: 24ecf956423c18933e824b43cea1bfb9127a79a9
    examined_paths:
    - app/src/Application/Automation/IdempotencyPurger.php
    - app/src/Application/Automation/IdempotencyRecord.php
    - app/src/Application/Automation/IdempotencyResult.php
    - app/src/Application/Automation/IdempotencyState.php
    - app/src/Application/Idempotency/IdempotencyLedger.php
    - app/src/Application/Idempotency/SecretOnceIdempotencyLedger.php
    - app/src/Delivery/Http/Api/Idempotency/IdempotencyKey.php
    old_namespace_roots:
    - Kumwe\App\Application\Automation
    - Kumwe\App\Delivery\Http\Api\Idempotency
    - Kumwe\Extension\Spi\Application\Automation
    capability_index_sha256: null
  semantic_inputs:
  - owner: kumwe/canonical-json
    version_or_commit: v0.1.1
    manifest_or_corpus: resources/corpus/v1.json
    sha256: 84d21b12e7a2bfd752356d9a6e664bcb332e209d19017e7634e7485a4fa4e250
  examined_dependencies:
  - kumwe/canonical-json
  active_related_pull_requests: []
target:
  repository: https://github.com/kumwe/idempotency
  artifact_identity: kumwe/idempotency
  canonical_namespace_or_abi: Kumwe\Idempotency
  branch: "agent/complete-ledger-conformance"
  pull_request: "https://github.com/kumwe/idempotency/pull/4"
ownership:
  responsibility: Immutable replay identities, request fingerprints, captured results,
    state transitions and durable ledger ports.
  non_responsibilities:
  - database adapters
  - authorization
  - transaction coupling
  - retention
  - external effects
  allowed_dependency_ceiling:
  - kumwe/canonical-json
  implementation_owner: kumwe/idempotency
  next_consumer: kumwe/app
  public_manifests:
  - path: resources/capabilities/v1.json
    sha256: b86bccea5aeb45d93047f9409f27d9e558aa36528482f71bc02812092bfc4ca6
  - path: resources/service-map/v1.json
    sha256: e1a4a14ad2688ecbfcd4e0059d16d460e2ef6becad3d67e945f10b61529d426e
  - path: resources/public-api/v1.json
    sha256: aec7adf3a40cc48ba4417f4ee29dbe89a5f1ba63206ab45e3d27a202fdfb5130
  intentionally_excluded:
  - App infrastructure, middleware, operational scheduling and consumer integration
    tests
framework_php:
  composer_package: kumwe/idempotency
  canonical_namespace: Kumwe\Idempotency
  public_api_manifest: resources/public-api/v1.json
  capability_manifest: resources/capabilities/v1.json
  service_map: resources/service-map/v1.json
  extracted_symbols:
  - old_fqcn: Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey
    new_fqcn: Kumwe\Idempotency\IdempotencyKey
    source_path: app/src/Delivery/Http/Api/Idempotency/IdempotencyKey.php
    target_path: src/IdempotencyKey.php
    kind: class
    public_methods:
    - fromString
    - fromHeader
    - value
    - equals
    - __toString
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Idempotency\IdempotencyLedger
    new_fqcn: Kumwe\Idempotency\IdempotencyLedger
    source_path: app/src/Application/Idempotency/IdempotencyLedger.php
    target_path: src/IdempotencyLedger.php
    kind: interface
    public_methods:
    - reserve
    - find
    - takeOverExpired
    - takeOverFailed
    - takeOverStale
    - complete
    - release
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Automation\IdempotencyPurger
    new_fqcn: Kumwe\Idempotency\IdempotencyPurger
    source_path: app/src/Application/Automation/IdempotencyPurger.php
    target_path: src/IdempotencyPurger.php
    kind: interface
    public_methods:
    - purgeExpired
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Automation\IdempotencyRecord
    new_fqcn: Kumwe\Idempotency\IdempotencyRecord
    source_path: app/src/Application/Automation/IdempotencyRecord.php
    target_path: src/IdempotencyRecord.php
    kind: class
    public_methods:
    - begin
    - fingerprintProfile
    - createdAt
    - key
    - subject
    - operation
    - requestDigest
    - state
    - expiresAt
    - isExpiredAt
    - assertRequestMatches
    - complete
    - fail
    - replay
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Automation\IdempotencyResult
    new_fqcn: Kumwe\Idempotency\IdempotencyResult
    source_path: app/src/Application/Automation/IdempotencyResult.php
    target_path: src/IdempotencyResult.php
    kind: class
    public_methods:
    - __construct
    - fingerprintProfile
    - statusCode
    - body
    - bodyDigest
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Automation\IdempotencyState
    new_fqcn: Kumwe\Idempotency\IdempotencyState
    source_path: app/src/Application/Automation/IdempotencyState.php
    target_path: src/IdempotencyState.php
    kind: enum
    public_methods: []
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  - old_fqcn: Kumwe\App\Application\Idempotency\SecretOnceIdempotencyLedger
    new_fqcn: Kumwe\Idempotency\SecretOnceIdempotencyLedger
    source_path: app/src/Application/Idempotency/SecretOnceIdempotencyLedger.php
    target_path: src/SecretOnceIdempotencyLedger.php
    kind: interface
    public_methods:
    - reserve
    - find
    - takeOver
    - confirmLease
    - complete
    - rewriteStoredResult
    - release
    public_properties: []
    public_constants: []
    exceptions:
    - InvalidArgumentException
    - DomainException
    serialization_contract: Documented PHP scalar/array projections; no native PHP
      serialized object is a durable wire contract.
    compatibility: Explicit required CanonicalEncoder on digest operations; detached
      snapshot semantics.
  consumers:
    app_code:
    - Source namespace imports and host adapters named in the integration guide
    configuration_and_di:
    - Host port bindings and existing canonical encoder service
    reflection_and_string_references:
    - Recompute namespace closure before adoption
    fixtures_and_examples:
    - examples/typed-consumer.php
    external: []
  dependency_injection:
    mode: direct
    provider: null
    factories: []
    aliases: []
    service_lifetimes: []
    configuration_keys: []
    provider_absence_reason: Pure immutable values, port contracts and directly callable
      static helpers; no injected runtime service is exported.
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - "tests/LedgerConformanceTest.php"
    - "tests/Conformance/"
    - "tests/Fixture/"
    - "tests/ownership.json"
    - tests/IdempotencyTest.php
  remain_in_app_or_consumer:
  - Host transaction atomicity, adapter parity, authorization, concurrency, recovery
    and database matrix
  split_tests: []
  prohibited_duplicates:
  - Remove implementation-unit counterparts only during separately verified App adoption
  corpora:
  - Literal canonical material and hostile metadata/replay vectors in package tests
documentation:
  charter: CHARTER.md
  readme: README.md
  public_api: docs/public-api.md
  architecture: docs/architecture.md
  integration_or_consumer: docs/integration.md
  examples:
  - examples/typed-consumer.php
  changelog_record: CHANGELOG.md / 0.1.1
release_expectations:
  version_policy: Pre-1.0 exact immutable pin only after reviewed release; no release
    claimed.
  expected_artifact_types:
  - Composer ZIP
  required_checks:
  - composer check
  - bash tools/check-release-dependencies.sh
  - external release attestation
  required_registry_or_installer: Composer
  required_external_attestation: true
next_task:
  phase_name: Immutable dependency admission and human package review, then separate
    release verification
  permitted_only_when:
  - All package gates pass
  - Exact canonical-json 0.1.1 dependency has independent external attestation
  - Human review and merge
  consumer_repository: kumwe/app
  dependency_or_native_change: Retain exact canonical-json 0.1.1; verify its external
    attestation and the Idempotency successor before consumer adoption
  namespace_or_api_replacements:
  - from: Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey
    to: Kumwe\Idempotency\IdempotencyKey
  - from: Kumwe\App\Application\Idempotency\IdempotencyLedger
    to: Kumwe\Idempotency\IdempotencyLedger
  - from: Kumwe\App\Application\Automation\IdempotencyPurger
    to: Kumwe\Idempotency\IdempotencyPurger
  - from: Kumwe\App\Application\Automation\IdempotencyRecord
    to: Kumwe\Idempotency\IdempotencyRecord
  - from: Kumwe\App\Application\Automation\IdempotencyResult
    to: Kumwe\Idempotency\IdempotencyResult
  - from: Kumwe\App\Application\Automation\IdempotencyState
    to: Kumwe\Idempotency\IdempotencyState
  - from: Kumwe\App\Application\Idempotency\SecretOnceIdempotencyLedger
    to: Kumwe\Idempotency\SecretOnceIdempotencyLedger
  files_to_update:
  - composer.json
  - composer.lock
  - App explicit adapter/encoder service bindings
  files_to_remove:
  - app/src/Delivery/Http/Api/Idempotency/IdempotencyKey.php
  - app/src/Application/Idempotency/IdempotencyLedger.php
  - app/src/Application/Automation/IdempotencyPurger.php
  - app/src/Application/Automation/IdempotencyRecord.php
  - app/src/Application/Automation/IdempotencyResult.php
  - app/src/Application/Automation/IdempotencyState.php
  - app/src/Application/Idempotency/SecretOnceIdempotencyLedger.php
  tests_to_remove:
  - Implementation-unit tests corresponding to package tests after verified adoption
  tests_to_retain_or_add:
  - All host database, authorization, transaction, concurrency and recovery tests
  di_or_provisioning_changes:
  - Bind ports to host adapters; inject CanonicalEncoder explicitly
  capability_index_changes:
  - Record verified package ownership only during consumer adoption
  changelog_and_evidence_changes:
  - Record exact verified source and artifacts in external attestation
  verification_commands:
  - composer check
  - bash tools/check-release-dependencies.sh
concurrency:
  likely_conflict_files:
  - App composer.json and composer.lock
  - Host DI bindings
  related_migrations:
  - Published CanonicalEncoder 0.1.1 contract and corpus
  ownership_conflicts: []
  integration_train: null
  resolution_rule: semantic-preservation
governance:
  roadmap_source_sha256: a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8
  roadmap_refs: []
  non_roadmap_refs:
  - Portable package extraction
  completion_claim: false
decisions:
- CanonicalEncoder is required and never defaulted.
- No App adoption, merge, tag or release in this task.
- Candidate dependency ZIP isolation is not release provenance.
blockers:
- Independent external dependency and successor release attestations remain required.
---

## Migration/implementation summary

The portable source and tests are implemented in this package. Host infrastructure remains in App. Source/API mapping and explicit ownership appear above.

## Public API and responsibility

[Public API](docs/public-api.md), [architecture](docs/architecture.md) and [integration](docs/integration.md) define complete signatures, bounds, exceptions and authority.

## Capability reuse/semantic input review

The explicit generic-v1 canonical port is reused; no executor or vendor algorithm is copied. The exact runtime dependency is canonical-json 0.1.1 at e7006a2580a49a1c8ab507b0d7b9c3403b4f9f58; its corpus digest is recorded above. Publication is observed, while independent external attestation remains a separate requirement.

## Consumer inventory

The extracted-symbol mapping identifies old App/SDK imports. Before Phase 2 recompute imports, constructor calls, reflection strings, configuration and fixtures with `rg` against the current consumer commit. Inject the existing host canonical service through the port and leave adapter authority in App.

## Test ownership

The sequential port contract is now executable through `tests/LedgerConformanceTest.php`, the reusable `tests/Conformance/` suite and explicitly test-only `tests/Fixture/` adapters. `tests/ownership.json` and `composer ownership` enforce the complete source/test inventory. See [test ownership](docs/test-ownership.md) for reuse and the retained host responsibilities.

Package tests own portable behavior and refusal cases. Original App tests remain temporarily because this is Phase 1; remove those implementation copies only in the separate verified adoption. Host concurrency, security, rollback and database tests remain.

## Next-task execution notes

Review PR #4 and the completed sequential conformance suite. The dependency already pins published canonical-json 0.1.1; no development dependency replacement remains. Verify its independent attestation, then human review/merge and normal release automation can produce the successor. Independently verify that artifact before any App namespace migration or class deletion.

## Drift check

Compare current App and SDK source against the source commits above. Reusable semantic changes require a separate upstream package change and release; do not maintain a host shadow implementation.

## Validation recipe and observed local results

Current conformance follow-up on PHP 8.5.10: 40 tests / 154 assertions; the ownership inventory gate also passes. Published baseline 0.1.0 is observed at 26ec2ac31c493a088dd2bd01983b7428692bd1ea. The 0.1.1 heading proposes a successor, not an observed release. Final-head full CI and independent artifact verification remain required.

Run `composer check`, `composer autoload:smoke`, `composer examples` and the release automation tests. PHP 8.5.10 behavior and conformance tests passed: 40 tests / 154 assertions. Final tested commits/trees and archive identities belong to external evidence, never self-referential handoff claims. Release/dependent-publication eligibility is not claimed.
