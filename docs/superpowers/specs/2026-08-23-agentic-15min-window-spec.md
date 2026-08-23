# Spec 1 — The Agentic 15-Minute Window

- **Date:** 2026-08-23
- **Status:** Draft (v1 = minimal core), pending review
- **Type:** Portable contract (spec-driven; apps conform to this, the spec does not conform to any app)
- **Author:** Carmelo Santana (with Claude)
- **Companion spec:** Spec 2 — Data Collection Pipeline (forthcoming, separate document)

## Why a spec, not just an app

Cross-platform apps are hard; portable contracts are not. This document defines
**what the agentic focus cadence *is*** so that any number of apps — the Linux
timer first, macOS and others later — can conform to one durable definition
instead of re-deriving the behavior each time. The spec is the single source of
truth; the apps are interchangeable implementations of it.

Two products come out of this program, each governed by one spec:

| Product | Conforms to | This doc? |
|---|---|---|
| **Tomato** — the agentic Pomodoro timer | **Spec 1** (this) | ✅ |
| **Activity Tracker** (name TBD) | **Spec 2** — data pipeline | ✕ (separate) |

They are built and validated **independently**. Tomato is proven standalone
(no Kanboard, no network) before any tracking/pipeline work connects them. They
may even diverge into fully separate products; conforming to shared specs keeps
them coherent regardless.

## The hypothesis this exists to test

> A hard-ish **15-minute ceiling on continuous computer focus actually sticks**
> when AI agents absorb the cost of stepping away — because delegated work keeps
> advancing during the break, so stopping is guilt-free and productive rather
> than lost time.

Classic Pomodoro breaks feel like forfeited progress. The agentic twist is not a
second work phase — it is the thing that makes the break *sustainable*. The first
app exists to find out whether this is true in daily practice.

## Core concept

- The human works in **sprints** of a bounded duration (default 15 minutes).
- At the sprint boundary, the human is **prompted or required to break**.
- During the break, delegated AI agents continue working (this is contextual
  philosophy in v1 — the timer does **not** yet detect agent activity; see Out of
  Scope).
- The cadence is **intentional**: the next sprint begins by a conscious choice,
  not by auto-chaining, so the human stays in control of the rhythm.

## Vocabulary (normative)

- **Sprint** — one bounded stretch of human computer focus. Default 15 min.
- **Boundary** — the moment a sprint reaches its planned duration.
- **Break** — a bounded rest after a boundary. Default 3 min.
- **Enforcement mode** — how the boundary is presented and how hard the break is
  pushed: `enforced` (escapable) or `soft`.
- **Cadence** — the ongoing sequence of sprints and breaks.

## State model (normative)

```
      start                 boundary reached
idle ────────▶ focus ──────────────────────────▶ (enforced) break
  ▲              │  │                                  │
  │              │  └── boundary reached (soft) ──▶ nudge; stays focus
  │              │        │ user chooses break ──────▶ break
  │  stop        │ stop                                │ complete / skip
  └──────────────┴─────────────────────────────────────┘
```

- `idle → focus`: user starts a sprint.
- `focus → break`: **enforced** mode auto-starts the break at the boundary;
  **soft** mode emits a nudge and remains in `focus` until the user chooses to
  break (or stops).
- `break → idle`: the break completes or is skipped. The app returns to `idle`
  (awaiting a conscious start of the next sprint). It MAY offer one-tap "start
  next sprint," but MUST NOT auto-chain sprints in v1.
- `focus/break → idle`: the user stops.

## Parameters & defaults (normative)

| Parameter | Default | Bounds | Notes |
|---|---|---|---|
| `sprint_duration_s` | 900 (15 min) | 60–3600 | configurable |
| `break_duration_s` | 180 (3 min) | 30–1800 | configurable |
| `enforcement_mode` | `enforced` | `enforced` \| `soft` | configurable |

## Boundary enforcement (normative)

Two modes, differing in **how hard** the break is pushed and **how** it is
presented:

- **`enforced` (escapable)** — at the boundary a break **starts automatically**
  and counts down, presented as an **assertive card/overlay**. The user MAY skip,
  but the default action is that the break happens. Repeated skips SHOULD escalate
  (e.g. a firmer prompt); escalation specifics are app-defined in v1.
- **`soft`** — at the boundary the app emits an **OS notification** nudge and
  stays in focus. The user breaks manually. Never blocks.

**Do-Not-Disturb / Focus awareness (SHOULD):**
- DND/Focus **active** → suppress the card; degrade to an OS notification (marked
  time-sensitive where the platform allows, so the user's own override settings
  decide visibility).
- DND state **undetectable** → show the card **without** stealing focus or playing
  sound (visible but not jarring).
- DND detection is best-effort and platform-specific (e.g. GNOME `show-banners`
  on Linux; macOS Focus is heuristic). Conformance requires the fallback
  behavior, not perfect detection.

## Event vocabulary (normative) — the seam to Spec 2

A conforming app **MUST** emit these events locally (persisted or observable), so
adherence to the cadence can be measured. This is what makes the concept testable
**and** is the exact join point Spec 2's pipeline will later consume — without
coupling Tomato to Kanboard now. In v1 the events stay local.

Common envelope: `{ event, ts, sprint_id, ... }` (`ts` = unix seconds; `sprint_id`
= a per-sprint UUID; a break carries its parent `sprint_id`).

| Event | Additional fields | Fires when |
|---|---|---|
| `sprint_started` | `planned_s`, `enforcement_mode` | a sprint (focus) begins |
| `boundary_reached` | — | the sprint hits `planned_s` |
| `break_started` | `break_id`, `planned_s` | a break begins (auto in enforced, manual in soft) |
| `break_completed` | `break_id`, `actual_s` | the break ran to its planned end |
| `break_skipped` | `break_id?`, `after_s` | user skipped/ended the break early, or dismissed the nudge and kept working |
| `sprint_ended` | `actual_focus_s`, `over_run_s` | user stops focusing (`over_run_s` = focus time past the boundary, if any) |

Derivable adherence metrics (not stored, computed from events): sprints
completed, breaks taken vs skipped, mean focus length, total over-run — the
signals that tell us whether the concept is working.

## Conformance (normative)

An app conforms to Spec 1 v1 if it:

1. **MUST** implement the `idle / focus / break` state model and the boundary
   behavior for the configured enforcement mode.
2. **MUST** support **both** enforcement modes (`enforced` card/overlay, `soft`
   notification) and the parameters/defaults above.
3. **MUST** emit the full event vocabulary locally.
4. **MUST NOT** require any network or Kanboard connection (v1 is standalone).
5. **MUST NOT** auto-chain sprints (intentional cadence).
6. **SHOULD** be DND/Focus-aware per the fallback rules above.

## Out of scope for v1 (deferred)

- Long breaks after N sprints; daily focus ceilings; max-consecutive-sprint
  limits; workday boundaries (candidate v2 "healthy-use protocol" additions —
  see Research backlog).
- **Actual agent-activity detection** (knowing agents are truly working during a
  break) — v1 treats the agentic benefit as philosophy, not a measured signal.
- The **data collection pipeline** and Kanboard integration — that is **Spec 2**
  and a separate product (the Activity Tracker).
- Multi-profile / multi-machine, reporting, macOS packaging.

## Research backlog (to be enriched)

A parallel deep-research pass is gathering evidence on the next ideals to bake in
(optimal focus/break ratios for knowledge work, microbreak science, flow vs
interruption cost, enforcement-UX that builds habit vs backfires, aggregate
healthy-use protocols, and human–AI supervision cadence). Findings will be
appended here and triaged into a possible Spec 1 v2 before the pipeline work.

## Superseded work

This spec replaces the coupled approach in
`2026-08-23-tomato-walking-skeleton-design.md` and its plan, which bundled the
timer and Kanboard ingest into one slice. That coupling is exactly what this
program now separates.
