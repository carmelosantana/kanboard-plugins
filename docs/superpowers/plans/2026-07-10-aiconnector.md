# AiConnector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the AI-provider backend out of SubtaskGenerator into a standalone `AiConnector` plugin that owns php-agents + all provider config and exposes a thin `ProviderRegistry` PHP API; refactor SubtaskGenerator to consume it and hard-`requires` it.

**Architecture:** `AiConnector` stores named provider profiles (id/label/provider/model/base_url) as JSON in config, with API keys stored separately + masked + env-fallback. `ProviderRegistry` (extends `Kanboard\Core\Base`) exposes `listProfiles/getDefaultProfileId/isReady/buildProvider/structured`; `structured()` maps a provider-agnostic message array to php-agents messages, calls the provider, and normalizes both php-agents return shapes (array | `Response`) to a decoded PHP array. SubtaskGenerator's model calls `ProviderRegistry::structured()` and keeps only its normalize/dedupe/clamp logic; its gate asks `ProviderRegistry::isReady()`.

**Tech Stack:** PHP ≥ 8.4, Kanboard v1.2.47 plugin API (buildless), php-agents (`carmelosantana/php-agents:dev-main` via Composer path repo), PHPUnit via `testing/run-plugin-tests.sh` (in-memory SQLite core), Docker suite on `:8081`.

**Design spec:** `docs/superpowers/specs/2026-07-10-aiconnector-design.md` (read it — every §N reference below points there).

## Global Constraints

- **Kanboard** ≥ 1.2.47; **PHP** ≥ 8.4; **buildless** (no compile step, no CDN); **MIT**; author "Carmelo Santana"; homepage `https://github.com/carmelosantana/kanboard-plugins`.
- **`getCompatibleVersion()` returns `>=1.2.47`.** `PluginTest` asserts that string + plugin **name** — NEVER a hardcoded plugin *version* (that assertion has gone stale before; do not add it).
- **CSP** (`default-src 'self'`): no inline `<script>`, no inline event handlers; inline `<style>`/`style=""` allowed. All JS in external `Assets/js/*.js`, injected via `template:layout:js`.
- **CSRF:** form posts → `$this->checkCSRFForm()` (forms carry `$this->form->csrf()`). Fetch/JS endpoints → reusable token `$this->token->getReusableCSRFToken()` generated **in the controller**, passed via `data-*`, validated with `$this->checkReusableCSRFParam()`. NEVER call `$this->token->...` inside a template (not a registered helper → throws mid-render → page drops out of layout).
- **Authorization:** all mutating settings actions + Test Connection are **admin-only** (`if (! $this->userSession->isAdmin()) throw new AccessForbiddenException();`), each also CSRF-gated. Escape all template output.
- **Secrets:** never log/echo/persist-empty an API key; mask stored keys (`••••••••`); never put a key in a profiles JSON, an exception message, or a JSON response.
- **php-agents load-order rule (HARD):** ONLY AiConnector bundles php-agents. `AiConnector::initialize()` does `require_once __DIR__.'/vendor/autoload.php'` guarded with `file_exists`. NEVER reference a `CarmeloSantana\PHPAgents\*` class inside ANY plugin's `initialize()` — only at request-handling time (controller/model during a route). Kanboard gives no cross-plugin init-order guarantee.
- **Unit tests never hit the network** — inject a fake `ProviderInterface`/registry. The harness does NOT run the plugin loader; load `vendor/autoload.php` in `setUpBeforeClass()` when a test needs php-agents classes.
- **Host-side edits only.** NEVER write into the running container via `docker exec ... sed/tee`. Make all edits with normal file tools.
- **Plugin `vendor/` is git-tracked** (SubtaskGenerator commits 265 vendor files). Commit AiConnector's `vendor/`; `git rm` SubtaskGenerator's during the refactor.

## Reference: verified core & php-agents APIs (use exactly these)

- **Cross-plugin instantiation:** `new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container)`. Kanboard auto-registers each plugin's `Kanboard\Plugin\<Name>\` PSR-4 namespace regardless of init order, so the class is autoloadable whenever `AiConnector/` exists on disk.
- **Presence check (safe in `initialize()`):** `class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class)` — loads the class file but NOT php-agents (type-hints resolve lazily at method-call time).
- **Config:** `$this->configModel->get($name, $default)`, `$this->configModel->save(['k' => 'v'])`.
- **Layout for a config page:** `$this->helper->layout->config('AiConnector:config/settings', [...])`.
- **Routes:** `$this->route->addRoute('ai-connector/settings', 'SettingsController', 'show')` (3-arg form; controller resolved within the plugin).
- **Reusable CSRF:** controller `$this->token->getReusableCSRFToken()` → pass into template → build URL with `csrf_token` param → endpoint calls `$this->checkReusableCSRFParam()`.
- **php-agents providers (verified constructor signatures — named args):**
  - `new \CarmeloSantana\PHPAgents\Provider\AnthropicProvider(model: $m, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider(model: $m, baseUrl: $b, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider(model: $m, baseUrl: $b, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\XAIProvider(model: $m, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\GeminiProvider(model: $m, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\MistralProvider(model: $m, apiKey: $k)`
  - `new \CarmeloSantana\PHPAgents\Provider\OllamaProvider(model: $m, baseUrl: $b)` — **NO apiKey param** (keyless)
- **php-agents messages:** `new \CarmeloSantana\PHPAgents\Message\SystemMessage($str)`, `...\UserMessage($str)`, `...\AssistantMessage($str)`.
- **php-agents structured contract:** `ProviderInterface::structured(array $messages, string $schema, array $options = []): mixed` → returns either a decoded PHP `array` (Anthropic tool_use / OpenAIResponses) OR a `\CarmeloSantana\PHPAgents\Provider\Response` whose `->content` is a JSON string (openai/grok/gemini/mistral/ollama).
- **`Response` construction in tests:** `new \CarmeloSantana\PHPAgents\Provider\Response(content: $json, finishReason: \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop)`.

## Test workflow (every task)

From the repo root:
```bash
./testing/run-plugin-tests.sh AiConnector       # after Task 0 adds it to the harness
./testing/run-plugin-tests.sh SubtaskGenerator
```
Runs PHPUnit against in-memory SQLite Kanboard v1.2.47 core. The bootstrap registers every plugin's PSR-4 namespace, so `Kanboard\Plugin\AiConnector\Model\ProviderRegistry` is available inside SubtaskGenerator's test run too (both plugins are symlinked in). Tests must never make a network call — always inject a fake provider/registry.

---

## Task 0: Scaffold AiConnector, build vendor, wire the harness

**Files:**
- Create: `AiConnector/plugin.json`
- Create: `AiConnector/composer.json`
- Create: `AiConnector/Plugin.php`
- Create: `AiConnector/LICENSE` (copy `SubtaskGenerator/LICENSE` verbatim)
- Create: `AiConnector/Test/PluginTest.php`
- Generate (via `composer install`): `AiConnector/vendor/**`
- Modify: `testing/docker-compose.dev.yml` (add the mount)
- Modify: `testing/run-plugin-tests.sh` (add `AiConnector` to both lists)

**Interfaces:**
- Produces: `Kanboard\Plugin\AiConnector\Plugin` with `getPluginName()='AiConnector'`, `getPluginVersion()='1.0.0'`, `getCompatibleVersion()='>=1.2.47'`, `getPluginHomepage()`, `getPluginAuthor()='Carmelo Santana'`, `getPluginDescription()`. `initialize(): void` guards-loads `vendor/autoload.php` and registers routes/hooks (routes/hooks added in Task 3/4 — a minimal `initialize()` that only loads vendor is fine to start).

- [ ] **Step 1: Write `AiConnector/composer.json`** — copy `SubtaskGenerator/composer.json` verbatim, changing only `name` and `description`:

```json
{
    "name": "carmelosantana/kanboard-ai-connector",
    "description": "Kanboard plugin — universal multi-provider AI backend (php-agents) with named provider profiles and a shared PHP API",
    "type": "kanboard-plugin",
    "license": "MIT",
    "authors": [
        {
            "name": "Carmelo Santana",
            "email": "carmelo@vctrs.io"
        }
    ],
    "require": {
        "php": ">=8.4",
        "carmelosantana/php-agents": "dev-main"
    },
    "replace": {
        "psr/log": "*",
        "psr/container": "*"
    },
    "repositories": [
        {
            "type": "path",
            "url": "/home/carmelo/Projects/CoquiBot/Core/php-agents",
            "options": {
                "symlink": false
            }
        }
    ],
    "config": {
        "optimize-autoloader": true,
        "vendor-dir": "vendor"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write `AiConnector/plugin.json`:**

```json
{
    "name": "AiConnector",
    "description": "Universal multi-provider AI backend (php-agents) with provider profiles and a shared PHP API.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "homepage": "https://github.com/carmelosantana/kanboard-plugins",
    "license": "MIT",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

- [ ] **Step 3: Write `AiConnector/Plugin.php`** (minimal; routes/hooks land in Tasks 3–4):

```php
<?php

namespace Kanboard\Plugin\AiConnector;

use Kanboard\Core\Plugin\Base;

/**
 * AiConnector — universal multi-provider AI backend for the Kanboard suite.
 *
 * Owns php-agents (bundled in vendor/) and all provider configuration; exposes
 * the ProviderRegistry PHP API that other plugins consume. See
 * docs/superpowers/specs/2026-07-10-aiconnector-design.md.
 *
 * php-agents load-order rule (HARD): this initialize() require_once's the
 * plugin-local vendor/autoload.php. No CarmeloSantana\PHPAgents\* class is ever
 * referenced here at init time — only at request-handling time in the
 * controller/model, because Kanboard gives no cross-plugin init-order guarantee.
 */
class Plugin extends Base
{
    public function initialize(): void
    {
        // Load the plugin-local vendor autoload (php-agents + deps).
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            error_log('[AiConnector] vendor/autoload.php not found — run composer install inside the plugin directory.');
        }

        // Routes + hooks are registered in later tasks (settings UI, test connection).
    }

    public function getPluginName(): string
    {
        return 'AiConnector';
    }

    public function getPluginDescription(): string
    {
        return t('Universal multi-provider AI backend (php-agents) with provider profiles and a shared PHP API.');
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-plugins';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
```

- [ ] **Step 4: Copy the license** — copy `SubtaskGenerator/LICENSE` to `AiConnector/LICENSE` verbatim.

- [ ] **Step 5: Build vendor/** — run from the plugin dir:

Run: `composer install -d /home/carmelo/Projects/Kanboard/kanboard-plugins/AiConnector`
Expected: `vendor/autoload.php` created; `vendor/carmelosantana/php-agents/` present. Verify:
```bash
ls AiConnector/vendor/carmelosantana/php-agents/src/Provider/AnthropicProvider.php
```
Expected: the path exists.

- [ ] **Step 6: Wire the docker dev harness** — in `testing/docker-compose.dev.yml`, add this line to the `volumes:` block alongside the other 8 plugin mounts:

```yaml
      - ../AiConnector:/var/www/app/plugins/AiConnector
```

- [ ] **Step 7: Wire the test harness** — in `testing/run-plugin-tests.sh`, add `AiConnector` to BOTH plugin lists:
  - Line ~34 usage echo: `echo "Available plugins: AiConnector  BulkProjectDelete  CalendarPlugin  DependencyPlugin  FeatureSync  ModMenu  SchedulerPlugin  ShadcnTheme  SubtaskGenerator"`
  - Line ~78 symlink loop: `for p in AiConnector BulkProjectDelete CalendarPlugin DependencyPlugin FeatureSync ModMenu SchedulerPlugin ShadcnTheme SubtaskGenerator; do`

- [ ] **Step 8: Write `AiConnector/Test/PluginTest.php`:**

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Plugin;
use KanboardTests\units\Base;

/**
 * Smoke tests for the AiConnector Plugin.
 *
 * Run from the repo root:
 *   ./testing/run-plugin-tests.sh AiConnector
 */
class PluginTest extends Base
{
    public function testPluginMetadata(): void
    {
        $plugin = new Plugin($this->container);

        $this->assertSame('AiConnector', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
        $this->assertSame('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertNotEmpty($plugin->getPluginHomepage());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testVendorAutoloadExists(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists(
            $autoload,
            'vendor/autoload.php must exist — run composer install inside AiConnector/'
        );
    }

    public function testProviderClassesResolve(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        foreach ([
            'AnthropicProvider',
            'OpenAICompatibleProvider',
            'OpenAIResponsesProvider',
            'XAIProvider',
            'GeminiProvider',
            'MistralProvider',
            'OllamaProvider',
        ] as $cls) {
            $this->assertTrue(
                class_exists('CarmeloSantana\\PHPAgents\\Provider\\' . $cls),
                "$cls must resolve after loading vendor/autoload.php"
            );
        }
    }
}
```

- [ ] **Step 9: Run the suite**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS (3 tests). If `testProviderClassesResolve` fails, `composer install` did not vendor php-agents — re-run Step 5.

- [ ] **Step 10: Commit**

```bash
git add AiConnector/plugin.json AiConnector/composer.json AiConnector/composer.lock AiConnector/Plugin.php AiConnector/LICENSE AiConnector/Test/PluginTest.php AiConnector/vendor testing/docker-compose.dev.yml testing/run-plugin-tests.sh
git commit -m "feat(AiConnector): scaffold plugin + php-agents vendor + harness wiring"
```

---

## Task 1: ProviderRegistry — profile reads, key resolution, isReady

**Files:**
- Create: `AiConnector/Model/ProviderRegistry.php` (profile-read + constants portion; provider-build portion added in Task 2)
- Test: `AiConnector/Test/ProviderRegistryReadTest.php`

**Interfaces:**
- Consumes: `configModel` from `Kanboard\Core\Base`.
- Produces (public API used by Task 2, Task 3, and SubtaskGenerator):
  - `const PROVIDERS` (map providerType→label), `const DEFAULT_MODELS`, `const ENV_VARS`, `const KEY_PLACEHOLDER='••••••••'`, config-key constants `PROFILES_KEY='aiconnector_profiles'`, `DEFAULT_KEY='aiconnector_default'`, `KEY_PREFIX='aiconnector_key_'`.
  - `listProfiles(): array` → `[['id'=>string,'label'=>string,'provider'=>string,'model'=>string], ...]` (NO key, NO base_url).
  - `getDefaultProfileId(): string`.
  - `isReady(): bool`.
  - `getProfiles(): array` — full structs incl. `base_url` (used internally + by SettingsController to render the list/edit form). Returns `[['id','label','provider','model','base_url'], ...]`.
  - `findProfile(string $id): ?array` — one full struct or null.
  - `resolveKey(string $providerType, string $storedKey): string` — stored → env → `''` (public so SettingsController/Task 2 reuse it; NEVER logs).
  - `hasStoredKey(string $id): bool` — whether `aiconnector_key_<id>` is non-empty (for the "key is stored" UI note).

- [ ] **Step 1: Write the failing test** `AiConnector/Test/ProviderRegistryReadTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * Task 1 — profile reads, key resolution, isReady. No network.
 */
class ProviderRegistryReadTest extends Base
{
    /** Write a setting directly to the DB (bypasses SettingModel::save/userSession freeze). */
    private function seed(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    private function seedProfiles(array $profiles, string $default = ''): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode($profiles));
        $this->seed(ProviderRegistry::DEFAULT_KEY, $default);
    }

    private function registry(): ProviderRegistry
    {
        return new ProviderRegistry($this->container);
    }

    private function clearEnv(): void
    {
        foreach (['ANTHROPIC_API_KEY','OPENAI_API_KEY','XAI_API_KEY','GEMINI_API_KEY','MISTRAL_API_KEY'] as $v) {
            putenv($v . '=');
        }
    }

    public function testListProfilesEmptyWhenNoneConfigured(): void
    {
        $this->assertSame([], $this->registry()->listProfiles());
    }

    public function testListProfilesOmitsKeysAndBaseUrl(): void
    {
        $this->seedProfiles([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514', 'base_url' => ''],
        ], 'p1');

        $list = $this->registry()->listProfiles();
        $this->assertCount(1, $list);
        $this->assertSame(['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'], $list[0]);
        $this->assertArrayNotHasKey('base_url', $list[0]);
        $this->assertArrayNotHasKey('key', $list[0]);
    }

    public function testGetDefaultProfileId(): void
    {
        $this->assertSame('', $this->registry()->getDefaultProfileId());

        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->assertSame('p1', $this->registry()->getDefaultProfileId());
    }

    public function testGetDefaultProfileIdReturnsEmptyWhenDangling(): void
    {
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'ghost');
        $this->assertSame('', $this->registry()->getDefaultProfileId());
    }

    public function testIsReadyFalseWhenNoProfiles(): void
    {
        $this->clearEnv();
        $this->assertFalse($this->registry()->isReady());
    }

    public function testIsReadyFalseWhenProfileHasNoKeyAndNoEnv(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->assertFalse($this->registry()->isReady());
    }

    public function testIsReadyTrueWithStoredKey(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => '']], 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'sk-stored');
        $this->assertTrue($this->registry()->isReady());
    }

    public function testIsReadyTrueViaEnvFallback(): void
    {
        $this->clearEnv();
        putenv('OPENAI_API_KEY=env-key');
        $this->seedProfiles([['id' => 'p1', 'label' => 'O', 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => '']], 'p1');
        $this->assertTrue($this->registry()->isReady());
        putenv('OPENAI_API_KEY=');
    }

    public function testIsReadyTrueForKeylessOllama(): void
    {
        $this->clearEnv();
        $this->seedProfiles([['id' => 'p1', 'label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.2', 'base_url' => '']], 'p1');
        $this->assertTrue($this->registry()->isReady());
    }

    public function testResolveKeyPrefersStoredThenEnvThenEmpty(): void
    {
        $this->clearEnv();
        $r = $this->registry();
        $this->assertSame('stored', $r->resolveKey('anthropic', 'stored'));

        putenv('ANTHROPIC_API_KEY=envk');
        $this->assertSame('envk', $r->resolveKey('anthropic', ''));
        putenv('ANTHROPIC_API_KEY=');

        $this->assertSame('', $r->resolveKey('anthropic', ''));
    }

    public function testFindProfileReturnsFullStructOrNull(): void
    {
        $this->seedProfiles([['id' => 'p1', 'label' => 'A', 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://x/v1']], 'p1');
        $r = $this->registry();
        $p = $r->findProfile('p1');
        $this->assertSame('https://x/v1', $p['base_url']);
        $this->assertNull($r->findProfile('nope'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `Class "Kanboard\Plugin\AiConnector\Model\ProviderRegistry" not found`.

- [ ] **Step 3: Write `AiConnector/Model/ProviderRegistry.php`** (Task 1 portion — provider-build methods added in Task 2):

```php
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS (Task 0 + Task 1 tests).

- [ ] **Step 5: Commit**

```bash
git add AiConnector/Model/ProviderRegistry.php AiConnector/Test/ProviderRegistryReadTest.php
git commit -m "feat(AiConnector): ProviderRegistry profile reads + key resolution + isReady"
```

---

## Task 2: ProviderRegistry — buildProvider (all 7 types) + structured() normalization

**Files:**
- Modify: `AiConnector/Model/ProviderRegistry.php` (add build + structured + normalize + test seam)
- Test: `AiConnector/Test/ProviderRegistryBuildTest.php`

**Interfaces:**
- Consumes: everything from Task 1 (constants, `getProfiles`, `findProfile`, `resolveKey`, `getDefaultProfileId`).
- Produces:
  - `buildProvider(?string $profileId = null): \CarmeloSantana\PHPAgents\Contract\ProviderInterface` — throws `\RuntimeException` (no key in message) on null-and-no-default / unknown id / unsupported provider type.
  - `structured(array $messages, string $schema, ?string $profileId = null): array` — `$messages` = `[['role'=>'system'|'user'|'assistant','content'=>string], ...]`; returns a decoded PHP array (`[]` on null/garbage).
  - `setProviderForTesting(\CarmeloSantana\PHPAgents\Contract\ProviderInterface $p): void` — test seam; when set, `structured()`/`buildProvider()` use it instead of building from config.

- [ ] **Step 1: Write the failing test** `AiConnector/Test/ProviderRegistryBuildTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * Task 2 — buildProvider (all 7 types) + structured() normalization. No network.
 */
class ProviderRegistryBuildTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    private function seed(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    private function seedOne(string $provider, string $model, string $key = 'k', string $baseUrl = ''): ProviderRegistry
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'P', 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        if ($key !== '') {
            $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', $key);
        }
        return new ProviderRegistry($this->container);
    }

    public function testBuildEachProviderType(): void
    {
        $cases = [
            [ProviderRegistry::PROVIDER_ANTHROPIC,        'claude-sonnet-4-20250514', AnthropicProvider::class],
            [ProviderRegistry::PROVIDER_OPENAI,           'gpt-4o',                   OpenAICompatibleProvider::class],
            [ProviderRegistry::PROVIDER_OPENAI_RESPONSES, 'gpt-5',                    OpenAIResponsesProvider::class],
            [ProviderRegistry::PROVIDER_GROK,             'grok-3',                   XAIProvider::class],
            [ProviderRegistry::PROVIDER_GEMINI,           'gemini-2.5-flash',         GeminiProvider::class],
            [ProviderRegistry::PROVIDER_MISTRAL,          'mistral-large-latest',     MistralProvider::class],
            [ProviderRegistry::PROVIDER_OLLAMA,           'llama3.2',                 OllamaProvider::class],
        ];
        foreach ($cases as [$type, $model, $class]) {
            $r = $this->seedOne($type, $model, $type === 'ollama' ? '' : 'k');
            $this->assertInstanceOf($class, $r->buildProvider(), "provider type $type must build $class");
        }
    }

    public function testBuildDefaultsToDefaultProfileWhenIdNull(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_ANTHROPIC, 'm', 'k');
        $this->assertInstanceOf(AnthropicProvider::class, $r->buildProvider(null));
    }

    public function testBuildThrowsWhenNoProfilesAndNoDefault(): void
    {
        $r = new ProviderRegistry($this->container);
        $this->expectException(\RuntimeException::class);
        $r->buildProvider();
    }

    public function testBuildThrowsOnUnknownIdWithoutLeakingKey(): void
    {
        $r = $this->seedOne(ProviderRegistry::PROVIDER_ANTHROPIC, 'm', 'super-secret-key');
        try {
            $r->buildProvider('ghost');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('super-secret-key', $e->getMessage());
        }
    }

    public function testBuildThrowsOnUnsupportedProviderType(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'X', 'provider' => 'llamacpp', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $r = new ProviderRegistry($this->container);
        $this->expectException(\RuntimeException::class);
        $r->buildProvider('p1');
    }

    // ── structured() normalization via injected fake provider ─────────────────

    private function fakeProviderReturning(mixed $value): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('structured')->willReturn($value);
        return $mock;
    }

    private function registryWithFake(ProviderInterface $p): ProviderRegistry
    {
        $r = new ProviderRegistry($this->container);
        $r->setProviderForTesting($p);
        return $r;
    }

    public function testStructuredNormalizesArrayResult(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(['subtasks' => [['title' => 'A']]]));
        $out = $r->structured([['role' => 'user', 'content' => 'hi']], '{}');
        $this->assertSame(['subtasks' => [['title' => 'A']]], $out);
    }

    public function testStructuredNormalizesResponseResult(): void
    {
        $resp = new Response(content: json_encode(['ok' => true]), finishReason: ProviderFinishReason::Stop);
        $r = $this->registryWithFake($this->fakeProviderReturning($resp));
        $out = $r->structured([['role' => 'user', 'content' => 'hi']], '{}');
        $this->assertSame(['ok' => true], $out);
    }

    public function testStructuredReturnsEmptyOnNull(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(null));
        $this->assertSame([], $r->structured([['role' => 'user', 'content' => 'x']], '{}'));
    }

    public function testStructuredReturnsEmptyOnResponseWithBadJson(): void
    {
        $resp = new Response(content: 'NOT_JSON{{', finishReason: ProviderFinishReason::Stop);
        $r = $this->registryWithFake($this->fakeProviderReturning($resp));
        $this->assertSame([], $r->structured([['role' => 'user', 'content' => 'x']], '{}'));
    }

    public function testStructuredMapsAllRolesWithoutError(): void
    {
        $r = $this->registryWithFake($this->fakeProviderReturning(['ok' => 1]));
        $out = $r->structured([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'u'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'weird', 'content' => 'fallback-to-user'],
        ], '{}');
        $this->assertSame(['ok' => 1], $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `buildProvider()`/`structured()`/`setProviderForTesting()` undefined.

- [ ] **Step 3: Add the build + structured methods to `ProviderRegistry.php`** — add these `use` statements at the top (after `use Kanboard\Core\Base;`) and the methods before the closing brace. **These php-agents `use`s are safe** because they are only referenced inside method bodies / method signatures resolved at call time:

```php
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
```

Add these members (property + methods):

```php
    /** Test seam — when set, buildProvider()/structured() use this instead of config. */
    private ?ProviderInterface $injectedProvider = null;

    /** Inject a provider for tests so no network call is made. */
    public function setProviderForTesting(ProviderInterface $provider): void
    {
        $this->injectedProvider = $provider;
    }

    /**
     * Build a configured php-agents provider for $profileId (null → the default).
     *
     * @throws \RuntimeException on missing/unknown profile or unsupported provider
     *         type. The message NEVER contains an API key.
     */
    public function buildProvider(?string $profileId = null): ProviderInterface
    {
        if ($this->injectedProvider !== null) {
            return $this->injectedProvider;
        }

        $id = $profileId ?? $this->getDefaultProfileId();
        if ($id === '') {
            throw new \RuntimeException('[AiConnector] No AI provider profile is configured. Add one in Settings → AI Connector.');
        }

        $profile = $this->findProfile($id);
        if ($profile === null) {
            throw new \RuntimeException(sprintf('[AiConnector] Unknown provider profile "%s".', $id));
        }

        $type    = $profile['provider'];
        $model   = $profile['model'] !== '' ? $profile['model'] : (self::DEFAULT_MODELS[$type] ?? '');
        $baseUrl = $profile['base_url'];
        $stored  = (string) $this->configModel->get(self::KEY_PREFIX . $id, '');
        $key     = $this->resolveKey($type, $stored);

        return match ($type) {
            self::PROVIDER_ANTHROPIC => new AnthropicProvider(model: $model, apiKey: $key),
            self::PROVIDER_OPENAI => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URLS[self::PROVIDER_OPENAI],
                apiKey: $key,
            ),
            self::PROVIDER_OPENAI_RESPONSES => $baseUrl !== ''
                ? new OpenAIResponsesProvider(model: $model, baseUrl: $baseUrl, apiKey: $key)
                : new OpenAIResponsesProvider(model: $model, apiKey: $key),
            self::PROVIDER_GROK    => new XAIProvider(model: $model, apiKey: $key),
            self::PROVIDER_GEMINI  => new GeminiProvider(model: $model, apiKey: $key),
            self::PROVIDER_MISTRAL => new MistralProvider(model: $model, apiKey: $key),
            self::PROVIDER_OLLAMA  => new OllamaProvider(
                model: $model,
                baseUrl: $this->resolveOllamaBaseUrl($baseUrl),
            ),
            default => throw new \RuntimeException(sprintf(
                '[AiConnector] Unsupported provider type "%s". Supported: %s',
                $type,
                implode(', ', array_keys(self::PROVIDERS))
            )),
        };
    }

    /**
     * Provider-agnostic structured call. Maps $messages to php-agents messages,
     * calls the provider's structured(), and normalizes BOTH return shapes
     * (decoded array | Response with JSON ->content) to a decoded PHP array.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @throws \RuntimeException from buildProvider() (no key in message).
     */
    public function structured(array $messages, string $schema, ?string $profileId = null): array
    {
        $provider = $this->buildProvider($profileId);
        $mapped   = $this->mapMessages($messages);
        $raw      = $provider->structured($mapped, $schema);
        return $this->normalizeStructuredResult($raw);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Map [['role'=>..,'content'=>..], ...] to php-agents message objects.
     * Unknown roles fall back to UserMessage (defensive).
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<int, object>
     */
    private function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role    = is_array($m) ? (string) ($m['role'] ?? 'user') : 'user';
            $content = is_array($m) ? (string) ($m['content'] ?? '') : (string) $m;
            $out[] = match ($role) {
                'system'    => new SystemMessage($content),
                'assistant' => new AssistantMessage($content),
                default     => new UserMessage($content),
            };
        }
        return $out;
    }

    /**
     * Normalize php-agents structured() return to a decoded PHP array.
     *  1. array (Anthropic tool_use / OpenAIResponses) → use as-is.
     *  2. Response (openai/grok/gemini/mistral/ollama) → json_decode(->content).
     *  3. anything else → [].
     */
    private function normalizeStructuredResult(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw instanceof Response) {
            $decoded = json_decode($raw->content, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Ollama base URL: profile override → OLLAMA_HOST (+ /v1) → php-agents default.
     * php-agents' OllamaProvider strips /v1 internally for its native endpoints.
     */
    private function resolveOllamaBaseUrl(string $baseUrl): string
    {
        if ($baseUrl !== '') {
            return $baseUrl;
        }
        $host = getenv('OLLAMA_HOST');
        if ($host !== false && $host !== '') {
            return rtrim($host, '/') . '/v1';
        }
        return self::DEFAULT_BASE_URLS[self::PROVIDER_OLLAMA];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS (all AiConnector tests so far).

- [ ] **Step 5: Commit**

```bash
git add AiConnector/Model/ProviderRegistry.php AiConnector/Test/ProviderRegistryBuildTest.php
git commit -m "feat(AiConnector): buildProvider for all 7 types + structured() normalization"
```

---

## Task 3: SettingsController + templates + sidebar + routes (profiles CRUD)

**Files:**
- Create: `AiConnector/Controller/SettingsController.php` (show/save/delete/setDefault; testConnection added in Task 4)
- Create: `AiConnector/Template/config/sidebar.php`
- Create: `AiConnector/Template/config/settings.php`
- Modify: `AiConnector/Plugin.php` (register the config sidebar hook + routes)
- Test: `AiConnector/Test/SettingsTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry` (`getProfiles`, `findProfile`, `getDefaultProfileId`, `hasStoredKey`, constants `PROVIDERS`, `DEFAULT_MODELS`, `KEY_PLACEHOLDER`, `PROFILES_KEY`, `DEFAULT_KEY`, `KEY_PREFIX`).
- Produces: routes `ai-connector/settings|save|delete|default` (+ `ai-connector/test` reserved for Task 4). `SettingsController::save()` mints a new id for a new profile (`p_` + 8 lowercase hex from a hash of label+count — deterministic, no `random`), keeps id on edit; writes key only when non-placeholder/non-empty; first profile becomes default.

- [ ] **Step 1: Write the failing test** `AiConnector/Test/SettingsTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Controller\SettingsController;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/**
 * Task 3 — settings CRUD, admin gate, key masking. No network.
 */
class SettingsTest extends Base
{
    private function seed(string $option, string $value): void
    {
        $db = $this->container['db'];
        if ($db->table('settings')->eq('option', $option)->count() > 0) {
            $db->table('settings')->eq('option', $option)->update(['value' => $value]);
        } else {
            $db->table('settings')->insert(['option' => $option, 'value' => $value]);
        }
        $this->container['memoryCache']->flush();
    }

    private function stubNonAdmin(): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin'])->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(false);
    }

    private function stubAdminWithForm(array $formValues): void
    {
        $this->container['userSession'] = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin', 'getId'])->getMock();
        $this->container['userSession']->method('isAdmin')->willReturn(true);
        $this->container['userSession']->method('getId')->willReturn(1);

        $this->container['token'] = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken'])->getMock();
        $this->container['token']->method('validateCSRFToken')->willReturn(true);

        $this->container['request'] = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues', 'getRawValue', 'getStringParam'])->getMock();
        $this->container['request']->method('getValues')->willReturn($formValues);
        $this->container['request']->method('getRawValue')->willReturn('dummy-csrf');
        $this->container['request']->method('getStringParam')->willReturnCallback(
            fn (string $p, $d = '') => $formValues[$p] ?? $d
        );
    }

    private function driveSave(): void
    {
        try {
            (new SettingsController($this->container))->save();
        } catch (\Throwable $e) {
            // redirect() exits/throws in test context — expected.
        }
    }

    public function testShowThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->show();
    }

    public function testSaveThrowsForNonAdmin(): void
    {
        $this->stubNonAdmin();
        $this->expectException(AccessForbiddenException::class);
        (new SettingsController($this->container))->save();
    }

    public function testSaveCreatesFirstProfileAsDefaultWithKey(): void
    {
        $this->stubAdminWithForm([
            'profile_id' => '',
            'label'      => 'Claude',
            'provider'   => 'anthropic',
            'model'      => 'claude-sonnet-4-20250514',
            'base_url'   => '',
            'api_key'    => 'sk-real',
        ]);
        $this->driveSave();

        $r = new ProviderRegistry($this->container);
        $profiles = $r->getProfiles();
        $this->assertCount(1, $profiles);
        $id = $profiles[0]['id'];
        $this->assertSame('anthropic', $profiles[0]['provider']);
        $this->assertSame($id, $r->getDefaultProfileId());
        $this->assertSame('sk-real', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . $id, ''));
        // Key must NOT be inside the profiles JSON.
        $this->assertStringNotContainsString('sk-real', (string) $this->container['configModel']->get(ProviderRegistry::PROFILES_KEY, ''));
    }

    public function testSaveWithBlankKeyPreservesStoredKey(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'kept-key');

        $this->stubAdminWithForm([
            'profile_id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic',
            'model' => 'm', 'base_url' => '', 'api_key' => '',
        ]);
        $this->driveSave();

        $this->assertSame('kept-key', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', ''));
    }

    public function testSaveWithPlaceholderKeyPreservesStoredKey(): void
    {
        $this->seed(ProviderRegistry::PROFILES_KEY, json_encode([
            ['id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic', 'model' => 'm', 'base_url' => ''],
        ]));
        $this->seed(ProviderRegistry::DEFAULT_KEY, 'p1');
        $this->seed(ProviderRegistry::KEY_PREFIX . 'p1', 'kept-key-2');

        $this->stubAdminWithForm([
            'profile_id' => 'p1', 'label' => 'Claude', 'provider' => 'anthropic',
            'model' => 'm', 'base_url' => '', 'api_key' => ProviderRegistry::KEY_PLACEHOLDER,
        ]);
        $this->driveSave();

        $this->assertSame('kept-key-2', $this->container['configModel']->get(ProviderRegistry::KEY_PREFIX . 'p1', ''));
    }

    public function testSaveRejectsUnknownProviderType(): void
    {
        $this->stubAdminWithForm([
            'profile_id' => '', 'label' => 'Bad', 'provider' => 'llamacpp',
            'model' => 'm', 'base_url' => '', 'api_key' => 'k',
        ]);
        $this->driveSave();
        // A profile with an unsupported provider must NOT be persisted.
        $this->assertSame([], (new ProviderRegistry($this->container))->getProfiles());
    }

    public function testTemplateNeverEchoesStoredKey(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/Template/config/settings.php');
        $this->assertMatchesRegularExpression('/name="api_key"[^>]*value=""/', $content,
            'api_key input must render value="" (never a stored key)');
        $this->assertStringNotContainsString('$this->token', $content,
            '$this->token is not a template helper — would break layout');
    }
}
```

*(Delete + setDefault behavioral cases: add `testDeleteRemovesProfileAndKey` and `testSetDefaultChangesDefault` following the same drive pattern — supply `profile_id` via `getStringParam` and assert `getProfiles()`/`getDefaultProfileId()`. Include them; they are one-liners over the same stubs.)*

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — `SettingsController` not found.

- [ ] **Step 3: Write `AiConnector/Controller/SettingsController.php`:**

```php
<?php

namespace Kanboard\Plugin\AiConnector\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiConnector settings — admin-only CRUD over provider profiles.
 *
 * Keys are stored separately (aiconnector_key_<id>), never echoed, and never
 * overwritten by an empty/placeholder submission (KEY_PLACEHOLDER pattern).
 *
 * @package Kanboard\Plugin\AiConnector\Controller
 * @author  Carmelo Santana
 */
class SettingsController extends BaseController
{
    public function show(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $registry = new ProviderRegistry($this->container);
        $editId   = $this->request->getStringParam('edit', '');
        $editProfile = $editId !== '' ? $registry->findProfile($editId) : null;

        $this->response->html($this->helper->layout->config('AiConnector:config/settings', [
            'title'          => t('Settings') . ' &gt; ' . t('AI Connector'),
            'profiles'       => $registry->getProfiles(),
            'default_id'     => $registry->getDefaultProfileId(),
            'providers'      => ProviderRegistry::PROVIDERS,
            'default_models' => ProviderRegistry::DEFAULT_MODELS,
            'edit_profile'   => $editProfile,
            'edit_has_key'   => $editProfile !== null ? $registry->hasStoredKey($editProfile['id']) : false,
            // Reusable CSRF token for the (external) Test-Connection fetch (Task 4).
            // MUST be generated here — `token` is not a template helper.
            'ai_test_csrf'   => $this->token->getReusableCSRFToken(),
        ]));
    }

    public function save(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $values   = $this->request->getValues();
        $registry = new ProviderRegistry($this->container);

        $provider = (string) ($values['provider'] ?? '');
        if (! array_key_exists($provider, ProviderRegistry::PROVIDERS)) {
            $this->flash->failure(t('Unsupported provider type.'));
            $this->redirectToShow();
            return;
        }

        $label   = trim((string) ($values['label'] ?? ''));
        $model   = trim((string) ($values['model'] ?? ''));
        $baseUrl = trim((string) ($values['base_url'] ?? ''));
        if ($model === '') {
            $model = ProviderRegistry::DEFAULT_MODELS[$provider] ?? '';
        }
        if ($label === '') {
            $label = ProviderRegistry::PROVIDERS[$provider];
        }

        $id = trim((string) ($values['profile_id'] ?? ''));
        $profiles = $registry->getProfiles();
        $isNew = ($id === '' || $registry->findProfile($id) === null);
        if ($isNew) {
            $id = $this->mintId($label, count($profiles));
        }

        $struct = ['id' => $id, 'label' => $label, 'provider' => $provider, 'model' => $model, 'base_url' => $baseUrl];

        // Upsert into the profiles list.
        $replaced = false;
        foreach ($profiles as $i => $p) {
            if ($p['id'] === $id) {
                $profiles[$i] = $struct;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            $profiles[] = $struct;
        }

        $this->configModel->save([ProviderRegistry::PROFILES_KEY => json_encode(array_values($profiles))]);

        // Key: only persist a real (non-placeholder, non-empty) submission.
        $submitted = trim((string) ($values['api_key'] ?? ''));
        if ($submitted !== '' && $submitted !== ProviderRegistry::KEY_PLACEHOLDER) {
            $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => $submitted]);
        }

        // First profile becomes the default.
        if ($registry->getDefaultProfileId() === '') {
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $id]);
        }

        $this->flash->success(t('Profile saved.'));
        $this->redirectToShow();
    }

    public function delete(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $id = $this->request->getStringParam('profile_id', '');
        $registry = new ProviderRegistry($this->container);
        $profiles = array_values(array_filter(
            $registry->getProfiles(),
            fn (array $p) => $p['id'] !== $id
        ));

        $this->configModel->save([ProviderRegistry::PROFILES_KEY => json_encode($profiles)]);
        $this->configModel->save([ProviderRegistry::KEY_PREFIX . $id => '']);

        // Fix up the default if we removed it.
        if ($this->configModel->get(ProviderRegistry::DEFAULT_KEY, '') === $id) {
            $newDefault = $profiles[0]['id'] ?? '';
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $newDefault]);
        }

        $this->flash->success(t('Profile removed.'));
        $this->redirectToShow();
    }

    public function setDefault(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $id = $this->request->getStringParam('profile_id', '');
        $registry = new ProviderRegistry($this->container);
        if ($registry->findProfile($id) !== null) {
            $this->configModel->save([ProviderRegistry::DEFAULT_KEY => $id]);
            $this->flash->success(t('Default profile updated.'));
        } else {
            $this->flash->failure(t('Unknown profile.'));
        }
        $this->redirectToShow();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Deterministic unique id (no random() — keeps the harness reproducible). */
    private function mintId(string $label, int $count): string
    {
        return 'p_' . substr(hash('sha256', $label . '|' . $count . '|' . microtime()), 0, 8);
    }

    private function redirectToShow(): void
    {
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'AiConnector']));
    }
}
```

Note: `microtime()` (not `Date.now`/`random`) is a host PHP call — fine in the controller; tests assert on counts/ids they read back, not on the literal id value.

- [ ] **Step 4: Write `AiConnector/Template/config/sidebar.php`:**

```php
<li>
    <?= $this->url->link(t('AI Connector'), 'SettingsController', 'show', ['plugin' => 'AiConnector']) ?>
</li>
```

- [ ] **Step 5: Write `AiConnector/Template/config/settings.php`** — profile list + reused add/edit form + (Task 4) Test Connection. Full template:

```php
<div class="page-header">
    <h2><?= t('AI Connector — Provider Profiles') ?></h2>
</div>

<p class="form-help">
    <?= t('Configure one or more AI provider profiles. Other plugins (e.g. Subtask Generator) use these. API keys are stored securely and never displayed after saving.') ?>
</p>

<?php /* ── Existing profiles ─────────────────────────────────────────────── */ ?>
<?php if (! empty($profiles)): ?>
<table class="table-striped">
    <thead>
        <tr>
            <th><?= t('Default') ?></th>
            <th><?= t('Label') ?></th>
            <th><?= t('Provider') ?></th>
            <th><?= t('Model') ?></th>
            <th><?= t('Actions') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($profiles as $p): ?>
        <tr>
            <td>
                <?php if ($p['id'] === $default_id): ?>
                    <strong><?= t('Default') ?></strong>
                <?php else: ?>
                    <form method="post" style="display:inline"
                          action="<?= $this->url->href('SettingsController', 'setDefault', ['plugin' => 'AiConnector']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="profile_id" value="<?= $this->text->e($p['id']) ?>">
                        <button type="submit" class="btn btn-grey"><?= t('Make default') ?></button>
                    </form>
                <?php endif ?>
            </td>
            <td><?= $this->text->e($p['label']) ?></td>
            <td><?= $this->text->e($providers[$p['provider']] ?? $p['provider']) ?></td>
            <td><?= $this->text->e($p['model']) ?></td>
            <td>
                <?= $this->url->link(t('Edit'), 'SettingsController', 'show', ['plugin' => 'AiConnector', 'edit' => $p['id']]) ?>
                &nbsp;
                <form method="post" style="display:inline"
                      action="<?= $this->url->href('SettingsController', 'delete', ['plugin' => 'AiConnector']) ?>">
                    <?= $this->form->csrf() ?>
                    <input type="hidden" name="profile_id" value="<?= $this->text->e($p['id']) ?>">
                    <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php else: ?>
    <div class="alert alert-info"><?= t('No provider profiles yet. Add one below.') ?></div>
<?php endif ?>

<hr>

<?php /* ── Add / edit form ────────────────────────────────────────────────── */ ?>
<h3><?= $edit_profile ? t('Edit Profile') : t('Add Profile') ?></h3>

<form method="post"
      action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'AiConnector']) ?>">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="profile_id" value="<?= $this->text->e($edit_profile['id'] ?? '') ?>">

    <?= $this->form->label(t('Label'), 'label') ?>
    <input type="text" name="label" id="label" class="form-text"
           value="<?= $this->text->e($edit_profile['label'] ?? '') ?>"
           placeholder="<?= t('e.g. Claude Sonnet') ?>">

    <?= $this->form->label(t('Provider'), 'provider') ?>
    <select name="provider" id="ai_provider" class="auto-select"
            data-defaults='<?= htmlspecialchars(json_encode($default_models), ENT_QUOTES) ?>'>
        <?php foreach ($providers as $key => $plabel): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                <?= (($edit_profile['provider'] ?? '') === $key) ? 'selected' : '' ?>>
                <?= htmlspecialchars($plabel, ENT_QUOTES) ?>
            </option>
        <?php endforeach ?>
    </select>

    <?= $this->form->label(t('Model'), 'model') ?>
    <input type="text" name="model" id="ai_model" class="form-text"
           value="<?= $this->text->e($edit_profile['model'] ?? '') ?>"
           placeholder="<?= htmlspecialchars($default_models[$edit_profile['provider'] ?? 'anthropic'] ?? '', ENT_QUOTES) ?>">

    <?= $this->form->label(t('Base URL (optional)'), 'base_url') ?>
    <input type="text" name="base_url" id="base_url" class="form-text"
           value="<?= $this->text->e($edit_profile['base_url'] ?? '') ?>"
           placeholder="<?= t('Leave blank for the provider default') ?>">
    <p class="form-help"><?= t('Only used for OpenAI-compatible / Ollama / self-hosted endpoints.') ?></p>

    <?= $this->form->label(t('API Key'), 'api_key') ?>
    <?php if ($edit_has_key): ?>
        <p class="form-help" style="color:green;">
            <?= t('An API key is stored. Leave blank to keep it, or enter a new key to replace it.') ?>
        </p>
    <?php endif ?>
    <input type="password" name="api_key" id="api_key" class="form-text" value=""
           placeholder="<?= t('Leave blank to keep the current key (Ollama needs none)') ?>"
           autocomplete="new-password">
    <p class="form-help">
        <?= t('Env-var fallback when blank: ANTHROPIC_API_KEY / OPENAI_API_KEY / XAI_API_KEY / GEMINI_API_KEY / MISTRAL_API_KEY. Ollama is keyless.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= $edit_profile ? t('Save Profile') : t('Add Profile') ?></button>
        <?php if ($edit_profile): ?>
            <?= $this->url->link(t('Cancel'), 'SettingsController', 'show', ['plugin' => 'AiConnector']) ?>
        <?php endif ?>
    </div>
</form>

<?php /* ── Test Connection block is added in Task 4 ─────────────────────────── */ ?>
```

- [ ] **Step 6: Register routes + sidebar hook in `AiConnector/Plugin.php`** — inside `initialize()`, after the vendor `require_once`, add:

```php
        // ── Settings sidebar link ─────────────────────────────────────────────
        $this->hook->on('template:config:sidebar', [
            'template' => 'AiConnector:config/sidebar',
        ]);

        // ── Settings routes ───────────────────────────────────────────────────
        $this->route->addRoute('ai-connector/settings', 'SettingsController', 'show');
        $this->route->addRoute('ai-connector/save',     'SettingsController', 'save');
        $this->route->addRoute('ai-connector/delete',   'SettingsController', 'delete');
        $this->route->addRoute('ai-connector/default',  'SettingsController', 'setDefault');
```

- [ ] **Step 7: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add AiConnector/Controller/SettingsController.php AiConnector/Template/config AiConnector/Plugin.php AiConnector/Test/SettingsTest.php
git commit -m "feat(AiConnector): profiles settings CRUD (list + add/edit/remove/default) + routes"
```

---

## Task 4: Test Connection endpoint + external JS

**Files:**
- Modify: `AiConnector/Controller/SettingsController.php` (add `testConnection()`)
- Create: `AiConnector/Assets/js/ai-connector.js`
- Modify: `AiConnector/Template/config/settings.php` (add the Test Connection block)
- Modify: `AiConnector/Plugin.php` (register the JS + the `ai-connector/test` route)
- Test: extend `AiConnector/Test/SettingsTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry::buildProvider($id)` + `structured()`; reusable CSRF (`checkReusableCSRFParam`).
- Produces: route `ai-connector/test → testConnection`; JSON `{ok:true}` / `{ok:false, error:string}` — never a key, never raw model output. JS reads `#ai-test-btn[data-test-url]` and posts (GET with `credentials:'same-origin'`), rendering the result into `#ai-test-result`.

- [ ] **Step 1: Write the failing test** — append to `AiConnector/Test/SettingsTest.php`:

```php
    public function testTestConnectionNonAdminReturnsError(): void
    {
        $this->stubNonAdmin();
        $controller = new class($this->container) extends SettingsController {
            protected function jsonOut(array $p): void { throw new \RuntimeException('json:' . json_encode($p)); }
        };
        // Non-admin path returns a JSON error (ok=false) rather than throwing AccessForbidden.
        // We assert the source guards on isAdmin() to keep the behavior verifiable without HTTP.
        $src = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringContainsString('checkReusableCSRFParam', $src,
            'testConnection() must validate the reusable CSRF param');
        $this->assertStringContainsString('isAdmin', $src);
    }

    public function testTestConnectionResponseNeverIncludesRawResult(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringNotContainsString("'result' =>", $src,
            'testConnection() must not echo the raw model output');
    }

    public function testSettingsTemplatePassesCsrfTokenToTestConnection(): void
    {
        $template   = file_get_contents(dirname(__DIR__) . '/Template/config/settings.php');
        $controller = file_get_contents(dirname(__DIR__) . '/Controller/SettingsController.php');
        $this->assertStringContainsString('csrf_token', $template);
        $this->assertStringContainsString('$ai_test_csrf', $template);
        $this->assertStringNotContainsString('$this->token', $template);
        $this->assertStringContainsString('getReusableCSRFToken', $controller);
    }

    public function testTestConnectionAssetExists(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/Assets/js/ai-connector.js');
    }
```

*(The `jsonOut` override in the first test is illustrative — keep the assertions source-level like SubtaskGenerator's proven `SettingsTest`; a full HTTP drive of testConnection is not needed and would risk a real provider call.)*

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: FAIL — asset missing / `checkReusableCSRFParam` absent.

- [ ] **Step 3: Add `testConnection()` to `SettingsController.php`** (before the private helpers). Mirrors SubtaskGenerator's proven pattern, but uses `ProviderRegistry::structured()` so no php-agents class is touched here:

```php
    /**
     * Test a profile's connection: build its provider and make a minimal
     * structured() call. Admin + reusable-CSRF gated. Returns {ok} / {ok,error}
     * — never the key or the raw model output.
     */
    public function testConnection(): void
    {
        if (! $this->userSession->isAdmin()) {
            $this->response->json(['ok' => false, 'error' => t('Access denied.')]);
            return;
        }
        $this->checkReusableCSRFParam();

        $profileId = $this->request->getStringParam('profile', '');

        try {
            $registry = new ProviderRegistry($this->container);
            $schema = json_encode([
                'name'   => 'test_output',
                'schema' => [
                    'type'       => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                    'required'   => ['ok'],
                ],
            ]);
            $registry->structured(
                [['role' => 'user', 'content' => 'Reply with ok=true to confirm the connection works.']],
                $schema,
                $profileId !== '' ? $profileId : null
            );
            $this->response->json(['ok' => true]);
        } catch (\Throwable $e) {
            // Message never contains the key (buildProvider guarantees this).
            $this->response->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
```

- [ ] **Step 4: Write `AiConnector/Assets/js/ai-connector.js`** — adapt the Test-Connection + provider→model auto-fill sections of `SubtaskGenerator/Assets/js/subtask-generator.js`, renaming ids `sg-*`→`ai-*` / `sg_*`→`ai_*`. Full file:

```javascript
/**
 * AiConnector — settings page interactions (CSP-safe, external).
 *
 * Injected sitewide via Plugin.php (template:layout:js); a strict no-op on pages
 * without #ai-test-btn / #ai_provider. The test URL (with a reusable CSRF token)
 * and i18n strings arrive via data-* attributes.
 */
(function () {
    'use strict';

    function runTestConnection(btn) {
        var box = document.getElementById('ai-test-result');
        var url = btn.getAttribute('data-test-url');
        if (!box || !url) { return; }

        // Append the selected profile id so the endpoint tests that profile.
        var sel = document.getElementById('ai-test-profile');
        if (sel && sel.value) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'profile=' + encodeURIComponent(sel.value);
        }

        btn.disabled = true;
        box.style.display = 'none';
        box.textContent = '';

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.style.display = 'block';
                if (data && data.ok) {
                    box.style.background = '#d1fae5';
                    box.style.color = '#065f46';
                    box.textContent = btn.getAttribute('data-msg-ok') || 'OK';
                } else {
                    box.style.background = '#fee2e2';
                    box.style.color = '#991b1b';
                    box.textContent = (btn.getAttribute('data-msg-fail') || 'Failed:') + ' ' +
                        ((data && data.error) || btn.getAttribute('data-msg-unknown') || '');
                }
                btn.disabled = false;
            })
            .catch(function (err) {
                box.style.display = 'block';
                box.style.background = '#fee2e2';
                box.style.color = '#991b1b';
                box.textContent = (btn.getAttribute('data-msg-request-failed') || 'Request failed:') + ' ' + err.message;
                btn.disabled = false;
            });
    }

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('#ai-test-btn') : null;
        if (t) {
            e.preventDefault();
            runTestConnection(t);
        }
    });

    // Auto-fill the model placeholder when the provider changes.
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || sel.id !== 'ai_provider') { return; }
        var modelInput = document.getElementById('ai_model');
        if (!modelInput) { return; }
        var defaults = {};
        try { defaults = JSON.parse(sel.getAttribute('data-defaults') || '{}'); } catch (err) {}
        var def = defaults[sel.value];
        if (def && modelInput.value === modelInput.placeholder) {
            modelInput.value = def;
        }
        modelInput.placeholder = def || '';
    });
}());
```

- [ ] **Step 5: Add the Test Connection block to `settings.php`** — replace the trailing `<?php /* ── Test Connection block is added in Task 4 ── */ ?>` comment with:

```php
<hr>
<h3><?= t('Test Connection') ?></h3>
<p class="form-help"><?= t('Verify a saved profile works. Select a profile and click Test.') ?></p>

<div id="ai-test-result" style="display:none; padding:8px; border-radius:4px; margin-bottom:12px;"></div>

<?php if (! empty($profiles)): ?>
<select id="ai-test-profile" class="form-select">
    <?php foreach ($profiles as $p): ?>
        <option value="<?= $this->text->e($p['id']) ?>" <?= ($p['id'] === $default_id) ? 'selected' : '' ?>>
            <?= $this->text->e($p['label']) ?>
        </option>
    <?php endforeach ?>
</select>
<button type="button" class="btn btn-blue" id="ai-test-btn"
        data-test-url="<?= $this->url->href('SettingsController', 'testConnection', ['plugin' => 'AiConnector', 'csrf_token' => $ai_test_csrf]) ?>"
        data-msg-ok="<?= $this->text->e(t('Connection successful.')) ?>"
        data-msg-fail="<?= $this->text->e(t('Connection failed:')) ?>"
        data-msg-unknown="<?= $this->text->e(t('Unknown error')) ?>"
        data-msg-request-failed="<?= $this->text->e(t('Request failed:')) ?>">
    <?= t('Test Connection') ?>
</button>
<?php endif ?>
```

- [ ] **Step 6: Register the JS + route in `Plugin.php`** — in `initialize()` add:

```php
        // ── External JS (CSP-safe: Test Connection + provider→model auto-fill) ─
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/AiConnector/Assets/js/ai-connector.js',
        ]);
```
and add the route alongside the others:
```php
        $this->route->addRoute('ai-connector/test', 'SettingsController', 'testConnection');
```

- [ ] **Step 7: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add AiConnector/Controller/SettingsController.php AiConnector/Assets/js/ai-connector.js AiConnector/Template/config/settings.php AiConnector/Plugin.php AiConnector/Test/SettingsTest.php
git commit -m "feat(AiConnector): per-profile Test Connection (reusable CSRF) + external JS"
```

---

## Task 5: SubtaskGenerator — consume ProviderRegistry; delete ProviderFactory & vendored php-agents

**Files:**
- Delete: `SubtaskGenerator/Model/ProviderFactory.php`, `SubtaskGenerator/composer.json`, `SubtaskGenerator/composer.lock`, `SubtaskGenerator/vendor/**`
- Modify: `SubtaskGenerator/Model/SubtaskGeneratorModel.php`
- Create: `SubtaskGenerator/Model/AiGate.php` (the shared readiness gate replacing `ProviderFactory::isAiReady`)
- Modify: `SubtaskGenerator/Test/SubtaskGeneratorModelTest.php`
- Modify: `SubtaskGenerator/Test/SettingsTest.php`, `SubtaskGenerator/Test/GeneratorTest.php`, `SubtaskGenerator/Test/PluginTest.php` (remove php-agents/ProviderFactory imports; retarget to the registry/gate)

**Interfaces:**
- Consumes: `Kanboard\Plugin\AiConnector\Model\ProviderRegistry` (`structured`, `isReady`, `listProfiles`, `getDefaultProfileId`, `setProviderForTesting`).
- Produces:
  - `SubtaskGeneratorModel::generate(string $prompt, ?string $profileId = null): array` — builds `[['role'=>'system','content'=>SYSTEM_PROMPT],['role'=>'user','content'=>$prompt]]`, calls `structured()`, then `normalise()`. Test seam: `setRegistry(ProviderRegistry $r): void`.
  - `SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS = 8` (moved from ProviderFactory).
  - `AiGate::isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool` — PHP≥8.4 AND AiConnector present AND `ProviderRegistry::isReady()`.

- [ ] **Step 1: Update the model test first** — rewrite `SubtaskGenerator/Test/SubtaskGeneratorModelTest.php` to drive through an injected fake registry. Replace the php-agents/ProviderFactory imports with:

```php
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel;
use KanboardTests\units\Base;
```

Replace `makeModel()`/`mockProvider()`/`makeResponse()`/`throwingProvider()` helpers with a fake-registry helper:

```php
    /** A registry stub whose structured() returns/throws a canned value — no network. */
    private function fakeRegistry(mixed $return, ?\Throwable $throw = null): ProviderRegistry
    {
        return new class($this->container, $return, $throw) extends ProviderRegistry {
            public function __construct($c, private mixed $r, private ?\Throwable $t) { parent::__construct($c); }
            public function structured(array $messages, string $schema, ?string $profileId = null): array {
                if ($this->t !== null) { throw $this->t; }
                return is_array($this->r) ? $this->r : [];
            }
        };
    }

    private function makeModel(mixed $return, ?\Throwable $throw = null): SubtaskGeneratorModel
    {
        $model = new SubtaskGeneratorModel($this->container);
        $model->setRegistry($this->fakeRegistry($return, $throw));
        return $model;
    }
```

Then update each existing case: `$this->makeModel($this->mockProvider($cannedArray))` → `$this->makeModel($cannedArray)`. The `Response`-shape cases (`testGenerateWithOpenAIStyleResponseResult`, `testGenerateReturnsEmptyOnInvalidJson`) move to AiConnector's `ProviderRegistryBuildTest` (already covered there) — **delete** them here; keep the array-path, dedupe, blank, clamp, non-string, and propagation cases (now `structured()` returns the decoded array directly, so feed decoded arrays). For propagation: `$this->makeModel(null, new \RuntimeException('boom'))` then `expectException`. Also **delete** `testAnthropicProviderStructuredReturnsArrayOrResponse` and `testModelHandlesBothReturnShapes` (that logic now lives in AiConnector; assert there).

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SubtaskGenerator`
Expected: FAIL — `setRegistry()` undefined / `ProviderFactory` still referenced.

- [ ] **Step 3: Rewrite `SubtaskGenerator/Model/SubtaskGeneratorModel.php`:**

```php
<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * SubtaskGeneratorModel
 *
 * Asks AiConnector's ProviderRegistry for a structured() result against a
 * {subtasks:[{title}]} schema, then normalises/dedupes/clamps to string titles.
 * All provider selection, key handling, and php-agents coupling live in
 * AiConnector — this model references no CarmeloSantana\PHPAgents\* class.
 *
 * @package Kanboard\Plugin\SubtaskGenerator\Model
 * @author  Carmelo Santana
 */
class SubtaskGeneratorModel extends Base
{
    public const DEFAULT_MAX_SUBTASKS = 8;

    /** JSON schema passed to structured(). Name matches the tool name. */
    private const SCHEMA = [
        'name'   => 'subtasks',
        'schema' => [
            'type'       => 'object',
            'properties' => [
                'subtasks' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => ['title' => ['type' => 'string']],
                        'required'   => ['title'],
                    ],
                ],
            ],
            'required' => ['subtasks'],
        ],
    ];

    private const SYSTEM_PROMPT = 'Break the task into concrete, actionable subtasks.';

    /** Optional registry override — injected in tests; null → built from container. */
    private ?ProviderRegistry $injectedRegistry = null;

    /** Inject a registry (with a fake provider) so unit tests make no network call. */
    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->injectedRegistry = $registry;
    }

    /**
     * Generate candidate subtask titles for the given prompt.
     *
     * @param  string      $prompt    Free-text prompt (task title + description).
     * @param  string|null $profileId AiConnector profile id (null → default).
     * @return string[]               Normalised, deduped, clamped titles.
     * @throws \RuntimeException on provider error (caller must catch).
     */
    public function generate(string $prompt, ?string $profileId = null): array
    {
        $registry = $this->injectedRegistry ?? new ProviderRegistry($this->container);

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => $prompt],
        ];

        $decoded = $registry->structured($messages, json_encode(self::SCHEMA), $profileId);

        return $this->normalise($decoded);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Validate + normalise the decoded array into clean string titles:
     * extract subtasks → trim → drop blanks/non-strings → case-insensitive dedupe
     * → clamp to sg_max_subtasks.
     *
     * @param  array<string, mixed> $data
     * @return string[]
     */
    private function normalise(array $data): array
    {
        $rawItems = $data['subtasks'] ?? [];
        if (! is_array($rawItems)) {
            return [];
        }

        $max = (int) $this->configModel->get('sg_max_subtasks', (string) self::DEFAULT_MAX_SUBTASKS);
        if ($max < 1) {
            $max = self::DEFAULT_MAX_SUBTASKS;
        }

        $titles = [];
        $seen   = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                if (is_string($item)) {
                    $title = trim($item);
                    if ($title === '') { continue; }
                    $lower = mb_strtolower($title);
                    if (isset($seen[$lower])) { continue; }
                    $seen[$lower] = true;
                    $titles[] = $title;
                }
                continue;
            }

            $raw = $item['title'] ?? null;
            if (! is_string($raw)) { continue; }

            $title = trim($raw);
            if ($title === '') { continue; }

            $lower = mb_strtolower($title);
            if (isset($seen[$lower])) { continue; }

            $seen[$lower] = true;
            $titles[] = $title;
        }

        return array_slice($titles, 0, $max);
    }
}
```

- [ ] **Step 4: Create `SubtaskGenerator/Model/AiGate.php`** (replaces `ProviderFactory::isAiReady`):

```php
<?php

namespace Kanboard\Plugin\SubtaskGenerator\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AiGate — single source of truth for "is AI subtask generation available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND ProviderRegistry::isReady().
 * Consulted identically by Plugin::initialize() (sidebar link) and
 * GeneratorController::isAiEnabled() (route guards) so they never diverge.
 *
 * class_exists(ProviderRegistry) is safe at initialize() time — it loads the
 * class file but not php-agents (method type-hints resolve lazily). isReady()
 * touches no php-agents class.
 */
class AiGate
{
    /**
     * @param \Pimple\Container $container
     * @param int|null  $phpVersionId    PHP_VERSION_ID override (tests).
     * @param bool|null $connectorPresent AiConnector-present override (tests).
     */
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class);
        if (! $present) {
            return false;
        }

        return (new ProviderRegistry($container))->isReady();
    }
}
```

- [ ] **Step 5: Delete the obsolete files**

```bash
git rm SubtaskGenerator/Model/ProviderFactory.php SubtaskGenerator/composer.json SubtaskGenerator/composer.lock
git rm -r SubtaskGenerator/vendor
```

- [ ] **Step 6: Fix the other SubtaskGenerator tests** — remove `use CarmeloSantana\PHPAgents\...` and `use ...\ProviderFactory;` from `SettingsTest.php`, `GeneratorTest.php`, `PluginTest.php`. `PluginTest`: bump the version assertion to `1.1.0` (Task 6 sets the value) and drop `testProviderClassesResolve` + `testVendorAutoloadExists` (SubtaskGenerator no longer vendors php-agents) and the ProviderFactory-based gate tests; replace gate tests with `AiGate::isReady(...)` using the `$connectorPresent`/`$phpVersionId` overrides. `SettingsTest`: keep only the `sg_max_subtasks` + admin-gate + template checks (Task 6 trims the template); remove ProviderFactory/testConnection cases. `GeneratorTest`: keep show/generate gate cases but route them through `isAiEnabled()` overrides (already anonymous-subclass based). *(Some of these tests are edited again in Task 6 when the templates/controller change — that's fine; keep this task green first.)*

- [ ] **Step 7: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh SubtaskGenerator`
Expected: PASS (model + gate green; controller/template cases updated in Task 6 if any remain red, note them).

- [ ] **Step 8: Commit**

```bash
git add -A SubtaskGenerator/Model SubtaskGenerator/Test
git commit -m "refactor(SubtaskGenerator): consume AiConnector ProviderRegistry; drop ProviderFactory + vendored php-agents"
```

---

## Task 6: SubtaskGenerator — controllers, modal dropdown, settings trim, plugin.json requires

**Files:**
- Modify: `SubtaskGenerator/Controller/GeneratorController.php` (gate via `AiGate`; pass profiles to modal; read `sg_profile`)
- Modify: `SubtaskGenerator/Controller/SettingsController.php` (drop provider/model/key + testConnection; keep `sg_max_subtasks`; gate via `AiGate`)
- Modify: `SubtaskGenerator/Plugin.php` (gate via `AiGate`; drop the `subtask-generator/test` route + `ProviderFactory` import; version 1.1.0; description wording)
- Modify: `SubtaskGenerator/Template/generator/modal.php` (add `sg_profile` select when ≥2 profiles)
- Modify: `SubtaskGenerator/Template/config/settings.php` (trim to `sg_max_subtasks` + AI Connector link)
- Modify: `SubtaskGenerator/Assets/js/subtask-generator.js` (remove the now-dead settings Test-Connection + provider auto-fill handlers; keep the generate/create modal logic)
- Modify: `SubtaskGenerator/plugin.json` (add hard `requires`, version 1.1.0)
- Modify: `SubtaskGenerator/Test/GeneratorTest.php`, `CreateSubtaskTest.php`, `PluginTest.php`, `SettingsTest.php` (dropdown presence; version)

**Interfaces:**
- Consumes: `AiGate::isReady`, `ProviderRegistry::listProfiles`, `ProviderRegistry::getDefaultProfileId`, `SubtaskGeneratorModel::generate($prompt, $profileId)`.
- Produces: modal renders `<select name="sg_profile">` **only when ≥2 profiles**; `generate()` reads `sg_profile`, validates against `listProfiles()` ids (unknown/empty → null), passes to the model.

- [ ] **Step 1: Update controller/template tests first** — in `GeneratorTest.php`, add:

```php
    public function testModalOmitsProfileDropdownWithZeroOrOneProfile(): void
    {
        // 0 profiles.
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''],
            'sg_prompt' => 'T',
            'profiles'  => [],
            'default_profile_id' => '',
        ]);
        $this->assertStringNotContainsString('name="sg_profile"', $html);
    }

    public function testModalShowsProfileDropdownWithTwoProfiles(): void
    {
        $html = $this->container['template']->render('SubtaskGenerator:generator/modal', [
            'task'      => ['id' => 1, 'project_id' => 1, 'title' => 'T', 'description' => ''],
            'sg_prompt' => 'T',
            'profiles'  => [
                ['id' => 'a', 'label' => 'A', 'provider' => 'anthropic', 'model' => 'm'],
                ['id' => 'b', 'label' => 'B', 'provider' => 'openai', 'model' => 'm'],
            ],
            'default_profile_id' => 'b',
        ]);
        $this->assertStringContainsString('name="sg_profile"', $html);
        $this->assertStringContainsString('value="b"', $html);
    }
```

In `PluginTest.php` set the version assertion to `1.1.0`. In `SettingsTest.php` add a check that the settings template links to AI Connector and no longer has a `sg_api_key` field:

```php
    public function testSettingsTemplateLinksToAiConnectorAndDropsKeyField(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/Template/config/settings.php');
        $this->assertStringNotContainsString('name="sg_api_key"', $content);
        $this->assertStringContainsString('AiConnector', $content);
        $this->assertStringContainsString('sg_max_subtasks', $content);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh SubtaskGenerator`
Expected: FAIL — modal has no `sg_profile`; settings template still has `sg_api_key`; version mismatch.

- [ ] **Step 3: Update `SubtaskGenerator/Plugin.php`** — replace `use ...\ProviderFactory;` with `use Kanboard\Plugin\SubtaskGenerator\Model\AiGate;`; in `initialize()` replace `ProviderFactory::isAiReady($this->configModel)` with `AiGate::isReady($this->container)`; keep the PHP-compat log; **remove** the `subtask-generator/test` route; set `getPluginVersion()` to `'1.1.0'`; update `getPluginDescription()` to `t('Generate subtasks from a task description using AI (provider backend supplied by the AiConnector plugin).')`. Keep the guarded vendor `require_once` **removed** — SubtaskGenerator no longer has a `vendor/`; delete those 6 lines (the `$autoload = __DIR__ . '/vendor/autoload.php'; ...` block) since AiConnector owns php-agents now.

- [ ] **Step 4: Update `GeneratorController.php`** — replace `use ...\ProviderFactory;` with `use Kanboard\Plugin\SubtaskGenerator\Model\AiGate;` and `use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;`. Change `isAiEnabled()` to `return AiGate::isReady($this->container);`. In `show()`, pass profiles to the modal:

```php
        $registry = new ProviderRegistry($this->container);
        $this->response->html($this->template->render('SubtaskGenerator:generator/modal', [
            'task'               => $task,
            'sg_prompt'          => $sg_prompt,
            'profiles'           => $registry->listProfiles(),
            'default_profile_id' => $registry->getDefaultProfileId(),
        ]));
```

In `generate()`, read + validate the profile and pass it through:

```php
        // ── Resolve the chosen profile (validate against known ids) ───────────
        $profileId = trim($this->request->getStringParam('sg_profile', ''));
        if ($profileId !== '') {
            $known = array_column((new ProviderRegistry($this->container))->listProfiles(), 'id');
            if (! in_array($profileId, $known, true)) {
                $profileId = '';
            }
        }
        $subtasks = $model->generate($prompt, $profileId !== '' ? $profileId : null);
```

- [ ] **Step 5: Update `SettingsController.php`** — remove `use ...\ProviderFactory;` and the whole `testConnection()` method. `show()` drops provider/model/key/csrf-token vars, passes only `sg_max_subtasks` + `ai_enabled`. `save()` persists only `sg_max_subtasks` (clamped 1–20). Gate helper `isAiEnabled()` → `AiGate::isReady($this->container)`:

```php
    public function show(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->response->html($this->helper->layout->config('SubtaskGenerator:config/settings', [
            'title'           => t('Settings') . ' &gt; ' . t('Subtask Generator'),
            'sg_max_subtasks' => (int) $this->configModel->get('sg_max_subtasks', (string) \Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS),
        ]));
    }

    public function save(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $maxSubtasks = max(1, min(20, (int) ($values['sg_max_subtasks'] ?? \Kanboard\Plugin\SubtaskGenerator\Model\SubtaskGeneratorModel::DEFAULT_MAX_SUBTASKS)));
        $this->configModel->save(['sg_max_subtasks' => (string) $maxSubtasks]);
        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'SubtaskGenerator']));
    }
```
Remove the old `use` for `AccessForbiddenException`? No — keep it. Remove the private `isAiEnabled()` if it's now unused, or keep and point at `AiGate`. Delete the vendor-path `isAiEnabled()` helper body that checked `vendor/autoload.php`.

- [ ] **Step 6: Update `Template/generator/modal.php`** — add, right after the opening `<form ...>` + csrf + hidden task_id (before the prompt `form-group`):

```php
    <?php if (isset($profiles) && count($profiles) >= 2): ?>
    <div class="form-group">
        <?= $this->form->label(t('AI provider'), 'sg_profile') ?>
        <select name="sg_profile" id="sg_profile" class="form-select">
            <?php foreach ($profiles as $p): ?>
                <option value="<?= $this->text->e($p['id']) ?>" <?= ($p['id'] === ($default_profile_id ?? '')) ? 'selected' : '' ?>>
                    <?= $this->text->e($p['label']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </div>
    <?php endif ?>
```

- [ ] **Step 7: Rewrite `Template/config/settings.php`** — trim to the max-subtasks field + an AI Connector link:

```php
<div class="page-header">
    <h2><?= t('Subtask Generator — Settings') ?></h2>
</div>

<div class="alert alert-info">
    <?= t('Provider setup (Anthropic, OpenAI, Grok, Gemini, Mistral, Ollama, …) now lives in the AI Connector plugin.') ?>
    <?= $this->url->link(t('Open AI Connector settings'), 'SettingsController', 'show', ['plugin' => 'AiConnector']) ?>
</div>

<form method="post"
      action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'SubtaskGenerator']) ?>">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Generation Limits') ?></legend>
        <?= $this->form->label(t('Maximum subtasks to generate'), 'sg_max_subtasks') ?>
        <input type="number" name="sg_max_subtasks" id="sg_max_subtasks"
               value="<?= (int) $sg_max_subtasks ?>" min="1" max="20" class="form-text">
        <p class="form-help"><?= t('Maximum number of subtasks the AI may suggest (1–20). Default: 8.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save Settings') ?></button>
        <?= $this->url->link(t('Cancel'), 'ConfigController', 'index') ?>
    </div>
</form>
```

- [ ] **Step 8: Trim `Assets/js/subtask-generator.js`** — delete the two trailing settings-page handlers (the `#sg-test-btn` click listener + `runTestConnection` and the `#sg_provider` change auto-fill listener); keep everything through the generate/create modal logic. Those settings controls no longer exist in SubtaskGenerator.

- [ ] **Step 9: Update `SubtaskGenerator/plugin.json`:**

```json
{
    "name": "SubtaskGenerator",
    "description": "Generate subtasks from a task description using AI (provider backend supplied by the AiConnector plugin).",
    "version": "1.1.0",
    "author": "Carmelo Santana",
    "homepage": "https://github.com/vctrs-io/kanboard-subtask-generator",
    "license": "MIT",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "requires": [
        { "plugin": "AiConnector", "min_version": "1.0.0", "reason": "provides the AI provider backend" }
    ]
}
```

- [ ] **Step 10: Run to verify it passes**

Run: `./testing/run-plugin-tests.sh SubtaskGenerator`
Expected: PASS. Then re-run AiConnector to confirm no regression:
Run: `./testing/run-plugin-tests.sh AiConnector`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add -A SubtaskGenerator
git commit -m "feat(SubtaskGenerator): point-of-use profile dropdown, AiGate, settings trim, hard requires on AiConnector (v1.1.0)"
```

---

## Task 7: Docs — README + CHANGELOG for both plugins

**Files:**
- Create: `AiConnector/README.md`, `AiConnector/CHANGELOG.md`
- Modify: `SubtaskGenerator/README.md`, `SubtaskGenerator/CHANGELOG.md`

- [ ] **Step 1: Write `AiConnector/README.md`** — cover: what it is (multi-provider AI backend), requirements (Kanboard ≥1.2.47, PHP ≥8.4, `composer install` for `vendor/`), the 7 provider types, profiles + default, key storage (separate + masked + env-fallback table), the `ProviderRegistry` API (`listProfiles/getDefaultProfileId/isReady/buildProvider/structured` with signatures + the message shape), the php-agents load-order rule, and a "for plugin authors" snippet showing `new ProviderRegistry($this->container)->structured($messages, $schema)`.

- [ ] **Step 2: Write `AiConnector/CHANGELOG.md`:**

```markdown
# Changelog

All notable changes to AiConnector will be documented here.

## [1.0.0] — 2026-07-10

### Added
- **Provider profiles**: named `{id,label,provider,model,base_url}` profiles with one global default; add/edit/remove in Settings → AI Connector.
- **Seven provider types** (all HTTP, via php-agents): Anthropic, OpenAI (Chat Completions), OpenAI Responses (Codex/gpt-5), Grok (xAI), Gemini, Mistral, and Ollama (keyless).
- **`ProviderRegistry` PHP API** for other plugins: `listProfiles()`, `getDefaultProfileId()`, `isReady()`, `buildProvider()`, and a provider-agnostic `structured()` that normalizes both php-agents return shapes to a decoded PHP array.
- **Secret handling**: API keys stored separately from the profiles JSON (`aiconnector_key_<id>`), masked in the UI, never logged/echoed, with per-provider env-var fallback (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `XAI_API_KEY` / `GEMINI_API_KEY` / `MISTRAL_API_KEY`; Ollama keyless, honours `OLLAMA_HOST`).
- **Per-profile Test Connection** (admin, reusable-CSRF, CSP-safe external JS).
- **Bundled php-agents** in `vendor/`; loaded only at request time (load-order-safe).
```

- [ ] **Step 3: Update `SubtaskGenerator/README.md`** — change the "Supports Anthropic/OpenAI/Grok" framing to "provider setup lives in the **AiConnector** plugin (required)"; document the point-of-use provider dropdown (shown when ≥2 profiles); remove the `composer install` step (no local vendor); add AiConnector to Requirements.

- [ ] **Step 4: Prepend to `SubtaskGenerator/CHANGELOG.md`:**

```markdown
## [1.1.0] — 2026-07-10

### Changed (breaking)
- **Provider configuration moved to the new AiConnector plugin.** SubtaskGenerator no longer bundles php-agents or stores provider/model/API-key settings; it now **requires** AiConnector (declared via `requires` in `plugin.json`).
- Existing `sg_provider` / `sg_model` / `sg_api_key` settings are ignored — **reconfigure providers in Settings → AI Connector** (no automatic migration).

### Added
- **Point-of-use provider dropdown** in the Generate-subtasks modal, shown when ≥2 AiConnector profiles exist (with one profile the default is used silently).
```

- [ ] **Step 5: Commit**

```bash
git add AiConnector/README.md AiConnector/CHANGELOG.md SubtaskGenerator/README.md SubtaskGenerator/CHANGELOG.md
git commit -m "docs(AiConnector,SubtaskGenerator): READMEs + CHANGELOGs for the provider-backend split"
```

---

## Final verification (after all tasks)

- [ ] `./testing/run-plugin-tests.sh AiConnector` → all green.
- [ ] `./testing/run-plugin-tests.sh SubtaskGenerator` → all green (no network).
- [ ] `grep -rn "CarmeloSantana\\\\PHPAgents" SubtaskGenerator --include=*.php` → **no matches**.
- [ ] `test ! -e SubtaskGenerator/vendor && test ! -e SubtaskGenerator/Model/ProviderFactory.php` → both gone.
- [ ] `grep -n requires SubtaskGenerator/plugin.json` → the hard requires present.
- [ ] Live smoke on `testing/docker-compose.dev.yml` (`:8081`, admin/admin): AI Connector settings render, add + save a profile, Test Connection wiring responds, SubtaskGenerator modal renders (dropdown when ≥2 profiles). Capture evidence.
- [ ] Whole-branch review on the most capable model → no Critical.
