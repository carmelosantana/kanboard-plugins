#!/usr/bin/env bash
# Tests for run-plugin-tests.sh against a throwaway Kanboard tree that borrows the real
# vendor/ (so PHPUnit is real) and fixture plugins, so nothing under the shared
# testing/kanboard-src/plugins is touched.
#
# Usage: testing/run-plugin-tests.test.sh   (needs testing/kanboard-src with vendor/, or KB_SRC)
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
RUNNER="$HERE/run-plugin-tests.sh"
REAL_KB="${KB_SRC:-}"
if [[ -z "$REAL_KB" ]]; then
  REAL_KB="$HERE/kanboard-src"
  if [[ ! -d "$REAL_KB/vendor" ]]; then
    common="$(git -C "$HERE" rev-parse --path-format=absolute --git-common-dir 2>/dev/null || true)"
    [[ -n "$common" ]] && REAL_KB="$(dirname "$common")/testing/kanboard-src"
  fi
fi
[[ -x "$REAL_KB/vendor/bin/phpunit" ]] || { echo "SKIP: no PHPUnit under $REAL_KB"; exit 0; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
fails=0
pass() { echo "ok   - $1"; }
fail() { echo "FAIL - $1"; echo "$2" | sed 's/^/       /'; fails=$((fails + 1)); }

# Throwaway Kanboard: real vendor/ and tests/units, our own plugins/.
KB="$TMP/kb"
mkdir -p "$KB/tests" "$KB/plugins"
ln -s "$REAL_KB/vendor" "$KB/vendor"
ln -s "$REAL_KB/app" "$KB/app"
cp "$REAL_KB/tests/units.sqlite.xml" "$KB/tests/units.sqlite.xml"

# make_plugin <dir> <marker>: a plugin whose suite has 3 tests; test 1 fails, and
# testMarker passes only when marker.txt says "worktree".
make_plugin() {
  mkdir -p "$1/Test"
  echo '{ "name": "RptFixture", "version": "1.0.0" }' > "$1/plugin.json"
  printf '<?php\nnamespace Kanboard\\Plugin\\RptFixture;\nclass Plugin {}\n' > "$1/Plugin.php"
  echo "$2" > "$1/marker.txt"
  cat > "$1/Test/FixtureTest.php" <<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class FixtureTest extends TestCase
{
    public function testAFails(): void { $this->assertTrue(false); }
    public function testBPasses(): void { $this->assertTrue(true); }
    public function testMarker(): void
    {
        $this->assertSame('worktree', trim(file_get_contents(__DIR__ . '/../marker.txt')));
    }
}
PHP
}

ROOT="$TMP/root"
make_plugin "$ROOT/RptFixture" main
make_plugin "$TMP/wt/fixture-branch" worktree

run() { PLUGINS_ROOT="$ROOT" KB_SRC="$KB" "$RUNNER" "$@" 2>&1; }

# 1. A failing test must not stop the run: PHPUnit reports all 3 tests.
out="$(run RptFixture)"; rc=$?
if [[ $rc -ne 0 ]] && grep -qE 'Tests: 3,' <<<"$out"; then
  pass "full test count after a failure (rc=$rc)"
else
  fail "full test count after a failure (rc=$rc)" "$out"
fi

# 2. A path argument tests that checkout (a worktree) in place of PLUGINS_ROOT/<Name>.
out="$(run "$TMP/wt/fixture-branch")"
if grep -q "Source        : $TMP/wt/fixture-branch" <<<"$out" \
   && grep -qE 'Tests: 3, Assertions: 3, Failures: 1\.' <<<"$out"; then
  pass "path argument runs the worktree's code"
else
  fail "path argument runs the worktree's code" "$out"
fi

# 3. The shared kanboard-src/plugins/ is never written: each run links into its own dir.
left="$(ls -A "$KB/plugins")"
if [[ -z "$left" ]]; then
  pass "shared plugins/ untouched"
else
  fail "shared plugins/ untouched" "$left"
fi

# 4. --all reports the full count in its table too.
out="$(LOG_DIR="$TMP/logs" run --all)"
if grep -qE 'RptFixture +\| FAIL +\| Tests: 3,' <<<"$out"; then
  pass "--all table shows the full count"
else
  fail "--all table shows the full count" "$out"
fi

# 5. Run from a linked harness worktree with no kanboard-src: falls back to the main one.
if [[ "$(git -C "$HERE" rev-parse --git-dir)" != "$(git -C "$HERE" rev-parse --git-common-dir)" \
      && ! -d "$HERE/kanboard-src" ]]; then
  out="$(PLUGINS_ROOT="$ROOT" "$RUNNER" --list 2>&1)"; rc=$?
  if [[ $rc -eq 0 ]] && grep -qx RptFixture <<<"$out"; then
    pass "linked worktree finds the main checkout's kanboard-src"
  else
    fail "linked worktree finds the main checkout's kanboard-src" "$out"
  fi
  out="$(PLUGINS_ROOT="$ROOT" "$RUNNER" RptFixture 2>&1)"
  if grep -q "Kanboard root : $REAL_KB" <<<"$out"; then
    pass "linked worktree uses $REAL_KB"
  else
    fail "linked worktree uses $REAL_KB" "$out"
  fi
  if [[ ! -e "$REAL_KB/plugins/RptFixture" ]]; then
    pass "linked worktree left $REAL_KB/plugins alone"
  else
    rm -f "$REAL_KB/plugins/RptFixture"; fail "linked worktree left $REAL_KB/plugins alone" "RptFixture link created"
  fi
else
  echo "skip - linked-worktree fallback (not run from a linked worktree)"
fi

[[ $fails -eq 0 ]] && echo "run-plugin-tests.test.sh PASS" || { echo "run-plugin-tests.test.sh: $fails failed"; exit 1; }
