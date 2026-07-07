# Changelog

All notable changes to the SchedulerPlugin will be documented in this file.

## [1.0.0] - 2026-07-07

### Added
- Daily sweep that rolls overdue, still-open tasks forward, per **opt-in project**.
- Policy pipeline: skip tasks with open blockers (DependencyPlugin), snap to today,
  shift off weekends/holidays (working-days), and de-clump days over a threshold.
- Three triggers wrapping one `SchedulerRunner`: lazy web-cron (guarded once/day),
  an admin **Run now** button with **dry-run preview**, and `./cli scheduler:run`.
- Audit: `scheduler_runs` + `scheduler_moves` tables with an admin **log page**,
  plus one clearly-automated per-run **activity-stream summary** (system actor).
- **Calendar auto-moved badge** via CalendarPlugin's `calendarEventDecorators` hook.
- Admin settings page (master switch, target hour, working days, holidays, de-clump
  threshold, badge window, respect-blocks, post-to-activity) and per-project toggle.
