#!/usr/bin/env bash
# Isolated identity regression fixtures use fake GitHub responses and the real jq.
set -euo pipefail
checker="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/check-package-dependencies.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/baseline/api"
commit='1111111111111111111111111111111111111111'
other='2222222222222222222222222222222222222222'
cat > "$work/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ $# == 4 && "$1" == api && "$2" == --method && "$3" == GET ]] || exit 91
case "$4" in
  repos/kumwe/fixture-dependency/releases/tags/v1.2.3) file=release.json ;;
  repos/kumwe/fixture-dependency/git/ref/tags/v1.2.3) file=tag.json ;;
  repos/kumwe/fixture-dependency/git/tags/2222222222222222222222222222222222222222) file=annotated.json ;;
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
cat > "$work/baseline/api/release.json" <<'JSON'
{"id":456,"html_url":"https://github.com/kumwe/fixture-dependency/releases/tag/v1.2.3",
 "tag_name":"v1.2.3","draft":false,"prerelease":false,"immutable":false,"published_at":"2026-09-07T11:00:00Z"}
JSON
jq -n --arg sha "$commit" '{ref:"refs/tags/v1.2.3",object:{type:"commit",sha:$sha}}' > "$work/baseline/api/tag.json"
jq -n --arg sha "$commit" '{object:{type:"commit",sha:$sha}}' > "$work/baseline/api/annotated.json"
reset_fixture() {
  rm -rf "$work/case"
  cp -R "$work/baseline" "$work/case"
  export FIXTURE_ROOT="$work/case"
}
edit() {
  jq "$2" "$FIXTURE_ROOT/$1" > "$work/edited.json"
  mv "$work/edited.json" "$FIXTURE_ROOT/$1"
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
  local status='unverified'
  if [[ "$wanted" == pass ]]; then status='published-source-verified'; fi
  jq -e --arg status "$status" '.status == $status' "$FIXTURE_ROOT/result.json" >/dev/null
  count=$((count+1))
  printf 'PASS: %s\n' "$label"
}
reset_fixture
expect pass 'published mutable release with exact source identity passes without external evidence'
jq -e '.dependencies[0].commit == "1111111111111111111111111111111111111111" and
  (.dependencies[0] | has("immutable") or has("attestation") | not)' "$FIXTURE_ROOT/result.json" >/dev/null
edit api/release.json '.draft=true'
expect fail 'failed rerun invalidates an earlier passing report'
reset_fixture
edit api/release.json '.immutable=true'
expect pass 'published immutable release also passes'
edit api/release.json 'del(.immutable)'
expect pass 'immutable field is optional'
reset_fixture
edit api/tag.json '.object={type:"tag",sha:"2222222222222222222222222222222222222222"}'
expect pass 'annotated tag is peeled to the exact locked commit'
edit api/annotated.json '.object.sha="2222222222222222222222222222222222222222"'
expect fail 'annotated tag resolving to a different commit is rejected'
reset_fixture
edit composer.json 'del(.require["kumwe/fixture-dependency"])'
expect pass 'transitive resolved Kumwe runtime package is verified'
edit api/tag.json '.object.sha="2222222222222222222222222222222222222222"'
expect fail 'transitive resolved package tag mismatch is rejected'
reset_fixture
edit composer.json 'del(.require["kumwe/fixture-dependency"])'
edit composer.lock '.packages=[]'
rm -rf "$FIXTURE_ROOT/api"
expect pass 'no resolved Kumwe dependencies needs no GitHub request'
while IFS= read -r file && IFS= read -r expression && IFS= read -r label; do
  reset_fixture
  edit "$file" "$expression"
  expect fail "$label"
done <<'CASES'
composer.json
.require["kumwe/fixture-dependency"]="^1.2"
ranged direct dependency is rejected
composer.lock
.packages=[]
missing direct dependency in lock is rejected
composer.lock
.packages += .packages
duplicate locked package is rejected
composer.lock
.aliases=[{package:"kumwe/fixture-dependency",version:"1.2.3",alias:"1.2.4"}]
locked Kumwe alias is rejected
composer.lock
.packages[0].version="dev-main"
development version is rejected
composer.lock
.packages[0].version="v1.2.4"
different direct locked version is rejected
composer.lock
.packages[0].source.url="https://example.invalid/fork.git"
foreign source origin is rejected
composer.lock
.packages[0].source.reference="1111111"
short source reference is rejected
composer.lock
.packages[0].dist.reference="2222222222222222222222222222222222222222"
different dist reference is rejected
composer.lock
.packages[0].dist.url="https://example.invalid/archive.zip"
foreign dist origin is rejected
api/release.json
.draft=true
draft release is rejected
api/release.json
.prerelease=true
prerelease is rejected
api/release.json
.published_at=null
unpublished release is rejected
api/release.json
.tag_name="v9.9.9"
different release tag is rejected
api/tag.json
.object.sha="2222222222222222222222222222222222222222"
moved tag is rejected
api/tag.json
.ref="refs/tags/v9.9.9"
different tag identity is rejected
api/tag.json
.object.type="tree"
tag must resolve to a commit
CASES
reset_fixture
printf '{broken' > "$FIXTURE_ROOT/composer.lock"
expect fail 'malformed lock fails before iteration'
for file in release.json tag.json annotated.json; do
  reset_fixture
  if [[ "$file" == annotated.json ]]; then
    edit api/tag.json '.object={type:"tag",sha:"2222222222222222222222222222222222222222"}'
  fi
  rm "$FIXTURE_ROOT/api/$file"
  expect fail "$file API failure is rejected"
done
printf '%s package dependency identity fixtures passed.\n' "$count"
