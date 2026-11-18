#!/usr/bin/env bash
# scenario.sh — git hosting P1 scenario steps, run on the host.
#
# Usage:
#   bash testing/git/scenario.sh <step>...      e.g. preflight credential revoke outage
#   bash testing/git/scenario.sh all            every step once, in table order
#   bash testing/git/scenario.sh list           step names and what each checks
#
# The stack must be up first (see testing/git/compose.sh and testing/README.md):
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/bootstrap.sh
#
# Steps live in one dispatch table (STEPS below): name, function, description. Renaming a step
# (Task 12 moves to the spec's numeric ids) is a one-line change there.
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
# The minted git token is kept in a 0600 file under a private temp dir and handed to git by an
# inline credential helper that reads that file, so it never appears on argv or in output.
# Global and system git config are ignored, so no credential store keeps it.
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
# Live helpers: Kanboard JSON-RPC, a minted credential, git through Traefik, the audit log.

WORK=""
cleanup_work() { [[ -n "$WORK" && -d "$WORK" ]] && rm -rf "$WORK"; }
trap cleanup_work EXIT

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
  fi
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

# Creates a task assigned to the agent; prints its id.
new_task() { # new_task <title>
  rpc_admin createTask "{\"project_id\":$PROJECT_ID,\"title\":\"$1\",\"owner_id\":$AGENT_USER_ID}" |
    json_get 'd["result"]'
}

# getGitCredential as the agent; writes the token to $WORK/token.<task> (0600). Prints the
# reply with the token redacted.
mint() { # mint <task_id>
  local reply
  reply="$(rpc_agent getGitCredential "{\"task_id\":$1}")"
  (umask 077; printf '%s' "$reply" | json_get 'd["result"].get("token","")' >"$WORK/token.$1")
  printf '%s' "$reply" | python3 -I -c '
import json,sys
d=json.load(sys.stdin); r=d.get("result")
if isinstance(r,dict) and "token" in r: r["token"]="<redacted>"
print(json.dumps(d))'
  [[ -s "$WORK/token.$1" ]]
}

# git through Traefik as the agent, with the token of <task> from its 0600 file.
git_as_agent() { # git_as_agent <task_id> <git args...>
  local task="$1"
  shift
  GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    SCENARIO_GIT_USER="$AGENT_USERNAME" SCENARIO_TOKEN_FILE="$WORK/token.$task" \
    GIT_CONFIG_COUNT=2 \
    GIT_CONFIG_KEY_0=credential.helper \
    GIT_CONFIG_VALUE_0='!f() { test "$1" = get || exit 0; echo "username=$SCENARIO_GIT_USER"; echo "password=$(cat "$SCENARIO_TOKEN_FILE")"; }; f' \
    GIT_CONFIG_KEY_1=http.curloptResolve \
    GIT_CONFIG_VALUE_1="${GIT_HOST}:${GIT_PORT}:127.0.0.1" \
    git "$@"
}

clone() { # clone <task_id> <dir>; prints git's output and "exit=N"
  local rc
  git_as_agent "$1" clone --quiet "${BASE}/${REPO}.git" "$2" 2>&1
  rc=$?
  echo "exit=$rc"
  return $rc
}

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

# The reasons of those lines, space-separated.
reasons_for_task() { audit_for_task "$1" "$2" | json_get_lines 'd["reason"]' | tr '\n' ' ' | sed 's/ $//'; }
json_get_lines() {
  python3 -I -c 'import json,sys
for l in sys.stdin: d=json.loads(l); print(eval(sys.argv[1]))' "$1"
}

has_reason() { [[ " $2 " == *" $1 "* ]] && echo yes || echo "no ($2)"; }

# ---------------------------------------------------------------------------------------------

step_credential() {
  echo "credential: agent ${AGENT_USERNAME} clones ${REPO} through ${BASE} with a minted token"
  need_creds || { check "credentials present" yes no; return; }
  local since task
  since="$(date +%s)"
  task="$(new_task 'scenario credential')"
  check "createTask in project ${PROJECT_ID} assigned to the agent" yes "$([[ "$task" =~ ^[0-9]+$ ]] && echo yes || echo "no ($task)")"
  echo "  \$ getGitCredential($task) as ${AGENT_USERNAME}"
  echo "  $(mint "$task")"
  check "getGitCredential returned a token" yes "$([[ -s "$WORK/token.$task" ]] && echo yes || echo no)"
  echo "  \$ git clone ${BASE}/${REPO}.git"
  local out
  out="$(clone "$task" "$WORK/clone-$task")"
  echo "$out" | sed 's/^/    /'
  check "git clone through Traefik succeeds" "exit=0" "$(echo "$out" | tail -1)"
  check "the clone has a HEAD commit" yes \
    "$(git -C "$WORK/clone-$task" rev-parse -q --verify HEAD >/dev/null 2>&1 && echo yes || echo no)"
  # F2: an agent may not post a commit status (kanboard/risk-gate is the broker's alone).
  local hdr="$WORK/hdr.$task"
  (umask 077; printf 'Authorization: token %s\n' "$(<"$WORK/token.$task")" >"$hdr")
  check "agent POST /api/v1/repos/${REPO}/statuses/<sha> is 403" "403" \
    "$(status POST "/api/v1/repos/${REPO}/statuses/$(git -C "$WORK/clone-$task" rev-parse HEAD 2>/dev/null)" \
      -H @"$hdr" -H 'Content-Type: application/json' -d '{"state":"success","context":"kanboard/risk-gate"}')"
  local reasons
  reasons="$(reasons_for_task "$task" "$since")"
  check "audit: the clone was allowed (reason ok)" yes "$(has_reason ok "$reasons")"
  check "audit: the status post was denied (kanboard_deny)" yes "$(has_reason kanboard_deny "$reasons")"
  echo "  audit lines (task $task):"
  audit_for_task "$task" "$since" | sed 's/^/    /'
  rpc_admin closeTask "{\"task_id\":$task}" >/dev/null
}

step_revoke() {
  echo "revoke: closing the task makes the next fetch fail (no wait)"
  need_creds || { check "credentials present" yes no; return; }
  local since task out
  since="$(date +%s)"
  task="$(new_task 'scenario revoke')"
  mint "$task" >/dev/null
  check "getGitCredential returned a token" yes "$([[ -s "$WORK/token.$task" ]] && echo yes || echo no)"
  out="$(clone "$task" "$WORK/clone-$task")"
  check "git clone before close succeeds" "exit=0" "$(echo "$out" | tail -1)"
  echo "  \$ closeTask($task): $(rpc_admin closeTask "{\"task_id\":$task}")"
  echo "  \$ git fetch (immediately)"
  out="$(git_as_agent "$task" -C "$WORK/clone-$task" fetch 2>&1; echo "exit=$?")"
  echo "$out" | sed 's/^/    /'
  check "git fetch after close is denied" yes "$([[ "$(echo "$out" | tail -1)" != "exit=0" ]] && echo yes || echo no)"
  local reasons
  reasons="$(reasons_for_task "$task" "$since")"
  check "audit: denied token_revoked or task_closed" yes \
    "$([[ " $reasons " == *" token_revoked "* || " $reasons " == *" task_closed "* ]] && echo yes || echo "no ($reasons)")"
  echo "  audit lines (task $task):"
  audit_for_task "$task" "$since" | sed 's/^/    /'
}

step_outage() {
  echo "outage: Kanboard paused → every git request denied; unpaused → allowed again"
  need_creds || { check "credentials present" yes no; return; }
  local since task out t0 t1
  since="$(date +%s)"
  task="$(new_task 'scenario outage')"
  mint "$task" >/dev/null
  check "getGitCredential returned a token" yes "$([[ -s "$WORK/token.$task" ]] && echo yes || echo no)"
  # Always unpause, whatever happens (F13: pause, never stop, the shared kb-suite).
  trap 'docker unpause "$KANBOARD_CONTAINER" >/dev/null 2>&1; cleanup_work' EXIT
  trap 'docker unpause "$KANBOARD_CONTAINER" >/dev/null 2>&1; exit 130' INT TERM
  echo "  \$ docker pause ${KANBOARD_CONTAINER}"
  docker pause "$KANBOARD_CONTAINER" >/dev/null
  t0="$(date +%s%N)"
  out="$(clone "$task" "$WORK/clone-paused-$task")"
  t1="$(date +%s%N)"
  echo "$out" | sed 's/^/    /'
  echo "  \$ docker unpause ${KANBOARD_CONTAINER}"
  docker unpause "$KANBOARD_CONTAINER" >/dev/null
  trap cleanup_work EXIT
  trap - INT TERM
  check "git clone while Kanboard is paused is denied" yes \
    "$([[ "$(echo "$out" | tail -1)" != "exit=0" ]] && echo yes || echo no)"
  echo "  denied clone took $(((t1 - t0) / 1000000)) ms"
  local reasons
  reasons="$(reasons_for_task "$task" "$since")"
  check "audit: denied kanboard_unreachable" yes "$(has_reason kanboard_unreachable "$reasons")"
  check "kb-suite is running again" running "$(docker inspect -f '{{.State.Status}}' "$KANBOARD_CONTAINER")"
  out="$(clone "$task" "$WORK/clone-$task")"
  echo "$out" | sed 's/^/    /'
  check "git clone after unpause succeeds" "exit=0" "$(echo "$out" | tail -1)"
  echo "  audit lines (task $task):"
  audit_for_task "$task" "$since" | sed 's/^/    /'
  rpc_admin closeTask "{\"task_id\":$task}" >/dev/null
}

# Dispatch table, in run order for `all`: <name> <function> <description>. Rename here only.
STEPS=(
  "preflight  step_preflight   stack invariants: no Forgejo port, every repo route behind ForwardAuth"
  "credential step_credential  getGitCredential, then git clone through Traefik succeeds; agent status post 403"
  "revoke     step_revoke      closeTask, then the next git fetch is denied (token_revoked/task_closed)"
  "outage     step_outage      docker pause kb-suite: clone denied (kanboard_unreachable); unpause: allowed"
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
    printf '  %-11s %s\n' "$name" "$desc" >&2
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
