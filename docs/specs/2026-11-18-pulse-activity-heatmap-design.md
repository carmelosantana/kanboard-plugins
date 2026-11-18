# Pulse — GitHub-style activity heatmap for Kanboard (v1.0)

- **Status:** design drafted 2026-11-18; awaiting Carmelo's approval
- **Plugin:** `Pulse` (directory and PHP namespace `Kanboard\Plugin\Pulse`), repo `carmelosantana/kanboard-pulse`
- **Working folder:** `kanboard-plugins/Pulse` (new)
- **Neighbours:** Bigboard (separate plugin, agreed 2026-11-17: Bigboard owns the cross-project board, Pulse owns all activity views), Agents (roster), TimeReport (manual time), a future git plugin (commits)

## Why

Carmelo wants to see where work actually happens, at a glance, the way GitHub's contribution graph shows
it: for himself, for the agents he runs, and, as a project manager, for the people on his projects. Kanboard
already records most of this (the `project_activities` stream, subtask timers, TimeReport's manual time) but
only shows it as a text feed. Pulse turns those records into a heatmap, with colour telling apart people,
projects and kinds of work.

## Goals, non-goals, anti-goals

- **Goals:** a 12-month heatmap per person; your own page (you plus your agents); a project page for
  managers (every member, within that project); colour by person, project or type; a per-day breakdown
  on hover; a seam for new activity sources (git commits later).
- **Non-goals (v1):** a cross-project kanban (Bigboard), an event feed of its own (core's activity stream
  already exists), history beyond 12 months, export, streaks/gamification, AI summaries.
- **Anti-goals:** revealing activity in a project the viewer can't see; counting anything twice; a new JS
  dependency; storing a second copy of activity that can drift from core.

## Decisions

| # | Decision | Settled |
|---|---|---|
| 1 | **Home:** a new suite plugin, Pulse. No activity page, tab or heatmap goes into Bigboard; Bigboard may later embed Pulse's partial as a `recommends`. | Bigboard session, 2026-11-17 |
| 2 | **Your page:** you see your own graph plus one graph per agent you own (Agents roster `owner_user_id`). Without the Agents plugin, only your own graph. A project filter (all projects / one) sits on top. | Carmelo |
| 3 | **Project managers:** see a graph per member of a project they manage, counting **only activity inside that project**. | Carmelo (accepted proposal) |
| 4 | **Admins:** see anyone, everywhere, through a person picker on the same page. | Carmelo (accepted proposal) |
| 5 | **Regular members:** don't see other members' graphs, even in shared projects. | Carmelo (accepted proposal) |
| 6 | **What counts (types):** task moves, task lifecycle (created, closed, reopened), comments, subtasks (created, completed), files attached, time logged. Excluded: mentions, field-only task edits, deletes, overdue notices. Git commits arrive later as a new type via the source seam. | Carmelo (accepted proposal) |
| 7 | **Range:** the last 12 months (53 week columns ending today), as on GitHub. | Carmelo (accepted proposal) |
| 8 | **Colour, A+B:** one graph per person, each in that person's hue, shade = how much (4 steps + empty). Each graph has a switch: colour by **person** (default), **type**, or **project**; in type/project mode each cell takes the hue of what dominated that day. Hovering a cell shows the full breakdown (e.g. "Nov 3 · 4 moves, 3 comments, 1.5 h logged · Website, Ops"). | Carmelo |
| 9 | **Data: count on the fly.** No Pulse tables. Each request aggregates from core tables (and TimeReport's when present), grouped by local day. Optimise later with a cache only if measured slow. | Carmelo (option 1) |
| 10 | **Rendering:** server-rendered HTML/CSS grid (no canvas, no chart library). One external `pulse.js` (Kanboard CSP forbids inline JS) handles the colour switch and tooltip from `data-*` attributes. jQuery/`KB` only, no new dependencies. | Builder's call, stated to Carmelo |
| 11 | **Days:** bucketed at local midnight in the viewer's timezone (Kanboard user timezone, else app timezone). | Builder's call, stated to Carmelo |
| 12 | **Where:** "Activity" link in the dashboard sidebar and the avatar dropdown → your page. "Activity heatmap" item in the project menu, shown only to that project's managers and to admins → project page. Must not reuse Bigboard's names or claim its links. | Builder's call, stated to Carmelo |
| 13 | **Closed projects count.** Your page uses every project you belong to, active or closed (`getProjectIds`), so closing a project doesn't erase past work, and self and admin views agree. The project filter lists the same set. | Builder's call (builder check) |
| 14 | **Admin picker = that person's own page.** An admin picking a person sees exactly what that person would see (them plus their agents), but across all projects. Picking an agent directly shows just that agent. | Builder's call (builder check) |
| 15 | **Project page lists everyone active there,** not only current members: one graph per user with any counted activity in the project in range (former members, admins, agents included), ordered by activity. Managers can already read the same actions in core's project activity stream, so this reveals nothing new. | Builder's call (builder check) |
| 16 | **Moves are de-duplicated:** move rows by the same user on the same task within the same second count as one move (a drag that changes swimlane and column writes two rows). | Builder's call (builder check) |

## Data sources

Each source is a class implementing one interface, so a git plugin can add commits without touching Pulse's
core:

```php
interface ActivitySource {
    public function type(): string;            // e.g. 'comment', 'time'
    /** @return list<array{user_id:int, project_id:int, ts:int, count:float, hours:float}> */
    public function fetch(array $userIds, array $projectIds, int $from, int $to): array;
}
```

Sources are registered through Kanboard's hook registry, which works whatever order plugins load in: a
third-party plugin calls `$this->hook->on('pulse:sources', fn () => new MySource($this->container))` from its
`initialize()` (it needs no Pulse classes at load time, only at call time, so it guards with
`interface_exists`). Pulse collects `getListeners('pulse:sources')` at request time, after every plugin has
initialised, and adds its own built-in sources. The aggregator merges every source's rows into `day → {type → count, hours}, {project → count}`.

| Type | Table | Rule |
|---|---|---|
| `move` | `project_activities` | `event_name` in `task.move.column`, `task.move.position`, `task.move.swimlane`, `task.move.project` |
| `task` | `project_activities` | `task.create`, `task.close`, `task.open` |
| `comment` | `project_activities` | `comment.create` |
| `subtask` | `project_activities` | `subtask.create`; and a completion = the **earliest** `subtask.update` row whose snapshot (`data.subtask.status`) is done, once per subtask, looked up without the range bound so later edits of a done subtask never count again. (Core stores no status diff: `changes` is empty on a toggle.) A subtask finished, reopened and finished again counts once; accepted. |
| `file` | `project_activities` | `task.file.create` |
| `time` | `subtask_time_tracking` | timer rows with `end > 0`, dated by `end`, hours = `time_spent`; joined through subtasks → tasks for `project_id` |
| `time` | `timereport_manual_time` | only if the table exists (TimeReport installed); rows with `entered_at > 0`, dated by `entered_at`, `hours`. TimeReport defines manual time as total minus timer time, so the two never overlap. Undated rows (`entered_at = 0`) are skipped. |

Who did it: `project_activities.creator_id`, `subtask_time_tracking.user_id`, `timereport_manual_time.user_id`.
Activity made with the shared application API token has no user and is not recorded by core, so it never
appears; per-user tokens (Agents) are recorded and attributed to the agent.

A day's **intensity** is its event count, with each logged hour counting as one event (so a day of pure
timer work still lights up). Shade steps are quartiles of the person's non-zero days in the range, as
GitHub does, so a quiet person's graph isn't blank next to a busy agent's.

## Permissions (every query)

The set of visible project ids is computed first and passed into every source as `$projectIds`; no source
queries without it.

- **Own page (self + own agents):** `projectPermissionModel->getProjectIds($viewerId)` (active and closed),
  i.e. the projects the viewer belongs to. An agent's activity is shown only in those projects too.
- **Admin viewing someone:** all projects (`projectModel->getAllIds()`); admin is checked with
  `userSession->isAdmin()`.
- **Project page:** the single project id, after
  `projectUserRoleModel->getUserRole($projectId, $viewerId) === Role::PROJECT_MANAGER` or admin;
  otherwise 403 (`AccessForbiddenException`). Graphs = every user with counted activity in that project
  (decision 15).
- The project filter on your own page is intersected with the visible set, never trusted from the request.
- User ids on your page are the viewer plus `AgentTable::getByOwner($viewerId)` (class_exists guard). An
  admin may pass `user_id`; anyone else passing a `user_id` other than self/own agent gets 403.

## Changes

```
Pulse/
  Plugin.php                 routes, hooks, asset includes
  plugin.json                version 1.0.0, MIT, kanboard >=1.2.47, php >=8.4, requires [],
                             recommends [Agents, TimeReport]
  Model/ActivitySource.php   interface
  Model/SourceRegistry.php   built-ins + hook listeners on 'pulse:sources', resolved at request time
  Model/Source/*.php         ProjectActivitySource (move/task/comment/subtask/file), TimerSource, ManualTimeSource
  Model/HeatmapBuilder.php   range, timezone buckets, merge, quartile levels, dominant type/project per day
  Model/ScopeResolver.php    viewer → user ids + visible project ids, with the 403 rules above
  Model/Palette.php          stable hue per user/project/type (fixed 8-colour order, falls back to grey "other")
  Controller/PulseController.php   user page (index), project page (project)
  Template/pulse/graph.php   one graph partial (reusable by Bigboard): params graph, palette, mode
  Template/pulse/user.php, pulse/project.php, dashboard/sidebar.php, header/dropdown.php, project/menu.php
  Assets/js/pulse.js         mode switch, tooltip
  Assets/css/pulse.css       grid, 4 shades via opacity or color-mix, ShadcnTheme-friendly tokens
  Locale/, Test/, README.md, LICENSE, .github/workflows/release.yml
```

Colour assignment is stable: a person or project keeps its hue across filters and pages (hue chosen by id,
not by rank). Types have fixed hues. More than 8 projects in one graph fold into "Other".

## Testing and verification

- PHPUnit via `testing/run-plugin-tests.sh Pulse`, full suite. TDD per source, builder, scope resolver.
- Permission tests are mandatory: manager of A can't see the member's activity in B; member gets 403 on the
  project page; non-admin `user_id` spoofing gets 403; filter outside the visible set is ignored.
- Dev stack at :8081: seed demo users, an agent, two projects and a year of activity (never the imported
  production board in screenshots or docs).
- SQLite, MySQL and Postgres: aggregation done in PHP over plain selects (no DB-specific date functions).

## Left to the builder

- Exact markup/CSS of the grid and tooltip; month/weekday labels.
- Hue values (use the suite palette / ShadcnTheme tokens where present).
- Whether a "Combined" view on your page ships in v1 (nice-to-have; drop if it costs much).
- Release mechanics follow the kanboard:plugin-suite skill (zip-on-tag, ModMenu listing).
