<?php

namespace Kanboard\Plugin\FeatureSync\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

class FeatureSyncController extends BaseController
{
    /**
     * GET — admin-only Feature Sync page shell.
     *
     * Guards: app-admin only. The 5-step workflow (source → features → targets →
     * preview → apply) is rendered as static placeholders here; wiring comes in
     * later tasks (02–06).
     *
     * @throws AccessForbiddenException when the current user is not an app admin
     */
    public function index()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $this->response->html($this->helper->layout->config('FeatureSync:sync/index', [
            'title' => t('Settings') . ' &gt; ' . t('Feature Sync'),
        ]));
    }
}
