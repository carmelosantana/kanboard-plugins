# TimeInvoice — AI Cover Note — Design Spec

**Date:** 2026-08-23 (rev. 2026-08-30 — reconciled to shipped AiConnector 1.1.0 + TimeReport 1.4.2)
**Status:** Approved design; spec revised for current ecosystem — pending re-approval
**Plugin:** TimeInvoice (own repo `github.com/carmelosantana/kanboard-time-invoice`, currently v1.0.0)
**Target release:** TimeInvoice **v1.1.0** (additive minor)
**Dependencies:**
- **`requires` TimeReport `1.3.0`** (raised from 1.1.0 — see [TimeReport data source](#timereport-data-source-current))
- **`recommends` AiConnector `1.1.0`** (soft — the release that shipped `ModelCatalog`)

## Purpose

Add an **optional, AI-generated cover note** to TimeInvoice: a short, professional
prose summary of the work billed on an invoice, written from the underlying
Kanboard task data and dropped into the invoice's **existing** editable
`notes`/cover field. The v1.0.0 spec already reserved this socket
("the form's notes/cover field is designed so a later AI pass can populate it").

This feature is **purely additive and fully degradable**: with no AI backend
configured, TimeInvoice behaves exactly as v1.0.0 (a plain, manually-typed notes
field). Invoicing never depends on AI.

**In scope (v1.1.0):** a single cover-note paragraph per invoice, generated on
demand, editable, regenerate-able.

**Out of scope / fast-follow:** AI-polished per-line-item descriptions;
per-invoice tone selection; multi-note/templated sections; team/multi-user
invoices (see [Multi-user scope](#multi-user-scope)).

## Suite constraints (non-negotiable)

- **Buildless.** PHP ≥ 8.4, vanilla JS + jQuery + global `KB`, plain CSS. No
  bundler, npm, or Composer build step. What is committed ships.
- **One repo per plugin.** Released by pushing a bare `vX.Y.Z` tag equal to
  `plugin.json`'s `version` and `Plugin.php`'s `getPluginVersion()`.
- **CSP-safe.** All JS in the delegated external asset file
  (`Assets/js/timeinvoice.js`); no inline handlers.
- **Edit on the host**, never inside the `kb-suite` dev container.
- **Dev stack:** `testing/docker-compose.dev.yml` on `:8081` (admin/admin). Drive
  the dev DB via curl/PDO — never the production Kanboard MCP.
- **Tests:** `./testing/run-plugin-tests.sh TimeInvoice` from the monorepo root
  (sqlite `:memory:`; tests extend `KanboardTests\units\Base`).

## AiConnector integration (SHIPPED — concrete contract)

All model access goes through AiConnector's public PHP API (never a bespoke HTTP
client in TimeInvoice). AiConnector **1.1.0** shipped the introspection layer, so
these signatures are real, not planned:

### `Model\ProviderRegistry` (v1.0.0, unchanged) — `new ProviderRegistry($this->container)`
- `isReady(): bool` — no network; true when ≥1 provider profile is usable.
- `listProfiles(): array<{id,label,provider,model}>` — populates the profile
  picker (no secrets, no base_url).
- `getDefaultProfileId(): string` — preselected picker value.
- `structured(array $messages, string $schema, ?string $profileId): array` —
  provider-agnostic structured call; returns a decoded PHP array. TimeInvoice
  passes a `{"cover_note":"string"}` JSON schema and reads `['cover_note']`.

### `Model\ModelCatalog` (NEW in 1.1.0) — `new ModelCatalog($this->container)`
- `getContextWindow(?string $profileId = null): int` — model's max context length
  in tokens. **Never throws** (returns `8192` GLOBAL_DEFAULT for a missing/dangling
  profile). Resolution: in-request memo → 24h config cache → Ollama `/api/show`
  live discovery (Ollama only) → curated `ModelContextTable` → 8192.
- `countTokens(string $text, ?string $profileId = null): int` — provider-aware
  heuristic token estimate. **Never throws.**
- `getInputBudget(?string $profileId = null, int $reserveOutputTokens = 1024): int`
  — `max(0, window − reserve)`, reserve clamped to ≤ half the window. TimeInvoice
  uses this to size the prompt.

Because `structured()` is provider-agnostic, **direct-Ollama vs. the metamodels
proxy vs. a frontier API is only a matter of which AiConnector profile the picker
selects** — TimeInvoice needs zero code change to switch backends. The metamodels
proxy is registered in AiConnector as an OpenAI-compatible (or Ollama) profile.

### Dependency declaration & degradation

- **`recommends`** (soft) AiConnector — array-of-objects, **bare** semver — in
  **both** `plugin.json` and `Plugin.php` metadata:
  `[{ "plugin": "AiConnector", "min_version": "1.1.0", "reason": "Optional AI cover-note generation via ProviderRegistry + ModelCatalog." }]`.
- **Gate (`AiGate`-style, mirrors TimeReport).** `ModelCatalog` exists only in
  AiConnector ≥ 1.1.0, and `recommends` is soft (not version-enforced), so the
  gate must check **both** classes:
  `aiReady()` ≡
  `class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry)`
  **&&** `class_exists(\Kanboard\Plugin\AiConnector\Model\ModelCatalog)`
  **&&** `(new ProviderRegistry($this->container))->isReady()`.
  When false: the Generate button + profile picker are **not rendered**; the notes
  field is a plain manual textarea; the `generateCoverNote` route no-ops with a
  clear "needs AiConnector 1.1.0+" message. No fatals, no partial UI. This
  gracefully handles a user on AiConnector 1.0.0 (ProviderRegistry present,
  ModelCatalog absent → feature hidden).

## TimeReport data source (current)

TimeInvoice's hours come only from TimeReport's `report()` aggregate (never
re-mined). TimeReport is now **v1.4.2**; the relevant facts:

- **Call (unchanged, 6-arg positional — still correct and forward-compatible):**
  ```php
  $this->timeReportModel->report($projectId, $start, $end, $granularity, true, $userId);
  // includeDetail=true; subjectUserIds defaults null, allUsers defaults false
  ```
  The first six params are frozen by TimeReport's contract. Two optional
  multi-user params (`?array $subjectUserIds = null`, `bool $allUsers = false`)
  were appended; TimeInvoice does not pass them (solo scope).
- **`breakdown[]` row shape (current):**
  `{ key:string, label:string, hours:float, task_count:int, task_ids:int[] }`.
  **`task_ids` is new (since ~v1.3.0) and is the key enabler for this feature** —
  it gives the exact billed task ids per line item, so the cover-note context can
  be gathered without re-deriving task membership. Line-item math is unchanged:
  sum `breakdown[].hours × rate`.
- **Correctness floor → `requires` TimeReport `1.3.0`.** v1.3.0 fixed a
  misattribution/double-count bug: suppression of the completed-task `time_spent`
  fallback now runs across **all users and all periods** before the subject
  filter, so a task with tracked subtask time never also adds its task-level
  fallback. On 1.1.0/1.2.0 the same data can **over-bill**. An invoicing plugin
  must bill the corrected hours, so the hard `requires` floor is raised
  1.1.0 → **1.3.0**. (Plan-time verification: confirm `task_ids` is present in
  `breakdown[]` at the pinned tag; bump the floor if it landed later than 1.3.0.)

### Multi-user scope

`report()`'s default subject set (both optional params unset) is the requesting
user alone — identical effective scope to the existing invoice hours. The
cover-note context is gathered under the **same requester-only scope** as the
invoice it describes. Team-wide invoicing (`allUsers = true`, permission-gated,
`scope_denied` handling) is explicitly out of scope for v1.1.0.

## Component map (additions to TimeInvoice)

```
TimeInvoice/
├── Model/
│   ├── CoverNoteContext.php     # NEW — PURE: priority-tiered, budget-trimmed context payload
│   └── CoverNoteGenerator.php   # NEW — orchestrates gather → budget → assemble → structured()
├── Controller/
│   ├── InvoiceController.php     # +generateCoverNote (POST, CSRF, access-scoped, JSON {note})
│   └── SettingsController.php    # +style-instructions field (admin-gated, existing pattern)
├── Template/invoice/
│   ├── form.php                  # +Generate button + profile <select> (rendered only when aiReady())
│   └── settings.php              # +"Cover-note style instructions" textarea
├── Assets/js/timeinvoice.js      # +delegated handler: POST generateCoverNote → fill notes textarea
└── Test/                         # +CoverNoteContextTest, CoverNoteGeneratorTest,
                                  #  +generateCoverNote controller test, PluginMeta/TemplateAssets updates
```

No new tables, no migration. The generated string is placed into the existing
draft `notes` input and freezes into the snapshot `notes` on send.

## `CoverNoteContext` (pure) — priority-tiered assembly

Pure class operating on plain arrays plus an injected token-count callback; no DB,
no network; the core unit-tested unit.

**Input:**
- Header facts (always): project name, client name, date range, granularity,
  line items `[{label, hours}]`, subtotal/tax/total, currency.
- Per-billed-task records: `[{ title, description, subtasks:[{title,done}],
  comments:[{text}] }]`.
- `int $budgetTokens` — total context budget available for the payload.
- `callable $countTokens` — `fn(string): int` (wraps `ModelCatalog::countTokens`).

**Priority tiers (highest → lowest):**
1. **Skeleton** — header facts + line items + totals. Always included, even if it
   alone exceeds the budget (the minimum viable context).
2. **Descriptions** — each billed task's description.
3. **Subtasks** — titles + done-state (highest-value narrative signal).
4. **Comments** — first to drop on small models.

**Fill algorithm:** include Tier 1 unconditionally. For Tiers 2→4 in order, add
the tier's units while `countTokens(payload_so_far) ≤ budget`. Within a tier,
iterate tasks in line-item order; include whole units until the budget is
reached, then stop adding further units (lower tiers are consequently skipped).
Record which tiers were fully/partially/not included so the generator can note
truncation. Output = a single structured text/markdown payload string.

**Edge cases:** empty task records → skeleton only; a single oversized
description → skeleton + that description (best effort), lower tiers dropped;
budget ≤ skeleton size → skeleton only.

## `CoverNoteGenerator` — orchestration

Responsibilities (all deps injectable for tests):
1. **Gate:** if not `aiReady()` → return `null` (caller renders nothing / no-ops).
2. **Gather:** collect the billed task-id set as the **union of
   `report()['breakdown'][].task_ids`** (the invoice's own date range /
   granularity / rate context). For each task id, fetch its description, subtasks
   (title + done-state), and comments via Kanboard's
   `TaskFinderModel` / `SubtaskModel` / `CommentModel`, scoped to the invoice's
   project and the current user's access. (TimeReport's `detail[]` now also
   carries `description`/`subtasks[]`, but only for completed-in-range tasks;
   gathering by `breakdown[].task_ids` is the complete, uniform path. Comments are
   never in `detail[]` and always come from `CommentModel`.)
3. **Budget:** `$budget = $modelCatalog->getInputBudget($profileId, OUTPUT_RESERVE)
   − $modelCatalog->countTokens($systemPrompt)` (OUTPUT_RESERVE a small named
   constant, e.g. 512). `getInputBudget` already subtracts the output reserve from
   the discovered window; subtracting the system-prompt tokens leaves the payload
   budget. Floor at 0.
4. **Assemble:** `CoverNoteContext` trims to `$budget`.
5. **Prompt:**
   - **System:** role ("You write concise, professional invoice cover notes for
     a solo consultant.") + **style instructions from settings** + hard rules
     ("Summarize only the work described in the provided context. Do not
     fabricate. 2–4 sentences. Client-facing prose.").
   - **User:** the assembled payload.
   - **Schema:** `{"type":"object","properties":{"cover_note":{"type":"string"}},
     "required":["cover_note"]}`.
6. **Call:** `registry.structured($messages, $schema, $profileId)`; read
   `['cover_note']`; trim; return the string (or `null`/error on empty result).

The generator does **not** persist anything — it returns text the controller
hands to the client for the editable field.

## Controller & routes

- **`InvoiceController::generateCoverNote`** — **POST**, `checkCSRFForm()`,
  project-access-scoped (reuse existing access assertion). Inputs: project id,
  date range, granularity, rate, and the chosen `profile_id`. Recomputes
  `report()` to obtain `breakdown[].task_ids`, runs `CoverNoteGenerator`, returns
  `JSON { "note": "..." }` on success; a JSON error payload (HTTP 4xx/5xx with a
  message) when not ready or on provider failure. Never leaks provider keys.
- Route registered in `Plugin.php` alongside the existing invoice routes.

## Settings

- **`SettingsController`** gains a **"Cover-note style instructions"** config
  value (global, `ConfigModel`), admin-gated exactly like the existing settings
  writes (`userSession->isAdmin()`; unmapped Kanboard plugin actions default to
  any authenticated user). Sensible default string shipped.
- Rendered in `settings.php` as a textarea. Escape any `%` for `t()`
  (`sprintf`) per the known TimeInvoice gotcha.

## UI / flow (form.php + JS)

- The draft form renders — **only when `aiReady()`** — a **profile `<select>`**
  (from `listProfiles()`, `getDefaultProfileId()` preselected) and a **"Generate
  cover note"** button, adjacent to the existing notes textarea.
- `Assets/js/timeinvoice.js` (delegated, CSP-safe): on button click, POST the
  form's current invoice context + selected `profile_id` + CSRF to
  `generateCoverNote`; show a loading state; on success drop `note` into the
  notes textarea (replacing its content); on error show an inline message. The
  user may edit the result or click again to regenerate; manual edits persist
  until the next generation.

## Testing strategy

- **CoverNoteContext (pure):** skeleton-always-present; tiers added in order;
  comments dropped before subtasks when budget is tight; subtasks dropped before
  descriptions; empty records → skeleton only; oversized single unit handled;
  truncation flags correct. (No DB, no network.)
- **CoverNoteGenerator:** injected fake `ProviderRegistry`
  (`structured()` returns canned `{cover_note}`) + injected fake `ModelCatalog`
  (`getInputBudget()`/`countTokens()` return fixtures) + injected fake finders;
  asserts the billed task-id set is the **union of `breakdown[].task_ids`**,
  gather → budget → assemble → structured wiring, and that the gate returns `null`
  when not ready. No live provider call.
- **Controller `generateCoverNote`:** route wiring; CSRF required; access
  control; JSON `{note}` shape; not-ready path returns the error payload, not a
  fatal.
- **PluginMeta:** `requires` TimeReport bumped to `1.3.0` and `recommends`
  AiConnector `1.1.0` — both present in **both** `plugin.json` and `Plugin.php`,
  array-of-objects with bare semver; version bump to `1.1.0` aligned across
  `plugin.json` and `getPluginVersion()`.
- **TemplateAssets:** Generate button + profile select referenced; no inline JS
  handlers; single-arg `t()` calls have no unescaped `%`.

## Release & directory

1. Bump TimeInvoice to **v1.1.0** (`plugin.json` + `Plugin.php`); set
   `requires` TimeReport `1.3.0` + `recommends` AiConnector `1.1.0` in both files.
2. Zip-on-tag CI (verbatim); verify the published asset downloads 200 with a
   clean single-folder layout, `Test/` excluded.
3. Update the **kanboard-modmenu-directory** entry: `requires` TimeReport
   `1.3.0` (bumped) + add `recommends` AiConnector `1.1.0` (bare-version, object
   shape).

## Design decisions (settled in brainstorm; reconciled to shipped ecosystem)

- **Cover note only** in v1.1.0; line-item descriptions deferred.
- Context ceiling is **descriptions + subtasks + comments**, but **budgeted to the
  model's context window** via `ModelCatalog::getInputBudget()`, tiered so small
  local models get titles/descriptions/subtasks and drop comments first.
- **Via AiConnector** (`ProviderRegistry::structured()` + `ModelCatalog`), **not**
  a self-contained client. Integration contract is concrete against shipped
  AiConnector **1.1.0**.
- **Auto-discover** the context window via `ModelCatalog::getContextWindow()`
  (Ollama live discovery + curated fallback table, both non-throwing).
- **Generate button on the draft form** (not auto-on-save), filling an editable
  field, regenerate-able.
- **Profile picker on the button** — per-generation backend switch (enables the
  direct-Ollama vs. metamodels-proxy two-app E2E).
- **Settings-level style instructions** (not per-invoice tone).
- **Billed-task context sourced from `breakdown[].task_ids`** (new in
  TimeReport ≥ 1.3.0), enriched via Kanboard models — no re-mining.
- **`requires` TimeReport raised 1.1.0 → 1.3.0** for billing correctness (dedup
  fix) and `task_ids` availability.
