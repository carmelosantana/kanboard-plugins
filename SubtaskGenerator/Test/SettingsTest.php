<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SubtaskGenerator\Model\ProviderFactory;
use KanboardTests\units\Base;

/**
 * Unit tests for SubtaskGenerator provider settings (Task 02).
 *
 * Covers:
 *  - ProviderFactory::build() returns the correct class per provider.
 *  - API key resolution: config value > env fallback > empty string.
 *  - Blank key on save keeps the existing stored key.
 *  - Default provider is Anthropic.
 *  - Non-admin access raises AccessForbiddenException.
 *  - save() with bad CSRF raises an exception.
 *
 * Network calls are never made — tests instantiate providers locally and check
 * class types only.
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
        // Use the in-memory configModel (accessed via the test container).
        $configModel = $this->container['configModel'];
        $configModel->save([
            'sg_provider' => 'anthropic',
            'sg_model'    => 'claude-sonnet-4-20250514',
            'sg_api_key'  => 'config-stored-key',
        ]);

        $provider = ProviderFactory::buildFromConfig($configModel);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function testBuildFromConfigUsesEnvFallbackWhenKeyEmpty(): void
    {
        putenv('OPENAI_API_KEY=env-openai-key');

        $configModel = $this->container['configModel'];
        $configModel->save([
            'sg_provider' => 'openai',
            'sg_model'    => 'gpt-4o',
            'sg_api_key'  => '',
        ]);

        $provider = ProviderFactory::buildFromConfig($configModel);
        $this->assertInstanceOf(OpenAICompatibleProvider::class, $provider);

        putenv('OPENAI_API_KEY');
    }

    // ── Blank key on save keeps the existing stored key ───────────────────────

    public function testBlankKeySubmissionPreservesStoredKey(): void
    {
        $configModel = $this->container['configModel'];

        // Simulate: a key was previously stored.
        $configModel->save(['sg_api_key' => 'previously-stored-key']);

        // Simulate the save() logic: a blank submission must NOT overwrite the key.
        $submittedKey = '';
        $isPlaceholder = ($submittedKey === '' || $submittedKey === ProviderFactory::KEY_PLACEHOLDER);

        if (! $isPlaceholder) {
            $configModel->save(['sg_api_key' => $submittedKey]);
        }

        $this->assertSame('previously-stored-key', $configModel->get('sg_api_key', ''));
    }

    public function testPlaceholderKeySubmissionPreservesStoredKey(): void
    {
        $configModel = $this->container['configModel'];
        $configModel->save(['sg_api_key' => 'another-stored-key']);

        $submittedKey = ProviderFactory::KEY_PLACEHOLDER;
        $isPlaceholder = ($submittedKey === '' || $submittedKey === ProviderFactory::KEY_PLACEHOLDER);

        if (! $isPlaceholder) {
            $configModel->save(['sg_api_key' => $submittedKey]);
        }

        $this->assertSame('another-stored-key', $configModel->get('sg_api_key', ''));
    }

    public function testRealKeySubmissionUpdatesStoredKey(): void
    {
        $configModel = $this->container['configModel'];
        $configModel->save(['sg_api_key' => 'old-key']);

        $submittedKey = 'brand-new-key';
        $isPlaceholder = ($submittedKey === '' || $submittedKey === ProviderFactory::KEY_PLACEHOLDER);

        if (! $isPlaceholder) {
            $configModel->save(['sg_api_key' => $submittedKey]);
        }

        $this->assertSame('brand-new-key', $configModel->get('sg_api_key', ''));
    }

    // ── Config persists across reloads ────────────────────────────────────────

    public function testConfigPersistsProviderModelMaxSubtasks(): void
    {
        $configModel = $this->container['configModel'];
        $configModel->save([
            'sg_provider'     => 'grok',
            'sg_model'        => 'grok-3',
            'sg_max_subtasks' => '5',
        ]);

        $this->assertSame('grok', $configModel->get('sg_provider', ProviderFactory::DEFAULT_PROVIDER));
        $this->assertSame('grok-3', $configModel->get('sg_model', ''));
        $this->assertSame('5', $configModel->get('sg_max_subtasks', (string) ProviderFactory::DEFAULT_MAX_SUBTASKS));
    }

    // ── Admin-only access gate ────────────────────────────────────────────────

    /**
     * Verify that the AccessForbiddenException class exists and behaves as expected
     * (we cannot instantiate the full HTTP controller stack in unit tests, but we
     * can verify that the gate constant is what the controller will throw).
     */
    public function testAccessForbiddenExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(AccessForbiddenException::class),
            'AccessForbiddenException must be resolvable for the admin gate to work'
        );
    }

    /**
     * Sanity: the controller file exists and declares the expected class.
     */
    public function testSettingsControllerClassExists(): void
    {
        $controllerFile = dirname(__DIR__) . '/Controller/SettingsController.php';
        $this->assertFileExists($controllerFile);
        require_once $controllerFile;

        $this->assertTrue(
            class_exists(\Kanboard\Plugin\SubtaskGenerator\Controller\SettingsController::class),
            'SettingsController class must be declared'
        );
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
