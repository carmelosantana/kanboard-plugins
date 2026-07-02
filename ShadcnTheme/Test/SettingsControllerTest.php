<?php

/**
 * Unit tests for ShadcnTheme SettingsController.
 *
 * Covers:
 *  - show() throws AccessForbiddenException for non-admin callers
 *  - upload() throws AccessForbiddenException for non-admin callers
 *  - upload() throws AccessForbiddenException before CSRF even runs (non-admin gate first)
 *  - config values persist via configModel
 *
 * Run via: ./testing/run-plugin-tests.sh ShadcnTheme
 */

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Model\ConfigModel;
use Kanboard\Plugin\ShadcnTheme\Controller\SettingsController;

class SettingsControllerTest extends Base
{
    // ── Helper: stub userSession so isAdmin() returns false ───────────────────

    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $this->container['userSession']
            ->method('isAdmin')
            ->willReturn(false);
    }

    // ── show() ────────────────────────────────────────────────────────────────

    /**
     * show() must throw AccessForbiddenException for non-admin users.
     */
    public function testShowThrowsForNonAdmin()
    {
        $this->stubNonAdmin();

        $controller = new SettingsController($this->container);

        $this->expectException(AccessForbiddenException::class);
        $controller->show();
    }

    // ── upload() ─────────────────────────────────────────────────────────────

    /**
     * upload() must throw AccessForbiddenException for non-admin users
     * (admin gate runs before CSRF check).
     */
    public function testUploadThrowsForNonAdmin()
    {
        $this->stubNonAdmin();

        $controller = new SettingsController($this->container);

        $this->expectException(AccessForbiddenException::class);
        $controller->upload();
    }

    // ── configModel persistence ───────────────────────────────────────────────

    /**
     * configModel->save(['shadcn_logo_path' => ...]) persists via configModel->get().
     */
    public function testConfigPersistsLogoPath()
    {
        $configModel = new ConfigModel($this->container);
        $configModel->save(['shadcn_logo_path' => 'shadcn-theme/logo/abc123.png']);

        $this->assertSame(
            'shadcn-theme/logo/abc123.png',
            $configModel->get('shadcn_logo_path', '')
        );
    }

    /**
     * configModel->save(['shadcn_favicon_path' => ...]) persists via configModel->get().
     */
    public function testConfigPersistsFaviconPath()
    {
        $configModel = new ConfigModel($this->container);
        $configModel->save(['shadcn_favicon_path' => 'shadcn-theme/favicon/def456.ico']);

        $this->assertSame(
            'shadcn-theme/favicon/def456.ico',
            $configModel->get('shadcn_favicon_path', '')
        );
    }

    /**
     * Default is an empty string when no config value has been saved.
     */
    public function testConfigDefaultsToEmptyString()
    {
        $configModel = new ConfigModel($this->container);
        $this->assertSame('', $configModel->get('shadcn_logo_path', ''));
        $this->assertSame('', $configModel->get('shadcn_favicon_path', ''));
    }

    /**
     * Saving an empty string clears a previously set path.
     */
    public function testConfigClearsOnEmptyString()
    {
        $configModel = new ConfigModel($this->container);
        $configModel->save(['shadcn_logo_path' => 'shadcn-theme/logo/old.png']);
        $configModel->save(['shadcn_logo_path' => '']);

        $this->assertSame('', $configModel->get('shadcn_logo_path', ''));
    }
}
