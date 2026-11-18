#!/usr/bin/env bash
# fetch-oauth2.sh — puts the third-party Kanboard OAuth2 plugin in testing/git/vendor/OAuth2 at a
# pinned commit, and checks it against a pinned content hash. Idempotent: a vendor copy that still
# matches the hash is left alone; anything else is replaced from the pinned commit.
#
# The git overlay mounts the directory read-only at /var/www/app/plugins/OAuth2 in kb-suite. It is
# gitignored and not a top-level plugin dir, so run-plugin-tests.sh --all never discovers it.
#
# Pin (supply-chain check 2026-11-18, see testing/README.md "Identity"): github.com/kanboard/plugin-oauth2
# master at 607d0e6 (2026-02-11). The last tag, v1.0.2 (f41ed17, 2020-06-12), predates eight commits that
# touch PHP (group handling, optional key splitting, login link text); Plugin.php says 1.0.2 on both.
# To bump: change PIN_COMMIT, review the diff, run with --print-hash and update PIN_SHA256.
set -euo pipefail

REPO_URL="https://github.com/kanboard/plugin-oauth2.git"
PIN_COMMIT="607d0e6071d522274511d1687aec113da0914796"
# sha256 of the sorted "sha256sum ./path" manifest of the exported tree (see tree_hash).
PIN_SHA256="56f1edeb926a32c7fd6c6f4a5535669c40ddea35df475ded65d20a40f1a46f31"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="$HERE/vendor/OAuth2"

tree_hash() { # tree_hash <dir>
  (cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum) | sha256sum | cut -d' ' -f1
}

if [[ -d "$DEST" && "$(tree_hash "$DEST")" == "$PIN_SHA256" ]]; then
  echo "fetch-oauth2: vendor/OAuth2 matches ${PIN_COMMIT:0:7} (sha256 ${PIN_SHA256:0:12}…)"
  exit 0
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
git -c init.defaultBranch=main init -q "$tmp/repo"
git -C "$tmp/repo" fetch -q --depth 1 "$REPO_URL" "$PIN_COMMIT"
got="$(git -C "$tmp/repo" rev-parse FETCH_HEAD)"
[[ "$got" == "$PIN_COMMIT" ]] || { echo "fetch-oauth2: fetched $got, not $PIN_COMMIT" >&2; exit 1; }
mkdir "$tmp/out"
git -C "$tmp/repo" archive --format=tar "$PIN_COMMIT" | tar -x -C "$tmp/out"
hash="$(tree_hash "$tmp/out")"
if [[ "${1:-}" == "--print-hash" ]]; then
  echo "$hash"
  exit 0
fi
[[ "$hash" == "$PIN_SHA256" ]] || { echo "fetch-oauth2: content hash $hash, expected $PIN_SHA256" >&2; exit 1; }
rm -rf "$DEST"
mkdir -p "$HERE/vendor"
mv "$tmp/out" "$DEST"
chmod -R a+rX "$DEST"
echo "fetch-oauth2: vendor/OAuth2 at ${PIN_COMMIT:0:7} (sha256 ${hash:0:12}…)"
