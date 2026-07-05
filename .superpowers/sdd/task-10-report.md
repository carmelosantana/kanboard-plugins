# Task 10 Report: CalendarPlugin Full E2E Sweep + Coexistence Check

**Date:** 2026-07-04  
**Branch:** `feat/calendarplugin`  
**HEAD:** `3765333`  
**Container:** `kb-suite` at `http://localhost:8081`

---

## 1. Full E2E Sweep (`cal-full.mjs`)

All 8 assertions passed. 0 non-baseline console errors across the entire session.

| Test | Result | Observed Value |
|------|--------|----------------|
| **(a)** Global `/calendar` month view + ≥1 event | **PASS** | 13 `.fc-event` rendered, `.fc-daygrid` present, 0 errors |
| **(b)** Drag event → due date persists (JSON-RPC `getTask`) | **PASS** | Task 27: `2026-07-08 → 2026-07-20` (target day matched) |
| **(c)** Unscheduled sidebar item → drag schedules + removes from sidebar | **PASS** | Task 30: `date_due=2026-07-14`, `stillInSidebar=false` |
| **(d)** Project filter narrows visible set | **PASS** | 14 events → 2 events after filtering to project A only |
| **(e)** Overdue task shows `.cal-ev-overdue` | **PASS** | `overdueCount=2` visible in current month (prior-year tasks) |
| **(f)** Click event → `#cal-popover` with "Open task" link; outside-click closes | **PASS** | `hasLink=true`, `linkText="Open task"`, `closedOnOutsideClick=true` |
| **(g)** Per-project `/project/13/calendar` scopes to one project | **PASS** | `data-project-id="13"` set, 2 events (project A only) |
| **(h)** Agenda `listMonth` view renders `.fc-list` | **PASS** | `.fc-list` present in DOM after clicking listMonth button |

### Screenshots captured
- `shots/cal-full-a-month.png` — month view with 13 events
- `shots/cal-full-d-filter.png` — filtered to project A (2 events)
- `shots/cal-full-f-popover.png` — popover closed (outside-click)
- `shots/cal-full-h-agenda.png` — agenda list view

---

## 2. Coexistence Check (`cal-coexist.mjs`)

All 5 checks passed.

| Check | Result | Detail |
|-------|--------|--------|
| **1. Asset namespacing** | **PASS** | 22 `.cal-*` CSS selectors, 0 conflicting non-cal selectors, 0 suspect global JS assignments |
| **2. Board (ShadcnTheme themed)** | **PASS** | Board renders at `/board/11`, board columns found, **0 console errors** |
| **3. FeatureSync `/feature-sync`** | **PASS** | Page loads (title: "Settings > Feature Sync"), **0 console errors** |
| **4. SubtaskGenerator settings** | **PASS** | `/settings/integrations` and `/settings` both reference SubtaskGenerator, **0 console errors** |
| **5. CalPlugin asset paths** | **PASS** | `/plugins/CalendarPlugin/Assets/css/calendar.css` HTTP 200, `.../js/calendar.js` HTTP 200 |

### 6 Plugins in container (`docker exec kb-suite ls /var/www/app/plugins`)
```
BulkProjectDelete
CalendarPlugin
FeatureSync
ModMenu
ShadcnTheme
SubtaskGenerator
```

### CSS/JS namespace verification
- All 22 CSS rules use `.cal-*` or `#cal-*` prefixes — no collisions with `fc-`, Kanboard core, or other plugins
- JS uses no unexpected global window assignments
- `cal-root`, `cal-ev`, `cal-popover`, `cal-ev-overdue`, `cal-unscheduled`, `cal-filterbar` are the only identifiers used

---

## 3. Unit Tests

```
PHPUnit 9.6.19
.................     17 / 17 (100%)
Time: 00:04.063, Memory: 14.00 MB
OK (17 tests, 39 assertions)
```

---

## 4. Code Changes

**No tracked file changes.** The Docker bind mount (`../CalendarPlugin:/var/www/app/plugins/CalendarPlugin`) was already committed in a prior task. The E2E scripts (`cal-full.mjs`, `cal-coexist.mjs`) are in the scratchpad and are not tracked.

**Commit:** none — `git status` shows working tree clean at `3765333`.

---

## 5. Concerns

None. All features work end-to-end:
- Drag-to-reschedule correctly POSTs to `/calendar/update` and persists the change
- Unscheduled drag correctly POSTs and removes the item from the sidebar
- Overdue tasks (past due date) correctly receive `.cal-ev-overdue` outline
- Popover opens on click and closes on outside-click as expected
- Per-project tab correctly scopes events via `data-project-id`
- All 6 suite plugins coexist without asset or JS collisions
- CalendarPlugin's 17 unit tests (controllers, model, plugin registration) all green

---

## Final-review fix pass

**Date:** 2026-07-04  
**Branch:** `feat/calendarplugin`  
**Base HEAD:** `3765333`

### C1 — Privilege escalation fix (viewer 403 on reschedule)

**APIs confirmed (read-only core inspection):**
- `Kanboard\Model\ProjectUserRoleModel::getUserRole($project_id, $user_id)` — returns role string or empty string; defined at `/var/www/app/app/Model/ProjectUserRoleModel.php:68`. Falls back to group role.
- `Kanboard\Core\Security\Role::PROJECT_VIEWER = 'project-viewer'`, `PROJECT_MEMBER = 'project-member'`, `PROJECT_MANAGER = 'project-manager'` — all confirmed in `/var/www/app/app/Core/Security/Role.php`.

**Code change in `CalendarPlugin/Controller/CalendarController.php`:**
- Added `use Kanboard\Core\Security\Role;` import.
- Replaced the old membership check (`getActiveProjectsByUser` set membership, which includes viewers) with an explicit role check for non-admins. After resolving the task's project, for non-admin users the code calls `$this->projectUserRoleModel->getUserRole($projectId, $userId)` and rejects (403) unless the returned role is exactly `Role::PROJECT_MEMBER` or `Role::PROJECT_MANAGER`. Admins bypass this check. Order preserved: CSRF → task/project resolved → **write-capable role** → date valid → update.

**C1 E2E result (`cal-viewer-reject.mjs`):**
| Test | Status |
|------|--------|
| Viewer POST to `/calendar/update` | **403** (PASS) |
| date_due unchanged after viewer attempt | **PASS** (1796095554 → 1796095554) |
| Admin POST to `/calendar/update` | **200** (PASS) |
| date_due updated after admin POST | **PASS** (→ 1801279554) |

All 4 assertions: PASS. No fixture leakage (project + user cleaned up).

### M3 — Deterministic overdue test

**File:** `CalendarPlugin/Test/CalendarQueryModelTest.php::testOverdueFlagIsSetCorrectly`

**Before:** `$futureDue = mktime(12, 0, 0, (int) date('n'), 15)` — this month's 15th, which becomes past on/after the 15th of any month.

**After:** `$futureDue = mktime(12, 0, 0, 1, 15, (int) date('Y') + 1)` — Jan 15 of next year, always in the future. Query window extended to `mktime(0, 0, 0, 3, 1, (int) date('Y') + 1)` (Mar 1 next year) to include the future date deterministically.

### M12 — README PHP version

**File:** `CalendarPlugin/README.md`  
`PHP >= 8.1` → `PHP >= 8.4` (matches `plugin.json`'s `"php_version": ">=8.4"` and suite standard).

### Unit tests after fixes

```
PHPUnit 9.6.19
.................     17 / 17 (100%)
OK (17 tests, 39 assertions)
```

### API adjustments

The Kanboard JSON-RPC `createTask` procedure requires `date_due` in `mm/dd/yyyy` format (not unix timestamp). `getTask` returns `date_due` as a unix timestamp integer. No controller API changes were needed beyond the role check.

---

## 6. E2E Script Paths

- `/tmp/claude-1001/-home-carmelo-Projects-Kanboard/048dfc86-b3a7-4ac0-aa43-7ff369f5ddd6/scratchpad/e2e/cal-full.mjs`
- `/tmp/claude-1001/-home-carmelo-Projects-Kanboard/048dfc86-b3a7-4ac0-aa43-7ff369f5ddd6/scratchpad/e2e/cal-coexist.mjs`
- Screenshots: `.../scratchpad/e2e/shots/cal-full-*.png`, `cal-coexist-board.png`
