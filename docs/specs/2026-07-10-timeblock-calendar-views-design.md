# TimeBlock + CalendarPlugin v1.2.0 — Design & Shared Contract

- **Date:** 2026-07-10
- **Status:** Approved via brainstorming; source of truth for two parallel agent builds.
- **Two deliverables, built in parallel by separate agents (different plugins/repos):**
  - **TimeBlock** — new standalone plugin (`kanboard-time-block`), one timed block per task.
  - **CalendarPlugin v1.2.0** — add a `calendarEventSources` hook + week/day time-grid views.
- The ONLY thing the two share is the `calendarEventSources` contract below. **This section is
  authoritative** — both agents implement to it verbatim so they can't drift. TimeBlock is
  independently useful without CalendarPlugin (panel + board badge); the calendar rendering is a
  `recommends`, not a hard requirement.

## 1. Shared contract — `calendarEventSources`

A container-registered extension point on CalendarPlugin, mirroring the existing
`calendarEventDecorators` (see `CalendarPlugin/Model/CalendarQueryModel.php:101`). Decorators
*mutate* existing due-date events; **sources *contribute new* events**.

**Container key:** `calendarEventSources` — an array of callables. Consumers append (never
overwrite), exactly like DependencyPlugin does for decorators (`DependencyPlugin/Plugin.php:50`):

```php
$this->container['calendarEventSources'] = array_merge(
    isset($this->container['calendarEventSources']) ? $this->container['calendarEventSources'] : array(),
    array(function (int $userId, array $filters, int $rangeStart, int $rangeEnd): array {
        // return FullCalendar event objects for this user/range
        return array();
    })
);
```

**Callable signature:** `fn(int $userId, array $filters, int $rangeStart, int $rangeEnd): array`
- `$filters` is the same array CalendarController::events() builds:
  `['project_ids'=>int[], 'assignee_id'=>int, 'category_id'=>int, 'column_id'=>int, 'hide_completed'=>bool]`.
- `$rangeStart`/`$rangeEnd` are unix timestamps (the visible FullCalendar range).

**Returned event shape** (identical to `CalendarQueryModel::mapRowToEvent()`):

```php
[
  'id'    => 'timeblock-123',   // STRING, namespaced. MUST NOT collide with due-date events (which use the int task id) or other sources.
  'title' => 'Task title',
  'start' => '2026-07-14T14:00:00+00:00', // ISO8601, date('c', $ts)
  'end'   => '2026-07-14T16:00:00+00:00', // ISO8601 or null
  'allDay'=> false,
  'color' => '#rrggbb',
  'url'   => '/…/task/123',
  'extendedProps' => ['project' => '…', 'badges' => [], /* free-form */],
]
```

**Contract rules (both sides honor these):**
1. **Access control is the source's job.** A source MUST filter to projects `$userId` may access
   (mirror `CalendarQueryModel::accessibleProjectIds($userId)`) and honor `$filters` where
   meaningful. CalendarPlugin does NOT re-check source events.
2. **Only return events overlapping `[$rangeStart, $rangeEnd)`.**
3. **`id` MUST be namespaced** (e.g. `timeblock-<taskId>`) — unique across sources and distinct
   from due-date event ids.
4. **Decorators are NOT applied to source events** (they have no task `$row`); a source builds
   complete events itself, including any `extendedProps.badges`.

**CalendarPlugin merge logic** (in `CalendarQueryModel::getEvents`, AFTER the existing due-date
build + decorator loop):

```php
$sources = isset($this->container['calendarEventSources']) ? $this->container['calendarEventSources'] : array();
foreach ($sources as $source) {
    foreach (call_user_func($source, $userId, $filters, $rangeStart, $rangeEnd) as $ev) {
        $events[] = $ev;
    }
}
return $events;
```

Read defensively: absent key → `[]` → behavior byte-identical to 1.1.0.

## 2. TimeBlock plugin (new — `kanboard-time-block`)

**Purpose:** give a task one *planned* start+end datetime (a time block) — distinct from `date_due`
(deadline) and `date_started` (actual start). It's the substrate CalendarPlugin's time-grid renders.

**Storage:** task metadata (no DB migration). Keys `timeblock_start`, `timeblock_end` — unix
timestamps stored as strings via core `TaskMetadataModel` (`taskMetadataModel->get/save/remove`).
Absent/empty either key ⇒ no block.

**Model — `Kanboard\Plugin\TimeBlock\Model\TimeBlockModel` (extends `Kanboard\Core\Base`):**
- `get(int $taskId): ?array` → `['start'=>int, 'end'=>int]` or `null`.
- `set(int $taskId, int $start, int $end): bool` — validates `end > start`; saves both keys.
- `clear(int $taskId): bool` — removes both keys.
- `blocksForCalendar(int $userId, array $filters, int $rangeStart, int $rangeEnd): array` — the
  `calendarEventSources` provider body: query `task_has_metadata` for `name='timeblock_start'` with
  the value (cast int) in `[$rangeStart, $rangeEnd)`, join tasks for project/title/color, filter to
  `$userId`'s accessible projects + `$filters`, and return namespaced event objects per §1. (Unix
  timestamps are 10 digits through year 2286, so a same-length string/int compare on the metadata
  value is safe; cast to int to be explicit.)

**Surfaces:**
- **Task-page panel** — hook `template:task:show:before-internal-links` (same hook DependencyPlugin
  uses, `DependencyPlugin/Plugin.php:32`): show the current block ("Tue 14 Jul, 14:00–16:00") with
  Set/Edit/Clear. Setting posts to a TimeBlock controller action.
- **Board-card badge** — hook `template:board:private:task:before-title`
  (`DependencyPlugin/Plugin.php:29`): a small badge (e.g. `🗓 Tue 14:00`). Ship a ~1KB CSS injected
  sitewide via `template:layout:css` (mirror DependencyPlugin's rationale, `Plugin.php:41`).
- **Calendar source** — register the `calendarEventSources` provider (§1) from `Plugin::initialize()`
  using the `array_merge` idiom, closure capturing `$this` so `TimeBlockModel` resolves lazily.

**Controller — `TimeBlockController`** (`save`, `clear` actions):
- Admin OR write-capable role on the task's project, via
  `$this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $projectId)` (the
  pattern SubtaskGenerator's GeneratorController + CalendarPlugin's `canUserReschedule` use) →
  `AccessForbiddenException` otherwise.
- `checkCSRFForm()` on every mutation. Parse a date + start-time + end-time into two unix ts;
  reject `end <= start`. Redirect back to the task with a flash.

**plugin.json:** name `TimeBlock`, version `1.0.0`, `kanboard_version >=1.2.47`, `php_version >=8.4`,
homepage `https://github.com/carmelosantana/kanboard-time-block`,
`recommends: [{plugin: CalendarPlugin, min_version: "1.2.0", reason: "renders time blocks on the calendar week/day views"}]`.

**Non-goals (v1):** multiple blocks per task; overlap detection/warnings; all-day blocks (blocks are
timed — use `date_due` for whole-day scheduling); drag/resize on the calendar (CalendarPlugin's
future); recurring blocks.

**Tests (PHPUnit, no network/DB-less where possible):** TimeBlockModel get/set/clear + `end>start`
validation + `blocksForCalendar` shape/namespacing/range-filter/access-filter (seed metadata + a
couple tasks/projects); TimeBlockController access + CSRF gates; provider returns §1-shaped events;
PluginTest (version 1.0.0, hooks registered).

## 3. CalendarPlugin v1.2.0 (existing — `kanboard-calendar`)

**Change 1 — `calendarEventSources` hook.** Implement the merge in
`CalendarQueryModel::getEvents` exactly per §1. CalendarPlugin only *reads/merges* the sources — it
registers none itself. Existing decorator behavior unchanged.

**Change 2 — week/day time-grid views + switcher.** In `Assets/js/calendar.js` +
`Template/calendar/index.php`, add FullCalendar `timeGridWeek` and `timeGridDay` views and expose
them in the `headerToolbar` view switcher alongside the current month + list/agenda views. The
events feed needs NO backend change — `CalendarController::events()` already parses `start`/`end`
from FullCalendar, so narrower week/day ranges pass straight through.

**Render-only (lean scope, confirmed):** do NOT add resize-to-set-duration or new write paths on the
time grid. Keep the existing month drag-to-reschedule (`calendar/update` → `date_due`) working and
consistent; disable event resizing (`eventResizableFromStart`/`eventDurationEditable` false). Deferred
to a follow-up: resize-to-set-duration, inline edit, create-by-click, undo, WIP warnings.

**Version:** 1.1.0 → **1.2.0**. Update CHANGELOG + README (new views + the `calendarEventSources`
extension point). Backward-compatible: DependencyPlugin/SchedulerPlugin `recommends CalendarPlugin
>=1.1.0` still hold; the decorator hook is untouched.

**Tests:** extend `CalendarQueryModelTest` — a registered source's events ARE merged into
`getEvents` output; absent-key path unchanged; existing decorator tests still pass. PluginTest
version 1.2.0.

## 4. Integration & release (orchestrator)

- The two agents build + unit-test + local-smoke on branches and **hand back** — they do NOT push,
  tag, release, create repos, or edit the directory. The orchestrator: creates `kanboard-time-block`
  (+ CI, per the repo-split runbook), releases both (`kanboard-calendar` → v1.2.0,
  `kanboard-time-block` → v1.0.0), and updates `kanboard-modmenu-directory` (bump CalendarPlugin to
  1.2.0; add TimeBlock entry with its `recommends`).
- **Joint E2E (after both land):** with CalendarPlugin 1.2.0 + TimeBlock installed, set a block on a
  task → it appears as a distinct timed event on the week/day grid (namespaced id, not merged with
  the due-date event); a block on a task with no due date still appears; TimeBlock alone (no
  CalendarPlugin) still shows the panel + board badge.
- Dev harness: TimeBlock must be added to `testing/docker-compose.dev.yml` + both lists in
  `testing/run-plugin-tests.sh` (these live in the demoted `kanboard-plugins` repo). CalendarPlugin
  is already wired.
