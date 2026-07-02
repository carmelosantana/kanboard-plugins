#!/usr/bin/env bash
# seed.sh — Seed Kanboard with realistic test data via JSON-RPC
# Usage: ./testing/seed.sh
# Run from repo root: ./testing/seed.sh
# Re-run: idempotent — deletes seed projects by prefix then recreates them.
#
# API_TOKEN is loaded from testing/.env (gitignored) or from environment.
# If testing/.env does not exist, the script writes the token into the DB and
# creates the file for future runs.

set -euo pipefail

COMPOSE_FILE="testing/docker-compose.dev.yml"
BASE_URL="http://localhost:8081"
JSONRPC_URL="${BASE_URL}/jsonrpc.php"
SEED_PREFIX="[SEED]"           # projects created by this script carry this prefix
ENV_FILE="testing/.env"
DB_PATH="/var/www/app/data/db.sqlite"

# ── PHP helper to run SQLite queries in container ────────────────────────────
db_exec() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
if (\$stmt) { foreach (\$stmt as \$row) { echo implode('|', \$row).PHP_EOL; } }
"
}

db_exec_scalar() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
\$row = \$stmt ? \$stmt->fetch(PDO::FETCH_NUM) : null;
echo \$row ? \$row[0] : '';
"
}

db_write() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$db->exec($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
echo 'ok';
"
}

# ── Wait for Kanboard to initialise (DB must exist) ─────────────────────────
echo "Waiting for Kanboard on ${BASE_URL}..."
for i in $(seq 1 30); do
  if curl -sf -o /dev/null "${BASE_URL}"; then
    echo "Kanboard is up."
    break
  fi
  echo "  attempt $i/30 — sleeping 3s..."
  sleep 3
done

# Give the DB a moment to be created after the first HTTP response
sleep 2

# ── Load or bootstrap API token ─────────────────────────────────────────────
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

if [[ -z "${API_TOKEN:-}" ]]; then
  echo "No API_TOKEN found — bootstrapping a fixed token into the DB..."
  API_TOKEN="kanboard_suite_dev_$(openssl rand -hex 8)"
  db_write "INSERT OR REPLACE INTO settings (option, value) VALUES ('api_token', '${API_TOKEN}');" > /dev/null
  mkdir -p testing
  echo "API_TOKEN=${API_TOKEN}" > "$ENV_FILE"
  echo "Token saved to $ENV_FILE"
else
  # Ensure the token is written (in case DB was reset)
  db_write "INSERT OR REPLACE INTO settings (option, value) VALUES ('api_token', '${API_TOKEN}');" > /dev/null
fi

echo "Using API_TOKEN=${API_TOKEN:0:12}…"

# ── JSON-RPC helper ──────────────────────────────────────────────────────────
rpc() {
  local method="$1"
  local params="${2-}"
  [[ -z "$params" ]] && params="{}"
  curl -sf -u "jsonrpc:${API_TOKEN}" "${JSONRPC_URL}" \
    -H "Content-Type: application/json" \
    -d "{\"jsonrpc\":\"2.0\",\"method\":\"${method}\",\"id\":1,\"params\":${params}}" \
    | python3 -c "import sys,json; d=json.load(sys.stdin); r=d.get('result',''); print(r if isinstance(r,(str,int)) else json.dumps(r))"
}

# ── Remove previous seed data ────────────────────────────────────────────────
echo "Removing any previous seed projects..."
ALL_PROJECTS=$(rpc "getAllProjects")
EXISTING_IDS=$(echo "$ALL_PROJECTS" | python3 -c "
import sys, json
projects = json.load(sys.stdin)
if not projects:
    sys.exit(0)
items = projects.values() if isinstance(projects, dict) else projects
for p in items:
    if isinstance(p, dict) and p.get('name','').startswith('[SEED]'):
        print(p['id'])
" 2>/dev/null || true)

for pid in $EXISTING_IDS; do
  echo "  Removing project $pid..."
  rpc "removeProject" "{\"project_id\":${pid}}" > /dev/null
done

# ── Shared file payload (base64-encoded text file, no image processing) ───────
# Using a plain-text blob avoids Kanboard's GD thumbnail path (which needs a
# real PNG/JPEG with valid headers). The same content is uploaded under two
# different task_ids to exercise the dedup / shared-path file case.
SHARED_FILE_B64="VGhpcyBpcyBhIHNlZWQgdGVzdCBmaWxlIGZvciBLYW5ib2FyZCBwbHVnaW4gc3VpdGUgdGVzdGluZy4="
SHARED_FILENAME="shared-spec.txt"

# ── Create seed projects ─────────────────────────────────────────────────────
PROJECT_IDS=()

for i in 1 2 3 4 5; do
  PNAME="${SEED_PREFIX} Project $i"
  echo "Creating project: $PNAME"
  PID=$(rpc "createProject" "{\"name\":\"${PNAME}\"}")
  PROJECT_IDS+=("$PID")
  echo "  → project_id=$PID"

  # Add a category
  rpc "createCategory" "{\"project_id\":${PID},\"name\":\"Category A\"}" > /dev/null

  # Get default column ids (first column = "Backlog" or "To Do")
  COLS=$(rpc "getColumns" "{\"project_id\":${PID}}")
  TODO_COL=$(echo "$COLS" | python3 -c "
import sys,json
cols=json.load(sys.stdin)
items=cols.values() if isinstance(cols,dict) else cols
print(list(items)[0]['id'])
")

  # Create 3 tasks per project
  for t in 1 2 3; do
    TNAME="${SEED_PREFIX} Task $t (proj $i)"
    TID=$(rpc "createTask" "{\"project_id\":${PID},\"title\":\"${TNAME}\",\"column_id\":${TODO_COL}}")
    echo "    task_id=$TID"

    # 2 subtasks per task
    for s in 1 2; do
      rpc "createSubtask" "{\"task_id\":${TID},\"title\":\"Subtask $s of task $t proj $i\"}" > /dev/null
    done

    # 2 comments per task
    for c in 1 2; do
      rpc "createComment" "{\"task_id\":${TID},\"user_id\":1,\"content\":\"Comment $c on task $t proj $i\"}" > /dev/null
    done

    # Per-task file (unique per task)
    rpc "createTaskFile" "{\"project_id\":${PID},\"task_id\":${TID},\"filename\":\"task-${t}-proj-${i}.txt\",\"blob\":\"${SHARED_FILE_B64}\"}" > /dev/null

    # Shared-path case: same filename uploaded to task 1 and task 2 (exercises dedup or collision)
    if [[ "$t" -le 2 ]]; then
      rpc "createTaskFile" "{\"project_id\":${PID},\"task_id\":${TID},\"filename\":\"${SHARED_FILENAME}\",\"blob\":\"${SHARED_FILE_B64}\"}" > /dev/null
    fi
  done

  # Project-level file
  rpc "createProjectFile" "{\"project_id\":${PID},\"filename\":\"project-${i}-brief.txt\",\"blob\":\"${SHARED_FILE_B64}\"}" > /dev/null
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Seed complete. Created project IDs:"
echo "${PROJECT_IDS[*]}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Run snapshot:  ./testing/snapshot.sh ${PROJECT_IDS[*]}"
