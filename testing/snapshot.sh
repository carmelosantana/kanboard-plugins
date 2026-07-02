#!/usr/bin/env bash
# snapshot.sh — Print per-table DB counts + on-disk file listing
# Usage: ./testing/snapshot.sh <project_id> [project_id ...]
# Example: ./testing/snapshot.sh 2 3 4 5 6
# Run from repo root.

set -euo pipefail

COMPOSE_FILE="testing/docker-compose.dev.yml"
DB_PATH="/var/www/app/data/db.sqlite"

if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <project_id> [project_id ...]"
  exit 1
fi

# Build comma-separated list for SQL IN clause
PROJECT_IDS_CSV=$(IFS=,; echo "$*")

# Run a SQL query via PHP PDO (returns newline-separated rows)
db_query() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
  if (\$stmt) { foreach (\$stmt as \$row) { echo implode('|', array_slice(\$row,0,count(\$row)/2)).PHP_EOL; } }
} catch (Exception \$e) { echo 'ERR: '.\$e->getMessage().PHP_EOL; }
"
}

db_scalar() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
  \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_NUM) : null;
  echo \$row ? \$row[0] : '0';
} catch (Exception \$e) { echo '0'; }
"
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Kanboard suite snapshot — project IDs: ${PROJECT_IDS_CSV}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo ""
echo "── DB row counts ──────────────────────────────────────"
printf "%-30s %s\n" "Table" "Count"
printf "%-30s %s\n" "-----" "-----"

count_and_print() {
  local label="$1" sql="$2"
  local val
  val=$(db_scalar "$sql")
  printf "%-30s %s\n" "$label" "$val"
}

count_and_print "projects (seed)"          "SELECT COUNT(*) FROM projects WHERE id IN (${PROJECT_IDS_CSV})"
count_and_print "columns (seed)"           "SELECT COUNT(*) FROM columns WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "swimlanes (seed)"         "SELECT COUNT(*) FROM swimlanes WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "tasks (seed)"             "SELECT COUNT(*) FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "subtasks (seed)"          "SELECT COUNT(*) FROM subtasks WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))"
count_and_print "comments (seed)"          "SELECT COUNT(*) FROM comments WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))"
count_and_print "task_has_files (seed)"    "SELECT COUNT(*) FROM task_has_files WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))"
count_and_print "project_has_files (seed)" "SELECT COUNT(*) FROM project_has_files WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "tags (seed)"              "SELECT COUNT(*) FROM tags WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "project_has_categories"   "SELECT COUNT(*) FROM project_has_categories WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "actions (seed)"           "SELECT COUNT(*) FROM actions WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "action_has_params (seed)" "SELECT COUNT(*) FROM action_has_params WHERE action_id IN (SELECT id FROM actions WHERE project_id IN (${PROJECT_IDS_CSV}))"
count_and_print "custom_filters (seed)"    "SELECT COUNT(*) FROM custom_filters WHERE project_id IN (${PROJECT_IDS_CSV})"
count_and_print "invites (seed)"           "SELECT COUNT(*) FROM invites WHERE project_id IN (${PROJECT_IDS_CSV})"

echo ""
echo "── Unique file paths recorded in DB ─────────────────────"
db_query "
SELECT 'task_file' AS type, name, path FROM task_has_files
  WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))
UNION ALL
SELECT 'project_file' AS type, name, path FROM project_has_files
  WHERE project_id IN (${PROJECT_IDS_CSV})
ORDER BY type, name
"

echo ""
echo "── On-disk files: /var/www/app/data/files/ ─────────────"
docker compose -f "$COMPOSE_FILE" exec -T kanboard \
  sh -c 'ls -lRh /var/www/app/data/files/ 2>/dev/null || echo "(empty or no files dir)"'

echo ""
echo "── Plugin directories in container ─────────────────────"
docker compose -f "$COMPOSE_FILE" exec -T kanboard \
  ls /var/www/app/plugins/

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Snapshot complete."
