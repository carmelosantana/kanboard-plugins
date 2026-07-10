# Kanboard Plugin Suite — Roadmap (next set of plugins)

- **Date:** 2026-07-05 (updated 2026-07-07)
- **Status:** Planning reference (each plugin still gets its own brainstorm → spec → plan → SDD cycle)
- **Repos (2026-07-10):** each plugin now lives in its own repo, `github.com/carmelosantana/kanboard-<slug>`, with build-on-tag CI. This `kanboard-plugins` repo is demoted to the shared dev harness (`testing/`, `scripts/`) + docs; plugin dirs stay here as their own gitignored checkouts. Dev stack: `testing/docker-compose.dev.yml` on `:8081` (admin/admin), all plugins bind-mounted. Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT.
- **Directory:** `../kanboard-modmenu-directory/plugins.json` → releases consumed by ModMenu.

## Shipped so far

| Plugin | Version | Notes |
|---|---|---|
| BulkProjectDelete | 1.0.1 | Bulk-delete projects |
| ShadcnTheme | 1.0.1 | shadcn/ui dark theme + toggle |
| FeatureSync | 1.0.0 | Copy project features across projects |
| SubtaskGenerator | 1.0.0 | AI subtask generation (Anthropic/OpenAI/Grok) |
| **ModMenu** | **1.1.0** | Standalone plugin manager + **dependency system**: plugins declare `requires` (hard, gates activation w/ one-click transitive resolve + reverse-block) / `recommends` (soft prompt). Pure `DependencyResolver`, 94 tests. See `specs/2026-07-09-modmenu-dependency-system-design.md`. |
| **CalendarPlugin** | **1.1.0** | Drag-and-drop calendar (global + per-project). Polish M1–M13 + generic `calendarEventDecorators` hook. |
| **DependencyPlugin** | **1.0.0** | Task dependencies on core links: blocked/blocker badges (board, calendar, task page) + cycle guard. |

The four-plugin design suite was: **CalendarPlugin → DependencyPlugin → SchedulerPlugin → EnhancedTaskPlugin.** CalendarPlugin is the flagship visual surface the later plugins decorate. See `docs/superpowers/specs/2026-07-04-calendarplugin-design.md` §11.

**Progress:** CalendarPlugin ✅ shipped (v1.1.0) · DependencyPlugin ✅ shipped (v1.0.0) · SchedulerPlugin ✅ shipped (v1.0.0). **EnhancedTaskPlugin is next.**

---

## Build order for the remaining work

### 0. CalendarPlugin v1.1 — deferred *features* (own spec → plan → SDD)

Not polish (all polish M1–M13 shipped in 1.0.1). These are net-new capabilities deferred from v1:

- **Time-grid week/day views + resize-to-set-duration.** Needs a real time axis. The v1 contract to honor: `duration = time_estimated, else all-day` (design D4). Resize writes back `time_estimated` (and/or a start time).
- **Inline edit** — double-click an event to edit title/due without leaving the calendar.
- **Create-by-click** — click an empty day/slot to create a task with that due date.
- **Undo drag** — revert-button / Ctrl-Z history after a reschedule.
- **WIP-limit warnings** — surface board column WIP limits on the calendar (needs column config + per-column counts).

Keep the event payload's `extendedProps` extensible (DependencyPlugin/SchedulerPlugin decorate it).

### 1. DependencyPlugin ✅ SHIPPED (v1.0.0, 2026-07-07)

Task dependencies built on Kanboard's core task links. Delivered:
- Blocked-by / blocks relationships on top of core `TaskLinkModel` (reuses the "blocks"/"is blocked by" link types — no new store).
- **Blocked/blocker badges** on board cards, the task page panel, and CalendarPlugin events (via the `calendarEventDecorators` hook + `extendedProps.badges`).
- **Cycle guard** — an event listener rejects link creations that would form a cycle (removes both directions + flashes a failure).

**Deferred from v1 (future DependencyPlugin v1.1+ — own brainstorm):**
- **Dependency graph** view (chain/DAG visualization — needs a graph-rendering lib).
- **Cascade** — moving/rescheduling a task nudges dependents. *This belongs to SchedulerPlugin's boundary, not here.*

### 2. SchedulerPlugin ✅ SHIPPED (v1.0.0, 2026-07-07)

Automated overdue-task rescheduling. See `docs/superpowers/specs/2026-07-07-schedulerplugin-design.md` + `docs/superpowers/plans/2026-07-07-schedulerplugin.md`. Delivered:
- **Daily sweep** per opt-in project; policy pipeline: skip-blocked (via `DependencyModel::getProjectBlockedMap()`) → snap-to-today → working-days → de-clump (max N/day).
- **Three triggers** wrapping one `SchedulerRunner`: lazy web-cron (guarded once/day), admin Run-now with dry-run preview, and `./cli scheduler:run`.
- **Audit log** (`scheduler_runs` + `scheduler_moves` tables + admin log page) and a per-run **activity-stream summary**.
- **Calendar auto-moved badge** via CalendarPlugin's `calendarEventDecorators`.

**Deferred to a future SchedulerPlugin release:** cascade auto-reschedule (moving a task nudges dependents); column/WIP-aware de-clumping; per-project policy overrides.

### 3. EnhancedTaskPlugin

**Purpose:** richer task scheduling primitives.

Candidate scope:
- **Recurring** tasks.
- **Snooze**.
- **Smart date-picker**.
- **Scheduled time slots** (this is where "duration/time block" storage lives — the substrate CalendarPlugin's time-grid views render).

---

## Per-plugin process (every plugin)

1. `superpowers:brainstorming` → design spec in `docs/superpowers/specs/`.
2. `superpowers:writing-plans` → task plan in `docs/superpowers/plans/`.
3. `superpowers:subagent-driven-development` → implement task-by-task with two-stage review + whole-branch review.
4. `superpowers:finishing-a-development-branch` → merge; **confirm before pushing/releasing**.
5. Release: in the plugin's own repo, push a `vX.Y.Z` tag (matching `plugin.json`) → build-on-tag CI (`.github/workflows/release.yml`) zips the plugin (single `<PluginName>/` top folder, `Test/` excluded) and publishes the GitHub release → bump `plugins.json` in the directory repo (homepage/download/version, `requires`/`recommends`). `scripts/package.sh` remains for local packaging.

## Live-E2E gotchas (must honor — see memory `kanboard-plugin-live-gotchas`)

Clean-URL ids; `addRoute` 4-arg form; no inline `<script>` (CSP); reusable-CSRF via `getRawValue` + `validateReusableCSRFToken`; write endpoints gate on a **write-capable role** not mere membership; `template:layout:js/css` inject **sitewide** unless registration is route-gated (`Router::getPath()`); BEM modifier classes lose to element-qualified base selectors on specificity. Always drive the real browser on `:8081`, not just PHPUnit.
