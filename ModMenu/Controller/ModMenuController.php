<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Model\DependencyResolver;
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
        $url = $this->postValue('url');
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
        (new SourceRepository($this->container))->removeSource($this->postValue('url'));
        $this->flash->success(t('Source removed.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    public function confirm()
    {
        $this->requireAdmin();
        $name = $this->request->getStringParam('name');
        $manager = $this->manager();
        $resolver = new DependencyResolver($this->container);
        $blockers = $resolver->resolveReverse($name, $manager->installedPluginsDeps(), $manager->installedMap());
        $this->response->html($this->template->render('ModMenu:plugin/remove', [
            'name'     => $name,
            'blockers' => array_map(static fn ($b) => $b['plugin'], $blockers),
        ]));
    }

    public function enable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->forwardOrConfirm($this->postValue('name'), 'enable', '');
    }

    public function disable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->disable($this->postValue('name')), t('Plugin disabled.'));
        $this->backToInstalled();
    }

    public function uninstall()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->uninstall($this->postValue('name')), t('Plugin removed.'));
        $this->backToInstalled();
    }

    public function install()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $target = $this->postValue('archive_url');

        // Legacy path: an install form that posts only archive_url (no name) can't
        // pre-flight deps — install directly, unchanged behavior.
        if ($name === '') {
            $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($target), t('Plugin installed.'));
            $this->backToDirectory();
            return;
        }
        $this->forwardOrConfirm($name, 'install', $target);
    }

    public function resolve()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $action = $this->postValue('action') === 'install' ? 'install' : 'enable';

        // Re-derive the plan + URLs server-side from a fresh catalog — never trust the post.
        $manager  = $this->manager();
        $catalog  = $this->catalog();
        $requires = $this->requiresFor($name, $action, $catalog, $manager);
        $check    = $manager->forwardCheck($requires, $catalog);
        $target   = $action === 'install' ? (string) ($catalog[$name]['download'] ?? '') : '';

        $this->runAndFlash(
            fn (PluginManager $m) => $m->resolveAndActivate($name, $action, $target, $check['plan']),
            $action === 'install' ? t('Plugin and its dependencies installed.') : t('Plugin and its dependencies enabled.')
        );
        $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
    }

    public function update()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = $this->postValue('archive_url');
        $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($url), t('Plugin updated.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    // ── dependency helpers ──────────────────────────────────────────────────

    private function catalog(): array
    {
        return (new DirectoryClient($this->container))->catalogMap();
    }

    private function requiresFor(string $name, string $action, array $catalog, PluginManager $manager): array
    {
        if ($action === 'install') {
            return $catalog[$name]['requires'] ?? [];
        }
        $deps = $manager->installedPluginsDeps();
        return $deps[$name]['requires'] ?? [];
    }

    /**
     * Forward gate for enable/install: act directly when satisfied, block when a
     * requirement is unresolvable, otherwise render the resolve-plan confirmation.
     */
    private function forwardOrConfirm(string $name, string $action, string $target): void
    {
        $manager  = $this->manager();
        $catalog  = $this->catalog();
        $requires = $this->requiresFor($name, $action, $catalog, $manager);

        // Resolve the target's own download URL when installing without one.
        if ($action === 'install' && $target === '') {
            $target = (string) ($catalog[$name]['download'] ?? '');
        }

        $check = $manager->forwardCheck($requires, $catalog);

        if ($check['satisfied']) {
            $this->runAndFlash(function (PluginManager $m) use ($name, $action, $target) {
                $action === 'install' ? $m->installFromUrl($target) : $m->enable($name);
            }, $action === 'install' ? t('Plugin installed.') : t('Plugin enabled.'));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        if ($check['blocked']) {
            $this->flash->failure(t('"%s" has requirements that cannot be installed automatically. Install them manually first.', $name));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        $this->response->html($this->helper->layout->config('ModMenu:plugin/resolve', [
            'title'  => t('ModMenu'),
            'name'   => $name,
            'action' => $action,
            'plan'   => $check['plan'],
        ]));
    }

    private function backToDirectory()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    /**
     * Read a value from the CSRF-validated POST body.
     *
     * ModMenu's mutating actions submit their values as POST form fields, so
     * they must be read via getValues() — NOT getStringParam(), which reads
     * ONLY the GET query string and returns '' for a posted field. (Kanboard's
     * core PluginController uses GET install links, which is why it can use
     * getStringParam; ModMenu uses POST forms for its mutations.) checkCSRFForm()
     * has already run and leaves the token in place, so getValues() returns the
     * filtered POST array. The value is already URL-decoded by PHP — applying
     * urldecode() would double-decode a literal '%' (e.g. '%20' in a
     * release-asset filename).
     */
    private function postValue(string $key): string
    {
        $values = $this->request->getValues();
        return isset($values[$key]) ? (string) $values[$key] : '';
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
