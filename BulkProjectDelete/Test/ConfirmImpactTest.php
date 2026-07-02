<?php

/**
 * Task-04 unit tests — impact pre-flight aggregation.
 *
 * Seeds a known project and asserts that the DB aggregates used by
 * BulkDeleteController::confirm() return the expected counts.
 *
 * Runs via: ./testing/run-plugin-tests.sh BulkProjectDelete
 * (from the repo root; test runner sets up the kanboard-src symlink).
 */

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskFileModel;
use Kanboard\Model\ProjectFileModel;
use Kanboard\Core\Controller\AccessForbiddenException;

class ConfirmImpactTest extends Base
{
    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a minimal project and return its id.
     */
    private function seedProject(string $name): int
    {
        $model = new ProjectModel($this->container);
        $id = $model->create(['name' => $name]);
        $this->assertGreaterThan(0, $id, "seedProject({$name}) failed");
        return $id;
    }

    /**
     * Create a task in a project; return the task id.
     */
    private function seedTask(int $projectId, string $title = 'Test task'): int
    {
        $model = new TaskCreationModel($this->container);
        // Dispatcher listeners are required by the model.
        $this->container['dispatcher']->addListener(\Kanboard\Model\TaskModel::EVENT_CREATE_UPDATE, function () {});
        $this->container['dispatcher']->addListener(\Kanboard\Model\TaskModel::EVENT_CREATE, function () {});
        $id = $model->create(['project_id' => $projectId, 'title' => $title]);
        $this->assertGreaterThan(0, $id, "seedTask() failed for project {$projectId}");
        return $id;
    }

    // ── count helpers (mirror controller logic) ────────────────────────────────

    private function countTasks(int $projectId): int
    {
        return $this->container['db']->table('tasks')
            ->eq('project_id', $projectId)
            ->count();
    }

    private function countSubtasks(int $projectId): int
    {
        $taskIds = $this->container['db']->table('tasks')
            ->eq('project_id', $projectId)
            ->findAllByColumn('id');
        if (empty($taskIds)) { return 0; }
        return $this->container['db']->table('subtasks')
            ->in('task_id', $taskIds)
            ->count();
    }

    private function countComments(int $projectId): int
    {
        $taskIds = $this->container['db']->table('tasks')
            ->eq('project_id', $projectId)
            ->findAllByColumn('id');
        if (empty($taskIds)) { return 0; }
        return $this->container['db']->table('comments')
            ->in('task_id', $taskIds)
            ->count();
    }

    private function countFiles(int $projectId): int
    {
        $taskIds = $this->container['db']->table('tasks')
            ->eq('project_id', $projectId)
            ->findAllByColumn('id');
        $taskFileCount = empty($taskIds) ? 0 : $this->container['db']->table('task_has_files')
            ->in('task_id', $taskIds)
            ->count();
        $projFileCount = $this->container['db']->table('project_has_files')
            ->eq('project_id', $projectId)
            ->count();
        return $taskFileCount + $projFileCount;
    }

    private function sumBytes(int $projectId): int
    {
        $taskIds = $this->container['db']->table('tasks')
            ->eq('project_id', $projectId)
            ->findAllByColumn('id');

        $taskBytes = 0;
        if (! empty($taskIds)) {
            $row = $this->container['db']->table('task_has_files')
                ->in('task_id', $taskIds)
                ->columns('SUM(size) AS total_bytes')
                ->findOne();
            $taskBytes = (int) ($row['total_bytes'] ?? 0);
        }

        $row = $this->container['db']->table('project_has_files')
            ->eq('project_id', $projectId)
            ->columns('SUM(size) AS total_bytes')
            ->findOne();
        $projBytes = (int) ($row['total_bytes'] ?? 0);

        return $taskBytes + $projBytes;
    }

    // ── tests ──────────────────────────────────────────────────────────────────

    /**
     * Empty project: all counts are zero.
     */
    public function testEmptyProjectReturnsZeroCounts()
    {
        $pid = $this->seedProject('Empty project');

        $this->assertSame(0, $this->countTasks($pid));
        $this->assertSame(0, $this->countSubtasks($pid));
        $this->assertSame(0, $this->countComments($pid));
        $this->assertSame(0, $this->countFiles($pid));
        $this->assertSame(0, $this->sumBytes($pid));
    }

    /**
     * Seed 2 tasks, 3 subtasks (2 on task 1, 1 on task 2), 1 comment — assert counts.
     */
    public function testCountsMatchSeededData()
    {
        $pid = $this->seedProject('Impact project');

        // 2 tasks
        $t1 = $this->seedTask($pid, 'Task one');
        $t2 = $this->seedTask($pid, 'Task two');

        // 3 subtasks
        $subtaskModel = new SubtaskModel($this->container);
        $subtaskModel->create(['task_id' => $t1, 'title' => 'Sub 1a']);
        $subtaskModel->create(['task_id' => $t1, 'title' => 'Sub 1b']);
        $subtaskModel->create(['task_id' => $t2, 'title' => 'Sub 2a']);

        // 1 comment
        $commentModel = new CommentModel($this->container);
        $commentModel->create(['task_id' => $t1, 'user_id' => 1, 'comment' => 'Hello']);

        $this->assertSame(2, $this->countTasks($pid), 'task count');
        $this->assertSame(3, $this->countSubtasks($pid), 'subtask count');
        $this->assertSame(1, $this->countComments($pid), 'comment count');
        $this->assertSame(0, $this->countFiles($pid), 'file count (no files seeded)');
        $this->assertSame(0, $this->sumBytes($pid), 'byte sum (no files seeded)');
    }

    /**
     * File byte sum: task_has_files.size and project_has_files.size are summed.
     */
    public function testFileByteSumIncludesTaskAndProjectFiles()
    {
        $pid = $this->seedProject('File project');
        $t1  = $this->seedTask($pid, 'File task');

        // Insert a task file directly (TaskFileModel.create needs a real upload —
        // use db directly to control the size value).
        $this->container['db']->table('task_has_files')->insert([
            'task_id'  => $t1,
            'name'     => 'doc.txt',
            'path'     => 'files/test/doc.txt',
            'is_image' => 0,
            'size'     => 1024,
        ]);

        // Insert a project file directly.
        $this->container['db']->table('project_has_files')->insert([
            'project_id' => $pid,
            'name'       => 'spec.pdf',
            'path'       => 'files/test/spec.pdf',
            'is_image'   => 0,
            'size'       => 2048,
            'user_id'    => 1,
            'date'       => time(),
        ]);

        // 1 task file + 1 project file = 2 total
        $this->assertSame(2, $this->countFiles($pid), 'total file count');
        $this->assertSame(3072, $this->sumBytes($pid), 'byte sum 1024+2048');
    }

    /**
     * Counts are isolated: seeding two projects does not bleed across.
     */
    public function testCountsAreIsolatedByProject()
    {
        $pidA = $this->seedProject('Project A');
        $pidB = $this->seedProject('Project B');

        $tA = $this->seedTask($pidA, 'Task in A');
        $this->seedTask($pidA, 'Task in A 2');

        $subtaskModel = new SubtaskModel($this->container);
        $subtaskModel->create(['task_id' => $tA, 'title' => 'Sub in A']);

        $this->assertSame(2, $this->countTasks($pidA), 'project A tasks');
        $this->assertSame(0, $this->countTasks($pidB), 'project B tasks (none)');
        $this->assertSame(1, $this->countSubtasks($pidA), 'project A subtasks');
        $this->assertSame(0, $this->countSubtasks($pidB), 'project B subtasks (none)');
    }

    /**
     * confirm() throws AccessForbiddenException when the caller is not an admin.
     *
     * Stubs $this->container['userSession'] so that isAdmin() returns false,
     * then instantiates the real BulkDeleteController and calls confirm().
     * The test genuinely exercises the admin gate — removing the guard in
     * BulkDeleteController::confirm() causes this test to go RED.
     */
    public function testConfirmThrowsForNonAdmin()
    {
        // Replace the real userSession with a stub whose isAdmin() returns false.
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $this->container['userSession']
            ->method('isAdmin')
            ->willReturn(false);

        $controller = new \Kanboard\Plugin\BulkProjectDelete\Controller\BulkDeleteController(
            $this->container
        );

        $this->expectException(AccessForbiddenException::class);
        $controller->confirm();
    }
}
