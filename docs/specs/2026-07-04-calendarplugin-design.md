# CalendarPlugin — Design Spec

- **Date:** 2026-07-04
- **Status:** Approved (brainstorming complete; ready for implementation plan)
- **Plugin:** `CalendarPlugin` (Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT)
- **Part of:** a 4-plugin suite (CalendarPlugin → DependencyPlugin → SchedulerPlugin → EnhancedTaskPlugin). This spec covers **CalendarPlugin only**; the others get their own spec → plan → SDD cycle later.

---

## 1. Purpose

Kanboard v1.2.47 ships **no visual calendar view** (only a Gantt chart and an iCal feed). CalendarPlugin adds a drag-and-drop calendar that surfaces tasks by their due date, both **across all of a user's projects** (a "my day / my month" view) and **within a single project**. It is the flagship of the suite and the visual surface the later plugins decorate (dependency badges, WIP warnings, workload heatmap).

It is an **enhancement layer over core primitives** — it reuses Kanboard's task query, permissions, due-date writes, colors, categories, and columns. It does not introduce a new date/task data model.

## 2. Goals / Non-Goals

### In scope (v1)

- **Two entry points, one view component:**
  - A global **Calendar** page in the top navigation — tasks across all projects the user can access.
  - A per-project **Calendar tab** (alongside Board / List / Gantt) — the same view pre-filtered to one `project_id`.
- **Views:** Month + Agenda (list). (FullCalendar provides Week/Day time-grid later at low marginal cost.)
- **Event model:** each task renders on its **due date**. The task's `time_estimated` (hours) is shown as a badge ("~2h"); a task with no estimate is an all-day event. Tasks with **no due date** appear in an **Unscheduled sidebar**.
- **Drag to reschedule (day granularity):**
  - Drag an event to another day → set the task's due date.
  - Drag an item from the Unscheduled sidebar onto a day → set its due date.
  - A failed server update **reverts** the drag in the UI.
- **Event display:** Kanboard task color, project-name badge, column/status badge, assignee avatar, **overdue tasks highlighted red**, estimate badge.
- **Click event → popover:** a lightweight, **non-modal** positioned element showing title, project, column, assignee, due date, and a link to the full task page.
- **Filters:** project (multi-select — covers the "multi-project overlay" case), assignee (`= me` covers "my tasks"), category, column/status; **hide completed** toggle; **show/hide unscheduled** toggle. Filter state lives in the **URL query string only** (shareable/bookmarkable); it is **not** persisted server-side in v1 (so no `configModel`/user-metadata writes for view prefs yet).

### Out of scope (deferred — future CalendarPlugin versions or sibling plugins)

- Week/Day **time-grid** views and **resize-to-set-duration** — needs a real time axis; the `duration = time_estimated, else all-day` rule (below) is the contract those views will honor.
- Inline **double-click edit**, **create task by clicking an empty slot**, **undo drag** (revert-button/Ctrl-Z history).
- **WIP-limit warnings** on the calendar — needs board column config + per-column counts.
- **Dependency badges / blocked-by / chain highlight** — **DependencyPlugin** will decorate events; CalendarPlugin keeps the event payload extensible so it can.
- **Recurring / snooze / smart date-picker / scheduled time slots** — **EnhancedTaskPlugin**.

### Explicitly reused from core (NOT rebuilt)

`TaskFinderModel` (query), `ProjectUserRoleModel` / core project-permission scoping, `TaskModificationModel` (due-date writes), task color list, categories, columns/swimlanes.

## 3. Key decisions (with rationale)

| # | Decision | Rationale |
|---|---|---|
| D1 | Build CalendarPlugin **first** of the four | Flagship, net-new (no core calendar), standalone value, and it establishes the events/API surface the others plug into. |
| D2 | **Day-granularity** v1 (Month + Agenda), no time-grid | Kanboard tasks have a due date but no native "duration/time block"; time-grid + resize needs new storage that belongs to EnhancedTaskPlugin. Ships the 80% cleanly. |
| D3 | **Both** a global page and a per-project tab | The per-project tab is the global view pre-filtered to one project — one view component, two thin entry points. |
| D4 | Event = task on its **due date**; **duration = `time_estimated`, else all-day** | Matches "drag updates due date" exactly and keeps "unscheduled" unambiguous (= no due date). In v1 the estimate is a **badge**; it becomes a sized block only when time-grid views land. Timed events use the due date's time component if set, otherwise all-day. |
| D5 | **Vendor FullCalendar v6** (global build, static assets) | MIT, buildless-friendly (`index.global.min.js`), CSP-safe as an external `<script src>`, mature drag-drop + keyboard + a11y, and a free path to time-grid later. |
| D6 | Popover is a **plain positioned element**, not a Kanboard modal | Avoids the core modal-overlay `overlayClickDestroy=false` trap; simpler dismissal. |

## 4. Architecture / components

- **`Plugin.php`** — registers routes; adds the top-nav **Calendar** link; hooks the per-project view-tab; injects vendored FullCalendar CSS/JS + `Assets/css/calendar.css` + `Assets/js/calendar.js`.
- **`Controller/CalendarController.php`**
  - `show` — global calendar page shell.
  - `project` — per-project shell (same view, `project_id` bound).
  - `events` — **JSON** feed of FullCalendar event objects for a date range + filters (a.k.a. `calendar.getEvents`).
  - `unscheduled` — **JSON** list of accessible tasks with no due date.
  - `updateDate` — **POST**; reschedule a task's due date (a.k.a. `calendar.updateTaskDate`).
- **`Model/CalendarQueryModel.php`** — thin wrapper over `TaskFinderModel`: applies permission scoping + filters + date range; maps rows to FullCalendar event objects. Guards `in([])`.
- **`Template/calendar/index.php`** — page shell: filter bar + `#calendar` container + Unscheduled sidebar. Server data (endpoint URLs, **reusable CSRF token generated in the controller**, current filter state, current user id) is passed via `data-*` attributes only.
- **Assets**
  - `Assets/vendor/fullcalendar/` — vendored `index.global.min.js` + CSS (static, pinned version, documented source + license).
  - `Assets/js/calendar.js` — external, CSP-safe, **event-delegated**; initializes FullCalendar from `data-*`, wires `eventDrop` / `eventReceive` → `updateDate`, filter changes → `refetchEvents`, unscheduled `Draggable`, and the click popover.
  - `Assets/css/calendar.css` — namespaced calendar styles (theme-token aware with fallbacks so it reads under ShadcnTheme dark and standalone).

## 5. Data flow

1. Page renders the shell; `calendar.js` reads `data-*` and inits FullCalendar with `events` pointing at the `events` endpoint.
2. FullCalendar requests `events?start=…&end=…&<filters>`; the controller returns tasks in that visible range, **scoped to the user's accessible projects** and active filters, mapped to `{ id, title, start, allDay, color, url?, extendedProps: { project, column, assignee, overdue, estimate } }`.
3. Drag: `eventDrop` (existing event) or `eventReceive` (from the Unscheduled sidebar) → `POST updateDate { task_id, date_due }` with the CSRF token. On non-OK response, call FullCalendar's `revert()`.
4. Filter change → `refetchEvents` with new query params; the URL is updated so the per-project tab is simply `?project_id=N` (or the clean route).
5. Unscheduled sidebar is populated from `unscheduled`; its items are FullCalendar `Draggable` external events.

## 6. Permissions & safety

- `events` / `unscheduled`: return only tasks in projects the requesting user may access (core project-permission scoping via `ProjectUserRoleModel` / `TaskFinderModel`).
- `updateDate`: **CSRF-validated** (reusable token generated in the controller, passed to the template via `data-*` — never `$this->token` in a template); **role check** (project-member minimum); the target task must belong to an accessible project; due-date write via `TaskModificationModel`.
- POST params read via `getValues()` (the CSRF-validated POST array), **not** `getStringParam()` (which only reads GET).

## 7. Cross-plugin API surface (for the later plugins)

- Documented endpoints: `events` (`calendar.getEvents`) and `updateDate` (`calendar.updateTaskDate`).
- Event payload keeps `extendedProps` **extensible** so DependencyPlugin can add blocked/blocker badges and SchedulerPlugin can react to reschedules.
- Cross-plugin communication is via **Kanboard's event system** (e.g., `TaskModificationEvent`) — no hard coupling between plugins.

## 8. Testing strategy

**Unit (PHPUnit host harness — clone of Kanboard v1.2.47 under `testing/`):**
- Permission gates: non-member request → forbidden; `events`/`unscheduled` never leak tasks from inaccessible projects.
- `events`: JSON shape; filters (project / assignee / category / column / hide-completed) applied correctly; date-range windowing; `overdue` flag; `estimate`/`allDay` mapping per D4.
- `updateDate`: CSRF required; updates the due date; **rejects cross-project / non-member**; invalid task id handled.
- `unscheduled`: returns only accessible tasks with no due date; `in([])` guarded.

**E2E (Playwright driving the live Docker on :8081, admin/admin):**
- Calendar renders; FullCalendar loads; **no CSP violations / console errors**; our external JS wired (no inline scripts).
- Seeded tasks appear on their due dates (Month view); overdue tasks render red.
- Drag an event to another day → due date changes (verified via API/DB).
- Drag an Unscheduled item onto a day → it schedules and leaves the sidebar.
- Filters change the visible set; **hide completed** works.
- Click an event → popover with correct data + working task link.
- Per-project tab scopes to a single project.

## 9. Kanboard gotchas honored (from prior live-E2E findings)

- **No inline `<script>`** (CSP `default-src 'self'`): FullCalendar and our init are external files; server data via `data-*` / hidden inputs.
- **CSRF token** generated in the **controller**, passed via `data-*` (`token` is not a template helper — calling it in a template throws and un-themes the page).
- **Clean-URL** id handling; `url->href()` puts the plugin **inside `$params`**, not as the 4th positional arg.
- **`getValues()`** for POST params (not `getStringParam`).
- **Modal trap avoided** — popover is a plain positioned element, not a `js-modal-*` overlay.
- **`configModel` cache flush** — N/A in v1 (filters are URL-only, nothing persisted); applies only if a later version stores view prefs.
- Dev-env notes: opcache revalidates ~2s; plugin files may need `chown` back to the dev user after container-side pushes.

## 10. Risks & de-risking

- **R1 — FullCalendar buildless under CSP.** The one real unknown. **Task-0 of the plan** vendors FullCalendar's global build and proves it renders + drag-drops with no console/CSP errors *before* any feature work.
- **R2 — Cross-project permission scoping correctness.** Covered by explicit unit tests that assert inaccessible-project tasks never appear and cannot be updated.
- **R3 — Timezone / date boundaries** (a task's due timestamp vs the day cell). Normalize on the server; assert with fixed seeded dates in tests.

## 11. Suite roadmap (context, not this spec's work)

1. **CalendarPlugin** (this spec).
2. Deferred CalendarPlugin work (time-grid + resize, inline edit, create-by-click, undo, WIP warnings).
3. **DependencyPlugin** (cascade + graph + blocked badges, built on core task links) — decorates calendar events.
4. **SchedulerPlugin** (nightly sweep + reschedule policies + auto-move log, as a plugin CLI command).
5. **EnhancedTaskPlugin** (recurring / snooze / smart date-picker / time slots).
