#!/usr/bin/env bash
# bootstrap.sh — idempotent Forgejo bootstrap for the git hosting P1 overlay.
#
# Usage (stack up first, see testing/git/compose.sh):
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/bootstrap.sh
#
# 1. Waits for Forgejo.
# 2. Creates the site admin (default kb-admin) with a random password, unless it exists. The
#    password is not kept: the token is what the broker uses, and `forgejo admin user
#    change-password` resets it if the web UI is ever needed.
# 3. Mints an admin token with the scopes the broker needs, unless testing/git/.env already holds
#    one that Forgejo still accepts.
# 4. Writes testing/git/.env (gitignored, mode 600): FORGEJO_URL and FORGEJO_ADMIN_TOKEN, the
#    broker's env file, and recreates the broker if the file changed.
# Re-running changes nothing when the admin and a working token already exist.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$HERE/.env"
FORGEJO_CONTAINER="${FORGEJO_CONTAINER:-kb-git-forgejo}"
ADMIN_USER="${FORGEJO_ADMIN_USER:-kb-admin}"
ADMIN_EMAIL="${FORGEJO_ADMIN_EMAIL:-kb-admin@git.localhost}"
# The broker reaches Forgejo directly on the compose network; ForwardAuth is for everyone else.
FORGEJO_URL="${FORGEJO_URL:-http://forgejo:3000}"
SCOPES="write:admin,write:organization,write:repository,write:user,read:user"

fj() { docker exec -u git "$FORGEJO_CONTAINER" forgejo "$@"; }

# HTTP status of GET /api/v1/user with a token, asked from inside the Forgejo container.
token_status() {
  docker exec "$FORGEJO_CONTAINER" curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
    -H "Authorization: token $1" http://localhost:3000/api/v1/user
}

echo "bootstrap: waiting for Forgejo"
for _ in $(seq 1 60); do
  if docker exec "$FORGEJO_CONTAINER" curl -sf -o /dev/null http://localhost:3000/api/healthz 2>/dev/null; then
    break
  fi
  sleep 2
done
docker exec "$FORGEJO_CONTAINER" curl -sf -o /dev/null http://localhost:3000/api/healthz \
  || { echo "bootstrap: Forgejo is not healthy" >&2; exit 1; }

if fj admin user list --admin | awk 'NR > 1 { print $2 }' | grep -qx "$ADMIN_USER"; then
  echo "bootstrap: admin $ADMIN_USER exists"
else
  fj admin user create --admin --username "$ADMIN_USER" --email "$ADMIN_EMAIL" \
    --random-password --must-change-password=false >/dev/null
  echo "bootstrap: created admin $ADMIN_USER"
fi

token=""
if [[ -f "$ENV_FILE" ]]; then
  token="$(sed -n 's/^FORGEJO_ADMIN_TOKEN=//p' "$ENV_FILE")"
fi
if [[ -n "$token" && "$(token_status "$token")" == "200" ]]; then
  echo "bootstrap: admin token in testing/git/.env still works"
else
  # Token names are unique per user, and a lost token cannot be read back: mint a new name.
  token="$(fj admin user generate-access-token --username "$ADMIN_USER" \
    --token-name "broker-$(date -u +%Y%m%dT%H%M%SZ)" --scopes "$SCOPES" --raw)"
  [[ "$(token_status "$token")" == "200" ]] || { echo "bootstrap: new token rejected" >&2; exit 1; }
  echo "bootstrap: minted admin token ($SCOPES)"
fi

new_env="$(printf 'FORGEJO_URL=%s\nFORGEJO_ADMIN_TOKEN=%s\n' "$FORGEJO_URL" "$token")"
if [[ -f "$ENV_FILE" && "$(cat "$ENV_FILE")" == "$new_env" ]]; then
  echo "bootstrap: testing/git/.env unchanged"
else
  (umask 077 && printf '%s\n' "$new_env" >"$ENV_FILE")
  echo "bootstrap: wrote testing/git/.env; recreating the broker to load it"
  bash "$HERE/compose.sh" up -d --no-deps --force-recreate broker
fi
echo "bootstrap: done"
