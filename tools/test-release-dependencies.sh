#!/usr/bin/env bash
# Isolated regression fixtures: every network observation is provided by a fake gh.
set -euo pipefail
checker="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/check-release-dependencies.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/baseline/resources" "$work/baseline/api"
commit='1111111111111111111111111111111111111111'
other='2222222222222222222222222222222222222222'
digest='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
cat > "$work/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ $# == 4 && "$1" == api && "$2" == --method && "$3" == GET ]] || exit 91
case "$4" in
  repos/kumwe/fixture-dependency/releases/tags/v1.2.3) file=release.json ;;
  repos/kumwe/fixture-dependency/git/ref/tags/v1.2.3) file=tag.json ;;
  repos/kumwe/fixture-dependency/git/tags/2222222222222222222222222222222222222222) file=annotated.json ;;
  repos/kumwe/fixture-evidence/contents/records/RELEASE-ATTESTATION.json?ref=2222222222222222222222222222222222222222)
    jq -n --arg content "$(base64 < "$FIXTURE_ROOT/attestation.json")" \
      '{type:"file",path:"records/RELEASE-ATTESTATION.json",encoding:"base64",content:$content}'
    exit 0 ;;
  repos/kumwe/fixture-dependency/actions/runs/123) file=run.json ;;
  *) printf 'Unexpected fixture network request: %s\n' "$4" >&2; exit 92 ;;
esac
cat "$FIXTURE_ROOT/api/$file"
SH
chmod +x "$work/bin/gh"
export PATH="$work/bin:$PATH"
cat > "$work/baseline/composer.json" <<'JSON'
{"name":"kumwe/fixture-consumer","require":{"php":"^8.5","kumwe/fixture-dependency":"1.2.3"}}
JSON
jq -n --arg sha "$commit" '{packages:[{name:"kumwe/fixture-dependency",version:"v1.2.3",
  source:{type:"git",url:"https://github.com/kumwe/fixture-dependency.git",reference:$sha},
  dist:{type:"zip",url:("https://api.github.com/repos/kumwe/fixture-dependency/zipball/"+$sha),reference:$sha}}]}
' > "$work/baseline/composer.lock"
jq -n --arg commit "$commit" --arg digest "$digest" '{
  schema:"kumwe-release-attestation/v2",artifact_kind:"framework_php",
  repository:"https://github.com/kumwe/fixture-dependency",merge_commit:$commit,version:"1.2.3",tag:"v1.2.3",
  source_archive:{url:"https://github.com/kumwe/fixture-dependency/archive/refs/tags/v1.2.3.tar.gz",sha256:$digest},
  artifacts:[{identity:"Fixture Composer dist",
    url:("https://api.github.com/repos/kumwe/fixture-dependency/zipball/"+$commit),sha256:$digest}],
  manifests_and_corpora:[{path:"resources/public-api/v1.json",sha256:$digest}],
  release_workflow:"https://github.com/kumwe/fixture-dependency/actions/runs/123 (release-on-record, success)",
  registry_or_pie_verification:["Fixture registry observation"],
  clean_consumer_or_build_verification:["Fixture independent consumer observation"],
  verified_at:"2026-09-07T12:00:00Z",verified_by:"Independent test fixture",status:"verified"
}' > "$work/baseline/attestation.json"
attestation_sha="$(sha256sum "$work/baseline/attestation.json")"
jq -n --arg commit "$other" --arg sha "${attestation_sha%% *}" '{
  status:"blocked",reason:"Historical state must not override current observations.",
  dependencies:{"kumwe/fixture-dependency":{version:"1.2.3",attestation:{
    repository:"kumwe/fixture-evidence",commit:$commit,path:"records/RELEASE-ATTESTATION.json",sha256:$sha}}}
}' > "$work/baseline/resources/release-readiness.json"
cat > "$work/baseline/api/release.json" <<'JSON'
{"id":456,"html_url":"https://github.com/kumwe/fixture-dependency/releases/tag/v1.2.3",
 "tag_name":"v1.2.3","draft":false,"prerelease":false,"immutable":true,"published_at":"2026-09-07T11:00:00Z"}
JSON
jq -n --arg sha "$commit" '{ref:"refs/tags/v1.2.3",object:{type:"commit",sha:$sha}}' > "$work/baseline/api/tag.json"
jq -n --arg sha "$commit" '{object:{type:"commit",sha:$sha}}' > "$work/baseline/api/annotated.json"
jq -n --arg sha "$commit" '{repository:{full_name:"kumwe/fixture-dependency"},head_sha:$sha,
  status:"completed",conclusion:"success",event:"push",path:".github/workflows/release-on-record.yml"}
' > "$work/baseline/api/run.json"

reset_fixture() {
  rm -rf "$work/case"
  cp -R "$work/baseline" "$work/case"
  export FIXTURE_ROOT="$work/case"
}
edit() {
  local file="$1" expression="$2"
  jq "$expression" "$FIXTURE_ROOT/$file" > "$work/edited.json"
  mv "$work/edited.json" "$FIXTURE_ROOT/$file"
}
bind_attestation() {
  local observed
  observed="$(sha256sum "$FIXTURE_ROOT/attestation.json")"
  edit resources/release-readiness.json \
    ".dependencies[\"kumwe/fixture-dependency\"].attestation.sha256 = \"${observed%% *}\""
}
count=0
expect() {
  local wanted="$1" label="$2" actual=0
  bash "$checker" "$FIXTURE_ROOT" "$FIXTURE_ROOT/result.json" > "$work/stdout" 2> "$work/stderr" || actual=$?
  if [[ "$wanted" == pass && "$actual" != 0 ]] || [[ "$wanted" == fail && "$actual" == 0 ]]; then
    printf 'FAIL: %s (exit %s)\n' "$label" "$actual" >&2
    cat "$work/stdout" "$work/stderr" >&2
    exit 1
  fi
  if [[ "$wanted" == fail ]] && jq -e '.status == "verified"' "$FIXTURE_ROOT/result.json" >/dev/null 2>&1; then
    printf 'FAIL: unsuccessful verification wrote a passing evidence file: %s\n' "$label" >&2
    exit 1
  fi
  count=$((count+1))
  printf 'PASS: %s\n' "$label"
}

reset_fixture
expect pass 'exact immutable release with external evidence passes despite historical blocked status'
jq -e '.status == "verified" and .dependencies[0].commit == "1111111111111111111111111111111111111111"' \
  "$FIXTURE_ROOT/result.json" >/dev/null
edit api/release.json '.immutable=false'
expect fail 'a failed rerun invalidates earlier passing evidence at the same output path'
reset_fixture
edit api/tag.json '.object={type:"tag",sha:"2222222222222222222222222222222222222222"}'
expect pass 'annotated release tag is peeled before comparing the locked commit'
reset_fixture
edit attestation.json '.release_workflow="https://github.com/kumwe/fixture-dependency/actions/runs/123"'
bind_attestation
expect pass 'external workflow URL without prose is supported'
reset_fixture
edit composer.json 'del(.require["kumwe/fixture-dependency"])'
edit composer.lock '.packages=[]'
rm "$FIXTURE_ROOT/resources/release-readiness.json"
expect pass 'package without Kumwe runtime dependencies needs no evidence coordinates'

while IFS= read -r file && IFS= read -r expression && IFS= read -r label; do
  reset_fixture
  edit "$file" "$expression"
  if [[ "$file" == attestation.json ]]; then bind_attestation; fi
  expect fail "$label"
done <<'CASES'
composer.json
.require["kumwe/fixture-dependency"]="^1.2"
ranged dependency is rejected
composer.lock
.packages=[]
missing or replaced direct dependency is rejected
composer.lock
.packages += .packages
duplicate locked package is rejected
composer.lock
.packages[0].version="dev-main"
development version is rejected
composer.lock
.packages[0].version="v1.2.4"
different resolved stable version is rejected
composer.lock
.packages[0].dist.reference="2222222222222222222222222222222222222222"
source and dist references must match
composer.lock
.packages[0].dist.url="https://example.invalid/archive.zip"
foreign dist origin is rejected
api/release.json
.immutable=false
mutable release is rejected
api/release.json
del(.immutable)
missing immutable observation is rejected
api/release.json
.draft=true
draft release is rejected
api/release.json
.prerelease=true
prerelease is rejected
api/tag.json
.object.sha="2222222222222222222222222222222222222222"
rebased or moved tag mismatch is rejected
api/tag.json
.ref="refs/tags/v9.9.9"
different tag identity is rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].attestation=null
missing external attestation is rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].version="1.2.2"
evidence coordinates for another version are rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].attestation.repository="kumwe/fixture-dependency"
self-issued evidence in dependency repository is rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].attestation.commit="main"
moving evidence branch coordinate is rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].attestation.path="records/../RELEASE-ATTESTATION.json"
ambiguous evidence path is rejected
resources/release-readiness.json
.dependencies["kumwe/fixture-dependency"].attestation.sha256=("a" * 64)
changed external evidence bytes are rejected
attestation.json
.status="failed"
failed independent verification cannot become verified
attestation.json
.version="1.2.2"
attestation for older dependency version is rejected
attestation.json
.merge_commit="2222222222222222222222222222222222222222"
attestation for a different commit is rejected
attestation.json
.clean_consumer_or_build_verification=[]
missing independent clean consumer evidence is rejected
attestation.json
.known_gaps=["Unverified archive"]
known gaps prevent dependency publication
attestation.json
.artifacts[0].url="https://example.invalid/other.zip"
attested artifact must be the Composer dist
attestation.json
.release_workflow="https://github.com/kumwe/fixture-dependency/actions/runs/123/attempts/1"
ambiguous workflow coordinate is rejected
api/run.json
.conclusion="failure"
failed release workflow is rejected
api/run.json
.head_sha="2222222222222222222222222222222222222222"
successful workflow for another commit is rejected
api/run.json
.event="pull_request"
branch PR success cannot substitute for released-commit verification
api/run.json
.path=".github/workflows/ci.yml"
ordinary CI cannot substitute for the publication workflow
CASES

reset_fixture
printf '{broken' > "$FIXTURE_ROOT/composer.lock"
expect fail 'invalid JSON fails closed before iteration'
reset_fixture
rm "$FIXTURE_ROOT/api/release.json"
expect fail 'release API failure fails closed'
printf 'Dependency release verification: %s fixtures passed.\n' "$count"
