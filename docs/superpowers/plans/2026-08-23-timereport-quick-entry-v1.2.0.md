# TimeReport v1.2.0 Quick-Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a project's ≡ menu, jump straight to that project's report (one click) or open the report form with the project pre-selected.

**Architecture:** Two entry links attached to Kanboard's `template:project:dropdown` hook (which passes `$project`). A new read-only GET action `view()` renders a report with fixed quick defaults; `index()` gains an optional `project_id` pre-fill. No model, schema, route-semantics, or dependency changes beyond one new GET route.

**Tech Stack:** Buildless Kanboard plugin — PHP ≥ 8.4, PicoDb (untouched), plain PHP templates, CSP-safe. PHPUnit against Kanboard v1.2.47 core.

**Repo:** Plugin repo `/home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport` (branch `main`, currently at v1.1.0). Edits host-side. Spec: `docs/superpowers/specs/2026-08-23-timereport-quick-entry-v1.2.0-design.md`.

**Run the tests** (from monorepo root `/home/carmelo/Projects/Kanboard/kanboard-plugins`):

```bash
./testing/run-plugin-tests.sh TimeReport
```

Whole `TimeReport/Test/` suite (67 tests / 212 assertions at 1.1.0). No per-method filter; run the full suite each verify step.

## Global Constraints

- **Buildless.** Plain PHP/JS/CSS; what is committed ships.
- **CSP.** No inline `<script>`, no `on*=` handlers. The project-menu partial is plain `<li>` links via `$this->url->icon(...)` — no JS at all.
- **Helper access is a PROPERTY:** `$this->helper->timeReport->toMarkdown(...)`.
- **`view()` is a read-only GET with NO CSRF** — it changes no state, only reads the user's own data and renders. `generate()` and `exportCsv()` stay POST + `checkCSRFForm()` and are NOT modified.
- **Self-only invariant:** `view()` mines `$this->userSession->getId()`'s data; the project is access-guarded by the model's existing `assertProjectAccess` (which throws `Kanboard\Core\Controller\AccessForbiddenException`). `view()` catches that and redirects to the form — never surfaces an error page.
- **Quick defaults are FIXED:** range this-month-to-date (`date('Y-m-01')`…`date('Y-m-d')`), granularity `task`, `include_detail` false, no AI.
- **Header user-dropdown is unchanged.** No settings, no split-button, no sidebar (all YAGNI).
- **tag == version == getPluginVersion**, all `1.2.0` (bump done last).
- **All 67 existing tests plus new ones pass** at every task boundary.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `Controller/TimeReportController.php` | `view()` + `quickReport()` seam; `index()` pre-fill + `prefillProjectId()` seam | 1 |
| `Plugin.php` | register `timereport/view` route (T1); attach `template:project:dropdown` (T2); version (T3) |
| `Template/project/menu.php` (new) | the two ≡-menu `<li>` links | 2 |
| `Test/TimeReportControllerTest.php` | behavioral: quickReport defaults + access guard; prefill; view source | 1 |
| `Test/TemplateAssetsTest.php` | menu partial links + CSP; Plugin route/hook wiring | 2 |
| `plugin.json`, `Test/PluginMetaTest.php`, `Test/PluginTest.php` | version → 1.2.0 | 3 |

---

### Task 1: Controller — `view()` quick action + `index()` pre-fill

**Files:**
- Modify: `Controller/TimeReportController.php`
- Modify: `Plugin.php` (add the `timereport/view` route only)
- Test: `Test/TimeReportControllerTest.php`

**Interfaces:**
- Produces: `public function view(): void`; protected `quickReport(int $projectId, int $userId): array` (fixed-default report or throws `AccessForbiddenException`); protected `prefillProjectId(int $requested, array $projects): int` (accessible id or 0). Task 2 relies on the `timereport/view` route existing.

- [ ] **Step 1: Write the failing tests**

Add to `Test/TimeReportControllerTest.php` (the class already has `source()` returning the controller file):

```php
public function testQuickReportDefaultsToThisMonthPerTaskNoDetail(): void
{
    $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);
    $this->container['timeReportModel'] = function ($c) {
        return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
    };
    $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
        public function quickPublic(int $projectId, int $userId): array { return $this->quickReport($projectId, $userId); }
    };
    $report = $controller->quickPublic($projectId, 1);
    $this->assertSame('task', $report['granularity']);
    $this->assertSame(date('Y-m-01'), $report['start_date']);
    $this->assertSame(date('Y-m-d'), $report['end_date']);
    $this->assertFalse($report['include_detail']);
}

public function testQuickReportAccessGuardThrowsForInaccessibleProject(): void
{
    $this->container['timeReportModel'] = function ($c) {
        return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
    };
    $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
        public function quickPublic(int $projectId, int $userId): array { return $this->quickReport($projectId, $userId); }
    };
    $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
    $controller->quickPublic(999999, 1);
}

public function testPrefillProjectIdOnlySelectsAccessibleProject(): void
{
    $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
        public function prefillPublic(int $requested, array $projects): int { return $this->prefillProjectId($requested, $projects); }
    };
    $projects = [5 => 'Acme', 8 => 'Beta'];
    $this->assertSame(5, $controller->prefillPublic(5, $projects));
    $this->assertSame(0, $controller->prefillPublic(7, $projects), 'inaccessible id not selected');
    $this->assertSame(0, $controller->prefillPublic(0, $projects), 'no id → no selection');
}

public function testViewIsReadOnlyGetWithAccessRedirect(): void
{
    $src = $this->source();
    $this->assertStringContainsString('function view(', $src, 'quick view action exists');
    $this->assertStringContainsString('getIntegerParam', $src, 'view/index read project_id from the query');
    $this->assertStringContainsString('AccessForbiddenException', $src, 'view catches the access exception and redirects');
    $this->assertStringContainsString('report/show', $src, 'view renders the report view');
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — `quickReport` / `prefillProjectId` / `view` undefined.

- [ ] **Step 3: Add the import and the two seams + `view()` to the controller**

In `Controller/TimeReportController.php`, add the import near the top (after the existing `use ... AiGate;`):

```php
use Kanboard\Core\Controller\AccessForbiddenException;
```

Add the `view()` action after `generate()` (keep it above `exportCsv()` or after — placement is free):

```php
/** One-click report for a project: read-only GET, fixed quick defaults. No CSRF (no state change). */
public function view(): void
{
    $userId    = $this->userSession->getId();
    $projectId = $this->request->getIntegerParam('project_id');

    try {
        $report = $this->quickReport($projectId, $userId);
    } catch (AccessForbiddenException $e) {
        $this->response->redirect($this->helper->url->to('TimeReportController', 'index', ['plugin' => 'TimeReport']));
        return;
    }

    $markdown = $this->helper->timeReport->toMarkdown($report);

    $this->response->html($this->helper->layout->app('TimeReport:report/show', [
        'title'      => t('Time Report'),
        'report'     => $report,
        'markdown'   => $markdown,
        'ai_enabled' => $this->isAiEnabled(),
    ]));
}

/** Fixed quick defaults: this month to date, per task, no detail, no AI. Access-guarded by the model. */
protected function quickReport(int $projectId, int $userId): array
{
    $report = $this->timeReportModel->report($projectId, date('Y-m-01'), date('Y-m-d'), 'task', false, $userId);
    $report['include_detail'] = false;
    return $report;
}

/** The project id to pre-select in the form, or 0 when the requested id isn't in the user's accessible list. */
protected function prefillProjectId(int $requested, array $projects): int
{
    return ($requested > 0 && isset($projects[$requested])) ? $requested : 0;
}
```

- [ ] **Step 4: Wire the pre-fill into `index()`**

In `index()`, replace the inline `'values' => [ ... ]` array with a `$values` built beforehand that includes the optional pre-fill. The method becomes:

```php
public function index(): void
{
    $userId = $this->userSession->getId();
    $projectIds = $this->projectPermissionModel->getActiveProjectIds($userId);
    $projects = [];
    foreach ($projectIds as $pid) {
        $p = $this->projectModel->getById((int) $pid);
        if (! empty($p)) {
            $projects[(int) $pid] = $p['name'];
        }
    }

    $values = [
        'start_date'  => date('Y-m-01'),
        'end_date'    => date('Y-m-d'),
        'granularity' => 'day',
    ];
    $selected = $this->prefillProjectId($this->request->getIntegerParam('project_id'), $projects);
    if ($selected > 0) {
        $values['project_id'] = $selected;
    }

    $this->response->html($this->helper->layout->app('TimeReport:report/form', [
        'title'      => t('Time Report'),
        'projects'   => $projects,
        'ai_enabled' => $this->isAiEnabled(),
        'profiles'   => $this->aiProfiles(),
        'values'     => $values,
    ]));
}
```

- [ ] **Step 5: Register the `view` route in `Plugin.php`**

In `Plugin.php::initialize()`, in the Routes block (after the `timereport/export-csv` route):

```php
$this->route->addRoute('timereport/view', 'TimeReportController', 'view', 'TimeReport');
```

- [ ] **Step 6: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — all prior tests plus the four new ones.

- [ ] **Step 7: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Controller/TimeReportController.php Plugin.php Test/TimeReportControllerTest.php
git commit -m "feat: one-click project report (view) + form project pre-fill"
```

---

### Task 2: Project ≡ menu entry links

**Files:**
- Create: `Template/project/menu.php`
- Modify: `Plugin.php` (attach `template:project:dropdown`)
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: the `view` route (Task 1) and the existing `index` route; the `$project` variable that core passes to `template:project:dropdown`.

- [ ] **Step 1: Write the failing tests**

Add to `Test/TemplateAssetsTest.php`:

```php
public function testProjectMenuPartialLinksBothEntries(): void
{
    $src = file_get_contents(dirname(__DIR__) . '/Template/project/menu.php');
    $this->assertStringNotContainsString('<script', $src, 'menu partial must not contain inline <script> (CSP)');
    $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, 'menu partial must not contain inline on* handlers (CSP)');
    $this->assertStringContainsString("'view'", $src, 'menu must link the quick view action');
    $this->assertStringContainsString("'index'", $src, 'menu must link the report form');
    $this->assertStringContainsString("project['id']", $src, 'menu links must carry the project id');
}

public function testPluginWiresProjectMenuAndViewRoute(): void
{
    $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
    $this->assertStringContainsString("addRoute('timereport/view'", $plugin, 'view route must be registered');
    $this->assertStringContainsString('template:project:dropdown', $plugin, 'project ≡ menu hook must be attached');
    $this->assertStringContainsString('TimeReport:project/menu', $plugin, 'the menu partial must be attached to the hook');
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — the menu file doesn't exist and the hook isn't attached. (`addRoute('timereport/view'` already passes from Task 1 — that's fine.)

- [ ] **Step 3: Create `Template/project/menu.php`**

```php
<?php
/** Two TimeReport entry links in the project ≡ menu (hook template:project:dropdown; core passes $project). */
?>
<li>
    <?= $this->url->icon('bolt', t('Generate report'), 'TimeReportController', 'view', ['plugin' => 'TimeReport', 'project_id' => $project['id']]) ?>
</li>
<li>
    <?= $this->url->icon('clock-o', t('Time Report'), 'TimeReportController', 'index', ['plugin' => 'TimeReport', 'project_id' => $project['id']]) ?>
</li>
```

- [ ] **Step 4: Attach the hook in `Plugin.php`**

In `Plugin.php::initialize()`, next to the existing `template:header:dropdown` attach:

```php
$this->template->hook->attach('template:project:dropdown', 'TimeReport:project/menu');
```

- [ ] **Step 5: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — the menu partial + wiring assertions pass; `testNoInlineScriptOrHandlersInTemplates` is unaffected (it lists `report/*` templates; the new file has no script/handlers regardless).

- [ ] **Step 6: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Template/project/menu.php Plugin.php Test/TemplateAssetsTest.php
git commit -m "feat: project ≡ menu links (Generate report + Time Report)"
```

---

### Task 3: Bump version to 1.2.0

**Files:**
- Modify: `plugin.json`, `Plugin.php`, `Test/PluginMetaTest.php`, `Test/PluginTest.php`

**Interfaces:** none — release bookkeeping, done last so earlier tasks run against stable assertions.

- [ ] **Step 1: Update the version test assertions (they will now fail against 1.1.0)**

In `Test/PluginMetaTest.php`, the exact-version test (currently `testVersionIsExactly110` asserting `1.1.0`) → assert `1.2.0`:

```php
public function testVersionIsExactly120(): void
{
    $this->assertSame('1.2.0', $this->json()['version']);
}
```

In `Test/PluginTest.php`, both hard-coded assertions → `1.2.0`:

```php
$this->assertSame('1.2.0', $plugin->getPluginVersion());
```
```php
$this->assertSame('1.2.0', $json['version']);
```

- [ ] **Step 2: Run the suite to verify these now fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — version assertions expect `1.2.0` but sources still say `1.1.0`.

- [ ] **Step 3: Bump `plugin.json`**

```json
    "version": "1.2.0",
```

- [ ] **Step 4: Bump `Plugin.php`**

In `getPluginVersion()`:

```php
        return '1.2.0';
```

- [ ] **Step 5: Run the suite to verify everything passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — full suite green including the 1.2.0 checks.

- [ ] **Step 6: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add plugin.json Plugin.php Test/PluginMetaTest.php Test/PluginTest.php
git commit -m "chore: bump version to 1.2.0"
```

---

## Post-implementation (controller/human, not a task)

1. **Live verification** on `kb-suite` (`:8081`): a demo project's ≡ menu shows **Generate report** + **Time Report…**; "Generate report" lands on the per-task, this-month report for that project (Copy-as-Markdown + CSV work); "Time Report…" opens the form with the project pre-selected; the header "Time Report" still opens the empty form; a project the user can't access (hand-crafted `timereport/view?project_id=<other>`) redirects to the form rather than erroring.
2. **Release:** push tag `v1.2.0`; confirm CI zip `TimeReport-1.2.0.zip` downloads 200, single folder, `Test/` excluded.
3. **Directory bump:** `kanboard-modmenu-directory/plugins.json` TimeReport entry → 1.2.0 + v1.2.0 download URL.
4. Update the `timereport-plugin-status` memory to v1.2.0.

## Self-Review

- **Spec coverage:** item 1 deep-link (index pre-fill, Task 1; menu "Time Report…" link, Task 2); item 2 one-click report (`view()`, Task 1; menu "Generate report" link, Task 2); version Task 3. All spec sections map to a task.
- **Placeholder scan:** none — every code step has complete code and exact expected output.
- **Type consistency:** `view()`, `quickReport(int,int):array`, `prefillProjectId(int,array):int` defined in Task 1 and asserted with those signatures in the Task 1 tests; the `timereport/view` route (Task 1) and `TimeReport:project/menu` partial (Task 2) names match between `Plugin.php`, the template path, and the Task 2 assertions. Quick defaults (`task`, `date('Y-m-01')`, `date('Y-m-d')`, `include_detail` false) match between `quickReport()` and its test.
