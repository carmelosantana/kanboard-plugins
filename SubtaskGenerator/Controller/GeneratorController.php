<?php

namespace Kanboard\Plugin\SubtaskGenerator\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Plugin\SubtaskGenerator\Model\AiGate;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;

/**
 * SubtaskGenerator Generator Controller
 *
 * show()     — renders the generate-subtasks modal prefilled with the task title + description.
 * generate() — POST endpoint: calls SubtaskGeneratorModel::generate(), returns JSON subtasks.
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
     * Create the selected/edited subtask candidates as real subtasks (POST).
     *
     * Guards (in order):
     *  1. AI-ready gate (PHP >= 8.4 + vendor + provider configured).
     *  2. Task must exist (throws PageNotFoundException via getTask()).
     *  3. User must have edit access to the task's project (403 otherwise).
     *  4. CSRF form token must be valid.
     *
     * Reads `titles[]` from the POST body (the JS pre-filters unchecked rows, so
     * everything that arrives should be created; blanks are skipped defensively).
     * Partial failures are tolerated — a failed create does NOT abort the rest.
     *
     * On success: redirects to the task view with a flash notice.
     * On zero created: flash failure, redirect back.
     *
     * @throws PageNotFoundException
     * @throws AccessForbiddenException
     */
    public function create(): void
    {
        // ── 1. AI gate ────────────────────────────────────────────────────────
        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException();
        }

        // ── 2. Task must exist ────────────────────────────────────────────────
        $task = $this->getTask();

        // ── 3. Permission gate ────────────────────────────────────────────────
        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        // ── 4. CSRF gate ──────────────────────────────────────────────────────
        $this->checkCSRFForm();

        // ── 5. Read selected titles ───────────────────────────────────────────
        $values = $this->request->getValues();
        $raw    = isset($values['titles']) && is_array($values['titles'])
            ? $values['titles']
            : [];

        $taskId  = (int) $task['id'];
        $created = 0;
        $failed  = 0;

        foreach ($raw as $title) {
            if (! is_string($title)) {
                continue;
            }
            $title = trim($title);
            if ($title === '') {
                continue; // skip blanks
            }

            $id = $this->subtaskModel->create([
                'task_id' => $taskId,
                'title'   => $title,
            ]);

            if ($id !== false && $id > 0) {
                $created++;
            } else {
                $failed++;
            }
        }

        // ── 6. Redirect with flash ────────────────────────────────────────────
        $redirectUrl = $this->helper->url->to('TaskViewController', 'show', ['task_id' => $taskId, 'project_id' => $task['project_id']]);

        if ($created > 0) {
            $msg = t('%d subtask(s) created successfully.', $created);
            if ($failed > 0) {
                $msg .= ' ' . t('%d could not be saved.', $failed);
            }
            $this->flash->success($msg);
        } else {
            $this->flash->failure(t('No subtasks were created. Please try again.'));
        }

        $this->response->redirect($redirectUrl);
    }

    /**
     * Generate subtasks via the configured LLM provider (POST).
     *
     * Guards (in order):
     *  1. AI-ready gate (PHP >= 8.4 + vendor + provider configured).
     *  2. Task must exist (throws PageNotFoundException via getTask()).
     *  3. User must have edit access to the task's project (403 otherwise).
     *  4. CSRF form token must be valid.
     *
     * On success returns JSON: {"subtasks": ["title 1", "title 2", ...]}.
     * On provider error / malformed output returns JSON: {"error": "<friendly msg>"}.
     * Never 500s the page; never leaks the API key in the error message.
     *
     * @throws PageNotFoundException
     * @throws AccessForbiddenException
     */
    public function generate(): void
    {
        // ── 1. AI gate ────────────────────────────────────────────────────────
        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException();
        }

        // ── 2. Task must exist ────────────────────────────────────────────────
        $task = $this->getTask();

        // ── 3. Permission gate ────────────────────────────────────────────────
        if (! $this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }

        // ── 4. CSRF gate ──────────────────────────────────────────────────────
        $this->checkCSRFForm();

        // ── 5. Read prompt ────────────────────────────────────────────────────
        $prompt = trim($this->request->getStringParam('sg_prompt', ''));

        if ($prompt === '') {
            // Build from the task if the user cleared the textarea.
            $prompt = $this->buildPrompt($task);
        }

        if ($prompt === '') {
            $this->response->json(['error' => t('Prompt is empty. Please enter a description.')]);
            return;
        }

        // ── 6. Call the model ─────────────────────────────────────────────────
        try {
            /** @var SubtaskGeneratorModel $model */
            $model    = $this->getGeneratorModel();
            $subtasks = $model->generate($prompt);

            $this->response->json(['subtasks' => $subtasks]);
        } catch (\Throwable $e) {
            // Log the exception class and code only — never the message, which
            // could contain a request URL, provider error body, or other details
            // that may inadvertently surface sensitive context. The API key is
            // never in the exception message itself, but this is defense-in-depth:
            // no raw exception text ever reaches the log.
            error_log('[SubtaskGenerator] Provider error: ' . get_class($e) . ' code ' . $e->getCode());

            $this->response->json([
                'error' => t('The AI provider returned an error. Please check your settings and try again.'),
            ]);
        }
    }

    // ── Protected factories (overridable in tests) ────────────────────────────

    /**
     * Return the SubtaskGeneratorModel instance.
     *
     * Protected so tests can override to inject a model with a mock provider
     * without touching the Pimple container.
     */
    protected function getGeneratorModel(): SubtaskGeneratorModel
    {
        return new SubtaskGeneratorModel($this->container);
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
     * Returns true when the runtime is fully AI-ready.
     *
     * Delegates to AiGate::isReady() — the single source of truth shared with
     * Plugin::initialize(). This guarantees the sidebar link is hidden if and
     * only if the controller also rejects the request: the two gates are
     * identical by construction.
     *
     * Protected so tests can override via anonymous subclass to inject any
     * gate result without spawning a child process.
     */
    protected function isAiEnabled(): bool
    {
        return AiGate::isReady($this->container);
    }
}
