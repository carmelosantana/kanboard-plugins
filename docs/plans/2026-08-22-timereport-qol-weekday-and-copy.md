# TimeReport v1.1.0 QoL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a day-of-week prefix to report dates (screen + Markdown, not CSV) and make single hours values one click to copy.

**Architecture:** Two presentation-only features on the existing TimeReport plugin. Feature 1 adds one shared helper method (`withWeekday`) called by the templates and the Markdown builder; the CSV builder deliberately does not call it. Feature 2 tags each hours cell with a `data-tr-copyval` attribute and extends the existing delegated JS handler to copy that value with a flash + badge. No model, schema, route, or dependency changes.

**Tech Stack:** Buildless Kanboard plugin — PHP ≥ 8.4, PicoDb (untouched here), vanilla delegated JS + jQuery/`KB` globals, plain CSS. PHPUnit against Kanboard v1.2.47 core.

**Repo:** The plugin is its own git repo at `/home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport` (branch `main`). All edits are host-side. This plan's spec lives in the monorepo at `docs/specs/2026-08-22-timereport-qol-weekday-and-copy-design.md`.

**Run the tests** (from the monorepo root `/home/carmelo/Projects/Kanboard/kanboard-plugins`):

```bash
./testing/run-plugin-tests.sh TimeReport
```

This runs the whole `TimeReport/Test/` suite (currently 59 tests / 184 assertions). There is no per-method filter; run the full suite for each verify step — it is fast.

## Global Constraints

- **Buildless.** No bundler/npm/transpile. What is committed ships. Plain PHP/JS/CSS only.
- **CSP.** No inline `<script>` and no `on*=` HTML handlers. All JS stays in `Assets/js/timereport.js`, event-delegated on `document`. Templates may only carry data-/aria-/role-/title attributes.
- **Helper access is a PROPERTY, never a call:** `$this->helper->timeReport->withWeekday(...)` — Kanboard's Helper has no `__call`. A `()` after `timeReport` is a fatal.
- **Weekday format is exactly** `Ddd YYYY-MM-DD` (e.g. `Mon 2026-08-10`) — abbreviated weekday from PHP `date('D', ...)` (English, locale-independent), a single space, then the ISO date.
- **Weekday appears on screen and in Markdown only. CSV keeps bare ISO dates.**
- **Copied value is the exact `formatHours(...)` 2-dp string**, carried in `data-tr-copyval` and copied verbatim.
- **No model/business-logic changes**, no settings toggle, no copy affordance on task-count integers or on date labels (YAGNI).
- **All 59 existing tests plus the new ones must pass** at every task boundary.
- **Version bump is a single coordinated change** (`plugin.json` + `Plugin.php` + the three version test assertions) — done last so earlier tasks keep the suite green.

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `Helper/TimeReportHelper.php` | `withWeekday()` method; Markdown wiring | 1, 2 |
| `Test/TimeReportHelperTest.php` | helper unit tests | 1, 2 |
| `Template/report/_breakdown.php` | weekday label + copy attrs on hours | 3 |
| `Template/report/_detail.php` | weekday date + copy attrs on hours | 3 |
| `Template/report/show.php` | copy attrs on grand-total hours | 3 |
| `Test/TemplateAssetsTest.php` | template + JS source assertions | 3, 4 |
| `Assets/js/timereport.js` | single-value copy branch + keydown + badge | 4 |
| `Assets/css/timereport.css` | `.tr-copy-num` / `.tr-copy-flash` / `.tr-copy-badge` | 4 |
| `plugin.json`, `Plugin.php` | version → 1.1.0 | 5 |
| `Test/PluginMetaTest.php`, `Test/PluginTest.php` | version assertions | 5 |

---

### Task 1: `withWeekday` helper method

**Files:**
- Modify: `Helper/TimeReportHelper.php` (add one public method after `formatHours`, ~line 16)
- Test: `Test/TimeReportHelperTest.php`

**Interfaces:**
- Produces: `public function withWeekday(string $date): string` — ISO date `YYYY-MM-DD` → `Ddd YYYY-MM-DD`; any non-ISO string returned unchanged. Consumed by Task 2 (Markdown) and Task 3 (templates).

- [ ] **Step 1: Write the failing tests**

Add to `Test/TimeReportHelperTest.php` (the class already has a `helper()` factory returning `new TimeReportHelper($this->container)`):

```php
public function testWithWeekdayPrefixesIsoDate(): void
{
    $h = $this->helper();
    $this->assertSame('Tue 2026-03-10', $h->withWeekday('2026-03-10'));
    $this->assertSame('Wed 2026-03-11', $h->withWeekday('2026-03-11'));
    $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{4}-\d{2}-\d{2}$/', $h->withWeekday('2026-08-10'));
}

public function testWithWeekdayLeavesNonIsoUnchanged(): void
{
    $h = $this->helper();
    $this->assertSame('Aug 10 – Aug 16', $h->withWeekday('Aug 10 – Aug 16'));
    $this->assertSame('#63 Some title', $h->withWeekday('#63 Some title'));
    $this->assertSame('Total', $h->withWeekday('Total'));
    $this->assertSame('', $h->withWeekday(''));
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — `Error: Call to undefined method ...::withWeekday()`.

- [ ] **Step 3: Implement the method**

In `Helper/TimeReportHelper.php`, immediately after the `formatHours` method (after line 16):

```php
/**
 * Prefix an ISO date (YYYY-MM-DD) with its abbreviated weekday: "Mon 2026-08-10".
 * Any string that is not a bare ISO date is returned unchanged, so it is safe to
 * call on any breakdown label (week ranges, task labels, "Total").
 */
public function withWeekday(string $date): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return $date;
    }
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return $date;
    }
    return date('D', $ts) . ' ' . $date;
}
```

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — all prior tests plus the two new ones green.

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Helper/TimeReportHelper.php Test/TimeReportHelperTest.php
git commit -m "feat: add withWeekday() date helper"
```

---

### Task 2: Weekday in Markdown, bare ISO in CSV

**Files:**
- Modify: `Helper/TimeReportHelper.php` — `toMarkdown()` only (leave `toCsv()` untouched)
- Test: `Test/TimeReportHelperTest.php`

**Interfaces:**
- Consumes: `withWeekday()` from Task 1.

The test fixture `sampleReport(true)` already has day-granularity breakdown labels `2026-03-10` / `2026-03-11` and a detail row with `date_completed => '2026-03-10'`. Weekdays: `2026-03-10` = **Tue**, `2026-03-11` = **Wed**.

- [ ] **Step 1: Write the failing tests**

Add to `Test/TimeReportHelperTest.php`:

```php
public function testMarkdownPrefixesDatesWithWeekday(): void
{
    $md = $this->helper()->toMarkdown($this->sampleReport(true));
    $this->assertStringContainsString('Tue 2026-03-10', $md); // breakdown label + detail date
    $this->assertStringContainsString('Wed 2026-03-11', $md); // breakdown label
}

public function testCsvKeepsBareIsoDatesNoWeekday(): void
{
    $csv = $this->helper()->toCsv($this->sampleReport(true));
    $this->assertStringContainsString('2026-03-10', $csv);
    $this->assertStringNotContainsString('Tue', $csv);
    $this->assertStringNotContainsString('Wed', $csv);
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — `testMarkdownPrefixesDatesWithWeekday` fails (Markdown still shows bare `2026-03-10`). `testCsvKeepsBareIsoDatesNoWeekday` passes already (CSV is unchanged) — that is fine.

- [ ] **Step 3: Wire `withWeekday` into `toMarkdown`**

In `Helper/TimeReportHelper.php::toMarkdown()`, wrap the breakdown label in **both** branches and the detail date. The three edits:

Task branch (currently line 37):
```php
$lines[] = '| ' . $this->withWeekday($row['label']) . ' | ' . $this->formatHours((float) $row['hours']) . ' |';
```

Non-task branch (currently line 39):
```php
$lines[] = '| ' . $this->withWeekday($row['label']) . ' | ' . $this->formatHours((float) $row['hours']) . ' | ' . (int) $row['task_count'] . ' |';
```

Detail row (currently lines 50–51) — wrap `$d['date_completed']`:
```php
$lines[] = '| ' . $d['reference'] . ' | ' . $d['title'] . ' | ' . $this->formatHours((float) $d['hours'])
    . ' | ' . $this->withWeekday($d['date_completed']) . ' | ' . $d['category'] . ' | ' . implode('; ', $d['tags']) . ' |';
```

Do **not** touch `toCsv()`. (`withWeekday` on a task-title label is a safe no-op thanks to the regex guard — task labels never match the ISO pattern.)

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — all tests green, including both new ones.

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Helper/TimeReportHelper.php Test/TimeReportHelperTest.php
git commit -m "feat: weekday prefix in Markdown dates (CSV stays bare ISO)"
```

---

### Task 3: Templates — weekday labels + click-to-copy hours cells

**Files:**
- Modify: `Template/report/_breakdown.php`
- Modify: `Template/report/_detail.php`
- Modify: `Template/report/show.php`
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `withWeekday()` (Task 1). Produces the `data-tr-copyval` / `tr-copy-num` markup that Task 4's JS binds to.

- [ ] **Step 1: Write the failing tests**

Add to `Test/TemplateAssetsTest.php` (it already has a `tpl($rel)` reader):

```php
public function testHoursCellsAreClickToCopy(): void
{
    foreach (['_breakdown.php', '_detail.php', 'show.php'] as $f) {
        $src = $this->tpl($f);
        $this->assertStringContainsString('data-tr-copyval', $src, "$f hours value must be click-to-copy");
        $this->assertStringContainsString('tr-copy-num', $src, "$f must mark the copyable cell");
    }
}

public function testBreakdownAndDetailDecorateDatesWithWeekday(): void
{
    $this->assertStringContainsString('withWeekday', $this->tpl('_breakdown.php'));
    $this->assertStringContainsString('withWeekday', $this->tpl('_detail.php'));
}
```

- [ ] **Step 2: Run the suite to verify the new tests fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — the two new assertions fail (markup not present yet).

- [ ] **Step 3: Edit `_breakdown.php`**

Replace the label cell (line 13) and the hours cell (line 14). New body of the row loop:

```php
        <?php foreach ($report['breakdown'] as $row): ?>
            <?php $trHours = $this->helper->timeReport->formatHours((float) $row['hours']); ?>
            <tr>
                <td><?= $this->text->e($this->helper->timeReport->withWeekday($row['label'])) ?></td>
                <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trHours) ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trHours) ?></td>
                <?php if (! $isTask): ?><td class="tr-num"><?= (int) $row['task_count'] ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
```

- [ ] **Step 4: Edit `_detail.php`**

Replace the hours cell (line 14) and the completed-date cell (line 15). New body of the row loop:

```php
        <?php foreach ($report['detail'] as $d): ?>
            <?php $trHours = $this->helper->timeReport->formatHours((float) $d['hours']); ?>
            <tr>
                <td><?= $this->text->e($d['reference']) ?></td>
                <td><?= $this->text->e($d['title']) ?></td>
                <td class="tr-num tr-copy-num" data-tr-copyval="<?= $this->text->e($trHours) ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trHours) ?></td>
                <td><?= $this->text->e($this->helper->timeReport->withWeekday($d['date_completed'])) ?></td>
                <td><?= $this->text->e($d['category']) ?></td>
                <td><?= $this->text->e(implode(', ', $d['tags'])) ?></td>
            </tr>
        <?php endforeach ?>
```

- [ ] **Step 5: Edit `show.php`**

Wrap the grand-total hours value (line 9) in a copyable span. Replace that line with:

```php
        <?php $trTotal = $this->helper->timeReport->formatHours((float) $report['total_hours']); ?>
        <strong><?= t('Total hours') ?>:</strong> <span class="tr-copy-num" data-tr-copyval="<?= $this->text->e($trTotal) ?>" role="button" tabindex="0" title="<?= t('Click to copy') ?>"><?= $this->text->e($trTotal) ?></span>
```

(The `<?php ... ?>` assignment must sit on the line above the `<p>`'s content or just inside the `<p>` — place it right before the `<strong>Range…` line so `$trTotal` is defined before use; putting it at the top of the `<p>` block is fine.)

- [ ] **Step 6: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — new template assertions pass, and `testNoInlineScriptOrHandlersInTemplates` still passes (data-/role-/tabindex-/title- attributes are not `on*` handlers and there is no `<script>`).

- [ ] **Step 7: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Template/report/_breakdown.php Template/report/_detail.php Template/report/show.php Test/TemplateAssetsTest.php
git commit -m "feat: weekday date labels + click-to-copy hours cells in templates"
```

---

### Task 4: JS single-value copy + CSS flash/badge

**Files:**
- Modify: `Assets/js/timereport.js` (rewrite — keep the Markdown-copy behavior, add the value-copy branch + keydown)
- Modify: `Assets/css/timereport.css` (append the copy styles)
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: the `[data-tr-copyval]` / `tr-copy-num` markup from Task 3 and the `[data-tr-copy]` Markdown button (unchanged).

- [ ] **Step 1: Write the failing test**

Add to `Test/TemplateAssetsTest.php`:

```php
public function testJsHandlesSingleValueCopyAndKeyboard(): void
{
    $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
    $this->assertStringContainsString('data-tr-copyval', $js, 'JS must copy single values');
    $this->assertStringContainsString('keydown', $js, 'JS must support Enter/Space copy');
    $this->assertStringContainsString('tr-copy-badge', $js, 'JS must show the Copied badge');
}
```

- [ ] **Step 2: Run the suite to verify the new test fails**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — `data-tr-copyval` / `keydown` / `tr-copy-badge` not yet in the JS.

- [ ] **Step 3: Rewrite `Assets/js/timereport.js`**

Replace the whole file with (one delegated `click` listener handling both copy modes, plus a `keydown` listener for accessibility):

```js
// TimeReport — CSP-safe, event-delegated clipboard helpers:
//   [data-tr-copy]    → copy the full Markdown payload (#tr-markdown)
//   [data-tr-copyval] → copy a single value (e.g. an hours figure) with a flash + badge
(function () {
    "use strict";

    function fallbackCopy(text) {
        var tmp = document.createElement("textarea");
        tmp.value = text;
        tmp.setAttribute("readonly", "");
        tmp.style.position = "absolute";
        tmp.style.left = "-9999px";
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand("copy"); } catch (err) { /* no-op */ }
        document.body.removeChild(tmp);
    }

    function copyText(text, onDone) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onDone).catch(function () {
                fallbackCopy(text);
                onDone();
            });
        } else {
            fallbackCopy(text);
            onDone();
        }
    }

    function flashValue(cell) {
        cell.classList.add("tr-copy-flash");
        setTimeout(function () { cell.classList.remove("tr-copy-flash"); }, 600);
        var badge = document.createElement("span");
        badge.className = "tr-copy-badge";
        badge.textContent = cell.getAttribute("data-tr-copied") || "Copied ✓";
        cell.appendChild(badge);
        setTimeout(function () {
            if (badge.parentNode) { badge.parentNode.removeChild(badge); }
        }, 1200);
    }

    function copyValue(cell) {
        var val = cell.getAttribute("data-tr-copyval");
        if (val === null) { return; }
        copyText(val, function () { flashValue(cell); });
    }

    function copyMarkdown(btn) {
        var ta = document.getElementById("tr-markdown");
        if (!ta) { return; }
        copyText(ta.value, function () {
            var original = btn.textContent;
            btn.textContent = btn.getAttribute("data-tr-copied") || "Copied";
            setTimeout(function () { btn.textContent = original; }, 1500);
        });
    }

    document.addEventListener("click", function (e) {
        var valCell = e.target.closest("[data-tr-copyval]");
        if (valCell) {
            e.preventDefault();
            copyValue(valCell);
            return;
        }
        var btn = e.target.closest("[data-tr-copy]");
        if (btn) {
            e.preventDefault();
            copyMarkdown(btn);
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") { return; }
        var valCell = e.target.closest ? e.target.closest("[data-tr-copyval]") : null;
        if (!valCell) { return; }
        e.preventDefault();
        copyValue(valCell);
    });
})();
```

- [ ] **Step 4: Append the copy styles to `Assets/css/timereport.css`**

```css
.tr-copy-num { cursor: pointer; position: relative; }
.tr-copy-num:hover { background: rgba(75,159,213,0.10); }
.tr-copy-num:focus { outline: 2px solid rgba(75,159,213,0.6); outline-offset: -2px; }
.tr-copy-flash { background: rgba(75,159,213,0.30); }
.tr-copy-badge {
    position: absolute; top: -0.6em; right: 0.25em;
    font-size: 0.7em; line-height: 1; padding: 0.15em 0.4em;
    background: #4b9fd5; color: #fff; border-radius: 3px;
    pointer-events: none; white-space: nowrap;
    animation: tr-copy-fade 1.2s ease-out forwards;
}
@keyframes tr-copy-fade { 0% { opacity: 0; } 15% { opacity: 1; } 80% { opacity: 1; } 100% { opacity: 0; } }
```

(Accent `#4b9fd5` matches the existing `.tr-ai` border already in this file.)

- [ ] **Step 5: Run the suite to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — the new JS test passes and the existing `testJsUsesClipboardAndDelegation` (asserts `navigator.clipboard` + `data-tr-copy`) still passes.

- [ ] **Step 6: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add Assets/js/timereport.js Assets/css/timereport.css Test/TemplateAssetsTest.php
git commit -m "feat: click/keyboard copy of single hours values with flash + badge"
```

---

### Task 5: Bump version to 1.1.0

**Files:**
- Modify: `plugin.json` (`version`)
- Modify: `Plugin.php` (`getPluginVersion`)
- Modify: `Test/PluginMetaTest.php`
- Modify: `Test/PluginTest.php`

**Interfaces:** none — release bookkeeping. Do this last so the earlier tasks run against the stable version assertions.

- [ ] **Step 1: Update the version test assertions (they will now fail against 1.0.0)**

In `Test/PluginMetaTest.php`, change `testVersionIsExactly100`:

```php
public function testVersionIsExactly110(): void
{
    $this->assertSame('1.1.0', $this->json()['version']);
}
```

In `Test/PluginTest.php`, `testMetadataVersionIs100` (line 15) → assert `'1.1.0'`:

```php
$this->assertSame('1.1.0', $plugin->getPluginVersion());
```

and in `testVersionMatchesPluginJson` (line 27):

```php
$this->assertSame('1.1.0', $json['version']);
```

(Renaming the two `...100` methods to `...110` is optional but tidy; the assertions are what matter.)

- [ ] **Step 2: Run the suite to verify these now fail**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: FAIL — version assertions expect `1.1.0` but the sources still say `1.0.0`.

- [ ] **Step 3: Bump `plugin.json`**

Change line 4:

```json
    "version": "1.1.0",
```

- [ ] **Step 4: Bump `Plugin.php`**

In `getPluginVersion()` (line 79):

```php
        return '1.1.0';
```

- [ ] **Step 5: Run the suite to verify everything passes**

Run: `./testing/run-plugin-tests.sh TimeReport`
Expected: PASS — full suite green (original 59 + the new weekday/copy tests), including the 1.1.0 version checks.

- [ ] **Step 6: Commit**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/TimeReport
git add plugin.json Plugin.php Test/PluginMetaTest.php Test/PluginTest.php
git commit -m "chore: bump version to 1.1.0"
```

---

## Post-implementation (controller/human, not a task)

After all tasks are green and the branch is reviewed:

1. **Live verification** on `kb-suite` (`:8081`, admin/admin) against the demo project: day-granularity report shows `Mon 2026-08-10` labels; the Markdown copy includes weekdays; the CSV download has bare ISO dates; clicking any hours value copies it with a flash + `Copied ✓` badge; Enter/Space on a focused value copies it; the existing "Copy as Markdown" button still works.
2. **Release** (per the suite methodology `references/release.md`): push tag `v1.1.0` (bare, equals `plugin.json`), confirm the CI-built `TimeReport-1.1.0.zip` asset downloads 200 and is a clean single-folder zip with `Test/` excluded.
3. **Directory bump:** update the `kanboard-modmenu-directory/plugins.json` TimeReport entry's download URL to the `v1.1.0` asset.
4. Update the `timereport-plugin-status` memory to record v1.1.0 shipped.

## Self-Review

- **Spec coverage:** Feature 1 (helper Task 1, Markdown Task 2, templates Task 3, CSV-bare asserted Task 2); Feature 2 (template attrs Task 3, JS+CSS Task 4); versioning Task 5; tests in every task. All spec sections map to a task.
- **Placeholder scan:** none — every code step carries complete code and exact expected output.
- **Type consistency:** `withWeekday(string): string` is defined in Task 1 and consumed with that exact signature in Tasks 2–3. `data-tr-copyval` / `tr-copy-num` / `tr-copy-flash` / `tr-copy-badge` names match across Tasks 3 (markup), 4 (JS + CSS), and the tests. `formatHours` is the single source of the copied value and the displayed value.
