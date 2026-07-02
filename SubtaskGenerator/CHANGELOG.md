# Changelog

All notable changes to SubtaskGenerator will be documented here.

## [Unreleased]

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
