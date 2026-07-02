# SubtaskGenerator — Kanboard Plugin

Generate subtasks from a task description using an AI provider (Anthropic, OpenAI, or Grok/xAI).

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4 (hard requirement — `carmelosantana/php-agents` requires PHP ^8.4)
- An API key for at least one supported provider: Anthropic, OpenAI, or xAI (Grok)

## Installation

1. Copy/clone this directory to `<kanboard-root>/plugins/SubtaskGenerator/`.
2. Inside the plugin directory, run `composer install --no-dev` to vendor `php-agents` and its dependencies.
3. Set file permissions so the web server can read the plugin: `chmod -R o+rX SubtaskGenerator/`.
4. The plugin appears under Settings → Plugins automatically.

## Providers

| Provider | Default model | API key env var |
|----------|---------------|-----------------|
| Anthropic (default) | `claude-sonnet-4-20250514` | `ANTHROPIC_API_KEY` |
| OpenAI | configurable | `OPENAI_API_KEY` |
| Grok (xAI) | configurable | `XAI_API_KEY` |

## PHP < 8.4

On hosts running PHP < 8.4 the plugin loads cleanly but AI features are disabled. A notice is written to the PHP error log. Upgrade the host PHP to enable them.

## License

MIT — see [LICENSE](LICENSE).
