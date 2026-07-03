<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Kanboard\Core\Base;

/**
 * ProviderFactory — builds a php-agents provider instance from plugin config.
 *
 * Key resolution order:
 *  1. Value stored in configModel['sg_api_key'] (set via admin UI).
 *  2. Environment variable: ANTHROPIC_API_KEY / OPENAI_API_KEY / XAI_API_KEY.
 *  3. Empty string (provider will fail at call time with a clear API error).
 *
 * API keys are NEVER logged. The placeholder constant is used to detect
 * "no change" form submissions without ever echoing the real key back.
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Model
 * @author  Carmelo Santana
 */
class ProviderFactory extends Base
{
    // ── Supported providers ───────────────────────────────────────────────────

    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GROK      = 'grok';

    /** Human-readable labels, keyed by provider identifier. */
    public const PROVIDERS = [
        self::PROVIDER_ANTHROPIC => 'Anthropic (Claude)',
        self::PROVIDER_OPENAI    => 'OpenAI',
        self::PROVIDER_GROK      => 'Grok (xAI)',
    ];

    // ── Defaults ──────────────────────────────────────────────────────────────

    public const DEFAULT_PROVIDER     = self::PROVIDER_ANTHROPIC;
    public const DEFAULT_MAX_SUBTASKS = 8;

    /** Sentinel returned in the masked key field — never persisted. */
    public const KEY_PLACEHOLDER = '••••••••';

    /** Default model IDs per provider. */
    public const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC => 'claude-sonnet-4-20250514',
        self::PROVIDER_OPENAI    => 'gpt-4o',
        self::PROVIDER_GROK      => 'grok-3',
    ];

    /** Environment variable names per provider. */
    public const ENV_VARS = [
        self::PROVIDER_ANTHROPIC => 'ANTHROPIC_API_KEY',
        self::PROVIDER_OPENAI    => 'OPENAI_API_KEY',
        self::PROVIDER_GROK      => 'XAI_API_KEY',
    ];

    // ── Public helpers ────────────────────────────────────────────────────────

    /**
     * Return the default model string for a given provider identifier.
     */
    public static function defaultModelFor(string $provider): string
    {
        return self::DEFAULT_MODELS[$provider] ?? self::DEFAULT_MODELS[self::DEFAULT_PROVIDER];
    }

    /**
     * Returns true when a provider is configured with a resolvable (non-empty) API key.
     *
     * "Configured" means: the selected provider has a non-empty key either from
     * the stored config (sg_api_key) or the relevant environment variable fallback
     * (ANTHROPIC_API_KEY / OPENAI_API_KEY / XAI_API_KEY). An unknown provider
     * string is treated as not configured.
     *
     * @param \Kanboard\Model\ConfigModel $configModel
     */
    public static function isProviderConfigured($configModel): bool
    {
        $provider = $configModel->get('sg_provider', self::DEFAULT_PROVIDER);

        // Unknown provider → not configured.
        if (! isset(self::PROVIDERS[$provider])) {
            return false;
        }

        $resolvedKey = self::resolveApiKey($provider, $configModel->get('sg_api_key', ''));

        return $resolvedKey !== '';
    }

    /**
     * Single source of truth: returns true when the runtime is fully ready for
     * AI-powered subtask generation.
     *
     * All three conditions must hold:
     *  1. PHP >= 8.4.0 (php-agents hard requirement).
     *  2. The plugin-local vendor/autoload.php is present (composer install done).
     *  3. A provider is configured — i.e. isProviderConfigured() returns true.
     *
     * This method is the ONLY gate that should be consulted for deciding whether
     * to show the sidebar link AND whether to honour controller requests. Both
     * Plugin::initialize() and GeneratorController::isAiEnabled() must delegate
     * to this method to guarantee identical behaviour.
     *
     * @param \Kanboard\Model\ConfigModel $configModel
     * @param int|null $phpVersionId PHP_VERSION_ID override (null = real constant; tests may inject).
     * @param string|null $autoloadPath vendor/autoload.php path override (null = real path; tests may inject).
     */
    public static function isAiReady($configModel, ?int $phpVersionId = null, ?string $autoloadPath = null): bool
    {
        $versionId    = $phpVersionId  ?? PHP_VERSION_ID;
        $autoload     = $autoloadPath  ?? __DIR__ . '/../vendor/autoload.php';

        return $versionId >= 80400
            && file_exists($autoload)
            && self::isProviderConfigured($configModel);
    }

    /**
     * Build a provider instance from a Kanboard configModel.
     *
     * Uses the sg_provider / sg_model / sg_api_key config keys, falling back
     * to environment variables for the API key when the config value is empty.
     *
     * @param  \Kanboard\Model\ConfigModel $configModel
     * @return AnthropicProvider|OpenAICompatibleProvider|XAIProvider
     * @throws \RuntimeException when an unknown provider is configured.
     */
    public static function buildFromConfig($configModel): AnthropicProvider|OpenAICompatibleProvider|XAIProvider
    {
        $provider = $configModel->get('sg_provider', self::DEFAULT_PROVIDER);
        $model    = $configModel->get('sg_model', self::defaultModelFor($provider));
        // Resolve once here (config value → env fallback → '').
        // Pass the resolved key directly to build() so it is not resolved again.
        $apiKey   = self::resolveApiKey($provider, $configModel->get('sg_api_key', ''));

        return match ($provider) {
            self::PROVIDER_ANTHROPIC => new AnthropicProvider(
                model: $model,
                apiKey: $apiKey,
            ),
            self::PROVIDER_OPENAI => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: 'https://api.openai.com/v1',
                apiKey: $apiKey,
            ),
            self::PROVIDER_GROK => new XAIProvider(
                model: $model,
                apiKey: $apiKey,
            ),
            default => throw new \RuntimeException(
                sprintf('[SubtaskGenerator] Unknown provider "%s". Supported: %s', $provider, implode(', ', array_keys(self::PROVIDERS)))
            ),
        };
    }

    /**
     * Build a provider instance directly from explicit values.
     *
     * If $apiKey is empty, falls back to the environment variable for the
     * provider. Call buildFromConfig() when you need config-then-env resolution;
     * call build() directly when you already have a resolved key (or want env
     * fallback only — i.e. no config model involved).
     *
     * @throws \RuntimeException when an unknown provider is requested.
     */
    public static function build(string $provider, string $model, string $apiKey = ''): AnthropicProvider|OpenAICompatibleProvider|XAIProvider
    {
        // Resolve env fallback only here (single resolution path).
        $apiKey = self::resolveApiKey($provider, $apiKey);

        return match ($provider) {
            self::PROVIDER_ANTHROPIC => new AnthropicProvider(
                model: $model,
                apiKey: $apiKey,
            ),
            self::PROVIDER_OPENAI => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: 'https://api.openai.com/v1',
                apiKey: $apiKey,
            ),
            self::PROVIDER_GROK => new XAIProvider(
                model: $model,
                apiKey: $apiKey,
            ),
            default => throw new \RuntimeException(
                sprintf('[SubtaskGenerator] Unknown provider "%s". Supported: %s', $provider, implode(', ', array_keys(self::PROVIDERS)))
            ),
        };
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the API key: use the stored value if non-empty, otherwise fall
     * back to the relevant environment variable.
     *
     * NEVER logs the returned key.
     */
    private static function resolveApiKey(string $provider, string $storedKey): string
    {
        if ($storedKey !== '') {
            return $storedKey;
        }

        $envVar = self::ENV_VARS[$provider] ?? null;
        if ($envVar !== null) {
            $envVal = getenv($envVar);
            if ($envVal !== false && $envVal !== '') {
                return $envVal;
            }
        }

        return '';
    }
}
