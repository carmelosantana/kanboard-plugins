<?php

namespace Kanboard\Plugin\AiConnector\Model;

use Kanboard\Core\Base;

/**
 * ProviderRegistry — the public PHP API other plugins consume for AI provider access.
 *
 * Consumers instantiate directly: new ProviderRegistry($this->container). Reads
 * named provider profiles + separately-stored API keys from configModel and
 * builds/uses php-agents providers.
 *
 * php-agents timing: every CarmeloSantana\PHPAgents\* reference lives inside a
 * method body reached only at request time (buildProvider/structured). Merely
 * loading this class (e.g. class_exists from a consumer's initialize()) does NOT
 * require the php-agents autoloader — PHP resolves method type-hints lazily.
 * isReady()/listProfiles()/getDefaultProfileId() touch no php-agents class.
 *
 * Secrets: resolveKey() NEVER logs; keys never enter the profiles JSON, an
 * exception message, or any response.
 *
 * @package Kanboard\Plugin\AiConnector\Model
 * @author  Carmelo Santana
 */
class ProviderRegistry extends Base
{
    // ── Provider types ────────────────────────────────────────────────────────
    public const PROVIDER_ANTHROPIC        = 'anthropic';
    public const PROVIDER_OPENAI           = 'openai';
    public const PROVIDER_OPENAI_RESPONSES = 'openai_responses';
    public const PROVIDER_GROK             = 'grok';
    public const PROVIDER_GEMINI           = 'gemini';
    public const PROVIDER_MISTRAL          = 'mistral';
    public const PROVIDER_OLLAMA           = 'ollama';

    /** Human labels, keyed by provider type. Also defines the supported set. */
    public const PROVIDERS = [
        self::PROVIDER_ANTHROPIC        => 'Anthropic (Claude)',
        self::PROVIDER_OPENAI           => 'OpenAI (Chat Completions)',
        self::PROVIDER_OPENAI_RESPONSES => 'OpenAI Responses (Codex / gpt-5)',
        self::PROVIDER_GROK             => 'Grok (xAI)',
        self::PROVIDER_GEMINI           => 'Google Gemini',
        self::PROVIDER_MISTRAL          => 'Mistral',
        self::PROVIDER_OLLAMA           => 'Ollama (local, keyless)',
    ];

    /** Default model id per provider type (placeholder/prefill only). */
    public const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC        => 'claude-sonnet-4-20250514',
        self::PROVIDER_OPENAI           => 'gpt-4o',
        self::PROVIDER_OPENAI_RESPONSES => 'gpt-5',
        self::PROVIDER_GROK             => 'grok-3',
        self::PROVIDER_GEMINI           => 'gemini-2.5-flash',
        self::PROVIDER_MISTRAL          => 'mistral-large-latest',
        self::PROVIDER_OLLAMA           => 'llama3.2',
    ];

    /** Env-var fallback per provider type. Ollama is keyless (absent). */
    public const ENV_VARS = [
        self::PROVIDER_ANTHROPIC        => 'ANTHROPIC_API_KEY',
        self::PROVIDER_OPENAI           => 'OPENAI_API_KEY',
        self::PROVIDER_OPENAI_RESPONSES => 'OPENAI_API_KEY',
        self::PROVIDER_GROK             => 'XAI_API_KEY',
        self::PROVIDER_GEMINI           => 'GEMINI_API_KEY',
        self::PROVIDER_MISTRAL          => 'MISTRAL_API_KEY',
    ];

    /** Provider types that need no API key. */
    public const KEYLESS = [self::PROVIDER_OLLAMA];

    /** Default base URL per provider type (empty = use php-agents class default). */
    public const DEFAULT_BASE_URLS = [
        self::PROVIDER_OPENAI => 'https://api.openai.com/v1',
        self::PROVIDER_OLLAMA => 'http://localhost:11434/v1',
    ];

    /** Config keys + masking sentinel. */
    public const PROFILES_KEY   = 'aiconnector_profiles';
    public const DEFAULT_KEY    = 'aiconnector_default';
    public const KEY_PREFIX     = 'aiconnector_key_';
    public const KEY_PLACEHOLDER = '••••••••';

    // ── Profile reads ─────────────────────────────────────────────────────────

    /**
     * Full profile structs incl. base_url. Order = storage order.
     *
     * @return array<int, array{id:string,label:string,provider:string,model:string,base_url:string}>
     */
    public function getProfiles(): array
    {
        $raw = $this->configModel->get(self::PROFILES_KEY, '');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $p) {
            if (! is_array($p) || ! isset($p['id'], $p['provider'])) {
                continue;
            }
            $out[] = [
                'id'       => (string) $p['id'],
                'label'    => (string) ($p['label'] ?? $p['id']),
                'provider' => (string) $p['provider'],
                'model'    => (string) ($p['model'] ?? ''),
                'base_url' => (string) ($p['base_url'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Profiles for dropdowns — id/label/provider/model only (NO key, NO base_url).
     *
     * @return array<int, array{id:string,label:string,provider:string,model:string}>
     */
    public function listProfiles(): array
    {
        $out = [];
        foreach ($this->getProfiles() as $p) {
            $out[] = [
                'id'       => $p['id'],
                'label'    => $p['label'],
                'provider' => $p['provider'],
                'model'    => $p['model'],
            ];
        }
        return $out;
    }

    /** The default profile id, or '' when none / dangling. */
    public function getDefaultProfileId(): string
    {
        $id = (string) $this->configModel->get(self::DEFAULT_KEY, '');
        if ($id === '') {
            return '';
        }
        return $this->findProfile($id) !== null ? $id : '';
    }

    /** One full profile struct, or null. */
    public function findProfile(string $id): ?array
    {
        foreach ($this->getProfiles() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** Whether aiconnector_key_<id> is non-empty. */
    public function hasStoredKey(string $id): bool
    {
        return (string) $this->configModel->get(self::KEY_PREFIX . $id, '') !== '';
    }

    /**
     * True when ≥1 profile has a resolvable key (stored/env) or is keyless (ollama).
     * No network call.
     */
    public function isReady(): bool
    {
        foreach ($this->getProfiles() as $p) {
            if (in_array($p['provider'], self::KEYLESS, true)) {
                return true;
            }
            $stored = (string) $this->configModel->get(self::KEY_PREFIX . $p['id'], '');
            if ($this->resolveKey($p['provider'], $stored) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve an API key: stored (if non-empty) → env var for the provider type → ''.
     * NEVER logs the returned value.
     */
    public function resolveKey(string $providerType, string $storedKey): string
    {
        if ($storedKey !== '') {
            return $storedKey;
        }
        $envVar = self::ENV_VARS[$providerType] ?? null;
        if ($envVar !== null) {
            $val = getenv($envVar);
            if ($val !== false && $val !== '') {
                return $val;
            }
        }
        return '';
    }
}
