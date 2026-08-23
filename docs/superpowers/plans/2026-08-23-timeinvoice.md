# TimeInvoice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the TimeInvoice Kanboard plugin — persisted PDF invoices generated from TimeReport's hours aggregate, with a `draft → sent → paid` lifecycle and gap-free per-year numbering.

**Architecture:** A buildless Kanboard plugin (own repo `kanboard-time-invoice`) that `requires` TimeReport and calls `timeReportModel->report()` for hours. Invoices persist as JSON blobs in `project_has_metadata`; global defaults + the number counter live in the `settings` table via `ConfigModel`. Line-item math and number formatting are pure, unit-testable functions. PDFs render server-side via a single vendored FPDF file, isolated behind `Model/InvoicePdf.php`.

**Tech Stack:** PHP ≥ 8.4, Kanboard core models (`ProjectMetadataModel`, `ConfigModel`, `ProjectPermissionModel`), vendored FPDF (public, MIT-compatible license), vanilla JS + jQuery + global `KB`, plain CSS. PHPUnit via `testing/run-plugin-tests.sh`.

## Global Constraints

- **Buildless:** PHP ≥ 8.4; vanilla JS + jQuery + global `KB`; plain CSS. No bundler/npm/Composer build step. What is committed ships.
- **One repo per plugin:** `plugin.json` at repo root; release by pushing bare tag `vX.Y.Z` == `plugin.json.version` == `Plugin.php::getPluginVersion()`.
- **Dependency shape:** `requires` is `[{ "plugin": "TimeReport", "min_version": "1.1.0", "reason": "…" }]` — array of objects, **bare** semver (no `>=`), in **both** `plugin.json` and `Plugin.php` metadata. The object-map form is silently dropped by ModMenu.
- **`kanboard_version` / `php_version`** carry the `>=` prefix (`">=1.2.47"`, `">=8.4"`); dependency `min_version` does not.
- **CSP-safe:** all JS in `Assets/js/timeinvoice.js` (delegated via `KB`/jQuery from `document`); no inline `<script>` or `onclick=`.
- **Edit on the host**, never inside the `kb-suite` container. Dev stack `:8081` (admin/admin). Drive dev DB via curl/PDO — never the production Kanboard MCP.
- **No DB migration:** persist via `project_has_metadata` (invoices, per-project defaults) and `settings` (global defaults + counter).
- **Storage keys:** invoices `timeinvoice:inv:<id>`; per-project defaults `timeinvoice:defaults`; global config keys prefixed `timeinvoice_`; counter key `timeinvoice_counter`.
- **Self-scoped:** every route enforces `assertProjectAccess` (reused from TimeReport's model) and operates on the current user's own data.
- **Author/License/Homepage:** `Carmelo Santana` / `MIT` / `https://github.com/carmelosantana/kanboard-time-invoice`.
- **Money math:** all currency amounts `round($x, 2)`; hours as float.

## File structure

```
TimeInvoice/
├── plugin.json                       # metadata + requires:[TimeReport 1.1.0]
├── Plugin.php                        # initialize(): services, routes, hooks, assets; getters
├── Controller/
│   ├── InvoiceController.php          # list / form / saveDraft / send / markPaid / delete / pdf
│   └── SettingsController.php         # settings / saveSettings
├── Model/
│   ├── InvoiceBuilder.php             # PURE: report() breakdown → line items + subtotal/tax/total
│   ├── InvoiceNumber.php              # PURE: {year:seq} map + date + format → next number
│   ├── InvoiceModel.php               # CRUD + counter over project_has_metadata + settings
│   ├── DefaultsResolver.php           # PURE: layer global ⊕ project ⊕ form defaults
│   └── InvoicePdf.php                 # snapshot array → PDF bytes via vendored FPDF
├── Helper/
│   └── InvoiceHelper.php              # money / date / status-badge / currency-encode for templates
├── Template/invoice/
│   ├── list.php                       # project + global invoice list
│   ├── form.php                       # new/edit draft form
│   ├── _line_items.php                # live preview partial (draft)
│   ├── settings.php                   # global defaults form
│   ├── sidebar.php                    # project-sidebar "Invoices" link
│   └── header_dropdown.php            # header "All invoices" link
├── Assets/
│   ├── css/timeinvoice.css
│   ├── js/timeinvoice.js              # delegated, CSP-safe
│   └── vendor/fpdf/{fpdf.php,LICENSE.txt,font/…}
├── Test/
│   ├── PluginMetaTest.php   PluginTest.php   InvoiceBuilderTest.php
│   ├── InvoiceNumberTest.php   DefaultsResolverTest.php   InvoiceModelTest.php
│   ├── InvoicePdfTest.php   InvoiceHelperTest.php
│   ├── InvoiceControllerTest.php   TemplateAssetsTest.php
├── LICENSE   README.md
└── .github/workflows/release.yml
```

**Test bootstrap (every test file):** first line `require_once 'tests/units/Base.php';` then `use KanboardTests\units\Base;` and `class XTest extends Base`. Tests run from `testing/kanboard-src/` via `./testing/run-plugin-tests.sh TimeInvoice`. `Base` provides `$this->container` (with `$this->container['db']` on `:memory:` sqlite).

**Dev-harness note (one-time):** `testing/run-plugin-tests.sh` hardcodes the plugin symlink list (two places). Add `TimeInvoice` to both loops so the runner links `testing/kanboard-src/plugins/TimeInvoice`. This is a commit in the **monorepo**, not the plugin repo (Task 0).

---

### Task 0: Dev-harness wiring (monorepo)

**Files:**
- Modify: `testing/run-plugin-tests.sh` (the `Available plugins:` echo line and the `for p in …` symlink loop — add `TimeInvoice`)

- [ ] **Step 1: Add TimeInvoice to the symlink loop and usage list**

In `testing/run-plugin-tests.sh`, change both occurrences of the plugin list to include `TimeInvoice` (alphabetical, after `TimeReport`). The `for p in` loop:

```bash
for p in AiConnector BulkProjectDelete CalendarPlugin DependencyPlugin FeatureSync Kensho ModMenu SchedulerPlugin ShadcnTheme SubtaskGenerator TimeBlock TimeReport TimeInvoice; do
```

and the usage echo `Available plugins: …  TimeReport  TimeInvoice`.

- [ ] **Step 2: Commit (monorepo)**

```bash
git add testing/run-plugin-tests.sh
git commit -m "dev-harness: wire TimeInvoice into run-plugin-tests symlinks"
```

> All remaining tasks operate inside the `TimeInvoice/` plugin directory, which is its **own git repo**. Create it first: `mkdir -p TimeInvoice && cd TimeInvoice && git init`. Commits in Tasks 1–14 are in that repo.

---

### Task 1: Scaffold + plugin metadata

**Files:**
- Create: `TimeInvoice/plugin.json`, `TimeInvoice/Plugin.php`, `TimeInvoice/LICENSE`, `TimeInvoice/README.md`, `TimeInvoice/.github/workflows/release.yml`
- Test: `TimeInvoice/Test/PluginMetaTest.php`, `TimeInvoice/Test/PluginTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\TimeInvoice\Plugin` with getters (`getPluginName`→`'TimeInvoice'`, `getPluginVersion`→`'0.1.0'`, `getPluginAuthor`, `getPluginDescription`, `getPluginHomepage`, `getPluginLicense`→`'MIT'`, `getCompatibleVersion`→`'>=1.2.47'`), `isPhpCompatible(?int)`, and `requiresTimeReport(): bool` (defensive gate: `isset($this->container['timeReportModel'])`).

- [ ] **Step 1: Write the failing meta tests**

`TimeInvoice/Test/PluginMetaTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testVersionIs010(): void
    {
        $this->assertSame('0.1.0', $this->json()['version']);
    }

    public function testNameAndCompat(): void
    {
        $j = $this->json();
        $this->assertSame('TimeInvoice', $j['name']);
        $this->assertSame('>=1.2.47', $j['kanboard_version']);
        $this->assertSame('>=8.4', $j['php_version']);
        $this->assertSame('MIT', $j['license']);
    }

    /** requires must be an ARRAY of objects with a bare min_version (no ">=" prefix). */
    public function testRequiresArrayShape(): void
    {
        $j = $this->json();
        $this->assertArrayNotHasKey('recommends', $j, 'v1 declares no recommends');
        $this->assertSame(['TimeReport'], array_column($j['requires'], 'plugin'));
        $this->assertSame('1.1.0', $j['requires'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['requires'][0]['min_version']);
        $this->assertNotEmpty($j['requires'][0]['reason']);
    }
}
```

`TimeInvoice/Test/PluginTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Plugin;

class PluginTest extends Base
{
    public function testMetadata(): void
    {
        $p = new Plugin($this->container);
        $this->assertSame('TimeInvoice', $p->getPluginName());
        $this->assertSame('0.1.0', $p->getPluginVersion());
        $this->assertSame('Carmelo Santana', $p->getPluginAuthor());
        $this->assertSame('MIT', $p->getPluginLicense());
        $this->assertSame('>=1.2.47', $p->getCompatibleVersion());
        $this->assertNotEmpty($p->getPluginDescription());
        $this->assertStringContainsString('github.com/carmelosantana/kanboard-time-invoice', $p->getPluginHomepage());
    }

    public function testVersionMatchesJson(): void
    {
        $json = json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
        $this->assertSame($json['version'], (new Plugin($this->container))->getPluginVersion());
    }

    public function testPhpGate(): void
    {
        $p = new Plugin($this->container);
        $this->assertTrue($p->isPhpCompatible(80400));
        $this->assertFalse($p->isPhpCompatible(80300));
    }

    public function testRequiresGateReflectsContainer(): void
    {
        $p = new Plugin($this->container);
        $this->assertFalse($p->requiresTimeReport(), 'no timeReportModel registered in bare container');
        $this->container['timeReportModel'] = fn ($c) => new \stdClass();
        $this->assertTrue($p->requiresTimeReport());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `plugin.json` / `Plugin` class not found.

- [ ] **Step 3: Create plugin.json**

`TimeInvoice/plugin.json`:

```json
{
    "name": "TimeInvoice",
    "description": "Generate polished PDF invoices from your TimeReport hours: pick a project and date range, roll up line items by task/day/week, set rate and tax, and track invoices through draft → sent → paid with stable per-year numbering.",
    "version": "0.1.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-time-invoice",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "requires": [
        { "plugin": "TimeReport", "min_version": "1.1.0", "reason": "supplies the deduped hours aggregate (report()) that invoice line items are built from" }
    ]
}
```

- [ ] **Step 4: Create Plugin.php (metadata + gates only; wiring added in later tasks)**

`TimeInvoice/Plugin.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Model services and route/hook wiring are added by later tasks.
    }

    /** Defensive dependency gate: TimeReport registers timeReportModel on the container. */
    public function requiresTimeReport(): bool
    {
        return isset($this->container['timeReportModel']);
    }

    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    public function getPluginName(): string        { return 'TimeInvoice'; }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginLicense(): string     { return 'MIT'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-time-invoice'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }

    public function getPluginDescription(): string
    {
        return t('Generate polished PDF invoices from your TimeReport hours, tracked through draft, sent and paid with per-year numbering.');
    }
}
```

- [ ] **Step 5: Create LICENSE (MIT), README.md, and the release workflow**

`TimeInvoice/.github/workflows/release.yml` — copy **verbatim** from `references/release.md` (name: release; on push tags `v*`; the tag/version gate + rsync-exclude `.git .github Test .DS_Store` + single-top-folder zip + `gh release create`). `LICENSE` = standard MIT (`Copyright (c) 2026 Carmelo Santana`). `README.md` = short intro (what it does, requires TimeReport, install via ModMenu).

- [ ] **Step 6: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS (PluginMetaTest, PluginTest).

- [ ] **Step 7: Commit**

```bash
git add TimeInvoice
git commit -m "feat: scaffold TimeInvoice plugin (metadata, gates, release CI)"
```

---

### Task 2: InvoiceBuilder — line items + totals (PURE)

**Files:**
- Create: `TimeInvoice/Model/InvoiceBuilder.php`
- Test: `TimeInvoice/Test/InvoiceBuilderTest.php`

**Interfaces:**
- Consumes: a TimeReport `report()` aggregate array with key `breakdown` = `list<array{key:string,label:string,hours:float,task_count:int}>`.
- Produces: `InvoiceBuilder::lineItems(array $breakdown, float $rate): array` → `list<array{label:string,hours:float,amount:float}>`; and `InvoiceBuilder::totals(array $lineItems, bool $taxEnabled, float $taxRate): array{subtotal:float,tax:float,total:float}`.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/InvoiceBuilderTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder;

class InvoiceBuilderTest extends Base
{
    public function testLineItemsMapBreakdownRowsAndMultiply(): void
    {
        $breakdown = [
            ['key' => '1', 'label' => '#1 Homepage', 'hours' => 8.5, 'task_count' => 1],
            ['key' => '2', 'label' => '#2 API',      'hours' => 3.0, 'task_count' => 1],
        ];
        $items = InvoiceBuilder::lineItems($breakdown, 150.0);
        $this->assertSame('#1 Homepage', $items[0]['label']);
        $this->assertSame(8.5, $items[0]['hours']);
        $this->assertSame(1275.0, $items[0]['amount']);
        $this->assertSame(450.0, $items[1]['amount']);
    }

    public function testTotalsWithTaxRoundToCents(): void
    {
        $items = [['label' => 'x', 'hours' => 8.5, 'amount' => 1275.0]];
        $t = InvoiceBuilder::totals($items, true, 8.875);
        $this->assertSame(1275.0, $t['subtotal']);
        $this->assertSame(113.16, $t['tax']);   // round(1275 * 0.08875, 2)
        $this->assertSame(1388.16, $t['total']);
    }

    public function testTotalsTaxDisabled(): void
    {
        $items = [['label' => 'x', 'hours' => 1.0, 'amount' => 100.0]];
        $t = InvoiceBuilder::totals($items, false, 8.875);
        $this->assertSame(0.0, $t['tax']);
        $this->assertSame(100.0, $t['total']);
    }

    public function testEmptyBreakdownYieldsZeroTotals(): void
    {
        $t = InvoiceBuilder::totals(InvoiceBuilder::lineItems([], 150.0), true, 10.0);
        $this->assertSame(0.0, $t['subtotal']);
        $this->assertSame(0.0, $t['total']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class `InvoiceBuilder` not found.

- [ ] **Step 3: Implement InvoiceBuilder**

`TimeInvoice/Model/InvoiceBuilder.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/**
 * Pure line-item + totals math. No DB, no framework — unit-testable in isolation.
 * Line items come from TimeReport's breakdown[] (complete billable hours at the
 * chosen granularity), never detail[] (which only lists completed-in-range tasks).
 */
class InvoiceBuilder
{
    /**
     * @param  list<array{key:string,label:string,hours:float,task_count:int}> $breakdown
     * @return list<array{label:string,hours:float,amount:float}>
     */
    public static function lineItems(array $breakdown, float $rate): array
    {
        $items = [];
        foreach ($breakdown as $row) {
            $hours = (float) $row['hours'];
            $items[] = [
                'label'  => (string) $row['label'],
                'hours'  => $hours,
                'amount' => round($hours * $rate, 2),
            ];
        }
        return $items;
    }

    /**
     * @param  list<array{label:string,hours:float,amount:float}> $lineItems
     * @return array{subtotal:float,tax:float,total:float}
     */
    public static function totals(array $lineItems, bool $taxEnabled, float $taxRate): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $li) {
            $subtotal += (float) $li['amount'];
        }
        $subtotal = round($subtotal, 2);
        $tax = $taxEnabled ? round($subtotal * ($taxRate / 100), 2) : 0.0;
        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Model/InvoiceBuilder.php TimeInvoice/Test/InvoiceBuilderTest.php
git commit -m "feat: InvoiceBuilder pure line-item + totals math"
```

---

### Task 3: InvoiceNumber — gap-free per-year numbering (PURE)

**Files:**
- Create: `TimeInvoice/Model/InvoiceNumber.php`
- Test: `TimeInvoice/Test/InvoiceNumberTest.php`

**Interfaces:**
- Produces: `InvoiceNumber::next(array $counter, string $issueDate, string $format): array{number:string,counter:array}` where `$counter` is a `{year:int => lastSeq:int}` map, `$issueDate` is `Y-m-d`, `$format` uses tokens `{YYYY}` and `{seq}` (seq zero-padded to 3). Returns the formatted number and the updated counter map.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/InvoiceNumberTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceNumber;

class InvoiceNumberTest extends Base
{
    public function testFirstNumberOfYear(): void
    {
        $r = InvoiceNumber::next([], '2026-08-23', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2026-001', $r['number']);
        $this->assertSame(['2026' => 1], $r['counter']);
    }

    public function testIncrementsWithinYear(): void
    {
        $r = InvoiceNumber::next(['2026' => 4], '2026-09-01', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2026-005', $r['number']);
        $this->assertSame(['2026' => 5], $r['counter']);
    }

    public function testResetsPerYear(): void
    {
        $r = InvoiceNumber::next(['2026' => 12], '2027-01-02', 'INV-{YYYY}-{seq}');
        $this->assertSame('INV-2027-001', $r['number']);
        $this->assertSame(['2026' => 12, '2027' => 1], $r['counter']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement InvoiceNumber**

`TimeInvoice/Model/InvoiceNumber.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/** Pure numbering: gap-free, resets per calendar year of the issue date. */
class InvoiceNumber
{
    /**
     * @param  array<string,int> $counter  year => last sequence used
     * @return array{number:string,counter:array<string,int>}
     */
    public static function next(array $counter, string $issueDate, string $format): array
    {
        $year = substr($issueDate, 0, 4);
        $seq  = (int) ($counter[$year] ?? 0) + 1;
        $counter[$year] = $seq;

        $number = str_replace(
            ['{YYYY}', '{seq}'],
            [$year, str_pad((string) $seq, 3, '0', STR_PAD_LEFT)],
            $format
        );
        return ['number' => $number, 'counter' => $counter];
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Model/InvoiceNumber.php TimeInvoice/Test/InvoiceNumberTest.php
git commit -m "feat: InvoiceNumber gap-free per-year numbering"
```

---

### Task 4: DefaultsResolver — layer global ⊕ project ⊕ form (PURE)

**Files:**
- Create: `TimeInvoice/Model/DefaultsResolver.php`
- Test: `TimeInvoice/Test/DefaultsResolverTest.php`

**Interfaces:**
- Produces: `DefaultsResolver::resolve(array $global, array $project, array $form): array` — later layers override earlier, but only for keys present with a non-empty value. Recognized keys: `business` (array), `client` (array), `rate` (float), `tax_enabled` (bool), `tax_rate` (float), `currency` (array `{code,symbol}`), `terms` (string), `terms_days` (int). `business` comes only from `$global`; `client` only from `$project`/`$form`.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/DefaultsResolverTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver;

class DefaultsResolverTest extends Base
{
    public function testProjectOverridesGlobalAndFormOverridesProject(): void
    {
        $global  = ['rate' => 100.0, 'tax_rate' => 8.0, 'terms' => 'Net 30', 'currency' => ['code' => 'USD', 'symbol' => '$']];
        $project = ['rate' => 150.0, 'client' => ['name' => 'Acme']];
        $form    = ['rate' => 175.0];
        $r = DefaultsResolver::resolve($global, $project, $form);
        $this->assertSame(175.0, $r['rate']);              // form wins
        $this->assertSame('Acme', $r['client']['name']);   // from project
        $this->assertSame(8.0, $r['tax_rate']);            // from global
        $this->assertSame('USD', $r['currency']['code']);
    }

    public function testEmptyFormValuesDoNotClobber(): void
    {
        $r = DefaultsResolver::resolve(['rate' => 100.0], ['rate' => 150.0], ['rate' => '']);
        $this->assertSame(150.0, $r['rate']); // empty string in form ignored
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement DefaultsResolver**

`TimeInvoice/Model/DefaultsResolver.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/** Pure precedence resolver: global ⊕ project ⊕ form; empty values never clobber. */
class DefaultsResolver
{
    public static function resolve(array $global, array $project, array $form): array
    {
        $out = [];
        foreach ([$global, $project, $form] as $layer) {
            foreach ($layer as $key => $value) {
                if (self::isEmpty($value)) {
                    continue;
                }
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }
        return $value === null || $value === '';
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Model/DefaultsResolver.php TimeInvoice/Test/DefaultsResolverTest.php
git commit -m "feat: DefaultsResolver layered defaults precedence"
```

---

### Task 5: InvoiceModel — persistence, counter, lifecycle

**Files:**
- Create: `TimeInvoice/Model/InvoiceModel.php`
- Test: `TimeInvoice/Test/InvoiceModelTest.php`

**Interfaces:**
- Consumes: `InvoiceNumber::next(...)`, Kanboard `projectMetadataModel` (`save/get/getAll/remove` on `project_has_metadata`), `configModel` (`get/save` on `settings`), `$this->db`.
- Produces (all on `Kanboard\Plugin\TimeInvoice\Model\InvoiceModel extends \Kanboard\Core\Base`):
  - `createDraft(int $projectId, int $userId, array $draft): string` → new invoice id (a time+random token), status `draft`, stored under key `timeinvoice:inv:<id>`.
  - `load(int $projectId, string $id): ?array`
  - `listByProject(int $projectId): list<array>` (newest first)
  - `listAll(array $projectIds): list<array>` (across the user's accessible projects, newest first)
  - `send(int $projectId, string $id, array $frozenSnapshot): array` → assigns number via counter, sets status `sent`, merges the frozen snapshot, persists; returns the saved record.
  - `markPaid(int $projectId, string $id): void`
  - `delete(int $projectId, string $id): void`
  - `outstandingTotal(array $projectIds): float` (sum of `sent` invoices' `total`).
- Storage: each record is `json_encode`d into the metadata `value`; `id` embedded in the record. Counter is `configModel` key `timeinvoice_counter` (JSON map); number format key `timeinvoice_number_format` (default `INV-{YYYY}-{seq}`).

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/InvoiceModelTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoiceModel;
use Kanboard\Model\ProjectModel;

class InvoiceModelTest extends Base
{
    private function seedProject(): int
    {
        $p = new ProjectModel($this->container);
        return $p->create(['name' => 'Client A']);
    }

    public function testCreateLoadListDraft(): void
    {
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $id = $m->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'rate' => 150.0]);
        $this->assertNotEmpty($id);

        $rec = $m->load($pid, $id);
        $this->assertSame('draft', $rec['status']);
        $this->assertSame($id, $rec['id']);
        $this->assertSame(150.0, $rec['rate']);
        $this->assertArrayNotHasKey('number', $rec);

        $list = $m->listByProject($pid);
        $this->assertCount(1, $list);
    }

    public function testSendAssignsNumberFreezesAndCountsOutstanding(): void
    {
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $id = $m->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31']]);

        $snap = ['issue_date' => '2026-08-23', 'line_items' => [['label' => 'x', 'hours' => 1, 'amount' => 100.0]], 'total' => 100.0];
        $sent = $m->send($pid, $id, $snap);
        $this->assertSame('sent', $sent['status']);
        $this->assertSame('INV-2026-001', $sent['number']);
        $this->assertSame(100.0, $sent['total']);

        $this->assertSame(100.0, $m->outstandingTotal([$pid]));

        $m->markPaid($pid, $id);
        $this->assertSame('paid', $m->load($pid, $id)['status']);
        $this->assertSame(0.0, $m->outstandingTotal([$pid]));
    }

    public function testNumbersAreGapFreeAcrossTwoSends(): void
    {
        $pid = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $a = $m->createDraft($pid, 1, []);
        $b = $m->createDraft($pid, 1, []);
        $m->delete($pid, $a); // deleting a draft consumes no number
        $sentB = $m->send($pid, $b, ['issue_date' => '2026-08-23', 'total' => 0.0]);
        $this->assertSame('INV-2026-001', $sentB['number']);
    }

    public function testListAllSpansProjects(): void
    {
        $p1 = $this->seedProject();
        $p2 = $this->seedProject();
        $m = new InvoiceModel($this->container);
        $m->createDraft($p1, 1, []);
        $m->createDraft($p2, 1, []);
        $this->assertCount(2, $m->listAll([$p1, $p2]));
        $this->assertCount(1, $m->listAll([$p1]));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement InvoiceModel**

`TimeInvoice/Model/InvoiceModel.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Persistence + lifecycle for invoices. Records are JSON blobs in
 * project_has_metadata keyed timeinvoice:inv:<id>. The per-year counter and the
 * number format live in the settings table via ConfigModel. No DB migration.
 */
class InvoiceModel extends Base
{
    private const KEY_PREFIX      = 'timeinvoice:inv:';
    private const CFG_COUNTER     = 'timeinvoice_counter';
    private const CFG_NUMBER_FMT  = 'timeinvoice_number_format';
    private const DEFAULT_FORMAT  = 'INV-{YYYY}-{seq}';

    public function createDraft(int $projectId, int $userId, array $draft): string
    {
        $id = $this->newId();
        $record = array_merge($draft, [
            'id'         => $id,
            'project_id' => $projectId,
            'user_id'    => $userId,
            'status'     => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->put($projectId, $id, $record);
        return $id;
    }

    public function load(int $projectId, string $id): ?array
    {
        $raw = $this->projectMetadataModel->get($projectId, self::KEY_PREFIX . $id, '');
        if ($raw === '' || $raw === null) {
            return null;
        }
        $rec = json_decode($raw, true);
        return is_array($rec) ? $rec : null;
    }

    public function listByProject(int $projectId): array
    {
        $rows = $this->db->table('project_has_metadata')
            ->eq('project_id', $projectId)
            ->like('name', self::KEY_PREFIX . '%')
            ->findAll();
        return $this->decodeRows($rows);
    }

    public function listAll(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $rows = $this->db->table('project_has_metadata')
            ->in('project_id', array_map('intval', $projectIds))
            ->like('name', self::KEY_PREFIX . '%')
            ->findAll();
        return $this->decodeRows($rows);
    }

    public function send(int $projectId, string $id, array $frozenSnapshot): array
    {
        $record = $this->load($projectId, $id);
        if ($record === null) {
            throw new \RuntimeException('Invoice not found');
        }
        $issueDate = $frozenSnapshot['issue_date'] ?? date('Y-m-d');

        $counter = json_decode($this->configModel->get(self::CFG_COUNTER, '{}'), true) ?: [];
        $format  = $this->configModel->get(self::CFG_NUMBER_FMT, self::DEFAULT_FORMAT) ?: self::DEFAULT_FORMAT;
        $next    = InvoiceNumber::next($counter, $issueDate, $format);
        $this->configModel->save([self::CFG_COUNTER => json_encode($next['counter'])]);

        $record = array_merge($record, $frozenSnapshot, [
            'status'  => 'sent',
            'number'  => $next['number'],
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $this->put($projectId, $id, $record);
        return $record;
    }

    public function markPaid(int $projectId, string $id): void
    {
        $record = $this->load($projectId, $id);
        if ($record === null) {
            return;
        }
        $record['status'] = 'paid';
        $record['paid_at'] = date('Y-m-d H:i:s');
        $this->put($projectId, $id, $record);
    }

    public function delete(int $projectId, string $id): void
    {
        $this->projectMetadataModel->remove($projectId, self::KEY_PREFIX . $id);
    }

    public function outstandingTotal(array $projectIds): float
    {
        $sum = 0.0;
        foreach ($this->listAll($projectIds) as $rec) {
            if (($rec['status'] ?? '') === 'sent') {
                $sum += (float) ($rec['total'] ?? 0.0);
            }
        }
        return round($sum, 2);
    }

    private function put(int $projectId, string $id, array $record): void
    {
        $this->projectMetadataModel->save($projectId, [self::KEY_PREFIX . $id => json_encode($record)]);
    }

    /** @return list<array> newest first */
    private function decodeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rec = json_decode($row['value'], true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $out;
    }

    /** Sortable, collision-resistant id (no Date.now/rand constraints — this is PHP runtime). */
    private function newId(): string
    {
        return date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS. (If `listByProject`/`listAll` ordering flakes because two drafts share the same `YmdHis` second, the `id` suffix keeps them distinct; the test asserts counts, not order.)

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Model/InvoiceModel.php TimeInvoice/Test/InvoiceModelTest.php
git commit -m "feat: InvoiceModel persistence, per-year counter, lifecycle"
```

---

### Task 6: InvoicePdf — vendored FPDF renderer

**Files:**
- Create: `TimeInvoice/Model/InvoicePdf.php`, `TimeInvoice/Assets/vendor/fpdf/fpdf.php` (+ `LICENSE.txt`, `font/` core-font metrics that ship with FPDF)
- Test: `TimeInvoice/Test/InvoicePdfTest.php`

**Interfaces:**
- Consumes: a snapshot array (`business`, `client`, `number`|null, `issue_date`, `due_date`, `range`, `currency{code,symbol}`, `line_items[]`, `subtotal`, `tax{enabled,rate,amount}`, `total`, `notes`, `status`).
- Produces: `InvoicePdf::render(array $snapshot): string` returning raw PDF bytes (`%PDF-` header). A `draft` status stamps a DRAFT watermark. Currency/text encoded to cp1252 via `iconv('UTF-8', 'windows-1252//TRANSLIT', $s)` before every `Cell`/`MultiCell`.

- [ ] **Step 1: Vendor FPDF**

Download FPDF 1.86 (`http://www.fpdf.org/`) into `TimeInvoice/Assets/vendor/fpdf/` — the single `fpdf.php`, its `font/` directory (core AFM metrics: `helvetica*.php`, `courier*.php`, `times*.php`), and the FPDF `LICENSE.txt` (permissive; redistribution allowed — MIT-compatible). No Composer; the class is `require_once`d directly. Verify: `grep -c "class FPDF" Assets/vendor/fpdf/fpdf.php` → `1`.

- [ ] **Step 2: Write the failing test**

`TimeInvoice/Test/InvoicePdfTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\InvoicePdf;

class InvoicePdfTest extends Base
{
    private function snapshot(string $status = 'sent'): array
    {
        return [
            'status'     => $status,
            'number'     => $status === 'draft' ? null : 'INV-2026-001',
            'issue_date' => '2026-08-23',
            'due_date'   => '2026-09-22',
            'range'      => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'currency'   => ['code' => 'USD', 'symbol' => '$'],
            'business'   => ['name' => 'Carmelo Santana', 'address' => "Newburgh, NY", 'email' => 'me@carmelosantana.com'],
            'client'     => ['name' => 'Acme Inc', 'address' => "123 Main St", 'email' => 'ap@acme.test'],
            'line_items' => [['label' => '#1 Homepage build', 'hours' => 8.5, 'amount' => 1275.0]],
            'subtotal'   => 1275.0,
            'tax'        => ['enabled' => true, 'rate' => 8.875, 'amount' => 113.16],
            'total'      => 1388.16,
            'notes'      => 'Thank you for your business.',
        ];
    }

    public function testRendersValidPdf(): void
    {
        $bytes = (new InvoicePdf($this->container))->render($this->snapshot());
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(800, strlen($bytes));
    }

    public function testDraftAlsoRenders(): void
    {
        $bytes = (new InvoicePdf($this->container))->render($this->snapshot('draft'));
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class `InvoicePdf` not found.

- [ ] **Step 4: Implement InvoicePdf**

`TimeInvoice/Model/InvoicePdf.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Snapshot array -> PDF bytes via vendored FPDF (core Helvetica; cp1252 encoding
 * covers $, EUR, GBP). Isolated so a tFPDF/branded-font upgrade is a drop-in swap.
 */
class InvoicePdf extends Base
{
    public function render(array $s): string
    {
        require_once __DIR__ . '/../Assets/vendor/fpdf/fpdf.php';

        $sym = $s['currency']['symbol'] ?? '$';
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        // Header band
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->Cell(0, 10, $this->enc($s['business']['name'] ?? ''), 0, 1);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 8, $this->enc('INVOICE ' . ($s['number'] ?? '(draft)')), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, $this->enc('Issued ' . ($s['issue_date'] ?? '') . '   Due ' . ($s['due_date'] ?? '')), 0, 1);
        $pdf->Ln(4);

        // Bill To
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 6, $this->enc('Bill To'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        foreach (['name', 'address', 'email'] as $k) {
            if (! empty($s['client'][$k])) {
                $pdf->MultiCell(0, 5, $this->enc((string) $s['client'][$k]));
            }
        }
        $pdf->Ln(4);

        // Line-item table header
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(95, 7, $this->enc('Description'), 1);
        $pdf->Cell(25, 7, $this->enc('Hours'), 1, 0, 'R');
        $pdf->Cell(30, 7, $this->enc('Rate'), 1, 0, 'R');
        $pdf->Cell(30, 7, $this->enc('Amount'), 1, 1, 'R');

        $pdf->SetFont('Helvetica', '', 10);
        $rate = $this->rateFromSnapshot($s);
        foreach ($s['line_items'] ?? [] as $li) {
            $pdf->Cell(95, 7, $this->enc((string) $li['label']), 1);
            $pdf->Cell(25, 7, number_format((float) $li['hours'], 2), 1, 0, 'R');
            $pdf->Cell(30, 7, $sym . number_format($rate, 2), 1, 0, 'R');
            $pdf->Cell(30, 7, $sym . number_format((float) $li['amount'], 2), 1, 1, 'R');
        }

        // Totals
        $this->totalRow($pdf, $sym, 'Subtotal', (float) ($s['subtotal'] ?? 0));
        if (! empty($s['tax']['enabled'])) {
            $this->totalRow($pdf, $sym, 'Tax (' . rtrim(rtrim(number_format((float) $s['tax']['rate'], 3), '0'), '.') . '%)', (float) $s['tax']['amount']);
        }
        $pdf->SetFont('Helvetica', 'B', 11);
        $this->totalRow($pdf, $sym, 'Total', (float) ($s['total'] ?? 0), true);

        // Notes
        if (! empty($s['notes'])) {
            $pdf->Ln(6);
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, $this->enc((string) $s['notes']));
        }

        // DRAFT watermark
        if (($s['status'] ?? '') === 'draft') {
            $pdf->SetFont('Helvetica', 'B', 60);
            $pdf->SetTextColor(220, 220, 220);
            $pdf->SetXY(35, 120);
            $pdf->Cell(0, 20, 'DRAFT');
            $pdf->SetTextColor(0, 0, 0);
        }

        return $pdf->Output('S');
    }

    private function totalRow(\FPDF $pdf, string $sym, string $label, float $amount, bool $bold = false): void
    {
        $pdf->Cell(120, 7, '', 0);
        $pdf->Cell(30, 7, $this->enc($label), $bold ? 1 : 0, 0, 'R');
        $pdf->Cell(30, 7, $sym . number_format($amount, 2), 1, 1, 'R');
    }

    private function rateFromSnapshot(array $s): float
    {
        if (isset($s['rate'])) {
            return (float) $s['rate'];
        }
        $items = $s['line_items'] ?? [];
        if ($items && (float) $items[0]['hours'] > 0) {
            return round((float) $items[0]['amount'] / (float) $items[0]['hours'], 2);
        }
        return 0.0;
    }

    private function enc(string $s): string
    {
        $out = iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        return $out === false ? $s : $out;
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add TimeInvoice/Model/InvoicePdf.php TimeInvoice/Assets/vendor/fpdf
git commit -m "feat: InvoicePdf FPDF renderer (vendored) + DRAFT watermark"
```

---

### Task 7: InvoiceHelper — template formatting

**Files:**
- Create: `TimeInvoice/Helper/InvoiceHelper.php`
- Test: `TimeInvoice/Test/InvoiceHelperTest.php`

**Interfaces:**
- Produces (extends `Kanboard\Core\Base\BaseHelper`): `money(float $amount, array $currency): string`, `statusLabel(string $status): string`, `statusClass(string $status): string`.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/InvoiceHelperTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper;

class InvoiceHelperTest extends Base
{
    public function testMoneyFormatsWithSymbol(): void
    {
        $h = new InvoiceHelper($this->container);
        $this->assertSame('$1,388.16', $h->money(1388.16, ['code' => 'USD', 'symbol' => '$']));
    }

    public function testStatusLabelAndClass(): void
    {
        $h = new InvoiceHelper($this->container);
        $this->assertSame('Draft', $h->statusLabel('draft'));
        $this->assertSame('Paid', $h->statusLabel('paid'));
        $this->assertNotSame('', $h->statusClass('sent'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement InvoiceHelper**

`TimeInvoice/Helper/InvoiceHelper.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Helper;

use Kanboard\Core\Base\BaseHelper;

class InvoiceHelper extends BaseHelper
{
    public function money(float $amount, array $currency): string
    {
        return ($currency['symbol'] ?? '$') . number_format($amount, 2);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => t('Draft'),
            'sent'  => t('Sent'),
            'paid'  => t('Paid'),
            default => ucfirst($status),
        };
    }

    public function statusClass(string $status): string
    {
        return match ($status) {
            'draft' => 'timeinvoice-status-draft',
            'sent'  => 'timeinvoice-status-sent',
            'paid'  => 'timeinvoice-status-paid',
            default => 'timeinvoice-status-unknown',
        };
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Helper/InvoiceHelper.php TimeInvoice/Test/InvoiceHelperTest.php
git commit -m "feat: InvoiceHelper money + status formatting"
```

---

### Task 8: Wire Plugin.php — services, routes, hooks, assets

**Files:**
- Modify: `TimeInvoice/Plugin.php` (fill `initialize()`)
- Create: `TimeInvoice/Assets/css/timeinvoice.css`, `TimeInvoice/Assets/js/timeinvoice.js`, `TimeInvoice/Template/invoice/sidebar.php`, `TimeInvoice/Template/invoice/header_dropdown.php`
- Test: `TimeInvoice/Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: controllers/models from later tasks (route targets resolve lazily at request time — registering routes to a not-yet-exercised controller is safe).
- Produces: container services `invoiceModel`, `invoicePdf`; helper `invoice`; routes `timeinvoice`, `timeinvoice/project`, `timeinvoice/form`, `timeinvoice/save`, `timeinvoice/send`, `timeinvoice/paid`, `timeinvoice/delete`, `timeinvoice/pdf`, `timeinvoice/settings`, `timeinvoice/settings/save`; hooks `template:project:sidebar`, `template:header:dropdown`, CSS/JS injection.

- [ ] **Step 1: Write the failing TemplateAssetsTest**

`TimeInvoice/Test/TemplateAssetsTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class TemplateAssetsTest extends Base
{
    private function root(): string { return dirname(__DIR__); }

    public function testReferencedAssetsExist(): void
    {
        foreach (['Assets/css/timeinvoice.css', 'Assets/js/timeinvoice.js',
                  'Template/invoice/sidebar.php', 'Template/invoice/header_dropdown.php'] as $rel) {
            $this->assertFileExists($this->root() . '/' . $rel, $rel);
        }
    }

    public function testNoInlineHandlersInTemplates(): void
    {
        foreach (glob($this->root() . '/Template/invoice/*.php') as $tpl) {
            $src = file_get_contents($tpl);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"/i', $src, "inline handler in $tpl");
            $this->assertStringNotContainsString('<script', $src, "inline <script> in $tpl");
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — asset/template files missing.

- [ ] **Step 3: Create the sidebar + header-dropdown partials, CSS, JS**

`TimeInvoice/Template/invoice/sidebar.php`:

```php
<li>
    <?= $this->url->link(t('Invoices'), 'InvoiceController', 'project', array('plugin' => 'TimeInvoice', 'project_id' => $project['id'])) ?>
</li>
```

`TimeInvoice/Template/invoice/header_dropdown.php`:

```php
<li>
    <?= $this->url->icon('file-text-o', t('All invoices'), 'InvoiceController', 'list', array('plugin' => 'TimeInvoice')) ?>
</li>
```

`TimeInvoice/Assets/css/timeinvoice.css`:

```css
.timeinvoice-status-draft { color: #b58900; }
.timeinvoice-status-sent  { color: #268bd2; }
.timeinvoice-status-paid  { color: #859900; }
.timeinvoice-total-row td { font-weight: bold; }
.timeinvoice-outstanding { font-weight: bold; }
```

`TimeInvoice/Assets/js/timeinvoice.js`:

```js
// CSP-safe: delegated handlers only, bound from document. No inline handlers.
(function () {
    "use strict";
    // Confirm destructive draft delete.
    jQuery(document).on("click", ".timeinvoice-delete", function (e) {
        if (!window.confirm("Delete this draft invoice?")) {
            e.preventDefault();
        }
    });
    // Confirm the send transition (freezes the snapshot + assigns a number).
    jQuery(document).on("click", ".timeinvoice-send", function (e) {
        if (!window.confirm("Send this invoice? Its number and totals will be locked.")) {
            e.preventDefault();
        }
    });
}());
```

- [ ] **Step 4: Fill Plugin.php initialize()**

Replace the empty `initialize()` body with:

```php
    public function initialize(): void
    {
        $this->container['invoiceModel'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($c);
        $this->container['invoicePdf']   = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf($c);

        $this->helper->register('invoice', \Kanboard\Plugin\TimeInvoice\Helper\InvoiceHelper::class);

        // Routes (clean-URL ids).
        $this->route->addRoute('timeinvoice', 'InvoiceController', 'list', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/project', 'InvoiceController', 'project', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/form', 'InvoiceController', 'form', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/save', 'InvoiceController', 'saveDraft', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/send', 'InvoiceController', 'send', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/paid', 'InvoiceController', 'markPaid', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/delete', 'InvoiceController', 'delete', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/pdf', 'InvoiceController', 'pdf', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/settings', 'SettingsController', 'show', 'TimeInvoice');
        $this->route->addRoute('timeinvoice/settings/save', 'SettingsController', 'save', 'TimeInvoice');

        // Entry points.
        $this->template->hook->attach('template:project:sidebar', 'TimeInvoice:invoice/sidebar');
        $this->template->hook->attach('template:header:dropdown', 'TimeInvoice:invoice/header_dropdown');

        // Assets (CSP-safe external files).
        $this->hook->on('template:layout:css', ['template' => 'plugins/TimeInvoice/Assets/css/timeinvoice.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/TimeInvoice/Assets/js/timeinvoice.js']);
    }
```

Add the two model `use` imports at the top of `Plugin.php` if you prefer over FQNs (optional — FQNs above are self-contained).

- [ ] **Step 5: Run tests to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS (TemplateAssetsTest green; all prior tests still green).

- [ ] **Step 6: Commit**

```bash
git add TimeInvoice/Plugin.php TimeInvoice/Assets TimeInvoice/Template/invoice/sidebar.php TimeInvoice/Template/invoice/header_dropdown.php TimeInvoice/Test/TemplateAssetsTest.php
git commit -m "feat: wire Plugin.php services/routes/hooks/assets + entry points"
```

---

### Task 9: InvoiceController — list + project list + defensive gate

**Files:**
- Create: `TimeInvoice/Controller/InvoiceController.php`, `TimeInvoice/Template/invoice/list.php`
- Test: `TimeInvoice/Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `invoiceModel`, `projectPermissionModel->getActiveProjectIds($userId)`, `timeReportModel` (presence check).
- Produces (`InvoiceController extends \Kanboard\Controller\BaseController`): `list()` (global), `project()` (per-project), and a protected `ensureDependency(): bool` used by every action. `accessibleProjectIds(int $userId): array`.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/InvoiceControllerTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\InvoiceController;

class InvoiceControllerTest extends Base
{
    public function testDependencyGateFalseWithoutTimeReport(): void
    {
        $c = new InvoiceController($this->container);
        $ref = new ReflectionMethod($c, 'hasTimeReport');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($c));
        $this->container['timeReportModel'] = fn ($x) => new stdClass();
        $this->assertTrue($ref->invoke($c));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — controller class not found.

- [ ] **Step 3: Implement list/project + gate + list.php**

`TimeInvoice/Controller/InvoiceController.php` (list/project/gate; form/save/send/paid/delete/pdf added in Tasks 10–12):

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Controller;

use Kanboard\Controller\BaseController;

class InvoiceController extends BaseController
{
    /** Defensive dependency gate — TimeReport supplies the hours source. */
    protected function hasTimeReport(): bool
    {
        return isset($this->container['timeReportModel']);
    }

    protected function accessibleProjectIds(int $userId): array
    {
        return array_map('intval', $this->projectPermissionModel->getActiveProjectIds($userId));
    }

    /** Cross-project invoice list + outstanding total. */
    public function list(): void
    {
        if (! $this->hasTimeReport()) {
            $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
                'title' => t('Invoices'), 'missing_dependency' => true,
                'invoices' => [], 'outstanding' => 0.0, 'project' => null,
            ]));
            return;
        }
        $userId = $this->userSession->getId();
        $pids = $this->accessibleProjectIds($userId);
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
            'title'       => t('Invoices'),
            'invoices'    => $this->invoiceModel->listAll($pids),
            'outstanding' => $this->invoiceModel->outstandingTotal($pids),
            'project'     => null,
            'missing_dependency' => false,
        ]));
    }

    /** Per-project invoice list. */
    public function project(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/list', [
            'title'       => t('Invoices'),
            'invoices'    => $this->invoiceModel->listByProject($projectId),
            'outstanding' => $this->invoiceModel->outstandingTotal([$projectId]),
            'project'     => $this->projectModel->getById($projectId),
            'missing_dependency' => ! $this->hasTimeReport(),
        ]));
    }
}
```

`TimeInvoice/Template/invoice/list.php`:

```php
<div class="page-header"><h2><?= t('Invoices') ?></h2></div>

<?php if (! empty($missing_dependency)): ?>
    <div class="alert alert-error"><?= t('TimeInvoice requires the TimeReport plugin, which is not enabled.') ?></div>
<?php else: ?>
    <?php if ($project): ?>
        <p><?= $this->url->link(t('New invoice'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $project['id']), false, 'btn btn-blue') ?></p>
    <?php endif ?>

    <p class="timeinvoice-outstanding"><?= t('Outstanding') ?>: <?= $this->helper->invoice->money((float) $outstanding, array('symbol' => '$')) ?></p>

    <?php if (empty($invoices)): ?>
        <p class="alert"><?= t('No invoices yet.') ?></p>
    <?php else: ?>
        <table class="table-striped">
            <tr>
                <th><?= t('Number') ?></th><th><?= t('Status') ?></th>
                <th><?= t('Issued') ?></th><th><?= t('Total') ?></th><th><?= t('Actions') ?></th>
            </tr>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= $this->text->e($inv['number'] ?? t('(draft)')) ?></td>
                    <td class="<?= $this->helper->invoice->statusClass($inv['status'] ?? '') ?>"><?= $this->helper->invoice->statusLabel($inv['status'] ?? '') ?></td>
                    <td><?= $this->text->e($inv['issue_date'] ?? $inv['created_at'] ?? '') ?></td>
                    <td><?= $this->helper->invoice->money((float) ($inv['total'] ?? 0), $inv['currency'] ?? array('symbol' => '$')) ?></td>
                    <td>
                        <?= $this->url->link(t('PDF'), 'InvoiceController', 'pdf', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                        <?php if (($inv['status'] ?? '') === 'draft'): ?>
                            | <?= $this->url->link(t('Edit'), 'InvoiceController', 'form', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                            | <?= $this->url->link(t('Send'), 'InvoiceController', 'send', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id']), false, 'timeinvoice-send') ?>
                            | <?= $this->url->link(t('Delete'), 'InvoiceController', 'delete', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id']), false, 'timeinvoice-delete') ?>
                        <?php elseif (($inv['status'] ?? '') === 'sent'): ?>
                            | <?= $this->url->link(t('Mark paid'), 'InvoiceController', 'markPaid', array('plugin' => 'TimeInvoice', 'project_id' => $inv['project_id'], 'id' => $inv['id'])) ?>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endif ?>
<?php endif ?>
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Controller/InvoiceController.php TimeInvoice/Template/invoice/list.php TimeInvoice/Test/InvoiceControllerTest.php
git commit -m "feat: InvoiceController list/project + dependency gate + list template"
```

---

### Task 10: InvoiceController — form + saveDraft

**Files:**
- Modify: `TimeInvoice/Controller/InvoiceController.php` (add `form`, `saveDraft`, and a protected `buildDraftFromRequest`)
- Create: `TimeInvoice/Template/invoice/form.php`
- Test: extend `TimeInvoice/Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `DefaultsResolver::resolve`, `configModel` (global defaults), `projectMetadataModel->get($pid, 'timeinvoice:defaults', '{}')` (project defaults), `invoiceModel->createDraft/load`.
- Produces: `form()` (new when no `id`, edit when `id` present), `saveDraft()` (persists a draft then redirects to `project` list), `protected globalDefaults(): array`, `protected projectDefaults(int $projectId): array`.

- [ ] **Step 1: Write the failing test**

Append to `InvoiceControllerTest.php`:

```php
    public function testGlobalDefaultsDecodeFromConfig(): void
    {
        $this->container['configModel']->save(['timeinvoice_business' => json_encode(['name' => 'Me'])]);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'globalDefaults');
        $m->setAccessible(true);
        $defaults = $m->invoke($c);
        $this->assertSame('Me', $defaults['business']['name']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `globalDefaults` not defined.

- [ ] **Step 3: Implement form/saveDraft + defaults readers**

Add to `InvoiceController`:

```php
    protected function globalDefaults(): array
    {
        $cfg = $this->configModel;
        return [
            'business'    => json_decode($cfg->get('timeinvoice_business', '{}'), true) ?: [],
            'currency'    => json_decode($cfg->get('timeinvoice_currency', '{"code":"USD","symbol":"$"}'), true) ?: ['code' => 'USD', 'symbol' => '$'],
            'rate'        => (float) $cfg->get('timeinvoice_rate', '0'),
            'tax_enabled' => $cfg->get('timeinvoice_tax_enabled', '0') === '1',
            'tax_rate'    => (float) $cfg->get('timeinvoice_tax_rate', '0'),
            'terms'       => $cfg->get('timeinvoice_terms', ''),
            'terms_days'  => (int) $cfg->get('timeinvoice_terms_days', '30'),
        ];
    }

    protected function projectDefaults(int $projectId): array
    {
        $raw = $this->projectMetadataModel->get($projectId, 'timeinvoice:defaults', '{}');
        return json_decode($raw ?: '{}', true) ?: [];
    }

    public function form(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }

        $id = $this->request->getStringParam('id');
        $existing = $id !== '' ? $this->invoiceModel->load($projectId, $id) : null;
        $defaults = \Kanboard\Plugin\TimeInvoice\Model\DefaultsResolver::resolve(
            $this->globalDefaults(),
            $this->projectDefaults($projectId),
            $existing ?? []
        );

        $values = array_merge([
            'project_id'  => $projectId,
            'id'          => $id,
            'start_date'  => $existing['range']['start'] ?? date('Y-m-01'),
            'end_date'    => $existing['range']['end'] ?? date('Y-m-d'),
            'granularity' => $existing['granularity'] ?? 'task',
        ], $defaults);

        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/form', [
            'title'   => t('New invoice'),
            'project' => $this->projectModel->getById($projectId),
            'values'  => $values,
        ]));
    }

    protected function buildDraftFromRequest(array $v): array
    {
        return [
            'range'       => ['start' => $this->validDate($v['start_date'] ?? '', date('Y-m-01')),
                              'end'   => $this->validDate($v['end_date'] ?? '', date('Y-m-d'))],
            'granularity' => in_array($v['granularity'] ?? '', ['day', 'week', 'task', 'total'], true) ? $v['granularity'] : 'task',
            'rate'        => (float) ($v['rate'] ?? 0),
            'tax_enabled' => ! empty($v['tax_enabled']),
            'tax_rate'    => (float) ($v['tax_rate'] ?? 0),
            'currency'    => ['code' => $v['currency_code'] ?? 'USD', 'symbol' => $v['currency_symbol'] ?? '$'],
            'client'      => ['name' => $v['client_name'] ?? '', 'address' => $v['client_address'] ?? '', 'email' => $v['client_email'] ?? ''],
            'terms'       => (string) ($v['terms'] ?? ''),
            'terms_days'  => (int) ($v['terms_days'] ?? 30),
            'notes'       => (string) ($v['notes'] ?? ''),
        ];
    }

    public function saveDraft(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $v = $this->request->getValues();
        $projectId = (int) ($v['project_id'] ?? 0);
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }

        $draft = $this->buildDraftFromRequest($v);
        $id = (string) ($v['id'] ?? '');
        if ($id !== '' && $this->invoiceModel->load($projectId, $id)) {
            $existing = $this->invoiceModel->load($projectId, $id);
            $this->invoiceModel->createDraft($projectId, $userId, array_merge($draft, ['id' => $existing['id']]));
        } else {
            $this->invoiceModel->createDraft($projectId, $userId, $draft);
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
```

> **Note on edit-in-place:** `createDraft` currently always generates a fresh id. To support editing, add an optional `id` passthrough: in `InvoiceModel::createDraft`, if `$draft['id']` is a non-empty string, reuse it instead of `newId()`. Update the method's first line to `$id = (! empty($draft['id']) && is_string($draft['id'])) ? $draft['id'] : $this->newId();` and remove `id` from the later `array_merge` override (keep the resolved `$id`). Add a regression test `testCreateDraftReusesProvidedId` in `InvoiceModelTest`.

`TimeInvoice/Template/invoice/form.php`:

```php
<div class="page-header"><h2><?= t('Invoice for %s', $project['name']) ?></h2></div>

<form method="post" action="<?= $this->url->href('InvoiceController', 'saveDraft', array('plugin' => 'TimeInvoice')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->form->hidden('project_id', $values) ?>
    <?= $this->form->hidden('id', $values) ?>

    <?= $this->form->label(t('Start date'), 'start_date') ?>
    <?= $this->form->text('start_date', $values) ?>
    <?= $this->form->label(t('End date'), 'end_date') ?>
    <?= $this->form->text('end_date', $values) ?>

    <?= $this->form->label(t('Group line items by'), 'granularity') ?>
    <?= $this->form->select('granularity', array('task' => t('Task'), 'day' => t('Day'), 'week' => t('Week'), 'total' => t('Single total')), $values) ?>

    <?= $this->form->label(t('Hourly rate'), 'rate') ?>
    <?= $this->form->number('rate', $values, array(), array('step' => '0.01')) ?>

    <?= $this->form->checkbox('tax_enabled', t('Apply tax'), '1', ! empty($values['tax_enabled'])) ?>
    <?= $this->form->label(t('Tax rate %'), 'tax_rate') ?>
    <?= $this->form->number('tax_rate', $values, array(), array('step' => '0.001')) ?>

    <?= $this->form->label(t('Client name'), 'client_name') ?>
    <?= $this->form->text('client_name', array('client_name' => $values['client']['name'] ?? '')) ?>
    <?= $this->form->label(t('Client address'), 'client_address') ?>
    <?= $this->form->textarea('client_address', array('client_address' => $values['client']['address'] ?? '')) ?>
    <?= $this->form->label(t('Client email'), 'client_email') ?>
    <?= $this->form->text('client_email', array('client_email' => $values['client']['email'] ?? '')) ?>

    <?= $this->form->label(t('Notes / terms'), 'notes') ?>
    <?= $this->form->textarea('notes', $values) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save draft') ?></button>
    </div>
</form>
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS (new `globalDefaults` test + `testCreateDraftReusesProvidedId`).

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Controller/InvoiceController.php TimeInvoice/Model/InvoiceModel.php TimeInvoice/Template/invoice/form.php TimeInvoice/Test/InvoiceControllerTest.php TimeInvoice/Test/InvoiceModelTest.php
git commit -m "feat: invoice draft form + saveDraft with layered defaults"
```

---

### Task 11: InvoiceController — send / markPaid / delete

**Files:**
- Modify: `TimeInvoice/Controller/InvoiceController.php` (add `send`, `markPaid`, `delete`, and `protected freezeSnapshot(array $draft, int $userId): array`)
- Test: extend `InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `timeReportModel->report($projectId, $start, $end, $granularity, true, $userId)`, `InvoiceBuilder::lineItems/totals`, `InvoiceNumber` (via model `send`), `globalDefaults`/`projectDefaults`.
- Produces: `freezeSnapshot(array $draft, int $userId): array` — pure-ish assembler that calls `report()` and returns the full snapshot (business/client/currency/line_items/subtotal/tax/total/issue_date/due_date). `send()` freezes + persists via `invoiceModel->send`, redirects. `markPaid()`/`delete()` mutate + redirect.

- [ ] **Step 1: Write the failing test**

Append to `InvoiceControllerTest.php` — seed a fake `timeReportModel` so `freezeSnapshot` is deterministic:

```php
    public function testFreezeSnapshotBuildsLineItemsAndTotals(): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class {
            public function report($pid, $s, $e, $g, $d, $u) {
                return ['breakdown' => [['key' => '1', 'label' => '#1 Task', 'hours' => 10.0, 'task_count' => 1]]];
            }
        };
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'freezeSnapshot');
        $m->setAccessible(true);
        $draft = [
            'project_id' => 5, 'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
            'granularity' => 'task', 'rate' => 150.0, 'tax_enabled' => true, 'tax_rate' => 10.0,
            'currency' => ['code' => 'USD', 'symbol' => '$'], 'client' => ['name' => 'Acme'],
            'terms_days' => 30, 'issue_date' => '2026-08-23',
        ];
        $snap = $m->invoke($c, $draft, 1);
        $this->assertSame(1500.0, $snap['subtotal']);
        $this->assertSame(150.0, $snap['tax']['amount']);
        $this->assertSame(1650.0, $snap['total']);
        $this->assertSame('2026-09-22', $snap['due_date']); // issue + 30 days
        $this->assertSame('#1 Task', $snap['line_items'][0]['label']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `freezeSnapshot` not defined.

- [ ] **Step 3: Implement send/markPaid/delete + freezeSnapshot**

Add to `InvoiceController` (`use` the two model classes at top: `InvoiceBuilder`):

```php
    protected function freezeSnapshot(array $draft, int $userId): array
    {
        $projectId = (int) $draft['project_id'];
        $issueDate = $draft['issue_date'] ?? date('Y-m-d');
        $termsDays = (int) ($draft['terms_days'] ?? 30);
        $rate      = (float) ($draft['rate'] ?? 0);

        $report = $this->timeReportModel->report(
            $projectId,
            $draft['range']['start'], $draft['range']['end'],
            $draft['granularity'] ?? 'task', true, $userId
        );
        $items  = \Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder::lineItems($report['breakdown'] ?? [], $rate);
        $totals = \Kanboard\Plugin\TimeInvoice\Model\InvoiceBuilder::totals($items, ! empty($draft['tax_enabled']), (float) ($draft['tax_rate'] ?? 0));

        $global = $this->globalDefaults();
        return [
            'issue_date' => $issueDate,
            'due_date'   => date('Y-m-d', strtotime($issueDate . ' +' . $termsDays . ' days')),
            'range'      => $draft['range'],
            'granularity'=> $draft['granularity'] ?? 'task',
            'currency'   => $draft['currency'] ?? ($global['currency'] ?? ['code' => 'USD', 'symbol' => '$']),
            'business'   => $global['business'] ?? [],
            'client'     => $draft['client'] ?? [],
            'rate'       => $rate,
            'line_items' => $items,
            'subtotal'   => $totals['subtotal'],
            'tax'        => ['enabled' => ! empty($draft['tax_enabled']), 'rate' => (float) ($draft['tax_rate'] ?? 0), 'amount' => $totals['tax']],
            'total'      => $totals['total'],
            'notes'      => (string) ($draft['notes'] ?? ($global['terms'] ?? '')),
        ];
    }

    public function send(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        $id = $this->request->getStringParam('id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }
        $draft = $this->invoiceModel->load($projectId, $id);
        if ($draft !== null && ($draft['status'] ?? '') === 'draft') {
            $draft['issue_date'] = date('Y-m-d');
            $snapshot = $this->freezeSnapshot($draft, $userId);
            $this->invoiceModel->send($projectId, $id, $snapshot);
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    public function markPaid(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->invoiceModel->markPaid($projectId, $this->request->getStringParam('id'));
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }

    public function delete(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        if (in_array($projectId, $this->accessibleProjectIds($userId), true)) {
            $this->invoiceModel->delete($projectId, $this->request->getStringParam('id'));
        }
        $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Controller/InvoiceController.php TimeInvoice/Test/InvoiceControllerTest.php
git commit -m "feat: send (freeze+number) / markPaid / delete transitions"
```

---

### Task 12: InvoiceController — pdf download

**Files:**
- Modify: `TimeInvoice/Controller/InvoiceController.php` (add `pdf`)
- Test: extend `InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `invoiceModel->load`, `invoicePdf->render`, and (for a **draft**) `freezeSnapshot` to render a live preview; a **sent/paid** invoice renders its stored frozen snapshot as-is.
- Produces: `pdf()` streaming `application/pdf` with `Content-Disposition: attachment; filename="<number|draft>.pdf"`.

- [ ] **Step 1: Write the failing test**

Append to `InvoiceControllerTest.php`:

```php
    public function testPdfPreviewForDraftRendersBytes(): void
    {
        $this->container['timeReportModel'] = fn ($x) => new class {
            public function report($pid, $s, $e, $g, $d, $u) {
                return ['breakdown' => [['key' => '1', 'label' => '#1', 'hours' => 2.0, 'task_count' => 1]]];
            }
        };
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $model = new \Kanboard\Plugin\TimeInvoice\Model\InvoiceModel($this->container);
        $id = $model->createDraft($pid, 1, ['range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task', 'rate' => 100.0, 'currency' => ['code' => 'USD', 'symbol' => '$']]);

        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'snapshotForPdf');
        $m->setAccessible(true);
        $snap = $m->invoke($c, $pid, $id, 1);
        $bytes = (new \Kanboard\Plugin\TimeInvoice\Model\InvoicePdf($this->container))->render($snap);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame('draft', $snap['status']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `snapshotForPdf` not defined.

- [ ] **Step 3: Implement pdf + snapshotForPdf**

Add to `InvoiceController`:

```php
    /** Draft -> live preview snapshot (status draft); sent/paid -> stored frozen snapshot. */
    protected function snapshotForPdf(int $projectId, string $id, int $userId): array
    {
        $rec = $this->invoiceModel->load($projectId, $id);
        if ($rec === null) {
            return [];
        }
        if (($rec['status'] ?? '') === 'draft') {
            $rec['issue_date'] = date('Y-m-d');
            $snap = $this->freezeSnapshot($rec, $userId);
            $snap['status'] = 'draft';
            $snap['number'] = null;
            return $snap;
        }
        return $rec;
    }

    public function pdf(): void
    {
        $userId = $this->userSession->getId();
        $projectId = $this->request->getIntegerParam('project_id');
        $id = $this->request->getStringParam('id');
        if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'list', ['plugin' => 'TimeInvoice']));
            return;
        }
        $snap = $this->snapshotForPdf($projectId, $id, $userId);
        if ($snap === []) {
            $this->response->redirect($this->helper->url->to('InvoiceController', 'project', ['plugin' => 'TimeInvoice', 'project_id' => $projectId]));
            return;
        }
        $bytes = $this->invoicePdf->render($snap);
        $name = ($snap['number'] ?? 'draft') . '.pdf';

        $this->response->withoutCache();
        $this->response->withContentType('application/pdf');
        $this->response->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $this->response->withBody($bytes);
        $this->response->send();
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Controller/InvoiceController.php TimeInvoice/Test/InvoiceControllerTest.php
git commit -m "feat: PDF download (draft live preview vs frozen snapshot)"
```

---

### Task 13: SettingsController + settings page

**Files:**
- Create: `TimeInvoice/Controller/SettingsController.php`, `TimeInvoice/Template/invoice/settings.php`
- Test: `TimeInvoice/Test/SettingsControllerTest.php`

**Interfaces:**
- Consumes: `configModel->get/save`.
- Produces (`SettingsController extends BaseController`): `show()` (renders current global defaults), `save()` (persists `timeinvoice_business` JSON, `timeinvoice_currency` JSON, `timeinvoice_rate`, `timeinvoice_tax_enabled`, `timeinvoice_tax_rate`, `timeinvoice_terms`, `timeinvoice_terms_days`, `timeinvoice_number_format`), then redirect. `protected currentSettings(): array`.

- [ ] **Step 1: Write the failing test**

`TimeInvoice/Test/SettingsControllerTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Controller\SettingsController;

class SettingsControllerTest extends Base
{
    public function testCurrentSettingsReadsConfig(): void
    {
        $this->container['configModel']->save([
            'timeinvoice_rate' => '175',
            'timeinvoice_business' => json_encode(['name' => 'CS Consulting']),
        ]);
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $s = $m->invoke($c);
        $this->assertSame(175.0, $s['rate']);
        $this->assertSame('CS Consulting', $s['business']['name']);
        $this->assertSame('INV-{YYYY}-{seq}', $s['number_format']); // default
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement SettingsController + settings.php**

`TimeInvoice/Controller/SettingsController.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Controller;

use Kanboard\Controller\BaseController;

class SettingsController extends BaseController
{
    protected function currentSettings(): array
    {
        $cfg = $this->configModel;
        return [
            'business'      => json_decode($cfg->get('timeinvoice_business', '{}'), true) ?: [],
            'currency'      => json_decode($cfg->get('timeinvoice_currency', '{"code":"USD","symbol":"$"}'), true) ?: ['code' => 'USD', 'symbol' => '$'],
            'rate'          => (float) $cfg->get('timeinvoice_rate', '0'),
            'tax_enabled'   => $cfg->get('timeinvoice_tax_enabled', '0') === '1',
            'tax_rate'      => (float) $cfg->get('timeinvoice_tax_rate', '0'),
            'terms'         => $cfg->get('timeinvoice_terms', ''),
            'terms_days'    => (int) $cfg->get('timeinvoice_terms_days', '30'),
            'number_format' => $cfg->get('timeinvoice_number_format', 'INV-{YYYY}-{seq}') ?: 'INV-{YYYY}-{seq}',
        ];
    }

    public function show(): void
    {
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/settings', [
            'title'  => t('Invoice settings'),
            'values' => $this->currentSettings(),
        ]));
    }

    public function save(): void
    {
        $this->checkCSRFForm();
        $v = $this->request->getValues();
        $this->configModel->save([
            'timeinvoice_business'      => json_encode(['name' => $v['business_name'] ?? '', 'address' => $v['business_address'] ?? '', 'email' => $v['business_email'] ?? '']),
            'timeinvoice_currency'      => json_encode(['code' => $v['currency_code'] ?? 'USD', 'symbol' => $v['currency_symbol'] ?? '$']),
            'timeinvoice_rate'          => (string) (float) ($v['rate'] ?? 0),
            'timeinvoice_tax_enabled'   => empty($v['tax_enabled']) ? '0' : '1',
            'timeinvoice_tax_rate'      => (string) (float) ($v['tax_rate'] ?? 0),
            'timeinvoice_terms'         => (string) ($v['terms'] ?? ''),
            'timeinvoice_terms_days'    => (string) (int) ($v['terms_days'] ?? 30),
            'timeinvoice_number_format' => (string) ($v['number_format'] ?? 'INV-{YYYY}-{seq}'),
        ]);
        $this->response->redirect($this->helper->url->to('SettingsController', 'show', ['plugin' => 'TimeInvoice']));
    }
}
```

`TimeInvoice/Template/invoice/settings.php`:

```php
<div class="page-header"><h2><?= t('Invoice settings') ?></h2></div>

<form method="post" action="<?= $this->url->href('SettingsController', 'save', array('plugin' => 'TimeInvoice')) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Business name'), 'business_name') ?>
    <?= $this->form->text('business_name', array('business_name' => $values['business']['name'] ?? '')) ?>
    <?= $this->form->label(t('Business address'), 'business_address') ?>
    <?= $this->form->textarea('business_address', array('business_address' => $values['business']['address'] ?? '')) ?>
    <?= $this->form->label(t('Business email'), 'business_email') ?>
    <?= $this->form->text('business_email', array('business_email' => $values['business']['email'] ?? '')) ?>

    <?= $this->form->label(t('Currency code'), 'currency_code') ?>
    <?= $this->form->text('currency_code', array('currency_code' => $values['currency']['code'] ?? 'USD')) ?>
    <?= $this->form->label(t('Currency symbol'), 'currency_symbol') ?>
    <?= $this->form->text('currency_symbol', array('currency_symbol' => $values['currency']['symbol'] ?? '$')) ?>

    <?= $this->form->label(t('Default hourly rate'), 'rate') ?>
    <?= $this->form->number('rate', $values, array(), array('step' => '0.01')) ?>

    <?= $this->form->checkbox('tax_enabled', t('Apply tax by default'), '1', ! empty($values['tax_enabled'])) ?>
    <?= $this->form->label(t('Default tax rate %'), 'tax_rate') ?>
    <?= $this->form->number('tax_rate', $values, array(), array('step' => '0.001')) ?>

    <?= $this->form->label(t('Payment terms (days)'), 'terms_days') ?>
    <?= $this->form->number('terms_days', $values) ?>
    <?= $this->form->label(t('Default notes / terms'), 'terms') ?>
    <?= $this->form->textarea('terms', $values) ?>

    <?= $this->form->label(t('Invoice number format'), 'number_format') ?>
    <?= $this->form->text('number_format', $values) ?>
    <p class="form-help"><?= t('Tokens: {YYYY} = year, {seq} = per-year sequence (zero-padded).') ?></p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save settings') ?></button>
    </div>
</form>
```

- [ ] **Step 4: Run to verify pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add TimeInvoice/Controller/SettingsController.php TimeInvoice/Template/invoice/settings.php TimeInvoice/Test/SettingsControllerTest.php
git commit -m "feat: global invoice settings page"
```

---

### Task 14: Full green run + live E2E verification + version freeze

**Files:**
- Modify: `TimeInvoice/README.md` (usage: settings → per-project defaults → new invoice → send → PDF)
- Possibly modify: `testing/docker-compose.dev.yml` (monorepo) if TimeInvoice needs mounting into `kb-suite` — mirror how TimeReport is mounted (see the last monorepo commit `dev-harness: wire TimeReport into docker-compose`).

- [ ] **Step 1: Full unit run, all green**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — every test across Tasks 1–13. Capture the summary line (`OK (N tests, M assertions)`) as evidence.

- [ ] **Step 2: Live E2E on the dev stack (evidence before completion)**

Bring up the dev stack and exercise the real flow (edit on the host only):

```bash
docker compose -f testing/docker-compose.dev.yml up -d
```

Then in the browser at `http://localhost:8081` (admin/admin), with TimeReport + TimeInvoice enabled:
1. Set global settings at `/timeinvoice/settings` (business, rate, currency).
2. Open a project with logged time → sidebar **Invoices** → **New invoice** → save draft.
3. Download the **draft** PDF (expect DRAFT watermark, live totals).
4. **Send** → confirm a number `INV-2026-001` is assigned; download the sent PDF (no watermark, frozen).
5. **Mark paid** → outstanding total drops to 0.
6. Confirm the **All invoices** header entry lists it cross-project.
Verify no CSP console errors and no 404s on the routes.

- [ ] **Step 3: Version freeze for first release**

Decide the first public version. Recommended **`1.0.0`** (feature-complete v1). Update all three in lockstep:
- `plugin.json` `version` → `1.0.0`
- `Plugin.php` `getPluginVersion()` → `1.0.0`
- `Test/PluginMetaTest.php` + `Test/PluginTest.php` version asserts → `1.0.0`

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS with the new version asserts.

- [ ] **Step 4: Commit**

```bash
git add TimeInvoice
git commit -m "chore: freeze TimeInvoice v1.0.0 (docs + version alignment)"
```

---

### Task 15: Release + ModMenu directory listing

> Follow `kanboard-plugin-suite` → `references/release.md` and `references/directory.md`. This is the terminal deliverable the user explicitly asked for.

- [ ] **Step 1: Create the GitHub repo and push**

Create `github.com/carmelosantana/kanboard-time-invoice` (repo root = the `TimeInvoice/` plugin, including `.github/workflows/release.yml`). Push `main`.

- [ ] **Step 2: Tag the release**

Verify the three versions agree (`plugin.json` == `Plugin.php` == tag), then:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

- [ ] **Step 3: Verify the published asset**

Watch the Actions `release` run succeed. Then confirm the asset downloads and unzips to a single `TimeInvoice/` folder with `plugin.json` at its top:

```bash
curl -sIL <asset-url> | head -1   # expect: HTTP/… 200
```

- [ ] **Step 4: List in the ModMenu directory**

In `kanboard-modmenu-directory/plugins.json`, add a TimeInvoice entry (name, description, homepage, the release `download` URL pointing at the exact `TimeInvoice-1.0.0.zip` asset, and its `requires: [{plugin: TimeReport, min_version: "1.1.0", …}]`). Match the shape of an existing entry. Commit and push the directory repo.

- [ ] **Step 5: Confirm discovery**

Load ModMenu against the directory feed and confirm TimeInvoice appears with its TimeReport dependency shown (not silently dropped — the array/bare-version shape from Task 1 guarantees this).

---

## Self-Review

**Spec coverage:**
- Buildless / one-repo / dependency shape → Task 1 (+ PluginMetaTest). ✓
- `requires` TimeReport + defensive gate → Task 1 (`requiresTimeReport`), Task 9 (`hasTimeReport`, list gate). ✓
- FPDF vendored engine → Task 6 (deviation from spec's tFPDF documented in plan header + flagged to user; `InvoicePdf` seam preserves the tFPDF upgrade path). ✓
- Global/project/form layered config → Tasks 4, 10, 13. ✓
- Persisted invoices in `project_has_metadata`; global list via one DB query; outstanding total → Task 5. ✓
- Lifecycle draft→sent→paid; freeze snapshot + assign number on send; gap-free per-year numbering → Tasks 3, 5, 11. ✓
- Line items from `breakdown[]` at selectable granularity (default task) → Tasks 2, 11. ✓
- Single optional tax → Tasks 2, 10, 11, 13. ✓
- Project sidebar + header dropdown + settings routes → Task 8. ✓
- Polished PDF layout (header/Bill To/table/totals/notes/DRAFT) → Task 6. ✓
- Numbering format configurable; net-terms due date → Tasks 3, 11, 13. ✓
- Testing strategy (builder/model/pdf/meta/template/controller) → Tasks 2–13. ✓
- Release + directory listing → Tasks 14, 15. ✓
- AI cover note explicitly out of scope; notes field present for the fast-follow → Task 10 form `notes`. ✓

**Placeholder scan:** No `TBD`/`TODO`/"add error handling"/"similar to Task N". Each code step shows complete code. ✓ (Task 6 Step 1 requires fetching the FPDF file from fpdf.org — an external asset download, not a code placeholder; its verification command is given.)

**Type consistency:** `InvoiceBuilder::lineItems/totals`, `InvoiceNumber::next`, `DefaultsResolver::resolve`, `InvoiceModel::{createDraft,load,listByProject,listAll,send,markPaid,delete,outstandingTotal}`, `InvoicePdf::render`, `InvoiceHelper::{money,statusLabel,statusClass}`, controller `{hasTimeReport,accessibleProjectIds,globalDefaults,projectDefaults,buildDraftFromRequest,freezeSnapshot,snapshotForPdf}` are used consistently across tasks. Snapshot keys (`business,client,currency,line_items,subtotal,tax{enabled,rate,amount},total,issue_date,due_date,number,status`) match between `freezeSnapshot` (Task 11), `InvoicePdf` (Task 6), and `list.php` (Task 9). ✓

**Note carried into execution:** Task 10 Step 3 amends `InvoiceModel::createDraft` (from Task 5) to reuse a provided `id` for edit-in-place, with an added regression test — call out this cross-task edit during review.
