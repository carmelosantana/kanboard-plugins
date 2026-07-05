# Kanboard Plugin Suite — Roadmap (next set of plugins)

- **Date:** 2026-07-05
- **Status:** Planning reference (each plugin still gets its own brainstorm → spec → plan → SDD cycle)
- **Repo:** `kanboard-plugins` (Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT). Dev stack: `testing/docker-compose.dev.yml` on `:8081` (admin/admin), all plugins bind-mounted.
- **Directory:** `../kanboard-modmenu-directory/plugins.json` → releases consumed by ModMenu.

## Shipped so far

| Plugin | Version | Notes |
|---|---|---|
| BulkProjectDelete | 1.0.1 | Bulk-delete projects |
| ShadcnTheme | 1.0.1 | shadcn/ui dark theme + toggle |
| FeatureSync | 1.0.0 | Copy project features across projects |
| SubtaskGenerator | 1.0.0 | AI subtask generation (Anthropic/OpenAI/Grok) |
| ModMenu | 1.0.1 | Standalone plugin manager |
| **CalendarPlugin** | **1.0.1** | Drag-and-drop calendar (global + per-project). Polish M1–M13 done. |

The four-plugin design suite was: **CalendarPlugin → DependencyPlugin → SchedulerPlugin → EnhancedTaskPlugin.** CalendarPlugin is the flagship visual surface the later plugins decorate. See `docs/superpowers/specs/2026-07-04-calendarplugin-design.md` §11.

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

### 1. DependencyPlugin (next distinct plugin — recommended next)

**Purpose:** task dependencies built on Kanboard's core task links, with cascade behavior and a visual graph; decorates CalendarPlugin events with blocked/blocker badges.

Candidate scope (refine in brainstorm):
- Blocked-by / blocks relationships on top of core `TaskLinkModel` (reuse the "blocks"/"is blocked by" link types; don't invent a new store).
- **Cascade** — moving/rescheduling a task nudges dependents (policy-driven; overlaps SchedulerPlugin — draw the boundary in brainstorm).
- **Dependency graph** view (chain/DAG visualization).
- **Blocked badges + chain highlight** on the calendar and board (cross-plugin decoration of CalendarPlugin events via `extendedProps`).
- Cycle detection / guard against circular dependencies.

**Integration contract with CalendarPlugin:** documented endpoints `events` (`calendar.getEvents`) and `updateDate` (`calendar.updateTaskDate`); communicate via Kanboard's event system (e.g. `TaskModificationEvent`) — no hard coupling.

### 2. SchedulerPlugin

**Purpose:** automated rescheduling as a plugin CLI command (cron-friendly).

Candidate scope:
- **Nightly sweep** + **reschedule policies** (e.g. roll overdue-incomplete forward; respect dependencies from DependencyPlugin).
- **Auto-move log** — audit trail of automated moves.
- Implemented as a Kanboard plugin **CLI command** (console), not a request-time hook.

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
5. Release: `scripts/package.sh <Plugin> <out>` → `gh release create <Plugin>-vX.Y.Z` → bump `plugins.json` in the directory repo.

## Live-E2E gotchas (must honor — see memory `kanboard-plugin-live-gotchas`)

Clean-URL ids; `addRoute` 4-arg form; no inline `<script>` (CSP); reusable-CSRF via `getRawValue` + `validateReusableCSRFToken`; write endpoints gate on a **write-capable role** not mere membership; `template:layout:js/css` inject **sitewide** unless registration is route-gated (`Router::getPath()`); BEM modifier classes lose to element-qualified base selectors on specificity. Always drive the real browser on `:8081`, not just PHPUnit.
