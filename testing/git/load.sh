#!/usr/bin/env bash
# load.sh — git hosting P1 load runs and measurements (Task 17), on the :8081 dev stack only.
#
# Usage:
#   bash testing/git/load.sh run <N> <target>...  N agents at once, each: credential (git-credential-kanboard
#                                                 get) -> clone -> commit -> push to agent/<login>/load-<run>
#   bash testing/git/load.sh agents <N>           make load-a1..load-aN (idempotent) and wait for Forgejo
#   bash testing/git/load.sh teardown <N>         remove load-a1..load-aN from the project (the users stay)
#   bash testing/git/load.sh prune <regex>        delete the branches of SCENARIO_REPO matching <regex>
#   bash testing/git/load.sh revlag               revocation lag: git (ForwardAuth) and web UI (cookie)
#   bash testing/git/load.sh ram                  one docker stats sample of the stack
#
# Targets (ruling F11: Forgejo keeps no host port, so the baseline runs on the overlay network):
#   traefik      git on the host -> http://git.localhost:8090 (Traefik + ForwardAuth): the agent path
#   traefik-net  git in a throwaway container on kanboard-suite_default -> Traefik's :8090
#   direct       the same container -> http://forgejo:3000: no Traefik, no ForwardAuth (baseline)
# traefik-net against direct is the like-for-like overhead; the container is the pinned Forgejo
# image (it has git and bash), run as the caller's uid with the run dir mounted.
#
# Agents load-a<n> are Kanboard agents made with scenario.sh's ensure_agent (PDO in kb-suite +
# JSON-RPC as KANBOARD_USERNAME), members of SCENARIO_PROJECT_ID, each with one open task "load
# run". Never the Kanboard MCP tools: they point at production. Credentials are minted once per
# run and agent, before the git phase, and kept in 0600 files under the run dir; nothing prints
# them. Every summary line starts with LOAD|, FA| or RAM| for metrics.md.
#
# Env: LOAD_OUT (run dir parent, default ${TMPDIR:-/tmp}), LOAD_ROUNDS (rounds per target,
# default 1; percentiles pool every agent of every round), plus scenario.sh's (scenario.env).
set -uo pipefail

LOAD_SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"

# Microseconds; bash's clock (busybox date in the container has no %N).
now_us() { echo "${EPOCHREALTIME//[.,]/}"; }

# One agent's git phase; runs on the host or in the container. Appends
# "<i> <clone_ms> <clone_exit> <push_ms> <push_exit>" to <out>/git.tsv.
_worker() { # _worker <i> <repo-url> <credfile> <out> <branch> [curloptResolve]
  local i="$1" url="$2" cred="$3" out="$4" branch="$5" resolve="${6:-}" dir t0 t1 t2 t3 ce pe
  dir="$out/clone-$i"
  rm -rf "$dir"
  local cfg=(-c credential.helper=
    -c 'credential.helper=!f() { test "$1" = get || exit 0; grep -E "^(username|password)=" "$LOAD_CRED"; }; f'
    -c user.name=load -c user.email=load@agents.invalid)
  [[ -n "$resolve" ]] && cfg+=(-c "http.curloptResolve=$resolve")
  export GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null LOAD_CRED="$cred"
  t0="$(now_us)"
  git "${cfg[@]}" clone --quiet "$url" "$dir" >"$out/clone-$i.err" 2>&1
  ce=$?
  t1="$(now_us)"
  pe=99
  if ((ce == 0)); then
    git -C "$dir" checkout --quiet -b load
    printf 'load %s\n' "$branch" >"$dir/load-$i-$(date +%s%N).txt"
    git -C "$dir" add -A && git "${cfg[@]}" -C "$dir" commit --quiet -m "load: $branch" >/dev/null 2>&1
    t2="$(now_us)"
    git "${cfg[@]}" -C "$dir" push --quiet origin "HEAD:refs/heads/$branch" >"$out/push-$i.err" 2>&1
    pe=$?
    t3="$(now_us)"
  else
    t2="$t1"
    t3="$t1"
  fi
  printf '%s\t%d\t%d\t%d\t%d\n' "$i" $(((t1 - t0) / 1000)) "$ce" $(((t3 - t2) / 1000)) "$pe" >>"$out/git.tsv"
  rm -rf "$dir"
}

# Every agent of <out>/agents.tsv ("<i> <branch>") at once; the git phase of one target.
_many() { # _many <out> <repo-url> [curloptResolve]
  local out="$1" url="$2" resolve="${3:-}" i branch pids=()
  while read -r i branch; do
    _worker "$i" "$url" "$out/cred-$i" "$out" "$branch" "$resolve" &
    pids+=($!)
  done <"$out/agents.tsv"
  # Only the workers: on the host the RAM sampler is a background job of the same shell.
  wait "${pids[@]}"
}

if [[ "${1:-}" == _many ]]; then
  shift
  _many "$@"
  exit
fi

# ---------------------------------------------------------------------------------------------
# On the host: scenario.sh's helpers (rpc, ensure_agent, helper_get, fj_api, fj_call, ...).
# shellcheck source=scenario.sh
. "$(dirname "$LOAD_SELF")/scenario.sh"

NET="${LOAD_NET:-kanboard-suite_default}"
STATS_CONTAINERS=(kb-git-traefik kb-git-forgejo kb-git-db kb-git-broker kb-suite kb-auth-server kb-auth-worker kb-auth-db)
LOAD_TITLE="load run"

pct() { # pct <p> : p-th percentile (nearest rank) of the numbers on stdin
  python3 -I -c '
import math,sys
p=float(sys.argv[1]); xs=sorted(float(l) for l in sys.stdin if l.strip())
print("%d" % xs[max(0,math.ceil(p/100*len(xs))-1)] if xs else "-")' "$1"
}

load_user() { echo "load-a$1"; }

# All load-a1..N in Forgejo team members?
load_ready() { # load_ready <N>
  local have i
  have=",$(team_logins members),"
  for i in $(seq 1 "$1"); do
    [[ "$have" == *",$(load_user "$i"),"* ]] || { echo no; return; }
  done
  echo yes
}

cmd_agents() { # cmd_agents <N>
  local n="$1" i t
  need_creds || return 1
  for i in $(seq 1 "$n"); do
    ensure_agent "$(load_user "$i")" 0 "$LOAD_TITLE" || { echo "agent $(load_user "$i") failed" >&2; return 1; }
  done
  broker_post /reconcile '' >/dev/null
  t="$(wait_yes 300 load_ready "$n")"
  echo "load-a1..load-a${n}: Kanboard agents in project ${PROJECT_ID}, in Forgejo team members (${t} s)"
  [[ "$t" =~ ^[0-9]+$ ]]
}

cmd_teardown() { # cmd_teardown <N>
  local n="$1" i id
  need_creds || return 1
  for i in $(seq 1 "$n"); do
    id="$(kb_user "$(load_user "$i")")"
    id="${id%% *}"
    [[ "$id" =~ ^[0-9]+$ ]] || continue
    rpc_admin removeProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$id}" >/dev/null
  done
  broker_post /reconcile '' >/dev/null
  echo "load-a1..load-a${n} removed from project ${PROJECT_ID}"
}

# docker stats of the stack, one line per container: "<name> <MiB>".
ram_sample() {
  docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' "${STATS_CONTAINERS[@]}" 2>/dev/null |
    python3 -I -c '
import sys
u={"B":1/2**20,"KiB":1/1024,"kB":1/1024,"MiB":1,"MB":1,"GiB":1024,"GB":1024}
for l in sys.stdin:
    name,use=l.split()[0],l.split()[1]
    num=use.rstrip("KMGiB"); unit=use[len(num):]
    print(name, "%.1f" % (float(num)*u.get(unit,1)))'
}

cmd_ram() {
  ram_sample | while read -r name mib; do echo "RAM|idle|${name}|${mib}"; done
}

# Background sampler: max MiB per container into <file> until <stopfile> appears.
ram_sampler() { # ram_sampler <file> <stopfile>
  declare -A max=()
  local name mib
  while [[ ! -e "$2" ]]; do
    while read -r name mib; do
      if [[ -z "${max[$name]:-}" ]] || python3 -I -c 'import sys; sys.exit(0 if float(sys.argv[1])>float(sys.argv[2]) else 1)' "$mib" "${max[$name]}"; then
        max[$name]="$mib"
      fi
    done < <(ram_sample)
  done
  for name in "${!max[@]}"; do echo "$name ${max[$name]}"; done >"$1"
}

# authz audit lines in [since, until]: latency_ms p50/p95, count, decisions.
fa_summary() { # fa_summary <label> <since> <until>
  docker cp "$BROKER_CONTAINER:/var/lib/broker/audit" - 2>/dev/null | tar -xO 2>/dev/null |
    python3 -I -c '
import json,math,sys,datetime
label,since,until=sys.argv[1],int(sys.argv[2]),int(sys.argv[3])
lat=[]; dec={}
for l in sys.stdin:
    try: e=json.loads(l)
    except ValueError: continue
    if e.get("verb")!="authz": continue
    ts=int(datetime.datetime.strptime(e["ts"],"%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc).timestamp())
    if since<=ts<=until:
        lat.append(e["latency_ms"]); k=e["decision"]+"/"+e["reason"]; dec[k]=dec.get(k,0)+1
def pct(xs,p):
    xs=sorted(xs); return xs[max(0,math.ceil(p/100*len(xs))-1)] if xs else -1
print("FA|%s|n=%d|p50=%d ms|p95=%d ms|max=%d ms|%s" % (label,len(lat),pct(lat,50),pct(lat,95),max(lat) if lat else -1,
      " ".join("%s=%d"%kv for kv in sorted(dec.items()))))' "$1" "$2" "$3"
}

# Credentials for every agent at once (N helper gets, each for its agent's own task) into
# <out>/cred-<i>; appends "<i> <ms> <0 ok|1 failed>" to <out>/cred.tsv.
cred_phase() { # cred_phase <N> <out>
  local n="$1" out="$2" i user pids=() t0
  t0="$(date +%s%N)"
  for i in $(seq 1 "$n"); do
    user="$(load_user "$i")"
    (
      s="$(date +%s%N)"
      (umask 077
       printf 'protocol=http\nhost=%s:%s\n\n' "$GIT_HOST" "$GIT_PORT" |
         env KB_ENDPOINT="$KANBOARD_API_ENDPOINT" KB_USER="$user" KB_TOKEN_FILE="$WORK/$user.apitoken" \
           KB_TASK_ID="${AGENT_TASKS[$user]}" KB_GIT_HOST="${GIT_HOST}:${GIT_PORT}" \
           "$HELPER" get >"$out/cred-$i" 2>"$out/cred-$i.err")
      e="$(date +%s%N)"
      printf '%s\t%d\t%d\n' "$i" $(((e - s) / 1000000)) \
        "$([[ -n "$(cred_field password "$out/cred-$i")" ]] && echo 0 || echo 1)" >>"$out/cred.tsv"
    ) &
    pids+=($!)
  done
  wait "${pids[@]}"
  echo "    credentials: ${n} in $((($(date +%s%N) - t0) / 1000000)) ms wall"
}

# The git phase of every agent at once on <target>.
git_phase() { # git_phase <target> <out>
  local target="$1" out="$2" t0 img base resolve traefik_ip
  t0="$(date +%s%N)"
  case "$target" in
    traefik)
      _many "$out" "${BASE}/${REPO}.git" "${GIT_HOST}:${GIT_PORT}:127.0.0.1"
      ;;
    traefik-net | direct)
      img="$(docker inspect -f '{{.Config.Image}}' "$FORGEJO_CONTAINER")"
      if [[ "$target" == direct ]]; then
        base="http://forgejo:3000"
        resolve=""
      else
        traefik_ip="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" kb-git-traefik)"
        base="${BASE}"
        resolve="${GIT_HOST}:${GIT_PORT}:${traefik_ip}"
      fi
      docker run --rm --network "$NET" --user "$(id -u):$(id -g)" -e HOME=/tmp \
        -v "$LOAD_SELF:/load.sh:ro" -v "$out:$out" --entrypoint bash "$img" \
        /load.sh _many "$out" "${base}/${REPO}.git" "$resolve"
      ;;
  esac
  echo "    git phase: $((($(date +%s%N) - t0) / 1000000)) ms wall (container start included for traefik-net/direct)"
}

# <rounds> rounds of N agents on <target>: each round mints N credentials, then runs N
# clone+commit+push at once. Percentiles pool every agent of every round.
run_target() { # run_target <N> <target> <out> <rounds>
  local n="$1" target="$2" out="$3" rounds="$4" r i since until stop sampler
  mkdir -p "$out"
  chmod 700 "$out"
  : >"$out/cred.tsv"
  : >"$out/git.tsv"
  stop="$out/ram.stop"
  rm -f "$stop"
  ram_sampler "$out/ram.max" "$stop" &
  sampler=$!
  since="$(date +%s)"
  for r in $(seq 1 "$rounds"); do
    echo "  round ${r}/${rounds}"
    : >"$out/agents.tsv"
    for i in $(seq 1 "$n"); do
      printf '%s\tagent/%s/load-%s-r%s-%s\n' "$i" "$(load_user "$i")" \
        "$(basename "$out" | sed 's/^kb-git-load-//')" "$r" "$i" >>"$out/agents.tsv"
    done
    cred_phase "$n" "$out"
    git_phase "$target" "$out"
    rm -f "$out"/cred-[0-9]*
  done
  until="$(date +%s)"
  touch "$stop"
  wait "$sampler"

  local total=$((n * rounds)) ok_c ok_p fail
  ok_c="$(awk -F'\t' '$3 == 0' "$out/git.tsv" | wc -l)"
  ok_p="$(awk -F'\t' '$5 == 0' "$out/git.tsv" | wc -l)"
  fail="$(awk -F'\t' '$3 != 0' "$out/cred.tsv" | wc -l)"
  echo "LOAD|${target}|N=${n}|rounds=${rounds}|cred p50=$(cut -f2 "$out/cred.tsv" | pct 50) p95=$(cut -f2 "$out/cred.tsv" | pct 95) ms (failed ${fail}/${total})|clone p50=$(awk -F'\t' '$3 == 0 {print $2}' "$out/git.tsv" | pct 50) p95=$(awk -F'\t' '$3 == 0 {print $2}' "$out/git.tsv" | pct 95) ms (ok ${ok_c}/${total})|push p50=$(awk -F'\t' '$5 == 0 {print $4}' "$out/git.tsv" | pct 50) p95=$(awk -F'\t' '$5 == 0 {print $4}' "$out/git.tsv" | pct 95) ms (ok ${ok_p}/${total})"
  if [[ "$target" != direct ]]; then
    fa_summary "${target}|N=${n}" "$since" "$until"
  fi
  sort "$out/ram.max" | while read -r name mib; do echo "RAM|${target}|N=${n}|${name}|${mib}"; done
  # Distinct git errors, if any (not Forgejo's "create a pull request" hint).
  cat "$out"/clone-*.err "$out"/push-*.err 2>/dev/null | grep -v '^remote:' | grep -v '^$' |
    sort | uniq -c | sort -rn | head -5 | sed 's/^/  err: /'
  return 0
}

cmd_run() { # cmd_run <N> <target>...   (LOAD_ROUNDS rounds each, default 1)
  local n="$1" target run
  shift
  need_creds || return 1
  for target in "$@"; do
    [[ "$target" =~ ^(traefik|traefik-net|direct)$ ]] || { echo "unknown target: $target" >&2; return 2; }
  done
  cmd_agents "$n" || return 1
  run="$(date +%Y%m%d%H%M%S)"
  for target in "$@"; do
    echo "run ${run}: ${n} agents, target ${target}"
    run_target "$n" "$target" "${LOAD_OUT:-${TMPDIR:-/tmp}}/kb-git-load-${run}-${target}-${n}" "${LOAD_ROUNDS:-1}"
  done
}

# Deletes the branches of REPO matching <regex> through the admin API. Forgejo refuses to delete
# a protected branch, even for the admin, so the rules matching them (agent/<login>/** of each
# login involved, and the catch-all) are lifted for a short window and then created again from a
# snapshot, agent rules first and the catch-all a second later (the broker's rule order). The
# broker is paused for the window (seconds; ForwardAuth denies meanwhile), or its sync would
# restore rules mid-window. Open PRs keep their head branch. Then the rule set is compared with
# the snapshot (every field but the timestamps).
cmd_prune() { # cmd_prune <regex>
  ensure_work
  local re="$1" page names logins rules lift out
  names="$WORK/prune.names"
  : >"$names"
  for page in $(seq 1 40); do
    out="$(fj_api "/api/v1/repos/${REPO}/branches?limit=50&page=${page}")"
    [[ "$(json_get 'len(d)' <<<"$out")" == 0 ]] && break
    python3 -I -c 'import json,re,sys
for b in json.load(sys.stdin):
    if re.search(sys.argv[1], b["name"]): print(b["name"])' "$re" <<<"$out" >>"$names"
  done
  fj_api "/api/v1/repos/${REPO}/pulls?state=open&limit=50" | json_get '"\n".join(p["head"]["ref"] for p in d)' >"$WORK/prune.open"
  grep -vxF -f "$WORK/prune.open" "$names" >"$WORK/prune.del" || true
  echo "prune: $(wc -l <"$names") branches of ${REPO} match /${re}/; $(($(wc -l <"$names") - $(wc -l <"$WORK/prune.del"))) kept (head of an open PR)"
  [[ -s "$WORK/prune.del" ]] || return 0
  rules="$WORK/prune.rules.json"
  fj_api "/api/v1/repos/${REPO}/branch_protections" >"$rules"
  logins="$(sed -n 's#^agent/\([^/]*\)/.*#\1#p' "$WORK/prune.del" | sort -u | tr '\n' ' ')"
  lift="$(python3 -I -c '
import json,sys
logins=set(sys.argv[2].split())
for r in sorted(json.load(open(sys.argv[1])), key=lambda r: r["created_at"]):
    n=r["rule_name"]
    if n in ("**","{**}") or (n.startswith("agent/") and n.endswith("/**") and n[6:-3] in logins): print(n)' "$rules" "$logins")"
  echo "prune: lifting $(wc -w <<<"$lift") rules: $(tr '\n' ' ' <<<"$lift")"
  # One script in the Forgejo container: token (first stdin line) into a private curl config,
  # then "<METHOD> <path> [json]" lines; prints "<status> <METHOD> <path>" per call.
  local calls="$WORK/prune.calls" r body
  { env_value FORGEJO_ADMIN_TOKEN "$HERE/.env"
    for r in $lift; do echo "DELETE /repos/${REPO}/branch_protections/$(urlencode "$r")"; done
    while read -r r; do echo "DELETE /repos/${REPO}/branches/$(urlencode "$r")"; done <"$WORK/prune.del"
    for r in $lift; do
      [[ "$r" == "**" || "$r" == "{**}" ]] && echo "SLEEP"
      body="$(python3 -I -c '
import json,sys
r=[x for x in json.load(open(sys.argv[1])) if x["rule_name"]==sys.argv[2]][0]
for k in ("created_at","updated_at","branch_name"): r.pop(k,None)
print(json.dumps(r,separators=(",",":")))' "$rules" "$r")"
      echo "POST /repos/${REPO}/branch_protections ${body}"
    done
  } >"$calls"
  chmod 600 "$calls"
  trap 'docker unpause "$BROKER_CONTAINER" >/dev/null 2>&1; restore_human; on_exit' EXIT
  docker pause "$BROKER_CONTAINER" >/dev/null
  local t0
  t0="$(date +%s)"
  docker exec -i "$FORGEJO_CONTAINER" sh -c '
umask 077; read -r tok; h=$(mktemp); printf "header = \"Authorization: token %s\"\n" "$tok" >"$h"
while read -r m p b; do
  if [ "$m" = SLEEP ]; then sleep 1.1; continue; fi
  if [ -n "$b" ]; then
    printf "%s" "$b" | curl -s -o /dev/null -w "%{http_code} $m $p\n" -K "$h" -X "$m" -H "Content-Type: application/json" --data-binary @- "http://localhost:3000/api/v1$p"
  else
    curl -s -o /dev/null -w "%{http_code} $m $p\n" -K "$h" -X "$m" "http://localhost:3000/api/v1$p"
  fi
done; rm -f "$h"' <"$calls" >"$WORK/prune.out"
  docker unpause "$BROKER_CONTAINER" >/dev/null
  trap 'restore_human; on_exit' EXIT
  rm -f "$calls"
  echo "prune: broker paused $(($(date +%s) - t0)) s"
  awk '{split($3,a,"/"); kind=($3 ~ /branch_protections/) ? "rule" : "branch"; print $2, kind, $1}' "$WORK/prune.out" | sort | uniq -c | sed 's/^/  /'
  fj_api "/api/v1/repos/${REPO}/branch_protections" | python3 -I -c '
import json,sys
def norm(rs):
    return sorted(json.dumps({k:v for k,v in r.items() if k not in ("created_at","updated_at")},sort_keys=True) for r in rs)
before=json.load(open(sys.argv[1])); after=json.load(sys.stdin)
order=[r["rule_name"] for r in sorted(after, key=lambda r: r["created_at"])]
print("prune: rules restored exactly (all fields but timestamps):", "yes" if norm(before)==norm(after) else "NO")
print("prune: catch-all newest:", "yes" if order[-1] in ("**","{**}") else "NO", "| %d rules" % len(order))' "$rules"
  ! grep -qv '^20[014] ' "$WORK/prune.out"
}

# Revocation lag. git: a token minted for a member, the member removed, then one fetch (ForwardAuth
# asks Kanboard on every request). Web UI: the human's Forgejo cookie session (Authentik login),
# not ForwardAuth-checked in P1 by spec; the human is removed from the project and the private
# repo page is polled every 0.5 s until it is no longer 200 (the broker's sync drops the team
# membership). Both memberships are restored, also from the EXIT trap.
REVLAG_HUMAN_ID=""
REVLAG_HUMAN_ROLE=""
restore_human() {
  [[ -z "$REVLAG_HUMAN_ROLE" ]] && return 0
  rpc_admin addProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$REVLAG_HUMAN_ID,\"role\":\"$REVLAG_HUMAN_ROLE\"}" >/dev/null
  REVLAG_HUMAN_ROLE=""
}
trap 'restore_human; on_exit' EXIT

page_code() { web "$1" -o /dev/null -w '%{http_code}' "${BASE}/${REPO}"; }

cmd_revlag() {
  need_creds || return 1
  ensure_core_task || return 1
  local clone="$WORK/clone-rev" out t0 t1 role
  # git
  git_helper "$CORE_TASK" clone --quiet "${BASE}/${REPO}.git" "$clone" >/dev/null 2>&1
  helper_get "$CORE_TASK" "$WORK/cred.rev" 2>/dev/null
  role="$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID}" | json_get 'd["result"]')"
  [[ "$role" == project-* ]] || { echo "agent not a member ($role)"; return 1; }
  REMOVED_ROLE="$role"
  out="$(run_git git_cached "$WORK/cred.rev" -C "$clone" fetch)"
  echo "  git: fetch before removal: $(last_exit "$out")"
  rpc_admin removeProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$AGENT_USER_ID}" >/dev/null
  t0="$(date +%s%N)"
  out="$(run_git git_cached "$WORK/cred.rev" -C "$clone" fetch)"
  t1="$(date +%s%N)"
  echo "REVLAG|git|first fetch after removeProjectUser returned: $(last_exit "$out") after $(((t1 - t0) / 1000000)) ms (denied: $(denied "$out"))"
  restore_membership

  # web UI
  local row jar="$WORK/rev.jar" pw="$WORK/rev.pw" hop cb code i lag="" api_code
  row="$(kb_user "$IDENTITY_USER")"
  REVLAG_HUMAN_ID="${row%% *}"
  local tok
  tok="$(env_value AUTHENTIK_BOOTSTRAP_TOKEN "$HERE/authentik.env")"
  (umask 077; printf 'Authorization: Bearer %s\n' "$tok" >"$WORK/ak.hdr")
  ak_user "$IDENTITY_USER" "$pw" >/dev/null || return 1
  rm -f "$jar"
  hop="$(web_hop "$jar" "${BASE}/user/oauth2/authentik")"
  cb="$(ak_authorize "$jar" "${hop#* }" "$IDENTITY_USER" "$pw")"
  web_hop "$jar" "$cb" >/dev/null
  echo "  web: Forgejo session $(fj_session_login "$jar"); ${BASE}/${REPO} -> $(page_code "$jar")"
  ensure_human >/dev/null 2>&1 || { echo "human approver setup failed"; return 1; }
  role="$(rpc_admin getProjectUserRole "{\"project_id\":$PROJECT_ID,\"user_id\":$REVLAG_HUMAN_ID}" | json_get 'd["result"]')"
  [[ "$role" == project-* ]] || { echo "human not a member ($role)"; return 1; }
  REVLAG_HUMAN_ROLE="$role"
  rpc_admin removeProjectUser "{\"project_id\":$PROJECT_ID,\"user_id\":$REVLAG_HUMAN_ID}" >/dev/null
  t0="$(date +%s%N)"
  api_code="$(status GET "/api/v1/repos/${REPO}" -H @"$WORK/hdr.human")"
  for i in $(seq 1 240); do
    code="$(page_code "$jar")"
    [[ "$code" != 200 ]] && { lag=$((($(date +%s%N) - t0) / 1000000)); break; }
    sleep 0.5
  done
  echo "REVLAG|web|human's API token through Traefik right after removal: ${api_code}"
  echo "REVLAG|web|cookie session: repo page ${code} after ${lag:-timeout(120 s)} ms; session still signed in as: $(fj_session_login "$jar")"
  restore_human
  t0="$(date +%s)"
  for i in $(seq 1 60); do [[ "$(page_code "$jar")" == 200 ]] && break; sleep 1; done
  echo "  web: repo page back to $(page_code "$jar") $(($(date +%s) - t0)) s after re-adding"
}

case "${1:-}" in
  run) shift; (($# >= 2)) || { echo "usage: load.sh run <N> <target>..." >&2; exit 2; }; cmd_run "$@" ;;
  agents) cmd_agents "${2:?N}" ;;
  teardown) cmd_teardown "${2:?N}" ;;
  prune) cmd_prune "${2:?regex}" ;;
  revlag) cmd_revlag ;;
  ram) cmd_ram ;;
  *) sed -n '2,28p' "$LOAD_SELF" >&2; exit 2 ;;
esac
