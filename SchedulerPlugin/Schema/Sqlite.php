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
