# TimeReport v1.2.0 — Quick entry (project deep link + one-click report)

**Date:** 2026-08-23
**Plugin:** TimeReport (`github.com/carmelosantana/kanboard-time-report`)
**Target version:** 1.2.0 (1.1.0 released)
**Status:** design approved

## Goal

Add a project-page path into the report: from any project's ≡ menu you can jump
straight to that project's report (one click) or open the report form with the
project pre-selected. Removes the "go to the header menu, then pick the project
from a dropdown" friction for the common case of reporting on the project you're
looking at.

Presentation + controller only. No model/business-logic change, no schema change,
no new dependency. Buildless as always (plain PHP ≥ 8.4; CSP-safe).

## Scope

This is one of a three-part roadmap; only Part 1 is in this spec:
- **v1.2.0 (this spec):** quick entry — items 1 + 2.
- v1.3.0 (later): all-projects aggregate + charts (items 4 + 3), informed by
  `docs/research/2026-08-22-time-tracking-reporting-patterns.md`.
- Invoicing plugin (later): separate repo, TimeReport as dependency (captured as a chip).

## Design

### Entry point: two items in the project ≡ menu

Attach a partial to Kanboard's `template:project:dropdown` hook. Core renders that
hook as `array('project' => $project)` in both `project_header/dropdown.php` (the
≡ menu at the top of the board/list/calendar) and `project/dropdown.php` — so the
attached template receives `$project` and can read `$project['id']`. The partial
emits two `<li>` links:

1. **Generate report** (first) → `timereport/view?project_id=<id>` — the new
   read-only quick action (below). One click straight to the rendered report.
2. **Time Report…** → `timereport?project_id=<id>` — the existing form, with the
   project pre-selected.

Both are plain GET links (no forms, no `on*=` handlers) — CSP-safe.

### New controller action: `view()` — one-click report (GET, read-only)

A new route `timereport/view` → `TimeReportController::view()`:

- Read `project_id` from the query (`$this->request->getIntegerParam('project_id')`).
- Access-guard through the model's existing `assertProjectAccess` (same guard the
  form path uses). No access / missing / invalid project → redirect to the form
  (`TimeReportController::index`), not an error page.
- Apply the **quick defaults**:
  - range: **this month to date** — `date('Y-m-01')` … `date('Y-m-d')` (same as the form default);
  - granularity: **`task`** (hours grouped by task — the most useful single glance for a project);
  - `include_detail`: **false**; AI summary: **off**.
- Compute with the existing `timeReportModel->report(...)` and render the existing
  `report/show` template plus the Markdown payload — identical to `generate()`'s
  output, so Copy-as-Markdown and Export CSV work unchanged (the CSV form on
  `show.php` reads its params from `$report`).

**Why no CSRF:** `view()` performs no state change — it only reads the user's own
time data and renders a page. It is a GET link, like Kanboard's own analytics
links. CSRF protection guards state-changing POSTs; the existing `generate()` /
`exportCsv()` stay POST + `checkCSRFForm()` and are unchanged.

**Self-only invariant preserved:** `view()` mines `$this->userSession->getId()`'s
own data exactly like `generate()`; `assertProjectAccess` still gates the project.

### Pre-fill the form: `index()` reads `project_id`

`index()` gains an optional query read: if `project_id` is present and the user
has access, set it as the selected value so the form's project `<select>` opens
pre-selected. Absent/invalid → the form behaves exactly as today (no selection).
The project list shown is unchanged (the user's active projects).

### Header user-dropdown: unchanged

The global "Time Report" header entry still opens the empty form. With no project
in context there is nothing for a one-click generate to target, so the quick
action lives only where a project is in scope (the ≡ menu).

## Files

| File | Change |
|---|---|
| `Plugin.php` | register route `timereport/view` → `view`; attach `template:project:dropdown` → new partial; bump version 1.2.0 |
| `Controller/TimeReportController.php` | new `view()` action; `index()` reads optional `project_id` to pre-select |
| `Template/project/menu.php` (new) | the two `<li>` links, using `$project['id']` |
| `plugin.json` | version → 1.2.0 |
| `Test/*` | controller tests (pre-fill + `view` defaults/guard/redirect); plugin/asset test for the route + hook wiring; version bump assertions |

## Testing

Extend the existing suite (67 tests / 212 assertions at 1.1.0), keep it green.

- **`view()`**: with a valid accessible project → renders the report with
  granularity `task`, range this-month-to-date, `include_detail` false; total
  hours computed from the user's data. Missing/inaccessible `project_id` →
  redirects to the form (no exception, no data leak).
- **`index()` pre-fill**: `project_id` in the query for an accessible project →
  that id is the selected value passed to the template; absent → no selection;
  inaccessible id → no selection (silently ignored, form still renders).
- **Menu partial**: the new template emits both links with the project id and
  contains no inline `<script>` / `on*=` (CSP); `Plugin.php` registers the
  `timereport/view` route and attaches `template:project:dropdown`.
- **Version**: `plugin.json` + `Plugin.php::getPluginVersion()` = `1.2.0`; the
  version-consistency assertions updated.

## Non-goals (YAGNI)

- No custom split-button/caret control (resolved to native ≡-menu items).
- No project sidebar links.
- No quick-generate entry in the header dropdown (no project context).
- No change to `generate()` / `exportCsv()` (still POST + CSRF).
- No all-projects, no charts (v1.3.0), no invoicing (separate plugin).
- No new configurable defaults/settings — the quick action's defaults are fixed;
  the form remains the place to customize a report.

## Verification (live)

On `kb-suite` (`:8081`, admin/admin): open a demo project → the ≡ menu shows
**Generate report** and **Time Report…**; "Generate report" lands on the
per-task, this-month report for that project with working Copy-as-Markdown / CSV;
"Time Report…" opens the form with the project pre-selected; the header "Time
Report" still opens the empty form.
