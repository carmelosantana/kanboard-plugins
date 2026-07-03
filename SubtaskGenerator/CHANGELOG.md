# Changelog

All notable changes to SubtaskGenerator will be documented here.

## [1.0.0] — 2026-07-02

### Added
- **Provider settings** (Settings → SubtaskGenerator): select Anthropic (default), OpenAI, or Grok
  (xAI); configure model + API key per provider; key is masked in the UI and never echoed back.
- **PHP 8.4 + provider gate**: AI features are enabled only when PHP >= 8.4, `vendor/autoload.php`
  is present, and a provider API key is resolvable (config DB or env var fallback).
- **Task-view modal**: "Generate subtasks" sidebar link opens a modal with an editable prompt
  textarea pre-filled from the task title and description.
- **Generate endpoint**: calls `ProviderInterface::structured()` with a JSON schema and normalises
  the result — trims titles, deduplicates (case-insensitive), clamps to `sg_max_subtasks`.
- **Create endpoint**: posts the user-selected/edited candidate titles; skips blanks; tolerates
  partial DB failures (one failed subtask does not abort the rest); flash notice reports counts.
- **Security hardening**: API key stored via `configModel`, never echoed in HTML, never logged;
  error_log records only `get_class($e) . ' code ' . $e->getCode()` (no message, no URL).
- **Graceful degradation**: provider unreachable / timeout / malformed / empty output → friendly
  modal error message, no 500, nothing created. Regenerate `.catch` also hides stale results.
- **Test suite** (79+ tests, zero network): provider factory per config, generate() normalization
  + all error paths, create() selection/blanks/permission/partial-failure (behavioral).
- **Docs**: README covering PHP 8.4 requirement, Anthropic/OpenAI/Grok config, API-key security
  note, usage walkthrough, and graceful-degradation behavior.

### Changed
- Version bumped from 0.1.0 → 1.0.0 (first stable release).
- `plugin.json` and `Plugin::getPluginVersion()` now agree on `1.0.0`.
- `kanboard_version` requirement set to `>=1.2.47`; `php_version` set to `>=8.4`.

## [0.1.0] — 2026-07-02

### Added
- Plugin skeleton: `Plugin.php`, `plugin.json`, `composer.json`.
- Vendored `carmelosantana/php-agents` (from path repo) with `symfony/http-client` deps.
  `psr/log` and `psr/container` are excluded from vendor (replaced) to avoid double-declaration
  with Kanboard core's copies.
- PHP 8.4 hard gate in `initialize()`: on PHP < 8.4 the plugin loads cleanly but logs a notice
  and skips AI feature wiring. Gate is testable via `isPhpCompatible(?int $versionId)`.
- `Test/PluginTest.php`: smoke tests for metadata, PHP gate, vendor presence, and provider class
  resolution (Anthropic, OpenAI Responses, OpenAI Compatible, xAI/Grok).
