# Releasing

The full package tests and clean consumer checks run on pull requests and the
actual default-branch event commit. Normal publication uses the release helpers
from Business Definition main 2cbb7f0430b4a950c129064b380ecf2378cc6662.

A recorded version requires exact stable Kumwe dependencies, whose published tags
must match Composer's source and dist commits. Branch protection, GitHub's optional
immutable-release setting and independent attestations are not normal publication
prerequisites. Independent verification remains separately available through
`tools/check-release-dependencies.sh`.

The runtime requirement is published `kumwe/canonical-json` 0.1.1, including the
`CanonicalEncoder` port. Its tag identifies e7006a2580a49a1c8ab507b0d7b9c3403b4f9f58.
`resources/release-readiness.json` records that exact coordinate with a null external
attestation until independently provided; `composer dependency-readiness` rejects
stale or floating coordinates. An optional stricter attestation check is available
through `tools/check-release-dependencies.sh`; it is not an assertion that independent
verification has already passed. Passing source CI is distinct from verification of
the published Idempotency successor.

An Unreleased-only changelog skips publication-specific dependency checks after
the full package gate passes. Existing tags and releases are never replaced.
