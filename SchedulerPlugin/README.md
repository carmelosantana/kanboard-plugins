# SchedulerPlugin

A daily sweep that rolls overdue, still-open tasks forward to today, per
**opt-in project**. Built to be boring and safe: nothing moves unless a
project has explicitly turned it on, every run is logged, and dry-run always
means dry-run.

## What it does

Once a day (or on demand), SchedulerPlugin looks at every task in an
opted-in project that is **open** and **overdue** (`date_due` is set and in
the past), and decides whether to move it forward. Each candidate task is
run through a small policy pipeline, and only tasks the policy decides to
move actually get a `date_due` update. Everything else is left alone.

## Per-project opt-in

Nothing happens anywhere until two switches are both on:

1. **Master switch** — global, in **Settings → Scheduler**. Off by default.
2. **Per-project toggle** — a sidebar control ("Enable auto-reschedule") on
   each project, visible to project managers and admins. Off by default.

A project with the toggle off is invisible to the scheduler even while the
master switch is on — its tasks are never read, planned, or written.

## The policy pipeline

For each overdue task, in order:

1. **Skip-blocked** — if "respect blocks" is on and DependencyPlugin is
   installed, a task with open blockers is left untouched (`reason:
   skipped-blocked`). No DependencyPlugin, or the setting is off, and this
   step is a no-op.
2. **Snap-to-today** — the task's target day starts at today's date,
   preserving the task's original time-of-day (only the day changes).
3. **Working-days** — if today isn't a configured working day (or is a
   configured holiday), the target day advances to the next working day.
4. **De-clump** — if a de-clump threshold is set (> 0), and the target day
   already has that many-or-more open tasks due, the target day advances
   (one working day at a time) until it finds a day under the threshold.
   `0` disables de-clump entirely.

If the resulting day is the same as the task's original due day, nothing is
written (`reason: noop`) — the task simply isn't overdue relative to the new
target day. Otherwise the task's `date_due` is updated and the move is
logged with a `reason` of `roll-forward`, `working-day`, or `de-clump`
(whichever step last changed the target day).

Planning is deterministic: tasks are processed in due-date-then-id order, so
a re-run against the same data plans the same moves.

## The three triggers

All three triggers share one entry point, `SchedulerRunner::run()` — same
policy, same logging, same activity summary, regardless of how the run was
started.

1. **Lazy web-cron** — a hook on `template:layout:top` checks, on every
   rendered page request, whether the sweep has already run today. If the
   master switch is on, today's date hasn't been stamped as `last_run` yet,
   and the current hour is at or past the configured target hour, it stamps
   `last_run` **first** (so a burst of concurrent requests can't double-fire)
   and then runs the sweep. It never runs on asset or API requests — only on
   pages that render the shared layout.
2. **Admin Run-now** — a button on **Settings → Scheduler** that runs the
   sweep immediately, with a **dry-run** checkbox to preview without
   writing.
3. **CLI** — `./cli scheduler:run [--dry-run] [--project=ID]`. Runs for all
   opted-in projects, or a single project if `--project` is given and that
   project is opted in (otherwise nothing runs). Prints a one-line summary
   plus a per-project move count.

## Audit log

Every non-dry-run run writes to two tables:

- **`scheduler_runs`** — one row per run: `started_at`, `finished_at`,
  `trigger` (`web`/`manual`/`cli`), `moved_count`, `is_dry_run`.
- **`scheduler_moves`** — one row per task actually moved: `run_id`,
  `project_id`, `task_id`, `old_date`, `new_date`, `reason`.

Dry runs never touch either table.

**Settings → Scheduler → Log** lists recent runs; opening a run shows its
per-move rows (old date → new date, reason) for full traceability.

## Activity-stream summary

When a run moves at least one task in a project (and "post to activity" is
on), SchedulerPlugin posts **one** activity-stream entry per project per run
— `scheduler.tasks.rescheduled`, rendered as "Automatically rescheduled
tasks" with the moved count — using the system actor (creator/owner `0`),
not one entry per task. The direct `date_due` write in `applyMove()`
deliberately bypasses `TaskModificationModel` so core doesn't also emit a
per-task activity event for every move.

## Calendar auto-moved badge

If CalendarPlugin is installed, SchedulerPlugin appends a decorator to its
`calendarEventDecorators` extension point. Any task moved within the last
**badge window** days (`scheduler.last_move` metadata, configurable, default
3) gets a ⏰ badge on its calendar event. Lookups are memoized per project
(`SchedulerConfigModel::recentlyMovedTaskIds()`), one query per project per
request — never a per-event metadata read.

## Config keys and defaults

| Key | Default | Meaning |
|---|---|---|
| `scheduler_enabled` | off (`0`) | Master switch |
| `scheduler_target_hour` | `2` | Hour of day (0–23) the web-cron trigger becomes eligible to fire |
| `scheduler_working_days` | `1,2,3,4,5` (Mon–Fri) | ISO weekday numbers (1=Mon..7=Sun) considered working days |
| `scheduler_holidays` | empty | Newline/comma/space-separated `YYYY-MM-DD` dates treated as non-working days |
| `scheduler_declump_threshold` | `0` (off) | Max open tasks allowed due on one day before later moves spill to the next working day |
| `scheduler_badge_days` | `3` | How many days a calendar event keeps the ⏰ auto-moved badge |
| `scheduler_respect_blocks` | on (`1`) | Whether tasks with open DependencyPlugin blockers are skipped |
| `scheduler_post_activity` | on (`1`) | Whether a per-run activity-stream summary is posted |

Per-project: `scheduler.enabled` project metadata (`0`/`1`, default off).

## Cross-plugin

SchedulerPlugin has two **soft** integrations — both degrade to a no-op when
the sibling plugin is absent, with no hard class references that would
fatal.

- **DependencyPlugin (consumer)** — if `respect_blocks` is on and the
  `dependencyModel` container key is registered, SchedulerPlugin calls
  `DependencyModel::getProjectBlockedMap()` to find tasks with open
  blockers and skips them. Without DependencyPlugin installed, the check is
  skipped entirely and no task is ever treated as blocked.
- **CalendarPlugin (contributor)** — SchedulerPlugin appends a callable to
  the `calendarEventDecorators` container array (the same extension point
  DependencyPlugin uses for its 🔒 badge) to add the ⏰ auto-moved badge.
  Without CalendarPlugin installed, nothing ever reads that container key,
  so the decorator registration is inert.

## Safety notes

- **Opt-in everywhere** — master switch off by default, per-project toggle
  off by default. A fresh install of SchedulerPlugin changes nothing until
  an admin turns it on for at least one project.
- **Dry-run writes nothing** — no `date_due` update, no `scheduler_runs` or
  `scheduler_moves` row, no activity-stream entry, no `scheduler.last_move`
  metadata. The CLI, the admin preview, and a hypothetical future caller of
  `SchedulerRunner::run(['dry_run' => true])` all share this guarantee
  because it's enforced once, in the runner, not duplicated per trigger.
- **Idempotent** — running the sweep again the same day is a no-op for any
  task whose planned target day equals its current due day (`reason: noop`,
  nothing written). The web-cron trigger additionally guards itself to at
  most once per calendar day.
- **Scoped writes** — only tasks belonging to a project with the opt-in
  metadata flag set are ever read or written; the master switch alone is not
  sufficient to move anything.

## Install

**Option A — manual:**

1. Download/copy the `SchedulerPlugin` directory.
2. Place it into your Kanboard installation's `plugins/` directory, so the
   path is `plugins/SchedulerPlugin/Plugin.php`.
3. Reload Kanboard. The plugin ships its own schema migrations (SQLite,
   MySQL, and PostgreSQL) for `scheduler_runs` and `scheduler_moves`; these
   run automatically on first load.

**Option B — via ModMenu:** install from the plugin directory listing if
you're running the ModMenu plugin manager.

After install, everything stays off until you visit **Settings →
Scheduler**, turn the master switch on, and enable auto-reschedule on at
least one project.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4
- Optional: **DependencyPlugin** for skip-blocked behavior (soft dependency)
- Optional: **CalendarPlugin >= 1.1.0** for the ⏰ auto-moved badge (soft
  dependency, same `calendarEventDecorators` hook DependencyPlugin uses)

See `CHANGELOG.md` for release history.
