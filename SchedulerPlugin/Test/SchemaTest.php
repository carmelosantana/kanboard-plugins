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
