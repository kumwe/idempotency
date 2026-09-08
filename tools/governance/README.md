# Authoritative governance validation

`composer governance:install` installs the exact locked development dependencies. `composer governance` runs full draft-2020-12 JSON Schema validation of the public API, capabilities, service map and complete discriminated migration handoff, then checks source, generated documentation, symbol ownership and manifest digests. The normal `composer check` and hosted source/archive gate require it. This development tooling and its dependencies are excluded from production archives.

The four schema snapshots are byte-for-byte copies of `kumwe/app` at `55bd9d22ed8846e5ad88b49fb77117a09f93fb76`, under `docs/architecture/governance/schemas/`. They are validation inputs, not package-owned schema forks. A future governance change must review the authoritative upstream snapshot before replacement.

`test.cjs` validates a real positive document set and independently mutates in-memory copies. Its refusals cover the actual invalid shapes found in the previously published metadata and missing, duplicate or stale cross-file ownership evidence. It never rewrites production artifacts to run a negative fixture.

For an intentional public source change, run `composer api:record`, `composer docs:record`, then regenerate the capability/service documents from `metadata.cjs` using the reviewed public manifest. Review and update the handoff symbol mapping and SHA-256 identities before running the complete gate. Version headings describe proposed releases until immutable publication is actually observed externally.
