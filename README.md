# Kanboard Plugin Suite — Dev Harness & Docs

This repository holds the **shared development harness** (Docker test stack, host-side PHPUnit
runner, the reusable CI workflows, packaging scripts) and the **design docs** (`docs/` specs, plans,
research) for a suite of Kanboard v1.2.47 plugins (buildless: plain JS/CSS + jQuery + global `KB`;
PHP >= 8.4; MIT).

> **The plugins live in their own repositories** — one repo per plugin, each released by pushing a
> `vX.Y.Z` tag. This repo contains no plugin code.

## Plugins

| Plugin | Repository | Notes |
|--------|-----------|-------|
| Agents | [kanboard-agents](https://github.com/carmelosantana/kanboard-agents) | API-only agent users driven over MCP, with a WIP view |
| AiConnector | [kanboard-ai-connector](https://github.com/carmelosantana/kanboard-ai-connector) | Multi-provider AI backend (php-agents) shared by the AI features |
| BattleLobby | [kanboard-battle-lobby](https://github.com/carmelosantana/kanboard-battle-lobby) | Battle-royale lobby theme |
| Bigboard | [kanboard-bigboard](https://github.com/carmelosantana/kanboard-bigboard) | Several projects on one board (maintained fork) |
| BulkProjectDelete | [kanboard-bulk-project-delete](https://github.com/carmelosantana/kanboard-bulk-project-delete) | Delete many projects in one action |
| CalendarPlugin | [kanboard-calendar](https://github.com/carmelosantana/kanboard-calendar) | Drag-and-drop calendar by due date |
| Chicane | [kanboard-chicane](https://github.com/carmelosantana/kanboard-chicane) | A project drawn as a race track |
| DependencyPlugin | [kanboard-dependency](https://github.com/carmelosantana/kanboard-dependency) | Blocked/blocker badges and a cycle guard on core task links |
| FeatureSync | [kanboard-feature-sync](https://github.com/carmelosantana/kanboard-feature-sync) | Copy actions, tags, columns, categories and swimlanes across projects |
| HelloHarmozi | [HelloHarmozi](https://github.com/carmelosantana/HelloHarmozi) | Footer quote; a Hello Dolly homage |
| Kensho | [kanboard-kensho](https://github.com/carmelosantana/kanboard-kensho) | Private per-user reflection on your own activity |
| ModMenu | [kanboard-modmenu](https://github.com/carmelosantana/kanboard-modmenu) | Plugin manager and the suite's dependency system |
| ModMenuUpdater | [kanboard-modmenu-updater](https://github.com/carmelosantana/kanboard-modmenu-updater) | One-shot upgrade of ModMenu < 1.3.0. Requires ModMenu |
| Presence | [kanboard-presence](https://github.com/carmelosantana/kanboard-presence) | Who is online, away or offline |
| RpcGuard | — | Private repository; installed by hand |
| SchedulerPlugin | [kanboard-scheduler](https://github.com/carmelosantana/kanboard-scheduler) | Daily roll-forward of overdue tasks |
| ShadcnTheme | [kanboard-shadcn-theme](https://github.com/carmelosantana/kanboard-shadcn-theme) | shadcn/ui-style theme with light/dark mode |
| SubtaskGenerator | [kanboard-subtask-generator](https://github.com/carmelosantana/kanboard-subtask-generator) | AI subtasks from a task description. Requires AiConnector |
| TimeBlock | [kanboard-time-block](https://github.com/carmelosantana/kanboard-time-block) | One planned time block per task |
| TimeInvoice | [kanboard-time-invoice](https://github.com/carmelosantana/kanboard-time-invoice) | PDF invoices from TimeReport hours. Requires TimeReport |
| TimeReport | [kanboard-time-report](https://github.com/carmelosantana/kanboard-time-report) | Hours reporting, progress meters and XP |

The [ModMenu directory](https://github.com/carmelosantana/kanboard-modmenu-directory) aggregates the
release metadata that ModMenu's Browse tab consumes.

## Development

Each plugin is checked out as its own git repo in a subdirectory here (gitignored by this repo). The
harness finds them by their `plugin.json`, so a new checkout needs no list edits:

```bash
docker compose -f testing/docker-compose.dev.yml up -d   # http://localhost:8081  (admin/admin)
./testing/run-plugin-tests.sh <PluginName>               # one plugin's PHPUnit suite
./testing/run-plugin-tests.sh --all                      # every suite, then a pass/fail table
```

See [`testing/README.md`](testing/README.md) for the stack, seeding and the test runner.

## CI

`.github/workflows/plugin-test.yml` is a reusable workflow (`workflow_call`) that checks out
Kanboard v1.2.47, installs its dev dependencies, checks out the plugins named in `requires` and `recommends` (tests may load a recommended plugin's classes), and runs
the plugin's PHPUnit suite with `testing/run-plugin-tests.sh`. `.github/workflows/plugin-release.yml`
checks the tag against `plugin.json` (and any version still hard-coded in `Plugin.php`), then builds
and publishes the release zip. Each plugin repo calls them from two short files,
[`templates/workflows/`](templates/workflows/) `test.yml` (PRs and pushes to `main`) and
`release.yml` (tags: test, then release). `scripts/sync-workflows.sh <PluginDir>...` writes both
into plugin checkouts, pinned to a harness commit on `origin/master`; third-party actions are
pinned by SHA too.

**Push explicitly.** Name the remote and the ref every time: `git push origin <branch>`,
`git push origin main`, `git push origin vX.Y.Z`. A bare `git push` once published a feature branch
by accident, and this harness's local `master` often carries other sessions' unpushed commits.

Plugins keep their version in one place: `getPluginVersion()` reads `plugin.json` (Kanboard core
never reads that file itself, so it is free to be the source).

Screenshots for the ModMenu directory come from `scripts/screenshots/` (see its README). Specs,
plans and research live in `docs/`.
