#!/usr/bin/env bash
# bootstrap.sh — idempotent bootstrap for the git hosting P1 overlay: Forgejo, Authentik, and the
# Kanboard OAuth2 plugin settings.
#
# Usage (stack up first, see testing/git/compose.sh):
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/bootstrap.sh
#
# 0. Writes testing/git/hmac.env (gitignored, mode 600) with a random GITHOST_HMAC_SECRET, unless it
#    already holds one. The broker and kb-suite both load it; kb-suite loads nothing else from here.
#    Writes testing/git/authentik.env (gitignored, mode 600) with Authentik's secret key, the akadmin
#    password and API token, and the two OIDC client secrets, unless it exists. Runs
#    fetch-oauth2.sh (the pinned, hash-checked OAuth2 plugin in testing/git/vendor/OAuth2).
# 1. Waits for Forgejo. If its rendered app.ini lacks a setting of app.ini.tmpl (a template edit
#    after first start), removes the rendered file and restarts Forgejo to re-render it.
# 2. Creates the site admin (default kb-admin) with a random password, unless it exists. The
#    password is not kept: the token is what the broker uses, and `forgejo admin user
#    change-password` resets it if the web UI is ever needed.
# 3. Mints an admin token with the scopes the broker needs, unless testing/git/.env already holds
#    one that Forgejo still accepts, minted with those scopes (FORGEJO_ADMIN_TOKEN_SCOPES).
# 4. Creates a placeholder OAuth2 auth source named `authentik`, unless one exists. Human
#    principals are bound to it (login_name = Authentik sub). `forgejo admin auth add-oauth`
#    fetches the discovery URL at creation, so the placeholder points at Forgejo's own OIDC
#    discovery document; step 7 points it at Authentik, keeping its id.
# 5. Writes testing/git/.env (gitignored, mode 600): FORGEJO_URL, FORGEJO_ADMIN_TOKEN,
#    FORGEJO_AUTHENTIK_SOURCE_ID (looked up by name; Forgejo has no API for auth sources) and
#    BROKER_WEBHOOK_SECRET (kept across runs), the broker's env file, and recreates the broker if the file changed.
# 6. Starts (or recreates on a config change) Authentik and Traefik (for the auth.kb.test alias),
#    waits for Authentik, and applies the blueprints in testing/git/authentik/blueprints through
#    the API: the kanboard and forgejo OIDC providers and applications.
# 7. Reconfigures the Forgejo source `authentik` in place against Authentik's forgejo provider.
# 8. Writes the Kanboard OAuth2 plugin settings (ConfigModel keys) into kb-suite's database.
# 9. Registers the dev CI runner dev-plain (secret in testing/git/runner.env) with the Forgejo CLI.
# Re-running changes nothing when everything already exists.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$HERE/.env"
HMAC_FILE="$HERE/hmac.env"
FORGEJO_CONTAINER="${FORGEJO_CONTAINER:-kb-git-forgejo}"
ADMIN_USER="${FORGEJO_ADMIN_USER:-kb-admin}"
ADMIN_EMAIL="${FORGEJO_ADMIN_EMAIL:-kb-admin@git.localhost}"
# The broker reaches Forgejo directly on the compose network; ForwardAuth is for everyone else.
FORGEJO_URL="${FORGEJO_URL:-http://forgejo:3000}"
# write:issue: the merge gate labels its auto-revert PRs (labels are an issue resource).
SCOPES="write:admin,write:organization,write:repository,write:issue,write:user,read:user"
AUTH_SOURCE="authentik"
AUTH_SOURCE_DISCOVERY="http://localhost:3000/.well-known/openid-configuration"
AK_ENV="$HERE/authentik.env"
KANBOARD_CONTAINER="${KANBOARD_CONTAINER:-kb-suite}"
# One issuer for the browser and every container (Traefik alias, see compose overlay).
AUTH_HOST="auth.kb.test"
AUTH_PORT="8090"
AUTH_BASE="http://${AUTH_HOST}:${AUTH_PORT}"
KANBOARD_URL="${KANBOARD_URL:-http://localhost:8081}"

fj() { docker exec -u git "$FORGEJO_CONTAINER" forgejo "$@"; }

# HTTP status of GET /api/v1/user with a token, asked from inside the Forgejo container.
token_status() {
  docker exec "$FORGEJO_CONTAINER" curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
    -H "Authorization: token $1" http://localhost:3000/api/v1/user
}

if grep -qE '^GITHOST_HMAC_SECRET=.{32,}$' "$HMAC_FILE" 2>/dev/null; then
  echo "bootstrap: testing/git/hmac.env has a secret"
else
  (umask 077 && printf 'GITHOST_HMAC_SECRET=%s\n' "$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')" >"$HMAC_FILE")
  echo "bootstrap: wrote testing/git/hmac.env; recreate kb-suite and the broker to load it:"
  echo "  bash testing/git/compose.sh up -d kanboard broker"
fi

rand_hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }
if [[ -f "$AK_ENV" ]]; then
  echo "bootstrap: testing/git/authentik.env exists"
else
  (umask 077 && printf '%s=%s\n' \
    AUTHENTIK_SECRET_KEY "$(rand_hex 48)" \
    AUTHENTIK_BOOTSTRAP_EMAIL "akadmin@kb.test" \
    AUTHENTIK_BOOTSTRAP_PASSWORD "$(rand_hex 24)" \
    AUTHENTIK_BOOTSTRAP_TOKEN "$(rand_hex 32)" \
    KANBOARD_OIDC_CLIENT_SECRET "$(rand_hex 32)" \
    FORGEJO_OIDC_CLIENT_SECRET "$(rand_hex 32)" >"$AK_ENV")
  echo "bootstrap: wrote testing/git/authentik.env"
fi
ak_env() { sed -n "s/^$1=//p" "$AK_ENV"; }

bash "$HERE/fetch-oauth2.sh"

wait_forgejo() {
  for _ in $(seq 1 60); do
    if docker exec "$FORGEJO_CONTAINER" curl -sf -o /dev/null http://localhost:3000/api/healthz 2>/dev/null; then
      return 0
    fi
    sleep 2
  done
  docker exec "$FORGEJO_CONTAINER" curl -sf -o /dev/null http://localhost:3000/api/healthz
}

echo "bootstrap: waiting for Forgejo"
wait_forgejo || { echo "bootstrap: Forgejo is not healthy" >&2; exit 1; }

# The template is rendered only on first start. Every literal "KEY = value" line of it must be in
# the rendered app.ini; otherwise re-render (Forgejo regenerates its internal secrets; access
# tokens and auth sources live in the database and are kept).
RENDERED=/var/lib/gitea/custom/conf/app.ini
missing="$(grep -E '^[A-Z_]+ = [^$]*$' "$HERE/forgejo/app.ini.tmpl" | while IFS= read -r line; do
  docker exec "$FORGEJO_CONTAINER" grep -qxF "$line" "$RENDERED" || echo "$line"
done)"
if [[ -n "$missing" ]]; then
  echo "bootstrap: app.ini lacks $(wc -l <<<"$missing") template setting(s), first: ${missing%%$'\n'*}; re-rendering"
  docker exec "$FORGEJO_CONTAINER" rm -f "$RENDERED"
  docker restart "$FORGEJO_CONTAINER" >/dev/null
  sleep 3
  wait_forgejo || { echo "bootstrap: Forgejo is not healthy after re-render" >&2; exit 1; }
else
  echo "bootstrap: Forgejo app.ini has every template setting"
fi

if fj admin user list --admin | awk 'NR > 1 { print $2 }' | grep -qx "$ADMIN_USER"; then
  echo "bootstrap: admin $ADMIN_USER exists"
else
  fj admin user create --admin --username "$ADMIN_USER" --email "$ADMIN_EMAIL" \
    --random-password --must-change-password=false >/dev/null
  echo "bootstrap: created admin $ADMIN_USER"
fi

token=""
token_scopes=""
if [[ -f "$ENV_FILE" ]]; then
  token="$(sed -n 's/^FORGEJO_ADMIN_TOKEN=//p' "$ENV_FILE")"
  token_scopes="$(sed -n 's/^FORGEJO_ADMIN_TOKEN_SCOPES=//p' "$ENV_FILE")"
fi
# A token minted with other scopes (an older bootstrap) is replaced; Forgejo cannot widen one.
if [[ -n "$token" && "$token_scopes" == "$SCOPES" && "$(token_status "$token")" == "200" ]]; then
  echo "bootstrap: admin token in testing/git/.env still works"
else
  # Token names are unique per user, and a lost token cannot be read back: mint a new name.
  token="$(fj admin user generate-access-token --username "$ADMIN_USER" \
    --token-name "broker-$(date -u +%Y%m%dT%H%M%SZ)" --scopes "$SCOPES" --raw)"
  [[ "$(token_status "$token")" == "200" ]] || { echo "bootstrap: new token rejected" >&2; exit 1; }
  echo "bootstrap: minted admin token ($SCOPES)"
fi

# `admin auth list` prints "ID<TAB>Name<TAB>..." with a header row.
source_id() { fj admin auth list | awk -v n="$AUTH_SOURCE" 'NR > 1 && $2 == n { print $1 }'; }
source="$(source_id)"
if [[ -n "$source" ]]; then
  echo "bootstrap: auth source $AUTH_SOURCE exists (id $source)"
else
  fj admin auth add-oauth --name "$AUTH_SOURCE" --provider openidConnect \
    --key placeholder-client-id --secret placeholder-client-secret \
    --auto-discover-url "$AUTH_SOURCE_DISCOVERY" >/dev/null
  source="$(source_id)"
  [[ -n "$source" ]] || { echo "bootstrap: auth source $AUTH_SOURCE not created" >&2; exit 1; }
  echo "bootstrap: created placeholder auth source $AUTH_SOURCE (id $source)"
fi

# The secret Forgejo signs webhooks to the broker with (X-Forgejo-Signature). Only the broker holds
# it; it gives it to Forgejo when it installs a repo's webhook. Kept across runs.
hook_secret=""
if [[ -f "$ENV_FILE" ]]; then
  hook_secret="$(sed -n 's/^BROKER_WEBHOOK_SECRET=//p' "$ENV_FILE")"
fi
[[ "$hook_secret" =~ ^[0-9a-f]{64}$ ]] || hook_secret="$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')"
new_env="$(printf 'FORGEJO_URL=%s\nFORGEJO_ADMIN_TOKEN=%s\nFORGEJO_ADMIN_TOKEN_SCOPES=%s\nFORGEJO_AUTHENTIK_SOURCE_ID=%s\nBROKER_WEBHOOK_SECRET=%s\n' \
  "$FORGEJO_URL" "$token" "$SCOPES" "$source" "$hook_secret")"
if [[ -f "$ENV_FILE" && "$(cat "$ENV_FILE")" == "$new_env" ]]; then
  echo "bootstrap: testing/git/.env unchanged"
else
  (umask 077 && printf '%s\n' "$new_env" >"$ENV_FILE")
  echo "bootstrap: wrote testing/git/.env; recreating the broker to load it"
  bash "$HERE/compose.sh" up -d --no-deps --force-recreate broker
fi

# 6. Authentik behind Traefik. `up` recreates a service only when its config (including the env
#    file) changed, so this is a no-op on a re-run.
bash "$HERE/compose.sh" up -d --no-deps authentik-db authentik-server authentik-worker traefik
ak_curl() { curl -s --max-time 20 --resolve "${AUTH_HOST}:${AUTH_PORT}:127.0.0.1" "$@"; }
ak_api() { # ak_api <method> <path> [curl args]; the token goes to curl on stdin, never argv
  local method="$1" path="$2"
  shift 2
  printf 'Authorization: Bearer %s\n' "$(ak_env AUTHENTIK_BOOTSTRAP_TOKEN)" |
    ak_curl -X "$method" -H @- -H 'Accept: application/json' "$@" "${AUTH_BASE}${path}"
}
echo "bootstrap: waiting for Authentik (first start migrates its database, a minute or two)"
for _ in $(seq 1 120); do
  [[ "$(ak_curl -o /dev/null -w '%{http_code}' "${AUTH_BASE}/-/health/ready/")" == 200 &&
     "$(ak_api GET /api/v3/core/users/me/ -o /dev/null -w '%{http_code}')" == 200 ]] && break
  sleep 3
done
[[ "$(ak_api GET /api/v3/core/users/me/ -o /dev/null -w '%{http_code}')" == 200 ]] \
  || { echo "bootstrap: Authentik is not ready, or the API token is refused" >&2; exit 1; }
echo "bootstrap: Authentik is ready at ${AUTH_BASE}"

# The worker discovers files under /blueprints and applies them on its own schedule. Apply ours
# now (the API enqueues it) and wait until it has the provider, signed by the self-signed key. On a
# first start the default flows, scope mappings and key may not exist yet, so retry.
provider_ok() { # provider_ok <client_id>
  ak_api GET "/api/v3/providers/oauth2/?client_id=$1" | python3 -I -c '
import json,sys
r=json.load(sys.stdin).get("results") or []
sys.exit(0 if len(r)==1 and r[0]["signing_key"] and r[0]["authorization_flow"] and len(r[0]["property_mappings"])==3 else 1)' 2>/dev/null
}
for app in kanboard forgejo; do
  pk=""
  for _ in $(seq 1 40); do
    pk="$(ak_api GET "/api/v3/managed/blueprints/?path=custom/${app}.yaml" |
      python3 -I -c 'import json,sys; r=json.load(sys.stdin).get("results") or []; print(r[0]["pk"] if r else "")')"
    [[ -n "$pk" ]] && break
    sleep 3
  done
  [[ -n "$pk" ]] || { echo "bootstrap: blueprint custom/${app}.yaml not discovered" >&2; exit 1; }
  ok=no
  for _ in $(seq 1 24); do
    ak_api POST "/api/v3/managed/blueprints/${pk}/apply/" -o /dev/null
    sleep 5
    status="$(ak_api GET "/api/v3/managed/blueprints/${pk}/" | python3 -I -c 'import json,sys; print(json.load(sys.stdin).get("status",""))')"
    if [[ "$status" == successful ]] && provider_ok "$app"; then ok=yes; break; fi
  done
  [[ "$ok" == yes ]] || { echo "bootstrap: blueprint custom/${app}.yaml: ${status:-no status}, provider incomplete" >&2; exit 1; }
  echo "bootstrap: blueprint custom/${app}.yaml applied (provider and application ${app})"
done

# 7. The Forgejo source, in place (same id). Forgejo fetches the discovery document now, through
#    Traefik under the auth.kb.test alias. The secret reaches the CLI through the environment.
FORGEJO_OIDC_CLIENT_SECRET="$(ak_env FORGEJO_OIDC_CLIENT_SECRET)" \
  docker exec -u git -e FORGEJO_OIDC_CLIENT_SECRET "$FORGEJO_CONTAINER" sh -c \
  'forgejo admin auth update-oauth --id "$1" --name "$2" --provider openidConnect --key forgejo \
     --secret "$FORGEJO_OIDC_CLIENT_SECRET" --auto-discover-url "$3" --scopes openid --scopes email --scopes profile' \
  sh "$source" "$AUTH_SOURCE" "${AUTH_BASE}/application/o/forgejo/.well-known/openid-configuration" >/dev/null
echo "bootstrap: auth source $AUTH_SOURCE (id $source) -> ${AUTH_BASE}/application/o/forgejo/"

# 8. Kanboard OAuth2 plugin settings: ConfigModel keys in kb-suite's database, written with PDO.
#    The plugin's redirect_uri is an absolute Kanboard URL. With application_url empty, Kanboard
#    builds it from the container's own SERVER_NAME/PORT (http://localhost/, no :8081), which does
#    not match Authentik's strict redirect URI, so application_url is set too, but only when empty.
#    The client secret reaches python through the environment and PHP on stdin, never argv.
KANBOARD_OIDC_CLIENT_SECRET="$(ak_env KANBOARD_OIDC_CLIENT_SECRET)" python3 -I -c '
import json,os,sys
base,secret=sys.argv[1],os.environ["KANBOARD_OIDC_CLIENT_SECRET"]
print(json.dumps({
  "oauth2_client_id": "kanboard",
  "oauth2_client_secret": secret,
  "oauth2_authorize_url": base + "/application/o/authorize/",
  "oauth2_token_url": base + "/application/o/token/",
  "oauth2_user_api_url": base + "/application/o/userinfo/",
  "oauth2_scopes": "openid email profile",
  "oauth2_key_user_id": "sub",
  "oauth2_key_username": "preferred_username",
  "oauth2_key_name": "name",
  "oauth2_key_email": "email",
  "oauth2_key_groups": "",
  "oauth2_account_creation": "1",
  "oauth2_email_domains": "",
  "oauth2_custom_login_text": "Sign in with Authentik",
}))' "$AUTH_BASE" |
  docker exec -i -u nginx "$KANBOARD_CONTAINER" php -r '
$p = new PDO("sqlite:/var/www/app/data/db.sqlite");
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = $p->prepare("INSERT INTO settings (option, value, changed_on) VALUES (?, ?, ?) ON CONFLICT(option) DO UPDATE SET value = excluded.value, changed_on = excluded.changed_on WHERE settings.value <> excluded.value");
$n = 0;
$in = json_decode(stream_get_contents(STDIN), true);
$app = $p->query("SELECT value FROM settings WHERE option = \x27application_url\x27")->fetchColumn();
if ($app === false || $app === "") { $in["application_url"] = $argv[1]; }
foreach ($in as $k => $v) { $s->execute([$k, $v, time()]); $n += $s->rowCount(); }
echo $n, "\n";' -- "${KANBOARD_URL}/" | { read -r n; echo "bootstrap: Kanboard OAuth2 settings: ${n} changed"; }
if docker exec "$KANBOARD_CONTAINER" test -f /var/www/app/plugins/OAuth2/Plugin.php; then
  echo "bootstrap: kb-suite has the OAuth2 plugin; login link ${KANBOARD_URL}/oauth/callback"
else
  echo "bootstrap: kb-suite lacks the OAuth2 plugin mount; recreate it once (keeps its data):"
  echo "  bash testing/git/compose.sh up -d --no-deps kanboard"
fi
# 9. The dev CI runner (profile ci-dev, ruling F12): a 40-hex shared secret in testing/git/runner.env
#    (gitignored, mode 600), registered with the Forgejo CLI. `forgejo-cli actions register` is
#    idempotent: the same secret is the same runner (uuid = its first 16 characters).
RUNNER_ENV="$HERE/runner.env"
runner_secret="$(sed -n 's/^RUNNER_SECRET=//p' "$RUNNER_ENV" 2>/dev/null || true)"
if [[ ! "$runner_secret" =~ ^[0-9a-f]{40}$ ]]; then
  runner_secret="$(od -An -tx1 -N20 /dev/urandom | tr -d ' \n')"
  (umask 077 && printf 'RUNNER_SECRET=%s\n' "$runner_secret" >"$RUNNER_ENV")
  echo "bootstrap: wrote testing/git/runner.env"
fi
runner_uuid="$(printf '%s' "$runner_secret" | docker exec -i -u git "$FORGEJO_CONTAINER" \
  forgejo forgejo-cli actions register --secret-stdin - --name dev-plain --labels dev-plain)"
echo "bootstrap: runner dev-plain registered (uuid ${runner_uuid}); start it with"
echo "  bash testing/git/compose.sh --profile ci-dev up -d --no-deps ci-runner"
echo "bootstrap: done"
