# AiConnector — Design Spec

- **Date:** 2026-07-10
- **Status:** Approved (brainstorm/design locked by orchestrator) — ready for implementation plan
- **Repo:** `kanboard-plugins` (Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT)
- **Branch:** `feat/aiconnector-split`
- **Suite position:** Extracts the AI-provider backend out of `SubtaskGenerator` into a standalone,
  reusable plugin. `SubtaskGenerator` becomes its first consumer and declares the suite's **first
  hard `requires`** on `AiConnector` (ModMenu 1.1.0 already enforces `requires`).

## 1. Goal

Today `SubtaskGenerator` bundles [php-agents](path repo at `/home/carmelo/Projects/CoquiBot/Core/php-agents`)
in its own `vendor/` and hard-wires three providers via a static `ProviderFactory` reading global
config keys `sg_provider`/`sg_model`/`sg_api_key`. One active provider, no on-the-fly switching, and
every future AI plugin would duplicate the wiring.

Extract that backend into **`AiConnector`** — a standalone plugin that owns php-agents plus *all*
provider configuration and exposes a small, stable PHP API (`ProviderRegistry`) that other plugins
call. Support **named provider profiles** (multiple configured providers, one global default), a
config UI to manage them, per-profile Test Connection, and a provider-agnostic `structured()` entry
point that normalizes php-agents' two return shapes to a decoded PHP array — so consumers never
reference a `CarmeloSantana\PHPAgents\*` class themselves.

Two deliverables in one branch:
- **A.** New plugin `AiConnector/` (profiles + config UI + `ProviderRegistry` API + php-agents).
- **B.** Refactor `SubtaskGenerator/` to consume `AiConnector`, drop its own provider code and
  vendored php-agents, add a point-of-use provider dropdown, and hard-`requires` `AiConnector`.

## 2. Global Constraints

- **Kanboard** ≥ 1.2.47; **PHP** ≥ 8.4; **buildless** (no compile step, no CDN — external assets
  only); **MIT**; author "Carmelo Santana"; homepage `https://github.com/carmelosantana/kanboard-plugins`.
- **CSP** (`default-src 'self'`): no inline `<script>`, no inline event handlers; inline
  `<style>`/`style=""` allowed. All JS in external `Assets/js/*.js`, injected via `template:layout:js`.
- **CSRF:** form posts validated with `$this->checkCSRFForm()` (forms carry `$this->form->csrf()`);
  fetch/JS endpoints (Test Connection) use a reusable token: `$this->token->getReusableCSRFToken()`
  generated in the **controller** and passed via `data-*`, validated with `checkReusableCSRFParam()`.
  The reusable token **must** be generated in the controller — `token` is not a registered template
  helper, so `$this->token->...` inside a template throws mid-render and drops the page out of layout
  (this exact bug shipped and was fixed in SubtaskGenerator 1.0.1).
- **Authorization:** all mutating settings actions (save profile, delete profile, set default) and
  Test Connection are **admin-only** (`if (! $this->userSession->isAdmin()) throw new
  AccessForbiddenException();`), each also CSRF-gated.
- **Routes:** clean-URL ids; `$this->route->addRoute('path', 'Controller', 'action')` (the 3-arg
  suite form; the 4-arg form with a plugin token is also valid — match the existing plugins).
- **Secrets:** never log, echo, or persist-empty an API key; mask stored keys in the UI (`••••••••`
  placeholder); never put a key in an exception message or the profiles JSON.
- **php-agents load-order rule (HARD):** only `AiConnector` bundles php-agents.
  `AiConnector::initialize()` does `require_once __DIR__.'/vendor/autoload.php'` guarded with
  `file_exists`. php-agents classes may be referenced **only at request-handling time**
  (controller/model during a route), **never** inside any plugin's `initialize()` — Kanboard's
  loader gives no cross-plugin init-order guarantee, so AiConnector's autoloader may not yet be
  registered when another plugin initializes.
- **Unit tests never hit the network** — inject a fake `ProviderInterface` (or a fake registry) so
  `structured()` / Test Connection make no HTTP call. Test harness (`run-plugin-tests.sh`) runs
  PHPUnit against in-memory SQLite core and does **not** run the plugin loader.
- **`getCompatibleVersion()` returns `>=1.2.47`.** `PluginTest` asserts that string + the plugin
  **name** — never a hardcoded plugin *version* (that assertion has gone stale before; do not add it).

## 3. Architecture Overview

```
   consumer plugin (SubtaskGenerator, …)
        │  new ProviderRegistry($this->container)
        ▼
  ┌──────────────────────────────────────────────────────────────────┐
  │ AiConnector\Model\ProviderRegistry  (extends Kanboard\Core\Base)  │
  │   listProfiles()  getDefaultProfileId()  isReady()                │
  │   buildProvider(?id)  structured(messages, schema, ?id)           │
  └───────┬───────────────────────────┬──────────────────────────────┘
          │ reads                      │ builds + calls
          ▼                            ▼
  ProfileStore (config)          php-agents ProviderInterface
   aiconnector_profiles JSON      (Anthropic / OpenAI / OpenAIResponses /
   aiconnector_default id         XAI / Gemini / Mistral / Ollama)
   aiconnector_key_<id> secrets          │ structured() → array | Response
                                          ▼
                              normalizeStructuredResult() → decoded PHP array
```

- **Settings UI** (admin): `SettingsController` + `Template/config/settings.php` list profiles with
  add/edit/remove, a default selector, and per-profile **Test Connection**. External CSP-safe
  `Assets/js/ai-connector.js` drives Test Connection via a reusable-CSRF fetch.
- **Consumers** depend ONLY on the `ProviderRegistry` PHP API; `buildProvider()` remains for advanced
  consumers who knowingly opt into the php-agents coupling.

## 4. Plugin identity & packaging

- Folder `AiConnector/`, namespace `Kanboard\Plugin\AiConnector`, settings title "AI Connector".
- **`composer.json`** — copy `SubtaskGenerator/composer.json` **verbatim**, changing only:
  - `name` → `carmelosantana/kanboard-ai-connector`
  - `description` → e.g. "Kanboard plugin — universal multi-provider AI backend (php-agents) with named provider profiles and a shared PHP API"

  Keep unchanged: the php-agents path repo
  (`type: path`, `url: /home/carmelo/Projects/CoquiBot/Core/php-agents`, `options.symlink: false`),
  `require` `{ "php": ">=8.4", "carmelosantana/php-agents": "dev-main" }`, the
  `"replace": {"psr/log":"*","psr/container":"*"}` block, `config.optimize-autoloader:true` +
  `config.vendor-dir:"vendor"`, `minimum-stability: dev`, `prefer-stable: true`. Run
  `composer install` inside `AiConnector/` to produce `vendor/` (committed, like SubtaskGenerator's was).
- **`plugin.json`**:
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
- **`Plugin.php`** metadata methods mirror SubtaskGenerator's (`getPluginName` → `AiConnector`,
  version `1.0.0`, `getCompatibleVersion` → `>=1.2.47`, homepage as above). No AI-ready gate in the
  plugin itself — AiConnector always loads; readiness is a per-call `ProviderRegistry::isReady()`.

## 5. Data model — provider profiles

A **profile** describes one configured provider endpoint:

```
{ id: string, label: string, provider: string, model: string, base_url?: string }
```

- `id` — stable, slug-ish, unique (e.g. `p_<8 hex>` or a sanitized slug of the label). Never reused
  for a different profile; used to key the secret store.
- `label` — human name shown in dropdowns ("Claude Sonnet", "Local Ollama").
- `provider` — one of the seven supported **provider types** (§6).
- `model` — model id string (provider-specific).
- `base_url` — optional override; defaults per provider type (§6). Only meaningful for the
  OpenAI-compatible family (openai, ollama) and any self-hosted endpoint; ignored where the provider
  hardcodes its host (anthropic/grok/gemini/mistral use php-agents defaults unless overridden).

The **API key is NOT part of the profile struct** — it lives in a separate config key so it never
enters the profiles JSON and is never echoed to a template.

### Storage (all via `configModel`)

| Config key | Holds |
|---|---|
| `aiconnector_profiles` | JSON array of profile structs **without keys** (`id/label/provider/model/base_url`) |
| `aiconnector_default` | the `id` of the global default profile (`''` if none) |
| `aiconnector_key_<id>` | the API key for profile `<id>`, stored separately, never echoed |

Deleting a profile removes its struct from `aiconnector_profiles`, clears `aiconnector_key_<id>`,
and — if it was the default — reassigns `aiconnector_default` to the first remaining profile (or `''`).

### Secret discipline (mirror SubtaskGenerator)

- `KEY_PLACEHOLDER = '••••••••'`. The key `<input type=password>` always renders `value=""`.
- On save, **never** overwrite a stored key with an empty or placeholder submission (reuse the
  SubtaskGenerator `$isPlaceholder = ($submitted === '' || $submitted === KEY_PLACEHOLDER)` guard).
- Never log a key; never place a key in an exception message or JSON response.

### Env-var fallback per provider type

When `aiconnector_key_<id>` is empty, resolve from the environment by provider type:

| provider | env var | notes |
|---|---|---|
| `anthropic` | `ANTHROPIC_API_KEY` | |
| `openai` | `OPENAI_API_KEY` | Chat Completions |
| `openai_responses` | `OPENAI_API_KEY` | same key as openai |
| `grok` | `XAI_API_KEY` | |
| `gemini` | `GEMINI_API_KEY` | |
| `mistral` | `MISTRAL_API_KEY` | |
| `ollama` | — | keyless; respect `OLLAMA_HOST` for the base URL |

Resolution order (single source of truth in a `resolveApiKey($providerType, $storedKey)` helper):
stored key → env var → `''`.

## 6. Supported provider types (v1)

All seven are HTTP providers already implemented in php-agents. Do **not** wire `LlamaCppProvider`
or `CliProvider` (deferred).

| type | php-agents class | default base_url | keyless | `structured()` returns |
|---|---|---|---|---|
| `anthropic` | `AnthropicProvider` | `https://api.anthropic.com/v1` (class default) | no | **array** (`tool_use['input']`) or `Response` fallback |
| `openai` | `OpenAICompatibleProvider` | `https://api.openai.com/v1` | no | `Response` (JSON in `->content`) |
| `openai_responses` | `OpenAIResponsesProvider` | `https://api.openai.com/v1` (class default) | no | **array** (`json_decode(arguments)`) or `Response` fallback |
| `grok` | `XAIProvider` | `https://api.x.ai/v1` (class default) | no | `Response` |
| `gemini` | `GeminiProvider` | `https://generativelanguage.googleapis.com/v1beta` (class default) | no | `Response` |
| `mistral` | `MistralProvider` | `https://api.mistral.ai/v1` (class default) | no | `Response` |
| `ollama` | `OllamaProvider` | `http://localhost:11434/v1` | **yes** | `Response` |

**Return-shape consequence:** across all seven, `structured()` yields either an already-decoded PHP
array or a `Response` whose `->content` is a JSON string. The existing SubtaskGenerator
`normaliseStructuredResult()` (array → use as-is; `Response` → `json_decode($r->content, true)`;
anything else → `[]`) therefore covers **every** provider. **Move that logic into `ProviderRegistry`.**

### Provider construction (verified php-agents constructor signatures)

- `new AnthropicProvider(model: $model, apiKey: $key)` — baseUrl defaults; pass `baseUrl:` only if a
  profile override is set.
- `new OpenAICompatibleProvider(model: $model, baseUrl: $base ?: 'https://api.openai.com/v1', apiKey: $key)`.
- `new OpenAIResponsesProvider(model: $model, baseUrl: $base ?: default, apiKey: $key)`.
- `new XAIProvider(model: $model, apiKey: $key)` (baseUrl defaults to x.ai).
- `new GeminiProvider(model: $model, apiKey: $key)` (baseUrl defaults).
- `new MistralProvider(model: $model, apiKey: $key)` (baseUrl defaults).
- `new OllamaProvider(model: $model, baseUrl: $base ?: 'http://localhost:11434/v1')` — **no apiKey
  param** (keyless). If `OLLAMA_HOST` is set and the profile base_url is empty, use
  `rtrim(OLLAMA_HOST,'/').'/v1'` (php-agents strips the `/v1` internally to hit `/api/*`).

Named args are used so optional params (httpClient/logger) keep their defaults, exactly as
`SubtaskGenerator\Model\ProviderFactory` does today.

### Default model per provider type

Provide a `DEFAULT_MODELS` map for placeholder/prefill (not enforced; the admin sets the model per
profile). Suggested v1 defaults:

| type | default model |
|---|---|
| anthropic | `claude-sonnet-4-20250514` |
| openai | `gpt-4o` |
| openai_responses | `gpt-5` |
| grok | `grok-3` |
| gemini | `gemini-2.5-flash` |
| mistral | `mistral-large-latest` |
| ollama | `llama3.2` |

## 7. Public API — `Kanboard\Plugin\AiConnector\Model\ProviderRegistry`

`extends Kanboard\Core\Base`. Consumers instantiate directly: `new ProviderRegistry($this->container)`
(the cross-plugin pattern the suite already uses; Kanboard auto-registers each plugin's PSR-4
namespace regardless of init order, so the class is autoloadable whenever `AiConnector/` is present).

```php
/** Profiles for building dropdowns. NEVER includes keys. */
public function listProfiles(): array;
//   → [ ['id'=>string,'label'=>string,'provider'=>string,'model'=>string], ... ]

/** The default profile id, or '' if none configured. */
public function getDefaultProfileId(): string;

/** True when ≥1 profile exists with a resolvable key (stored or env). Ollama counts (keyless). */
public function isReady(): bool;

/**
 * Build a configured php-agents provider for $profileId (or the default when null).
 * @throws \RuntimeException on unknown/unconfigured profile — message NEVER contains a key.
 */
public function buildProvider(?string $profileId = null): \CarmeloSantana\PHPAgents\Contract\ProviderInterface;

/**
 * Provider-agnostic structured call. $messages is [['role'=>'system'|'user'|'assistant','content'=>string], ...].
 * Maps to php-agents SystemMessage/UserMessage/AssistantMessage INTERNALLY, calls structured(),
 * and normalizes BOTH return shapes to a decoded PHP array.
 * @throws \RuntimeException on unknown/unconfigured profile (no key in message).
 */
public function structured(array $messages, string $schema, ?string $profileId = null): array;
```

### Behaviour details

- **`listProfiles()`** returns the profiles JSON minus `base_url` (dropdowns need only id/label/
  provider/model). Order = storage order. Empty array when none.
- **`getDefaultProfileId()`** reads `aiconnector_default`; if it points to a missing profile, returns
  `''` (defensive) — callers treat `''` as "no default".
- **`isReady()`** iterates profiles; returns true on the first whose resolved key is non-empty OR
  whose provider type is `ollama` (keyless). No network call.
- **`buildProvider($id)`** resolves `$id` (null → default; `''`/unknown → throw). Reads the profile
  struct + `aiconnector_key_<id>` (+ env fallback) and constructs the php-agents provider per §6.
  Throws `\RuntimeException` with a message naming the *profile id/label and provider type only* —
  never the key — when the profile is unknown or the provider type unsupported.
- **`structured($messages, $schema, $id)`** maps each `$messages` row to `SystemMessage` /
  `UserMessage` / `AssistantMessage` by `role` (unknown role → treat as user, defensive), calls
  `buildProvider($id)->structured($mapped, $schema)`, then `normalizeStructuredResult()` → array.
  This is the ONLY method consumers need for generation; they never touch a php-agents class.

### Test seam

`ProviderRegistry` must be unit-testable without network. Provide an injection seam so tests supply a
fake `ProviderInterface`: e.g. a `setProviderForTesting(ProviderInterface $p)` (or a protected
`buildProvider()` overridable via anonymous subclass) that `structured()` uses instead of building
from config. Consumers (SubtaskGenerator) additionally get to inject a fake **registry** (§9).

### php-agents timing

Every php-agents reference (`SystemMessage`, `UserMessage`, `AssistantMessage`, `Response`, provider
classes, `ProviderInterface`) sits inside `buildProvider()`/`structured()`/`normalize…()` method
bodies — reached only at request time. The class file itself references php-agents only in a
`use`/type position on those method signatures; PHP does not autoload a type-hinted class until the
method is actually invoked, so merely loading `ProviderRegistry` (e.g. `class_exists` from a consumer
during `initialize()`) does not require the php-agents autoloader. Document this invariant in the
class docblock.

## 8. Settings UI (admin-only)

**Decision (resolves OPEN UNKNOWN §4b "Profiles UI mechanics"): list + single add/edit form.**
An inline-editable table would need one password field per row and JS to submit each row; a list plus
one reused server-rendered add/edit form keeps keys strictly write-only with the proven
SubtaskGenerator masking pattern and less JS. Chosen for simplicity.

- **Sidebar link** via `template:config:sidebar` → "AI Connector" (`Template/config/sidebar.php`),
  linking to `SettingsController::show` (`plugin=AiConnector`).
- **`show()`** (admin-gated): renders `helper->layout->config('AiConnector:config/settings', …)` with:
  - the profile list (id/label/provider/model, a "default" marker, edit + remove controls),
  - a single **add/edit form**: label, provider `<select>` (7 types), model text input (placeholder
    = default model for the selected type), optional base_url, and a masked API key field
    (`value=""`, password). Editing loads a profile by `?edit=<id>` — the controller pre-fills
    label/provider/model/base_url but **never** the key (field stays blank; a "key is stored" note
    shows when `aiconnector_key_<id>` is non-empty).
  - a default-profile selector (radio in the list, or a `<select>` posting to a `setDefault` action).
  - per-profile **Test Connection** button (see below).
  - the reusable CSRF token for Test Connection, generated in the controller as `$ai_test_csrf`.
- **`save()`** (admin + `checkCSRFForm()`): validates provider type ∈ supported set (else reject to a
  safe default), trims model (empty → default for type), sanitizes/derives `id` (new profile → mint a
  fresh unique id; edit → keep id). Persists the struct into `aiconnector_profiles`; writes
  `aiconnector_key_<id>` only when a real (non-placeholder, non-empty) key was submitted. If this is
  the first profile, set it as default. Flash + redirect to `show`.
- **`delete()`** (admin + CSRF): removes the profile struct + its `aiconnector_key_<id>`, fixes up
  `aiconnector_default`. Flash + redirect.
- **`setDefault()`** (admin + CSRF): sets `aiconnector_default` to a valid existing id. Flash + redirect.
- **`testConnection()`** (admin + `checkReusableCSRFParam()`): reads a `profile` id from the query,
  builds that provider via `ProviderRegistry`, makes a **minimal** `structured()` call (ask for
  `{ok:boolean}`), returns `{ok:true}` or `{ok:false, error:<message>}` — never echoing the key or the
  raw model output. Reuse SubtaskGenerator's proven wiring exactly: external `Assets/js/ai-connector.js`
  injected via `template:layout:js`, URL + i18n via `data-*`, token via `data-*`.

All template output escaped (`$this->text->e(...)` / `htmlspecialchars(..., ENT_QUOTES)`); the key is
never rendered into any attribute or body.

### Routes (`Plugin::initialize()`)

```
ai-connector/settings   → SettingsController::show
ai-connector/save       → SettingsController::save
ai-connector/delete     → SettingsController::delete
ai-connector/default    → SettingsController::setDefault
ai-connector/test       → SettingsController::testConnection
```

Plus the two hooks: `template:config:sidebar` (sidebar link) and `template:layout:js`
(`plugins/AiConnector/Assets/js/ai-connector.js`). `initialize()` also does the guarded
`require_once __DIR__.'/vendor/autoload.php'`. **No php-agents class is referenced in `initialize()`.**

## 9. SubtaskGenerator refactor

**Delete:** `Model/ProviderFactory.php`, the bundled `vendor/`, `composer.json`, `composer.lock`, and
the `carmelosantana/php-agents` dependency. Remove every `use CarmeloSantana\PHPAgents\*` from
SubtaskGenerator code (model, controllers, and the now-obsolete provider settings).

**`SubtaskGeneratorModel`:**
- `generate(string $prompt, ?string $profileId = null): array` builds
  `$messages = [['role'=>'system','content'=>SYSTEM_PROMPT], ['role'=>'user','content'=>$prompt]]`
  and calls `(new ProviderRegistry($this->container))->structured($messages, $schema, $profileId)`,
  then runs the existing `normalise()` (title-extract / trim / case-insensitive dedupe / clamp to
  `sg_max_subtasks`). `SCHEMA` and `SYSTEM_PROMPT` stay in this model.
- The `normaliseStructuredResult()` shape-handling **moves to `ProviderRegistry`**; the model no
  longer sees `Response` or php-agents at all — `structured()` already returns a decoded array.
- **Test seam:** allow injecting a fake registry (preferred) so unit tests make no network call.
  E.g. `setRegistry(ProviderRegistry $r)` used by `generate()`; the registry itself is injected with
  a fake provider, OR `generate()` accepts an already-built registry. Keep the existing
  `SubtaskGeneratorModelTest` semantics: a canned provider result flows through `generate()` and is
  normalized/deduped/clamped. `sg_max_subtasks` default constant moves from `ProviderFactory` into
  `SubtaskGeneratorModel` (e.g. `DEFAULT_MAX_SUBTASKS = 8`).

**AI-ready gate** — becomes "PHP ≥ 8.4 **AND** AiConnector present **AND**
`ProviderRegistry::isReady()`". Ask AiConnector; do **not** read provider config locally anymore.
- Presence check: `class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class)` — safe in
  `initialize()` (loads the class file but not php-agents; see §7). Then
  `(new ProviderRegistry($this->container))->isReady()`.
- A small local helper (replacing `ProviderFactory::isAiReady`) computes the gate; both
  `Plugin::initialize()` (sidebar link visibility) and `GeneratorController::isAiEnabled()` delegate
  to it so they stay identical. Signature keeps testable overrides (PHP version id; optionally a
  presence flag) so tests are deterministic without AiConnector installed.
- Not ready → hide the task-sidebar link and 403 the `show`/`generate`/`create` routes, as today.

**Generate modal** (`Template/generator/modal.php`): add a provider `<select name="sg_profile">`
populated from `ProviderRegistry::listProfiles()`, pre-selected to `getDefaultProfileId()`. **Show
the dropdown only when ≥2 profiles exist**; with exactly one profile, omit it and use the default
silently. `GeneratorController::generate()` reads `sg_profile` (validate it is a known profile id;
empty/unknown → null → default) and passes it to `SubtaskGeneratorModel::generate($prompt, $profileId)`.
The controller passes `profiles` + `default_profile_id` into the modal template. External JS
(`subtask-generator.js`) already sends the whole form via `FormData`, so `sg_profile` rides along
automatically — a tiny addition, no new fetch plumbing.

**SubtaskGenerator settings page:** drop the provider/model/key fields **and** the Test Connection
block (those now live in AiConnector). Keep `sg_max_subtasks`. Add a short note linking admins to
**Settings → AI Connector** for provider setup. Stop reading `sg_provider`/`sg_model`/`sg_api_key`
entirely. **No migration** — reconfigure fresh (leaving orphan `sg_*` keys is acceptable per §4b).
`SettingsController` keeps `save()` (only `sg_max_subtasks`) and `show()`; the old `testConnection()`
action + route are removed.

**`plugin.json`:** add the hard requires and bump the version:
```json
{
  "name": "SubtaskGenerator",
  "version": "1.1.0",
  "kanboard_version": ">=1.2.47",
  "php_version": ">=8.4",
  "requires": [
    { "plugin": "AiConnector", "min_version": "1.0.0", "reason": "provides the AI provider backend" }
  ]
}
```
(Version `1.1.0` per §4b; the orchestrator may relabel `2.0.0` at release — build with `1.1.0`,
don't block.) `Plugin::getPluginVersion()` returns `1.1.0`; `PluginTest` updated accordingly.

**CHANGELOG:** call out the breaking split — provider/model/key config moved to AiConnector;
AiConnector is now **required**; existing `sg_*` provider settings are ignored (reconfigure in AI
Connector). README: point provider setup at AI Connector; document the point-of-use dropdown.

## 10. Dev-harness wiring

- **`testing/docker-compose.dev.yml`:** add `- ../AiConnector:/var/www/app/plugins/AiConnector` to
  the volumes list (alongside the other 8 mounts).
- **`testing/run-plugin-tests.sh`:** add `AiConnector` to BOTH lists — the usage `echo` (available
  plugins) and the symlink-loop array.

## 11. Tests

**AiConnector `Test/`** (all no-network; load `vendor/autoload.php` in `setUpBeforeClass` like SG):
- `PluginTest` — metadata (name `AiConnector`, version `1.0.0`, compatible `>=1.2.47`),
  `vendor/autoload.php` present, php-agents provider classes resolve after autoload.
- `ProviderRegistryTest` — `listProfiles()` returns structs **without** any key field;
  `getDefaultProfileId()` (`''` when none; valid id when set; `''` when dangling); `isReady()` true
  with a stored key, true for a keyless ollama profile, true via env fallback, false with none;
  `buildProvider()` returns the correct php-agents class per type for all 7 types and throws (no key
  in message) on unknown/unconfigured; `structured()` normalizes an injected **array** result and an
  injected **`Response`** result to a decoded array, and returns `[]` on null/garbage (fake provider
  injected — no network).
- `SettingsTest` — non-admin → `AccessForbiddenException` on show/save/delete/setDefault; blank &
  placeholder key on save preserve the stored `aiconnector_key_<id>`; real key persists; profiles
  JSON never contains a key; template renders `value=""` for the key input and does not echo a stored
  key; reusable CSRF token generated in the controller (not the template); Test Connection URL carries
  the token; deleting the default reassigns it.
- (Structure-check parity with SG where behavioral drive is impractical.)

**SubtaskGenerator `Test/`** — update to the new backend:
- Replace `ProviderFactory`/php-agents imports. `SubtaskGeneratorModelTest`: inject a **fake registry**
  whose `structured()` returns canned arrays; assert generate() normalizes/dedupes/clamps (keep every
  existing case, now driven through the registry seam). Remove tests asserting `Response`-shape
  handling *in the model* (that logic moved to AiConnector — assert it there instead).
- `PluginTest` version → `1.1.0`; gate tests updated to the new "PHP + AiConnector present + isReady"
  gate (deterministic via overrides, not requiring AiConnector in the test tree).
- `SettingsTest` — drop provider/model/key/testConnection assertions; keep `sg_max_subtasks`
  save/persist + admin gate + CSRF; assert the settings template links to AI Connector and no longer
  renders a key field.
- `GeneratorTest`/`CreateSubtaskTest` — modal now includes `sg_profile` **only when ≥2 profiles**;
  add coverage that the dropdown is absent with 0/1 profile and present with ≥2, and that `generate()`
  passes the selected profile through. Existing show/generate/create gate + permission + CSRF cases
  stay green.

## 12. Definition of Done (from the task)

- `AiConnector/`: `Plugin.php`, `Model/ProviderRegistry.php`, `Controller/SettingsController.php`,
  `Template/config/{sidebar,settings}.php`, `Assets/js/ai-connector.js`, `Test/`, `plugin.json`
  (1.0.0), `composer.json`, built `vendor/` with php-agents.
- `ProviderRegistry` exposes the five §7 methods with the stated signatures; `structured()` handles
  both provider return shapes.
- All 7 provider types buildable; keys stored separately + masked + env-fallback; never logged.
- SubtaskGenerator: no php-agents, no `CarmeloSantana\PHPAgents\*`, `ProviderFactory.php` deleted,
  hard `requires` on AiConnector, version 1.1.0, modal dropdown when ≥2 profiles.
- `./testing/run-plugin-tests.sh AiConnector` and `… SubtaskGenerator` both green (no network).
- Harness: AiConnector mounted in `docker-compose.dev.yml` and in both `run-plugin-tests.sh` lists.
- Live smoke on `:8081` (admin/admin): AI Connector settings render, add + save a profile, Test
  Connection wiring responds, SubtaskGenerator modal renders (dropdown when ≥2 profiles). The
  hard-`requires` *gate* can't be exercised in the bind-mounted stack (ModMenu can't move a mount) —
  it's covered by ModMenu's own unit tests.
- Whole-branch review returns no Critical; README + CHANGELOG updated for both plugins.

## 13. Out of scope

No push / no `gh release` / no directory-repo edits / no roadmap edit (orchestrator owns those). No
LlamaCpp/CliProvider. No agents/tools/memory/streaming/embeddings surface — v1 is providers +
`structured()` only. No auto-migration of legacy `sg_*`. Do not modify ModMenu / DependencyResolver
(single hard edge, no diamond/cycle). Do not modify php-agents.

## 14. Resolved open unknowns (§4b)

- **Profiles UI mechanics** → **list + single reused add/edit form** (keys strictly write-only,
  least JS). §8.
- **SubtaskGenerator version** → build `1.1.0`; orchestrator may relabel at release. §9.
- **Legacy key cleanup** → leave orphan `sg_*` keys; no migration. §9.
- **Ollama base URL** → default `http://localhost:11434/v1` (php-agents strips `/v1` for native
  endpoints); `OLLAMA_HOST` (if set, profile base empty) → `rtrim(host,'/').'/v1'`. §6.
