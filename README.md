# Kanboard Plugin Suite — Dev Harness & Docs

This repository holds the **shared development harness** (Docker test stack, host-side PHPUnit
runner, packaging scripts) and the **design docs** (`docs/superpowers/` specs, plans, roadmap) for a
suite of Kanboard v1.2.47 plugins (buildless: plain JS/CSS + jQuery + global `KB`; PHP >= 8.4; MIT).

> **The plugins themselves now live in their own repositories** — one repo per plugin, each with
> build-on-tag CI. This repo no longer contains plugin code.

## Plugins

| Plugin | Repository | Notes |
|--------|-----------|-------|
| AiConnector | [kanboard-ai-connector](https://github.com/carmelosantana/kanboard-ai-connector) | Multi-provider AI backend (php-agents) |
| BulkProjectDelete | [kanboard-bulk-project-delete](https://github.com/carmelosantana/kanboard-bulk-project-delete) | |
| CalendarPlugin | [kanboard-calendar](https://github.com/carmelosantana/kanboard-calendar) | |
| DependencyPlugin | [kanboard-dependency](https://github.com/carmelosantana/kanboard-dependency) | |
| FeatureSync | [kanboard-feature-sync](https://github.com/carmelosantana/kanboard-feature-sync) | |
| ModMenu | [kanboard-modmenu](https://github.com/carmelosantana/kanboard-modmenu) | Plugin manager + dependency system |
| SchedulerPlugin | [kanboard-scheduler](https://github.com/carmelosantana/kanboard-scheduler) | |
| ShadcnTheme | [kanboard-shadcn-theme](https://github.com/carmelosantana/kanboard-shadcn-theme) | |
| SubtaskGenerator | [kanboard-subtask-generator](https://github.com/carmelosantana/kanboard-subtask-generator) | Requires AiConnector |
| HelloHarmozi | [kanboard-hello-harmozi](https://github.com/carmelosantana/kanboard-hello-harmozi) | Demo |

The [ModMenu directory](https://github.com/carmelosantana/kanboard-modmenu-directory) aggregates the
release metadata that ModMenu's Browse tab consumes.

## Development

Each plugin is checked out as its own git repo in a subdirectory here (gitignored by this repo); the
shared Docker stack mounts them all:

```
cd testing
docker compose -f docker-compose.dev.yml up -d   # http://localhost:8081  (admin/admin)
```

`testing/run-plugin-tests.sh <PluginName>` runs a plugin's PHPUnit suite against Kanboard v1.2.47.
Specs, plans, and the roadmap live in `docs/superpowers/`.
