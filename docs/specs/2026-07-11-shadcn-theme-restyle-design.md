# ShadcnTheme Neutral-Zinc Restyle — Design & Spec

- **Date:** 2026-07-11
- **Status:** Approved via brainstorming; source of truth for a single-agent SDD build.
- **Plugin/repo:** `ShadcnTheme` (`kanboard-shadcn-theme`), currently released at **1.0.4**.
- **Target version:** **1.1.0** (no API/settings/behavior break — a visual repaint).

## Goal

Make the ShadcnTheme plugin look near-identical to the authentic **shadcn/ui "neutral"**
aesthetic (the look of the shadcn/ui site and the Dokploy dashboard reference): a pure
neutral-zinc, near-monochrome palette with a **near-white primary**, color reserved for
destructive actions and status. Apply it **exhaustively across every Kanboard screen**,
**CSS-first**, keeping Kanboard's existing top-nav layout (no structural re-layout).

## Approved decisions (locked in brainstorming)

1. **Layout fidelity:** *Visual language only.* Keep Kanboard's top-nav chrome; restyle every
   surface. Do **not** build a left-sidebar app shell.
2. **Primary color:** *Near-white primary.* Faithful shadcn neutral — primary actions (Save/Add)
   become light buttons; color is reserved for destructive + semantic status.
3. **Surface scope:** *Exhaustive.* The token overhaul applies globally; every screen (including
   admin/settings/report) gets an audit pass, not just high-traffic pages.
4. **JS richness:** *CSS-first, minimal JS.* Pure CSS for components; only safe, additive JS. No
   changes to Kanboard's core jQuery interactions.

## Non-negotiable constraints (suite-wide)

- **Buildless.** Plain CSS/JS only; no build step. External `Assets/js` only; **no inline
  `<script>`** (CSP `default-src 'self'`). Assets are injected via the existing
  `template:layout:css` / `template:layout:js` hooks (`ShadcnTheme/Plugin.php:73-101`).
- **Preserve the theme system.** The no-FOUC head script, per-user light/dark/system toggle,
  favicon/logo upload + public asset route, and header brand override all stay working exactly as
  today. This is a repaint, not a rearchitecture.
- **Preserve E2E-earned Kanboard fixes** (do not regress these — they exist for real reasons,
  documented inline): the header project-selector double-box collapse
  (`shadcn-core.css:241-262`), board/calendar row-hover suppression (`shadcn-core.css:347-356`),
  fieldset flattening (`:266-282`), `.listing` bullet removal (`:309-315`), input `box-sizing`
  height alignment (`:180-202`).
- **Browser floor** stays: evergreen browsers with `:has()` and `color-mix()` (Chrome 105+,
  Firefox 121+, Safari 15.4+). OKLCH tokens are fine.
- **Host-side edits only.** Never `docker exec … sed/tee` into the running container.

---

## 1. Design tokens (the core change)

Replace the warm-slate/violet token values with the canonical shadcn **neutral** palette. The
*mapping* onto Kanboard's own CSS variables (in `shadcn-dark.css` / `shadcn-light.css`) is
unchanged — only the source token values change, so most surfaces re-tint automatically.

### 1a. Dark theme (`.theme-dark`) — replace values in `shadcn-dark.css`

| Token | Current (warm/violet) | New (shadcn neutral dark) |
|---|---|---|
| `--background` | `oklch(0.147 0.004 49.25)` | `oklch(0.145 0 0)` |
| `--foreground` | `oklch(0.985 0.001 106.423)` | `oklch(0.985 0 0)` |
| `--card` / `--card-foreground` | `oklch(0.216 0.006 56.043)` / off-white | `oklch(0.205 0 0)` / `oklch(0.985 0 0)` |
| `--popover` / `--popover-foreground` | `oklch(0.216 0.006 56.043)` / off-white | `oklch(0.205 0 0)` / `oklch(0.985 0 0)` |
| `--primary` | `oklch(0.62 0.19 293)` (violet) | `oklch(0.922 0 0)` (**near-white**) |
| `--primary-foreground` | `oklch(0.985 0 0)` | `oklch(0.205 0 0)` (**dark**) |
| `--secondary` / `--secondary-foreground` | `oklch(0.268 0.007 34.298)` / off-white | `oklch(0.269 0 0)` / `oklch(0.985 0 0)` |
| `--muted` / `--muted-foreground` | `oklch(0.268 …)` / `oklch(0.76 0.01 56)` | `oklch(0.269 0 0)` / `oklch(0.708 0 0)` |
| `--accent` / `--accent-foreground` | `oklch(0.268 …)` / off-white | `oklch(0.269 0 0)` / `oklch(0.985 0 0)` |
| `--destructive` / `--destructive-foreground` | `oklch(0.704 0.191 22.216)` / off-white | *(unchanged)* `oklch(0.704 0.191 22.216)` / `oklch(0.985 0 0)` |
| `--border` | `oklch(1 0 0 / 8%)` | `oklch(1 0 0 / 10%)` |
| `--input` | `oklch(1 0 0 / 8%)` | `oklch(1 0 0 / 15%)` |
| `--ring` | `oklch(0.62 0.19 293 / 70%)` (violet) | `oklch(0.556 0 0)` (**neutral**) |
| `--radius` (in `shadcn-core.css`) | `0.5rem` | `0.625rem` |

Semantic status colors (success/warning/info) that are NOT part of shadcn's neutral core stay as
today's chart/alert values (they must remain distinguishable — green/amber/blue/red). Only the
neutral chrome goes monochrome.

### 1b. Light theme (`:root` / `.theme-light`) — replace values in `shadcn-light.css`

| Token | New (shadcn neutral light) |
|---|---|
| `--background` / `--foreground` | `oklch(1 0 0)` / `oklch(0.145 0 0)` |
| `--card` / `--card-foreground` | `oklch(1 0 0)` / `oklch(0.145 0 0)` |
| `--popover` / `--popover-foreground` | `oklch(1 0 0)` / `oklch(0.145 0 0)` |
| `--primary` / `--primary-foreground` | `oklch(0.205 0 0)` (**near-black**) / `oklch(0.985 0 0)` |
| `--secondary` / `--secondary-foreground` | `oklch(0.97 0 0)` / `oklch(0.205 0 0)` |
| `--muted` / `--muted-foreground` | `oklch(0.97 0 0)` / `oklch(0.556 0 0)` |
| `--accent` / `--accent-foreground` | `oklch(0.97 0 0)` / `oklch(0.205 0 0)` |
| `--destructive` | `oklch(0.577 0.245 27.325)` |
| `--border` / `--input` | `oklch(0.922 0 0)` / `oklch(0.922 0 0)` |
| `--ring` | `oklch(0.708 0 0)` |
| `--radius` | `0.625rem` (shared, from core) |

Note the primary **inverts** between modes (near-white on dark, near-black on light) — this is
authentic shadcn and is exactly why primary must not be treated as a hue.

### 1c. Link decoupling (required consequence)

Kanboard links currently inherit `--primary` (`shadcn-dark.css:81` `--link-color-primary: var(--primary)`).
With a near-white/near-black primary that makes links blend into text or invert unreadably.
**Decouple links from primary:** set `--link-color-primary`, `--link-color-hover`,
`--link-color-focus` to `--foreground`, with `text-decoration: none` by default and
`underline` on `:hover`/`:focus-visible`. The violet link accent is removed deliberately (matches
the neutral reference). Task-title links on the board must stay clean (no permanent underline).

---

## 2. CSS architecture

Keep the four-file split and the current hook order, but reorganize `shadcn-core.css` into clearly
labelled layers so the audit is navigable: **(1) tokens/radius/type → (2) base & reset →
(3) components → (4) Kanboard-specific overrides**. Do not split into more files (buildless; fewer
requests is better). The `kbx-*` shared primitives (consumed by BulkProjectDelete, FeatureSync,
DependencyPlugin, etc.) stay in `core` and re-tint automatically via the new tokens — verify they
still render with the neutral palette.

---

## 3. Component specifications

All components use tokens (no hard-coded colors except documented semantic status). Target the
shadcn/ui "new-york" component look.

- **Buttons.** Primary = near-white bg + dark text (`--primary`/`--primary-foreground`), hover
  `primary/90` (opacity 0.9). Secondary/default = `--secondary` bg + `--border` (dark gray with
  hairline). Destructive = `--destructive`. Ghost/link variants where Kanboard uses bare links in
  toolbars. Height ~2rem, `--radius`, `font-weight:500`, focus ring = neutral `--ring`.
  (Existing `.btn` rules at `shadcn-core.css:105-174` already flow from tokens — verify, don't
  rebuild.)
- **Inputs / selects / textarea.** `--input` border, `--background` fill, focus = neutral ring +
  `color-mix` glow. Keep the `box-sizing`/height-alignment fix. Checkboxes/radios use
  `accent-color: var(--primary)` — with near-white primary, verify contrast; if a white check on
  white is unreadable, use `--foreground` for the accent instead. Document the choice.
- **Cards / panels / modals.** `--card` bg, hairline `--border`, `--radius`, restrained shadow
  (dark-mode shadows stay subtle per existing `--shadow-*`).
- **Badges — including status-dot pills.** Extend `.kbx-badge`: add a leading/trailing **status
  dot** variant (`● Done`, `● Error` as in the reference) and semantic color variants
  (`--kbx-badge--success|warning|destructive|neutral`) driven by tokens/status colors. Pure CSS.
- **Dropdowns / popovers.** `--popover` bg, `--border`, `--shadow-lg`, item hover = `--accent`
  (subtle gray). Already close (`shadcn-core.css:362-396`) — verify under neutral tokens.
- **Tables.** Flat hairline dividers, muted-foreground headers (already done, `:321-356`) — keep
  board/calendar hover suppression.
- **Alerts.** Keep semantic border-left/background treatment; re-tint neutral chrome.
- **Tabs.** Kanboard's `.views`/tab strips styled as shadcn segmented tabs (underline or pill
  active state) using tokens.
- **Board columns & task cards.** Column header = `--card`, list body = `--muted`, task card =
  `--card` with hairline; keep the card lift-on-hover (translateY + shadow), keep task color
  classes readable in dark mode.
- **Header / nav.** Top bar = `--background`, board selector single clean trigger (keep the
  double-box fix), user dropdown = popover styling, search input tokenized.
- **Login card.** Re-tint the centered card (`shadcn-login.css`) to neutral; logo/product-name
  header unchanged in behavior.

---

## 4. Exhaustive screen audit checklist

Every surface below is opened in dark **and** light and reconciled to the component specs. The
token overhaul handles most of it; this list is the completeness gate for hand-tuning and catching
stragglers (hard-coded colors, contrast misses, layout breaks):

- **Auth:** login, password reset, 2FA check.
- **Dashboard (my views):** overview, my tasks, my subtasks, my projects, my activity, my calendar,
  notifications.
- **Board:** columns, swimlanes, collapsed swimlanes, task cards, board filters dropdown, board
  header/actions, WIP-limit states, drag ghosts.
- **Task view:** details panel, subtasks, comments (+ markdown), internal/external links,
  attachments, time tracking, metadata, task sidebar action list, task colors.
- **Task forms/modals:** create, edit, close/remove confirm, small/medium/large modals.
- **Project views:** list, calendar, gantt, table/analytics, activity, and the sub-view tab strips.
- **Project settings:** columns, categories, swimlanes, permissions, automatic actions, notifications,
  integrations, share/public.
- **Admin/Settings:** application settings, users, groups, project management, links, currencies,
  automated actions, plugins page.
- **User profile/preferences:** profile, password, 2FA, sessions, notifications, API/personal tokens,
  external accounts.
- **Global chrome:** header (board selector, search, user menu), flash messages, tooltips, dropdown
  menus, confirm dialogs, pagination, empty states.
- **This plugin's own pages:** the ShadcnTheme admin settings page + config sidebar link.

---

## 5. JavaScript changes (minimal)

`shadcn-enhancements.js` — align to the aesthetic:

- **Remove** the Material-style button **ripple** (`setupRippleEffects`) and the scroll-in
  **`animate-in`** intersection-observer animation (`setupAnimations`) — neither is shadcn; both add
  motion/paint the redesign doesn't want. Remove their injected `<style>` blocks too.
- **Keep** the genuinely useful, non-decorative behaviors: backdrop-click modal close (with the
  form-guard), Escape-to-close, and the subtle modal/dropdown/tooltip fade-in (short, shadcn-like).
- **Optional additive nicety (in-scope, no regression):** route Kanboard flash messages into a
  shadcn-style **toast** using the existing `showToast()` (bottom/top-right, `--popover` card,
  auto-dismiss). If it can't be done without touching core flash rendering, leave flashes as
  re-tinted alerts — do not fight Kanboard's JS.
- No new dependencies, no inline scripts, no changes to Kanboard's core interactions (board DnD,
  modals open/close lifecycle, autocomplete).

`theme-preload.js` / `theme-switcher.js` — unchanged (they drive the no-FOUC + toggle; leave them).

---

## 6. Non-goals

- No left-sidebar app shell / IA restructure.
- No richer JS widgets (comboboxes, command palette, custom select) — CSS-first only.
- No new settings/options, no new routes, no changes to the favicon/logo/upload feature.
- No external fonts/icons (CSP + buildless). System sans stack stays.
- No changes to other plugins (only the `kbx-*` primitives are shared — re-tint and verify, don't
  redesign their markup).

---

## 7. Version & release (orchestrator owns the outward steps)

- Bump to **1.1.0** in **both** `plugin.json` **and** `Plugin.php::getPluginVersion()` — note the
  existing drift (`plugin.json` = `1.0.4`, `Plugin.php` returns `1.0.2`); align both to `1.1.0`.
- Update `CHANGELOG.md` + `README.md` (neutral-zinc palette, near-white primary, exhaustive
  screen pass).
- The agent does **not** push/tag/release or edit the directory. On hand-back the **orchestrator**
  merges `feat/shadcn-restyle` → `main`, tags `kanboard-shadcn-theme` **v1.1.0** (CI releases), and
  bumps the `kanboard-modmenu-directory` entry (version + download URL → `v1.1.0`).

## 8. Testing & regression guardrails

- **Unit tests stay green:** `./testing/run-plugin-tests.sh ShadcnTheme` (PluginTest,
  SettingsControllerTest, ThemeControllerTest). Update `PluginTest` version assertion to `1.1.0`.
- **Live acceptance = before/after screenshot matrix** on the shared `:8081` dev stack across the
  §4 screens, in **both** dark and light. This is the primary acceptance gate for a visual change.
  Capture representative before/after pairs (board, task view, dashboard, a settings page, login).
- **Regression focus:** the preserved E2E fixes (§ constraints) must still hold; check contrast
  (WCAG AA for text), that no surface has an unreadable near-white-on-near-white or
  hard-coded leftover warm/violet color, and that the `kbx-*` primitives still render for the
  other suite plugins.
- **Shared-stack caveat:** two other agents (CalendarPlugin v1.2.0, TimeBlock) may be using `:8081`
  concurrently. Live verification is best-effort; if the stack is mid-recreate, rely on review +
  unit tests and leave the authoritative full matrix to the orchestrator.

## 9. Follow-up (tracked, not in this build)

CalendarPlugin v1.2.0 (in flight) adds `timeGridWeek`/`timeGridDay` views. Once it lands, a small
follow-up pass styles those new grids under the neutral tokens. Out of scope here so it doesn't
block the restyle.
