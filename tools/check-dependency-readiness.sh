#!/usr/bin/env bash
# Keep recorded dependency evidence aligned with the exact production Composer graph.
set -euo pipefail
[[ $# -le 1 ]] || { echo 'Usage: check-dependency-readiness.sh [PACKAGE_ROOT]' >&2; exit 2; }
root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
fail() { echo "Dependency readiness: $*" >&2; exit 1; }
manifest="$root/composer.json"
readiness="$root/resources/release-readiness.json"
[[ -f "$manifest" && -f "$readiness" ]] || fail 'Composer and readiness manifests are required.'
jq -e --slurpfile readiness "$readiness" '
  (.require // {} | with_entries(select(.key | startswith("kumwe/")))) as $required
  | ($readiness[0].dependencies // null) as $recorded
  | ($recorded | type == "object")
    and ($required | to_entries | all(.value | test("^[0-9]+\\.[0-9]+\\.[0-9]+$")))
    and (($required | keys) == ($recorded | keys))
    and ($required | to_entries | all(. as $entry |
      $recorded[$entry.key].version == $entry.value
      and ($recorded[$entry.key] | has("attestation"))))
' "$manifest" >/dev/null || fail 'readiness versions must match every exact stable Kumwe requirement.'
dependencies="$root/resources/release-dependencies/v1.json"
if [[ -f "$dependencies" ]]; then
  jq -e --slurpfile dependencies "$dependencies" '
    (.require // {} | with_entries(select(.key | startswith("kumwe/")))) as $required
    | $dependencies[0] as $recorded
    | $recorded.schema == "kumwe-release-dependencies/v1"
      and $recorded.package == .name
      and ($recorded.dependencies | type == "array")
      and (($recorded.dependencies | map(.package) | sort) == ($required | keys))
      and ($recorded.dependencies | all(. as $entry | $required[$entry.package] == $entry.constraint))
  ' "$manifest" >/dev/null || fail \
    'release dependency inventory must match Composer without missing or duplicate entries.'
fi
echo 'Exact Composer requirements and dependency evidence coordinates agree.'
