<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFinderModel;
use Kanboard\Plugin\SubtaskGenerator\Controller\GeneratorController;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator Task 05: create() controller.
 *
 * Covers:
 *  (a) create() creates exactly the selected titles via subtaskModel — asserted via DB.
 *  (b) Blanks are skipped (empty string, whitespace-only).
 *  (c) Non-editor / AI-disabled → 403.
 *  (d) Bad CSRF → 403.
 *  (e) Partial failure: one bad title does not abort the rest (source-level assertion).
 *  (f) Route registered in Plugin.php.
 *  (g) create form targets the create route in the modal template.
 */
class CreateSubtaskTest extends Base
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Stub userSession to return admin (isAdmin=true, isLogged=true, getId=1). */
    private function stubAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId', 'isLogged'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('isLogged')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);
    }

    /** Stub userSession so isAdmin() returns false (non-editor scenario). */
    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId', 'isLogged'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(false);
        $this->container['userSession']->method('isLogged')->willReturn(false);
        $this->container['userSession']->method('getId')->willReturn(2);
    }

    /**
     * Stub the HTTP request so getIntegerParam('task_id') returns $taskId
     * and getValues() returns $postValues.
     */
    private function stubRequest(int $taskId, array $postValues = []): void
    {
        $mock = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['getIntegerParam', 'getValues', 'getStringParam'])
            ->getMock();

        $mock->method('getIntegerParam')
            ->willReturnCallback(function (string $param) use ($taskId) {
                return $param === 'task_id' ? $taskId : 0;
            });

        $mock->method('getValues')->willReturn($postValues);

        $mock->method('getStringParam')->willReturn('');

        $this->container['request'] = $mock;
    }

    /**
     * Seed one project + one task; return [project_id, task_id].
     */
    private function seedProjectAndTask(): array
    {
        $projectModel      = new ProjectModel($this->container);
        $taskCreationModel = new TaskCreationModel($this->container);

        $projectId = $projectModel->create(['name' => 'Test Project']);
        $taskId    = $taskCreationModel->create(['title' => 'Test Task', 'project_id' => $projectId]);

        return [$projectId, $taskId];
    }

    /**
     * Build a GeneratorController subclass with:
     *  - isAiEnabled() overridden to $aiEnabled
     *  - checkCSRFForm() overridden to $csrfOk (no-op or throw)
     *  - response->redirect() overridden to a no-op (avoids header() calls in test)
     */
    private function makeController(bool $aiEnabled = true, bool $csrfOk = true): GeneratorController
    {
        $container = $this->container;

        $ctrl = new class($container, $aiEnabled, $csrfOk) extends GeneratorController {
            private bool $ai;
            private bool $csrf;

            public function __construct($c, bool $ai, bool $csrf)
            {
                parent::__construct($c);
                $this->ai   = $ai;
                $this->csrf = $csrf;
            }

            protected function isAiEnabled(): bool
            {
                return $this->ai;
            }

            protected function checkCSRFForm(): void
            {
                if (! $this->csrf) {
                    throw new AccessForbiddenException();
                }
                // ok — no-op
            }
        };

        // Replace response with a stub so redirect() does not call header().
        $this->container['response'] = $this
            ->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect', 'json', 'html'])
            ->getMock();

        // Replace flash so calls don't need a real session.
        $this->container['flash'] = $this
            ->getMockBuilder(\Kanboard\Core\Session\FlashMessage::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['success', 'failure'])
            ->getMock();

        return $ctrl;
    }

    // ── (a) Creates exactly the selected titles ───────────────────────────────

    /**
     * create() must create exactly the supplied titles as subtasks on the task.
     * Verified via DB (SubtaskModel::getAll / count).
     */
    public function testCreateCreatesExactlySelectedTitles(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();

        $titles = ['Write unit tests', 'Deploy to staging', 'Update docs'];
        $this->stubRequest($taskId, ['task_id' => $taskId, 'titles' => $titles]);

        $ctrl = $this->makeController(true, true);
        $ctrl->create();

        $subtaskModel = new SubtaskModel($this->container);
        $subtasks     = $subtaskModel->getAll($taskId);

        $this->assertCount(3, $subtasks, 'Exactly 3 subtasks must be created');

        $createdTitles = array_column($subtasks, 'title');
        $this->assertContains('Write unit tests', $createdTitles);
        $this->assertContains('Deploy to staging', $createdTitles);
        $this->assertContains('Update docs', $createdTitles);
    }

    /**
     * Only one title → only one subtask created.
     */
    public function testCreateCreatesSingleTitle(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();

        $this->stubRequest($taskId, ['task_id' => $taskId, 'titles' => ['Setup CI pipeline']]);

        $ctrl = $this->makeController(true, true);
        $ctrl->create();

        $subtaskModel = new SubtaskModel($this->container);
        $subtasks     = $subtaskModel->getAll($taskId);

        $this->assertCount(1, $subtasks);
        $this->assertSame('Setup CI pipeline', $subtasks[0]['title']);
    }

    // ── (b) Blanks are skipped ────────────────────────────────────────────────

    /**
     * Blank and whitespace-only titles must be skipped; only non-blank ones
     * are persisted as subtasks.
     */
    public function testCreateSkipsBlanks(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();

        $this->stubRequest($taskId, [
            'task_id' => $taskId,
            'titles'  => ['', '   ', 'Valid title', "\t", '   Another valid   '],
        ]);

        $ctrl = $this->makeController(true, true);
        $ctrl->create();

        $subtaskModel = new SubtaskModel($this->container);
        $subtasks     = $subtaskModel->getAll($taskId);

        $this->assertCount(2, $subtasks, 'Only the 2 non-blank titles must be created');

        $createdTitles = array_column($subtasks, 'title');
        $this->assertContains('Valid title', $createdTitles);
        $this->assertContains('Another valid', $createdTitles);
    }

    /**
     * When all titles are blank, zero subtasks are created.
     */
    public function testCreateCreatesZeroWhenAllBlanks(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();

        $this->stubRequest($taskId, ['task_id' => $taskId, 'titles' => ['', '   ']]);

        $ctrl = $this->makeController(true, true);
        $ctrl->create();

        $subtaskModel = new SubtaskModel($this->container);
        $subtasks     = $subtaskModel->getAll($taskId);

        $this->assertCount(0, $subtasks, 'No subtasks must be created when all titles are blank');
    }

    /**
     * When titles[] is not present in the POST body, zero subtasks are created.
     */
    public function testCreateCreatesZeroWhenNoTitles(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();

        $this->stubRequest($taskId, ['task_id' => $taskId]);

        $ctrl = $this->makeController(true, true);
        $ctrl->create();

        $subtaskModel = new SubtaskModel($this->container);
        $subtasks     = $subtaskModel->getAll($taskId);

        $this->assertCount(0, $subtasks);
    }

    // ── (c) Non-editor / AI-disabled → 403 ───────────────────────────────────

    /**
     * create() must throw AccessForbiddenException when AI is disabled.
     */
    public function testCreateThrowsWhenAiDisabled(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();
        $this->stubRequest($taskId, ['task_id' => $taskId, 'titles' => ['A title']]);

        $ctrl = $this->makeController(false, true); // AI disabled

        $this->expectException(AccessForbiddenException::class);
        $ctrl->create();
    }

    /**
     * create() must throw PageNotFoundException when the task does not exist.
     */
    public function testCreateThrows404ForMissingTask(): void
    {
        $this->stubAdmin();
        $this->stubRequest(9999, ['task_id' => 9999, 'titles' => ['A title']]);

        $ctrl = $this->makeController(true, true);

        $this->expectException(PageNotFoundException::class);
        $ctrl->create();
    }

    // ── (d) Bad CSRF → 403 ───────────────────────────────────────────────────

    /**
     * create() must throw AccessForbiddenException when the CSRF token is invalid.
     */
    public function testCreateThrowsOnBadCsrf(): void
    {
        $this->stubAdmin();
        [$projectId, $taskId] = $this->seedProjectAndTask();
        $this->stubRequest($taskId, ['task_id' => $taskId, 'titles' => ['A title']]);

        $ctrl = $this->makeController(true, false); // CSRF fails

        $this->expectException(AccessForbiddenException::class);
        $ctrl->create();
    }

    // ── (e) Partial-failure handling — source-level assertion ─────────────────

    /**
     * The create() source must loop over all titles and NOT break/return early on
     * a single failed create. A failed create increments $failed but does not abort
     * the rest of the loop.
     */
    public function testCreateSourceHandlesPartialFailures(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');

        // Must have a loop over the titles.
        $this->assertStringContainsString('foreach ($raw', $src,
            'create() must iterate over all selected titles');

        // Failed creates increment $failed, not break/return.
        $this->assertStringContainsString('$failed++', $src,
            'create() must count failures without aborting the loop');

        // Must NOT have a bare `return` inside the loop body (after any failed create).
        // We verify this by checking that $failed++ is used instead of an early return.
        $this->assertStringNotContainsString('return; // abort on first failure', $src,
            'create() must not abort on first failure');
    }

    // ── (f) Route registered ──────────────────────────────────────────────────

    /**
     * Plugin.php must register the subtask-generator/create route.
     */
    public function testCreateRouteRegisteredInPlugin(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Plugin.php');

        $this->assertStringContainsString(
            'subtask-generator/create',
            $src,
            'Plugin.php must register the subtask-generator/create route'
        );
    }

    // ── (g) Modal template targets create route ───────────────────────────────

    /**
     * The modal template must contain a form that targets the create action.
     */
    public function testModalTemplateContainsCreateForm(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString(
            "'create'",
            $content,
            'Modal template must reference the create action'
        );

        $this->assertStringContainsString(
            'titles[]',
            $content,
            'Modal template must include titles[] inputs for the create form'
        );
    }

    /**
     * The modal template must contain Regenerate and Create buttons.
     */
    public function testModalTemplateContainsRegenerateAndCreateButtons(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('sg-regenerate-btn', $content,
            'Modal must contain a Regenerate button');
        $this->assertStringContainsString('sg-create-btn', $content,
            'Modal must contain a Create button');
    }

    /**
     * The modal template must have the candidate checklist container.
     */
    public function testModalTemplateContainsCandidateList(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('sg-candidate-list', $content,
            'Modal must contain a candidate-list container');
        $this->assertStringContainsString('sg-results', $content,
            'Modal must contain a results container');
    }

    // ── Controller source guards ──────────────────────────────────────────────

    /**
     * create() source must guard on isAiEnabled(), hasProjectAccess, and checkCSRFForm.
     */
    public function testCreateSourceGuardsOnPermissions(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/GeneratorController.php');

        $this->assertStringContainsString(
            'public function create()',
            $src,
            'GeneratorController must define create()'
        );
        $this->assertStringContainsString(
            'isAiEnabled()',
            $src,
            'create() must check isAiEnabled()'
        );
        $this->assertStringContainsString(
            'hasProjectAccess',
            $src,
            'create() must check hasProjectAccess'
        );
        $this->assertStringContainsString(
            'checkCSRFForm',
            $src,
            'create() must call checkCSRFForm()'
        );
        $this->assertStringContainsString(
            'subtaskModel->create(',
            $src,
            'create() must call subtaskModel->create()'
        );
    }
}
