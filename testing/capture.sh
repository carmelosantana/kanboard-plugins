#!/usr/bin/env bash
# capture.sh — Capture pre-delete IDs and file paths for verify.sh
#
# Run BEFORE the bulk delete to snapshot which rows exist.  Outputs shell
# variable assignments (suitable for `eval` or `source`) that verify.sh needs:
#
#   SEEDED_TASK_IDS        — comma-separated task IDs (e.g. "7,8,9")
#   SEEDED_ACTION_IDS      — comma-separated action IDs (e.g. "12,13")
#   SEEDED_SUBTASK_IDS     — comma-separated subtask IDs (for subtask_time_tracking check)
#   SEEDED_FILE_PATHS      — colon-separated on-disk relative paths for task/project files
#   SURVIVING_FILE_PATH    — first file from a non-deleted project (if any; may be empty)
#
# Usage (eval form — captures and exports immediately):
#   eval "$(./testing/capture.sh <project_id> [project_id ...])"
#   # …invoke bulk delete via JSON-RPC or UI…
#   ./testing/verify.sh <project_id> [project_id ...]
#
# Or write to a file then source it:
#   ./testing/capture.sh 2 3 4 5 > /tmp/pre_delete.env
#   source /tmp/pre_delete.env
#   ./testing/verify.sh 2 3 4 5
#
# Run from the repo root.

set -euo pipefail

COMPOSE_FILE="testing/docker-compose.dev.yml"
DB_PATH="/var/www/app/data/db.sqlite"

if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <project_id> [project_id ...]" >&2
  exit 1
fi

PROJECT_IDS_CSV=$(IFS=,; echo "$*")

# ── PHP/PDO scalar helper ────────────────────────────────────────────────────
db_scalar() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
  \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_NUM) : null;
  echo \$row ? (string)\$row[0] : '';
} catch (Exception \$e) { echo ''; }
"
}

# ── Capture task IDs ─────────────────────────────────────────────────────────
TASK_IDS_RAW=$(db_scalar "SELECT GROUP_CONCAT(id) FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV})")
TASK_IDS="${TASK_IDS_RAW:-}"

# ── Capture action IDs ───────────────────────────────────────────────────────
ACTION_IDS_RAW=$(db_scalar "SELECT GROUP_CONCAT(id) FROM actions WHERE project_id IN (${PROJECT_IDS_CSV})")
ACTION_IDS="${ACTION_IDS_RAW:-}"

# ── Capture subtask IDs (for subtask_time_tracking check) ───────────────────
# Must be captured BEFORE delete; subtasks are gone post-delete via cascade.
if [[ -n "$TASK_IDS" ]]; then
  SUBTASK_IDS_RAW=$(db_scalar "SELECT GROUP_CONCAT(id) FROM subtasks WHERE task_id IN (${TASK_IDS})")
else
  SUBTASK_IDS_RAW=""
fi
SUBTASK_IDS="${SUBTASK_IDS_RAW:-}"

# ── Capture file paths (task_has_files + project_has_files) ─────────────────
FILE_PATHS_RAW=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
  php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query(\"
    SELECT path FROM task_has_files
      WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))
    UNION ALL
    SELECT path FROM project_has_files
      WHERE project_id IN (${PROJECT_IDS_CSV})
  \");
  \$paths = [];
  while (\$row = \$stmt->fetch(PDO::FETCH_NUM)) { if (\$row[0]) \$paths[] = \$row[0]; }
  echo implode(':', \$paths);
} catch (Exception \$e) { echo ''; }
" 2>/dev/null || echo "")

# ── Capture a surviving file path (from a non-deleted project) ───────────────
SURVIVING_PATH_RAW=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
  php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query(\"
    SELECT path FROM task_has_files
      WHERE task_id NOT IN (SELECT id FROM tasks WHERE project_id IN (${PROJECT_IDS_CSV}))
      AND path != ''
    LIMIT 1
  \");
  \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_NUM) : null;
  echo \$row ? \$row[0] : '';
} catch (Exception \$e) { echo ''; }
" 2>/dev/null || echo "")

# ── Output shell variable assignments ────────────────────────────────────────
printf 'export SEEDED_TASK_IDS=%q\n'     "${TASK_IDS}"
printf 'export SEEDED_ACTION_IDS=%q\n'   "${ACTION_IDS}"
printf 'export SEEDED_SUBTASK_IDS=%q\n'  "${SUBTASK_IDS}"
printf 'export SEEDED_FILE_PATHS=%q\n'   "${FILE_PATHS_RAW}"
printf 'export SURVIVING_FILE_PATH=%q\n' "${SURVIVING_PATH_RAW}"
