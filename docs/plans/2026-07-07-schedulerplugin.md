# SchedulerPlugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically roll overdue-but-open Kanboard tasks forward on a daily sweep, with per-project opt-in, a policy pipeline (skip-blocked → snap-to-today → working-days → de-clump), a full audit log, a clearly-automated per-run activity-stream summary, and a calendar "auto-moved" badge.

**Architecture:** One pure `ReschedulePolicy` decides target dates; a `SchedulerRunner` service performs a sweep and records it; three thin entry points (lazy web-cron hook, admin HTTP button, `./cli scheduler:run`) all call the runner; `SchedulerConfigModel` and `SchedulerLogModel` back it; a controller + templates give the settings page, log pages, and per-project toggle; two soft integrations (DependencyPlugin consume, CalendarPlugin decorate) degrade to no-ops when the sibling is absent.

**Tech Stack:** PHP ≥ 8.4, Kanboard v1.2.47 plugin API (buildless), PicoDb, Symfony Console (`$container['cli']`), PHPUnit via `testing/run-plugin-tests.sh`, Docker suite on `:8081`.

## Global Constraints

- **Kanboard** ≥ 1.2.47; **PHP** ≥ 8.4; **buildless** (no compile step); **MIT** license; author "Carmelo Santana"; homepage `https://github.com/carmelosantana/kanboard-plugins`.
- **`getCompatibleVersion()` returns `>=1.2.47`.** `PluginTest` asserts that string and plugin **name**, never a hardcoded plugin *version* (that test went stale twice on CalendarPlugin — do not repeat it).
- **CSP:** no inline `<script>`, no inline event handlers; inline `<style>`/`style=""` allowed. All JS in `Assets/`.
- **CSRF:** state-changing HTTP endpoints are POST and validated with `$this->checkCSRFForm()` (forms carry `$this->formHelper->csrf()`).
- **Authorization:** settings, save, run, log, runDetail are **admin-only** (`if (! $this->userSession->isAdmin()) throw new AccessForbiddenException();`); the per-project toggle requires **project manager or admin** on that project.
- **No hard dependency on sibling plugins.** DependencyPlugin/CalendarPlugin detected via `isset($this->container[...])`; absent → the feature is a silent no-op.
- **PicoDb footgun:** never pass an empty array to `->in()` — guard `if (! empty($ids))`.
- **Per-driver schema:** `Schema/Sqlite.php`, `Schema/Mysql.php`, `Schema/Postgres.php`, each `namespace Kanboard\Plugin\SchedulerPlugin\Schema;` with `const VERSION = 1;` and `function version_1(PDO $pdo)`. Core `SchemaHandler` `require_once`s `Schema/<Ucfirst(DB_DRIVER)>.php`, runs `version_N($pdo)` inside a transaction with FKs disabled, and records state in `plugin_schema_versions`.
- **Direct due-date writes only for the move:** update `date_due` + `date_modification` via `$this->db->table(TaskModel::TABLE)` — deliberately NOT `TaskModificationModel::update()` (that path emits a core per-task activity event per move and would defeat the single per-run summary).
- **Dry-run writes nothing anywhere** (no tables, no metadata, no activity).

## Reference: verified core APIs (use exactly these)

- Console registration: `$this->container['cli']->add(new \Kanboard\Plugin\SchedulerPlugin\Console\SchedulerRunCommand($this->container));`
- PDO in code/tests: `$this->db->getConnection()` (in a model) / `$this->container['db']->getConnection()` (in a test).
- Config: `$this->configModel->get($name, $default)`, `$this->configModel->save(['k' => 'v'])`.
- Project metadata: `$this->projectMetadataModel->get($pid, $name, $default)`, `->save($pid, ['name' => 'val'])`. Task metadata: `$this->taskMetadataModel->get/save` (same shape).
- Activity: `$this->projectActivityModel->createEvent($project_id, $task_id, $creator_id, $event_name, array $data)` (data is `json_encode`d internally). `creator_id = 0` = system actor.
- Event registration + render override (in `Plugin::initialize()`): `$this->eventManager->register('scheduler.tasks.rescheduled', t('Automatically rescheduled tasks'));` and `$this->template->setTemplateOverride('event/scheduler_tasks_rescheduled', 'SchedulerPlugin:event/tasks_rescheduled');` (formatter renders `event/<event_name with dots→underscores>`).
- Hook with side-effect closure: `$this->hook->on('template:layout:top', ['template' => 'SchedulerPlugin:layout/webcron', 'callable' => function () use ($container) { (new WebCronTrigger($container))->maybeRun(); return array(); }]);` (HookHelper::render calls the closure then renders the template).
- Task table constants: `\Kanboard\Model\TaskModel::TABLE` (`tasks`), `TaskModel::STATUS_OPEN` (`1`). Project: `\Kanboard\Model\ProjectModel::TABLE` (`projects`), `ProjectModel::ACTIVE` (`1`).
- Layout for a config page: `$this->helper->layout->config('SchedulerPlugin:config/settings', [...])`.

## Test workflow (every task)

Run a single plugin's unit suite from the repo root:

```bash
./testing/run-plugin-tests.sh SchedulerPlugin
```

It runs PHPUnit against an in-memory SQLite Kanboard v1.2.47 core (`DB_DRIVER=sqlite`). **The test harness does NOT run the plugin loader**, so plugin schema tables (`scheduler_runs`, `scheduler_moves`) do **not** exist automatically. Any test touching those tables must apply the schema in `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();
    require_once __DIR__.'/../Schema/Sqlite.php';
    \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
}
```

Tests touching only core tables (config, metadata, tasks, projects) need no schema step.

## File Structure

- `SchedulerPlugin/Plugin.php` — metadata; register `schedulerConfigModel`/`schedulerLogModel`/`schedulerRunner` container services; register CLI command; register event name + template override; web-cron hook; config sidebar; project sidebar toggle; calendar decorator; badge CSS.
- `SchedulerPlugin/Schema/{Sqlite,Mysql,Postgres}.php` — `version_1` creates `scheduler_runs` + `scheduler_moves`.
- `SchedulerPlugin/Model/SchedulerConfigModel.php` — typed config/metadata access + memoized `recentlyMovedTaskIds`.
- `SchedulerPlugin/Model/ReschedulePolicy.php` — pure date-planning pipeline (no DB).
- `SchedulerPlugin/Model/SchedulerLogModel.php` — runs+moves persistence.
- `SchedulerPlugin/Model/SchedulerRunner.php` — orchestration.
- `SchedulerPlugin/Console/SchedulerRunCommand.php` — `scheduler:run`.
- `SchedulerPlugin/Trigger/WebCronTrigger.php` — guarded once/day web trigger.
- `SchedulerPlugin/Controller/SchedulerController.php` — settings/save/run/log/runDetail/toggleProject.
- `SchedulerPlugin/Template/config/{settings,sidebar}.php`, `Template/log/{index,run}.php`, `Template/project/toggle.php`, `Template/event/tasks_rescheduled.php`, `Template/layout/webcron.php`.
- `SchedulerPlugin/Assets/css/scheduler.css` — badge + log-page styling (tiny, sitewide, mirrors DependencyPlugin's decision).
- `SchedulerPlugin/Test/*` — one test file per model + `PluginTest`.
- `SchedulerPlugin/{README.md,CHANGELOG.md,LICENSE,plugin.json}`.
- Modify `testing/run-plugin-tests.sh` (add `SchedulerPlugin` to both plugin lists) and `testing/docker-compose.dev.yml` (add the bind mount).

---

### Task 0: Skeleton — plugin loads, CLI command registers, schema applies, mounted in Docker

**Files:**
- Create: `SchedulerPlugin/plugin.json`, `SchedulerPlugin/LICENSE`, `SchedulerPlugin/Plugin.php`
- Create: `SchedulerPlugin/Schema/Sqlite.php`, `SchedulerPlugin/Schema/Mysql.php`, `SchedulerPlugin/Schema/Postgres.php`
- Create: `SchedulerPlugin/Console/SchedulerRunCommand.php`
- Create: `SchedulerPlugin/Test/PluginTest.php`, `SchedulerPlugin/Test/SchemaTest.php`
- Modify: `testing/run-plugin-tests.sh`, `testing/docker-compose.dev.yml`

**Interfaces:**
- Produces: `\Kanboard\Plugin\SchedulerPlugin\Schema\VERSION` (=1) and `version_1(PDO)`; `Console\SchedulerRunCommand` (name `scheduler:run`); the plugin metadata methods.

- [ ] **Step 1: `plugin.json`**

```json
{
    "name": "SchedulerPlugin",
    "description": "Automatically roll overdue tasks forward on a daily sweep: per-project opt-in, working-days and de-clump policies, audit log, and a calendar auto-moved badge.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-plugins",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

- [ ] **Step 2: `LICENSE`** — copy `DependencyPlugin/LICENSE` verbatim (MIT, same author/year).

- [ ] **Step 3: `Schema/Sqlite.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE scheduler_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at INTEGER NOT NULL DEFAULT 0,
            finished_at INTEGER NOT NULL DEFAULT 0,
            trigger TEXT NOT NULL DEFAULT "cli",
            moved_count INTEGER NOT NULL DEFAULT 0,
            is_dry_run INTEGER NOT NULL DEFAULT 0
        )
    ');

    $pdo->exec('
        CREATE TABLE scheduler_moves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL DEFAULT 0,
            project_id INTEGER NOT NULL DEFAULT 0,
            task_id INTEGER NOT NULL DEFAULT 0,
            old_date INTEGER NOT NULL DEFAULT 0,
            new_date INTEGER NOT NULL DEFAULT 0,
            reason TEXT NOT NULL DEFAULT ""
        )
    ');

    $pdo->exec('CREATE INDEX scheduler_moves_run_idx ON scheduler_moves(run_id)');
}
```

- [ ] **Step 4: `Schema/Mysql.php`** (same two tables, MySQL types)

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE scheduler_runs (
            id INT NOT NULL AUTO_INCREMENT,
            started_at INT NOT NULL DEFAULT 0,
            finished_at INT NOT NULL DEFAULT 0,
            `trigger` VARCHAR(20) NOT NULL DEFAULT 'cli',
            moved_count INT NOT NULL DEFAULT 0,
            is_dry_run TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE scheduler_moves (
            id INT NOT NULL AUTO_INCREMENT,
            run_id INT NOT NULL DEFAULT 0,
            project_id INT NOT NULL DEFAULT 0,
            task_id INT NOT NULL DEFAULT 0,
            old_date INT NOT NULL DEFAULT 0,
            new_date INT NOT NULL DEFAULT 0,
            reason VARCHAR(30) NOT NULL DEFAULT '',
            PRIMARY KEY(id),
            INDEX scheduler_moves_run_idx (run_id)
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");
}
```

- [ ] **Step 5: `Schema/Postgres.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE scheduler_runs (
            id SERIAL PRIMARY KEY,
            started_at INTEGER NOT NULL DEFAULT 0,
            finished_at INTEGER NOT NULL DEFAULT 0,
            "trigger" VARCHAR(20) NOT NULL DEFAULT \'cli\',
            moved_count INTEGER NOT NULL DEFAULT 0,
            is_dry_run SMALLINT NOT NULL DEFAULT 0
        )
    ');

    $pdo->exec('
        CREATE TABLE scheduler_moves (
            id SERIAL PRIMARY KEY,
            run_id INTEGER NOT NULL DEFAULT 0,
            project_id INTEGER NOT NULL DEFAULT 0,
            task_id INTEGER NOT NULL DEFAULT 0,
            old_date INTEGER NOT NULL DEFAULT 0,
            new_date INTEGER NOT NULL DEFAULT 0,
            reason VARCHAR(30) NOT NULL DEFAULT \'\'
        )
    ');

    $pdo->exec('CREATE INDEX scheduler_moves_run_idx ON scheduler_moves(run_id)');
}
```

- [ ] **Step 6: `Console/SchedulerRunCommand.php`** (Task-0 stub; Task 5 wires it to the runner)

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Console;

use Kanboard\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SchedulerRunCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('scheduler:run')
            ->setDescription('Reschedule overdue tasks in opt-in projects')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview moves without writing')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Limit to one project id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>scheduler:run</info> registered.');
        return 0;
    }
}
```

- [ ] **Step 7: `Plugin.php`** (Task-0 minimum: metadata + register the CLI command)

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\SchedulerPlugin\Console\SchedulerRunCommand;

class Plugin extends Base
{
    public function initialize()
    {
        $this->container['cli']->add(new SchedulerRunCommand($this->container));
    }

    public function getPluginName()        { return 'SchedulerPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Automatically roll overdue tasks forward: per-project opt-in, working-days and de-clump policies, audit log, calendar badge.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
```

- [ ] **Step 8: `Test/PluginTest.php`**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('SchedulerPlugin', $plugin->getPluginName());
    }

    public function testPluginCompatibleVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
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

- [ ] **Step 9: `Test/SchemaTest.php`** — proves `version_1` builds both tables on the in-memory PDO

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class SchemaTest extends Base
{
    public function testVersion1CreatesBothTables()
    {
        require_once __DIR__.'/../Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($pdo);

        // Insert + read back a row from each table to prove they exist with the expected columns.
        $pdo->exec('INSERT INTO scheduler_runs (started_at, trigger, moved_count, is_dry_run) VALUES (100, "cli", 3, 0)');
        $runId = (int) $pdo->lastInsertId();
        $this->assertGreaterThan(0, $runId);

        $pdo->exec('INSERT INTO scheduler_moves (run_id, project_id, task_id, old_date, new_date, reason) VALUES ('.$runId.', 1, 2, 10, 20, "roll-forward")');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM scheduler_moves WHERE run_id = '.$runId)->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testVersionConstantIsOne()
    {
        require_once __DIR__.'/../Schema/Sqlite.php';
        $this->assertSame(1, \Kanboard\Plugin\SchedulerPlugin\Schema\VERSION);
    }
}
```

- [ ] **Step 10: Wire the test runner** — in `testing/run-plugin-tests.sh`, add `SchedulerPlugin` to BOTH the "Available plugins" echo line and the `for p in ...` symlink loop (alphabetical, after `ShadcnTheme` or wherever it sorts):

```bash
# both occurrences become, e.g.:
for p in BulkProjectDelete CalendarPlugin DependencyPlugin FeatureSync ModMenu SchedulerPlugin ShadcnTheme SubtaskGenerator; do
```
Use the exact spelling `SchedulerPlugin` (the line above intentionally shows placement, not spelling — write `SchedulerPlugin`).

- [ ] **Step 11: Add the Docker mount** — in `testing/docker-compose.dev.yml`, under the `volumes:` list, after the DependencyPlugin line:

```yaml
      - ../SchedulerPlugin:/var/www/app/plugins/SchedulerPlugin
```

- [ ] **Step 12: Run unit tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (PluginTest 3, SchemaTest 2 = 5 tests).

- [ ] **Step 13: Recreate the container and verify the CLI + schema live**

```bash
docker compose -f testing/docker-compose.dev.yml up -d --force-recreate kanboard
# The image re-chowns bind-mounted plugins to nginx (uid 100); chown host files back:
docker run --rm -v "$PWD":/w alpine chown -R 1001:1001 /w/SchedulerPlugin
sleep 3
docker exec kb-suite php84 /var/www/app/cli scheduler:run
docker exec kb-suite php84 /var/www/app/cli plugin:schema:version SchedulerPlugin 2>/dev/null || \
  docker exec kb-suite sh -c "php84 -r 'require \"/var/www/app/app/common.php\"; \$db=\$container[\"db\"]; var_dump(\$db->table(\"plugin_schema_versions\")->eq(\"plugin\",\"SchedulerPlugin\")->findOne());'"
```
Expected: `scheduler:run registered.` printed; `plugin_schema_versions` shows `SchedulerPlugin => 1`; the DevTools/`\dt` equivalent shows `scheduler_runs` and `scheduler_moves` tables exist in the suite DB.

- [ ] **Step 14: Commit**

```bash
git add SchedulerPlugin testing/run-plugin-tests.sh testing/docker-compose.dev.yml
git commit -m "feat(SchedulerPlugin): skeleton — CLI command, per-driver schema, Docker mount"
```

---

### Task 1: SchedulerConfigModel — typed config, project/task metadata, memoized badge lookup

**Files:**
- Create: `SchedulerPlugin/Model/SchedulerConfigModel.php`
- Test: `SchedulerPlugin/Test/SchedulerConfigModelTest.php`
- Modify: `SchedulerPlugin/Plugin.php` (register `schedulerConfigModel` container service)

**Interfaces:**
- Consumes: core `configModel`, `projectMetadataModel`, `taskMetadataModel`, `db`.
- Produces (all used by later tasks):
  - Constants `MASTER, TARGET_HOUR, WORKING_DAYS, HOLIDAYS, DECLUMP, RESPECT_BLOCKS, POST_ACTIVITY, BADGE_DAYS, LAST_RUN` (config keys), `PROJECT_META='scheduler.enabled'`, `TASK_META='scheduler.last_move'`.
  - `isMasterEnabled(): bool`, `getTargetHour(): int`, `getWorkingDays(): array` (ISO daynums 1=Mon..7=Sun), `getHolidays(): array` (list of `Y-m-d`), `getDeclumpThreshold(): int`, `respectBlocks(): bool`, `postToActivity(): bool`, `getBadgeDays(): int`
  - `getLastRun(): string`, `setLastRun(string $ymd): void`
  - `isProjectEnabled(int $projectId): bool`, `setProjectEnabled(int $projectId, bool $on): void`, `enabledProjectIds(): array`
  - `setTaskLastMove(int $taskId, string $ymd): void`, `recentlyMovedTaskIds(int $projectId): array` (memoized per project)
  - `getAllForForm(): array` (raw values for the settings template)

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class SchedulerConfigModelTest extends Base
{
    public function testDefaults()
    {
        $m = new SchedulerConfigModel($this->container);
        $this->assertFalse($m->isMasterEnabled());
        $this->assertSame(2, $m->getTargetHour());
        $this->assertSame([1, 2, 3, 4, 5], $m->getWorkingDays());
        $this->assertSame([], $m->getHolidays());
        $this->assertSame(0, $m->getDeclumpThreshold());
        $this->assertTrue($m->respectBlocks());
        $this->assertTrue($m->postToActivity());
        $this->assertSame(3, $m->getBadgeDays());
    }

    public function testRoundTripGlobals()
    {
        $m = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '5',
            SchedulerConfigModel::WORKING_DAYS => '1,2,3',
            SchedulerConfigModel::HOLIDAYS => "2026-12-25\n2026-01-01",
            SchedulerConfigModel::DECLUMP => '4',
            SchedulerConfigModel::RESPECT_BLOCKS => '0',
        ]);
        $this->assertTrue($m->isMasterEnabled());
        $this->assertSame(5, $m->getTargetHour());
        $this->assertSame([1, 2, 3], $m->getWorkingDays());
        $this->assertSame(['2026-12-25', '2026-01-01'], $m->getHolidays());
        $this->assertSame(4, $m->getDeclumpThreshold());
        $this->assertFalse($m->respectBlocks());
    }

    public function testProjectEnableToggleAndList()
    {
        $p = new ProjectModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $m = new SchedulerConfigModel($this->container);

        $this->assertFalse($m->isProjectEnabled($pid));
        $this->assertSame([], $m->enabledProjectIds());

        $m->setProjectEnabled($pid, true);
        $this->assertTrue($m->isProjectEnabled($pid));
        $this->assertSame([$pid], $m->enabledProjectIds());

        $m->setProjectEnabled($pid, false);
        $this->assertFalse($m->isProjectEnabled($pid));
        $this->assertSame([], $m->enabledProjectIds());
    }

    public function testRecentlyMovedTaskIdsWindow()
    {
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $recent = $tc->create(['project_id' => $pid, 'title' => 'recent']);
        $old = $tc->create(['project_id' => $pid, 'title' => 'old']);

        $m = new SchedulerConfigModel($this->container);
        $m->setTaskLastMove($recent, date('Y-m-d'));                       // today
        $m->setTaskLastMove($old, date('Y-m-d', time() - 10 * 86400));      // 10 days ago (outside default 3-day window)

        $ids = $m->recentlyMovedTaskIds($pid);
        $this->assertContains($recent, $ids);
        $this->assertNotContains($old, $ids);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — class `SchedulerConfigModel` not found.

- [ ] **Step 3: Implement `Model/SchedulerConfigModel.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Model;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskModel;

class SchedulerConfigModel extends Base
{
    const MASTER         = 'scheduler_enabled';
    const TARGET_HOUR    = 'scheduler_target_hour';
    const WORKING_DAYS   = 'scheduler_working_days';
    const HOLIDAYS       = 'scheduler_holidays';
    const DECLUMP        = 'scheduler_declump_threshold';
    const RESPECT_BLOCKS = 'scheduler_respect_blocks';
    const POST_ACTIVITY  = 'scheduler_post_activity';
    const BADGE_DAYS     = 'scheduler_badge_days';
    const LAST_RUN       = 'scheduler_last_run';

    const PROJECT_META   = 'scheduler.enabled';
    const TASK_META      = 'scheduler.last_move';

    private $recentCache = array();

    public function isMasterEnabled()
    {
        return $this->configModel->get(self::MASTER, '0') === '1';
    }

    public function getTargetHour()
    {
        return max(0, min(23, (int) $this->configModel->get(self::TARGET_HOUR, '2')));
    }

    public function getWorkingDays()
    {
        $raw = $this->configModel->get(self::WORKING_DAYS, '1,2,3,4,5');
        $days = array();
        foreach (explode(',', $raw) as $d) {
            $d = (int) trim($d);
            if ($d >= 1 && $d <= 7) {
                $days[] = $d;
            }
        }
        return ! empty($days) ? array_values(array_unique($days)) : array(1, 2, 3, 4, 5);
    }

    public function getHolidays()
    {
        $raw = trim($this->configModel->get(self::HOLIDAYS, ''));
        if ($raw === '') {
            return array();
        }
        $out = array();
        foreach (preg_split('/[\s,]+/', $raw) as $token) {
            $token = trim($token);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token) === 1) {
                $out[] = $token;
            }
        }
        return $out;
    }

    public function getDeclumpThreshold()
    {
        return max(0, (int) $this->configModel->get(self::DECLUMP, '0'));
    }

    public function respectBlocks()
    {
        return $this->configModel->get(self::RESPECT_BLOCKS, '1') === '1';
    }

    public function postToActivity()
    {
        return $this->configModel->get(self::POST_ACTIVITY, '1') === '1';
    }

    public function getBadgeDays()
    {
        return max(0, (int) $this->configModel->get(self::BADGE_DAYS, '3'));
    }

    public function getLastRun()
    {
        return $this->configModel->get(self::LAST_RUN, '');
    }

    public function setLastRun($ymd)
    {
        $this->configModel->save(array(self::LAST_RUN => $ymd));
    }

    public function isProjectEnabled($projectId)
    {
        return $this->projectMetadataModel->get((int) $projectId, self::PROJECT_META, '0') === '1';
    }

    public function setProjectEnabled($projectId, $on)
    {
        $this->projectMetadataModel->save((int) $projectId, array(self::PROJECT_META => $on ? '1' : '0'));
    }

    /**
     * @return int[] active projects with the opt-in flag set
     */
    public function enabledProjectIds()
    {
        $rows = $this->db->table('project_has_metadata')
            ->columns('project_has_metadata.project_id')
            ->join(ProjectModel::TABLE, 'id', 'project_id', 'project_has_metadata')
            ->eq('project_has_metadata.name', self::PROJECT_META)
            ->eq('project_has_metadata.value', '1')
            ->eq(ProjectModel::TABLE.'.is_active', ProjectModel::ACTIVE)
            ->findAllByColumn('project_id');

        return array_map('intval', $rows);
    }

    public function setTaskLastMove($taskId, $ymd)
    {
        $this->taskMetadataModel->save((int) $taskId, array(self::TASK_META => $ymd));
    }

    /**
     * Task ids in the project whose scheduler.last_move falls within the badge window.
     * One query per project, memoized — never call the metadata model per task (N+1).
     *
     * @return int[]
     */
    public function recentlyMovedTaskIds($projectId)
    {
        $projectId = (int) $projectId;
        if (array_key_exists($projectId, $this->recentCache)) {
            return $this->recentCache[$projectId];
        }

        $days = $this->getBadgeDays();
        if ($days <= 0) {
            return $this->recentCache[$projectId] = array();
        }

        $cutoff = date('Y-m-d', time() - $days * 86400);

        $rows = $this->db->table('task_has_metadata')
            ->columns('task_has_metadata.task_id')
            ->join(TaskModel::TABLE, 'id', 'task_id', 'task_has_metadata')
            ->eq('task_has_metadata.name', self::TASK_META)
            ->eq(TaskModel::TABLE.'.project_id', $projectId)
            ->gte('task_has_metadata.value', $cutoff)
            ->findAllByColumn('task_id');

        return $this->recentCache[$projectId] = array_map('intval', $rows);
    }
}
```

- [ ] **Step 4: Register the service in `Plugin.php`** — add at the very top of `initialize()`, before the CLI registration:

```php
$this->container['schedulerConfigModel'] = function ($c) {
    return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
};
```

- [ ] **Step 5: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (PluginTest 3, SchemaTest 2, SchedulerConfigModelTest 4).

- [ ] **Step 6: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): SchedulerConfigModel — typed config, project opt-in, memoized badge lookup"
```

---

### Task 2: ReschedulePolicy — pure date-planning pipeline

**Files:**
- Create: `SchedulerPlugin/Model/ReschedulePolicy.php`
- Test: `SchedulerPlugin/Test/ReschedulePolicyTest.php`

**Interfaces:**
- Consumes: nothing (pure PHP; no container, no DB).
- Produces:
  - `__construct(array $workingDays, array $holidays, int $declumpThreshold, bool $respectBlocks)`
  - `plan(array $tasks, int $todayMidnight, array $blockedMap, array $dayLoad): array` where
    - `$tasks` = list of `['id' => int, 'date_due' => int]`
    - `$todayMidnight` = timestamp of 00:00 today (caller supplies, for determinism)
    - `$blockedMap` = `map[taskId] => ['open_blockers' => int, ...]` (empty when DependencyPlugin absent)
    - `$dayLoad` = `map['Y-m-d'] => int` baseline count of already-scheduled tasks per day
    - returns list of `['task_id' => int, 'old_date' => int, 'new_date' => int, 'reason' => string, 'move' => bool]`; `reason` ∈ `roll-forward|working-day|de-clump|skipped-blocked|noop`.
  - Public helpers (unit-tested directly): `isWorkingDay(int $ts): bool`, `nextWorkingDay(int $ts): int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\ReschedulePolicy;
use KanboardTests\units\Base;

class ReschedulePolicyTest extends Base
{
    private function midnight($ymd)
    {
        return (int) (new \DateTime($ymd.' 00:00:00'))->getTimestamp();
    }

    public function testRollsOverdueToToday()
    {
        // Wed 2026-07-08 is a working day; a task overdue from 2026-07-01 rolls to today.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];

        $moves = $policy->plan($tasks, $today, [], []);
        $this->assertCount(1, $moves);
        $this->assertTrue($moves[0]['move']);
        $this->assertSame('roll-forward', $moves[0]['reason']);
        $this->assertSame($today, $moves[0]['new_date']);
    }

    public function testPreservesTimeOfDay()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $due = $this->midnight('2026-07-01') + 14 * 3600; // 14:00
        $moves = $policy->plan([['id' => 10, 'date_due' => $due]], $today, [], []);
        $this->assertSame($today + 14 * 3600, $moves[0]['new_date']);
    }

    public function testSkipsWeekendToMonday()
    {
        // Today is Sat 2026-07-11; next working day is Mon 2026-07-13.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-11');
        $moves = $policy->plan([['id' => 10, 'date_due' => $this->midnight('2026-07-01')]], $today, [], []);
        $this->assertTrue($moves[0]['move']);
        $this->assertSame('working-day', $moves[0]['reason']);
        $this->assertSame($this->midnight('2026-07-13'), $moves[0]['new_date']);
    }

    public function testSkipsHoliday()
    {
        // Today Wed 2026-07-08 is a holiday; next working day Thu 2026-07-09.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], ['2026-07-08'], 0, true);
        $today = $this->midnight('2026-07-08');
        $moves = $policy->plan([['id' => 10, 'date_due' => $this->midnight('2026-07-01')]], $today, [], []);
        $this->assertSame('working-day', $moves[0]['reason']);
        $this->assertSame($this->midnight('2026-07-09'), $moves[0]['new_date']);
    }

    public function testSkipsBlockedTask()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];
        $moves = $policy->plan($tasks, $today, [10 => ['open_blockers' => 1]], []);
        $this->assertFalse($moves[0]['move']);
        $this->assertSame('skipped-blocked', $moves[0]['reason']);
    }

    public function testRespectBlocksOffMovesBlockedTask()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, false);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];
        $moves = $policy->plan($tasks, $today, [10 => ['open_blockers' => 1]], []);
        $this->assertTrue($moves[0]['move']);
    }

    public function testDeclumpSpreadsAcrossDays()
    {
        // Threshold 2: today already has 2 scheduled; three overdue tasks must spread.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 2, true);
        $today = $this->midnight('2026-07-08'); // Wed
        $dayLoad = [date('Y-m-d', $today) => 2];
        $tasks = [
            ['id' => 1, 'date_due' => $this->midnight('2026-07-01')],
            ['id' => 2, 'date_due' => $this->midnight('2026-07-02')],
        ];
        $moves = $policy->plan($tasks, $today, [], $dayLoad);
        // Today is already full (2 >= 2) → first task goes to Thu 07-09, second to Fri 07-10.
        $this->assertSame($this->midnight('2026-07-09'), $moves[0]['new_date']);
        $this->assertSame('de-clump', $moves[0]['reason']);
        $this->assertSame($this->midnight('2026-07-10'), $moves[1]['new_date']);
    }

    public function testDeclumpDisabledWhenThresholdZero()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $dayLoad = [date('Y-m-d', $today) => 999];
        $moves = $policy->plan([['id' => 1, 'date_due' => $this->midnight('2026-07-01')]], $today, [], $dayLoad);
        $this->assertSame($today, $moves[0]['new_date']); // no spreading
    }

    public function testDeterministicOrderByDateThenId()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 1, true);
        $today = $this->midnight('2026-07-08');
        // Same due date, ids out of order — should be planned id-ascending.
        $tasks = [
            ['id' => 20, 'date_due' => $this->midnight('2026-07-01')],
            ['id' => 10, 'date_due' => $this->midnight('2026-07-01')],
        ];
        $moves = $policy->plan($tasks, $today, [], []);
        $this->assertSame(10, $moves[0]['task_id']);
        $this->assertSame(20, $moves[1]['task_id']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — class `ReschedulePolicy` not found.

- [ ] **Step 3: Implement `Model/ReschedulePolicy.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Model;

/**
 * Pure due-date planning. No DB, no container — every input is passed in, so
 * the whole policy is deterministic and unit-testable in isolation.
 */
class ReschedulePolicy
{
    private $workingDays;
    private $holidays;
    private $declumpThreshold;
    private $respectBlocks;

    public function __construct(array $workingDays, array $holidays, $declumpThreshold, $respectBlocks)
    {
        $this->workingDays = ! empty($workingDays) ? $workingDays : array(1, 2, 3, 4, 5);
        $this->holidays = array_flip($holidays); // Y-m-d => idx, for O(1) lookup
        $this->declumpThreshold = (int) $declumpThreshold;
        $this->respectBlocks = (bool) $respectBlocks;
    }

    public function isWorkingDay($ts)
    {
        $iso = (int) date('N', $ts); // 1=Mon..7=Sun
        if (! in_array($iso, $this->workingDays, true)) {
            return false;
        }
        return ! isset($this->holidays[date('Y-m-d', $ts)]);
    }

    public function nextWorkingDay($ts)
    {
        $guard = 0;
        while (! $this->isWorkingDay($ts) && $guard < 366) {
            $ts += 86400;
            $guard++;
        }
        return $ts;
    }

    /**
     * @return array list of ['task_id','old_date','new_date','reason','move']
     */
    public function plan(array $tasks, $todayMidnight, array $blockedMap, array $dayLoad)
    {
        // Deterministic: by due date ascending, then id ascending.
        usort($tasks, function ($a, $b) {
            if ($a['date_due'] === $b['date_due']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['date_due'] <=> $b['date_due'];
        });

        $load = $dayLoad; // local mutable copy
        $moves = array();

        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $oldDate = (int) $task['date_due'];

            if ($this->respectBlocks && ! empty($blockedMap[$taskId]['open_blockers'])) {
                $moves[] = $this->result($taskId, $oldDate, $oldDate, 'skipped-blocked', false);
                continue;
            }

            // Preserve original time-of-day across the day change.
            $oldMidnight = (int) (new \DateTime('@'.$oldDate))
                ->setTime(0, 0, 0)->getTimestamp();
            $timeOfDay = $oldDate - $oldMidnight;

            $reason = 'roll-forward';
            $targetDay = $todayMidnight; // snap-to-today

            $shifted = $this->nextWorkingDay($targetDay);
            if ($shifted !== $targetDay) {
                $reason = 'working-day';
                $targetDay = $shifted;
            }

            if ($this->declumpThreshold >= 1) {
                $guard = 0;
                while ((isset($load[date('Y-m-d', $targetDay)]) ? $load[date('Y-m-d', $targetDay)] : 0) >= $this->declumpThreshold && $guard < 366) {
                    $targetDay = $this->nextWorkingDay($targetDay + 86400);
                    $reason = 'de-clump';
                    $guard++;
                }
            }

            $newDate = $targetDay + $timeOfDay;

            if (date('Y-m-d', $newDate) === date('Y-m-d', $oldDate)) {
                $moves[] = $this->result($taskId, $oldDate, $oldDate, 'noop', false);
                continue;
            }

            $key = date('Y-m-d', $targetDay);
            $load[$key] = (isset($load[$key]) ? $load[$key] : 0) + 1;
            $moves[] = $this->result($taskId, $oldDate, $newDate, $reason, true);
        }

        return $moves;
    }

    private function result($taskId, $old, $new, $reason, $move)
    {
        return array(
            'task_id'  => $taskId,
            'old_date' => $old,
            'new_date' => $new,
            'reason'   => $reason,
            'move'     => $move,
        );
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds ReschedulePolicyTest 9).

- [ ] **Step 5: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): ReschedulePolicy — pure snap/working-day/de-clump/skip-blocked pipeline"
```

---

### Task 3: SchedulerLogModel — runs + moves persistence

**Files:**
- Create: `SchedulerPlugin/Model/SchedulerLogModel.php`
- Test: `SchedulerPlugin/Test/SchedulerLogModelTest.php`
- Modify: `SchedulerPlugin/Plugin.php` (register `schedulerLogModel`)

**Interfaces:**
- Consumes: core `db`.
- Produces:
  - `createRun(string $trigger, bool $isDryRun): int` (inserts started_at=time(), returns id)
  - `recordMove(int $runId, int $projectId, int $taskId, int $oldDate, int $newDate, string $reason): void`
  - `finishRun(int $runId, int $movedCount): void` (sets finished_at=time(), moved_count)
  - `getRecentRuns(int $limit = 50): array` (desc by id)
  - `getMovesForRun(int $runId): array` (asc by id)
- Table columns per Task 0 schema. Constants `RUNS='scheduler_runs'`, `MOVES='scheduler_moves'`.

- [ ] **Step 1: Write the failing test** (note the schema `setUp`)

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel;
use KanboardTests\units\Base;

class SchedulerLogModelTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
    }

    public function testCreateRecordFinishAndRead()
    {
        $m = new SchedulerLogModel($this->container);

        $runId = $m->createRun('cli', false);
        $this->assertGreaterThan(0, $runId);

        $m->recordMove($runId, 7, 42, 1000, 2000, 'roll-forward');
        $m->recordMove($runId, 7, 43, 1000, 3000, 'de-clump');
        $m->finishRun($runId, 2);

        $runs = $m->getRecentRuns(10);
        $this->assertCount(1, $runs);
        $this->assertSame(2, (int) $runs[0]['moved_count']);
        $this->assertSame('cli', $runs[0]['trigger']);
        $this->assertSame(0, (int) $runs[0]['is_dry_run']);
        $this->assertGreaterThan(0, (int) $runs[0]['finished_at']);

        $moves = $m->getMovesForRun($runId);
        $this->assertCount(2, $moves);
        $this->assertSame(42, (int) $moves[0]['task_id']);
        $this->assertSame('de-clump', $moves[1]['reason']);
    }

    public function testRecentRunsDescendingLimited()
    {
        $m = new SchedulerLogModel($this->container);
        $first = $m->createRun('web', false);
        $second = $m->createRun('manual', true);

        $runs = $m->getRecentRuns(1);
        $this->assertCount(1, $runs);
        $this->assertSame($second, (int) $runs[0]['id']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — class `SchedulerLogModel` not found.

- [ ] **Step 3: Implement `Model/SchedulerLogModel.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Model;

use Kanboard\Core\Base;

class SchedulerLogModel extends Base
{
    const RUNS  = 'scheduler_runs';
    const MOVES = 'scheduler_moves';

    public function createRun($trigger, $isDryRun)
    {
        $this->db->table(self::RUNS)->insert(array(
            'started_at'  => time(),
            'finished_at' => 0,
            'trigger'     => $trigger,
            'moved_count' => 0,
            'is_dry_run'  => $isDryRun ? 1 : 0,
        ));

        return (int) $this->db->getLastId();
    }

    public function recordMove($runId, $projectId, $taskId, $oldDate, $newDate, $reason)
    {
        $this->db->table(self::MOVES)->insert(array(
            'run_id'     => (int) $runId,
            'project_id' => (int) $projectId,
            'task_id'    => (int) $taskId,
            'old_date'   => (int) $oldDate,
            'new_date'   => (int) $newDate,
            'reason'     => $reason,
        ));
    }

    public function finishRun($runId, $movedCount)
    {
        $this->db->table(self::RUNS)->eq('id', (int) $runId)->update(array(
            'finished_at' => time(),
            'moved_count' => (int) $movedCount,
        ));
    }

    public function getRecentRuns($limit = 50)
    {
        return $this->db->table(self::RUNS)
            ->desc('id')
            ->limit((int) $limit)
            ->findAll();
    }

    public function getMovesForRun($runId)
    {
        return $this->db->table(self::MOVES)
            ->eq('run_id', (int) $runId)
            ->asc('id')
            ->findAll();
    }
}
```

- [ ] **Step 4: Register in `Plugin.php`** (after `schedulerConfigModel`):

```php
$this->container['schedulerLogModel'] = function ($c) {
    return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c);
};
```

- [ ] **Step 5: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds SchedulerLogModelTest 2).

- [ ] **Step 6: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): SchedulerLogModel — runs+moves audit persistence"
```

---

### Task 4: SchedulerRunner — orchestration (real + dry-run)

**Files:**
- Create: `SchedulerPlugin/Model/SchedulerRunner.php`
- Test: `SchedulerPlugin/Test/SchedulerRunnerTest.php`
- Modify: `SchedulerPlugin/Plugin.php` (register `schedulerRunner`)

**Interfaces:**
- Consumes: `schedulerConfigModel`, `schedulerLogModel`, core `db`, `projectActivityModel`, `taskMetadataModel`; optional `dependencyModel` (soft).
- Produces: `run(array $options = []): array` — options `dry_run` (bool, default false), `project_id` (int|null), `trigger` (string, default `cli`). Returns `['run_id'=>int|null, 'dry_run'=>bool, 'total_moved'=>int, 'projects'=>[['project_id'=>int,'moves'=>[...]]]]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFinderModel;
use KanboardTests\units\Base;

class SchedulerRunnerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
    }

    private function midnight($daysAgo)
    {
        $t = time() - $daysAgo * 86400;
        return (int) (new \DateTime('@'.$t))->setTime(0, 0, 0)->getTimestamp();
    }

    public function testMasterOffDoesNothing()
    {
        $runner = new SchedulerRunner($this->container);
        $result = $runner->run();
        $this->assertSame(0, $result['total_moved']);
        $this->assertNull($result['run_id']);
    }

    public function testRollsOverdueTaskInEnabledProject()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7', // every day is a working day → deterministic
        ]);

        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);

        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['trigger' => 'cli']);

        $this->assertSame(1, $result['total_moved']);

        $tf = new TaskFinderModel($this->container);
        $reloaded = $tf->getById($task);
        $this->assertSame(date('Y-m-d'), date('Y-m-d', (int) $reloaded['date_due'])); // now due today
        $this->assertSame(date('Y-m-d'), $cfg->recentlyMovedTaskIds($pid) ? date('Y-m-d', time()) : ''); // marker set (window contains it)
        $this->assertContains($task, $cfg->recentlyMovedTaskIds($pid));
    }

    public function testDisabledProjectUntouched()
    {
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']); // NOT enabled
        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run();
        $this->assertSame(0, $result['total_moved']);

        $tf = new TaskFinderModel($this->container);
        $this->assertSame($this->midnight(5), (int) $tf->getById($task)['date_due']); // unchanged
    }

    public function testDryRunWritesNothing()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $task = $tc->create(['project_id' => $pid, 'title' => 'overdue', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['dry_run' => true]);

        $this->assertSame(1, $result['total_moved']);      // projected
        $this->assertNull($result['run_id']);              // nothing persisted
        $tf = new TaskFinderModel($this->container);
        $this->assertSame($this->midnight(5), (int) $tf->getById($task)['date_due']); // task unchanged
        $this->assertNotContains($task, $cfg->recentlyMovedTaskIds($pid));            // no marker
        $moves = $this->container['db']->table('scheduler_moves')->findAll();
        $this->assertCount(0, $moves);
    }

    public function testProjectScopeLimitsToOne()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $a = $p->create(['name' => 'A']);
        $b = $p->create(['name' => 'B']);
        $cfg->setProjectEnabled($a, true);
        $cfg->setProjectEnabled($b, true);
        $tc->create(['project_id' => $a, 'title' => 'oa', 'date_due' => $this->midnight(5)]);
        $tc->create(['project_id' => $b, 'title' => 'ob', 'date_due' => $this->midnight(5)]);

        $runner = new SchedulerRunner($this->container);
        $result = $runner->run(['project_id' => $a]);
        $this->assertSame(1, $result['total_moved']);
        $this->assertSame($a, $result['projects'][0]['project_id']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — class `SchedulerRunner` not found.

- [ ] **Step 3: Implement `Model/SchedulerRunner.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Model;

use Kanboard\Core\Base;
use Kanboard\Model\TaskModel;

class SchedulerRunner extends Base
{
    const EVENT_NAME = 'scheduler.tasks.rescheduled';

    public function run(array $options = array())
    {
        $dryRun    = ! empty($options['dry_run']);
        $projectId = isset($options['project_id']) ? (int) $options['project_id'] : null;
        $trigger   = isset($options['trigger']) ? $options['trigger'] : 'cli';

        $config = $this->schedulerConfigModel;
        $empty = array('run_id' => null, 'dry_run' => $dryRun, 'total_moved' => 0, 'projects' => array());

        if (! $config->isMasterEnabled()) {
            return $empty;
        }

        // Resolve target projects.
        if ($projectId !== null) {
            $projectIds = $config->isProjectEnabled($projectId) ? array($projectId) : array();
        } else {
            $projectIds = $config->enabledProjectIds();
        }
        if (empty($projectIds)) {
            return $empty;
        }

        $todayMidnight = (int) (new \DateTime('today'))->getTimestamp();

        $policy = new ReschedulePolicy(
            $config->getWorkingDays(),
            $config->getHolidays(),
            $config->getDeclumpThreshold(),
            $config->respectBlocks()
        );

        $runId = null;
        if (! $dryRun) {
            $runId = $this->schedulerLogModel->createRun($trigger, false);
        }

        $projectsOut = array();
        $totalMoved = 0;

        foreach ($projectIds as $pid) {
            $tasks = $this->overdueTasks($pid, $todayMidnight);
            if (empty($tasks)) {
                continue;
            }

            $blockedMap = $this->blockedMap($pid);
            $dayLoad = $this->dayLoad($pid, $todayMidnight);

            $planned = $policy->plan($tasks, $todayMidnight, $blockedMap, $dayLoad);
            $moved = array();

            foreach ($planned as $move) {
                if (! $move['move']) {
                    continue;
                }

                if (! $dryRun) {
                    $this->applyMove($move['task_id'], $move['new_date']);
                    $this->schedulerLogModel->recordMove($runId, $pid, $move['task_id'], $move['old_date'], $move['new_date'], $move['reason']);
                }
                $moved[] = $move;
            }

            if (! empty($moved)) {
                $projectsOut[] = array('project_id' => (int) $pid, 'moves' => $moved);
                $totalMoved += count($moved);

                if (! $dryRun && $config->postToActivity()) {
                    $this->projectActivityModel->createEvent((int) $pid, 0, 0, self::EVENT_NAME, array(
                        'count'  => count($moved),
                        'run_id' => $runId,
                    ));
                }
            }
        }

        if (! $dryRun) {
            $this->schedulerLogModel->finishRun($runId, $totalMoved);
        }

        return array(
            'run_id'      => $runId,
            'dry_run'     => $dryRun,
            'total_moved' => $totalMoved,
            'projects'    => $projectsOut,
        );
    }

    /**
     * Overdue = open, has a due date, strictly before today's midnight.
     *
     * @return array list of ['id','date_due']
     */
    private function overdueTasks($projectId, $todayMidnight)
    {
        return $this->db->table(TaskModel::TABLE)
            ->columns('id', 'date_due')
            ->eq('project_id', (int) $projectId)
            ->eq('is_active', TaskModel::STATUS_OPEN)
            ->neq('date_due', 0)
            ->lt('date_due', $todayMidnight)
            ->findAll();
    }

    /**
     * Baseline per-day load for de-clump: open tasks already due today or later.
     *
     * @return array map Y-m-d => count
     */
    private function dayLoad($projectId, $todayMidnight)
    {
        $rows = $this->db->table(TaskModel::TABLE)
            ->columns('date_due')
            ->eq('project_id', (int) $projectId)
            ->eq('is_active', TaskModel::STATUS_OPEN)
            ->neq('date_due', 0)
            ->gte('date_due', $todayMidnight)
            ->findAll();

        $load = array();
        foreach ($rows as $r) {
            $k = date('Y-m-d', (int) $r['date_due']);
            $load[$k] = (isset($load[$k]) ? $load[$k] : 0) + 1;
        }
        return $load;
    }

    /**
     * @return array map taskId => ['open_blockers'=>int] (empty when DependencyPlugin absent)
     */
    private function blockedMap($projectId)
    {
        if (! $this->schedulerConfigModel->respectBlocks() || ! isset($this->container['dependencyModel'])) {
            return array();
        }
        return $this->container['dependencyModel']->getProjectBlockedMap((int) $projectId);
    }

    /**
     * Write the new due date directly — deliberately NOT via TaskModificationModel,
     * to avoid one core per-task activity event per move (see plan Global Constraints).
     */
    private function applyMove($taskId, $newDate)
    {
        $this->db->table(TaskModel::TABLE)
            ->eq('id', (int) $taskId)
            ->update(array(
                'date_due'          => (int) $newDate,
                'date_modification' => time(),
            ));

        $this->schedulerConfigModel->setTaskLastMove((int) $taskId, date('Y-m-d'));
    }
}
```

- [ ] **Step 4: Register in `Plugin.php`** (after `schedulerLogModel`):

```php
$this->container['schedulerRunner'] = function ($c) {
    return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
};
```

- [ ] **Step 5: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds SchedulerRunnerTest 5).

- [ ] **Step 6: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): SchedulerRunner — sweep orchestration, direct writes, per-run activity summary"
```

---

### Task 5: Wire the console command to the runner

**Files:**
- Modify: `SchedulerPlugin/Console/SchedulerRunCommand.php`
- Test: `SchedulerPlugin/Test/SchedulerRunCommandTest.php`

**Interfaces:**
- Consumes: `schedulerRunner::run()`.
- Produces: `scheduler:run [--dry-run] [--project=ID]` prints a summary and exits 0.

- [ ] **Step 1: Write the failing test** (drive the command through Symfony's tester)

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Console\SchedulerRunCommand;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SchedulerRunCommandTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
        $this->container['schedulerConfigModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
        };
        $this->container['schedulerLogModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c);
        };
        $this->container['schedulerRunner'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
        };
    }

    private function tester()
    {
        $app = new Application();
        $app->add(new SchedulerRunCommand($this->container));
        return new CommandTester($app->find('scheduler:run'));
    }

    public function testReportsNothingWhenMasterOff()
    {
        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('0', $tester->getDisplay());
    }

    public function testDryRunReportsProjectedCountWithoutWriting()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $tc->create(['project_id' => $pid, 'title' => 'o', 'date_due' => (int) (new \DateTime('today'))->getTimestamp() - 5 * 86400]);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('1', $tester->getDisplay());
        $this->assertStringContainsString('dry', strtolower($tester->getDisplay()));
        $this->assertCount(0, $this->container['db']->table('scheduler_moves')->findAll());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — output does not yet include projected count / "dry".

- [ ] **Step 3: Implement the command body** — replace `execute()` in `Console/SchedulerRunCommand.php`

```php
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = array(
            'dry_run' => (bool) $input->getOption('dry-run'),
            'trigger' => 'cli',
        );
        if ($input->getOption('project')) {
            $options['project_id'] = (int) $input->getOption('project');
        }

        $result = $this->schedulerRunner->run($options);

        $mode = $result['dry_run'] ? 'DRY-RUN' : 'applied';
        $output->writeln(sprintf('<info>Scheduler %s:</info> %d task(s) across %d project(s).', $mode, $result['total_moved'], count($result['projects'])));

        foreach ($result['projects'] as $p) {
            $output->writeln(sprintf('  project %d: %d move(s)', $p['project_id'], count($p['moves'])));
        }

        return 0;
    }
```

- [ ] **Step 4: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds SchedulerRunCommandTest 2).

- [ ] **Step 5: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): scheduler:run CLI wired to runner with --dry-run/--project"
```

---

### Task 6: WebCronTrigger — guarded once-per-day web trigger

**Files:**
- Create: `SchedulerPlugin/Trigger/WebCronTrigger.php`
- Create: `SchedulerPlugin/Template/layout/webcron.php` (empty render target)
- Test: `SchedulerPlugin/Test/WebCronTriggerTest.php`
- Modify: `SchedulerPlugin/Plugin.php` (register the hook)

**Interfaces:**
- Consumes: `schedulerConfigModel`, `schedulerRunner`.
- Produces: `maybeRun(): bool` (returns true iff it fired the sweep). Guard: master on, current hour ≥ target hour, `last_run < today`; stamps `last_run = today` **before** running so concurrent requests don't double-fire.

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Trigger\WebCronTrigger;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use KanboardTests\units\Base;

class WebCronTriggerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['schedulerConfigModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
        };
        $this->container['schedulerRunner'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
        };
    }

    public function testDoesNotFireWhenMasterOff()
    {
        $trigger = new WebCronTrigger($this->container);
        $this->assertFalse($trigger->maybeRun());
    }

    public function testDoesNotFireWhenAlreadyRanToday()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '0', // any hour qualifies
        ]);
        $cfg->setLastRun(date('Y-m-d')); // already ran today
        $trigger = new WebCronTrigger($this->container);
        $this->assertFalse($trigger->maybeRun());
    }

    public function testFiresAndStampsLastRunWhenDue()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([
            SchedulerConfigModel::MASTER => '1',
            SchedulerConfigModel::TARGET_HOUR => '0',
        ]);
        $cfg->setLastRun(date('Y-m-d', time() - 86400)); // last ran yesterday

        $trigger = new WebCronTrigger($this->container);
        $this->assertTrue($trigger->maybeRun());
        $this->assertSame(date('Y-m-d'), $cfg->getLastRun()); // stamped today

        // Second call same day is a no-op.
        $this->assertFalse($trigger->maybeRun());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — class `WebCronTrigger` not found.

- [ ] **Step 3: Implement `Trigger/WebCronTrigger.php`**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Trigger;

use Kanboard\Core\Base;

/**
 * Lazy web-cron: fires the daily sweep at most once per day, on the first
 * rendered page request past the configured target hour. Stamps last_run
 * BEFORE running so a burst of concurrent requests cannot double-fire.
 */
class WebCronTrigger extends Base
{
    public function maybeRun()
    {
        $config = $this->schedulerConfigModel;

        if (! $config->isMasterEnabled()) {
            return false;
        }

        $today = date('Y-m-d');
        if ($config->getLastRun() === $today) {
            return false;
        }

        if ((int) date('G') < $config->getTargetHour()) {
            return false;
        }

        // Claim the day first, then run.
        $config->setLastRun($today);
        $this->schedulerRunner->run(array('trigger' => 'web'));

        return true;
    }
}
```

- [ ] **Step 4: `Template/layout/webcron.php`** — the hook renders a template; keep it empty (a single blank line is fine). Content:

```php
<?php /* SchedulerPlugin web-cron hook target — the side effect runs in the callable; nothing to render. */ ?>
```

- [ ] **Step 5: Register the hook in `Plugin.php`** — add inside `initialize()` after the service registrations. Capture `$container` in a local so the closure resolves lazily:

```php
$container = $this->container;
$this->hook->on('template:layout:top', array(
    'template' => 'SchedulerPlugin:layout/webcron',
    'callable' => function () use ($container) {
        (new \Kanboard\Plugin\SchedulerPlugin\Trigger\WebCronTrigger($container))->maybeRun();
        return array();
    },
));
```

- [ ] **Step 6: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds WebCronTriggerTest 3).

- [ ] **Step 7: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): lazy web-cron trigger (guarded once/day) + layout hook"
```

---

### Task 7: Settings page + Run-now/dry-run + routes + config sidebar + activity event

**Files:**
- Create: `SchedulerPlugin/Controller/SchedulerController.php` (settings/save/run methods)
- Create: `SchedulerPlugin/Template/config/settings.php`, `SchedulerPlugin/Template/config/sidebar.php`
- Create: `SchedulerPlugin/Template/event/tasks_rescheduled.php`
- Create: `SchedulerPlugin/Assets/css/scheduler.css`
- Modify: `SchedulerPlugin/Plugin.php` (routes, config sidebar hook, event register + template override, sitewide CSS)
- Test: `SchedulerPlugin/Test/SchedulerControllerTest.php` (dry-run preview count via the runner path — controller instantiation in unit tests is heavy, so this task's automated test covers the runner-facing helper; full HTTP verification happens in the Task 10 E2E)

**Interfaces:**
- Consumes: `schedulerConfigModel`, `schedulerRunner`.
- Produces routes: `scheduler/settings` (GET), `scheduler/save` (POST), `scheduler/run` (POST). Admin-gated.

- [ ] **Step 1: Implement `Controller/SchedulerController.php` (settings/save/run)**

```php
<?php

namespace Kanboard\Plugin\SchedulerPlugin\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;

class SchedulerController extends BaseController
{
    private function requireAdmin()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    public function settings()
    {
        $this->requireAdmin();
        $c = $this->schedulerConfigModel;

        $this->response->html($this->helper->layout->config('SchedulerPlugin:config/settings', array(
            'title'          => t('Settings').' &gt; '.t('Scheduler'),
            'master'         => $c->isMasterEnabled(),
            'target_hour'    => $c->getTargetHour(),
            'working_days'   => implode(',', $c->getWorkingDays()),
            'holidays'       => implode("\n", $c->getHolidays()),
            'declump'        => $c->getDeclumpThreshold(),
            'respect_blocks' => $c->respectBlocks(),
            'post_activity'  => $c->postToActivity(),
            'badge_days'     => $c->getBadgeDays(),
            'last_run'       => $c->getLastRun(),
        )));
    }

    public function save()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $v = $this->request->getValues();

        $this->configModel->save(array(
            SchedulerConfigModel::MASTER         => empty($v['master']) ? '0' : '1',
            SchedulerConfigModel::TARGET_HOUR    => (string) max(0, min(23, (int) ($v['target_hour'] ?? 2))),
            SchedulerConfigModel::WORKING_DAYS   => trim($v['working_days'] ?? '1,2,3,4,5'),
            SchedulerConfigModel::HOLIDAYS       => trim($v['holidays'] ?? ''),
            SchedulerConfigModel::DECLUMP        => (string) max(0, (int) ($v['declump'] ?? 0)),
            SchedulerConfigModel::RESPECT_BLOCKS => empty($v['respect_blocks']) ? '0' : '1',
            SchedulerConfigModel::POST_ACTIVITY  => empty($v['post_activity']) ? '0' : '1',
            SchedulerConfigModel::BADGE_DAYS     => (string) max(0, (int) ($v['badge_days'] ?? 3)),
        ));

        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SchedulerController', 'settings', array('plugin' => 'SchedulerPlugin')));
    }

    public function run()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();

        $dryRun = $this->request->getStringParam('dry_run') === '1';
        $result = $this->schedulerRunner->run(array('dry_run' => $dryRun, 'trigger' => 'manual'));

        if ($dryRun) {
            $this->flash->success(t('Dry run: %d task(s) would be rescheduled.', $result['total_moved']));
        } else {
            $this->flash->success(t('Rescheduled %d task(s).', $result['total_moved']));
        }

        $this->response->redirect($this->helper->url->to('SchedulerController', 'settings', array('plugin' => 'SchedulerPlugin')));
    }
}
```

- [ ] **Step 2: `Template/config/settings.php`** (form; buttons post to run with/without dry_run; all inline styles only, no inline JS)

```php
<div class="page-header">
    <h2><?= t('Scheduler') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('SchedulerController', 'save', array('plugin' => 'SchedulerPlugin')) ?>">
    <?= $this->form->csrf() ?>

    <?= $this->form->checkbox('master', t('Enable the scheduler (master switch)'), '1', $master) ?>

    <?= $this->form->label(t('Daily target hour (0–23)'), 'target_hour') ?>
    <?= $this->form->number('target_hour', array('target_hour' => $target_hour), array(), array('min="0"', 'max="23"')) ?>

    <?= $this->form->label(t('Working days (ISO: 1=Mon … 7=Sun, comma-separated)'), 'working_days') ?>
    <?= $this->form->text('working_days', array('working_days' => $working_days)) ?>

    <?= $this->form->label(t('Holidays (one YYYY-MM-DD per line)'), 'holidays') ?>
    <?= $this->form->textarea('holidays', array('holidays' => $holidays)) ?>

    <?= $this->form->label(t('De-clump threshold (0 = off; max tasks per day before spreading)'), 'declump') ?>
    <?= $this->form->number('declump', array('declump' => $declump), array(), array('min="0"')) ?>

    <?= $this->form->label(t('Calendar badge window (days)'), 'badge_days') ?>
    <?= $this->form->number('badge_days', array('badge_days' => $badge_days), array(), array('min="0"')) ?>

    <?= $this->form->checkbox('respect_blocks', t('Skip tasks that have open blockers (DependencyPlugin)'), '1', $respect_blocks) ?>
    <?= $this->form->checkbox('post_activity', t('Post a per-run summary to the project activity stream'), '1', $post_activity) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>

<hr>

<h3><?= t('Run now') ?></h3>
<?php if (! empty($last_run)): ?>
    <p class="text-muted"><?= t('Last automatic run:') ?> <?= $this->text->e($last_run) ?></p>
<?php endif ?>

<div style="display:flex; gap:.5rem;">
    <form method="post" action="<?= $this->url->href('SchedulerController', 'run', array('plugin' => 'SchedulerPlugin', 'dry_run' => '1')) ?>">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-default"><?= t('Preview (dry run)') ?></button>
    </form>
    <form method="post" action="<?= $this->url->href('SchedulerController', 'run', array('plugin' => 'SchedulerPlugin')) ?>">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-red" onclick="return confirm('<?= t('Reschedule overdue tasks now?') ?>')"><?= t('Run now') ?></button>
    </form>
</div>
```

Note on the `confirm()` attribute: Kanboard core templates use `js-modal-confirm`/data-attributes; inline `onclick` is blocked by CSP. Use core's confirmation-link pattern instead — render the "Run now" action as `$this->url->link()` with `array('class' => 'btn btn-red', 'data-confirm' => '...')` **only if** a core JS handler exists; otherwise drop the confirm and rely on the flash result. The reviewer must verify no inline handler ships. Safe default: omit `onclick` entirely (both buttons just submit).

- [ ] **Step 3: `Template/config/sidebar.php`**

```php
<li>
    <?= $this->url->link(t('Scheduler'), 'SchedulerController', 'settings', array('plugin' => 'SchedulerPlugin')) ?>
</li>
```

- [ ] **Step 4: `Template/event/tasks_rescheduled.php`** (activity-stream fragment; `$data` carries the json-decoded payload; system actor, distinct look)

```php
<p class="activity-title">
    <span title="<?= t('Automated') ?>">&#9200;</span>
    <?= t('Scheduler rescheduled %d task(s)', isset($data['count']) ? (int) $data['count'] : 0) ?>
</p>
<p class="activity-description text-muted">
    <?= $this->dt->datetime($date_creation) ?>
</p>
```

- [ ] **Step 5: `Assets/css/scheduler.css`** (tiny, sitewide; badge + activity marker)

```css
.sch-moved { opacity: .85; }
.cal-ev .sch-moved,
.fc-daygrid-event .sch-moved { margin-left: .15em; }
.activity-title span[title] { margin-right: .25em; }
```

- [ ] **Step 6: Wire routes + sidebar + event + CSS in `Plugin.php`** — add inside `initialize()`:

```php
// Admin settings routes.
$this->route->addRoute('scheduler/settings', 'SchedulerController', 'settings', 'SchedulerPlugin');
$this->route->addRoute('scheduler/save', 'SchedulerController', 'save', 'SchedulerPlugin');
$this->route->addRoute('scheduler/run', 'SchedulerController', 'run', 'SchedulerPlugin');

// Config sidebar link.
$this->hook->on('template:config:sidebar', array('template' => 'SchedulerPlugin:config/sidebar'));

// Activity-stream event: register the name and point its render at our template.
$this->eventManager->register('scheduler.tasks.rescheduled', t('Automatically rescheduled tasks'));
$this->template->setTemplateOverride('event/scheduler_tasks_rescheduled', 'SchedulerPlugin:event/tasks_rescheduled');

// Tiny sitewide CSS (badge + activity marker) — mirrors DependencyPlugin's decision.
$this->hook->on('template:layout:css', array('template' => 'plugins/SchedulerPlugin/Assets/css/scheduler.css'));
```

- [ ] **Step 7: Write a controller-facing test** — `Test/SchedulerControllerTest.php` verifies the runner path the controller calls (dry-run count) without booting the HTTP layer:

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class SchedulerControllerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
        $this->container['schedulerConfigModel'] = function ($c) { return new SchedulerConfigModel($c); };
        $this->container['schedulerLogModel'] = function ($c) { return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c); };
        $this->container['schedulerRunner'] = function ($c) { return new SchedulerRunner($c); };
    }

    public function testManualDryRunProducesCountWithoutWriting()
    {
        $cfg = new SchedulerConfigModel($this->container);
        $this->container['configModel']->save([SchedulerConfigModel::MASTER => '1', SchedulerConfigModel::WORKING_DAYS => '1,2,3,4,5,6,7']);
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $cfg->setProjectEnabled($pid, true);
        $tc->create(['project_id' => $pid, 'title' => 'o', 'date_due' => (int) (new \DateTime('today'))->getTimestamp() - 5 * 86400]);

        $result = $this->container['schedulerRunner']->run(['dry_run' => true, 'trigger' => 'manual']);
        $this->assertSame(1, $result['total_moved']);
        $this->assertCount(0, $this->container['db']->table('scheduler_moves')->findAll());
    }
}
```

- [ ] **Step 8: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds SchedulerControllerTest 1).

- [ ] **Step 9: Verify the form helpers used exist** — confirm `$this->form->number(...)` and `$this->form->textarea(...)` signatures against `testing/kanboard-src/app/Helper/FormHelper.php`; adjust argument arrays to match core (the reviewer checks this). Then commit:

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): admin settings page, Run-now/dry-run, config sidebar, activity event"
```

---

### Task 8: Log pages + per-project toggle

**Files:**
- Modify: `SchedulerPlugin/Controller/SchedulerController.php` (add `log`, `runDetail`, `toggleProject`)
- Create: `SchedulerPlugin/Template/log/index.php`, `SchedulerPlugin/Template/log/run.php`, `SchedulerPlugin/Template/project/toggle.php`
- Modify: `SchedulerPlugin/Plugin.php` (routes + project sidebar hook + config sidebar link to log)
- Test: extend `SchedulerPlugin/Test/SchedulerLogModelTest.php` is already covered; add a toggle test in `SchedulerConfigModelTest` is covered. Add `Test/SchedulerToggleTest.php` for the project-scoped auth boundary via config model (HTTP auth verified in E2E).

**Interfaces:**
- Consumes: `schedulerLogModel::getRecentRuns/getMovesForRun`, `schedulerConfigModel::isProjectEnabled/setProjectEnabled`.
- Produces routes: `scheduler/log` (GET admin), `scheduler/log/run` (GET admin), `scheduler/project/toggle` (POST manager/admin).

- [ ] **Step 1: Add controller methods** to `SchedulerController.php`

```php
    public function log()
    {
        $this->requireAdmin();
        $this->response->html($this->helper->layout->config('SchedulerPlugin:log/index', array(
            'title' => t('Scheduler').' &gt; '.t('Log'),
            'runs'  => $this->schedulerLogModel->getRecentRuns(50),
        )));
    }

    public function runDetail()
    {
        $this->requireAdmin();
        $runId = $this->request->getIntegerParam('run_id');
        $this->response->html($this->helper->layout->config('SchedulerPlugin:log/run', array(
            'title'  => t('Scheduler').' &gt; '.t('Run #%d', $runId),
            'run_id' => $runId,
            'moves'  => $this->schedulerLogModel->getMovesForRun($runId),
        )));
    }

    public function toggleProject()
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $project = $this->projectModel->getById($projectId);
        if (empty($project)) {
            throw new AccessForbiddenException();
        }

        // Manager or admin on THIS project.
        if (! $this->userSession->isAdmin() &&
            $this->projectUserRoleModel->getUserRole($projectId, $this->userSession->getId()) !== \Kanboard\Core\Security\Role::PROJECT_MANAGER) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();
        $enable = ! $this->schedulerConfigModel->isProjectEnabled($projectId);
        $this->schedulerConfigModel->setProjectEnabled($projectId, $enable);

        $this->flash->success($enable ? t('Auto-reschedule enabled for this project.') : t('Auto-reschedule disabled for this project.'));
        $this->response->redirect($this->helper->url->to('ProjectViewController', 'show', array('project_id' => $projectId)));
    }
```

(Add `use Kanboard\Core\Security\Role;` if you prefer; the fully-qualified reference above also works.)

- [ ] **Step 2: `Template/log/index.php`**

```php
<div class="page-header"><h2><?= t('Scheduler log') ?></h2></div>

<?php if (empty($runs)): ?>
    <p class="alert"><?= t('No runs recorded yet.') ?></p>
<?php else: ?>
<table class="table-striped">
    <tr>
        <th><?= t('Run') ?></th>
        <th><?= t('Started') ?></th>
        <th><?= t('Trigger') ?></th>
        <th><?= t('Moved') ?></th>
        <th><?= t('Mode') ?></th>
    </tr>
    <?php foreach ($runs as $run): ?>
    <tr>
        <td><?= $this->url->link('#'.$run['id'], 'SchedulerController', 'runDetail', array('plugin' => 'SchedulerPlugin', 'run_id' => $run['id'])) ?></td>
        <td><?= $this->dt->datetime($run['started_at']) ?></td>
        <td><?= $this->text->e($run['trigger']) ?></td>
        <td><?= (int) $run['moved_count'] ?></td>
        <td><?= ((int) $run['is_dry_run']) ? t('dry-run') : t('applied') ?></td>
    </tr>
    <?php endforeach ?>
</table>
<?php endif ?>
```

- [ ] **Step 3: `Template/log/run.php`**

```php
<div class="page-header"><h2><?= t('Run #%d', $run_id) ?></h2></div>

<?php if (empty($moves)): ?>
    <p class="alert"><?= t('No moves in this run.') ?></p>
<?php else: ?>
<table class="table-striped">
    <tr>
        <th><?= t('Task') ?></th>
        <th><?= t('Project') ?></th>
        <th><?= t('From') ?></th>
        <th><?= t('To') ?></th>
        <th><?= t('Reason') ?></th>
    </tr>
    <?php foreach ($moves as $move): ?>
    <tr>
        <td>#<?= (int) $move['task_id'] ?></td>
        <td>#<?= (int) $move['project_id'] ?></td>
        <td><?= $this->dt->date($move['old_date']) ?></td>
        <td><?= $this->dt->date($move['new_date']) ?></td>
        <td><?= $this->text->e($move['reason']) ?></td>
    </tr>
    <?php endforeach ?>
</table>
<?php endif ?>
```

- [ ] **Step 4: `Template/project/toggle.php`** (rendered in the project sidebar; `$project` provided by the hook)

```php
<?php $enabled = $this->schedulerConfigModel->isProjectEnabled($project['id']); ?>
<li>
    <form method="post" action="<?= $this->url->href('SchedulerController', 'toggleProject', array('plugin' => 'SchedulerPlugin', 'project_id' => $project['id'])) ?>" style="display:inline;">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-link" style="padding:0;">
            <?= $enabled ? t('Disable auto-reschedule') : t('Enable auto-reschedule') ?>
        </button>
    </form>
</li>
```

Note: templates cannot resolve `$this->schedulerConfigModel` (templates are not the container). Instead the hook must pass `enabled` in. Register the sidebar hook with a callable that computes it (Step 5), and in the template read `$enabled` from the passed variables rather than calling the model.

Corrected `Template/project/toggle.php`:

```php
<li>
    <form method="post" action="<?= $this->url->href('SchedulerController', 'toggleProject', array('plugin' => 'SchedulerPlugin', 'project_id' => $project['id'])) ?>" style="display:inline;">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-link" style="padding:0;">
            <?= $enabled ? t('Disable auto-reschedule') : t('Enable auto-reschedule') ?>
        </button>
    </form>
</li>
```

- [ ] **Step 5: Wire routes + project sidebar (callable) + log sidebar in `Plugin.php`**

```php
$this->route->addRoute('scheduler/log', 'SchedulerController', 'log', 'SchedulerPlugin');
$this->route->addRoute('scheduler/log/run', 'SchedulerController', 'runDetail', 'SchedulerPlugin');
$this->route->addRoute('scheduler/project/toggle', 'SchedulerController', 'toggleProject', 'SchedulerPlugin');

$container = $this->container; // if not already captured above
$this->hook->on('template:project:sidebar', array(
    'template' => 'SchedulerPlugin:project/toggle',
    'callable' => function ($project) use ($container) {
        return array('enabled' => $container['schedulerConfigModel']->isProjectEnabled($project['id']));
    },
));
```

Add a second `<li>` to `Template/config/sidebar.php` linking to the log:

```php
<li>
    <?= $this->url->link(t('Scheduler log'), 'SchedulerController', 'log', array('plugin' => 'SchedulerPlugin')) ?>
</li>
```

- [ ] **Step 6: `Test/SchedulerToggleTest.php`** (config-model enable/disable round trip through the same path the controller uses)

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use KanboardTests\units\Base;

class SchedulerToggleTest extends Base
{
    public function testToggleFlipsProjectFlag()
    {
        $p = new ProjectModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $m = new SchedulerConfigModel($this->container);

        $enable = ! $m->isProjectEnabled($pid);
        $m->setProjectEnabled($pid, $enable);
        $this->assertTrue($m->isProjectEnabled($pid));

        $enable = ! $m->isProjectEnabled($pid);
        $m->setProjectEnabled($pid, $enable);
        $this->assertFalse($m->isProjectEnabled($pid));
    }
}
```

- [ ] **Step 7: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds SchedulerToggleTest 1).

- [ ] **Step 8: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): admin log pages + per-project enable toggle"
```

---

### Task 9: CalendarPlugin auto-moved badge (decorator)

**Files:**
- Modify: `SchedulerPlugin/Plugin.php` (append a `calendarEventDecorators` closure)
- Test: `SchedulerPlugin/Test/CalendarDecoratorTest.php`

**Interfaces:**
- Consumes: `schedulerConfigModel::recentlyMovedTaskIds`, CalendarPlugin's `calendarEventDecorators` container key (soft — absent means our closure simply never runs).
- Produces: appends a closure that pushes `['text' => "\u{23F0}", 'cls' => 'sch-moved']` onto `$event['extendedProps']['badges']` when the task is in the project's recently-moved set.

- [ ] **Step 1: Write the failing test** — exercises the decorator closure directly

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Plugin;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use KanboardTests\units\Base;

class CalendarDecoratorTest extends Base
{
    public function testDecoratorAddsBadgeForRecentlyMovedTask()
    {
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $task = $tc->create(['project_id' => $pid, 'title' => 't']);

        // Register config model + mark the task moved today.
        $this->container['schedulerConfigModel'] = function ($c) { return new SchedulerConfigModel($c); };
        $this->container['schedulerConfigModel']->setTaskLastMove($task, date('Y-m-d'));

        // Initialize the plugin so it appends the decorator.
        (new Plugin($this->container))->initialize();
        $decorators = $this->container['calendarEventDecorators'];
        $this->assertNotEmpty($decorators);

        $event = ['id' => $task, 'extendedProps' => ['badges' => []]];
        $row = ['project_id' => $pid];
        foreach ($decorators as $d) {
            $event = call_user_func($d, $event, $row);
        }

        $texts = array_column($event['extendedProps']['badges'], 'text');
        $this->assertContains("\u{23F0}", $texts);
    }

    public function testDecoratorSkipsUnmovedTask()
    {
        $p = new ProjectModel($this->container);
        $tc = new TaskCreationModel($this->container);
        $pid = $p->create(['name' => 'P']);
        $task = $tc->create(['project_id' => $pid, 'title' => 't']); // never moved

        (new Plugin($this->container))->initialize();
        $event = ['id' => $task, 'extendedProps' => ['badges' => []]];
        foreach ($this->container['calendarEventDecorators'] as $d) {
            $event = call_user_func($d, $event, ['project_id' => $pid]);
        }
        $this->assertEmpty($event['extendedProps']['badges']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: FAIL — no decorator appended yet.

- [ ] **Step 3: Append the decorator in `Plugin.php`** (mirror DependencyPlugin's `array_merge` pattern; capture `$this` so `schedulerConfigModel` resolves lazily)

```php
$this->container['calendarEventDecorators'] = array_merge(
    isset($this->container['calendarEventDecorators']) ? $this->container['calendarEventDecorators'] : array(),
    array(function (array $event, array $row) {
        $projectId = (int) (isset($row['project_id']) ? $row['project_id'] : 0);
        $recent = $this->schedulerConfigModel->recentlyMovedTaskIds($projectId);
        if (in_array((int) $event['id'], $recent, true)) {
            if (! isset($event['extendedProps']['badges'])) {
                $event['extendedProps']['badges'] = array();
            }
            $event['extendedProps']['badges'][] = array('text' => "\u{23F0}", 'cls' => 'sch-moved');
        }
        return $event;
    })
);
```

- [ ] **Step 4: Run tests**

Run: `./testing/run-plugin-tests.sh SchedulerPlugin`
Expected: PASS (adds CalendarDecoratorTest 2).

- [ ] **Step 5: Commit**

```bash
git add SchedulerPlugin
git commit -m "feat(SchedulerPlugin): calendar auto-moved badge via calendarEventDecorators"
```

---

### Task 10: Docs, packaging metadata, and live E2E on the Docker suite

**Files:**
- Create: `SchedulerPlugin/README.md`, `SchedulerPlugin/CHANGELOG.md`
- Verify: `SchedulerPlugin/plugin.json` (version `1.0.0`, description final)

**Interfaces:** none (documentation + verification).

- [ ] **Step 1: `README.md`** — cover: what it does; per-project opt-in; the policy pipeline (skip-blocked → today → working-day → de-clump); the three triggers (lazy web-cron, Run-now, `./cli scheduler:run`); the audit log + activity summary; the calendar badge; config keys + defaults; cross-plugin soft dependencies (DependencyPlugin/CalendarPlugin optional); safety notes (opt-in, dry-run, idempotent). Include a "Cross-plugin" section documenting that it appends to `calendarEventDecorators` and consumes `dependencyModel::getProjectBlockedMap`.

- [ ] **Step 2: `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to the SchedulerPlugin will be documented in this file.

## [1.0.0] - 2026-07-07

### Added
- Daily sweep that rolls overdue, still-open tasks forward, per **opt-in project**.
- Policy pipeline: skip tasks with open blockers (DependencyPlugin), snap to today,
  shift off weekends/holidays (working-days), and de-clump days over a threshold.
- Three triggers wrapping one `SchedulerRunner`: lazy web-cron (guarded once/day),
  an admin **Run now** button with **dry-run preview**, and `./cli scheduler:run`.
- Audit: `scheduler_runs` + `scheduler_moves` tables with an admin **log page**,
  plus one clearly-automated per-run **activity-stream summary** (system actor).
- **Calendar auto-moved badge** via CalendarPlugin's `calendarEventDecorators` hook.
- Admin settings page (master switch, target hour, working days, holidays, de-clump
  threshold, badge window, respect-blocks, post-to-activity) and per-project toggle.
```

- [ ] **Step 3: Recreate the container and chown** (bind mount already added in Task 0)

```bash
docker compose -f testing/docker-compose.dev.yml up -d --force-recreate kanboard
docker run --rm -v "$PWD":/w alpine chown -R 1001:1001 /w/SchedulerPlugin
sleep 3
```

- [ ] **Step 4: Live E2E** (drive the real site on `:8081`, admin/admin). Perform and record evidence for each:
  1. Enable the plugin is automatic (bind-mounted); visit **Settings → Scheduler**, turn the master switch **on**, set working days to `1,2,3,4,5,6,7` (so the run is deterministic today), Save.
  2. Create a project; on its sidebar click **Enable auto-reschedule**.
  3. Seed 2–3 overdue open tasks (past `date_due`) via JSON-RPC; make one **blocked** (DependencyPlugin link "is blocked by" an open task).
  4. Click **Preview (dry run)** — flash reports N tasks *would* move; confirm the tasks' due dates are UNCHANGED and `scheduler_moves` is still empty.
  5. Click **Run now** — flash reports N rescheduled; confirm the non-blocked overdue tasks are now due **today**, the blocked one is **untouched**.
  6. **Scheduler log** → the run appears; open it → per-move rows show old→new date + reason.
  7. Open the project **activity stream** → exactly one "⏰ Scheduler rescheduled N tasks" entry (system actor), not one per task.
  8. Open the **calendar** (CalendarPlugin) → moved tasks show the ⏰ badge.
  9. `docker exec kb-suite php84 /var/www/app/cli scheduler:run --dry-run` prints a summary and writes nothing.
  10. Repeat 4–8 with **ShadcnTheme** active (settings page, log page, calendar badge render cleanly).
  - For every page, capture console errors and assert only the known baseline (`/fciconsfont|favicon|data:application\/x-font|data:font/i`) appears — zero non-baseline errors.

- [ ] **Step 5: Full suite regression** — confirm sibling plugins still green:

```bash
./testing/run-plugin-tests.sh SchedulerPlugin
./testing/run-plugin-tests.sh CalendarPlugin
./testing/run-plugin-tests.sh DependencyPlugin
```
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add SchedulerPlugin
git commit -m "docs(SchedulerPlugin): README + CHANGELOG; v1.0.0 ready for release"
```

---

## Whole-branch review + finish

After Task 10, dispatch the whole-branch code review (most-capable model) over `merge-base(master, HEAD)..HEAD`, focusing on:
- **Data-write safety:** the direct `date_due` update path never fires core per-task activity; `date_modification` is always set; no task outside an enabled project is ever written.
- **Dry-run purity:** no writes to any table, metadata, or activity on a dry run.
- **Web-cron guard:** `last_run` is stamped before the sweep runs (no double-fire); the hook runs only on rendered pages, never assets/API.
- **Auth:** admin gate on settings/save/run/log/runDetail; manager-or-admin gate on toggleProject; CSRF on every POST.
- **CSP:** no inline `<script>`/inline handlers in any template (re-check the settings page's "Run now" control).
- **Soft integrations:** DependencyPlugin-absent and CalendarPlugin-absent paths are true no-ops; no hard class references that fatal when a sibling is missing.
- **N+1:** the calendar badge uses the memoized `recentlyMovedTaskIds` (one query/project), never per-event metadata reads.
- **PicoDb:** every `->in()` is guarded against an empty array.

Then use **superpowers:finishing-a-development-branch** to merge, release `SchedulerPlugin-v1.0.0`, and add it to `kanboard-modmenu-directory/plugins.json`.

## Self-Review (author's pass — completed)

- **Spec coverage:** policy pipeline (Task 2), triggers web/HTTP/CLI (Tasks 5,6,7), per-project opt-in (Tasks 1,8), config (Tasks 1,7), audit table + admin page (Tasks 3,8), per-run activity summary (Tasks 4,7), calendar badge (Task 9), schema (Task 0), testing + cross-theme E2E (Task 10). All spec sections map to a task.
- **Placeholder scan:** no TBD/TODO; every code step carries complete code. The two "Note:" callouts (settings-page confirm control; template cannot access the container) are corrections with the fixed code shown inline, not deferrals.
- **Type consistency:** container keys (`schedulerConfigModel`, `schedulerLogModel`, `schedulerRunner`), method names (`recentlyMovedTaskIds`, `isProjectEnabled`, `plan`, `run`, `getRecentRuns`, `getMovesForRun`), the move-record shape (`task_id/old_date/new_date/reason/move`), and the event name (`scheduler.tasks.rescheduled`) are used identically across tasks. Badge glyph `\u{23F0}` (⏰) is consistent in the CSS class `sch-moved`, decorator, and activity template.
