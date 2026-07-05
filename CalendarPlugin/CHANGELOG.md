# Changelog

All notable changes to the CalendarPlugin will be documented in this file.

## [1.0.0] - 2026-07-04

### Added
- Global calendar page (`/calendar`) showing tasks across all accessible projects
- Per-project calendar page (`/project/:id/calendar`) scoped to a single project
- Per-project Calendar tab in the project view-switcher header
- Global Calendar link in the user dropdown navigation
- FullCalendar month grid view (`dayGridMonth`) with drag-and-drop to reschedule tasks
- FullCalendar agenda/list view (`listMonth`) toggled via toolbar button
- Unscheduled tasks sidebar — drag unscheduled tasks onto the calendar to set a due date
- Filter bar: filter events by project(s), assignee, category, and hide-completed toggle
- Per-project page auto-scopes events to that project (no manual project filter needed)
- Task popover on event click: shows project, column, assignee, and link to open task
- Overdue task highlighting (red border)
- Assignee avatar initials on each calendar event
- Project and time-estimate badges on each calendar event
- CSRF-protected drag-to-reschedule endpoint
- Admin users see all active projects; other users see only their accessible projects
