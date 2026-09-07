# Releasing

The full package tests and clean consumer checks run on pull requests and the
actual default-branch event commit. Normal publication uses the release helpers
from Business Definition main 2cbb7f0430b4a950c129064b380ecf2378cc6662.

A recorded version requires exact stable Kumwe dependencies, whose published tags
must match Composer's source and dist commits. Branch protection, GitHub's optional
immutable-release setting and independent attestations are not normal publication
prerequisites. Independent verification remains separately available through
`tools/check-release-dependencies.sh`.

The current source candidate requires `CanonicalEncoder` from Canonical JSON
PR #7. That interface is not in the published `v0.1.0` dependency. Keep the source
candidate until the upstream port has a stable release, then select and verify
that exact version before publishing Idempotency. Passing source CI alone does
not establish this package's publication readiness.

An Unreleased-only changelog skips publication-specific dependency checks after
the full package gate passes. Existing tags and releases are never replaced.
