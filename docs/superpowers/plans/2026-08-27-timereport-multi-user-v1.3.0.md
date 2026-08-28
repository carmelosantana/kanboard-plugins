# TimeReport v1.3.0 — Multi-user tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authorized user report on other users' hours in a project — all of them or a subset — without weakening the self-only privacy boundary for everyone else.

**Architecture:** Authorization is a two-layer gate in the model (existing project access, then a new project-manager/admin check), and both the subject set *and the original scope intent* are sanitized server-side and fail-closed. Rows are gathered **project-wide once per request**; the selected user set governs only which contributions are *emitted*, never which rows are *seen* — so dedup suppression, task metadata and participant discovery all stay correct regardless of who is selected. The UI adds a scope toggle on the form and a checkbox panel on the results page — no JS, no AJAX, no new route.

**Tech Stack:** PHP 8.4, Kanboard plugin API, PHPUnit. Buildless: plain PHP templates, no bundler, no new dependency.

**Spec:** `docs/superpowers/specs/2026-08-27-timereport-multi-user-v1.3.0-design.md`

## Global Constraints

- **Buildless.** No build step, no new dependency, no new JS file, no inline `<script>` or `on*=` attributes (CSP). What is committed is what ships.
- **PHP `>=8.4`**, Kanboard `>=1.2.47`.
- **`report()`'s first six parameters MUST NOT change.** TimeInvoice v1.0.0 calls it positionally with six arguments: `report($projectId, $start, $end, $granularity, true, $userId)`. The subject set is an optional **seventh** parameter defaulting to `null`.
- **Single-user output keeps its v1.2.0 *format* byte-for-byte** — same columns, order, separators and headers; new columns appear only when more than one user is in scope. **Totals are a separate matter**: the attribution fix in Task 2 legitimately *changes numbers* wherever the shipped bug was inflating them. Compatibility is therefore asserted as: byte-identical rendering for a fixture the bug never touched, plus explicitly documented numeric changes for one that it did. Do not write a fixture that pins the old, wrong totals.
- **Authorization:** app admin (`userSession->isAdmin()`) OR `project-manager` on that project. `app-manager` is NOT granted this by itself.
- **Sanitization is fail-closed and happens in the model**, never in the controller. Denied requests narrow to self and set `scope_denied => true`.
- Helper is accessed as a **property**: `$this->helper->timeReport->method()`.
- Template partial names are `'TimeReport:report/_name'`.
- All 73 existing tests must stay green (232 assertions).
- **Work on a feature branch**, never directly on `main`.
- **Task-level `time_spent` is an un-attributable, all-time pool.** Kanboard's `calculateSubtaskTime()` sets `tasks.time_spent` to `SUM(subtasks.time_spent)` across *every* user and *every* period. It may therefore only ever be emitted as a fallback for a task that has **no tracked subtask time from anyone, ever** — so the suppression set must be built project-wide **and all-time**, never from the selected users' rows and never from the report range alone.
- **Only positive user ids are real.** `tasks.owner_id` and `subtasks.user_id` both default to `0` for unassigned. Id `0` must never enter a subject set, a participant list, or a contribution.
- **The untracked-time warning is selection-invariant.** It must not change when the subject set changes. Its recorded side is scoped by *permission*, and its tracked side is never filtered by user — see Task 4.
- **Selecting fewer users must never increase any total.** This is the invariant that the pre-existing misattribution bug violates; Task 2 fixes it.
- **`participants()` is computed at most once per request.** `report()` derives it from rows it has already gathered and returns it in the aggregate; the controller must not call it again.

### Test command

`./testing/run-plugin-tests.sh TimeReport` can abort on a pre-existing `TimeInvoice` symlink collision in the shared harness. Use the direct runner instead, from the monorepo root:

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Single file: append the file path. Single test: add `--filter testName`.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `Model/TimeReportModel.php` | authorization, sanitization, gathering, union, bucketing, report aggregate | 1-5 |
| `Helper/TimeReportHelper.php` | Markdown + CSV rendering of the aggregate | 6 |
| `Controller/TimeReportController.php` | forwards scope intent, refuses narrowed exports | 7 |
| `Template/report/form.php` | scope toggle, `By user` granularity option | 8 |
| `Template/report/_users.php` | **NEW** — participant checkbox panel | 8 |
| `Template/report/show.php` | panel + notice wiring, CSV hidden `user_ids[]` | 8 |
| `Template/report/_detail.php` | Assignee column | 8 |
| `Template/report/_breakdown.php` | `User` header for the new granularity | 8 |
| `plugin.json`, `Plugin.php`, `CHANGELOG.md` | version + release metadata | 9 |

---

### Task 1: Authorization primitives

**Files:**
- Modify: `Model/TimeReportModel.php`
- Test: `Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `canReportOnOthers(int $projectId, int $userId): bool`
  - `static sanitizeSubjectUserIds(?array $requested, bool $allUsers, int $requestingUserId, array $participantIds, bool $canReportOnOthers): array` returning `[list<int> $subjectIds, bool $scopeDenied]`
- Task 5 consumes both.

**Why `$allUsers` is a separate parameter.** "Every participant" must reach the model as *intent*, not as a pre-resolved id list. If the controller resolved `scope=all` into ids itself, an unauthorized requester would resolve to `[self]`, the model would see a request naming nobody else, and the denial would be invisible — the user asks for the whole team and silently gets themselves. Keeping the intent intact is what makes `scope_denied` reachable.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportModelTest.php`:

```php
    // --- sanitizeSubjectUserIds (pure) ---

    public function testNullRequestMeansSelfOnlyAndIsNotDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, false, 7, [7, 8, 9], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testWithoutPermissionRequestingOthersNarrowsToSelfAndFlagsDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7, 8], false, 7, [7, 8], false);
        $this->assertSame([7], $ids);
        $this->assertTrue($denied, 'asking for another user without permission must be visible');
    }

    /** The regression for the "scope=all silently narrows" hole. */
    public function testWithoutPermissionScopeAllIsDeniedNotSilentlySelfOnly(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, true, 7, [], false);
        $this->assertSame([7], $ids);
        $this->assertTrue($denied, 'asking for ALL users without permission must be flagged, not silently narrowed');
    }

    public function testWithoutPermissionRequestingOnlySelfIsNotDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7], false, 7, [7], false);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testWithPermissionScopeAllResolvesToEveryParticipant(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, true, 7, [9, 7, 8], true);
        $this->assertSame([7, 8, 9], $ids);
        $this->assertFalse($denied);
    }

    public function testWithPermissionRequestedSetIsHonoredAndSorted(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([9, 7], false, 7, [7, 8, 9], true);
        $this->assertSame([7, 9], $ids);
        $this->assertFalse($denied);
    }

    public function testNonParticipantIdsAreDropped(): void
    {
        // 42 never logged time here — must not be reported on, and must not error.
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7, 42], false, 7, [7, 8], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testEmptyResolvedSetFallsBackToSelfNotEveryone(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([42], false, 7, [7, 8], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testDuplicateAndStringIdsAreNormalized(): void
    {
        [$ids] = TimeReportModel::sanitizeSubjectUserIds(['8', 8, '7'], false, 7, [7, 8], true);
        $this->assertSame([7, 8], $ids);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

Expected: FAIL — `Call to undefined method ...::sanitizeSubjectUserIds()`.

- [ ] **Step 3: Implement**

Add the import at the top of `Model/TimeReportModel.php`, after the existing `use` lines:

```php
use Kanboard\Core\Security\Role;
```

Add both methods to the class, directly above `assertProjectAccess()`:

```php
    /**
     * May $userId include OTHER users' hours in reports for $projectId?
     *
     * App administrators always may. Otherwise the requester must hold the
     * project-manager role on this specific project. Note that app-manager is
     * deliberately NOT sufficient: it confers project creation, not visibility
     * into an existing project's time.
     */
    public function canReportOnOthers(int $projectId, int $userId): bool
    {
        if ($this->userSession->isAdmin()) {
            return true;
        }

        return $this->projectUserRoleModel->getUserRole($projectId, $userId) === Role::PROJECT_MANAGER;
    }

    /**
     * Resolve the requested scope into a subject set that is safe to report on.
     *
     * $allUsers carries the "every participant" INTENT rather than a pre-resolved id
     * list. That distinction is what makes denial visible: an unauthorized request for
     * the whole team must raise $scopeDenied, not quietly collapse to a set of one that
     * looks like it was never asking for anyone else.
     *
     * Fail-closed: without permission the set collapses to the requester alone and
     * $scopeDenied is raised so the UI can say so out loud (silently narrowing would
     * under-bill with no signal). Requested ids are also intersected with actual
     * participants, so a tampered user_ids[] can neither read a stranger's hours nor
     * probe for the existence of a user id.
     *
     * @param  ?array $requested       Explicit ids from the request; null when none given.
     * @param  bool   $allUsers        The scope=all intent.
     * @param  array  $participantIds  Ids with hours in this project + range.
     * @return array{0: list<int>, 1: bool}  [subject ids, scope denied]
     */
    public static function sanitizeSubjectUserIds(?array $requested, bool $allUsers, int $requestingUserId, array $participantIds, bool $canReportOnOthers): array
    {
        // 0 is Kanboard's "unassigned" sentinel, never a person to report on.
        $requestedIds = $requested === null
            ? null
            : array_values(array_unique(array_filter(array_map('intval', $requested), static fn ($id) => $id > 0)));

        $wantsOthers = $allUsers
            || ($requestedIds !== null && $requestedIds !== [] && array_diff($requestedIds, [$requestingUserId]) !== []);

        if (! $canReportOnOthers) {
            return [[$requestingUserId], $wantsOthers];
        }

        $participants = array_values(array_unique(array_map('intval', $participantIds)));

        if ($allUsers) {
            $resolved = $participants;
        } elseif ($requestedIds === null) {
            return [[$requestingUserId], false];
        } else {
            $resolved = array_values(array_intersect($requestedIds, $participants));
        }

        if ($resolved === []) {
            return [[$requestingUserId], false];
        }

        sort($resolved);

        return [$resolved, false];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Same command as Step 2. Expected: PASS, and no previously-passing test regresses.

- [ ] **Step 5: Commit**

```bash
git add Model/TimeReportModel.php Test/TimeReportModelTest.php
git commit -m "feat: authorization gate and fail-closed scope sanitization"
```

---


### Task 2: All-time dedup suppression and multi-user attribution

**Files:**
- Modify: `Model/TimeReportModel.php` (`buildContributions`; add `suppressedTaskIdsFromRows`)
- Test: `Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `static suppressedTaskIdsFromRows(array $rows): array` → `[taskId => true]` for every task with real tracked time, computed from **all-time** lean tracking rows
  - `static buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, array $userIds, array $suppressedTaskIds = []): array`
- Tasks 3, 4 and 5 consume both.

**This task fixes a bug that is already shipped in v1.2.0.** `tasks.time_spent` is set by Kanboard's `calculateSubtaskTime()` to `SUM(subtasks.time_spent)` across every user *and every period*. Today the dedup suppression set is built only from rows that survive the user filter, so a task owned by Alice whose time was logged entirely by Bob has no suppression entry in Alice's report — and the whole cross-user pool is emitted as Alice's task-level fallback. Verified against v1.2.0: Alice bills 2.0h she never logged.

**Suppression must be all-time, not merely project-wide.** Restricting it to the report range reopens the same defect across period boundaries: if Bob tracked in February and the task completed in March, a March report sees no tracking row and emits the entire historical pool as Alice's March fallback. Suppression eligibility is therefore supplied by the caller from an unranged query (Task 4) and passed in; contribution *rows* stay range-bounded.

The user set governs only which contributions are **emitted**, never which rows are **seen**.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportModelTest.php`:

```php
    // --- multi-user contributions ---

    public function testContributionsCarryUserAttribution(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1]);

        $this->assertSame(1, $contribs[0]['user_id']);
    }

    public function testTwoUsersBothContributeWhenBothSelected(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-11 09:00:00'), 'end' => $this->ts('2026-03-11 12:00:00'), 'time_spent' => 3.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(2, $contribs);
        $this->assertSame(5.0, array_sum(array_column($contribs, 'hours')));
    }

    public function testUnselectedUsersAreExcluded(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-11 09:00:00'), 'end' => $this->ts('2026-03-11 12:00:00'), 'time_spent' => 3.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1]);

        $this->assertCount(1, $contribs);
        $this->assertSame(1, $contribs[0]['user_id']);
    }

    /**
     * REGRESSION: an excluded user's tracked time must never resurface as the task
     * owner's task-level fallback. tasks.time_spent is SUM(subtasks.time_spent) over
     * ALL users, so suppression must ignore the user filter.
     */
    public function testExcludedLoggersTimeIsNotRebilledToTheTaskOwner(): void
    {
        // Bob (2) logged every hour. Alice (1) merely owns the task.
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertSame([], $contribs, "Alice logged nothing; Bob's hours must not be billed to her");
    }

    /**
     * REGRESSION: the same defect across a period boundary. tasks.time_spent is an
     * ALL-TIME pool, so tracked time outside the report range still disqualifies the
     * task-level fallback inside it.
     */
    public function testTrackedTimeOutsideTheRangeStillSuppressesTheFallback(): void
    {
        // Bob tracked in February; the Alice-owned task completed in March.
        $februaryRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-02-10 09:00:00'), 'end' => $this->ts('2026-02-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        $suppressed = TimeReportModel::suppressedTaskIdsFromRows($februaryRows);

        // The March query returns no tracking rows at all — suppression must come from
        // the all-time set, not from the in-range rows.
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1], $suppressed);

        $this->assertSame([], $contribs, "February's tracked time must still disqualify March's fallback");
    }

    public function testSuppressionIgnoresRowsThatRoundToZero(): void
    {
        // A two-second timer toggle is not work and must not suppress a real fallback.
        $rows = [
            ['task_id' => 10, 'start' => $this->ts('2026-02-10 09:00:00'), 'end' => $this->ts('2026-02-10 09:00:02'), 'time_spent' => 0.0],
        ];

        $this->assertSame([], TimeReportModel::suppressedTaskIdsFromRows($rows));
    }

    /** Deselecting a user must only ever REMOVE hours. */
    public function testNarrowingTheUserSetNeverIncreasesTheTotal(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$both] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);
        [$one]  = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertLessThanOrEqual(
            array_sum(array_column($both, 'hours')),
            array_sum(array_column($one, 'hours'))
        );
    }

    /** Unassigned tasks (owner_id 0) carry no attributable time. */
    public function testUnassignedTaskFallbackIsNeverEmitted(): void
    {
        $taskRows = [
            ['id' => 77, 'project_id' => 5, 'owner_id' => 0, 'time_spent' => 9.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [0, 1]);

        $this->assertSame([], $contribs, 'user 0 is "unassigned", not a person to bill');
    }

    public function testTaskLevelFallbackIsSuppressedByAnyUsersSubtaskTime(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 8.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(1, $contribs);
        $this->assertSame(2.0, $contribs[0]['hours']);
        $this->assertSame(2, $contribs[0]['user_id']);
    }

    /** With no tracked time at all, the task-level pool is attributed to the owner. */
    public function testTaskLevelFallbackIsAttributedToTheTaskOwner(): void
    {
        $taskRows = [
            ['id' => 11, 'project_id' => 5, 'owner_id' => 2, 'time_spent' => 4.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(1, $contribs);
        $this->assertSame(2, $contribs[0]['user_id']);
        $this->assertSame(4.0, $contribs[0]['hours']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

Expected: FAIL. `testExcludedLoggersTimeIsNotRebilledToTheTaskOwner` must fail with one 2.0h contribution — that failure IS the shipped bug.

- [ ] **Step 3: Implement**

Add the suppression helper above `buildContributions()`:

```php
    /**
     * Task ids disqualified from the task-level fallback, from lean tracking rows.
     *
     * Fed by an UNRANGED query: tasks.time_spent is an all-time pool, so tracked time
     * from any period and any user disqualifies the fallback in every report window.
     * Applies the same round-to-2dp rule as the contribution math, so a two-second
     * timer toggle neither counts as work nor hides a real fallback.
     *
     * @param  list<array{task_id:int,start:int,end:int,time_spent:float}> $rows
     * @return array<int,bool>
     */
    public static function suppressedTaskIdsFromRows(array $rows): array
    {
        $suppressed = [];
        foreach ($rows as $row) {
            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $start = (int) $row['start'];
                $end   = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }
            if (round($hours, 2) > 0) {
                $suppressed[(int) $row['task_id']] = true;
            }
        }

        return $suppressed;
    }
```

Replace `buildContributions`:

```php
    /**
     * Build the deduped flat contribution list for the SELECTED users.
     *
     * $subtaskRows and $taskRows are PROJECT-WIDE (every user). $userIds governs only
     * which contributions are emitted — never which rows are seen. That separation is
     * load-bearing: tasks.time_spent is SUM(subtasks.time_spent) across all users and
     * all time, so building suppression from the selected users' in-range rows alone
     * lets an excluded user's time resurface as the owner's fallback — which would make
     * narrowing the set INCREASE the total.
     *
     * $suppressedTaskIds supplies all-time eligibility from an unranged query; rows
     * seen in range are merged into it. Pass it whenever the report window could
     * exclude tracking that still disqualifies a fallback.
     *
     * @return array{0: list<array{task_id:int,hours:float,date:string,user_id:int}>, 1: array<int,bool>}
     */
    public static function buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, array $userIds, array $suppressedTaskIds = []): array
    {
        $contributions = [];
        $suppressed    = $suppressedTaskIds;
        $selected      = array_flip(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0));

        foreach ($subtaskRows as $row) {
            if ((int) $row['project_id'] !== $projectId) {
                continue;
            }
            $start = (int) $row['start'];
            if ($start < $startTs || $start > $endTs) {
                continue;
            }

            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $end = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }

            // An entry that rounds to 0.00h at the report's own precision represents no
            // logged work — a still-running timer, or an instant start/stop toggle.
            if (round($hours, 2) <= 0) {
                continue;
            }

            $taskId = (int) $row['task_id'];

            // Suppression deliberately precedes the user filter.
            $suppressed[$taskId] = true;

            if (! isset($selected[(int) $row['user_id']])) {
                continue;
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => (float) $hours,
                'date'    => date('Y-m-d', $start),
                'user_id' => (int) $row['user_id'],
            ];
        }

        foreach ($taskRows as $task) {
            $ownerId = (int) $task['owner_id'];
            // owner_id 0 means UNASSIGNED, not a person: its pool is unattributable.
            if ((int) $task['project_id'] !== $projectId || $ownerId <= 0 || ! isset($selected[$ownerId])) {
                continue;
            }
            $timeSpent = (float) $task['time_spent'];
            if ($timeSpent <= 0) {
                continue;
            }
            $completed = (int) $task['date_completed'];
            if ($completed < $startTs || $completed > $endTs) {
                continue;
            }
            $taskId = (int) $task['id'];
            if (isset($suppressed[$taskId])) {
                continue; // dedup: already represented by tracked subtask time
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => $timeSpent,
                'date'    => date('Y-m-d', $completed),
                'user_id' => $ownerId,
            ];
        }

        return [$contributions, $suppressed];
    }
```

- [ ] **Step 4: Update the existing call sites**

In `Model/TimeReportModel.php::report()`, pass `[$userId]` for now (Task 5 replaces this properly):

```php
        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, [$userId]);
```

In `Test/TimeReportModelTest.php`, change every existing `buildContributions(...)` call's sixth argument from a bare int to its single-element array (`1` becomes `[1]`). Leave all assertions untouched.

**One pre-existing test may now legitimately change meaning.** If a test asserted that a task-level fallback appears while another user's tracked time exists on the same task, it was encoding the bug. Do not silently rewrite such an assertion: report it in your task report with the before/after so the reviewer can confirm it is the bug being corrected and not a regression being masked.

- [ ] **Step 5: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS, all green.

- [ ] **Step 6: Commit**

```bash
git add Model/TimeReportModel.php Test/TimeReportModelTest.php
git commit -m "fix: all-time project-wide dedup suppression so no user's time is rebilled"
```

---


### Task 3: `user` granularity in `bucket()`

**Files:**
- Modify: `Model/TimeReportModel.php` (`bucket`)
- Test: `Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: contributions carrying `user_id` (Task 2).
- Produces: `static bucket(array $contributions, string $granularity, array $taskMeta = [], array $userMeta = []): array`. `$userMeta` is `[userId => ['name' => string]]`. Task 5 passes it.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportModelTest.php`:

```php
    // --- user granularity ---

    private function userContribs(): array
    {
        return [
            ['task_id' => 10, 'hours' => 2.0, 'date' => '2026-03-10', 'user_id' => 1],
            ['task_id' => 11, 'hours' => 3.0, 'date' => '2026-03-11', 'user_id' => 1],
            ['task_id' => 10, 'hours' => 4.0, 'date' => '2026-03-12', 'user_id' => 2],
        ];
    }

    public function testUserGranularityGroupsByPersonWithNames(): void
    {
        $meta = [1 => ['name' => 'Alice'], 2 => ['name' => 'Bob']];

        $out = TimeReportModel::bucket($this->userContribs(), 'user', [], $meta);

        $this->assertSame(9.0, $out['total_hours']);
        $this->assertCount(2, $out['breakdown']);
        $this->assertSame('Alice', $out['breakdown'][0]['label']);
        $this->assertSame(5.0, $out['breakdown'][0]['hours']);
        $this->assertSame('Bob', $out['breakdown'][1]['label']);
        $this->assertSame(4.0, $out['breakdown'][1]['hours']);
    }

    public function testUserGranularityCountsDistinctTasksPerUser(): void
    {
        $meta = [1 => ['name' => 'Alice'], 2 => ['name' => 'Bob']];

        $out = TimeReportModel::bucket($this->userContribs(), 'user', [], $meta);

        $this->assertSame(2, $out['breakdown'][0]['task_count'], 'Alice touched tasks 10 and 11');
        $this->assertSame(1, $out['breakdown'][1]['task_count']);
    }

    public function testUserGranularityFallsBackToHashIdWhenNameUnknown(): void
    {
        $out = TimeReportModel::bucket($this->userContribs(), 'user', [], []);

        $this->assertSame('#1', $out['breakdown'][0]['label']);
        $this->assertSame('#2', $out['breakdown'][1]['label']);
    }

    public function testExistingGranularitiesPoolAcrossUsers(): void
    {
        $contribs = [
            ['task_id' => 10, 'hours' => 2.0, 'date' => '2026-03-10', 'user_id' => 1],
            ['task_id' => 11, 'hours' => 3.0, 'date' => '2026-03-10', 'user_id' => 2],
        ];

        $out = TimeReportModel::bucket($contribs, 'day');

        $this->assertCount(1, $out['breakdown'], 'one billable row per day regardless of who logged it');
        $this->assertSame(5.0, $out['breakdown'][0]['hours']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

Expected: FAIL — `user` falls through to the `day` default, so labels are dates.

- [ ] **Step 3: Implement**

Change the `bucket()` signature in `Model/TimeReportModel.php`:

```php
    public static function bucket(array $contributions, string $granularity, array $taskMeta = [], array $userMeta = []): array
```

Add a `user` case to the `switch`, immediately before `case 'total':`:

```php
                case 'user':
                    $uid   = (int) ($c['user_id'] ?? 0);
                    $key   = 'u' . $uid;
                    $label = (string) ($userMeta[$uid]['name'] ?? ('#' . $uid));
                    $sort  = $label;
                    break;
```

- [ ] **Step 4: Run the tests to verify they pass**

Same command as Step 2. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Model/TimeReportModel.php Test/TimeReportModelTest.php
git commit -m "feat: add 'user' granularity to bucket()"
```

---

### Task 4: Lean bounded gathering and participant discovery

**Files:**
- Modify: `Model/TimeReportModel.php` (replace `gatherSubtaskRows`, `gatherCompletedTaskRows`, `gatherUntrackedInputs`; add `gatherSuppressionRows`, `gatherRangeTaskRows`, `gatherTaskMeta`, `participants`, `buildParticipants`, `allUserIds`, `hoursByUser`, `userNames`)
- Test: `Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: `buildContributions` and `suppressedTaskIdsFromRows` (Task 2).
- Produces:
  - `gatherSubtaskRows(int $projectId, int $startTs, int $endTs): array` — every user, **range-bounded in SQL**; `{task_id, project_id, user_id, start, end, time_spent}`
  - `gatherSuppressionRows(int $projectId): array` — every user, **unranged**, lean; `{task_id, start, end, time_spent}`
  - `gatherRangeTaskRows(int $projectId, int $startTs, int $endTs): array` — tasks **completed in range**, every owner, lean projection
  - `gatherTaskMeta(int $projectId, array $taskIds): array` → `[taskId => ['reference' => string, 'title' => string]]`
  - `gatherUntrackedInputs(int $projectId, ?int $ownUserId): array` — `null` means project-wide
  - `static allUserIds(array $subtaskRows, array $taskRows): array` — positive ids only
  - `static hoursByUser(array $contributions): array` → `[userId => float]`
  - `participants(int $projectId, string $startDate, string $endDate, int $requestingUserId): array`
  - `userNames(array $userIds): array`
- Task 5 consumes all of these.

**Three deliberate departures from the obvious implementation.**

*Nothing uses `getExtendedQuery()`.* It carries seven correlated `COUNT(*)` subqueries and ~35 columns per row, of which this report uses nine. Running it unbounded over a long-lived project is the single most expensive thing the plugin could do. Every query here is a lean projection, and the task query is bounded by completion date.

*Suppression is gathered separately and unranged.* Contribution rows must be range-bounded, but fallback eligibility must not be — see Task 2. These are different questions, so they get different queries. The suppression query selects four small columns from one indexed table.

*Metadata is fetched by id, not by scanning.* A selected user can contribute to a task completed outside the range; that task still needs its reference and title for the `task` granularity label. Rather than widen the task scan, `gatherTaskMeta()` fetches only the ids the contributions actually reference.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportModelTest.php`:

```php
    public function testParticipantsReportsEveryUserWithHoursInRange(): void
    {
        $model = new TimeReportModel($this->container);

        $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);
        $bob = (int) $this->container['userModel']->create(['username' => 'bob', 'name' => 'Bob Jones', 'password' => 'xxxxxxxx']);
        $this->container['projectUserRoleModel']->addUser($projectId, $bob, \Kanboard\Core\Security\Role::PROJECT_MEMBER);

        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Ship it', 'project_id' => $projectId, 'owner_id' => $bob, 'time_spent' => 4.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);

        $found = $model->participants($projectId, date('Y-m-01'), date('Y-m-d'), 1);

        $this->assertArrayHasKey($bob, $found, 'Bob logged time and must be discoverable');
        $this->assertSame('Bob Jones', $found[$bob]['name']);
        $this->assertSame(4.0, $found[$bob]['hours']);
    }

    public function testParticipantsExcludesProjectMembersWithNoHours(): void
    {
        $model = new TimeReportModel($this->container);

        $projectId = (int) $this->container['projectModel']->create(['name' => 'Quiet'], 1, true);
        $carol = (int) $this->container['userModel']->create(['username' => 'carol', 'name' => 'Carol', 'password' => 'xxxxxxxx']);
        $this->container['projectUserRoleModel']->addUser($projectId, $carol, \Kanboard\Core\Security\Role::PROJECT_MEMBER);

        $found = $model->participants($projectId, date('Y-m-01'), date('Y-m-d'), 1);

        $this->assertArrayNotHasKey($carol, $found, 'membership alone is not participation');
    }

    /** Unassigned tasks must not manifest as a participant "#0". */
    public function testParticipantsNeverIncludesUserZero(): void
    {
        $model = new TimeReportModel($this->container);

        $projectId = (int) $this->container['projectModel']->create(['name' => 'Orphan'], 1, true);
        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Nobody owns this', 'project_id' => $projectId, 'time_spent' => 9.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);

        $found = $model->participants($projectId, date('Y-m-01'), date('Y-m-d'), 1);

        $this->assertArrayNotHasKey(0, $found, 'owner_id 0 is "unassigned", not a billable person');
    }

    public function testAllUserIdsDropsNonPositiveIds(): void
    {
        $subtaskRows = [['user_id' => 3], ['user_id' => 0]];
        $taskRows    = [['owner_id' => 0], ['owner_id' => 5]];

        $ids = TimeReportModel::allUserIds($subtaskRows, $taskRows);
        sort($ids);

        $this->assertSame([3, 5], $ids);
    }

    public function testHoursByUserGroupsContributions(): void
    {
        $contribs = [
            ['task_id' => 1, 'hours' => 2.0, 'date' => '2026-03-10', 'user_id' => 1],
            ['task_id' => 2, 'hours' => 3.0, 'date' => '2026-03-11', 'user_id' => 1],
            ['task_id' => 3, 'hours' => 4.0, 'date' => '2026-03-12', 'user_id' => 2],
        ];

        $this->assertSame([1 => 5.0, 2 => 4.0], TimeReportModel::hoursByUser($contribs));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

Expected: FAIL — `Call to undefined method ...::participants()`.

- [ ] **Step 3: Replace the gather methods**

Replace `gatherSubtaskRows()`:

```php
    /**
     * In-range subtask time rows for the whole project, every user.
     *
     * Hand-rolled rather than SubtaskTimeTrackingModel::getUserQuery(), which is scoped
     * to one user, omits user_id, and applies no range at all. The range predicate is
     * in SQL because this query is project-wide.
     */
    private function gatherSubtaskRows(int $projectId, int $startTs, int $endTs): array
    {
        $table = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $rows = $this->db->table($table)
            ->columns(
                $table . '.user_id',
                $table . '.start',
                $table . '.end',
                $table . '.time_spent',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\TaskModel::TABLE . '.project_id'
            )
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $table)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->gte($table . '.start', $startTs)
            ->lte($table . '.start', $endTs)
            ->findAll();

        $normalized = [];
        foreach ($rows as $r) {
            $normalized[] = [
                'task_id'    => (int) $r['task_id'],
                'project_id' => (int) $r['project_id'],
                'user_id'    => (int) $r['user_id'],
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }

        return $normalized;
    }

    /**
     * Lean, UNRANGED tracking rows used only to decide task-level fallback eligibility.
     *
     * Deliberately not range-bounded: tasks.time_spent is an all-time pool, so tracked
     * time from any period disqualifies the fallback in every window. Four small
     * columns from one indexed table — this is the cheap query, not the expensive one.
     */
    private function gatherSuppressionRows(int $projectId): array
    {
        $table = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $rows = $this->db->table($table)
            ->columns($table . '.start', $table . '.end', $table . '.time_spent', \Kanboard\Model\SubtaskModel::TABLE . '.task_id')
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $table)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'task_id'    => (int) $r['task_id'],
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }

        return $out;
    }
```

Delete `gatherCompletedTaskRows()` and add these two in its place. Note that neither uses `getExtendedQuery()`:

```php
    /**
     * Tasks COMPLETED in range, every owner, as a lean projection.
     *
     * Replaces getExtendedQuery(), which carries seven correlated COUNT(*) subqueries
     * and ~35 columns for the nine fields this report needs. Owner-agnostic, because it
     * feeds both the task-level fallback and the completed-task detail, and a selected
     * user can have worked on a task somebody else owns.
     */
    private function gatherRangeTaskRows(int $projectId, int $startTs, int $endTs): array
    {
        $t = \Kanboard\Model\TaskModel::TABLE;

        $rows = $this->db->table($t)
            ->columns(
                $t . '.id', $t . '.project_id', $t . '.owner_id', $t . '.time_spent',
                $t . '.date_completed', $t . '.reference', $t . '.title', $t . '.category_id'
            )
            ->eq($t . '.project_id', $projectId)
            ->gte($t . '.date_completed', $startTs)
            ->lte($t . '.date_completed', $endTs)
            ->findAll();

        // Category names resolved from a small per-project map rather than a join, so
        // the task query stays a single-table projection.
        $categories = [];
        foreach ($this->categoryModel->getAll($projectId) as $c) {
            $categories[(int) $c['id']] = (string) $c['name'];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'project_id'     => (int) $r['project_id'],
                'owner_id'       => (int) $r['owner_id'],
                'time_spent'     => (float) $r['time_spent'],
                'date_completed' => (int) $r['date_completed'],
                'reference'      => (string) $r['reference'],
                'title'          => (string) $r['title'],
                'category_id'    => (int) $r['category_id'],
                'category'       => $categories[(int) $r['category_id']] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Reference + title for specific task ids.
     *
     * Used for tasks a selected user contributed to that fall outside the completed-in-
     * range set, so their breakdown label is a real title instead of "#id".
     *
     * @return array<int,array{reference:string,title:string}>
     */
    private function gatherTaskMeta(int $projectId, array $taskIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $taskIds)));
        if ($ids === []) {
            return [];
        }

        $t = \Kanboard\Model\TaskModel::TABLE;

        $rows = $this->db->table($t)
            ->columns($t . '.id', $t . '.reference', $t . '.title')
            ->eq($t . '.project_id', $projectId)
            ->in($t . '.id', $ids)
            ->findAll();

        $meta = [];
        foreach ($rows as $r) {
            $meta[(int) $r['id']] = ['reference' => (string) $r['reference'], 'title' => (string) $r['title']];
        }

        return $meta;
    }
```

- [ ] **Step 4: Make the untracked warning selection-invariant**

This is a behavior change, and it corrects a second latent defect. `subtasks.user_id` is the **assignee** (defaulting to 0); `subtask_time_tracking.user_id` is the **logger**. They are different people, and `subtasks.time_spent` is itself a cross-user pool. Filtering the tracked side by user therefore under-counts it and invents untracked hours that do not exist — and made the warning grow as users were deselected.

Two rules fix it: the **tracked side is never filtered by user** (a pool must be offset by the whole pool), and the **recorded side is scoped by permission, not by the subject set** (so the warning cannot move when the selection moves).

Replace `gatherUntrackedInputs()`:

```php
    /**
     * Inputs for findUntrackedSubtaskTime().
     *
     * $ownUserId null = project-wide (the requester may see others' time); otherwise
     * only that user's own subtasks. Scoped by PERMISSION, never by the subject set,
     * so the warning is selection-invariant.
     *
     * The tracked side is deliberately unfiltered by user and unranged: subtasks.
     * time_spent is a pool contributed to by every logger over all time, so offsetting
     * it with one user's tracked hours would invent untracked time that does not exist.
     *
     * @return array{0: list<array{subtask_id:int,task_id:int,time_spent:float}>, 1: array<int,float>, 2: array<int,array{reference:string,title:string}>}
     */
    private function gatherUntrackedInputs(int $projectId, ?int $ownUserId): array
    {
        $query = $this->db->table(\Kanboard\Model\SubtaskModel::TABLE)
            ->columns(
                \Kanboard\Model\SubtaskModel::TABLE . '.id',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\SubtaskModel::TABLE . '.time_spent',
                \Kanboard\Model\TaskModel::TABLE . '.reference',
                \Kanboard\Model\TaskModel::TABLE . '.title'
            )
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id')
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->gt(\Kanboard\Model\SubtaskModel::TABLE . '.time_spent', 0);

        if ($ownUserId !== null) {
            $query->eq(\Kanboard\Model\SubtaskModel::TABLE . '.user_id', $ownUserId);
        }

        $rows = $query->findAll();

        $records  = [];
        $taskMeta = [];
        foreach ($rows as $r) {
            $records[] = [
                'subtask_id' => (int) $r['id'],
                'task_id'    => (int) $r['task_id'],
                'time_spent' => (float) $r['time_spent'],
            ];
            $taskMeta[(int) $r['task_id']] = [
                'reference' => (string) ($r['reference'] ?? ''),
                'title'     => (string) ($r['title'] ?? ''),
            ];
        }

        // Tracked hours per subtask across ALL loggers and ALL time — see the docblock.
        $trackedBySubtask = [];
        $ttTable = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $ttRows = $this->db->table($ttTable)
            ->columns($ttTable . '.subtask_id', $ttTable . '.start', $ttTable . '.end', $ttTable . '.time_spent')
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $ttTable)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->findAll();

        foreach ($ttRows as $tt) {
            $sid       = (int) $tt['subtask_id'];
            $timeSpent = (float) $tt['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $start = (int) $tt['start'];
                $end   = (int) $tt['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }
            $trackedBySubtask[$sid] = ($trackedBySubtask[$sid] ?? 0.0) + $hours;
        }

        return [$records, $trackedBySubtask, $taskMeta];
    }
```

- [ ] **Step 5: Add `allUserIds()`, `hoursByUser()`, `userNames()`, `participants()`, `buildParticipants()`**

Add after `canReportOnOthers()`:

```php
    /** Every POSITIVE user id in the gathered rows, as a logger or a task owner. */
    public static function allUserIds(array $subtaskRows, array $taskRows): array
    {
        $ids = array_merge(
            array_map('intval', array_column($subtaskRows, 'user_id')),
            array_map('intval', array_column($taskRows, 'owner_id'))
        );

        // 0 is Kanboard's "unassigned" sentinel for both columns, not a person.
        return array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
    }

    /**
     * Sum contribution hours per user.
     *
     * @return array<int,float>
     */
    public static function hoursByUser(array $contributions): array
    {
        $byUser = [];
        foreach ($contributions as $c) {
            $uid = (int) ($c['user_id'] ?? 0);
            $byUser[$uid] = round(($byUser[$uid] ?? 0.0) + (float) $c['hours'], 2);
        }

        return $byUser;
    }

    /**
     * Display names for the given ids, preferring the full name and falling back to
     * the username, then to "#id" for a deleted user still referenced by old rows.
     *
     * @return array<int,string>
     */
    public function userNames(array $userIds): array
    {
        $names = [];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $user = $this->userModel->getById($uid);
            if (empty($user)) {
                continue;
            }
            $name = trim((string) ($user['name'] ?? ''));
            $names[$uid] = $name !== '' ? $name : (string) ($user['username'] ?? ('#' . $uid));
        }

        return $names;
    }

    /**
     * Every user with hours in $projectId over the range, with their totals.
     *
     * Public entry point for callers outside report(); report() computes the same thing
     * from rows it has already gathered, so a single request never runs discovery twice.
     *
     * @return array<int, array{name:string, hours:float}> Ordered by hours desc, then name.
     */
    public function participants(int $projectId, string $startDate, string $endDate, int $requestingUserId): array
    {
        $this->assertProjectAccess($projectId, $requestingUserId);

        $startTs = (int) strtotime($startDate . ' 00:00:00');
        $endTs   = (int) strtotime($endDate . ' 23:59:59');

        $subtaskRows = $this->gatherSubtaskRows($projectId, $startTs, $endTs);
        $taskRows    = $this->gatherRangeTaskRows($projectId, $startTs, $endTs);
        $suppressed  = self::suppressedTaskIdsFromRows($this->gatherSuppressionRows($projectId));

        $ids = $this->canReportOnOthers($projectId, $requestingUserId)
            ? self::allUserIds($subtaskRows, $taskRows)
            : [$requestingUserId];

        return $this->buildParticipants($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $ids, $suppressed);
    }

    /**
     * Group already-gathered rows into the participant panel shape.
     *
     * Reuses the SAME contribution union the report itself uses, so the panel's
     * per-user totals and the report's numbers agree by construction. A divergence
     * between the two would be a billing bug, not a cosmetic one.
     *
     * @return array<int, array{name:string, hours:float}>
     */
    private function buildParticipants(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, array $userIds, array $suppressedTaskIds): array
    {
        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $userIds, $suppressedTaskIds);

        $hours = self::hoursByUser($contributions);
        $names = $this->userNames(array_keys($hours));

        $out = [];
        foreach ($hours as $uid => $h) {
            if ($uid <= 0) {
                continue;
            }
            $out[$uid] = ['name' => $names[$uid] ?? ('#' . $uid), 'hours' => (float) $h];
        }

        uasort($out, static fn ($a, $b) => [$b['hours'], $a['name']] <=> [$a['hours'], $b['name']]);

        return $out;
    }
```

- [ ] **Step 6: Update `report()`'s call sites so the suite stays green**

```php
        $subtaskRows = $this->gatherSubtaskRows($projectId, $startTs, $endTs);
        $taskRows    = $this->gatherRangeTaskRows($projectId, $startTs, $endTs);
```

and

```php
        [$untrackedRecords, $trackedBySubtask, $untrackedTaskMeta] = $this->gatherUntrackedInputs($projectId, $userId);
```

- [ ] **Step 7: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS. If an untracked-warning test regressed, decide deliberately: the tracked-side change **corrects** a false warning when the logger differs from the assignee. Report any such change with before/after rather than editing the assertion silently.

- [ ] **Step 8: Commit**

```bash
git add Model/TimeReportModel.php Test/TimeReportModelTest.php
git commit -m "perf+fix: lean bounded queries, unranged suppression, selection-invariant untracked warning"
```

---


### Task 5: Wire the subject set and scope intent through `report()`

**Files:**
- Modify: `Model/TimeReportModel.php` (`report`, `buildDetail`)
- Test: `Test/TimeReportModelTest.php`

**Interfaces:**
- Consumes: Tasks 1-4.
- Produces:

```php
public function report(
    int $projectId, string $startDate, string $endDate,
    string $granularity, bool $includeDetail, int $userId,
    ?array $subjectUserIds = null, bool $allUsers = false
): array
```

  New aggregate keys: `subject_user_ids`, `users`, `participants`, `multi_user`, `can_report_others`, `scope_denied`; each detail row gains `assignee`. Tasks 6, 7 and 8 consume these.

**Note on existing test doubles.** `TimeReportControllerTest` wires anonymous classes with a six-parameter `report()`. PHP permits passing extra arguments to userland methods — verified: an 8-argument call into a 6-parameter method reports `func_num_args() === 8` and raises nothing. Those doubles therefore keep working untouched. Update one only if it needs to *read* the new arguments; do not "fix" them pre-emptively.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportModelTest.php`. Note the fixture gives **both** users real hours — a requester with none would be dropped by participant intersection, leaving one subject and `multi_user === false`:

```php
    // --- report() subject-set wiring ---

    /** Both users get hours: a requester with none is not a participant and gets filtered out. */
    private function seedProjectWithTwoContributors(): array
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Billing'], 1, true);
        $bob = (int) $this->container['userModel']->create(['username' => 'bob2', 'name' => 'Bob', 'password' => 'xxxxxxxx']);
        $this->container['projectUserRoleModel']->addUser($projectId, $bob, \Kanboard\Core\Security\Role::PROJECT_MEMBER);

        $bobTask = (int) $this->container['taskCreationModel']->create([
            'title' => 'Bob task', 'project_id' => $projectId, 'owner_id' => $bob, 'time_spent' => 4.0,
        ]);
        $this->container['taskStatusModel']->close($bobTask);

        $mineTask = (int) $this->container['taskCreationModel']->create([
            'title' => 'My task', 'project_id' => $projectId, 'owner_id' => 1, 'time_spent' => 1.0,
        ]);
        $this->container['taskStatusModel']->close($mineTask);

        return [$projectId, $bob];
    }

    public function testSixArgumentCallIsSelfOnlyAndNotMultiUser(): void
    {
        [$projectId] = $this->seedProjectWithTwoContributors();
        $model = new TimeReportModel($this->container);

        // The TimeInvoice call shape — six positional arguments.
        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', true, 1);

        $this->assertSame([1], $report['subject_user_ids']);
        $this->assertFalse($report['multi_user']);
        $this->assertFalse($report['scope_denied']);
        $this->assertSame(1.0, $report['total_hours'], "only user 1's own hour; Bob's 4h must not leak in");
    }

    public function testAdminCanIncludeAnotherUser(): void
    {
        [$projectId, $bob] = $this->seedProjectWithTwoContributors();
        $model = new TimeReportModel($this->container);

        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'user', true, 1, [1, $bob]);

        $this->assertSame([1, $bob], $report['subject_user_ids']);
        $this->assertTrue($report['multi_user']);
        $this->assertSame(5.0, $report['total_hours']);
        $this->assertArrayHasKey($bob, $report['users']);
    }

    public function testAllUsersIntentResolvesToEveryParticipant(): void
    {
        [$projectId, $bob] = $this->seedProjectWithTwoContributors();
        $model = new TimeReportModel($this->container);

        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'user', false, 1, null, true);

        $this->assertContains($bob, $report['subject_user_ids']);
        $this->assertSame(5.0, $report['total_hours']);
    }

    public function testParticipantsAreReturnedForDiscovery(): void
    {
        [$projectId, $bob] = $this->seedProjectWithTwoContributors();
        $model = new TimeReportModel($this->container);

        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', false, 1);

        $this->assertArrayHasKey($bob, $report['participants'], 'a manager must discover Bob without a second query');
    }

    public function testDetailRowsCarryTheAssigneeName(): void
    {
        [$projectId, $bob] = $this->seedProjectWithTwoContributors();
        $model = new TimeReportModel($this->container);

        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', true, 1, [1, $bob]);

        $names = array_column($report['detail'], 'assignee');
        $this->assertContains('Bob', $names);
    }

    /**
     * REGRESSION: a selected user's work on someone else's task must keep its task
     * label and appear in the completed-task detail, even though the owner is not in
     * the subject set.
     */
    public function testCrossOwnerContributionKeepsMetadataAndDetail(): void
    {
        $model = new TimeReportModel($this->container);

        $projectId = (int) $this->container['projectModel']->create(['name' => 'Cross'], 1, true);
        $alice = (int) $this->container['userModel']->create(['username' => 'alice', 'name' => 'Alice', 'password' => 'xxxxxxxx']);
        $bob   = (int) $this->container['userModel']->create(['username' => 'bobx', 'name' => 'Bob', 'password' => 'xxxxxxxx']);
        foreach ([$alice, $bob] as $u) {
            $this->container['projectUserRoleModel']->addUser($projectId, $u, \Kanboard\Core\Security\Role::PROJECT_MEMBER);
        }

        // Alice OWNS the task; Bob logs the tracked time on its subtask.
        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Shared work', 'project_id' => $projectId, 'owner_id' => $alice,
        ]);
        $subtaskId = (int) $this->container['subtaskModel']->create([
            'task_id' => $taskId, 'title' => 'Do it', 'user_id' => $bob,
        ]);
        $this->container['db']->table('subtask_time_tracking')->insert([
            'subtask_id' => $subtaskId,
            'user_id'    => $bob,
            'start'      => strtotime(date('Y-m-01') . ' 09:00:00'),
            'end'        => strtotime(date('Y-m-01') . ' 11:00:00'),
            'time_spent' => 2.0,
        ]);
        $this->container['taskStatusModel']->close($taskId);

        // Report on BOB only — Alice, the owner, is deliberately excluded.
        $report = $model->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', true, 1, [$bob]);

        $this->assertSame(2.0, $report['total_hours']);
        $this->assertStringContainsString('Shared work', $report['breakdown'][0]['label'], 'task label must not degrade to #id');
        $this->assertNotEmpty($report['detail'], 'the task Bob worked on must appear in detail');
        $this->assertSame($taskId, $report['detail'][0]['task_id']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportModelTest.php --no-coverage
```

Expected: FAIL — unknown key `subject_user_ids`, and `report()` ignores the seventh argument.

- [ ] **Step 3: Implement `report()`**

```php
    /**
     * Compute the full report aggregate for one project + range.
     *
     * $userId is the REQUESTING user and drives both authorization layers. The subject
     * set arrives separately: $subjectUserIds names specific users, $allUsers carries
     * the "every participant" intent. The first six parameters are frozen — TimeInvoice
     * calls this positionally with six arguments.
     *
     * AI is not attached here (ai => null); the controller adds it when enabled.
     */
    public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId, ?array $subjectUserIds = null, bool $allUsers = false): array
    {
        $this->assertProjectAccess($projectId, $userId);

        $startTs = (int) strtotime($startDate . ' 00:00:00');
        $endTs   = (int) strtotime($endDate . ' 23:59:59');

        // Gathered ONCE. The subject set decides what is emitted, never what is seen.
        $subtaskRows = $this->gatherSubtaskRows($projectId, $startTs, $endTs);
        $taskRows    = $this->gatherRangeTaskRows($projectId, $startTs, $endTs);
        $suppressed  = self::suppressedTaskIdsFromRows($this->gatherSuppressionRows($projectId));

        $canReportOthers = $this->canReportOnOthers($projectId, $userId);

        // Discovery runs at most once per request and doubles as the refine panel's data.
        $participants = $canReportOthers
            ? $this->buildParticipants($subtaskRows, $taskRows, $startTs, $endTs, $projectId, self::allUserIds($subtaskRows, $taskRows), $suppressed)
            : [];

        [$subjectIds, $scopeDenied] = self::sanitizeSubjectUserIds(
            $subjectUserIds,
            $allUsers,
            $userId,
            array_keys($participants),
            $canReportOthers
        );

        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $subjectIds, $suppressed);

        // Labels for every contributed task, including one completed outside the range
        // or owned by somebody outside the subject set.
        $taskMeta = [];
        foreach ($taskRows as $t) {
            $taskMeta[(int) $t['id']] = ['reference' => (string) ($t['reference'] ?? ''), 'title' => (string) ($t['title'] ?? '')];
        }
        $missing = array_diff(array_unique(array_map('intval', array_column($contributions, 'task_id'))), array_keys($taskMeta));
        foreach ($this->gatherTaskMeta($projectId, $missing) as $tid => $meta) {
            $taskMeta[$tid] = $meta;
        }

        $userNames = $this->userNames($subjectIds);
        $userMeta  = [];
        foreach ($userNames as $uid => $name) {
            $userMeta[$uid] = ['name' => $name];
        }

        $bucketed = self::bucket($contributions, $granularity, $taskMeta, $userMeta);

        $perUser = self::hoursByUser($contributions);
        $users   = [];
        foreach ($subjectIds as $uid) {
            $users[$uid] = [
                'name'  => $userNames[$uid] ?? ('#' . $uid),
                'hours' => (float) ($perUser[$uid] ?? 0.0),
            ];
        }

        $project = $this->projectModel->getById($projectId);

        $report = [
            'project_id'        => $projectId,
            'project_name'      => (string) ($project['name'] ?? ('#' . $projectId)),
            'start_date'        => $startDate,
            'end_date'          => $endDate,
            'granularity'       => $granularity,
            'total_hours'       => $bucketed['total_hours'],
            'breakdown'         => $bucketed['breakdown'],
            'include_detail'    => $includeDetail,
            'detail'            => [],
            'ai'                => null,
            'subject_user_ids'  => $subjectIds,
            'users'             => $users,
            'participants'      => $participants,
            'multi_user'        => count($subjectIds) > 1,
            'can_report_others' => $canReportOthers,
            'scope_denied'      => $scopeDenied,
        ];

        if ($includeDetail) {
            $report['detail'] = $this->buildDetail($contributions, $taskRows, $subjectIds);
        }

        // Scoped by PERMISSION, not by the subject set, so the warning is invariant
        // under selection changes.
        [$untrackedRecords, $trackedBySubtask, $untrackedTaskMeta] = $this->gatherUntrackedInputs($projectId, $canReportOthers ? null : $userId);
        $report['untracked'] = self::findUntrackedSubtaskTime($untrackedRecords, $trackedBySubtask, $untrackedTaskMeta);

        return $report;
    }
```

- [ ] **Step 4: Rewrite `buildDetail()`**

`$taskRows` is already completed-in-range, so the range arguments are gone:

```php
    /**
     * Completed-task detail set.
     *
     * $taskRows is already restricted to tasks completed in range. A task belongs in
     * the detail when a selected user owns it OR a selected user contributed hours to
     * it. The second half matters: without it, a user's work on somebody else's task
     * would be counted in the total but missing from the artifact justifying the invoice.
     */
    private function buildDetail(array $contributions, array $taskRows, array $subjectIds): array
    {
        $selected = array_flip(array_map('intval', $subjectIds));

        $hoursByTask = [];
        foreach ($contributions as $c) {
            $hoursByTask[(int) $c['task_id']] = ($hoursByTask[(int) $c['task_id']] ?? 0.0) + (float) $c['hours'];
        }

        $completed = [];
        foreach ($taskRows as $t) {
            $id = (int) $t['id'];
            if (! isset($selected[(int) $t['owner_id']]) && ! isset($hoursByTask[$id])) {
                continue;
            }
            $completed[$id] = $t;
        }

        $ids = array_keys($completed);
        $tagsByTask = empty($ids) ? [] : $this->taskTagModel->getTagsByTaskIds($ids);
        $assigneeNames = $this->userNames(array_map(static fn ($t) => (int) $t['owner_id'], $completed));

        $detail = [];
        foreach ($completed as $id => $t) {
            $tagNames = array_map(static fn ($tag) => (string) $tag['name'], $tagsByTask[$id] ?? []);
            $detail[] = [
                'task_id'        => $id,
                'reference'      => (string) $t['reference'],
                'title'          => (string) $t['title'],
                'assignee'       => (string) ($assigneeNames[(int) $t['owner_id']] ?? ''),
                'hours'          => (float) ($hoursByTask[$id] ?? 0.0),
                'date_completed' => date('Y-m-d', (int) $t['date_completed']),
                'category'       => (string) $t['category'],
                'tags'           => $tagNames,
            ];
        }
        usort($detail, static fn ($a, $b) => [$a['date_completed'], $a['reference']] <=> [$b['date_completed'], $b['reference']]);

        return $detail;
    }
```

- [ ] **Step 5: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add Model/TimeReportModel.php Test/TimeReportModelTest.php
git commit -m "feat: report() accepts a subject set and scope intent, returns participants"
```

---


### Task 6: Markdown and CSV rendering

**Files:**
- Modify: `Helper/TimeReportHelper.php`
- Test: `Test/TimeReportHelperTest.php`

**Interfaces:**
- Consumes: `multi_user` and detail `assignee` from Task 5.
- Produces: no signature changes. `toMarkdown()` and `toCsv()` gain a conditional Assignee column; `breakdownHeader()` gains `'user' => 'User'`.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportHelperTest.php`. Reuse whatever fixture builder that file already uses for a report array; if it builds inline arrays, follow that style:

```php
    private function multiUserReport(): array
    {
        return [
            'project_name'   => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity'    => 'user', 'total_hours' => 9.0,
            'breakdown'      => [
                ['key' => 'u1', 'label' => 'Alice', 'hours' => 5.0, 'task_count' => 2],
                ['key' => 'u2', 'label' => 'Bob', 'hours' => 4.0, 'task_count' => 1],
            ],
            'include_detail' => true,
            'detail'         => [
                ['task_id' => 10, 'reference' => 'R1', 'title' => 'Ship it', 'assignee' => 'Bob',
                 'hours' => 4.0, 'date_completed' => '2026-03-12', 'category' => 'Dev', 'tags' => ['x']],
            ],
            'ai'             => null,
            'multi_user'     => true,
        ];
    }

    public function testMarkdownAddsAssigneeColumnWhenMultiUser(): void
    {
        $md = $this->helper()->toMarkdown($this->multiUserReport());

        $this->assertStringContainsString('| Ref | Title | Assignee | Hours | Completed | Category | Tags |', $md);
        $this->assertStringContainsString('| R1 | Ship it | Bob |', $md);
    }

    public function testMarkdownUsesUserHeaderForUserGranularity(): void
    {
        $md = $this->helper()->toMarkdown($this->multiUserReport());

        $this->assertStringContainsString('| User | Hours | Tasks |', $md);
        $this->assertStringContainsString('| Alice | 5.00 | 2 |', $md);
    }

    public function testCsvAddsAssigneeColumnWhenMultiUser(): void
    {
        $csv = $this->helper()->toCsv($this->multiUserReport());

        $this->assertStringContainsString('Reference,Title,Assignee,Hours,Completed,Category,Tags', $csv);
        $this->assertStringContainsString('R1,Ship it,Bob,4.00', $csv);
    }

    public function testSingleUserDetailOutputIsUnchanged(): void
    {
        $report = $this->multiUserReport();
        $report['multi_user']  = false;
        $report['granularity'] = 'task';

        $md  = $this->helper()->toMarkdown($report);
        $csv = $this->helper()->toCsv($report);

        $this->assertStringContainsString('| Ref | Title | Hours | Completed | Category | Tags |', $md);
        $this->assertStringNotContainsString('Assignee', $md);
        $this->assertStringContainsString('Reference,Title,Hours,Completed,Category,Tags', $csv);
        $this->assertStringNotContainsString('Assignee', $csv);
    }

    /**
     * Golden output. Substring checks would miss a changed separator, column order or
     * line ending, so the whole rendering is pinned for a single-user fixture.
     *
     * This asserts FORMAT, not arithmetic: the fixture's hours are given directly, so
     * the Task 2 attribution fix cannot move them. Never pin a fixture whose numbers
     * come from the contribution union — those legitimately changed.
     */
    public function testSingleUserMarkdownAndCsvAreByteIdentical(): void
    {
        $report = [
            'project_name' => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity'  => 'task', 'total_hours' => 3.0,
            'breakdown'    => [['key' => '10', 'label' => '#10 Ship it', 'hours' => 3.0, 'task_count' => 1]],
            'include_detail' => true,
            'detail' => [
                ['task_id' => 10, 'reference' => 'R1', 'title' => 'Ship it', 'assignee' => 'Alice',
                 'hours' => 3.0, 'date_completed' => '2026-03-12', 'category' => 'Dev', 'tags' => ['x']],
            ],
            'ai' => null, 'multi_user' => false,
        ];

        $expectedMd = "# Time Report — Acme\n"
            . "\n"
            . "**Range:** 2026-03-01 → 2026-03-31\n"
            . "**Total hours:** 3.00\n"
            . "\n"
            . "| Task | Hours |\n"
            . "| --- | ---: |\n"
            . "| #10 Ship it | 3.00 |\n"
            . "\n"
            . "## Completed tasks\n"
            . "\n"
            . "| Ref | Title | Hours | Completed | Category | Tags |\n"
            . "| --- | --- | ---: | --- | --- | --- |\n"
            . "| R1 | Ship it | 3.00 | Thu 2026-03-12 | Dev | x |\n";

        $expectedCsv = "# Time Report,Acme\r\n"
            . "# Range,2026-03-01,2026-03-31\r\n"
            . "# Total hours,3.00\r\n"
            . "\r\n"
            . "Label,Hours,Tasks\r\n"
            . "#10 Ship it,3.00,1\r\n"
            . "\r\n"
            . "Reference,Title,Hours,Completed,Category,Tags\r\n"
            . "R1,Ship it,3.00,2026-03-12,Dev,x\r\n";

        $this->assertSame($expectedMd, $this->helper()->toMarkdown($report));
        $this->assertSame($expectedCsv, $this->helper()->toCsv($report));
    }
```

If `Test/TimeReportHelperTest.php` has no `helper()` accessor, add one matching how the file already obtains the helper (typically `new TimeReportHelper($this->container)`).

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportHelperTest.php --no-coverage
```

Expected: FAIL — no Assignee column, and the `user` granularity renders the `Day` header.

- [ ] **Step 3: Implement in `Helper/TimeReportHelper.php`**

In `toMarkdown()`, replace the completed-tasks block:

```php
        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $multi = ! empty($report['multi_user']);
            $lines[] = '';
            $lines[] = '## Completed tasks';
            $lines[] = '';
            $lines[] = $multi
                ? '| Ref | Title | Assignee | Hours | Completed | Category | Tags |'
                : '| Ref | Title | Hours | Completed | Category | Tags |';
            $lines[] = $multi
                ? '| --- | --- | --- | ---: | --- | --- | --- |'
                : '| --- | --- | ---: | --- | --- | --- |';
            foreach ($report['detail'] as $d) {
                $assignee = $multi ? (($d['assignee'] ?? '') . ' | ') : '';
                $lines[] = '| ' . $d['reference'] . ' | ' . $d['title'] . ' | ' . $assignee . $this->formatHours((float) $d['hours'])
                    . ' | ' . $this->withWeekday($d['date_completed']) . ' | ' . $d['category'] . ' | ' . implode('; ', $d['tags']) . ' |';
            }
        }
```

In `toCsv()`, replace the detail block:

```php
        if (! empty($report['include_detail']) && ! empty($report['detail'])) {
            $multi = ! empty($report['multi_user']);
            $out[] = '';
            $out[] = $multi
                ? $this->csvRow(['Reference', 'Title', 'Assignee', 'Hours', 'Completed', 'Category', 'Tags'])
                : $this->csvRow(['Reference', 'Title', 'Hours', 'Completed', 'Category', 'Tags']);
            foreach ($report['detail'] as $d) {
                $fields = [$d['reference'], $d['title']];
                if ($multi) {
                    $fields[] = (string) ($d['assignee'] ?? '');
                }
                $fields[] = $this->formatHours((float) $d['hours']);
                $fields[] = $d['date_completed'];
                $fields[] = $d['category'];
                $fields[] = implode('; ', $d['tags']);
                $out[] = $this->csvRow($fields);
            }
        }
```

In `breakdownHeader()`, add the new case:

```php
            'user'  => 'User',
```

- [ ] **Step 4: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS, including every pre-existing Markdown/CSV assertion — those encode the format-identity rule.

If the golden strings above do not match on first run, compare character by character and fix the **expectation**, not the helper: this test exists to pin v1.2.0's exact rendering, so the helper's current output is the authority for format. (Do not confuse this with the Task 2 numeric changes, which are intentional and live in the model, not here.)

- [ ] **Step 5: Commit**

```bash
git add Helper/TimeReportHelper.php Test/TimeReportHelperTest.php
git commit -m "feat: conditional Assignee column in Markdown and CSV"
```

---

### Task 7: Controller — carry scope intent, refuse narrowed exports

**Files:**
- Modify: `Controller/TimeReportController.php`
- Test: `Test/TimeReportControllerTest.php`

**Interfaces:**
- Consumes: `report()`'s seventh and eighth parameters, and the `participants` key it returns (Task 5).
- Produces: `generate()` and `exportCsv()` pass both the explicit set and the scope intent; `exportCsv()` refuses when `scope_denied`; `index()` passes `can_report_others`. `view()` stays self-only. Task 8 consumes `$report['participants']`.

**The controller makes no authorization decision.** It forwards intent and lets the model sanitize. In particular it must **not** resolve `scope=all` into ids by calling `participants()` — doing so would discard the denial for a user who lacks permission on the chosen project, which is reachable because the form toggle is enabled when *any* accessible project qualifies.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TimeReportControllerTest.php`:

```php
    public function testScopeIntentIsForwardedNotPreResolved(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('user_ids', $src, 'must read the submitted user set');
        $this->assertStringContainsString("'all'", $src, 'must honor the scope=all toggle');
        $this->assertStringNotContainsString(
            "participants(",
            $src,
            'scope=all must be forwarded as intent; resolving it here would discard the denial'
        );
    }

    public function testQuickViewStaysSelfOnly(): void
    {
        $src = $this->source();
        $this->assertMatchesRegularExpression(
            '/function quickReport.*?report\(\s*\$projectId,[^;]*\$userId\s*\)\s*;/s',
            $src,
            'the one-click menu report must remain self-only'
        );
    }

    public function testCsvExportRefusesWhenScopeWasDenied(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('scope_denied', $src, 'export must not stream a silently narrowed report');
        $this->assertMatchesRegularExpression(
            '/scope_denied.*?redirect/s',
            $src,
            'a denied export must redirect rather than stream a misleading billing artifact'
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TimeReportControllerTest.php --no-coverage
```

Expected: FAIL — none of those strings exist yet.

- [ ] **Step 3: Implement**

Extend the granularity allow-list:

```php
    private const GRANULARITIES = ['day', 'week', 'task', 'total', 'user'];
```

Add the selection resolver. It returns intent, never a resolved id list:

```php
    /**
     * What the request asked for: an explicit set, or the "all users" intent.
     *
     * scope=all is deliberately NOT resolved into ids here. The model needs the intact
     * intent to tell "asked for the team and may not have it" (which must warn) apart
     * from "asked only for myself" (which must not). Resolving it here would collapse
     * the two for any user without permission on the chosen project.
     *
     * @return array{0: ?array, 1: bool} [explicit ids or null, all-users intent]
     */
    protected function subjectSelection(array $values): array
    {
        if (! empty($values['user_ids']) && is_array($values['user_ids'])) {
            return [array_map('intval', $values['user_ids']), false];
        }

        return [null, ($values['scope'] ?? '') === 'all'];
    }
```

In `buildReportFromRequest()`, after the `$wantsAi` line:

```php
        [$subjectUserIds, $allUsers] = $this->subjectSelection($values);
```

and change the `report()` call:

```php
        $report = $this->timeReportModel->report($projectId, $startDate, $endDate, $granularity, $needDetail, $userId, $subjectUserIds, $allUsers);
```

`generate()` needs no participant lookup — the aggregate carries it:

```php
    public function generate(): void
    {
        $this->checkCSRFForm();
        $report = $this->buildReportFromRequest();

        $markdown = $this->helper->timeReport->toMarkdown($report);

        $this->response->html($this->helper->layout->app('TimeReport:report/show', [
            'title'      => t('Time Report'),
            'report'     => $report,
            'markdown'   => $markdown,
            'ai_enabled' => $this->isAiEnabled(),
        ]));
    }
```

In `exportCsv()`, refuse a narrowed export. Insert immediately after `$report = $this->buildReportFromRequest();`:

```php
        // A CSV carries no on-screen notice, so a silently narrowed export would look
        // like a valid team invoice covering people it does not contain. Refuse it.
        if (! empty($report['scope_denied'])) {
            $this->response->redirect($this->helper->url->to('TimeReportController', 'index', ['plugin' => 'TimeReport']));
            return;
        }
```

In `index()`, expose whether to render the scope toggle. After the `$selected` block:

```php
        // Admins short-circuit; otherwise check the projects already being listed, so
        // this adds no query beyond the getById() loop above.
        $canReportOthers = $this->userSession->isAdmin();
        if (! $canReportOthers) {
            foreach (array_keys($projects) as $pid) {
                if ($this->timeReportModel->canReportOnOthers((int) $pid, $userId)) {
                    $canReportOthers = true;
                    break;
                }
            }
        }
```

and add `'can_report_others' => $canReportOthers,` to the `index()` view payload.

**Note for the form toggle.** Because this is true when *any* accessible project qualifies, a user can submit `scope=all` against a project where they are only a member. That path is exactly what Task 1's `scope_denied` covers, and Task 8 renders the notice.

- [ ] **Step 4: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Controller/TimeReportController.php Test/TimeReportControllerTest.php
git commit -m "feat: forward scope intent to the model and refuse narrowed CSV exports"
```

---


### Task 8: Templates

**Files:**
- Modify: `Template/report/form.php`, `Template/report/show.php`, `Template/report/_detail.php`, `Template/report/_breakdown.php`
- Create: `Template/report/_users.php`
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `can_report_others` (from `index()`), and `participants`, `subject_user_ids`, `multi_user`, `scope_denied` from the report aggregate (Task 5).
- Produces: no PHP interface. The panel POSTs `user_ids[]` back to `generate`.

- [ ] **Step 1: Write the failing tests**

Append to `Test/TemplateAssetsTest.php`, following that file's existing `file_get_contents` style:

```php
    private function tpl(string $name): string
    {
        return file_get_contents(dirname(__DIR__) . '/Template/report/' . $name);
    }

    public function testUsersPanelExistsAndPostsUserIds(): void
    {
        $src = $this->tpl('_users.php');
        $this->assertStringContainsString('user_ids[]', $src);
        $this->assertStringContainsString('csrf', $src, 'the refine panel is a POST and must carry CSRF');
        $this->assertStringContainsString("'generate'", $src);
    }

    public function testCsvExportCarriesTheSubjectSet(): void
    {
        $src = $this->tpl('show.php');
        $this->assertStringContainsString('subject_user_ids', $src, 'CSV must export the same people shown on screen');
        $this->assertStringContainsString('scope_denied', $src, 'the denial notice must be rendered');
    }

    /**
     * A denied result posts back [self], which would look like an ordinary self-only
     * request and slip past exportCsv()'s guard. The export action must not be
     * rendered at all in that state.
     */
    public function testDeniedResultRendersNoCsvExportAction(): void
    {
        $src = $this->tpl('show.php');
        $this->assertMatchesRegularExpression(
            '/empty\(\$report\[.scope_denied.\]\).*?exportCsv/s',
            $src,
            'the CSV export form must be suppressed when the scope was denied'
        );
    }

    public function testFormHasScopeToggleAndUserGranularity(): void
    {
        $src = $this->tpl('form.php');
        $this->assertStringContainsString("'scope'", $src);
        $this->assertStringContainsString('can_report_others', $src, 'the toggle is gated on permission');
        $this->assertStringContainsString("'user' => t('By user')", $src);
    }

    public function testDetailAssigneeColumnIsConditional(): void
    {
        $src = $this->tpl('_detail.php');
        $this->assertStringContainsString('multi_user', $src);
        $this->assertStringContainsString('Assignee', $src);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/TemplateAssetsTest.php --no-coverage
```

Expected: FAIL — `_users.php` does not exist.

- [ ] **Step 3: Create `Template/report/_users.php`**

Participants come from the aggregate, so this partial needs no extra query and cannot disagree with the totals above it:

```php
<?php /** Refine panel: which participants the report covers. POSTs back to generate. */ ?>
<div class="tr-users">
    <h3><?= t('Users') ?></h3>
    <form method="post" action="<?= $this->url->href('TimeReportController', 'generate', ['plugin' => 'TimeReport']) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="project_id" value="<?= (int) $report['project_id'] ?>">
        <input type="hidden" name="start_date" value="<?= $this->text->e($report['start_date']) ?>">
        <input type="hidden" name="end_date" value="<?= $this->text->e($report['end_date']) ?>">
        <input type="hidden" name="granularity" value="<?= $this->text->e($report['granularity']) ?>">
        <?php if (! empty($report['include_detail'])): ?>
            <input type="hidden" name="include_detail" value="1">
        <?php endif ?>

        <ul class="tr-user-list">
            <?php $trSelected = array_map('intval', $report['subject_user_ids']); ?>
            <?php foreach ($report['participants'] as $trUid => $trPerson): ?>
                <li>
                    <label>
                        <input type="checkbox" name="user_ids[]" value="<?= (int) $trUid ?>"
                            <?= in_array((int) $trUid, $trSelected, true) ? 'checked' : '' ?>>
                        <?= $this->text->e($trPerson['name']) ?>
                        — <?= $this->text->e($this->helper->timeReport->formatHours((float) $trPerson['hours'])) ?><?= t('h') ?>
                    </label>
                </li>
            <?php endforeach ?>
        </ul>

        <button type="submit" class="btn"><?= t('Update report') ?></button>
    </form>
</div>
```

- [ ] **Step 4: Wire `Template/report/show.php`**

Add the denial notice immediately after the `page-header` div:

```php
<?php if (! empty($report['scope_denied'])): ?>
    <div class="alert alert-error">
        <?= t('You do not have permission to include other users in this project. Showing your own hours.') ?>
    </div>
<?php endif ?>
```

**Suppress the CSV export entirely on a denied result, and only then add the subject set.**
This is load-bearing, not cosmetic. A denied report resolves to `[self]`, so if the form
posted that sanitized set back, `exportCsv()` would see an ordinary self-only request,
sanitization would return `scope_denied === false`, and the guard from Task 7 would never
fire — re-creating the silent narrowed export through the normal button. Removing the
action is what closes that path; the Task 7 guard remains for direct POSTs.

Wrap the whole existing `<form>` for CSV export in:

```php
        <?php if (empty($report['scope_denied'])): ?>
            <!-- ... existing CSV export form ... -->
        <?php endif ?>
```

and inside that form, immediately after its `include_detail` hidden input, add:

```php
            <?php foreach ($report['subject_user_ids'] as $trUid): ?>
                <input type="hidden" name="user_ids[]" value="<?= (int) $trUid ?>">
            <?php endforeach ?>
```

Leave **Copy as Markdown** in place: it renders inline, directly beneath the denial
notice, so the narrowing is visible at the moment of copying. A downloaded file carries
no such context, which is the whole reason the export is treated differently.

Render the panel immediately before the breakdown render:

```php
<?php if (! empty($report['participants']) && count($report['participants']) > 1): ?>
    <?= $this->render('TimeReport:report/_users', ['report' => $report]) ?>
<?php endif ?>
```

- [ ] **Step 5: Wire `Template/report/form.php`**

Add the `By user` option to the granularity select:

```php
    <?= $this->form->select('granularity', ['day' => t('Per day'), 'week' => t('Per week'), 'task' => t('Per task'), 'total' => t('Total only'), 'user' => t('By user')], $values) ?>
```

Add the scope toggle immediately after it:

```php
    <?php if (! empty($can_report_others)): ?>
        <?= $this->form->label(t('Include'), 'scope') ?>
        <?= $this->form->select('scope', ['self' => t('Just me'), 'all' => t('All users')], $values) ?>
    <?php endif ?>
```

- [ ] **Step 6: Wire `Template/report/_detail.php`**

Set a flag at the top of the file:

```php
<?php $trMulti = ! empty($report['multi_user']); ?>
```

Add the header cell after `Title`:

```php
            <?php if ($trMulti): ?><th><?= t('Assignee') ?></th><?php endif ?>
```

and the body cell after the title `<td>`:

```php
                <?php if ($trMulti): ?><td><?= $this->text->e($d['assignee'] ?? '') ?></td><?php endif ?>
```

- [ ] **Step 7: Wire `Template/report/_breakdown.php`**

Replace the first line so the `user` granularity gets its own label while keeping the Tasks column:

```php
<?php
$isTask = $report['granularity'] === 'task';
$trLabel = match ($report['granularity']) {
    'task'  => t('Task'),
    'user'  => t('User'),
    default => t('Period'),
};
?>
```

and replace the first `<th>`:

```php
            <th><?= $trLabel ?></th>
```

- [ ] **Step 8: Run the full suite to verify it passes**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add Template/report/ Test/TemplateAssetsTest.php
git commit -m "feat: scope toggle, participant refine panel, assignee column"
```

---


### Task 9: Version bump, CHANGELOG, and live verification

**Files:**
- Modify: `plugin.json`, `Plugin.php`, `CHANGELOG.md`, `Test/PluginTest.php`, `Test/PluginMetaTest.php`

**Interfaces:**
- Consumes: everything.
- Produces: a release-ready 1.3.0.

- [ ] **Step 1: Update the version in all five places**

Per the suite's release rule, these must move together:

```bash
sed -i 's/"version": "1.2.0"/"version": "1.3.0"/' plugin.json
sed -i "s/return '1.2.0';/return '1.3.0';/" Plugin.php
sed -i "s/'1.2.0'/'1.3.0'/g" Test/PluginTest.php Test/PluginMetaTest.php
```

- [ ] **Step 2: Verify exactly the intended sites changed**

```bash
grep -rn "1\.3\.0" plugin.json Plugin.php Test/PluginTest.php Test/PluginMetaTest.php && grep -rn "1\.2\.0" plugin.json Plugin.php Test/
```

Expected: four files list 1.3.0 (three assertions plus `getPluginVersion`), and the second grep returns only CHANGELOG history, nothing in code or tests.

- [ ] **Step 3: Add the CHANGELOG entry**

Insert immediately below the `# Changelog` heading in `CHANGELOG.md`:

```markdown
## 1.3.0 — 2026-08-27

### Fixed

- **Hours logged by one person could be billed to another.** Kanboard stores a task's `time_spent` as the sum of *all* its subtasks' time, across every user and every period. The report treated that pool as the task owner's whenever the people who actually tracked the time were filtered out — so a task you merely owned could add someone else's hours to your report. Time is now attributed to whoever tracked it, and a task's own total is only ever used when nobody tracked time on it at all, in any period. Reports may show fewer hours than before; the earlier figures were overstated.
- **The untracked-time warning could report time that was in fact tracked.** A subtask's recorded time is a shared pool, but it was being compared against only one person's tracked hours — so when the person who logged the time was not the person the subtask was assigned to, the difference was reported as untracked. The comparison now uses every logger's tracked time.
- Unassigned tasks (no owner) no longer contribute their time to any user's report.

### Added

- Report on other users' hours: project managers and administrators can include any user who logged time in the project, then refine the set from a **Users** panel on the results page to bill everyone or a subset.
- New **By user** breakdown, grouping hours per person.
- Completed-task detail gains an **Assignee** column when the report covers more than one user; single-user reports are unchanged.

### Security

- Members and viewers keep the self-only report. A request to include others without permission narrows to your own hours and says so on the page, and a CSV export of such a request is refused rather than silently downloaded.
```

- [ ] **Step 4: Run the full suite**

```bash
cd testing/kanboard-src && vendor/bin/phpunit --bootstrap tests/plugin-bootstrap.php -c tests/units.sqlite.xml plugins/TimeReport/Test/ --no-coverage
```

Expected: PASS, all green. Record the final test/assertion counts.

- [ ] **Step 5: Live verification on the dev stack**

On `:8081` (`admin`/`admin`), in a project with a second user who has logged time. Edit files on the **host** only, never inside the container.

Verify, and capture what you saw:

1. As **admin**: form shows the `Include` toggle and `By user`; `All users` produces pooled totals; the Users panel lists participants with hours; unchecking a user and pressing **Update report** changes the totals.
2. **Narrowing never inflates.** Note the total with everyone selected, then uncheck one person: the total must go *down or stay equal*, never up. Check the untracked-time warning too — it must not move at all.
2b. **Cross-period.** Have user B track time in a previous month on a task that completes in the current month, then run the current month for user A (the owner): A must not be billed B's hours.
3. **Cross-owner work.** Have user B track time on a subtask of a task owned by user A. Report on B alone: the task shows its real title (not `#id`) and appears in the completed-task detail.
4. `By user` granularity shows one row per person.
5. Detail shows the **Assignee** column with more than one user, and does **not** with one.
6. **Export CSV** from a multi-user report contains the same people as the screen.
7. As a **project member** (not manager): no `Include` toggle, no Users panel, self-only numbers.
8. As that member, POST `scope=all` and separately a `user_ids[]` naming another user: both show the denial notice and only their own hours.
9. As that member, POST the CSV export with `user_ids[]`: the download is refused and they land back on the form — no file. Then confirm the denial page itself shows **no Export CSV button** at all.
9b. **Unassigned tasks.** Give the project a completed task with no assignee and `time_spent > 0`, then run `All users`: no participant `#0` appears and those hours are excluded.
10. A user who is manager on project A but only a member on project B: selecting project B with `All users` shows the denial notice.
11. The project ≡ menu **Generate report** is still self-only.

- [ ] **Step 6: Commit**

```bash
git add plugin.json Plugin.php CHANGELOG.md Test/PluginTest.php Test/PluginMetaTest.php
git commit -m "chore: bump version to 1.3.0"
```

---

## Post-implementation

Do **not** release without an explicit go-ahead. When approved, follow the suite release process: fast-forward the feature branch to `main`, push, tag `v1.3.0`, verify the CI zip asset (200, single folder, `Test/` excluded, version 1.3.0), then bump the `kanboard-modmenu-directory` entry's version and download URL. `recommends.min_version` for AiConnector stays `1.0.0`.

Because 1.3.0 corrects a misattribution bug that changes reported totals, the release note should lead with that fix rather than the new feature.
