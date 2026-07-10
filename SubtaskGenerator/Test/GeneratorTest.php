<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Plugin\SubtaskGenerator\Controller\GeneratorController;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator GeneratorController (Task 03).
 *
 * Covers:
 *  - show() throws AccessForbiddenException when PHP < 8.4 (AI disabled).
 *  - show() throws PageNotFoundException when task does not exist.
 *  - show() returns modal HTML for a valid task (with and without description).
 *  - generate() stub throws AccessForbiddenException when AI disabled.
 *  - CSRF field present in the modal template.
 *  - Sidebar link template file exists and contains required markers.
 *
 * Network calls are never made. The modal renders a partial template; the
 * response->html() call is expected to throw/exit in test context, so we
 * catch Throwable and only assert what happened before the rendering step.
 */
class GeneratorTest extends Base
{
    // ── Stubs ─────────────────────────────────────────────────────────────────

    /**
     * Stub userSession to return admin (isAdmin=true, getId=1).
     * Must be called BEFORE any configModel->save() freezes the service.
     */
    private function stubAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);
    }

    /**
     * Stub userSession so isAdmin() returns false.
     */
    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId'])
            ->getMock();

        $this->container['userSession']->method('isAdmin')->willReturn(false);
        $this->container['userSession']->method('getId')->willReturn(2);
    }

    /**
     * Stub the HTTP request so getIntegerParam('task_id') returns $taskId.
     */
    private function stubTaskIdRequest(int $taskId): void
    {
        $this->container['request'] = $this
            ->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['getIntegerParam'])
            ->getMock();

        $this->container['request']
            ->method('getIntegerParam')
            ->willReturnCallback(function (string $param) use ($taskId) {
                if ($param === 'task_id') {
                    return $taskId;
                }
                return 0;
            });
    }

    // ── Template / file-system checks ────────────────────────────────────────

    /** Modal template file must exist. */
    public function testModalTemplateExists(): void
    {
        $file = dirname(__DIR__) . '/Template/generator/modal.php';
        $this->assertFileExists($file, 'Template/generator/modal.php must exist');
    }

    /** Modal template must contain $this->form->csrf() for CSRF protection. */
    public function testModalTemplateContainsCsrf(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString(
            '$this->form->csrf()',
            $content,
            'Modal template must include $this->form->csrf()'
        );
    }

    /** Modal template must include the sg_prompt textarea. */
    public function testModalTemplateContainsPromptTextarea(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('sg_prompt', $content,
            'Modal template must contain a sg_prompt field');
        $this->assertStringContainsString('<textarea', $content,
            'Modal template must have a textarea element');
    }

    /** Modal template form must target the generate route. */
    public function testModalTemplateTargetsGenerateRoute(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/modal.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('GeneratorController', $content,
            'Modal form action must reference GeneratorController');
        $this->assertStringContainsString('generate', $content,
            'Modal form action must reference the generate action');
    }

    /** Sidebar link template must exist. */
    public function testSidebarLinkTemplateExists(): void
    {
        $file = dirname(__DIR__) . '/Template/generator/sidebar_link.php';
        $this->assertFileExists($file, 'Template/generator/sidebar_link.php must exist');
    }

    /** Sidebar link template must guard on $ai_enabled. */
    public function testSidebarLinkGuardsOnAiEnabled(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/sidebar_link.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('ai_enabled', $content,
            'Sidebar link template must check ai_enabled');
    }

    /** Sidebar link template must guard on hasProjectAccess. */
    public function testSidebarLinkGuardsOnProjectAccess(): void
    {
        $file    = dirname(__DIR__) . '/Template/generator/sidebar_link.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('hasProjectAccess', $content,
            'Sidebar link template must check hasProjectAccess');
    }

    // ── Plugin.php wiring checks ──────────────────────────────────────────────

    /**
     * STRUCTURE-CHECK: verifies Plugin.php contains the sidebar hook registration.
     * Plugin.php must register the sidebar hook.
     */
    public function testPluginWiresSidebarHook(): void
    {
        $file    = dirname(__DIR__) . '/Plugin.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString(
            'template:task:sidebar:after-basic-actions',
            $content,
            'Plugin.php must attach to template:task:sidebar:after-basic-actions'
        );
    }

    /**
     * STRUCTURE-CHECK: verifies Plugin.php contains the show and generate route registrations.
     * Plugin.php must register the show and generate routes.
     */
    public function testPluginWiresGeneratorRoutes(): void
    {
        $file    = dirname(__DIR__) . '/Plugin.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('subtask-generator/show', $content,
            'Plugin.php must register the show route');
        $this->assertStringContainsString('subtask-generator/generate', $content,
            'Plugin.php must register the generate route');
    }

    // ── Controller access gates ───────────────────────────────────────────────

    /**
     * GeneratorController::show() must throw AccessForbiddenException
     * when AI is disabled (PHP < 8.4).
     *
     * We simulate the disabled case by accessing the private isAiEnabled()
     * indirectly: a subclass override lets us inject the gate result.
     */
    public function testShowThrowsWhenAiDisabled(): void
    {
        $this->stubAdmin();

        $controller = new class($this->container) extends GeneratorController {
            protected function isAiEnabled(): bool { return false; }
        };

        $this->expectException(AccessForbiddenException::class);
        $controller->show();
    }

    /**
     * GeneratorController::generate() stub must throw AccessForbiddenException
     * when AI is disabled.
     */
    public function testGenerateStubThrowsWhenAiDisabled(): void
    {
        $this->stubAdmin();

        $controller = new class($this->container) extends GeneratorController {
            protected function isAiEnabled(): bool { return false; }
        };

        $this->expectException(AccessForbiddenException::class);
        $controller->generate();
    }

    // ── Gate-parity regression tests ──────────────────────────────────────────

    /**
     * Gate-parity: with NO provider configured, show() must reject (403) — matching
     * the hidden sidebar link state.
     *
     * The real GeneratorController::isAiEnabled() now delegates to AiGate::isReady(),
     * which requires PHP >= 8.4 AND AiConnector present AND a provider profile
     * configured. Coverage for the AiGate matrix itself (PHP version / connector
     * presence / registry readiness) lives in PluginTest.
     */
    public function testShowRejectsWhenGateReturnsFalseMatchingSidebarHiddenState(): void
    {
        $this->stubAdmin();

        // Use an anonymous subclass that forces isAiEnabled() to false (no provider),
        // matching what AiGate::isReady() returns when no provider is configured.
        $controller = new class($this->container) extends GeneratorController {
            protected function isAiEnabled(): bool { return false; }
        };

        $this->expectException(AccessForbiddenException::class);
        $controller->show();
    }

    /**
     * show() must throw PageNotFoundException when task_id = 0 (task not found).
     */
    public function testShowThrows404ForMissingTask(): void
    {
        $this->stubAdmin();
        $this->stubTaskIdRequest(0);

        $controller = new class($this->container) extends GeneratorController {
            protected function isAiEnabled(): bool { return true; }
        };

        $this->expectException(PageNotFoundException::class);
        $controller->show();
    }

    /**
     * The modal template must render the task title + description in the textarea
     * when both are provided, and must include a CSRF field.
     *
     * We test the template directly (via template->render()) rather than driving
     * the full controller → response->html() path, which calls header() and is
     * not valid in CLI/test context.
     */
    public function testModalTemplateRendersTaskTitleAndDescription(): void
    {
        $task = [
            'id'          => 42,
            'project_id'  => 1,
            'title'       => 'My Task Title',
            'description' => 'My task description text.',
        ];
        $sg_prompt = $task['title'] . "\n\n" . $task['description'];

        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => $task,
            'sg_prompt' => $sg_prompt,
        ]);

        $this->assertStringContainsString('My Task Title', $html,
            'Modal must include the task title in the prompt textarea');
        $this->assertStringContainsString('My task description text.', $html,
            'Modal must include the task description in the prompt textarea');

        // CSRF field must be present in the rendered HTML.
        $this->assertMatchesRegularExpression(
            '/name="csrf_token"/',
            $html,
            'Modal HTML must contain a csrf_token field'
        );
    }

    /**
     * The modal template must render without error when the task has no description
     * (title-only prompt).
     */
    public function testModalTemplateRendersWithTitleOnlyWhenNoDescription(): void
    {
        $task = [
            'id'          => 99,
            'project_id'  => 1,
            'title'       => 'Task With No Description',
            'description' => '',
        ];
        $sg_prompt = $task['title'];

        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => $task,
            'sg_prompt' => $sg_prompt,
        ]);

        $this->assertStringContainsString('Task With No Description', $html,
            'Modal must show the task title even without a description');
        $this->assertStringNotContainsString('Warning:', $html,
            'No PHP warnings in the modal output');
        $this->assertStringNotContainsString('Fatal error', $html,
            'No PHP fatal errors in the modal output');
    }

    // ── Point-of-use profile dropdown ────────────────────────────────────────

    public function testModalOmitsProfileDropdownWithZeroOrOneProfile(): void
    {
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''],
            'sg_prompt' => 'T',
            'profiles'  => [],
            'default_profile_id' => '',
        ]);
        $this->assertStringNotContainsString('name="sg_profile"', $html);
    }

    public function testModalOmitsProfileDropdownWithExactlyOneProfile(): void
    {
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''],
            'sg_prompt' => 'T',
            'profiles'  => [
                ['id' => 'a', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm'],
            ],
            'default_profile_id' => 'a',
        ]);
        $this->assertStringNotContainsString('name="sg_profile"', $html);
    }

    public function testModalShowsProfileDropdownWithTwoProfiles(): void
    {
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''],
            'sg_prompt' => 'T',
            'profiles'  => [
                ['id' => 'a', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm'],
                ['id' => 'b', 'label' => 'B', 'provider' => 'openai', 'model' => 'm'],
            ],
            'default_profile_id' => 'b',
        ]);
        $this->assertStringContainsString('name="sg_profile"', $html);
        $this->assertStringContainsString('value="b"', $html);
    }
}
