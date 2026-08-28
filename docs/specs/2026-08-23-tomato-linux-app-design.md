# Tomato (Linux) — First-App Design

- **Date:** 2026-08-23
- **Status:** Draft, pending review
- **Conforms to:** Spec 1 — The Agentic 15-Minute Window
  (`2026-08-23-agentic-15min-window-spec.md`)
- **Author:** Carmelo Santana (with Claude)
- **Next step after approval:** a visual **/design** pass (UI mockups) before any
  implementation plan.

## Identity & scope

Tomato (Linux) is a **standalone, fully offline agentic Pomodoro** — the first
implementation of Spec 1 v1. It exists for one purpose: to let the operator
**live with the cadence for ~2 weeks and judge whether the concept holds**
(a hard-ish 15-min ceiling that sticks because delegated agents keep working
during breaks).

It touches **no network, no Kanboard, and no agent detection**. It writes the
Spec 1 event vocabulary to a local append-only JSONL log and shows a minimal
adherence view over that log. It is the "Product 1" (timer) of the two-product
program; the Activity Tracker (Product 2, Spec 2) is entirely separate and comes
later.

## Surfaces

- **Tray ring** (always visible) — circular sprint progress, tinted green →
  amber (grace) → red (over-run). Left-click opens the stats window; the tray
  menu is Start / Stop / Settings / Quit.
- **Grace cue** (enforced mode, at boundary) — the ring turns amber and a gentle,
  **non-focus-stealing toast** appears (e.g. "Wrap up — break in 2:00") with a
  **"Break now"** action. No full card yet. On the first sprint of a streak the
  grace is longer (`early_grace_s`).
- **Break card** (enforced mode) — an always-on-top window counting the break
  down (default 5 min), with **Skip** (and **Break now** while still in grace).
  Copy is **neutral** in v1 (no "your agents keep working" framing — deferred).
  DND-aware: under Focus/DND it degrades to an OS notification; if DND state is
  unknown, the card shows **without** stealing focus or playing sound.
- **Soft mode** — at the boundary the app fires an OS notification only; no grace
  window, no card, never blocks.
- **Stats window** (the test instrument) — small and glanceable, computed from
  the JSONL log: today's sprints, breaks taken vs skipped, total over-run, and
  current streak.
- **Settings** — sprint / break / grace durations, enforcement mode
  (`enforced` | `soft`), and optional autostart-on-login.

## Architecture

Split so the Spec 1 logic is pure, portable, and unit-tested, and the OS
touchpoints are a thin shell.

- **Pure TS core (unit-tested; the heart of Spec 1 conformance):**
  - the `idle → focus → grace → break` state machine, including grace and
    early-flow logic and the "Break now" transition;
  - the event emitter producing the full Spec 1 vocabulary (`sprint_started`,
    `boundary_reached`, `grace_started`, `grace_ended`, `break_started`,
    `break_completed`, `break_skipped`, `sprint_ended`);
  - the ring-progress + color function;
  - the DND-aware nudge decision (`card` | `card-quiet` | `notification`);
  - a **stats aggregator** that folds an event log into the adherence view.
- **Thin Rust/Tauri v2 shell (manually verified on Linux):**
  - tray + dynamic icon (ring rendered to RGBA and pushed to the tray);
  - the break-card window and the grace toast;
  - OS notifications;
  - DND detection (GNOME `show-banners` via gsettings; best-effort, falls back to
    `unknown`);
  - autostart registration;
  - JSONL event append and config read/write.

## Data (local only, XDG)

- **Events:** `~/.local/share/tomato/events.jsonl` — one JSON object per line,
  exactly the Spec 1 envelope `{ event, ts, sprint_id, … }`. Append-only,
  human-inspectable, no database.
- **Config:** `~/.config/tomato/config.json` — durations, enforcement mode,
  autostart flag.

## Testability

- **Pure core** is TDD'd with Vitest: state transitions (including grace,
  early-flow, Break-now, skip, stop), correct event emission for each transition,
  and stats aggregation from a fixture event log.
- **Native shell** is verified with a documented manual checklist on Linux (tray
  ring updates, grace toast, break card, DND degrade, notification path,
  autostart, JSONL written correctly) — these cannot be meaningfully unit-tested.

## Out of scope (v1)

Network / Kanboard / the data pipeline (Spec 2); agent-activity detection;
enforcement escalation; long breaks, daily ceilings, and other v2 protocol
additions; multi-profile; macOS packaging (code stays portable, but only Linux is
verified).

## Repository & distribution

New repo at `~/Projects/Tomato/` (Tauri v2). Packaging (AppImage / `.deb`) is
deferred to the implementation plan.

## Process note

Per the operator's direction, a visual **/design** pass (multi-artboard mockups
of the tray states, grace toast, break card, stats window, and settings) happens
**after** this spec is approved and **before** the implementation plan is written.
The mockups may refine surface details here; this document is the functional
contract, the /design canvas is the visual one, and both feed the plan.
