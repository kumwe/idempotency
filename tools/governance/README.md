# Authoritative contract validation

`composer governance:install` installs the locked development dependencies. `composer governance` validates
full draft-2020-12 JSON Schemas for the public API, capabilities, service map and discriminated package release record,
then checks source, generated documentation, symbol ownership and manifest digests. The complete package and archive
consumer gates require these checks. Tooling and dependencies are excluded from production archives.

The three package manifest schemas are byte-for-byte snapshots of kumwe/app commit
`55bd9d22ed8846e5ad88b49fb77117a09f93fb76`, under `docs/architecture/governance/schemas/`.
The release record schema is the maintained `kumwe-package-release-record/v1` contract from the Extension SDK's
`tools/release-verification/package-release-record.v1.schema.json`. Review authoritative upstream changes before
replacing any snapshot. Older published artifacts retain their original schema and are supported by the SDK verifier.

`test.cjs` validates the real positive document set and independently mutates in-memory copies. Refusals cover
invalid schemas, obsolete process fields and missing, duplicate or stale cross-file ownership evidence. Negative
fixtures never rewrite production artifacts.

For public source changes, run `composer api:record` and `composer docs:record`, then regenerate capability/service
documents from `metadata.cjs` using the reviewed public manifest. Update release-record mappings and SHA-256
identities before the complete gate. Actual publication and independent verification are observed externally.
