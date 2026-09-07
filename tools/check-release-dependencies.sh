#!/usr/bin/env bash
# Verify resolved Kumwe dependencies using live release metadata and external evidence.
# No branch SHA, checked-in status label, or local test result grants release eligibility.
set -euo pipefail

fail() { printf 'Dependency release refused: %s\n' "$*" >&2; exit 1; }

if [[ $# -gt 2 ]]; then
  echo 'Usage: check-release-dependencies.sh [PACKAGE_ROOT [EVIDENCE_OUTPUT.json]]' >&2
  exit 2
fi
root="${1:-.}"
output="${2:-}"
# A failed rerun must not leave an earlier passing observation at the requested path.
if [[ -n "$output" ]]; then
  printf '{"schema":"kumwe-dependency-verification/v1","status":"unverified","dependencies":[]}\n' > "$output"
fi
for command in jq gh sha256sum base64; do
  command -v "$command" >/dev/null || fail "required command is missing: $command"
done
[[ -f "$root/composer.json" && -f "$root/composer.lock" ]] ||
  fail 'run composer install first; composer.json and composer.lock are required.'
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Read files through jq before iteration: a parse failure must never become an empty success.
jq -e '.require | type == "object"' "$root/composer.json" >/dev/null || fail 'invalid Composer requirements.'
jq -e '.packages | type == "array"' "$root/composer.lock" >/dev/null || fail 'invalid Composer lock.'
jq '[.require | to_entries[] | select(.key | startswith("kumwe/"))]' "$root/composer.json" > "$work/direct.json"
jq '[.packages[] | select(.name | startswith("kumwe/"))]' "$root/composer.lock" > "$work/resolved.json"

jq -e '
  all(.[]; (.key | test("^kumwe/[a-z0-9][a-z0-9-]*$")) and
    (.value | type == "string" and test("^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$")))
' "$work/direct.json" >/dev/null ||
  fail 'direct Kumwe dependencies must use exact stable versions, without aliases or ranges.'
jq -e --slurpfile direct "$work/direct.json" '
  . as $locked | all($direct[0][]; . as $required |
    [$locked[] | select(.name == $required.key and (.version | ltrimstr("v")) == $required.value)] | length == 1)
' "$work/resolved.json" >/dev/null ||
  fail 'Composer lock must contain each exact required Kumwe package once; replace/provide aliases are not a release.'
jq -e 'length == (map(.name) | unique | length)' "$work/resolved.json" >/dev/null ||
  fail 'duplicate Kumwe packages in Composer lock.'

if [[ "$(jq length "$work/resolved.json")" == 0 ]]; then
  report='{"schema":"kumwe-dependency-verification/v1","status":"verified","dependencies":[]}'
  if [[ -n "$output" ]]; then printf '%s\n' "$report" > "$output"; fi
  echo 'No resolved Kumwe runtime dependencies require release verification.'
  exit 0
fi
readiness="$root/resources/release-readiness.json"
[[ -f "$readiness" ]] ||
  fail 'resources/release-readiness.json must locate external attestations for resolved Kumwe dependencies.'
jq -e '.dependencies | type == "object"' "$readiness" >/dev/null || fail 'invalid dependency evidence coordinates.'
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
    .tag_name == $tag and .draft == false and .prerelease == false and .immutable == true and
    (.published_at | type == "string" and length > 0)
  ' "$work/release.json" >/dev/null || fail "$name $tag must be a published immutable stable release."
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

  jq -e --arg name "$name" --arg version "$version" '
    .dependencies[$name] | .version == $version and (.attestation | type == "object") and
    (.attestation.repository | type == "string" and test("^kumwe/[a-z0-9][a-z0-9-]*$")) and
    (.attestation.repository != $name) and
    (.attestation.commit | type == "string" and test("^[a-f0-9]{40}$")) and
    (.attestation.path | type == "string" and test("^[A-Za-z0-9_-][A-Za-z0-9_./-]*\\.json$")) and
    (.attestation.path | split("/") | all(.[]; . != "." and . != ".." and . != "")) and
    (.attestation.sha256 | type == "string" and test("^[a-f0-9]{64}$"))
  ' "$readiness" >/dev/null ||
    fail "$name $version needs a digest-pinned external JSON release attestation at a full commit."
  coordinates="$(jq -c --arg name "$name" '.dependencies[$name].attestation' "$readiness")"
  evidence_repo="$(jq -r .repository <<< "$coordinates")"
  evidence_commit="$(jq -r .commit <<< "$coordinates")"
  evidence_path="$(jq -r .path <<< "$coordinates")"
  evidence_sha="$(jq -r .sha256 <<< "$coordinates")"
  gh api --method GET "repos/$evidence_repo/contents/$evidence_path?ref=$evidence_commit" > "$work/content.json" ||
    fail "cannot retrieve external evidence for $name."
  jq -e --arg path "$evidence_path" '
    .type == "file" and .path == $path and .encoding == "base64" and (.content | type == "string")
  ' "$work/content.json" >/dev/null || fail "$name attestation must be a repository file."
  jq -r .content "$work/content.json" | base64 --decode > "$work/attestation.json" ||
    fail "$name attestation encoding is invalid."
  actual_sha="$(sha256sum "$work/attestation.json")"
  [[ "${actual_sha%% *}" == "$evidence_sha" ]] ||
    fail "$name external attestation digest does not match its reviewed coordinate."
  jq -e --arg name "$name" --arg version "$version" --arg tag "$tag" --arg commit "$commit" \
    --arg dist "$(jq -r .dist.url <<< "$locked")" '
    def nonempty: type == "string" and length > 0;
    def digest: type == "string" and test("^[a-f0-9]{64}$");
    .schema == "kumwe-release-attestation/v2" and .artifact_kind == "framework_php" and
    .repository == ("https://github.com/" + $name) and .version == $version and .tag == $tag and
    .merge_commit == $commit and .status == "verified" and ((.known_gaps // []) | length == 0) and
    (.verified_at | nonempty) and (.verified_by | nonempty) and
    (.source_archive.url == ("https://github.com/" + $name + "/archive/refs/tags/" + $tag + ".tar.gz")) and
    (.source_archive.sha256 | digest) and
    (.artifacts | type == "array" and any(.[]; .url == $dist and (.sha256 | digest))) and
    (.manifests_and_corpora | type == "array" and length > 0 and
      all(.[]; (.path | nonempty) and (.sha256 | digest))) and
    (.registry_or_pie_verification | type == "array" and length > 0 and all(.[]; nonempty)) and
    (.clean_consumer_or_build_verification | type == "array" and length > 0 and all(.[]; nonempty)) and
    (.release_workflow | type == "string" and startswith("https://github.com/" + $name + "/actions/runs/"))
  ' "$work/attestation.json" >/dev/null ||
    fail "$name external attestation must independently verify this exact release," \
      'artifacts, registry, manifests and clean consumer without gaps.'

  workflow_url="$(jq -r .release_workflow "$work/attestation.json")"
  workflow_suffix="${workflow_url#https://github.com/$name/actions/runs/}"
  [[ "$workflow_suffix" =~ ^([1-9][0-9]*)(\ \([^$'\n']*\))?$ ]] ||
    fail "$name attestation has an invalid release workflow coordinate."
  run_id="${BASH_REMATCH[1]}"
  gh api --method GET "repos/$name/actions/runs/$run_id" > "$work/run.json" ||
    fail "cannot retrieve $name release workflow evidence."
  jq -e --arg name "$name" --arg commit "$commit" '
    .repository.full_name == $name and .head_sha == $commit and .status == "completed" and .conclusion == "success" and
    .path == ".github/workflows/release-on-record.yml" and
    (.event == "push" or .event == "workflow_dispatch")
  ' "$work/run.json" >/dev/null ||
    fail "$name release-on-record workflow must have succeeded for the exact tagged commit."

  jq --arg name "$name" --arg version "$version" --arg commit "$commit" --argjson attestation "$coordinates" \
    --slurpfile release "$work/release.json" --argjson run_id "$run_id" \
    '. + [{package: $name, version: $version, commit: $commit, release_url: $release[0].html_url,
      release_id: $release[0].id, immutable: true, workflow_run_id: $run_id, attestation: $attestation}]' \
    "$work/evidence.json" > "$work/next-evidence.json"
  mv "$work/next-evidence.json" "$work/evidence.json"
  printf 'Verified dependency: %s %s at %s; immutable release and external attestation agree.\n' \
    "$name" "$version" "$commit"
done < "$work/packages.jsonl"

if [[ -n "$output" ]]; then
  jq '{schema: "kumwe-dependency-verification/v1", status: "verified", dependencies: .}' \
    "$work/evidence.json" > "$output"
fi
