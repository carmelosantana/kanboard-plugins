<?php

namespace Kanboard\Plugin\SubtaskGenerator\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;

/**
 * SubtaskGenerator Generator Controller
 *
 * show()     — renders the generate-subtasks modal prefilled with the task title + description.
 * generate() — stub placeholder for task-04; receives the POST from the modal form.
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Controller
 * @author  Carmelo Santana
 */
class GeneratorController extends BaseController
{
    /**
     * Render the "Generate subtasks" modal, prefilled with the task title + description.
     *
     * Guards:
     *  - The task must exist (404 otherwise).
     *  - The current user must have edit access to the task's project (403 otherwise).
     *  - AI features must be enabled (PHP >= 8.4 + vendor loaded); 403 if not.
     *
     * @throws PageNotFoundException
     * @throws AccessForbiddenException
     */
    public function show(): void
    {
        // AI gate first — cheapest check.
        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException();
        }

        // Fetch and validate the task (throws 404 when not found).
        $task = $this->getTask();

        // Permission gate: user must be able to edit the task's project.
        // Mirrors what the core sidebar uses (sidebar.php:27):
        //   $this->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])
        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        // Build the prefilled prompt: title + (optional) description.
        $sg_prompt = $this->buildPrompt($task);

        $this->response->html($this->template->render('SubtaskGenerator:generator/modal', [
            'task'      => $task,
            'sg_prompt' => $sg_prompt,
        ]));
    }

    /**
     * Generate subtasks via the configured LLM provider.
     *
     * STUB: task-04 fills this in. This stub validates access + CSRF so the
     * form wiring is complete and task-04 can slot in without touching Plugin.php.
     *
     * @throws PageNotFoundException
     * @throws AccessForbiddenException
     */
    public function generate(): void
    {
        // AI gate.
        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException();
        }

        $task = $this->getTask();

        // Permission gate (same check as show()).
        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        // CSRF gate for the POST form.
        $this->checkCSRFForm();

        // task-04 implements the actual generation here.
        // For now, redirect back to the task so the form submit has a safe landing.
        $this->flash->success(t('Subtask generation will be implemented in the next task.'));
        $this->response->redirect($this->helper->url->to(
            'TaskViewController',
            'show',
            ['task_id' => $task['id'], 'project_id' => $task['project_id']]
        ));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the default prompt text from the task's title and (optional) description.
     *
     * A task with no description still produces a useful prompt (title only).
     */
    private function buildPrompt(array $task): string
    {
        $title       = trim($task['title'] ?? '');
        $description = trim($task['description'] ?? '');

        if ($description !== '') {
            return $title . "\n\n" . $description;
        }

        return $title;
    }

    /**
     * Returns true when the host meets the PHP 8.4 gate and vendor is loaded.
     *
     * Protected so tests can override via anonymous subclass to simulate
     * the disabled state without spawning a child process.
     */
    protected function isAiEnabled(): bool
    {
        return PHP_VERSION_ID >= 80400
            && file_exists(__DIR__ . '/../vendor/autoload.php');
    }
}
