# FeatureSync

A Kanboard plugin that bulk-copies project features — automated actions, tags, board columns, categories, and swimlanes — from a source project to many target projects in one operation.

## What it syncs

| Feature              | Notes                                                              |
|----------------------|--------------------------------------------------------------------|
| Automated Actions    | Event/action pairs; column-id params are re-mapped to the target  |
| Tags                 | Project-level tags with names and colours                         |
| Board Columns        | Column titles (task limits and descriptions are also copied)      |
| Categories           | Project categories                                                |
| Swimlanes            | Named swimlanes                                                   |

## Sync modes

### ADD (add missing)

Copies every source item that is not already present in the target, matched by name/key. Items that already exist in the target are left untouched. **Running the same sync twice is safe — it is a no-op on the second run.**

### REPLACE

Deletes all existing items of the selected feature in the target, then copies all items from the source. The target ends up with exactly the source set.

> **WARNING — REPLACE MODE RISKS**
>
> **1. Columns and swimlanes that hold tasks are NOT removed.**
> Kanboard refuses to delete a column or swimlane while it has tasks. FeatureSync
> detects this and leaves those items in place rather than failing. As a result,
> a "replace" on columns or swimlanes may be *partial*: task-holding items survive,
> even though all task-free items are removed and the full source set is added.
> Inspect the apply report carefully to understand the final state.
>
> **2. No automatic rollback — a failed target may be left partially applied.**
> FeatureSync processes each target project independently. Within a target, if an
> error occurs mid-way through applying one feature, that feature's changes are
> NOT rolled back. PicoDb (Kanboard's ORM) does not support nested transactions or
> savepoints; the core model methods each commit on the shared connection, making an
> outer rollback impossible. FeatureSync uses per-feature try/catch to log the error
> and continue to the next target, but the affected target may be in a partial state.
> Review the apply report and correct manually if needed.

## Security

- **Admin only.** All routes (`/feature-sync`, `/feature-sync/preview`, `/feature-sync/apply`) require admin role. Non-admin requests are rejected with a 403 Forbidden response.
- **CSRF protected.** The preview and apply forms carry Kanboard's built-in CSRF token; forged cross-site submissions are rejected.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4

## Installation

Copy or symlink the `FeatureSync/` directory into your Kanboard `plugins/` directory and reload Kanboard. No database migrations are required.

## Walkthrough

1. **Settings → Feature Sync** — opens the admin page.
2. **Source project** — select the project to use as template.
3. **Features** — tick which features to copy (actions, tags, columns, categories, swimlanes).
4. **Target projects** — select one or more destination projects (the source project is excluded).
5. **Sync mode** — choose *Add missing* (safe, idempotent) or *Replace* (destructive, see warnings above).
6. **Preview** — review the diff for each target before any changes are written.
7. **Apply** — confirm; FeatureSync applies the sync and shows a per-target, per-feature report with counts and any errors.

## License

MIT
