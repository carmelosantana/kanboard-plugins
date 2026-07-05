# Changelog

## [1.0.0] - 2026-07-05

Initial release.

### Added

- Board card blocked badge: a 🔒 pill with an open-blocker count, rendered
  on any board card blocked by at least one still-open task.
- Task-page dependencies panel: an accordion section listing "Blocked by"
  and "Blocks" tasks with open/done status pills; hidden when a task has no
  dependency links.
- Calendar-event blocked badge: a 🔒 marker on blocked tasks in
  CalendarPlugin's month/week views, via the `calendarEventDecorators`
  extension hook (requires CalendarPlugin >= 1.1.0).
- Cycle guard: rejects/removes a `blocks` / `is blocked by` link that would
  create a circular blocking chain, with a flash message on synchronous
  (default, no queue driver) installs.
- Shared blocked-task computation (`DependencyModel::getProjectBlockedMap()`)
  backing all three badge surfaces, so board, task panel, and calendar
  always agree on blocked status.

### Notes

- No new storage model — built entirely on Kanboard's core task links.
- No HTTP controller/endpoint shipped in this release; see `README.md`.
