# SubtaskGenerator — Kanboard Plugin

Generate candidate subtasks from a task description using an AI provider. Supports **Anthropic** (default), **OpenAI**, and **Grok (xAI)** out of the box.

## Requirements

**PHP >= 8.4 is required.** The underlying `carmelosantana/php-agents` library declares `require php: ^8.4`. On hosts running PHP < 8.4 the plugin loads without errors but all AI features are disabled and a notice is written to the PHP error log.

- Kanboard >= 1.2.47
- PHP >= 8.4
- An API key for at least one supported provider: Anthropic, OpenAI, or Grok (xAI)

## Installation

1. Copy or clone this directory to `<kanboard-root>/plugins/SubtaskGenerator/`.
2. Inside the plugin directory, install PHP dependencies:
   ```
   composer install --no-dev
   ```
3. Set file permissions so the web server can read the plugin:
   ```
   chmod -R o+rX SubtaskGenerator/
   ```
4. Go to **Settings → Plugins** in Kanboard to confirm the plugin is active.
5. Go to **Settings → SubtaskGenerator** to enter your API key.

## Provider Configuration

Navigate to **Settings → SubtaskGenerator** to configure which AI provider to use.

### Anthropic (default)

Anthropic is the default provider. No base URL configuration is needed.

| Setting | Value |
|---------|-------|
| Provider | `anthropic` |
| Model | `claude-sonnet-4-20250514` (default) |
| API Key | Your Anthropic API key (starts with `sk-ant-`) |
| Env var fallback | `ANTHROPIC_API_KEY` |

### OpenAI

| Setting | Value |
|---------|-------|
| Provider | `openai` |
| Model | e.g. `gpt-4o`, `gpt-4o-mini` |
| API Key | Your OpenAI API key (starts with `sk-`) |
| Env var fallback | `OPENAI_API_KEY` |

### Grok (xAI)

| Setting | Value |
|---------|-------|
| Provider | `grok` |
| Model | e.g. `grok-3`, `grok-3-mini` |
| API Key | Your xAI API key |
| Env var fallback | `XAI_API_KEY` |

## API Key Security

- Keys are stored via Kanboard's `configModel` (the `settings` database table), which is only accessible to administrators.
- The API key field in the settings form always renders empty (`value=""`). The stored key is **never echoed back** into the HTML response.
- If the form is submitted with a blank key or the placeholder sentinel, the existing stored key is preserved unchanged.
- Keys are **never written to the PHP error log**. If a provider call fails, only the exception class and code are logged — not the exception message, request URL, or any provider response body.
- As an alternative to storing the key in the database, you can set the corresponding environment variable (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, or `XAI_API_KEY`) on the server. The plugin reads the env var as a fallback when the database setting is empty.

## Usage Walkthrough

1. Open any task in Kanboard.
2. In the task sidebar, click **Generate subtasks** (appears only when AI is fully configured).
3. Review and optionally edit the pre-filled prompt (the task title and description are used as the default prompt).
4. Click **Generate**. The plugin calls the configured AI provider and displays a candidate checklist.
5. Uncheck any subtasks you do not want, and edit titles inline if needed.
6. Click **Create** to persist the selected subtasks on the task.
7. You are redirected back to the task view with a flash notice showing how many subtasks were created.

If the provider is unreachable, times out, or returns malformed output, a friendly error message is shown in the modal — nothing is created, and the page does not 500.

## Graceful Degradation

- **PHP < 8.4:** the plugin loads cleanly, AI features are disabled, the sidebar link is hidden.
- **No API key configured:** same — AI features are disabled, the sidebar link is hidden.
- **Provider error (network, timeout, malformed output, empty result):** the modal shows a friendly error message; no subtasks are created; no page crash.
- **Partial create failure:** if one subtask cannot be saved to the database, the remaining subtasks are still created. The flash notice reports both the created and failed counts.

## Optional: Ollama (local)

Ollama/local LLM support is not included in the default configuration. The plugin is designed for hosted providers (Anthropic, OpenAI, Grok) which require no local infrastructure.

## License

MIT — see [LICENSE](LICENSE).
