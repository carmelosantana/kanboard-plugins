# TimeInvoice — AI Cover Note — Design Spec

**Date:** 2026-08-23
**Status:** Approved (brainstorm complete)
**Plugin:** TimeInvoice (own repo `github.com/carmelosantana/kanboard-time-invoice`, currently v1.0.0)
**Target release:** TimeInvoice **v1.1.0** (additive minor)
**Depends on (new, soft):** AiConnector introspection release (see [Dependency & sequencing](#dependency--sequencing))

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
per-invoice tone selection; multi-note/templated sections. (Line-item
descriptions were explicitly deferred during brainstorming.)

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

## Dependency & sequencing

### Transport — AiConnector `ProviderRegistry`

All model access goes through AiConnector's public PHP API (never a bespoke HTTP
client in TimeInvoice). The consumed surface (from `Model/ProviderRegistry.php`,
instantiated as `new ProviderRegistry($this->container)`):

- `isReady(): bool` — no network; true when ≥1 provider profile is usable. The
  degrade gate.
- `listProfiles(): array<{id,label,provider,model}>` — populates the profile
  picker (no secrets, no base_url).
- `getDefaultProfileId(): string` — preselected picker value.
- `structured(array $messages, string $schema, ?string $profileId): array` —
  provider-agnostic structured call; returns a decoded PHP array. TimeInvoice
  passes a `{"cover_note":"string"}` JSON schema and reads `['cover_note']`.

Because the call is provider-agnostic, **direct-Ollama vs. the metamodels proxy
vs. a frontier API is only a matter of which AiConnector profile the picker
selects** — TimeInvoice needs zero code change to switch backends.

### New AiConnector capability consumed (context budgeting)

Auto-discovering the model's context window is an **AiConnector** capability
(it owns provider/model config and vendors php-agents). It is being built
separately (own work chip, `kanboard-plugin-suite` methodology). TimeInvoice
codes against this agreed interface:

- `getContextWindow(?string $profileId = null): int` — model's max context length
  in tokens (live discovery where cheap, e.g. Ollama `/api/show`; curated
  fallback table; conservative default otherwise).
- `countTokens(string $text, ?string $profileId = null): int` — provider-aware
  token estimate.

**Method names/return types above are the integration contract.** If the
AiConnector chip finalizes different signatures, this spec's consuming code is
updated to match before implementation of the budgeting path.

### Dependency declaration & degradation

- Add **`recommends`** (soft) AiConnector — array-of-objects, **bare** semver — to
  **both** `plugin.json` and `Plugin.php` metadata:
  `[{ "plugin": "AiConnector", "min_version": "<introspection release>", "reason": "..." }]`.
  `min_version` is pinned to the AiConnector version that ships
  `getContextWindow()`/`countTokens()` (finalized when that release lands).
- TimeReport remains a hard **`requires`** `1.1.0` (unchanged).
- **Gate (`AiGate`-style, mirrors TimeReport):**
  `aiReady()` ≡ `class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry)`
  **&&** `(new ProviderRegistry($this->container))->isReady()`.
  When false: the Generate button and profile picker are **not rendered**; the
  notes field is a normal manual textarea; the `generateCoverNote` route no-ops
  with a clear "needs AiConnector" message. No fatals, no partial UI.

### Build/ship ordering

The auto-discover budget requires the AiConnector introspection release. The
pure context/assembly and UI are built and unit-tested against the interface
immediately (with an injected fake). Live through-Ollama and through-proxy E2E
run once AiConnector's release is installed in the dev stack. As a soft
`recommends`, TimeInvoice v1.1.0 can ship independently; the AI path activates
only when AiConnector is present and configured.

## Component map (additions to TimeInvoice)

```
TimeInvoice/
├── Model/
│   ├── CoverNoteContext.php     # NEW — PURE: priority-tiered, budget-trimmed context payload
│   └── CoverNoteGenerator.php   # NEW — orchestrates gather → budget → assemble → structured()
├── Controller/
│   └── InvoiceController.php     # +generateCoverNote (POST, CSRF, access-scoped, JSON {note})
├── Controller/
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
- `callable $countTokens` — `fn(string): int` (wraps AiConnector `countTokens`).

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
2. **Gather:** for the invoice's billed task ids (from the same TimeReport
   `report()` breakdown TimeInvoice already uses), fetch description, subtasks,
   and comments via Kanboard's `TaskFinderModel`/`SubtaskModel`/`CommentModel`,
   scoped to the invoice's project and the current user's access.
3. **Budget:** `window = registry.getContextWindow($profileId)`;
   `budget = window − OUTPUT_RESERVE − countTokens(systemPrompt)`
   (OUTPUT_RESERVE a small named constant, e.g. 512 tokens).
4. **Assemble:** `CoverNoteContext` trims to `budget`.
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
  date range, granularity, rate/line-item context needed to identify billed
  tasks, and the chosen `profile_id`. Returns `JSON { "note": "..." }` on success;
  a JSON error payload (HTTP 4xx/5xx with a message) when not ready or on provider
  failure. Never leaks provider keys.
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
  (`structured()` returns canned `{cover_note}`, `getContextWindow()`/
  `countTokens()` return fixtures) + injected fake finders; asserts gather →
  budget → assemble → structured wiring and that the gate returns `null` when not
  ready. No live provider call.
- **Controller `generateCoverNote`:** route wiring; CSRF required; access
  control; JSON `{note}` shape; not-ready path returns the error payload, not a
  fatal.
- **PluginMeta:** `recommends` AiConnector present in **both** `plugin.json` and
  `Plugin.php`, array-of-objects with bare semver; version bump to `1.1.0`
  aligned across `plugin.json` and `getPluginVersion()`.
- **TemplateAssets:** Generate button + profile select referenced; no inline JS
  handlers; single-arg `t()` calls have no unescaped `%`.

## Release & directory

1. Bump TimeInvoice to **v1.1.0** (`plugin.json` + `Plugin.php`).
2. Zip-on-tag CI (verbatim); verify the published asset downloads 200 with a
   clean single-folder layout, `Test/` excluded.
3. Update the **kanboard-modmenu-directory** entry to add the
   `recommends AiConnector` array (bare-version, object shape) alongside the
   existing `requires TimeReport`.

## Design decisions (settled in brainstorm)

- **Cover note only** in v1.1.0; line-item descriptions deferred.
- Context ceiling is **descriptions + subtasks + comments**, but **budgeted to the
  model's context window** (auto-discovered), tiered so small local models get
  titles/descriptions/subtasks and drop comments first.
- **Via AiConnector** (`structured()`), **not** a self-contained client.
- **Auto-discover** the context window via AiConnector's new introspection API.
- **Generate button on the draft form** (not auto-on-save), filling an editable
  field, regenerate-able.
- **Profile picker on the button** — per-generation backend switch (enables the
  direct-Ollama vs. metamodels-proxy two-app E2E).
- **Settings-level style instructions** (not per-invoice tone).
