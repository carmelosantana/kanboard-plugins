#!/usr/bin/env bash
# sync-workflows.sh — write the shared test.yml and release.yml into plugin checkouts,
# pinned to a harness commit that is already on origin/master.
#
# Usage: scripts/sync-workflows.sh [--ship-docs] [--sha <commit>] <PluginDir>...
#   --ship-docs  keep docs/ in that plugin's release zip (ShadcnTheme ships its docs)
#   --sha        harness commit to pin (default: origin/master)
#
# Only writes files; review, branch, commit and open the PR in each plugin repo yourself.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SHIP_DOCS=false
SHA=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ship-docs) SHIP_DOCS=true; shift ;;
    --sha) SHA="$2"; shift 2 ;;
    -*) echo "unknown option $1" >&2; exit 2 ;;
    *) break ;;
  esac
done
[[ $# -gt 0 ]] || { sed -n '2,9p' "$0"; exit 2; }

git -C "$ROOT" fetch --quiet origin master
SHA="$(git -C "$ROOT" rev-parse "${SHA:-origin/master}")"
git -C "$ROOT" merge-base --is-ancestor "$SHA" origin/master \
  || { echo "ERROR: $SHA is not on origin/master; push the harness first." >&2; exit 1; }
DATE="$(git -C "$ROOT" show -s --format=%cs "$SHA")"

for dir in "$@"; do
  dir="${dir%/}"
  [[ -f "$dir/plugin.json" ]] || { echo "skip $dir: no plugin.json" >&2; continue; }
  mkdir -p "$dir/.github/workflows"
  for wf in test.yml release.yml; do
    sed -e "s/HARNESS_SHA/${SHA}/" -e "s/HARNESS_DATE/harness ${DATE}/" -e "s/SHIP_DOCS/${SHIP_DOCS}/" \
      "$ROOT/templates/workflows/$wf" > "$dir/.github/workflows/$wf"
  done
  echo "$dir: test.yml + release.yml pinned to ${SHA:0:12} (ship-docs=${SHIP_DOCS})"
done
