<?php

namespace Kanboard\Plugin\SubtaskGenerator\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

/**
 * SubtaskGenerator Settings Controller
 *
 * Admin-only settings page for the subtask generation limit. Provider/model/
 * API key configuration now lives entirely in the AiConnector plugin.
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Controller
 * @author  Carmelo Santana
 */
class SettingsController extends BaseController
{
    /**
     * Display the settings page (admin only).
     *
     * @throws AccessForbiddenException
     */
    public function show(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->response->html($this->helper->layout->config('SubtaskGenerator:config/settings', [
            'title'           => t('Settings') . ' &gt; ' . t('Subtask Generator'),
            'sg_max_subtasks' => (int) $this->configModel->get(
                'sg_max_subtasks',
                (string) \Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS
            ),
        ]));
    }

    /**
     * Save settings (admin only, CSRF protected).
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
        $maxSubtasks = max(1, min(20, (int) ($values['sg_max_subtasks']
            ?? \Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS)));
        $this->configModel->save(['sg_max_subtasks' => (string) $maxSubtasks]);
        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'SubtaskGenerator']));
    }
}
