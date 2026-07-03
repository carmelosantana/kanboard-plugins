# Changelog

All notable changes to BulkProjectDelete are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0.1] — 2026-07-03

### Fixed

- **Dark-theme surfaces** — the confirm modal (and toolbar chrome) rendered on a
  hard-coded white background, jarringly light on ShadcnTheme's dark UI. All
  surface/text/border/danger colours now use ShadcnTheme design tokens
  (`--card`, `--card-foreground`, `--border`, `--destructive`,
  `--alert-*-error`, …) with the original light values kept as fallbacks, so the
  plugin themes correctly under ShadcnTheme **and** still looks right stand-alone.

---

## [1.0.0] — 2026-07-02

### Added

- **Bulk-delete UI** — selection toolbar injected into the admin Projects list
  via `template:project-list:menu:after` hook. Adds per-row checkboxes,
  a Select-all toggle, and a live selected-count badge. Toolbar is
  admin-only and list-page-only; does not override core templates so it
  coexists with theme plugins.
- **Impact pre-flight** (`GET /bulk-project-delete/confirm`) — read-only
  confirmation page showing per-project counts of tasks, subtasks, comments,
  and files (with total bytes) before any data is touched.
- **Typed-DELETE confirmation modal** — the confirmation page requires the
  admin to type `DELETE` before the destructive form can be submitted.
  CSRF-protected via Kanboard's standard form token.
- **Bulk-delete endpoint** (`POST /bulk-project-delete/remove`) — loops
  `ProjectModel::remove()` per project inside a per-project transaction.
  Explicitly deletes `custom_filters` and `invites` rows (the two tables
  Kanboard core's FK cascade omits) before calling the core remove. One
  project failure never aborts the rest; a partial-success flash report is
  shown after completion.
- **Zero-orphan guarantee** — all child rows removed via FK cascade
  (columns, swimlanes, categories, tasks and everything under tasks —
  subtasks → subtask\_time\_tracking, comments, files, metadata, tags, links,
  external links, transitions, activities, stats, actions + action\_has\_params,
  roles, restrictions, notifications); physical files removed from disk
  (dedup-aware); `custom_filters` + `invites` cleaned explicitly.
- **Admin-only gate** — `isAdmin()` checked in every controller action;
  `AccessForbiddenException` thrown for non-admins.
- **PHPUnit test suite** — `PluginTest`, `ConfirmImpactTest`,
  `RemoveEndpointTest` (exhaustive table coverage, on-disk file removal,
  dedup semantics, admin gate, CSRF enforcement, partial-failure, messy-id
  list, empty-list safety).
- **`plugin.json`** metadata (`name`, `version`, `description`, `author`,
  `homepage`, `kanboard_version >=1.2.47`, `php_version >=8.4`).
