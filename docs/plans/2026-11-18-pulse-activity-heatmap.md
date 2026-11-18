# Pulse Activity Heatmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `Pulse` v1.0.0, a buildless Kanboard plugin that draws a GitHub-style 12-month activity heatmap per person, on your own page and on a manager-only project page.

**Architecture:** No Pulse tables. Sources read core tables (and TimeReport's when present) and emit flat rows; `HeatmapBuilder` buckets rows into local days and levels; `ScopeResolver` decides which users and projects a viewer may see before any source runs. Server-rendered HTML grid; one external `pulse.js` swaps colour mode and shows the hover breakdown.

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 plugin API, PHPUnit (core test harness), plain CSS, vanilla JS + `node --test`.

**Spec:** `docs/specs/2026-11-18-pulse-activity-heatmap-design.md` (decisions 1–16 are binding).

## Global Constraints

- Plugin name `Pulse`, namespace `Kanboard\Plugin\Pulse`, repo `carmelosantana/kanboard-pulse`, folder `kanboard-plugins/Pulse` (its own git repo; harness ignores it).
- `kanboard_version` `>=1.2.47`, `php_version` `>=8.4`, MIT, author `Carmelo Santana`, version `1.0.0` in `plugin.json`, `Plugin::getPluginVersion()` and the tag `v1.0.0`.
- Dependency shape, verbatim: `"requires": []`, `"recommends": [{ "plugin": "Agents", "min_version": "0.4.1", "reason": "shows your agents' graphs next to yours" }, { "plugin": "TimeReport", "min_version": "1.7.1", "reason": "counts manually entered time as time logged" }]`.
- Buildless: no npm deps, no bundler, no inline JS (CSP). No new JS libraries.
- Every source call receives an explicit, non-empty `$projectIds` list; an empty list returns `[]` without querying.
- No DB-specific SQL date functions; bucket in PHP. Must run on SQLite, MySQL, Postgres.
- Never use Bigboard's names, routes, controllers, services or template namespace.
- Commits in the Pulse repo: no AI attribution. Tests: `./testing/run-plugin-tests.sh Pulse` (from harness root), full suite to completion; JS: `node --test Pulse/Test/js/`.

## Review Focus

1. **A manager peeking through another project:** member active in A and B, viewer manages only A → project page for A shows only A's activity; own-page `user_id`/`project_id` spoofing returns 403 or is ignored. (Task 6 tests.)
2. **Timezone edges:** an event at 23:30 UTC for a viewer in `America/New_York` lands on the local day; DST change weeks still give 7 rows per column. (Task 4 tests.)
3. **Serialized vs JSON activity data:** older rows store PHP-serialized `data`, newer JSON; both must decode, and garbage must be skipped, not fatal. (Task 2 test.)
4. **TimeReport absent / Agents absent:** missing `timereport_manual_time` table and missing `AgentTable` class degrade silently. (Tasks 3 and 6 tests.)
5. **Empty and huge graphs:** a person with zero activity renders an all-empty grid with "No activity in the last 12 months"; a person with one busy day doesn't flatten everyone else (levels are per-person quartiles). (Task 4 tests.)

---

### Task 1: Scaffold the plugin repo

**Files:**
- Create: `Pulse/plugin.json`, `Pulse/Plugin.php`, `Pulse/LICENSE` (MIT, copy Presence's), `Pulse/README.md` (stub), `Pulse/.github/workflows/release.yml` (verbatim copy of `Presence/.github/workflows/release.yml`), `Pulse/.gitignore`
- Test: `Pulse/Test/PluginVersionTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\Pulse\Plugin` with getters (`getPluginName()` = `'Pulse'`, version `'1.0.0'`, homepage `https://github.com/carmelosantana/kanboard-pulse`, compatible `'>=1.2.47'`). `initialize()` empty for now.

- [ ] **Step 1:** `mkdir Pulse && git -C Pulse init -b main`.
- [ ] **Step 2: Write the failing test** `PluginVersionTest::testVersionsAgree`: decode `plugin.json`; assert `version === (new Plugin($container))->getPluginVersion() === '1.0.0'`; assert `requires === []`; assert every `recommends` entry has keys `plugin`, `min_version`, `reason` and `min_version` matches `/^\d+\.\d+\.\d+$/`.
- [ ] **Step 3:** Run `./testing/run-plugin-tests.sh Pulse` → FAIL (no plugin.json / class).
- [ ] **Step 4:** Write `plugin.json` (Global Constraints values, description "A GitHub-style activity heatmap for you, your agents and your projects.") and `Plugin.php`.
- [ ] **Step 5:** Run → PASS. Commit `chore: scaffold Pulse plugin`.

### Task 2: ProjectActivitySource

**Files:**
- Create: `Pulse/Model/ActivitySource.php`, `Pulse/Model/Source/ProjectActivitySource.php`
- Test: `Pulse/Test/ProjectActivitySourceTest.php`

**Interfaces:**
- Produces:
  ```php
  interface ActivitySource {
      /** @return list<string> */
      public function types(): array;
      /** @param list<int> $userIds  @param list<int> $projectIds
       *  @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}> */
      public function fetch(array $userIds, array $projectIds, int $from, int $to): array;
  }
  ```
  `ProjectActivitySource extends \Kanboard\Core\Base implements ActivitySource`; `types()` = `['move','task','comment','subtask','file']`. `$userIds === []` means "any user" (project page); `$projectIds === []` returns `[]`.

Mapping (spec "Data sources"): `move` ← `task.move.column|position|swimlane|project`; `task` ← `task.create|close|open`; `comment` ← `comment.create`; `subtask` ← `subtask.create` plus one completion per subtask = the earliest `subtask.update` row (any date, not range-bound) whose decoded `data['subtask']['status'] == SubtaskModel::STATUS_DONE`, emitted only if that row's date is in range; `file` ← `task.file.create`. Moves by the same `creator_id` + `task_id` + `date_creation` collapse to one row. Decode `data` with JSON first, then `unserialize($data, ['allowed_classes' => false])`; undecodable → skip that subtask.update row. Each row `count = 1, hours = 0`.

- [ ] **Step 1: Write failing tests** (insert rows straight into `project_activities` with `createEvent()`):
  - `testMapsEventNamesToTypes` — one of each event + a `task.update` and `comment.user.mention` → types `move, task, comment, subtask, file` only.
  - `testDedupesMultiRowDrag` — `task.move.swimlane` + `task.move.column`, same user/task/second → one `move`; same pair one second apart → two.
  - `testSubtaskCompletionCountsOnce` — updates with status 1, 2, 2 (title edit), 0, 2 → exactly one `subtask` completion row at the first status-2 timestamp.
  - `testCompletionBeforeRangeNotRecounted` — first done row before `$from`, later done-snapshot edit inside range → no completion row.
  - `testDecodesSerializedAndJson` / `testSkipsGarbageData`.
  - `testFiltersByUsersProjectsAndRange` — rows outside users, projects or `[$from,$to]` excluded; `$projectIds = []` → `[]`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. Query `project_activities` with `in('project_id')`, optional `in('creator_id')`, `gte/lte date_creation`, `in('event_name')`; completion lookup is a second query over `subtask.update` for the same projects/users with no lower date bound.
- [ ] **Step 4:** Run → PASS. Commit `feat: project activity source`.

### Task 3: Time sources

**Files:**
- Create: `Pulse/Model/Source/TimerSource.php`, `Pulse/Model/Source/ManualTimeSource.php`
- Test: `Pulse/Test/TimeSourcesTest.php`

**Interfaces:**
- Consumes: `ActivitySource` (Task 2).
- Produces: both implement `ActivitySource`, `types()` = `['time']`, rows `count = 0`, `hours = float`.

`TimerSource`: `subtask_time_tracking` with `end > 0`, `ts = end`, `hours = time_spent`, joined `subtasks` → `tasks` for `project_id`, `user_id` from the tracking row. `ManualTimeSource`: if table `timereport_manual_time` is absent (probe with a `try { SELECT 1 … LIMIT 1 }` catch, or `$this->db->getDriver()`-agnostic check), return `[]`; else rows with `entered_at > 0`, `ts = entered_at`, `hours`, joined the same way.

- [ ] **Step 1: Write failing tests:** `testTimerRowsDatedByEnd`, `testOpenTimersIgnored` (`end = 0`), `testManualTimeWhenTableExists` (create the table in setUp with TimeReport's Sqlite DDL from `TimeReport/Schema/Sqlite.php`), `testUndatedManualSkipped`, `testManualTimeTableMissingReturnsEmpty`, `testProjectFilterApplies`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: timer and manual time sources`.

### Task 4: HeatmapBuilder

**Files:**
- Create: `Pulse/Model/HeatmapBuilder.php`
- Test: `Pulse/Test/HeatmapBuilderTest.php`

**Interfaces:**
- Produces:
  ```php
  HeatmapBuilder::range(\DateTimeImmutable $today): array{from:int, to:int, start:string}  // start = Sunday 52 weeks before this week's Sunday; to = end of $today; tz from $today
  HeatmapBuilder::build(array $rows, int $userId, \DateTimeZone $tz, \DateTimeImmutable $today): array
  // returns ['user_id'=>int, 'start'=>'Y-m-d', 'weeks'=>int (53 or 54 so today is included), 'total'=>float,
  //          'days'=>['Y-m-d'=>['n'=>float,'level'=>0..4,'types'=>[type=>['count'=>int,'hours'=>float]],
  //                            'projects'=>[project_id=>float], 'top_type'=>?string, 'top_project'=>?int]]]
  ```
  `days` holds every date from `start` to today (empty days `n = 0, level = 0`). Rows for other user ids are ignored.

Rules: day key = `(new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d')`; `n = count + hours` per row; `projects[pid] += n`; `top_type`/`top_project` = max `n`, ties broken by type order (`move, task, comment, subtask, file, time`) / lower project id. Levels: take non-zero `n` values sorted; thresholds at 25th/50th/75th percentile (nearest-rank); `level = 1 + number of thresholds strictly below n`, capped at 4.

- [ ] **Step 1: Write failing tests:** `testRangeStartsOnSundayAndEndsToday`, `testLocalDayBucketing` (23:30 UTC, NY tz → previous local day), `testDstWeekStillContiguous` (range across 2026-03-08 and 2026-11-01 has no gaps/duplicates), `testHoursCountAsEvents`, `testTopTypeAndProjectWithTies`, `testQuartileLevels` (n = 1,2,3,4,…,8 → levels 1,1,2,2,3,3,4,4), `testEmptyGraph` (total 0, all level 0).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: heatmap builder`.

### Task 5: SourceRegistry and Palette

**Files:**
- Create: `Pulse/Model/SourceRegistry.php`, `Pulse/Model/Palette.php`
- Test: `Pulse/Test/SourceRegistryTest.php`, `Pulse/Test/PaletteTest.php`

**Interfaces:**
- Produces:
  - `SourceRegistry::all(): list<ActivitySource>` — built-ins (Tasks 2–3) then each callable from `$this->hook->getListeners('pulse:sources')` invoked with no args; non-`ActivitySource` results skipped and logged at debug.
  - `SourceRegistry::fetchAll(array $userIds, array $projectIds, int $from, int $to): array` — concatenated rows.
  - `Palette::SERIES` = `['#2a78d6','#eb6834','#1baf7a','#eda100','#e87ba4','#008300','#6250d6','#e34948']`, `Palette::OTHER = '#898781'`.
  - `Palette::TYPES` = `['move'=>'#2a78d6','task'=>'#008300','comment'=>'#eb6834','subtask'=>'#1baf7a','file'=>'#eda100','time'=>'#6250d6']`; unknown type (e.g. future `commit`) → `#e87ba4`.
  - `Palette::assign(array $weights): array` — `$weights` = `[id => n]`; the 8 heaviest get a colour, preferred slot `id % 8`, linear-probe to the next free slot; the rest map to `OTHER`. Ties by lower id.

- [ ] **Step 1: Write failing tests:** `testRegistryIncludesBuiltinsAndHookSources` (register a fake source via `$this->container['hook']->on('pulse:sources', fn () => new FakeSource)`), `testRegistrySkipsNonSources`; `testAssignPrefersIdSlot`, `testAssignProbesOnCollision` (ids 1 and 9), `testAssignFoldsBeyondEight`, `testAssignIgnoresInputOrder`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: source registry and palette`.

### Task 6: ScopeResolver

**Files:**
- Create: `Pulse/Model/ScopeResolver.php`
- Test: `Pulse/Test/ScopeResolverTest.php`

**Interfaces:**
- Produces:
  ```php
  ScopeResolver::forUserPage(int $viewerId, ?int $targetUserId, ?int $projectId): array{user_ids:list<int>, project_ids:list<int>, projects:array<int,string>}
  ScopeResolver::forProjectPage(int $viewerId, int $projectId): array{project_ids:list<int>}
  ScopeResolver::ownedAgentIds(int $ownerId): list<int>
  ```
  Both throw `Kanboard\Core\Controller\AccessForbiddenException` on refusal.

Rules (spec "Permissions"):
- `forUserPage`: target defaults to viewer. If target ≠ viewer: allowed when viewer is admin (`$this->userSession->isAdmin()`), or target ∈ `ownedAgentIds(viewer)`; else 403. `user_ids` = target + `ownedAgentIds(target)` (target's own agents, deduped). `project_ids` = admin → `projectModel->getAllIds()`; otherwise `projectPermissionModel->getProjectIds($viewerId)` (active + closed). `projects` = id → name for that set (for the filter). If `$projectId` is given and in the set → `[$projectId]`; given and not in the set → ignored (whole set).
- `ownedAgentIds`: `class_exists('\Kanboard\Plugin\Agents\Model\AgentTable')` guard, then `AgentTable::getByOwner($ownerId)` → `agent_user_id` ints; any `\Throwable` (unmigrated table) → `[]`.
- `forProjectPage`: allowed when admin or `projectUserRoleModel->getUserRole($projectId, $viewerId) === Role::PROJECT_MANAGER` (covers group roles); else 403.

- [ ] **Step 1: Write failing tests:** `testSelfSeesOwnProjectsIncludingClosed`, `testOwnerSeesOwnAgents` (create `agents` table via `Agents/Schema/Sqlite.php` `version_1` when Agents is autoloadable; else skip), `testAgentsAbsentDegrades`, `testNonAdminCannotTargetOtherUser` (403), `testAdminTargetGetsAllProjectsAndTargetsAgents`, `testProjectFilterOutsideSetIgnored`, `testManagerAllowedOnProjectPage`, `testGroupManagerAllowed`, `testMemberForbiddenOnProjectPage`, `testManagerOfAOnlyGetsA`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS. **Step 5:** Commit `feat: scope resolver with permission rules`.

### Task 7: Controller, templates, menu links

**Files:**
- Create: `Pulse/Controller/PulseController.php`, `Pulse/Model/PulseService.php`, `Pulse/Template/pulse/graph.php`, `Pulse/Template/pulse/user.php`, `Pulse/Template/pulse/project.php`, `Pulse/Template/dashboard/sidebar.php`, `Pulse/Template/header/dropdown.php`, `Pulse/Template/project/dropdown.php`
- Modify: `Pulse/Plugin.php` (routes, hooks)
- Test: `Pulse/Test/PulseControllerTest.php`, `Pulse/Test/TemplatesTest.php`

**Interfaces:**
- Consumes: Tasks 4–6.
- Produces:
  - `PulseService::userGraphs(int $viewerId, ?int $targetUserId, ?int $projectId): array{graphs:list<array>, projects:array<int,string>, project_id:?int, user_colors:array<int,string>, project_colors:array<int,string>}` — one `HeatmapBuilder::build` per user id; tz = `new \DateTimeZone($this->timezoneModel->getCurrentTimezone())`; each graph also carries `name` (user name or username).
  - `PulseService::projectGraphs(int $viewerId, int $projectId): array` — same shape; users = distinct `user_id` in the project's rows (`userIds = []`), sorted by `total` desc.
  - Routes: `pulse` → `PulseController::index` (query `user_id`, `project_id`), `pulse/project/:project_id` → `PulseController::project`.
  - Partial `Pulse:pulse/graph` params: `graph`, `user_colors`, `project_colors`, `project_names`. This is the reusable partial Bigboard may embed later.
  - Cell markup: `<span class="pulse-cell" data-date data-level data-type data-project data-tip>` where `data-tip` is the escaped text breakdown, e.g. `Nov 3 · 4 moves, 3 comments, 1.5 h logged · Website, Ops`; `--pulse-c` inline custom property per graph wrapper carries the person colour; type/project colours live in `data-type-color` / `data-project-color` on each cell. Graph wrapper has a mode switch (`person | type | project`) as plain `<button data-pulse-mode>` elements, and a "No activity in the last 12 months" line when `total == 0`.
  - Hooks: `template:dashboard:sidebar` → `Pulse:dashboard/sidebar` ("Activity"); `template:header:dropdown` → `Pulse:header/dropdown` ("Activity"); `template:project:dropdown` → `Pulse:project/dropdown` ("Activity heatmap", rendered only when admin or project manager); `template:layout:css` / `template:layout:js` for the assets (Task 8 files).
  - User page: project filter `<select>` (all + `projects`), admin-only person picker (`userModel->getActiveUsersList()`), graphs stacked, viewer first then agents.

- [ ] **Step 1: Write failing tests** (pattern: `Presence/Test/HeartbeatControllerTest.php`, mocked `Response`):
  - `testIndexRendersOwnGraph`, `testIndexForbidsForeignUserForNonAdmin`, `testProjectPageForbiddenForMember`, `testProjectPageListsActiveUsersOnly` (a member with no activity absent; a non-member admin with activity present), `testGraphPartialEscapesTip` (task title / project name with `<script>` is escaped), `testProjectMenuLinkHiddenForMember`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement (controller calls `PulseService`; `PulseService` calls `ScopeResolver`, `SourceRegistry::fetchAll`, `HeatmapBuilder`, `Palette::assign`). **Step 4:** PASS. **Step 5:** Commit `feat: activity pages, graph partial and menu links`.

### Task 8: Assets

**Files:**
- Create: `Pulse/Assets/css/pulse.css`, `Pulse/Assets/js/pulse.js`
- Test: `Pulse/Test/js/pulse.test.mjs`, `Pulse/Test/AssetsTest.php`

**Interfaces:**
- Produces: `window.KBPulse = { colorFor(cell, mode, personColor), init(root) }`. `colorFor` returns `personColor` for `person`, `cell.dataset.typeColor` / `cell.dataset.projectColor` otherwise, `null` for level 0. Shade = `opacity` by level: `[0, .3, .5, .75, 1]` (empty cells use `var(--color-border, #e5e5e5)`). `init` binds mode buttons per graph (persists nothing) and a single tooltip element showing `data-tip` on hover/focus; cells are `tabindex="0"` for keyboard.

- [ ] **Step 1: Write failing tests:** JS (loader pattern from `Presence/Test/js/presence.test.mjs`): `colorFor` per mode, level-0 null, unknown mode falls back to person. PHP `AssetsTest`: both files exist, no `<script>` in any template, `Plugin::initialize()` registers both asset hooks.
- [ ] **Step 2:** FAIL. **Step 3:** Implement CSS grid (`grid-auto-flow: column; grid-template-rows: repeat(7, 11px); gap: 2px`, month labels above, Mon/Wed/Fri labels left, horizontal scroll inside the graph only at phone width) and JS. **Step 4:** PASS. **Step 5:** Commit `feat: heatmap styles and mode switch`.

### Task 9: Dev-stack verification and docs

**Files:**
- Create: `Pulse/scripts/seed-demo.php` (CLI, run inside `kb-suite`; refuses unless `DB_DRIVER` is sqlite and the host is the dev container), `Pulse/README.md` (full), `Pulse/CHANGELOG.md`
- Test: manual + full suites

- [ ] **Step 1:** Mount check: `docker exec kb-suite ls /var/www/app/plugins/Pulse` (add the mount to `testing/docker-compose.dev.yml` only if missing; that harness file is shared, commit by explicit path).
- [ ] **Step 2:** Seed: users `demo.ana` (manager of "Demo Web"), `demo.ben` (member of "Demo Web" and "Demo Ops"), agent `demo.ana.bot` owned by ana (Agents roster), 12 months of spread-out activity of every type via core models (`createEvent`, timers, manual time). Never touch or screenshot the imported production board.
- [ ] **Step 3:** Browser on `http://localhost:8081`: as admin, as ana, as ben, check own page, agent graph, mode switch, tooltip, project page 403 for ben, ana sees only "Demo Web" activity for ben. Capture screenshots of demo data only.
- [ ] **Step 4:** `./testing/run-plugin-tests.sh --all` → every suite passes (cite the table); `node --test Pulse/Test/js/` passes.
- [ ] **Step 5:** README (what, install, permissions table, `pulse:sources` hook contract with an example source, recommends), CHANGELOG `1.0.0`. Commit `docs: README and changelog`.

### Task 10: Release

- [ ] **Step 1:** Follow `kanboard:plugin-suite` `references/release.md`: create `carmelosantana/kanboard-pulse` (public), push `main`, tag `v1.0.0`, confirm the CI asset `Pulse-1.0.0.zip` has a single `Pulse/` top folder without `Test/`/`scripts/`, and downloads 200.
- [ ] **Step 2:** Add the entry to `kanboard-modmenu-directory/plugins.json` per `references/directory.md`; PR or push as that repo's convention says.
- [ ] **Step 3:** Message the Bigboard session with the partial name `Pulse:pulse/graph` and its params.
