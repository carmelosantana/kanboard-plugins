# Spec 1 — The Agentic 15-Minute Window

- **Date:** 2026-08-23
- **Status:** Draft (v1 = minimal core + grace & early-flow from research), pending review
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
- **Grace window** — a short allowance *after* the boundary to reach a natural
  stopping point before the break begins (default 2 min; longer on the first
  sprint of a streak — see "protect early flow"). Applies in `enforced` mode.
- **Break** — a bounded rest after the grace window. Default 5 min.
- **Enforcement mode** — how the boundary is presented and how hard the break is
  pushed: `enforced` (escapable) or `soft`.
- **Streak** — consecutive sprint→break cycles since leaving cold `idle`; the
  first sprint of a streak gets a longer grace to protect forming flow.
- **Cadence** — the ongoing sequence of sprints and breaks.

## State model (normative)

```
      start           boundary        grace elapses / "Break now"
idle ───────▶ focus ───────────▶ grace ───────────────────────────▶ break (enforced)
  ▲             │ │                │                                    │
  │             │ └─ boundary (soft) ─▶ nudge; stays focus             │
  │             │      │ user chooses break ─────────────────────────▶ break
  │  stop       │ stop │                                               │ complete / skip
  └─────────────┴──────┴────────────────────────────────────────────────┘
```

- `idle → focus`: user starts a sprint.
- `focus → grace` (**enforced**): at the boundary the sprint enters a **grace
  window** (still focus, flagged "overtime") for `effective_grace_s` — the longer
  `early_grace_s` on the first sprint of a streak, otherwise `grace_s`. The app
  SHOULD show a gentle "wrap up" cue, not the break card yet.
- `grace → break` (**enforced**): the break auto-starts when grace elapses, or
  immediately if the user hits "Break now" (their manual "I'm at a stopping
  point," since v1 cannot detect real task boundaries).
- `focus → nudge` (**soft**): at the boundary the app emits a notification and
  stays in `focus`; no grace, no forced break. The user breaks manually.
- `break → idle`: the break completes or is skipped. The app returns to `idle`
  (awaiting a conscious start of the next sprint). It MAY offer one-tap "start
  next sprint," but MUST NOT auto-chain sprints in v1.
- `focus/grace/break → idle`: the user stops.

## Parameters & defaults (normative)

| Parameter | Default | Bounds | Notes |
|---|---|---|---|
| `sprint_duration_s` | 900 (15 min) | 60–3600 | configurable |
| `break_duration_s` | 300 (5 min) | 30–1800 | configurable |
| `grace_s` | 120 (2 min) | 0–600 | enforced-mode wrap-up allowance after the boundary |
| `early_grace_s` | 300 (5 min) | 0–900 | grace used on the first sprint of a streak (protect forming flow) |
| `enforcement_mode` | `enforced` | `enforced` \| `soft` | configurable |

## Boundary enforcement (normative)

Two modes, differing in **how hard** the break is pushed and **how** it is
presented:

- **`enforced` (escapable)** — at the boundary a **grace window** opens
  (`effective_grace_s`, with a gentle "wrap up" cue). When grace elapses — or the
  user hits **"Break now"** — a break **starts automatically** and counts down,
  presented as an **assertive card/overlay**. The user MAY skip the break, but the
  default action is that it happens. Escalation on repeated skips is **v2**; v1
  keeps a single flat enforced behavior.
- **`soft`** — at the boundary the app emits an **OS notification** nudge and
  stays in focus (no grace window, no forced break). The user breaks manually.
  Never blocks.

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
| `grace_started` | `grace_s`, `streak_index` | enforced mode enters the grace window at the boundary |
| `grace_ended` | `actual_grace_s`, `ended_by` (`user`\|`timeout`) | grace ends (user hit "Break now", or it elapsed) |
| `break_started` | `break_id`, `planned_s` | a break begins (after grace in enforced, manual in soft) |
| `break_completed` | `break_id`, `actual_s` | the break ran to its planned end |
| `break_skipped` | `break_id?`, `after_s` | user skipped/ended the break early, or dismissed the nudge and kept working |
| `sprint_ended` | `actual_focus_s`, `over_run_s` | user stops focusing (`over_run_s` = focus time past the boundary, if any) |

Derivable adherence metrics (not stored, computed from events): sprints
completed, breaks taken vs skipped, mean focus length, total over-run — the
signals that tell us whether the concept is working.

## Conformance (normative)

An app conforms to Spec 1 v1 if it:

1. **MUST** implement the `idle / focus / grace / break` state model and the
   boundary behavior for the configured enforcement mode.
2. **MUST**, in `enforced` mode, apply the **grace window** after the boundary
   (using `early_grace_s` on the first sprint of a streak, else `grace_s`) before
   starting the break, and honor a user **"Break now"** action that ends grace early.
3. **MUST** support **both** enforcement modes (`enforced` card/overlay, `soft`
   notification) and the parameters/defaults above.
4. **MUST** emit the full event vocabulary locally.
5. **MUST NOT** require any network or Kanboard connection (v1 is standalone).
6. **MUST NOT** auto-chain sprints (intentional cadence).
7. **SHOULD** be DND/Focus-aware per the fallback rules above.

## Out of scope for v1 (deferred)

- Long breaks after N sprints; daily focus ceilings; max-consecutive-sprint
  limits; workday boundaries (candidate v2 "healthy-use protocol" additions —
  see Research backlog).
- **Actual agent-activity detection** (knowing agents are truly working during a
  break) — v1 treats the agentic benefit as philosophy, not a measured signal.
- The **data collection pipeline** and Kanboard integration — that is **Spec 2**
  and a separate product (the Activity Tracker).
- Multi-profile / multi-machine, reporting, macOS packaging.

## Research backlog (evidence-based, for Spec 1 v2 triage)

From a deep-research pass (2026-08-23). These inform a **possible v2** — v1 stays
minimal core. Items flagged **⚑v1?** tension with a v1 decision and are worth
deciding now (see the review note at the end).

**Cadence & timing**
- **✅ ADOPTED v1 — Grace/extension window instead of a hard clock.**
  Interrupting at coarse task boundaries costs ~nothing; mid-subtask spikes
  resumption time. A strict 15:00 cut is the "fine breakpoint" the research says
  to avoid → `grace_s` (default 2 min) added in v1.
  ([SAGE](https://journals.sagepub.com/doi/10.1177/00187208211009010),
  [Frontiers](https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2024.1465323/full))
- **✅ ADOPTED v1 — Protect the first ~15–25 min of forming flow.** A rigid
  boundary can end a sprint just as flow forms → `early_grace_s` (default 5 min)
  on the first sprint of a streak. (v2 could make this adaptive/detected.)
  ([Gloria Mark research summary](https://tctecinnovation.com/blogs/daily-blog/every-distraction-costs-you-23-minutes))
- **Adaptive/task-typed sprint length, not a fixed 15.** No ratio is empirically
  superior (52/17 was vendor log-mining, drifted to 75/33 → 112/26). Keep 15 as
  default; expose per-project length + an opt-in ~50–90 min deep-work mode.
  ([DeskTime](https://desktime.com/blog/52-17-updated/))
- **Flowtime variant: scale break length to the sprint just completed** — a
  middle ground between rigid Pomodoro and pure self-regulation.
  ([Behavioral Sciences RCT / PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC12292963/))

**Break design**
- **Match break length to cognitive demand of the finished task;** only breaks
  ≳10 min moved *performance* (vs wellbeing) in the largest meta-analysis, and
  mainly for clerical/creative work.
  ([PLOS ONE](https://journals.plos.org/plosone/article?id=10.1371%2Fjournal.pone.0272460))
- **Prescribe break *content* (walk/stretch/nature), not just duration;** passive
  screen breaks recover worse. Hedges eye-strain goals too. (same meta-analysis)
- **20-20-20 as a default nudge, not "proven."** Ubiquitous advice, weak
  peer-reviewed support — include as cheap best-practice, don't oversell.
  ([PubMed](https://pubmed.ncbi.nlm.nih.gov/36473088/))
- **Add an hourly/daily aggregate layer (OSHA-style ~5 min/hour)** separate from
  the micro-cadence, targeting musculoskeletal risk. (candidate v2 protocol)
  ([OSHA](https://www.osha.gov/etools/computer-workstations/additional-information))

**Enforcement & habit**
- **↦ v2 — Enforcement should escalate (soft → shrinking snooze → lockout), keyed
  to session/streak state — never binary.** Forced lockout cuts screen time more
  but raises reactance; users prefer monitoring even knowing it's less effective.
  *(Deferred: v1 keeps a flat enforced/soft toggle.)*
  ([Stretchly](https://github.com/hovancik/stretchly),
  [Zimmermann & Sobolev](https://cdn.prod.website-files.com/5f340a9c3a168c42579d818b/5ffd48267e1b89297ed74d15_Digital%20Nudges%20for%20Screen%20Time%20Reduction%20(Zimmermann%20and%20Sobolev,%202020).pdf))
- **Fade the scaffolding** — reminder-dependency can *prevent* habit automaticity;
  after N compliant days, reduce intrusiveness / graduate to lighter-touch.
  ([Frontiers](https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2020.00167/full))

**The agentic angle (most novel)**
- **Let a running agent task *be* the break trigger.** Dev workflows already have
  idle-wait (~43 min/day on builds); firing the break exactly when a delegated
  agent is working makes it truly free.
  ([DX](https://newsletter.getdx.com/p/build-times-and-developer-productivity))
- **Agent "re-entry briefing" at break end** (diff/status summary) to pay down the
  11–23 min resumption tax.
  ([CMU ACT-R](http://act-r.psy.cmu.edu/wordpress/wp-content/uploads/2012/12/830interruptions.pdf))
- **Keep a "ready queue" of pre-scoped delegable tasks** so starting a break is
  picking from a menu, not composing a prompt under time pressure (specification
  cost undermines guilt-free breaks).
  ([arXiv](https://arxiv.org/html/2606.05391v1))
- **Do NOT build a live "watch the agent" view for the break** — real-time
  monitoring is rare in practice and excess oversight *reduces* safety; save
  status for the re-entry briefing.
  ([arXiv](https://arxiv.org/html/2606.05391v1))

**Contrarian findings that qualify our hypothesis**
- Systematic (Pomodoro) breaks are **not** clearly better than self-regulated
  ones; an RCT found no productivity/flow difference and Pomodoro's rigid breaks
  raised fatigue *faster*. → validates keeping `soft`/escapable modes.
- Microbreaks reliably help **wellbeing** but the **overall performance** effect
  was non-significant — a 15/short design may improve morale without moving
  output. Name this as a known gap.
- Forced enforcement is more effective at cutting screen time **but** higher
  reactance — treat "enforced-escapable" as an explicit trade-off, not a win-win.

**v1 decisions (2026-08-23, from this research):** ADOPTED into v1 — a boundary
**grace/extension window** (`grace_s`, default 2 min) and **early-flow
protection** (`early_grace_s`, default 5 min, on the first sprint of a streak);
the **break default was raised to 5 min**. DEFERRED to v2 — **escalating**
enforcement (v1 keeps a flat enforced/soft toggle). Everything else above is v2
backlog, to be triaged before the pipeline (Spec 2) work.

## Superseded work

This spec replaces the coupled approach in
`2026-08-23-tomato-walking-skeleton-design.md` and its plan, which bundled the
timer and Kanboard ingest into one slice. That coupling is exactly what this
program now separates.
