#!/usr/bin/env bash
# scenario.sh — git hosting P1 scenario steps, run on the host.
#
# Usage:
#   bash testing/git/scenario.sh <step>...      e.g. preflight 1 2 5a 7 8
#   bash testing/git/scenario.sh all            every step once, in table order
#   bash testing/git/scenario.sh list           step names and what each checks
#
# The stack must be up first (see testing/git/compose.sh and testing/README.md):
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/bootstrap.sh
#
# Steps live in one dispatch table (STEPS below): name, function, description. Names are the
# spec's numeric scenario ids (ruling F10).
#
# Credentials for the live steps come from the environment, or from testing/git/scenario.env
# (gitignored, mode 600) when it exists; scenario.sh never prints them:
#   KANBOARD_API_ENDPOINT   JSON-RPC URL, default http://localhost:8081/jsonrpc.php
#   KANBOARD_USERNAME       a Kanboard user that can create and close tasks in the project
#   KANBOARD_API_KEY        that user's API key
#   AGENT_USERNAME          the agent (default githost-e2e-agent)
#   AGENT_USER_ID           its Kanboard user id (default 20)
#   AGENT_API_TOKEN         the agent's Kanboard API token, or AGENT_API_TOKEN_FILE (a 0600 file)
#   SCENARIO_PROJECT_ID     default 28 (githost-e2e)
#   SCENARIO_REPO           default kb-ghe2e/app
#   BROKER_DIR              broker checkout, default ../kanboard-git-broker beside this repo
#   KB_HELPER               a prebuilt git-credential-kanboard (default: cargo build from BROKER_DIR)
# The identity step also reads AUTHENTIK_BOOTSTRAP_TOKEN from testing/git/authentik.env,
# FORGEJO_ADMIN_TOKEN and FORGEJO_AUTHENTIK_SOURCE_ID from testing/git/.env and
# GITHOST_HMAC_SECRET from testing/git/hmac.env (all gitignored, written by bootstrap.sh):
#   IDENTITY_USER           the Authentik/Kanboard test human (default idtest-human)
#   IDENTITY_LOCAL_USER     a local Forgejo user with a known password (default idtest-local)
#   IDENTITY_UNLINKED_USER  an Authentik user that is never provisioned (default idtest-unlinked)
#   IDENTITY_PROVISION      1 (default); 0 skips pre-provisioning, which must make the step fail
# git gets its token from git-credential-kanboard (credential.helper = the built binary), which
# reads the agent's API token from a 0600 copy (KB_TOKEN_FILE) and calls getGitCredential. Where
# a step needs a token minted *before* a change (removal, close, outage), it keeps one helper
# reply in a 0600 file under a private temp dir and replays it with an inline helper. Tokens
# never appear on argv or in output. Global and system git config are ignored, so no credential
# store keeps them.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$HERE/scenario.env" ]]; then
  set -a
  # shellcheck source=/dev/null
  . "$HERE/scenario.env"
  set +a
fi

GIT_HOST="${GIT_HOST:-git.localhost}"
GIT_PORT="${GIT_PORT:-8090}"
BASE="http://${GIT_HOST}:${GIT_PORT}"
# Authentik, also behind Traefik on :8090. Not *.localhost: libcurl pins those to loopback, and
# Kanboard's OAuth2 back-channel (PHP curl) must reach the same issuer URL from kb-suite.
AUTH_HOST="${AUTH_HOST:-auth.kb.test}"
AUTH_BASE="http://${AUTH_HOST}:${GIT_PORT}"
KANBOARD_URL="${KANBOARD_URL:-http://localhost:8081}"
FORGEJO_CONTAINER="${FORGEJO_CONTAINER:-kb-git-forgejo}"
BROKER_CONTAINER="${BROKER_CONTAINER:-kb-git-broker}"
KANBOARD_CONTAINER="${KANBOARD_CONTAINER:-kb-suite}"
KANBOARD_API_ENDPOINT="${KANBOARD_API_ENDPOINT:-http://localhost:8081/jsonrpc.php}"
AGENT_USERNAME="${AGENT_USERNAME:-githost-e2e-agent}"
AGENT_USER_ID="${AGENT_USER_ID:-20}"
PROJECT_ID="${SCENARIO_PROJECT_ID:-28}"
REPO="${SCENARIO_REPO:-kb-ghe2e/app}"
BROKER_DIR="${BROKER_DIR:-$(cd "$HERE/../../.." && pwd)/kanboard-git-broker}"
HELPER=""
# The broker's 401 challenge; Forgejo's own says realm="Gitea".
BROKER_REALM='realm="Kanboard git"'

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

# Like status, for requests without a credential: "401+broker" when the broker's ForwardAuth
# challenged it. If a router lost its middleware, Forgejo answers instead (401 realm="Gitea",
# or 404), and the check fails.
challenge() { # challenge <method> <path> [extra curl args...]
  local method="$1" path="$2" out code
  shift 2
  out="$(curl -s -o /dev/null -D - --max-time 5 --path-as-is \
    --resolve "${GIT_HOST}:${GIT_PORT}:127.0.0.1" -X "$method" "$@" "${BASE}${path}")"
  code="$(printf '%s' "$out" | awk 'NR == 1 { print $2 }')"
  if printf '%s' "$out" | grep -qi "^www-authenticate:.*${BROKER_REALM}"; then
    echo "${code}+broker"
  else
    echo "${code:-000}"
  fi
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

step_preflight() {
  echo "preflight against ${BASE}"
  check "forgejo has no host port" "none" "$(forgejo_ports)"
  check "GET /api/healthz is 200 through Traefik" "200" "$(status GET /api/healthz)"
  # Without a credential the broker challenges (401 with its realm), so git asks its helper.
  check "GET /kb-x/r.git/info/refs?service=git-upload-pack is challenged by the broker" "401+broker" \
    "$(challenge GET '/kb-x/r.git/info/refs?service=git-upload-pack')"
  check "GET /api/v1/repos/kb-x/r is challenged by the broker" "401+broker" \
    "$(challenge GET /api/v1/repos/kb-x/r)"
  # F1: smart-HTTP without the .git suffix, push, LFS and token-auth web routes. A credential
  # the broker does not know is a 403 (Forgejo itself would answer 401).
  local bad=(-H 'Authorization: token not-a-real-token')
  check "GET /kb-x/r.git/info/refs?service=git-upload-pack with a bad token is 403" "403" \
    "$(status GET '/kb-x/r.git/info/refs?service=git-upload-pack' "${bad[@]}")"
  check "GET /kb-x/r/info/refs?service=git-upload-pack is 403" "403" \
    "$(status GET '/kb-x/r/info/refs?service=git-upload-pack' "${bad[@]}")"
  check "GET /kb-x/r/info/refs?service=git-receive-pack is 403" "403" \
    "$(status GET '/kb-x/r/info/refs?service=git-receive-pack' "${bad[@]}")"
  check "POST /kb-x/r/git-receive-pack is 403" "403" \
    "$(status POST /kb-x/r/git-receive-pack -H 'Content-Type: application/x-git-receive-pack-request' "${bad[@]}")"
  check "POST /kb-x/r.git/info/lfs/objects/batch is 403" "403" \
    "$(status POST /kb-x/r.git/info/lfs/objects/batch -H 'Content-Type: application/vnd.git-lfs+json' "${bad[@]}")"
  check "GET /kb-x/r/raw/branch/main/README.md is 403" "403" \
    "$(status GET /kb-x/r/raw/branch/main/README.md "${bad[@]}")"
  check "GET /kb-x/r/archive/main.zip is 403" "403" "$(status GET /kb-x/r/archive/main.zip "${bad[@]}")"
  # Any credentialed request on a non-exempt path, and the rest of /api/, is checked too.
  check "GET /api/v1/user with a token is 403" "403" \
    "$(status GET /api/v1/user -H 'Authorization: token not-a-real-token')"
  check "GET /kb-x with ?token= is 403" "403" "$(status GET '/kb-x?token=not-a-real-token')"
  check "GET /kb-x/r with basic auth is 403" "403" "$(status GET /kb-x/r -u 'someone:not-a-password')"
  # Exempt paths must not sit behind a dot-dot that Traefik forwards unnormalised.
  check "GET /assets/../kb-x/r.git/info/refs is challenged by the broker" "401+broker" \
    "$(challenge GET '/assets/../kb-x/r.git/info/refs?service=git-upload-pack')"
  # An encoded slash would dodge the repo regex and reach Forgejo unchecked; Traefik rejects it.
  check "GET /kb-x%2Fr.git/info/refs is rejected (400)" "400" \
    "$(status GET '/kb-x%2Fr.git/info/refs?service=git-upload-pack')"
}

# ---------------------------------------------------------------------------------------------
# Live helpers: Kanboard JSON-RPC, the credential helper, git through Traefik, the audit log.

WORK=""
cleanup_work() { [[ -n "$WORK" && -d "$WORK" ]] && rm -rf "$WORK"; }
on_exit() { restore_membership; cleanup_work; }
trap on_exit EXIT

need_creds() {
  local missing=()
  [[ -n "${KANBOARD_USERNAME:-}" ]] || missing+=(KANBOARD_USERNAME)
  [[ -n "${KANBOARD_API_KEY:-}" ]] || missing+=(KANBOARD_API_KEY)
  if [[ -z "${AGENT_API_TOKEN:-}" && -n "${AGENT_API_TOKEN_FILE:-}" && -r "$AGENT_API_TOKEN_FILE" ]]; then
    AGENT_API_TOKEN="$(<"$AGENT_API_TOKEN_FILE")"
  fi
  [[ -n "${AGENT_API_TOKEN:-}" ]] || missing+=("AGENT_API_TOKEN or AGENT_API_TOKEN_FILE")
  if ((${#missing[@]})); then
    echo "  FAIL  missing credentials: ${missing[*]} (env or testing/git/scenario.env)" >&2
    return 1
  fi
  if [[ ! -f "${WORK:-/nonexistent}/agent.apitoken" ]]; then
    ensure_work
    # The helper's KB_TOKEN_FILE must be 0600: a private copy of the agent's API token.
    (umask 077; printf '%s\n' "$AGENT_API_TOKEN" >"$WORK/agent.apitoken")
  fi
  need_helper
}

# A private (0700) temp dir for secrets and clones, removed on exit.
ensure_work() {
  [[ -n "$WORK" ]] && return 0
  WORK="$(mktemp -d "${TMPDIR:-/tmp}/kb-git-scenario.XXXXXX")"
  chmod 700 "$WORK"
}

# The credential helper is built on the host with cargo from the broker checkout (the same
# binary also ships in the broker image as /usr/local/bin/git-credential-kanboard). Set
# KB_HELPER to use a prebuilt one instead.
need_helper() {
  [[ -n "${HELPER:-}" ]] && return 0
  if [[ -n "${KB_HELPER:-}" ]]; then
    HELPER="$KB_HELPER"
  else
    cargo build --quiet --locked --bin git-credential-kanboard \
      --manifest-path "$BROKER_DIR/Cargo.toml" >&2 || return 1
    HELPER="$BROKER_DIR/target/debug/git-credential-kanboard"
  fi
  [[ -x "$HELPER" ]]
}

# JSON-RPC call; the password goes to curl through a 0600 config file, never argv.
rpc() { # rpc <user> <secret> <method> <params-json>
  local cfg="$WORK/curl.$$.cfg"
  (umask 077; printf 'user = "%s:%s"\n' "$1" "$2" >"$cfg")
  curl -s --max-time 15 -K "$cfg" -H 'Content-Type: application/json' \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$3\",\"params\":$4}" "$KANBOARD_API_ENDPOINT"
  rm -f "$cfg"
}
rpc_admin() { rpc "$KANBOARD_USERNAME" "$KANBOARD_API_KEY" "$@"; }
rpc_agent() { rpc "$AGENT_USERNAME" "$AGENT_API_TOKEN" "$@"; }

json_get() { # json_get <python expression on d> ; reads JSON on stdin
  python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))' "$1" 2>/dev/null
}

# The agent creates a task in the project and assigns it to itself; prints its id.
new_task() { # new_task <title>
  rpc_agent createTask "{\"project_id\":$PROJECT_ID,\"title\":\"$1\",\"owner_id\":$AGENT_USER_ID}" |
    json_get 'd["result"]'
}

# The agent's open task titled <title> assigned to itself, or a new one (idempotent setup).
claim_task() { # claim_task <title>
  local id
  id="$(rpc_agent getAllTasks "{\"project_id\":$PROJECT_ID,\"status_id\":1}" | python3 -I -c '
import json,sys
title,owner=sys.argv[1],int(sys.argv[2])
ts=[t for t in json.load(sys.stdin).get("result") or [] if t["title"]==title and int(t["owner_id"])==owner]
print(min(int(t["id"]) for t in ts) if ts else "")' "$1" "$AGENT_USER_ID" 2>/dev/null)"
  [[ -n "$id" ]] && { echo "$id"; return; }
  new_task "$1"
}

# git through Traefik with the real helper (credential.helper = the built binary, configured by
# KB_* variables) for <task>.
git_helper() { # git_helper <task_id> <git args...>
  local task="$1"
  shift
  env GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    KB_ENDPOINT="$KANBOARD_API_ENDPOINT" KB_USER="$AGENT_USERNAME" \
    KB_TOKEN_FILE="$WORK/agent.apitoken" KB_TASK_ID="$task" KB_GIT_HOST="${GIT_HOST}:${GIT_PORT}" \
    GIT_CONFIG_COUNT=5 \
    GIT_CONFIG_KEY_0=credential.helper GIT_CONFIG_VALUE_0='' \
    GIT_CONFIG_KEY_1=credential.helper GIT_CONFIG_VALUE_1="$HELPER" \
    GIT_CONFIG_KEY_2=http.curloptResolve GIT_CONFIG_VALUE_2="${GIT_HOST}:${GIT_PORT}:127.0.0.1" \
    GIT_CONFIG_KEY_3=user.name GIT_CONFIG_VALUE_3="$AGENT_USERNAME" \
    GIT_CONFIG_KEY_4=user.email GIT_CONFIG_VALUE_4="${AGENT_USERNAME}@agents.invalid" \
    git "$@"
}

# One helper `get`, as git would ask it; the output (username, password, expiry) goes to a
# 0600 file. A step uses it to hold a token minted *before* a change (removal, close, outage),
# because the helper itself would refuse to mint one after it.
helper_get() { # helper_get <task_id> <outfile>; stderr passes through
  (umask 077
   printf 'protocol=http\nhost=%s:%s\n\n' "$GIT_HOST" "$GIT_PORT" |
     env KB_ENDPOINT="$KANBOARD_API_ENDPOINT" KB_USER="$AGENT_USERNAME" \
       KB_TOKEN_FILE="$WORK/agent.apitoken" KB_TASK_ID="$1" KB_GIT_HOST="${GIT_HOST}:${GIT_PORT}" \
       "$HELPER" get >"$2")
}

# git with a credential file from helper_get, replayed by an inline helper (no new mint).
git_cached() { # git_cached <credfile> <git args...>
  local cred="$1"
  shift
  GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    SCENARIO_CRED_FILE="$cred" GIT_CONFIG_COUNT=3 \
    GIT_CONFIG_KEY_0=credential.helper GIT_CONFIG_VALUE_0='' \
    GIT_CONFIG_KEY_1=credential.helper \
    GIT_CONFIG_VALUE_1='!f() { test "$1" = get || exit 0; cat "$SCENARIO_CRED_FILE"; }; f' \
    GIT_CONFIG_KEY_2=http.curloptResolve GIT_CONFIG_VALUE_2="${GIT_HOST}:${GIT_PORT}:127.0.0.1" \
    git "$@"
}

redact() { sed -E 's/^password=.*/password=<redacted>/'; }
cred_field() { sed -n "s/^$1=//p" "$2"; } # cred_field <key> <credfile>
last_exit() { tail -1 <<<"$1"; }
denied() { [[ "$(last_exit "$1")" != "exit=0" ]] && echo yes || echo no; }
run_git() { "$@" 2>&1; echo "exit=$?"; } # output, then exit=N

# The broker's audit lines for <task> since <epoch>, verb authz. The broker image has no shell,
# so the log is read through docker cp.
audit_for_task() { # audit_for_task <task_id> <since-epoch>
  docker cp "$BROKER_CONTAINER:/var/lib/broker/audit" - 2>/dev/null | tar -xO 2>/dev/null |
    python3 -I -c '
import json,sys,datetime
task=int(sys.argv[1]); since=int(sys.argv[2])
for l in sys.stdin:
    try: e=json.loads(l)
    except ValueError: continue
    ts=datetime.datetime.strptime(e["ts"],"%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc).timestamp()
    if e.get("verb")=="authz" and e.get("task_id")==task and ts>=since: print(json.dumps(e))' "$1" "$2"
}

# The reasons of those lines, space-separated; with a target suffix, only those lines.
reasons_for_task() { # reasons_for_task <task_id> <since> [target-suffix]
  audit_for_task "$1" "$2" | python3 -I -c '
import json,sys
suf=sys.argv[1]
print(" ".join(e["reason"] for e in map(json.loads,sys.stdin) if e["target"].endswith(suf)))' "${3:-}"
}
# Allowed write lines on git-receive-pack (the push itself, not the advertisement).
push_allows() { # push_allows <task_id> <since>
  audit_for_task "$1" "$2" | python3 -I -c '
import json,sys
print(sum(1 for e in map(json.loads,sys.stdin) if e["target"].endswith("/git-receive-pack")
          and e["access"]=="write" and e["decision"]=="allow" and e["reason"]=="ok"))'
}
has_reason() { [[ " $2 " == *" $1 "* ]] && echo yes || echo "no ($2)"; }
has_any() { # has_any "<r1> <r2>" "<reasons>"
  local r
  for r in $1; do [[ " $2 " == *" $r "* ]] && { echo yes; return; }; done
  echo "no ($2)"
}
show_audit() { # show_audit <task_id> <since>
  echo "  audit lines (task $1):"
  audit_for_task "$1" "$2" | python3 -I -c '
import json,sys
for e in map(json.loads,sys.stdin):
    print("    %s %-4s %-5s %-5s %-21s %s" % (e["ts"][11:19], e["status"], e["access"], e["decision"], e["reason"], e["target"]))'
}

# ---------------------------------------------------------------------------------------------
# Shared state across steps 1, 2, 5a and 8: the agent's core task, its Forgejo login (from the
# helper's username=), and a clone with a pushed agent branch. Each step sets up what it needs
# if an earlier one did not run, so any subset of steps works.

CORE_TITLE="scenario core path"
CORE_TASK=""
FJ_USER=""
CLONE=""
BRANCH=""

ensure_core_task() {
  [[ -n "$CORE_TASK" ]] && return 0
  CORE_TASK="$(claim_task "$CORE_TITLE")"
  [[ "$CORE_TASK" =~ ^[0-9]+$ ]]
}

ensure_fj_user() {
  ensure_fj_user_for "$CORE_TASK"
  [[ -n "$FJ_USER" ]]
}

# The Forgejo login, from a helper reply for <task> (step 7 runs on its own task).
ensure_fj_user_for() {
  [[ -n "$FJ_USER" ]] && return 0
  helper_get "$1" "$WORK/cred.user" 2>/dev/null
  FJ_USER="$(cred_field username "$WORK/cred.user")"
}

ensure_clone() {
  [[ -n "$CLONE" && -d "$CLONE/.git" ]] && return 0
  CLONE="$WORK/clone-core"
  rm -rf "$CLONE"
  git_helper "$CORE_TASK" clone --quiet "${BASE}/${REPO}.git" "$CLONE" >/dev/null 2>&1
}

# ---------------------------------------------------------------------------------------------

step_1() {
  echo "1: agent ${AGENT_USERNAME} claims an open task in project ${PROJECT_ID}; the helper gets a credential"
  need_creds || { check "credentials and helper present" yes no; return; }
  ensure_core_task
  check "claim or create the open task \"${CORE_TITLE}\" assigned to the agent" yes \
    "$([[ "$CORE_TASK" =~ ^[0-9]+$ ]] && echo yes || echo "no ($CORE_TASK)")"
  local state
  state="$(rpc_agent getTask "{\"task_id\":${CORE_TASK:-0}}" |
    json_get 'str(d["result"]["owner_id"]) + "/" + str(d["result"]["is_active"])')"
  check "task ${CORE_TASK} is assigned to user ${AGENT_USER_ID} and open" "${AGENT_USER_ID}/1" "$state"
  echo "  helper: ${HELPER}"
  echo "  \$ printf 'protocol=http\\nhost=${GIT_HOST}:${GIT_PORT}\\n\\n' | KB_TASK_ID=${CORE_TASK} git-credential-kanboard get"
  local cred="$WORK/cred.1" err
  err="$(helper_get "$CORE_TASK" "$cred" 2>&1)"
  redact <"$cred" | sed 's/^/    /'
  [[ -n "$err" ]] && echo "    stderr: $err"
  FJ_USER="$(cred_field username "$cred")"
  check "helper prints username=" yes "$([[ -n "$FJ_USER" ]] && echo yes || echo no)"
  check "helper prints password=" yes "$([[ -n "$(cred_field password "$cred")" ]] && echo yes || echo no)"
  local exp now
  exp="$(cred_field password_expiry_utc "$cred")"
  now="$(date +%s)"
  check "helper prints password_expiry_utc= (epoch, in the next hour)" yes \
    "$([[ "$exp" =~ ^[0-9]+$ ]] && ((exp > now && exp <= now + 3700)) && echo yes || echo "no ($exp)")"
  check "helper store prints nothing" "" "$(printf 'protocol=http\nhost=%s:%s\n\n' "$GIT_HOST" "$GIT_PORT" | "$HELPER" store)"
  check "helper get for another host prints nothing" "" \
    "$(printf 'protocol=https\nhost=github.com\n\n' | KB_TASK_ID="$CORE_TASK" KB_TOKEN_FILE="$WORK/agent.apitoken" "$HELPER" get 2>/dev/null)"
}

step_2() {
  echo "2: clone ${REPO} through ${BASE} with the helper; branch agent/<login>/scenario-<ts>; commit; push"
  need_creds || { check "credentials and helper present" yes no; return; }
  ensure_core_task || { check "core task" yes no; return; }
  ensure_fj_user || { check "Forgejo login from the helper" yes no; return; }
  local since out
  since="$(date +%s)"
  CLONE="$WORK/clone-core"
  rm -rf "$CLONE"
  echo "  \$ git -c credential.helper=git-credential-kanboard clone ${BASE}/${REPO}.git   (KB_TASK_ID=${CORE_TASK})"
  out="$(run_git git_helper "$CORE_TASK" clone --quiet "${BASE}/${REPO}.git" "$CLONE")"
  echo "$out" | sed 's/^/    /'
  check "git clone through Traefik succeeds" "exit=0" "$(last_exit "$out")"
  BRANCH="agent/${FJ_USER}/scenario-$(date +%Y%m%d%H%M%S)-$$"
  git -C "$CLONE" checkout --quiet -b "$BRANCH" 2>/dev/null
  printf 'scenario %s\n' "$BRANCH" >"$CLONE/scenario-$(date +%s%N).txt"
  git -C "$CLONE" add -A >/dev/null 2>&1
  git_helper "$CORE_TASK" -C "$CLONE" commit --quiet -m "scenario: ${BRANCH}" >/dev/null 2>&1
  check "commit on ${BRANCH}" yes "$(git -C "$CLONE" log -1 --format=%s 2>/dev/null | grep -q "$BRANCH" && echo yes || echo no)"
  echo "  \$ git push origin ${BRANCH}"
  out="$(run_git git_helper "$CORE_TASK" -C "$CLONE" push --quiet origin "$BRANCH")"
  echo "$out" | sed 's/^/    /'
  check "push to the agent's own prefix is accepted" "exit=0" "$(last_exit "$out")"
  check "the branch is on the remote at the pushed commit" "$(git -C "$CLONE" rev-parse HEAD 2>/dev/null)" \
    "$(git_helper "$CORE_TASK" -C "$CLONE" ls-remote origin "refs/heads/$BRANCH" 2>/dev/null | cut -f1)"
  check "audit: the receive-pack was allowed (write, ok)" yes \
    "$(n="$(push_allows "$CORE_TASK" "$since")"; ((n >= 1)) && echo yes || echo "no ($n)")"
  show_audit "$CORE_TASK" "$since"
}

step_5a() {
  echo "5a: push to another agent's prefix and to main is denied by branch protection (authz allowed it)"
  need_creds || { check "credentials and helper present" yes no; return; }
  ensure_core_task && ensure_fj_user && ensure_clone || { check "core task, login and clone" yes no; return; }
  local since out other n
  since="$(date +%s)"
  other="agent/not-${FJ_USER}/scenario-$(date +%Y%m%d%H%M%S)-$$"
  # From the current main (steps 3, 4 and 6 move it), so the push to main is a fast-forward.
  git_helper "$CORE_TASK" -C "$CLONE" fetch --quiet origin main >/dev/null 2>&1
  git -C "$CLONE" checkout --quiet -B "scenario-5a" FETCH_HEAD 2>/dev/null
  printf '5a %s\n' "$other" >"$CLONE/scenario-5a-$(date +%s%N).txt"
  git -C "$CLONE" add -A >/dev/null 2>&1
  git_helper "$CORE_TASK" -C "$CLONE" commit --quiet -m "scenario 5a" >/dev/null 2>&1
  echo "  \$ git push origin HEAD:${other}"
  out="$(run_git git_helper "$CORE_TASK" -C "$CLONE" push origin "HEAD:refs/heads/${other}")"
  echo "$out" | grep -iE 'protected|rejected|declined|exit=' | sed 's/^/    /'
  check "push to another agent's prefix is denied" yes "$(denied "$out")"
  check "  ... by branch protection (Forgejo: protected branch)" yes \
    "$(grep -qi 'protected branch' <<<"$out" && echo yes || echo no)"
  echo "  \$ git push origin HEAD:main   (a fast-forward of main)"
  out="$(run_git git_helper "$CORE_TASK" -C "$CLONE" push origin HEAD:refs/heads/main)"
  echo "$out" | grep -iE 'protected|rejected|declined|exit=' | sed 's/^/    /'
  check "push to main is denied" yes "$(denied "$out")"
  check "  ... by branch protection (Forgejo: protected branch)" yes \
    "$(grep -qi 'protected branch' <<<"$out" && echo yes || echo no)"
  check "the other agent's branch was not created" "" \
    "$(git_helper "$CORE_TASK" -C "$CLONE" ls-remote origin "refs/heads/${other}" 2>/dev/null)"
  check "main did not move" no \
    "$([[ "$(git_helper "$CORE_TASK" -C "$CLONE" ls-remote origin refs/heads/main 2>/dev/null | cut -f1)" == "$(git -C "$CLONE" rev-parse HEAD)" ]] && echo yes || echo no)"
  n="$(push_allows "$CORE_TASK" "$since")"
  check "audit: authz allowed both receive-packs (write, ok), so the denial is not authz" yes \
    "$( ((n >= 2)) && echo yes || echo "no ($n)")"
  # F2: an agent may not post a commit status (kanboard/risk-gate is the broker's alone).
  local cred="$WORK/cred.5a" hdr="$WORK/hdr.5a"
  helper_get "$CORE_TASK" "$cred" 2>/dev/null
  (umask 077; printf 'Authorization: token %s\n' "$(cred_field password "$cred")" >"$hdr")
  check "agent POST /api/v1/repos/${REPO}/statuses/<sha> is 403" "403" \
    "$(status POST "/api/v1/repos/${REPO}/statuses/$(git -C "$CLONE" rev-parse HEAD 2>/dev/null)" \
      -H @"$hdr" -H 'Content-Type: application/json' -d '{"state":"success","context":"kanboard/risk-gate"}')"
  check "audit: the status post was denied (kanboard_deny)" yes \
    "$(has_reason kanboard_deny "$(reasons_for_task "$CORE_TASK" "$since")")"
  show_audit "$CORE_TASK" "$since"
}

# Step 7 removes the agent from the project; this puts it back, from the step or the EXIT trap.
REMOVED_ROLE=""
restore_membership() {
  [[ -z "$REMOVED_ROLE" ]] && return 0
  rpc_admin addProjectUser \
    "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID,\"role\":\"$REMOVED_ROLE\"}" >/dev/null
  REMOVED_ROLE=""
}

step_7() {
  echo "7: remove the agent from the project -> the next fetch is denied; close the task -> its token is dead"
  need_creds || { check "credentials and helper present" yes no; return; }
  local since since2 task out role reasons clone
  since="$(date +%s)"
  task="$(new_task 'scenario 7 revoke')"
  check "agent creates task in project ${PROJECT_ID}" yes "$([[ "$task" =~ ^[0-9]+$ ]] && echo yes || echo "no ($task)")"
  clone="$WORK/clone-7"
  out="$(run_git git_helper "$task" clone --quiet "${BASE}/${REPO}.git" "$clone")"
  check "clone with the helper succeeds" "exit=0" "$(last_exit "$out")"
  # A token minted while still a member: the one an agent mid-task would hold.
  helper_get "$task" "$WORK/cred.7a" 2>/dev/null
  check "helper minted a token before the removal" yes "$([[ -n "$(cred_field password "$WORK/cred.7a")" ]] && echo yes || echo no)"

  role="$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID}" | json_get 'd["result"]')"
  check "agent is a direct project member" yes "$([[ "$role" == project-* ]] && echo yes || echo "no ($role)")"
  [[ "$role" == project-* ]] || return
  REMOVED_ROLE="$role" # restored by restore_membership, here or on exit
  echo "  \$ removeProjectUser(${PROJECT_ID}, ${AGENT_USER_ID}): $(rpc_admin removeProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID}")"
  echo "  \$ git fetch (immediately, with the token minted before)"
  out="$(run_git git_cached "$WORK/cred.7a" -C "$clone" fetch)"
  echo "$out" | sed 's/^/    /'
  check "git fetch after removal is denied" yes "$(denied "$out")"
  reasons="$(reasons_for_task "$task" "$since")"
  check "audit: denied kanboard_deny (or token_revoked, if sync revoked it first)" yes \
    "$(has_any "kanboard_deny token_revoked" "$reasons")"
  out="$(helper_get "$task" "$WORK/cred.7x" 2>&1)"
  echo "    helper get now: stdout $(wc -c <"$WORK/cred.7x") bytes; stderr: ${out}"
  check "the helper mints nothing for a non-member (not_member)" yes \
    "$([[ ! -s "$WORK/cred.7x" && "$out" == *not_member* ]] && echo yes || echo no)"

  echo "  \$ addProjectUser(${PROJECT_ID}, ${AGENT_USER_ID}, ${role})"
  restore_membership
  # Forgejo team membership and the agent's branch rule come back with the plugin's sync nudge,
  # which the broker runs detached (202 at once). Until that sync has made a new catch-all, the
  # old one still matches first, so allow it a few seconds: bounded at 10 s, well under one
  # reconcile sweep (30 s), and report how long it took.
  local ok=no t0 n=0 b
  t0="$(date +%s)"
  out="$(run_git git_helper "$task" -C "$clone" fetch)"
  check "after re-adding, a fetch with the helper is allowed again" "exit=0" "$(last_exit "$out")"
  ensure_fj_user_for "$task"
  git -C "$clone" checkout --quiet -B scenario-7 2>/dev/null
  while (($(date +%s) - t0 < 10)); do
    n=$((n + 1))
    b="agent/${FJ_USER}/scenario-7-$(date +%Y%m%d%H%M%S)-$$-$n"
    out="$(run_git git_helper "$task" -C "$clone" push --quiet origin "HEAD:refs/heads/$b")"
    [[ "$(last_exit "$out")" == "exit=0" ]] && { ok=yes; break; }
    sleep 1
  done
  echo "  own-prefix push accepted again after $(($(date +%s) - t0)) s ($n attempt(s))"
  check "after re-adding, a push to the agent's own prefix is accepted (within 10 s)" yes "$ok"

  helper_get "$task" "$WORK/cred.7b" 2>/dev/null
  check "helper minted a token before the close" yes "$([[ -n "$(cred_field password "$WORK/cred.7b")" ]] && echo yes || echo no)"
  since2="$(date +%s)"
  echo "  \$ closeTask(${task}): $(rpc_admin closeTask "{\"task_id\":$task}")"
  echo "  \$ git fetch (immediately, with the token minted before the close)"
  out="$(run_git git_cached "$WORK/cred.7b" -C "$clone" fetch)"
  echo "$out" | sed 's/^/    /'
  check "git fetch after close is denied" yes "$(denied "$out")"
  check "audit: denied token_revoked or task_closed" yes \
    "$(has_any "token_revoked task_closed" "$(reasons_for_task "$task" "$since2")")"
  out="$(helper_get "$task" "$WORK/cred.7y" 2>&1)"
  echo "    helper get now: stdout $(wc -c <"$WORK/cred.7y") bytes; stderr: ${out}"
  check "the helper mints nothing for a closed task (task_closed)" yes \
    "$([[ ! -s "$WORK/cred.7y" && "$out" == *task_closed* ]] && echo yes || echo no)"
  check "membership restored" "$role" \
    "$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID}" | json_get 'd["result"]')"
  show_audit "$task" "$since"
}

step_8() {
  echo "8: Kanboard paused -> every git request denied; unpaused -> allowed again"
  need_creds || { check "credentials and helper present" yes no; return; }
  ensure_core_task && ensure_clone || { check "core task and clone" yes no; return; }
  local since out t0 t1
  since="$(date +%s)"
  helper_get "$CORE_TASK" "$WORK/cred.8" 2>/dev/null
  check "helper minted a token before the outage" yes "$([[ -n "$(cred_field password "$WORK/cred.8")" ]] && echo yes || echo no)"
  # Always unpause, whatever happens (F13: pause, never stop, the shared kb-suite).
  trap 'docker unpause "$KANBOARD_CONTAINER" >/dev/null 2>&1; on_exit' EXIT
  trap 'docker unpause "$KANBOARD_CONTAINER" >/dev/null 2>&1; exit 130' INT TERM
  echo "  \$ docker pause ${KANBOARD_CONTAINER}"
  docker pause "$KANBOARD_CONTAINER" >/dev/null
  t0="$(date +%s%N)"
  out="$(run_git git_cached "$WORK/cred.8" -C "$CLONE" fetch)"
  t1="$(date +%s%N)"
  echo "$out" | sed 's/^/    /'
  echo "  \$ docker unpause ${KANBOARD_CONTAINER}"
  docker unpause "$KANBOARD_CONTAINER" >/dev/null
  trap on_exit EXIT
  trap - INT TERM
  check "git fetch while Kanboard is paused is denied" yes "$(denied "$out")"
  echo "  denied fetch took $(((t1 - t0) / 1000000)) ms"
  check "audit: denied kanboard_unreachable" yes \
    "$(has_reason kanboard_unreachable "$(reasons_for_task "$CORE_TASK" "$since")")"
  check "kb-suite is running again" running "$(docker inspect -f '{{.State.Status}}' "$KANBOARD_CONTAINER")"
  out="$(run_git git_helper "$CORE_TASK" -C "$CLONE" fetch)"
  echo "$out" | sed 's/^/    /'
  check "git fetch with the helper after unpause succeeds" "exit=0" "$(last_exit "$out")"
  show_audit "$CORE_TASK" "$since"
}

# ---------------------------------------------------------------------------------------------
# Identity: Authentik OIDC into Kanboard (OAuth2 plugin) and Forgejo, joined on the sub.

IDENTITY_USER="${IDENTITY_USER:-idtest-human}"
LOCAL_USER="${IDENTITY_LOCAL_USER:-idtest-local}"
UNLINKED_USER="${IDENTITY_UNLINKED_USER:-idtest-unlinked}"
IDENTITY_PROVISION="${IDENTITY_PROVISION:-1}"

env_value() { sed -n "s/^$1=//p" "$2" 2>/dev/null | head -1; } # env_value <KEY> <file>
yesno() { "$@" && echo yes || echo no; }
urlencode() { python3 -I -c 'import sys,urllib.parse as u; print(u.quote(sys.argv[1], safe=""))' "$1"; }

# curl with a cookie jar; git.localhost and auth.kb.test are pinned to the host loopback.
web() { # web <jar> <curl args...>
  local jar="$1"
  shift
  curl -s --max-time 30 -b "$jar" -c "$jar" \
    --resolve "${GIT_HOST}:${GIT_PORT}:127.0.0.1" --resolve "${AUTH_HOST}:${GIT_PORT}:127.0.0.1" "$@"
}
# "<status> <absolute Location>" of one request; redirects are not followed.
web_hop() { # web_hop <jar> <url> [curl args...]
  local jar="$1" url="$2"
  shift 2
  web "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' "$@" "$url"
}

# Authentik admin API as akadmin; the bootstrap token is in a 0600 header file, never argv.
ak_api() { # ak_api <method> <path> [curl args...]
  local method="$1" path="$2"
  shift 2
  curl -s --max-time 30 --resolve "${AUTH_HOST}:${GIT_PORT}:127.0.0.1" -X "$method" \
    -H @"$WORK/ak.hdr" -H 'Accept: application/json' "$@" "${AUTH_BASE}${path}"
}

# Runs Authentik flow <slug> through the flow-executor API, answering the identification and
# password stages; prints where the flow sends the browser next.
ak_flow() { # ak_flow <jar> <slug> <query> <user> <pwfile>
  local jar="$1" url body comp csrf i
  url="${AUTH_BASE}/api/v3/flows/executor/$2/?query=$(urlencode "$3")"
  body="$(web "$jar" -H 'Accept: application/json' "$url")"
  for i in 1 2 3 4 5 6; do
    comp="$(json_get 'd.get("component","")' <<<"$body")"
    case "$comp" in
      xak-flow-redirect)
        json_get 'd["to"]' <<<"$body"
        return 0
        ;;
      ak-stage-identification)
        python3 -I -c 'import json,sys; print(json.dumps({"component": sys.argv[1], "uid_field": sys.argv[2]}))' \
          "$comp" "$4" >"$WORK/ak.answer"
        ;;
      ak-stage-password)
        (umask 077; python3 -I -c 'import json,sys; print(json.dumps({"component": sys.argv[1], "password": open(sys.argv[2]).read().strip()}))' \
          "$comp" "$5" >"$WORK/ak.answer")
        ;;
      *)
        echo "    flow $2: unexpected challenge '${comp}': $(head -c 400 <<<"$body")" >&2
        return 1
        ;;
    esac
    csrf="$(awk '$6 == "authentik_csrf" { print $7 }' "$jar" 2>/dev/null | tail -1)"
    # Authentik answers a POST with a redirect back to the executor; -L follows it as a GET.
    body="$(web "$jar" -L -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -H "X-authentik-CSRF: ${csrf}" -H "Referer: ${AUTH_BASE}/if/flow/$2/" \
      --data-binary @"$WORK/ak.answer" "$url")"
    rm -f "$WORK/ak.answer"
  done
  echo "    flow $2: still at '${comp}' after six answers (wrong password?)" >&2
  return 1
}

# Follows an OIDC authorize URL through Authentik (logging in as <user> when asked) until it
# leaves Authentik; prints that URL: the client's callback with ?code=.
ak_authorize() { # ak_authorize <jar> <authorize-url> <user> <pwfile>
  local jar="$1" url="$2" hop slug query i
  for i in $(seq 1 12); do
    case "$url" in
      "${AUTH_BASE}"/if/flow/*)
        read -r slug query < <(python3 -I -c '
import sys,urllib.parse as u
p=u.urlsplit(sys.argv[1]); print(p.path.split("/")[3], p.query)' "$url")
        url="$(ak_flow "$jar" "$slug" "$query" "$3" "$4")" || return 1
        [[ "$url" == /* ]] && url="${AUTH_BASE}${url}"
        ;;
      "${AUTH_BASE}"/*)
        hop="$(web_hop "$jar" "$url")"
        [[ -n "${hop#* }" ]] || { echo "    no redirect from ${url%%\?*} (${hop})" >&2; return 1; }
        url="${hop#* }"
        ;;
      *)
        echo "$url"
        return 0
        ;;
    esac
  done
  return 1
}

# Forgejo admin API (GET) inside the Forgejo container; the token goes in on stdin.
fj_api() { # fj_api <path>
  printf 'Authorization: token %s\n' "$(env_value FORGEJO_ADMIN_TOKEN "$HERE/.env")" |
    docker exec -i "$FORGEJO_CONTAINER" curl -s --max-time 10 -H @- "http://localhost:3000$1"
}

# "id oauth2_user_id" of a Kanboard user, read with PDO from the :8081 dev db.
kb_user() { # kb_user <username>
  docker exec "$KANBOARD_CONTAINER" php -r '
$p = new PDO("sqlite:/var/www/app/data/db.sqlite");
$s = $p->prepare("SELECT id, oauth2_user_id FROM users WHERE username = ?");
$s->execute([$argv[1]]);
if ($r = $s->fetch(PDO::FETCH_NUM)) { echo $r[0], " ", $r[1]; }' -- "$1" 2>/dev/null
}

# An HMAC-signed broker call (the broker has no host port, so it is sent from kb-suite, as
# GitHost does). Prints the body, then the status on its own line.
broker_post() { # broker_post <path> <json>
  local ts nonce sig
  ts="$(date +%s)"
  nonce="scenario-$(od -An -tx1 -N12 /dev/urandom | tr -d ' \n')"
  sig="$(env_value GITHOST_HMAC_SECRET "$HERE/hmac.env" | python3 -I -c '
import hashlib,hmac,sys
ts,nonce,method,path,body=sys.argv[1:]
msg="\n".join([ts,nonce,method,path,hashlib.sha256(body.encode()).hexdigest()])
print(hmac.new(sys.stdin.read().strip().encode(),msg.encode(),hashlib.sha256).hexdigest())' \
    "$ts" "$nonce" POST "$1" "$2")"
  printf '%s' "$2" | docker exec -i "$KANBOARD_CONTAINER" curl -s --max-time 30 -w '\n%{http_code}' \
    -H 'Content-Type: application/json' -H "X-GitHost-Timestamp: $ts" -H "X-GitHost-Nonce: $nonce" \
    -H "X-GitHost-Signature: $sig" --data-binary @- "http://broker:8080$1"
}

# The login of the Forgejo session in <jar> (from /user/settings), or nothing when signed out.
fj_session_login() { # fj_session_login <jar>
  web "$1" "${BASE}/user/settings" | sed -n 's/.*<input name="name" value="\([^"]*\)".*/\1/p' | head -1
}

# Makes sure Authentik user <name> exists (reused across runs) and gives it a fresh random
# password, written to <pwfile> (0600). Prints "<pk> <sub>": the OIDC sub of both providers
# (sub_mode hashed_user_id) is the user's uid.
ak_user() { # ak_user <name> <pwfile>
  local pk out
  pk="$(ak_api GET "/api/v3/core/users/?username=$(urlencode "$1")" |
    json_get 'd["results"][0]["pk"] if d.get("results") else ""')"
  if [[ -z "$pk" ]]; then
    out="$(ak_api POST /api/v3/core/users/ -H 'Content-Type: application/json' --data-binary "$(python3 -I -c '
import json,sys; u=sys.argv[1]
print(json.dumps({"username": u, "name": "Identity Test " + u, "email": u + "@kb.test", "is_active": True, "path": "users"}))' "$1")")"
    pk="$(json_get 'd["pk"]' <<<"$out")"
    echo "  created Authentik user $1 (pk ${pk})" >&2
  else
    echo "  reusing Authentik user $1 (pk ${pk})" >&2
  fi
  [[ -n "$pk" ]] || return 1
  (umask 077
   od -An -tx1 -N24 /dev/urandom | tr -d ' \n' >"$2"
   python3 -I -c 'import json,sys; print(json.dumps({"password": open(sys.argv[1]).read().strip()}))' "$2" >"$WORK/ak.setpw")
  out="$(ak_api POST "/api/v3/core/users/${pk}/set_password/" -o /dev/null -w '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary @"$WORK/ak.setpw")"
  rm -f "$WORK/ak.setpw"
  [[ "$out" == 204 ]] || { echo "  set_password for $1: $out" >&2; return 1; }
  echo "$pk $(ak_api GET "/api/v3/core/users/${pk}/" | json_get 'd["uid"]')"
}

step_identity() {
  echo "identity: ${IDENTITY_USER} signs in to Kanboard and Forgejo through Authentik (${AUTH_BASE}); joined on the sub"
  ensure_work
  local tok
  tok="$(env_value AUTHENTIK_BOOTSTRAP_TOKEN "$HERE/authentik.env")"
  check "testing/git/authentik.env holds the Authentik API token" yes "$(yesno test -n "$tok")"
  [[ -n "$tok" ]] || return
  (umask 077; printf 'Authorization: Bearer %s\n' "$tok" >"$WORK/ak.hdr")
  check "Authentik is ready through Traefik (GET ${AUTH_BASE}/-/health/ready/)" 200 \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 --resolve "${AUTH_HOST}:${GIT_PORT}:127.0.0.1" "${AUTH_BASE}/-/health/ready/")"

  # 1. The Authentik test user, reused across runs, with a fresh random password each run.
  local pk sub jar="$WORK/identity.jar" pw="$WORK/identity.pw" out hop cb
  read -r pk sub < <(ak_user "$IDENTITY_USER" "$pw")
  check "Authentik user ${IDENTITY_USER} exists and has a fresh random password" yes \
    "$(yesno test -n "$sub")"
  [[ -n "$sub" ]] || return
  rm -f "$jar"

  # 2. Kanboard: the OAuth2 plugin's login creates (or finds) the user by oauth2_user_id = sub.
  echo "  \$ GET ${KANBOARD_URL}/oauth/callback   (Kanboard's OAuth2 plugin login link)"
  hop="$(web_hop "$jar" "${KANBOARD_URL}/oauth/callback")"
  check "Kanboard redirects to Authentik's authorize endpoint" yes \
    "$(yesno test "${hop#* }" != "${hop#* ${AUTH_BASE}/application/o/authorize/}")"
  cb="$(ak_authorize "$jar" "${hop#* }" "$IDENTITY_USER" "$pw")"
  check "Authentik sends the browser back to Kanboard's callback with a code" yes \
    "$([[ "$cb" == "${KANBOARD_URL}/oauth/callback?"*code=* ]] && echo yes || echo "no (${cb%%\?*})")"
  hop="$(web_hop "$jar" "$cb")"
  echo "  callback: ${hop}"
  check "Kanboard accepts the code and logs the user in (302 into the app)" 302 "${hop%% *}"
  local row kb_id kb_sub
  row="$(kb_user "$IDENTITY_USER")"
  kb_id="${row%% *}"
  kb_sub="${row#* }"
  echo "  Kanboard user ${IDENTITY_USER}: id ${kb_id:-none}"
  # The dashboard's header links the signed-in user's own profile.
  check "the Kanboard session is ${IDENTITY_USER}'s (dashboard links /user/show/${kb_id:-?})" yes \
    "$(yesno grep -q "href=\"/user/show/${kb_id:-none}\"" <(web "$jar" "${KANBOARD_URL}/dashboard"))"
  check "PDO: users.oauth2_user_id of ${IDENTITY_USER} = the Authentik sub" "$sub" "${kb_sub:-<none>}"

  # 3. The broker pre-provisions the human: sync (nudged by addProjectUser) and POST /principals.
  local src fj_id="" fj_login="" i
  src="$(env_value FORGEJO_AUTHENTIK_SOURCE_ID "$HERE/.env")"
  if [[ "$IDENTITY_PROVISION" == 1 ]]; then
    if [[ -n "${KANBOARD_USERNAME:-}" && -n "${KANBOARD_API_KEY:-}" && "$kb_id" =~ ^[0-9]+$ ]]; then
      echo "  \$ addProjectUser(${PROJECT_ID}, ${kb_id}, project-member): $(rpc_admin addProjectUser \
        "{\"project_id\":$PROJECT_ID,\"user_id\":$kb_id,\"role\":\"project-member\"}" | json_get 'd.get("result")')"
    fi
    for i in $(seq 1 20); do
      read -r fj_id fj_login < <(fj_api "/api/v1/admin/users?source_id=${src}&login_name=$(urlencode "$sub")" |
        python3 -I -c '
import json,sys
src,sub=int(sys.argv[1]),sys.argv[2]
us=[u for u in json.load(sys.stdin) if u.get("source_id")==src and u.get("login_name")==sub]
print(us[0]["id"], us[0]["login"]) if len(us)==1 else print("")' "${src:-0}" "$sub" 2>/dev/null)
      [[ -n "$fj_id" ]] && break
      sleep 1
    done
    check "sync provisioned a Forgejo user on source ${src} with login_name = sub" yes \
      "$([[ -n "$fj_id" ]] && echo "yes" || echo no)"
    echo "  Forgejo user: ${fj_login:-none} (id ${fj_id:-none}) after ${i} s"
    out="$(broker_post /principals "{\"user_id\":${kb_id:-0},\"username\":\"${IDENTITY_USER}\",\"kind\":\"human\",\"authentik_sub\":\"${sub}\"}")"
    echo "  POST /principals: $(tr '\n' ' ' <<<"$out")"
    check "POST /principals is idempotent: 200 with that login" "200 ${fj_login}" \
      "$(tail -1 <<<"$out") $(head -n -1 <<<"$out" | json_get 'd.get("forgejo_username")')"
  else
    echo "  IDENTITY_PROVISION=0: pre-provisioning skipped"
  fi

  # 4. Forgejo: the OIDC login lands in that same account; nothing is auto-created.
  echo "  \$ GET ${BASE}/user/oauth2/authentik   (Forgejo's sign-in button)"
  hop="$(web_hop "$jar" "${BASE}/user/oauth2/authentik")"
  check "Forgejo redirects to Authentik's authorize endpoint" yes \
    "$(yesno test "${hop#* }" != "${hop#* ${AUTH_BASE}/application/o/authorize/}")"
  cb="$(ak_authorize "$jar" "${hop#* }" "$IDENTITY_USER" "$pw")"
  check "Authentik (already signed in: SSO) sends the browser back to Forgejo's callback" yes \
    "$([[ "$cb" == "${BASE}/user/oauth2/authentik/callback?"*code=* ]] && echo yes || echo "no (${cb%%\?*})")"
  hop="$(web_hop "$jar" "$cb")"
  echo "  callback: ${hop}"
  local s_login s_id n
  s_login="$(fj_session_login "$jar")"
  s_id="$([[ -n "$s_login" ]] && fj_api "/api/v1/users/${s_login}" | json_get 'd["id"]')"
  echo "  Forgejo session: ${s_login:-signed out} (id ${s_id:-none})"
  check "the Forgejo session is the pre-provisioned user (same id)" "${fj_id:-<pre-provisioned>}" "${s_id:-<signed out>}"
  n="$(fj_api "/api/v1/admin/users?source_id=${src}&login_name=$(urlencode "$sub")" |
    python3 -I -c 'import json,sys; print(sum(1 for u in json.load(sys.stdin) if u.get("login_name")==sys.argv[1]))' "$sub" 2>/dev/null)"
  check "exactly one Forgejo user is bound to the sub (no auto-created second account)" 1 "${n:-0}"

  # 5. Password sign-in on the web is off (agents are local users guarded by a password), but
  #    API basic auth still works: the broker's mint flow basic-auths as the agent. Forgejo 15
  #    has no CSRF form token (it checks Origin/Sec-Fetch headers, which curl does not send), so a
  #    403 here is the sign-in switch, not CSRF.
  local lpw="$WORK/local.pw" ljar="$WORK/local.jar"
  (umask 077; od -An -tx1 -N24 /dev/urandom | tr -d ' \n' >"$lpw")
  if ! fj_api "/api/v1/users/${LOCAL_USER}" | grep -q '"id"'; then
    LPW="$(<"$lpw")" docker exec -u git -e LPW "$FORGEJO_CONTAINER" sh -c \
      'forgejo admin user create --username "$1" --email "$1@agents.invalid" --password "$LPW" --must-change-password=false' \
      sh "$LOCAL_USER" >/dev/null 2>&1
  fi
  LPW="$(<"$lpw")" docker exec -u git -e LPW "$FORGEJO_CONTAINER" sh -c \
    'forgejo admin user change-password --username "$1" --password "$LPW" --must-change-password=false' \
    sh "$LOCAL_USER" >/dev/null 2>&1
  (umask 077; python3 -I -c '
import sys,urllib.parse as u
sys.stdout.write(u.urlencode({"user_name": sys.argv[1], "password": open(sys.argv[2]).read().strip()}))' \
    "$LOCAL_USER" "$lpw" >"$WORK/local.form")
  rm -f "$ljar"
  check "the sign-in page has no password field" no \
    "$(yesno grep -q 'name="password"' <(web "$ljar" "${BASE}/user/login"))"
  check "POST /user/login with ${LOCAL_USER}'s correct password is refused (403)" 403 \
    "$(web_hop "$ljar" "${BASE}/user/login" --data-binary @"$WORK/local.form" | cut -d' ' -f1)"
  check "  ... and no session: /user/settings is not ${LOCAL_USER}'s" "" "$(fj_session_login "$ljar")"
  check "API basic auth as ${LOCAL_USER} still works (GET /api/v1/user is 200)" 200 \
    "$(printf 'user = "%s:%s"\n' "$LOCAL_USER" "$(<"$lpw")" |
      docker exec -i "$FORGEJO_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -K - http://localhost:3000/api/v1/user)"

  # 6. An Authentik user nobody provisioned: Forgejo creates nothing and links nothing. Its
  #    "link account" page would sign in with a local password and bind the sub to that user;
  #    Traefik closes that form (router git-link-account).
  local upw="$WORK/unlinked.pw" ujar="$WORK/unlinked.jar" usub
  read -r _ usub < <(ak_user "$UNLINKED_USER" "$upw")
  check "Authentik user ${UNLINKED_USER} exists and has a fresh random password" yes "$(yesno test -n "$usub")"
  rm -f "$ujar"
  hop="$(web_hop "$ujar" "${BASE}/user/oauth2/authentik")"
  cb="$(ak_authorize "$ujar" "${hop#* }" "$UNLINKED_USER" "$upw")"
  hop="$(web_hop "$ujar" "$cb")"
  echo "  ${UNLINKED_USER} callback: ${hop}"
  check "an unprovisioned sub ends on /user/link_account, signed out" "${BASE}/user/link_account " \
    "${hop#* } $(fj_session_login "$ujar")"
  check "no Forgejo user is bound to that sub" 0 \
    "$(fj_api "/api/v1/admin/users?source_id=${src}&login_name=$(urlencode "${usub:-none}")" |
      python3 -I -c 'import json,sys; print(sum(1 for u in json.load(sys.stdin) if u.get("login_name")==sys.argv[1]))' "${usub:-none}" 2>/dev/null)"
  check "POST /user/link_account_signin with ${LOCAL_USER}'s correct password is 403" 403 \
    "$(web_hop "$ujar" "${BASE}/user/link_account_signin" --data-binary @"$WORK/local.form" | cut -d' ' -f1)"
  check "  ... and no session" "" "$(fj_session_login "$ujar")"
  rm -f "$WORK/local.form" "$ujar"
  hop="$(web_hop "$ujar" "${BASE}/user/oauth2/authentik")"
  cb="$(ak_authorize "$ujar" "${hop#* }" "$UNLINKED_USER" "$upw")"
  check "  ... and nothing was linked: a new login still ends on /user/link_account" "${BASE}/user/link_account" \
    "$(web_hop "$ujar" "$cb" | cut -d' ' -f2)"
}

# ---------------------------------------------------------------------------------------------
# Merge gate (steps 3, 4, 5c, 6): CI on the dev runner, the broker's kanboard/risk-gate status,
# reviewer agent r1's approval and auto-merge, the humans-high-risk approval, and auto-revert.
#
# Setup, idempotent and reused across runs:
# - r1 (REVIEWER_USERNAME) and a2 (OTHER_AGENT_USERNAME) are Kanboard agents (agents table, owner
#   KANBOARD_USERNAME's id) and members of the project; r1 is in the group git-reviewers, a2 is
#   not. They are written with PDO in kb-suite, and each run gives them a fresh random Kanboard
#   API token (0600, under WORK), so no credential of theirs outlives the run.
# - The human: IDENTITY_USER (Task 13's Authentik user, signed in by the identity step) is made a
#   project manager, so GitHost lists it in humans_high_risk. Its Forgejo API token
#   (write:repository, read:user) is minted with the Forgejo CLI once and kept in
#   testing/git/scenario-human.token (gitignored, 0600); a human has no password to rotate.
# - main gets .forgejo/workflows/ci.yml (testing/git/fixtures/ci.yml) through the high-risk flow
#   the first time a step needs CI.
# Agents act through Traefik with tokens minted by git-credential-kanboard for their own task, so
# every API call below passes the broker's live ForwardAuth.

REVIEWER_USERNAME="${REVIEWER_USERNAME:-githost-e2e-r1}"
OTHER_AGENT_USERNAME="${OTHER_AGENT_USERNAME:-githost-e2e-a2}"
HUMAN_TOKEN_FILE="${HUMAN_TOKEN_FILE:-$HERE/scenario-human.token}"
RUNNER_CONTAINER="${RUNNER_CONTAINER:-kb-git-ci-runner}"
CI_FIXTURE="$HERE/fixtures/ci.yml"
CI_PATH=".forgejo/workflows/ci.yml"
CI_PR_CONTEXT="ci / test (pull_request)"
CI_MAIN_CONTEXT="ci / test (push)"
RISK_GATE="kanboard/risk-gate"
ORG="${REPO%%/*}"
GATE_WAIT="${GATE_WAIT:-240}"

declare -A AGENT_IDS=() AGENT_TASKS=()
HUMAN_LOGIN=""

# Waits up to <secs> for <cmd...> to print "yes"; prints how long it took.
wait_yes() { # wait_yes <secs> <cmd...>
  local secs="$1" t0
  shift
  t0="$(date +%s)"
  while (($(date +%s) - t0 < secs)); do
    [[ "$("$@" 2>/dev/null)" == yes ]] && { echo "$(($(date +%s) - t0))"; return 0; }
    sleep 2
  done
  echo "timeout"
  return 1
}

# Forgejo API through Traefik with an Authorization header file. Prints the body, then the
# status on its own line.
api() { # api <hdrfile> <method> <path> [json]
  local args=(-s --max-time 30 --resolve "${GIT_HOST}:${GIT_PORT}:127.0.0.1" -X "$2" -H @"$1"
    -H 'Accept: application/json' -w '\n%{http_code}')
  [[ -n "${4:-}" ]] && args+=(-H 'Content-Type: application/json' --data-binary "$4")
  curl "${args[@]}" "${BASE}/api/v1$3"
}
code_of() { tail -1 <<<"$1"; }
body_of() { head -n -1 <<<"$1"; }

# A Kanboard agent for the scenario, written with PDO: the user (app-user), its agents row, its
# git-reviewers membership (reviewer=1) or none (0), and a fresh API token from stdin. Prints its id.
upsert_agent() { # upsert_agent <username> <reviewer 0|1> <tokenfile>
  docker exec -i -u nginx "$KANBOARD_CONTAINER" php -r '
$p = new PDO("sqlite:/var/www/app/data/db.sqlite");
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
[$_, $user, $reviewer, $owner] = $argv;
$token = trim(stream_get_contents(STDIN));
$p->beginTransaction();
$s = $p->prepare("SELECT id FROM users WHERE username = ?");
$s->execute([$user]);
$id = $s->fetchColumn();
if ($id === false) {
  $p->prepare("INSERT INTO users (username, password, name, role, is_active, api_access_token) VALUES (?, ?, ?, ?, 1, ?)")
    ->execute([$user, password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), "Scenario agent " . $user, "app-user", $token]);
  $id = $p->lastInsertId();
} else {
  $p->prepare("UPDATE users SET api_access_token = ?, is_active = 1 WHERE id = ?")->execute([$token, $id]);
}
$s = $p->prepare("SELECT COUNT(*) FROM agents WHERE agent_user_id = ?");
$s->execute([$id]);
if ((int) $s->fetchColumn() === 0) {
  $p->prepare("INSERT INTO agents (owner_user_id, agent_user_id, kind, created_at) VALUES (?, ?, ?, ?)")
    ->execute([$owner, $id, "githost-e2e", time()]);
}
$p->exec("INSERT OR IGNORE INTO groups (name) VALUES (\x27git-reviewers\x27)");
$gid = $p->query("SELECT id FROM groups WHERE name = \x27git-reviewers\x27")->fetchColumn();
if ($reviewer === "1") {
  $p->prepare("INSERT OR IGNORE INTO group_has_users (group_id, user_id) VALUES (?, ?)")->execute([$gid, $id]);
} else {
  $p->prepare("DELETE FROM group_has_users WHERE group_id = ? AND user_id = ?")->execute([$gid, $id]);
}
$p->commit();
echo $id;' -- "$1" "$2" "$3" <"$3"
}

# The Kanboard user id of KANBOARD_USERNAME (the agents' owner).
admin_user_id() {
  rpc_admin getMe '{}' | json_get 'd["result"]["id"]'
}

# Logins (lowercased) in the org's Forgejo team <team>, comma-separated.
team_logins() { # team_logins <team>
  local id
  id="$(fj_api "/api/v1/orgs/${ORG}/teams?limit=50" | python3 -I -c '
import json,sys; t=[x["id"] for x in json.load(sys.stdin) if x["name"]==sys.argv[1]]; print(t[0] if t else "")' "$1")"
  [[ -n "$id" ]] || return
  fj_api "/api/v1/teams/${id}/members?limit=50" | python3 -I -c '
import json,sys; print(",".join(sorted(u["login"].lower() for u in json.load(sys.stdin))))'
}
in_team() { [[ ",$(team_logins "$1")," == *",${2,,},"* ]] && echo yes || echo no; } # in_team <team> <login>

# Makes <username> a scenario agent in the project and gives it an open task; sets
# AGENT_IDS[<username>] and AGENT_TASKS[<username>]. Its API token is $WORK/<username>.apitoken.
ensure_agent() { # ensure_agent <username> <reviewer 0|1>
  local user="$1" tok="$WORK/$1.apitoken" owner id role task
  [[ -n "${AGENT_TASKS[$user]:-}" ]] && return 0
  owner="$(admin_user_id)"
  [[ "$owner" =~ ^[0-9]+$ ]] || { echo "  cannot read ${KANBOARD_USERNAME}'s user id" >&2; return 1; }
  (umask 077; od -An -tx1 -N32 /dev/urandom | tr -d ' \n' >"$tok")
  id="$(upsert_agent "$user" "$2" "$tok")"
  [[ "$id" =~ ^[0-9]+$ ]] || { echo "  upsert_agent $user: $id" >&2; return 1; }
  AGENT_IDS[$user]="$id"
  role="$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$id}" | json_get 'd["result"]')"
  if [[ "$role" != project-member ]]; then
    rpc_admin addProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$id,\"role\":\"project-member\"}" >/dev/null
  fi
  task="$(rpc "$user" "$(<"$tok")" getAllTasks "{\"project_id\":$PROJECT_ID,\"status_id\":1}" | python3 -I -c '
import json,sys
owner=int(sys.argv[1]); ts=[t for t in json.load(sys.stdin).get("result") or [] if t["title"]=="scenario merge gate" and int(t["owner_id"])==owner]
print(min(int(t["id"]) for t in ts) if ts else "")' "$id" 2>/dev/null)"
  if [[ -z "$task" ]]; then
    task="$(rpc "$user" "$(<"$tok")" createTask \
      "{\"project_id\":$PROJECT_ID,\"title\":\"scenario merge gate\",\"owner_id\":$id}" | json_get 'd["result"]')"
  fi
  [[ "$task" =~ ^[0-9]+$ ]] || { echo "  $user: no task ($task)" >&2; return 1; }
  AGENT_TASKS[$user]="$task"
}

# An Authorization header file with a token git-credential-kanboard mints for <user>'s task.
agent_hdr() { # agent_hdr <username> <task> <hdrfile>
  local cred="$WORK/cred.$1" tok
  tok="$([[ "$1" == "$AGENT_USERNAME" ]] && echo "$WORK/agent.apitoken" || echo "$WORK/$1.apitoken")"
  (umask 077
   printf 'protocol=http\nhost=%s:%s\n\n' "$GIT_HOST" "$GIT_PORT" |
     env KB_ENDPOINT="$KANBOARD_API_ENDPOINT" KB_USER="$1" KB_TOKEN_FILE="$tok" KB_TASK_ID="$2" \
       KB_GIT_HOST="${GIT_HOST}:${GIT_PORT}" "$HELPER" get >"$cred" 2>/dev/null
   printf 'Authorization: token %s\n' "$(cred_field password "$cred")" >"$3")
  [[ -n "$(cred_field password "$cred")" ]]
}

# The Forgejo login of the broker principal of Kanboard user <username> (agents: same name).
fj_login_of() { fj_api "/api/v1/users/$1" | json_get 'd["login"]'; }

# The scenario's reviewers: r1 in git-reviewers, a2 a plain member. Waits until Forgejo has r1 in
# `reviewers` and a2 in `members` but not `reviewers`.
ensure_reviewers() {
  [[ -n "${AGENT_TASKS[$REVIEWER_USERNAME]:-}" && -n "${AGENT_TASKS[$OTHER_AGENT_USERNAME]:-}" ]] && return 0
  ensure_agent "$REVIEWER_USERNAME" 1 && ensure_agent "$OTHER_AGENT_USERNAME" 0 || return 1
  broker_post /reconcile '' >/dev/null
  wait_yes 60 reviewers_ready >/dev/null
}
reviewers_ready() {
  [[ "$(in_team reviewers "$REVIEWER_USERNAME") $(in_team members "$OTHER_AGENT_USERNAME") $(in_team reviewers "$OTHER_AGENT_USERNAME")" == "yes yes no" ]] &&
    echo yes || echo no
}

# The human high-risk approver: IDENTITY_USER as a project manager (humans_high_risk), its
# Forgejo login in HUMAN_LOGIN, and a header file $WORK/hdr.human with its token.
ensure_human() {
  [[ -n "$HUMAN_LOGIN" && -s "$WORK/hdr.human" ]] && return 0
  local row kb_id sub src role tok
  row="$(kb_user "$IDENTITY_USER")"
  kb_id="${row%% *}"
  sub="${row#* }"
  if [[ ! "$kb_id" =~ ^[0-9]+$ || -z "$sub" || "$sub" == "$row" ]]; then
    echo "  ${IDENTITY_USER} has no Authentik sub yet: running the identity step first"
    step_identity
    row="$(kb_user "$IDENTITY_USER")"
    kb_id="${row%% *}"
    sub="${row#* }"
  fi
  [[ "$kb_id" =~ ^[0-9]+$ && -n "$sub" ]] || return 1
  role="$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$kb_id}" | json_get 'd["result"]')"
  if [[ "$role" == project-* && "$role" != project-manager ]]; then
    rpc_admin changeProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$kb_id,\"role\":\"project-manager\"}" >/dev/null
  elif [[ "$role" != project-manager ]]; then
    rpc_admin addProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$kb_id,\"role\":\"project-manager\"}" >/dev/null
  fi
  src="$(env_value FORGEJO_AUTHENTIK_SOURCE_ID "$HERE/.env")"
  HUMAN_LOGIN="$(fj_api "/api/v1/admin/users?source_id=${src}&login_name=$(urlencode "$sub")" | python3 -I -c '
import json,sys; us=[u for u in json.load(sys.stdin) if u.get("login_name")==sys.argv[1]]; print(us[0]["login"] if len(us)==1 else "")' "$sub")"
  [[ -n "$HUMAN_LOGIN" ]] || return 1
  broker_post /reconcile '' >/dev/null
  wait_yes 60 in_team humans-high-risk "$HUMAN_LOGIN" >/dev/null || return 1
  # The token: kept across runs (a human has no password to rotate, and the CLI cannot delete
  # tokens), minted again only when Forgejo no longer accepts it.
  tok="$(cat "$HUMAN_TOKEN_FILE" 2>/dev/null)"
  if [[ -z "$tok" || "$(printf 'Authorization: token %s\n' "$tok" |
        docker exec -i "$FORGEJO_CONTAINER" curl -s -o /dev/null -w '%{http_code}' -H @- http://localhost:3000/api/v1/user)" != 200 ]]; then
    tok="$(docker exec -u git "$FORGEJO_CONTAINER" forgejo admin user generate-access-token \
      --username "$HUMAN_LOGIN" --token-name "scenario-high-risk-$(date +%s)" \
      --scopes write:repository,read:user --raw 2>/dev/null)"
    (umask 077; printf '%s\n' "$tok" >"$HUMAN_TOKEN_FILE")
  fi
  (umask 077; printf 'Authorization: token %s\n' "$tok" >"$WORK/hdr.human")
  [[ -n "$tok" ]]
}

# The PR's JSON (admin read, inside the Forgejo container).
pr_json() { fj_api "/api/v1/repos/${REPO}/pulls/$1"; }
pr_field() { pr_json "$1" | json_get "$2"; } # pr_field <n> <python expr on d>
pr_merged() { [[ "$(pr_field "$1" 'd["merged"]')" == True ]] && echo yes || echo no; }
pr_head() { pr_field "$1" 'd["head"]["sha"]'; }

# "<status> <creator>: <description>" of context <ctx> on <sha>, or nothing.
status_of() { # status_of <sha> <context>
  fj_api "/api/v1/repos/${REPO}/commits/$1/status" | python3 -I -c '
import json,sys
ss=[s for s in (json.load(sys.stdin).get("statuses") or []) if s["context"]==sys.argv[1]]
print("%s %s: %s" % (ss[0]["status"], (ss[0].get("creator") or {}).get("login",""), ss[0]["description"]) if ss else "")' "$2"
}
status_is() { [[ "$(status_of "$1" "$2")" == "$3"* ]] && echo yes || echo no; } # status_is <sha> <ctx> <state>

# A fresh clone of main for <task>, in $WORK/<name>.
fresh_clone() { # fresh_clone <task> <name>
  rm -rf "${WORK:?}/$2"
  git_helper "$1" clone --quiet "${BASE}/${REPO}.git" "$WORK/$2" >/dev/null 2>&1
}

# Pushes the clone's HEAD to <branch> and opens a PR into main as the agent; prints its number.
push_and_open() { # push_and_open <clone> <branch> <title>
  local out
  out="$(run_git git_helper "$CORE_TASK" -C "$1" push --quiet origin "HEAD:refs/heads/$2")"
  [[ "$(last_exit "$out")" == exit=0 ]] || { echo "push: $out" >&2; return 1; }
  agent_hdr "$AGENT_USERNAME" "$CORE_TASK" "$WORK/hdr.a1" || return 1
  out="$(api "$WORK/hdr.a1" POST "/repos/${REPO}/pulls" "$(python3 -I -c '
import json,sys; print(json.dumps({"head": sys.argv[1], "base": "main", "title": sys.argv[2]}))' "$2" "$3")")"
  [[ "$(code_of "$out")" == 201 ]] || { echo "open PR: $(code_of "$out") $(body_of "$out" | head -c 300)" >&2; return 1; }
  body_of "$out" | json_get 'd["number"]'
}

# r1 approves PR <n> and schedules "merge when checks succeed" (squash); prints both codes.
r1_approve_and_schedule() { # r1_approve_and_schedule <n>
  local out a s
  agent_hdr "$REVIEWER_USERNAME" "${AGENT_TASKS[$REVIEWER_USERNAME]}" "$WORK/hdr.r1" || { echo "mint failed"; return 1; }
  # Schedule first, then approve. Forgejo checks a scheduled auto-merge only on a later event
  # (a status or a review); scheduled in the same moment as the last check turns green, it can
  # miss that event and stay open. The approval always comes after the schedule this way.
  out="$(api "$WORK/hdr.r1" POST "/repos/${REPO}/pulls/$1/merge" '{"Do":"squash","merge_when_checks_succeed":true}')"
  s="$(code_of "$out")"
  # 201: scheduled (the usual case: no approval yet).
  [[ "$s" == 200 || "$s" == 201 ]] && s=ok
  out="$(api "$WORK/hdr.r1" POST "/repos/${REPO}/pulls/$1/reviews" '{"event":"APPROVED","body":"r1: looks good"}')"
  a="$(code_of "$out")"
  echo "approve=${a} schedule=${s}"
}

# The high-risk flow of step 4 (and the seed of ci.yml): a PR changing .forgejo/workflows/ci.yml
# (the fixture when main has none, else a new stamp line) stays pending on the risk gate, with CI
# green and r1's approval, until the human approves. Records checks.
highrisk_flow() { # highrisk_flow <label>
  local clone="clone-hr" branch n head out t
  fresh_clone "$CORE_TASK" "$clone" || { check "clone main" yes no; return 1; }
  branch="agent/${FJ_USER}/scenario-hr-$(date +%Y%m%d%H%M%S)-$$"
  git -C "$WORK/$clone" checkout --quiet -b "$branch"
  mkdir -p "$WORK/$clone/.forgejo/workflows"
  if [[ -f "$WORK/$clone/$CI_PATH" ]]; then
    sed -i "s/^# scenario-stamp: .*/# scenario-stamp: $(date -u +%Y%m%dT%H%M%SZ)-$$/" "$WORK/$clone/$CI_PATH"
    echo "  $1: change ${CI_PATH} (new stamp line)"
  else
    cp "$CI_FIXTURE" "$WORK/$clone/$CI_PATH"
    echo "  $1: add ${CI_PATH} from testing/git/fixtures/ci.yml (main has none yet)"
  fi
  git -C "$WORK/$clone" add -A
  git_helper "$CORE_TASK" -C "$WORK/$clone" commit --quiet -m "scenario: ${1}: ${CI_PATH}" >/dev/null 2>&1
  n="$(push_and_open "$WORK/$clone" "$branch" "scenario ${1}: change ${CI_PATH}")"
  check "$1: agent opens a PR changing ${CI_PATH} (through Traefik)" yes "$([[ "$n" =~ ^[0-9]+$ ]] && echo yes || echo "no ($n)")"
  [[ "$n" =~ ^[0-9]+$ ]] || return 1
  head="$(pr_head "$n")"
  echo "  PR #${n} head ${head:0:7}"
  t="$(wait_yes 60 status_is "$head" "$RISK_GATE" pending)"
  check "$1: ${RISK_GATE} is pending (posted by the broker within 60 s: ${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  echo "    ${RISK_GATE}: $(status_of "$head" "$RISK_GATE")"
  check "$1: ... written by the broker identity, naming ${CI_PATH}" yes \
    "$([[ "$(status_of "$head" "$RISK_GATE")" == "pending kb-admin:"*"$CI_PATH"* ]] && echo yes || echo no)"

  # F2: the agent cannot clear the gate itself.
  local since
  since="$(date +%s)"
  out="$(api "$WORK/hdr.a1" POST "/repos/${REPO}/statuses/${head}" \
    '{"state":"success","context":"kanboard/risk-gate","description":"forged by the agent"}')"
  check "$1: agent POST ${RISK_GATE}=success through Traefik is 403" 403 "$(code_of "$out")"
  check "$1: ... audit: kanboard_deny" yes "$(has_reason kanboard_deny "$(reasons_for_task "$CORE_TASK" "$since" "/statuses/${head}")")"
  check "$1: ... and the gate is still pending" yes "$(status_is "$head" "$RISK_GATE" pending)"

  out="$(r1_approve_and_schedule "$n")"
  check "$1: r1 schedules auto-merge and approves" "approve=200 schedule=ok" "$out"
  t="$(wait_yes "$GATE_WAIT" status_is "$head" "$CI_PR_CONTEXT" success)"
  check "$1: CI (${CI_PR_CONTEXT}) is green on the runner (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  sleep 4
  check "$1: with CI green and r1's approval, the PR is still open: blocked on ${RISK_GATE}" "no pending" \
    "$(pr_merged "$n") $(status_of "$head" "$RISK_GATE" | cut -d' ' -f1)"

  out="$(api "$WORK/hdr.human" POST "/repos/${REPO}/pulls/${n}/reviews" "{\"event\":\"APPROVED\",\"body\":\"${HUMAN_LOGIN}: high-risk change approved\"}")"
  check "$1: ${HUMAN_LOGIN} (humans-high-risk) approves through Traefik" 200 "$(code_of "$out")"
  t="$(wait_yes 60 status_is "$head" "$RISK_GATE" success)"
  check "$1: ${RISK_GATE} turns success (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  echo "    ${RISK_GATE}: $(status_of "$head" "$RISK_GATE")"
  t="$(wait_yes 90 pr_merged "$n")"
  check "$1: the PR merges on its own (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
}

# main has ci.yml; if not, the high-risk flow adds it (needs the human).
ensure_ci_seeded() {
  if fj_api "/api/v1/repos/${REPO}/contents/${CI_PATH}?ref=main" | grep -q '"sha"'; then
    return 0
  fi
  echo "  setup: main has no ${CI_PATH}; seeding it through the high-risk flow"
  ensure_human || { check "setup: human high-risk approver" yes no; return 1; }
  highrisk_flow "seed"
}

# Shared start of steps 3, 4 and 6: credentials, the agent's core task and login, the runner,
# r1 and a2, and ci.yml on main.
gate_setup() {
  need_creds || { check "credentials and helper present" yes no; return 1; }
  ensure_core_task && ensure_fj_user || { check "core task and Forgejo login" yes no; return 1; }
  check "the dev runner (${RUNNER_CONTAINER}, profile ci-dev) is running" true \
    "$(docker inspect -f '{{.State.Running}}' "$RUNNER_CONTAINER" 2>/dev/null)"
  ensure_reviewers
  check "r1 (${REVIEWER_USERNAME}) is in Forgejo reviewers; a2 (${OTHER_AGENT_USERNAME}) is a member but not a reviewer" \
    "yes yes no" "$(in_team reviewers "$REVIEWER_USERNAME") $(in_team members "$OTHER_AGENT_USERNAME") $(in_team reviewers "$OTHER_AGENT_USERNAME")"
  ensure_ci_seeded
}

step_3() {
  echo "3: low-risk PR -> CI green, risk gate success, r1 schedules auto-merge and approves -> merged on green"
  gate_setup || return
  local clone="clone-3" branch n head out t
  fresh_clone "$CORE_TASK" "$clone" || { check "clone main" yes no; return; }
  branch="agent/${FJ_USER}/scenario-3-$(date +%Y%m%d%H%M%S)-$$"
  git -C "$WORK/$clone" checkout --quiet -b "$branch"
  mkdir -p "$WORK/$clone/scenario"
  printf 'step 3 %s\n' "$branch" >"$WORK/$clone/scenario/step3-$(date +%s%N).txt"
  git -C "$WORK/$clone" add -A
  git_helper "$CORE_TASK" -C "$WORK/$clone" commit --quiet -m "scenario 3: low-risk change" >/dev/null 2>&1
  n="$(push_and_open "$WORK/$clone" "$branch" "scenario 3: low-risk change")"
  check "agent opens a PR (through Traefik)" yes "$([[ "$n" =~ ^[0-9]+$ ]] && echo yes || echo "no ($n)")"
  [[ "$n" =~ ^[0-9]+$ ]] || return
  head="$(pr_head "$n")"
  echo "  PR #${n} head ${head:0:7}"
  t="$(wait_yes 60 status_is "$head" "$RISK_GATE" success)"
  check "${RISK_GATE} is success from the broker (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  echo "    ${RISK_GATE}: $(status_of "$head" "$RISK_GATE")"
  check "  ... written by the broker identity" yes "$([[ "$(status_of "$head" "$RISK_GATE")" == "success kb-admin: no high-risk paths" ]] && echo yes || echo no)"
  t="$(wait_yes "$GATE_WAIT" status_is "$head" "$CI_PR_CONTEXT" success)"
  check "CI (${CI_PR_CONTEXT}) is green on the dev runner (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"

  # A member who is not a reviewer: the approval is recorded but does not count.
  agent_hdr "$OTHER_AGENT_USERNAME" "${AGENT_TASKS[$OTHER_AGENT_USERNAME]}" "$WORK/hdr.a2"
  out="$(api "$WORK/hdr.a2" POST "/repos/${REPO}/pulls/${n}/reviews" '{"event":"APPROVED","body":"a2 is not a reviewer"}')"
  check "a2 (not in git-reviewers) approves through Traefik (allowed: a member)" 200 "$(code_of "$out")"
  check "  ... Forgejo marks a2's approval not official" False "$(body_of "$out" | json_get 'd["official"]')"
  out="$(api "$WORK/hdr.a1" POST "/repos/${REPO}/pulls/${n}/merge" '{"Do":"squash"}')"
  echo "    merge now as the author: $(code_of "$out") $(body_of "$out" | json_get 'd.get("message","")' | head -c 120)"
  check "  ... so with CI and the gate green, merging now is refused (405) and the PR stays open" "405 no" \
    "$(code_of "$out") $(pr_merged "$n")"

  local since
  since="$(date +%s)"
  out="$(r1_approve_and_schedule "$n")"
  check "r1 schedules auto-merge and approves through Traefik" "approve=200 schedule=ok" "$out"
  check "  ... audit: r1's review and merge requests were allowed (write, ok)" yes \
    "$(audit_for_task "${AGENT_TASKS[$REVIEWER_USERNAME]}" "$since" | python3 -I -c '
import json,sys
es=[e for e in map(json.loads,sys.stdin) if e["access"]=="write"]
ok=lambda s: any(e["target"].split("?")[0].endswith(s) and e["decision"]=="allow" and e["reason"]=="ok" for e in es)
print("yes" if ok("/reviews") and ok("/merge") else "no")')"
  t="$(wait_yes 90 pr_merged "$n")"
  check "the PR merges on green (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  check "  ... merged by r1" "${REVIEWER_USERNAME}" "$(pr_field "$n" '(d.get("merged_by") or {}).get("login","")')"
}

step_4() {
  echo "4: a change to ${CI_PATH} stays pending on ${RISK_GATE} until a humans-high-risk member approves"
  gate_setup || return
  ensure_human || { check "human high-risk approver ${IDENTITY_USER} (project manager, Forgejo token)" yes no; return; }
  check "${HUMAN_LOGIN} is in Forgejo humans-high-risk; r1 is not" "yes no" \
    "$(in_team humans-high-risk "$HUMAN_LOGIN") $(in_team humans-high-risk "$REVIEWER_USERNAME")"
  highrisk_flow "4"
}

step_5c() {
  echo "5c: force-push to main is denied by branch protection (authz allowed it)"
  need_creds || { check "credentials and helper present" yes no; return; }
  ensure_core_task && ensure_fj_user || { check "core task and login" yes no; return; }
  local clone="clone-5c" since out before
  fresh_clone "$CORE_TASK" "$clone" || { check "clone main" yes no; return; }
  before="$(git -C "$WORK/$clone" rev-parse HEAD)"
  git_helper "$CORE_TASK" -C "$WORK/$clone" commit --quiet --amend --allow-empty -m "scenario 5c: rewritten main" >/dev/null 2>&1
  check "a rewritten main tip (not a fast-forward)" no \
    "$(git -C "$WORK/$clone" merge-base --is-ancestor "$before" HEAD && echo yes || echo no)"
  since="$(date +%s)"
  echo "  \$ git push --force origin HEAD:main"
  out="$(run_git git_helper "$CORE_TASK" -C "$WORK/$clone" push --force origin HEAD:refs/heads/main)"
  echo "$out" | grep -iE 'protected|rejected|declined|force|exit=' | sed 's/^/    /'
  check "force-push to main is denied" yes "$(denied "$out")"
  check "  ... by branch protection (Forgejo: protected from force push)" yes \
    "$(grep -qi 'protected from force push' <<<"$out" && echo yes || echo no)"
  check "main did not move" "$before" \
    "$(git_helper "$CORE_TASK" -C "$WORK/$clone" ls-remote origin refs/heads/main 2>/dev/null | cut -f1)"
  check "audit: authz allowed the receive-pack (write, ok), so the denial is not authz" yes \
    "$(n="$(push_allows "$CORE_TASK" "$since")"; ((n >= 1)) && echo yes || echo "no ($n)")"
}

# The open-or-closed revert PR for <sha> (number), from the admin API.
revert_pr_for() { fj_api "/api/v1/repos/${REPO}/pulls/main/revert%2F${1:0:7}" | json_get 'd["number"]'; }
has_revert_pr() { [[ "$(revert_pr_for "$1")" =~ ^[0-9]+$ ]] && echo yes || echo no; }
main_sha() { fj_api "/api/v1/repos/${REPO}/branches/main" | json_get 'd["commit"]["id"]'; }

# A run cut off inside step 6 can leave main red with its revert PR waiting for r1: finish it
# first, so this run starts from a green main.
settle_red_main() {
  local sha rn t
  sha="$(main_sha)"
  [[ "$(status_is "$sha" "$CI_MAIN_CONTEXT" failure)" == yes ]] || return 0
  echo "  setup: main ${sha:0:7} is red from an earlier run; finishing its revert"
  wait_yes 90 has_revert_pr "$sha" >/dev/null
  rn="$(revert_pr_for "$sha")"
  [[ "$rn" =~ ^[0-9]+$ ]] || return 1
  if [[ "$(pr_merged "$rn")" == no ]]; then
    agent_hdr "$REVIEWER_USERNAME" "${AGENT_TASKS[$REVIEWER_USERNAME]}" "$WORK/hdr.r1"
    api "$WORK/hdr.r1" POST "/repos/${REPO}/pulls/${rn}/reviews" '{"event":"APPROVED","body":"r1: revert approved"}' >/dev/null
    wait_yes "$GATE_WAIT" pr_merged "$rn" >/dev/null || return 1
  fi
  t="$(wait_yes "$GATE_WAIT" status_is "$(main_sha)" "$CI_MAIN_CONTEXT" success)"
  echo "  setup: revert #${rn} merged; main green after ${t} s"
}

step_6() {
  echo "6: break main (ci/red merges green, CI fails on main) -> the broker opens a revert PR -> it merges -> main green"
  gate_setup || return
  settle_red_main || { check "setup: an earlier red main is settled" yes no; return; }
  local clone="clone-6" branch n head bad out t rn rhead files count
  fresh_clone "$CORE_TASK" "$clone" || { check "clone main" yes no; return; }
  branch="agent/${FJ_USER}/scenario-6-$(date +%Y%m%d%H%M%S)-$$"
  git -C "$WORK/$clone" checkout --quiet -b "$branch"
  mkdir -p "$WORK/$clone/ci"
  printf 'red %s\n' "$branch" >"$WORK/$clone/ci/red"
  git -C "$WORK/$clone" add -A
  git_helper "$CORE_TASK" -C "$WORK/$clone" commit --quiet -m "scenario 6: break main (ci/red)" >/dev/null 2>&1
  n="$(push_and_open "$WORK/$clone" "$branch" "scenario 6: a change that breaks main")"
  check "agent opens a PR adding ci/red" yes "$([[ "$n" =~ ^[0-9]+$ ]] && echo yes || echo "no ($n)")"
  [[ "$n" =~ ^[0-9]+$ ]] || return
  out="$(r1_approve_and_schedule "$n")"
  check "r1 schedules auto-merge and approves" "approve=200 schedule=ok" "$out"
  t="$(wait_yes "$GATE_WAIT" pr_merged "$n")"
  check "the PR is green (CI and gate) and merges (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  bad="$(pr_field "$n" 'd["merge_commit_sha"]')"
  echo "  main is now ${bad:0:7}"
  t="$(wait_yes "$GATE_WAIT" status_is "$bad" "$CI_MAIN_CONTEXT" failure)"
  check "CI on main (${CI_MAIN_CONTEXT}) is red (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"

  t="$(wait_yes 90 has_revert_pr "$bad")"
  check "the broker opens revert/${bad:0:7} (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  rn="$(revert_pr_for "$bad")"
  [[ "$rn" =~ ^[0-9]+$ ]] || return
  echo "  revert PR #${rn}: $(pr_field "$rn" 'd["title"]')"
  check "  ... opened by the broker identity, labelled auto-revert" "kb-admin auto-revert" \
    "$(pr_field "$rn" '(d.get("user") or {}).get("login","") + " " + ",".join(l["name"] for l in d.get("labels") or [])')"
  files="$(fj_api "/api/v1/repos/${REPO}/pulls/${rn}/files" | python3 -I -c '
import json,sys; print(" ".join(sorted("%s:%s" % (f["status"], f["filename"]) for f in json.load(sys.stdin))))')"
  check "  ... reverting exactly the bad change" "deleted:ci/red" "$files"
  rhead="$(pr_head "$rn")"
  t="$(wait_yes 60 status_is "$rhead" "$RISK_GATE" success)"
  check "  ... ${RISK_GATE} success: a broker revert is low risk (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  echo "    ${RISK_GATE}: $(status_of "$rhead" "$RISK_GATE")"
  agent_hdr "$REVIEWER_USERNAME" "${AGENT_TASKS[$REVIEWER_USERNAME]}" "$WORK/hdr.r1"
  out="$(api "$WORK/hdr.r1" POST "/repos/${REPO}/pulls/${rn}/reviews" '{"event":"APPROVED","body":"r1: revert approved"}')"
  check "r1 approves the revert PR (the broker scheduled its auto-merge)" 200 "$(code_of "$out")"
  t="$(wait_yes "$GATE_WAIT" pr_merged "$rn")"
  check "the revert PR merges (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  local fixed
  fixed="$(main_sha)"
  t="$(wait_yes "$GATE_WAIT" status_is "$fixed" "$CI_MAIN_CONTEXT" success)"
  check "main (${fixed:0:7}) is green again (${t} s)" yes "$([[ "$t" =~ ^[0-9]+$ ]] && echo yes || echo no)"
  check "  ... and has no ci/red" no \
    "$(fj_api "/api/v1/repos/${REPO}/contents/ci/red?ref=main" | grep -q '"sha"' && echo yes || echo no)"

  # Idempotent: another sweep on the same bad sha opens nothing.
  broker_post /reconcile '' >/dev/null
  count="$(fj_api "/api/v1/repos/${REPO}/pulls?state=all&sort=recentupdate&limit=50" | python3 -I -c '
import json,sys; print(sum(1 for p in json.load(sys.stdin) if p["head"]["ref"]==sys.argv[1]))' "revert/${bad:0:7}")"
  check "one revert PR for ${bad:0:7}, after another sweep" 1 "$count"
}

# Dispatch table, in run order for `all`: <name> <function> <description>. The spec's numeric
# step ids only (ruling F10).
STEPS=(
  "preflight step_preflight stack invariants: no Forgejo port, every repo route behind ForwardAuth"
  "1         step_1         the agent claims an open task; git-credential-kanboard get prints username, password, expiry"
  "2         step_2         clone through Traefik with the helper, branch agent/<login>/scenario-<ts>, commit, push accepted"
  "3         step_3         low-risk PR: CI green on the dev runner, risk gate success; a2's approval does not count; r1 approves + auto-merge -> merged"
  "4         step_4         a change to .forgejo/workflows/ci.yml: risk gate pending (agent cannot forge it, 403) until a humans-high-risk member approves"
  "5a        step_5a        push to another agent's prefix and to main denied by branch protection (authz ok); status post 403"
  "5c        step_5c        force-push to main denied by branch protection (authz ok); main unchanged"
  "6         step_6         break main (ci/red): CI red on main -> broker opens revert/<sha7> (auto-revert, low risk) -> r1 approves -> merged, main green; one PR per sha"
  "7         step_7         removeProjectUser: next fetch denied; re-add; closeTask: token dead (token_revoked/task_closed)"
  "8         step_8         docker pause kb-suite: fetch denied (kanboard_unreachable); unpause: allowed"
  # Not a numbered spec step: the spec's sequencing exit for identity (Task 13).
  "identity  step_identity  Authentik OIDC: Kanboard oauth2_user_id = sub; Forgejo login lands in the pre-provisioned user; no password web login"
)

step_fn() { # step_fn <name>: prints the function of a step, or nothing
  local row
  for row in "${STEPS[@]}"; do
    read -r name fn _ <<<"$row"
    [[ "$name" == "$1" ]] && { echo "$fn"; return; }
  done
}

usage() {
  echo "usage: bash testing/git/scenario.sh <step>... | all | list" >&2
  echo "steps:" >&2
  local row
  for row in "${STEPS[@]}"; do
    read -r name _ desc <<<"$row"
    printf '  %-10s %s\n' "$name" "$desc" >&2
  done
}

main() {
  local names=() row fn
  case "${1:-}" in
    "" ) usage; exit 2 ;;
    list) usage; exit 0 ;;
    all) for row in "${STEPS[@]}"; do read -r name _ <<<"$row"; names+=("$name"); done ;;
    *) names=("$@") ;;
  esac
  for name in "${names[@]}"; do
    fn="$(step_fn "$name")"
    if [[ -z "$fn" ]]; then
      echo "unknown step: $name" >&2
      usage
      exit 2
    fi
  done
  for name in "${names[@]}"; do
    local before_pass=$pass before_total=$total
    "$(step_fn "$name")"
    local p=$((pass - before_pass)) t=$((total - before_total))
    if [[ "$p" -eq "$t" ]]; then
      echo "${name}: PASS (${p}/${t})"
    else
      echo "${name}: FAIL (${p}/${t})"
    fi
  done
  if ((${#names[@]} > 1)); then
    [[ "$pass" -eq "$total" ]] && echo "scenario: PASS (${pass}/${total})" || echo "scenario: FAIL (${pass}/${total})"
  fi
  [[ "$pass" -eq "$total" ]]
}

main "$@"
