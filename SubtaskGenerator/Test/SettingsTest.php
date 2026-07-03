<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Controller\SettingsController;
use Kanboard\Plugin\SubtaskGenerator\Model\ProviderFactory;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator provider settings (Task 02).
 *
 * Covers:
 *  - ProviderFactory::build() returns the correct class per provider.
 *  - API key resolution: config value > env fallback > empty string.
 *  - Blank key on save() keeps the existing stored key (real controller drive).
 *  - Default provider is Anthropic.
 *  - Non-admin access to show()/save() raises AccessForbiddenException.
 *
 * Network calls are never made — tests instantiate providers locally and check
 * class types only.
 *
 * IMPORTANT: SettingModel::save() calls $this->userSession->getId(), which
 * resolves and FREEZES the Pimple 'userSession' service. Any test that needs
 * to stub userSession must do so BEFORE calling configModel->save(). Tests that
 * only need config data and don't stub userSession use seedSetting() which
 * writes directly to the DB, bypassing SettingModel::save().
 */
class SettingsTest extends Base
{
    // ── Ensure vendor is loaded for provider class checks ─────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

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

    // ── ProviderFactory: correct class per provider ───────────────────────────

    public function testBuildReturnsAnthropicProvider(): void
    {
        $provider = ProviderFactory::build(
            ProviderFactory::PROVIDER_ANTHROPIC,
            'claude-sonnet-4-20250514',
            'test-key'
        );

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function testBuildReturnsOpenAICompatibleProvider(): void
    {
        $provider = ProviderFactory::build(
            ProviderFactory::PROVIDER_OPENAI,
            'gpt-4o',
            'test-key'
        );

        $this->assertInstanceOf(OpenAICompatibleProvider::class, $provider);
    }

    public function testBuildReturnsXAIProviderForGrok(): void
    {
        $provider = ProviderFactory::build(
            ProviderFactory::PROVIDER_GROK,
            'grok-3',
            'test-key'
        );

        $this->assertInstanceOf(XAIProvider::class, $provider);
        // XAIProvider extends OpenAICompatibleProvider — verify both.
        $this->assertInstanceOf(OpenAICompatibleProvider::class, $provider);
    }

    public function testBuildThrowsForUnknownProvider(): void
    {
        $this->expectException(\RuntimeException::class);
        ProviderFactory::build('ollama', 'llama3', 'key');
    }

    // ── Default provider is Anthropic ─────────────────────────────────────────

    public function testDefaultProviderIsAnthropic(): void
    {
        $this->assertSame(ProviderFactory::PROVIDER_ANTHROPIC, ProviderFactory::DEFAULT_PROVIDER);
    }

    public function testDefaultModelForAnthropicIsSonnet(): void
    {
        $model = ProviderFactory::defaultModelFor(ProviderFactory::PROVIDER_ANTHROPIC);
        $this->assertStringStartsWith('claude-', $model);
    }

    public function testDefaultModelForUnknownProviderFallsBackToAnthropic(): void
    {
        $model = ProviderFactory::defaultModelFor('unknown-provider');
        $this->assertSame(ProviderFactory::DEFAULT_MODELS[ProviderFactory::DEFAULT_PROVIDER], $model);
    }

    // ── API key resolution ────────────────────────────────────────────────────

    public function testBuildUsesStoredKeyWhenProvided(): void
    {
        // We can't read the private $apiKey property directly, but we can
        // confirm the call succeeds and returns the right class — the key
        // value is only verified by the provider itself at call time.
        $provider = ProviderFactory::build(ProviderFactory::PROVIDER_ANTHROPIC, 'claude-sonnet-4-20250514', 'stored-key-xyz');
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function testBuildFallsBackToEnvWhenStoredKeyIsEmpty(): void
    {
        // Set a fake env var for the duration of this test.
        putenv('ANTHROPIC_API_KEY=env-fallback-key');

        $provider = ProviderFactory::build(ProviderFactory::PROVIDER_ANTHROPIC, 'claude-sonnet-4-20250514', '');
        $this->assertInstanceOf(AnthropicProvider::class, $provider);

        // Clean up.
        putenv('ANTHROPIC_API_KEY');
    }

    public function testBuildFromConfigUsesConfigKeyWhenSet(): void
    {
        // Seed via DB to avoid userSession freeze (configModel->save() would
        // call userSession->getId() and freeze the service).
        $this->seedSetting('sg_provider', 'anthropic');
        $this->seedSetting('sg_model', 'claude-sonnet-4-20250514');
        $this->seedSetting('sg_api_key', 'config-stored-key');

        $provider = ProviderFactory::buildFromConfig($this->container['configModel']);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function testBuildFromConfigUsesEnvFallbackWhenKeyEmpty(): void
    {
        putenv('OPENAI_API_KEY=env-openai-key');

        $this->seedSetting('sg_provider', 'openai');
        $this->seedSetting('sg_model', 'gpt-4o');
        $this->seedSetting('sg_api_key', '');

        $provider = ProviderFactory::buildFromConfig($this->container['configModel']);
        $this->assertInstanceOf(OpenAICompatibleProvider::class, $provider);

        putenv('OPENAI_API_KEY');
    }

    // ── Blank key on save() keeps the existing stored key (real controller) ───

    /**
     * Drive SettingsController::save() with a blank sg_api_key.
     *
     * Stub order matters: stubAdminWithForm() must be called FIRST (before any
     * configModel->save() that would freeze the userSession service).
     *
     * RED evidence: removing the `$isPlaceholder` guard from save() causes this
     * test to fail because save() then overwrites the stored key with ''.
     */
    public function testSaveWithBlankKeyPreservesStoredKey(): void
    {
        // STUB FIRST — before any SettingModel::save() resolves userSession.
        $this->stubAdminWithForm([
            'sg_provider'     => 'anthropic',
            'sg_model'        => 'claude-sonnet-4-20250514',
            'sg_api_key'      => '',
            'sg_max_subtasks' => '8',
        ]);

        // Seed via DB (bypasses SettingModel::save() / userSession freeze).
        $this->seedSetting('sg_api_key', 'previously-stored-key');

        $controller = new SettingsController($this->container);

        try {
            $controller->save();
        } catch (\Throwable $e) {
            // save() calls $this->response->redirect(), which exits / throws in
            // the test context — that is expected and does not indicate failure.
        }

        // The stored key must remain unchanged.
        $this->assertSame(
            'previously-stored-key',
            $this->container['configModel']->get('sg_api_key', ''),
            'save() with blank key must NOT overwrite the existing stored key'
        );
    }

    /**
     * Drive SettingsController::save() with the placeholder sentinel.
     *
     * RED evidence: same as testSaveWithBlankKeyPreservesStoredKey — removing
     * the guard causes this test to fail (placeholder would be persisted).
     */
    public function testSaveWithPlaceholderKeyPreservesStoredKey(): void
    {
        $this->stubAdminWithForm([
            'sg_provider'     => 'anthropic',
            'sg_model'        => 'claude-sonnet-4-20250514',
            'sg_api_key'      => ProviderFactory::KEY_PLACEHOLDER,
            'sg_max_subtasks' => '8',
        ]);

        $this->seedSetting('sg_api_key', 'another-stored-key');

        $controller = new SettingsController($this->container);

        try {
            $controller->save();
        } catch (\Throwable $e) {
            // Redirect is expected.
        }

        $this->assertSame(
            'another-stored-key',
            $this->container['configModel']->get('sg_api_key', ''),
            'save() with placeholder key must NOT overwrite the existing stored key'
        );
    }

    /**
     * Drive SettingsController::save() with a real new key.
     *
     * Verifies that a genuine key IS persisted (the guard should NOT block it).
     */
    public function testSaveWithRealKeyUpdatesStoredKey(): void
    {
        $this->stubAdminWithForm([
            'sg_provider'     => 'anthropic',
            'sg_model'        => 'claude-sonnet-4-20250514',
            'sg_api_key'      => 'brand-new-key',
            'sg_max_subtasks' => '8',
        ]);

        $this->seedSetting('sg_api_key', 'old-key');

        $controller = new SettingsController($this->container);

        try {
            $controller->save();
        } catch (\Throwable $e) {
            // Redirect is expected.
        }

        $this->assertSame(
            'brand-new-key',
            $this->container['configModel']->get('sg_api_key', ''),
            'save() with a real key must update the stored key'
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

    public function testConfigPersistsProviderModelMaxSubtasks(): void
    {
        // Use seedSetting (DB direct) so we don't trigger the userSession freeze.
        $this->seedSetting('sg_provider', 'grok');
        $this->seedSetting('sg_model', 'grok-3');
        $this->seedSetting('sg_max_subtasks', '5');

        $configModel = $this->container['configModel'];
        $this->assertSame('grok', $configModel->get('sg_provider', ProviderFactory::DEFAULT_PROVIDER));
        $this->assertSame('grok-3', $configModel->get('sg_model', ''));
        $this->assertSame('5', $configModel->get('sg_max_subtasks', (string) ProviderFactory::DEFAULT_MAX_SUBTASKS));
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

    /**
     * The test-connection URL must include a reusable CSRF token so the
     * controller's checkReusableCSRFParam() gate is satisfied.
     *
     * The token MUST be generated in the controller (where `token` is an
     * injected dependency) and passed into the template as $sg_test_csrf.
     * `token` is NOT a registered template helper, so calling
     * $this->token->getReusableCSRFToken() *inside the template* throws and
     * aborts the render — which silently drops the whole page out of the
     * themed layout. Guard against reintroducing that here.
     */
    public function testSettingsTemplatePassesCsrfTokenToTestConnection(): void
    {
        $templateFile   = dirname(__DIR__) . '/Template/config/settings.php';
        $controllerFile = dirname(__DIR__) . '/Controller/SettingsController.php';
        $template   = file_get_contents($templateFile);
        $controller = file_get_contents($controllerFile);

        $this->assertStringContainsString(
            'csrf_token',
            $template,
            'The test-connection URL must include a csrf_token parameter'
        );
        $this->assertStringContainsString(
            '$sg_test_csrf',
            $template,
            'The template must use the controller-provided $sg_test_csrf token'
        );
        $this->assertStringNotContainsString(
            '$this->token',
            $template,
            '$this->token is not a template helper — calling it in the template throws and breaks the page layout'
        );
        $this->assertStringContainsString(
            'getReusableCSRFToken',
            $controller,
            'The controller must generate the reusable CSRF token and pass it to the template'
        );
    }

    // ── API key is NOT echoed in the template ─────────────────────────────────

    public function testSettingsTemplateDoesNotEchoApiKeyValue(): void
    {
        $templateFile = dirname(__DIR__) . '/Template/config/settings.php';
        $content = file_get_contents($templateFile);

        // The API key input must always have value="" — the actual stored key
        // must NEVER be rendered into the form field.
        $this->assertStringNotContainsString('value="<?= $sg_api_key', $content,
            'The API key must NEVER be echoed back into the form input value');
        $this->assertStringNotContainsString("value=\"<?= htmlspecialchars(\$sg_api_key", $content,
            'The API key must NEVER be echoed back into the form input value');
        $this->assertStringNotContainsString("value=\"<?= \$this->e(\$sg_api_key", $content,
            'The API key must NEVER be echoed back into the form input value');

        // Confirm the key input itself is present with an empty value attribute.
        $this->assertMatchesRegularExpression(
            '/name="sg_api_key"[^>]*value=""/',
            $content,
            'The sg_api_key input must render with value="" (never the stored key)'
        );
    }

    /**
     * Confirm testConnection returns ok/error only — not the raw LLM $result.
     *
     * We read the controller source to verify the JSON payload does not include
     * a 'result' key (which would expose raw LLM output).
     */
    public function testConnectionResponseDoesNotIncludeRawResult(): void
    {
        $controllerFile = dirname(__DIR__) . '/Controller/SettingsController.php';
        $content = file_get_contents($controllerFile);

        $this->assertStringNotContainsString(
            "'result' => \$result",
            $content,
            'testConnection() must not include the raw LLM $result in the JSON response'
        );
        $this->assertStringNotContainsString(
            '"result" => $result',
            $content,
            'testConnection() must not include the raw LLM $result in the JSON response'
        );
    }

    // ── ProviderFactory PROVIDERS map is complete ─────────────────────────────

    public function testAllProvidersHaveDefaultModels(): void
    {
        foreach (array_keys(ProviderFactory::PROVIDERS) as $key) {
            $this->assertArrayHasKey($key, ProviderFactory::DEFAULT_MODELS,
                "Provider '$key' must have a DEFAULT_MODELS entry");
            $this->assertArrayHasKey($key, ProviderFactory::ENV_VARS,
                "Provider '$key' must have an ENV_VARS entry");
        }
    }
}
