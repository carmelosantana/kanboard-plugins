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
