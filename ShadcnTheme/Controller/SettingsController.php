<?php

namespace Kanboard\Plugin\ShadcnTheme\Controller;

use Exception;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\ObjectStorage\ObjectStorageException;

/**
 * ShadcnTheme Settings Controller
 *
 * Admin-only settings page for uploading a custom favicon and logo.
 * Files are stored under data/files/ via core ObjectStorage and their
 * paths persisted in configModel as shadcn_logo_path / shadcn_favicon_path.
 *
 * @package Kanboard\Plugin\ShadcnTheme\Controller
 * @author  Carmelo Santana
 */
class SettingsController extends BaseController
{
    /**
     * Allowed MIME types for favicon uploads
     */
    const FAVICON_ALLOWED_TYPES = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];

    /**
     * Allowed extensions for favicon uploads
     */
    const FAVICON_ALLOWED_EXTENSIONS = ['ico', 'png', 'svg'];

    /**
     * Allowed MIME types for logo uploads
     */
    const LOGO_ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];

    /**
     * Allowed extensions for logo uploads
     */
    const LOGO_ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

    /**
     * Max upload size in bytes (2 MB)
     */
    const MAX_FILE_SIZE = 2 * 1024 * 1024;

    /**
     * Storage path prefix for plugin-managed files
     */
    const STORAGE_PREFIX = 'shadcn-theme';

    /**
     * Display the settings page (admin only)
     *
     * @access public
     * @throws AccessForbiddenException
     */
    public function show()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $this->response->html($this->helper->layout->config('ShadcnTheme:config/settings', [
            'title' => t('Settings') . ' &gt; ' . t('Theme'),
            'logo_path'    => $this->configModel->get('shadcn_logo_path', ''),
            'favicon_path' => $this->configModel->get('shadcn_favicon_path', ''),
        ]));
    }

    /**
     * Handle file uploads (logo and/or favicon). Admin only, CSRF protected.
     *
     * @access public
     * @throws AccessForbiddenException
     */
    public function upload()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();

        // getValues() reads from POST (after CSRF validation) — use it for checkbox fields.
        // For file inputs we use getFileInfo(); for remove checkboxes we use getValues().
        $values = $this->request->getValues();
        $saved  = [];
        $errors = [];

        // ── Remove logo ──────────────────────────────────────────────────────
        if (! empty($values['remove_logo'])) {
            $this->removeFile('shadcn_logo_path');
            $saved[] = t('Logo removed');
        }

        // ── Remove favicon ───────────────────────────────────────────────────
        if (! empty($values['remove_favicon'])) {
            $this->removeFile('shadcn_favicon_path');
            $saved[] = t('Favicon removed');
        }

        // ── Logo upload ──────────────────────────────────────────────────────
        $logo = $this->request->getFileInfo('shadcn_logo');
        if (! empty($logo['name']) && $logo['name'] !== '') {
            $result = $this->handleUpload($logo, 'logo', self::LOGO_ALLOWED_EXTENSIONS, self::LOGO_ALLOWED_TYPES);
            if ($result['ok']) {
                $this->configModel->save(['shadcn_logo_path' => $result['path']]);
                $saved[] = t('Logo');
            } else {
                $errors[] = t('Logo') . ': ' . $result['error'];
            }
        }

        // ── Favicon upload ───────────────────────────────────────────────────
        $favicon = $this->request->getFileInfo('shadcn_favicon');
        if (! empty($favicon['name']) && $favicon['name'] !== '') {
            $result = $this->handleUpload($favicon, 'favicon', self::FAVICON_ALLOWED_EXTENSIONS, self::FAVICON_ALLOWED_TYPES);
            if ($result['ok']) {
                $this->configModel->save(['shadcn_favicon_path' => $result['path']]);
                $saved[] = t('Favicon');
            } else {
                $errors[] = t('Favicon') . ': ' . $result['error'];
            }
        }

        if (! empty($errors)) {
            $this->flash->failure(implode('; ', $errors));
        }

        if (! empty($saved)) {
            $this->flash->success(t('Settings saved successfully.'));
        } elseif (empty($errors)) {
            $this->flash->success(t('No changes were made.'));
        }

        $this->response->redirect($this->helper->url->to(
            'SettingsController',
            'show',
            ['plugin' => 'ShadcnTheme']
        ));
    }

    /**
     * Serve a stored asset (logo or favicon) through the controller.
     *
     * URL: ?controller=ShadcnTheme:SettingsController&action=serveAsset&slot=logo|favicon
     *
     * @access public
     */
    public function serveAsset()
    {
        $slot = $this->request->getStringParam('slot');
        if (! in_array($slot, ['logo', 'favicon'], true)) {
            $this->response->status(400);
            return;
        }

        $configKey = 'shadcn_' . $slot . '_path';
        $path = $this->configModel->get($configKey, '');

        if ($path === '') {
            $this->response->status(404);
            return;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = [
            'ico'  => 'image/x-icon',
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        try {
            $etag = md5($path);
            $this->response->withCache(86400, $etag);
            $this->response->withContentType($mime);
            $this->response->withSecurityHeaders();
            $this->response->withContentSecurityPolicy(['default-src' => "'none'"]);

            if ($this->request->getHeader('If-None-Match') !== '"' . $etag . '"') {
                $this->response->send();
                $this->objectStorage->output($path);
            } else {
                $this->response->status(304);
            }
        } catch (ObjectStorageException $e) {
            $this->logger->error('[ShadcnTheme] serveAsset: ' . $e->getMessage());
            $this->response->status(404);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Validate and store an uploaded file via ObjectStorage.
     *
     * @param  array    $file       $_FILES entry (name, tmp_name, size, error, type)
     * @param  string   $slot       'logo' or 'favicon'
     * @param  string[] $allowedExt Allowed file extensions (without dot)
     * @param  string[] $allowedMime Allowed MIME types
     * @return array{ok:bool, path:string, error:string}
     */
    private function handleUpload(array $file, string $slot, array $allowedExt, array $allowedMime): array
    {
        // PHP upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => '', 'error' => t('Upload failed (error code %d)', $file['error'])];
        }

        // Size limit
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['ok' => false, 'path' => '', 'error' => t('File too large (max 2 MB)')];
        }

        // Extension check
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'path' => '', 'error' => t('Invalid file type (allowed: %s)', implode(', ', $allowedExt))];
        }

        // MIME check: use finfo when available, otherwise trust the extension check above.
        // Core (AvatarFileModel::isAvatarImage) also relies solely on extension.
        if (class_exists('\finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file['tmp_name']);

            // SVG files may report as text/plain/html — allow when extension is valid
            $mimeOk = in_array($detectedMime, $allowedMime, true) ||
                      ($ext === 'svg' && in_array('image/svg+xml', $allowedMime, true));

            if (! $mimeOk) {
                return ['ok' => false, 'path' => '', 'error' => t('Invalid file type detected')];
            }
        }

        // Remove old file if one exists
        $this->removeFile('shadcn_' . $slot . '_path');

        // Generate a unique storage key: shadcn-theme/<slot>/<hash>.<ext>
        $key = self::STORAGE_PREFIX . DIRECTORY_SEPARATOR . $slot . DIRECTORY_SEPARATOR
             . hash('sha1', $file['name'] . time()) . '.' . $ext;

        try {
            $this->objectStorage->moveUploadedFile($file['tmp_name'], $key);
        } catch (ObjectStorageException $e) {
            $this->logger->error('[ShadcnTheme] Upload failed: ' . $e->getMessage());
            return ['ok' => false, 'path' => '', 'error' => t('Could not store file. Check data folder permissions.')];
        }

        return ['ok' => true, 'path' => $key, 'error' => ''];
    }

    /**
     * Remove an existing file from ObjectStorage and clear its config key.
     *
     * @param  string $configKey  e.g. 'shadcn_logo_path'
     */
    private function removeFile(string $configKey): void
    {
        $existing = $this->configModel->get($configKey, '');
        if ($existing !== '') {
            try {
                $this->objectStorage->remove($existing);
            } catch (Exception $e) {
                $this->logger->error('[ShadcnTheme] Could not remove file: ' . $e->getMessage());
            }
            $this->configModel->save([$configKey => '']);
        }
    }
}
