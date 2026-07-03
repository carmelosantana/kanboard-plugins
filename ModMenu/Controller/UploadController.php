<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * WordPress-style zip upload. GET renders the upload form; POST validates the
 * uploaded archive and installs it via PluginManager.
 */
class UploadController extends BaseController
{
    public function upload()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $this->handleUpload();
            $this->response->redirect($this->helper->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu']));
            return;
        }

        $manager = new PluginManager($this->container);
        $this->response->html($this->helper->layout->config('ModMenu:settings/upload', [
            'title' => t('ModMenu'),
            'tab' => 'upload',
            'is_configured' => $manager->isConfigured(),
        ]));
    }

    private function handleUpload(): void
    {
        $file = $_FILES['plugin'] ?? null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash->failure(t('No file was uploaded, or the upload failed.'));
            return;
        }
        if (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash->failure(t('Please upload a .zip archive.'));
            return;
        }

        try {
            $name = (new PluginManager($this->container))->installFromFile($file['tmp_name']);
            $this->flash->success(t('Plugin "%s" installed.', $name));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
    }
}
