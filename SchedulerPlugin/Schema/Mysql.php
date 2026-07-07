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
