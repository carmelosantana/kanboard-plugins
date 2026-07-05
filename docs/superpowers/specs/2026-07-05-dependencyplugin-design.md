# DependencyPlugin — Design Spec

- **Date:** 2026-07-05
- **Status:** Approved (brainstorming complete; ready for implementation plan)
- **Plugin:** `DependencyPlugin` (Kanboard v1.2.47, PHP ≥ 8.4, buildless, MIT)
- **Part of:** the plugin suite (CalendarPlugin → **DependencyPlugin** → SchedulerPlugin → EnhancedTaskPlugin). This spec covers **DependencyPlugin only**.

---

## 1. Purpose

Core Kanboard lets you create `blocks` / `is blocked by` task links but does **nothing** with them: it never shows that a task is *currently blocked*, and it never prevents a nonsensical dependency **cycle** (A blocked by B, B blocked by A). DependencyPlugin is an **enhancement layer over core task links** that:

- computes each task's **blocked status** from existing links, and
- **surfaces** it on the board, on CalendarPlugin events, and on the task page, and
- **guards** dependency-graph integrity by rejecting cycles.

It does **not** introduce a new dependency data model — it reads and decorates Kanboard's own `task_has_links` / `links` tables via `TaskLinkModel`.

## 2. Goals / Non-Goals

### In scope (v1)

- **Blocked-status computation** from core links: a task is *blocked* when it has ≥ 1 `is blocked by` link to a task that is **still open** (`is_active = 1`, not completed). Also compute a downstream **blocks** count.
- **Three read-only surfaces** (all reflect *direct* blockers):
  - **Board cards** — a 🔒 "blocked" badge + open-blocker count on each blocked card.
  - **Calendar events** — the same badge on CalendarPlugin events (cross-plugin integration).
  - **Task detail panel** — a status-aware list of *blocked by* / *blocks* with per-row open/closed state (richer than core's plain link list).
- **Cycle guard** — reject a new `blocks` / `is blocked by` link that would close a cycle (direct or transitive), via an event listener (see §3 D3 / §6).

### Out of scope (deferred)

- **Dependency graph page** (DAG visualization) — needs a graph-rendering lib; a future DependencyPlugin version.
- **Any scheduling side effects** — cascade auto-reschedule, "shift dependents when a blocker moves." This is **SchedulerPlugin**'s domain.
- **Enforcement / guardrails** — blocking task completion or column moves while blockers are open. Deliberately excluded from v1 (visibility only).

### Explicitly reused from core (NOT rebuilt)

`TaskLinkModel` (`getAll`, `create`, `remove`, `getOppositeTaskLink`), `LinkModel` (the seeded `blocks` id / `is blocked by` id pair), core's existing "Add a link" task-page UI, `ProjectUserRoleModel` scoping, board/task template hooks.

## 3. Key decisions (with rationale)

| # | Decision | Rationale |
|---|---|---|
| D1 | Build on **core task links**, no new store | Core already seeds `blocks` (id 3) / `is blocked by` (id 2) and `TaskLinkModel::getAll()` returns the blocker's `is_active`, `date_completed`, `title`, `project_id`, etc. — everything needed. A parallel store would duplicate and drift. |
| D2 | v1 is **visibility + management only** | Clean boundary with SchedulerPlugin (automation) and EnhancedTaskPlugin. Ships the high-value 80% with no risky write-side scheduling. |
| D3 | **Cycle guard via Option A** — listen to `TaskLinkModel::EVENT_CREATE_UPDATE`, detect a cycle, remove the offending link pair, flash a warning | Core commits the link then fires the event; there is no pre-save veto hook. Option A uses only stable public APIs (event + `TaskLinkModel::remove`) and never forks `TaskLinkController` — upgrade-safe. The trade-off (link is created-then-removed with a flash, not an inline form error) is acceptable. |
| D4 | Badges reflect **direct** open blockers; transitive reasoning is used **only** by the cycle detector | "This card can't start because these specific tasks aren't done" is the intuitive card meaning. Transitive blocked-ness is expensive and confusing on a card. |
| D5 | Calendar integration via a **generic decorator hook added to CalendarPlugin (→ v1.1)** | CalendarPlugin's spec §7 explicitly promised an extensible event payload for exactly this. A generic `badges[]` passthrough (server-side) is cleaner than DependencyPlugin poking CalendarPlugin's `window.__calInstance`, and it also serves SchedulerPlugin later. Entails a small, bundled CalendarPlugin change. |
| D6 | Blocked map computed **per project in ≤ 2 queries and memoized per request** | The board renders many cards through a per-card hook; a naive `getAll()` per card is N+1. One project-scoped map lookup keeps board render O(1) per card. |

## 4. Architecture / components

- **`Plugin.php`** — registers the board/task hooks, the calendar decorator attachment, the route(s), the model, and the `EVENT_CREATE_UPDATE` listener. Asset injection (board/task/calendar CSS + the calendar JS decorator) **route-scoped** where practical (per CalendarPlugin gotcha M1: `template:layout:*` injects sitewide unless gated).
- **`Model/DependencyModel.php`**
  - `getProjectBlockedMap($projectId)` → `array<int taskId, array{open_blockers:int, blocks:int}>`, computed in ≤ 2 queries, **memoized** in a private property per request.
  - `isBlocked($taskId)` / `getBlockers($taskId)` / `getBlocking($taskId)` — task-panel helpers (may reuse `TaskLinkModel::getAll` + status).
  - `wouldCreateCycle($taskId, $blockerId)` → bool — DFS over the `is blocked by` graph from `$blockerId` seeking `$taskId`, with a visited set (bounded; follows links across projects).
- **`Controller/DependencyController.php`**
  - `blocked` — JSON: blocked task-ids (+ open-blocker counts) for a project, scoped to accessible projects. Consumed by the calendar decorator.
- **`Subscriber/DependencyLinkSubscriber.php`** (or a listener registered in `Plugin.php`) — on `TaskLinkModel::EVENT_CREATE_UPDATE`: if either created edge is `blocks`/`is blocked by` and closes a cycle, `TaskLinkModel::remove()` both directions and flash a warning.
- **Templates**
  - `Template/board/badge.php` — card badge (rendered from the memoized map).
  - `Template/task/panel.php` — task-page dependencies panel.
- **Assets**
  - `Assets/js/dependency-calendar.js` — external, CSP-safe; on calendar pages, marks blocked events. (Only needed if the CalendarPlugin decorator hook renders our badge; if CalendarPlugin renders the generic `badges[]` itself, this may reduce to CSS only.)
  - `Assets/css/dependency.css` — namespaced `dep-*`, theme-token-aware (standard + ShadcnTheme).

## 5. Data flow

1. **Board:** board renders → `template:board:private:task:before-title` fires per card with `$task` → badge partial asks `DependencyModel::getProjectBlockedMap($task['project_id'])` (computed once, memoized) → renders 🔒 + count if `open_blockers > 0`.
2. **Task page:** task hook → panel partial calls `DependencyModel::getBlockers/getBlocking($task_id)` → renders each with open/closed status.
3. **Calendar:** CalendarPlugin's `events` payload fires its new decorator hook → DependencyPlugin pushes a `blocked` badge into the event's `badges[]` for blocked tasks → CalendarPlugin's `eventContent` renders the badge. (No new client globals.)
4. **Cycle guard:** user adds a link via core's UI → core commits + fires `EVENT_CREATE_UPDATE` → subscriber checks `wouldCreateCycle` → if cyclic, removes the pair + flashes "This link would create a dependency cycle."

## 6. Permissions & safety

- `blocked` endpoint: returns only tasks in projects the requester may access (core `ProjectUserRoleModel` scoping, mirroring CalendarPlugin).
- The cycle-guard listener only acts on links the actor just created (it removes, never creates) — no privilege escalation.
- Removal is idempotent and transactional via `TaskLinkModel::remove` (removes both directions the same way core does).

## 7. Cross-plugin API surface

- **New in CalendarPlugin v1.1 (bundled):** a generic event-decorator extension point in `CalendarQueryModel::getEvents` / the `events` payload — an extensible `badges[]` (or a Kanboard hook) other suite plugins attach to. DependencyPlugin is its first consumer; SchedulerPlugin may reuse it.
- DependencyPlugin exposes `DependencyModel::getProjectBlockedMap()` as the reusable blocked-status source for later plugins.
- Cross-plugin communication stays via Kanboard's event system / documented model methods — no hard coupling.

## 8. Testing strategy

**Unit (PHPUnit host harness):**
- `getProjectBlockedMap`: task with an open blocker → blocked; blocker completed → not blocked; `blocks` count correct; empty project → empty map; memoization returns the same instance without re-querying.
- `wouldCreateCycle`: direct cycle (A↔B) rejected; transitive cycle (A→B→C→A) rejected; a valid non-cyclic link allowed; cross-project link handled.
- Subscriber: a cyclic `EVENT_CREATE_UPDATE` results in the link pair removed; a valid link is left intact.
- Endpoint scoping: `blocked` never returns tasks from inaccessible projects.

**E2E (Playwright on :8081):**
- A task with an open blocker shows the board badge; completing the blocker clears it.
- Calendar event shows the blocked badge (verifies the CalendarPlugin decorator hook end-to-end).
- Task-detail panel lists blockers/blocking with correct open/closed status.
- Creating a link that forms a cycle is rejected (flash shown, link not persisted).
- Renders on **both** a standard Kanboard install and ShadcnTheme; **0 non-baseline console errors**.

## 9. Kanboard gotchas honored (from prior live-E2E findings — see memory `kanboard-plugin-live-gotchas`)

- **No inline `<script>`** (CSP) — external, event-delegated JS; server data via `data-*`.
- **`addRoute` 4-arg form**; `url->href()` puts the plugin **inside `$params`**.
- **Asset scoping** — `template:layout:*` injects sitewide unless registration is route-gated (`Router::getPath()`); scope the calendar/board/task assets.
- **Clean-URL id extraction** on the board (`/board/42`).
- **Write paths gate on write-capable roles**, not mere membership (N/A for v1's read-only surfaces + self-scoped listener, but honored if any write is added).
- **CSS specificity** — namespaced `dep-*`, and qualify modifiers so they don't lose to element-qualified base selectors.
- **`token` is not a template helper** — generate any CSRF token in the controller.

## 10. Risks & de-risking

- **R1 — CalendarPlugin decorator hook.** The one cross-plugin coupling. De-risk: **Task-0 of the plan** adds the generic `badges[]` hook to CalendarPlugin and proves an event renders a badge, *before* DependencyPlugin logic. Fallback: client-side decoration via the existing `window.__calInstance` if the hook proves awkward.
- **R2 — Board N+1.** Covered by the memoized project map (D6) + a unit test asserting a single computation.
- **R3 — Cycle detector correctness / unbounded walk.** Covered by explicit transitive-cycle unit tests + a visited-set bound.
- **R4 — Event listener can't show an inline error.** Accepted (D3); a flash message is the v1 UX.

## 11. Suite roadmap (context, not this spec's work)

CalendarPlugin (shipped) → **DependencyPlugin** (this spec) → SchedulerPlugin (nightly sweep / reschedule policies, CLI) → EnhancedTaskPlugin (recurring / snooze / smart date-picker / time slots). Deferred DependencyPlugin work: dependency **graph page**, and any cascade/enforcement.
