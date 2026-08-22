# TimeReport Untracked Subtask-Time Warning — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an on-screen warning to the existing TimeReport report that detects subtask time typed onto the record but not date-tracked (so it isn't counted), showing the untracked amount per task — without blocking any Kanboard editing.

**Architecture:** A pure, unit-tested `TimeReportModel::findUntrackedSubtaskTime()` computes an `untracked` aggregate (difference between each subtask's recorded `time_spent` and the user's tracked hours for it); `report()` gathers the inputs and attaches `untracked` to the aggregate; a new `_untracked.php` partial renders a banner + affected-task list in `show.php` when there is anything to show. Read-only, stateless, on-screen HTML only (Markdown/CSV unchanged).

**Tech Stack:** PHP ≥ 8.4, Kanboard ≥ 1.2.47 plugin API (`\Kanboard\Core\Base`, PicoDb via `$this->db`), plain PHP templates + the existing `timeReport` helper, plain CSS. PHPUnit via the suite harness (`KanboardTests\units\Base`, SQLite `:memory:`).

## Global Constraints

_From the spec `docs/superpowers/specs/2026-08-21-timereport-untracked-subtask-warning-design.md`. Every task implicitly includes these._

- Read-only, **stateless**: no persisted state, no settings page, no DB migration, no change to Kanboard editing behavior.
- Buildless / CSP: plain PHP ≥ 8.4, no inline `<script>`, no inline `on*=` handlers, no new JS.
- Self-only v1: `userId = $this->userSession->getId()`, a single named variable.
- **Detection rule:** `untracked = round(subtask.time_spent, 2) − round(trackedForUser, 2)`, clamped ≥ 0; flag a subtask when `untracked ≥ 0.01`. The flagged value is the **difference** (uncounted portion), not the whole subtask.
- Detection is **not** date-filtered (manual time has no date); scoped to the selected project + current user.
- The `untracked` aggregate is separate from `breakdown`/`detail`/`total_hours` — counted totals stay unchanged.
- On-screen HTML only. **Markdown and CSV are not changed** by this feature.
- Versions stay aligned; this lands in the current pre-release **1.0.0** (no version bump unless 1.0.0 was already cut, then 1.1.0 — out of scope for this plan).
- Touch nothing outside `TimeReport/`. SDD scratch under `TimeReport/.superpowers/sdd/` only.
- Template helper is accessed as a **property**: `$this->helper->timeReport->formatHours(...)` (Kanboard's `Helper` has no `__call`).

### Running the tests

The plugin is already symlinked into the harness and has a private bootstrap (from the base plugin build). Reuse them:

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && \
vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

If the symlink/bootstrap are missing (fresh session), recreate them once:

```bash
KB=/home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src
ln -sfn /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport "$KB/plugins/TimeReport"
cat > "$KB/tests/timereport-bootstrap.php" <<'EOF'
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$dir = __DIR__ . '/../plugins';
foreach (new DirectoryIterator($dir) as $e) { if ($e->isDot() || !$e->isDir()) continue; $loader->addPsr4("Kanboard\\Plugin\\{$e->getFilename()}\\", $e->getPathname() . '/'); }
EOF
```

### Shared data shapes

**Untracked-subtask record** (input to the pure function):
```php
['subtask_id' => int, 'task_id' => int, 'time_spent' => float]   // subtask record time_spent > 0
```
**Tracked-by-subtask map:** `array<int subtask_id, float trackedHours>`
**Task-meta map:** `array<int task_id, array{reference:string, title:string}>`
**Untracked aggregate** (added to the report under `untracked`):
```php
[
  'task_count'    => int,
  'subtask_count' => int,
  'total_hours'   => float,
  'tasks'         => list<array{task_id:int, reference:string, title:string, hours:float}>,  // per-task untracked total, sorted by reference then id
]
```

---

### Task 1: `findUntrackedSubtaskTime()` — the pure detection core

**Files:**
- Modify: `TimeReport/Model/TimeReportModel.php` (add one public static method)
- Test: `TimeReport/Test/TimeReportModelTest.php` (append)

**Interfaces:**
- Consumes: the three shared maps above.
- Produces:
  ```php
  public static function findUntrackedSubtaskTime(array $subtaskRecords, array $trackedBySubtask, array $taskMeta): array
  ```
  Returns the Untracked aggregate. Per subtask: `untracked = round(time_spent,2) − round(trackedBySubtask[subtask_id] ?? 0, 2)`, clamped ≥ 0; included only when `untracked >= 0.01`. Grouped by task; per-task `hours` = sum of that task's untracked values (rounded 2dp); `task_count` = distinct flagged tasks; `subtask_count` = flagged subtasks; `total_hours` = sum (2dp). `tasks` sorted by reference (falling back to zero-padded id) then task id. Empty/none-flagged → `{task_count:0, subtask_count:0, total_hours:0.0, tasks:[]}`.

- [ ] **Step 1: Append the failing test** to `Test/TimeReportModelTest.php`

```php
    // ── Untracked subtask time (difference-based) ────────────────────────────

    public function testUntrackedFullyManualSubtaskFlaggedWithFullAmount(): void
    {
        $records = [['subtask_id' => 1, 'task_id' => 63, 'time_spent' => 1.0]];
        $tracked = []; // nothing tracked
        $meta    = [63 => ['reference' => 'ABC-63', 'title' => 'hgello']];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, $meta);

        $this->assertSame(1, $u['task_count']);
        $this->assertSame(1, $u['subtask_count']);
        $this->assertSame(1.0, $u['total_hours']);
        $this->assertSame(63, $u['tasks'][0]['task_id']);
        $this->assertSame('ABC-63', $u['tasks'][0]['reference']);
        $this->assertSame('hgello', $u['tasks'][0]['title']);
        $this->assertSame(1.0, $u['tasks'][0]['hours']);
    }

    public function testUntrackedPartialFlagsOnlyTheDifference(): void
    {
        // Recorded 1.5, tracked 0.5 → 1.0 untracked (the manual portion).
        $records = [['subtask_id' => 2, 'task_id' => 70, 'time_spent' => 1.5]];
        $tracked = [2 => 0.5];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, [70 => ['reference' => '', 'title' => 'Task 70']]);

        $this->assertSame(1, $u['subtask_count']);
        $this->assertSame(1.0, $u['total_hours']);
        $this->assertSame(1.0, $u['tasks'][0]['hours']);
    }

    public function testUntrackedFullyTrackedNotFlagged(): void
    {
        $records = [['subtask_id' => 3, 'task_id' => 80, 'time_spent' => 2.0]];
        $tracked = [3 => 2.0];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, []);
        $this->assertSame(0, $u['task_count']);
        $this->assertSame(0, $u['subtask_count']);
        $this->assertSame(0.0, $u['total_hours']);
        $this->assertSame([], $u['tasks']);
    }

    public function testUntrackedSubPrecisionDifferenceNotFlagged(): void
    {
        // 1.50 recorded, 1.499 tracked → 0.001 → rounds to 0.00 → not flagged.
        $records = [['subtask_id' => 4, 'task_id' => 81, 'time_spent' => 1.50]];
        $tracked = [4 => 1.499];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, []);
        $this->assertSame(0, $u['subtask_count']);
    }

    public function testUntrackedGroupsMultipleSubtasksPerTaskAndSorts(): void
    {
        $records = [
            ['subtask_id' => 10, 'task_id' => 90, 'time_spent' => 1.0],  // untracked 1.0
            ['subtask_id' => 11, 'task_id' => 90, 'time_spent' => 2.0],  // untracked 2.0
            ['subtask_id' => 12, 'task_id' => 85, 'time_spent' => 0.5],  // untracked 0.5
        ];
        $tracked = [];
        $meta = [90 => ['reference' => 'REF-90', 'title' => 'Ninety'], 85 => ['reference' => 'REF-85', 'title' => 'Eighty-five']];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, $meta);

        $this->assertSame(2, $u['task_count']);
        $this->assertSame(3, $u['subtask_count']);
        $this->assertSame(3.5, $u['total_hours']);
        // sorted by reference: REF-85 before REF-90
        $this->assertSame(85, $u['tasks'][0]['task_id']);
        $this->assertSame(0.5, $u['tasks'][0]['hours']);
        $this->assertSame(90, $u['tasks'][1]['task_id']);
        $this->assertSame(3.0, $u['tasks'][1]['hours']); // 1.0 + 2.0
    }

    public function testUntrackedEmptyInput(): void
    {
        $u = TimeReportModel::findUntrackedSubtaskTime([], [], []);
        $this->assertSame(['task_count' => 0, 'subtask_count' => 0, 'total_hours' => 0.0, 'tasks' => []], $u);
    }
```

- [ ] **Step 2: Run — expect FAIL** (`findUntrackedSubtaskTime` undefined)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 3: Add the method** to `Model/TimeReportModel.php` (place it after `bucket()`, before the DB-gather methods)

```php
    /**
     * Detect subtask time recorded on the subtask but not date-tracked (so it is not
     * counted in the report). Untracked = recorded time_spent − the user's tracked hours
     * for that subtask (clamped >= 0), flagged when >= 0.01h. Grouped per task.
     *
     * @param  list<array{subtask_id:int,task_id:int,time_spent:float}> $subtaskRecords
     * @param  array<int,float>                                         $trackedBySubtask
     * @param  array<int,array{reference:string,title:string}>          $taskMeta
     * @return array{task_count:int, subtask_count:int, total_hours:float, tasks: list<array{task_id:int,reference:string,title:string,hours:float}>}
     */
    public static function findUntrackedSubtaskTime(array $subtaskRecords, array $trackedBySubtask, array $taskMeta): array
    {
        $byTask = [];       // task_id => hours (untracked sum)
        $subtaskCount = 0;
        $total = 0.0;

        foreach ($subtaskRecords as $rec) {
            $recorded  = round((float) $rec['time_spent'], 2);
            $tracked   = round((float) ($trackedBySubtask[(int) $rec['subtask_id']] ?? 0.0), 2);
            $untracked = round($recorded - $tracked, 2);
            if ($untracked < 0.01) {
                continue;
            }
            $taskId = (int) $rec['task_id'];
            $byTask[$taskId] = round(($byTask[$taskId] ?? 0.0) + $untracked, 2);
            $subtaskCount++;
            $total = round($total + $untracked, 2);
        }

        $tasks = [];
        foreach ($byTask as $taskId => $hours) {
            $ref = (string) ($taskMeta[$taskId]['reference'] ?? '');
            $tasks[] = [
                'task_id'   => $taskId,
                'reference' => $ref,
                'title'     => (string) ($taskMeta[$taskId]['title'] ?? ''),
                'hours'     => (float) $hours,
                '_sort'     => $ref !== '' ? $ref : str_pad((string) $taskId, 12, '0', STR_PAD_LEFT),
            ];
        }
        usort($tasks, static fn ($a, $b) => [$a['_sort'], $a['task_id']] <=> [$b['_sort'], $b['task_id']]);
        foreach ($tasks as &$t) {
            unset($t['_sort']);
        }
        unset($t);

        return [
            'task_count'    => count($tasks),
            'subtask_count' => $subtaskCount,
            'total_hours'   => (float) $total,
            'tasks'         => $tasks,
        ];
    }
```

- [ ] **Step 4: Run — expect PASS** (Task 1 tests + all prior model tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: findUntrackedSubtaskTime — difference-based untracked subtask detection"
```

---

### Task 2: Wire `untracked` into `report()`

**Files:**
- Modify: `TimeReport/Model/TimeReportModel.php` (`report()` + new private `gatherUntrackedInputs()`)
- Test: `TimeReport/Test/TimeReportModelTest.php` (append one DB-backed integration test)

**Interfaces:**
- Consumes: `$this->db` (PicoDb), `subtaskTimeTrackingModel->getUserQuery($userId)`, `SubtaskModel`/`TaskModel` tables.
- Produces: `report()` returns the same aggregate plus `'untracked' => <aggregate>` (always present). New helper:
  ```php
  /** @return array{0: list<array{subtask_id:int,task_id:int,time_spent:float}>, 1: array<int,float>, 2: array<int,array{reference:string,title:string}>} */
  private function gatherUntrackedInputs(int $projectId, int $userId): array
  ```

- [ ] **Step 1: Append the failing integration test** to `Test/TimeReportModelTest.php`

```php
    public function testReportAttachesUntrackedAggregateFromRealData(): void
    {
        // Project the user (1) can access.
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Untracked Demo'], 1, true);

        // A task assigned to the user, and a subtask assigned to the user.
        $taskId = (int) $this->container['taskCreationModel']->create([
            'project_id' => $projectId, 'title' => 'Has manual subtask time', 'owner_id' => 1,
        ]);
        $subId = (int) $this->container['subtaskModel']->create([
            'task_id' => $taskId, 'title' => 'typed in', 'user_id' => 1,
        ]);

        // Apply the recorded values LAST via direct DB writes: creating the subtask fires
        // Kanboard's updateTaskTimeTracking(), which recalculates tasks.time_spent from the
        // (then-zero) subtask — so set both AFTER the subtask exists, and there are no
        // tracking rows to recalc them again.
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 1.25]);
        $this->container['db']->table('tasks')->eq('id', $taskId)->update([
            'owner_id' => 1, 'time_spent' => 3.0, 'is_active' => 0, 'date_completed' => strtotime('2026-03-10 12:00:00'),
        ]);

        $model = new TimeReportModel($this->container);
        $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'task', true, 1);

        $this->assertArrayHasKey('untracked', $report);
        $this->assertSame(1, $report['untracked']['task_count']);
        $this->assertSame(1, $report['untracked']['subtask_count']);
        $this->assertSame(1.25, $report['untracked']['total_hours']);
        $this->assertSame($taskId, $report['untracked']['tasks'][0]['task_id']);
        $this->assertSame(1.25, $report['untracked']['tasks'][0]['hours']);

        // Counted totals are unaffected by the untracked warning.
        $this->assertSame(3.0, $report['total_hours']);
    }

    public function testReportUntrackedEmptyWhenNothingManual(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Clean Demo'], 1, true);
        $model = new TimeReportModel($this->container);
        $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'day', false, 1);
        $this->assertSame(0, $report['untracked']['task_count']);
        $this->assertSame([], $report['untracked']['tasks']);
    }
```

- [ ] **Step 2: Run — expect FAIL** (`untracked` key missing)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml "plugins/TimeReport/Test/TimeReportModelTest.php" --filter Untracked --no-coverage
```

- [ ] **Step 3: Wire it in `report()`** — add before `return $report;`:

```php
        [$untrackedRecords, $trackedBySubtask, $untrackedTaskMeta] = $this->gatherUntrackedInputs($projectId, $userId);
        $report['untracked'] = self::findUntrackedSubtaskTime($untrackedRecords, $trackedBySubtask, $untrackedTaskMeta);
```

Add the private helper (next to the other `gather*` methods):

```php
    /**
     * Inputs for findUntrackedSubtaskTime(): the user's subtasks in the project that
     * carry a recorded time_spent, and the user's tracked hours per subtask.
     *
     * @return array{0: list<array{subtask_id:int,task_id:int,time_spent:float}>, 1: array<int,float>, 2: array<int,array{reference:string,title:string}>}
     */
    private function gatherUntrackedInputs(int $projectId, int $userId): array
    {
        $rows = $this->db->table(\Kanboard\Model\SubtaskModel::TABLE)
            ->columns(
                \Kanboard\Model\SubtaskModel::TABLE . '.id',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\SubtaskModel::TABLE . '.time_spent',
                \Kanboard\Model\TaskModel::TABLE . '.reference',
                \Kanboard\Model\TaskModel::TABLE . '.title'
            )
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id')
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->eq(\Kanboard\Model\SubtaskModel::TABLE . '.user_id', $userId)
            ->gt(\Kanboard\Model\SubtaskModel::TABLE . '.time_spent', 0)
            ->findAll();

        $records  = [];
        $taskMeta = [];
        foreach ($rows as $r) {
            $records[] = [
                'subtask_id' => (int) $r['id'],
                'task_id'    => (int) $r['task_id'],
                'time_spent' => (float) $r['time_spent'],
            ];
            $taskMeta[(int) $r['task_id']] = [
                'reference' => (string) ($r['reference'] ?? ''),
                'title'     => (string) ($r['title'] ?? ''),
            ];
        }

        // Sum the user's tracked hours per subtask (same hours math as the report).
        $trackedBySubtask = [];
        foreach ($this->subtaskTimeTrackingModel->getUserQuery($userId)->findAll() as $tt) {
            $sid       = (int) $tt['subtask_id'];
            $timeSpent = (float) $tt['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $start = (int) $tt['start'];
                $end   = (int) $tt['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }
            $trackedBySubtask[$sid] = ($trackedBySubtask[$sid] ?? 0.0) + $hours;
        }

        return [$records, $trackedBySubtask, $taskMeta];
    }
```

> `getUserQuery($userId)` selects `subtask_id`, `start`, `end`, `time_spent` (verified in `SubtaskTimeTrackingModel`). Summing across all the user's tracking rows and looking up only the subtask ids present in `$records` is correct and harmless.

- [ ] **Step 4: Run — expect PASS** (all model tests, including the two new integration tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: attach untracked subtask-time aggregate to report()"
```

---

### Task 3: Render the warning (partial + show.php + CSS) and note it in the changelog

**Files:**
- Create: `TimeReport/Template/report/_untracked.php`
- Modify: `TimeReport/Template/report/show.php` (render the partial when `untracked.task_count > 0`)
- Modify: `TimeReport/Assets/css/timereport.css` (banner styling)
- Modify: `TimeReport/CHANGELOG.md`
- Test: `TimeReport/Test/TemplateAssetsTest.php` (extend the CSP guard + assert wiring)

**Interfaces:**
- Consumes: `$report['untracked']`, the `timeReport` helper (`formatHours`), `$this->text->e`.
- Produces: on-screen banner + affected-task list; no new JS.

- [ ] **Step 1: Extend the failing guard test** in `Test/TemplateAssetsTest.php`

In `testNoInlineScriptOrHandlersInTemplates`, add `'_untracked.php'` to the file list so it reads:
```php
        foreach (['form.php', 'show.php', '_breakdown.php', '_detail.php', 'header_dropdown.php', '_untracked.php'] as $f) {
```

Then append a new test:
```php
    public function testUntrackedPartialIsGatedAndWired(): void
    {
        $partial = $this->tpl('_untracked.php');
        $this->assertStringContainsString('tr-untracked', $partial, 'partial must carry its container class');
        $this->assertStringContainsString("untracked", $partial);
        $this->assertStringContainsString("task_count", $partial, 'banner block must be gated on untracked.task_count');

        $show = $this->tpl('show.php');
        $this->assertStringContainsString('TimeReport:report/_untracked', $show, 'show must render the untracked partial');
        $this->assertStringContainsString("task_count", $show, 'show must gate the partial on untracked.task_count');
    }
```

- [ ] **Step 2: Run — expect FAIL** (`_untracked.php` missing → `file_get_contents` warning/failure, and the wiring assertions fail)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TemplateAssetsTest.php --no-coverage
```

- [ ] **Step 3: Create `Template/report/_untracked.php`**

```php
<?php
/**
 * Untracked subtask-time warning — rendered only when $report['untracked']['task_count'] > 0.
 * Advisory guidance; on-screen HTML only (not in Markdown/CSV). CSP-safe, no inline JS.
 */
$u = $report['untracked'];
?>
<div class="tr-untracked">
    <p class="tr-untracked-banner">
        <strong>&#9888; <?= t('Untracked subtask time') ?>:</strong>
        <?= t(
            '%d subtask(s) on %d task(s) have %sh of manually-entered time that is not date-tracked, so it is not counted here — log it with the subtask timer or add it to the task Time spent.',
            (int) $u['subtask_count'],
            (int) $u['task_count'],
            $this->helper->timeReport->formatHours((float) $u['total_hours'])
        ) ?>
    </p>
    <table class="table-fixed tr-untracked-list">
        <thead>
            <tr>
                <th><?= t('Ref') ?></th>
                <th><?= t('Task') ?></th>
                <th class="tr-num"><?= t('Untracked hours') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($u['tasks'] as $tk): ?>
                <tr>
                    <td><?= $this->text->e($tk['reference']) ?></td>
                    <td><?= $this->text->e($tk['title'] !== '' ? $tk['title'] : ('#' . $tk['task_id'])) ?></td>
                    <td class="tr-num"><?= $this->text->e($this->helper->timeReport->formatHours((float) $tk['hours'])) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
```

> `t()` escapes its sprintf args (verified in `Translator::translate`), so the banner needs no extra `text->e`; the identifier is static. `&#9888;` is the ⚠ entity (avoids a raw multibyte char in source).

- [ ] **Step 4: Render it from `show.php`** — insert immediately after the `</div>` that closes `.tr-summary` and before the `_breakdown` render:

```php
<?php if (! empty($report['untracked']['task_count'])): ?>
    <?= $this->render('TimeReport:report/_untracked', ['report' => $report]) ?>
<?php endif ?>
```

- [ ] **Step 5: Add CSS** to `Assets/css/timereport.css`:

```css
.tr-untracked { margin: 1em 0; padding: 0.75em 1em; border-left: 3px solid #b58900; background: rgba(181,137,0,0.08); }
.tr-untracked-banner { margin: 0 0 0.5em; }
.tr-untracked-list { margin-top: 0.5em; }
```

- [ ] **Step 6: Add a CHANGELOG note** under the `## 1.0.0` entry in `CHANGELOG.md`:

```markdown
- Warn on-screen when a subtask has manually-entered time that isn't date-tracked (and so isn't counted), showing the untracked amount per task.
```

- [ ] **Step 7: Run the template test, then the full suite — expect PASS**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && \
vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TemplateAssetsTest.php --no-coverage && \
vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```
Expected: `OK` for the template file and for the whole suite.

- [ ] **Step 8: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: on-screen untracked subtask-time warning (partial, show, css, changelog)"
```

---

## Notes for the executor

- Do **not** add settings, persistence, or any change to Kanboard's subtask editing. This is display-only.
- Do **not** touch Markdown (`toMarkdown`) or CSV (`toCsv`) — the warning is on-screen only.
- Keep the `timeReport` helper accessed as a property (`$this->helper->timeReport->...`), never a method call.
- After green tests, a live re-check is optional but easy: `docker cp` the changed files into `kb-suite` and regenerate a report for a project that has manual subtask time (e.g. the `C1ViewerTest` / demo projects used during development) to see the banner. Do not release or tag.
