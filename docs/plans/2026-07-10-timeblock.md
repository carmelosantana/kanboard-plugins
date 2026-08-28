# TimeBlock Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the standalone Kanboard `TimeBlock` plugin — give a task one planned start+end datetime (a "time block"), shown on the task page and board, and contributed to CalendarPlugin's week/day views via the shared `calendarEventSources` hook.

**Architecture:** Storage is task metadata (`timeblock_start`, `timeblock_end` as unix-ts strings) via core `TaskMetadataModel` — no DB migration. `TimeBlockModel` owns get/set/clear + the `blocksForCalendar` provider body. A `TimeBlockController` (write-ACL + CSRF gated) handles save/clear from a task-page panel. `Plugin::initialize()` registers the model, a template helper, three template hooks (panel, board badge, sitewide CSS), and the `calendarEventSources` provider closure (appended via `array_merge`, capturing `$this` for lazy model resolution). TimeBlock is independently useful without CalendarPlugin.

**Tech Stack:** PHP 8.4, Kanboard v1.2.47 core (Pimple container, PicoDb, Turbo/Mustache-style PHP templates), PHPUnit (harness: `./testing/run-plugin-tests.sh TimeBlock`). Buildless — no compile step; external assets only.

## Global Constraints

_Every task's requirements implicitly include this section._

- **PHP:** `>=8.4`. **Kanboard core:** `>=1.2.47`.
- **Buildless / CSP `default-src 'self'`:** NO inline `<script>` and NO inline event handlers. External `Assets/js` only (this plugin ships NO JS — panel uses plain POST forms + `<details>`; do not add inline scripts).
- **Escape ALL template output** with `$this->text->e(...)`. `date()`-formatted strings are safe by construction, but any task-derived text (title) MUST be escaped.
- **Write endpoints gate on a write-capable role**, not mere membership: `$this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $projectId)` → `AccessForbiddenException` otherwise. **`checkCSRFForm()` on every mutation.**
- **No network/HTTP in unit tests.** Seed metadata/tasks/projects directly through core models.
- **Calendar contract (spec §1) is verbatim:** provider signature `fn(int $userId, array $filters, int $rangeStart, int $rangeEnd): array`; event `id` MUST be the string `timeblock-<taskId>`; only return events whose start is in `[$rangeStart, $rangeEnd)`; access control is the source's job (mirror `getActiveProjectIds($userId)`); build complete events (decorators are NOT applied to source events).
- **Metadata keys:** `timeblock_start`, `timeblock_end` (unix ts stored as strings). Absent/empty either key ⇒ no block.
- **Isolation:** create/edit ONLY the `TimeBlock/` dir plus the two dev-harness files (`testing/docker-compose.dev.yml`, `testing/run-plugin-tests.sh`). Do NOT read/modify `CalendarPlugin/` or any other plugin. `git init` `TimeBlock/` locally (branch `main`, no remote). Harness edits stay UNCOMMITTED (they belong to the demoted repo).
- **Live gotcha:** plugin controller actions are reached via query-string form (`?controller=TimeBlockController&action=save&plugin=TimeBlock`). Use `$this->url->href('TimeBlockController', 'save', ['plugin' => 'TimeBlock', ...])` in templates — it produces the correct form.

---

## File Structure

All paths under `TimeBlock/`:

- `plugin.json` — manifest: name `TimeBlock`, version `1.0.0`, `recommends` CalendarPlugin `>=1.2.0`.
- `Plugin.php` — `initialize()` (model service, helper, 3 hooks, `calendarEventSources` provider) + metadata methods.
- `Model/TimeBlockModel.php` — `get`/`set`/`clear` + `blocksForCalendar` (+ protected `accessibleProjectIds`).
- `Helper/TimeBlockHelper.php` — template-facing `get`/`formatRange`/`formatBadge`.
- `Controller/TimeBlockController.php` — `save`/`clear` actions + static `parseTimes`.
- `Template/task/panel.php` — task-page panel (current block + set/edit/clear forms).
- `Template/board/badge.php` — board-card badge.
- `Assets/css/timeblock.css` — ~1KB sitewide styles for panel + badge.
- `README.md`, `CHANGELOG.md`, `LICENSE` — docs.
- `Test/PluginTest.php`, `Test/TimeBlockModelTest.php`, `Test/TimeBlockControllerTest.php` — PHPUnit.

**Reference patterns (cite, do not copy blindly):** `DependencyPlugin/Plugin.php:29` (board badge hook), `:32` (task-panel hook), `:41` (sitewide CSS rationale), `:50` (`array_merge` container idiom); `SubtaskGenerator/Controller/GeneratorController.php:46` (hasProjectAccess gate) + `:97` (checkCSRFForm); `SubtaskGenerator/Test/GeneratorTest.php:33-79` (userSession/request stubs for controller tests); `DependencyPlugin/Test/DependencyModelTest.php:11-30` (seed projects/tasks in tests); core `app/Model/MetadataModel.php` (`get`/`save`/`remove`/`exists`), `app/Model/ProjectPermissionModel.php:170` (`getActiveProjectIds`), `app/Model/ColorModel.php:191` (`getBorderColor`).

---

## Task 1: Plugin scaffold, manifest, and metadata

**Files:**
- Create: `TimeBlock/plugin.json`
- Create: `TimeBlock/Plugin.php`
- Create: `TimeBlock/LICENSE`
- Test: `TimeBlock/Test/PluginTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\TimeBlock\Plugin` with `getPluginName(): 'TimeBlock'`, `getPluginVersion(): '1.0.0'`, `getCompatibleVersion(): '>=1.2.47'`, `getPluginHomepage()`, `getPluginAuthor()`, `getPluginDescription()`, `getPluginLicense(): 'MIT'`. `initialize()` is stubbed in this task (empty body) and fleshed out in Task 6.

- [ ] **Step 1: `git init` the plugin dir**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock 2>/dev/null || mkdir -p /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git init -b main
```
Expected: `Initialized empty Git repository`.

- [ ] **Step 2: Write `plugin.json`**

```json
{
    "name": "TimeBlock",
    "description": "Give a task one planned start+end time block — shown on the task page and board, and contributed to CalendarPlugin's week/day views.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-time-block",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "recommends": [ { "plugin": "CalendarPlugin", "min_version": "1.2.0", "reason": "renders time blocks on the calendar week/day views" } ]
}
```

- [ ] **Step 3: Write `LICENSE`** — copy the MIT text from a sibling plugin verbatim, updating only the year/author line to `Copyright (c) 2026 Carmelo Santana`.

```bash
cp /home/carmelo/Projects/Kanboard/kanboard-plugins/DependencyPlugin/LICENSE /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock/LICENSE
```
(If the copied file's copyright line differs, leave it — suite consistency wins.)

- [ ] **Step 4: Write `Plugin.php` with metadata methods and a stub `initialize()`**

```php
<?php

namespace Kanboard\Plugin\TimeBlock;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Wired in Task 6: model service, template helper, hooks, calendarEventSources provider.
    }

    public function getPluginName()        { return 'TimeBlock'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Give a task one planned start+end time block — shown on the task page and board, and contributed to CalendarPlugin\'s week/day views.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-time-block'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
```

- [ ] **Step 5: Write the failing `PluginTest`**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\TimeBlock\Plugin;
use KanboardTests\units\Base;

/**
 * Smoke tests for the TimeBlock Plugin.
 *
 * Run from the Kanboard root via the plugin test harness:
 *   ./testing/run-plugin-tests.sh TimeBlock
 */
class PluginTest extends Base
{
    public function testPluginMetadata(): void
    {
        $plugin = new Plugin($this->container);

        $this->assertSame('TimeBlock', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertSame('MIT', $plugin->getPluginLicense());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertNotEmpty($plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginHomepage());
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS (1 test). If the harness reports "No Test/ directory" or "Plugin directory not found", confirm the dir + Test/ file exist. (The harness auto-creates the `kanboard-src/plugins/TimeBlock` symlink on first run only for plugins in its hard-coded list — it is NOT yet listed here, so if the symlink is missing, create it manually for now: `ln -s ../../TimeBlock testing/kanboard-src/plugins/TimeBlock`. Task 7 adds TimeBlock to the harness list permanently.)

- [ ] **Step 7: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: scaffold TimeBlock plugin (manifest + metadata + PluginTest)"
```

---

## Task 2: TimeBlockModel — get / set / clear

**Files:**
- Create: `TimeBlock/Model/TimeBlockModel.php`
- Test: `TimeBlock/Test/TimeBlockModelTest.php`

**Interfaces:**
- Consumes: core `taskMetadataModel` (`get($id,$name,$default)`, `save($id, array $values)`, `remove($id,$name)`, `exists($id,$name)`).
- Produces:
  - `TimeBlockModel::KEY_START = 'timeblock_start'`, `KEY_END = 'timeblock_end'`.
  - `get(int $taskId): ?array` → `['start'=>int, 'end'=>int]` or `null`.
  - `set(int $taskId, int $start, int $end): bool` — returns `false` when `end <= start`; else saves both keys.
  - `clear(int $taskId): bool` — removes both keys; returns `true` when no block remains.

- [ ] **Step 1: Write the failing tests** (`TimeBlock/Test/TimeBlockModelTest.php`)

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\TimeBlock\Model\TimeBlockModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class TimeBlockModelTest extends Base
{
    private function seedTask(): int
    {
        $p  = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(array('name' => 'TB'), 1); // user 1 becomes a member
        return (int) $tc->create(array('project_id' => $pid, 'title' => 'Task'));
    }

    public function testGetReturnsNullWhenNoBlock(): void
    {
        $model = new TimeBlockModel($this->container);
        $this->assertNull($model->get($this->seedTask()));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $model = new TimeBlockModel($this->container);
        $taskId = $this->seedTask();

        $this->assertTrue($model->set($taskId, 1000000000, 1000003600));
        $this->assertSame(
            array('start' => 1000000000, 'end' => 1000003600),
            $model->get($taskId)
        );
    }

    public function testSetRejectsEndNotAfterStart(): void
    {
        $model = new TimeBlockModel($this->container);
        $taskId = $this->seedTask();

        $this->assertFalse($model->set($taskId, 1000003600, 1000000000)); // end < start
        $this->assertFalse($model->set($taskId, 1000000000, 1000000000)); // end == start
        $this->assertNull($model->get($taskId));                          // nothing persisted
    }

    public function testClearRemovesBlock(): void
    {
        $model = new TimeBlockModel($this->container);
        $taskId = $this->seedTask();
        $model->set($taskId, 1000000000, 1000003600);

        $this->assertTrue($model->clear($taskId));
        $this->assertNull($model->get($taskId));
        $this->assertTrue($model->clear($taskId)); // idempotent: clearing an empty block still "succeeds"
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: FAIL — `Class "Kanboard\Plugin\TimeBlock\Model\TimeBlockModel" not found`.

- [ ] **Step 3: Write `Model/TimeBlockModel.php` (get/set/clear only)**

```php
<?php

namespace Kanboard\Plugin\TimeBlock\Model;

use Kanboard\Core\Base;

/**
 * TimeBlock model.
 *
 * Stores one planned start+end datetime per task as task metadata — distinct
 * from date_due (deadline) and date_started (actual start). Timestamps are
 * unix seconds, persisted as strings via core TaskMetadataModel.
 *
 * @package Kanboard\Plugin\TimeBlock\Model
 */
class TimeBlockModel extends Base
{
    const KEY_START = 'timeblock_start';
    const KEY_END   = 'timeblock_end';

    /**
     * Return the task's block as ['start'=>int, 'end'=>int], or null if unset.
     */
    public function get(int $taskId): ?array
    {
        $start = $this->taskMetadataModel->get($taskId, self::KEY_START, '');
        $end   = $this->taskMetadataModel->get($taskId, self::KEY_END, '');

        if ($start === '' || $end === '') {
            return null;
        }

        return array('start' => (int) $start, 'end' => (int) $end);
    }

    /**
     * Persist a block. Rejects (returns false) when end is not strictly after start.
     */
    public function set(int $taskId, int $start, int $end): bool
    {
        if ($end <= $start) {
            return false;
        }

        return $this->taskMetadataModel->save($taskId, array(
            self::KEY_START => (string) $start,
            self::KEY_END   => (string) $end,
        ));
    }

    /**
     * Remove both keys. Returns true when no block remains (idempotent).
     */
    public function clear(int $taskId): bool
    {
        if ($this->taskMetadataModel->exists($taskId, self::KEY_START)) {
            $this->taskMetadataModel->remove($taskId, self::KEY_START);
        }
        if ($this->taskMetadataModel->exists($taskId, self::KEY_END)) {
            $this->taskMetadataModel->remove($taskId, self::KEY_END);
        }

        return $this->get($taskId) === null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS (all PluginTest + 4 TimeBlockModelTest cases).

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: TimeBlockModel get/set/clear with end>start validation"
```

---

## Task 3: TimeBlockModel::blocksForCalendar — the calendarEventSources provider body

**Files:**
- Modify: `TimeBlock/Model/TimeBlockModel.php` (add `blocksForCalendar` + protected `accessibleProjectIds`)
- Test: `TimeBlock/Test/TimeBlockModelTest.php` (add provider cases)

**Interfaces:**
- Consumes: `this->db` (PicoDb), `this->projectPermissionModel->getActiveProjectIds($userId)`, `this->colorModel->getBorderColor($color_id)`, `this->helper->url->to(...)`; core `Kanboard\Model\TaskModel::TABLE` (`'tasks'`) and `TaskModel::STATUS_OPEN` (`1`); metadata table `'task_has_metadata'` (columns `task_id`, `name`, `value`).
- Produces: `blocksForCalendar(int $userId, array $filters, int $rangeStart, int $rangeEnd): array` — array of spec-§1 event arrays with keys `id` (`'timeblock-'.$taskId`), `title`, `start`, `end` (ISO8601 via `date('c', $ts)`), `allDay` (`false`), `color`, `url`, `extendedProps` (`['project'=>string, 'badges'=>[]]`).

- [ ] **Step 1: Add the failing provider tests** (append to `TimeBlockModelTest.php`)

```php
    // ── blocksForCalendar ─────────────────────────────────────────────────────

    private const R_START = 1000000000; // range start
    private const R_END   = 1000086400; // +1 day

    private function seedTaskInProject(int $ownerUserId, string $projectName = 'Cal'): array
    {
        $p  = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = (int) $p->create(array('name' => $projectName), $ownerUserId);
        $tid = (int) $tc->create(array('project_id' => $pid, 'title' => 'Blocked Task', 'color_id' => 'green'));
        return array('project_id' => $pid, 'task_id' => $tid);
    }

    public function testBlocksForCalendarReturnsNamespacedShapedEvent(): void
    {
        $model = new TimeBlockModel($this->container);
        $seed  = $this->seedTaskInProject(1);
        $start = self::R_START + 3600;
        $end   = $start + 7200;
        $model->set($seed['task_id'], $start, $end);

        $events = $model->blocksForCalendar(1, array(), self::R_START, self::R_END);

        $this->assertCount(1, $events);
        $ev = $events[0];
        $this->assertSame('timeblock-' . $seed['task_id'], $ev['id']);
        $this->assertSame('Blocked Task', $ev['title']);
        $this->assertSame(date('c', $start), $ev['start']);
        $this->assertSame(date('c', $end), $ev['end']);
        $this->assertFalse($ev['allDay']);
        $this->assertArrayHasKey('color', $ev);
        $this->assertArrayHasKey('url', $ev);
        $this->assertArrayHasKey('extendedProps', $ev);
        $this->assertArrayHasKey('badges', $ev['extendedProps']);
    }

    public function testBlocksForCalendarRangeFilters(): void
    {
        $model = new TimeBlockModel($this->container);
        $seed  = $this->seedTaskInProject(1);
        // Block starts AFTER the visible range → excluded.
        $model->set($seed['task_id'], self::R_END + 3600, self::R_END + 7200);

        $this->assertSame(array(), $model->blocksForCalendar(1, array(), self::R_START, self::R_END));
    }

    public function testBlocksForCalendarAccessFilters(): void
    {
        // Create a project owned by user 2 that user 1 is NOT a member of.
        $userModel = new \Kanboard\Model\UserModel($this->container);
        $uid2 = (int) $userModel->create(array('username' => 'u2', 'password' => 'p'));

        $model = new TimeBlockModel($this->container);
        $seed  = $this->seedTaskInProject($uid2, 'PrivateProj');
        $start = self::R_START + 3600;
        $model->set($seed['task_id'], $start, $start + 3600);

        // User 1 cannot access the project → no events.
        $this->assertSame(array(), $model->blocksForCalendar(1, array(), self::R_START, self::R_END));
        // The owning member sees it.
        $this->assertCount(1, $model->blocksForCalendar($uid2, array(), self::R_START, self::R_END));
    }

    public function testBlocksForCalendarHonorsProjectIdsFilter(): void
    {
        $model = new TimeBlockModel($this->container);
        $seed  = $this->seedTaskInProject(1, 'Wanted');
        $other = $this->seedTaskInProject(1, 'Unwanted');
        $start = self::R_START + 3600;
        $model->set($seed['task_id'], $start, $start + 3600);
        $model->set($other['task_id'], $start, $start + 3600);

        $events = $model->blocksForCalendar(1, array('project_ids' => array($seed['project_id'])), self::R_START, self::R_END);
        $this->assertCount(1, $events);
        $this->assertSame('timeblock-' . $seed['task_id'], $events[0]['id']);
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: FAIL — `Call to undefined method ...::blocksForCalendar()`.

- [ ] **Step 3: Add `blocksForCalendar` + `accessibleProjectIds` to `TimeBlockModel`**

Add `use Kanboard\Model\TaskModel;` under the namespace, then append these methods to the class:

```php
    /**
     * calendarEventSources provider body (spec §1).
     *
     * Returns FullCalendar event objects for every task the user may access
     * whose timeblock_start falls in [$rangeStart, $rangeEnd), honoring the
     * calendar $filters where meaningful. Access control is THIS source's job —
     * CalendarPlugin does not re-check.
     *
     * @param array $filters ['project_ids'=>int[], 'assignee_id'=>int, 'category_id'=>int, 'column_id'=>int, 'hide_completed'=>bool]
     */
    public function blocksForCalendar(int $userId, array $filters, int $rangeStart, int $rangeEnd): array
    {
        $projectIds = $this->accessibleProjectIds($userId);

        if (! empty($filters['project_ids'])) {
            $requested  = array_map('intval', (array) $filters['project_ids']);
            $projectIds = array_values(array_intersect($projectIds, $requested));
        }

        if (empty($projectIds)) {
            return array();
        }

        // Start-metadata rows in range, joined to their task for title/color/project.
        // Unix timestamps are 10 digits through year 2286, so a same-length string
        // compare on the stored value is order-preserving; range endpoints are cast
        // to string to match the stored representation.
        $rows = $this->db->table('task_has_metadata')
            ->columns(
                'task_has_metadata.task_id',
                'task_has_metadata.value AS start_ts',
                TaskModel::TABLE . '.title',
                TaskModel::TABLE . '.project_id',
                TaskModel::TABLE . '.color_id',
                TaskModel::TABLE . '.owner_id',
                TaskModel::TABLE . '.category_id',
                TaskModel::TABLE . '.column_id',
                TaskModel::TABLE . '.is_active'
            )
            ->join(TaskModel::TABLE, 'id', 'task_id', 'task_has_metadata')
            ->eq('task_has_metadata.name', self::KEY_START)
            ->in(TaskModel::TABLE . '.project_id', $projectIds)
            ->gte('task_has_metadata.value', (string) $rangeStart)
            ->lt('task_has_metadata.value', (string) $rangeEnd)
            ->findAll();

        if (empty($rows)) {
            return array();
        }

        // Batch-fetch the matching end values.
        $taskIds = array();
        foreach ($rows as $r) {
            $taskIds[] = (int) $r['task_id'];
        }
        $ends = array();
        foreach ($this->db->table('task_has_metadata')
                     ->columns('task_id', 'value')
                     ->eq('name', self::KEY_END)
                     ->in('task_id', $taskIds)
                     ->findAll() as $er) {
            $ends[(int) $er['task_id']] = (int) $er['value'];
        }

        $events = array();
        foreach ($rows as $r) {
            $taskId = (int) $r['task_id'];

            if (! isset($ends[$taskId])) {
                continue; // start without end ⇒ not a complete block
            }

            if (! empty($filters['assignee_id']) && (int) $r['owner_id'] !== (int) $filters['assignee_id']) {
                continue;
            }
            if (! empty($filters['category_id']) && (int) $r['category_id'] !== (int) $filters['category_id']) {
                continue;
            }
            if (! empty($filters['column_id']) && (int) $r['column_id'] !== (int) $filters['column_id']) {
                continue;
            }
            if (! empty($filters['hide_completed']) && (int) $r['is_active'] !== TaskModel::STATUS_OPEN) {
                continue;
            }

            $start = (int) $r['start_ts'];
            $end   = $ends[$taskId];

            $events[] = array(
                'id'     => 'timeblock-' . $taskId,
                'title'  => $r['title'],
                'start'  => date('c', $start),
                'end'    => date('c', $end),
                'allDay' => false,
                'color'  => $this->colorModel->getBorderColor($r['color_id']),
                'url'    => $this->helper->url->to('TaskViewController', 'show', array(
                    'task_id'    => $taskId,
                    'project_id' => (int) $r['project_id'],
                )),
                'extendedProps' => array(
                    'project' => (string) $r['project_id'],
                    'badges'  => array(),
                ),
            );
        }

        return $events;
    }

    /**
     * Project ids the user may access — mirrors CalendarQueryModel's
     * access filter (role-based active memberships).
     */
    protected function accessibleProjectIds(int $userId): array
    {
        return array_map('intval', $this->projectPermissionModel->getActiveProjectIds($userId));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS (PluginTest + 8 TimeBlockModelTest cases).

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: TimeBlockModel::blocksForCalendar (range- + access-filtered §1 provider)"
```

---

## Task 4: TimeBlockController — save / clear (write-ACL + CSRF gated)

**Files:**
- Create: `TimeBlock/Controller/TimeBlockController.php`
- Test: `TimeBlock/Test/TimeBlockControllerTest.php`

**Interfaces:**
- Consumes: `BaseController` helpers `getTask()`, `$this->helper->user->hasProjectAccess(...)`, `$this->checkCSRFForm()`, `$this->request->getValues()`, `$this->flash`, `$this->response->redirect(...)`, `$this->helper->url->to(...)`; the `timeBlockModel` container service (registered in Task 6 — the controller resolves it lazily via `$this->timeBlockModel`).
- Produces:
  - `save(): void` — 403 unless write access; CSRF check; parse `date`+`start_time`+`end_time`; `set()` or flash failure; redirect to task.
  - `clear(): void` — 403 unless write access; CSRF check; `clear()`; redirect to task.
  - `public static parseTimes(string $date, string $start, string $end): ?array` → `['start'=>int,'end'=>int]` or `null` (empty field or `end <= start`).

- [ ] **Step 1: Write the failing tests** (`TimeBlock/Test/TimeBlockControllerTest.php`)

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\TimeBlock\Controller\TimeBlockController;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class TimeBlockControllerTest extends Base
{
    // ── Pure parse logic (no HTTP) ────────────────────────────────────────────

    public function testParseTimesValid(): void
    {
        $parsed = TimeBlockController::parseTimes('2026-07-14', '14:00', '16:00');
        $this->assertNotNull($parsed);
        $this->assertSame(strtotime('2026-07-14 14:00'), $parsed['start']);
        $this->assertSame(strtotime('2026-07-14 16:00'), $parsed['end']);
        $this->assertGreaterThan($parsed['start'], $parsed['end']);
    }

    public function testParseTimesRejectsEmptyFields(): void
    {
        $this->assertNull(TimeBlockController::parseTimes('', '14:00', '16:00'));
        $this->assertNull(TimeBlockController::parseTimes('2026-07-14', '', '16:00'));
        $this->assertNull(TimeBlockController::parseTimes('2026-07-14', '14:00', ''));
    }

    public function testParseTimesRejectsEndNotAfterStart(): void
    {
        $this->assertNull(TimeBlockController::parseTimes('2026-07-14', '16:00', '14:00'));
        $this->assertNull(TimeBlockController::parseTimes('2026-07-14', '14:00', '14:00'));
    }

    // ── ACL gate ──────────────────────────────────────────────────────────────

    private function stubNonAdminUser(int $userId): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs(array($this->container))
            ->onlyMethods(array('isAdmin', 'getId'))
            ->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(false);
        $this->container['userSession']->method('getId')->willReturn($userId);
    }

    private function stubTaskIdRequest(int $taskId): void
    {
        $this->container['request'] = $this
            ->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs(array($this->container))
            ->onlyMethods(array('getIntegerParam'))
            ->getMock();
        $this->container['request']
            ->method('getIntegerParam')
            ->willReturnCallback(function (string $param) use ($taskId) {
                return $param === 'task_id' ? $taskId : 0;
            });
    }

    public function testSaveForbiddenForUserWithoutAccess(): void
    {
        // Task lives in a project owned by user 1; user 2 has no role on it.
        $p   = new ProjectModel($this->container);
        $tc  = new TaskCreationModel($this->container);
        $pid = (int) $p->create(array('name' => 'Owned'), 1);
        $tid = (int) $tc->create(array('project_id' => $pid, 'title' => 'T'));

        $this->stubNonAdminUser(2);
        $this->stubTaskIdRequest($tid);

        $controller = new TimeBlockController($this->container);

        $this->expectException(AccessForbiddenException::class);
        $controller->save();
    }
}
```

- [ ] **Step 2: Run to verify the tests fail**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: FAIL — `Class "...TimeBlockController" not found`.

- [ ] **Step 3: Write `Controller/TimeBlockController.php`**

```php
<?php

namespace Kanboard\Plugin\TimeBlock\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

/**
 * TimeBlock controller.
 *
 * save()  — parse a date + start/end time into two unix timestamps and persist
 *           the block. Write-ACL + CSRF gated.
 * clear() — remove the task's block. Write-ACL + CSRF gated.
 *
 * @package Kanboard\Plugin\TimeBlock\Controller
 * @author  Carmelo Santana
 */
class TimeBlockController extends BaseController
{
    public function save(): void
    {
        $task = $this->getTask();

        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();

        $values = $this->request->getValues();
        $parsed = self::parseTimes(
            isset($values['date'])       ? (string) $values['date']       : '',
            isset($values['start_time']) ? (string) $values['start_time'] : '',
            isset($values['end_time'])   ? (string) $values['end_time']   : ''
        );

        $taskId = (int) $task['id'];

        if ($parsed === null) {
            $this->flash->failure(t('Invalid time block: fill every field and make the end time later than the start time.'));
        } elseif ($this->timeBlockModel->set($taskId, $parsed['start'], $parsed['end'])) {
            $this->flash->success(t('Time block saved.'));
        } else {
            $this->flash->failure(t('Unable to save the time block.'));
        }

        $this->redirectToTask($taskId, (int) $task['project_id']);
    }

    public function clear(): void
    {
        $task = $this->getTask();

        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();

        $taskId = (int) $task['id'];
        $this->timeBlockModel->clear($taskId);
        $this->flash->success(t('Time block cleared.'));

        $this->redirectToTask($taskId, (int) $task['project_id']);
    }

    /**
     * Compose a date + start time + end time into two unix timestamps.
     * Returns null when any field is empty or end is not strictly after start.
     *
     * Static + HTTP-free so it is unit-testable without a request.
     */
    public static function parseTimes(string $date, string $start, string $end): ?array
    {
        $date  = trim($date);
        $start = trim($start);
        $end   = trim($end);

        if ($date === '' || $start === '' || $end === '') {
            return null;
        }

        $startTs = strtotime($date . ' ' . $start);
        $endTs   = strtotime($date . ' ' . $end);

        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return null;
        }

        return array('start' => $startTs, 'end' => $endTs);
    }

    private function redirectToTask(int $taskId, int $projectId): void
    {
        $this->response->redirect($this->helper->url->to('TaskViewController', 'show', array(
            'task_id'    => $taskId,
            'project_id' => $projectId,
        )));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS (parse cases + ACL-forbidden case). The forbidden test exercises the `hasProjectAccess` gate for a non-admin user with no role.

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: TimeBlockController save/clear (write-ACL + CSRF) + parseTimes"
```

---

## Task 5: Template helper, task panel, board badge, sitewide CSS

**Files:**
- Create: `TimeBlock/Helper/TimeBlockHelper.php`
- Create: `TimeBlock/Template/task/panel.php`
- Create: `TimeBlock/Template/board/badge.php`
- Create: `TimeBlock/Assets/css/timeblock.css`
- Test: `TimeBlock/Test/PluginTest.php` (add template-file + helper assertions)

**Interfaces:**
- Consumes: `timeBlockModel` container service (via `$this->timeBlockModel`), registered as helper `timeBlock` in Task 6.
- Produces:
  - `TimeBlockHelper::get(int $taskId): ?array` (delegates to model),
  - `TimeBlockHelper::formatRange(array $block): string` → e.g. `"Tue 14 Jul, 14:00–16:00"`,
  - `TimeBlockHelper::formatBadge(array $block): string` → e.g. `"Tue 14:00"`.
  - Templates referenced by hooks as `TimeBlock:task/panel` and `TimeBlock:board/badge`; CSS at `plugins/TimeBlock/Assets/css/timeblock.css`.

- [ ] **Step 1: Write `Helper/TimeBlockHelper.php`**

```php
<?php

namespace Kanboard\Plugin\TimeBlock\Helper;

use Kanboard\Core\Base;

/**
 * Template-facing helper for TimeBlock.
 *
 * Exposes the current block and human-readable formatting to board/task
 * templates via $this->timeBlock->... (registered in Plugin::initialize()).
 *
 * @package Kanboard\Plugin\TimeBlock\Helper
 */
class TimeBlockHelper extends Base
{
    /** Current block for a task, or null. */
    public function get(int $taskId): ?array
    {
        return $this->timeBlockModel->get($taskId);
    }

    /** Full range label, e.g. "Tue 14 Jul, 14:00–16:00" (or spanning days). */
    public function formatRange(array $block): string
    {
        $sameDay = date('Y-m-d', $block['start']) === date('Y-m-d', $block['end']);
        $start   = date('D j M, H:i', $block['start']);
        $end     = $sameDay ? date('H:i', $block['end']) : date('D j M, H:i', $block['end']);

        return $start . '–' . $end;
    }

    /** Compact badge label, e.g. "Tue 14:00". */
    public function formatBadge(array $block): string
    {
        return date('D H:i', $block['start']);
    }
}
```

- [ ] **Step 2: Write `Template/task/panel.php`**

```php
<?php
/**
 * Task-page TimeBlock panel.
 *
 * Attached to `template:task:show:before-internal-links`; rendered with
 * $task and $project in scope. Shows the current block (if any) plus a
 * Set/Edit form and a Clear form. No inline <script> — CSP-safe.
 */
$tb_block = $this->timeBlock->get($task['id']);
?>
<div class="accordion-section timeblock-panel">
    <div class="accordion-title"><?= t('Time block') ?></div>
    <div class="accordion-content">
        <?php if ($tb_block !== null): ?>
            <p class="tb-current">🗓 <?= $this->text->e($this->timeBlock->formatRange($tb_block)) ?></p>
        <?php else: ?>
            <p class="tb-empty"><?= t('No time block set.') ?></p>
        <?php endif ?>

        <details class="tb-edit">
            <summary><?= $tb_block === null ? t('Set time block') : t('Edit time block') ?></summary>
            <form method="post"
                  class="tb-form"
                  action="<?= $this->url->href('TimeBlockController', 'save', array('plugin' => 'TimeBlock', 'task_id' => $task['id'])) ?>">
                <?= $this->form->csrf() ?>
                <?= $this->form->label(t('Date'), 'date') ?>
                <input type="date" name="date" required
                       value="<?= $tb_block !== null ? date('Y-m-d', $tb_block['start']) : '' ?>">
                <?= $this->form->label(t('Start time'), 'start_time') ?>
                <input type="time" name="start_time" required
                       value="<?= $tb_block !== null ? date('H:i', $tb_block['start']) : '' ?>">
                <?= $this->form->label(t('End time'), 'end_time') ?>
                <input type="time" name="end_time" required
                       value="<?= $tb_block !== null ? date('H:i', $tb_block['end']) : '' ?>">
                <div class="tb-actions">
                    <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
                </div>
            </form>
        </details>

        <?php if ($tb_block !== null): ?>
            <form method="post"
                  class="tb-clear-form"
                  action="<?= $this->url->href('TimeBlockController', 'clear', array('plugin' => 'TimeBlock', 'task_id' => $task['id'])) ?>">
                <?= $this->form->csrf() ?>
                <button type="submit" class="btn btn-red"><?= t('Clear') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>
```

- [ ] **Step 3: Write `Template/board/badge.php`**

```php
<?php
/**
 * Board-card TimeBlock badge.
 *
 * Attached to `template:board:private:task:before-title`; rendered with
 * $task in scope. Emits only text/attributes — CSP-safe.
 */
$tb_block = $this->timeBlock->get($task['id']);
if ($tb_block !== null): ?>
<span class="tb-badge"
      title="<?= $this->text->e(t('Time block: %s', $this->timeBlock->formatRange($tb_block))) ?>">🗓 <?= $this->text->e($this->timeBlock->formatBadge($tb_block)) ?></span>
<?php endif ?>
```

- [ ] **Step 4: Write `Assets/css/timeblock.css`** (~1KB — panel + badge)

```css
/* TimeBlock — sitewide styles for the task panel and board badge.
   Injected via template:layout:css (mirrors DependencyPlugin's ~1KB rationale:
   too small to justify a route allowlist; badge renders on board/search too). */

.timeblock-panel .tb-current {
    font-weight: 600;
    margin: 4px 0;
}

.timeblock-panel .tb-empty {
    color: #999;
    margin: 4px 0;
}

.timeblock-panel .tb-edit {
    margin: 6px 0;
}

.timeblock-panel .tb-edit > summary {
    cursor: pointer;
    color: #36c;
}

.timeblock-panel .tb-form label {
    display: block;
    margin-top: 6px;
    font-size: 0.9em;
}

.timeblock-panel .tb-form input[type="date"],
.timeblock-panel .tb-form input[type="time"] {
    width: 100%;
    max-width: 220px;
}

.timeblock-panel .tb-actions,
.timeblock-panel .tb-clear-form {
    margin-top: 8px;
}

.tb-badge {
    display: inline-block;
    font-size: 0.8em;
    line-height: 1.4;
    padding: 0 4px;
    margin-right: 4px;
    border-radius: 3px;
    background: #eef3fb;
    color: #36c;
    white-space: nowrap;
}
```

- [ ] **Step 5: Add template/helper smoke assertions to `PluginTest`**

```php
    public function testTemplateAndAssetFilesExist(): void
    {
        $base = dirname(__DIR__);
        $this->assertFileExists($base . '/Template/task/panel.php');
        $this->assertFileExists($base . '/Template/board/badge.php');
        $this->assertFileExists($base . '/Assets/css/timeblock.css');
    }

    public function testHelperFormatsRangeAndBadge(): void
    {
        $helper = new \Kanboard\Plugin\TimeBlock\Helper\TimeBlockHelper($this->container);
        $block  = array('start' => strtotime('2026-07-14 14:00'), 'end' => strtotime('2026-07-14 16:00'));

        $this->assertStringContainsString('14:00', $helper->formatRange($block));
        $this->assertStringContainsString('16:00', $helper->formatRange($block));
        $this->assertStringContainsString('14:00', $helper->formatBadge($block));
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS (new file-exists + helper-format cases).

- [ ] **Step 7: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: TimeBlock helper + task panel + board badge + sitewide CSS"
```

---

## Task 6: Wire Plugin::initialize() — model service, helper, hooks, calendarEventSources provider

**Files:**
- Modify: `TimeBlock/Plugin.php` (fill in `initialize()`)
- Test: `TimeBlock/Test/PluginTest.php` (add init + provider-registration assertions)

**Interfaces:**
- Consumes: `$this->container` (Pimple), `$this->helper->register(...)`, `$this->hook->on(...)`, `TimeBlockModel`, `TimeBlockHelper`.
- Produces: after `initialize()`:
  - `container['timeBlockModel']` resolves to a `TimeBlockModel`.
  - helper `timeBlock` registered.
  - hooks: `template:board:private:task:before-title` → `TimeBlock:board/badge`; `template:task:show:before-internal-links` → `TimeBlock:task/panel`; `template:layout:css` → `plugins/TimeBlock/Assets/css/timeblock.css`.
  - `container['calendarEventSources']` is an array containing a callable with signature `fn(int,array,int,int): array` returning `blocksForCalendar(...)`, appended via `array_merge` (never overwriting an existing key).

- [ ] **Step 1: Add the failing init/provider tests to `PluginTest`**

```php
    public function testInitializeRegistersModelAndCalendarSource(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $this->assertInstanceOf(
            \Kanboard\Plugin\TimeBlock\Model\TimeBlockModel::class,
            $this->container['timeBlockModel']
        );

        $this->assertArrayHasKey('calendarEventSources', $this->container);
        $this->assertIsArray($this->container['calendarEventSources']);
        $this->assertNotEmpty($this->container['calendarEventSources']);
        $this->assertIsCallable($this->container['calendarEventSources'][0]);
    }

    public function testCalendarSourceAppendsAndDoesNotOverwrite(): void
    {
        // Simulate another plugin having already registered a source.
        $sentinel = function (int $u, array $f, int $s, int $e): array { return array(); };
        $this->container['calendarEventSources'] = array($sentinel);

        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $sources = $this->container['calendarEventSources'];
        $this->assertCount(2, $sources);
        $this->assertSame($sentinel, $sources[0]); // pre-existing source preserved
    }

    public function testCalendarSourceReturnsNamespacedEvent(): void
    {
        // A consumer (like CalendarPlugin) would invoke the registered source.
        $plugin = new Plugin($this->container);
        $plugin->initialize();

        $p  = new \Kanboard\Model\ProjectModel($this->container);
        $tc = new \Kanboard\Model\TaskCreationModel($this->container);
        $pid = (int) $p->create(array('name' => 'CalSrc'), 1);
        $tid = (int) $tc->create(array('project_id' => $pid, 'title' => 'Blocked'));

        $start = 1000000000 + 3600;
        $this->container['timeBlockModel']->set($tid, $start, $start + 3600);

        $source = $this->container['calendarEventSources'][0];
        $events = call_user_func($source, 1, array(), 1000000000, 1000086400);

        $this->assertCount(1, $events);
        $this->assertSame('timeblock-' . $tid, $events[0]['id']);
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: FAIL — `timeBlockModel` not in container / `calendarEventSources` missing (stub `initialize()` is still empty).

- [ ] **Step 3: Fill in `Plugin.php` `initialize()` and add imports**

Replace the class body of `TimeBlock/Plugin.php`:

```php
<?php

namespace Kanboard\Plugin\TimeBlock;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\TimeBlock\Helper\TimeBlockHelper;
use Kanboard\Plugin\TimeBlock\Model\TimeBlockModel;

class Plugin extends Base
{
    public function initialize()
    {
        // Model service — resolved lazily by the controller, helper, and the
        // calendar source closure below.
        $this->container['timeBlockModel'] = function ($c) {
            return new TimeBlockModel($c);
        };

        // Template helper: exposes get()/formatRange()/formatBadge() to templates.
        $this->helper->register('timeBlock', TimeBlockHelper::class);

        // Board card badge: small time-block chip before each card title.
        $this->hook->on('template:board:private:task:before-title', array('template' => 'TimeBlock:board/badge'));

        // Task page panel: set/edit/clear the block.
        $this->hook->on('template:task:show:before-internal-links', array('template' => 'TimeBlock:task/panel'));

        // Inject ~1KB timeblock.css sitewide (same rationale as DependencyPlugin,
        // Plugin.php:41): too small to justify a route allowlist, and the badge
        // renders on board/search pages, not just the task view.
        $this->hook->on('template:layout:css', array('template' => 'plugins/TimeBlock/Assets/css/timeblock.css'));

        // Calendar integration: contribute time-block events to CalendarPlugin's
        // generic `calendarEventSources` extension point (spec §1, CalendarPlugin
        // >= 1.2.0). Append rather than overwrite, since other suite plugins may
        // also register sources. The closure captures $this so `timeBlockModel`
        // resolves lazily, whenever CalendarQueryModel::getEvents() invokes it.
        $this->container['calendarEventSources'] = array_merge(
            isset($this->container['calendarEventSources']) ? $this->container['calendarEventSources'] : array(),
            array(function (int $userId, array $filters, int $rangeStart, int $rangeEnd): array {
                return $this->timeBlockModel->blocksForCalendar($userId, $filters, $rangeStart, $rangeEnd);
            })
        );
    }

    public function getPluginName()        { return 'TimeBlock'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Give a task one planned start+end time block — shown on the task page and board, and contributed to CalendarPlugin\'s week/day views.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-time-block'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
```

- [ ] **Step 4: Run the full suite to verify it passes**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh TimeBlock`
Expected: PASS — every PluginTest, TimeBlockModelTest, and TimeBlockControllerTest case green.

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "feat: wire Plugin::initialize (model, helper, hooks, calendarEventSources provider)"
```

---

## Task 7: Docs, dev-harness wiring, and whole-branch review

**Files:**
- Create: `TimeBlock/README.md`
- Create: `TimeBlock/CHANGELOG.md`
- Modify (UNCOMMITTED, demoted repo): `testing/docker-compose.dev.yml`, `testing/run-plugin-tests.sh`

**Interfaces:**
- Consumes: everything built in Tasks 1–6.
- Produces: user-facing docs; TimeBlock mounted + listed in the dev harness so `./testing/run-plugin-tests.sh TimeBlock` runs without manual symlinking.

- [ ] **Step 1: Write `README.md`**

Include: what a time block is (planned start+end, distinct from `date_due`/`date_started`); the task-page panel + board badge; metadata keys (`timeblock_start`/`timeblock_end`); the CalendarPlugin `>=1.2.0` `recommends` integration (contributes `timeblock-<id>` events to week/day views via `calendarEventSources`); install; non-goals (no multiple blocks per task, no overlap warnings, no all-day blocks, no calendar drag/resize, no recurring blocks). Keep it consistent in tone with `DependencyPlugin/README.md`.

- [ ] **Step 2: Write `CHANGELOG.md`**

```markdown
# Changelog

## 1.0.0 — 2026-07-10

- Initial release.
- One planned start+end time block per task, stored as task metadata
  (`timeblock_start` / `timeblock_end`) — distinct from `date_due` and
  `date_started`.
- Task-page panel to set / edit / clear the block (write-ACL + CSRF gated).
- Board-card badge showing the block start.
- Contributes `timeblock-<id>` events to CalendarPlugin's week/day views via
  the shared `calendarEventSources` hook (recommends CalendarPlugin >= 1.2.0).
```

- [ ] **Step 3: Commit the plugin docs**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "docs: TimeBlock README + CHANGELOG (v1.0.0)"
```

- [ ] **Step 4: Wire the dev harness (leave UNCOMMITTED)**

Add the mount to `testing/docker-compose.dev.yml` under `volumes:` (alongside the other `../Plugin:/var/www/app/plugins/Plugin` lines):

```yaml
      - ../TimeBlock:/var/www/app/plugins/TimeBlock
```

In `testing/run-plugin-tests.sh`, add `TimeBlock` to BOTH plugin lists — the usage-help line (~line 34) and the symlink `for p in ...` loop (~line 78):

- Line ~34: `echo "Available plugins: AiConnector  BulkProjectDelete  CalendarPlugin  DependencyPlugin  FeatureSync  ModMenu  SchedulerPlugin  ShadcnTheme  SubtaskGenerator  TimeBlock"`
- Line ~78: `for p in AiConnector BulkProjectDelete CalendarPlugin DependencyPlugin FeatureSync ModMenu SchedulerPlugin ShadcnTheme SubtaskGenerator TimeBlock; do`

Do NOT `git add` these in the demoted repo — the orchestrator commits them. (They are also gitignored from the `TimeBlock/` repo since they live outside it.)

- [ ] **Step 5: Verify the harness runs TimeBlock without manual symlinking**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins
rm -f testing/kanboard-src/plugins/TimeBlock   # drop any manual symlink from Task 1
./testing/run-plugin-tests.sh TimeBlock
```
Expected: the script prints `Created symlink: ...plugins/TimeBlock -> ../../TimeBlock` then runs all tests green.

- [ ] **Step 6: Whole-branch review**

Use `superpowers:requesting-code-review` over the full `TimeBlock/` commit range (`git -C TimeBlock log --oneline`). Confirm: escaping on all task-derived output; write endpoints gate on `hasProjectAccess(...'edit'...)` + `checkCSRFForm()`; provider events are namespaced (`timeblock-<id>`), range-filtered `[start,end)`, and access-filtered; no inline `<script>`; `end > start` enforced in both model and parse. Address any Critical/Major before hand-back.

- [ ] **Step 7: Live smoke on :8081 (best-effort — shared stack)**

Per the task's DoD: set a block on a task via the panel (confirm write-ACL + CSRF), confirm it persists and shows on the task page and as a board badge. If the stack is busy/mid-recreate (the parallel CalendarPlugin agent shares it), rely on unit tests + review and note that the authoritative joint E2E is the orchestrator's. Capture evidence (curl transcript or screenshot path) if run. Use the query-string form for the controller (`?controller=TimeBlockController&action=save&plugin=TimeBlock`); Kanboard curl login: GET `/login` → post `csrf_token` to `/login/check` (admin/admin).

- [ ] **Step 8: Final commit (if review produced changes)**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeBlock
git add -A
git commit -m "chore: address whole-branch review"
```

---

## Self-Review (against spec)

**Spec coverage:**
- §1 contract (container key, callable signature, event shape, namespaced id, `[start,end)` range, access-control-is-source's-job, decorators-not-applied) → Task 3 (`blocksForCalendar`) + Task 6 (registration via `array_merge`). ✅
- §2 storage (metadata keys, absent⇒no block) → Task 2. ✅
- §2 Model API (`get`/`set`/`clear`+`end>start`, `blocksForCalendar`) → Tasks 2–3. ✅
- §2 surfaces (task panel, board badge via the two DependencyPlugin hooks, ~1KB sitewide CSS, calendar source) → Tasks 5–6. ✅
- §2 controller (`save`/`clear`, `hasProjectAccess` edit gate, `checkCSRFForm`, parse date+start+end, reject `end<=start`, redirect+flash) → Task 4. ✅
- §2 plugin.json (name/version/floors/homepage/`recommends` CalendarPlugin >=1.2.0) → Task 1. ✅
- §2 tests (model get/set/clear+validation, `blocksForCalendar` shape/namespacing/range/access, controller access+CSRF, provider §1-shaped, PluginTest version+hooks) → Tasks 2–6. ✅
- §4 harness wiring (compose mount + both run-plugin-tests lists, uncommitted) → Task 7. ✅
- Non-goals — no multiple blocks, overlap, all-day, drag/resize, recurring — respected (single block, timed only, render-only source). ✅

**Type consistency:** `get()` returns `['start'=>int,'end'=>int]|null` everywhere; `set(int,int,int):bool`; `clear(int):bool`; `blocksForCalendar(int,array,int,int):array`; `parseTimes(string,string,string):?array`; helper `get/formatRange/formatBadge`; container service key `timeBlockModel`; event id `'timeblock-'.$taskId` — consistent across Tasks 2–6.

**Placeholder scan:** none — every code step ships complete code; no TODO/TBD/"handle edge cases".

**Note on CSRF test depth:** the controller's `checkCSRFForm()` gate is verified structurally (present after the ACL gate in `save`/`clear`) and by the forbidden-access test; a full positive-CSRF HTTP round-trip is out of scope for DB-less unit tests and is covered by the live smoke (Task 7 Step 7) + whole-branch review.
