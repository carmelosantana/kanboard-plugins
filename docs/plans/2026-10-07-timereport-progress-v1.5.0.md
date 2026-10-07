# TimeReport 1.5.0 progress, milestones and XP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Model rule:** implementer/fixer = **Opus**, reviewer = **Opus** (per-task and whole-branch). Pass `model: "opus"` explicitly on every Agent dispatch. Never Haiku.

**Goal:** Ship TimeReport 1.5.0. It adds subtask and time meters on board cards through a hook (no template override), project and milestone progress, XP and levels (including agent "party" totals), XP in the report, five read-only JSON-RPC procedures and matching kanboard-mcp tools, and a coexistence path that retires the third-party TaskProgressBar.

**Architecture:** Pure, table-driven models (`ProgressModel`, `XpModel`) derive everything from core tables. Two small plugin tables hold the subtask completion stamps (for dating) and a lifetime XP cache, kept honest by one event subscriber that stamps and invalidates. A view helper feeds three server-rendered templates that form theme contract v1 (`.tr-progress`, `.tr-track`, `.tr-level` + `data-tr-*`). A thin API service enforces permissions behind two procedure classes.

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 plugin API (template hooks, Symfony event subscriber, JsonRPC `withObject`), PicoDb, PHPUnit via `testing/run-plugin-tests.sh TimeReport` (SQLite in-memory). Go + mcp-go for kanboard-mcp (`go test ./...`).

**Spec:** `kanboard-plugins/docs/specs/2026-10-07-timereport-progress-v1.5.0-design.md` (decisions settled on the wayfinder map Kanboard #5376). Read it before Task 1.

## Global Constraints

- Plugin `TimeReport`, namespace `Kanboard\Plugin\TimeReport`, repo `carmelosantana/kanboard-time-report` (its own git repo at `kanboard-plugins/TimeReport`, branch `main`). Commit TimeReport work **in that repo**, on a feature branch `feat/progress-1.5.0`.
- Version `1.5.0` must match across `Plugin.php`, `plugin.json` and `CHANGELOG.md` (Task 11). `kanboard_version` `>=1.2.47`, `php_version` `>=8.4`, MIT.
- **No `setTemplateOverride` anywhere.** Card meters use `template:board:task:footer` only.
- **CSP:** no inline `<script>` and no inline `on*=` handlers in templates. Inline `style="--tr-pct:N%"` custom properties are allowed (Kanboard's CSP allows inline styles, which TaskProgressBar already relies on in production).
- XP rules (verbatim from the spec): completed subtask = **10 XP** to the subtask assignee; closed task = **25 XP + 10 × complexity score** to the task assignee; unassigned earns nothing; hours earn nothing; level L needs **50 × L × (L − 1)** XP.
- Milestone = a task with core **"is a milestone of"** links (match the link by **label**, never by numeric id). Members are counted only within the milestone's own project.
- Theme contract v1 is **additive only**: never rename or remove a `tr-` class or `data-tr-*` attribute listed in `docs/progress-contract.md`.
- All plugin SQL goes through PicoDb (`$this->db->table()` or `$this->db->execute()`), never raw `getConnection()->query()` outside schema files. This keeps the query counter in Task 7 honest.
- Performance budget: at most **3 extra queries** per page on a warm cache, whatever the card count. Card meters 0, pass track ≤ 2, level badge 1.
- **Plan refinement of spec § XP rules "Cache":** invalidation clears the **whole** `timereport_xp_cache` table on any relevant event, and each user's rows are rebuilt on their next read. Core subtask events carry no previous assignee, so per-row rebuilds cannot know whose XP an assignee change took away; a full clear is always correct and costs one lazy rebuild per active user. The marker row also stores `party_xp`, so the badge stays a single read.
- **Plan refinement of spec § TaskProgressBar coexistence:** the CSS rule hiding `.container-task-progress-bar` ships unconditionally in `progress.css` (it matches nothing once TaskProgressBar is gone). Detection is needed only for the admin notice.
- **Plan refinement of spec § Changes file list:** the procedure classes are named `TimeReportProgressProcedure` and `TimeReportXpProcedure`. Core's API ACL keys on short class names, so the names are prefixed, as in the Agents plugin.
- No PII in any file: the author string is `Carmelo Santana`, with no emails or addresses. Commit identity comes from `/powerup:who-am-i`.
- Do not `git push`, tag, publish a release, edit the ModMenu directory or touch production without Carmelo's explicit go (Tasks 11–13 call this out).
- Baseline before Task 1: `./testing/run-plugin-tests.sh TimeReport` → `OK (183 tests, 520 assertions)`.

## Review Focus

1. **Cards with an estimate but no subtasks, or subtasks but no estimate**, render only the meter that has data. A card with neither renders nothing; zero-subtask maths never divides by zero. Pinned in Task 2 (`testMetersWithoutSubtasksButWithEstimate`, `testMetersWithNeitherAreInvisible`) and Task 5 (`testCardWithNothingRendersNothing`).
2. **Reopening a closed task, un-completing a subtask, or reassigning a done subtask** moves the XP: it disappears from the old assignee and appears for the new one, on the very next read. Pinned in Task 4 (`testReassignDoneSubtaskMovesXpAfterInvalidation`, `testReopenRemovesTaskXp`).
3. **A milestone linked to tasks in another project** (cross-project links are allowed in core) counts only same-project members, and an undated milestone sorts after dated ones. Pinned in Task 2 (`testMilestoneIgnoresCrossProjectMembers`, `testUndatedMilestonesSortLast`).
4. **A personal-token user calling the API for a project they are not a member of** gets an access error, and `getUserXp` never reveals XP from projects the caller can't see. Pinned in Task 8 (`testProjectProgressDeniedForNonMember`, `testUserXpHidesInvisibleProjects`).
5. **Installs without the Agents plugin** (no `agents` table) have no party totals and no SQL errors, on the badge, the API and the report. Pinned in Task 3 (`testAgentOwnerMapIsEmptyWithoutAgentsTable`) and Task 6 (`testLevelBadgeOmitsPartyWithoutAgents`).

---

## File Structure

```
TimeReport/
  Plugin.php                         modified in Tasks 4, 5, 6, 8, 10, 11
  plugin.json                        Task 11 (version, description, recommends Agents)
  CHANGELOG.md, README.md            Task 11
  Schema/Sqlite.php|Mysql.php|Postgres.php   Task 1: VERSION 2, version_2(), backfill
  Model/ProgressModel.php            Task 2: task meters (pure), project %, milestones
  Model/SubtaskCompletionStamp.php   Task 3: stamp sync
  Model/XpModel.php                  Task 3: rules, level curve, derivation, agent map
  Model/XpCache.php                  Task 4: lifetime cache read/rebuild/invalidate
  Subscriber/ProgressSubscriber.php  Task 4: events → stamp + invalidate
  Helper/ProgressHelper.php          Tasks 5–6: card(), track(), level(), tpbNoticeVisible()
  Template/board/progress.php        Task 5
  Template/project/track.php         Task 6
  Template/layout/level.php          Task 6
  Template/config/tpb_notice.php     Task 10
  Assets/css/progress.css            Task 5 (+ Task 10 notice styles)
  Model/ProgressApi.php              Task 8: permission-checked service
  Api/TimeReportProgressProcedure.php, Api/TimeReportXpProcedure.php   Task 8
  Model/TimeReportModel.php          Task 9: report() gains 'xp'
  Helper/TimeReportHelper.php        Task 9: Markdown/CSV XP line
  Template/report/show.php           Task 9: XP in the control bar
  Controller/TimeReportController.php Task 10: dismissTpbNotice()
  docs/progress-contract.md          Task 6
  Test/ProgressFixture.php           Task 1 (shared trait, not a test)
  Test/fixtures/progress-contract.html Task 6
  Test/*Test.php                     per task
~/MCP/kanboard-mcp/ main.go, timereport_tools_test.go, testdata/timereport-1.5.0-procedures.txt, api_methods_test.go   Task 12
```

Run every PHP test command from the parent repo root, `/home/carmelo/Projects/Kanboard/kanboard-plugins`.

---

### Task 1: Schema version 2 — completion stamps, XP cache, backfill

**Files:**
- Create: `TimeReport/Test/ProgressFixture.php`, `TimeReport/Test/ProgressSchemaTest.php`
- Modify: `TimeReport/Schema/Sqlite.php`, `TimeReport/Schema/Mysql.php`, `TimeReport/Schema/Postgres.php`

**Interfaces:**
- Produces: tables `timereport_subtask_completions (subtask_id PK → subtasks.id ON DELETE CASCADE, user_id, completed_at)` and `timereport_xp_cache (user_id, project_id, xp, party_xp NULL, computed_at; PK user_id+project_id)`; `Schema\VERSION = 2`; `Schema\version_2(PDO)`; `Schema\backfill_subtask_completions(PDO)`.
- Produces: trait `ProgressFixture` with `createProgressSchema()`, `user(string): int`, `project(string = 'P'): int`, `task(int $projectId, array $extra = []): int`, `subtask(int $taskId, int $userId = 0, int $status = 0): int`, `close(int $taskId): void`, `milestone(int $milestoneId, array $memberIds): void`, `agentsTable(array $agentToOwner): void`.

- [ ] **Step 1: Create the feature branch in the TimeReport repo**

```bash
git -C /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport switch -c feat/progress-1.5.0
```

- [ ] **Step 2: Write the shared fixture trait**

`TimeReport/Test/ProgressFixture.php`:

```php
<?php

/**
 * Shared seeding for the progress/XP tests. Runs the REAL schema migrations
 * (version_1 then version_2) so every test also exercises them.
 *
 * Not named *Test.php: PHPUnit collects that suffix only.
 */
trait ProgressFixture
{
    private function createProgressSchema(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';

        $pdo = $this->container['db']->getConnection();
        $has = static function (string $table) use ($pdo): bool {
            $found = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
            return $found !== false && $found !== null;
        };

        if (! $has('timereport_task_summaries')) {
            \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);
        }
        if (! $has('timereport_xp_cache')) {
            \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);
        }
    }

    private function user(string $name): int
    {
        return (int) $this->container['userModel']->create(['username' => $name, 'password' => 'x1234567', 'role' => 'app-user']);
    }

    private function project(string $name = 'P'): int
    {
        return (int) $this->container['projectModel']->create(['name' => $name], 1, true);
    }

    private function task(int $projectId, array $extra = []): int
    {
        return (int) $this->container['taskCreationModel']->create(['title' => 'T', 'project_id' => $projectId] + $extra);
    }

    private function subtask(int $taskId, int $userId = 0, int $status = 0): int
    {
        return (int) $this->container['subtaskModel']->create(['task_id' => $taskId, 'title' => 'S', 'user_id' => $userId, 'status' => $status]);
    }

    private function close(int $taskId): void
    {
        $this->container['taskStatusModel']->close($taskId);
    }

    /** Link $milestoneId as "is a milestone of" each member (core creates the opposite link). */
    private function milestone(int $milestoneId, array $memberIds): void
    {
        $link = $this->container['linkModel']->getByLabel('is a milestone of');
        foreach ($memberIds as $memberId) {
            $this->container['taskLinkModel']->create($milestoneId, $memberId, (int) $link['id']);
        }
    }

    /** Stand-in for the Agents plugin's table (that plugin is not loaded in this harness). */
    private function agentsTable(array $agentToOwner): void
    {
        $this->container['db']->getConnection()->exec(
            'CREATE TABLE IF NOT EXISTS agents (id INTEGER PRIMARY KEY, owner_user_id INTEGER NOT NULL, agent_user_id INTEGER NOT NULL UNIQUE, kind TEXT NOT NULL, created_at INTEGER NOT NULL DEFAULT 0)'
        );
        foreach ($agentToOwner as $agentId => $ownerId) {
            $this->container['db']->table('agents')->insert([
                'owner_user_id' => $ownerId, 'agent_user_id' => $agentId, 'kind' => 'claude', 'created_at' => 0,
            ]);
        }
    }
}
```

- [ ] **Step 3: Write the failing migration test**

`TimeReport/Test/ProgressSchemaTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;

class ProgressSchemaTest extends Base
{
    use ProgressFixture;

    private function stamp(int $subtaskId): ?array
    {
        $row = $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $subtaskId)->findOne();
        return $row ?: null;
    }

    public function testVersionIsTwo(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $this->assertSame(2, \Kanboard\Plugin\TimeReport\Schema\VERSION);
    }

    public function testVersion2CreatesBothTables(): void
    {
        $this->createProgressSchema();
        $db = $this->container['db'];
        $s = $this->subtask($this->task($this->project()), 1, 0);
        $this->assertNotFalse($db->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => 5]));
        $this->assertSame(0, $db->table('timereport_xp_cache')->count());
        $db->table('timereport_xp_cache')->insert(['user_id' => 1, 'project_id' => 0, 'xp' => 10, 'party_xp' => null, 'computed_at' => 1]);
        $this->assertSame(1, $db->table('timereport_xp_cache')->count());
    }

    public function testBackfillPrefersLastTimerEnd(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $p = $this->project();
        $t = $this->task($p);
        $s = $this->subtask($t, 1, 2);
        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $s, 'start' => 1000, 'end' => 4000, 'time_spent' => 0.8]);
        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $s, 'start' => 5000, 'end' => 9000, 'time_spent' => 1.1]);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertSame(9000, (int) $this->stamp($s)['completed_at']);
        $this->assertSame(1, (int) $this->stamp($s)['user_id']);
    }

    public function testBackfillFallsBackToTaskCompletionThenModification(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $p = $this->project();
        $closed = $this->task($p);
        $open = $this->task($p);
        $sClosed = $this->subtask($closed, 1, 2);
        $sOpen = $this->subtask($open, 1, 2);
        $this->container['db']->table('tasks')->eq('id', $closed)->update(['date_completed' => 7000, 'date_modification' => 7100]);
        $this->container['db']->table('tasks')->eq('id', $open)->update(['date_completed' => null, 'date_modification' => 6100]);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertSame(7000, (int) $this->stamp($sClosed)['completed_at']);
        $this->assertSame(6100, (int) $this->stamp($sOpen)['completed_at']);
    }

    public function testBackfillSkipsSubtasksThatAreNotDone(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $t = $this->task($this->project());
        $todo = $this->subtask($t, 1, 0);
        $doing = $this->subtask($t, 1, 1);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertNull($this->stamp($todo));
        $this->assertNull($this->stamp($doing));
    }

    public function testStampCascadesWithSubtask(): void
    {
        $this->createProgressSchema();
        $t = $this->task($this->project());
        $s = $this->subtask($t, 1, 0);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => 5]);

        $this->container['subtaskModel']->remove($s);

        $this->assertNull($this->stamp($s));
    }
}
```

PicoDb's SQLite driver turns on `PRAGMA foreign_keys`, so stamps must reference real subtasks (that is also what makes `testStampCascadesWithSubtask` meaningful).

- [ ] **Step 4: Run it to verify it fails**

The suite runner takes only a plugin name, so run one test file with PHPUnit directly from the Kanboard root (the runner has already written `tests/plugin-bootstrap.php` and the symlinks during the baseline run):

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressSchemaTest.php --no-coverage; cd -
```

Expected: FAIL. `VERSION` is 1, and `version_2` is undefined.

- [ ] **Step 5: Implement version 2 in all three schema files**

In `TimeReport/Schema/Sqlite.php`, change `const VERSION = 1;` to `const VERSION = 2;` and append:

```php
/**
 * Progress + XP (1.5.0, spec Kanboard #5379/#5383).
 *
 * Core subtasks carry no completion time, so date-range XP needs a stamp per
 * done subtask. The stamp only DATES XP; amounts are always derived from the
 * live subtask/task rows. The XP cache holds lifetime XP per user per project;
 * project_id 0 is the per-user marker row (xp = lifetime total, party_xp = own +
 * agents' total, NULL when the user has no agents). It is fully disposable.
 */
function version_2(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE timereport_subtask_completions (
            subtask_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 0,
            completed_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(subtask_id),
            FOREIGN KEY(subtask_id) REFERENCES subtasks(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX timereport_subtask_completions_at_idx ON timereport_subtask_completions(completed_at)');

    $pdo->exec("
        CREATE TABLE timereport_xp_cache (
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            xp INTEGER NOT NULL DEFAULT 0,
            party_xp INTEGER NULL,
            computed_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(user_id, project_id)
        )
    ");

    backfill_subtask_completions($pdo);
}

/**
 * Date every already-done subtask: last timer end, else the parent task's
 * completion date, else its last modification. Best effort by design.
 */
function backfill_subtask_completions(PDO $pdo)
{
    $pdo->exec("
        INSERT INTO timereport_subtask_completions (subtask_id, user_id, completed_at)
        SELECT s.id, s.user_id,
               COALESCE(
                   (SELECT MAX(stt.end) FROM subtask_time_tracking stt WHERE stt.subtask_id = s.id AND stt.end > 0),
                   NULLIF(t.date_completed, 0),
                   t.date_modification,
                   0
               )
        FROM subtasks s
        JOIN tasks t ON t.id = s.task_id
        WHERE s.status = 2
    ");
}
```

In `TimeReport/Schema/Mysql.php`: set `const VERSION = 2;` and append the same two functions, with these differences. Column types are `INT`. Both `CREATE TABLE` statements end with `) ENGINE=InnoDB CHARSET=utf8mb4`. The `end` column is written `` stt.`end` `` (in both the `MAX()` and the `WHERE`).

In `TimeReport/Schema/Postgres.php`: set `const VERSION = 2;` and append the same two functions with `INTEGER` types, writing the `end` column as `stt."end"` (it is a reserved word in Postgres).

- [ ] **Step 6: Run the tests to verify they pass**

Run the PHPUnit command from Step 4. Expected: PASS (6 tests). Then run the full suite: `./testing/run-plugin-tests.sh TimeReport 2>&1 | tail -3`. Expected: `OK` with 189 tests (`SchemaMigrationTest` still passes, because it only calls `version_1`).

- [ ] **Step 7: Lint the other drivers' schema files**

Run: `php -l TimeReport/Schema/Mysql.php && php -l TimeReport/Schema/Postgres.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 8: Commit**

```bash
git -C TimeReport add Schema Test/ProgressFixture.php Test/ProgressSchemaTest.php
git -C TimeReport commit -m "feat(schema): version 2 — subtask completion stamps, XP cache, backfill"
```

---

### Task 2: ProgressModel — task meters, project %, milestones

**Files:**
- Create: `TimeReport/Model/ProgressModel.php`, `TimeReport/Test/ProgressModelTest.php`

**Interfaces:**
- Consumes: `ProgressFixture` (Task 1).
- Produces:
  - `ProgressModel::pct(int $done, int $total): int` (0 when `$total` is 0; rounded)
  - `ProgressModel::taskMeters(array $task): array`. This is pure. It reads `id`, `nb_subtasks`, `nb_completed_subtasks`, `time_spent`, `time_estimated` and returns `['task_id'=>int,'subtasks_done'=>int,'subtasks_total'=>int,'pct'=>int,'time_spent'=>float,'time_estimated'=>float,'time_pct'=>int (capped 100),'time_state'=>'ok'|'over'|'none','has_subtasks'=>bool,'has_estimate'=>bool]`
  - `ProgressModel->taskProgress(int $taskId): ?array` (the board row via `taskFinderModel->getExtendedQuery()`, then `taskMeters`; null when the task is missing)
  - `ProgressModel->projectProgress(int $projectId): array` → `['project_id'=>int,'closed'=>int,'total'=>int,'pct'=>int,'milestones'=>list<['task_id'=>int,'title'=>string,'closed'=>int,'total'=>int,'pct'=>int,'due'=>string 'Y-m-d' or '']>]`, using exactly 2 queries
  - `ProgressModel->milestoneProgress(int $milestoneTaskId): ?array` → `['task_id','title','closed','total','pct','due','members'=>list<int>]`; null when the task is missing or has no same-project "is a milestone of" members

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/ProgressModelTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;

class ProgressModelTest extends Base
{
    use ProgressFixture;

    private function row(array $o): array
    {
        return $o + ['id' => 42, 'nb_subtasks' => 0, 'nb_completed_subtasks' => 0, 'time_spent' => 0, 'time_estimated' => 0];
    }

    public function testPct(): void
    {
        $this->assertSame(0, ProgressModel::pct(0, 0));
        $this->assertSame(60, ProgressModel::pct(3, 5));
        $this->assertSame(67, ProgressModel::pct(2, 3));
        $this->assertSame(100, ProgressModel::pct(4, 4));
    }

    public function testMetersWithSubtasksAndEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4]));
        $this->assertSame(['task_id' => 42, 'subtasks_done' => 3, 'subtasks_total' => 5, 'pct' => 60,
            'time_spent' => 3.4, 'time_estimated' => 4.0, 'time_pct' => 85, 'time_state' => 'ok',
            'has_subtasks' => true, 'has_estimate' => true], $m);
    }

    public function testMetersOverrunCapsWidthAndFlagsOver(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 6, 'time_estimated' => 4]));
        $this->assertSame(100, $m['time_pct']);
        $this->assertSame('over', $m['time_state']);
    }

    public function testMetersWithoutSubtasksButWithEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 1, 'time_estimated' => 2]));
        $this->assertFalse($m['has_subtasks']);
        $this->assertTrue($m['has_estimate']);
        $this->assertSame(0, $m['pct']);
    }

    public function testMetersWithSubtasksButNoEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['nb_subtasks' => 2, 'nb_completed_subtasks' => 1, 'time_spent' => 3]));
        $this->assertTrue($m['has_subtasks']);
        $this->assertFalse($m['has_estimate']);
        $this->assertSame('none', $m['time_state']);
        $this->assertSame(0, $m['time_pct']);
    }

    public function testMetersWithNeitherAreInvisible(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 2]));
        $this->assertFalse($m['has_subtasks']);
        $this->assertFalse($m['has_estimate']);
    }

    public function testTaskProgressReadsTheBoardRow(): void
    {
        $t = $this->task($this->project(), ['time_estimated' => 4]);
        $this->subtask($t, 1, 2);
        $this->subtask($t, 1, 0);

        $m = (new ProgressModel($this->container))->taskProgress($t);
        $this->assertSame(1, $m['subtasks_done']);
        $this->assertSame(2, $m['subtasks_total']);
        $this->assertSame(50, $m['pct']);
        $this->assertNull((new ProgressModel($this->container))->taskProgress(99999));
    }

    public function testProjectProgressCountsClosedOverAll(): void
    {
        $p = $this->project();
        $a = $this->task($p);
        $this->task($p);
        $this->task($p);
        $this->close($a);

        $r = (new ProgressModel($this->container))->projectProgress($p);
        $this->assertSame(['project_id' => $p, 'closed' => 1, 'total' => 3, 'pct' => 33, 'milestones' => []], $r);
    }

    public function testProjectProgressListsOpenMilestonesWithCounts(): void
    {
        $p = $this->project();
        $ms = $this->task($p, ['title' => 'Beta', 'date_due' => strtotime('2026-10-20 00:00:00')]);
        $m1 = $this->task($p);
        $m2 = $this->task($p);
        $this->milestone($ms, [$m1, $m2]);
        $this->close($m1);

        $r = (new ProgressModel($this->container))->projectProgress($p);
        $this->assertCount(1, $r['milestones']);
        $this->assertSame(['task_id' => $ms, 'title' => 'Beta', 'closed' => 1, 'total' => 2, 'pct' => 50, 'due' => '2026-10-20'], $r['milestones'][0]);
    }

    public function testClosedMilestonesAreNotOnTheTrack(): void
    {
        $p = $this->project();
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($p)]);
        $this->close($ms);

        $this->assertSame([], (new ProgressModel($this->container))->projectProgress($p)['milestones']);
    }

    public function testUndatedMilestonesSortLast(): void
    {
        $p = $this->project();
        $undated = $this->task($p, ['title' => 'Someday']);
        $late = $this->task($p, ['title' => 'Late', 'date_due' => strtotime('2026-12-01')]);
        $soon = $this->task($p, ['title' => 'Soon', 'date_due' => strtotime('2026-11-01')]);
        foreach ([$undated, $late, $soon] as $ms) {
            $this->milestone($ms, [$this->task($p)]);
        }

        $titles = array_column((new ProgressModel($this->container))->projectProgress($p)['milestones'], 'title');
        $this->assertSame(['Soon', 'Late', 'Someday'], $titles);
    }

    public function testMilestoneIgnoresCrossProjectMembers(): void
    {
        $p = $this->project('A');
        $other = $this->project('B');
        $ms = $this->task($p);
        $same = $this->task($p);
        $foreign = $this->task($other);
        $this->milestone($ms, [$same, $foreign]);

        $r = (new ProgressModel($this->container))->milestoneProgress($ms);
        $this->assertSame([$same], $r['members']);
        $this->assertSame(1, $r['total']);
    }

    public function testMilestoneProgressIsNullForPlainTasks(): void
    {
        $t = $this->task($this->project());
        $this->assertNull((new ProgressModel($this->container))->milestoneProgress($t));
        $this->assertNull((new ProgressModel($this->container))->milestoneProgress(99999));
    }

    public function testProjectProgressUsesTwoQueries(): void
    {
        $p = $this->project();
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($p), $this->task($p)]);

        $h = $this->container['db']->getStatementHandler();
        $before = $h->getNbQueries();
        (new ProgressModel($this->container))->projectProgress($p);
        $this->assertSame(2, $h->getNbQueries() - $before);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressModelTest.php --no-coverage; cd -`
Expected: FAIL with `Class "Kanboard\Plugin\TimeReport\Model\ProgressModel" not found`.

- [ ] **Step 3: Write the implementation**

`TimeReport/Model/ProgressModel.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use PDO;

/**
 * Progress figures (spec Kanboard #5377/#5378). Task meters are pure over the
 * board row core already selects (nb_subtasks, nb_completed_subtasks,
 * time_spent, time_estimated), so a card costs no query. Project progress is
 * two queries: one COUNT pair, one grouped milestone query.
 */
class ProgressModel extends Base
{
    public const MILESTONE_LABEL = 'is a milestone of';

    public static function pct(int $done, int $total): int
    {
        return $total > 0 ? (int) round($done * 100 / $total) : 0;
    }

    public static function taskMeters(array $task): array
    {
        $total = (int) ($task['nb_subtasks'] ?? 0);
        $done  = (int) ($task['nb_completed_subtasks'] ?? 0);
        $spent = (float) ($task['time_spent'] ?? 0);
        $est   = (float) ($task['time_estimated'] ?? 0);
        $hasEstimate = $est > 0;

        return [
            'task_id'        => (int) ($task['id'] ?? 0),
            'subtasks_done'  => $done,
            'subtasks_total' => $total,
            'pct'            => self::pct($done, $total),
            'time_spent'     => $spent,
            'time_estimated' => $est,
            'time_pct'       => $hasEstimate ? (int) min(100, round($spent * 100 / $est)) : 0,
            'time_state'     => $hasEstimate ? ($spent > $est ? 'over' : 'ok') : 'none',
            'has_subtasks'   => $total > 0,
            'has_estimate'   => $hasEstimate,
        ];
    }

    public function taskProgress(int $taskId): ?array
    {
        $row = $this->taskFinderModel->getExtendedQuery()->eq('tasks.id', $taskId)->findOne();
        return $row ? self::taskMeters($row) : null;
    }

    public function projectProgress(int $projectId): array
    {
        $counts = $this->db->execute(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS closed FROM tasks WHERE project_id = ?',
            [$projectId]
        )->fetch(PDO::FETCH_ASSOC);

        $rows = $this->db->execute(
            'SELECT m.id AS task_id, m.title, m.date_due,
                    COUNT(mem.id) AS total,
                    SUM(CASE WHEN mem.is_active = 0 THEN 1 ELSE 0 END) AS closed
             FROM tasks m
             JOIN task_has_links tl ON tl.task_id = m.id
             JOIN links l ON l.id = tl.link_id AND l.label = ?
             JOIN tasks mem ON mem.id = tl.opposite_task_id AND mem.project_id = m.project_id
             WHERE m.project_id = ? AND m.is_active = 1
             GROUP BY m.id, m.title, m.date_due',
            [self::MILESTONE_LABEL, $projectId]
        )->fetchAll(PDO::FETCH_ASSOC);

        $milestones = array_map(fn (array $r) => self::milestoneRow($r), $rows);
        usort($milestones, static function (array $a, array $b): int {
            if (($a['due'] === '') !== ($b['due'] === '')) {
                return $a['due'] === '' ? 1 : -1;
            }
            return [$a['due'], $a['task_id']] <=> [$b['due'], $b['task_id']];
        });

        $total  = (int) ($counts['total'] ?? 0);
        $closed = (int) ($counts['closed'] ?? 0);

        return ['project_id' => $projectId, 'closed' => $closed, 'total' => $total, 'pct' => self::pct($closed, $total), 'milestones' => $milestones];
    }

    public function milestoneProgress(int $milestoneTaskId): ?array
    {
        $rows = $this->db->execute(
            'SELECT m.id AS task_id, m.title, m.date_due, mem.id AS member_id, mem.is_active
             FROM tasks m
             JOIN task_has_links tl ON tl.task_id = m.id
             JOIN links l ON l.id = tl.link_id AND l.label = ?
             JOIN tasks mem ON mem.id = tl.opposite_task_id AND mem.project_id = m.project_id
             WHERE m.id = ?
             ORDER BY mem.id',
            [self::MILESTONE_LABEL, $milestoneTaskId]
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return null;
        }

        $members = array_map(fn (array $r) => (int) $r['member_id'], $rows);
        $closed  = count(array_filter($rows, fn (array $r) => (int) $r['is_active'] === 0));

        return self::milestoneRow(['task_id' => $rows[0]['task_id'], 'title' => $rows[0]['title'], 'date_due' => $rows[0]['date_due'],
            'total' => count($rows), 'closed' => $closed]) + ['members' => $members];
    }

    private static function milestoneRow(array $r): array
    {
        $total  = (int) $r['total'];
        $closed = (int) $r['closed'];
        $due    = (int) ($r['date_due'] ?? 0);

        return [
            'task_id' => (int) $r['task_id'],
            'title'   => (string) $r['title'],
            'closed'  => $closed,
            'total'   => $total,
            'pct'     => self::pct($closed, $total),
            'due'     => $due > 0 ? date('Y-m-d', $due) : '',
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run the command from Step 2. Expected: PASS (14 tests). If `testProjectProgressListsOpenMilestonesWithCounts` reports `due` one day off, the harness timezone differs from the server's. Create the due date with `strtotime('2026-10-20 12:00:00')` in the test rather than changing the model.

- [ ] **Step 5: Commit**

```bash
git -C TimeReport add Model/ProgressModel.php Test/ProgressModelTest.php
git -C TimeReport commit -m "feat(progress): ProgressModel — task meters, project %, core milestones"
```

---

### Task 3: XpModel and completion stamps — rules, levels, derivation, agents

**Files:**
- Create: `TimeReport/Model/XpModel.php`, `TimeReport/Model/SubtaskCompletionStamp.php`, `TimeReport/Test/XpModelTest.php`

**Interfaces:**
- Consumes: `ProgressFixture` (Task 1), tables from Task 1.
- Produces:
  - `XpModel::SUBTASK_XP = 10`, `XpModel::TASK_XP = 25`, `XpModel::SCORE_XP = 10`
  - `XpModel::xpForLevel(int $level): int` = 50·L·(L−1); `XpModel::levelFor(int $xp): int` (≥ 1); `XpModel::nextFor(int $xp): int` (XP still needed for the next level)
  - `XpModel->deriveUser(int $userId, ?int $startTs = null, ?int $endTs = null): array<int,int>` (project_id ⇒ xp; `[]` for user 0; with a range, subtasks are dated by stamps and tasks by `date_completed`)
  - `XpModel->byUser(int $projectId, ?int $startTs = null, ?int $endTs = null): array<int,int>` (user_id ⇒ xp within one project; user 0 is excluded)
  - `XpModel->agentOwnerMap(): array<int,int>` (agent_user_id ⇒ owner_user_id; `[]` when the `agents` table is absent)
  - `SubtaskCompletionStamp->sync(int $subtaskId, int $status, int $userId, ?int $now = null): void` (status 2 inserts the stamp, keeping the first `completed_at` and updating `user_id`; any other status deletes it)

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/XpModelTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\XpModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;

class XpModelTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
    }

    private function xp(): XpModel
    {
        return new XpModel($this->container);
    }

    public function testLevelCurveBoundaries(): void
    {
        $cases = [[0, 1, 100], [99, 1, 1], [100, 2, 200], [299, 2, 1], [1000, 5, 500], [4500, 10, 1000], [19000, 20, 2000]];
        foreach ($cases as [$xp, $level, $next]) {
            $this->assertSame($level, XpModel::levelFor($xp), "level for $xp");
            $this->assertSame($next, XpModel::nextFor($xp), "next for $xp");
        }
        $this->assertSame(0, XpModel::xpForLevel(1));
        $this->assertSame(19000, XpModel::xpForLevel(20));
    }

    public function testSubtaskAndTaskXpGoToTheirAssignees(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice, 'score' => 3]);
        $this->subtask($t, $bob, 2);
        $this->subtask($t, $bob, 2);
        $this->subtask($t, $alice, 0);
        $this->close($t);

        $this->assertSame([$p => 55], $this->xp()->deriveUser($alice));   // 25 + 10×3
        $this->assertSame([$p => 20], $this->xp()->deriveUser($bob));     // 2 × 10
        $this->assertSame([$alice => 55, $bob => 20], $this->xp()->byUser($p));
    }

    public function testUnassignedWorkEarnsNobody(): void
    {
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, 0, 2);
        $this->close($t);

        $this->assertSame([], $this->xp()->byUser($p));
        $this->assertSame([], $this->xp()->deriveUser(0));
    }

    public function testOpenTasksEarnNoTaskXp(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $this->task($p, ['owner_id' => $alice, 'score' => 5]);

        $this->assertSame([], $this->xp()->deriveUser($alice));
    }

    public function testRangeUsesStampsAndCompletionDates(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice]);
        $inRange = $this->subtask($t, $alice, 2);
        $outRange = $this->subtask($t, $alice, 2);
        $stamp = new SubtaskCompletionStamp($this->container);
        $stamp->sync($inRange, 2, $alice, 1500);
        $stamp->sync($outRange, 2, $alice, 9000);
        $this->close($t);
        $this->container['db']->table('tasks')->eq('id', $t)->update(['date_completed' => 1600]);

        $this->assertSame([$p => 35], $this->xp()->deriveUser($alice, 1000, 2000));   // 10 + 25
        $this->assertSame([$alice => 35], $this->xp()->byUser($p, 1000, 2000));
        $this->assertSame([$p => 45], $this->xp()->deriveUser($alice));                // lifetime: 20 + 25
    }

    public function testStampKeepsFirstCompletionAndDeletesOnReopen(): void
    {
        $t = $this->task($this->project());
        $s = $this->subtask($t, 1, 2);
        $stamp = new SubtaskCompletionStamp($this->container);

        $stamp->sync($s, 2, 1, 100);
        $stamp->sync($s, 2, 2, 200);
        $row = $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->findOne();
        $this->assertSame(100, (int) $row['completed_at']);
        $this->assertSame(2, (int) $row['user_id']);

        $stamp->sync($s, 1, 2, 300);
        $this->assertSame(0, $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->count());
    }

    public function testAgentOwnerMapIsEmptyWithoutAgentsTable(): void
    {
        $this->assertSame([], $this->xp()->agentOwnerMap());
    }

    public function testAgentOwnerMapReadsTheAgentsTable(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);

        $this->assertSame([$bot => $owner], $this->xp()->agentOwnerMap());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/XpModelTest.php --no-coverage; cd -`
Expected: FAIL with `Class "Kanboard\Plugin\TimeReport\Model\XpModel" not found`.

- [ ] **Step 3: Write the stamp model**

`TimeReport/Model/SubtaskCompletionStamp.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;

/**
 * When a subtask was first completed (core keeps no such date). It dates XP
 * for range reports and never stores an amount (spec Kanboard #5379).
 */
class SubtaskCompletionStamp extends Base
{
    public const TABLE = 'timereport_subtask_completions';

    public function sync(int $subtaskId, int $status, int $userId, ?int $now = null): void
    {
        $query = fn () => $this->db->table(self::TABLE)->eq('subtask_id', $subtaskId);

        if ($status !== SubtaskModel::STATUS_DONE) {
            $query()->remove();
            return;
        }

        if ($query()->exists()) {
            $query()->update(['user_id' => $userId]);
            return;
        }

        $this->db->table(self::TABLE)->insert(['subtask_id' => $subtaskId, 'user_id' => $userId, 'completed_at' => $now ?? time()]);
    }
}
```

- [ ] **Step 4: Write the XP model**

`TimeReport/Model/XpModel.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use PDO;
use PicoDb\SQLException;

/**
 * XP rules (spec Kanboard #5379): a done subtask earns 10 for its assignee; a
 * closed task earns 25 + 10 × score for its assignee. Unassigned work and
 * logged hours earn nothing. Amounts are derived from live rows, so reopening
 * removes XP by construction. Ranges date subtasks by completion stamp and
 * tasks by date_completed.
 */
class XpModel extends Base
{
    public const SUBTASK_XP = 10;
    public const TASK_XP    = 25;
    public const SCORE_XP   = 10;

    public static function xpForLevel(int $level): int
    {
        return 50 * $level * ($level - 1);
    }

    public static function levelFor(int $xp): int
    {
        $level = 1;
        while (self::xpForLevel($level + 1) <= $xp) {
            $level++;
        }
        return $level;
    }

    public static function nextFor(int $xp): int
    {
        return self::xpForLevel(self::levelFor($xp) + 1) - $xp;
    }

    /** @return array<int,int> project_id => xp */
    public function deriveUser(int $userId, ?int $startTs = null, ?int $endTs = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        [$subRange, $subArgs]   = $this->subtaskRange($startTs, $endTs);
        [$taskRange, $taskArgs] = $this->taskRange($startTs, $endTs);

        $subtasks = $this->db->execute(
            "SELECT t.project_id AS k, COUNT(*) AS n
             FROM subtasks s JOIN tasks t ON t.id = s.task_id {$subRange['join']}
             WHERE s.user_id = ? AND s.status = 2 {$subRange['where']}
             GROUP BY t.project_id",
            array_merge([$userId], $subArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        $tasks = $this->db->execute(
            "SELECT project_id AS k, COUNT(*) AS n, COALESCE(SUM(score), 0) AS score
             FROM tasks WHERE owner_id = ? AND is_active = 0 {$taskRange}
             GROUP BY project_id",
            array_merge([$userId], $taskArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::combine($subtasks, $tasks);
    }

    /** @return array<int,int> user_id => xp within one project */
    public function byUser(int $projectId, ?int $startTs = null, ?int $endTs = null): array
    {
        [$subRange, $subArgs]   = $this->subtaskRange($startTs, $endTs);
        [$taskRange, $taskArgs] = $this->taskRange($startTs, $endTs);

        $subtasks = $this->db->execute(
            "SELECT s.user_id AS k, COUNT(*) AS n
             FROM subtasks s JOIN tasks t ON t.id = s.task_id {$subRange['join']}
             WHERE t.project_id = ? AND s.status = 2 AND s.user_id > 0 {$subRange['where']}
             GROUP BY s.user_id",
            array_merge([$projectId], $subArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        $tasks = $this->db->execute(
            "SELECT owner_id AS k, COUNT(*) AS n, COALESCE(SUM(score), 0) AS score
             FROM tasks WHERE project_id = ? AND is_active = 0 AND owner_id > 0 {$taskRange}
             GROUP BY owner_id",
            array_merge([$projectId], $taskArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::combine($subtasks, $tasks);
    }

    /** @return array<int,int> agent_user_id => owner_user_id; [] without the Agents plugin */
    public function agentOwnerMap(): array
    {
        try {
            $rows = $this->db->table('agents')->columns('agent_user_id', 'owner_user_id')->findAll();
        } catch (SQLException $e) {
            return [];
        }

        $map = [];
        foreach ($rows ?: [] as $r) {
            $map[(int) $r['agent_user_id']] = (int) $r['owner_user_id'];
        }
        return $map;
    }

    private function subtaskRange(?int $startTs, ?int $endTs): array
    {
        if ($startTs === null || $endTs === null) {
            return [['join' => '', 'where' => ''], []];
        }
        return [[
            'join'  => 'JOIN ' . SubtaskCompletionStamp::TABLE . ' c ON c.subtask_id = s.id',
            'where' => 'AND c.completed_at BETWEEN ? AND ?',
        ], [$startTs, $endTs]];
    }

    private function taskRange(?int $startTs, ?int $endTs): array
    {
        if ($startTs === null || $endTs === null) {
            return ['', []];
        }
        return ['AND date_completed BETWEEN ? AND ?', [$startTs, $endTs]];
    }

    private static function combine(array $subtasks, array $tasks): array
    {
        $xp = [];
        foreach ($subtasks as $r) {
            $xp[(int) $r['k']] = ($xp[(int) $r['k']] ?? 0) + (int) $r['n'] * self::SUBTASK_XP;
        }
        foreach ($tasks as $r) {
            $xp[(int) $r['k']] = ($xp[(int) $r['k']] ?? 0) + (int) $r['n'] * self::TASK_XP + (int) $r['score'] * self::SCORE_XP;
        }
        ksort($xp);
        return $xp;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run the command from Step 2. Expected: PASS (8 tests). If `testAgentOwnerMapIsEmptyWithoutAgentsTable` errors instead of returning `[]`, PicoDb returned `false` rather than throwing. Keep the `?: []` guard and also catch `\PDOException`.

- [ ] **Step 6: Commit**

```bash
git -C TimeReport add Model/XpModel.php Model/SubtaskCompletionStamp.php Test/XpModelTest.php
git -C TimeReport commit -m "feat(xp): XpModel rules, level curve, range derivation, agent map; completion stamps"
```

---

### Task 4: XpCache and ProgressSubscriber — lifetime cache kept honest by events

**Files:**
- Create: `TimeReport/Model/XpCache.php`, `TimeReport/Subscriber/ProgressSubscriber.php`, `TimeReport/Test/XpCacheTest.php`
- Modify: `TimeReport/Plugin.php` (register services and subscriber)

**Interfaces:**
- Consumes: `XpModel->deriveUser()`, `XpModel->agentOwnerMap()`, `SubtaskCompletionStamp->sync()` (Task 3).
- Produces:
  - `XpCache::TABLE = 'timereport_xp_cache'`, `XpCache::MARKER = 0`
  - `XpCache->lifetime(int $userId): array{total:int, by_project:array<int,int>, party_xp:?int}`. Warm, this is **exactly 1 query**. Cold, it rebuilds that user's rows (own per-project XP + marker with `xp` = total and `party_xp` = total + every agent's lifetime total, or NULL when the user owns no agents).
  - `XpCache->invalidateAll(): void`
  - `ProgressSubscriber` (Symfony `EventSubscriberInterface`): `subtask.create`/`subtask.update` → stamp sync + invalidate; `subtask.delete`, `task.close`, `task.open`, `task.update`, `task.assignee_change`, `task.move.project` → invalidate.
  - Container services `progressModel`, `xpModel`, `xpCache`, `subtaskCompletionStamp`.

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/XpCacheTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber;

class XpCacheTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['dispatcher']->addSubscriber(new ProgressSubscriber($this->container));
    }

    private function cache(): XpCache
    {
        return new XpCache($this->container);
    }

    private function queries(callable $fn): int
    {
        $h = $this->container['db']->getStatementHandler();
        $before = $h->getNbQueries();
        $fn();
        return $h->getNbQueries() - $before;
    }

    public function testColdReadBuildsThenWarmReadIsOneQuery(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $this->subtask($this->task($p), $alice, 2);

        $this->assertSame(['total' => 10, 'by_project' => [$p => 10], 'party_xp' => null], $this->cache()->lifetime($alice));
        $this->assertSame(1, $this->queries(fn () => $this->cache()->lifetime($alice)));
    }

    public function testCompletingASubtaskStampsAndInvalidates(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $s = $this->subtask($this->task($p), $alice, 0);
        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);

        $this->container['subtaskModel']->update(['id' => $s, 'status' => 2]);

        $this->assertSame(1, $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->count());
        $this->assertSame(10, $this->cache()->lifetime($alice)['total']);
    }

    public function testReassignDoneSubtaskMovesXpAfterInvalidation(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $s = $this->subtask($this->task($this->project()), $alice, 2);
        $this->assertSame(10, $this->cache()->lifetime($alice)['total']);
        $this->assertSame(0, $this->cache()->lifetime($bob)['total']);

        $this->container['subtaskModel']->update(['id' => $s, 'user_id' => $bob]);

        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);
        $this->assertSame(10, $this->cache()->lifetime($bob)['total']);
    }

    public function testReopenRemovesTaskXp(): void
    {
        $alice = $this->user('alice');
        $t = $this->task($this->project(), ['owner_id' => $alice, 'score' => 2]);
        $this->close($t);
        $this->assertSame(45, $this->cache()->lifetime($alice)['total']);

        $this->container['taskStatusModel']->open($t);

        $this->assertSame(0, $this->cache()->lifetime($alice)['total']);
    }

    public function testScoreChangeOnClosedTaskIsPickedUp(): void
    {
        $alice = $this->user('alice');
        $t = $this->task($this->project(), ['owner_id' => $alice]);
        $this->close($t);
        $this->assertSame(25, $this->cache()->lifetime($alice)['total']);

        $this->container['taskModificationModel']->update(['id' => $t, 'score' => 3]);

        $this->assertSame(55, $this->cache()->lifetime($alice)['total']);
    }

    public function testPartyXpIncludesAgentsAndIsNullWithoutThem(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $solo = $this->user('solo');
        $this->agentsTable([$bot => $owner]);
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, $owner, 2);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $bot, 2);

        $this->assertSame(['total' => 10, 'by_project' => [$p => 10], 'party_xp' => 30], $this->cache()->lifetime($owner));
        $this->assertNull($this->cache()->lifetime($solo)['party_xp']);
        $this->assertNull($this->cache()->lifetime($bot)['party_xp']);
    }

    public function testInvalidateAllEmptiesTheCache(): void
    {
        $alice = $this->user('alice');
        $this->cache()->lifetime($alice);
        $this->assertGreaterThan(0, $this->container['db']->table(XpCache::TABLE)->count());

        $this->cache()->invalidateAll();

        $this->assertSame(0, $this->container['db']->table(XpCache::TABLE)->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/XpCacheTest.php --no-coverage; cd -`
Expected: FAIL with `Class "Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber" not found`.

- [ ] **Step 3: Write the cache**

`TimeReport/Model/XpCache.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * Lifetime XP per user per project, plus a per-user marker row (project_id 0)
 * holding the total and the party total. A warm read is one query; a missing
 * marker means "rebuild this user". Invalidation clears the whole table: core
 * subtask events carry no previous assignee, so a targeted rebuild could miss
 * whose XP an assignment change removed. Every row is derivable, so a clear is
 * always safe.
 */
class XpCache extends Base
{
    public const TABLE  = 'timereport_xp_cache';
    public const MARKER = 0;

    /** @return array{total:int, by_project:array<int,int>, party_xp:?int} */
    public function lifetime(int $userId): array
    {
        $rows = $this->db->table(self::TABLE)->eq('user_id', $userId)->findAll();
        $hit = self::fromRows($rows ?: []);
        if ($hit !== null) {
            return $hit;
        }
        return $this->rebuild($userId);
    }

    public function invalidateAll(): void
    {
        $this->db->table(self::TABLE)->remove();
    }

    private function rebuild(int $userId): array
    {
        $xpModel   = new XpModel($this->container);
        $byProject = $xpModel->deriveUser($userId);
        $total     = array_sum($byProject);

        $agents = array_keys(array_filter($xpModel->agentOwnerMap(), fn (int $owner) => $owner === $userId));
        $party  = null;
        if ($agents !== []) {
            $party = $total;
            foreach ($agents as $agentId) {
                $party += array_sum($xpModel->deriveUser($agentId));
            }
        }

        $now = time();
        $this->db->table(self::TABLE)->eq('user_id', $userId)->remove();
        foreach ($byProject as $projectId => $xp) {
            $this->db->table(self::TABLE)->insert(['user_id' => $userId, 'project_id' => $projectId, 'xp' => $xp, 'party_xp' => null, 'computed_at' => $now]);
        }
        $this->db->table(self::TABLE)->insert(['user_id' => $userId, 'project_id' => self::MARKER, 'xp' => $total, 'party_xp' => $party, 'computed_at' => $now]);

        return ['total' => $total, 'by_project' => $byProject, 'party_xp' => $party];
    }

    private static function fromRows(array $rows): ?array
    {
        $marker = null;
        $byProject = [];
        foreach ($rows as $r) {
            if ((int) $r['project_id'] === self::MARKER) {
                $marker = $r;
            } else {
                $byProject[(int) $r['project_id']] = (int) $r['xp'];
            }
        }
        if ($marker === null) {
            return null;
        }
        ksort($byProject);
        return [
            'total'      => (int) $marker['xp'],
            'by_project' => $byProject,
            'party_xp'   => $marker['party_xp'] === null ? null : (int) $marker['party_xp'],
        ];
    }
}
```

- [ ] **Step 4: Write the subscriber**

`TimeReport/Subscriber/ProgressSubscriber.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keeps completion stamps current and the XP cache honest (spec Kanboard #5383). */
class ProgressSubscriber extends Base implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SubtaskModel::EVENT_CREATE          => 'onSubtask',
            SubtaskModel::EVENT_UPDATE          => 'onSubtask',
            SubtaskModel::EVENT_DELETE          => 'onChange',
            TaskModel::EVENT_CLOSE              => 'onChange',
            TaskModel::EVENT_OPEN               => 'onChange',
            TaskModel::EVENT_UPDATE             => 'onChange',
            TaskModel::EVENT_ASSIGNEE_CHANGE    => 'onChange',
            TaskModel::EVENT_MOVE_PROJECT       => 'onChange',
        ];
    }

    public function onSubtask($event): void
    {
        $subtask = $event['subtask'] ?? null;
        if (is_array($subtask) && isset($subtask['id'])) {
            (new SubtaskCompletionStamp($this->container))->sync((int) $subtask['id'], (int) $subtask['status'], (int) $subtask['user_id']);
        }
        $this->onChange($event);
    }

    public function onChange($event): void
    {
        (new XpCache($this->container))->invalidateAll();
    }
}
```

- [ ] **Step 5: Register services and the subscriber in `Plugin.php`**

Add these `use` lines next to the existing ones:

```php
use Kanboard\Plugin\TimeReport\Model\ProgressModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Model\XpModel;
use Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber;
```

At the end of the "Model services" block in `initialize()`:

```php
        $this->container['progressModel'] = fn ($c) => new ProgressModel($c);
        $this->container['xpModel'] = fn ($c) => new XpModel($c);
        $this->container['xpCache'] = fn ($c) => new XpCache($c);
        $this->container['subtaskCompletionStamp'] = fn ($c) => new SubtaskCompletionStamp($c);

        // ── Progress/XP bookkeeping: completion stamps + cache invalidation ───
        $this->dispatcher->addSubscriber(new ProgressSubscriber($this->container));
```

Add to `TimeReport/Test/PluginTest.php`:

```php
    public function testInitializeRegistersTheProgressSubscriber(): void
    {
        (new Plugin($this->container))->initialize();
        $listeners = $this->container['dispatcher']->getListeners('subtask.update');
        $found = array_filter($listeners, fn ($l) => is_array($l) && $l[0] instanceof \Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber);
        $this->assertNotEmpty($found);
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run the command from Step 2, then `./testing/run-plugin-tests.sh TimeReport 2>&1 | tail -3`.
Expected: `XpCacheTest` PASS (7 tests), and the full suite OK.

If an event test fails because no event fired, check whether the queue ran the job synchronously (`queueManager` without a queue driver executes immediately). Do not call the subscriber directly to make it pass.

- [ ] **Step 7: Commit**

```bash
git -C TimeReport add Model/XpCache.php Subscriber/ProgressSubscriber.php Plugin.php Test/XpCacheTest.php Test/PluginTest.php
git -C TimeReport commit -m "feat(xp): lifetime XP cache with party totals, invalidated by task/subtask events"
```

---

### Task 5: Card meters on the board — hook, helper, CSS (replaces TaskProgressBar's bar)

**Files:**
- Create: `TimeReport/Helper/ProgressHelper.php`, `TimeReport/Template/board/progress.php`, `TimeReport/Assets/css/progress.css`, `TimeReport/Test/ProgressCardTest.php`
- Modify: `TimeReport/Plugin.php`, `TimeReport/Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `ProgressModel::taskMeters()` (Task 2).
- Produces: helper `timeReportProgress` with `card(array $task): array` (= `taskMeters`) and `num(float $x): string` (raw number for attributes: `round($x, 2)`, with trailing zeros trimmed). Template `TimeReport:board/progress` (arg `task`). CSS `plugins/TimeReport/Assets/css/progress.css`.

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/ProgressCardTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;
use Kanboard\Plugin\TimeReport\Plugin;

class ProgressCardTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    private function render(array $task): string
    {
        $task += ['id' => 42, 'project_id' => 1, 'nb_subtasks' => 0, 'nb_completed_subtasks' => 0, 'time_spent' => 0, 'time_estimated' => 0];
        return $this->container['template']->render('TimeReport:board/progress', ['task' => $task]);
    }

    public function testCardWithSubtasksAndEstimate(): void
    {
        $html = $this->render(['nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4]);
        $this->assertStringContainsString('class="tr-progress"', $html);
        $this->assertStringContainsString('data-tr-contract="1"', $html);
        $this->assertStringContainsString('data-tr-task="42"', $html);
        $this->assertStringContainsString('data-tr-subtasks-done="3"', $html);
        $this->assertStringContainsString('data-tr-subtasks-total="5"', $html);
        $this->assertStringContainsString('data-tr-pct="60"', $html);
        $this->assertStringContainsString('data-tr-time-spent="3.4"', $html);
        $this->assertStringContainsString('data-tr-time-est="4"', $html);
        $this->assertStringContainsString('data-tr-time-state="ok"', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuenow="60"', $html);
        $this->assertStringContainsString('--tr-pct:60%', $html);
        $this->assertStringContainsString('3/5', $html);
    }

    public function testCardOverrunMarksOver(): void
    {
        $html = $this->render(['time_spent' => 6, 'time_estimated' => 4]);
        $this->assertStringContainsString('data-tr-time-state="over"', $html);
        $this->assertStringContainsString('tr-progress__time--over', $html);
        $this->assertStringNotContainsString('tr-progress__bar', $html);
    }

    public function testCardWithoutEstimateHasNoTimeMeter(): void
    {
        $html = $this->render(['nb_subtasks' => 2, 'nb_completed_subtasks' => 1, 'time_spent' => 3]);
        $this->assertStringContainsString('tr-progress__bar', $html);
        $this->assertStringNotContainsString('tr-progress__time', $html);
        $this->assertStringNotContainsString('data-tr-time-', $html);
    }

    public function testCardWithNothingRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(['time_spent' => 2])));
    }

    public function testPluginAttachesFooterHookAndNoOverride(): void
    {
        (new Plugin($this->container))->initialize();
        $hooks = $this->container['hook']->getListeners('template:board:task:footer');
        $this->assertContains('TimeReport:board/progress', array_column($hooks, 'template'));
        $this->assertStringNotContainsString('setTemplateOverride', file_get_contents(dirname(__DIR__) . '/Plugin.php'));
    }

    public function testProgressCssShipsAndHidesTaskProgressBar(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/Assets/css/progress.css');
        $this->assertStringContainsString('.container-task-progress-bar', $css);
        $this->assertMatchesRegularExpression('/\.tr-track\s*,\s*\.tr-level\s*\{[^}]*display:\s*none/', $css);
        $this->assertStringContainsString('var(--tr-pct', $css);
    }
}
```

Add `'board/progress.php'` to the `$templates` list in `TemplateAssetsTest::testNoInlineScriptOrHandlersInTemplates`.

Template hooks are stored in `$this->container['hook']` (`Kanboard\Core\Plugin\Hook`); each listener is an array with a `template` key (the same accessor Battle Lobby's tests use).

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressCardTest.php --no-coverage; cd -`
Expected: FAIL with `Class "Kanboard\Plugin\TimeReport\Helper\ProgressHelper" not found`.

- [ ] **Step 3: Write the helper**

`TimeReport/Helper/ProgressHelper.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Helper;

use Kanboard\Core\Base;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;

/** View-side access to progress data for the contract templates. */
class ProgressHelper extends Base
{
    public function card(array $task): array
    {
        return ProgressModel::taskMeters($task);
    }

    /** Raw number for a data-* attribute: 3.40 → "3.4", 4.0 → "4". */
    public function num(float $x): string
    {
        $s = number_format(round($x, 2), 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
}
```

- [ ] **Step 4: Write the card template**

`TimeReport/Template/board/progress.php`:

```php
<?php $trm = $this->helper->timeReportProgress->card($task) ?>
<?php if ($trm['has_subtasks'] || $trm['has_estimate']): ?>
<div class="tr-progress" data-tr-contract="1" data-tr-task="<?= (int) $trm['task_id'] ?>"<?php if ($trm['has_subtasks']): ?> data-tr-subtasks-done="<?= (int) $trm['subtasks_done'] ?>" data-tr-subtasks-total="<?= (int) $trm['subtasks_total'] ?>" data-tr-pct="<?= (int) $trm['pct'] ?>"<?php endif ?><?php if ($trm['has_estimate']): ?> data-tr-time-spent="<?= $this->helper->timeReportProgress->num($trm['time_spent']) ?>" data-tr-time-est="<?= $this->helper->timeReportProgress->num($trm['time_estimated']) ?>" data-tr-time-state="<?= $this->text->e($trm['time_state']) ?>"<?php endif ?>>
    <?php if ($trm['has_subtasks']): ?>
    <div class="tr-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $trm['pct'] ?>" aria-label="<?= t('Subtasks completed') ?>" style="--tr-pct:<?= (int) $trm['pct'] ?>%">
        <span class="tr-progress__fill"></span>
        <span class="tr-progress__label"><?= (int) $trm['subtasks_done'] ?>/<?= (int) $trm['subtasks_total'] ?></span>
    </div>
    <?php endif ?>
    <?php if ($trm['has_estimate']): ?>
    <div class="tr-progress__time tr-progress__time--<?= $this->text->e($trm['time_state']) ?>" style="--tr-pct:<?= (int) $trm['time_pct'] ?>%" title="<?= t('Time spent and estimated') ?>">
        <span class="tr-progress__track"><span class="tr-progress__fill"></span></span>
        <span class="tr-progress__label"><?= $this->helper->timeReportProgress->num($trm['time_spent']) ?>/<?= $this->helper->timeReportProgress->num($trm['time_estimated']) ?>h</span>
    </div>
    <?php endif ?>
</div>
<?php endif ?>
```

- [ ] **Step 5: Write the CSS**

`TimeReport/Assets/css/progress.css`:

```css
/* TimeReport progress — theme contract v1 (docs/progress-contract.md). Themes restyle freely. */
.tr-progress { display: grid; gap: 3px; margin: 4px 0 2px; }
.tr-progress__bar { position: relative; height: 16px; border-radius: 4px; overflow: hidden; background: rgba(130, 130, 130, 0.18); }
.tr-progress__bar .tr-progress__fill { position: absolute; inset: 0 auto 0 0; width: var(--tr-pct, 0%); background: #5cb85c; }
.tr-progress__bar .tr-progress__label { position: relative; display: block; padding: 0 4px; font-size: 0.8em; line-height: 16px; color: #333; }
.tr-progress__time { display: flex; align-items: center; gap: 4px; font-size: 0.75em; color: #666; }
.tr-progress__track { position: relative; flex: 1; height: 5px; border-radius: 3px; overflow: hidden; background: rgba(130, 130, 130, 0.18); }
.tr-progress__time .tr-progress__fill { position: absolute; inset: 0 auto 0 0; width: var(--tr-pct, 0%); background: #4b9fd5; }
.tr-progress__time--over .tr-progress__fill { background: #d9534f; }
.tr-progress__time--over .tr-progress__label { color: #b94a48; font-weight: bold; }

/* Theme-only surfaces: emitted for themes, hidden in the stock UI (spec Kanboard #5380). */
.tr-track, .tr-level { display: none; }

/* TaskProgressBar coexistence (spec Kanboard #5382): one bar per card while both are installed. */
.container-task-progress-bar { display: none; }
```

- [ ] **Step 6: Register the helper, hook and CSS in `Plugin.php`**

Add `use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;`. After the existing `$this->helper->register('timeReport', ...)` line:

```php
        $this->helper->register('timeReportProgress', ProgressHelper::class);
```

After the `template:config:integrations` attach:

```php
        // ── Progress on board cards: a hook, never a board/task_footer override ──
        $this->template->hook->attach('template:board:task:footer', 'TimeReport:board/progress');
```

Next to the existing CSS hook:

```php
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeReport/Assets/css/progress.css']);
```

- [ ] **Step 7: Run the tests to verify they pass**

Run the command from Step 2, then the full suite. Expected: `ProgressCardTest` PASS (6 tests), full suite OK.

- [ ] **Step 8: Commit**

```bash
git -C TimeReport add Helper/ProgressHelper.php Template/board/progress.php Assets/css/progress.css Plugin.php Test/ProgressCardTest.php Test/TemplateAssetsTest.php
git -C TimeReport commit -m "feat(progress): subtask + time meters on board cards via template:board:task:footer"
```

---

### Task 6: Pass track and level badge (theme-only) + contract doc and fixture

**Files:**
- Create: `TimeReport/Template/project/track.php`, `TimeReport/Template/layout/level.php`, `TimeReport/docs/progress-contract.md`, `TimeReport/Test/fixtures/progress-contract.html`, `TimeReport/Test/ProgressContractTest.php`
- Modify: `TimeReport/Helper/ProgressHelper.php`, `TimeReport/Plugin.php`, `TimeReport/Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `ProgressModel->projectProgress()` (Task 2), `XpCache->lifetime()`, `XpModel::levelFor()/nextFor()` (Tasks 3–4).
- Produces: `ProgressHelper->track(int $projectId): ?array` (null when the project has no tasks) and `ProgressHelper->level(): ?array` → `['user_id','level','xp','next','party_xp'=>?int]` (null when nobody is logged in). Templates `TimeReport:project/track` (arg `project`) and `TimeReport:layout/level`. The contract doc and fixture are what Battle Lobby reads.

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/ProgressContractTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;
use Kanboard\Plugin\TimeReport\Plugin;

class ProgressContractTest extends Base
{
    use ProgressFixture;

    private const FIXTURE = __DIR__ . '/fixtures/progress-contract.html';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function tpl(string $name, array $args = []): string
    {
        return $this->container['template']->render($name, $args);
    }

    private function seed(): array
    {
        $alice = $this->user('alice');
        $p = $this->project('Contract');
        $ms = $this->task($p, ['title' => 'Beta', 'date_due' => strtotime('2026-10-20 12:00:00')]);
        $a = $this->task($p, ['owner_id' => $alice]);
        $b = $this->task($p);
        $this->milestone($ms, [$a, $b]);
        $this->subtask($a, $alice, 2);
        $this->close($a);
        return [$alice, $p, $ms];
    }

    public function testTrackMarkup(): void
    {
        [, $p, $ms] = $this->seed();
        $html = $this->tpl('TimeReport:project/track', ['project' => ['id' => $p]]);

        $this->assertStringContainsString('class="tr-track"', $html);
        $this->assertStringContainsString('data-tr-contract="1"', $html);
        $this->assertStringContainsString('data-tr-project="' . $p . '"', $html);
        $this->assertStringContainsString('data-tr-project-pct="33"', $html);
        $this->assertStringContainsString('class="tr-track__milestone"', $html);
        $this->assertStringContainsString('data-tr-milestone="' . $ms . '"', $html);
        $this->assertStringContainsString('data-tr-pct="50"', $html);
        $this->assertStringContainsString('data-tr-due="2026-10-20"', $html);
        $this->assertStringContainsString('data-tr-state="open"', $html);
        $this->assertStringContainsString('Beta', $html);
    }

    public function testTrackIsEmptyForProjectsWithoutTasks(): void
    {
        $this->assertSame('', trim($this->tpl('TimeReport:project/track', ['project' => ['id' => $this->project('Empty')]])));
    }

    public function testLevelBadgeMarkupForCurrentUser(): void
    {
        [$alice] = $this->seed();
        $this->actAs($alice);
        $html = $this->tpl('TimeReport:layout/level');

        $this->assertStringContainsString('class="tr-level"', $html);
        $this->assertStringContainsString('data-tr-user="' . $alice . '"', $html);
        $this->assertStringContainsString('data-tr-xp="35"', $html);
        $this->assertStringContainsString('data-tr-level="1"', $html);
        $this->assertStringContainsString('data-tr-next="65"', $html);
    }

    public function testLevelBadgeOmitsPartyWithoutAgents(): void
    {
        [$alice] = $this->seed();
        $this->actAs($alice);
        $this->assertStringNotContainsString('data-tr-party-xp', $this->tpl('TimeReport:layout/level'));
    }

    public function testLevelBadgeShowsPartyForOwners(): void
    {
        [$alice, $p] = $this->seed();
        $bot = $this->user('alice.claude');
        $this->agentsTable([$bot => $alice]);
        $this->subtask($this->task($p), $bot, 2);
        $this->container['xpCache']->invalidateAll();
        $this->actAs($alice);

        $this->assertStringContainsString('data-tr-party-xp="45"', $this->tpl('TimeReport:layout/level'));
    }

    public function testLevelBadgeIsEmptyWhenLoggedOut(): void
    {
        $this->assertSame('', trim($this->tpl('TimeReport:layout/level')));
    }

    public function testPluginAttachesTrackAndLevelHooks(): void
    {
        (new Plugin($this->container))->initialize();
        $hook = $this->container['hook'];
        $this->assertContains('TimeReport:project/track', array_column($hook->getListeners('template:project:header:after'), 'template'));
        $this->assertContains('TimeReport:layout/level', array_column($hook->getListeners('template:layout:top'), 'template'));
    }

    /**
     * The rendered contract, frozen. Battle Lobby's tests read this file.
     * Regenerate deliberately with TR_UPDATE_FIXTURE=1, and only for ADDITIVE changes.
     */
    public function testRenderedContractMatchesFixture(): void
    {
        [$alice, $p] = $this->seed();
        $this->actAs($alice);
        $card = ['id' => 42, 'project_id' => $p, 'nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4];

        $html = "<!-- TimeReport progress contract v1 (generated by ProgressContractTest) -->\n"
            . "<!-- card -->\n" . trim($this->tpl('TimeReport:board/progress', ['task' => $card])) . "\n"
            . "<!-- track -->\n" . trim($this->tpl('TimeReport:project/track', ['project' => ['id' => $p]])) . "\n"
            . "<!-- level -->\n" . trim($this->tpl('TimeReport:layout/level')) . "\n";
        $html = preg_replace('/data-tr-(project|milestone|user)="\d+"/', 'data-tr-$1="N"', $html);

        if (getenv('TR_UPDATE_FIXTURE') === '1') {
            @mkdir(dirname(self::FIXTURE), 0777, true);
            file_put_contents(self::FIXTURE, $html);
        }
        $this->assertFileExists(self::FIXTURE, 'Run once with TR_UPDATE_FIXTURE=1 to create the fixture.');
        $this->assertSame(file_get_contents(self::FIXTURE), $html);
    }
}
```

Here, `alice` earns 10 (subtask) + 25 (closed task, score 0) = 35 XP, which is level 1 with 65 to go. The party adds the agent's 10, giving 45. Add `'project/track.php'` and `'layout/level.php'` to `TemplateAssetsTest`'s template list.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressContractTest.php --no-coverage; cd -`
Expected: FAIL. The template `TimeReport:project/track` is not found, and `xpCache` is not in the container (the Plugin is not initialised in this test). Register `xpCache` in `setUp`: `$this->container['xpCache'] = fn ($c) => new \Kanboard\Plugin\TimeReport\Model\XpCache($c);`.

- [ ] **Step 3: Extend the helper**

Add to `TimeReport/Helper/ProgressHelper.php`:

```php
use Kanboard\Plugin\TimeReport\Model\XpCache;
use Kanboard\Plugin\TimeReport\Model\XpModel;
```

```php
    public function track(int $projectId): ?array
    {
        $p = (new ProgressModel($this->container))->projectProgress($projectId);
        return $p['total'] > 0 ? $p : null;
    }

    public function level(): ?array
    {
        if (! $this->userSession->isLogged()) {
            return null;
        }
        $userId = (int) $this->userSession->getId();
        $xp = (new XpCache($this->container))->lifetime($userId);

        return [
            'user_id'  => $userId,
            'level'    => XpModel::levelFor($xp['total']),
            'xp'       => $xp['total'],
            'next'     => XpModel::nextFor($xp['total']),
            'party_xp' => $xp['party_xp'],
        ];
    }
```

- [ ] **Step 4: Write the two templates**

`TimeReport/Template/project/track.php`:

```php
<?php $trt = $this->helper->timeReportProgress->track((int) $project['id']) ?>
<?php if ($trt !== null): ?>
<section class="tr-track" data-tr-contract="1" data-tr-project="<?= (int) $trt['project_id'] ?>" data-tr-project-pct="<?= (int) $trt['pct'] ?>" aria-label="<?= t('Project progress') ?>">
    <div class="tr-track__project" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $trt['pct'] ?>" style="--tr-pct:<?= (int) $trt['pct'] ?>%">
        <span class="tr-track__fill"></span>
        <span class="tr-track__count"><?= (int) $trt['closed'] ?>/<?= (int) $trt['total'] ?></span>
    </div>
    <ol class="tr-track__milestones">
        <?php foreach ($trt['milestones'] as $trms): ?>
        <li class="tr-track__milestone" data-tr-milestone="<?= (int) $trms['task_id'] ?>" data-tr-pct="<?= (int) $trms['pct'] ?>" data-tr-due="<?= $this->text->e($trms['due']) ?>" data-tr-state="open" style="--tr-pct:<?= (int) $trms['pct'] ?>%">
            <span class="tr-track__title"><?= $this->text->e($trms['title']) ?></span>
            <span class="tr-track__count"><?= (int) $trms['closed'] ?>/<?= (int) $trms['total'] ?></span>
        </li>
        <?php endforeach ?>
    </ol>
</section>
<?php endif ?>
```

`TimeReport/Template/layout/level.php`:

```php
<?php $trl = $this->helper->timeReportProgress->level() ?>
<?php if ($trl !== null): ?>
<span class="tr-level" data-tr-contract="1" data-tr-user="<?= (int) $trl['user_id'] ?>" data-tr-level="<?= (int) $trl['level'] ?>" data-tr-xp="<?= (int) $trl['xp'] ?>" data-tr-next="<?= (int) $trl['next'] ?>"<?php if ($trl['party_xp'] !== null): ?> data-tr-party-xp="<?= (int) $trl['party_xp'] ?>"<?php endif ?>>
    <span class="tr-level__label"><?= t('Level') ?> <?= (int) $trl['level'] ?></span>
</span>
<?php endif ?>
```

- [ ] **Step 5: Attach the hooks in `Plugin.php`**

After the board footer attach from Task 5:

```php
        // ── Theme-only surfaces (hidden by progress.css; a theme reveals them) ──
        $this->template->hook->attach('template:project:header:after', 'TimeReport:project/track');
        $this->template->hook->attach('template:layout:top', 'TimeReport:layout/level');
```

- [ ] **Step 6: Write the contract doc**

`TimeReport/docs/progress-contract.md`:

````markdown
# TimeReport progress — theme contract v1

TimeReport ≥ 1.5.0 renders three server-side surfaces. Themes (e.g. Battle Lobby) restyle them with CSS
and may read the `data-tr-*` values from external JS. No inline script is involved, so the contract is CSP-safe.

**Stability:** within v1, classes and attributes are only ever **added**, never renamed or removed. A breaking
change bumps `data-tr-contract` and TimeReport's major version. `Test/fixtures/progress-contract.html` is the
rendered reference.

| Surface | Hook | Visible in stock UI |
|---|---|---|
| Card progress `.tr-progress` | `template:board:task:footer` | yes |
| Pass track `.tr-track` | `template:project:header:after` | no (`display:none`) |
| Level badge `.tr-level` | `template:layout:top` | no (`display:none`) |

## Card progress

```html
<div class="tr-progress" data-tr-contract="1" data-tr-task="42"
     data-tr-subtasks-done="3" data-tr-subtasks-total="5" data-tr-pct="60"
     data-tr-time-spent="3.4" data-tr-time-est="4" data-tr-time-state="ok">
  <div class="tr-progress__bar" role="progressbar" aria-valuenow="60" style="--tr-pct:60%">
    <span class="tr-progress__fill"></span><span class="tr-progress__label">3/5</span>
  </div>
  <div class="tr-progress__time tr-progress__time--ok" style="--tr-pct:85%">
    <span class="tr-progress__track"><span class="tr-progress__fill"></span></span>
    <span class="tr-progress__label">3.4/4h</span>
  </div>
</div>
```

- The subtask attributes and `.tr-progress__bar` appear only when the task has subtasks.
- The time attributes and `.tr-progress__time` appear only when the task has an estimate. `data-tr-time-state` is `ok` or `over`; the width is capped at 100%.
- A task with neither renders nothing.

## Pass track

`section.tr-track[data-tr-contract][data-tr-project][data-tr-project-pct]` contains `.tr-track__project`
(`--tr-pct`, `.tr-track__fill`, `.tr-track__count`) and `ol.tr-track__milestones` > `li.tr-track__milestone`
with `data-tr-milestone`, `data-tr-pct`, `data-tr-due` (`Y-m-d`, or empty when undated) and `data-tr-state="open"`.
It holds open milestones (core "is a milestone of" links), dated first by due date, then undated by id.
A project with no tasks renders nothing.

## Level badge

`span.tr-level[data-tr-contract][data-tr-user][data-tr-level][data-tr-xp][data-tr-next]` with `.tr-level__label`.
`data-tr-next` is the XP still needed for the next level. `data-tr-party-xp` (own + agents' XP) appears only
when the Agents plugin is installed and the user owns agents. It renders nothing when logged out.
````

- [ ] **Step 7: Generate the fixture, inspect it, run the tests**

```bash
cd testing/kanboard-src && TR_UPDATE_FIXTURE=1 vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressContractTest.php --no-coverage; cd -
cat TimeReport/Test/fixtures/progress-contract.html
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressContractTest.php --no-coverage; cd -
```

Expected: the fixture shows all three surfaces with the documented attributes, and the second run PASSES (8 tests) without the env var. Then run the full suite: OK.

- [ ] **Step 8: Commit**

```bash
git -C TimeReport add Helper/ProgressHelper.php Template/project/track.php Template/layout/level.php Plugin.php docs/progress-contract.md Test/fixtures/progress-contract.html Test/ProgressContractTest.php Test/TemplateAssetsTest.php
git -C TimeReport commit -m "feat(progress): pass track + level badge (theme-only), contract v1 doc and fixture"
```

---

### Task 7: Performance budget — at most 3 extra queries, flat in card count

**Files:**
- Create: `TimeReport/Test/ProgressPerformanceTest.php`

**Interfaces:**
- Consumes: the three templates and the helper (Tasks 5–6); `XpCache` (Task 4).

- [ ] **Step 1: Write the test**

`TimeReport/Test/ProgressPerformanceTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;

/** Spec Kanboard #5383: ≤ 3 extra queries per page, independent of the number of cards. */
class ProgressPerformanceTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    /** Bulk rows straight into the tables: no events, fast enough for 500 cards. */
    private function bulkProject(int $cards): int
    {
        $p = $this->project('Big' . $cards);
        $db = $this->container['db'];
        $column = (int) $db->table('columns')->eq('project_id', $p)->asc('position')->findOneColumn('id');
        $swimlane = (int) $db->table('swimlanes')->eq('project_id', $p)->findOneColumn('id');
        for ($i = 0; $i < $cards; $i++) {
            $db->table('tasks')->insert(['title' => "Card $i", 'project_id' => $p, 'column_id' => $column, 'swimlane_id' => $swimlane,
                'position' => $i + 1, 'is_active' => 1, 'time_estimated' => 4, 'time_spent' => $i % 6, 'date_creation' => time()]);
            $taskId = (int) $db->getLastId();
            $db->table('subtasks')->insert(['task_id' => $taskId, 'title' => 's1', 'status' => 2, 'user_id' => 1]);
            $db->table('subtasks')->insert(['task_id' => $taskId, 'title' => 's2', 'status' => 0, 'user_id' => 1]);
        }
        return $p;
    }

    /** Queries the progress surfaces add on top of core's board query, for one page. */
    private function extraQueries(int $projectId): int
    {
        $rows = $this->container['taskFinderModel']->getExtendedQuery()->eq('tasks.project_id', $projectId)->findAll();
        $tpl = $this->container['template'];
        $h = $this->container['db']->getStatementHandler();

        $before = $h->getNbQueries();
        foreach ($rows as $row) {
            $tpl->render('TimeReport:board/progress', ['task' => $row]);
        }
        $tpl->render('TimeReport:project/track', ['project' => ['id' => $projectId]]);
        $tpl->render('TimeReport:layout/level');
        return $h->getNbQueries() - $before;
    }

    public function testBudgetIsAtMostThreeAndFlatInCardCount(): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
        $small = $this->bulkProject(50);
        $large = $this->bulkProject(500);
        $this->container['xpCache']->lifetime(1);   // warm the badge cache, as any earlier page would

        $smallCost = $this->extraQueries($small);
        $largeCost = $this->extraQueries($large);

        $this->assertLessThanOrEqual(3, $largeCost);
        $this->assertSame($smallCost, $largeCost, 'cost must not grow with the number of cards');
    }

    public function testCardMetersCostZeroQueries(): void
    {
        $p = $this->bulkProject(200);
        $rows = $this->container['taskFinderModel']->getExtendedQuery()->eq('tasks.project_id', $p)->findAll();
        $h = $this->container['db']->getStatementHandler();

        $before = $h->getNbQueries();
        foreach ($rows as $row) {
            $this->container['template']->render('TimeReport:board/progress', ['task' => $row]);
        }
        $this->assertSame(0, $h->getNbQueries() - $before);
        $this->assertCount(200, $rows);
    }
}
```

Register `xpCache` in `setUp` as in Task 6 Step 2. The project header renders once per page, which is why `ProgressHelper::track()` carries no memo (YAGNI). Don't add one to game this test, and don't re-register the helper mid-test: Kanboard's helper registry is a Pimple container, and overriding a service that is already in use throws.

- [ ] **Step 2: Run it**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ProgressPerformanceTest.php --no-coverage; cd -`
Expected: PASS (2 tests). Card meters cost 0; the track costs 2 and the warm badge 1, so the total is 3 for both board sizes.

If the total is above 3, find the extra query with `$this->container['db']->getStatementHandler()->withLogging()` and `getLogMessages()`, then fix the code (usually a missed memo or an uncached lookup in a template). Never fix it by loosening the assertion.

- [ ] **Step 3: Commit**

```bash
git -C TimeReport add Test/ProgressPerformanceTest.php
git -C TimeReport commit -m "test(progress): 500-card query budget — ≤3 extra queries, flat in card count"
```

---

### Task 8: JSON-RPC — five read-only procedures behind a permission-checked service

**Files:**
- Create: `TimeReport/Model/ProgressApi.php`, `TimeReport/Api/TimeReportProgressProcedure.php`, `TimeReport/Api/TimeReportXpProcedure.php`, `TimeReport/Test/ProgressApiTest.php`, `TimeReport/Test/ProcedureSurfaceTest.php`
- Modify: `TimeReport/Plugin.php`

**Interfaces:**
- Consumes: `ProgressModel` (Task 2), `XpModel` (Task 3), `XpCache` (Task 4).
- Produces: JSON-RPC methods `getTaskProgress(task_id)`, `getProjectProgress(project_id)`, `getMilestoneProgress(task_id)`, `getUserXp(user_id, project_id = null, from = null, to = null)` and `getXpLeaderboard(project_id, from = null, to = null)`. Each returns `false` on invalid input or a missing object, and throws `JsonRPC\Exception\AccessDeniedException` when a personal-token caller lacks project access. Return shapes are in the test below. `getXpLeaderboard`'s `level` is the level of the XP shown.

- [ ] **Step 1: Write the failing tests**

`TimeReport/Test/ProcedureSurfaceTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure;
use Kanboard\Plugin\TimeReport\Api\TimeReportXpProcedure;

/** withObject() exposes EVERY method (private too) and lets core win name clashes. Pin both. */
class ProcedureSurfaceTest extends Base
{
    private const SURFACE = [
        TimeReportProgressProcedure::class => ['getTaskProgress', 'getProjectProgress', 'getMilestoneProgress'],
        TimeReportXpProcedure::class       => ['getUserXp', 'getXpLeaderboard'],
    ];

    public function testProcedureClassesDeclareOnlyTheirRpcMethods(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            $declared = array_map(fn ($m) => $m->getName(), array_filter(
                (new \ReflectionClass($class))->getMethods(),
                fn ($m) => $m->getDeclaringClass()->getName() === $class
            ));
            sort($declared);
            sort($methods);
            $this->assertSame($methods, $declared, $class);
        }
    }

    public function testNoCoreProcedureShadowsOurNames(): void
    {
        $files = glob('app/Api/Procedure/*Procedure.php');
        $this->assertNotEmpty($files, 'run from the Kanboard root');
        foreach ($files as $file) {
            $core = 'Kanboard\\Api\\Procedure\\' . basename($file, '.php');
            foreach (array_merge(...array_values(self::SURFACE)) as $method) {
                $this->assertFalse(method_exists($core, $method), $core . '::' . $method . ' would win over the plugin');
            }
        }
    }

    public function testProcedureParamsCarryNoTypeHints(): void
    {
        foreach (self::SURFACE as $class => $methods) {
            foreach ($methods as $m) {
                foreach ((new \ReflectionMethod($class, $m))->getParameters() as $p) {
                    $this->assertNull($p->getType(), $class . '::' . $m . ' $' . $p->getName());
                }
            }
        }
    }
}
```

`TimeReport/Test/ProgressApiTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure;
use Kanboard\Plugin\TimeReport\Api\TimeReportXpProcedure;

class ProgressApiTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
    }

    private function progress(): TimeReportProgressProcedure
    {
        return new TimeReportProgressProcedure($this->container);
    }

    private function xp(): TimeReportXpProcedure
    {
        return new TimeReportXpProcedure($this->container);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function member(int $projectId, int $userId): void
    {
        $this->container['projectUserRoleModel']->addUser($projectId, $userId, \Kanboard\Core\Security\Role::PROJECT_MEMBER);
    }

    public function testTaskProgressShape(): void
    {
        $t = $this->task($this->project(), ['time_estimated' => 4, 'time_spent' => 1]);
        $this->subtask($t, 1, 2);

        $r = $this->progress()->getTaskProgress($t);
        $this->assertSame(1, $r['subtasks_done']);
        $this->assertSame(1, $r['subtasks_total']);
        $this->assertSame(100, $r['pct']);
        $this->assertSame('ok', $r['time_state']);
        $this->assertFalse($this->progress()->getTaskProgress(99999));
        $this->assertFalse($this->progress()->getTaskProgress('abc'));
    }

    public function testProjectAndMilestoneProgress(): void
    {
        $p = $this->project();
        $ms = $this->task($p, ['title' => 'Beta']);
        $a = $this->task($p);
        $this->milestone($ms, [$a, $this->task($p)]);
        $this->close($a);

        $r = $this->progress()->getProjectProgress($p);
        $this->assertSame(1, $r['closed']);
        $this->assertSame(3, $r['total']);
        $this->assertSame('Beta', $r['milestones'][0]['title']);

        $m = $this->progress()->getMilestoneProgress($ms);
        $this->assertSame(50, $m['pct']);
        $this->assertCount(2, $m['members']);
        $this->assertFalse($this->progress()->getMilestoneProgress($a));
    }

    public function testProjectProgressDeniedForNonMember(): void
    {
        $p = $this->project();
        $outsider = $this->user('outsider');
        $this->actAs($outsider);

        $this->expectException(AccessDeniedException::class);
        $this->progress()->getProjectProgress($p);
    }

    public function testTaskProgressDeniedForNonMember(): void
    {
        $t = $this->task($this->project());
        $this->actAs($this->user('outsider'));

        $this->expectException(AccessDeniedException::class);
        $this->progress()->getTaskProgress($t);
    }

    public function testUserXpLifetimeAndRange(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice, 'score' => 1]);
        $s = $this->subtask($t, $alice, 2);
        $this->close($t);
        $this->container['db']->table('tasks')->eq('id', $t)->update(['date_completed' => strtotime('2026-10-05 10:00:00')]);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => $alice, 'completed_at' => strtotime('2026-09-01 10:00:00')]);

        $life = $this->xp()->getUserXp($alice);
        $this->assertSame(45, $life['xp']);
        $this->assertSame(1, $life['level']);
        $this->assertSame(55, $life['next']);
        $this->assertSame([$p => 45], $life['by_project']);
        $this->assertArrayNotHasKey('party', $life);

        $range = $this->xp()->getUserXp($alice, $p, '2026-10-01', '2026-10-31');
        $this->assertSame(35, $range['xp']);

        $this->assertFalse($this->xp()->getUserXp($alice, null, 'not-a-date', '2026-10-31'));
    }

    public function testUserXpHidesInvisibleProjects(): void
    {
        $alice = $this->user('alice');
        $viewer = $this->user('viewer');
        $shared = $this->project('Shared');
        $secret = $this->project('Secret');
        $this->member($shared, $viewer);
        $this->subtask($this->task($shared), $alice, 2);
        $this->subtask($this->task($secret), $alice, 2);
        $this->actAs($viewer);

        $r = $this->xp()->getUserXp($alice);
        $this->assertSame([$shared => 10], $r['by_project']);
        $this->assertSame(10, $r['xp']);
    }

    public function testUserXpPartyForOwners(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);
        $t = $this->task($this->project());
        $this->subtask($t, $owner, 2);
        $this->subtask($t, $bot, 2);

        $r = $this->xp()->getUserXp($owner);
        $this->assertSame(20, $r['party']['xp']);
        $this->assertSame([['user_id' => $bot, 'xp' => 10]], $r['party']['members']);
    }

    public function testLeaderboardMarksAgents(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $bot, 2);
        $this->subtask($t, $owner, 2);

        $this->assertSame([
            ['user_id' => $bot, 'xp' => 20, 'level' => 1, 'is_agent' => true, 'owner_id' => $owner],
            ['user_id' => $owner, 'xp' => 10, 'level' => 1, 'is_agent' => false, 'owner_id' => 0],
        ], $this->xp()->getXpLeaderboard($p));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml --filter 'ProgressApiTest|ProcedureSurfaceTest' plugins/TimeReport/Test/ --no-coverage; cd -`
Expected: FAIL with `Class "Kanboard\Plugin\TimeReport\Api\TimeReportProgressProcedure" not found`.

- [ ] **Step 3: Write the service**

`TimeReport/Model/ProgressApi.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Model;

use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;

/**
 * Permission-checked logic behind the procedures (spec Kanboard #5381). Lives
 * here, not on the procedure classes, because withObject() exposes every
 * method of those objects, private ones included.
 *
 * App-token calls (no user session) see everything, like core. Personal-token
 * calls see only projects the caller is allowed in.
 */
class ProgressApi extends Base
{
    public function taskProgress($taskId)
    {
        $taskId = self::id($taskId);
        $projectId = $taskId ? (int) $this->taskFinderModel->getProjectId($taskId) : 0;
        if ($projectId === 0) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->taskProgress($taskId) ?? false;
    }

    public function projectProgress($projectId)
    {
        $projectId = self::id($projectId);
        if (! $projectId || ! $this->projectModel->exists($projectId)) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->projectProgress($projectId);
    }

    public function milestoneProgress($taskId)
    {
        $taskId = self::id($taskId);
        $projectId = $taskId ? (int) $this->taskFinderModel->getProjectId($taskId) : 0;
        if ($projectId === 0) {
            return false;
        }
        $this->assertProject($projectId);
        return (new ProgressModel($this->container))->milestoneProgress($taskId) ?? false;
    }

    public function userXp($userId, $projectId = null, $from = null, $to = null)
    {
        $userId = self::id($userId);
        $range = self::range($from, $to);
        if (! $userId || $range === false) {
            return false;
        }
        $projectId = $projectId === null || $projectId === '' ? null : self::id($projectId);
        if ($projectId !== null) {
            $this->assertProject($projectId);
        }

        $own = $this->xpByProject($userId, $range, $projectId);
        $out = ['xp' => array_sum($own)] + self::levelOf(array_sum($own)) + ['by_project' => $own];

        $xpModel = new XpModel($this->container);
        $agents = array_keys(array_filter($xpModel->agentOwnerMap(), fn (int $owner) => $owner === $userId));
        if ($agents !== []) {
            $members = [];
            foreach ($agents as $agentId) {
                $members[] = ['user_id' => $agentId, 'xp' => array_sum($this->xpByProject($agentId, $range, $projectId))];
            }
            $partyXp = $out['xp'] + array_sum(array_column($members, 'xp'));
            $out['party'] = ['xp' => $partyXp] + self::levelOf($partyXp) + ['members' => $members];
        }

        return $out;
    }

    public function leaderboard($projectId, $from = null, $to = null)
    {
        $projectId = self::id($projectId);
        $range = self::range($from, $to);
        if (! $projectId || $range === false || ! $this->projectModel->exists($projectId)) {
            return false;
        }
        $this->assertProject($projectId);

        $xpModel = new XpModel($this->container);
        $byUser = $xpModel->byUser($projectId, $range[0] ?? null, $range[1] ?? null);
        $owners = $xpModel->agentOwnerMap();

        $rows = [];
        foreach ($byUser as $uid => $xp) {
            $rows[] = ['user_id' => $uid, 'xp' => $xp, 'level' => XpModel::levelFor($xp), 'is_agent' => isset($owners[$uid]), 'owner_id' => $owners[$uid] ?? 0];
        }
        usort($rows, fn (array $a, array $b) => [$b['xp'], $a['user_id']] <=> [$a['xp'], $b['user_id']]);
        return $rows;
    }

    /** @return array<int,int> project_id => xp, restricted to $projectId and to what the caller may see */
    private function xpByProject(int $userId, array $range, ?int $projectId): array
    {
        $byProject = $range === []
            ? (new XpCache($this->container))->lifetime($userId)['by_project']
            : (new XpModel($this->container))->deriveUser($userId, $range[0], $range[1]);

        if ($projectId !== null) {
            return isset($byProject[$projectId]) ? [$projectId => $byProject[$projectId]] : [];
        }
        if ($this->userSession->isLogged()) {
            $visible = array_map('intval', $this->projectPermissionModel->getActiveProjectIds($this->userSession->getId()));
            $byProject = array_intersect_key($byProject, array_flip($visible));
        }
        return $byProject;
    }

    private function assertProject(int $projectId): void
    {
        if ($this->userSession->isLogged()
            && ! $this->projectPermissionModel->isUserAllowed($projectId, $this->userSession->getId())) {
            throw new AccessDeniedException('Project Access Denied');
        }
    }

    private static function levelOf(int $xp): array
    {
        return ['level' => XpModel::levelFor($xp), 'next' => XpModel::nextFor($xp)];
    }

    private static function id($value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /** @return array{}|array{0:int,1:int}|false  [] = lifetime; false = invalid */
    private static function range($from, $to)
    {
        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return [];
        }
        foreach ([$from, $to] as $d) {
            if (! is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1 || strtotime($d) === false) {
                return false;
            }
        }
        return [(int) strtotime($from . ' 00:00:00'), (int) strtotime($to . ' 23:59:59')];
    }
}
```

- [ ] **Step 4: Write the two procedure classes**

`TimeReport/Api/TimeReportProgressProcedure.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\TimeReport\Model\ProgressApi;

// JSON-RPC: progress reads (spec Kanboard #5381). Prefixed short name because core's ACL
// maps key on short class names. Holds ONLY RPC methods (withObject exposes every method)
// and declares params without type hints (a TypeError is a fatal with no JSON-RPC body).
class TimeReportProgressProcedure extends BaseProcedure
{
    public function getTaskProgress($task_id)
    {
        return (new ProgressApi($this->container))->taskProgress($task_id);
    }

    public function getProjectProgress($project_id)
    {
        return (new ProgressApi($this->container))->projectProgress($project_id);
    }

    public function getMilestoneProgress($task_id)
    {
        return (new ProgressApi($this->container))->milestoneProgress($task_id);
    }
}
```

`TimeReport/Api/TimeReportXpProcedure.php`:

```php
<?php

namespace Kanboard\Plugin\TimeReport\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\TimeReport\Model\ProgressApi;

// JSON-RPC: XP reads (spec Kanboard #5381). Same rules as TimeReportProgressProcedure.
class TimeReportXpProcedure extends BaseProcedure
{
    public function getUserXp($user_id, $project_id = null, $from = null, $to = null)
    {
        return (new ProgressApi($this->container))->userXp($user_id, $project_id, $from, $to);
    }

    public function getXpLeaderboard($project_id, $from = null, $to = null)
    {
        return (new ProgressApi($this->container))->leaderboard($project_id, $from, $to);
    }
}
```

- [ ] **Step 5: Register them in `Plugin.php`**

Add these `use` lines for both procedure classes, then add this after the subscriber line:

```php
        // ── JSON-RPC (read-only). withObject so core wins any name clash. ─────
        $this->api->getProcedureHandler()->withObject(new TimeReportProgressProcedure($this->container));
        $this->api->getProcedureHandler()->withObject(new TimeReportXpProcedure($this->container));
```

- [ ] **Step 6: Run the tests to verify they pass**

Run the command from Step 2, then the full suite. Expected: PASS (`ProgressApiTest` 8, `ProcedureSurfaceTest` 3), and the full suite OK.

- [ ] **Step 7: Commit**

```bash
git -C TimeReport add Model/ProgressApi.php Api Plugin.php Test/ProgressApiTest.php Test/ProcedureSurfaceTest.php
git -C TimeReport commit -m "feat(api): read-only progress + XP JSON-RPC procedures with project permission checks"
```

---

### Task 9: XP in the report — control bar, Markdown and CSV

**Files:**
- Modify: `TimeReport/Model/TimeReportModel.php` (inside `report()`), `TimeReport/Helper/TimeReportHelper.php` (`toMarkdown`, `toCsv`), `TimeReport/Template/report/show.php`
- Create: `TimeReport/Test/ReportXpTest.php`

**Interfaces:**
- Consumes: `XpModel->byUser()` (Task 3).
- Produces: `report()` gains `'xp' => ['users' => array<int,int> (each subject user ⇒ XP in range, 0-filled), 'total' => int]`. The existing keys and the frozen six positional parameters are unchanged (TimeInvoice calls it). Markdown gains a line `**XP earned:** N` after the total hours. CSV gains a row `# XP earned,N` after `# Total hours`.

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/ReportXpTest.php`:

```php
<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

class ReportXpTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
    }

    private function report(int $projectId): array
    {
        return (new TimeReportModel($this->container))->report($projectId, date('Y-m-01'), date('Y-m-t'), 'task', false, 1);
    }

    public function testReportCarriesXpForTheSubjectUserInRange(): void
    {
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => 1, 'score' => 2]);
        $s = $this->subtask($t, 1, 2);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => time()]);
        $this->close($t);

        $r = $this->report($p);
        $this->assertSame(['users' => [1 => 55], 'total' => 55], $r['xp']);   // 10 + 25 + 20
    }

    public function testReportXpIsZeroFilledWhenNothingWasEarned(): void
    {
        $this->assertSame(['users' => [1 => 0], 'total' => 0], $this->report($this->project())['xp']);
    }

    public function testMarkdownAndCsvShowXp(): void
    {
        $helper = new TimeReportHelper($this->container);
        $r = $this->report($this->project());
        $r['xp'] = ['users' => [1 => 55], 'total' => 55];

        $this->assertStringContainsString('**XP earned:** 55', $helper->toMarkdown($r));
        $this->assertStringContainsString("# XP earned,55", $helper->toCsv($r));
    }

    public function testMarkdownAndCsvToleratesReportsWithoutXp(): void
    {
        $helper = new TimeReportHelper($this->container);
        $r = $this->report($this->project());
        unset($r['xp']);

        $this->assertStringNotContainsString('XP earned', $helper->toMarkdown($r));
        $this->assertStringNotContainsString('XP earned', $helper->toCsv($r));
    }

    public function testShowTemplateRendersXp(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Template/report/show.php');
        $this->assertStringContainsString("\$report['xp']", $src);
        $this->assertStringContainsString("t('XP')", $src);
    }
}
```

`createProgressSchema()` runs version_1 too, so the summary tables exist if `report()` needs them.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ReportXpTest.php --no-coverage; cd -`
Expected: FAIL with `Undefined array key "xp"`.

- [ ] **Step 3: Add XP to `report()`**

In `TimeReport/Model/TimeReportModel.php` (same namespace as `XpModel`, so no `use` line is needed), just before `$project = $this->projectModel->getById($projectId);` inside `report()`:

```php
        // XP earned in range by the same subject users (spec Kanboard #5380); 2 grouped queries.
        $xpByUser = (new XpModel($this->container))->byUser($projectId, $startTs, $endTs);
        $xpUsers  = [];
        foreach ($subjectIds as $uid) {
            $xpUsers[(int) $uid] = (int) ($xpByUser[(int) $uid] ?? 0);
        }
```

Add this key to the `$report = [...]` array, after `'total_hours'`:

```php
            'xp'                => ['users' => $xpUsers, 'total' => array_sum($xpUsers)],
```

- [ ] **Step 4: Add the Markdown and CSV lines**

In `TimeReportHelper::toCsv`, directly after the `# Total hours` row:

```php
        if (isset($report['xp']['total'])) {
            $out[] = $this->csvRow(['# XP earned', (string) (int) $report['xp']['total']]);
        }
```

In `TimeReportHelper::toMarkdown`, find the line that writes the total hours. Directly after it, append the XP line in the same style:

```php
        if (isset($report['xp']['total'])) {
            $lines[] = '**XP earned:** ' . (int) $report['xp']['total'];
        }
```

Use the variable name that method actually builds its output with (it may not be `$lines`). Keep the XP line adjacent to the hours total.

- [ ] **Step 5: Show XP in the control bar**

In `TimeReport/Template/report/show.php`, directly after the total-hours `<span class="tr-copy-num" …>…</span>` inside `.tr-controlbar-crumbs`:

```php
            <?php if (isset($report['xp']['total'])): ?>
            &nbsp;·&nbsp; <strong><?= t('XP') ?>:</strong>
            <span class="tr-copy-num" data-tr-copyval="<?= (int) $report['xp']['total'] ?>" data-tr-copied="<?= t('Copied') ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= (int) $report['xp']['total'] ?></span>
            <?php endif ?>
```

- [ ] **Step 6: Run the tests, including TimeInvoice's**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ReportXpTest.php --no-coverage; cd -
./testing/run-plugin-tests.sh TimeReport 2>&1 | tail -3
./testing/run-plugin-tests.sh TimeInvoice 2>&1 | tail -3
```

Expected: `ReportXpTest` PASS (5 tests), and both suites OK. TimeInvoice calls `report()` and must be unaffected by the new key. If the existing TimeReport tests assert the exact `report()` key set or the exact Markdown/CSV output, update those expectations to include the XP key and line. Never drop the XP line to make them pass. If the report runs on a fresh DB without version_2 (e.g. `TimeReportModelTest` doesn't create the schema), add `use ProgressFixture;` + `createProgressSchema()` to that test's `setUp`.

- [ ] **Step 7: Commit**

```bash
git -C TimeReport add Model/TimeReportModel.php Helper/TimeReportHelper.php Template/report/show.php Test
git -C TimeReport commit -m "feat(report): XP earned in range alongside hours (control bar, Markdown, CSV)"
```

---

### Task 10: TaskProgressBar admin notice with per-user dismissal

**Files:**
- Create: `TimeReport/Template/config/tpb_notice.php`, `TimeReport/Test/TpbNoticeTest.php`
- Modify: `TimeReport/Helper/ProgressHelper.php`, `TimeReport/Controller/TimeReportController.php`, `TimeReport/Plugin.php`, `TimeReport/Assets/css/progress.css`, `TimeReport/Test/TemplateAssetsTest.php`

**Interfaces:**
- Produces: `ProgressHelper::TPB_DISMISS_KEY = 'timereport_tpb_notice_dismissed'`; `ProgressHelper->tpbNoticeVisible(): bool` (logged-in admin, `TaskProgressBar` among `pluginLoader->getPlugins()` keys, not dismissed; checked at render time, so load order doesn't matter); route `timereport/tpb-dismiss` → `TimeReportController::dismissTpbNotice()` (POST, CSRF-checked, saves user metadata `1`, redirects to the dashboard).

- [ ] **Step 1: Write the failing test**

`TimeReport/Test/TpbNoticeTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;

class TpbNoticeTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
    }

    private function loadFakeTaskProgressBar(): void
    {
        $loader = $this->container['pluginLoader'];
        $prop = new \ReflectionProperty($loader, 'plugins');
        $prop->setValue($loader, $prop->getValue($loader) + ['TaskProgressBar' => new \stdClass()]);
    }

    private function actAs(string $role): int
    {
        $uid = (int) $this->container['userModel']->create(['username' => 'u_' . $role, 'password' => 'x1234567', 'role' => $role]);
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
        return $uid;
    }

    private function helper(): ProgressHelper
    {
        return new ProgressHelper($this->container);
    }

    public function testHiddenWhenTaskProgressBarIsAbsent(): void
    {
        $this->actAs('app-admin');
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testVisibleToAdminsWhileTaskProgressBarIsLoaded(): void
    {
        $this->loadFakeTaskProgressBar();
        $this->actAs('app-admin');
        $this->assertTrue($this->helper()->tpbNoticeVisible());

        $html = $this->container['template']->render('TimeReport:config/tpb_notice');
        $this->assertStringContainsString('plugins/TaskProgressBar', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('tpb-dismiss', $html);
    }

    public function testHiddenFromNonAdmins(): void
    {
        $this->loadFakeTaskProgressBar();
        $this->actAs('app-user');
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testHiddenOnceDismissed(): void
    {
        $this->loadFakeTaskProgressBar();
        $uid = $this->actAs('app-admin');
        $this->container['userMetadataModel']->save($uid, [ProgressHelper::TPB_DISMISS_KEY => '1']);
        $this->assertFalse($this->helper()->tpbNoticeVisible());
    }

    public function testDismissActionChecksCsrfAndIsRouted(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/function dismissTpbNotice\(\): void\s*\{\s*\$this->checkCSRFForm\(\);/', $src);
        $this->assertStringContainsString("'timereport/tpb-dismiss'", file_get_contents(dirname(__DIR__) . '/Plugin.php'));
    }
}
```

Add `'config/tpb_notice.php'` to `TemplateAssetsTest`'s template list. If `ReflectionProperty::setValue` fails because the property is not `plugins`, read `app/Core/Plugin/Loader.php` (line ~128 stores `$this->plugins[$pluginName]`) and use the real property name.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TpbNoticeTest.php --no-coverage; cd -`
Expected: FAIL with `Call to undefined method …ProgressHelper::tpbNoticeVisible()`.

- [ ] **Step 3: Extend the helper**

Add to `ProgressHelper`:

```php
    public const TPB_DISMISS_KEY = 'timereport_tpb_notice_dismissed';

    /** Spec Kanboard #5382: tell admins TaskProgressBar is redundant, until they dismiss it. */
    public function tpbNoticeVisible(): bool
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return false;
        }
        if (! array_key_exists('TaskProgressBar', $this->pluginLoader->getPlugins())) {
            return false;
        }
        return $this->userMetadataModel->get($this->userSession->getId(), self::TPB_DISMISS_KEY, '') !== '1';
    }
```

- [ ] **Step 4: Write the notice template**

`TimeReport/Template/config/tpb_notice.php`:

```php
<?php if ($this->helper->timeReportProgress->tpbNoticeVisible()): ?>
<div class="tr-tpb-notice alert alert-info">
    <form method="post" action="<?= $this->url->href('TimeReportController', 'dismissTpbNotice', ['plugin' => 'TimeReport']) ?>" class="tr-inline-form">
        <?= $this->form->csrf() ?>
        <?= t('TimeReport now shows subtask progress on board cards. TaskProgressBar is redundant: remove the %s folder.', '<code>plugins/TaskProgressBar</code>') ?>
        <button type="submit" class="btn btn-small"><?= t('Dismiss') ?></button>
    </form>
</div>
<?php endif ?>
```

`t()` escapes its arguments in some Kanboard versions. If the `<code>` tag renders escaped, drop the tag and pass the plain path `plugins/TaskProgressBar`. The test only requires the path text.

- [ ] **Step 5: Add the controller action, route and hook**

In `TimeReportController`:

```php
    /** POST: hide the TaskProgressBar notice for the current admin (spec Kanboard #5382). */
    public function dismissTpbNotice(): void
    {
        $this->checkCSRFForm();
        $this->userMetadataModel->save($this->userSession->getId(), [\Kanboard\Plugin\TimeReport\Helper\ProgressHelper::TPB_DISMISS_KEY => '1']);
        $this->response->redirect($this->helper->url->to('DashboardController', 'show'));
    }
```

In `Plugin.php`, add next to the other routes:

```php
        $this->route->addRoute('timereport/tpb-dismiss', 'TimeReportController', 'dismissTpbNotice', 'TimeReport');
```

and next to the level-badge attach:

```php
        $this->template->hook->attach('template:layout:top', 'TimeReport:config/tpb_notice');
```

Append to `progress.css`:

```css
.tr-tpb-notice { margin: 0; border-radius: 0; }
.tr-tpb-notice .btn { margin-left: 0.5em; }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run the command from Step 2, then the full suite. Expected: `TpbNoticeTest` PASS (5 tests), full suite OK.

- [ ] **Step 7: Commit**

```bash
git -C TimeReport add Helper/ProgressHelper.php Template/config/tpb_notice.php Controller/TimeReportController.php Plugin.php Assets/css/progress.css Test/TpbNoticeTest.php Test/TemplateAssetsTest.php
git -C TimeReport commit -m "feat(migration): admin notice while TaskProgressBar is loaded, dismissible per user"
```

---

### Task 11: Release prep 1.5.0 — metadata, CHANGELOG, README (no tag, no push)

**Files:**
- Modify: `TimeReport/Plugin.php`, `TimeReport/plugin.json`, `TimeReport/CHANGELOG.md`, `TimeReport/README.md`, `TimeReport/Test/PluginTest.php`

- [ ] **Step 1: Update the version tests first**

In `TimeReport/Test/PluginTest.php`, rename `testMetadataVersionIs144` to `testMetadataVersionIs150` and change both `'1.4.4'` assertions (in that test and in `testVersionMatchesPluginJson`) to `'1.5.0'`. Add:

```php
    public function testPluginJsonRecommendsAgentsForPartyXp(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $names = array_column($json['recommends'], 'plugin');
        $this->assertContains('AiConnector', $names);
        $this->assertContains('Agents', $names);
    }

    public function testChangelogHeadsWithThisVersion(): void
    {
        $changelog = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        $this->assertMatchesRegularExpression('/^## 1\.5\.0 — \d{4}-\d{2}-\d{2}$/m', $changelog);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/PluginTest.php --no-coverage; cd -`
Expected: FAIL on the version (`'1.4.4'` vs `'1.5.0'`) and on the missing `Agents` recommendation.

- [ ] **Step 3: Bump metadata**

- `Plugin.php`: `getPluginVersion()` returns `'1.5.0'`. `getPluginDescription()` becomes `t('Consultant hours reporting plus progress: subtask and time meters on board cards, project and milestone progress, and XP with levels. Pick a project and date range, choose breakdowns, optionally add an AI summary, copy as Markdown or export CSV.')`. Update the class docblock's first line to `TimeReport — consultant hours reporting and progress (cards, milestones, XP).`
- `plugin.json`: `"version": "1.5.0"`, `"description"` identical to the PHP description (without `t()`), and `recommends` gains:

```json
        { "plugin": "Agents", "min_version": "0.3.0", "reason": "adds party XP totals: an owner's level includes their agents' work (progress works fully without it)" }
```

- [ ] **Step 4: Write the CHANGELOG entry**

Prepend under the title of `TimeReport/CHANGELOG.md` (use the actual date of the commit):

```markdown
## 1.5.0 — 2026-10-07

### Added
- Progress on board cards: a subtask completion bar plus a thin time-vs-estimate meter (overrun shows in red), rendered through the `template:board:task:footer` hook. Replaces the third-party TaskProgressBar plugin.
- Project and milestone progress (core "is a milestone of" links) and XP with levels: completed subtask 10 XP, closed task 25 + 10 × complexity, level L at 50·L·(L−1). Agents' XP rolls into their owner's party total when the Agents plugin is installed.
- XP earned in range shown in the report's control bar, Markdown and CSV.
- Theme contract v1 (`docs/progress-contract.md`): `.tr-progress`, `.tr-track`, `.tr-level` with `data-tr-*` attributes. The pass track and level badge are emitted hidden for themes such as Battle Lobby.
- JSON-RPC: `getTaskProgress`, `getProjectProgress`, `getMilestoneProgress`, `getUserXp`, `getXpLeaderboard` (read-only, project-permission checked).
- Admins see a dismissible notice while TaskProgressBar is still installed; its bar is hidden so cards show one bar.

### Changed
- Schema version 2: `timereport_subtask_completions` (backfilled from timer ends or task dates) and `timereport_xp_cache`.
```

- [ ] **Step 5: README section**

Append a `## Progress and XP` section to `TimeReport/README.md`. It says what shows on cards, links `docs/progress-contract.md` for themes, lists the five JSON-RPC methods with one-line descriptions, gives the XP rules and level formula, and adds a short "Replacing TaskProgressBar" subsection with the switch steps from spec Decision 6 (deploy → check a board → delete `plugins/TaskProgressBar` → confirm the notice is gone; rollback = restore the folder).

- [ ] **Step 6: Full verification**

```bash
./testing/run-plugin-tests.sh TimeReport 2>&1 | tail -3
./testing/run-plugin-tests.sh TimeInvoice 2>&1 | tail -3
for f in $(git -C TimeReport ls-files '*.php'); do php -l "TimeReport/$f" >/dev/null || echo "LINT FAIL $f"; done
grep -rn "setTemplateOverride" TimeReport --include=*.php | grep -v Test/ || echo "no overrides"
git -C TimeReport grep -nE "[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}" -- ':!*.lock' || echo "no emails"
```

Expected: both suites OK, no lint failures, `no overrides`, `no emails`. Record the TimeReport test count in the commit body.

- [ ] **Step 7: Commit (no tag, no push)**

```bash
git -C TimeReport add Plugin.php plugin.json CHANGELOG.md README.md Test/PluginTest.php
git -C TimeReport commit -m "chore: release prep 1.5.0 — progress, milestones, XP"
```

Stop here and ask Carmelo before merging `feat/progress-1.5.0` into `main`, tagging `v1.5.0`, pushing, or updating the ModMenu directory entry.

---

### Task 12: kanboard-mcp — five typed TimeReport tools

**Files (repo `~/MCP/kanboard-mcp`, branch `feat/timereport-progress-tools`):**
- Modify: `main.go`, `api_methods_test.go`
- Create: `timereport_tools_test.go`, `testdata/timereport-1.5.0-procedures.txt`

**Interfaces:**
- Consumes: the JSON-RPC methods from Task 8 (names and params exactly as defined there).
- Produces: MCP tools `get_task_progress {task_id}`, `get_project_progress {project_id}`, `get_milestone_progress {task_id}`, `get_user_xp {user_id, project_id?, from?, to?}`, `get_xp_leaderboard {project_id, from?, to?}`, and the helper `callPluginAPI(ctx, method, params)`, which maps JSON-RPC `-32601` to `TimeReport >= 1.5.0 not installed on this Kanboard (method <name>)`.

- [ ] **Step 1: Branch**

```bash
git -C ~/MCP/kanboard-mcp switch -c feat/timereport-progress-tools
```

- [ ] **Step 2: Write the failing tests**

`~/MCP/kanboard-mcp/timereport_tools_test.go`:

```go
package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"

	"github.com/mark3labs/mcp-go/mcp"
)

// fixedKanboard answers every call with the given raw JSON-RPC body and records calls.
func fixedKanboard(t *testing.T, body string) (*kanboardClient, *[]rpcCall) {
	t.Helper()
	calls := &[]rpcCall{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var c rpcCall
		if err := json.NewDecoder(r.Body).Decode(&c); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		*calls = append(*calls, c)
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	return newKanboardClient(srv.URL, "test-token", "", "", nil), calls
}

type toolHandler = func(context.Context, mcp.CallToolRequest) (*mcp.CallToolResult, error)

func TestTimeReportToolsCallTheirProcedures(t *testing.T) {
	cases := []struct {
		name    string
		handler func(*kanboardClient) toolHandler
		args    map[string]interface{}
		method  string
		params  map[string]interface{}
	}{
		{"task", func(kc *kanboardClient) toolHandler { return kc.getTaskProgressHandler },
			map[string]interface{}{"task_id": float64(7)}, "getTaskProgress", map[string]interface{}{"task_id": float64(7)}},
		{"project", func(kc *kanboardClient) toolHandler { return kc.getProjectProgressHandler },
			map[string]interface{}{"project_id": float64(94)}, "getProjectProgress", map[string]interface{}{"project_id": float64(94)}},
		{"milestone", func(kc *kanboardClient) toolHandler { return kc.getMilestoneProgressHandler },
			map[string]interface{}{"task_id": float64(8)}, "getMilestoneProgress", map[string]interface{}{"task_id": float64(8)}},
		{"user xp lifetime", func(kc *kanboardClient) toolHandler { return kc.getUserXpHandler },
			map[string]interface{}{"user_id": float64(2)}, "getUserXp", map[string]interface{}{"user_id": float64(2)}},
		{"user xp range", func(kc *kanboardClient) toolHandler { return kc.getUserXpHandler },
			map[string]interface{}{"user_id": float64(2), "project_id": float64(94), "from": "2026-10-01", "to": "2026-10-31"},
			"getUserXp", map[string]interface{}{"user_id": float64(2), "project_id": float64(94), "from": "2026-10-01", "to": "2026-10-31"}},
		{"leaderboard", func(kc *kanboardClient) toolHandler { return kc.getXpLeaderboardHandler },
			map[string]interface{}{"project_id": float64(94)}, "getXpLeaderboard", map[string]interface{}{"project_id": float64(94)}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			kc, calls := fixedKanboard(t, `{"jsonrpc":"2.0","id":1,"result":{"ok":true}}`)
			req := mcp.CallToolRequest{}
			req.Params.Arguments = tc.args
			res, err := tc.handler(kc)(context.Background(), req)
			if err != nil || res.IsError {
				t.Fatalf("handler failed: err=%v result=%+v", err, res)
			}
			if len(*calls) != 1 || (*calls)[0].Method != tc.method {
				t.Fatalf("calls = %+v, want one %s", *calls, tc.method)
			}
			if !reflect.DeepEqual((*calls)[0].Params, tc.params) {
				t.Errorf("params = %v, want %v", (*calls)[0].Params, tc.params)
			}
		})
	}
}

func TestTimeReportToolsExplainMissingPlugin(t *testing.T) {
	kc, _ := fixedKanboard(t, `{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}`)
	req := mcp.CallToolRequest{}
	req.Params.Arguments = map[string]interface{}{"project_id": float64(94)}
	res, _ := kc.getProjectProgressHandler(context.Background(), req)
	if !res.IsError {
		t.Fatal("want an error result")
	}
	text := res.Content[0].(mcp.TextContent).Text
	if !strings.Contains(text, "TimeReport >= 1.5.0 not installed") {
		t.Errorf("error text = %q", text)
	}
}
```

In `api_methods_test.go`, load the plugin list as well. After the core file is read into `known`, add:

```go
	if pf, err := os.ReadFile("testdata/timereport-1.5.0-procedures.txt"); err == nil {
		for _, line := range strings.Split(string(pf), "\n") {
			if line = strings.TrimSpace(line); line != "" && !strings.HasPrefix(line, "#") {
				known[line] = true
			}
		}
	} else {
		t.Fatal(err)
	}
```

Change the regexp so it also matches the plugin helper: `` regexp.MustCompile(`call(?:Kanboard|Plugin)API\([^,]+, "([a-zA-Z]+)"`) ``.

`testdata/timereport-1.5.0-procedures.txt`:

```
# JSON-RPC methods added by the TimeReport plugin 1.5.0 (spec: kanboard-plugins/docs/specs/2026-10-07-timereport-progress-v1.5.0-design.md)
getTaskProgress
getProjectProgress
getMilestoneProgress
getUserXp
getXpLeaderboard
```

- [ ] **Step 3: Run them to verify they fail**

Run: `cd ~/MCP/kanboard-mcp && go test ./... 2>&1 | tail -15`
Expected: FAIL to compile with `kc.getTaskProgressHandler undefined`.

- [ ] **Step 4: Implement the helper, tools and handlers in `main.go`**

Next to `callKanboardAPI`:

```go
// callPluginAPI calls a JSON-RPC method provided by a suite plugin (not core) and turns
// "method not found" into an actionable message instead of a bare -32601.
func (kc *kanboardClient) callPluginAPI(ctx context.Context, method string, params interface{}) (interface{}, error) {
	result, err := kc.callKanboardAPI(ctx, method, params)
	if err != nil && strings.Contains(err.Error(), "code -32601") {
		return nil, fmt.Errorf("TimeReport >= 1.5.0 not installed on this Kanboard (method %s)", method)
	}
	return result, err
}

func (kc *kanboardClient) pluginResult(ctx context.Context, method string, params map[string]interface{}) (*mcp.CallToolResult, error) {
	result, err := kc.callPluginAPI(ctx, method, params)
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	resultBytes, err := json.MarshalIndent(result, "", "  ")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	return mcp.NewToolResultText(string(resultBytes)), nil
}

func (kc *kanboardClient) getTaskProgressHandler(ctx context.Context, request mcp.CallToolRequest) (*mcp.CallToolResult, error) {
	id, err := request.RequireInt("task_id")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	return kc.pluginResult(ctx, "getTaskProgress", map[string]interface{}{"task_id": id})
}

func (kc *kanboardClient) getProjectProgressHandler(ctx context.Context, request mcp.CallToolRequest) (*mcp.CallToolResult, error) {
	id, err := request.RequireInt("project_id")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	return kc.pluginResult(ctx, "getProjectProgress", map[string]interface{}{"project_id": id})
}

func (kc *kanboardClient) getMilestoneProgressHandler(ctx context.Context, request mcp.CallToolRequest) (*mcp.CallToolResult, error) {
	id, err := request.RequireInt("task_id")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	return kc.pluginResult(ctx, "getMilestoneProgress", map[string]interface{}{"task_id": id})
}

func (kc *kanboardClient) getUserXpHandler(ctx context.Context, request mcp.CallToolRequest) (*mcp.CallToolResult, error) {
	id, err := request.RequireInt("user_id")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	params := map[string]interface{}{"user_id": id}
	if p := request.GetInt("project_id", 0); p != 0 {
		params["project_id"] = p
	}
	addRange(request, params)
	return kc.pluginResult(ctx, "getUserXp", params)
}

func (kc *kanboardClient) getXpLeaderboardHandler(ctx context.Context, request mcp.CallToolRequest) (*mcp.CallToolResult, error) {
	id, err := request.RequireInt("project_id")
	if err != nil {
		return mcp.NewToolResultError(err.Error()), nil
	}
	params := map[string]interface{}{"project_id": id}
	addRange(request, params)
	return kc.pluginResult(ctx, "getXpLeaderboard", params)
}

// addRange copies optional from/to (YYYY-MM-DD) into params when both are present.
func addRange(request mcp.CallToolRequest, params map[string]interface{}) {
	from, to := request.GetString("from", ""), request.GetString("to", "")
	if from != "" && to != "" {
		params["from"], params["to"] = from, to
	}
}
```

The test's recorded `params` decode integers as `float64(7)`, matching `RequireInt` → JSON number. If `GetInt` is not the accessor name in this mcp-go version, use whatever `getSubtaskTimeSpentHandler` uses (`request.GetInt("user_id", 0)` is already in the file).

Register the tools beside the subtask time-tracking tools (after `get_subtask_time_spent`):

```go
	// TimeReport plugin (>= 1.5.0) progress and XP — read-only
	tool = mcp.NewTool("get_task_progress",
		mcp.WithDescription("TimeReport: subtask completion and time-vs-estimate meters for a task"),
		mcp.WithNumber("task_id", mcp.Required(), mcp.Description("ID of the task")),
	)
	s.AddTool(tool, kbClient.getTaskProgressHandler)

	tool = mcp.NewTool("get_project_progress",
		mcp.WithDescription("TimeReport: project % (closed/total tasks) and open milestones with progress"),
		mcp.WithNumber("project_id", mcp.Required(), mcp.Description("ID of the project")),
	)
	s.AddTool(tool, kbClient.getProjectProgressHandler)

	tool = mcp.NewTool("get_milestone_progress",
		mcp.WithDescription("TimeReport: progress of a milestone task (core 'is a milestone of' links) and its member task ids"),
		mcp.WithNumber("task_id", mcp.Required(), mcp.Description("ID of the milestone task")),
	)
	s.AddTool(tool, kbClient.getMilestoneProgressHandler)

	tool = mcp.NewTool("get_user_xp",
		mcp.WithDescription("TimeReport: a user's XP, level and per-project split; owners also get their agents' party total"),
		mcp.WithNumber("user_id", mcp.Required(), mcp.Description("ID of the user")),
		mcp.WithNumber("project_id", mcp.Description("Limit to one project (optional)")),
		mcp.WithString("from", mcp.Description("Range start YYYY-MM-DD (optional; with 'to')")),
		mcp.WithString("to", mcp.Description("Range end YYYY-MM-DD (optional; with 'from')")),
	)
	s.AddTool(tool, kbClient.getUserXpHandler)

	tool = mcp.NewTool("get_xp_leaderboard",
		mcp.WithDescription("TimeReport: XP per user in a project, agents marked with their owner"),
		mcp.WithNumber("project_id", mcp.Required(), mcp.Description("ID of the project")),
		mcp.WithString("from", mcp.Description("Range start YYYY-MM-DD (optional; with 'to')")),
		mcp.WithString("to", mcp.Description("Range end YYYY-MM-DD (optional; with 'from')")),
	)
	s.AddTool(tool, kbClient.getXpLeaderboardHandler)
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd ~/MCP/kanboard-mcp && gofmt -l . | grep -v worktrees; go vet ./... && go test ./... 2>&1 | tail -5`
Expected: no gofmt output; `ok` for the package. `TestCalledMethodsExistInKanboard` passes with the plugin list.

- [ ] **Step 6: Commit (no release build, no push)**

```bash
git -C ~/MCP/kanboard-mcp add main.go api_methods_test.go timereport_tools_test.go testdata/timereport-1.5.0-procedures.txt
git -C ~/MCP/kanboard-mcp commit -m "feat: TimeReport progress + XP tools (get_task_progress … get_xp_leaderboard)"
```

Do not rebuild the shipped binary (`kanboard-mcp-linux-amd64`) or push; ask Carmelo first.

---

### Task 13: Production switch from TaskProgressBar (human-gated)

**Files:** none in the repos. This is an operational checklist run with Carmelo **after** TimeReport 1.5.0 is released and deployed.

- [ ] **Step 1: Confirm the go-ahead.** Ask Carmelo explicitly before touching `/home/carmelo/Projects/Kanboard/Production`. Do not proceed without a yes.
- [ ] **Step 2: Deploy TimeReport 1.5.0** through the usual ModMenu or zip path, and confirm the schema migrated (`timereport_xp_cache` exists).
- [ ] **Step 3: Check a real board.** Find cards with subtasks and an estimate, subtasks only, an estimate only, and neither. Confirm one bar per card (TaskProgressBar's is hidden) and that admins see the notice.
- [ ] **Step 4: Remove TaskProgressBar** by deleting `app/plugins/TaskProgressBar`. Reload the board: core's footer renders (no override), the meters still show, and the notice is gone.
- [ ] **Step 5: Record the switch** as a comment on Kanboard #5376, with the date and what was checked. Rollback, if needed, is restoring the `app/plugins/TaskProgressBar` folder.
