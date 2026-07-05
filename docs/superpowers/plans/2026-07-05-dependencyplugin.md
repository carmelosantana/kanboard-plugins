# DependencyPlugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Kanboard plugin that computes and surfaces task **blocked status** (from core `blocks` / `is blocked by` links) on board cards, calendar events, and the task page, and rejects dependency **cycles**.

**Architecture:** An enhancement layer over core `TaskLinkModel`. A `DependencyModel` computes a memoized per-project blocked map (≤2 queries, no N+1) and detects cycles via graph DFS. Read-only surfaces render via core template hooks. A listener on `TaskLinkModel::EVENT_CREATE_UPDATE` removes any newly-created cyclic link and flashes a warning. Calendar integration reuses a **generic `badges[]` decorator hook added to CalendarPlugin** (Task 0).

**Tech Stack:** PHP ≥ 8.4, Kanboard v1.2.47 plugin API, PicoDb, vanilla JS (CSP-safe, external), PHPUnit (host harness `testing/run-plugin-tests.sh`), Playwright E2E on Docker `:8081`.

## Global Constraints

- **Buildless, MIT, PHP ≥ 8.4, Kanboard `>=1.2.47`.** `plugin.json` and `Plugin.php` metadata must agree (name `DependencyPlugin`, matching version, author `Carmelo Santana`).
- **CSP:** no inline `<script>` and no inline event handlers. JS lives in `Assets/js/*.js`, event-delegated, injected via `template:layout:js`; server data via `data-*`. Inline `<style>`/`style=""` allowed.
- **Asset scoping:** `template:layout:js`/`css` inject on EVERY page. Gate registration on the route in `Plugin::initialize()` (board/task/calendar pages only) — mirror CalendarPlugin's `isCalendarRequest()` using `Router::getPath()` (pure read; never `Route::findRoute`).
- **Routes:** `addRoute($path,'FooController','action','DependencyPlugin')` (4-arg form; the colon string 404s). `url->href/to()` put the plugin **inside `$params`**.
- **`token` is not a template helper** — generate CSRF tokens in the controller, pass via `data-*`.
- **CSS:** namespace everything `dep-*`; qualify modifier selectors so they don't lose specificity to element-qualified base rules; theme-token-aware with fallbacks (must read on standard Kanboard AND ShadcnTheme).
- **PicoDb:** never `->in('col', [])` (drops WHERE); guard `if (! empty($ids))`.
- **Blocked = has ≥1 `is blocked by` link to an OPEN task** (`tasks.is_active = 1`). "No blockers" and "all blockers completed" both mean not-blocked.
- **Do not restart/recreate the kb-suite container** (its entrypoint re-chowns bind-mounted plugins to nginx, breaking host writes). Edit files directly; opcache revalidates ~2s. If a chown is needed: `docker run --rm -v <repo>:/w alpine chown -R 1001:1001 /w`.
- **Every implementer implements its task ITSELF — do not spawn nested subagents** (git race hazard).

---

## File Structure

- `CalendarPlugin/Model/CalendarQueryModel.php` — **modified** (Task 0): fire a decorator hook per event; add `badges` to the payload.
- `CalendarPlugin/Assets/js/calendar.js` — **modified** (Task 0): render `extendedProps.badges[]` in `eventContent`.
- `DependencyPlugin/plugin.json`, `Plugin.php`, `LICENSE`, `README.md`, `CHANGELOG.md` — plugin metadata + wiring.
- `DependencyPlugin/Model/DependencyModel.php` — blocked map, cycle detector, task-panel helpers.
- `DependencyPlugin/Controller/DependencyController.php` — `blocked` JSON endpoint (calendar decorator source).
- `DependencyPlugin/Subscriber/DependencyLinkSubscriber.php` — cycle-guard listener.
- `DependencyPlugin/Template/board/badge.php` — board-card badge.
- `DependencyPlugin/Template/task/panel.php` — task-page dependencies panel.
- `DependencyPlugin/Assets/css/dependency.css`, `Assets/js/dependency-calendar.js` — surfaces styling/decoration.
- `DependencyPlugin/Test/*.php` — PHPUnit tests.
- `testing/docker-compose.dev.yml` — **modified**: mount `DependencyPlugin`.

---

### Task 0: CalendarPlugin generic event-decorator hook (de-risk cross-plugin coupling)

Add a documented extension point so any suite plugin can push badges onto calendar events, and prove it renders — BEFORE any DependencyPlugin logic. This becomes **CalendarPlugin 1.1.0**.

**Files:**
- Modify: `CalendarPlugin/Model/CalendarQueryModel.php` (in `mapRowToEvent` / `getEvents`)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (`eventContent`)
- Modify: `CalendarPlugin/plugin.json` + `CalendarPlugin/Plugin.php` version → `1.1.0`; `CalendarPlugin/CHANGELOG.md`
- Test: `CalendarPlugin/Test/CalendarQueryModelTest.php`

**Interfaces:**
- Produces: each event object gains `extendedProps.badges` = `array<{text:string, cls:string}>`. `CalendarQueryModel::getEvents()` calls `$this->hook->reference('calendar:event:badges', $event, $row)` — a filter other plugins attach to. (Use Kanboard's hook system: register the plugin listener via `$this->hook->on(...)` is for templates; for a data filter use the container-based approach below.)

Because Kanboard's template `hook` helper is for rendering, use a **plain listener list on the container**: CalendarPlugin exposes `calendarEventDecorators` (an array of callables) in the container; other plugins append a decorator `function(array $event, array $row): array`. `getEvents` runs each decorator over every event.

- [ ] **Step 1: Write the failing test** — `CalendarQueryModelTest::testEventDecoratorsAddBadges`

```php
public function testEventDecoratorsAddBadges()
{
    $projectModel = new \Kanboard\Model\ProjectModel($this->container);
    $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
    $pid = $projectModel->create(array('name' => 'DecP'));
    $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'Dec', 'date_due' => mktime(12,0,0,(int)date('n'),10)));

    // Register a decorator that flags this task.
    $this->container['calendarEventDecorators'] = array(
        function (array $event, array $row) use ($tid) {
            if ((int) $event['id'] === $tid) { $event['extendedProps']['badges'][] = array('text' => 'X', 'cls' => 'dep-blk'); }
            return $event;
        },
    );

    $model = new \Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel($this->container);
    $events = $model->getEvents(1, array(), mktime(0,0,0,(int)date('n'),1), mktime(0,0,0,(int)date('n')+1,1));
    $ev = null; foreach ($events as $e) { if ((int) $e['id'] === $tid) { $ev = $e; } }
    $this->assertNotNull($ev);
    $this->assertSame('X', $ev['extendedProps']['badges'][0]['text']);
}
```

- [ ] **Step 2: Run it — expect FAIL** (`badges` key missing). `./testing/run-plugin-tests.sh CalendarPlugin`
- [ ] **Step 3: Implement.** In `CalendarQueryModel::getEvents`, after building each event, apply decorators:

```php
// after $events[] = $this->mapRowToEvent($row); collect, then:
$decorators = isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array();
foreach ($events as $i => $event) {
    if (! isset($event['extendedProps']['badges'])) { $events[$i]['extendedProps']['badges'] = array(); }
    foreach ($decorators as $decorator) {
        $events[$i] = call_user_func($decorator, $events[$i], $rowsById[$event['id']]);
    }
}
```

Keep a `$rowsById` map (id ⇒ source row) so decorators can inspect task fields. If simpler, pass `$event` twice. Ensure `badges` defaults to `[]` on every event even with no decorators.

- [ ] **Step 4: Render badges in `calendar.js` `eventContent`** — after the estimate badge:

```javascript
var badges = arg.event.extendedProps.badges || [];
badges.forEach(function (b) {
    var el = document.createElement('span');
    el.className = 'cal-ev-badge ' + (b.cls || '');
    el.textContent = b.text;
    wrap.appendChild(el);
});
```

- [ ] **Step 5: Run tests — expect PASS.** Then bump `CalendarPlugin` to `1.1.0` (plugin.json + Plugin.php + CHANGELOG "Added: generic `calendarEventDecorators` extension point for suite plugins").
- [ ] **Step 6: Commit** `feat(CalendarPlugin): event-decorator extension point (badges[]) v1.1.0`

> **Note for controller:** the `calendarEventDecorators` container entry is populated by OTHER plugins in their `initialize()`. CalendarPlugin must read it defensively (may be unset). Document it in `CalendarPlugin/README.md` §cross-plugin.

---

### Task 1: DependencyPlugin skeleton

**Files:**
- Create: `DependencyPlugin/plugin.json`, `Plugin.php`, `LICENSE` (copy MIT from CalendarPlugin), `README.md`, `CHANGELOG.md`
- Create: `DependencyPlugin/Test/PluginTest.php`
- Modify: `testing/docker-compose.dev.yml` (add mount)

**Interfaces:**
- Produces: `Plugin` class `Kanboard\Plugin\DependencyPlugin\Plugin` with metadata getters; `getPluginName()='DependencyPlugin'`, version `1.0.0`, `getCompatibleVersion()='>=1.2.47'`.

- [ ] **Step 1: `plugin.json`** — mirror CalendarPlugin's (name/description/version 1.0.0/author/MIT/homepage/kanboard_version `>=1.2.47`/php_version `>=8.4`). Description: "Task dependencies: blocked/blocker badges on board, calendar, and task pages; cycle guard — built on core task links."
- [ ] **Step 2: `Plugin.php`** — `namespace Kanboard\Plugin\DependencyPlugin; use Kanboard\Core\Plugin\Base;` with `initialize()` (empty for now) and the metadata getters (copy CalendarPlugin's shape, adjust strings).
- [ ] **Step 3: `Test/PluginTest.php`** — assert the plugin class exists, `getPluginName()` and `getCompatibleVersion()` return the expected strings (mirror `CalendarPlugin/Test/PluginTest.php`).
- [ ] **Step 4: Mount in Docker** — add `- ../DependencyPlugin:/var/www/app/plugins/DependencyPlugin` to `testing/docker-compose.dev.yml`. **Do not restart the container in this task** (mount takes effect on next recreate; tests run via host harness).
- [ ] **Step 5: Run** `./testing/run-plugin-tests.sh DependencyPlugin` → PASS.
- [ ] **Step 6: Commit** `feat(DependencyPlugin): plugin skeleton + metadata + PluginTest`

---

### Task 2: DependencyModel — blocked map (no N+1)

**Files:**
- Create: `DependencyPlugin/Model/DependencyModel.php`
- Test: `DependencyPlugin/Test/DependencyModelTest.php`

**Interfaces:**
- Produces: `DependencyModel extends \Kanboard\Core\Base`.
  - `getProjectBlockedMap(int $projectId): array` → `map[taskId] = ['open_blockers'=>int, 'blocks'=>int]`; **memoized** per `$projectId` in a private array; only tasks with `open_blockers>0` OR `blocks>0` need appear (callers treat missing as zeros).
- Constants: `LINK_IS_BLOCKED_BY = 2`, `LINK_BLOCKS = 3` (core seeded ids — but resolve by label at runtime to be safe; see step 3).

- [ ] **Step 1: Failing test** — `DependencyModelTest::testBlockedMapCountsOpenBlockersOnly`

```php
public function testBlockedMapCountsOpenBlockersOnly()
{
    $p = new \Kanboard\Model\ProjectModel($this->container);
    $tc = new \Kanboard\Model\TaskCreationModel($this->container);
    $tl = new \Kanboard\Model\TaskLinkModel($this->container);
    $ts = new \Kanboard\Model\TaskStatusModel($this->container);
    $pid = $p->create(array('name' => 'Blk'));
    $a = $tc->create(array('project_id' => $pid, 'title' => 'A'));
    $b = $tc->create(array('project_id' => $pid, 'title' => 'B (blocker)'));
    $tl->create($a, $b, 2); // "A is blocked by B"

    $model = new \Kanboard\Plugin\DependencyPlugin\Model\DependencyModel($this->container);
    $map = $model->getProjectBlockedMap($pid);
    $this->assertSame(1, $map[$a]['open_blockers']);   // B is open
    $this->assertSame(1, $map[$b]['blocks']);          // B blocks A

    $ts->close($b);                                    // complete the blocker
    $model2 = new \Kanboard\Plugin\DependencyPlugin\Model\DependencyModel($this->container);
    $map2 = $model2->getProjectBlockedMap($pid);
    $this->assertTrue(empty($map2[$a]['open_blockers']) || $map2[$a]['open_blockers'] === 0);
}
```

- [ ] **Step 2: Run — expect FAIL** (class/method missing).
- [ ] **Step 3: Implement `getProjectBlockedMap`.** Resolve link ids by label once (`linkModel->getIdByLabel('is blocked by')` — verify method name; fallback to the `links` table query). Two queries scoped to the project:

```php
public function getProjectBlockedMap($projectId)
{
    $projectId = (int) $projectId;
    if (array_key_exists($projectId, $this->cache)) { return $this->cache[$projectId]; }

    $blockedById = $this->linkModel->getIdByLabel('is blocked by'); // returns int link id
    $map = array();

    // open_blockers: task T (in project) is blocked by an OPEN opposite task.
    $rows = $this->db->table(\Kanboard\Model\TaskLinkModel::TABLE)
        ->columns(\Kanboard\Model\TaskLinkModel::TABLE.'.task_id', 'COUNT(*) AS c')
        ->eq(\Kanboard\Model\TaskLinkModel::TABLE.'.link_id', $blockedById)
        ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'opposite_task_id') // the blocker task
        ->eq(\Kanboard\Model\TaskModel::TABLE.'.is_active', \Kanboard\Model\TaskModel::STATUS_OPEN)
        ->join('tasks_self', ...) // ensure the subject task is in $projectId — see note
        ->groupBy(\Kanboard\Model\TaskLinkModel::TABLE.'.task_id')
        ->findAll();
    // ...populate $map[task_id]['open_blockers']
```

**Implementer note (resolve during TDD):** scoping the *subject* task to the project requires joining `task_has_links.task_id → tasks.id` while the `is_active` filter applies to the *opposite* (blocker) task. PicoDb single-table `join` aliasing is limited; the robust approach is **two simpler queries** + PHP assembly: (1) fetch all `is blocked by` link rows whose subject task is in the project (`join tasks on tasks.id = task_id, eq project_id`), returning `task_id, opposite_task_id`; (2) fetch the set of open task ids among those `opposite_task_id`s; then count in PHP. This stays ≤2 queries, is readable, and sidesteps double-join aliasing. Do the same (mirrored) for `blocks`. Guard every `->in()` with `! empty()`.

Memoize: `$this->cache[$projectId] = $map;`.

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Add memoization + empty-project tests** — `testBlockedMapMemoizedAndEmptyProject`: assert a project with no links returns `array()` and that a second call does not re-query (spy by asserting identical array / add a query counter if practical; otherwise assert idempotent output).
- [ ] **Step 6: Commit** `feat(DependencyPlugin): DependencyModel::getProjectBlockedMap (memoized, no N+1)`

---

### Task 3: DependencyModel — cycle detector + panel helpers

**Files:**
- Modify: `DependencyPlugin/Model/DependencyModel.php`
- Test: `DependencyPlugin/Test/DependencyModelTest.php`

**Interfaces:**
- Produces:
  - `wouldCreateCycle(int $taskId, int $blockerId): bool` — true if making `$taskId` blocked-by `$blockerId` closes a cycle (i.e. `$taskId` is already a (transitive) blocker of `$blockerId`), or `$taskId === $blockerId`.
  - `getBlockers(int $taskId): array` and `getBlocking(int $taskId): array` — rows with `{task_id, title, is_active, project_id}` for the task-panel (may wrap `TaskLinkModel::getAll` filtered by label).

- [ ] **Step 1: Failing tests** — direct + transitive cycle + valid link:

```php
public function testWouldCreateCycleDetectsTransitive()
{
    // A blocked-by B, B blocked-by C already. Adding C blocked-by A closes A->B->C->A.
    // build A,B,C + links (link id 2 = "is blocked by")
    $model = new \Kanboard\Plugin\DependencyPlugin\Model\DependencyModel($this->container);
    $this->assertTrue($model->wouldCreateCycle($c, $a));   // C blocked-by A would cycle
    $this->assertFalse($model->wouldCreateCycle($a, $c));  // A blocked-by C is fine (already implied? assert per graph)
    $this->assertTrue($model->wouldCreateCycle($x, $x));   // self
}
```

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement `wouldCreateCycle`** — DFS from `$blockerId` following "is blocked by" edges (blocker → its blockers) looking for `$taskId`; visited-set bounded; cross-project safe:

```php
public function wouldCreateCycle($taskId, $blockerId)
{
    $taskId = (int) $taskId; $blockerId = (int) $blockerId;
    if ($taskId === $blockerId) { return true; }
    $blockedById = $this->linkModel->getIdByLabel('is blocked by');
    $stack = array($blockerId); $seen = array();
    while (! empty($stack)) {
        $node = array_pop($stack);
        if (isset($seen[$node])) { continue; }
        $seen[$node] = true;
        if ($node === $taskId) { return true; }
        $blockerIds = $this->db->table(\Kanboard\Model\TaskLinkModel::TABLE)
            ->eq('task_id', $node)->eq('link_id', $blockedById)
            ->findAllByColumn('opposite_task_id');
        foreach ($blockerIds as $bid) { $stack[] = (int) $bid; }
    }
    return false;
}
```

- [ ] **Step 4: Run — PASS.** Add `getBlockers`/`getBlocking` + a test asserting each returns the linked task with `is_active`.
- [ ] **Step 5: Commit** `feat(DependencyPlugin): cycle detector + panel helpers`

---

### Task 4: Cycle-guard listener

**Files:**
- Create: `DependencyPlugin/Subscriber/DependencyLinkSubscriber.php`
- Modify: `DependencyPlugin/Plugin.php` (register listener in `initialize()`)
- Test: `DependencyPlugin/Test/DependencyLinkSubscriberTest.php`

**Interfaces:**
- Consumes: `TaskLinkModel::EVENT_CREATE_UPDATE` (`'task_internal_link.create_update'`), payload is a `TaskLinkEvent` (GenericEvent) carrying at least the task-link id; the listener loads the link via `TaskLinkModel::getById`.
- Behavior: if the link's label is `is blocked by`/`blocks` and `wouldCreateCycle(subject, blocker)` (evaluated from the link's direction), remove the link pair via `TaskLinkModel::remove` and flash a failure.

- [ ] **Step 1: Failing test** — creating a cyclic link leaves no link rows:

```php
public function testCyclicLinkIsRemoved()
{
    // A blocked-by B, B blocked-by A (the second create closes the cycle)
    $tl = new \Kanboard\Model\TaskLinkModel($this->container);
    // register the subscriber the way Plugin::initialize does (dispatcher->addListener)
    (new \Kanboard\Plugin\DependencyPlugin\Plugin($this->container))->initialize();
    $tl->create($a, $b, 2);            // A blocked-by B — fine
    $tl->create($b, $a, 2);            // B blocked-by A — cycle → listener removes it
    $model = new \Kanboard\Plugin\DependencyPlugin\Model\DependencyModel($this->container);
    $this->assertFalse($model->wouldCreateCycle($a, $b) === null); // sanity
    // assert the B-blocked-by-A pair no longer exists
    $links = $tl->getAll($b);
    $labels = array_map(function ($l) { return $l['label'].':'.$l['task_id']; }, $links);
    $this->assertNotContains('is blocked by:'.$a, $labels);
}
```

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement the subscriber** — resolve the created link, detect cycle, remove pair, flash. Register in `Plugin::initialize()`:

```php
$this->dispatcher->addListener(\Kanboard\Model\TaskLinkModel::EVENT_CREATE_UPDATE, function ($event) {
    $this->dependencyModel; // ensure container service exists
    (new \Kanboard\Plugin\DependencyPlugin\Subscriber\DependencyLinkSubscriber($this->container))->onLinkCreateUpdate($event);
});
```

Subscriber `onLinkCreateUpdate($event)`: read `$event['id']` (or `getAll()` of event values), `getById`, check label ∈ {is blocked by, blocks}; normalize to (subject, blocker); if `wouldCreateCycle(subject, blocker)` → `taskLinkModel->remove($linkId)` (core removes both directions) and `$this->flash->failure(t('This link was removed: it would create a dependency cycle.'))`. Guard against infinite loops (removing fires EVENT_DELETE, not CREATE_UPDATE — safe).

**Implementer note:** confirm the flash accessor (`$this->flash->failure(...)` as core controllers use) and that `EVENT_CREATE_UPDATE` fires synchronously (default queue runs jobs inline — verified in the spec). Also register `dependencyModel` in the container in `initialize()`.

- [ ] **Step 4: Run — PASS.** Add a `testValidLinkIsKept` (non-cyclic link survives).
- [ ] **Step 5: Commit** `feat(DependencyPlugin): cycle-guard listener (remove + flash)`

---

### Task 5: Board-card badge

**Files:**
- Create: `DependencyPlugin/Template/board/badge.php`, `DependencyPlugin/Assets/css/dependency.css`
- Modify: `DependencyPlugin/Plugin.php` (hook + route-scoped CSS injection + container `dependencyModel`)
- E2E: scratchpad Playwright

**Interfaces:**
- Consumes: `template:board:private:task:before-title` (`$task` with `id`, `project_id`).
- Renders: a `dep-blocked` badge (🔒 + open-blocker count) when `getProjectBlockedMap($task['project_id'])[$task['id']]['open_blockers'] > 0`.

- [ ] **Step 1** Register in `initialize()`: `$this->template->hook->attach('template:board:private:task:before-title', 'DependencyPlugin:board/badge');` and register `dependencyModel` in the container. Inject `dependency.css` via `template:layout:css` **only on board/task/calendar routes** (route-gate like CalendarPlugin M1).
- [ ] **Step 2** `Template/board/badge.php`:

```php
<?php
$map = $this->model ? null : null; // use the helper below instead
$deps = $this->helper->app->getContainer()['dependencyModel']->getProjectBlockedMap($task['project_id']);
$open = isset($deps[$task['id']]['open_blockers']) ? (int) $deps[$task['id']]['open_blockers'] : 0;
if ($open > 0): ?>
<span class="dep-badge dep-blocked" title="<?= t('Blocked by %d open task(s)', $open) ?>">🔒 <?= $open ?></span>
<?php endif ?>
```

**Implementer note:** obtaining the model inside a template — prefer passing it via a template helper or reading `$this->container` if available in template scope; if neither is clean, register a tiny template helper `dependency` exposing `blockedOpen($task)`. Resolve during TDD; the memoized map means repeated calls per card are O(1).

- [ ] **Step 3** `dependency.css`: `.dep-badge { ... }` `.dep-blocked { color: var(--destructive,#c0392b); font-size:0.8em; }` — namespaced, theme-token fallbacks.
- [ ] **Step 4: E2E** (Playwright, `:8081`, admin/admin): seed project + task A blocked-by open task B; open the board; assert a `.dep-blocked` badge on A's card; complete B via API; reload; assert the badge is gone. Zero non-baseline console errors. Screenshot.
- [ ] **Step 5: Commit** `feat(DependencyPlugin): board-card blocked badge`

---

### Task 6: Task-page dependencies panel

**Files:**
- Create: `DependencyPlugin/Template/task/panel.php`
- Modify: `DependencyPlugin/Plugin.php` (hook)
- E2E: scratchpad Playwright

**Interfaces:**
- Consumes: `template:task:show:before-internal-links` (`$task`, `$project`).
- Renders: "Blocked by" list (each blocker with open/closed status) + "Blocks" list, from `DependencyModel::getBlockers/getBlocking($task['id'])`.

- [ ] **Step 1** Register `$this->template->hook->attach('template:task:show:before-internal-links', 'DependencyPlugin:task/panel');`.
- [ ] **Step 2** `panel.php`: render two lists; each row shows the task title (link via `url->href('TaskViewController','show',['task_id'=>...,'project_id'=>...])`), and a status pill (`dep-open`/`dep-done`) from `is_active`/`date_completed`. Skip the whole panel if both lists are empty.
- [ ] **Step 3: E2E** — on task A's page, assert the panel lists B under "Blocked by" with an "open" status; after completing B, the status shows "done". Zero console errors. Screenshot.
- [ ] **Step 4: Commit** `feat(DependencyPlugin): task-page dependencies panel`

---

### Task 7: Calendar integration (via Task-0 hook)

**Files:**
- Modify: `DependencyPlugin/Plugin.php` (append a calendar event decorator to the container `calendarEventDecorators`)
- Modify: `DependencyPlugin/Assets/css/dependency.css` (badge style on calendar)
- E2E: scratchpad Playwright

**Interfaces:**
- Consumes: CalendarPlugin's `calendarEventDecorators` container array (Task 0).
- Behavior: in `initialize()`, append a decorator `function(array $event, array $row): array` that, if the event's task is blocked (open blockers > 0 for its project), pushes `['text'=>'🔒','cls'=>'dep-blk']` into `$event['extendedProps']['badges']`.

- [ ] **Step 1** In `initialize()`:

```php
$this->container['calendarEventDecorators'] = array_merge(
    isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array(),
    array(function (array $event, array $row) {
        $pid = (int) ($row['project_id'] ?? 0);
        $map = $this->dependencyModel->getProjectBlockedMap($pid);
        if (! empty($map[(int) $event['id']]['open_blockers'])) {
            $event['extendedProps']['badges'][] = array('text' => '🔒', 'cls' => 'dep-blk');
        }
        return $event;
    })
);
```

**Implementer note:** the container is a Pimple instance; overwriting `calendarEventDecorators` with a plain array is fine at `initialize()` time (both plugins run `initialize` at bootstrap). If CalendarPlugin defines it lazily, ensure ordering doesn't matter (read-with-default on both sides). Confirm `$row` carries `project_id` (CalendarQueryModel's source rows do).

- [ ] **Step 2** CSS: `.cal-ev .dep-blk { ... }` (small, readable on pastel pills).
- [ ] **Step 3: E2E** — seed a blocked task with a due date; open `/calendar`; assert its FullCalendar event contains a `.dep-blk` badge; complete the blocker; refetch; badge gone. Zero console errors. Screenshot. **Requires** CalendarPlugin ≥ 1.1.0 (Task 0) in the running stack.
- [ ] **Step 4: Commit** `feat(DependencyPlugin): calendar blocked badge via CalendarPlugin decorator hook`

---

### Task 8: Docs, controller endpoint (if needed), release prep

**Files:**
- Create: `DependencyPlugin/Controller/DependencyController.php` (only if the calendar decorator proves insufficient server-side and a JSON endpoint is needed; otherwise SKIP and note why)
- Create/Modify: `DependencyPlugin/README.md`, `CHANGELOG.md`, `plugin.json`
- Test: controller scoping test (if the controller is added)

- [ ] **Step 1** Decide: Task 7's server-side decorator covers the calendar without a client endpoint → **default: no controller**. If added, `blocked` must scope to accessible projects (mirror CalendarPlugin's `accessibleProjectIds`) with a reject-path test.
- [ ] **Step 2** `README.md` — features, the three surfaces, the cycle guard (+ the async-queue caveat: on installs with a real queue driver, a cyclic link is still removed but the flash may not reach the user), install, and the CalendarPlugin ≥ 1.1.0 dependency for calendar badges.
- [ ] **Step 3** `CHANGELOG.md` `1.0.0`; confirm `plugin.json`/`Plugin.php` agree.
- [ ] **Step 4** Full test run `./testing/run-plugin-tests.sh DependencyPlugin` (all green) + `CalendarPlugin` (Task 0 didn't regress).
- [ ] **Step 5: Commit** `docs(DependencyPlugin): README + CHANGELOG; v1.0.0`

---

## After all tasks

- Dispatch the **whole-branch review** (most-capable model) over the full branch diff (`scripts/review-package <merge-base> HEAD`), paying special attention to: the cross-plugin container coupling (Task 0/7), the cycle-guard's synchronous-queue assumption + infinite-loop safety, the board-map N+1 claim (assert single computation), and permission scoping.
- Then `superpowers:finishing-a-development-branch` — merge to master; **confirm with the user before pushing/releasing**. Release entails: CalendarPlugin **1.1.0** (Task 0) AND DependencyPlugin **1.0.0**, plus two directory `plugins.json` entries.

## Self-review notes (author)

- **Spec coverage:** blocked map (T2), cycle guard (T3+T4), board (T5), task panel (T6), calendar (T0+T7), permissions (T8), testing (each task) — all §-mapped. Graph page + scheduling correctly absent (deferred).
- **Cross-task type consistency:** `getProjectBlockedMap` shape `[id=>['open_blockers','blocks']]` used identically in T5/T7; `badges[]` item shape `{text,cls}` identical in T0 render and T7 producer.
- **Known implementer-resolved unknowns (flagged inline):** exact PicoDb join strategy for the blocked map (T2 note → two-query fallback), template access to the model (T5 note → helper option), flash accessor + container decorator ordering (T4/T7 notes). Each has a concrete fallback so no step is a placeholder.
