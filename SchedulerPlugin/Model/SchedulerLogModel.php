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
