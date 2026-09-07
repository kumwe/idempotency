#!/usr/bin/env bash
# Verify published dependency identity against the resolved Composer source and dist.
set -euo pipefail
fail() { printf 'Package dependency refused: %s\n' "$*" >&2; exit 1; }
[[ $# -le 2 ]] || { echo 'Usage: check-package-dependencies.sh [PACKAGE_ROOT [REPORT.json]]' >&2; exit 2; }
root="${1:-.}"
output="${2:-}"
schema='kumwe-package-dependency-sources/v1'
if [[ -n "$output" ]]; then
  printf '{"schema":"%s","status":"unverified","dependencies":[]}\n' "$schema" > "$output"
fi
for command in jq gh; do
  command -v "$command" >/dev/null || fail "required command is missing: $command"
done
[[ -f "$root/composer.json" && -f "$root/composer.lock" ]] ||
  fail 'run composer install first; composer.json and composer.lock are required.'
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
jq -e '.require | type == "object"' "$root/composer.json" >/dev/null || fail 'invalid Composer requirements.'
jq -e '.packages | type == "array"' "$root/composer.lock" >/dev/null || fail 'invalid Composer lock.'
jq '[.require | to_entries[] | select(.key | startswith("kumwe/"))]' "$root/composer.json" > "$work/direct.json"
jq '[.packages[] | select(.name | startswith("kumwe/"))]' "$root/composer.lock" > "$work/resolved.json"
jq -e '
  all(.[]; (.key | test("^kumwe/[a-z0-9][a-z0-9-]*$")) and
    (.value | type == "string" and test("^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$")))
' "$work/direct.json" >/dev/null || fail 'direct Kumwe dependencies must use exact stable versions without aliases.'
jq -e --slurpfile direct "$work/direct.json" '
  . as $locked | all($direct[0][]; . as $required |
    [$locked[] | select(.name == $required.key and (.version | ltrimstr("v")) == $required.value)] | length == 1)
' "$work/resolved.json" >/dev/null || fail 'Composer lock must contain each exact required Kumwe package once.'
jq -e 'length == (map(.name) | unique | length)' "$work/resolved.json" >/dev/null ||
  fail 'duplicate Kumwe packages in Composer lock.'
jq -e '(.aliases // []) | all(.[]; (.package | startswith("kumwe/") | not))' \
  "$root/composer.lock" >/dev/null || fail 'Kumwe version aliases are not supported.'
printf '[]\n' > "$work/evidence.json"
jq -c '.[]' "$work/resolved.json" > "$work/packages.jsonl"
while IFS= read -r locked; do
  name="$(jq -r .name <<< "$locked")"
  version="$(jq -r '.version | ltrimstr("v")' <<< "$locked")"
  [[ "$name" =~ ^kumwe/[a-z0-9][a-z0-9-]*$ ]] || fail "invalid package coordinate: $name"
  [[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] ||
    fail "$name resolved to a non-stable version: $version"
  tag="v$version"
  jq -e --arg name "$name" '
    .source.type == "git" and
    (.source.url == ("https://github.com/" + $name + ".git") or .source.url == ("https://github.com/" + $name)) and
    (.source.reference | type == "string" and test("^[a-f0-9]{40}$")) and
    .dist.type == "zip" and .dist.reference == .source.reference and
    .dist.url == ("https://api.github.com/repos/" + $name + "/zipball/" + .source.reference)
  ' <<< "$locked" >/dev/null || fail "$name $version must resolve source and dist to the same GitHub commit."
  commit="$(jq -r .source.reference <<< "$locked")"
  gh api --method GET "repos/$name/releases/tags/$tag" > "$work/release.json" ||
    fail "cannot retrieve $name $tag release."
  jq -e --arg tag "$tag" '
    .tag_name == $tag and .draft == false and .prerelease == false and
    (.published_at | type == "string" and length > 0)
  ' "$work/release.json" >/dev/null || fail "$name $tag must be a published stable release."
  gh api --method GET "repos/$name/git/ref/tags/$tag" > "$work/tag.json" || fail "cannot resolve $name $tag."
  jq -e --arg tag "$tag" '.ref == ("refs/tags/" + $tag)' "$work/tag.json" >/dev/null ||
    fail "$name returned a different tag."
  for ((depth=0; depth<6; depth++)); do
    object_type="$(jq -r '.object.type' "$work/tag.json")"
    object_sha="$(jq -r '.object.sha' "$work/tag.json")"
    [[ "$object_sha" =~ ^[a-f0-9]{40}$ ]] || fail "$name tag returned an invalid object."
    if [[ "$object_type" == commit ]]; then break; fi
    [[ "$object_type" == tag && "$depth" -lt 5 ]] ||
      fail "$name tag must resolve to a commit within five annotated tags."
    gh api --method GET "repos/$name/git/tags/$object_sha" > "$work/next-tag.json" ||
      fail "cannot peel $name annotated tag."
    mv "$work/next-tag.json" "$work/tag.json"
  done
  [[ "$object_sha" == "$commit" ]] || fail "$name $tag does not match the Composer source/dist commit."
  jq --arg name "$name" --arg version "$version" --arg commit "$commit" --arg tag "$tag" \
    --slurpfile release "$work/release.json" \
    '. + [{package: $name, version: $version, tag: $tag, commit: $commit,
      release_url: $release[0].html_url, published_at: $release[0].published_at}]' \
    "$work/evidence.json" > "$work/next-evidence.json"
  mv "$work/next-evidence.json" "$work/evidence.json"
  printf 'Published dependency source verified: %s %s at %s.\n' "$name" "$version" "$commit"
done < "$work/packages.jsonl"
if [[ ! -s "$work/packages.jsonl" ]]; then echo 'No resolved Kumwe runtime dependencies require verification.'; fi
if [[ -n "$output" ]]; then
  jq --arg schema "$schema" '{schema: $schema, status: "published-source-verified", dependencies: .}' \
    "$work/evidence.json" > "$output"
fi
