# TimeReport 1.5.0 — progress, milestones and XP (replaces TaskProgressBar)

- **Status:** design settled; ready for planning
- **Wayfinder map:** Kanboard #5376 (board 94 "Kanboard"); all 7 cruxes resolved 2026-10-07
- **Seed idea:** Kanboard #5375. **Origin:** Kanboard #5374 (progress lives in TimeReport), Battle Lobby map Kanboard #5363
- **Plugin:** `TimeReport` (`kanboard-plugins/TimeReport`, repo `carmelosantana/kanboard-time-report`), 1.4.4 → **1.5.0**; PHP ≥ 8.4, Kanboard ≥ 1.2.47, MIT
- **Consumer:** Battle Lobby, spec `docs/specs/2026-10-07-battle-lobby-theme-v1-design.md` (it *recommends* TimeReport ≥ 1.5.0 and themes its markup)
- **Replaces:** TaskProgressBar v1.0.1 (third-party, MIT) running in production at `app/plugins/TaskProgressBar`
- **Agent tooling:** kanboard-mcp (`~/MCP/kanboard-mcp`, separate repo)

## Why

Production shows subtask progress on board cards through TaskProgressBar. That plugin is a single `board/task_footer` template override plus one CSS file. It has no API, no project or milestone view, and no hooks of its own. Kanboard keeps one override per template, so it collides with any other plugin that wants the card footer, including Battle Lobby, whose quest bars, pass track and level badge need progress data.

TimeReport already owns the time data and is the suite's reporting plugin. Building progress into it gives one owner for "how far along is this work": card bars, project and milestone progress, and XP with levels that turn the report into something that also says how you're doing. A theme can style it through a stable markup contract, and agents can read it through JSON-RPC.

## Decisions

| # | Decision | Ticket |
|---|---|---|
| 1 | **Progress measure: two meters.** The primary meter is subtask completion, `nb_completed_subtasks / nb_subtasks` (TaskProgressBar parity), labelled with counts ("3/5"). A thin secondary meter shows `time_spent / time_estimated`, only when the task has an estimate. Overrun renders at 100% width in a warning state, with the real ratio in its label and attribute. Each meter renders only when its data exists, so a card with no subtasks and no estimate emits no markup. Stock wording is neutral ("subtasks", hours); themed labels such as "quests" are the theme's job. | Kanboard #5377 |
| 2 | **Milestone = core milestone link.** A milestone is a task with core "is a milestone of" links (link 8; members carry "targets milestone", link 9), the construct core already flags as `is_milestone`. Milestone progress = closed member tasks / member tasks in the same project. Due date orders the pass track; undated milestones sort after dated ones, by id. **Project progress** = closed tasks / all tasks. The agent workflow's subtask "milestones" count only as subtasks. | Kanboard #5378 |
| 3 | **XP lives in TimeReport, earned by completed work.** A completed subtask earns **10 XP** for the subtask assignee. A closed task earns **25 XP + 10 × complexity score** for the task assignee. Unassigned work earns nobody XP; logged hours earn nothing; reopening removes the XP. XP is per user and split per project: the level is global, and the data filters by project. **Agents** earn their own XP and hours. Through the Agents plugin's owner mapping (`agent_user_id → owner_user_id`), the owner also sees a **party** total (own XP plus their agents' XP), with the split shown. Project totals never double-count. **Level curve:** XP to reach level L = **50 × L × (L − 1)**. XP amounts are **derived and cached**. A completion-stamp table dates subtask XP for range reports. | Kanboard #5379 |
| 4 | **Theme contract v1: server-rendered BEM markup + `data-tr-*`.** There are three surfaces, carrying raw numbers in attributes, with no inline script (CSP-safe). The stock UI shows **only the card bars**. The pass track and level badge are always emitted but hidden by TimeReport's CSS, so a theme reveals them. Within v1, classes and attributes are only ever added; a breaking change bumps `data-tr-contract` and TimeReport's major version. The existing report gains **XP earned in the range** next to hours. | Kanboard #5380 |
| 5 | **API: five read-only JSON-RPC procedures**, registered with `withObject` (core wins name clashes) and checked against project permission. kanboard-mcp gains a **typed tool** for each, with a clear "TimeReport ≥ 1.5.0 not installed" error on method-not-found. No write procedures, and no hours-report procedure, in 1.5.0. | Kanboard #5381 |
| 6 | **Migration: coexist, then uninstall.** While TaskProgressBar is loaded, TimeReport hides `.container-task-progress-bar` and shows admins a dismissible removal notice. Roll out with deploy → check → delete the folder → confirm the core footer. Rollback restores the folder. TimeReport never declares a hard `conflicts` on TaskProgressBar. | Kanboard #5382 |
| 7 | **Performance: at most 3 extra queries per page, whatever the card count.** Card bars cost 0 queries (the fields already come from the board query). The pass track costs at most 2. The level badge costs 1 read from an event-maintained `xp_cache` table. A 500-card query-count test proves the budget. | Kanboard #5383 |

## Changes

### TimeReport plugin (1.5.0)

```
TimeReport/
  Plugin.php                  + footer/header/layout-top hooks, progress CSS, API objects,
                                event subscriber, TaskProgressBar detection + notice; version 1.5.0
  plugin.json                 version 1.5.0; description mentions progress + XP;
                                + recommends Agents (party XP totals; optional)
  Model/ProgressModel.php     task meters (pure, from the board row), project %, milestones
  Model/XpModel.php           earning rules, level curve, per user/project, party totals, ranges
  Model/XpCache.php           xp_cache read/rebuild (the one read/write path, like AiSummaryCache)
  Model/SubtaskCompletionStamp.php   stamp write/remove + one-time backfill
  Subscriber/ProgressSubscriber.php  task close/open, subtask status/assignee change → stamp + cache rebuild
  Api/ProgressProcedure.php   getTaskProgress, getProjectProgress, getMilestoneProgress
  Api/XpProcedure.php         getUserXp, getXpLeaderboard
  Template/board/progress.php        card meters (template:board:task:footer)
  Template/project/track.php         pass track (template:project:header:after), hidden by default
  Template/layout/level.php          current user's level badge (template:layout:top), hidden by default
  Template/config/tpb_notice.php     admin notice while TaskProgressBar is loaded
  Template/report/*                  + XP column / summary for the range
  Schema/{Sqlite,Mysql,Postgres}.php version_2: timereport_xp_cache, timereport_subtask_completions
  Assets/css/progress.css            stock card meters; hides .tr-track/.tr-level; TaskProgressBar hide rule
  docs/progress-contract.md + Test/fixtures/progress-contract.html
  Test/                              see Sequencing
```

### Progress rules

- **Task meters.** These are computed from the board row only (`nb_subtasks`, `nb_completed_subtasks`, `time_spent`, `time_estimated`), with no lookup. `pct = round(done / total × 100)`. `time_state = over` when `time_spent > time_estimated`, else `ok`.
- **Milestones.** Members are the tasks reached from the milestone task by "is a milestone of" links in the same project. The pass track lists the project's open milestones ordered by due date, then by id.
- **Project.** `pct = round(closed / total × 100)` over all tasks in the project; a project with no tasks emits no track.

### XP rules

| Event | XP | Credited to | Dated by |
|---|---|---|---|
| Subtask status → Done (2) | 10 | subtask `user_id` | `timereport_subtask_completions.completed_at` |
| Task closed | 25 + 10 × `score` | task `owner_id` | task `date_completed` |

- Unassigned (user 0) earns nothing. Reopening a task or un-completing a subtask removes the XP because amounts are re-derived from current state.
- Level: the highest L with `50 × L × (L − 1) ≤ xp`, so level 1 starts at 0 XP. `next` = XP still needed for L + 1.
- **Party:** if the Agents plugin is installed, an owner's party XP = own XP + the XP of every agent whose `owner_user_id` is the owner. Otherwise `party` is absent. Project and leaderboard totals count each user once.
- **Stamp table** `timereport_subtask_completions (subtask_id PK, user_id, completed_at)` cascades with the subtask. The subscriber writes a row on → Done and deletes it on ← Done. A one-time backfill in `version_2` dates already-done subtasks by their last `subtask_time_tracking.end`, else the parent task's `date_completed`, else its `date_modification`.
- **Cache** `timereport_xp_cache (user_id, project_id, xp, computed_at; PK user_id+project_id)` holds lifetime XP per user per project. The subscriber rebuilds the affected rows when a task closes or reopens, or a subtask's status or assignee changes. A missing row is computed on demand and stored. Date-range figures for the report and API are computed directly from tasks and stamps, not from the cache.

### Theme contract v1

| Surface | Hook | Root element | Attributes |
|---|---|---|---|
| Card progress | `template:board:task:footer` | `.tr-progress` containing `.tr-progress__bar` (`role="progressbar"`, `aria-valuenow/min/max`) and `.tr-progress__time` | `data-tr-contract="1"`, `data-tr-task`, `data-tr-subtasks-done`, `data-tr-subtasks-total`, `data-tr-pct`, `data-tr-time-spent`, `data-tr-time-est`, `data-tr-time-state` (`ok`/`over`) |
| Pass track | `template:project:header:after` | `section.tr-track` > `ol` > `li.tr-track__milestone` | `data-tr-contract`, `data-tr-project`, `data-tr-project-pct`; per milestone `data-tr-milestone`, `data-tr-pct`, `data-tr-due` (Y-m-d or empty), `data-tr-state` (`open`) |
| Level badge | `template:layout:top` | `span.tr-level` (current user, logged-in pages) | `data-tr-contract`, `data-tr-user`, `data-tr-level`, `data-tr-xp`, `data-tr-next`, `data-tr-party-xp` (only with Agents) |

- Meter fill width is set through a CSS custom property on the element (`style="--tr-pct:60%"`). The values are numeric and escaped. No script runs.
- The time meter's attributes and element are omitted when the task has no estimate. The whole `.tr-progress` root is omitted when the task has neither subtasks nor an estimate.
- Stock `progress.css` styles the card meters and sets `.tr-track, .tr-level { display: none }`. Themes override that rule.
- `docs/progress-contract.md` documents every class and attribute. `Test/fixtures/progress-contract.html` is a rendered sample that a TimeReport test regenerates and compares, and that Battle Lobby's tests read.

### JSON-RPC

| Procedure | Params | Returns |
|---|---|---|
| `getTaskProgress` | `task_id` | `{subtasks_done, subtasks_total, pct, time_spent, time_estimated, time_state}` |
| `getProjectProgress` | `project_id` | `{pct, closed, total, milestones:[{task_id, title, pct, closed, total, due}]}` |
| `getMilestoneProgress` | `task_id` (the milestone) | `{pct, closed, total, due, members:[task ids]}` |
| `getUserXp` | `user_id`, `project_id?`, `from?`, `to?` (Y-m-d) | `{xp, level, next, by_project:{id: xp}, party?:{xp, level, members:[{user_id, xp}]}}` |
| `getXpLeaderboard` | `project_id`, `from?`, `to?` | `[{user_id, xp, level, is_agent, owner_id}]` |

Project-scoped calls require project view permission for personal-token users. `getUserXp` counts only projects the caller can see. Without `from`/`to`, figures are lifetime (cache); with them, they are range-derived.

### TaskProgressBar coexistence

- Detection: at render time (so plugin load order doesn't matter), TimeReport checks whether `pluginLoader->getPlugins()` contains `TaskProgressBar`. If so, it adds a CSS rule hiding `.container-task-progress-bar` and renders an admin-only, dismissible notice (dismissal is stored per user in user metadata).
- Production switch: deploy TimeReport 1.5.0 → open a board and check cards with and without subtasks and estimates → delete `plugins/TaskProgressBar` → confirm core's `board/task_footer` renders (no override) and the notice is gone. Rollback: restore the folder.

### Battle Lobby

No change to its spec. It consumes contract v1. Its quest bar restyles `.tr-progress`, its pass track reveals `.tr-track`, and its party plates read `.tr-level`. Without TimeReport ≥ 1.5.0 those surfaces stay hidden, as its spec already says.

### kanboard-mcp

Add typed tools `get_task_progress`, `get_project_progress`, `get_milestone_progress`, `get_user_xp` and `get_xp_leaderboard` to `main.go`, mirroring the procedures. A JSON-RPC method-not-found maps to "TimeReport ≥ 1.5.0 not installed on this Kanboard". Tests follow `api_methods_test.go`.

### Release

CHANGELOG 1.5.0, a version bump in `Plugin.php` and `plugin.json`, a tag `v1.5.0`, zip-on-tag CI, and a ModMenu directory entry update (description, recommends Agents).

## Non-goals

- Write procedures (awarding XP, setting milestones) and exposing the hours report over JSON-RPC.
- Rendering the pass track or level badge in the stock UI, or a dedicated Progress page.
- XP from logged hours, achievements, badges beyond the level, seasons or resets.
- New milestone constructs (swimlane, tag or parent-task milestones) or a milestone editor; core's link UI is used as-is.
- Themed wording ("quests", tiers): that belongs to Battle Lobby.
- Lazy-loading progress after paint, or a JS renderer.
- A hard `conflicts` declaration on TaskProgressBar.

## Sequencing

1. **Schema `version_2`** (Sqlite/MySQL/Postgres): `timereport_xp_cache`, `timereport_subtask_completions`, and the backfill. Migration tests come first, extending `SchemaMigrationTest`.
2. **ProgressModel**: task meters (pure, table-driven tests covering the empty, no-estimate and overrun cases), milestones via core links, and project %.
3. **XpModel + SubtaskCompletionStamp**: earning rules, the level curve (boundary tests at L2/L5/L10/L20), unassigned and reopen cases, range dating, and party totals with and without the Agents plugin.
4. **XpCache + ProgressSubscriber**: event-driven rebuild on task close/open and subtask status/assignee change, plus compute-on-miss.
5. **Card meters**: the `template:board:task:footer` partial and `progress.css`. Tests assert that no override is registered and check the markup per contract.
6. **Pass track + level badge**: hidden-by-default surfaces, the contract doc, and the fixture test.
7. **Performance test**: seed a 500-card board and assert ≤ 3 extra queries, flat as cards grow.
8. **JSON-RPC**: the five procedures with permission tests; their numbers match the markup for the same fixtures.
9. **Report XP**: the XP column and summary in the existing report and CSV export.
10. **TaskProgressBar coexistence**: detection, the hide rule, and the admin notice with per-user dismissal.
11. **kanboard-mcp tools** (separate repo): the five typed tools and the not-installed error.
12. **Release 1.5.0**, then the production switch from TaskProgressBar (Decision 6 steps).
