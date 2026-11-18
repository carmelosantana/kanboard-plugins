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
  if [[ -z "$WORK" ]]; then
    WORK="$(mktemp -d "${TMPDIR:-/tmp}/kb-git-scenario.XXXXXX")"
    chmod 700 "$WORK"
    # The helper's KB_TOKEN_FILE must be 0600: a private copy of the agent's API token.
    (umask 077; printf '%s\n' "$AGENT_API_TOKEN" >"$WORK/agent.apitoken")
  fi
  need_helper
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
  git -C "$CLONE" checkout --quiet -B "scenario-5a" 2>/dev/null
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
  # Forgejo team membership and the agent's branch rule come back with the plugin's sync nudge.
  # The nudge can be cut short (its 2 s client timeout drops the broker's inline sync), leaving
  # an older catch-all ahead of the new agent rule until the next reconcile sweep (30 s). So
  # wait, bounded, for a push to the agent's own prefix, and report how long it took: later
  # steps and the next run need the rule set restored.
  local ok=no t0 n=0 b
  t0="$(date +%s)"
  out="$(run_git git_helper "$task" -C "$clone" fetch)"
  check "after re-adding, a fetch with the helper is allowed again" "exit=0" "$(last_exit "$out")"
  ensure_fj_user_for "$task"
  git -C "$clone" checkout --quiet -B scenario-7 2>/dev/null
  while (($(date +%s) - t0 < 90)); do
    n=$((n + 1))
    b="agent/${FJ_USER}/scenario-7-$(date +%Y%m%d%H%M%S)-$$-$n"
    out="$(run_git git_helper "$task" -C "$clone" push --quiet origin "HEAD:refs/heads/$b")"
    [[ "$(last_exit "$out")" == "exit=0" ]] && { ok=yes; break; }
    sleep 5
  done
  echo "  own-prefix push accepted again after $(($(date +%s) - t0)) s ($n attempt(s))"
  check "after re-adding, a push to the agent's own prefix is accepted (within 90 s)" yes "$ok"

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

# Dispatch table, in run order for `all`: <name> <function> <description>. The spec's numeric
# step ids only (ruling F10).
STEPS=(
  "preflight step_preflight stack invariants: no Forgejo port, every repo route behind ForwardAuth"
  "1         step_1         the agent claims an open task; git-credential-kanboard get prints username, password, expiry"
  "2         step_2         clone through Traefik with the helper, branch agent/<login>/scenario-<ts>, commit, push accepted"
  "5a        step_5a        push to another agent's prefix and to main denied by branch protection (authz ok); status post 403"
  "7         step_7         removeProjectUser: next fetch denied; re-add; closeTask: token dead (token_revoked/task_closed)"
  "8         step_8         docker pause kb-suite: fetch denied (kanboard_unreachable); unpause: allowed"
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
