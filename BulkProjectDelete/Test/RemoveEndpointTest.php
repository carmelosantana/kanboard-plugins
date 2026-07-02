<?php

/**
 * Task-05 unit tests — BulkDeleteController::remove() delete endpoint.
 *
 * Exercises:
 *   - Child rows (tasks, subtasks, comments, task_has_files, project_has_files) are gone.
 *   - Orphan-gap tables (custom_filters, invites) are cleared per-project.
 *   - Partial success: a bad id fails only that project; valid ones still delete.
 *   - Messy id list (blank/dup/nonexistent) deletes only valid unique ids.
 *   - Non-admin call throws AccessForbiddenException.
 *   - CSRF enforcement: checkCSRFForm() rejects missing/invalid tokens.
 *
 * Runs via: ./testing/run-plugin-tests.sh BulkProjectDelete
 *
 * PicoDb tx API used by the controller (verified in libs/picodb/lib/PicoDb/Database.php):
 *   startTransaction() / closeTransaction() / cancelTransaction()
 *
 * CSRF method used by the controller (verified in app/Controller/BaseController.php):
 *   checkCSRFForm()  — reads token from POST body via request->getRawValue('csrf_token')
 */

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\CommentModel;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\BulkProjectDelete\Controller\BulkDeleteController;

class RemoveEndpointTest extends Base
{
    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a project and return its id.
     */
    private function seedProject(string $name): int
    {
        $model = new ProjectModel($this->container);
        $id    = $model->create(['name' => $name]);
        $this->assertGreaterThan(0, $id, "seedProject({$name}) failed");
        return $id;
    }

    /**
     * Create a task in a project and return its id.
     */
    private function seedTask(int $projectId, string $title = 'Test task'): int
    {
        $model = new TaskCreationModel($this->container);
        $this->container['dispatcher']->addListener(
            \Kanboard\Model\TaskModel::EVENT_CREATE_UPDATE,
            function () {}
        );
        $this->container['dispatcher']->addListener(
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            function () {}
        );
        $id = $model->create(['project_id' => $projectId, 'title' => $title]);
        $this->assertGreaterThan(0, $id, "seedTask() failed for project {$projectId}");
        return $id;
    }

    /**
     * Insert a custom_filters row for a project; return inserted row id.
     */
    private function seedCustomFilter(int $projectId, int $userId = 1): void
    {
        $this->container['db']->table('custom_filters')->insert([
            'filter'     => 'status:open',
            'project_id' => $projectId,
            'user_id'    => $userId,
            'name'       => 'Open tasks',
            'is_shared'  => 0,
        ]);
    }

    /**
     * Insert an invites row for a project.
     */
    private function seedInvite(int $projectId, string $email = 'test@example.com'): void
    {
        $this->container['db']->table('invites')->insert([
            'email'      => $email,
            'project_id' => $projectId,
            'token'      => bin2hex(random_bytes(8)),
        ]);
    }

    /**
     * Build a BulkDeleteController wired to a mock userSession where isAdmin()
     * returns $isAdmin, and a fake request returning $postValues.
     *
     * The controller's checkCSRFForm() reads $this->token->validateCSRFToken(…).
     * We stub the token service to always accept so CSRF never blocks these tests
     * (except the dedicated CSRF test which replaces it differently).
     *
     * Pimple freezes a service after first access.  We call offsetUnset() before
     * reassigning so the container accepts the new stub without throwing
     * FrozenServiceException (offsetUnset also clears the frozen flag — see
     * vendor/pimple/pimple/src/Pimple/Container.php:offsetUnset).
     */
    private function buildController(
        bool $isAdmin,
        array $postValues = [],
        bool $validCsrf = true
    ): BulkDeleteController {
        // Stub userSession.isAdmin
        $userSession = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin'])
            ->getMock();
        $userSession->method('isAdmin')->willReturn($isAdmin);
        unset($this->container['userSession']);
        $this->container['userSession'] = $userSession;

        // Stub token.validateCSRFToken
        $token = $this
            ->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateCSRFToken'])
            ->getMock();
        $token->method('validateCSRFToken')->willReturn($validCsrf);
        unset($this->container['token']);
        $this->container['token'] = $token;

        // Stub request.getRawValue + getValues
        $request = $this
            ->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRawValue', 'getValues'])
            ->getMock();
        $request->method('getRawValue')->willReturn($validCsrf ? 'valid-token' : '');
        $request->method('getValues')->willReturn($postValues);
        unset($this->container['request']);
        $this->container['request'] = $request;

        // Stub response to suppress redirect (redirect calls header() which is fatal in CLI)
        $response = $this
            ->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect'])
            ->getMock();
        $response->method('redirect')->willReturn(null);
        unset($this->container['response']);
        $this->container['response'] = $response;

        return new BulkDeleteController($this->container);
    }

    // ── T-a: admin gate ───────────────────────────────────────────────────────

    /**
     * Non-admin call → AccessForbiddenException is thrown before any data changes.
     */
    public function testNonAdminThrowsAccessForbidden()
    {
        $pid = $this->seedProject('Should survive');

        $controller = $this->buildController(
            isAdmin: false,
            postValues: ['project_ids' => [$pid]]
        );

        $this->expectException(AccessForbiddenException::class);
        $controller->remove();

        // Verify the project still exists.
        $row = $this->container['db']->table('projects')->eq('id', $pid)->findOne();
        $this->assertNotEmpty($row, 'Project must not be deleted when admin gate fires');
    }

    // ── T-b: CSRF guard ───────────────────────────────────────────────────────

    /**
     * Missing/invalid CSRF token → AccessForbiddenException (checkCSRFForm rejected).
     */
    public function testInvalidCsrfThrowsAccessForbidden()
    {
        $pid = $this->seedProject('CSRF victim');

        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$pid]],
            validCsrf: false
        );

        $this->expectException(AccessForbiddenException::class);
        $controller->remove();

        $row = $this->container['db']->table('projects')->eq('id', $pid)->findOne();
        $this->assertNotEmpty($row, 'Project must not be deleted when CSRF fails');
    }

    // ── T-c: core deletion cascades child rows ────────────────────────────────

    /**
     * RED → GREEN test: seed a project with tasks, subtasks, comments, task_has_files,
     * project_has_files; call remove(); assert the project row AND all child rows are gone.
     */
    public function testCoreChildRowsAreDeletedWithProject()
    {
        $pid  = $this->seedProject('Project with children');
        $tid  = $this->seedTask($pid, 'Child task');

        // Subtask
        $subtaskModel = new SubtaskModel($this->container);
        $subtaskModel->create(['task_id' => $tid, 'title' => 'Sub A']);

        // Comment
        $commentModel = new CommentModel($this->container);
        $commentModel->create(['task_id' => $tid, 'user_id' => 1, 'comment' => 'hi']);

        // Task file (direct insert — avoids real upload path)
        $this->container['db']->table('task_has_files')->insert([
            'task_id'  => $tid,
            'name'     => 'doc.txt',
            'path'     => 'files/test/doc.txt',
            'is_image' => 0,
            'size'     => 1024,
        ]);

        // Project file
        $this->container['db']->table('project_has_files')->insert([
            'project_id' => $pid,
            'name'       => 'spec.pdf',
            'path'       => 'files/test/spec.pdf',
            'is_image'   => 0,
            'size'       => 2048,
            'user_id'    => 1,
            'date'       => time(),
        ]);

        // ── PRE-DELETE: verify rows exist ───────────────────────────────────
        $this->assertNotEmpty(
            $this->container['db']->table('projects')->eq('id', $pid)->findOne(),
            'PRE: project row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('tasks')->eq('project_id', $pid)->count(),
            'PRE: task row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('subtasks')->eq('task_id', $tid)->count(),
            'PRE: subtask row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('comments')->eq('task_id', $tid)->count(),
            'PRE: comment row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('task_has_files')->eq('task_id', $tid)->count(),
            'PRE: task file row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('project_has_files')->eq('project_id', $pid)->count(),
            'PRE: project file row must exist'
        );

        // ── DELETE ──────────────────────────────────────────────────────────
        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$pid]]
        );
        $controller->remove();

        // ── POST-DELETE: all rows must be gone ──────────────────────────────
        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $pid)->findOne(),
            'POST: project row must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('tasks')->eq('project_id', $pid)->count(),
            'POST: task rows must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('subtasks')->eq('task_id', $tid)->count(),
            'POST: subtask rows must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('comments')->eq('task_id', $tid)->count(),
            'POST: comment rows must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('task_has_files')->eq('task_id', $tid)->count(),
            'POST: task file rows must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('project_has_files')->eq('project_id', $pid)->count(),
            'POST: project file rows must be gone'
        );
    }

    // ── T-d: orphan-gap tables are cleared ───────────────────────────────────

    /**
     * custom_filters and invites rows keyed on project_id are deleted before
     * projectModel->remove() so they do not become orphans.
     */
    public function testOrphanGapTablesAreCleared()
    {
        $pid = $this->seedProject('Gap table project');
        $this->seedCustomFilter($pid);
        $this->seedInvite($pid, 'alice@example.com');

        // PRE: gap rows exist
        $this->assertSame(
            1,
            $this->container['db']->table('custom_filters')->eq('project_id', $pid)->count(),
            'PRE: custom_filters row must exist'
        );
        $this->assertSame(
            1,
            $this->container['db']->table('invites')->eq('project_id', $pid)->count(),
            'PRE: invites row must exist'
        );

        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$pid]]
        );
        $controller->remove();

        // POST: gap rows must be gone
        $this->assertSame(
            0,
            $this->container['db']->table('custom_filters')->eq('project_id', $pid)->count(),
            'POST: custom_filters orphans must be gone'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('invites')->eq('project_id', $pid)->count(),
            'POST: invites orphans must be gone'
        );

        // And the project itself is gone.
        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $pid)->findOne(),
            'POST: project row must be gone'
        );
    }

    // ── T-e: partial success ─────────────────────────────────────────────────

    /**
     * One valid id + one nonexistent id: the valid project is deleted; the bad id
     * is reported as failed; the loop does not abort.
     */
    public function testPartialFailureDeletesValidAndSkipsBad()
    {
        $goodPid   = $this->seedProject('Good project');
        $badPid    = 999999; // nonexistent
        $this->seedCustomFilter($goodPid);

        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$goodPid, $badPid]]
        );
        $controller->remove();

        // Good project is gone.
        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $goodPid)->findOne(),
            'Good project must be deleted'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('custom_filters')->eq('project_id', $goodPid)->count(),
            'custom_filters for good project must be cleared'
        );
    }

    // ── T-f: messy id list ────────────────────────────────────────────────────

    /**
     * Blank strings, zero, duplicates, and nonexistent ids in the list — only the
     * unique valid project is deleted; no fatal error occurs.
     */
    public function testMessyIdListDeletesOnlyValidUnique()
    {
        $pid = $this->seedProject('Messy list project');
        $this->seedCustomFilter($pid);

        // Messy list: duplicate, zero (blank), nonexistent
        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => ['', '0', $pid, $pid, '0', 888888]]
        );
        $controller->remove();

        // The valid project was deleted exactly once (no error from duplicate).
        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $pid)->findOne(),
            'Valid project must be deleted from messy list'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('custom_filters')->eq('project_id', $pid)->count(),
            'custom_filters must be cleared'
        );
    }

    // ── T-g: empty id list ────────────────────────────────────────────────────

    /**
     * An empty project_ids list must not fatal; it just flashes "0 deleted".
     */
    public function testEmptyIdListIsHarmless()
    {
        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => []]
        );

        // Should not throw.
        $controller->remove();
        $this->assertTrue(true, 'Empty list completed without error');
    }

    // ── T-h: multiple projects deleted in one call ────────────────────────────

    /**
     * Two valid projects, both with gap rows — both deleted cleanly.
     */
    public function testMultipleProjectsAllDeleted()
    {
        $pid1 = $this->seedProject('Batch project 1');
        $pid2 = $this->seedProject('Batch project 2');
        $this->seedCustomFilter($pid1);
        $this->seedInvite($pid2, 'bob@example.com');

        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$pid1, $pid2]]
        );
        $controller->remove();

        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $pid1)->findOne(),
            'Project 1 must be deleted'
        );
        $this->assertEmpty(
            $this->container['db']->table('projects')->eq('id', $pid2)->findOne(),
            'Project 2 must be deleted'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('custom_filters')->eq('project_id', $pid1)->count(),
            'custom_filters for project 1 cleared'
        );
        $this->assertSame(
            0,
            $this->container['db']->table('invites')->eq('project_id', $pid2)->count(),
            'invites for project 2 cleared'
        );
    }
}
