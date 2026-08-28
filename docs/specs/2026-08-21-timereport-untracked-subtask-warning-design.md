# TimeReport — Untracked Subtask-Time Warning (Design)

**Date:** 2026-08-21
**Status:** Approved (brainstormed with the user)
**Plugin:** `TimeReport` (extends the existing plugin; see
`2026-08-21-time-report-plugin-design.md`)
**Methodology:** Follow the `kanboard-plugin-suite` skill for all suite conventions.

## Problem

Kanboard lets a user type a `time_spent` value directly onto a subtask (via the
subtask edit form), or add manual time that later mixes with timer runs. TimeReport
reports **date-tracked** time only — it reads `subtask_time_tracking` (which has
timestamps it can bucket by day/week), not the subtask record's `time_spent` field
(which has no date). So manually-entered subtask time silently does not appear in the
report, and the user cannot tell it was dropped.

Confirmed Kanboard behavior (`SubtaskTimeTrackingModel::logEndTime` →
`updateSubtaskTimeSpent`): stopping a timer **adds** the elapsed time to
`subtasks.time_spent` (`time_spent = time_spent + elapsed`). So a subtask's recorded
`time_spent` can be larger than the sum of its dated tracking rows — the difference is
manual/untracked time.

## Decision

**Surface it, don't block it.** TimeReport stays a read-only reporting plugin — it does
**not** disable or alter Kanboard's subtask editing (that would be a global behavior
change affecting every project/user and Kanboard's own analytics, and would need a
persisted settings system this plugin deliberately lacks). Instead, the report
**detects and warns** about untracked subtask time, on-screen.

## Global constraints (unchanged from the plugin)

- Read-only, stateless: no persisted state, no settings page, no DB migration.
- Buildless: plain PHP ≥ 8.4, plain templates, no inline JS (CSP).
- Self-only v1: `userId = $this->userSession->getId()`, a single named variable.
- This feature is additive and pre-release, so it lands in **1.0.0** (keep
  `plugin.json.version`, `Plugin.php::getPluginVersion()`, and the tag aligned). If
  1.0.0 has already been cut for release, it becomes **1.1.0** instead — align all three.

## Detection rule (difference-based, dateless, scoped to project + self)

For the selected `projectId` and the current `userId`:

1. Gather the user's subtasks in the project whose record `time_spent > 0`:
   `(subtask_id, task_id, time_spent, parent task reference + title)`.
2. Gather the user's **tracked** hours per subtask from `subtask_time_tracking`
   (same hours math as the report: `time_spent` when `> 0`, else `(end - start)/3600`),
   summed per `subtask_id`.
3. For each such subtask, compute:
   `untracked = round(subtask.time_spent, 2) − round(tracked, 2)`, **clamped to ≥ 0**.
   Flag the subtask when `untracked ≥ 0.01` (one hundredth of an hour ≈ 36 s — the same
   2-dp precision the report uses everywhere; sub-second timer toggles never trip it).

The flagged `untracked` value is the **difference** (the uncounted portion), not the
whole subtask. This covers both the fully-manual case (`tracked = 0` → `untracked =
time_spent`) and the partial case (record 1.5, tracked 0.5 → `untracked = 1.0`). A
subtask whose time is fully tracked (`time_spent == tracked`) yields `untracked = 0` and
is not flagged.

**Not date-filtered.** Manual `time_spent` has no reliable date, so detection is scoped
to the project + current user, not the report's date range.

**Known v1 limitation:** `subtasks.time_spent` is not per-user; detection compares it to
**the current user's** tracked rows. If a subtask's time was tracked by multiple users
(reassignment), the untracked figure can be off. Acceptable because this is an advisory
warning, never part of the report's counted totals. Documented, not fixed in v1.

## Data model

Add a pure, unit-tested function to `TimeReportModel` (mirrors `buildContributions`'s
array-in/array-out style so it is testable without a database):

```php
/**
 * @param list<array{subtask_id:int,task_id:int,time_spent:float}> $subtaskRecords user's subtasks with time_spent>0 in the project
 * @param array<int,float> $trackedBySubtask  subtask_id => summed tracked hours for the user
 * @param array<int,array{reference:string,title:string}> $taskMeta  task_id => labels
 * @return array{task_count:int, subtask_count:int, total_hours:float, tasks: list<array{task_id:int,reference:string,title:string,hours:float}>}
 */
public static function findUntrackedSubtaskTime(array $subtaskRecords, array $trackedBySubtask, array $taskMeta): array
```

- Per subtask: `untracked = round(time_spent,2) − round(trackedBySubtask[id] ?? 0, 2)`,
  clamped ≥ 0; include only when `untracked ≥ 0.01`.
- Group flagged subtasks by `task_id`; per-task `hours` = sum of that task's `untracked`
  values; `subtask_count` = flagged subtasks; `task_count` = distinct tasks;
  `total_hours` = sum of all `untracked`. `tasks` sorted by reference then task id.
- Empty input (or nothing flagged) → `{task_count:0, subtask_count:0, total_hours:0.0,
  tasks: []}`.

`report()` gathers the two inputs (a new private `gatherUntrackedInputs($projectId,
$userId)` querying `subtasks` joined to `tasks`, and reusing the already-fetched
per-user subtask tracking rows to sum tracked hours per subtask), calls
`findUntrackedSubtaskTime(...)`, and adds the result to the report aggregate under a new
key:

```php
$report['untracked'] = [ 'task_count'=>…, 'subtask_count'=>…, 'total_hours'=>…, 'tasks'=>[…] ];
```

This is kept entirely separate from `breakdown`/`detail`/`total_hours` — the union math
and all counted totals are unchanged. `untracked` is always present (empty aggregate
when nothing is flagged).

## Rendering (on-screen HTML only)

New partial `Template/report/_untracked.php`, rendered from `show.php` **only when**
`$report['untracked']['task_count'] > 0`, placed just below the header/summary block
(before the breakdown) so it is seen first.

- **Banner:** `⚠ {subtask_count} subtask(s) on {task_count} task(s) have {total_hours}h
  of manually-entered time that isn't date-tracked, so it isn't counted here — log it
  with the subtask timer or add it to the task's Time spent.` (hours via the existing
  `timeReport` helper `formatHours`).
- **List:** a small table — `Ref | Task | Untracked hours` — one row per affected task
  from `untracked['tasks']`, all values escaped via `$this->text->e(...)`.
- CSP-safe: no inline `<script>`, no inline handlers, no new JS.

**Markdown and CSV are unchanged** — the warning is on-screen guidance only, so the
exported report keeps carrying just the counted figures.

## Testing

- **Pure function `findUntrackedSubtaskTime`:**
  - fully-manual subtask (tracked 0) → flagged with full `time_spent`;
  - partial (record 1.5, tracked 0.5) → flagged with `untracked = 1.0`;
  - fully-tracked (record == tracked) → not flagged;
  - sub-precision difference (record 1.50, tracked 1.499) → rounds to 0.00 → not flagged;
  - grouping across multiple subtasks of one task sums per-task `hours`; counts and
    `total_hours` correct; `tasks` sorted; empty input → empty aggregate.
- **Template static-guard** (`_untracked.php` + `show.php`): banner block present, gated
  on `untracked.task_count > 0`; no inline `<script>`/`on*=` handlers.
- Full existing suite stays green; `report()` still returns the prior keys plus
  `untracked`.

## File changes

```
TimeReport/
├── Model/TimeReportModel.php        # + findUntrackedSubtaskTime() + gatherUntrackedInputs() + report() wires 'untracked'
├── Template/report/_untracked.php   # new partial (banner + affected-task list)
├── Template/report/show.php         # render _untracked when task_count>0
├── Test/TimeReportModelTest.php     # + findUntrackedSubtaskTime cases
├── Test/TemplateAssetsTest.php      # + _untracked guard assertions
└── CHANGELOG.md                     # note the warning under the target version
```

## Out of scope (v1)

- Blocking or altering subtask/time editing anywhere in Kanboard.
- A settings page or any persisted configuration.
- Carrying the warning into Markdown/CSV exports.
- Multi-user attribution of subtask `time_spent` (the known limitation above).
- Reconciling/importing the manual time into the dated report (there is no date to
  assign it to).
