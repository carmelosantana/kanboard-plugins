#!/usr/bin/env bash
# Read-only import of a production Kanboard board into the local harness (fixture data for Chicane).
# Usage: bash testing/import-board.sh <prod_project_id> [window_days=30]
#
# Production is only READ, through ~/MCP/kanboard-mcp/bin/kb-lib.sh `req` (get*/getAll*/getProjectActivity).
# Local writes go to $LOCAL_EP (default http://localhost:8081/jsonrpc.php) as the `jsonrpc` user with
# API_TOKEN from testing/.env. The local project "<prod name> (import)" is removed and recreated each run.
set -uo pipefail

die(){ echo "import-board: $*" >&2; exit 1; }
[ $# -ge 1 ] && [[ "$1" =~ ^[0-9]+$ ]] || die "usage: bash testing/import-board.sh <prod_project_id> [window_days=30]"
P=$1; WIN=${2:-30}
[[ "$WIN" =~ ^[0-9]+$ ]] || die "window_days must be a number"

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[ -n "${API_TOKEN:-}" ] || API_TOKEN=$(grep '^API_TOKEN=' "$HERE/.env" 2>/dev/null | tail -1 | sed 's/^API_TOKEN=//')
[ -n "$API_TOKEN" ] || die "no API_TOKEN (env or testing/.env) — run testing/seed.sh first"
LEP=${LOCAL_EP:-http://localhost:8081/jsonrpc.php}
CHUNK=100

KB_LIB="$HOME/MCP/kanboard-mcp/bin/kb-lib.sh"
# kb-lib honours a pre-set KB_EP/KB_KEY; make sure production reads never inherit a local override.
unset KB_EP KB_KEY
# shellcheck source=/dev/null
. "$KB_LIB" || die "cannot load $KB_LIB"

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
export T KB_LIB

# ---------- production (read-only) ----------
preq(){ # method params -> .result (retries; dies on error)
  local out i
  case "$1" in get*) ;; *) die "refusing non-read production method $1";; esac
  for i in 1 2 3; do
    out=$(req "$1" "$2") && jq -e 'has("error")|not' <<<"$out" >/dev/null 2>&1 && { jq -c '.result' <<<"$out"; return 0; }
    sleep "$i"
  done
  echo "import-board: production $1 $2 failed: $out" >&2; return 1
}

# ---------- local (harness) ----------
lpost(){ # body on stdin -> response; token travels on a pipe, not argv
  curl -sS --max-time 300 -K <(printf 'user = "jsonrpc:%s"\n' "$API_TOKEN") \
    -H 'Content-Type: application/json' --data-binary @- "$LEP"
}
lreq(){ # method params -> .result; dies on error
  local out
  out=$(jq -c -n --arg m "$1" --argjson p "$2" '{jsonrpc:"2.0",id:1,method:$m,params:$p}' | lpost) || die "local $1: curl failed"
  jq -e 'has("error")|not' <<<"$out" >/dev/null 2>&1 || die "local $1: $(jq -c '.error' <<<"$out" 2>/dev/null || echo "$out")"
  jq -c '.result' <<<"$out"
}
lbatch(){ # file of JSON lines {method,params} [allow_false] -> one result per line, in order; dies on error (or false)
  local f=$1 okf=${2:-0} chunk out
  [ -s "$f" ] || return 0
  jq -s -c --argjson n "$CHUNK" '. as $a | range(0; length; $n) | $a[.:.+$n]' "$f" | while IFS= read -r chunk; do
    out=$(jq -c 'to_entries|map({jsonrpc:"2.0",id:(.key+1),method:.value.method,params:.value.params})' <<<"$chunk" | lpost) \
      || { echo "import-board: local batch: curl failed" >&2; exit 1; }
    if ! jq -e 'type=="array" and all(.[]; (has("error")|not) and .result != null and ($okf == 1 or .result != false))' --argjson okf "$okf" <<<"$out" >/dev/null 2>&1; then
      echo "import-board: local batch failed: $(jq -c --argjson c "$chunk" '[.[]?|select(has("error") or .result==false or .result==null)|{error,result,call:$c[.id-1]}][0]' <<<"$out" 2>/dev/null || echo "$out")" >&2
      exit 1
    fi
    jq -c 'sort_by(.id)|.[].result' <<<"$out"
  done
  [ "${PIPESTATUS[1]}" = 0 ] || die "local batch aborted"
}

lreq getVersion '{}' >/dev/null

# ---------- read production ----------
now=$(date +%s); since=$((now - WIN*86400))
pname=$(preq getProjectById "{\"project_id\":$P}" | jq -r '.name // empty') || die "cannot read production project $P"
[ -n "$pname" ] || die "production project $P not found"
preq getActiveSwimlanes "{\"project_id\":$P}" | jq -c 'sort_by(.position|tonumber)|map({id:(.id|tostring),name,description:(.description // "")})' > "$T/lanes" || die "swimlanes"
preq getColumns "{\"project_id\":$P}" | jq -c 'sort_by(.position|tonumber)|map({id:(.id|tostring),title,task_limit:(.task_limit|tonumber? // 0),description:(.description // "")})' > "$T/cols" || die "columns"
preq getProjectUsers "{\"project_id\":$P}" > "$T/pusers" || die "project users"
preq getAllTasks "{\"project_id\":$P,\"status_id\":1}" > "$T/open" || die "open tasks"
preq getAllTasks "{\"project_id\":$P,\"status_id\":0}" | jq -c --argjson s "$since" '[.[]|select((.date_completed|tonumber? // 0) >= $s)]' > "$T/closed" || die "closed tasks"
preq getProjectActivity "{\"project_id\":$P}" | jq -c '[.[]|select(.event_name=="task.open")|(.task_id|tostring)]|unique' > "$T/reopened" || die "activity"
jq -s -c 'add|map({id:(.id|tostring), title, description:(.description // ""), color_id:(.color_id // ""),
    open:((.is_active|tostring)=="1"), column_id:(.column_id|tostring), swimlane_id:(.swimlane_id|tostring),
    owner_id:(.owner_id|tostring), creator_id:(.creator_id|tostring), position:(.position|tonumber? // 0),
    score:(.score|tonumber? // 0), time_estimated:(.time_estimated|tonumber? // 0),
    dates:{date_creation, date_modification, date_moved, date_completed}})' "$T/open" "$T/closed" > "$T/tasks"

one(){ # per-task detail read (production, read-only)
  # shellcheck source=/dev/null
  . "$KB_LIB" || exit 1
  local id=$1 subs coms tags links out i
  r(){ for i in 1 2 3; do out=$(req "$1" "$2") && jq -e 'has("error")|not' <<<"$out" >/dev/null 2>&1 && { jq -c '.result' <<<"$out"; return 0; }; sleep "$i"; done; return 1; }
  subs=$(r getAllSubtasks "{\"task_id\":$id}") || { echo "import-board: subtasks of $id failed" >&2; exit 1; }
  coms=$(r getAllComments "{\"task_id\":$id}") || { echo "import-board: comments of $id failed" >&2; exit 1; }
  tags=$(r getTaskTags "{\"task_id\":$id}") || { echo "import-board: tags of $id failed" >&2; exit 1; }
  links=$(r getAllTaskLinks "{\"task_id\":$id}") || { echo "import-board: links of $id failed" >&2; exit 1; }
  jq -c -n --arg id "$id" --argjson s "$subs" --argjson c "$coms" --argjson g "$tags" --argjson l "$links" '{id:$id,
    subtasks:([$s[]?|{title, status:(.status|tonumber? // 0), user_id:(.user_id|tostring), username:(.username // null), name:(.name // null),
      position:(.position|tonumber? // 0), time_estimated:(.time_estimated|tonumber? // 0), time_spent:(.time_spent|tonumber? // 0)}]|sort_by(.position)),
    comments:([$c[]?|{id:(.id|tonumber), user_id:(.user_id|tostring), username:(.username // null), name:(.name // null), content:(.comment // ""),
      date_creation, date_modification}]|sort_by(.id)),
    tags:[($g|values[]?)],
    blocked_by:[$l[]?|select(.label=="is blocked by")|(.task_id|tostring)]}' > "$T/d.$id.tmp" && mv "$T/d.$id.tmp" "$T/d.$id"
}
export -f one
jq -r '.[].id' "$T/tasks" | xargs -P 6 -I{} bash -c 'one "$@"' _ {}
n_tasks=$(jq 'length' "$T/tasks")
n_read=$(find "$T" -maxdepth 1 -name 'd.*' ! -name '*.tmp' | wc -l)
[ "$n_read" = "$n_tasks" ] || die "read details for $n_read of $n_tasks tasks"
jq -s -c 'INDEX(.id)' "$T"/d.* > "$T/details"

# ---------- users ----------
# The agent profile cannot call getUser, so usernames come from comment/subtask rows; a user seen
# nowhere else falls back to a slug of the display name.
jq -c -n --slurpfile pu "$T/pusers" --slurpfile t "$T/tasks" --slurpfile d "$T/details" '
  ([$d[0][]|(.comments[],.subtasks[])|select(.username!=null)|{key:.user_id,value:.username}]|from_entries) as $un
  | ([$d[0][]|(.comments[],.subtasks[])|select(.name!=null)|{key:.user_id,value:.name}]|from_entries) as $nm
  | ([$pu[0]|to_entries[]|{key:(.key|tostring),value:.value}]|from_entries) as $pn
  | ([($pn|keys[]), ($un|keys[]), ($t[0][]|.owner_id,.creator_id)]|unique|map(select(.!="0" and .!="")))
  | map(. as $id | ($pn[$id] // $nm[$id] // ("user " + $id)) as $name
        | {id:$id, name:$name, username:("imp." + ($un[$id] // ($name|ascii_downcase|gsub("[^a-z0-9]+";"-")|ltrimstr("-")|rtrimstr("-")))) })' > "$T/users"

: > "$T/umap"
while IFS= read -r u; do
  uname=$(jq -r '.username' <<<"$u"); name=$(jq -r '.name' <<<"$u"); pid=$(jq -r '.id' <<<"$u")
  existing=$(lreq getUserByName "$(jq -c -n --arg u "$uname" '{username:$u}')")
  if [ "$existing" != "false" ] && [ "$existing" != "null" ]; then
    lid=$(jq -r '.id' <<<"$existing")
    lreq updateUser "$(jq -c -n --argjson i "$lid" --arg n "$name" '{id:$i,name:$n}')" >/dev/null
  else
    pw=$(head -c 24 /dev/urandom | base64 | tr -d '/+=')
    lid=$(lreq createUser "$(jq -c -n --arg u "$uname" --arg p "$pw" --arg n "$name" '{username:$u,password:$p,name:$n}')")
    unset pw
    [[ "$lid" =~ ^[0-9]+$ ]] || die "createUser $uname returned $lid"
  fi
  printf '{"%s":%s}\n' "$pid" "$lid" >> "$T/umap"
done < <(jq -c '.[]' "$T/users")
jq -s -c 'add // {}' "$T/umap" > "$T/umap.json"

# ---------- local project (replace previous import) ----------
lname="$pname (import)"
while :; do
  old=$(lreq getProjectByName "$(jq -c -n --arg n "$lname" '{name:$n}')")
  [ "$old" = "false" ] || [ "$old" = "null" ] && break
  [ "$(jq -r '.name' <<<"$old")" = "$lname" ] || die "getProjectByName returned a different project"
  lreq removeProject "{\"project_id\":$(jq -r '.id' <<<"$old")}" >/dev/null
done
L=$(lreq createProject "$(jq -c -n --arg n "$lname" '{name:$n,owner_id:1}')")
[[ "$L" =~ ^[0-9]+$ ]] || die "createProject returned $L"
jq -r '.[]' "$T/umap.json" | while read -r lid; do
  lreq addProjectUser "{\"project_id\":$L,\"user_id\":$lid,\"role\":\"project-member\"}" >/dev/null
done

# columns: match production by position (rename, add, remove surplus)
lreq getColumns "{\"project_id\":$L}" | jq -c 'sort_by(.position|tonumber)|map(.id|tonumber)' > "$T/lcols"
: > "$T/cmap"
n_pc=$(jq length "$T/cols"); n_lc=$(jq length "$T/lcols")
for ((i=0; i<n_pc; i++)); do
  c=$(jq -c ".[$i]" "$T/cols")
  if [ "$i" -lt "$n_lc" ]; then
    lid=$(jq -r ".[$i]" "$T/lcols")
    lreq updateColumn "$(jq -c --argjson i "$lid" '{column_id:$i,title,task_limit,description}' <<<"$c")" >/dev/null
  else
    lid=$(lreq addColumn "$(jq -c --argjson p "$L" '{project_id:$p,title,task_limit,description}' <<<"$c")")
  fi
  jq -c --argjson l "$lid" '{(.id):$l}' <<<"$c" >> "$T/cmap"
done
for ((i=n_pc; i<n_lc; i++)); do lreq removeColumn "{\"column_id\":$(jq -r ".[$i]" "$T/lcols")}" >/dev/null; done
jq -s -c 'add // {}' "$T/cmap" > "$T/cmap.json"

# swimlanes: rename the local default to the first production lane, add the rest in order
first_lane=$(lreq getAllSwimlanes "{\"project_id\":$L}" | jq -r 'sort_by(.position|tonumber)|.[0].id')
: > "$T/smap"
n_pl=$(jq length "$T/lanes")
for ((i=0; i<n_pl; i++)); do
  s=$(jq -c ".[$i]" "$T/lanes")
  if [ "$i" = 0 ]; then
    lreq updateSwimlane "$(jq -c --argjson p "$L" --argjson i "$first_lane" '{project_id:$p,swimlane_id:$i,name,description}' <<<"$s")" >/dev/null
    lid=$first_lane
  else
    lid=$(lreq addSwimlane "$(jq -c --argjson p "$L" '{project_id:$p,name,description}' <<<"$s")")
  fi
  jq -c --argjson l "$lid" '{(.id):$l}' <<<"$s" >> "$T/smap"
done
jq -s -c 'add // {}' "$T/smap" > "$T/smap.json"

# ---------- tasks: create, then place in the same column/swimlane/order ----------
# Production column/lane ordinals sort tasks; a task in an inactive lane falls back to the first lane.
jq -c --slurpfile cm "$T/cmap.json" --slurpfile sm "$T/smap.json" --slurpfile um "$T/umap.json" \
   --slurpfile cols "$T/cols" --slurpfile lanes "$T/lanes" --argjson fl "$first_lane" '
  ($cols[0]|to_entries|map({key:.value.id,value:.key})|from_entries) as $co
  | ($lanes[0]|to_entries|map({key:.value.id,value:.key})|from_entries) as $lo
  | map(. + {lcol:$cm[0][.column_id], llane:($sm[0][.swimlane_id] // $fl),
             cord:($co[.column_id] // 999), lord:($lo[.swimlane_id] // -1),
             lowner:($um[0][.owner_id] // 0), lcreator:($um[0][.creator_id] // 0)})
  | sort_by(.cord, .lord, .position, (.id|tonumber))' "$T/tasks" > "$T/plan"
jq -e 'all(.[]; .lcol != null)' "$T/plan" >/dev/null || die "a task is in a column that was not imported"

jq -c --argjson p "$L" '.[]|{method:"createTask",params:{title, project_id:$p, color_id, column_id:.lcol, swimlane_id:.llane,
    owner_id:.lowner, creator_id:.lcreator, description, score, time_estimated}}' "$T/plan" > "$T/calls.create"
lbatch "$T/calls.create" > "$T/created" || die "createTask batch"
[ "$(wc -l < "$T/created")" = "$n_tasks" ] || die "created $(wc -l < "$T/created") of $n_tasks tasks"
jq -s -c --slurpfile pl "$T/plan" '[range(0;length) as $i | {key:$pl[0][$i].id, value:.[$i]}]|from_entries' "$T/created" > "$T/tmap.json"

jq -c --argjson p "$L" --slurpfile tm "$T/tmap.json" '
  group_by([.lcol,.llane]) | .[] | to_entries[]
  | {method:"moveTaskPosition",params:{project_id:$p, task_id:$tm[0][.value.id], column_id:.value.lcol, position:(.key+1), swimlane_id:.value.llane}}' \
  "$T/plan" > "$T/calls.move"
# moveTaskPosition returns false when the task already sits at that spot (created in order), so false is allowed here.
lbatch "$T/calls.move" 1 > /dev/null || die "moveTaskPosition batch"

# ---------- subtasks, comments, tags ----------
jq -c --slurpfile tm "$T/tmap.json" --slurpfile um "$T/umap.json" '.[]|. as $d|$tm[0][$d.id] as $t
  | $d.subtasks[] | {method:"createSubtask",params:{task_id:$t, title, user_id:($um[0][.user_id] // 0), time_estimated, time_spent, status}}' \
  "$T/details" > "$T/calls.sub"
jq -c --slurpfile tm "$T/tmap.json" --slurpfile um "$T/umap.json" '.[]|. as $d|$tm[0][$d.id] as $t
  | $d.comments[] | {method:"createComment",params:{task_id:$t, user_id:($um[0][.user_id] // 1), content:(if .content=="" then "(empty)" else .content end)}}' \
  "$T/details" > "$T/calls.com"
jq -c --argjson p "$L" --slurpfile tm "$T/tmap.json" '.[]|select(.tags|length>0)
  | {method:"setTaskTags",params:{project_id:$p, task_id:$tm[0][.id], tags}}' "$T/details" > "$T/calls.tags"
lbatch "$T/calls.sub"  > /dev/null || die "createSubtask batch"
lbatch "$T/calls.com"  > "$T/comments.created" || die "createComment batch"
lbatch "$T/calls.tags" > /dev/null || die "setTaskTags batch"

# ---------- "is blocked by" links between imported tasks ----------
blk=$(lreq getAllLinks '{}' | jq -r '.[]|select(.label=="is blocked by")|.id')
[[ "$blk" =~ ^[0-9]+$ ]] || die "no local 'is blocked by' link type"
jq -c --argjson k "$blk" --slurpfile tm "$T/tmap.json" '[.[]|.id as $a|.blocked_by[]|select($tm[0][.] != null)|[$a,.]]|unique|.[]
  | {method:"createTaskLink",params:{task_id:$tm[0][.[0]], opposite_task_id:$tm[0][.[1]], link_id:$k}}' "$T/details" > "$T/calls.links"
lbatch "$T/calls.links" > /dev/null || die "createTaskLink batch"

# ---------- reopened (close + open), then close closed tasks ----------
jq -c --slurpfile tm "$T/tmap.json" '.[]|select($tm[0][.] != null)|$tm[0][.] as $t
  | {method:"closeTask",params:{task_id:$t}}, {method:"openTask",params:{task_id:$t}}' "$T/reopened" > "$T/calls.reopen"
lbatch "$T/calls.reopen" > /dev/null || die "reopen batch"
jq -c --slurpfile tm "$T/tmap.json" '.[]|select(.open|not)|{method:"closeTask",params:{task_id:$tm[0][.id]}}' "$T/plan" > "$T/calls.close"
lbatch "$T/calls.close" > /dev/null || die "closeTask batch"

# ---------- restore production timestamps (JSON-RPC cannot set them) ----------
# One PDO transaction in the container (same mechanism as seed.sh's db_write). Only ids and dates
# reach SQL, as bound integer parameters; jq refuses anything that is not a non-negative integer.
jq -c -n --slurpfile plan "$T/plan" --slurpfile tm "$T/tmap.json" --slurpfile d "$T/details" --slurpfile cl "$T/comments.created" '
  def n: if . == null or . == "" then null elif (tostring|test("^[0-9]+$")) then tonumber else error("non-numeric value: \(.)") end;
  { tasks: [ $plan[0][] | [($tm[0][.id]|n), (.dates.date_creation|n), (.dates.date_modification|n), (.dates.date_moved|n), (.dates.date_completed|n)] ],
    comments: ( [ $d[0][] | .comments[] | [(.date_creation|n), (.date_modification|n)] ] as $pd
                | [ range(0; $cl|length) as $i | [($cl[$i]|n)] + $pd[$i] ] ) }' > "$T/dates.json" || die "dates payload"
[ "$(jq '.comments|length' "$T/dates.json")" = "$(wc -l < "$T/calls.com")" ] || die "comment id/date count mismatch"
restored=$(docker exec -i "${KB_CONTAINER:-kb-suite}" php -r '
$d = json_decode(stream_get_contents(STDIN), true);
$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$i = function ($v) { if ($v === null) return null; if (!is_int($v) || $v < 0) throw new Exception("bad value"); return $v; };
$db->beginTransaction();
$t = $db->prepare("UPDATE tasks SET date_creation=?, date_modification=?, date_moved=?, date_completed=? WHERE id=?");
$c = $db->prepare("UPDATE comments SET date_creation=?, date_modification=? WHERE id=?");
$nt = 0; $nc = 0;
foreach ($d["tasks"] as $r) { $t->execute([$i($r[1]) ?? 0, $i($r[2]) ?? 0, $i($r[3]) ?? 0, $i($r[4]), $i($r[0])]); $nt += $t->rowCount(); }
foreach ($d["comments"] as $r) { $c->execute([$i($r[1]) ?? 0, $i($r[2]) ?? 0, $i($r[0])]); $nc += $c->rowCount(); }
$db->commit();
echo "$nt $nc";
' < "$T/dates.json") || die "timestamp restore failed"
[ "$restored" = "$n_tasks $(wc -l < "$T/calls.com")" ] || die "timestamp restore updated $restored rows"

echo "imported project_id=$L tasks=$n_tasks subtasks=$(wc -l < "$T/calls.sub") comments=$(wc -l < "$T/calls.com") blocked=$(wc -l < "$T/calls.links")"
