# Tomato — Brand & Product Design Brief

> **For:** (external designer) · **From:** Carmelo Santana · **Date:** 2026-08-23
> **Engagement:** Brand identity + full brand kit + screen layouts for a desktop app.
> **Deliverable we most need back:** a **machine-readable brand kit** — design
> tokens (JSON) + screen layouts — that drops straight into a web-tech desktop app.

---

## 0. TL;DR for the designer

Tomato is a small, calm desktop **menu-bar timer** for people who work alongside
AI coding agents. It runs 15-minute focus sprints and nudges you to take a real
break — the twist being that your AI agents keep working while you step away, so
the break is guilt-free. We need a **brand identity** (name treatment, icon
system, mood, palette, type), a **storyboard** of the core experience, **layouts
for ~5 screens**, and a **machine-readable design-token package** we can wire into
the app. The single most important asset is the **menu-bar icon**, which is a
live, data-driven **progress ring**.

Please feel free to originate the mood and visual language — the sections below
give you the product truth, constraints, and the exact deliverables list, not a
pre-baked look.

---

## 1. Product context & the idea

- **What it is:** a standalone, offline desktop app (Linux first, macOS later)
  that lives in the menu bar / system tray. It is a Pomodoro-style focus timer.
- **Who it's for:** developers and knowledge workers who now delegate real work to
  AI agents (Claude Code, Codex, etc.). Primary user is a lead engineer / AI
  consultant who wants healthier, more intentional computer use.
- **The core insight ("agentic Pomodoro"):** classic Pomodoro breaks feel like
  lost time, so people skip them. Tomato's bet is that when your AI agents keep
  running during the break, stepping away costs nothing — the delegated work
  advances while you rest. So the break becomes sustainable and even productive.
- **The emotional job:** *focus without guilt; stop without loss.* Calm, in
  control, healthy — never punitive, never a nag, never a manipulative "streak
  anxiety" machine.
- **The single feature to nail visually:** an ambient **progress ring** in the
  menu bar that shows how much of the current 15-minute sprint is left, at a
  glance, all day.

**Brand adjectives to aim for (guidance, not a cage):** calm, focused, warm,
intentional, quietly confident, modern, a little playful (it's called Tomato) —
but restrained, because it sits in your menu bar all day and must never feel
loud or stressful.

**Brand-relationship question for Carmelo to answer you:** should Tomato read as
its own standalone product brand, or visually nod to being part of a small suite
of tools by the same maker? (Default assumption: **standalone**, with room to
grow into a family.)

---

## 2. Audience & tone

- **Tone of voice / copy:** spare, kind, non-judgmental. "Time to break" not
  "STOP! You've been working too long!" Breaks and skips are both fine; the app
  informs, it doesn't scold.
- **Anti-goals (please avoid):** dark patterns, guilt-tripping, fake urgency,
  aggressive gamification, anything that punishes a skipped break. It should
  respect the user's autonomy and their operating system's Do-Not-Disturb.

---

## 3. Brand identity — what we'd like created

1. **Name & wordmark.** The name is **Tomato** (a nod to *pomodoro*). We'd love a
   wordmark and a decision on how literal to go — a stylized tomato, an abstract
   mark, or a hybrid. The mark must survive being tiny and monochrome (see icon
   constraints).
2. **Icon system:**
   - **App icon** (launcher / dock / about): full color, expressive.
   - **Menu-bar / tray icon — the hero asset.** This is drawn *live* by the app as
     a **circular progress ring** that fills as the sprint elapses. We need:
     - the ring's visual language (track vs progress stroke, stroke weight, line
       cap, start position, how "empty" vs "full" reads);
     - its **state colors**: *focus* (normal), *grace* (the wrap-up window near the
       end), *over-run* (past the limit), and a *break/idle* look;
     - a **monochrome / template variant** (macOS menu-bar icons are typically
       single-color templates) plus a color variant (Linux trays allow color);
     - legibility at 16, 22, 24, 32 px (and @2x). See §7 for the geometry we need
       specified.
3. **Iconography set** (SVG, consistent grid & stroke): start, pause/stop, break,
   skip, "break now", settings, streak/flame, sprint, and a few stat glyphs.
4. **Color palette** — light **and** dark themes (the app is theme-aware), with
   **semantic roles** we can map to app states (see token list in §8): focus,
   grace, break, over-run, plus neutrals (background/surface/border/text scales)
   and standard success/warning/danger/info. State colors must be
   **color-blind-safe** and not rely on red/green alone.
5. **Typography** — a display/UI face and a monospace (for timers/numbers).
   Google Fonts or open-license preferred so we can embed them.
6. **Motion & feel** — brief notes on transitions (ring fill, card entrance, toast
   slide). Ambient and subtle; nothing bouncy or attention-grabbing.
7. **Mood board** — establish the world before the pixels.

---

## 4. Storyboard (the experience to visualize)

A single sprint cycle, which we'd love told as a storyboard / flow:

1. **Idle** — menu-bar icon at rest; user clicks *Start*.
2. **Focus** — ring fills over 15 minutes; the icon is the only presence.
3. **Boundary + Grace** — near the end the ring shifts to the *grace* color and a
   small, **non-intrusive toast** appears ("Wrap up — break in 2:00") with a
   *Break now* option. (The first sprint of a run gets a longer grace, to protect
   a forming flow state.)
4. **Break** — an **always-on-top break card** counts the break down (default
   5 min), with *Skip*. Copy is **neutral** in v1.
5. **Return** — back to idle, ready for the next sprint (never auto-started —
   starting is a conscious choice).
6. **Reflection** — the user opens the **stats window** to see how the day went.

Also worth a frame or two: **first run / onboarding** (explain the cadence, set
durations) and **empty state** (before any data exists).

---

## 5. Screens we need designed

Sizes are suggestions — please refine. All screens need **light + dark**.

| # | Screen | Purpose | Key content |
|---|--------|---------|-------------|
| 1 | **Menu-bar icon states** | the always-on presence | idle · focus (early/mid/late) · grace (amber) · break · over-run (red). This is the dynamic ring — deliver the states + the geometry spec in §7. |
| 2 | **Grace toast** (~360×90, bottom-corner, non-modal, does not steal focus) | gentle wrap-up nudge | short message + countdown + "Break now" |
| 3 | **Break card** (~420×280, centered, always-on-top) | the break itself | large break countdown, "Skip", ("Break now" while still in grace). Neutral copy. |
| 4 | **Stats window** (~720×520) | the "is this working?" instrument | today's summary + the charts in §6 + a timeline of today's sprints/breaks |
| 5 | **Settings** (~560×480) | configuration | sprint / break / grace durations, enforcement mode (Enforced ↔ Soft), autostart-on-login |
| 6 | *(optional)* **First-run / onboarding** | explain the concept, set durations | 2–3 light steps |

**Enforcement modes to reflect in the designs:**
- **Enforced (default):** grace toast → break card (assertive but escapable).
- **Soft:** at the boundary, an **OS notification** only — no card. Please show how
  the notification reads.
- **Do-Not-Disturb aware:** when the OS is in Focus/DND, the assertive card
  degrades to a quiet notification. A note on how that variant looks is welcome.

---

## 6. Charts / data-viz for the stats window

Minimal but real — this is how the user judges whether the cadence helps. Please
propose styles (and include their colors in the token set as a small chart
palette — categorical + sequential, color-blind-safe, working in light & dark):

- **Today's sprints** — a count, plus a **horizontal timeline strip** of the day
  showing sprints and breaks in sequence.
- **Breaks taken vs skipped** — a compact ratio (small donut or stacked bar).
- **Total over-run today** — a number with a subtle trend indicator.
- **Current streak** — a badge (consecutive compliant sprint→break cycles).
- *(nice-to-have)* **This week** — sprints per day as a small bar chart.

Design principle: these live in a small window and must read instantly; favor
clarity over decoration.

---

## 7. Technical constraints (please design within these)

- **The app is built in web tech** (Tauri v2 → an HTML/CSS/TypeScript frontend).
  So the most useful token formats are **CSS custom properties** and a **JSON
  token file** (see §8). Layouts as Figma + exported SVG/PNG are ideal.
- **Theme-aware:** provide full **light and dark** values for every token.
- **The menu-bar ring is rendered at runtime to a raster bitmap.** Please specify
  the ring precisely so we can draw it programmatically:
  - canvas sizes **16, 22, 24, 32 px** (+ @2x / retina);
  - ring diameter as a % of canvas, **stroke width**, inner gap, **start angle**
    (we assume 12 o'clock / −90° and clockwise fill — confirm), line cap;
  - **color stops per state** (focus / grace / over-run) and the empty-track color;
  - a **monochrome template** version for macOS menu bars (single color + alpha)
    and a color version for Linux.
- **Break card & toast:** small windows; the toast must look right *without*
  grabbing focus; the card is always-on-top and centered.
- **Accessibility:** meet WCAG AA contrast in both themes; **never encode state by
  color alone** — pair state with shape, icon, or label (color-blind users).
- **Assets:** vector (SVG) sources for everything; PNG exports for the tray icon
  at the sizes above; app icon at standard desktop sizes.

---

## 8. The brand kit we'd like delivered

1. **Brand guidelines** (PDF and/or Figma) — logo usage, palette, type, spacing,
   voice, do/don't.
2. **Mood board.**
3. **Logo / marks** — wordmark + app icon + tray glyph, in **SVG** + PNG exports
   (tray at 16/22/24/32 + @2x, mono template + color; app icon at desktop sizes).
4. **Icon set** — SVG, consistent grid.
5. **Screen layouts** — screens 1–6 above, light + dark, Figma link + exported
   PNG/SVG.
6. **Chart styles** — specs/examples for the §6 charts.
7. ⭐ **Machine-readable design tokens** — the key hand-off. Please deliver:
   - a **JSON token file** in the **W3C Design Tokens (DTCG) format**
     (`$value` / `$type`), Style-Dictionary-compatible, and
   - a **CSS custom-properties** export (`:root` for light, a dark override).
   Token groups we need (names are suggestions; own the values):
   - **color** — brand/primary; neutral scales (bg, surface, border, text);
     semantic **state** colors `focus`, `grace`, `break`, `overrun`, plus
     `success` / `warning` / `danger` / `info`; a small **chart** palette. Each
     with **light and dark** values.
   - **ring** — `track`, `progress-focus`, `progress-grace`, `progress-overrun`,
     stroke-width ratio, cap style.
   - **typography** — font families (display / body / mono), a size scale,
     weights, line-heights.
   - **spacing** scale, **radii**, **border widths**, **shadow/elevation**,
     **opacity**.
   - **motion** — durations + easing curves.
   - **icon** — stroke width, grid unit.

---

## 9. What NOT to do

- No dark patterns, guilt-tripping, or manufactured urgency.
- Don't punish skipped breaks or dramatize streaks.
- Don't make it loud — it lives in the menu bar all day.
- Don't rely on red/green alone for meaning.

---

## 10. Reference files (attached by Carmelo)

These give the full functional truth behind this brief — useful for depth, not
required to start:

- **Spec 1 — The Agentic 15-Minute Window** (the cadence contract: states,
  timings, grace window, event model).
- **Tomato (Linux) — First-App Design** (the functional design of the app the
  screens belong to).

*(Optional context: a research backlog of evidence on focus/break cadences and
human–AI work rhythms informs the roadmap; available on request.)*

---

## 11. For Carmelo to fill before sending

- Timeline / deadline: ______
- Budget / scope: ______
- Preferred tooling / hand-off (Figma? Style Dictionary?): ______
- Standalone brand vs. part of a suite (see §1): ______
