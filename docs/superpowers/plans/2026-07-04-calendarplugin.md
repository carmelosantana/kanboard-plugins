# CalendarPlugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a drag-and-drop **Calendar** view for Kanboard v1.2.47 — a global "all my projects" page plus a per-project tab — that places tasks on their due date and lets users reschedule by dragging.

**Architecture:** A standard Kanboard plugin (`CalendarPlugin/`) that reuses core models (`TaskFinderModel`, `TaskModificationModel`, `ProjectUserRoleModel`, `ColorModel`). A `CalendarQueryModel` turns permission-scoped, filtered task rows into FullCalendar event objects; a `CalendarController` exposes a JSON `events` feed, a JSON `unscheduled` feed, and a POST `updateDate` reschedule endpoint. The UI is **FullCalendar v6 vendored as a static global build** (buildless, CSP-safe), initialized by our own external, event-delegated `calendar.js`.

**Tech Stack:** PHP ≥ 8.4, Kanboard v1.2.47 plugin API, PicoDb (via core models), FullCalendar v6 global build (MIT), vanilla ES5-safe JS (no build step), PHPUnit (host harness), Playwright (live E2E on Docker :8081).

## Global Constraints

- Kanboard **v1.2.47**, PHP **≥ 8.4**, **buildless** (no bundler/npm build), **MIT** license.
- **CSP is `default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:`** — NO inline `<script>` anywhere. All JS in `Assets/js/*.js` injected via `template:layout:js`; server→JS data via `data-*` attributes only. Inline `<style>`/`style=""` IS allowed.
- **CSRF for POST**: generate the reusable token in the **controller** (`$this->token->getReusableCSRFToken()`) and pass it to the template as a variable rendered into a `data-*` attribute. **NEVER** call `$this->token->...` inside a template (`token` is not a template helper — it throws and drops the page out of its layout).
- **POST params**: read via `$this->request->getValues()` (CSRF-validated POST array), never `getStringParam()` (GET-only).
- **`url->href()`**: the plugin goes **inside `$params`** (`href('C','a',['plugin'=>'CalendarPlugin', ...])`), not as the 4th positional arg (that arg is `$csrf`).
- **`route->addRoute()`**: use the **4-argument** form — `addRoute($path, 'CalendarController', 'action', 'CalendarPlugin')` (plugin as the 4th positional arg). The `'CalendarPlugin:CalendarController'` colon form does NOT work in v1.2.47 — `Router::sanitize()` rejects the colon and silently falls back to `DashboardController` (verified in Task 2). This applies only to `addRoute`; `url->to()`/`url->href()` still take the plugin inside `$params`.
- **E2E console-error baseline:** Kanboard emits 2 console errors from its own `fciconsfont` data-URI font on EVERY page (including `/dashboard`) — NOT plugin bugs. E2E "no console errors" checks must filter them (ignore `/fciconsfont|favicon|data:font/i`) and assert no NEW errors beyond that baseline.
- **PicoDb `->in('col', [])`** drops the WHERE clause (matches the whole table) — always guard `if (! empty($ids))` before an `in()` on a possibly-empty id list.
- **Popover** is a plain positioned element, NOT a Kanboard `js-modal-*` (avoids the `overlayClickDestroy=false` "stuck overlay" trap).
- Event contract (D4): a task with `time_estimated > 0` → **timed** event (`allDay=false`, `end = start + estimate hours`); `time_estimated == 0` → **all-day** event. In the Month/Agenda v1 the estimate also appears as a badge; sized-block rendering arrives with the deferred time-grid views.
- Filter state lives in the **URL query string only**; nothing persisted server-side in v1.
- Colors: theme-token-aware with hardcoded fallbacks so the UI reads under ShadcnTheme (dark) and stand-alone.
- Tests: unit via `./testing/run-plugin-tests.sh CalendarPlugin`; E2E via Playwright in `/tmp/.../scratchpad/e2e` against `http://localhost:8081` (admin/admin). The plugin is bind-mounted into the `kb-suite` container, so file edits are live (opcache revalidates ~2s).

**Reference — core APIs used (verified against v1.2.47):**
- `TaskFinderModel::getExtendedQuery()` → PicoDb query with columns incl. `tasks.id, title, date_due, date_completed, color_id, project_id, column_id, category_id, owner_id, time_estimated, is_active`, plus joins `projects.name` (alias via query), `columns.title AS column_title`, `users.name AS assignee_name / username AS assignee_username`. Constrain it further with `->in()`, `->gte('date_due',…)`, `->eq()`, then `->findAll()`.
- `ProjectUserRoleModel::getActiveProjectsByUser($user_id)` → `[project_id => project_name]` the user may access.
- `TaskModificationModel::update(array $values, $fire_events = true)` → update due date: `->update(['id'=>$id, 'date_due'=>$ts])`.
- `ColorModel::getBackgroundColor($color_id)` → hex string; `ColorModel::getList()` → `[color_id => label]`.
- `TaskModel::STATUS_OPEN` = 1, `STATUS_CLOSED` = 0.
- Full-page render: `$this->helper->layout->app('CalendarPlugin:calendar/index', $params)`.
- Project view-switcher hook: `template:project-header:view-switcher` (params `project`, `filters`).
- Test base: `require_once 'tests/units/Base.php'; use KanboardTests\units\Base;`. Create fixtures via `$this->container['projectModel']->create(...)`, `$this->container['taskCreationModel']->create(...)`, `$this->container['userModel']->create(...)`.

---

## Task 1: Plugin skeleton + metadata

**Files:**
- Create: `CalendarPlugin/Plugin.php`
- Create: `CalendarPlugin/plugin.json`
- Create: `CalendarPlugin/LICENSE` (MIT, copy from `FeatureSync/LICENSE`)
- Create: `CalendarPlugin/README.md` (one-paragraph description + install)
- Create: `CalendarPlugin/CHANGELOG.md`
- Test: `CalendarPlugin/Test/PluginTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\CalendarPlugin\Plugin` with `getPluginName(): string = 'CalendarPlugin'`, `getPluginVersion(): string = '1.0.0'`, `getPluginAuthor()`, `getPluginDescription()`, `getCompatibleVersion() = '>=1.2.47'`, `getPluginLicense() = 'MIT'`, and `initialize(): void` (empty for now).

- [ ] **Step 1: Write the failing test** — `CalendarPlugin/Test/PluginTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('CalendarPlugin', $plugin->getPluginName());
    }

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
    }

    public function testPluginMetadataNotEmpty()
    {
        $plugin = new Plugin($this->container);
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('MIT', $plugin->getPluginLicense());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh CalendarPlugin`
Expected: FAIL — class `Kanboard\Plugin\CalendarPlugin\Plugin` not found.

- [ ] **Step 3: Write minimal implementation** — `CalendarPlugin/Plugin.php`

```php
<?php

namespace Kanboard\Plugin\CalendarPlugin;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Wired in later tasks.
    }

    public function getPluginName()        { return 'CalendarPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Drag-and-drop calendar view: tasks by due date, across all projects or per project.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
```

And `CalendarPlugin/plugin.json`:

```json
{
    "name": "CalendarPlugin",
    "description": "Drag-and-drop calendar view: tasks by due date, across all projects or per project.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-plugins",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

Copy `FeatureSync/LICENSE` → `CalendarPlugin/LICENSE`. Write a short `README.md` and a `CHANGELOG.md` with an `## [1.0.0]` "Added — initial calendar plugin" stub.

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh CalendarPlugin`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add CalendarPlugin && git commit -m "feat(CalendarPlugin): plugin skeleton + metadata + PluginTest"
```

---

## Task 2: FullCalendar spike — vendor + routes + blank calendar renders (de-risks R1)

Goal: prove FullCalendar v6's global build loads buildless under our CSP and renders an (empty) month calendar in the live app, with **no CSP violations or console errors**, before any feature work.

**Files:**
- Create: `CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js` (download FullCalendar v6.1.x global build)
- Create: `CalendarPlugin/Assets/vendor/fullcalendar/VENDOR.md` (source URL, version, MIT license note)
- Create: `CalendarPlugin/Assets/js/calendar.js`
- Create: `CalendarPlugin/Assets/css/calendar.css`
- Create: `CalendarPlugin/Controller/CalendarController.php`
- Create: `CalendarPlugin/Template/calendar/index.php`
- Modify: `CalendarPlugin/Plugin.php` (register route + inject assets)
- E2E: `scratchpad/e2e/cal-spike.mjs`

**Interfaces:**
- Produces: route `GET /calendar` → `CalendarController::show`; a page containing `<div id="cal-root" data-events-url="…" data-update-url="…" data-unscheduled-url="…" data-csrf="…"><div id="calendar"></div></div>`; global `FullCalendar` available before `calendar.js` runs.

- [ ] **Step 1: Vendor FullCalendar**

Download the pinned global build (buildless, self-contained, CSS injected by the JS at runtime — allowed by `style-src 'unsafe-inline'`):

```bash
mkdir -p CalendarPlugin/Assets/vendor/fullcalendar
curl -fsSL https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js \
  -o CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js
printf 'FullCalendar v6.1.15 global build\nSource: https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js\nLicense: MIT (standard views: dayGrid, list, timeGrid)\n' \
  > CalendarPlugin/Assets/vendor/fullcalendar/VENDOR.md
```

Verify it's a non-empty JS file (~300KB) and exposes the `FullCalendar` global:

```bash
test -s CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js && grep -c "FullCalendar" CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js
```
Expected: file exists; grep count ≥ 1.

- [ ] **Step 2: Controller shell** — `CalendarPlugin/Controller/CalendarController.php`

```php
<?php

namespace Kanboard\Plugin\CalendarPlugin\Controller;

use Kanboard\Controller\BaseController;

class CalendarController extends BaseController
{
    /**
     * Global calendar page (all of the user's accessible projects).
     */
    public function show()
    {
        $this->response->html($this->helper->layout->app('CalendarPlugin:calendar/index', array(
            'title'          => t('Calendar'),
            'project_id'     => 0,
            'events_url'     => $this->helper->url->to('CalendarController', 'events', array('plugin' => 'CalendarPlugin')),
            'update_url'     => $this->helper->url->to('CalendarController', 'updateDate', array('plugin' => 'CalendarPlugin')),
            'unscheduled_url'=> $this->helper->url->to('CalendarController', 'unscheduled', array('plugin' => 'CalendarPlugin')),
            'csrf'           => $this->token->getReusableCSRFToken(),
        )));
    }
}
```

- [ ] **Step 3: Template shell** — `CalendarPlugin/Template/calendar/index.php`

```php
<div class="page-header"><h2><?= $this->text->e($title) ?></h2></div>

<div id="cal-root"
     class="cal-root"
     data-project-id="<?= (int) $project_id ?>"
     data-events-url="<?= $this->text->e($events_url) ?>"
     data-update-url="<?= $this->text->e($update_url) ?>"
     data-unscheduled-url="<?= $this->text->e($unscheduled_url) ?>"
     data-csrf="<?= $this->text->e($csrf) ?>">
    <div id="calendar" class="cal-calendar"></div>
</div>
```

- [ ] **Step 4: Minimal external JS** — `CalendarPlugin/Assets/js/calendar.js`

```javascript
/*! CalendarPlugin — external, CSP-safe. */
(function () {
    'use strict';

    function init() {
        var root = document.getElementById('cal-root');
        var host = document.getElementById('calendar');
        if (!root || !host || typeof FullCalendar === 'undefined') { return; }
        if (host.dataset.calReady) { return; }
        host.dataset.calReady = '1';

        var calendar = new FullCalendar.Calendar(host, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' }
        });
        calendar.render();
        window.__calInstance = calendar; // handy for E2E assertions
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
```

- [ ] **Step 5: Minimal CSS** — `CalendarPlugin/Assets/css/calendar.css`

```css
.cal-root { margin-top: 0.5rem; }
.cal-calendar { background: var(--card, #fff); color: var(--card-foreground, #1a1a1a); border-radius: var(--radius, 0.5rem); padding: 0.5rem; }
```

- [ ] **Step 6: Wire route + assets in `Plugin.php`** — replace `initialize()`:

```php
    public function initialize()
    {
        // Route: global calendar page.
        $this->route->addRoute('calendar', 'CalendarController', 'show', 'CalendarPlugin');

        // Assets. FullCalendar MUST be injected before calendar.js (both are
        // deferred, so document order = execution order).
        $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/vendor/fullcalendar/index.global.min.js']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/CalendarPlugin/Assets/js/calendar.js']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/CalendarPlugin/Assets/css/calendar.css']);
    }
```

- [ ] **Step 7: E2E spike** — `scratchpad/e2e/cal-spike.mjs`

```javascript
import { chromium } from 'playwright';
const BASE = 'http://localhost:8081';
const b = await chromium.launch({ channel: 'chrome', headless: true });
const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
const errs = []; p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
p.on('pageerror', e => errs.push('PAGEERROR ' + e.message));
await p.goto(BASE + '/login', { waitUntil: 'networkidle' });
await p.fill('input[name=username]', 'admin'); await p.fill('input[name=password]', 'admin');
await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
await p.goto(BASE + '/calendar', { waitUntil: 'networkidle' });
await p.waitForTimeout(800);
const r = await p.evaluate(() => ({
  fcGlobal: typeof window.FullCalendar,
  hasGrid: !!document.querySelector('.fc'),
  hasMonthCells: document.querySelectorAll('.fc-daygrid-day').length,
}));
console.log('RESULT', JSON.stringify(r));
console.log('CONSOLE_ERRORS', JSON.stringify(errs.filter(e => !/favicon/i.test(e))));
await p.screenshot({ path: new URL('./cal-spike.png', import.meta.url).pathname });
await b.close();
```

Run: `node scratchpad/e2e/cal-spike.mjs`
Expected: `fcGlobal:"object"`, `hasGrid:true`, `hasMonthCells` ≥ 28, `CONSOLE_ERRORS []` (no CSP violations). Inspect `cal-spike.png` — a month grid renders.

- [ ] **Step 8: Commit**

```bash
git add CalendarPlugin && git commit -m "feat(CalendarPlugin): vendor FullCalendar v6 + blank calendar renders (spike)"
```

---

## Task 3: CalendarQueryModel — task rows → FullCalendar events (permission-scoped, date-windowed)

**Files:**
- Create: `CalendarPlugin/Model/CalendarQueryModel.php`
- Test: `CalendarPlugin/Test/CalendarQueryModelTest.php`

**Interfaces:**
- Consumes: core `taskFinderModel`, `projectUserRoleModel`, `colorModel` from the container.
- Produces:
  - `CalendarQueryModel::getEvents(int $userId, array $filters, int $rangeStart, int $rangeEnd): array` — returns a list of event arrays:
    `['id'=>int, 'title'=>string, 'start'=>string(ISO8601), 'end'=>?string, 'allDay'=>bool, 'color'=>string(hex), 'url'=>string, 'extendedProps'=>['project'=>string,'column'=>string,'assignee'=>?string,'overdue'=>bool,'estimate'=>float]]`.
  - `CalendarQueryModel::accessibleProjectIds(int $userId): array` — `[int, …]` project ids the user may access.
  - `$filters` keys (all optional): `project_ids`(int[]), `assignee_id`(int, `-1` = me handled by caller), `category_id`(int), `column_id`(int), `hide_completed`(bool).
  - Event mapping rule (D4): `time_estimated > 0` ⇒ `allDay=false`, `start` = due timestamp (if its time-of-day is `00:00`, default to `09:00`), `end` = `start + round(estimate)` hours. Else `allDay=true`, `start` = due date (Y-m-d), `end` = null. `overdue` = `date_due < today-midnight && date_completed == 0 && is_active == 1`.
- Reference: register the model in `Plugin.php` container in Step (below) OR instantiate directly in tests via `new CalendarQueryModel($this->container)`.

- [ ] **Step 1: Write the failing test** — `CalendarPlugin/Test/CalendarQueryModelTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarQueryModelTest extends Base
{
    private function seed()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'Cal P1'));
        // admin user id is 1 in the test container; make admin a member implicitly (owner)
        $due = mktime(12, 0, 0, (int) date('n'), 15); // 15th of this month, noon
        $t1 = $taskCreation->create(array('project_id' => $pid, 'title' => 'With estimate', 'date_due' => $due, 'time_estimated' => 2));
        $t2 = $taskCreation->create(array('project_id' => $pid, 'title' => 'No estimate', 'date_due' => $due, 'time_estimated' => 0));
        return array($pid, $t1, $t2, $due);
    }

    public function testGetEventsMapsDueDateAndEstimate()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);

        $events = $model->getEvents(1, array(), $start, $end);
        $byId = array();
        foreach ($events as $e) { $byId[$e['id']] = $e; }

        $this->assertArrayHasKey($t1, $byId);
        $this->assertArrayHasKey($t2, $byId);
        // estimate>0 => timed, has end, not allDay
        $this->assertFalse($byId[$t1]['allDay']);
        $this->assertNotNull($byId[$t1]['end']);
        $this->assertEqualsWithDelta(2.0, $byId[$t1]['extendedProps']['estimate'], 0.001);
        // estimate==0 => allDay
        $this->assertTrue($byId[$t2]['allDay']);
        $this->assertNull($byId[$t2]['end']);
    }

    public function testGetEventsIsWindowedByDateRange()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        // A window in a different month must exclude both tasks.
        $start = mktime(0, 0, 0, (int) date('n') + 2, 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 3, 1);
        $events = $model->getEvents(1, array(), $start, $end);
        $this->assertCount(0, $events);
    }

    public function testAccessibleProjectIdsExcludesForeignProjects()
    {
        list($pid, $t1, $t2, $due) = $this->seed();
        $model = new CalendarQueryModel($this->container);
        $ids = $model->accessibleProjectIds(1);
        $this->assertContains($pid, $ids);
    }

    public function testEmptyAccessibleProjectsYieldsNoEvents()
    {
        // user id 999 has no projects -> in([]) must NOT leak the whole table
        $this->seed();
        $model = new CalendarQueryModel($this->container);
        $start = mktime(0, 0, 0, (int) date('n'), 1);
        $end   = mktime(0, 0, 0, (int) date('n') + 1, 1);
        $events = $model->getEvents(999, array(), $start, $end);
        $this->assertCount(0, $events);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh CalendarPlugin`
Expected: FAIL — `CalendarQueryModel` not found.

- [ ] **Step 3: Write the implementation** — `CalendarPlugin/Model/CalendarQueryModel.php`

```php
<?php

namespace Kanboard\Plugin\CalendarPlugin\Model;

use Kanboard\Core\Base;
use Kanboard\Model\TaskModel;

class CalendarQueryModel extends Base
{
    /**
     * @return int[] project ids the user may access
     */
    public function accessibleProjectIds($userId)
    {
        return array_map('intval', array_keys($this->projectUserRoleModel->getActiveProjectsByUser($userId)));
    }

    /**
     * @return array[] FullCalendar event objects
     */
    public function getEvents($userId, array $filters, $rangeStart, $rangeEnd)
    {
        $projectIds = $this->accessibleProjectIds($userId);

        // If a project filter is set, intersect with what the user may access.
        if (! empty($filters['project_ids'])) {
            $projectIds = array_values(array_intersect($projectIds, array_map('intval', $filters['project_ids'])));
        }

        // Guard: empty id list must NOT match the whole table (PicoDb in([]) footgun).
        if (empty($projectIds)) {
            return array();
        }

        $query = $this->taskFinderModel->getExtendedQuery()
            ->in(TaskModel::TABLE.'.project_id', $projectIds)
            ->neq(TaskModel::TABLE.'.date_due', 0)
            ->gte(TaskModel::TABLE.'.date_due', $rangeStart)
            ->lt(TaskModel::TABLE.'.date_due', $rangeEnd);

        if (! empty($filters['assignee_id'])) {
            $query->eq(TaskModel::TABLE.'.owner_id', (int) $filters['assignee_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->eq(TaskModel::TABLE.'.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['column_id'])) {
            $query->eq(TaskModel::TABLE.'.column_id', (int) $filters['column_id']);
        }
        if (! empty($filters['hide_completed'])) {
            $query->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN);
        }

        $rows = $query->findAll();
        $events = array();
        foreach ($rows as $row) {
            $events[] = $this->mapRowToEvent($row);
        }
        return $events;
    }

    private function mapRowToEvent(array $row)
    {
        $due       = (int) $row['date_due'];
        $estimate  = (float) $row['time_estimated'];
        $todayMidnight = mktime(0, 0, 0);
        $overdue = $due > 0 && $due < $todayMidnight && (int) $row['date_completed'] === 0 && (int) $row['is_active'] === TaskModel::STATUS_OPEN;

        if ($estimate > 0) {
            $start = $due;
            // If the due time is midnight, default the block to 09:00 for a sane timed event.
            if ((int) date('H', $due) === 0 && (int) date('i', $due) === 0) {
                $start = mktime(9, 0, 0, (int) date('n', $due), (int) date('j', $due), (int) date('Y', $due));
            }
            $end = $start + (int) round($estimate * 3600);
            $allDay = false;
            $startIso = date('c', $start);
            $endIso   = date('c', $end);
        } else {
            $allDay = true;
            $startIso = date('Y-m-d', $due);
            $endIso   = null;
        }

        $assignee = ! empty($row['assignee_name']) ? $row['assignee_name'] : (! empty($row['assignee_username']) ? $row['assignee_username'] : null);

        return array(
            'id'    => (int) $row['id'],
            'title' => $row['title'],
            'start' => $startIso,
            'end'   => $endIso,
            'allDay'=> $allDay,
            'color' => $this->colorModel->getBackgroundColor($row['color_id']),
            'url'   => $this->helper->url->to('TaskViewController', 'show', array('task_id' => (int) $row['id'], 'project_id' => (int) $row['project_id'])),
            'extendedProps' => array(
                'project'  => isset($row['project_name']) ? $row['project_name'] : '',
                'column'   => isset($row['column_title']) ? $row['column_title'] : '',
                'assignee' => $assignee,
                'overdue'  => $overdue,
                'estimate' => $estimate,
            ),
        );
    }
}
```

Note: `getExtendedQuery()` aliases the project name — confirm the exact alias while implementing (`project_name` or `projects.name`); if the alias differs, add `->columns(... 'projects.name AS project_name')` or read the actual key. Adjust the `project_name`/`column_title` keys to the real aliases and keep the test green.

- [ ] **Step 4: Register the model** — in `Plugin.php` `initialize()`, add:

```php
        $this->container['calendarQueryModel'] = function ($c) {
            return new \Kanboard\Plugin\CalendarPlugin\Model\CalendarQueryModel($c);
        };
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh CalendarPlugin`
Expected: PASS (all CalendarQueryModelTest + PluginTest).

- [ ] **Step 6: Commit**

```bash
git add CalendarPlugin && git commit -m "feat(CalendarPlugin): CalendarQueryModel (scoped, windowed, D4 mapping) + tests"
```

---

## Task 4: `events` JSON endpoint + calendar shows real tasks

**Files:**
- Modify: `CalendarPlugin/Controller/CalendarController.php` (add `events`)
- Modify: `CalendarPlugin/Plugin.php` (route)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (point FC `events` at the endpoint)
- Test: `CalendarPlugin/Test/CalendarControllerTest.php`
- E2E: `scratchpad/e2e/cal-events.mjs`

**Interfaces:**
- Consumes: `calendarQueryModel->getEvents`.
- Produces: route `GET /calendar/events` → `CalendarController::events`; JSON array of event objects. Reads `start`/`end` (ISO or Y-m-d, from FullCalendar) and filter params from GET.

- [ ] **Step 1: Write the failing test** — append to `CalendarPlugin/Test/CalendarControllerTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\CalendarPlugin\Controller\CalendarController;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarControllerTest extends Base
{
    public function testEventsReturnsScopedJson()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'CtrlP'));
        $due = mktime(12, 0, 0, (int) date('n'), 10);
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'E1', 'date_due' => $due));

        // Simulate an authenticated admin session (user id 1).
        $this->container['sessionStorage']->user = array('id' => 1, 'role' => 'app-admin');

        $events = $this->container['calendarQueryModel']->getEvents(
            1, array(),
            mktime(0, 0, 0, (int) date('n'), 1),
            mktime(0, 0, 0, (int) date('n') + 1, 1)
        );
        $ids = array_column($events, 'id');
        $this->assertContains($tid, $ids);
    }
}
```

(Controller-through-HTTP is exercised in E2E; the unit test asserts the model contract the controller delegates to. Keep this test focused on scoping.)

- [ ] **Step 2: Run test to verify it fails** — `./testing/run-plugin-tests.sh CalendarPlugin` — FAIL (controller class not found until created below; the assertion itself may pass once the model exists, but the file's `use` of the controller triggers autoload — create the controller method in Step 3, then it passes).

- [ ] **Step 3: Add the `events` action** — in `CalendarController`:

```php
    /**
     * JSON feed of FullCalendar events for the visible range + filters.
     * (calendar.getEvents)
     */
    public function events()
    {
        $userId = $this->userSession->getId();
        $start  = $this->parseDate($this->request->getStringParam('start'), strtotime('-1 month'));
        $end    = $this->parseDate($this->request->getStringParam('end'), strtotime('+2 month'));

        $filters = array(
            'project_ids'    => $this->intList($this->request->getStringParam('project_ids')),
            'assignee_id'    => $this->resolveAssignee($this->request->getStringParam('assignee_id'), $userId),
            'category_id'    => (int) $this->request->getIntegerParam('category_id'),
            'column_id'      => (int) $this->request->getIntegerParam('column_id'),
            'hide_completed' => $this->request->getStringParam('hide_completed') === '1',
        );

        $events = $this->container['calendarQueryModel']->getEvents($userId, $filters, $start, $end);
        $this->response->json($events);
    }

    private function parseDate($value, $default)
    {
        if (empty($value)) { return $default; }
        $ts = strtotime($value);
        return $ts !== false ? $ts : $default;
    }

    private function intList($value)
    {
        if (empty($value)) { return array(); }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /** '-1' (or 'me') means the current user; '' means no assignee filter. */
    private function resolveAssignee($value, $userId)
    {
        if ($value === 'me' || $value === '-1') { return (int) $userId; }
        return (int) $value;
    }
```

- [ ] **Step 4: Route** — in `Plugin.php` `initialize()`:

```php
        $this->route->addRoute('calendar/events', 'CalendarController', 'events', 'CalendarPlugin');
```

- [ ] **Step 5: Point FullCalendar at the endpoint** — in `calendar.js`, replace the `new FullCalendar.Calendar(...)` options with:

```javascript
        var eventsUrl = root.getAttribute('data-events-url');
        var calendar = new FullCalendar.Calendar(host, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' },
            events: function (info, success, failure) {
                var url = eventsUrl + (eventsUrl.indexOf('?') >= 0 ? '&' : '?') +
                    'start=' + encodeURIComponent(info.startStr) + '&end=' + encodeURIComponent(info.endStr) +
                    buildFilterQuery(root);
                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { success(data); })
                    .catch(function (e) { failure(e); });
            }
        });
```

And add a `buildFilterQuery` helper near the top of the IIFE (returns `''` for now; extended in Task 5):

```javascript
    function buildFilterQuery(root) { return ''; }
```

- [ ] **Step 6: Run unit tests** — `./testing/run-plugin-tests.sh CalendarPlugin` — PASS.

- [ ] **Step 7: E2E** — `scratchpad/e2e/cal-events.mjs` (login; create a project + task with a due date this month via the JSON API using the container's API token OR via the UI; open `/calendar`; assert an `.fc-event` with the task title is present). Minimal shape:

```javascript
import { chromium } from 'playwright';
const BASE = 'http://localhost:8081';
const b = await chromium.launch({ channel: 'chrome', headless: true });
const p = await (await b.newContext()).newPage();
const errs = []; p.on('console', m => { if (m.type()==='error') errs.push(m.text()); });
await p.goto(BASE + '/login', { waitUntil: 'networkidle' });
await p.fill('input[name=username]','admin'); await p.fill('input[name=password]','admin');
await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
// Assumes seed.sh created tasks with due dates this month; otherwise create one via UI first.
await p.goto(BASE + '/calendar', { waitUntil: 'networkidle' });
await p.waitForTimeout(1000);
const n = await p.evaluate(() => document.querySelectorAll('.fc-event').length);
console.log('EVENTS', n, 'ERRORS', JSON.stringify(errs.filter(e=>!/favicon/i.test(e))));
await p.screenshot({ path: new URL('./cal-events.png', import.meta.url).pathname });
await b.close();
```

Run: `node scratchpad/e2e/cal-events.mjs` — Expected: `EVENTS` ≥ 1 (ensure at least one seeded task has a due date in the current month first), `ERRORS []`.

- [ ] **Step 8: Commit**

```bash
git add CalendarPlugin && git commit -m "feat(CalendarPlugin): events JSON endpoint; calendar shows real tasks"
```

(E2E scripts live in the gitignored `scratchpad/` and are not committed.)

---

## Task 5: Filters (project / assignee / category / column / hide-completed) + filter bar

**Files:**
- Modify: `CalendarPlugin/Template/calendar/index.php` (filter bar controls with `data-*`)
- Modify: `CalendarPlugin/Controller/CalendarController.php` (`show` passes filter option lists)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (`buildFilterQuery` reads controls; refetch on change; sync URL)
- Test: `CalendarPlugin/Test/CalendarQueryModelTest.php` (add per-filter cases)

**Interfaces:**
- Consumes: `getEvents` `$filters` keys defined in Task 3.
- Produces: filter bar with `<select data-cal-filter="project_ids" multiple>`, `assignee_id`, `category_id`, `column_id`, and a `hide_completed` checkbox. `buildFilterQuery(root)` returns `&project_ids=1,2&assignee_id=me&…`.

- [ ] **Step 1: Add failing model tests** — append both of these to `CalendarQueryModelTest.php`:

```php
    public function testHideCompletedExcludesClosed()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $taskStatus   = new \Kanboard\Model\TaskStatusModel($this->container);
        $pid = $projectModel->create(array('name' => 'HC'));
        $due = mktime(12, 0, 0, (int) date('n'), 12);
        $open   = $taskCreation->create(array('project_id' => $pid, 'title' => 'open',   'date_due' => $due));
        $closed = $taskCreation->create(array('project_id' => $pid, 'title' => 'closed', 'date_due' => $due));
        $taskStatus->close($closed);
        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getEvents(1, array('hide_completed' => true),
            mktime(0,0,0,(int)date('n'),1), mktime(0,0,0,(int)date('n')+1,1)), 'id');
        $this->assertContains($open, $ids);
        $this->assertNotContains($closed, $ids);
    }

    public function testAssigneeFilterKeepsOnlyOwner()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $userModel    = new \Kanboard\Model\UserModel($this->container);
        $pid  = $projectModel->create(array('name' => 'AS'));
        $u2   = $userModel->create(array('username' => 'cal_u2', 'password' => 'test1234'));
        $due  = mktime(12, 0, 0, (int) date('n'), 14);
        $mine  = $taskCreation->create(array('project_id' => $pid, 'title' => 'mine',  'date_due' => $due, 'owner_id' => 1));
        $other = $taskCreation->create(array('project_id' => $pid, 'title' => 'other', 'date_due' => $due, 'owner_id' => $u2));
        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getEvents(1, array('assignee_id' => 1),
            mktime(0,0,0,(int)date('n'),1), mktime(0,0,0,(int)date('n')+1,1)), 'id');
        $this->assertContains($mine, $ids);
        $this->assertNotContains($other, $ids);
    }
```

(Project-filter intersection with accessible projects is already covered by `testEmptyAccessibleProjectsYieldsNoEvents` + the `array_intersect` in `getEvents`; the `category_id` filter uses the identical `->eq()` pattern as assignee and needs no separate test.)

- [ ] **Step 2: Run — confirm status** (`hide_completed` was implemented in Task 3, so that test should already pass; `testAssigneeFilterKeepsOnlyOwner` exercises the `owner_id` `->eq()` also added in Task 3 — it should pass too. If either fails, fix the model filter and re-run.) — `./testing/run-plugin-tests.sh CalendarPlugin`.

- [ ] **Step 3: Filter bar template** — add above `#calendar` in `index.php`:

```php
<div class="cal-filterbar" id="cal-filterbar">
    <select data-cal-filter="project_ids" multiple size="1" class="cal-filter">
        <?php foreach ($projects as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>
    <select data-cal-filter="assignee_id" class="cal-filter">
        <option value=""><?= t('All assignees') ?></option>
        <option value="me"><?= t('My tasks') ?></option>
        <?php foreach ($users as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>
    <select data-cal-filter="category_id" class="cal-filter">
        <option value=""><?= t('All categories') ?></option>
        <?php foreach ($categories as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>
    <label class="cal-filter-check"><input type="checkbox" data-cal-filter="hide_completed" value="1"> <?= t('Hide completed') ?></label>
</div>
```

- [ ] **Step 4: Controller passes option lists** — in `show()` params add: `'projects' => $this->projectUserRoleModel->getActiveProjectsByUser($this->userSession->getId())`, `'users' => $this->projectUserRoleModel->getActiveProjectsByUser(...)`-derived assignable users (use `$this->userModel->getActiveUsersList()`), `'categories' => array()` for the global page (categories are per-project — leave empty on the global page, populated on the per-project page in Task 9). Keep column filter out of the global bar (columns are per-project) — it appears on the per-project page in Task 9.

- [ ] **Step 5: `buildFilterQuery` + change handlers** — in `calendar.js` replace the stub and add a delegated change listener:

```javascript
    function buildFilterQuery(root) {
        var bar = document.getElementById('cal-filterbar');
        if (!bar) { return ''; }
        var parts = [];
        var proj = bar.querySelector('[data-cal-filter="project_ids"]');
        if (proj) {
            var ids = Array.prototype.filter.call(proj.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
            if (ids.length) { parts.push('project_ids=' + encodeURIComponent(ids.join(','))); }
        }
        ['assignee_id', 'category_id'].forEach(function (name) {
            var el = bar.querySelector('[data-cal-filter="' + name + '"]');
            if (el && el.value) { parts.push(name + '=' + encodeURIComponent(el.value)); }
        });
        var hc = bar.querySelector('[data-cal-filter="hide_completed"]');
        if (hc && hc.checked) { parts.push('hide_completed=1'); }
        return parts.length ? '&' + parts.join('&') : '';
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.closest && e.target.closest('#cal-filterbar') && window.__calInstance) {
            window.__calInstance.refetchEvents();
        }
    });
```

- [ ] **Step 6: Run tests — PASS** — `./testing/run-plugin-tests.sh CalendarPlugin`.

- [ ] **Step 7: E2E** — extend a script: select a project in the filter, assert only that project's events remain. (Add `scratchpad/e2e/cal-filters.mjs`.)

- [ ] **Step 8: Commit** — `git add CalendarPlugin && git commit -m "feat(CalendarPlugin): filters (project/assignee/category/hide-completed)"`

---

## Task 6: `updateDate` (POST) — drag an event to reschedule

**Files:**
- Modify: `CalendarPlugin/Controller/CalendarController.php` (`updateDate`)
- Modify: `CalendarPlugin/Plugin.php` (route)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (`eventDrop` → POST, revert on failure)
- Test: `CalendarPlugin/Test/CalendarControllerTest.php` (CSRF + update + cross-project reject)

**Interfaces:**
- Consumes: `taskFinderModel->getProjectId`, `projectUserRoleModel`, `taskModificationModel->update`, reusable CSRF token.
- Produces: route `POST /calendar/update` → `CalendarController::updateDate`; request body `task_id`(int) + `date_due`(Y-m-d or ISO) + `csrf_token`; JSON `{ "result": true }` or HTTP 403 / `{ "result": false, "error": … }`.

- [ ] **Step 1: Write the failing test** — append to `CalendarControllerTest.php`:

```php
    public function testUpdateDatePersistsDueDateForAccessibleTask()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $taskFinder   = new \Kanboard\Model\TaskFinderModel($this->container);
        $pid = $projectModel->create(array('name' => 'UD'));
        $tid = $taskCreation->create(array('project_id' => $pid, 'title' => 'move me', 'date_due' => mktime(12,0,0,(int)date('n'),5)));

        $newTs = mktime(0, 0, 0, (int) date('n'), 20);
        $ok = $this->container['taskModificationModel']->update(array('id' => $tid, 'date_due' => $newTs));
        $this->assertTrue($ok);

        $task = $taskFinder->getById($tid);
        $this->assertSame((int) $newTs, (int) $task['date_due']);
    }
```

(The controller wraps this with CSRF + permission checks; those paths are asserted in E2E where the real request pipeline + token exist. The unit test locks the persistence contract.)

- [ ] **Step 2: Run — FAIL/PASS check** — `./testing/run-plugin-tests.sh CalendarPlugin` (this asserts core update works; keep it as a regression anchor).

- [ ] **Step 3: Add `updateDate`** — in `CalendarController`:

```php
    /**
     * POST — reschedule a task's due date. (calendar.updateTaskDate)
     */
    public function updateDate()
    {
        $values = $this->request->getValues(); // CSRF-validated POST array

        if (! isset($values['csrf_token']) || ! $this->token->validateReusableCSRFToken($values['csrf_token'])) {
            $this->response->status(403);
            return $this->response->json(array('result' => false, 'error' => 'csrf'));
        }

        $taskId = isset($values['task_id']) ? (int) $values['task_id'] : 0;
        $dueRaw = isset($values['date_due']) ? $values['date_due'] : '';
        $projectId = $taskId > 0 ? $this->taskFinderModel->getProjectId($taskId) : 0;

        // Permission: task must exist and belong to a project the user can access.
        $accessible = array_map('intval', array_keys($this->projectUserRoleModel->getActiveProjectsByUser($this->userSession->getId())));
        if ($projectId === 0 || ! in_array($projectId, $accessible, true)) {
            $this->response->status(403);
            return $this->response->json(array('result' => false, 'error' => 'forbidden'));
        }

        $ts = is_numeric($dueRaw) ? (int) $dueRaw : (int) strtotime($dueRaw);
        if ($ts <= 0) {
            $this->response->status(400);
            return $this->response->json(array('result' => false, 'error' => 'date'));
        }

        $ok = $this->taskModificationModel->update(array('id' => $taskId, 'date_due' => $ts));
        return $this->response->json(array('result' => (bool) $ok));
    }
```

- [ ] **Step 4: Route** — in `Plugin.php`: `$this->route->addRoute('calendar/update', 'CalendarController', 'updateDate', 'CalendarPlugin');`

- [ ] **Step 5: `eventDrop` handler** — in `calendar.js`, add to the Calendar options:

```javascript
            editable: true,
            eventDrop: function (info) {
                postDate(root, info.event.id, info.event.startStr, function (ok) { if (!ok) { info.revert(); } });
            },
```

And add the helper inside the IIFE:

```javascript
    function postDate(root, taskId, dateStr, done) {
        var body = new URLSearchParams();
        body.set('task_id', taskId);
        body.set('date_due', dateStr);
        body.set('csrf_token', root.getAttribute('data-csrf'));
        fetch(root.getAttribute('data-update-url'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { result: false }; }); })
          .then(function (d) { done(!!(d && d.result)); })
          .catch(function () { done(false); });
    }
```

- [ ] **Step 6: Run unit tests — PASS** — `./testing/run-plugin-tests.sh CalendarPlugin`.

- [ ] **Step 7: E2E** — `scratchpad/e2e/cal-drag.mjs`: open `/calendar`, drag an `.fc-event` to a different day cell (use bounding boxes + `page.mouse.down/move/up`), then fetch the task via the API/DB and assert `date_due` changed. Assert no console/CSP errors and the event stays on the new day (no revert).

- [ ] **Step 8: Commit** — `git add CalendarPlugin && git commit -m "feat(CalendarPlugin): updateDate endpoint + drag-to-reschedule"`

---

## Task 7: Unscheduled sidebar + drag-to-schedule

**Files:**
- Modify: `CalendarPlugin/Controller/CalendarController.php` (`unscheduled`)
- Modify: `CalendarPlugin/Plugin.php` (route)
- Modify: `CalendarPlugin/Template/calendar/index.php` (sidebar container)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (fetch list, FullCalendar `Draggable`, `eventReceive`)
- Modify: `CalendarPlugin/Assets/css/calendar.css` (sidebar layout)
- Test: `CalendarPlugin/Test/CalendarQueryModelTest.php` (`getUnscheduled`)

**Interfaces:**
- Produces: `CalendarQueryModel::getUnscheduled(int $userId, array $filters): array` — `[['id'=>int,'title'=>string,'color'=>hex,'project'=>string], …]`, only accessible tasks with `date_due == 0` and `is_active == STATUS_OPEN`, `in([])` guarded. Route `GET /calendar/unscheduled` → `CalendarController::unscheduled` (JSON).

- [ ] **Step 1: Failing test** — append to `CalendarQueryModelTest.php`:

```php
    public function testGetUnscheduledReturnsOnlyNoDueDateAccessibleTasks()
    {
        $projectModel = new \Kanboard\Model\ProjectModel($this->container);
        $taskCreation = new \Kanboard\Model\TaskCreationModel($this->container);
        $pid = $projectModel->create(array('name' => 'US'));
        $withDue = $taskCreation->create(array('project_id' => $pid, 'title' => 'has due', 'date_due' => mktime(12,0,0,(int)date('n'),9)));
        $noDue   = $taskCreation->create(array('project_id' => $pid, 'title' => 'no due'));
        $model = new CalendarQueryModel($this->container);
        $ids = array_column($model->getUnscheduled(1, array()), 'id');
        $this->assertContains($noDue, $ids);
        $this->assertNotContains($withDue, $ids);
        // empty-access guard
        $this->assertCount(0, $model->getUnscheduled(999, array()));
    }
```

- [ ] **Step 2: Run — FAIL** — `./testing/run-plugin-tests.sh CalendarPlugin`.

- [ ] **Step 3: Implement `getUnscheduled`** — in `CalendarQueryModel`:

```php
    public function getUnscheduled($userId, array $filters)
    {
        $projectIds = $this->accessibleProjectIds($userId);
        if (! empty($filters['project_ids'])) {
            $projectIds = array_values(array_intersect($projectIds, array_map('intval', $filters['project_ids'])));
        }
        if (empty($projectIds)) { return array(); }

        $rows = $this->taskFinderModel->getExtendedQuery()
            ->in(TaskModel::TABLE.'.project_id', $projectIds)
            ->eq(TaskModel::TABLE.'.date_due', 0)
            ->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN)
            ->findAll();

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id'      => (int) $row['id'],
                'title'   => $row['title'],
                'color'   => $this->colorModel->getBackgroundColor($row['color_id']),
                'project' => isset($row['project_name']) ? $row['project_name'] : '',
            );
        }
        return $out;
    }
```

- [ ] **Step 4: `unscheduled` action + route** — controller:

```php
    public function unscheduled()
    {
        $filters = array('project_ids' => $this->intList($this->request->getStringParam('project_ids')));
        $this->response->json($this->container['calendarQueryModel']->getUnscheduled($this->userSession->getId(), $filters));
    }
```

Route: `$this->route->addRoute('calendar/unscheduled', 'CalendarController', 'unscheduled', 'CalendarPlugin');`

- [ ] **Step 5: Sidebar markup** — wrap the calendar in `index.php`:

```php
<div class="cal-layout" id="cal-layout">
    <aside class="cal-unscheduled" id="cal-unscheduled" aria-label="<?= t('Unscheduled tasks') ?>">
        <h3><?= t('Unscheduled') ?></h3>
        <div id="cal-unscheduled-list" class="cal-unscheduled-list"></div>
    </aside>
    <div id="calendar" class="cal-calendar"></div>
</div>
```

(Remove the old bare `<div id="calendar">` from Task 2.) Also add a **show/hide unscheduled** toggle to the filter bar (spec §2) — append to `#cal-filterbar` in `index.php`:

```php
    <label class="cal-filter-check"><input type="checkbox" id="cal-toggle-unscheduled" checked> <?= t('Show unscheduled') ?></label>
```

And a delegated handler in `calendar.js` (toggles a class on the layout; pure CSS hide):

```javascript
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'cal-toggle-unscheduled') {
            var layout = document.getElementById('cal-layout');
            if (layout) { layout.classList.toggle('cal-hide-unscheduled', !e.target.checked); }
        }
    });
```

CSS: `.cal-hide-unscheduled .cal-unscheduled { display: none; }` (add to Step 7 CSS).

- [ ] **Step 6: JS — render list + Draggable + eventReceive** — in `calendar.js`, after the calendar renders:

```javascript
    function loadUnscheduled(root) {
        var list = document.getElementById('cal-unscheduled-list');
        if (!list) { return; }
        fetch(root.getAttribute('data-unscheduled-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (items) {
                list.textContent = '';
                items.forEach(function (it) {
                    var el = document.createElement('div');
                    el.className = 'cal-unscheduled-item';
                    el.setAttribute('data-task-id', it.id);
                    el.style.borderLeft = '4px solid ' + it.color;
                    el.textContent = it.title;
                    list.appendChild(el);
                });
                new FullCalendar.Draggable(list, {
                    itemSelector: '.cal-unscheduled-item',
                    eventData: function (el) { return { id: el.getAttribute('data-task-id'), title: el.textContent, allDay: true }; }
                });
            });
    }
```

Add to Calendar options:

```javascript
            droppable: true,
            eventReceive: function (info) {
                var el = document.querySelector('.cal-unscheduled-item[data-task-id="' + info.event.id + '"]');
                postDate(root, info.event.id, info.event.startStr, function (ok) {
                    if (ok) { if (el) { el.parentNode.removeChild(el); } }
                    else { info.event.remove(); }
                });
            },
```

Call `loadUnscheduled(root);` right after `calendar.render();`.

- [ ] **Step 7: CSS** — add sidebar layout to `calendar.css`:

```css
.cal-layout { display: flex; gap: 1rem; align-items: flex-start; }
.cal-unscheduled { flex: 0 0 220px; background: var(--muted, #f5f5f5); border: 1px solid var(--border, #ddd); border-radius: var(--radius, 0.5rem); padding: 0.5rem; }
.cal-calendar { flex: 1 1 auto; }
.cal-unscheduled-item { background: var(--card, #fff); border: 1px solid var(--border, #ddd); border-radius: calc(var(--radius, 0.5rem) - 2px); padding: 0.35rem 0.5rem; margin-bottom: 0.4rem; cursor: grab; font-size: 0.85rem; }
```

- [ ] **Step 8: Run unit tests — PASS**, then **E2E** `scratchpad/e2e/cal-unscheduled.mjs`: assert the sidebar lists a no-due-date task, drag it onto a day, assert it schedules (API shows a due date) and leaves the sidebar.

- [ ] **Step 9: Commit** — `git add CalendarPlugin && git commit -m "feat(CalendarPlugin): unscheduled sidebar + drag-to-schedule"`

---

## Task 8: Event display (color, badges, avatar, overdue red, estimate) + click popover

**Files:**
- Modify: `CalendarPlugin/Assets/js/calendar.js` (`eventContent`, `eventClassNames`, click popover)
- Modify: `CalendarPlugin/Assets/css/calendar.css` (event chip, overdue, popover styles)
- E2E: `scratchpad/e2e/cal-display.mjs`

**Interfaces:**
- Consumes: event `extendedProps` from Task 3 (`project`, `column`, `assignee`, `overdue`, `estimate`) + `url`.

- [ ] **Step 1: Custom event rendering** — add to Calendar options:

```javascript
            eventClassNames: function (arg) { return arg.event.extendedProps.overdue ? ['cal-ev-overdue'] : []; },
            eventContent: function (arg) {
                var ep = arg.event.extendedProps;
                var wrap = document.createElement('div');
                wrap.className = 'cal-ev';
                // Assignee initials chip (spec's "assignee avatar" — initials in v1;
                // full avatar image is a later enhancement).
                if (ep.assignee) {
                    var av = document.createElement('span');
                    av.className = 'cal-ev-avatar';
                    av.textContent = initials(ep.assignee);
                    av.title = ep.assignee;
                    wrap.appendChild(av);
                }
                var title = document.createElement('span');
                title.className = 'cal-ev-title';
                title.textContent = arg.event.title;
                wrap.appendChild(title);
                if (ep.project) {
                    var proj = document.createElement('span');
                    proj.className = 'cal-ev-badge cal-ev-proj';
                    proj.textContent = ep.project;
                    wrap.appendChild(proj);
                }
                if (ep.estimate > 0) {
                    var est = document.createElement('span');
                    est.className = 'cal-ev-badge';
                    est.textContent = '~' + ep.estimate + 'h';
                    wrap.appendChild(est);
                }
                return { domNodes: [wrap] };
            },
            eventClick: function (info) { info.jsEvent.preventDefault(); showPopover(info); },
```

Note: the **column badge** and the assignee's full name are surfaced in the popover (Step 2) and the agenda/list view — month-cell chips are space-constrained, so the chip carries color + initials + title + project + estimate, and the popover carries the rest. This keeps all spec §2 display data present without overflowing a day cell.

- [ ] **Step 2: Popover (plain positioned element, NOT a modal) + `initials` helper** — add inside the IIFE:

```javascript
    function initials(name) {
        var parts = String(name).trim().split(/\s+/);
        var s = (parts[0] ? parts[0][0] : '') + (parts.length > 1 ? parts[parts.length - 1][0] : '');
        return s.toUpperCase();
    }
    function closePopover() { var ex = document.getElementById('cal-popover'); if (ex) { ex.parentNode.removeChild(ex); } }
    function showPopover(info) {
        closePopover();
        var ep = info.event.extendedProps;
        var pop = document.createElement('div');
        pop.id = 'cal-popover';
        pop.className = 'cal-popover';
        function row(label, value) { if (!value) { return; } var d = document.createElement('div'); d.className = 'cal-pop-row'; var b = document.createElement('strong'); b.textContent = label + ': '; d.appendChild(b); d.appendChild(document.createTextNode(value)); pop.appendChild(d); }
        var h = document.createElement('div'); h.className = 'cal-pop-title'; h.textContent = info.event.title; pop.appendChild(h);
        row('Project', ep.project); row('Column', ep.column); row('Assignee', ep.assignee);
        var a = document.createElement('a'); a.href = info.event.url; a.className = 'cal-pop-link'; a.textContent = 'Open task'; pop.appendChild(a);
        document.body.appendChild(pop);
        var r = info.el.getBoundingClientRect();
        pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
        pop.style.left = (window.scrollX + r.left) + 'px';
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        if (!e.target.closest('#cal-popover') && !e.target.closest('.fc-event')) { closePopover(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePopover(); } });
```

- [ ] **Step 3: CSS** — add to `calendar.css`:

```css
.cal-ev { display: flex; align-items: center; gap: 0.25rem; overflow: hidden; }
.cal-ev-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cal-ev-badge { font-size: 0.7rem; opacity: 0.85; flex: 0 0 auto; }
.cal-ev-proj { padding: 0 0.3em; border-radius: 999px; background: rgba(0,0,0,0.15); }
.cal-ev-avatar { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1.15em; height: 1.15em; border-radius: 50%; font-size: 0.62rem; font-weight: 700; background: rgba(255,255,255,0.35); color: inherit; }
.cal-ev-overdue { outline: 2px solid var(--destructive, #c0392b); outline-offset: -2px; }
.cal-popover { position: absolute; z-index: 9000; min-width: 200px; max-width: 320px; background: var(--card, #fff); color: var(--card-foreground, #1a1a1a); border: 1px solid var(--border, #ccc); border-radius: var(--radius, 0.5rem); box-shadow: 0 8px 24px rgba(0,0,0,0.25); padding: 0.6rem 0.75rem; }
.cal-pop-title { font-weight: 600; margin-bottom: 0.35rem; }
.cal-pop-row { font-size: 0.85rem; }
.cal-pop-link { display: inline-block; margin-top: 0.4rem; }
```

- [ ] **Step 4: E2E** — `scratchpad/e2e/cal-display.mjs`: seed an overdue task → assert an `.cal-ev-overdue` element exists; click an event → assert `#cal-popover` appears with the project text and a working "Open task" link; click elsewhere → popover closes; no console errors.

- [ ] **Step 5: Commit** — `git add CalendarPlugin && git commit -m "feat(CalendarPlugin): event badges, overdue highlight, click popover"`

---

## Task 9: Per-project Calendar tab + global nav link + agenda view + finalize

**Files:**
- Modify: `CalendarPlugin/Controller/CalendarController.php` (`project` action; add `column`/`category` filter options on per-project page)
- Modify: `CalendarPlugin/Plugin.php` (routes + `template:project-header:view-switcher` hook + `template:header:dropdown` hook)
- Create: `CalendarPlugin/Template/calendar/tab.php` (per-project view-switcher `<li>`)
- Create: `CalendarPlugin/Template/calendar/nav.php` (global nav `<li>` for the user dropdown)
- Modify: `CalendarPlugin/Assets/js/calendar.js` (add `listMonth` view + toolbar button; project-scoped events via `data-project-id`)
- Modify: `CalendarPlugin/Template/calendar/index.php` (agenda toolbar; project scoping)
- Modify: `CalendarPlugin/CHANGELOG.md`, `CalendarPlugin/README.md`

**Interfaces:**
- Produces: route `GET /project/:project_id/calendar` → `CalendarController::project`; view-switcher tab; global nav link; FullCalendar `right: 'dayGridMonth,listMonth'`.

- [ ] **Step 1: `project` action** — in `CalendarController`, add a `project()` that mirrors `show()` but binds `project_id` (from `getIntegerParam('project_id')`), scopes the default filter to that project (`data-project-id` → JS adds `project_ids=<id>`), and passes that project's `columns` + `categories` for the extra filters. Verify project access first (`$this->getProject()` helper throws if not a member).

```php
    public function project()
    {
        $project = $this->getProject(); // BaseController helper: loads + access-checks by project_id
        $this->response->html($this->helper->layout->app('CalendarPlugin:calendar/index', array(
            'title'           => $project['name'] . ' &gt; ' . t('Calendar'),
            'project_id'      => (int) $project['id'],
            'projects'        => array(),
            'users'           => $this->projectUserRoleModel->getAssignableUsersList($project['id']),
            'categories'      => $this->categoryModel->getList($project['id'], false),
            'events_url'      => $this->helper->url->to('CalendarController', 'events', array('plugin' => 'CalendarPlugin')),
            'update_url'      => $this->helper->url->to('CalendarController', 'updateDate', array('plugin' => 'CalendarPlugin')),
            'unscheduled_url' => $this->helper->url->to('CalendarController', 'unscheduled', array('plugin' => 'CalendarPlugin')),
            'csrf'            => $this->token->getReusableCSRFToken(),
        )));
    }
```

- [ ] **Step 2: Route** — `Plugin.php`: `$this->route->addRoute('project/:project_id/calendar', 'CalendarController', 'project', 'CalendarPlugin');`

- [ ] **Step 3: JS reads `data-project-id`** — in `buildFilterQuery`, if `root.getAttribute('data-project-id')` is a positive int and no explicit project filter is selected, append `project_ids=<id>`. So the per-project page auto-scopes.

- [ ] **Step 4: View-switcher tab** — `Template/calendar/tab.php`:

```php
<li <?= $this->app->checkMenuSelection('CalendarController', 'project') ?>>
    <?= $this->url->icon('calendar', t('Calendar'), 'CalendarController', 'project', array('plugin' => 'CalendarPlugin', 'project_id' => $project['id']), false, 'view-calendar') ?>
</li>
```

Hook in `Plugin.php`: `$this->hook->on('template:project-header:view-switcher', ['template' => 'CalendarPlugin:calendar/tab']);`

- [ ] **Step 5: Global nav link** — `Template/calendar/nav.php`:

```php
<li>
    <?= $this->url->icon('calendar', t('Calendar'), 'CalendarController', 'show', array('plugin' => 'CalendarPlugin')) ?>
</li>
```

Hook: `$this->hook->on('template:header:dropdown', ['template' => 'CalendarPlugin:calendar/nav']);`

- [ ] **Step 6: Agenda view** — in `calendar.js` set `headerToolbar.right = 'dayGridMonth,listMonth today'` (FullCalendar's global build bundles the list view). Verify the list view renders.

- [ ] **Step 7: E2E** — `scratchpad/e2e/cal-tab.mjs`: open a project board → assert a "Calendar" tab is in the view switcher → click → URL is `/project/<id>/calendar` and only that project's events show; open the user dropdown → assert a global "Calendar" link → it loads `/calendar`; toggle the agenda (`listMonth`) view → assert `.fc-list` renders. No console errors.

- [ ] **Step 8: Finalize docs** — fill `README.md` (features, screenshots note, install) and `CHANGELOG.md` `## [1.0.0]` with the full feature list. Keep version `1.0.0`.

- [ ] **Step 9: Full test run + commit**

```bash
./testing/run-plugin-tests.sh CalendarPlugin   # all green
git add CalendarPlugin && git commit -m "feat(CalendarPlugin): per-project tab + global nav + agenda view + docs (v1.0.0)"
```

---

## Task 10: Whole-plugin E2E sweep + mount into the suite Docker

**Files:**
- Modify: `testing/docker-compose.dev.yml` (add the `CalendarPlugin` bind mount)
- E2E: `scratchpad/e2e/cal-full.mjs` (end-to-end pass over all features)

- [ ] **Step 1: Mount the plugin** — add to `testing/docker-compose.dev.yml` volumes:

```yaml
      - ../CalendarPlugin:/var/www/app/plugins/CalendarPlugin
```

Then `docker restart kb-suite` (or `docker compose -f testing/docker-compose.dev.yml up -d`) and confirm `docker exec kb-suite ls /var/www/app/plugins | grep CalendarPlugin`.

- [ ] **Step 2: Full E2E** — `scratchpad/e2e/cal-full.mjs` exercises, in one run: global page renders (no CSP/console errors) → month shows seeded tasks → drag reschedules (verified) → unscheduled drag schedules → filters narrow the set → overdue red → popover + task link → per-project tab scopes → agenda view lists. Capture screenshots.

- [ ] **Step 3: Coexistence check** — confirm the other suite plugins still work (open a board with ShadcnTheme active; no route/asset/JS collisions; CalendarPlugin CSS is namespaced `cal-*`). No console errors on the board.

- [ ] **Step 4: Commit** — `git add testing/docker-compose.dev.yml && git commit -m "chore(CalendarPlugin): mount into suite Docker + full E2E sweep"`

---

## Final review (after all tasks)

Dispatch the whole-branch code review (superpowers:requesting-code-review) against `MERGE_BASE..HEAD`, then finish per superpowers:finishing-a-development-branch. Bump nothing else — version is already `1.0.0`.

## Deferred (next cycle, per spec §2 / §11)

Time-grid week/day views + resize-to-duration; inline double-click edit; create-by-clicking-empty-slot; undo drag; WIP-limit warnings; multi-project overlay coloring. Then DependencyPlugin (decorates events with blocked badges), SchedulerPlugin (nightly sweep CLI), EnhancedTaskPlugin (recurring/snooze/smart picker).
