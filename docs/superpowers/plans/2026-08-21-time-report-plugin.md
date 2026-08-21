# TimeReport Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the buildless Kanboard `TimeReport` plugin — a self-only consultant hours report over one project and a date range, delivered as on-screen HTML, Copy-as-Markdown, and CSV export, with an optional AiConnector narrative summary.

**Architecture:** A pure-aggregation `TimeReportModel` computes a deduped hours union (subtask time entries ∪ task-level `time_spent`) then buckets it by day/week/task/total; a `TimeReportHelper` renders that one aggregate to hours/Markdown/CSV; a controller drives three surfaces from a single computation; `AiGate` + `AiSummaryModel` add an optional, degradable AI summary. No DB migration, no persisted state, no build step.

**Tech Stack:** PHP ≥ 8.4, Kanboard ≥ 1.2.47 plugin API (Pimple container, `\Kanboard\Core\Base`, `\Kanboard\Controller\BaseController`), vanilla JS + jQuery + global `KB`, plain CSS. PHPUnit via the suite dev harness (`KanboardTests\units\Base`, SQLite `:memory:`). AiConnector `ProviderRegistry` (optional).

## Global Constraints

_Every task's requirements implicitly include this section. Values are verbatim from the spec._

- **Plugin location:** `/home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport/` (repo root = plugin, its own git repo, gitignored by the monorepo). Touch nothing outside it. All SDD scratch under `TimeReport/.superpowers/sdd/` — never a shared/top-level `.superpowers/`.
- **Versions must agree at `1.0.0`:** `plugin.json.version`, `Plugin.php::getPluginVersion()`, and the intended tag `v1.0.0`.
- **Compat:** `kanboard_version >= 1.2.47`, `php_version >= 8.4`, license MIT, author "Carmelo Santana", homepage `https://github.com/carmelosantana/kanboard-time-report`.
- **Dependency shape (get exactly right):** `recommends` is an ARRAY of objects with a BARE `min_version`. AiConnector is **recommends, never requires**:
  ```json
  "recommends": [
      { "plugin": "AiConnector", "min_version": "1.0.0", "reason": "adds an AI narrative summary of the completed work (the report works fully without it)" }
  ]
  ```
  Never an object-map; never a `">="` prefix on `min_version` — the wrong shape is silently dropped by ModMenu.
- **AiConnector has no container service:** instantiate `new ProviderRegistry($this->container)` and gate every use behind `AiGate::isReady()`. Primary call: `ProviderRegistry::structured($messages, json_encode($schema), $profileId)`.
- **Buildless / CSP:** no bundler/npm build; what is committed ships. No inline `<script>` or inline handlers — the Copy-as-Markdown clipboard code lives in `Assets/js/timereport.js` and is delegated from `document`.
- **Access guard first, always:** confirm `projectId ∈ ProjectPermissionModel::getActiveProjectIds($userId)`, else throw `\Kanboard\Core\Controller\AccessForbiddenException`.
- **Self-only v1:** `userId = $this->userSession->getId()`, kept as a single named variable so a future `userId` param is a one-line change. **Out of scope:** money/rates, PDF, saved presets, multi-user/team reports, DB migration. AI degrades to fully manual.
- **Hours display:** decimal hours formatted to 2 dp (Kanboard stores time in hours as a float).
- **Include verbatim** `.github/workflows/release.yml` (from `references/release.md`), plus `LICENSE` (MIT), `README.md`, `CHANGELOG.md`.

### Running the tests

The plugin is not wired into the shared `run-plugin-tests.sh` list (avoid editing that shared file). Run PHPUnit directly against the harness's Kanboard checkout. **One-time per session**, create the symlink, then run:

```bash
KB=/home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src
ln -sfn /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport "$KB/plugins/TimeReport"
cat > "$KB/tests/timereport-bootstrap.php" <<'EOF'
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$pluginsDir = __DIR__ . '/../plugins';
foreach (new DirectoryIterator($pluginsDir) as $e) {
    if ($e->isDot() || !$e->isDir()) continue;
    $loader->addPsr4("Kanboard\\Plugin\\{$e->getFilename()}\\", $e->getPathname() . '/');
}
EOF
```

Then to run the suite (from the Kanboard root so `tests/units/Base.php` resolves):

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && \
vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Every test class starts with `require_once 'tests/units/Base.php';` and extends `KanboardTests\units\Base` (gives `$this->container` with an in-memory SQLite DB, exactly as the SubtaskGenerator tests do).

### Data shapes (shared across tasks)

**Normalized subtask time row** (input to `buildContributions`):
```php
['task_id' => int, 'project_id' => int, 'user_id' => int, 'start' => int /*unix ts*/, 'end' => int /*unix ts, 0 if running*/, 'time_spent' => float /*hours, may be 0*/]
```

**Normalized candidate task row** (input to `buildContributions` — completed tasks assigned to the user):
```php
['id' => int, 'project_id' => int, 'owner_id' => int, 'time_spent' => float /*hours*/, 'date_completed' => int /*unix ts, 0 if not completed*/]
```

**Contribution** (output of `buildContributions`):
```php
['task_id' => int, 'hours' => float, 'date' => 'Y-m-d']
```

**Breakdown row** (output of `bucket`):
```php
['key' => string, 'label' => string, 'hours' => float, 'task_count' => int]
```

**Report aggregate** (output of `TimeReportModel::report`, input to the helper/templates/AI):
```php
[
  'project_id'     => int,
  'project_name'   => string,
  'start_date'     => 'Y-m-d',
  'end_date'       => 'Y-m-d',
  'granularity'    => 'day'|'week'|'task'|'total',
  'total_hours'    => float,
  'breakdown'      => Breakdown row[],
  'include_detail' => bool,
  'detail'         => DetailRow[],   // [] when include_detail is false
  'ai'             => null | ['summary' => string, 'highlights' => string[]],
]
```

**DetailRow:**
```php
['task_id' => int, 'reference' => string, 'title' => string, 'hours' => float, 'date_completed' => 'Y-m-d', 'category' => string, 'tags' => string[]]
```

---

### Task 1: Project scaffold + `plugin.json` + release/license/docs

**Files:**
- Create: `TimeReport/plugin.json`
- Create: `TimeReport/LICENSE`
- Create: `TimeReport/README.md`
- Create: `TimeReport/CHANGELOG.md`
- Create: `TimeReport/.github/workflows/release.yml`
- Create: `TimeReport/.gitignore`
- Test: `TimeReport/Test/PluginMetaTest.php` (asserts the JSON shape — the dependency-array trap)

**Interfaces:**
- Consumes: nothing.
- Produces: `plugin.json` with `name` `"TimeReport"`, `version` `"1.0.0"`, and the `recommends` array shape that Task 8's `Plugin.php` and Task 11's tests rely on.

- [ ] **Step 1: `cd` into the plugin dir and init git**

```bash
mkdir -p /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git init -q
mkdir -p .superpowers/sdd Test .github/workflows Assets/css Assets/js Controller Model Helper Template/report
```

- [ ] **Step 2: Write `plugin.json`** (exact — the `recommends` array is the correctness trap)

```json
{
    "name": "TimeReport",
    "description": "Consultant hours reporting: pick a project and date range, choose per-day/per-week/per-task breakdowns, list completed tasks, and optionally add an AI summary. Copy as Markdown or export CSV.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-time-report",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "recommends": [
        { "plugin": "AiConnector", "min_version": "1.0.0", "reason": "adds an AI narrative summary of the completed work (the report works fully without it)" }
    ]
}
```

- [ ] **Step 3: Write `.github/workflows/release.yml`** (verbatim from the skill's `references/release.md`)

```yaml
name: release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build and publish plugin zip
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          NAME=$(grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          VERSION=$(grep -oE '"version"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          if [ "v$VERSION" != "$GITHUB_REF_NAME" ]; then
            echo "::error::tag $GITHUB_REF_NAME does not match plugin.json v$VERSION"; exit 1
          fi
          mkdir -p /tmp/stage/"$NAME"
          rsync -a --exclude '.git' --exclude '.github' --exclude 'Test' --exclude '.DS_Store' ./ /tmp/stage/"$NAME"/
          ( cd /tmp/stage && zip -qr "$GITHUB_WORKSPACE/${NAME}-${VERSION}.zip" "$NAME" )
          gh release create "$GITHUB_REF_NAME" "${NAME}-${VERSION}.zip" \
            --title "$GITHUB_REF_NAME" --notes "Release $GITHUB_REF_NAME"
```

- [ ] **Step 4: Write `LICENSE`** (MIT, `Copyright (c) 2026 Carmelo Santana`), `.gitignore` (one line: `.superpowers/`), `README.md` (purpose, install, usage of the three surfaces, "AI optional — degrades to manual"), and `CHANGELOG.md`:

```markdown
# Changelog

## 1.0.0 — 2026-08-21

- Initial release: self-only consultant hours report for one project over a date range.
- Deduped hours union of subtask time entries and task-level time_spent.
- Breakdowns by day, week, task, or total; optional completed-task detail.
- Delivery: on-screen HTML, Copy-as-Markdown, CSV export.
- Optional AI narrative summary via AiConnector (degrades to fully manual when absent).
```

- [ ] **Step 5: Write the failing metadata test** `Test/PluginMetaTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIsExactly100(): void
    {
        $this->assertSame('1.0.0', $this->json()['version']);
    }

    public function testNameAndCompat(): void
    {
        $j = $this->json();
        $this->assertSame('TimeReport', $j['name']);
        $this->assertSame('>=1.2.47', $j['kanboard_version']);
        $this->assertSame('>=8.4', $j['php_version']);
        $this->assertSame('MIT', $j['license']);
    }

    /** The dependency-array trap: recommends must be an ARRAY of objects with a bare min_version. */
    public function testRecommendsArrayShape(): void
    {
        $j = $this->json();
        $this->assertArrayNotHasKey('requires', $j, 'AiConnector must be recommends, never requires');
        $this->assertSame(['AiConnector'], array_column($j['recommends'], 'plugin'));
        $this->assertSame('1.0.0', $j['recommends'][0]['min_version'], 'bare semver, no ">=" prefix');
        $this->assertStringStartsNotWith('>=', $j['recommends'][0]['min_version']);
        $this->assertNotEmpty($j['recommends'][0]['reason']);
    }
}
```

- [ ] **Step 6: Run the metadata test — expect PASS** (the JSON already exists)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && \
ln -sfn /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport plugins/TimeReport && \
cat > tests/timereport-bootstrap.php <<'EOF'
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';
$dir = __DIR__ . '/../plugins';
foreach (new DirectoryIterator($dir) as $e) { if ($e->isDot() || !$e->isDir()) continue; $loader->addPsr4("Kanboard\\Plugin\\{$e->getFilename()}\\", $e->getPathname() . '/'); }
EOF
vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/PluginMetaTest.php --no-coverage
```
Expected: `OK (3 tests)`.

- [ ] **Step 7: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "chore: scaffold TimeReport plugin (plugin.json, release.yml, license, docs)"
```

---

### Task 2: `TimeReportModel` — the deduped hours union (correctness core)

**Files:**
- Create: `TimeReport/Model/TimeReportModel.php`
- Test: `TimeReport/Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: normalized subtask/task rows (see Data shapes).
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport\Model;
  class TimeReportModel extends \Kanboard\Core\Base {
      /** @return array{0: list<array{task_id:int,hours:float,date:string}>, 1: array<int,bool>} [contributions, subtaskTaskIds] */
      public static function buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, int $userId): array
  }
  ```
  `hours` for a subtask row = `time_spent` when `> 0`, else `max(0, end - start) / 3600` (0 when `end` is 0/running). `date` = `date('Y-m-d', start)` for subtask contributions, `date('Y-m-d', date_completed)` for task-level. A task id appears in `subtaskTaskIds` only if it had ≥1 **in-range** subtask entry, and such tasks are excluded from the task-level source (dedup).

- [ ] **Step 1: Write the failing test** `Test/TimeReportModelTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

class TimeReportModelTest extends Base
{
    // Range: 2026-03-01 00:00:00 .. 2026-03-31 23:59:59
    private int $startTs;
    private int $endTs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startTs = strtotime('2026-03-01 00:00:00');
        $this->endTs   = strtotime('2026-03-31 23:59:59');
    }

    private function ts(string $d): int { return strtotime($d); }

    public function testSubtaskEntryInRangeCountsFromSubtaskSourceOnly(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        // Task 10 also has task-level time_spent + completed in range — must be IGNORED (deduped).
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 8.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);

        $this->assertTrue($subtaskTaskIds[10]);
        $this->assertCount(1, $contribs);
        $this->assertSame(10, $contribs[0]['task_id']);
        $this->assertSame(2.0, $contribs[0]['hours']);
        $this->assertSame('2026-03-10', $contribs[0]['date']);
    }

    public function testTaskLevelFallbackCountsWhenNoSubtaskTime(): void
    {
        $taskRows = [
            ['id' => 20, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 4.5, 'date_completed' => $this->ts('2026-03-15 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, 1);

        $this->assertCount(1, $contribs);
        $this->assertSame(20, $contribs[0]['task_id']);
        $this->assertSame(4.5, $contribs[0]['hours']);
        $this->assertSame('2026-03-15', $contribs[0]['date']);
    }

    public function testTaskCompletedOutsideRangeExcluded(): void
    {
        $taskRows = [
            ['id' => 30, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 3.0, 'date_completed' => $this->ts('2026-04-02 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, 1);
        $this->assertSame([], $contribs);
    }

    public function testSubtaskEntryOutsideRangeDoesNotMarkDedup(): void
    {
        // Out-of-range subtask entry for task 40 must NOT suppress the task-level fallback.
        $subtaskRows = [
            ['task_id' => 40, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-02-20 09:00:00'), 'end' => $this->ts('2026-02-20 10:00:00'), 'time_spent' => 1.0],
        ];
        $taskRows = [
            ['id' => 40, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 6.0, 'date_completed' => $this->ts('2026-03-20 12:00:00')],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);

        $this->assertArrayNotHasKey(40, $subtaskTaskIds);
        $this->assertCount(1, $contribs);
        $this->assertSame(6.0, $contribs[0]['hours']); // task-level, dated by completion
        $this->assertSame('2026-03-20', $contribs[0]['date']);
    }

    public function testWrongProjectAndWrongUserExcluded(): void
    {
        $subtaskRows = [
            ['task_id' => 50, 'project_id' => 99, 'user_id' => 1, 'start' => $this->ts('2026-03-05 09:00:00'), 'end' => 0, 'time_spent' => 2.0],
            ['task_id' => 51, 'project_id' => 5,  'user_id' => 2, 'start' => $this->ts('2026-03-05 09:00:00'), 'end' => 0, 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 52, 'project_id' => 99, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
            ['id' => 53, 'project_id' => 5,  'owner_id' => 2, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);
        $this->assertSame([], $contribs);
    }

    public function testSubtaskHoursFromEndMinusStartWhenTimeSpentZero(): void
    {
        $subtaskRows = [
            ['task_id' => 60, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => $this->ts('2026-03-08 12:30:00'), 'time_spent' => 0.0],
        ];
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, 1);
        $this->assertSame(1, count($contribs));
        $this->assertEqualsWithDelta(3.5, $contribs[0]['hours'], 0.0001);
    }

    public function testRunningSubtaskTimerContributesZeroHours(): void
    {
        $subtaskRows = [
            ['task_id' => 61, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => 0, 'time_spent' => 0.0],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, 1);
        $this->assertTrue($subtaskTaskIds[61]); // still marks the task as having an in-range entry
        $this->assertSame(0.0, $contribs[0]['hours']);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`Class "...TimeReportModel" not found`)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 3: Implement `Model/TimeReportModel.php`** (only `buildContributions` for now)

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * TimeReportModel — computes a deduped hours union and buckets it.
 *
 * The aggregation methods are pure (operate on plain arrays) so the union/dedup
 * and bucketing math is unit-testable without a database. Data gathering (the
 * report() method, added in a later task) normalizes Kanboard rows into the
 * shapes these pure methods consume.
 */
class TimeReportModel extends Base
{
    /**
     * Build the deduped flat contribution list.
     *
     * Source 1 (granular truth): subtask time rows for the user whose task is in
     * $projectId and whose start ts is in [$startTs,$endTs]. Each contributes
     * time_spent (hours) or (end-start)/3600 when time_spent is 0, dated by start.
     * Every such in-range task id is recorded in $subtaskTaskIds.
     *
     * Source 2 (fallback): tasks in $projectId owned by the user with time_spent>0
     * and date_completed in range whose id is NOT in $subtaskTaskIds. Contributes
     * the full time_spent, dated by date_completed.
     *
     * @return array{0: list<array{task_id:int,hours:float,date:string}>, 1: array<int,bool>}
     */
    public static function buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, int $userId): array
    {
        $contributions = [];
        $subtaskTaskIds = [];

        foreach ($subtaskRows as $row) {
            if ((int) $row['project_id'] !== $projectId || (int) $row['user_id'] !== $userId) {
                continue;
            }
            $start = (int) $row['start'];
            if ($start < $startTs || $start > $endTs) {
                continue;
            }
            $taskId = (int) $row['task_id'];
            $subtaskTaskIds[$taskId] = true;

            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $end = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => (float) $hours,
                'date'    => date('Y-m-d', $start),
            ];
        }

        foreach ($taskRows as $task) {
            if ((int) $task['project_id'] !== $projectId || (int) $task['owner_id'] !== $userId) {
                continue;
            }
            $timeSpent = (float) $task['time_spent'];
            if ($timeSpent <= 0) {
                continue;
            }
            $completed = (int) $task['date_completed'];
            if ($completed < $startTs || $completed > $endTs) {
                continue;
            }
            $taskId = (int) $task['id'];
            if (isset($subtaskTaskIds[$taskId])) {
                continue; // dedup: represented by source 1
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => $timeSpent,
                'date'    => date('Y-m-d', $completed),
            ];
        }

        return [$contributions, $subtaskTaskIds];
    }
}
```

- [ ] **Step 4: Run — expect PASS** (7 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: TimeReportModel.buildContributions — deduped hours union"
```

---

### Task 3: `TimeReportModel` — day/week/task/total bucketing (incl. ISO-week boundary)

**Files:**
- Modify: `TimeReport/Model/TimeReportModel.php`
- Modify: `TimeReport/Test/TimeReportModelTest.php` (append)

**Interfaces:**
- Consumes: Contribution[] from Task 2.
- Produces:
  ```php
  /** @return array{total_hours:float, breakdown: list<array{key:string,label:string,hours:float,task_count:int}>} */
  public static function bucket(array $contributions, string $granularity, array $taskMeta = []): array
  ```
  `$taskMeta` maps `taskId => ['reference'=>string,'title'=>string]` (used only for `task` granularity labels; may be empty → label falls back to `#<id>`). Keys: `day` → `Y-m-d`; `week` → ISO `o-\WW` with label = "Mon d – Sun d" span of that ISO week; `task` → one row per task id; `total` → single row key `total`, label `Total`. `task_count` = distinct task ids in the bucket. Rows sorted ascending by key (by task reference for `task`). Also produces static helpers `dayKey(int $ts): string`, `weekKey(int $ts): string`, `weekLabel(int $ts): string`.

- [ ] **Step 1: Append failing tests** to `Test/TimeReportModelTest.php`

```php
    // ── Bucketing ────────────────────────────────────────────────────────────

    private function contrib(int $taskId, float $hours, string $date): array
    {
        return ['task_id' => $taskId, 'hours' => $hours, 'date' => $date];
    }

    public function testBucketByDaySumsPerCalendarDay(): void
    {
        $c = [
            $this->contrib(1, 2.0, '2026-03-10'),
            $this->contrib(2, 1.5, '2026-03-10'),
            $this->contrib(1, 3.0, '2026-03-11'),
        ];
        $out = TimeReportModel::bucket($c, 'day');
        $this->assertEqualsWithDelta(6.5, $out['total_hours'], 0.0001);
        $this->assertCount(2, $out['breakdown']);
        $this->assertSame('2026-03-10', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(3.5, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']); // tasks 1 and 2
        $this->assertSame('2026-03-11', $out['breakdown'][1]['key']);
        $this->assertSame(1, $out['breakdown'][1]['task_count']);
    }

    public function testBucketByTotalSingleRow(): void
    {
        $c = [$this->contrib(1, 2.0, '2026-03-10'), $this->contrib(1, 3.0, '2026-03-11'), $this->contrib(2, 1.0, '2026-03-12')];
        $out = TimeReportModel::bucket($c, 'total');
        $this->assertCount(1, $out['breakdown']);
        $this->assertSame('total', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(6.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']);
    }

    public function testBucketByTaskOneRowPerTaskWithLabel(): void
    {
        $c = [$this->contrib(7, 2.0, '2026-03-10'), $this->contrib(7, 1.0, '2026-03-11'), $this->contrib(9, 4.0, '2026-03-12')];
        $meta = [7 => ['reference' => 'ABC-7', 'title' => 'Build API'], 9 => ['reference' => 'ABC-9', 'title' => 'Write docs']];
        $out = TimeReportModel::bucket($c, 'task', $meta);
        $this->assertCount(2, $out['breakdown']);
        // sorted by reference: ABC-7 before ABC-9
        $this->assertSame('7', $out['breakdown'][0]['key']);
        $this->assertStringContainsString('Build API', $out['breakdown'][0]['label']);
        $this->assertEqualsWithDelta(3.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(1, $out['breakdown'][0]['task_count']);
    }

    public function testBucketByTaskFallsBackToHashIdLabelWithoutMeta(): void
    {
        $c = [$this->contrib(7, 2.0, '2026-03-10')];
        $out = TimeReportModel::bucket($c, 'task');
        $this->assertSame('#7', $out['breakdown'][0]['label']);
    }

    /** ISO-week boundary: 2025-12-29 (Mon) .. 2026-01-04 (Sun) is ISO week 2026-W01. */
    public function testBucketByWeekIsoBoundary(): void
    {
        $c = [
            $this->contrib(1, 2.0, '2025-12-29'), // Mon of ISO 2026-W01
            $this->contrib(2, 1.0, '2026-01-04'), // Sun of ISO 2026-W01
            $this->contrib(3, 5.0, '2026-01-05'), // Mon of ISO 2026-W02
        ];
        $out = TimeReportModel::bucket($c, 'week');
        $this->assertCount(2, $out['breakdown']);
        $this->assertSame('2026-W01', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(3.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']);
        $this->assertSame('2026-W02', $out['breakdown'][1]['key']);
        $this->assertStringContainsString('Dec 29', $out['breakdown'][0]['label']);
        $this->assertStringContainsString('Jan 04', $out['breakdown'][0]['label']);
    }

    public function testWeekKeyHelperOnIsoBoundary(): void
    {
        $this->assertSame('2026-W01', TimeReportModel::weekKey(strtotime('2025-12-29 08:00:00')));
        $this->assertSame('2026-W01', TimeReportModel::weekKey(strtotime('2026-01-04 20:00:00')));
        $this->assertSame('2026-W02', TimeReportModel::weekKey(strtotime('2026-01-05 00:00:00')));
    }
```

- [ ] **Step 2: Run — expect FAIL** (`bucket` undefined)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 3: Add `bucket` + key helpers to `Model/TimeReportModel.php`** (inside the class)

```php
    public static function dayKey(int $ts): string
    {
        return date('Y-m-d', $ts);
    }

    /** ISO-8601 week key: <ISO-year>-W<2-digit ISO week>. */
    public static function weekKey(int $ts): string
    {
        return date('o', $ts) . '-W' . date('W', $ts);
    }

    /** "Mon d – Sun d" span for the ISO week containing $ts. */
    public static function weekLabel(int $ts): string
    {
        $monday = strtotime('monday this week', $ts);
        $sunday = strtotime('sunday this week', $ts);
        return date('M d', $monday) . ' – ' . date('M d', $sunday);
    }

    /**
     * Sum contributions into breakdown rows per the chosen granularity.
     *
     * @param  list<array{task_id:int,hours:float,date:string}> $contributions
     * @param  array<int,array{reference:string,title:string}>  $taskMeta
     * @return array{total_hours:float, breakdown: list<array{key:string,label:string,hours:float,task_count:int}>}
     */
    public static function bucket(array $contributions, string $granularity, array $taskMeta = []): array
    {
        $buckets = []; // key => ['label'=>, 'hours'=>, 'tasks'=>[id=>true], 'sort'=>]
        $total = 0.0;

        foreach ($contributions as $c) {
            $hours  = (float) $c['hours'];
            $taskId = (int) $c['task_id'];
            $total += $hours;
            $ts = strtotime($c['date'] . ' 12:00:00');

            switch ($granularity) {
                case 'week':
                    $key   = self::weekKey($ts);
                    $label = self::weekLabel($ts);
                    $sort  = $key;
                    break;
                case 'task':
                    $key   = (string) $taskId;
                    $ref   = $taskMeta[$taskId]['reference'] ?? '';
                    $title = $taskMeta[$taskId]['title'] ?? '';
                    $label = $title !== '' ? ('#' . $taskId . ' ' . $title) : ('#' . $taskId);
                    $sort  = ($ref !== '' ? $ref : str_pad((string) $taskId, 12, '0', STR_PAD_LEFT));
                    break;
                case 'total':
                    $key   = 'total';
                    $label = t('Total');
                    $sort  = 'total';
                    break;
                case 'day':
                default:
                    $key   = self::dayKey($ts);
                    $label = $key;
                    $sort  = $key;
                    break;
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'hours' => 0.0, 'tasks' => [], 'sort' => $sort];
            }
            $buckets[$key]['hours'] += $hours;
            $buckets[$key]['tasks'][$taskId] = true;
        }

        uasort($buckets, static fn ($a, $b) => strcmp((string) $a['sort'], (string) $b['sort']));

        $breakdown = [];
        foreach ($buckets as $key => $b) {
            $breakdown[] = [
                'key'        => (string) $key,
                'label'      => $b['label'],
                'hours'      => (float) $b['hours'],
                'task_count' => count($b['tasks']),
            ];
        }

        return ['total_hours' => (float) $total, 'breakdown' => $breakdown];
    }
```

> Note: `t()` is Kanboard's global translation function, always available in the harness. For `task` sort without meta, numeric ids are zero-padded so `#7` sorts before `#10`.

- [ ] **Step 4: Run — expect PASS** (all Task 2 + Task 3 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: TimeReportModel.bucket — day/week/task/total (ISO-week boundary)"
```

---

### Task 4: `TimeReportModel::report()` + access guard (DB-backed gather)

**Files:**
- Modify: `TimeReport/Model/TimeReportModel.php`
- Modify: `TimeReport/Test/TimeReportModelTest.php` (append access-guard tests)

**Interfaces:**
- Consumes: Kanboard container services `projectPermissionModel`, `subtaskTimeTrackingModel`, `taskFinderModel`, `projectModel`, `taskTagModel`, `categoryModel` (all resolved via `$this->` on `\Kanboard\Core\Base`).
- Produces:
  ```php
  public function assertProjectAccess(int $projectId, int $userId): void  // throws AccessForbiddenException
  public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId): array // Report aggregate (ai => null)
  ```
  `report()` calls `assertProjectAccess` first. It normalizes rows and delegates to `buildContributions` + `bucket`, then builds `detail` (only when `$includeDetail`). `ai` is always `null` here (the controller attaches AI). Date bounds: `startTs = strtotime($startDate.' 00:00:00')`, `endTs = strtotime($endDate.' 23:59:59')`.

- [ ] **Step 1: Append failing access-guard tests** to `Test/TimeReportModelTest.php`

```php
    // ── Access guard ─────────────────────────────────────────────────────────

    public function testAssertProjectAccessThrowsForInaccessibleProject(): void
    {
        $model = new TimeReportModel($this->container);
        // No projects created/assigned to user 1 → getActiveProjectIds is empty.
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $model->assertProjectAccess(4242, 1);
    }

    public function testAssertProjectAccessPassesForAccessibleProject(): void
    {
        // Create a project (creator becomes owner/member → appears in getActiveProjectIds).
        $projectId = $this->container['projectModel']->create(['name' => 'Acme'], 1, true);
        $model = new TimeReportModel($this->container);
        $model->assertProjectAccess((int) $projectId, 1);
        $this->assertTrue(true); // no exception
    }

    public function testReportRefusesInaccessibleProject(): void
    {
        $model = new TimeReportModel($this->container);
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $model->report(4242, '2026-03-01', '2026-03-31', 'day', false, 1);
    }
```

- [ ] **Step 2: Run — expect FAIL** (`assertProjectAccess` / `report` undefined)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 3: Add `use` + `assertProjectAccess` + `report` to `Model/TimeReportModel.php`**

At the top, add the import under the existing `use`:
```php
use Kanboard\Core\Controller\AccessForbiddenException;
```

Add these methods to the class:
```php
    /** Throw unless $projectId is one the user may access. Always call first. */
    public function assertProjectAccess(int $projectId, int $userId): void
    {
        $allowed = $this->projectPermissionModel->getActiveProjectIds($userId);
        if (! in_array($projectId, array_map('intval', $allowed), true)) {
            throw new AccessForbiddenException(t('You are not allowed to access this project.'));
        }
    }

    /**
     * Compute the full report aggregate for one project + range for $userId.
     * AI is not attached here (ai => null); the controller adds it when enabled.
     *
     * @return array Report aggregate (see plan Data shapes).
     */
    public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId): array
    {
        $this->assertProjectAccess($projectId, $userId);

        $startTs = (int) strtotime($startDate . ' 00:00:00');
        $endTs   = (int) strtotime($endDate . ' 23:59:59');

        // Source 1: normalize the user's subtask time rows → map subtask→task→project.
        $subtaskRows = $this->gatherSubtaskRows($userId, $projectId);
        // Source 2: completed tasks assigned to the user in this project.
        $taskRows = $this->gatherCompletedTaskRows($projectId, $userId);

        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $userId);

        // Task meta for `task` granularity labels.
        $taskMeta = [];
        foreach ($taskRows as $t) {
            $taskMeta[(int) $t['id']] = ['reference' => (string) ($t['reference'] ?? ''), 'title' => (string) ($t['title'] ?? '')];
        }

        $bucketed = self::bucket($contributions, $granularity, $taskMeta);

        $project = $this->projectModel->getById($projectId);

        $report = [
            'project_id'     => $projectId,
            'project_name'   => (string) ($project['name'] ?? ('#' . $projectId)),
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'granularity'    => $granularity,
            'total_hours'    => $bucketed['total_hours'],
            'breakdown'      => $bucketed['breakdown'],
            'include_detail' => $includeDetail,
            'detail'         => [],
            'ai'             => null,
        ];

        if ($includeDetail) {
            $report['detail'] = $this->buildDetail($contributions, $taskRows, $projectId, $userId, $startTs, $endTs);
        }

        return $report;
    }

    /** Normalize the user's subtask time rows into the buildContributions shape (task_id + project_id resolved). */
    private function gatherSubtaskRows(int $userId, int $projectId): array
    {
        // getUserQuery joins subtasks→tasks, exposing task_id; project_id resolved per task below.
        $rows = $this->subtaskTimeTrackingModel->getUserQuery($userId)->findAll();
        $normalized = [];
        $projectByTask = [];
        foreach ($rows as $r) {
            $taskId = (int) $r['task_id'];
            if (! isset($projectByTask[$taskId])) {
                $projectByTask[$taskId] = (int) $this->taskFinderModel->getProjectId($taskId);
            }
            $normalized[] = [
                'task_id'    => $taskId,
                'project_id' => $projectByTask[$taskId],
                'user_id'    => $userId,
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }
        return $normalized;
    }

    /** Completed tasks assigned to $userId in $projectId (all statuses so completed/closed are included). */
    private function gatherCompletedTaskRows(int $projectId, int $userId): array
    {
        $rows = $this->taskFinderModel->getExtendedQuery()
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.owner_id', $userId)
            ->findAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'project_id'     => (int) $r['project_id'],
                'owner_id'       => (int) $r['owner_id'],
                'time_spent'     => (float) $r['time_spent'],
                'date_completed' => (int) $r['date_completed'],
                'reference'      => (string) $r['reference'],
                'title'          => (string) $r['title'],
                'category_id'    => (int) $r['category_id'],
                'category'       => (string) ($r['category_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Completed-task detail set: tasks assigned to the user with date_completed in
     * range, each with its hours from the contribution union (0 if none), category, tags.
     */
    private function buildDetail(array $contributions, array $taskRows, int $projectId, int $userId, int $startTs, int $endTs): array
    {
        $hoursByTask = [];
        foreach ($contributions as $c) {
            $hoursByTask[(int) $c['task_id']] = ($hoursByTask[(int) $c['task_id']] ?? 0.0) + (float) $c['hours'];
        }

        $completed = [];
        foreach ($taskRows as $t) {
            $completedTs = (int) $t['date_completed'];
            if ($completedTs < $startTs || $completedTs > $endTs) {
                continue;
            }
            $completed[(int) $t['id']] = $t;
        }

        $ids = array_keys($completed);
        $tagsByTask = empty($ids) ? [] : $this->taskTagModel->getTagsByTaskIds($ids);

        $detail = [];
        foreach ($completed as $id => $t) {
            $tagNames = array_map(static fn ($tag) => (string) $tag['name'], $tagsByTask[$id] ?? []);
            $detail[] = [
                'task_id'        => $id,
                'reference'      => (string) $t['reference'],
                'title'          => (string) $t['title'],
                'hours'          => (float) ($hoursByTask[$id] ?? 0.0),
                'date_completed' => date('Y-m-d', (int) $t['date_completed']),
                'category'       => (string) $t['category'],
                'tags'           => $tagNames,
            ];
        }
        // Sort by completion date then reference for stable output.
        usort($detail, static fn ($a, $b) => [$a['date_completed'], $a['reference']] <=> [$b['date_completed'], $b['reference']]);
        return $detail;
    }
```

- [ ] **Step 4: Run — expect PASS** (all model tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: TimeReportModel.report + access guard (DB gather)"
```

---

### Task 5: `TimeReportHelper` — hours / Markdown / CSV formatting

**Files:**
- Create: `TimeReport/Helper/TimeReportHelper.php`
- Test: `TimeReport/Test/TimeReportHelperTest.php`

**Interfaces:**
- Consumes: a Report aggregate (Task 4 shape).
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport\Helper;
  class TimeReportHelper extends \Kanboard\Core\Base {
      public function formatHours(float $hours): string           // number_format($hours, 2)
      public function toMarkdown(array $report): string
      public function toCsv(array $report): string
      public function csvFilename(string $projectName, string $start, string $end): string
  }
  ```
  **CSV layout (documented):** row 1 = `# Time Report - <project>` ; row 2 = `# Range,<start>,<end>` ; row 3 = `# Total hours,<total>` ; blank ; a `Breakdown` section with header `Label,Hours` (task granularity also emits `task_count` as a third column `Tasks`); if `include_detail`, a blank line then a `Detail` section with header `Reference,Title,Hours,Completed,Category,Tags` (tags joined by `; `). All fields CSV-escaped (quotes doubled, wrapped when containing `, " \n`). `csvFilename` = `time-report-<slug(project)>-<start>_<end>.csv` (slug = lowercased, non-alnum → `-`).

- [ ] **Step 1: Write the failing test** `Test/TimeReportHelperTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;

class TimeReportHelperTest extends Base
{
    private function helper(): TimeReportHelper
    {
        return new TimeReportHelper($this->container);
    }

    private function sampleReport(bool $detail = false): array
    {
        return [
            'project_id'     => 5,
            'project_name'   => 'Acme Website',
            'start_date'     => '2026-03-01',
            'end_date'       => '2026-03-31',
            'granularity'    => 'day',
            'total_hours'    => 6.5,
            'breakdown'      => [
                ['key' => '2026-03-10', 'label' => '2026-03-10', 'hours' => 3.5, 'task_count' => 2],
                ['key' => '2026-03-11', 'label' => '2026-03-11', 'hours' => 3.0, 'task_count' => 1],
            ],
            'include_detail' => $detail,
            'detail'         => $detail ? [
                ['task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build, "the" API', 'hours' => 3.5, 'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend', 'urgent']],
            ] : [],
            'ai'             => null,
        ];
    }

    public function testFormatHoursTwoDecimals(): void
    {
        $this->assertSame('6.50', $this->helper()->formatHours(6.5));
        $this->assertSame('0.00', $this->helper()->formatHours(0.0));
        $this->assertSame('10.25', $this->helper()->formatHours(10.25));
    }

    public function testMarkdownHasHeaderTotalAndBreakdown(): void
    {
        $md = $this->helper()->toMarkdown($this->sampleReport());
        $this->assertStringContainsString('# Time Report — Acme Website', $md);
        $this->assertStringContainsString('2026-03-01', $md);
        $this->assertStringContainsString('2026-03-31', $md);
        $this->assertStringContainsString('**Total hours:** 6.50', $md);
        $this->assertStringContainsString('| 2026-03-10 | 3.50 |', $md);
    }

    public function testMarkdownIncludesDetailAndAiWhenPresent(): void
    {
        $report = $this->sampleReport(true);
        $report['ai'] = ['summary' => 'Solid week of backend work.', 'highlights' => ['Shipped API', 'Cleared backlog']];
        $md = $this->helper()->toMarkdown($report);
        $this->assertStringContainsString('ABC-7', $md);
        $this->assertStringContainsString('Build, "the" API', $md);
        $this->assertStringContainsString('backend; urgent', $md);
        $this->assertStringContainsString('Solid week of backend work.', $md);
        $this->assertStringContainsString('Shipped API', $md);
    }

    public function testCsvEscapesAndStructuresRows(): void
    {
        $csv = $this->helper()->toCsv($this->sampleReport(true));
        $this->assertStringContainsString('# Time Report,Acme Website', $csv);
        $this->assertStringContainsString('# Total hours,6.50', $csv);
        $this->assertStringContainsString("Label,Hours", $csv);
        $this->assertStringContainsString('2026-03-10,3.50', $csv);
        // Detail header + escaped title (embedded quotes doubled, field wrapped)
        $this->assertStringContainsString('Reference,Title,Hours,Completed,Category,Tags', $csv);
        $this->assertStringContainsString('"Build, ""the"" API"', $csv);
        $this->assertStringContainsString('backend; urgent', $csv);
    }

    public function testCsvTaskGranularityAddsTasksColumn(): void
    {
        $report = $this->sampleReport();
        $report['granularity'] = 'task';
        $report['breakdown'] = [['key' => '7', 'label' => '#7 Build API', 'hours' => 3.0, 'task_count' => 1]];
        $csv = $this->helper()->toCsv($report);
        $this->assertStringContainsString('Label,Hours,Tasks', $csv);
    }

    public function testCsvFilenameSlug(): void
    {
        $this->assertSame(
            'time-report-acme-website-2026-03-01_2026-03-31.csv',
            $this->helper()->csvFilename('Acme Website', '2026-03-01', '2026-03-31')
        );
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportHelperTest.php --no-coverage
```

- [ ] **Step 3: Implement `Helper/TimeReportHelper.php`**

```php
<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;

/**
 * TimeReportHelper — renders one report aggregate to hours / Markdown / CSV.
 * Pure formatting over the aggregate; no data access.
 */
class TimeReportHelper extends Base
{
    public function formatHours(float $hours): string
    {
        return number_format($hours, 2, '.', '');
    }

    public function toMarkdown(array $report): string
    {
        $isTask = ($report['granularity'] ?? 'day') === 'task';
        $lines = [];
        $lines[] = '# Time Report — ' . $report['project_name'];
        $lines[] = '';
        $lines[] = '**Range:** ' . $report['start_date'] . ' → ' . $report['end_date'];
        $lines[] = '**Total hours:** ' . $this->formatHours((float) $report['total_hours']);
        $lines[] = '';

        if ($isTask) {
            $lines[] = '| Task | Hours |';
            $lines[] = '| --- | ---: |';
        } else {
            $lines[] = '| ' . $this->breakdownHeader($report['granularity']) . ' | Hours | Tasks |';
            $lines[] = '| --- | ---: | ---: |';
        }
        foreach ($report['breakdown'] as $row) {
            if ($isTask) {
                $lines[] = '| ' . $row['label'] . ' | ' . $this->formatHours((float) $row['hours']) . ' |';
            } else {
                $lines[] = '| ' . $row['label'] . ' | ' . $this->formatHours((float) $row['hours']) . ' | ' . (int) $row['task_count'] . ' |';
            }
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $lines[] = '';
            $lines[] = '## Completed tasks';
            $lines[] = '';
            $lines[] = '| Ref | Title | Hours | Completed | Category | Tags |';
            $lines[] = '| --- | --- | ---: | --- | --- | --- |';
            foreach ($report['detail'] as $d) {
                $lines[] = '| ' . $d['reference'] . ' | ' . $d['title'] . ' | ' . $this->formatHours((float) $d['hours'])
                    . ' | ' . $d['date_completed'] . ' | ' . $d['category'] . ' | ' . implode('; ', $d['tags']) . ' |';
            }
        }

        if (! empty($report['ai']) && is_array($report['ai'])) {
            $lines[] = '';
            $lines[] = '## Summary';
            $lines[] = '';
            $lines[] = (string) ($report['ai']['summary'] ?? '');
            if (! empty($report['ai']['highlights'])) {
                $lines[] = '';
                foreach ($report['ai']['highlights'] as $h) {
                    $lines[] = '- ' . $h;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function toCsv(array $report): string
    {
        $out = [];
        $out[] = $this->csvRow(['# Time Report', $report['project_name']]);
        $out[] = $this->csvRow(['# Range', $report['start_date'], $report['end_date']]);
        $out[] = $this->csvRow(['# Total hours', $this->formatHours((float) $report['total_hours'])]);
        $out[] = '';

        // Uniform breakdown header across all granularities (the Tasks count is 1
        // per row for task granularity — simple and documented).
        $out[] = $this->csvRow(['Label', 'Hours', 'Tasks']);
        foreach ($report['breakdown'] as $row) {
            $out[] = $this->csvRow([$row['label'], $this->formatHours((float) $row['hours']), (string) (int) $row['task_count']]);
        }

        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $out[] = '';
            $out[] = $this->csvRow(['Reference', 'Title', 'Hours', 'Completed', 'Category', 'Tags']);
            foreach ($report['detail'] as $d) {
                $out[] = $this->csvRow([
                    $d['reference'], $d['title'], $this->formatHours((float) $d['hours']),
                    $d['date_completed'], $d['category'], implode('; ', $d['tags']),
                ]);
            }
        }

        return implode("\r\n", $out) . "\r\n";
    }

    public function csvFilename(string $projectName, string $start, string $end): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $projectName));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'project';
        }
        return 'time-report-' . $slug . '-' . $start . '_' . $end . '.csv';
    }

    private function breakdownHeader(string $granularity): string
    {
        return match ($granularity) {
            'week'  => 'Week',
            'task'  => 'Task',
            'total' => 'Total',
            default => 'Day',
        };
    }

    private function csvRow(array $fields): string
    {
        return implode(',', array_map([$this, 'csvField'], $fields));
    }

    private function csvField(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
```

> The CSV `Label,Hours,Tasks` header is uniform across granularities (the "Tasks" count is meaningful for day/week/total and is `1` per row for task granularity — simple and documented). The `breakdownHeader` helper is kept for the Markdown non-task header only.

- [ ] **Step 4: Run — expect PASS**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportHelperTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: TimeReportHelper — hours/markdown/csv formatting"
```

---

### Task 6: `AiGate` — availability gate (all four branches)

**Files:**
- Create: `TimeReport/Model/AiGate.php`
- Test: `TimeReport/Test/AiGateTest.php`

**Interfaces:**
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport\Model;
  class AiGate {
      public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
  }
  ```
  Gate = PHP ≥ 8.4 AND AiConnector present (`class_exists(ProviderRegistry::class)`) AND `(new ProviderRegistry($container))->isReady()`. The two override params make all four branches testable. Mirror SubtaskGenerator's `AiGate` exactly, re-namespaced.

- [ ] **Step 1: Write the failing test** `Test/AiGateTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\AiGate;

class AiGateTest extends Base
{
    public function testFalseBelowPhp84(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80399, true));
    }

    public function testFalseWhenConnectorAbsent(): void
    {
        $this->assertFalse(AiGate::isReady($this->container, 80400, false));
    }

    public function testFalseWhenPresentButNoProfileConfigured(): void
    {
        // PHP ok, connector present, but no AiConnector profile → registry isReady() false.
        $this->assertFalse(AiGate::isReady($this->container, 80400, true));
    }

    public function testTrueWhenReady(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);
        $this->assertTrue(AiGate::isReady($this->container, 80400, true));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/AiGateTest.php --no-coverage
```

- [ ] **Step 3: Implement `Model/AiGate.php`**

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiGate — single source of truth for "is the AI narrative summary available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND ProviderRegistry::isReady().
 * Consulted identically by Plugin::initialize() (AI toggle) and
 * TimeReportController::isAiEnabled() (route guards) so they never diverge.
 *
 * class_exists(ProviderRegistry) is safe at initialize() time — it loads the
 * class file but not any provider SDK; isReady() touches no provider class.
 */
class AiGate
{
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class);
        if (! $present) {
            return false;
        }

        return (new ProviderRegistry($container))->isReady();
    }
}
```

- [ ] **Step 4: Run — expect PASS** (4 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/AiGateTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: AiGate — availability gate (4 branches)"
```

---

### Task 7: `AiSummaryModel` — build messages + call `structured()`

**Files:**
- Create: `TimeReport/Model/AiSummaryModel.php`
- Test: `TimeReport/Test/AiSummaryModelTest.php`

**Interfaces:**
- Consumes: the `detail` DetailRow[] from a report; an injected `ProviderRegistry` (fake in tests).
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport\Model;
  class AiSummaryModel extends \Kanboard\Core\Base {
      public const SCHEMA = [ ... ];               // {summary:string, highlights:string[]}
      public function setRegistry(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry $r): void
      public function summarize(array $detailTasks, ?string $profileId = null): array  // ['summary'=>string,'highlights'=>string[]]
      public function buildMessages(array $detailTasks): array   // public for the boundary test
  }
  ```
  `summarize` calls `structured($messages, json_encode(self::SCHEMA), $profileId)` on the injected/constructed registry and normalizes the decoded result to `['summary'=>string,'highlights'=>string[]]` (missing/malformed → `['summary'=>'','highlights'=>[]]`). **Boundary:** `buildMessages` includes only task title, hours, category, tags, completion date — never descriptions/comments.

- [ ] **Step 1: Write the failing test** `Test/AiSummaryModelTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;

class AiSummaryModelTest extends Base
{
    private function fakeRegistry(mixed $return, ?\Throwable $throw = null): ProviderRegistry
    {
        return new class($this->container, $return, $throw) extends ProviderRegistry {
            public function __construct($c, private mixed $r, private ?\Throwable $t) { parent::__construct($c); }
            public function structured(array $messages, string $schema, ?string $profileId = null): array {
                if ($this->t !== null) { throw $this->t; }
                return is_array($this->r) ? $this->r : [];
            }
        };
    }

    private function model(mixed $return): AiSummaryModel
    {
        $m = new AiSummaryModel($this->container);
        $m->setRegistry($this->fakeRegistry($return));
        return $m;
    }

    private function detail(): array
    {
        return [
            ['task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build API', 'hours' => 3.5, 'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend']],
        ];
    }

    public function testSummarizeReturnsNormalizedResult(): void
    {
        $m = $this->model(['summary' => 'Good week.', 'highlights' => ['Shipped API', 'Cleared backlog']]);
        $out = $m->summarize($this->detail());
        $this->assertSame('Good week.', $out['summary']);
        $this->assertSame(['Shipped API', 'Cleared backlog'], $out['highlights']);
    }

    public function testSummarizeGracefulOnMalformed(): void
    {
        $m = $this->model(['unexpected' => true]);
        $out = $m->summarize($this->detail());
        $this->assertSame('', $out['summary']);
        $this->assertSame([], $out['highlights']);
    }

    public function testSummarizeDropsNonStringHighlights(): void
    {
        $m = $this->model(['summary' => 'x', 'highlights' => ['ok', 42, null, 'fine']]);
        $out = $m->summarize($this->detail());
        $this->assertSame(['ok', 'fine'], $out['highlights']);
    }

    /** Boundary: the message payload must carry only titles/hours/category/tags/dates — no descriptions. */
    public function testBuildMessagesOnlyIncludesAllowedFields(): void
    {
        $detail = [[
            'task_id' => 7, 'reference' => 'ABC-7', 'title' => 'Build API', 'hours' => 3.5,
            'date_completed' => '2026-03-10', 'category' => 'Dev', 'tags' => ['backend'],
            'description' => 'SECRET internal notes', // must NOT leak even if present
        ]];
        $m = new AiSummaryModel($this->container);
        $messages = $m->buildMessages($detail);
        $blob = json_encode($messages);
        $this->assertStringContainsString('Build API', $blob);
        $this->assertStringContainsString('backend', $blob);
        $this->assertStringNotContainsString('SECRET internal notes', $blob);
        $this->assertStringNotContainsString('description', $blob);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/AiSummaryModelTest.php --no-coverage
```

- [ ] **Step 3: Implement `Model/AiSummaryModel.php`**

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiSummaryModel — builds a message payload from the completed-task detail set
 * and asks AiConnector's ProviderRegistry for a {summary, highlights} result.
 *
 * Boundary: only data already visible to the user (task title, hours, category,
 * tags, completion date) is sent — never descriptions or comments. AI proposes;
 * the user disposes. Degrades to nothing when the gate is closed (never called).
 */
class AiSummaryModel extends Base
{
    public const SCHEMA = [
        'name'   => 'time_report_summary',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'summary'    => ['type' => 'string'],
                'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'highlights'],
        ],
    ];

    private const SYSTEM_PROMPT =
        'You summarize a consultant\'s completed work for a client-facing hours report. '
        . 'Given a list of completed tasks with hours, categories, tags and completion dates, '
        . 'write a concise professional narrative summary and a short list of highlights. '
        . 'Do not invent work that is not in the data.';

    private ?ProviderRegistry $injectedRegistry = null;

    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->injectedRegistry = $registry;
    }

    /**
     * @param  array $detailTasks DetailRow[] (only allowed fields are forwarded)
     * @return array{summary:string, highlights:string[]}
     */
    public function summarize(array $detailTasks, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);
        $messages = $this->buildMessages($detailTasks);
        $decoded  = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);
        return $this->normalise($decoded);
    }

    /** Build the chat messages, forwarding ONLY the allowed fields. Public for the boundary test. */
    public function buildMessages(array $detailTasks): array
    {
        $safe = [];
        foreach ($detailTasks as $t) {
            $safe[] = [
                'title'          => (string) ($t['title'] ?? ''),
                'hours'          => round((float) ($t['hours'] ?? 0.0), 2),
                'category'       => (string) ($t['category'] ?? ''),
                'tags'           => array_values(array_map('strval', $t['tags'] ?? [])),
                'date_completed' => (string) ($t['date_completed'] ?? ''),
            ];
        }

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => json_encode(['completed_tasks' => $safe], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** @return array{summary:string, highlights:string[]} */
    private function normalise(array $decoded): array
    {
        $summary = isset($decoded['summary']) && is_string($decoded['summary']) ? $decoded['summary'] : '';

        $highlights = [];
        if (isset($decoded['highlights']) && is_array($decoded['highlights'])) {
            foreach ($decoded['highlights'] as $h) {
                if (is_string($h) && trim($h) !== '') {
                    $highlights[] = $h;
                }
            }
        }

        return ['summary' => $summary, 'highlights' => $highlights];
    }
}
```

- [ ] **Step 4: Run — expect PASS** (4 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/AiSummaryModelTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: AiSummaryModel — safe message boundary + structured() call"
```

---

### Task 8: `Plugin.php` — wiring, metadata, AI gate, routes, hooks

**Files:**
- Create: `TimeReport/Plugin.php`
- Test: `TimeReport/Test/PluginTest.php`

**Interfaces:**
- Consumes: `AiGate` (Task 6), the models/helper (Tasks 2–7).
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport;
  class Plugin extends \Kanboard\Core\Plugin\Base {
      public function initialize(): void
      public function isPhpCompatible(?int $versionId = null): bool  // >= 80400
      public function isAiEnabled(): bool
      public function getPluginName(): string        // 'TimeReport'
      public function getPluginVersion(): string     // '1.0.0'
      // + getPluginDescription/Author/Homepage/getCompatibleVersion
  }
  ```
  `initialize()`: register `timeReportModel`, `timeReportHelper` (as a template helper via `$this->helper->register('timeReport', TimeReportHelper::class)`), and `aiSummaryModel` services; add routes `timereport`, `timereport/generate`, `timereport/export-csv`; attach the header-dropdown link (`template:header:dropdown` → `TimeReport:report/header_dropdown`); inject `Assets/css/timereport.css` (`template:layout:css`) and `Assets/js/timereport.js` (`template:layout:js`); compute `$this->aiEnabled = AiGate::isReady($this->container)`.

- [ ] **Step 1: Write the failing test** `Test/PluginTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Plugin;
use Kanboard\Plugin\TimeReport\Model\AiGate;

class PluginTest extends Base
{
    public function testMetadataVersionIs100(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('TimeReport', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertSame('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertStringContainsString('github.com/carmelosantana/kanboard-time-report', $plugin->getPluginHomepage());
    }

    public function testVersionMatchesPluginJson(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $plugin = new Plugin($this->container);
        $this->assertSame($json['version'], $plugin->getPluginVersion(), 'Plugin.php version must equal plugin.json version');
        $this->assertSame('1.0.0', $json['version']);
    }

    public function testPhpGate(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertTrue($plugin->isPhpCompatible(80400));
        $this->assertFalse($plugin->isPhpCompatible(80399));
    }

    public function testInitializeRunsAndSetsAiDisabledWithoutProfile(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        // No AiConnector profile configured → gate closed.
        $this->assertFalse($plugin->isAiEnabled());
    }

    public function testInitializeEnablesAiWhenProfileConfigured(): void
    {
        $this->container['configModel']->save([
            'aiconnector_profiles' => json_encode([
                ['id' => 'p1', 'label' => 'Test', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
            ]),
            'aiconnector_key_p1' => 'sk-test-fake-key-for-gate-test',
        ]);
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertTrue($plugin->isAiEnabled());
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/PluginTest.php --no-coverage
```

- [ ] **Step 3: Implement `Plugin.php`**

```php
<?php

namespace Kanboard\Plugin\TimeReport;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\TimeReport\Model\AiGate;
use Kanboard\Plugin\TimeReport\Model\AiSummaryModel;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;

/**
 * TimeReport — self-only consultant hours report for one project + date range.
 *
 * Pure query→render: no persisted state, no DB migration. AI narrative summary
 * is optional (AiConnector) and degrades to fully manual when absent.
 */
class Plugin extends Base
{
    private bool $aiEnabled = false;

    public function initialize(): void
    {
        // ── Model services (lazy singletons) ──────────────────────────────────
        $this->container['timeReportModel'] = function ($c) {
            return new TimeReportModel($c);
        };
        $this->container['aiSummaryModel'] = function ($c) {
            return new AiSummaryModel($c);
        };

        // ── Template helper: $this->helper->timeReport()->formatHours(...) ─────
        $this->helper->register('timeReport', TimeReportHelper::class);

        // ── AI availability gate (single source of truth) ─────────────────────
        $this->aiEnabled = AiGate::isReady($this->container);

        // ── Routes ────────────────────────────────────────────────────────────
        $this->route->addRoute('timereport', 'TimeReportController', 'index', 'TimeReport');
        $this->route->addRoute('timereport/generate', 'TimeReportController', 'generate', 'TimeReport');
        $this->route->addRoute('timereport/export-csv', 'TimeReportController', 'exportCsv', 'TimeReport');

        // ── Entry-point link in the header user dropdown ──────────────────────
        $this->template->hook->attach('template:header:dropdown', 'TimeReport:report/header_dropdown');

        // ── Assets (CSP-safe: external files, delegated JS) ───────────────────
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeReport/Assets/css/timereport.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/TimeReport/Assets/js/timereport.js']);
    }

    /** True when the PHP runtime satisfies the >= 8.4 gate. $versionId override for tests. */
    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    public function getPluginName(): string
    {
        return 'TimeReport';
    }

    public function getPluginDescription(): string
    {
        return t('Consultant hours reporting: pick a project and date range, choose per-day/per-week/per-task breakdowns, list completed tasks, and optionally add an AI summary. Copy as Markdown or export CSV.');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-time-report';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
```

- [ ] **Step 4: Run — expect PASS** (5 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/PluginTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: Plugin.php — wiring, metadata (1.0.0), AI gate, routes, hooks"
```

---

### Task 9: `TimeReportController` — index / generate / exportCsv + AI guard

**Files:**
- Create: `TimeReport/Controller/TimeReportController.php`
- Test: `TimeReport/Test/TimeReportControllerTest.php`

**Interfaces:**
- Consumes: `timeReportModel`, `aiSummaryModel`, `timeReport` helper, `AiGate`, `ProviderRegistry` (profile validation).
- Produces:
  ```php
  namespace Kanboard\Plugin\TimeReport\Controller;
  class TimeReportController extends \Kanboard\Controller\BaseController {
      public function index(): void
      public function generate(): void
      public function exportCsv(): void
      protected function isAiEnabled(): bool   // delegates to AiGate; overridable in tests
  }
  ```
  `generate()` and `exportCsv()`: read `project_id`(int), `start_date`, `end_date`, `granularity` (validated against `['day','week','task','total']`, default `day`), `include_detail`(bool), `include_ai_summary`(bool). Call `$model->report(...)`. When `include_ai_summary` is set **and** `isAiEnabled()`: validate submitted `profile_id` against `array_column($registry->listProfiles(), 'id')`, call `aiSummaryModel->summarize($report['detail'], $profileId)`, attach to `$report['ai']`. When the gate is closed, **ignore** `include_ai_summary` (no error). `exportCsv()` streams `text/csv` with `Content-Disposition: attachment; filename="<csvFilename>"`.

- [ ] **Step 1: Write the failing test** `Test/TimeReportControllerTest.php` (behavioral gate + source-structure guards, mirroring SubtaskGenerator's approach)

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class TimeReportControllerTest extends Base
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
    }

    public function testGenerateGuardsWithAccessAndCsrfAndGranularityValidation(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('assertProjectAccess', $src, 'must access-guard via the model');
        $this->assertStringContainsString('checkCSRFForm', $src, 'generate/export must check CSRF on POST');
        $this->assertStringContainsString("'day', 'week', 'task', 'total'", $src, 'granularity must be validated against the allow-list');
    }

    public function testAiIsGatedAndProfileValidated(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('isAiEnabled()', $src, 'AI path must be gated');
        $this->assertStringContainsString('include_ai_summary', $src);
        $this->assertStringContainsString("array_column(", $src, 'submitted profile id must be validated against listProfiles()');
    }

    public function testExportStreamsCsv(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('text/csv', $src);
        $this->assertStringContainsString('Content-Disposition', $src);
        $this->assertStringContainsString('csvFilename', $src);
    }

    /** Behavioral: when the gate is CLOSED, include_ai_summary must be ignored (no AI attached, no throw). */
    public function testGenerateIgnoresAiSummaryWhenGateClosed(): void
    {
        // Build a project owned by user 1 so access passes.
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);

        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public array $captured = [];
            protected function isAiEnabled(): bool { return false; }
            // Capture the report the action would render instead of emitting HTML.
            public function renderProbe(int $projectId): array {
                $model = $this->timeReportModel;
                $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'day', false, 1);
                // Simulate the gate-closed AI branch: since isAiEnabled() is false, ai stays null.
                return $report;
            }
        };

        $report = $controller->renderProbe($projectId);
        $this->assertNull($report['ai'], 'AI must not be attached when the gate is closed');
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportControllerTest.php --no-coverage
```

- [ ] **Step 3: Implement `Controller/TimeReportController.php`**

```php
<?php

namespace Kanboard\Plugin\TimeReport\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\TimeReport\Model\AiGate;

/**
 * TimeReportController — the report form + the three delivery surfaces.
 *
 * Every action operates on the current user's OWN data (userSession id; login
 * enforced by the router). Access to the chosen project is enforced by
 * TimeReportModel::assertProjectAccess() before any mining. The AI narrative is
 * gated by AiGate and ignored (never errored) when the gate is closed.
 */
class TimeReportController extends BaseController
{
    private const GRANULARITIES = ['day', 'week', 'task', 'total'];

    /** The report form: project picker, range inputs, toggles. */
    public function index(): void
    {
        $userId = $this->userSession->getId();
        $projectIds = $this->projectPermissionModel->getActiveProjectIds($userId);
        $projects = [];
        foreach ($projectIds as $pid) {
            $p = $this->projectModel->getById((int) $pid);
            if (! empty($p)) {
                $projects[(int) $pid] = $p['name'];
            }
        }

        $this->response->html($this->helper->layout->app('TimeReport:report/form', [
            'title'      => t('Time Report'),
            'projects'   => $projects,
            'ai_enabled' => $this->isAiEnabled(),
            'profiles'   => $this->aiProfiles(),
            'values'     => [
                'start_date'  => date('Y-m-01'),
                'end_date'    => date('Y-m-d'),
                'granularity' => 'day',
            ],
        ]));
    }

    /** Validate + access-guard + compute + render the report (and the Markdown payload). */
    public function generate(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        $markdown = $this->helper->timeReport()->toMarkdown($report);

        $this->response->html($this->helper->layout->app('TimeReport:report/show', [
            'title'      => t('Time Report'),
            'report'     => $report,
            'markdown'   => $markdown,
            'ai_enabled' => $this->isAiEnabled(),
        ]));
    }

    /** Same params, streamed as a CSV download. */
    public function exportCsv(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        $helper = $this->helper->timeReport();
        $csv = $helper->toCsv($report);
        $filename = $helper->csvFilename($report['project_name'], $report['start_date'], $report['end_date']);

        $this->response->withContentType('text/csv; charset=utf-8');
        $this->response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->send();
        echo $csv;
    }

    /** Shared: read/validate params, compute the report, attach AI only when the gate is open. */
    private function buildReportFromRequest(): array
    {
        $userId      = $this->userSession->getId();
        $values      = $this->request->getValues();
        $projectId   = (int) ($values['project_id'] ?? 0);
        $startDate   = $this->validDate($values['start_date'] ?? '', date('Y-m-01'));
        $endDate     = $this->validDate($values['end_date'] ?? '', date('Y-m-d'));
        $granularity = in_array($values['granularity'] ?? '', self::GRANULARITIES, true) ? $values['granularity'] : 'day';
        $includeDetail = ! empty($values['include_detail']);
        $wantsAi       = ! empty($values['include_ai_summary']);

        // Model access-guards the project before any mining.
        $report = $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, $includeDetail, $userId);

        if ($wantsAi && $this->isAiEnabled()) {
            $detail = ! empty($report['detail'])
                ? $report['detail']
                : $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, true, $userId)['detail'];
            $profileId = $this->validProfileId($values['profile_id'] ?? null);
            try {
                $report['ai'] = $this->aiSummaryModel->summarize($detail, $profileId);
            } catch (\Throwable $e) {
                $report['ai'] = ['summary' => '', 'highlights' => [], 'error' => t('The AI summary could not be generated.')];
            }
        }

        return $report;
    }

    /** AiGate delegate — overridable in tests. */
    protected function isAiEnabled(): bool
    {
        return AiGate::isReady($this->container);
    }

    private function aiProfiles(): array
    {
        if (! $this->isAiEnabled()) {
            return [];
        }
        $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
        return $registry->listProfiles();
    }

    /** Validate a submitted profile id against the registry's list; null → default. */
    private function validProfileId(?string $submitted): ?string
    {
        if ($submitted === null || $submitted === '') {
            return null;
        }
        $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
        $ids = array_column($registry->listProfiles(), 'id');
        return in_array($submitted, $ids, true) ? $submitted : null;
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
```

> Note on `exportCsv`: if `Response::withContentType`/`withHeader` differ in the pinned Kanboard version, the implementer should match the framework's actual `Response` API (e.g. `$this->response->text($csv)` after setting headers, or the `Response` streaming helper). The **test asserts the source contains `text/csv`, `Content-Disposition`, and `csvFilename`** — keep those literals. Verify against `testing/kanboard-src/app/Core/Http/Response.php` during implementation and adjust the emit calls (not the literals) if needed.

- [ ] **Step 4: Run — expect PASS** (4 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportControllerTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: TimeReportController — index/generate/exportCsv + AI guard"
```

---

### Task 10: Templates + Assets (form, show, partials, header link, JS, CSS)

**Files:**
- Create: `TimeReport/Template/report/header_dropdown.php`
- Create: `TimeReport/Template/report/form.php`
- Create: `TimeReport/Template/report/show.php`
- Create: `TimeReport/Template/report/_breakdown.php`
- Create: `TimeReport/Template/report/_detail.php`
- Create: `TimeReport/Assets/js/timereport.js`
- Create: `TimeReport/Assets/css/timereport.css`
- Test: `TimeReport/Test/TemplateAssetsTest.php` (static guards — CSP, no inline handlers, clipboard in JS)

**Interfaces:**
- Consumes: the report aggregate (`$report`), `$markdown`, `$projects`, `$profiles`, `$ai_enabled`, `$values`, and the `timeReport` helper.
- Produces: the rendered surfaces. The Markdown copy payload is emitted into a hidden `<textarea id="tr-markdown">` (or `data-` attribute); the copy button carries `data-tr-copy` and is wired by `Assets/js/timereport.js` via delegation (`navigator.clipboard.writeText`).

- [ ] **Step 1: Write the failing static-guard test** `Test/TemplateAssetsTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class TemplateAssetsTest extends Base
{
    private function tpl(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/Template/report/' . $rel);
    }

    public function testNoInlineScriptOrHandlersInTemplates(): void
    {
        foreach (['form.php', 'show.php', '_breakdown.php', '_detail.php', 'header_dropdown.php'] as $f) {
            $src = $this->tpl($f);
            $this->assertStringNotContainsString('<script', $src, "$f must not contain inline <script> (CSP)");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, "$f must not contain inline on* handlers (CSP)");
        }
    }

    public function testShowEmitsMarkdownPayloadAndCopyButton(): void
    {
        $src = $this->tpl('show.php');
        $this->assertStringContainsString('tr-markdown', $src, 'show must emit the Markdown payload container');
        $this->assertStringContainsString('data-tr-copy', $src, 'show must have the delegated copy button');
    }

    public function testJsUsesClipboardAndDelegation(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('navigator.clipboard', $js);
        $this->assertStringContainsString('data-tr-copy', $js);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TemplateAssetsTest.php --no-coverage
```

- [ ] **Step 3: Create the templates and assets**

`Template/report/header_dropdown.php`:
```php
<?php
/** Entry-point link in the header user dropdown (hook template:header:dropdown). */
?>
<li>
    <?= $this->url->icon('duration', t('Time Report'), 'TimeReportController', 'index', ['plugin' => 'TimeReport']) ?>
</li>
```

`Template/report/form.php`:
```php
<div class="page-header">
    <h2><?= t('Time Report') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Project'), 'project_id') ?>
    <?= $this->form->select('project_id', $projects, $values, [], ['required']) ?>

    <?= $this->form->label(t('Start date'), 'start_date') ?>
    <?= $this->form->text('start_date', $values, [], ['placeholder="YYYY-MM-DD"', 'required']) ?>

    <?= $this->form->label(t('End date'), 'end_date') ?>
    <?= $this->form->text('end_date', $values, [], ['placeholder="YYYY-MM-DD"', 'required']) ?>

    <?= $this->form->label(t('Breakdown'), 'granularity') ?>
    <?= $this->form->select('granularity', ['day' => t('Per day'), 'week' => t('Per week'), 'task' => t('Per task'), 'total' => t('Total only')], $values) ?>

    <div class="tr-toggles">
        <label><?= $this->form->checkbox('include_detail', t('Include completed-task detail'), 1) ?></label>
        <?php if ($ai_enabled): ?>
            <label><?= $this->form->checkbox('include_ai_summary', t('Add AI narrative summary'), 1) ?></label>
            <p class="tr-ai-note"><?= t('Only completed-task titles, hours, categories, tags and dates are sent to the AI provider.') ?></p>
            <?php if (! empty($profiles)): ?>
                <?= $this->form->label(t('AI profile'), 'profile_id') ?>
                <?= $this->form->select('profile_id', array_column($profiles, 'label', 'id')) ?>
            <?php endif ?>
        <?php else: ?>
            <p class="tr-ai-note tr-ai-off"><?= t('AI summary unavailable (install and configure the AiConnector plugin to enable it).') ?></p>
        <?php endif ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Generate report') ?></button>
    </div>
</form>
```

`Template/report/_breakdown.php`:
```php
<?php $isTask = $report['granularity'] === 'task'; ?>
<table class="table-fixed tr-breakdown">
    <thead>
        <tr>
            <th><?= $isTask ? t('Task') : t('Period') ?></th>
            <th class="tr-num"><?= t('Hours') ?></th>
            <?php if (! $isTask): ?><th class="tr-num"><?= t('Tasks') ?></th><?php endif ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['breakdown'] as $row): ?>
            <tr>
                <td><?= $this->text->e($row['label']) ?></td>
                <td class="tr-num"><?= $this->text->e($this->helper->timeReport()->formatHours((float) $row['hours'])) ?></td>
                <?php if (! $isTask): ?><td class="tr-num"><?= (int) $row['task_count'] ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
```

`Template/report/_detail.php`:
```php
<h3><?= t('Completed tasks') ?></h3>
<table class="table-fixed tr-detail">
    <thead>
        <tr>
            <th><?= t('Ref') ?></th><th><?= t('Title') ?></th><th class="tr-num"><?= t('Hours') ?></th>
            <th><?= t('Completed') ?></th><th><?= t('Category') ?></th><th><?= t('Tags') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($report['detail'] as $d): ?>
            <tr>
                <td><?= $this->text->e($d['reference']) ?></td>
                <td><?= $this->text->e($d['title']) ?></td>
                <td class="tr-num"><?= $this->text->e($this->helper->timeReport()->formatHours((float) $d['hours'])) ?></td>
                <td><?= $this->text->e($d['date_completed']) ?></td>
                <td><?= $this->text->e($d['category']) ?></td>
                <td><?= $this->text->e(implode(', ', $d['tags'])) ?></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
```

`Template/report/show.php`:
```php
<div class="page-header">
    <h2><?= t('Time Report') ?> — <?= $this->text->e($report['project_name']) ?></h2>
</div>

<div class="tr-summary">
    <p>
        <strong><?= t('Range') ?>:</strong> <?= $this->text->e($report['start_date']) ?> → <?= $this->text->e($report['end_date']) ?>
        &nbsp;·&nbsp;
        <strong><?= t('Total hours') ?>:</strong> <?= $this->text->e($this->helper->timeReport()->formatHours((float) $report['total_hours'])) ?>
    </p>
    <div class="tr-actions">
        <button type="button" class="btn" data-tr-copy><?= t('Copy as Markdown') ?></button>
        <form method="post" class="tr-inline-form" action="<?= $this->url->href('TimeReportController', 'exportCsv', ['plugin' => 'TimeReport']) ?>">
            <?= $this->form->csrf() ?>
            <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
            <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
            <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
            <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
            <input type="hidden" name="include_detail" value="<?= ! empty($report['include_detail']) ? 1 : 0 ?>">
            <button type="submit" class="btn"><?= t('Export CSV') ?></button>
        </form>
    </div>
</div>

<?= $this->render('TimeReport:report/_breakdown', ['report' => $report]) ?>

<?php if (! empty($report['include_detail']) && ! empty($report['detail'])): ?>
    <?= $this->render('TimeReport:report/_detail', ['report' => $report]) ?>
<?php endif ?>

<?php if (! empty($report['ai']) && is_array($report['ai'])): ?>
    <div class="tr-ai">
        <h3><?= t('Summary') ?></h3>
        <?php if (! empty($report['ai']['error'])): ?>
            <p class="tr-ai-error"><?= $this->text->e($report['ai']['error']) ?></p>
        <?php else: ?>
            <p><?= $this->text->e($report['ai']['summary']) ?></p>
            <?php if (! empty($report['ai']['highlights'])): ?>
                <ul>
                    <?php foreach ($report['ai']['highlights'] as $h): ?>
                        <li><?= $this->text->e($h) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
        <p class="tr-ai-note"><?= t('AI-proposed — review before sharing.') ?></p>
    </div>
<?php endif ?>

<textarea id="tr-markdown" class="tr-hidden" readonly aria-hidden="true"><?= $this->text->e($markdown) ?></textarea>

<p><a href="<?= $this->url->href('TimeReportController', 'index', ['plugin' => 'TimeReport']) ?>">&larr; <?= t('New report') ?></a></p>
```

`Assets/js/timereport.js`:
```javascript
// TimeReport — CSP-safe, event-delegated clipboard copy of the Markdown payload.
(function () {
    "use strict";
    document.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-tr-copy]");
        if (!btn) {
            return;
        }
        e.preventDefault();
        var ta = document.getElementById("tr-markdown");
        if (!ta) {
            return;
        }
        var text = ta.value;
        var done = function () {
            var original = btn.textContent;
            btn.textContent = btn.getAttribute("data-tr-copied") || "Copied";
            setTimeout(function () { btn.textContent = original; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                ta.removeAttribute("aria-hidden");
                ta.select();
                try { document.execCommand("copy"); done(); } catch (err) { /* no-op */ }
            });
        } else {
            ta.removeAttribute("aria-hidden");
            ta.select();
            try { document.execCommand("copy"); done(); } catch (err) { /* no-op */ }
        }
    });
})();
```

`Assets/css/timereport.css`:
```css
.tr-hidden { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
.tr-summary { margin: 1em 0; }
.tr-actions { display: flex; gap: 0.5em; align-items: center; margin-top: 0.5em; }
.tr-inline-form { display: inline; margin: 0; }
.tr-breakdown .tr-num, .tr-detail .tr-num { text-align: right; }
.tr-toggles { margin: 1em 0; }
.tr-ai-note { color: #888; font-size: 0.9em; margin: 0.25em 0; }
.tr-ai-off { font-style: italic; }
.tr-ai { margin-top: 1.5em; padding: 0.75em 1em; border-left: 3px solid #4b9fd5; background: rgba(75,159,213,0.06); }
.tr-ai-error { color: #b94a48; }
```

> The header-dropdown icon name (`duration`) and `$this->url->icon(...)` mirror Kensho's pattern; if `duration` is not a registered icon in the pinned version, use `clock-o` or `list-alt`. Verify against `testing/kanboard-src/app/Template/config/keyboard_shortcuts.php` or the FontAwesome set the theme ships; the test does not assert the icon name.

- [ ] **Step 4: Run — expect PASS** (3 tests)

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TemplateAssetsTest.php --no-coverage
```

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "feat: templates + assets (form/show/partials, header link, delegated copy JS, CSS)"
```

---

### Task 11: Full-suite green + whole-branch review polish

**Files:**
- Modify: any file surfaced by the review.

**Interfaces:** none (integration).

- [ ] **Step 1: Run the entire plugin test suite**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/timereport-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```
Expected: `OK` — all tests across `PluginMetaTest`, `TimeReportModelTest`, `TimeReportHelperTest`, `AiGateTest`, `AiSummaryModelTest`, `PluginTest`, `TimeReportControllerTest`, `TemplateAssetsTest` pass.

- [ ] **Step 2: Verify the three version anchors agree at 1.0.0**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && \
grep '"version"' plugin.json && grep -n "getPluginVersion" Plugin.php && \
grep -n "1.0.0" CHANGELOG.md | head -1
```
Expected: `plugin.json` → `1.0.0`; `Plugin.php::getPluginVersion()` returns `'1.0.0'`; CHANGELOG has a `1.0.0` entry.

- [ ] **Step 3: Verify the `recommends` array shape one more time**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && \
php -r '$j=json_decode(file_get_contents("plugin.json"),true); var_export(array_column($j["recommends"],"plugin")); echo PHP_EOL; var_export($j["recommends"][0]["min_version"]);'
```
Expected: `array (0 => 'AiConnector',)` and `'1.0.0'` (bare, no `>=`, and no `requires` key present).

- [ ] **Step 4: Confirm no stray files / no shared-path writes**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git status --porcelain && ls .superpowers/sdd
```
Expected: clean tree (all committed); SDD scratch only under `TimeReport/.superpowers/sdd/`.

- [ ] **Step 5: Final commit if the review changed anything**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport && git add -A && git commit -q -m "chore: whole-branch review polish" || echo "nothing to commit"
```

---

## Notes for the executor

- **Do not** create the GitHub repo, `git tag`, push, cut a release, or edit `kanboard-modmenu-directory`. Stop at green tests + hand-back.
- **Do not** edit `testing/run-plugin-tests.sh`, `testing/docker-compose.dev.yml`, or any sibling plugin directory. The symlink + private bootstrap in the "Running the tests" block keep the shared runner untouched.
- If a Kanboard core API used here (`Response` streaming, `getUserQuery`, `getProjectId`, icon name) differs in the pinned `v1.2.47` checkout, adapt the call to the real signature found in `testing/kanboard-src/app/` — keep the test-asserted string literals intact.
- Keep `userId` a single named variable everywhere (self-only v1; future `userId` param is a one-line change).
