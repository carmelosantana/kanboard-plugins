# TimeReport Plugin — Design

**Date:** 2026-08-21
**Status:** Approved (brainstormed with the user)
**Repo:** `github.com/carmelosantana/kanboard-time-report` (new) · plugin `name`: `TimeReport`
**Methodology:** Follow the `kanboard-plugin-suite` skill for all suite conventions
(buildless architecture, `Plugin.php` anatomy, storage, CSP/clean-URL gotchas,
dependency array shape, AiConnector consumption, zip-on-tag release, directory listing).
This spec only records what is specific to TimeReport.

## Purpose

A consultant's hours report. For **one project** over a **date range**, produce a
report of the current user's logged hours, in the **shape the current client needs**,
optionally with an **AI narrative summary** of the completed work. Self-only in v1,
strictly access-filtered. It is a pure query→render tool: no persisted state.

## Global constraints

- Buildless: plain PHP ≥ 8.4, vanilla JS + jQuery + `KB`, plain CSS. What is committed
  ships. Repo root is the plugin.
- Versions must agree across `plugin.json.version`, `Plugin.php::getPluginVersion()`,
  and the release tag `vX.Y.Z`. First release: `v1.0.0`.
- `kanboard_version >= 1.2.47`, `php_version >= 8.4`, license MIT, author "Carmelo Santana".
- No new DB tables, no migration. No persisted storage in v1.
- AI is optional and must degrade to fully manual (hide the toggle, guard the routes).
- CSP: no inline `<script>` / handlers — all JS in the injected asset, delegated.
- Money/rates/invoicing, PDF export, saved presets, and multi-user/client reports are
  **out of scope** for v1 (see "Out of scope" — the code is factored so they extend in).

## Data model — the hours union (correctness core)

Inputs: `projectId` (one project the user may access), `startDate`, `endDate`
(inclusive, whole-day boundaries), and the toggles below. The current user is
`userId = $this->userSession->getId()` (v1 hardcodes self; keep it a single named
variable so a future `userId` parameter is a one-line change).

**Access guard (first, always):** confirm `projectId` is in
`ProjectPermissionModel::getActiveProjectIds($userId)`; otherwise throw
`AccessForbiddenException`. Never mine a project the user cannot see.

Compute hours as a **union of two sources, deduped so no task is counted twice:**

1. **Subtask time entries** — the granular, dated truth. Pull the user's subtask
   time-tracking rows (`SubtaskTimeTrackingModel` — `getUserTimesheet($userId)` or the
   task-scoped query), keep rows whose `start` timestamp falls within
   `[startDate, endDate]` **and** whose task belongs to `projectId`. Each row
   contributes its `time_spent` (or `end - start` when `time_spent` is absent),
   attributed to its `task_id`, dated by `start`. Record the set of task ids that had
   at least one in-range subtask entry — call it `subtaskTaskIds`.

2. **Task-level `time_spent` fallback** — for tasks in `projectId` assigned to the user
   (`owner_id == userId`) with `time_spent > 0`, whose `date_completed` falls within
   `[startDate, endDate]`, **and whose id is NOT in `subtaskTaskIds`**. Each such task
   contributes its full `time_spent`, dated by `date_completed`. The exclusion is the
   dedup rule: a task that logged subtask time is represented by source 1 only.

The result is a flat list of `(taskId, hours, date)` contributions. Everything else is
aggregation over that list.

**Completed-task detail set:** tasks in `projectId` assigned to the user with
`date_completed` within range — for the optional detail listing (title, task ref,
hours from the union above, completion date, `category_id`, tags via
`TaskTagModel::getTagsByTaskIds`).

## Report shape — generate-time toggles

Chosen at generation time; nothing saved. Parameters:

- `project_id` (required), `start_date`, `end_date` (required).
- `granularity`: one of `day` | `week` | `task` | `total`.
  - `day` — sum contributions by calendar day (`Y-m-d` key).
  - `week` — sum by ISO week (`o-\WW` key; label as the week's Mon–Sun span).
  - `task` — sum by task (one row per task, with its title/ref).
  - `total` — a single grand-total row.
- `include_detail` (bool) — append the completed-task detail list.
- `include_ai_summary` (bool) — only offered/honored when the AI gate is open.

Rendered report structure (in order):
1. **Header:** project name, range, **total hours** (sum of all contributions).
2. **Breakdown table:** rows per the chosen granularity, each with hours (and a
   running/where-relevant task count).
3. **Completed-task detail** (if `include_detail`): title, task ref (`#id`), hours,
   completion date, category, tags.
4. **AI narrative** (if `include_ai_summary` and gate open): the summary + highlights.

Hours display: format decimal hours to 2 dp (Kanboard stores time in hours as a float).

## Delivery

Three surfaces of the **same** computed report (compute once, render three ways):

- **On-screen HTML** — the `generate` view.
- **Copy as Markdown** — the controller renders a Markdown string of the report into
  the page (e.g. a hidden `<textarea>` / `data-` attribute); a button in the injected
  JS copies it via `navigator.clipboard.writeText(...)`. No inline JS.
- **CSV download** — an `exportCsv` route that recomputes for the same params and
  streams `Content-Type: text/csv` with a filename like
  `time-report-<project>-<start>_<end>.csv`. Columns follow the chosen granularity
  (e.g. day/week/task, hours) plus, if `include_detail`, the detail rows — keep the CSV
  layout simple and documented in the plan.

## AI summary via AiConnector

Follow the `AiGate` pattern from the skill's `references/ai-integration.md`:

- `TimeReport\Model\AiGate::isReady($container)` = PHP ≥ 8.4 AND AiConnector present
  (`class_exists(ProviderRegistry::class)`) AND `(new ProviderRegistry($container))->isReady()`.
  Add `$phpVersionId` / `$connectorPresent` test overrides so all four branches are unit-testable.
- Consult the gate in `Plugin::initialize()` (only surface the AI toggle when open —
  or render it disabled with a hint) **and** in the controller (guard `generate` when
  `include_ai_summary` is set; ignore/hide rather than error when the gate is closed).
- The call: `ProviderRegistry::structured($messages, json_encode(self::SCHEMA), $profileId)`.
  Schema returns e.g. `{ "summary": string, "highlights": string[] }`. Inject the
  registry via a setter for tests (`setProviderForTesting`).
- **What is sent:** only the completed-task list already visible to the user — task
  titles, hours, categories/tags, completion dates. No descriptions/comments beyond
  titles. Render a one-line "what will be sent" summary next to the toggle so the user
  consents knowingly. AI proposes a summary; it is not authoritative.
- Optional per-call provider picker: if included, populate from `listProfiles()` /
  `getDefaultProfileId()` and validate the submitted id against
  `array_column($registry->listProfiles(), 'id')`.

## Routes & entry point

- Entry point: a "Time Report" link in the user header dropdown
  (`template:layout:top` / the user dropdown hook used by Kensho — match the suite's
  existing pattern). Build links with `$this->helper->url->href(...)` (clean-URL safe).
- `TimeReportController`:
  - `index` — the form: project picker (accessible projects), range inputs, toggles.
  - `generate` — validate + access-guard, compute the union, render the report (and the
    Markdown payload for the copy button).
  - `exportCsv` — same params, stream CSV.

## File structure

```
TimeReport/
├── plugin.json
├── Plugin.php
├── Controller/TimeReportController.php
├── Model/TimeReportModel.php      # the hours union + aggregation (the tested core)
├── Model/AiGate.php               # AI availability gate
├── Model/AiSummaryModel.php       # builds messages + calls ProviderRegistry::structured()
├── Helper/TimeReportHelper.php    # hours/markdown/csv formatting helpers
├── Template/report/form.php
├── Template/report/show.php
├── Template/report/_breakdown.php # partial: the granularity table
├── Template/report/_detail.php    # partial: completed-task detail
├── Assets/js/timereport.js        # copy-to-clipboard (delegated)
├── Assets/css/timereport.css
├── Test/ …                        # unit tests
├── LICENSE  README.md  CHANGELOG.md
└── .github/workflows/release.yml  # zip-on-tag (verbatim from the skill)
```

Keep `TimeReportModel` free of rendering — it returns structured aggregates; the
templates/helper/CSV/Markdown/AI all consume the same aggregate.

## plugin.json

```json
{
    "name": "TimeReport",
    "description": "Consultant hours reporting: pick a project and date range, choose per-day/per-week/per-task breakdowns, list completed tasks, and optionally add an AI summary. Copy as Markdown or export CSV.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-time-report",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "recommends": [
        { "plugin": "AiConnector", "min_version": "1.0.0", "reason": "adds an AI narrative summary of the completed work (the report works fully without it)" }
    ]
}
```

Mirror the same `recommends` array in the directory entry.

## Testing (the risk surface)

Unit tests must cover:
- **Union + dedup:** a task with subtask entries in range is counted from subtask
  source only; a task with only task-level `time_spent` (completed in range) is counted
  from the fallback; a task completed outside range is excluded; total = sum of
  contributions with no double count.
- **Bucketing:** `day` and `week` key computation (incl. an ISO-week boundary case),
  `task` grouping, `total`.
- **Access guard:** a `projectId` outside `getActiveProjectIds` is refused.
- **Formatting:** Markdown and CSV output for a known aggregate; hours 2-dp.
- **AiGate:** all four branches (PHP too old / connector absent / present-not-ready /
  ready) via the test overrides; `generate` ignores `include_ai_summary` when closed.

## Out of scope (v1) — but design-compatible

- Money, hourly rates, invoice totals.
- PDF export (no buildless PDF toolchain).
- Saved per-client report presets.
- Reporting on other users / whole-team / client-facing shared reports. v1 is self-only;
  keep `userId` a single variable and the aggregation preset-agnostic so these extend
  cleanly.

## Release & directory

Per the skill's `references/release.md` and `references/directory.md`: create the repo
with the verbatim `release.yml`, align versions, push `v1.0.0`, verify the asset is a
single-folder zip that 200s, then add a new entry to
`kanboard-modmenu-directory/plugins.json` (with the `download` URL and the `recommends`
array). The user handles verification/integration/release after the build agent hands back.
