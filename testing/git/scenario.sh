#!/usr/bin/env bash
# scenario.sh — git hosting P1 scenario steps, run on the host.
#
# Usage:
#   bash testing/git/scenario.sh preflight
#
# The stack must be up first (see testing/git/compose.sh and testing/README.md):
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/bootstrap.sh
#
# preflight asserts the P1 stack invariants: Forgejo has no host port, Traefik serves
# /api/healthz, and every repo route (smart-HTTP with and without .git, LFS, raw/archive/media,
# /api/v1) is sent through the broker's ForwardAuth, which denies all in the skeleton (403).
# If the ForwardAuth middleware is dropped from a router, that route reaches Forgejo and
# returns 401/404 instead of 403, and preflight fails.
set -uo pipefail

GIT_HOST="${GIT_HOST:-git.localhost}"
GIT_PORT="${GIT_PORT:-8090}"
BASE="http://${GIT_HOST}:${GIT_PORT}"
FORGEJO_CONTAINER="${FORGEJO_CONTAINER:-kb-git-forgejo}"

pass=0
total=0

check() { # check <description> <expected> <actual>
  total=$((total + 1))
  if [[ "$3" == "$2" ]]; then
    pass=$((pass + 1))
    printf '  ok    %s\n' "$1"
  else
    printf '  FAIL  %s (expected %s, got %s)\n' "$1" "$2" "$3"
  fi
}

# HTTP status through Traefik on the host loopback port. --resolve pins git.localhost to
# 127.0.0.1 so the check does not depend on the host resolver. Prints 000 if unreachable.
status() { # status <method> <path> [extra curl args...]
  local method="$1" path="$2"
  shift 2
  curl -s -o /dev/null -w '%{http_code}' --max-time 5 --path-as-is \
    --resolve "${GIT_HOST}:${GIT_PORT}:127.0.0.1" -X "$method" "$@" "${BASE}${path}"
}

forgejo_ports() {
  # Prints "none" only when the container exists and has neither published nor bound ports.
  local bindings published
  bindings="$(docker inspect -f '{{json .HostConfig.PortBindings}}' "$FORGEJO_CONTAINER" 2>/dev/null)" || {
    echo "missing-container"
    return
  }
  published="$(docker port "$FORGEJO_CONTAINER" 2>/dev/null)"
  if [[ ( "$bindings" == "{}" || "$bindings" == "null" ) && -z "$published" ]]; then
    echo "none"
  else
    echo "bindings=${bindings} published=${published//$'\n'/,}"
  fi
}

preflight() {
  echo "preflight against ${BASE}"
  check "forgejo has no host port" "none" "$(forgejo_ports)"
  check "GET /api/healthz is 200 through Traefik" "200" "$(status GET /api/healthz)"
  check "GET /kb-x/r.git/info/refs?service=git-upload-pack is 403" "403" \
    "$(status GET '/kb-x/r.git/info/refs?service=git-upload-pack')"
  check "GET /api/v1/repos/kb-x/r is 403" "403" "$(status GET /api/v1/repos/kb-x/r)"
  # F1: smart-HTTP without the .git suffix, push, LFS and token-auth web routes.
  check "GET /kb-x/r/info/refs?service=git-upload-pack is 403" "403" \
    "$(status GET '/kb-x/r/info/refs?service=git-upload-pack')"
  check "GET /kb-x/r/info/refs?service=git-receive-pack is 403" "403" \
    "$(status GET '/kb-x/r/info/refs?service=git-receive-pack')"
  check "POST /kb-x/r/git-receive-pack is 403" "403" \
    "$(status POST /kb-x/r/git-receive-pack -H 'Content-Type: application/x-git-receive-pack-request')"
  check "POST /kb-x/r.git/info/lfs/objects/batch is 403" "403" \
    "$(status POST /kb-x/r.git/info/lfs/objects/batch -H 'Content-Type: application/vnd.git-lfs+json')"
  check "GET /kb-x/r/raw/branch/main/README.md is 403" "403" \
    "$(status GET /kb-x/r/raw/branch/main/README.md)"
  check "GET /kb-x/r/archive/main.zip is 403" "403" "$(status GET /kb-x/r/archive/main.zip)"
  # Any credentialed request on a non-exempt path, and the rest of /api/, is checked too.
  check "GET /api/v1/user with a token is 403" "403" \
    "$(status GET /api/v1/user -H 'Authorization: token not-a-real-token')"
  check "GET /kb-x with ?token= is 403" "403" "$(status GET '/kb-x?token=not-a-real-token')"
  check "GET /kb-x/r with basic auth is 403" "403" "$(status GET /kb-x/r -u 'someone:not-a-password')"
  # Exempt paths must not sit behind a dot-dot that Traefik forwards unnormalised.
  check "GET /assets/../kb-x/r.git/info/refs is 403" "403" \
    "$(status GET '/assets/../kb-x/r.git/info/refs?service=git-upload-pack')"
  # An encoded slash would dodge the repo regex and reach Forgejo unchecked; Traefik rejects it.
  check "GET /kb-x%2Fr.git/info/refs is rejected (400)" "400" \
    "$(status GET '/kb-x%2Fr.git/info/refs?service=git-upload-pack')"

  if [[ "$pass" -eq "$total" ]]; then
    echo "preflight: PASS (${pass}/${total})"
    return 0
  fi
  echo "preflight: FAIL (${pass}/${total})"
  return 1
}

case "${1:-}" in
  preflight) preflight ;;
  *)
    echo "usage: bash testing/git/scenario.sh preflight" >&2
    exit 2
    ;;
esac
