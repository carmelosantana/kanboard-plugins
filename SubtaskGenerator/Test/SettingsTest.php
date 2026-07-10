<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Controller\SettingsController;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator settings (sg_max_subtasks only — provider
 * configuration now lives entirely in the AiConnector plugin).
 *
 * Covers:
 *  - sg_max_subtasks save/persist via the real controller.
 *  - Non-admin access to show()/save() raises AccessForbiddenException.
 *  - CSRF token present in the settings template.
 *  - The template no longer exposes an sg_api_key field.
 *
 * IMPORTANT: SettingModel::save() calls $this->userSession->getId(), which
 * resolves and FREEZES the Pimple 'userSession' service. Any test that needs
 * to stub userSession must do so BEFORE calling configModel->save(). Tests that
 * only need config data and don't stub userSession use seedSetting() which
 * writes directly to the DB, bypassing SettingModel::save().
 */
class SettingsTest extends Base
{
    // ── Stubs ─────────────────────────────────────────────────────────────────

    /**
     * Stub userSession so isAdmin() returns false.
     *
     * Must be called BEFORE any configModel->save() / SettingModel::save()
     * call in the same test, or the service will already be frozen.
     */
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

    /**
     * Stub userSession so isAdmin() returns true, AND stub token + request so
     * CSRF validation always passes and getValues() returns $formValues.
     *
     * Must be called BEFORE any configModel->save() / SettingModel::save()
     * call in the same test, or the service will already be frozen.
     *
     * @param array $formValues  The POST values save() should see (no csrf_token needed).
     */
    private function stubAdminWithForm(array $formValues): void
    {
        // Admin gate.
        $this->container['userSession'] = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['isAdmin', 'getId'])
            ->getMock();

        $this->container['userSession']
            ->method('isAdmin')
            ->willReturn(true);

        // getId() is called by SettingModel::save() to record who changed the
        // setting — return a valid integer so it does not break DB constraints.
        $this->container['userSession']
            ->method('getId')
            ->willReturn(1);

        // CSRF: validateCSRFToken always returns true.
        $this->container['token'] = $this
            ->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['validateCSRFToken'])
            ->getMock();

        $this->container['token']
            ->method('validateCSRFToken')
            ->willReturn(true);

        // Request: getValues() returns the desired form payload;
        // getRawValue('csrf_token') returns a dummy string (checkCSRFForm uses it
        // before passing to validateCSRFToken, which we already stubbed).
        $this->container['request'] = $this
            ->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['getValues', 'getRawValue'])
            ->getMock();

        $this->container['request']
            ->method('getValues')
            ->willReturn($formValues);

        $this->container['request']
            ->method('getRawValue')
            ->willReturn('dummy-csrf-token');
    }

    /**
     * Write a setting directly to the DB, bypassing SettingModel::save().
     *
     * Use this instead of configModel->save() when userSession is already
     * stubbed (or when the test does not stub userSession at all), since
     * SettingModel::save() calls userSession->getId() which would freeze the
     * service before our mock is registered.
     */
    private function seedSetting(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        // Bust the in-memory config cache so configModel->get() re-reads from DB.
        $this->container['memoryCache']->flush();
    }

    // ── sg_max_subtasks save/persist (real controller) ────────────────────────

    public function testSaveUpdatesMaxSubtasks(): void
    {
        $this->stubAdminWithForm(['sg_max_subtasks' => '12']);

        $controller = new SettingsController($this->container);

        try {
            $controller->save();
        } catch (\Throwable $e) {
            // save() calls $this->response->redirect(), which exits / throws in
            // the test context — that is expected and does not indicate failure.
        }

        $this->assertSame(
            '12',
            $this->container['configModel']->get('sg_max_subtasks', ''),
            'save() must persist sg_max_subtasks'
        );
    }

    public function testSaveClampsMaxSubtasksToRange(): void
    {
        $this->stubAdminWithForm(['sg_max_subtasks' => '999']);

        $controller = new SettingsController($this->container);

        try {
            $controller->save();
        } catch (\Throwable $e) {
            // Redirect is expected.
        }

        $this->assertSame(
            '20',
            $this->container['configModel']->get('sg_max_subtasks', ''),
            'save() must clamp sg_max_subtasks to the 1..20 range'
        );
    }

    // ── Admin-only access gate (real non-admin tests) ─────────────────────────

    /**
     * show() must throw AccessForbiddenException for non-admin users.
     *
     * RED evidence: removing the `!$this->userSession->isAdmin()` guard from
     * show() causes this test to go RED (no exception is thrown and PHPUnit
     * reports "Failed asserting that exception … is thrown").
     */
    public function testShowThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();

        $controller = new SettingsController($this->container);

        $this->expectException(AccessForbiddenException::class);
        $controller->show();
    }

    /**
     * save() must throw AccessForbiddenException for non-admin users
     * (admin gate runs before CSRF check).
     *
     * RED evidence: removing the admin gate from save() causes this test to go
     * RED — the controller proceeds past the gate and either throws for CSRF or
     * returns normally, but NOT AccessForbiddenException.
     */
    public function testSaveThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();

        $controller = new SettingsController($this->container);

        $this->expectException(AccessForbiddenException::class);
        $controller->save();
    }

    // ── Config persists across reloads ────────────────────────────────────────

    public function testConfigPersistsMaxSubtasks(): void
    {
        // Use seedSetting (DB direct) so we don't trigger the userSession freeze.
        $this->seedSetting('sg_max_subtasks', '5');

        $configModel = $this->container['configModel'];
        $this->assertSame('5', $configModel->get('sg_max_subtasks', (string) SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS));
    }

    // ── CSRF: verify the form helper call exists in the template ─────────────

    public function testSettingsTemplateContainsCsrfToken(): void
    {
        $templateFile = dirname(__DIR__) . '/Template/config/settings.php';
        $this->assertFileExists($templateFile);

        $content = file_get_contents($templateFile);
        $this->assertStringContainsString('$this->form->csrf()', $content,
            'Settings template must include $this->form->csrf() to protect the form');
    }

    // ── API key field no longer exists in the trimmed template ───────────────

    public function testSettingsTemplateDoesNotEchoApiKeyValue(): void
    {
        $templateFile = dirname(__DIR__) . '/Template/config/settings.php';
        $content = file_get_contents($templateFile);

        $this->assertStringNotContainsString('name="sg_api_key"', $content,
            'The trimmed settings template must no longer contain an sg_api_key field — provider config moved to AiConnector');
    }
}
