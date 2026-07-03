<?php

namespace Kanboard\Plugin\SubtaskGenerator\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Plugin\SubtaskGenerator\Model\ProviderFactory;
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
            // Log the real error (without the API key — the exception message
            // from the HTTP client never contains the key itself, but guard
            // against any accidental leak by omitting raw exception details from
            // the JSON response that reaches the browser).
            error_log('[SubtaskGenerator] Provider error: ' . $e->getMessage());

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
     * Delegates to ProviderFactory::isAiReady() — the single source of truth
     * shared with Plugin::initialize(). This guarantees the sidebar link is
     * hidden if and only if the controller also rejects the request: the two
     * gates are identical by construction.
     *
     * Protected so tests can override via anonymous subclass to inject any
     * gate result without spawning a child process.
     */
    protected function isAiEnabled(): bool
    {
        return ProviderFactory::isAiReady($this->configModel);
    }
}
