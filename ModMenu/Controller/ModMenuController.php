<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Admin-only plugin-manager UI. Thin: delegates all work to the models and
 * renders the four tabs (Installed / Browse / Upload / Sources).
 */
class ModMenuController extends BaseController
{
    private function requireAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    private function manager(): PluginManager
    {
        return new PluginManager($this->container);
    }

    private function backToInstalled()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu']));
    }

    public function show()
    {
        $this->requireAdmin();
        $manager = $this->manager();

        $this->response->html($this->helper->layout->config('ModMenu:settings/installed', [
            'title' => t('ModMenu'),
            'tab' => 'installed',
            'plugins' => $manager->listInstalled(),
            'is_configured' => $manager->isConfigured(),
            'not_configured_reason' => $manager->notConfiguredReason(),
            'self_name' => PluginManager::SELF,
        ]));
    }

    public function directory()
    {
        $this->requireAdmin();
        $result = (new DirectoryClient($this->container))->fetchAll();

        $this->response->html($this->helper->layout->config('ModMenu:settings/directory', [
            'title' => t('ModMenu'),
            'tab' => 'browse',
            'plugins' => $result['plugins'],
            'errors' => $result['errors'],
            'is_configured' => $this->manager()->isConfigured(),
        ]));
    }

    public function sources()
    {
        $this->requireAdmin();

        $this->response->html($this->helper->layout->config('ModMenu:settings/sources', [
            'title' => t('ModMenu'),
            'tab' => 'sources',
            'sources' => (new SourceRepository($this->container))->getSources(),
            'default_source' => SourceRepository::DEFAULT_SOURCE,
        ]));
    }

    public function addSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = $this->request->getStringParam('url');
        try {
            (new SourceRepository($this->container))->addSource($url);
            $this->flash->success(t('Source added.'));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    public function removeSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        (new SourceRepository($this->container))->removeSource($this->request->getStringParam('url'));
        $this->flash->success(t('Source removed.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    public function confirm()
    {
        $this->requireAdmin();
        $this->response->html($this->template->render('ModMenu:plugin/remove', [
            'name' => $this->request->getStringParam('name'),
        ]));
    }

    public function enable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->enable($this->request->getStringParam('name')), t('Plugin enabled.'));
        $this->backToInstalled();
    }

    public function disable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->disable($this->request->getStringParam('name')), t('Plugin disabled.'));
        $this->backToInstalled();
    }

    public function uninstall()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->uninstall($this->request->getStringParam('name')), t('Plugin removed.'));
        $this->backToInstalled();
    }

    public function install()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = urldecode($this->request->getStringParam('archive_url'));
        $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($url), t('Plugin installed.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    public function update()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = urldecode($this->request->getStringParam('archive_url'));
        $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($url), t('Plugin updated.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    private function runAndFlash(callable $op, string $successMessage): void
    {
        try {
            $op($this->manager());
            $this->flash->success($successMessage);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
    }
}
