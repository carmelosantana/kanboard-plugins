# TimeReport v1.3.0 — Multi-user tracking (design)

**Date:** 2026-08-27
**Plugin:** TimeReport (`github.com/carmelosantana/kanboard-time-report`)
**Target version:** 1.3.0

## Goal

Today the report is strictly self-only. v1.3.0 lets an authorized user report on the
hours of **other users who logged time in a project**, select all of them or a subset,
and bill accordingly — without weakening the privacy boundary for everyone else.

It also corrects a misattribution bug that is already shipped in v1.2.0 (see §2.2), found while adversarially reviewing this design.

## Non-goals

- Charts (deferred to v1.4.0).
- All-projects aggregate reporting (unscheduled).
- Editing, correcting, or attributing time on behalf of another user. This is
  read-only reporting.
- Cross-project multi-user rollups. Scope stays one project per report.

---

## 1. Authorization

Self-only is the current privacy boundary. Two layers replace it, evaluated in order.

**Layer 1 (unchanged).** `assertProjectAccess($projectId, $requestingUserId)` still runs
first in `report()`. You cannot touch a project you cannot see. Untouched by this work.

**Layer 2 (new).** Permission to include *other* users:

```php
public function canReportOnOthers(int $projectId, int $userId): bool
```

Returns true when either:

- `$this->userSession->isAdmin()` — an application administrator; or
- `$this->projectUserRoleModel->getUserRole($projectId, $userId) === Role::PROJECT_MANAGER`

Project members and viewers get exactly today's self-only report. `app-manager` is
**not** granted this by itself — it confers project *creation*, not visibility into
existing projects' time. An app-manager who is also a project manager on the project
qualifies through the role check like anyone else.

### Sanitization (fail-closed, and loud)

The requested scope is sanitized inside the model, never trusted from the request. Crucially,
"every participant" reaches the model as **intent** (`$allUsers`), not as a pre-resolved id list:
if the controller resolved it, an unauthorized requester would resolve to `[self]`, the model
would see a request naming nobody else, and the denial would be invisible — you ask for the whole
team and silently get yourself. This is reachable in normal use, because the form toggle is
enabled when *any* accessible project qualifies while permission is per-project.

1. If the requester lacks `canReportOnOthers`, the subject set collapses to
   `[$requestingUserId]` and the report carries `scope_denied => true` — including when the
   request was `scope=all`.
2. The subject set is intersected with the project's **actual participants** for the
   range, so a tampered `user_ids[]` cannot fish for a stranger's hours or probe for
   the existence of a user id.
3. An empty resulting set falls back to `[$requestingUserId]`.

`scope_denied` renders as a visible notice on the results page:

> You do not have permission to include other users in this project. Showing your own hours.

**Why fail-closed *plus* a notice, rather than throwing.** For a billing tool, silently
narrowing a four-person request to one person means under-billing with no signal — the
worst outcome. But a hard `AccessForbiddenException` breaks bookmarked and shared URLs
the moment someone's project role changes, which is a normal occurrence. Narrowing keeps
the page working; the notice makes the narrowing impossible to miss.

---

## 2. Model changes

### 2.1 Lean, bounded, project-wide gathering

The current gathers are user-scoped and already pull *all* of a user's time rows across
every project with no SQL range filter (`getUserQuery($userId)->findAll()`), so there is
no regression to protect here — only a bound to add.

Gathering becomes **project-wide, user-agnostic, and bounded**. The selected set governs
which contributions are *emitted*, never which rows are *seen* — §2.2 explains why that
separation is load-bearing. Nothing uses `TaskFinderModel::getExtendedQuery()`: it carries
seven correlated `COUNT(*)` subqueries and ~35 columns per row for the nine fields this
report needs, and running it unbounded over a long-lived project would be the most
expensive thing the plugin does.

- `gatherSubtaskRows(int $projectId, int $startTs, int $endTs)` — every user, **range-bounded
  in SQL**. It must select `user_id`, which core's `getUserQuery()` does not expose; that,
  plus `getUserQuery()`'s single-user scope and total absence of a range predicate, is why
  this is hand-rolled.
- `gatherSuppressionRows(int $projectId)` — every user, **deliberately unranged**, four small
  columns. Contribution rows must be range-bounded but fallback *eligibility* must not be
  (§2.2), so these are different questions and get different queries.
- `gatherRangeTaskRows(int $projectId, int $startTs, int $endTs)` — tasks **completed in
  range**, every owner, as a single-table lean projection. Owner-agnostic because it feeds
  both the task-level fallback and the completed-task detail, and a selected user can have
  worked on a task somebody else owns. Category names come from a small per-project map
  rather than a join.
- `gatherTaskMeta(int $projectId, array $taskIds)` — reference and title for specific ids.
  A selected user can contribute to a task completed *outside* the range; it still needs a
  real label instead of `#id`. Fetching by id avoids widening the task scan.
- `gatherUntrackedInputs(int $projectId, ?int $ownUserId)` — see §2.6.

### 2.2 Contributions carry attribution

```php
public static function buildContributions(
    array $subtaskRows, array $taskRows, int $startTs, int $endTs,
    int $projectId, array $userIds, array $suppressedTaskIds = []
): array
```

The `int $userId` parameter becomes `array $userIds`; the two identity comparisons
become set membership. Every emitted contribution gains a `user_id` key:

```php
['task_id' => int, 'hours' => float, 'date' => 'Y-m-d', 'user_id' => int]
```

Dedup remains **per task**, not per user — task-level `time_spent` is a single
un-attributable pool, so mixing it with per-user subtask rows for the same task would
double count. Source-2 fallback hours are attributed to the task's `owner_id`.

**The suppression set must be built project-wide, before the user filter.** Kanboard's
`calculateSubtaskTime()` sets `tasks.time_spent` to `SUM(subtasks.time_spent)` across
*every* user. v1.2.0 builds the suppression set only from rows that survive the user
filter, so a task owned by Alice whose time was logged entirely by Bob has no
suppression entry in Alice's report — and the whole cross-user pool is emitted as
Alice's task-level fallback. Verified against shipped v1.2.0: Alice bills 2.0h she
never logged. Multi-user makes it worse still, because *deselecting* Bob would make the
total go **up**.

**And it must be all-time, not merely project-wide.** Restricting suppression to the
report range reopens the same defect across period boundaries: if Bob tracked in February
and the task completed in March, a March report sees no tracking row and emits the entire
historical pool as Alice's March fallback. Eligibility therefore comes from an unranged
query and is passed into `buildContributions()`; contribution *rows* stay range-bounded.

So: suppression is computed from every tracked row in the project, in any period,
regardless of who logged it, and only then is the emission filter applied. This yields
the invariant

> narrowing the selected set can only ever remove hours, never add them

which is worth stating as a testable property, since it is the one a biller would
notice being violated only after sending the invoice.

**Unassigned is not a person.** `tasks.owner_id` and `subtasks.user_id` both default to
`0`. Id `0` never enters a subject set, a participant list, or a contribution, so an
unassigned task's pool is simply omitted rather than billed to a phantom user `#0`.

### 2.3 Participant discovery

```php
public function participants(int $projectId, string $startDate, string $endDate, int $requestingUserId): array
// [userId => ['name' => 'Alice', 'hours' => 12.5]]
```

It calls `assertProjectAccess($projectId, $requestingUserId)` first, like `report()`.

Implemented by gathering the project's rows, running `buildContributions` over every
**positive** user id appearing in them, and grouping the result by `user_id`. Names come
from `userModel`, using the display name with a fallback to username.

**Why reuse the contribution union rather than a lighter dedicated query.** The Users
panel's per-user totals and the report's numbers are then guaranteed to agree by
construction — they are literally the same computation. A separate `SELECT DISTINCT
user_id` style query would be cheaper but could drift from the union's dedup and
round-to-zero rules, producing a participant with 12.5h in the panel and 11.0h in the
report. For a billing tool that divergence is a correctness bug, and the extra cost is
one project-scoped query on a range that is already bounded.

Ordering: descending hours, then name, so the biggest contributors are first.

A caller without `canReportOnOthers` receives only their own entry.

**Computed at most once per request.** `report()` derives participants from rows it has
already gathered and returns them in the aggregate, so the refine panel needs no second
query and the controller must not call `participants()` itself. The public method
remains for external callers and tests.

### 2.4 `report()` signature — backward compatible

```php
public function report(
    int $projectId, string $startDate, string $endDate,
    string $granularity, bool $includeDetail, int $userId,
    ?array $subjectUserIds = null
): array
```

`$userId` keeps its position and meaning: the **requesting** user, used for both
authorization layers. The subject set arrives as an optional seventh parameter, where
`null` means self-only.

This is load-bearing. TimeInvoice v1.0.0 calls this positionally with six arguments
(`Controller/InvoiceController.php`):

```php
$this->timeReportModel->report($projectId, $start, $end, $granularity, true, $userId);
```

That call must keep working untouched, producing byte-identical output. Any change to
the first six parameters is out of bounds.

New keys on the returned aggregate:

```php
'subject_user_ids' => [int, ...],   // resolved, sanitized set actually reported on
'users'            => [userId => ['name' => string, 'hours' => float], ...],
'participants'     => [userId => ['name' => string, 'hours' => float], ...],
'multi_user'       => bool,          // count(subject_user_ids) > 1
'can_report_others'=> bool,          // drives UI affordances
'scope_denied'     => bool,
```

`participants` spans users not currently selected and drives the refine panel. It is
returned here rather than fetched by the controller so discovery happens once.

Each detail row gains `assignee`. A completed-in-range task belongs in the detail when
a selected user owns it **or** a selected user contributed hours to it — without the
second half, a user's work on someone else's task would be counted in the total but
missing from the artifact that justifies the invoice.

### 2.5 Bucketing by user

`bucket()` gains a `user` case and a `$userMeta` parameter for names:

```php
public static function bucket(
    array $contributions, string $granularity,
    array $taskMeta = [], array $userMeta = []
): array
```

For `granularity === 'user'`: key is the user id, label is the display name, sort is the
name. `task_count` is the number of distinct tasks that user touched — meaningful, so
the Tasks column is shown for this granularity (it behaves like day/week, not like task).

The existing four granularities are unchanged and **pool** the selected users' hours:
one client-billable figure per day, week, task, or total.

`withWeekday()` is safe on user-name labels — its ISO-date regex guard passes anything
non-matching through unchanged.

### 2.6 The untracked-time warning becomes selection-invariant

This corrects a second latent defect. `subtasks.user_id` is the **assignee** (defaulting
to 0); `subtask_time_tracking.user_id` is the **logger**. They are different people, and
`subtasks.time_spent` is itself a cross-user pool. v1.2.0 compares the whole recorded pool
against a *single user's* tracked hours, so whenever the logger differs from the assignee
the difference is reported as untracked time that was in fact tracked.

Multi-user would have made this violate the §2.2 invariant outright: for an Alice-assigned
subtask whose two hours Bob logged, `[Alice, Bob]` reports zero untracked hours while
`[Alice]` reports two — the warning *grows* as users are removed.

Two rules fix it:

1. **The tracked side is never filtered by user** (nor by range). A pool must be offset by
   the whole pool.
2. **The recorded side is scoped by permission, not by the subject set** — project-wide
   when the requester may see others' time, their own subtasks otherwise.

The warning therefore cannot move when the selection moves, and the false-warning case is
fixed for single-user reports too.

---

## 3. Controller and UI

### 3.1 Form (`Template/report/form.php`)

One new control, rendered **only** when the requester is an app admin or holds
`project-manager` on at least one accessible project:

```
Include:  (•) Just me     ( ) All users
```

Field name `scope`, values `self` | `all`. Default `self`.

Granularity gains a fifth option, **By user** (`user`).

Everything else on the form is unchanged. Defaulting to `self` means v1.2.0 behavior is
identical on upgrade: nobody's existing report silently starts including colleagues.

### 3.2 Results page — the Users panel

Rendered when `can_report_others` is true and the project has more than one participant
in range. New partial `Template/report/_users.php`:

- One checkbox per participant, labelled `Name — 12.50h`, checked when that user is in
  `subject_user_ids`.
- Wrapped in a CSRF-protected POST to `generate`, carrying hidden `project_id`,
  `start_date`, `end_date`, `granularity`, `include_detail`, plus `user_ids[]`.
- Submit button: **Update report**.

No JavaScript, no AJAX, no new route. Refining the set is one round trip.

### 3.3 CSV export must carry the scope

The existing CSV form in `show.php` posts a fixed set of hidden fields. It gains
`user_ids[]` hidden inputs for the resolved subject set. Without this the exported CSV
would silently cover a different set of people than the screen — a correctness bug in a
billing artifact, not a cosmetic one.

**And `exportCsv()` refuses outright when `scope_denied` is true**, redirecting to the
form instead of streaming. The fail-closed-plus-notice design of §1 depends on the notice
being visible, and a downloaded file carries none: a manager demoted after loading the
page would otherwise export a valid-looking team invoice containing only themselves.
On-screen, narrowing plus a warning is the right trade; in a file, refusal is.

**That guard alone is not sufficient**, which is the subtle part. A denied report resolves
to `[self]`, so a form posting the sanitized set back would re-enter as an ordinary
self-only request, sanitize to `scope_denied === false`, and sail past the guard — the
defect restored through the normal button. So the results page renders **no export action
at all** on a denied result; the guard remains for direct POSTs. **Copy as Markdown**
stays, because it renders inline directly beneath the notice, where the narrowing is
visible at the moment of copying.

### 3.4 Request handling

`buildReportFromRequest()` forwards intent, and resolves nothing:

- `user_ids[]` present → cast to int, passed as the explicit set.
- else `scope === 'all'` → `$allUsers = true`, with no id list.
- else → `null`, `false` (self-only).

Both are passed as the seventh and eighth arguments; the model sanitizes. The controller
never decides authorization, and never expands `scope=all` itself — see §1.

### 3.5 The ≡ menu quick report

`view()` stays **self-only**. It is a one-click convenience with fixed defaults; silently
surfacing colleagues' hours from a menu click is exactly the surprise §1 exists to
prevent. Users wanting a team report go through the form.

---

## 4. Output compatibility

**The rule for this entire section: single-user output keeps its v1.2.0 *format*
byte-for-byte** — same columns, order, separators, headers and line endings. This protects
TimeInvoice, saved Markdown, and existing CSV consumers.

*Format* and *numbers* are separate promises, and only the first is preserved. The §2.2
attribution fix deliberately changes totals wherever the shipped bug was inflating them,
and those corrected totals flow through to TimeInvoice amounts. Compatibility is therefore
asserted two ways: a golden-output test pinning the exact rendering of a fixture whose
hours are supplied directly (so the fix cannot move them), plus explicitly documented
numeric changes for affected data. No fixture may pin the old, wrong totals.

When `multi_user` is true:

- **Screen** (`_detail.php`): detail rows gain an **Assignee** column.
- **Markdown** (`toMarkdown`): the completed-tasks table gains an `Assignee` column;
  `breakdownHeader()` gains `'user' => 'User'`.
- **CSV** (`toCsv`): the detail header gains `Assignee`. The breakdown header is already
  the uniform `Label, Hours, Tasks` and needs no change for the `user` granularity.

When `multi_user` is false none of these appear, and every existing format string,
column order, and separator is untouched.

The detail set gains an `assignee` key (display name of the task's `owner_id`).
v1.1.0's weekday prefixes and click-to-copy apply to the new rows and columns unchanged.

---

## 5. Testing

The existing 73 tests must stay green, and the single-user byte-identity rule gets its
own explicit assertions.

**Pure statics (no DB), as today:**

- `buildContributions` with a multi-user set: attribution correctness, per-task dedup
  across users, round-to-zero entries still skipped.
- `bucket` with `user` granularity: labels, sort order, distinct `task_count`.
- Participant grouping and ordering.

**Authorization (the security surface — most important):**

- Project member requesting `user_ids[]` → narrowed to self, `scope_denied === true`.
- Project viewer → same.
- Project manager → full requested set honored.
- App admin → honored on a project where they hold no explicit role.
- Tampered `user_ids[]` naming a non-participant → that id dropped, not reported on.
- Empty resolved set → falls back to self, never to "everyone".

**Attribution (the correctness surface):**

- An excluded user's tracked time is never rebilled as the task owner's fallback.
- Tracked time *outside* the report range still disqualifies the fallback inside it.
- Narrowing the selected set never increases the total — nor moves the untracked warning.
- A selected user's work on a task owned by someone outside the set keeps its task
  label and appears in the completed-task detail.
- An unassigned task never produces a participant `#0` or contributes hours.
- A tracking row that rounds to 0.00h neither counts as work nor suppresses a fallback.

**Compatibility:**

- Six-argument `report()` call (the TimeInvoice call shape) stays self-only and renders
  the v1.2.0 Markdown and CSV shape for the same fixture. Note that *totals* may
  legitimately differ from v1.2.0 wherever the misattribution bug was inflating them;
  the fixture must not encode the old, wrong numbers.

**Live verification** on the `:8081` dev stack with a second user logging time in the
demo project, exercising manager, member, and admin paths in the browser.

---

## 6. Version and release

`plugin.json`, `Plugin.php::getPluginVersion()`, and the three hard-coded version
assertions (`PluginMetaTest`, `PluginTest` ×2) move to `1.3.0` together. CHANGELOG entry
added. Released by tag `v1.3.0`, then the `kanboard-modmenu-directory` entry is bumped.
The AiConnector `recommends.min_version` stays at `1.0.0`.

Buildless throughout: no new dependency, no build step, no inline `<script>` or `on*=`
handlers, and no new JS at all.
