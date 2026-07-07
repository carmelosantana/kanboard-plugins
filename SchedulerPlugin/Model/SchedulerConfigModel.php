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
