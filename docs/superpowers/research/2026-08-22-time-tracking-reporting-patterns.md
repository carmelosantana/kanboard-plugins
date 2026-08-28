# Time-Tracking Reporting Patterns — Research for Kanboard TimeReport Plugin

Date: 2026-08-22
Scope: survey commercial and open-source time-tracking tools to inform report/chart/invoice
features for the buildless Kanboard `TimeReport` plugin.

## Landscape summary

Every mature time-tracking product converges on the same three-layer report model: a **summary
bar** of 3–6 headline numbers (total hours, billable hours/%, revenue, cost, profit, avg/day), a
**chart** (usually a bar or pie, sometimes both side by side) driven by whatever grouping the user
picked, and a **grouped/pivotable table** underneath with expandable subtotals. Toggl Track and
Everhour push this furthest with fully configurable group-by/columns; Harvest and Clockify keep it
simpler with fixed tabs (Clients/Projects/Tasks/Team) or a single pie+table. Billable-vs-non-billable
is treated as a first-class split everywhere, not an afterthought — it drives both the color coding
in charts and a distinct subtotal row. Rounding is a deliberately separate, explicit setting (applied
at report/invoice time, not by mutating stored entries) with configurable increment and
up/down/nearest direction — every vendor surveyed (Toggl, Bonsai, Kimai) treats "rounding rules" as
its own settings block. Cross-project aggregation is the weakest spot in the market: most tools
default to a single-project view and only reveal portfolio-style aggregation behind a premium
"profitability/insights" tier (Toggl, Everhour, Hubstaff) — this is a real gap Kanboard's
project-per-board structure could fill well, since Kanboard already has a natural "all projects"
scope. Invoicing tools (FreshBooks, Bonsai, Kimai) all follow the same template: branded header,
client block, line items grouped by day/task/activity with rate × hours = amount, then
subtotal/tax/total, with rounding and detail-level applied at generation time and a choice to show
or hide the underlying time entries. Kimai is the closest architectural analogue to what
`TimeReport` should become: PHP, self-hosted, timesheet → report → invoice in one pipeline, with
invoice templates as separate, swappable Twig files.

## 1. Report structures & "amendments"

Beyond a raw hours table, every tool adds the same handful of ingredients, just with different
defaults for what's on by default vs. opt-in:

- **Billable vs non-billable split.** Treated as a hard partition, not just a filter. Harvest's Time
  report summary panel shows "Total hours, Billable and Non-billable hours, Billable amount, and
  Uninvoiced amount" up front (Harvest, [support.getharvest.com/.../Time-report](https://support.getharvest.com/hc/en-us/articles/360048181692-Time-report)).
  TimeCamp lets a user toggle an entry's billable flag inline via a dollar-sign icon in the timesheet
  (TimeCamp, [timecamp.com/timesheet-app](https://www.timecamp.com/timesheet-app/)). Toggl's summary
  bar can show "billable hours with percentage" as one of its 4 configurable tiles (Toggl,
  [support.toggl.com/.../summary-report](https://support.toggl.com/en-us/article/summary-report-1emjk2m/)).
- **Hourly rates & computed amounts.** Everhour's configurable reports expose "Billable Amount,"
  "Cost," and "Profit" as columns, but restrict them to admins/supervisors — a useful role-gating
  precedent (Everhour, [support.everhour.com/article/424](https://support.everhour.com/article/424-configurable-reports)).
  Toggl's Profitability Report computes profitability as "profit over revenue (amount)" and, for
  recurring projects, multiplies a per-period fixed fee "by the number of periods in the report
  range" (Toggl, [support.toggl.com/profitability-report](https://support.toggl.com/profitability-report)).
- **Rounding rules.** Universally a separate control, not a silent mutation of stored data. Toggl:
  "round individual entries or group totals, choose intervals from 1 minute up to 4 hours, and round
  up, down, or to the nearest value," while dollar amounts "always round up to two decimal places"
  (Toggl, same summary-report source). Bonsai has a dedicated help article for "Rounding time
  tracking entries on invoices" — rounding is applied at invoice-generation time, is configurable per
  increment/direction, and is explicitly decoupled from the underlying tracked entries (Bonsai,
  [help.hellobonsai.com/.../7050494](https://help.hellobonsai.com/en/articles/7050494-rounding-time-tracking-entries-on-invoices)).
  The classic default increment across the industry is 15 minutes, applied at report/export time.
- **Grouping/pivoting.** Toggl and Everhour allow arbitrary grouping by member, client, project,
  task, tag, or day/week/month, with a secondary ("sub-group") dimension for a two-level pivot
  (Toggl summary-report source; Everhour configurable-reports source). Clockify's Summary report is
  simpler — one grouping dimension drives a pie chart plus a table, and clicking any slice/row opens
  the Detailed report pre-filtered to that slice (Clockify, [clockify.me/help/reports/summary-report](https://clockify.me/help/reports/summary-report)).
- **Subtotals & "insight" numbers.** The recurring set is: total hours, billable hours/%, computed
  $ amount, average hours/day, cost, and profit. Toggl's summary bar is literally "up to 4
  customizable metrics" chosen from that list (Toggl summary-report source). Everhour's utilization
  metric is defined plainly as "out of the hours a person, team, or project had available, how many
  became billable work" (Everhour, [everhour.com/calculators/resource-utilization-calculator](https://everhour.com/calculators/resource-utilization-calculator)).
  The canonical utilization formula across the industry: `billable hours ÷ available hours × 100`,
  with 75–85% cited as a healthy target band for billable staff (Harvest glossary via search;
  Clockify, [clockify.me/blog/business/utilization-rate](https://clockify.me/blog/business/utilization-rate/)).

## 2. Charts — which visualizations, and what each answers

| Chart | What it answers | Data shape needed | Verdict |
|---|---|---|---|
| **Bar/column, hours per day or week** | "Is my/the team's tracked time trending up or down, and are there gaps?" | 1 series × N time buckets (day/week) | Genuinely useful — the single highest-value chart, present in every tool surveyed (Toggl bar chart, Harvest "Tracked Hours by Assignee" column chart). |
| **Stacked bar, composition over time** | "Of the hours in each period, how much went to each project/category?" | N time buckets × M categories (project/tag/member) | Useful once >1 project exists; Toggl explicitly supports stacking bars "by Billable, Members, Clients, Projects, Tasks and Tags" (Toggl summary-report source). |
| **Pie/donut, share by project/client/tag** | "Where did my time go, overall, right now?" (no time axis) | 1 dimension × share of total | Useful for a single at-a-glance snapshot; Clockify's whole Summary report is built around this ([clockify.me/help/reports/summary-report](https://clockify.me/help/reports/summary-report)). Loses value beyond ~6–8 slices — decorative at that point. |
| **Horizontal bar ranking, top tasks/projects** | "What are the 5–10 biggest time sinks?" | Sorted 1D list, ranked | Genuinely useful and cheap to build; Toggl's Profitability Report explicitly offers "top or bottom 5/10 performing items" (Toggl profitability-report source). |
| **Calendar heatmap, daily intensity** | "Which days/weeks were heavy vs. light, and are there streak/gap patterns?" | 1 value per calendar day across weeks/months | High information density for a whole-year view (GitHub-contributions style), but a bigger build lift; best treated as a stretch goal, not a v1 chart. |
| **Burn-down/cumulative line** | "Are we on pace against an estimate/budget?" | cumulative sum vs. a target line over time | Only useful where Kanboard tasks/projects carry time *estimates* — otherwise there's no budget to burn down against. Conditionally valuable. |

Bottom line: **bar-per-period (trend) and horizontal-bar ranking are the two chart types that pay
for themselves immediately**; pie/donut is worth one instance for the "share of total" snapshot but
shouldn't be the primary chart; stacked bar is the natural v2 once multi-project aggregation exists;
heatmap and burn-down are real but lower priority given a buildless constraint.

## 3. Cross-project / all-work aggregation

This is the thinnest part of the market. Findings:

- **Toggl Track** has no native all-projects portfolio dashboard — its "Project Dashboard" is
  scoped to one project at a time (task/member/status breakdowns), and true cross-project rollups
  require the paid "Insights"/"Profitability" report or external tooling via the API (Toggl,
  [support.toggl.com/project-dashboard](https://support.toggl.com/project-dashboard); search summary
  on Toggl portfolio view).
- **Harvest** partially covers this: its Time report has a summary panel and four tabs
  (Clients / Projects / Tasks / Team) that already aggregate *across* whatever the date filter
  covers, so "all my work this month" is one report, tabbed by dimension rather than siloed per
  project (Harvest Time-report source).
- **Everhour** ships "6 pre-set dashboards" specifically to give an out-of-the-box cross-project
  pulse without configuration (Everhour, [support.everhour.com/article/508](https://support.everhour.com/article/508-pre-set-dashboards)),
  and its configurable-report builder lets any column (member, project, client, tag) become the
  group-by for a firm-wide rollup.
- **Hubstaff** frames the aggregate view around utilization/productivity rather than hours-by-project
  — its Insights dashboard shows utilization rate vs. targets across the whole team, which is a
  different (people-centric, not project-centric) cut on aggregation (Hubstaff,
  [hubstaff.com/insights](https://hubstaff.com/insights)).
- **Default breakdown when a portfolio view exists**: by client first, then project, then
  member/task — matching how a services business bills, not how a PM tool tracks status.

Kanboard's structure (many projects, one shared time-tracking plugin) is actually well suited to
close this gap: a native "all projects" report — grouped by project by default, with client/tag/
member as secondary groupings — would be ahead of most competitors rather than catching up to them.

## 4. Invoicing / PDF

Structure of a polished time-based invoice, consistent across FreshBooks, Bonsai, and Kimai:

1. **Header/branding** — business name, logo, contact details (FreshBooks,
   [freshbooks.com/invoice-templates/hourly](https://www.freshbooks.com/invoice-templates/hourly)).
2. **Client block** — recipient details plus invoice number, issue date, due date (same source).
3. **Line items** — one row per grouped chunk of time: description, rate, hours, computed amount.
   Grouping granularity is a user choice, not fixed: Bonsai lets you present "a more detailed
   breakdown by task, service, role, or date" instead of one aggregated line per rate (Bonsai,
   [help.hellobonsai.com/.../1898575](https://help.hellobonsai.com/en/articles/1898575-invoicing-your-tracked-time)).
   Kimai's invoice templates do the grouping server-side: "When you group by day, all line items are
   merged," and grouping by project/activity/customer replaces the line description with that
   dimension's label (Kimai docs via search, [kimai.org/documentation/invoices.html](https://www.kimai.org/documentation/invoices.html)).
4. **Subtotal → tax → total.** Universal three-step roll-up; FreshBooks explicitly calls out
   "subtotals and taxes" before the final total (FreshBooks source).
5. **Notes/terms** — payment terms, accepted methods, special instructions (FreshBooks source).
6. **Time-entry rollup mechanics** — FreshBooks auto-populates rate "depending on the chosen billing
   rate" when converting tracked time to line items, but caps at "400 time entry line items" per
   invoice — a concrete reminder that raw entries must be pre-aggregated before hitting a line-item
   table, not dumped 1:1 (FreshBooks, [support.freshbooks.com/.../227602308](https://support.freshbooks.com/hc/en-us/articles/227602308-How-do-I-generate-an-invoice)).
   Bonsai models billing status per entry as **Non-billable → Unbilled → Billed**, so an invoice run
   only ever pulls "Unbilled" entries and flips them to "Billed" on generation — this is a clean,
   idempotent state machine worth copying directly (Bonsai
   [help.hellobonsai.com/.../1898575](https://help.hellobonsai.com/en/articles/1898575-invoicing-your-tracked-time)).
7. **Standard practices**: rounding is applied once, at invoice-generation time, using the same
   increment/direction controls as reports (not a separate ad hoc rule); detail can be shown or
   collapsed (a full timesheet attached vs. one line per rate) — Bonsai's "Invoice Unbilled Hours"
   action explicitly generates "a new invoice including all billable hours and a timesheet of
   tracked entries," i.e. summary line items *plus* an optional detail appendix (same Bonsai source).

## Recommendations for our plugin

Constraints recap: buildless Kanboard plugin — plain PHP, vanilla JS + jQuery, plain CSS, no
bundler/npm step. Charts must be inline SVG/CSS or one small vendored dependency-free JS file. PDF
is server-side PHP. Current state: `TimeReport` (repo at `TimeReport/`) already has a
`TimeReportModel`, `TimeReportController`, and templates (`_breakdown.php`, `_detail.php`,
`_untracked.php`, `show.php`) rendering tabular breakdowns with no charts yet — this is exactly the
gap this research targets.

### 1. Project → report deep link
Every competitor treats "jump straight into this project's numbers" as table stakes (Harvest's
per-project tab, Toggl's per-project dashboard). Add a "Time Report" link/icon in Kanboard's project
header/sidebar that opens `TimeReportController` pre-filtered to `project_id`, carrying the current
date range if one is already selected on the board. Low effort, matches existing controller
architecture — just needs a route + a template partial hookable into Kanboard's project menu.

### 2. Quick "generate report" action
Follow Toggl/Harvest's pattern of one summary bar + immediate default grouping, no configuration
required. On the task or project page, add a single "Generate report" button that opens the report
pre-scoped (this task's project, this month) rather than making the user set every filter first —
mirrors Everhour's pre-set dashboards philosophy of "no extra setup." Cheap: reuse existing
`TimeReportModel` query paths with sensible defaults baked in as the entry point.

### 3. Charts — priority recommendation
**Ranked chart short-list, value-for-effort:**

1. **Bar chart, hours per day/week** (trend) — highest value, lowest effort. One `<svg>` partial:
   N rectangles scaled to a shared max, labeled by day. Directly answers "is this project active
   right now."
2. **Horizontal bar, top N projects/tasks/members** (ranking) — same SVG technique rotated 90°,
   reused component. Answers "where did the hours go" without needing a full pivot UI.
3. **Donut/pie, billable vs non-billable share** — one instance only, as a snapshot stat next to the
   summary numbers, not the primary chart (avoid Clockify's failure mode of relying on pie for
   everything). SVG `stroke-dasharray` arcs, no library needed.
4. **Stacked bar, composition by project over time** — v2, once cross-project aggregation (item 4
   below) exists; reuses the bar-chart component with per-segment color coding by project.
5. *(stretch)* **Calendar heatmap** — genuinely useful for spotting activity gaps across a quarter,
   but the highest implementation cost of the five; defer past v1.

**Buildless rendering approach:** hand-roll inline SVG for #1–#4. All four are simple enough (bars,
arcs) that a ~150-line vanilla-JS helper (`renderBarChart(el, data)`, `renderDonut(el, data)`)
generating `<svg>` markup directly into the DOM is both smaller and more theme-controllable (light/
dark via CSS custom properties, matching Kanboard's existing CSS) than vendoring a library. If a
named library is ever justified (e.g. for the heatmap stretch goal), **Frappé Charts** is the best
fit of those surveyed — genuinely zero runtime dependencies, single-file, SVG-based like the rest of
the plugin's rendering, and scoped to exactly bar/line/pie (no unused surface area). Avoid Chart.js/
ApexCharts for this project: both are canvas-based (harder to theme via CSS, no accessible DOM per
data point) and sized for far broader feature sets than four chart types need.

### 4. All-projects aggregate view
This is a genuine market gap (see Q3) — Toggl and most competitors leave this behind a paywall or
external tooling, so a first-class "All Projects" report in `TimeReport` would be ahead of the
field, not catching up. Recommended default: group by **project** first (matching Kanboard's own
mental model), with **member** and **tag** as one-click alternate groupings, plus the same summary
bar (total hours, billable %, computed $ if rates are configured). Reuse the per-project report's
bar/ranking charts, just fed with an unfiltered (or date-ranged) query across all projects the user
can access — same `TimeReportModel` methods, different scope.

### 5. PDF invoicing plugin
Treat as a **separate plugin** (`TimeInvoice` or similar) that depends on `TimeReport` for its data,
following Kimai's architecture of decoupled timesheet → report → invoice stages and matching this
suite's existing dependency conventions. Design points to steal:
- **Billing-status state machine** (Bonsai): every time entry is Non-billable / Unbilled / Billed;
  invoice generation only pulls Unbilled and flips them to Billed — idempotent, no double-billing.
- **Grouping choice at generation time** (Bonsai + Kimai): let the user pick line-item granularity
  (per day / per task / per activity) rather than hard-coding one aggregation.
- **Pre-aggregate before line items** (FreshBooks' 400-line-item ceiling is a warning, not a target):
  never emit one invoice line per raw time entry — always roll up to the chosen grouping first.
  Rounding is applied once, at this rollup step, using the same increment/direction settings as
  `TimeReport`'s own rounding control, so numbers agree between report and invoice.
- **Structure**: branded header, client block, grouped line items (rate × rounded hours = amount),
  subtotal → tax → total, notes/terms, with an optional attached detail timesheet.
- **PDF rendering**: server-side PHP, no build step — use a lightweight pure-PHP PDF library
  (e.g. `dompdf`/`tcpdf`-class HTML-to-PDF, since Kanboard plugins are typically vendored via
  Composer without a JS build step) rendering a Twig/PHP template analogous to Kimai's swappable
  invoice templates, so operators can override the template file without touching plugin code.

## Sources
- [Toggl — Billable rates](https://support.toggl.com/billable-rates)
- [Toggl — Summary Report](https://support.toggl.com/en-us/article/summary-report-1emjk2m/)
- [Toggl — Profitability Report](https://support.toggl.com/profitability-report)
- [Toggl — Project Dashboard](https://support.toggl.com/project-dashboard)
- [Harvest — Time report](https://support.getharvest.com/hc/en-us/articles/360048181692-Time-report)
- [Harvest — Billable vs Non-billable hours](https://www.getharvest.com/time-tracking/billable-vs-non-billable-hours)
- [Clockify — Summary report](https://clockify.me/help/reports/summary-report)
- [Clockify — Reports feed](https://clockify.me/help/category/reports/feed)
- [Clockify — Utilization rate](https://clockify.me/blog/business/utilization-rate/)
- [Everhour — Configurable reports](https://support.everhour.com/article/424-configurable-reports)
- [Everhour — Pre-set dashboards](https://support.everhour.com/article/508-pre-set-dashboards)
- [Everhour — Resource utilization calculator](https://everhour.com/calculators/resource-utilization-calculator)
- [Timely — Reports & Analytics](https://www.timely.com/feature/reports/)
- [Hubstaff — Insights](https://hubstaff.com/insights)
- [TimeCamp — Timesheet App](https://www.timecamp.com/timesheet-app/)
- [FreshBooks — Hourly invoice template](https://www.freshbooks.com/invoice-templates/hourly)
- [FreshBooks — How do I generate an invoice?](https://support.freshbooks.com/hc/en-us/articles/227602308-How-do-I-generate-an-invoice)
- [Bonsai — Invoicing your tracked time](https://help.hellobonsai.com/en/articles/1898575-invoicing-your-tracked-time)
- [Bonsai — Rounding time tracking entries on invoices](https://help.hellobonsai.com/en/articles/7050494-rounding-time-tracking-entries-on-invoices)
- [Kimai — GitHub project](https://github.com/kimai/kimai)
- [Kimai — Invoices documentation](https://www.kimai.org/documentation/invoices.html)
- [Kimai — Invoice templates documentation](https://www.kimai.org/documentation/invoice-templates.html)
- [Kimai discussion — grouping by record description in invoices](https://github.com/kimai/kimai/discussions/5041)
- [Redmine — PluginCharts wiki](https://www.redmine.org/projects/redmine/wiki/PluginCharts)
- [Redmine — Timesheet Plugin by Redmineflux](https://www.redmine.org/plugins/redmineflux-timesheet-plugin)
- [Chart.js](https://www.chartjs.org/)
- [Frappé Charts (zero-dependency SVG charts)](https://github.com/topics/zero-dependency?l=javascript)
- [ApexCharts — JS chart library comparison](https://apexcharts.com/javascript-charts-comparison/)
