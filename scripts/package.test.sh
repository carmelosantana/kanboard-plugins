#!/usr/bin/env bash
# Smoke test for package.sh: builds a fixture plugin zip and asserts structure.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Fixture plugin
mkdir -p "$TMP/src/DemoPlugin/Test"
cat > "$TMP/src/DemoPlugin/Plugin.php" <<'PHP'
<?php
PHP
cat > "$TMP/src/DemoPlugin/plugin.json" <<'JSON'
{ "name": "DemoPlugin", "version": "2.3.4" }
JSON
echo "junk" > "$TMP/src/DemoPlugin/Test/foo.php"

OUT="$("$HERE/package.sh" "$TMP/src/DemoPlugin" "$TMP/out")"
[ -f "$TMP/out/DemoPlugin-2.3.4.zip" ] || { echo "FAIL: expected DemoPlugin-2.3.4.zip"; exit 1; }

# Top-level entry must be DemoPlugin/, and Test/ excluded
LIST="$(unzip -Z1 "$TMP/out/DemoPlugin-2.3.4.zip")"
echo "$LIST" | grep -q '^DemoPlugin/Plugin.php$' || { echo "FAIL: missing DemoPlugin/Plugin.php"; exit 1; }
echo "$LIST" | grep -q 'Test/' && { echo "FAIL: Test/ should be excluded"; exit 1; }

echo "package.test.sh PASS"
