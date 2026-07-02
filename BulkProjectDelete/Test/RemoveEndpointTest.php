<?php

/**
 * Task-05 + Task-07 unit tests — BulkDeleteController::remove() delete endpoint.
 *
 * Exercises:
 *   - Child rows (tasks, subtasks, comments, task_has_files, project_has_files) are gone.
 *   - Orphan-gap tables (custom_filters, invites) are cleared per-project.
 *   - Partial success: a bad id fails only that project; valid ones still delete.
 *   - Messy id list (blank/dup/nonexistent) deletes only valid unique ids.
 *   - Non-admin call throws AccessForbiddenException.
 *   - CSRF enforcement: checkCSRFForm() rejects missing/invalid tokens.
 *   - Task-07 (G7 ZERO-ORPHANS):
 *       - Exhaustive coverage of every v1.2.47 table that has a project_id / task_id FK.
 *       - On-disk file removal via a real FileStorage (tmpdir-backed).
 *       - Dedup semantics: shared path not unlinked while a second row still references it.
 *
 * Skipped tables (not present in Kanboard v1.2.47 SQLite final schema):
 *   - project_integrations: created in migration v64, DROPPED in migration v88 (never
 *     re-added in this version).
 *
 * Dedup semantics (verified against FileModel::remove()):
 *   Kanboard's FileModel::remove() checks COUNT(*) WHERE path = <path> in the SAME table
 *   before calling objectStorage->remove().  If two rows share the identical path string,
 *   the physical file is NOT removed when the first row is deleted — only when the last
 *   row referencing that path is gone.  generatePath() uses sha1(filename.time()) so
 *   two uploads of the same filename at different seconds get different paths; true dedup
 *   only occurs when rows are inserted with the same path value directly (or at the exact
 *   same second).
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
use Kanboard\Core\ObjectStorage\FileStorage;
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

        $threw = false;
        try {
            $controller->remove();
        } catch (AccessForbiddenException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected AccessForbiddenException');

        // Verify the project still exists (survival assertion — actually executes now).
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

        $threw = false;
        try {
            $controller->remove();
        } catch (AccessForbiddenException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected AccessForbiddenException');

        // Verify the project still exists (survival assertion — actually executes now).
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

    // ── T-07-a: exhaustive table coverage (G7 ZERO-ORPHANS) ──────────────────

    /**
     * Task-07 T-a: seed a fully-populated project with rows in every FK-cascade table
     * and both orphan-gap tables; after bulk delete assert every table returns 0 rows
     * for that project_id / its task_ids.
     *
     * Tables asserted (verified against Sqlite.php v1.2.47 final schema):
     *
     *   FK cascade via project_id:
     *     columns, swimlanes, project_has_categories, project_has_files,
     *     project_has_users, project_has_groups, project_has_roles,
     *     project_has_metadata, project_has_notification_types, actions,
     *     action_has_params (via actions), project_daily_stats,
     *     project_daily_column_stats, project_activities, predefined_task_descriptions,
     *     column_has_restrictions, project_role_has_restrictions,
     *     column_has_move_restrictions, user_has_notifications, tags, tasks
     *
     *   FK cascade via task_id (task rows are deleted above via tasks cascade):
     *     subtasks, comments, task_has_files, task_has_metadata, task_has_tags,
     *     task_has_links, task_has_external_links, transitions
     *
     *   FK cascade via subtask_id:
     *     subtask_time_tracking
     *
     *   Explicit plugin cleanup (no FK cascade):
     *     custom_filters, invites
     *
     * Skipped (not in v1.2.47 final schema):
     *   project_integrations — created in v64, DROPPED in v88, never re-added.
     */
    public function testExhaustiveTableCoverageAfterDelete()
    {
        $db  = $this->container['db'];

        // ── Seed the project ─────────────────────────────────────────────────
        $pid = $this->seedProject('Exhaustive project');
        $tid = $this->seedTask($pid, 'Exhaustive task');

        // Subtask
        $subtaskModel = new SubtaskModel($this->container);
        $sid = $subtaskModel->create(['task_id' => $tid, 'title' => 'Sub A']);
        $this->assertGreaterThan(0, $sid);

        // subtask_time_tracking (FK → subtasks.id ON DELETE CASCADE)
        $db->table('subtask_time_tracking')->insert([
            'user_id'    => 1,
            'subtask_id' => $sid,
            'start'      => time(),
            'end'        => 0,
            'time_spent' => 0.0,
        ]);

        // Comment (FK → tasks.id)
        $commentModel = new CommentModel($this->container);
        $commentModel->create(['task_id' => $tid, 'user_id' => 1, 'comment' => 'hi']);

        // task_has_files
        $db->table('task_has_files')->insert([
            'task_id'  => $tid, 'name' => 'doc.txt',
            'path'     => 'tasks/'.$tid.'/exhaust-doc', 'is_image' => 0, 'size' => 512,
        ]);

        // task_has_metadata
        $db->table('task_has_metadata')->insert(['task_id' => $tid, 'name' => 'key1', 'value' => 'v1']);

        // tags + task_has_tags
        $db->table('tags')->insert(['name' => 'exhaust-tag', 'project_id' => $pid]);
        $tagId = $db->getLastId();
        $db->table('task_has_tags')->insert(['task_id' => $tid, 'tag_id' => $tagId]);

        // task_has_links (self-referential; need a second task)
        $tid2 = $this->seedTask($pid, 'Link target');
        // link_id=1 always exists ("relates to") in the default fixture
        $db->table('task_has_links')->insert([
            'link_id' => 1, 'task_id' => $tid, 'opposite_task_id' => $tid2,
        ]);

        // task_has_external_links
        $db->table('task_has_external_links')->insert([
            'link_type'         => 'weblink',
            'dependency'        => 'related',
            'title'             => 'Kanboard',
            'url'               => 'https://kanboard.org',
            'date_creation'     => time(),
            'date_modification' => time(),
            'task_id'           => $tid,
            'creator_id'        => 1,
        ]);

        // project_has_files
        $db->table('project_has_files')->insert([
            'project_id' => $pid, 'name' => 'brief.pdf',
            'path' => 'projects/'.$pid.'/brief', 'is_image' => 0,
            'size' => 1024, 'user_id' => 1, 'date' => time(),
        ]);

        // project_has_metadata
        $db->table('project_has_metadata')->insert(['project_id' => $pid, 'name' => 'pkey', 'value' => 'pval']);

        // project_has_notification_types
        $db->table('project_has_notification_types')->insert([
            'project_id' => $pid, 'notification_type' => 'email',
        ]);

        // predefined_task_descriptions
        $db->table('predefined_task_descriptions')->insert([
            'project_id' => $pid, 'title' => 'Tpl', 'description' => 'desc',
        ]);

        // project_daily_stats
        $db->table('project_daily_stats')->insert([
            'day' => '2024-01-01', 'project_id' => $pid,
            'avg_lead_time' => 0, 'avg_cycle_time' => 0,
        ]);

        // project_daily_column_stats — need a column_id
        $colId = $db->table('columns')->eq('project_id', $pid)->findOne()['id'];
        $this->assertNotNull($colId, 'PRE: must have a column');
        // Use insertIgnore-style: project_daily_column_stats has a unique index on (day, project_id, column_id)
        $db->table('project_daily_column_stats')->insert([
            'day' => '2024-01-01', 'project_id' => $pid,
            'column_id' => $colId, 'total' => 0, 'score' => 0,
        ]);

        // transitions (FK → project_id, task_id, src_column_id, dst_column_id)
        $db->table('transitions')->insert([
            'user_id'       => 1,
            'project_id'    => $pid,
            'task_id'       => $tid,
            'src_column_id' => $colId,
            'dst_column_id' => $colId,
            'date'          => time(),
            'time_spent'    => 0,
        ]);

        // project_activities (FK → project_id, task_id)
        $db->table('project_activities')->insert([
            'date_creation' => time(),
            'event_name'    => 'task.create',
            'creator_id'    => 1,
            'project_id'    => $pid,
            'task_id'       => $tid,
            'data'          => '{}',
        ]);

        // actions + action_has_params (actions FK → project_id; params FK → action_id)
        $db->table('actions')->insert([
            'project_id'  => $pid,
            'event_name'  => 'task.create',
            'action_name' => '\\Kanboard\\Action\\TaskAssignCurrentUser',
        ]);
        $actionId = $db->getLastId();
        $db->table('action_has_params')->insert([
            'action_id' => $actionId, 'name' => 'column_id', 'value' => $colId,
        ]);

        // project_has_roles
        $db->table('project_has_roles')->insert([
            'role' => 'test-role', 'project_id' => $pid,
        ]);
        $roleId = $db->getLastId();

        // column_has_restrictions (FK → project_id, role_id, column_id)
        $db->table('column_has_restrictions')->insert([
            'project_id' => $pid,
            'role_id'    => $roleId,
            'column_id'  => $colId,
            'rule'       => 'task_creation',
        ]);

        // project_role_has_restrictions (FK → project_id, role_id)
        $db->table('project_role_has_restrictions')->insert([
            'project_id' => $pid,
            'role_id'    => $roleId,
            'rule'       => 'task_suppression',
        ]);

        // column_has_move_restrictions (FK → project_id, role_id, src/dst column_id)
        $db->table('column_has_move_restrictions')->insert([
            'project_id'    => $pid,
            'role_id'       => $roleId,
            'src_column_id' => $colId,
            'dst_column_id' => $colId,
        ]);

        // user_has_notifications (FK → project_id, user_id) — user 1 watches this project
        $db->table('user_has_notifications')->insert([
            'user_id' => 1, 'project_id' => $pid,
        ]);

        // project_has_users (FK → project_id, user_id)
        $db->table('project_has_users')->insert([
            'project_id' => $pid, 'user_id' => 1, 'role' => 'project-manager',
        ]);

        // project_has_groups (FK → project_id, group_id) — need a group
        $db->table('groups')->insert(['external_id' => '', 'name' => 'exhaust-grp-'.$pid]);
        $groupId = $db->getLastId();
        $db->table('project_has_groups')->insert([
            'group_id' => $groupId, 'project_id' => $pid, 'role' => 'project-member',
        ]);

        // project_has_categories (FK → project_id ON DELETE CASCADE)
        // Seeded explicitly here so the POST assertion is load-bearing (not vacuous 0=0).
        // Default categories are empty; we insert one directly to guarantee PRE count > 0.
        $db->table('project_has_categories')->insert([
            'name'       => 'exhaust-category',
            'project_id' => $pid,
            'color_id'   => 'yellow',
        ]);

        // custom_filters (no FK cascade — must be explicitly cleaned by plugin)
        $this->seedCustomFilter($pid);

        // invites (no FK cascade — must be explicitly cleaned by plugin)
        $this->seedInvite($pid, 'exhaust@example.com');

        // ── PRE-DELETE: spot-check a few key tables exist ────────────────────
        $taskIds = $db->table('tasks')->eq('project_id', $pid)->findAllByColumn('id');
        $this->assertNotEmpty($taskIds, 'PRE: tasks must exist');

        $subtaskIds = $db->table('subtasks')->in('task_id', $taskIds)->findAllByColumn('id');
        $this->assertNotEmpty($subtaskIds, 'PRE: subtasks must exist');

        $this->assertSame(1, $db->table('subtask_time_tracking')
            ->in('subtask_id', $subtaskIds)->count(), 'PRE: subtask_time_tracking row must exist');

        // project_has_categories PRE assertion — must be 1 (load-bearing; not vacuous)
        $this->assertSame(1, $db->table('project_has_categories')
            ->eq('project_id', $pid)->count(), 'PRE: project_has_categories row must exist');

        $this->assertSame(1, $db->table('custom_filters')
            ->eq('project_id', $pid)->count(), 'PRE: custom_filters must exist');

        $this->assertSame(1, $db->table('invites')
            ->eq('project_id', $pid)->count(), 'PRE: invites must exist');

        // ── DELETE ────────────────────────────────────────────────────────────
        $controller = $this->buildController(
            isAdmin: true,
            postValues: ['project_ids' => [$pid]]
        );
        $controller->remove();

        // ── POST-DELETE: assert EVERY table empty ─────────────────────────────

        // project row itself
        $this->assertEmpty($db->table('projects')->eq('id', $pid)->findOne(),
            'POST: projects row must be gone');

        // ── Project-id–keyed cascade tables ──────────────────────────────────
        // columns: seeded automatically by ProjectModel::create()
        $this->assertSame(0, $db->table('columns')->eq('project_id', $pid)->count(),
            'POST: columns must be gone');

        $this->assertSame(0, $db->table('swimlanes')->eq('project_id', $pid)->count(),
            'POST: swimlanes must be gone');

        $this->assertSame(0, $db->table('project_has_categories')->eq('project_id', $pid)->count(),
            'POST: project_has_categories must be gone');

        $this->assertSame(0, $db->table('project_has_files')->eq('project_id', $pid)->count(),
            'POST: project_has_files must be gone');

        $this->assertSame(0, $db->table('project_has_users')->eq('project_id', $pid)->count(),
            'POST: project_has_users must be gone');

        $this->assertSame(0, $db->table('project_has_groups')->eq('project_id', $pid)->count(),
            'POST: project_has_groups must be gone');

        $this->assertSame(0, $db->table('project_has_roles')->eq('project_id', $pid)->count(),
            'POST: project_has_roles must be gone');

        $this->assertSame(0, $db->table('project_has_metadata')->eq('project_id', $pid)->count(),
            'POST: project_has_metadata must be gone');

        $this->assertSame(0, $db->table('project_has_notification_types')->eq('project_id', $pid)->count(),
            'POST: project_has_notification_types must be gone');

        $this->assertSame(0, $db->table('actions')->eq('project_id', $pid)->count(),
            'POST: actions must be gone');

        // action_has_params cascades from actions; if actions gone, params gone
        $this->assertSame(0, $db->table('action_has_params')->eq('id', $actionId)->count(),
            'POST: action_has_params must be gone');

        $this->assertSame(0, $db->table('project_daily_stats')->eq('project_id', $pid)->count(),
            'POST: project_daily_stats must be gone');

        $this->assertSame(0, $db->table('project_daily_column_stats')->eq('project_id', $pid)->count(),
            'POST: project_daily_column_stats must be gone');

        $this->assertSame(0, $db->table('project_activities')->eq('project_id', $pid)->count(),
            'POST: project_activities must be gone');

        $this->assertSame(0, $db->table('predefined_task_descriptions')->eq('project_id', $pid)->count(),
            'POST: predefined_task_descriptions must be gone');

        $this->assertSame(0, $db->table('column_has_restrictions')->eq('project_id', $pid)->count(),
            'POST: column_has_restrictions must be gone');

        $this->assertSame(0, $db->table('project_role_has_restrictions')->eq('project_id', $pid)->count(),
            'POST: project_role_has_restrictions must be gone');

        $this->assertSame(0, $db->table('column_has_move_restrictions')->eq('project_id', $pid)->count(),
            'POST: column_has_move_restrictions must be gone');

        $this->assertSame(0, $db->table('user_has_notifications')->eq('project_id', $pid)->count(),
            'POST: user_has_notifications must be gone');

        $this->assertSame(0, $db->table('tags')->eq('project_id', $pid)->count(),
            'POST: tags must be gone');

        // ── Task-id–keyed cascade tables (tasks cascade from project) ─────────
        $this->assertSame(0, $db->table('tasks')->eq('project_id', $pid)->count(),
            'POST: tasks must be gone');

        $this->assertSame(0, $db->table('subtasks')->in('task_id', [$tid, $tid2])->count(),
            'POST: subtasks must be gone');

        $this->assertSame(0, $db->table('subtask_time_tracking')
            ->in('subtask_id', [$sid])->count(),
            'POST: subtask_time_tracking must be gone');

        $this->assertSame(0, $db->table('comments')->in('task_id', [$tid, $tid2])->count(),
            'POST: comments must be gone');

        $this->assertSame(0, $db->table('task_has_files')->in('task_id', [$tid, $tid2])->count(),
            'POST: task_has_files must be gone');

        $this->assertSame(0, $db->table('task_has_metadata')->in('task_id', [$tid, $tid2])->count(),
            'POST: task_has_metadata must be gone');

        $this->assertSame(0, $db->table('task_has_tags')->in('task_id', [$tid, $tid2])->count(),
            'POST: task_has_tags must be gone');

        $this->assertSame(0, $db->table('task_has_links')->in('task_id', [$tid, $tid2])->count(),
            'POST: task_has_links must be gone');

        $this->assertSame(0, $db->table('task_has_external_links')->in('task_id', [$tid, $tid2])->count(),
            'POST: task_has_external_links must be gone');

        $this->assertSame(0, $db->table('transitions')->eq('project_id', $pid)->count(),
            'POST: transitions must be gone');

        // ── Explicit plugin gap-cleanup ────────────────────────────────────────
        $this->assertSame(0, $db->table('custom_filters')->eq('project_id', $pid)->count(),
            'POST: custom_filters orphans must be gone');

        $this->assertSame(0, $db->table('invites')->eq('project_id', $pid)->count(),
            'POST: invites orphans must be gone');
    }

    // ── T-07-b: on-disk file removal via real FileStorage ────────────────────

    /**
     * Task-07 T-b: seed a task file using a real FileStorage pointing at a tmpdir;
     * after bulk delete the physical file path must no longer exist on disk.
     *
     * The base test class mocks objectStorage.  We replace it here with a real
     * FileStorage so unlink() is actually called.
     */
    public function testOnDiskFileIsRemovedAfterDelete()
    {
        // Create a real tmpdir for this test
        $filesDir = sys_get_temp_dir() . '/kb_orphan_test_' . getmypid();
        mkdir($filesDir, 0755, true);
        $this->addToAssertionCount(0); // silence coverage noise

        try {
            // Wire real FileStorage into the container (replaces the mock)
            $realStorage = new FileStorage($filesDir);
            unset($this->container['objectStorage']);
            $this->container['objectStorage'] = $realStorage;

            $pid = $this->seedProject('OnDisk project');
            $tid = $this->seedTask($pid, 'OnDisk task');

            // Determine the path Kanboard would generate: tasks/<task_id>/<sha1>
            // We use the same generatePath() logic: tasks/<id>/<sha1(name.time())>
            // Instead, we register the file directly via uploadContent() on the model
            // so the DB row and disk file are both seeded consistently.
            $taskFileModel = new \Kanboard\Model\TaskFileModel($this->container);
            $fileId = $taskFileModel->uploadContent($tid, 'readme.txt', base64_encode('hello test'));
            $this->assertGreaterThan(0, $fileId, 'T-b PRE: file upload must succeed');

            $fileRow = $this->container['db']->table('task_has_files')->eq('id', $fileId)->findOne();
            $this->assertNotEmpty($fileRow, 'T-b PRE: task_has_files row must exist');
            $filePath = $filesDir . DIRECTORY_SEPARATOR . $fileRow['path'];
            $this->assertFileExists($filePath, 'T-b PRE: physical file must exist on disk');

            // ── DELETE ───────────────────────────────────────────────────────
            $controller = $this->buildController(
                isAdmin: true,
                postValues: ['project_ids' => [$pid]]
            );
            $controller->remove();

            // ── POST: row gone AND disk file gone ─────────────────────────────
            $this->assertSame(0,
                $this->container['db']->table('task_has_files')->eq('id', $fileId)->count(),
                'T-b POST: task_has_files row must be gone');

            $this->assertFileDoesNotExist($filePath,
                'T-b POST: physical file must be removed from disk');
        } finally {
            // Clean up tmpdir regardless of test outcome
            if (is_dir($filesDir)) {
                array_map('unlink', glob($filesDir . '/*/*/*') ?: []);
                array_map('unlink', glob($filesDir . '/*/*') ?: []);
                array_map('rmdir',  glob($filesDir . '/*/') ?: []);
                array_map('rmdir',  glob($filesDir . '/tasks/*/') ?: []);
                @rmdir($filesDir . '/tasks');
                @rmdir($filesDir);
            }
        }
    }

    // ── T-07-c: dedup semantics — shared path not unlinked while second row exists ──

    /**
     * Task-07 T-c: Dedup semantics verification.
     *
     * When two task_has_files rows share the same path value, FileModel::remove()
     * only unlinks the physical file when the LAST row referencing that path is deleted.
     * If we delete a project that owns task A (with path X), but another task B in a
     * DIFFERENT surviving project also has path X, the file must NOT be unlinked.
     *
     * Real dedup semantics (from FileModel::remove()):
     *   "Only remove files from disk attached to a single task."  This is checked via
     *   COUNT(*) WHERE path = <path> in task_has_files.  If count > 1, objectStorage
     *   ->remove() is NOT called.  generatePath() uses sha1(filename+time()) so in
     *   production two uploads of the same filename rarely share a path.  This test
     *   forces the shared-path scenario via direct DB insert.
     */
    public function testDedupSharedPathFileIsPreservedWhenOtherTaskSurvives()
    {
        // Create a real tmpdir for this test
        $filesDir = sys_get_temp_dir() . '/kb_dedup_test_' . getmypid();
        mkdir($filesDir, 0755, true);

        try {
            // Wire real FileStorage
            $realStorage = new FileStorage($filesDir);
            unset($this->container['objectStorage']);
            $this->container['objectStorage'] = $realStorage;

            // Project to DELETE
            $pidDel  = $this->seedProject('Dedup-delete project');
            $tidDel  = $this->seedTask($pidDel, 'Dedup-delete task');

            // Project to KEEP (survives)
            $pidKeep = $this->seedProject('Dedup-keep project');
            $tidKeep = $this->seedTask($pidKeep, 'Dedup-keep task');

            // Seed the shared physical file via the real storage
            $sharedPath = 'tasks/shared/dedup-shared-file';
            $taskDir    = $filesDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'shared';
            mkdir($taskDir, 0755, true);
            file_put_contents($filesDir . DIRECTORY_SEPARATOR . $sharedPath, 'shared content');

            $physicalPath = $filesDir . DIRECTORY_SEPARATOR . $sharedPath;
            $this->assertFileExists($physicalPath, 'T-c PRE: shared file must exist on disk');

            // Insert two task_has_files rows with IDENTICAL path (forces dedup scenario)
            $this->container['db']->table('task_has_files')->insert([
                'task_id'  => $tidDel,
                'name'     => 'shared.txt',
                'path'     => $sharedPath,
                'is_image' => 0,
                'size'     => 14,
            ]);
            $this->container['db']->table('task_has_files')->insert([
                'task_id'  => $tidKeep,
                'name'     => 'shared.txt',
                'path'     => $sharedPath,
                'is_image' => 0,
                'size'     => 14,
            ]);

            $this->assertSame(2,
                $this->container['db']->table('task_has_files')->eq('path', $sharedPath)->count(),
                'T-c PRE: 2 rows must share the path');

            // ── DELETE only the "delete" project ──────────────────────────────
            $controller = $this->buildController(
                isAdmin: true,
                postValues: ['project_ids' => [$pidDel]]
            );
            $controller->remove();

            // ── POST: deleted project's row gone, physical file STILL exists ──
            $this->assertSame(0,
                $this->container['db']->table('task_has_files')
                    ->eq('task_id', $tidDel)->count(),
                'T-c POST: deleted task row must be gone from task_has_files');

            // Surviving task still has its row
            $this->assertSame(1,
                $this->container['db']->table('task_has_files')
                    ->eq('task_id', $tidKeep)->count(),
                'T-c POST: surviving task row must still be in task_has_files');

            // Physical file must NOT have been unlinked (dedup protection fired)
            $this->assertFileExists($physicalPath,
                'T-c POST: shared physical file must NOT be unlinked while another row references it');

        } finally {
            // Clean up
            if (is_dir($filesDir)) {
                foreach (glob($filesDir . '/tasks/shared/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($filesDir . '/tasks/shared');
                @rmdir($filesDir . '/tasks');
                @rmdir($filesDir);
            }
        }
    }
}
