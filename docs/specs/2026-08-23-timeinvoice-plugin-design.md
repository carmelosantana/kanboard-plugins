# TimeInvoice — Plugin Design Spec

**Date:** 2026-08-23
**Status:** Approved (brainstorm complete)
**Repo (on release):** `github.com/carmelosantana/kanboard-time-invoice`
**Working directory:** `TimeInvoice/` (own git repo; lives in the `kanboard-plugins` dev harness on disk, gitignored there)

## Purpose

Generate polished PDF invoices for a solo consultant from Kanboard time data.
TimeInvoice is a **persisted-record** invoicing tool: each invoice is a saved
record with a stable sequential number, a frozen snapshot of its line items and
totals, and a `draft → sent → paid` status. It **reuses TimeReport's aggregate**
as its sole data source — it never re-mines Kanboard for hours.

Non-goals for v1 (see [Out of scope](#out-of-scope--fast-follow)): AI cover note,
multi-project invoices, overdue/void statuses, multiple named taxes.

## Suite constraints (non-negotiable)

- **Buildless.** PHP ≥ 8.4, vanilla JS + jQuery + global `KB`, plain CSS. No
  bundler, no npm, no Composer build step. What is committed ships.
- **One repo per plugin.** `plugin.json` at repo root; released by pushing a bare
  `vX.Y.Z` tag equal to `plugin.json`'s `version` and `Plugin.php`'s
  `getPluginVersion()`.
- **CSP-safe.** All JS in a delegated external asset file; no inline handlers.
- **Edit on the host**, never inside the `kb-suite` dev container (bind-mount).
- **Dev stack:** `testing/docker-compose.dev.yml` on `:8081` (admin/admin). Drive
  the dev DB via curl/PDO — never the production Kanboard MCP.

## Dependency

- **`requires` TimeReport `1.1.0`** — hard requirement; TimeReport's
  `timeReportModel->report()` is the only hours source.
- Declared as `[{ "plugin": "TimeReport", "min_version": "1.1.0", "reason": "..." }]`
  (array of objects, **bare** semver — no `>=`) in **both** `plugin.json` and
  `Plugin.php`'s metadata. The object-map form is silently dropped by ModMenu.
- **No AiConnector dependency in v1.**
- **Defensive gate:** even though `requires` should guarantee presence, if
  `timeReportModel` is absent from the container (TimeReport disabled), the UI
  shows a "needs TimeReport" notice and every route no-ops — mirrors TimeReport's
  `AiGate` degradation discipline.

## PDF engine

- **tFPDF**, vendored as a single library file + one TrueType brand font under
  `Assets/vendor/tfpdf/`. Zero Composer, zero build. TrueType/UTF-8 support so
  currency glyphs, any client name, and a real brand font all render.
- Isolated in `Model/InvoicePdf.php`: input = a snapshot array, output = PDF bytes.
  Unit-testable (assert a valid `%PDF-` header and non-trivial length) and
  swappable without touching the rest of the plugin.
- **License note:** confirm tFPDF's license file is vendored alongside the library
  and is redistribution-compatible with the plugin's MIT license (pre-release
  checklist item).

## Data model & storage (no DB migration)

Everything lives on Kanboard's existing tables. Three layers:

### Global settings — `settings` table (ConfigModel `get`/`save`)
- Business identity block: name, address, email, logo path.
- Default terms/notes text.
- Currency: code + symbol (default USD `$`).
- Default tax rate (percent).
- Invoice-number format: `INV-{YYYY}-{seq}` (format string configurable).
- Per-year sequence counter (stored as a `{year: lastSeq}` map).
- Default payment terms in days (e.g. Net 30). `issue_date` defaults to the send
  date; `due_date` defaults to `issue_date + terms_days`. Both editable on the form.

### Per-project defaults — `project_has_metadata` (ProjectMetadataModel)
- Client block: name, address, email.
- Default hourly rate.
- Optional overrides: tax rate, currency, terms.
- Rationale: one project ≈ one client, so client + rate default naturally per project.

### Persisted invoices — `project_has_metadata`, one row per invoice
- Key: `timeinvoice:inv:<id>`; value: a JSON record (see [Snapshot shape](#snapshot-shape)).
- **Project list** = filter rows by `project_id` + key prefix.
- **Global list** = one `$this->db` query on `project_has_metadata` for the key
  prefix across all projects.
- **Outstanding total** = Σ `total` of `sent`-but-not-`paid` invoices.
- Each record carries the creator `user_id`; access reuses TimeReport's
  `assertProjectAccess`. Self-scoped (solo instance = one user).

## Invoice lifecycle: `draft → sent → paid`

1. **Draft** — created from the form (date range, granularity, rate, tax, client,
   notes). Stored as **inputs only**, **no number assigned**. Its PDF/preview
   **recomputes live** from `report()` each time and is watermarked **DRAFT**.
   Editable and deletable; deleting consumes no number.
2. **Sent** — on the `draft → sent` transition:
   - call `report()` once,
   - **freeze the full snapshot** (line items, rate, tax, client, business,
     currency, totals) into the row,
   - **assign the next number** `INV-{year}-{seq}` from the per-year counter
     (`year` = year of the send date; `seq` resets each new year).
   - From here the PDF renders the **frozen snapshot** — reproducible months later
     even if underlying hours change. Numbering is **gap-free** (drafts never
     consume a number).
3. **Paid** — status flip only; snapshot untouched; drops out of the outstanding total.

## Line-item rollup (reuse, don't re-mine)

`Model/InvoiceBuilder.php` is **pure** (operates on plain arrays; no DB) and
unit-testable, mirroring TimeReport's pure-method discipline.

- Calls `timeReportModel->report(projectId, startDate, endDate, granularity, includeDetail: true, userId)`.
- Maps **`report()['breakdown'][]` rows → line items** at the selected granularity
  (**default `task`**, switchable to `day` / `week` / `total` on the form).
  Using `breakdown[]` (not `detail[]`) captures the *complete* billable-hours
  picture uniformly across all granularities — `detail[]` only lists
  completed-in-range tasks and would miss contributed-but-not-completed hours.
- Each line item: `{ label, hours }` → `amount = round(hours × rate, 2)`.
- **Subtotal** = Σ amounts. **Tax** = `round(subtotal × taxRate, 2)` when the
  single optional tax rate is enabled (toggleable off per invoice). **Total** =
  subtotal + tax.
- `breakdown[]` shape consumed (from TimeReport): `{ key, label, hours, task_count }`.

### Snapshot shape
The frozen JSON stored on a `sent`/`paid` invoice (illustrative):

```json
{
  "id": "…",
  "number": "INV-2026-001",
  "status": "sent",
  "user_id": 1,
  "project_id": 12,
  "project_name": "Acme redesign",
  "issue_date": "2026-08-23",
  "due_date": "2026-09-22",
  "range": { "start": "2026-08-01", "end": "2026-08-31" },
  "granularity": "task",
  "currency": { "code": "USD", "symbol": "$" },
  "business": { "name": "…", "address": "…", "email": "…", "logo": "…" },
  "client": { "name": "…", "address": "…", "email": "…" },
  "rate": 150.0,
  "line_items": [ { "label": "#123 Homepage build", "hours": 8.5, "amount": 1275.0 } ],
  "subtotal": 1275.0,
  "tax": { "enabled": true, "rate": 8.875, "amount": 113.16 },
  "total": 1388.16,
  "notes": "…"
}
```

A **draft** stores the same fields minus `number`, with `line_items`/totals
recomputed live rather than frozen.

## UI & routes

- **Project sidebar "Invoices"** → that project's invoice list + "New invoice"
  (project preselected). Attached via the project-view sidebar hook.
- **Header-dropdown "All invoices"** → cross-project list + outstanding total
  (mirrors TimeReport's `template:header:dropdown` entry point).
- **Settings page** `timeinvoice/settings` → business identity + global defaults.
- Routes (auto-derived controller/method + explicit `addRoute` for clean URLs):
  `list` (project + global), `form` (new/edit draft), `saveDraft`, `send`,
  `markPaid`, `delete`, `pdf`, plus `settings`/`saveSettings`.
- CSP-safe assets: `Assets/js/timeinvoice.js` (delegated, no inline handlers),
  `Assets/css/timeinvoice.css`, injected via `template:layout:js` / `:css`.

## Polished PDF layout

Header band (business name/logo · "INVOICE" · number · issue/due dates) ·
**Bill To** client block · meta (project, date range) · line-items table
(Description · Hours · Rate · Amount) · Subtotal / Tax / **Total** · notes/terms
footer · **DRAFT** watermark when unsent.

## Component map (buildless skeleton)

```
TimeInvoice/
├── plugin.json                     # name, version, requires:[TimeReport 1.1.0], php/kanboard version
├── Plugin.php                      # initialize(): services, routes, hooks, assets; getters aligned w/ plugin.json
├── Controller/
│   ├── InvoiceController.php        # list / form / saveDraft / send / markPaid / delete / pdf
│   └── SettingsController.php       # settings / saveSettings
├── Model/
│   ├── InvoiceModel.php             # CRUD + per-year counter over project_has_metadata
│   ├── InvoiceBuilder.php           # PURE: report() breakdown → line items, subtotal/tax/total
│   └── InvoicePdf.php               # snapshot array → PDF bytes via tFPDF
├── Helper/
│   └── InvoiceHelper.php            # money / date / status-badge formatting for templates
├── Template/invoice/               # list.php, form.php, _line_items.php, settings.php,
│                                    # project sidebar partial, header dropdown partial
├── Assets/{css,js}/                # timeinvoice.css, timeinvoice.js (delegated, CSP-safe)
├── Assets/vendor/tfpdf/            # vendored library + TrueType font + license
├── Test/                           # InvoiceBuilderTest, InvoiceModelTest, InvoicePdfTest,
│                                    # PluginMetaTest (version/dep alignment), TemplateAssetsTest,
│                                    # InvoiceControllerTest
├── LICENSE (MIT), README.md
└── .github/workflows/release.yml   # zip-on-tag CI (verbatim across plugins)
```

## Testing strategy

- **InvoiceBuilder** (pure): rollup per granularity, amount/subtotal/tax/total math,
  tax on/off, rounding, empty report.
- **InvoiceModel:** create draft (no number), send (assigns gap-free per-year
  number + freezes snapshot), markPaid, delete draft, project vs global listing,
  outstanding total.
- **InvoicePdf:** emits a valid `%PDF-` document of non-trivial length from a
  snapshot; DRAFT watermark path.
- **PluginMeta:** `plugin.json` ⇄ `Plugin.php` version match; `requires` shape
  (array of objects, bare version) in both files.
- **TemplateAssets:** referenced templates/assets exist; no inline JS handlers.
- **Controller:** route wiring, TimeReport-absent defensive gate, access control.

## Release & directory

1. Own repo `kanboard-time-invoice`, root = plugin, MIT license.
2. Zip-on-tag CI verbatim; produce a clean single-folder zip; verify the published
   asset downloads 200.
3. Align `plugin.json` `version` == tag `vX.Y.Z` == `Plugin.php` `getPluginVersion()`.
4. **List in `kanboard-modmenu-directory/plugins.json`** so ModMenu users can
   discover/install it (final step per the suite methodology).

## Out of scope — fast-follow

- **AI cover note** (`recommends` AiConnector, degrades to blank): the form's
  notes/cover field is designed so a later AI pass can populate it. No AI
  dependency in v1.
- Multi-project invoices; `overdue`/`void` statuses; multiple named taxes.

## Design decisions chosen without an explicit question

- **Line items from `breakdown[]`, not `detail[]`** — complete billable hours,
  uniform across granularities.
- **Per-year sequence reset** — `INV-2026-001` → `INV-2027-001`.
- **Number & snapshot commit on `draft → sent`** — gap-free numbering; drafts are
  unnumbered live previews.
