# SchedulerPlugin — Design Spec

- **Date:** 2026-07-07
- **Status:** Approved (brainstorm complete) — ready for implementation plan
- **Repo:** `kanboard-plugins` (Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT)
- **Suite position:** 3rd of the 4-plugin design suite (CalendarPlugin → DependencyPlugin → **SchedulerPlugin** → EnhancedTaskPlugin). Consumes DependencyPlugin (skip-if-blocked) and CalendarPlugin (auto-moved badge), both as soft/graceful integrations.

## 1. Goal

Automatically roll overdue-but-open tasks forward on a daily sweep, with policy controls, per-project opt-in, a full audit log, and a native — clearly-automated — activity-stream summary. This is the first plugin in the suite that **writes** task data unattended, so safety (opt-in, dry-run, idempotence, deterministic policy) is a first-class requirement.

## 2. Global Constraints

- **Kanboard** ≥ 1.2.47; **PHP** ≥ 8.4; **buildless** (no compile step); **MIT**.
- **CSP:** no inline `<script>` and no inline event handlers; inline `<style>`/`style=""` are allowed. All JS in external files under `Assets/`.
- **CSRF:** state-changing HTTP endpoints (Run-now, per-project toggle, settings save) are POST and validated with Kanboard's form CSRF (`$this->token->getCSRFToken()` / `$this->checkCSRFParam()` for plain form posts; reusable CSRF only if a control posts via JS).
- **Authorization:** global settings, the log page, and Run-now are **admin-only**; the per-project enable toggle requires **project manager or admin** on that project. The sweep itself is a system job with no viewer context — the per-project opt-in is the consent gate.
- **Assets scoped by route** — never inject plugin JS/CSS sitewide unconditionally (see `kanboard-plugin-live-gotchas`). The calendar badge CSS is the one deliberately-sitewide exception, kept to a few bytes (mirrors DependencyPlugin's badge CSS decision).
- **No hard dependency on sibling plugins.** DependencyPlugin and CalendarPlugin are detected at runtime via the container; when absent, the corresponding feature is a silent no-op.
- **PicoDb footgun:** never pass an empty array to `->in()` (it drops the WHERE clause) — guard `if (! empty($ids))`.
- **Per-driver schema:** provide `Schema/Sqlite.php`, `Schema/Mysql.php`, `Schema/Postgres.php` with a `VERSION` constant and `version_N(PDO)` functions; core's `SchemaHandler` applies them and tracks state in `plugin_schema_versions`.

## 3. Architecture Overview

One pure policy component decides target dates; one orchestrator service performs a sweep and records it; three thin entry points (web-cron hook, HTTP button, CLI) all call the orchestrator; two read/write model layers (config, log) back it; controllers + templates provide the settings page, log pages, and per-project toggle; two soft integrations decorate sibling plugins.

```
                    ┌──────────────── entry points ────────────────┐
  layout hook  ─────┤ WebCronTrigger (guarded, once/day)            │
  HTTP button  ─────┤ SchedulerController::run  (admin, CSRF)       ├──► SchedulerRunner ──► ReschedulePolicy (pure)
  CLI          ─────┤ SchedulerRunCommand (--dry-run --project)     │           │                  ▲
                    └───────────────────────────────────────────────┘           │                  │
                                                                                 ├── SchedulerConfigModel (ConfigModel + project/task metadata)
                                                                                 ├── SchedulerLogModel   (scheduler_runs + scheduler_moves tables)
                                                                                 ├── ProjectActivityModel::createEvent (per-run summary, system actor)
                                                                                 └── DependencyModel::getProjectBlockedMap (soft, skip-if-blocked)
```

## 4. Components

### 4.1 `Model/ReschedulePolicy` — pure decision logic (no DB writes)

Given a list of candidate tasks, the config, a blocked-map, and a running per-day load accounting, compute each task's target due date. Deterministic and side-effect-free so it is fully unit-testable.

**Per-task pipeline** (applied to each overdue **open** task in an enabled project; "today" = the sweep's reference date):

1. **Skip if blocked** — if respect-blocks is on and `blockedMap[taskId]['open_blockers'] > 0`, return "no move" (leave current due date).
2. **Base target = today** (snap-overdue-to-today).
3. **Working-days adjust** — if the target is a non-working day (weekend per config, or in the holiday list), advance to the next working day.
4. **De-clump** — skipped entirely when the threshold `N <= 0` (the default, off). When `N >= 1`: let `load(day)` = count of open tasks in the project already due on `day` (existing scheduled tasks **plus** tasks this sweep has already assigned to `day`). While `load(target) >= N`, advance target by one day and re-apply the working-days rule. When a task is placed, increment `load(target)`.

**Outputs** per task: `{ move: bool, old_date, new_date, reason }` where `reason` names the deciding rule (`roll-forward`, `working-day`, `de-clump`, `skipped-blocked`). Idempotent: a task already due on its computed target yields `move: false`.

De-clump scope for v1 is **per-project-per-day count ≥ N**. Column/WIP-limit-aware de-clumping is explicitly deferred (needs per-column config + counts).

### 4.2 `Model/SchedulerRunner` — orchestration

`run(array $options): RunResult` where `$options` = `{ dry_run: bool, project_id: ?int, trigger: 'web'|'manual'|'cli' }`.

1. Master switch off → return an empty result immediately.
2. Resolve target projects: the explicit `project_id` if given (and enabled), else all opt-in-enabled active projects.
3. For each project: load overdue open tasks (`date_due` non-zero and `< today-midnight`, `is_active = STATUS_OPEN`); load the blocked-map once (if DependencyPlugin present); run `ReschedulePolicy` over the tasks with a fresh per-day load accounting seeded from tasks already due in-window.
4. For each task the policy says to move:
   - **Real run:** write `date_due` and `date_modification` **directly** via the task table (not `TaskModificationModel::update()` — that path would emit a core per-task activity event and defeat the single per-run summary). Set task metadata `scheduler.last_move = <today Y-m-d>`. Record a `scheduler_moves` row.
   - **Dry run:** record nothing persistent; collect the projected move into the result only.
5. **Real run, per project with ≥1 move:** `ProjectActivityModel::createEvent($project_id, 0, 0, 'scheduler.tasks.rescheduled', ['count' => M, 'run_id' => R])` — but only if post-to-activity config is on.
6. Persist one `scheduler_runs` row (real runs only) with trigger, moved_count, is_dry_run, timestamps.
7. Return `RunResult { runId, projects: [{project_id, moves: [...]}], total_moved, dry_run }`.

Ordering within a project is deterministic (e.g. by `date_due` then `id`) so de-clump distribution is stable and reproducible in tests.

### 4.3 Triggers

- **`Trigger/WebCronTrigger`** — subscribes to a cheap sitewide layout hook. On each eligible request: read `scheduler.last_run` (a date) from config; if the master switch is on, the current hour ≥ configured target hour, and `last_run < today`, then stamp `last_run = today` **first** (so concurrent requests don't double-fire) and call `SchedulerRunner::run(['trigger' => 'web'])`. Otherwise no-op. The guard read is a single config lookup; the sweep runs only once/day.
- **`Controller/SchedulerController::run`** — admin-only, CSRF-checked POST. Query param `dry_run` renders a **preview** (projected moves, nothing written); a real run redirects to the log page with a flash summary.
- **`Console/SchedulerRunCommand`** — registered via `$this->container['cli']->add(...)`. `./cli scheduler:run [--dry-run] [--project=ID]`. Prints a summary table; exit 0.

### 4.4 `Model/SchedulerConfigModel` — typed config access

Thin typed layer over `ConfigModel` (global) and metadata (project/task). Keys:

- Global (`ConfigModel`): `scheduler_enabled` (master, default `0`), `scheduler_target_hour` (default `2`), `scheduler_working_days` (default Mon–Fri), `scheduler_holidays` (list of `YYYY-MM-DD`), `scheduler_declump_threshold` (default `0` = off), `scheduler_respect_blocks` (default `1`), `scheduler_post_activity` (default `1`), `scheduler_badge_days` (calendar-badge recency window, default `3`), `scheduler_last_run` (date, internal).
- Project metadata: `scheduler.enabled` (`1` when opted in).
- Task metadata: `scheduler.last_move` (date of last auto-move; drives the calendar badge).

Methods: `isMasterEnabled()`, `getTargetHour()`, `getWorkingDays()`, `getHolidays()`, `getDeclumpThreshold()`, `respectBlocks()`, `postToActivity()`, `getBadgeDays()`, `getLastRun()/setLastRun()`, `isProjectEnabled($projectId)`, `setProjectEnabled($projectId, $bool)`, `enabledProjectIds()`, `getTaskLastMove($taskId)/setTaskLastMove($taskId,$date)`.

### 4.5 `Model/SchedulerLogModel` — audit persistence

Two tables (versioned schema):

- `scheduler_runs`: `id`, `started_at`, `finished_at`, `trigger` (`web`|`manual`|`cli`), `moved_count`, `is_dry_run`.
- `scheduler_moves`: `id`, `run_id` (FK → scheduler_runs.id), `project_id`, `task_id`, `old_date`, `new_date`, `reason`.

Methods: `createRun(...)`, `recordMove(...)`, `finishRun($id,$count)`, `getRecentRuns($limit)`, `getMovesForRun($runId)`. Real runs only; dry-runs never persist.

### 4.6 Controllers & Templates

- `SchedulerController`: `settings` (GET admin form), `save` (POST admin, CSRF), `run` (POST admin, CSRF, dry-run/real), `log` (GET admin — runs list), `runDetail` (GET admin — one run's moves), `toggleProject` (POST manager/admin, CSRF — set `scheduler.enabled`).
- Templates: `config/settings.php` (global form + Run-now/dry-run buttons), `project/toggle.php` (per-project enable control, hooked into project settings/sidebar), `log/index.php` (runs), `log/run.php` (moves detail), `event/tasks_rescheduled.php` (activity-stream fragment).
- Nav: an admin entry point to the settings/log page via `template:config:sidebar` (or the user dropdown), and the per-project toggle via a project-settings hook.

### 4.7 Cross-plugin integrations (both soft)

- **DependencyPlugin (consume):** in `SchedulerRunner`, if `isset($this->container['dependencyModel'])`, call `getProjectBlockedMap($projectId)` for the skip-if-blocked rule; otherwise treat every task as unblocked. No hard dependency.
- **CalendarPlugin (decorate):** in `Plugin::initialize()`, append a closure to `calendarEventDecorators` (via `array_merge`, exactly as DependencyPlugin does) that pushes a badge `['text' => "\u{23F0}", 'cls' => 'sch-moved']` when the task's `scheduler.last_move` is within `scheduler_badge_days` of today. No-op passthrough otherwise; graceful when CalendarPlugin is absent (the container key simply is never consumed).

## 5. Activity-Stream Event (native, clearly automated)

- Register the event name: `$this->eventManager->register('scheduler.tasks.rescheduled', t('Automatically rescheduled tasks'))`.
- Point its render at our template: `$this->template->setTemplateOverride('event/scheduler_tasks_rescheduled', 'SchedulerPlugin:event/tasks_rescheduled')` (the formatter renders `event/<event_name with dots→underscores>`).
- `createEvent(..., $creator_id = 0, ...)` = **system actor** (no fake human). The template renders "⏰ Scheduler rescheduled N tasks — [view log]" with a distinct automated look, ignoring the (empty) author. One event per project per run; dry-runs excluded; suppressed entirely when `scheduler_post_activity` is off.

## 6. Data-Write Safety Notes

- **Direct due-date write** (`date_due` + `date_modification`) is deliberate — it avoids core emitting a per-task modification activity for every move, which would flood the timeline and defeat the per-run-summary decision. A due-date-only change needs none of the extra work `TaskModificationModel::update()` does (recurrence, column moves, etc.).
- **Idempotence + last_run guard** together make double-firing harmless: the guard prevents a second same-day web-cron run, and even a forced re-run is a no-op because tasks already sit at their computed target.
- **Dry-run** writes nothing anywhere (no tables, no metadata, no activity) — pure preview.

## 7. Testing Strategy

- **Unit:** `ReschedulePolicy` (roll-forward target, weekend skip, holiday skip, de-clump distribution, blocked-skip, idempotence), `SchedulerConfigModel` (defaults + round-trip, project/task metadata), `SchedulerLogModel` (run/move persistence, dry-run writes nothing), `SchedulerRunner` (real vs dry-run, project scoping, activity-event emission gated by config), `PluginTest` (asserts `getCompatibleVersion()`, not a hardcoded version string).
- **E2E on the Docker suite (`:8081`):** enable one project, seed overdue open tasks (some blocked via DependencyPlugin, some on a weekend target), run via CLI and via the Run-now button (dry-run preview then real), and verify: correct moves, `scheduler.last_move` set, log page runs→detail, one per-run activity summary in the project stream, calendar "⏰" badge on moved events. Verify settings page + log page render cleanly on **both** standard Kanboard and ShadcnTheme with zero non-baseline console errors. Confirm the DependencyPlugin-absent and CalendarPlugin-absent paths degrade gracefully.

## 8. Out of Scope (deferred)

- **Cascade auto-reschedule** (moving a task shifts its dependents) — recursive graph mutation; the cascade behavior deferred from DependencyPlugin lands in a later SchedulerPlugin release, not v1.
- **Column/WIP-limit-aware de-clumping** — v1 de-clumps on a per-project-per-day count only.
- **Per-project policy overrides** — v1 policy parameters are global; only the enable toggle is per-project.
- **Additional target rules** beyond snap-to-today (e.g. per-task lead-time, priority-weighted spreading) — future.
- **Non-overdue rescheduling** — v1 only touches overdue open tasks; it never pulls future tasks earlier.

## 9. Deliverables

Plugin under `SchedulerPlugin/` (Plugin.php, Model/, Controller/, Console/, Trigger/, Schema/, Template/, Assets/, Test/, README.md, CHANGELOG.md, plugin.json), bind-mounted into the Docker suite, released as `SchedulerPlugin-v1.0.0`, and added to `kanboard-modmenu-directory/plugins.json`.
