# TimeReport v1.1.0 — QoL: weekday dates + click-to-copy hours

**Date:** 2026-08-22
**Plugin:** TimeReport (`github.com/carmelosantana/kanboard-time-report`)
**Target version:** 1.1.0 (1.0.0 is already released; this is a new minor)
**Status:** design approved

## Goal

Two small, independent display-layer quality-of-life improvements to the
consultant hours report, driven by real use: make the dates easier to read at
a glance, and make single hours values one click to copy for manual entry into
another system one value at a time.

Both are **presentation-only**. No model/business-logic changes, no schema
changes, no new dependencies. Buildless as always (plain PHP ≥ 8.4, vanilla
JS + delegated handlers, plain CSS; CSP-safe — no inline `<script>`/`on*=`).

## Feature 1 — Weekday prefix on dates

Show the day of the week in front of the ISO date so the report reads like a
mini-calendar down the column.

**Format (approved):** prefix, abbreviated — `Mon 2026-08-10`.

**Where it appears (approved):** on-screen report **and** the Copy-as-Markdown
output. The **CSV export keeps bare ISO dates** (`2026-08-10`) so it stays clean
for spreadsheets/parsing.

**Design decision — it is a presentation decoration, not a model change.**
Baking the weekday into the model's stored `label` field would also leak it
into the CSV (which reuses those labels). Instead, a single shared helper
decorates a date string at render time; the CSV path simply doesn't call it.

### Helper

New method on `TimeReportHelper`:

```php
/**
 * Prefix an ISO date (YYYY-MM-DD) with its abbreviated weekday: "Mon 2026-08-10".
 * Any string that is not a bare ISO date is returned unchanged, so this is safe
 * to call unconditionally on breakdown labels (week ranges, task labels, "Total").
 */
public function withWeekday(string $date): string
```

- If `$date` matches `^\d{4}-\d{2}-\d{2}$`: return `date('D', strtotime($date.' 12:00:00')) . ' ' . $date`.
- Otherwise: return `$date` unchanged.

The regex guard is what lets callers apply it to every breakdown row without
branching on granularity:

| Granularity | Row label            | `withWeekday` result        |
|-------------|----------------------|-----------------------------|
| day         | `2026-08-10`         | `Mon 2026-08-10` (decorated)|
| week        | `Aug 10 – Aug 16`    | unchanged                   |
| task        | `#63 Some title`     | unchanged                   |
| total       | `Total`              | unchanged                   |

Task labels always start with `#<id>` and the total label is localized text, so
neither can accidentally match the ISO pattern.

### Render surfaces

- **`Template/report/_breakdown.php`** — wrap the row label:
  `$this->helper->timeReport->withWeekday($row['label'])`. Still HTML-escaped
  via `$this->text->e(...)` around the result.
- **`Template/report/_detail.php`** — wrap the `date_completed` cell the same way.
- **`Helper/TimeReportHelper::toMarkdown()`** — apply `withWeekday` to the
  breakdown labels and to each detail row's `date_completed`, so the copied
  Markdown matches the screen.
- **`Helper/TimeReportHelper::toCsv()`** — **unchanged**; emits bare ISO dates.

## Feature 2 — Click-to-copy hours

Make single hours values one click to copy, so entering them one at a time
elsewhere is trivial.

**Interaction (approved):** click the number itself (not a separate icon).
**Scope (approved):** every Hours cell in the breakdown and detail tables, plus
the grand-total hours. Task-count integers stay non-interactive.
**Feedback (approved):** the cell briefly flashes an accent background and a
small `Copied ✓` badge pops next to it, then fades. The number text is never
replaced (it must stay visible for the next copy).

**Copied value:** the exact 2-dp string shown (e.g. `7.17`), carried explicitly
in a `data-tr-copyval` attribute so the copy is decoupled from any display
decoration and from surrounding markup.

### Templates

Each copyable hours cell gains a class, the value attribute, and keyboard
affordances:

```php
<td class="tr-num tr-copy-num"
    data-tr-copyval="<?= $this->text->e($hoursStr) ?>"
    role="button" tabindex="0"
    title="<?= t('Click to copy') ?>"><?= $this->text->e($hoursStr) ?></td>
```

- `Template/report/_breakdown.php` — the Hours `<td>`.
- `Template/report/_detail.php` — the Hours `<td>`.
- `Template/report/show.php` — the grand-total hours value is currently a bare
  inline expression after `<strong>Total hours:</strong>` (line 9). Wrap it in a
  `<span class="tr-copy-num" data-tr-copyval="…" role="button" tabindex="0"
  title="…">…</span>` carrying the same value (the `formatHours(...)` output).

`$hoursStr` is the existing `formatHours(...)` output — the same string used for
display and for `data-tr-copyval`.

### JS — `Assets/js/timereport.js`

Extend the existing single delegated `click` listener (do **not** add a second
top-level listener) with a branch for `[data-tr-copyval]`:

- On click of an element matching `[data-tr-copyval]` (via `closest`): read the
  attribute, copy it using the **existing** clipboard-with-`execCommand`
  fallback already in the file, then:
  - add a `tr-copy-flash` class to the cell and remove it after ~600 ms;
  - create a `<span class="tr-copy-badge">` (text from a
    `data-tr-copied`/localized string, default `Copied ✓`) inside the cell,
    then remove it after ~1.2 s.
- Add a `keydown` delegated handler so **Enter/Space** on a focused
  `[data-tr-copyval]` triggers the same copy (accessibility; `role="button"` +
  `tabindex="0"` are already on the cell). Prevent default scroll on Space.
- The existing Markdown-copy branch (`[data-tr-copy]` → `#tr-markdown`) is
  untouched and must keep working.

All handlers remain event-delegated on `document`; no inline handlers; CSP-safe.

### CSS — `Assets/css/timereport.css`

- `.tr-copy-num { cursor: pointer; }` plus a subtle hover affordance (e.g. faint
  background or dotted underline) so the number reads as interactive.
- `.tr-copy-flash` — a brief (~600 ms) accent-background transition/animation.
- `.tr-copy-badge` — a small floating label positioned near the cell
  (cell gets `position: relative`), accent-colored, that fades out.

Use existing theme tokens/accent already used elsewhere in `timereport.css` for
visual consistency; no hard-coded brand colors that fight the theme.

## Cross-cutting

### Versioning / release

- Bump `plugin.json` `version` → `1.1.0`.
- Bump `Plugin.php::getPluginVersion()` → `1.1.0` (must match `plugin.json`).
- Release by pushing tag `v1.1.0` (bare, equals `plugin.json` version — CI gates on it).
- At release time, bump the `kanboard-modmenu-directory/plugins.json` TimeReport
  entry's download URL to the `TimeReport-1.1.0.zip` asset.
- `recommends` (AiConnector) is unchanged.

### Testing

Extend the existing suite (currently 59 tests / 184 assertions) and keep all of
it green. New/changed coverage:

- **Helper `withWeekday`:**
  - a known ISO date → correct `Ddd YYYY-MM-DD` prefix (assert against a date
    whose weekday is computed in the test, e.g. from `date('D', strtotime(...))`,
    and also assert the `^[A-Z][a-z]{2} \d{4}-\d{2}-\d{2}$` shape);
  - non-ISO inputs returned unchanged: a week range (`Aug 10 – Aug 16`), a task
    label (`#63 Foo`), and `Total`.
- **`toMarkdown`** on a day-granularity report → output contains the weekday
  prefix on the date label.
- **`toCsv`** on the same report → output contains the **bare** ISO date and
  **not** the weekday prefix.
- **Template/assets** (extend `TemplateAssetsTest` style): breakdown/detail
  templates emit `data-tr-copyval` on hours cells; `timereport.js` contains the
  `data-tr-copyval` copy branch and the keydown handler; `timereport.css`
  contains `.tr-copy-num` / `.tr-copy-flash` / `.tr-copy-badge`.

### Non-goals (YAGNI)

- No copy affordance on task-count integers or on date labels.
- No weekday in the CSV.
- No configuration/settings toggle for either feature — they are always on.
- No change to the untracked-subtask warning, AI summary, or any model logic.

## Verification (live)

After tests pass, exercise on the `kb-suite` dev stack (`:8081`, admin/admin)
against the demo project(s): day-granularity report shows `Mon 2026-08-10`
labels; Markdown copy includes weekday; CSV download has bare ISO; clicking any
hours value copies it with the flash + `Copied ✓` badge; Enter/Space on a
focused value copies it; the existing Copy-as-Markdown button still works.
