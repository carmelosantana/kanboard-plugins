# Changelog

## 1.0.0 — 2026-07-02

First stable release. Complete end-to-end workflow for syncing project features across Kanboard projects.

### Features

- **Admin page** (Settings → Feature Sync): single-page workflow guarded by admin-only + CSRF protection.
- **Source & feature selection**: pick a source project; choose any combination of automated actions, tags, board columns, categories, and swimlanes.
- **Multi-target selection**: select one or more destination projects; the source project is excluded from the list.
- **Sync modes**:
  - *Add missing* — copies source items absent from the target; idempotent (safe to re-run).
  - *Replace* — removes all existing target items then copies the full source set; task-holding columns/swimlanes are left in place (safe fallback).
- **Read-only preview (diff)**: shows exactly what would be added, skipped, or replaced per target before any writes occur; highlights columns/swimlanes that hold tasks.
- **Apply + per-target report**: writes changes to each target independently; reports per-feature counts and any errors per target; a failed feature on one target does not abort other targets.
- **Action param re-mapping**: column-id parameters in copied actions are resolved to the target project's corresponding column (by title), not the source column id.
- **74 unit tests / 411 assertions** covering add, replace, idempotency, task-holding item safety, diff/apply count consistency, partial-failure isolation, and the feature-key whitelist.

## 0.1.0 — 2026-07-02

- Initial skeleton: plugin loads, admin-only Feature Sync page reachable from Settings sidebar.
- Controller `FeatureSyncController::index()` guards admin access; renders 5-step workflow shell.
- Step wiring (source → features → targets → preview → apply) to follow in tasks 02–06.
