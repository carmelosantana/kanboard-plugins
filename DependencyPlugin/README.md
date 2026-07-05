# DependencyPlugin

Task dependencies for Kanboard, built entirely on top of core task links
(`blocks` / `is blocked by`). No new storage model for the relationship
itself — just presentation (badges/panels) and validation (cycle guard) on
top of Kanboard's existing link feature.

## What it does

- **Board card badge** — a 🔒 pill on any board card that is blocked by at
  least one still-open task, showing the count of open blockers.
- **Task-page dependencies panel** — an accordion section on the task view
  ("Dependencies") listing "Blocked by" and "Blocks" tasks, each with an
  open/done status pill. Hidden entirely when a task has no links of either
  kind.
- **Calendar-event badge** — a 🔒 marker on blocked tasks shown in
  CalendarPlugin's month/week views, so blocked status is visible without
  opening the task.
- **Cycle guard** — rejects (removes) a `blocks` / `is blocked by` link that
  would create a circular blocking chain (A blocks B blocks C blocks A).

All three badge surfaces read from the same blocked-task computation
(`DependencyModel::getProjectBlockedMap()`), so board, task panel, and
calendar always agree about which tasks are blocked.

## Surfaces in detail

| Surface | Where | Shows |
|---|---|---|
| Board badge | `template:board:private:task:before-title` | 🔒 + open-blocker count, per card |
| Task panel | `template:task:show:before-internal-links` | Full "Blocked by" / "Blocks" lists with status pills |
| Calendar badge | CalendarPlugin's `calendarEventDecorators` hook | 🔒 marker on FullCalendar event pills |

## Cycle guard

When a task link is created or updated (`TaskLinkModel::EVENT_CREATE_UPDATE`),
`DependencyLinkSubscriber` walks the blocking chain from the new link. If it
detects that the link would close a cycle, the link is removed and the user
is shown a flash error message.

**Async-queue caveat:** on installs configured with a real async queue
driver, a cyclic link is still removed but the flash message may not reach
the user (the guard runs in a worker, not in the web request that created
the link, so there's no HTTP response to attach the flash to). On default
single-server installs (the common case, no queue driver configured) it runs
synchronously in the same request as the link creation, so the flash message
displays as expected. Either way, the cyclic link itself is always removed —
only the user-facing notification is affected by the deployment mode.

## Install

**Option A — manual:**

1. Download/copy the `DependencyPlugin` directory.
2. Unzip/place it into your Kanboard installation's `plugins/` directory, so
   the path is `plugins/DependencyPlugin/Plugin.php`.
3. Reload Kanboard — no configuration step is required.

**Option B — via ModMenu:** install from the plugin directory listing if
you're running the ModMenu plugin manager.

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4
- **Calendar badges require CalendarPlugin >= 1.1.0** (the
  `calendarEventDecorators` extension hook was added in that release). If
  CalendarPlugin is absent or older, DependencyPlugin still works — the
  board badge, task panel, and cycle guard are all fully standalone and do
  not depend on CalendarPlugin. You simply won't see blocked badges on
  calendar events.

## No HTTP endpoints

DependencyPlugin ships no controller and no JSON/AJAX endpoint in this
release. The calendar badge is produced entirely server-side: CalendarPlugin
builds its FullCalendar event payload and calls out to DependencyPlugin's
`calendarEventDecorators` closure to attach the badge before the response is
sent, so there's no client-side request for DependencyPlugin to serve. If a
future version needs a client-facing endpoint (e.g. an on-demand dependency
graph view), it will scope results to the caller's accessible projects, the
same way CalendarPlugin's own controller does.

See `CHANGELOG.md` for release history.
