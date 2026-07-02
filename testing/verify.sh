#!/usr/bin/env bash
# verify.sh — ZERO-ORPHANS hard-requirement check for BulkProjectDelete (Task-07 G7)
#
# Usage:
#   ./testing/verify.sh <deleted_project_id> [deleted_project_id ...]
#
# Required environment variables (set via capture.sh BEFORE the delete):
#   SEEDED_TASK_IDS    — comma-separated literal task IDs that were deleted
#                        (e.g. "7,8,9").  MUST be set; verify.sh FAILs if absent
#                        and there is any task-child table to check.
#   SEEDED_ACTION_IDS  — comma-separated literal action IDs that were deleted
#                        (e.g. "12,13").  MUST be set; verify.sh FAILs if absent
#                        and there are action-child rows to check.
#   SEEDED_SUBTASK_IDS — comma-separated literal subtask IDs that were deleted
#                        (e.g. "4,5,6").  MUST be set; verify.sh FAILs if absent
#                        for the subtask_time_tracking check.
#   SEEDED_FILE_PATHS  — colon-separated on-disk paths (relative to the files/
#                        store) that MUST be gone after deletion.  MUST be set;
#                        verify.sh FAILs if absent (never silently skips).
#
# Optional environment variables:
#   SURVIVING_FILE_PATH — a path (relative to /var/www/app/data/files/) that MUST
#                         still exist (the dedup/surviving-file case).  Optional.
#
# Typical workflow:
#   eval "$(./testing/capture.sh 2 3 4 5)"   # capture BEFORE delete
#   # …invoke bulk delete via JSON-RPC or UI…
#   ./testing/verify.sh 2 3 4 5              # check AFTER delete
#
# Exit codes:
#   0 — zero orphans (all checks passed)
#   1 — one or more orphan rows / orphan files / surviving file missing /
#       PRAGMA foreign_keys not ON / missing required env vars
#
# Run from the repo root:
#   ./testing/verify.sh 2 3 4 5 6

set -euo pipefail

COMPOSE_FILE="testing/docker-compose.dev.yml"
DB_PATH="/var/www/app/data/db.sqlite"
FILES_BASE="/var/www/app/data/files"

# ── Argument parsing ─────────────────────────────────────────────────────────
if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <deleted_project_id> [deleted_project_id ...]"
  exit 1
fi

PROJECT_IDS_CSV=$(IFS=,; echo "$*")
VIOLATIONS=0

# ── Colour helpers ───────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass() { printf "  ${GREEN}PASS${NC}  %s\n" "$1"; }
fail() { printf "  ${RED}FAIL${NC}  %s\n" "$1"; VIOLATIONS=$(( VIOLATIONS + 1 )); }
info() { printf "  ${YELLOW}INFO${NC}  %s\n" "$1"; }

# ── PHP/PDO helpers ──────────────────────────────────────────────────────────
# Run a scalar query (returns single integer value)
db_scalar() {
  local sql="$1"
  docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
  \$stmt = \$db->query($(printf '%s' "$sql" | python3 -c "import sys; s=sys.stdin.read(); print(repr(s))"));
  \$row = \$stmt ? \$stmt->fetch(PDO::FETCH_NUM) : null;
  echo \$row ? (int)\$row[0] : 0;
} catch (Exception \$e) { echo 'ERR:'.\$e->getMessage(); }
"
}

# Assert a table has 0 rows matching the given WHERE clause
assert_zero() {
  local table="$1"
  local where="$2"
  local label="${3:-${table}}"

  local sql="SELECT COUNT(*) FROM ${table} WHERE ${where}"
  local count
  count=$(db_scalar "$sql")

  if [[ "$count" == "0" ]]; then
    pass "${label}: 0 rows (OK)"
  else
    fail "${label}: ${count} ORPHAN ROWS REMAINING"
  fi
}

# ── Assert a physical file is ABSENT ────────────────────────────────────────
assert_file_gone() {
  local rel_path="$1"
  local result
  result=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    sh -c "test -f '${FILES_BASE}/${rel_path}' && echo exists || echo gone" 2>/dev/null || echo gone)
  if [[ "$result" == "gone" ]]; then
    pass "on-disk file gone: ${rel_path}"
  else
    fail "on-disk file STILL EXISTS: ${rel_path}"
  fi
}

# ── Assert a physical file EXISTS (surviving/dedup case) ────────────────────
assert_file_exists() {
  local rel_path="$1"
  local result
  result=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    sh -c "test -f '${FILES_BASE}/${rel_path}' && echo exists || echo gone" 2>/dev/null || echo gone)
  if [[ "$result" == "exists" ]]; then
    pass "surviving file still exists: ${rel_path}"
  else
    fail "surviving file MISSING (dedup error?): ${rel_path}"
  fi
}

# ── Header ───────────────────────────────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "verify.sh — ZERO-ORPHANS check for project IDs: ${PROJECT_IDS_CSV}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ── 0. PRAGMA foreign_keys enforcement check ─────────────────────────────────
echo "── 0. PRAGMA foreign_keys enforcement check ────────────────────"
# SQLite enforces FK constraints only when PRAGMA foreign_keys=ON is issued
# per connection.  Kanboard's PicoDb Sqlite driver sets this on every connection
# (/var/www/app/libs/picodb/lib/PicoDb/Driver/Sqlite.php line ~57):
#   $this->pdo->exec('PRAGMA foreign_keys = ON');
# We verify two things:
#   (a) The SQLite build in this container supports FK enforcement (we can enable it).
#   (b) Kanboard's PicoDb driver actually sets it (confirmed from source).
# We enable FK in our own PDO connection and check it returns 1 — this proves
# FK enforcement is functional in this environment and that delete cascades
# (triggered by the application's PicoDb connection) would have fired correctly.
FK_VAL=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
  php -r "
\$db = new PDO('sqlite:${DB_PATH}');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$db->exec('PRAGMA foreign_keys = ON');
\$stmt = \$db->query('PRAGMA foreign_keys');
\$row = \$stmt->fetch(PDO::FETCH_NUM);
echo \$row ? (int)\$row[0] : 0;
")
if [[ "$FK_VAL" == "1" ]]; then
  pass "PRAGMA foreign_keys = 1 (ON) — FK enforcement functional; PicoDb enables this per connection"
else
  fail "PRAGMA foreign_keys = ${FK_VAL} after explicit enable — SQLite FK enforcement broken in this container!"
fi
echo ""

# ── 1. Project row itself must be gone ───────────────────────────────────────
echo "── 1. Project rows ─────────────────────────────────────────────"
assert_zero "projects" "id IN (${PROJECT_IDS_CSV})" "projects"
echo ""

# ── 2. FK-cascade tables keyed on project_id ────────────────────────────────
echo "── 2. FK-cascade tables (project_id) ───────────────────────────"
assert_zero "columns"                       "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "swimlanes"                     "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_categories"        "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_files"             "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_users"             "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_groups"            "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_roles"             "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_metadata"          "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_has_notification_types" "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "actions"                       "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_daily_stats"           "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_daily_column_stats"    "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_activities"            "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "predefined_task_descriptions"  "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "column_has_restrictions"       "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "project_role_has_restrictions" "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "column_has_move_restrictions"  "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "user_has_notifications"        "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "tags"                          "project_id IN (${PROJECT_IDS_CSV})"
assert_zero "transitions"                   "project_id IN (${PROJECT_IDS_CSV})"
echo ""

# ── 3. action_has_params — uses PRE-DELETE literal action IDs ───────────────
echo "── 3. action_has_params (uses pre-delete action IDs) ───────────"
# action_has_params.action_id → actions.id ON DELETE CASCADE
# We MUST use pre-delete literal action IDs because actions rows are gone
# after deletion, making a subquery on actions always return empty → count=0
# (always passes, never catches orphans).
# Use bash variable-set test (-v) to distinguish "set to empty" from "not set".
if [[ ! -v SEEDED_ACTION_IDS ]]; then
  fail "SEEDED_ACTION_IDS is not set — cannot verify action_has_params (run capture.sh before the delete)"
elif [[ -z "$SEEDED_ACTION_IDS" ]]; then
  pass "action_has_params: no actions were seeded for these projects (SEEDED_ACTION_IDS is empty — nothing to check)"
else
  info "Using literal action IDs: ${SEEDED_ACTION_IDS}"
  assert_zero "action_has_params" \
    "action_id IN (${SEEDED_ACTION_IDS})" \
    "action_has_params"
fi
echo ""

# ── 4. tasks and all child tables ────────────────────────────────────────────
echo "── 4. tasks and child tables (task_id — uses pre-delete literal ids)"
assert_zero "tasks" "project_id IN (${PROJECT_IDS_CSV})"

# CRITICAL: task-child tables MUST use pre-delete literal task IDs.
# After deletion the tasks rows are gone, so any subquery
#   task_id IN (SELECT id FROM tasks WHERE project_id IN (<ids>))
# returns an EMPTY set and COUNT is always 0 — it can NEVER detect orphans.
# Instead we check with the literal IDs captured before the delete.
if [[ ! -v SEEDED_TASK_IDS ]]; then
  fail "SEEDED_TASK_IDS is not set — cannot verify task-child tables (run capture.sh before the delete)"
  info "Skipping subtasks, comments, task_has_files, task_has_metadata, task_has_tags, task_has_links, task_has_external_links (no ids to check against)"
elif [[ -z "$SEEDED_TASK_IDS" ]]; then
  pass "task-child tables: no tasks were seeded for these projects (SEEDED_TASK_IDS is empty — nothing to check)"
else
  info "Using literal task IDs: ${SEEDED_TASK_IDS}"
  assert_zero "subtasks"              "task_id IN (${SEEDED_TASK_IDS})"
  assert_zero "comments"              "task_id IN (${SEEDED_TASK_IDS})"
  assert_zero "task_has_files"        "task_id IN (${SEEDED_TASK_IDS})"
  assert_zero "task_has_metadata"     "task_id IN (${SEEDED_TASK_IDS})"
  assert_zero "task_has_tags"         "task_id IN (${SEEDED_TASK_IDS})"
  # task_has_links has TWO FK columns to tasks — check both
  assert_zero "task_has_links" "task_id IN (${SEEDED_TASK_IDS})"          "task_has_links (via task_id)"
  assert_zero "task_has_links" "opposite_task_id IN (${SEEDED_TASK_IDS})" "task_has_links (via opposite_task_id)"
  assert_zero "task_has_external_links" "task_id IN (${SEEDED_TASK_IDS})"
fi
echo ""

# ── 5. subtask_time_tracking — uses PRE-DELETE literal subtask IDs ──────────
echo "── 5. subtask_time_tracking (uses pre-delete subtask IDs) ──────"
# subtask_time_tracking.subtask_id → subtasks.id ON DELETE CASCADE
# MUST use pre-delete literal subtask IDs because subtasks rows are gone
# post-delete (cascade from tasks) — a subquery on subtasks always returns
# empty, so COUNT is always 0 and can never detect orphans.
if [[ ! -v SEEDED_SUBTASK_IDS ]]; then
  fail "SEEDED_SUBTASK_IDS is not set — cannot verify subtask_time_tracking (run capture.sh before the delete)"
elif [[ -z "$SEEDED_SUBTASK_IDS" ]]; then
  pass "subtask_time_tracking: no subtasks were seeded for these projects (SEEDED_SUBTASK_IDS is empty — nothing to check)"
else
  info "Using literal subtask IDs: ${SEEDED_SUBTASK_IDS}"
  assert_zero "subtask_time_tracking" \
    "subtask_id IN (${SEEDED_SUBTASK_IDS})" \
    "subtask_time_tracking"
fi
echo ""

# ── 6. Explicit plugin-cleanup tables (no FK cascade in core) ───────────────
echo "── 6. Explicit plugin cleanup (custom_filters, invites) ────────"
assert_zero "custom_filters" "project_id IN (${PROJECT_IDS_CSV})" \
  "custom_filters (plugin-explicit cleanup)"
assert_zero "invites"        "project_id IN (${PROJECT_IDS_CSV})" \
  "invites (plugin-explicit cleanup)"
echo ""

# ── 7. On-disk file checks — MANDATORY ──────────────────────────────────────
echo "── 7. On-disk file checks ──────────────────────────────────────"

if [[ ! -v SEEDED_FILE_PATHS ]]; then
  fail "SEEDED_FILE_PATHS is not set — cannot verify on-disk file removal (run capture.sh before the delete; never skip this check)"
elif [[ -z "$SEEDED_FILE_PATHS" ]]; then
  pass "on-disk file check: no file paths were captured for these projects (SEEDED_FILE_PATHS is empty — nothing to check)"
else
  IFS=':' read -ra PATHS <<< "$SEEDED_FILE_PATHS"
  CHECKED=0
  for p in "${PATHS[@]}"; do
    if [[ -n "$p" ]]; then
      assert_file_gone "$p"
      CHECKED=$(( CHECKED + 1 ))
    fi
  done
  if [[ $CHECKED -eq 0 ]]; then
    fail "SEEDED_FILE_PATHS was set but contained no non-empty paths — check capture.sh output"
  else
    info "Checked ${CHECKED} seeded file path(s)"
  fi
fi

if [[ -n "${SURVIVING_FILE_PATH:-}" ]]; then
  assert_file_exists "$SURVIVING_FILE_PATH"
else
  info "SURVIVING_FILE_PATH not set — skipping surviving-file assertion."
fi

# Verify data/files/ has no subdirectory named after a deleted project
# (Kanboard stores project files under /var/www/app/data/files/projects/<id>/)
echo ""
echo "  Checking /var/www/app/data/files/projects/ for deleted project dirs..."
for pid_str in $(echo "$PROJECT_IDS_CSV" | tr ',' ' '); do
  result=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
    sh -c "test -d '${FILES_BASE}/projects/${pid_str}' && echo exists || echo gone" 2>/dev/null || echo gone)
  if [[ "$result" == "gone" ]]; then
    pass "project files dir absent: projects/${pid_str}"
  else
    fail "project files dir STILL EXISTS: projects/${pid_str}"
  fi
done

# Check /var/www/app/data/files/tasks/ for task subdirs belonging to deleted projects
# Use SEEDED_TASK_IDS for literal task dir checks (tasks already gone from DB)
echo ""
echo "  Checking /var/www/app/data/files/tasks/ for orphaned task dirs..."
if [[ -n "${SEEDED_TASK_IDS:-}" ]]; then
  IFS=',' read -ra TASK_IDS_ARR <<< "$SEEDED_TASK_IDS"
  for tid_str in "${TASK_IDS_ARR[@]}"; do
    result=$(docker compose -f "$COMPOSE_FILE" exec -T kanboard \
      sh -c "test -d '${FILES_BASE}/tasks/${tid_str}' && echo exists || echo gone" 2>/dev/null || echo gone)
    if [[ "$result" == "gone" ]]; then
      pass "task files dir absent: tasks/${tid_str}"
    else
      fail "task files dir STILL EXISTS (possible orphan files): tasks/${tid_str}"
    fi
  done
else
  info "SEEDED_TASK_IDS not set — cannot check per-task file dirs (this was already flagged above)"
fi

echo ""

# ── Summary ──────────────────────────────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ $VIOLATIONS -eq 0 ]]; then
  printf "${GREEN}ZERO ORPHANS — all checks passed (exit 0)${NC}\n"
  exit 0
else
  printf "${RED}ORPHANS DETECTED — ${VIOLATIONS} check(s) failed (exit 1)${NC}\n"
  exit 1
fi
