# Tomato — Walking Skeleton Design

- **Date:** 2026-08-23
- **Status:** Approved (brainstorm), pending implementation plan
- **Author:** Carmelo Santana (with Claude)
- **Scope:** First vertical slice (A+B) of the larger Tomato system

## Context

Tomato is a new two-part system for making computer use intentional: a
desktop menu-bar applet that runs a Pomodoro-like focus cadence and a
Kanboard plugin that ingests and (eventually) reports on the resulting data.
The long-term vision spans several independent subsystems:

| # | Piece | What it is |
|---|---|---|
| **A** | Data contract + Kanboard ingest | Plugin schema + write API for sessions, activity, tokens, profiles, project links |
| **B** | Applet core | Menu-bar circle timer, 15-min window, break nudge, session start/stop |
| **C** | Activity collectors | Active-window/app + idle detection, per-OS |
| **D** | AI-usage collectors | Claude/Codex token & session parsing; ai-garden hook; repo/GitHub → project links |
| **E** | Reporting | Plugin dashboards: computer-time vs deliverables, tokens, time-of-day |
| **F** | Kensho automation | Feed Tomato aggregates into Kensho on a cadence |

Each piece gets its own spec → plan → build cycle. **This document covers
only the walking skeleton — a thin vertical slice through A+B** that proves
the whole applet↔Kanboard pipe end-to-end before any collectors, reporting,
or multi-profile machinery is added.

### Ecosystem fit

- **AiConnector** — the suite's AI backend (`ProviderRegistry`). Any future
  AI summarization rides on it and degrades without it. Not used in the
  skeleton.
- **Kensho** — already mines Kanboard activity into life-areas/goals/
  alignment. Tomato becomes a richer data source it reads (piece F).
- **TimeReport / TimeInvoice / TimeBlock** — own per-task/day hour rollups,
  PDF invoicing, and planned time-windows. Tomato's reporting (piece E) must
  *complement*, not duplicate, these.
- **ai-garden** — local Claude/Codex→Ollama gateway, scoped to *one machine
  and one account*. It is therefore one optional collector (piece D), never
  the universal source of token data.
- **kanboard-mcp** — a Go MCP server wrapping Kanboard's JSON-RPC API for AI
  assistants. Not the applet's transport (it is a client over the same API);
  relevant later as a way to surface `tomato.*` procedures to agents (piece D).

## Goal & scope of the skeleton

The thinnest end-to-end proof of the core cadence:

> A menu-bar timer that runs a 15-minute focus window, nudges you to break
> (DND-aware), and logs each completed **work session** to Kanboard,
> attributed to a real Kanboard user.

**Primary user goal:** actually stop at ~15 minutes and capture total
time-at-computer so it can later be weighed against deliverables.

Everything in pieces C–F is explicitly **out of scope** (see below).

## Decisions (from brainstorm)

| Decision | Choice | Rationale |
|---|---|---|
| First slice | Walking skeleton (A+B) | Proves the pipe and the primary goal before layering collectors/reporting |
| Applet stack | **Tauri v2** (TS UI + thin Rust shell) | Lean (~5–10 MB), first-class cross-platform tray, low idle memory; keeps logic in TypeScript |
| Transport | **Custom JSON-RPC procedures** on the plugin | Reuses Kanboard's token auth, CSRF-free, suite-idiomatic |
| Auth identity | Authenticate **as an existing Kanboard user** via that user's personal API token (HTTP Basic) | Each applet install maps to one real user; sessions attributed to them. No synthetic service account |
| Session model | **Continuous session + soft nudges** | One record per Start→Stop stretch captures both cadence and total time |
| Cadence mode | Single-mode for now (work/dictate `mode` deferred) | Keep the skeleton simple; add later without migration pain |
| Break nudge | **Assertive break card, DND-aware** (degrades to native notification under DND/Focus) | Actually makes you stop, without being jarring on a call |
| Tray icon | Circular progress drawn in TS → RGBA → Tauri `set_icon` | No native drawing code; identical on both OSes |
| Offline | Local SQLite outbox + retry with backoff | Nothing lost when Kanboard is unreachable |
| Token storage | OS keychain via Tauri | Never plaintext config |

## Architecture

```
Tomato applet (Tauri v2, Linux now / macOS-ready)
   tray ring · timer state machine · DND-aware nudge · local SQLite outbox
        │  JSON-RPC over HTTPS, HTTP Basic = username + personal API token
        ▼
Kanboard "Tomato" plugin
   tomato.recordSession procedure · tomato_session table · attributes to auth'd user
```

Two components, one contract. Each is understandable and testable in
isolation; the JSON-RPC payload (§ Data contract) is the only coupling.

## Component: Kanboard plugin (minimal A)

- New suite plugin `Tomato/` following suite conventions (buildless PHP;
  eventually its own repo `kanboard-tomato`). Files: `plugin.json`,
  `Plugin.php`, `Api/`, `Schema.php`, `Test/`.
- **One JSON-RPC procedure** `tomato.recordSession`, registered on
  Kanboard's API via the plugin's API class. Authentication is Kanboard's
  per-user personal API token; the session row is attributed to the
  authenticated user's `user_id` (taken from auth context, **never** from the
  payload).
- **One table** `tomato_session`, created via a plugin schema migration:

  | Column | Type | Notes |
  |---|---|---|
  | `id` | integer PK | |
  | `user_id` | integer | FK to Kanboard `users`; from auth context |
  | `machine_id` | varchar | stable UUID minted by the applet on first run |
  | `machine_label` | varchar | human label, e.g. `carmelo-thinkpad` |
  | `client_session_id` | varchar, **unique** | UUID minted by applet; idempotency key |
  | `started_at` | integer | unix seconds |
  | `ended_at` | integer | unix seconds |
  | `active_seconds` | integer | active time in the session |
  | `windows_elapsed` | integer | count of 15-min windows that elapsed |
  | `breaks_taken` | integer | count of breaks taken |
  | `app_version` | varchar | applet version |
  | `created_at` | integer | unix seconds, server-set |

- **Idempotent:** a repeat `client_session_id` does not create a second row
  (retry-safe). Input is validated; malformed payloads are rejected with a
  clear error.

## Component: Applet (B)

- **Timer state machine** (pure TS, fully unit-testable):
  `idle → running → (window elapsed → nudging) → running | break → stopped`.
  The window is *soft*: at zero it nudges but the session keeps running until
  the user takes the break or presses Stop.
- **Tray ring:** circular progress rendered in TS to RGBA bytes and pushed to
  the tray via Tauri `set_icon`, refreshed roughly every 30–60 s. Tray menu:
  Start/Stop, live session time, Settings, Quit.
- **DND-aware nudge:**
  - Not in DND/Focus → assertive break card (always-on-top window that counts
    the break down; Acknowledge / Skip / Extend).
  - DND/Focus active → suppress the card; post a native OS notification marked
    time-sensitive where the platform supports it, so the user's own override
    settings decide whether it surfaces.
  - DND state undetectable → show the card **without** stealing focus or
    playing sound (visible but not jarring).
  - DND detection sits behind a `DndDetector` interface (faked in tests).
    **Honest limitation:** robust DND detection is the trickiest cross-platform
    piece — macOS Focus status is best-effort (no clean public API; read
    heuristically), Linux is DE-dependent (GNOME queryable via gsettings;
    others best-effort). The undetectable-state fallback above bounds the risk.
- **Offline outbox:** on Stop, the session is written to a local SQLite outbox
  first, then synced; unreachable Kanboard triggers retry with backoff. No
  session is lost offline.
- **Config:** Kanboard URL, username, machine label, window minutes
  (default **15**), break minutes (default **3**), nudge intensity. The
  **personal API token is stored in the OS keychain**, never in plaintext
  config.

## Data contract

`tomato.recordSession(params)` → `{ ok: true, session_id }`

`params` = `{ client_session_id, machine_id, machine_label, started_at,
ended_at, active_seconds, windows_elapsed, breaks_taken, app_version }`.

`user_id` is derived from authentication, **not** accepted from the payload.
A repeated `client_session_id` returns the existing `session_id` with
`ok: true` (idempotent, not an error).

## Out of scope (deferred to later slices)

- Active-window/app + idle tracking (C)
- Claude/Codex token & AI-usage collectors + ai-garden hook (D)
- Multi-profile / multi-account (D) — **single profile per install** for now;
  `machine_id` is already in the schema so multi-machine rollups work later
  without a migration
- Folder→project & GitHub-repo linking (D)
- Reporting dashboards (E)
- Kensho automation (F)
- macOS packaging/signing — code stays cross-platform, but the skeleton is
  **verified on Linux only**; macOS is a follow-up
- `mode` (work vs dictate-to-agents) on the session — deferred

## Testing

- **Plugin (PHPUnit via the suite harness, `./testing/run-plugin-tests.sh
  Tomato`):** schema migration creates the table; `recordSession` inserts a
  row; a repeat `client_session_id` is idempotent; the row is attributed to
  the authenticated user; malformed input is rejected.
- **Applet (TS unit tests):** the timer state machine (transitions, soft
  window, window/break counting) and the outbox/retry logic (queue, backoff,
  no-loss) are pure and fully tested. `DndDetector` and the native tray are
  behind interfaces (faked in tests) and manually verified on Linux.

## Repository structure

- **Plugin:** lives at `kanboard-plugins/Tomato/` for dev-harness testing;
  eventually its own repo `kanboard-tomato` per suite convention.
- **Applet:** its own repo (e.g. `tomato-applet`, Tauri v2). Not a Kanboard
  plugin, so it does not belong in the plugin monorepo tree.
- **This spec** lives in the monorepo alongside the other suite design docs.

## Open follow-ups (not blocking the skeleton)

- Exact mechanism for reading the authenticated user id inside a Kanboard
  plugin API procedure (resolved during implementation).
- macOS Focus-detection approach and packaging/signing (piece for the macOS
  follow-up).
- Whether `tomato.*` procedures should later be surfaced via kanboard-mcp for
  agent self-logging (piece D).
