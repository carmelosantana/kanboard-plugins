<?php

namespace Kanboard\Plugin\DependencyPlugin\Model;

use Kanboard\Core\Base;
use Kanboard\Model\TaskLinkModel;
use Kanboard\Model\TaskModel;

/**
 * Dependency model
 *
 * Computes, per project, each task's blocked status derived from core
 * task links ("is blocked by" / "blocks") without an N+1 query storm.
 *
 * @package Kanboard\Plugin\DependencyPlugin\Model
 */
class DependencyModel extends Base
{
    /**
     * Core seeded link ids (fallback reference only — always resolved by label at runtime).
     *
     * @var int
     */
    const LINK_IS_BLOCKED_BY = 2;
    const LINK_BLOCKS = 3;

    /**
     * Per-project memoization cache.
     *
     * @var array
     */
    private $cache = array();

    /**
     * Build the blocked/blocks map for a project.
     *
     * map[taskId] = ['open_blockers' => int, 'blocks' => int]
     *
     * Only tasks with a non-zero open_blockers or blocks count are present;
     * callers must treat a missing entry as zeros.
     *
     * @access public
     * @param  int $projectId
     * @return array
     */
    public function getProjectBlockedMap($projectId)
    {
        $projectId = (int) $projectId;

        if (array_key_exists($projectId, $this->cache)) {
            return $this->cache[$projectId];
        }

        $map = array();

        $blockedByLink = $this->linkModel->getByLabel('is blocked by');
        $blocksLink = $this->linkModel->getByLabel('blocks');

        if (empty($blockedByLink) || empty($blocksLink)) {
            $this->cache[$projectId] = array();
            return $this->cache[$projectId];
        }

        $blockedById = $blockedByLink['id'];
        $blocksId = $blocksLink['id'];

        $this->applyOpenBlockers($map, $projectId, $blockedById);
        $this->applyBlocks($map, $projectId, $blocksId);

        $this->cache[$projectId] = $map;

        return $map;
    }

    /**
     * Populate open_blockers counts: for each subject task T in the project that has
     * an "is blocked by" link to an opposite task, count how many of those opposite
     * tasks are still open.
     *
     * @access private
     * @param  array $map
     * @param  int   $projectId
     * @param  int   $blockedById
     * @return void
     */
    private function applyOpenBlockers(array &$map, $projectId, $blockedById)
    {
        // Query A: all "is blocked by" link rows whose subject task belongs to the project.
        $rows = $this->db->table(TaskLinkModel::TABLE)
            ->columns(
                TaskLinkModel::TABLE.'.task_id',
                TaskLinkModel::TABLE.'.opposite_task_id'
            )
            ->join(TaskModel::TABLE, 'id', 'task_id', TaskLinkModel::TABLE)
            ->eq(TaskLinkModel::TABLE.'.link_id', $blockedById)
            ->eq(TaskModel::TABLE.'.project_id', $projectId)
            ->findAll();

        if (empty($rows)) {
            return;
        }

        $oppositeIds = array();
        foreach ($rows as $row) {
            $oppositeIds[$row['opposite_task_id']] = true;
        }
        $oppositeIds = array_keys($oppositeIds);

        $openIds = array();
        if (! empty($oppositeIds)) {
            $openIds = $this->db->table(TaskModel::TABLE)
                ->in('id', $oppositeIds)
                ->eq('is_active', TaskModel::STATUS_OPEN)
                ->findAllByColumn('id');
        }
        $openIds = ! empty($openIds) ? array_flip($openIds) : array();

        foreach ($rows as $row) {
            if (isset($openIds[$row['opposite_task_id']])) {
                $taskId = $row['task_id'];
                if (! isset($map[$taskId])) {
                    $map[$taskId] = array('open_blockers' => 0, 'blocks' => 0);
                }
                $map[$taskId]['open_blockers']++;
            }
        }
    }

    /**
     * Populate blocks counts: for each subject task T in the project that has a
     * "blocks" link, count how many tasks it blocks.
     *
     * @access private
     * @param  array $map
     * @param  int   $projectId
     * @param  int   $blocksId
     * @return void
     */
    private function applyBlocks(array &$map, $projectId, $blocksId)
    {
        $rows = $this->db->table(TaskLinkModel::TABLE)
            ->columns(
                TaskLinkModel::TABLE.'.task_id',
                'COUNT(*) AS c'
            )
            ->join(TaskModel::TABLE, 'id', 'task_id', TaskLinkModel::TABLE)
            ->eq(TaskLinkModel::TABLE.'.link_id', $blocksId)
            ->eq(TaskModel::TABLE.'.project_id', $projectId)
            ->groupBy(TaskLinkModel::TABLE.'.task_id')
            ->findAll();

        foreach ($rows as $row) {
            $taskId = $row['task_id'];
            if (! isset($map[$taskId])) {
                $map[$taskId] = array('open_blockers' => 0, 'blocks' => 0);
            }
            $map[$taskId]['blocks'] = (int) $row['c'];
        }
    }
}
