#!/usr/bin/env bash
# bootstrap-argv-test.sh — the Forgejo admin token never reaches a process argument list
# (GH-06, Task 18). Runs each bootstrap.sh helper that sends the token, with a shim `docker`
# on PATH that records its argv and stdin, and a sentinel token. No stack needed.
#
# Usage: bash testing/git/bootstrap-argv-test.sh    (exit 0 = pass)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SENTINEL="sentinel-admin-token-$(od -An -tx1 -N8 /dev/urandom | tr -d ' \n')"

mkdir -p "$WORK/bin"
cat >"$WORK/bin/docker" <<'SHIM'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$SHIM_DIR/argv"
cat >>"$SHIM_DIR/stdin" 2>/dev/null || true
echo 200
SHIM
chmod +x "$WORK/bin/docker"

# The helpers, as bootstrap.sh defines them (one function each, `name() {` to the first `}`).
helpers="$(awk '/^(token_status|fj_admin)\(\) *\{/,/^\}/' "$HERE/bootstrap.sh")"
[[ "$(grep -c '^[a-z_]*() *{' <<<"$helpers")" == 2 ]] || { echo "FAIL: helpers not found"; exit 1; }

fail=0
(
  export PATH="$WORK/bin:$PATH" SHIM_DIR="$WORK"
  FORGEJO_CONTAINER=x
  token="$SENTINEL"
  eval "$helpers"
  token_status "$SENTINEL" >/dev/null </dev/null
  fj_admin GET /user >/dev/null
)
if grep -qF "$SENTINEL" "$WORK/argv"; then
  echo "FAIL: the admin token is in a docker argument list"; fail=1
else
  echo "ok: no argument list holds the admin token"
fi
if [[ "$(grep -cF "$SENTINEL" "$WORK/stdin")" == 2 ]]; then
  echo "ok: both helpers send it on stdin"
else
  echo "FAIL: a helper does not send the token on stdin"; fail=1
fi
exit "$fail"
