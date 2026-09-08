#!/usr/bin/env bash
# Regression fixtures for previously stale readiness and dependency-release records.
set -euo pipefail
script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-dependency-readiness.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/resources/release-dependencies"
reset_fixture() {
  cat > "$work/composer.json" <<'JSON'
{"name":"kumwe/consumer","require":{"php":"^8.5","kumwe/upstream":"0.1.3"}}
JSON
  cat > "$work/resources/release-readiness.json" <<'JSON'
{"dependencies":{"kumwe/upstream":{"version":"0.1.3","attestation":null}}}
JSON
  cat > "$work/resources/release-dependencies/v1.json" <<'JSON'
{"schema":"kumwe-release-dependencies/v1","package":"kumwe/consumer",
 "dependencies":[{"package":"kumwe/upstream","constraint":"0.1.3"}]}
JSON
}
reject() {
  if bash "$script" "$work" > "$work/output" 2>&1; then
    echo "Accepted invalid dependency readiness fixture: $1" >&2
    exit 1
  fi
}
edit() {
  jq "$2" "$work/$1" > "$work/edited"
  mv "$work/edited" "$work/$1"
}
reset_fixture
bash "$script" "$work"
edit resources/release-readiness.json '.dependencies["kumwe/upstream"].version = "0.1.0"'
reject stale-readiness-version
reset_fixture
edit resources/release-dependencies/v1.json '.dependencies[0].constraint = "0.1.0"'
reject stale-release-dependency
reset_fixture
edit resources/release-readiness.json '.dependencies = {}'
reject missing-evidence-coordinate
reset_fixture
edit resources/release-readiness.json '.dependencies["kumwe/obsolete"] = {version:"0.1.0",attestation:null}'
reject obsolete-evidence-coordinate
reset_fixture
edit resources/release-dependencies/v1.json '.dependencies += .dependencies'
reject duplicate-release-dependency
reset_fixture
edit resources/release-dependencies/v1.json '.package = "kumwe/wrong-owner"'
reject wrong-owner
reset_fixture
edit composer.json '.require["kumwe/upstream"] = "^0.1.3"'
edit resources/release-readiness.json '.dependencies["kumwe/upstream"].version = "^0.1.3"'
edit resources/release-dependencies/v1.json '.dependencies[0].constraint = "^0.1.3"'
reject non-exact-requirement
reset_fixture
edit resources/release-readiness.json 'del(.dependencies["kumwe/upstream"].attestation)'
reject absent-attestation-state
reset_fixture
rm "$work/resources/release-dependencies/v1.json"
bash "$script" "$work"
echo 'Dependency readiness regression fixtures passed.'
