<?php

namespace Kanboard\Plugin\BulkProjectDelete\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

class BulkDeleteController extends BaseController
{
    /**
     * GET/POST — show a confirmation page listing the selected projects and their impact.
     *
     * Read-only: computes counts of tasks, subtasks, comments, and files per project.
     * No data is modified here. Deletion is task-05 (remove()).
     *
     * Schema verified against kanboard-1.2.47/app/Schema/Sqlite.php:
     *   tasks           — project_id (direct; version_1 CREATE)
     *   subtasks        — task_id (renamed from task_has_subtasks in version_49)
     *   comments        — task_id
     *   task_has_files  — task_id, size (size added as ALTER TABLE in version_61)
     *   project_has_files — project_id, size (native; created in version_98)
     */
    public function confirm()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        // Accept project_ids from POST body or GET query string.
        //
        // confirm() is read-only — no mutation. The initial POST from the JS selection
        // form (task-03) does NOT carry a CSRF token (that token lives in the confirm
        // template for task-05's destructive action). We therefore read the raw POST
        // values here rather than getValues(), which requires a valid CSRF token.
        $post   = $this->request->getRawFormValues();
        $get    = isset($_GET['project_ids']) ? (array) $_GET['project_ids'] : [];
        $rawIds = isset($post['project_ids']) ? (array) $post['project_ids'] : $get;

        // Cast to int, drop zeros/dupes.
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));

        $rows       = [];
        $totals     = [
            'tasks'    => 0,
            'subtasks' => 0,
            'comments' => 0,
            'files'    => 0,
            'bytes'    => 0,
        ];

        foreach ($projectIds as $id) {
            $project = $this->projectModel->getById($id);
            if (! $project) {
                continue; // skip ids that no longer exist
            }

            // Count tasks in this project.
            $taskCount = $this->db->table('tasks')
                ->eq('project_id', $id)
                ->count();

            // Count subtasks through tasks.
            $subtaskCount = $this->db->table('subtasks')
                ->in('task_id', $this->db->table('tasks')
                    ->eq('project_id', $id)
                    ->findAllByColumn('id'))
                ->count();

            // Count comments through tasks.
            $commentCount = $this->db->table('comments')
                ->in('task_id', $this->db->table('tasks')
                    ->eq('project_id', $id)
                    ->findAllByColumn('id'))
                ->count();

            // Count task files and sum their sizes.
            $taskIds = $this->db->table('tasks')
                ->eq('project_id', $id)
                ->findAllByColumn('id');

            $taskFileCount = 0;
            $taskFileBytes = 0;
            if (! empty($taskIds)) {
                $taskFileCount = $this->db->table('task_has_files')
                    ->in('task_id', $taskIds)
                    ->count();

                $taskFileSizeRow = $this->db->table('task_has_files')
                    ->in('task_id', $taskIds)
                    ->columns('SUM(size) AS total_bytes')
                    ->findOne();
                $taskFileBytes = isset($taskFileSizeRow['total_bytes'])
                    ? (int) $taskFileSizeRow['total_bytes']
                    : 0;
            }

            // Count project-level files and sum their sizes.
            $projFileCount = $this->db->table('project_has_files')
                ->eq('project_id', $id)
                ->count();

            $projFileSizeRow = $this->db->table('project_has_files')
                ->eq('project_id', $id)
                ->columns('SUM(size) AS total_bytes')
                ->findOne();
            $projFileBytes = isset($projFileSizeRow['total_bytes'])
                ? (int) $projFileSizeRow['total_bytes']
                : 0;

            $fileCount = $taskFileCount + $projFileCount;
            $fileBytes = $taskFileBytes + $projFileBytes;

            $rows[] = [
                'id'       => $id,
                'name'     => $project['name'],
                'tasks'    => $taskCount,
                'subtasks' => $subtaskCount,
                'comments' => $commentCount,
                'files'    => $fileCount,
                'bytes'    => $fileBytes,
            ];

            $totals['tasks']    += $taskCount;
            $totals['subtasks'] += $subtaskCount;
            $totals['comments'] += $commentCount;
            $totals['files']    += $fileCount;
            $totals['bytes']    += $fileBytes;
        }

        $this->response->html(
            $this->template->render('BulkProjectDelete:remove/confirm', [
                'rows'    => $rows,
                'totals'  => $totals,
            ])
        );
    }

    /**
     * POST — perform the bulk delete.
     * (stub: empty response until task-05 implements the delete endpoint)
     */
    public function remove()
    {
        $this->response->html('');
    }
}
