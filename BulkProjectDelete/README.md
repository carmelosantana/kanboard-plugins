# BulkProjectDelete

A Kanboard plugin that lets administrators select multiple projects from the
Projects list and delete them all in one action, with no orphaned rows or
files left behind.

> **Deletion is permanent and irreversible. There is no undo.**

---

## What it does

Adds a selection toolbar to the admin Projects list. The admin toggles
selection mode, picks one or more projects, and confirms with a typed `DELETE`
prompt. The plugin then deletes every selected project — including all child
data and physical files on disk — in a single bulk operation. A per-project
partial-success report is shown after completion: projects that could not be
removed are listed separately so the others are not blocked.

---

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4

---

## Installation

1. Download or clone this repository.
2. Copy (or symlink) the `BulkProjectDelete/` directory into your Kanboard
   `plugins/` directory:

   ```
   plugins/
   └── BulkProjectDelete/
       ├── Plugin.php
       ├── Controller/
       ├── Template/
       └── ...
   ```

3. Reload the application.
4. Go to **Settings → Plugins** and confirm **BulkProjectDelete** appears
   enabled. No database migration is needed.

---

## Permissions

**Application-admin only.** Only users whose account has the *Application
Administrator* role can see the selection toolbar or reach the delete
endpoint. Non-admins see neither the checkboxes nor the bulk-delete button;
the controller also hard-rejects any non-admin request with
`AccessForbiddenException` as defence-in-depth.

Every destructive POST is CSRF-protected via Kanboard's standard form token
(`checkCSRFForm()`).

---

## How to use

1. Log in as an application administrator.
2. Open the **Projects** list (the default admin view at `/projects`).
3. Click **Select projects** in the toolbar to enable selection mode.
   Checkboxes appear next to each project row and a **Select all** toggle
   appears in the header.
4. Tick the projects you want to delete (or use **Select all**). A live
   counter shows how many are selected.
5. Click **Delete selected**. An impact-summary page lists each project with
   its task, subtask, comment, and file counts.
6. Review the impact summary. Type `DELETE` (all-caps) in the confirmation
   field and click **Confirm delete**.
7. The plugin deletes the selected projects. A flash message reports how many
   were deleted and (if any) which failed.

> Screenshots are deferred to the final browser end-to-end pass.

---

## What gets deleted

> **Deletion is permanent and irreversible. There is no undo.**

The plugin calls Kanboard core's `ProjectModel::remove()` for each project,
then explicitly removes rows in the two tables that core's FK cascade
intentionally omits. Everything below is wiped for every selected project.

### Deleted via ON DELETE CASCADE (core handles these automatically)

| Table | Relationship |
|---|---|
| `columns` | `project_id` → `projects.id` |
| `swimlanes` | `project_id` → `projects.id` |
| `project_has_categories` | `project_id` → `projects.id` |
| `project_has_files` | `project_id` → `projects.id` |
| `project_has_users` | `project_id` → `projects.id` |
| `project_has_groups` | `project_id` → `projects.id` |
| `project_has_roles` | `project_id` → `projects.id` |
| `project_has_metadata` | `project_id` → `projects.id` |
| `project_has_notification_types` | `project_id` → `projects.id` |
| `actions` | `project_id` → `projects.id` |
| `action_has_params` | `action_id` → `actions.id` (cascades from actions) |
| `predefined_task_descriptions` | `project_id` → `projects.id` |
| `project_daily_stats` | `project_id` → `projects.id` |
| `project_daily_column_stats` | `project_id` → `projects.id` |
| `project_activities` | `project_id` → `projects.id` |
| `column_has_restrictions` | `project_id` → `projects.id` |
| `project_role_has_restrictions` | `project_id` → `projects.id` |
| `column_has_move_restrictions` | `project_id` → `projects.id` |
| `user_has_notifications` | `project_id` → `projects.id` |
| `tags` | `project_id` → `projects.id` |
| `tasks` | `project_id` → `projects.id` |
| `subtasks` | `task_id` → `tasks.id` (cascades from tasks) |
| `subtask_time_tracking` | `subtask_id` → `subtasks.id` (cascades from subtasks) |
| `comments` | `task_id` → `tasks.id` (cascades from tasks) |
| `task_has_files` | `task_id` → `tasks.id` (cascades from tasks) |
| `task_has_metadata` | `task_id` → `tasks.id` (cascades from tasks) |
| `task_has_tags` | `task_id` → `tasks.id` (cascades from tasks) |
| `task_has_links` | `task_id` → `tasks.id` (cascades from tasks) |
| `task_has_external_links` | `task_id` → `tasks.id` (cascades from tasks) |
| `transitions` | `project_id` / `task_id` → cascade |

### Physical files on disk

Core's `FileModel::remove()` deletes the stored file from disk for every row
in `task_has_files` and `project_has_files`. File removal is **dedup-aware**:
if two file rows reference the same path, the physical file is not unlinked
until the last row referencing that path is removed.

### Explicit plugin cleanup (orphan gaps core leaves open)

Kanboard core does **not** add a foreign-key cascade for the following tables,
so the plugin removes them explicitly before calling `ProjectModel::remove()`:

| Table | Why core leaves it orphaned |
|---|---|
| `custom_filters` | `project_id` column has no FK cascade in `app/Schema/Sqlite.php` |
| `invites` | `project_id` column has no FK cascade in `app/Schema/Sqlite.php` |

Both are deleted inside the same per-project transaction.

---

## License

MIT — see [LICENSE](LICENSE).
