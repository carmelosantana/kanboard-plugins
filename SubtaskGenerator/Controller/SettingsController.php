<?php

namespace Kanboard\Plugin\SubtaskGenerator\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Model\ProviderFactory;

/**
 * SubtaskGenerator Settings Controller
 *
 * Admin-only settings page for choosing the LLM provider, model, API key,
 * and subtask generation limits. API keys are stored via configModel and
 * are NEVER echoed back into the form value (masked as "••••").
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Controller
 * @author  Carmelo Santana
 */
class SettingsController extends BaseController
{
    /**
     * Display the settings page (admin only).
     *
     * If PHP < 8.4, shows a "feature disabled" notice instead of the form.
     *
     * @throws AccessForbiddenException
     */
    public function show(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $aiEnabled = $this->isAiEnabled();
        $provider  = $this->configModel->get('sg_provider', ProviderFactory::DEFAULT_PROVIDER);
        $model     = $this->configModel->get('sg_model', ProviderFactory::defaultModelFor($provider));
        $hasKey    = $this->configModel->get('sg_api_key', '') !== '';
        $maxSubtasks = (int) $this->configModel->get('sg_max_subtasks', ProviderFactory::DEFAULT_MAX_SUBTASKS);

        $this->response->html($this->helper->layout->config('SubtaskGenerator:config/settings', [
            'title'        => t('Settings') . ' &gt; ' . t('Subtask Generator'),
            'ai_enabled'   => $aiEnabled,
            'sg_provider'  => $provider,
            'sg_model'     => $model,
            'sg_key_is_set'=> $hasKey,
            'sg_max_subtasks' => $maxSubtasks,
            'providers'    => ProviderFactory::PROVIDERS,
            'default_models' => ProviderFactory::DEFAULT_MODELS,
        ]));
    }

    /**
     * Save settings (admin only, CSRF protected).
     *
     * Never overwrites a stored API key with an empty/placeholder value.
     *
     * @throws AccessForbiddenException
     */
    public function save(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();

        $values = $this->request->getValues();

        $provider = isset($values['sg_provider']) && array_key_exists($values['sg_provider'], ProviderFactory::PROVIDERS)
            ? $values['sg_provider']
            : ProviderFactory::DEFAULT_PROVIDER;

        $model = trim($values['sg_model'] ?? '');
        if ($model === '') {
            $model = ProviderFactory::defaultModelFor($provider);
        }

        $maxSubtasks = max(1, min(20, (int) ($values['sg_max_subtasks'] ?? ProviderFactory::DEFAULT_MAX_SUBTASKS)));

        // API key: only update if a non-empty, non-placeholder value was submitted.
        $submittedKey = trim($values['sg_api_key'] ?? '');
        $isPlaceholder = ($submittedKey === '' || $submittedKey === ProviderFactory::KEY_PLACEHOLDER);
        if (! $isPlaceholder) {
            // Never log the key value.
            $this->configModel->save(['sg_api_key' => $submittedKey]);
        }

        $this->configModel->save([
            'sg_provider'     => $provider,
            'sg_model'        => $model,
            'sg_max_subtasks' => (string) $maxSubtasks,
        ]);

        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to(
            'SettingsController',
            'show',
            ['plugin' => 'SubtaskGenerator']
        ));
    }

    /**
     * Test connection: instantiates the configured provider and makes a minimal
     * structured() call to verify the API key and connectivity.
     *
     * Returns a JSON response — never leaks the key in the response.
     */
    public function testConnection(): void
    {
        if (! $this->userSession->isAdmin()) {
            $this->response->json(['ok' => false, 'error' => t('Access denied.')]);
            return;
        }

        if (! $this->isAiEnabled()) {
            $this->response->json(['ok' => false, 'error' => t('AI features require PHP >= 8.4.')]);
            return;
        }

        try {
            $provider = ProviderFactory::buildFromConfig($this->configModel);

            // A minimal structured call: ask for exactly one subtask title.
            $schema = json_encode([
                'name'        => 'test_output',
                'description' => 'Connection test',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean'],
                    ],
                    'required' => ['ok'],
                ],
            ]);

            $messages = [
                new \CarmeloSantana\PHPAgents\Message\UserMessage('Reply with ok=true to confirm the connection works.'),
            ];

            $result = $provider->structured($messages, $schema);
            $this->response->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            // Never log or return the key; only the exception message (which does not contain it).
            $this->response->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when the host meets the PHP 8.4 gate and vendor is loaded.
     */
    private function isAiEnabled(): bool
    {
        return PHP_VERSION_ID >= 80400
            && file_exists(__DIR__ . '/../vendor/autoload.php');
    }
}
