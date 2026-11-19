#!/usr/bin/env bash
# ci-phpunit.sh <Plugin> — the PHPUnit step of plugin-test.yml: run one suite, append its
# PHPUnit summary line to $GITHUB_STEP_SUMMARY, and exit with PHPUnit's status.
#
# GitHub's default `bash -e` has no pipefail, so `run-plugin-tests.sh | tee` once exited 0 on a
# failing suite; PIPESTATUS keeps the runner's status. Same env as run-plugin-tests.sh.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="${1:?usage: ci-phpunit.sh <Plugin>}"
LOG="$(mktemp)"; trap 'rm -f "$LOG"' EXIT

"$HERE/run-plugin-tests.sh" "$PLUGIN" 2>&1 | tee "$LOG"
status=${PIPESTATUS[0]}

summary="$(grep -E '^(OK|Tests:|No tests executed)' "$LOG" | tail -1 || true)"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  echo "### ${PLUGIN} PHPUnit: ${summary:-no PHPUnit summary line}" >> "$GITHUB_STEP_SUMMARY"
fi
exit "$status"
