# TimeInvoice AI Cover Note — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional, AI-generated cover note to TimeInvoice — a short professional prose summary of the billed work, generated on demand from the invoice's task data and dropped into the existing editable notes field.

**Architecture:** A pure context-assembler (`CoverNoteContext`) tiers and budget-trims task data; an orchestrator (`CoverNoteGenerator`) gathers the billed tasks via TimeReport's `breakdown[].task_ids` + Kanboard finders, sizes the prompt with AiConnector's `ModelCatalog`, and generates via `ProviderRegistry::structured()`. A gated controller action returns the note as JSON; delegated CSP-safe JS drops it into the notes textarea. The feature degrades to invisible when AiConnector is absent.

**Tech Stack:** Buildless PHP ≥ 8.4, vanilla JS + jQuery + global `KB`, plain CSS. AiConnector 1.1.0 (`ProviderRegistry`, `ModelCatalog`). TimeReport 1.3.0+ (`report()` aggregate). FPDF already vendored (untouched here).

## Global Constraints

- **Buildless.** No bundler/npm/Composer build step. What is committed ships. PHP ≥ 8.4.
- **Plugin repo:** `/home/carmelo/Projects/Kanboard/kanboard-plugins/TimeInvoice` (own git repo). Commit there.
- **Test command (run from the monorepo root** `/home/carmelo/Projects/Kanboard/kanboard-plugins`**):** `./testing/run-plugin-tests.sh TimeInvoice`
- **Target version:** TimeInvoice **1.1.0** — aligned across `plugin.json` `version` and `Plugin.php::getPluginVersion()`.
- **`requires` TimeReport `1.3.0`** (bare semver, array-of-objects) — raised from 1.1.0 (dedup/attribution correctness + `breakdown[].task_ids`).
- **`recommends` AiConnector `1.1.0`** (bare semver, array-of-objects, soft) — the release that shipped `ModelCatalog`.
- **AiConnector API (SHIPPED 1.1.0), consumed via `new <FQN>($this->container)`:**
  - `Kanboard\Plugin\AiConnector\Model\ProviderRegistry`: `isReady(): bool`, `listProfiles(): array<{id,label,provider,model}>`, `getDefaultProfileId(): string`, `structured(array $messages, string $schema, ?string $profileId): array`.
  - `Kanboard\Plugin\AiConnector\Model\ModelCatalog`: `getContextWindow(?string $profileId=null): int` (never throws), `countTokens(string $text, ?string $profileId=null): int` (never throws), `getInputBudget(?string $profileId=null, int $reserveOutputTokens=1024): int`.
- **TimeReport call (frozen 6-arg positional):** `$this->timeReportModel->report($projectId, $start, $end, $granularity, true, $userId)`. `breakdown[]` rows: `{key:string,label:string,hours:float,task_count:int,task_ids:int[]}`.
- **Degrade gate** must check **both** AiConnector classes exist AND `isReady()` — a user on AiConnector 1.0.0 has `ProviderRegistry` but not `ModelCatalog`; the feature must hide, never fatal.
- **CSP-safe:** all JS in `Assets/js/timeinvoice.js` (delegated, no inline handlers).
- **`t()` gotcha:** any literal `%` inside a single-arg `t('...')` must be escaped `%%` (Kanboard runs it through `sprintf`). `TemplateAssetsTest` enforces this.
- **Release:** push a bare `v1.1.0` tag equal to the version; then update `kanboard-modmenu-directory`.

---

### Task 1: Version + dependency metadata

**Files:**
- Modify: `plugin.json` (version, requires, add recommends)
- Modify: `Plugin.php:50` (`getPluginVersion()`)
- Test: `Test/PluginMetaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `plugin.json` with `version:"1.1.0"`, `requires:[{plugin:"TimeReport",min_version:"1.3.0",...}]`, `recommends:[{plugin:"AiConnector",min_version:"1.1.0",...}]`; `Plugin.php::getPluginVersion()` returns `'1.1.0'`.

**Note for implementer:** the shipped v1.0.0 declares dependencies in `plugin.json` only (no `Plugin.php` requires method) and lists correctly in ModMenu, so `plugin.json` is the source of truth here. Do not invent a `Plugin.php` dependency method. Also confirm `breakdown[].task_ids` exists at the pinned TimeReport floor: the installed TimeReport is 1.4.2 (has it); the field landed by ~1.3.0. If a check of the 1.3.0 tag shows it arrived later, raise the floor to the tag that introduced it and note it in the report.

- [ ] **Step 1: Update the metadata tests to the new contract (RED)**

Replace the two failing assertions and add the `recommends` test in `Test/PluginMetaTest.php`. Replace `testVersionIs100` and `testRequiresArrayShape` with:

```php
    public function testVersionIs110(): void
    {
        $this->assertSame('1.1.0', $this->json()['version']);
    }

    /** requires must be an ARRAY of objects with a bare min_version (no ">=" prefix). */
    public function testRequiresArrayShape(): void
    {
        $j = $this->json();
        $this->assertSame(['TimeReport'], array_column($j['requires'], 'plugin'));
        $this->assertSame('1.3.0', $j['requires'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['requires'][0]['min_version']);
        $this->assertNotEmpty($j['requires'][0]['reason']);
    }

    /** recommends: AiConnector, bare min_version, same array-of-objects shape. */
    public function testRecommendsAiConnectorShape(): void
    {
        $j = $this->json();
        $this->assertArrayHasKey('recommends', $j, 'v1.1.0 recommends AiConnector');
        $this->assertSame(['AiConnector'], array_column($j['recommends'], 'plugin'));
        $this->assertSame('1.1.0', $j['recommends'][0]['min_version']);
        $this->assertStringStartsNotWith('>=', $j['recommends'][0]['min_version']);
        $this->assertNotEmpty($j['recommends'][0]['reason']);
    }

    public function testPluginPhpVersionMatchesJson(): void
    {
        $plugin = new \Kanboard\Plugin\TimeInvoice\Plugin($this->container);
        $this->assertSame($this->json()['version'], $plugin->getPluginVersion());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `PluginMetaTest` (version is still 1.0.0; `recommends` key missing; `Plugin.php` returns 1.0.0).

- [ ] **Step 3: Update `plugin.json`**

Set `"version": "1.1.0"`, raise the `requires` floor, and add `recommends`:

```json
{
    "name": "TimeInvoice",
    "description": "Generate polished PDF invoices from your TimeReport hours: pick a project and date range, roll up line items by task/day/week, set rate and tax, and track invoices through draft → sent → paid with stable per-year numbering.",
    "version": "1.1.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-time-invoice",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "requires": [
        { "plugin": "TimeReport", "min_version": "1.3.0", "reason": "supplies the deduped, correctly-attributed hours aggregate (report()) and breakdown[].task_ids used for invoice line items and AI cover-note context" }
    ],
    "recommends": [
        { "plugin": "AiConnector", "min_version": "1.1.0", "reason": "optional AI cover-note generation via ProviderRegistry (structured output) and ModelCatalog (context-window sizing); the feature is hidden when absent" }
    ]
}
```

- [ ] **Step 4: Update `Plugin.php::getPluginVersion()`**

```php
    public function getPluginVersion(): string     { return '1.1.0'; }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — all `PluginMetaTest` tests green; total count = 37 (net 0; renamed one, added two, one asserted method) — confirm no failures.

- [ ] **Step 6: Commit**

```bash
git add plugin.json Plugin.php Test/PluginMetaTest.php
git commit -m "chore: bump TimeInvoice to 1.1.0; require TimeReport 1.3.0, recommend AiConnector 1.1.0"
```

---

### Task 2: `CoverNoteContext` — pure priority-tiered assembly

**Files:**
- Create: `Model/CoverNoteContext.php`
- Test: `Test/CoverNoteContextTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces: `CoverNoteContext::assemble(array $header, array $tasks, int $budget, callable $countTokens): string`.
  - `$header`: `{project:string, client:string, range:{start,end}, granularity:string, line_items:list<{label:string,hours:float}>, subtotal:float, tax:{enabled:bool,rate:float,amount:float}, total:float, currency:{code:string,symbol:string}}`
  - `$tasks`: `list<{title:string, description:string, subtasks:list<{title:string,done:bool}>, comments:list<{text:string}>}>`
  - `$countTokens`: `fn(string): int`
  - Returns the assembled text payload. Tier order: skeleton (always) → descriptions → subtasks → comments. Adds whole units while `countTokens(payload) <= budget`; stops entirely on the first non-fitting unit (lower tiers skipped). Skeleton is always present even if it alone exceeds budget.

- [ ] **Step 1: Write the failing test**

Create `Test/CoverNoteContextTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\CoverNoteContext;

class CoverNoteContextTest extends Base
{
    /** ~4 chars per token, matching AiConnector's heuristic order of magnitude. */
    private function counter(): callable
    {
        return fn (string $s): int => (int) ceil(mb_strlen($s) / 4);
    }

    private function header(): array
    {
        return [
            'project' => 'Acme Redesign', 'client' => 'Acme Inc',
            'range' => ['start' => '2026-08-01', 'end' => '2026-08-31'], 'granularity' => 'task',
            'line_items' => [['label' => '#1 Homepage', 'hours' => 8.5], ['label' => '#2 API', 'hours' => 3.0]],
            'subtotal' => 1725.0, 'tax' => ['enabled' => true, 'rate' => 10.0, 'amount' => 172.5],
            'total' => 1897.5, 'currency' => ['code' => 'USD', 'symbol' => '$'],
        ];
    }

    private function tasks(): array
    {
        return [
            ['title' => 'Homepage', 'description' => 'Rebuilt the homepage hero and nav.',
             'subtasks' => [['title' => 'Hero', 'done' => true], ['title' => 'Nav', 'done' => false]],
             'comments' => [['text' => 'Client approved the hero copy.']]],
            ['title' => 'API', 'description' => 'Added the reporting endpoint.',
             'subtasks' => [['title' => 'Schema', 'done' => true]],
             'comments' => [['text' => 'Deferred pagination to next sprint.']]],
        ];
    }

    public function testSkeletonAlwaysPresentEvenWithZeroBudget(): void
    {
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), 0, $this->counter());
        $this->assertStringContainsString('INVOICE CONTEXT', $out);
        $this->assertStringContainsString('Project: Acme Redesign', $out);
        $this->assertStringContainsString('#1 Homepage', $out);
        $this->assertStringContainsString('Total: $1,897.50', $out);
        // Zero budget: nothing beyond the skeleton.
        $this->assertStringNotContainsString('description:', $out);
    }

    public function testLargeBudgetIncludesEveryTier(): void
    {
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), 100000, $this->counter());
        $this->assertStringContainsString('Rebuilt the homepage hero', $out); // description
        $this->assertStringContainsString('Hero [done]', $out);               // subtask
        $this->assertStringContainsString('Client approved the hero copy', $out); // comment
    }

    public function testCommentsDropBeforeSubtasksUnderTightBudget(): void
    {
        // Budget that fits skeleton + descriptions + subtasks, but not comments.
        $c = $this->counter();
        $skeleton = CoverNoteContext::assemble($this->header(), [], 0, $c);
        $withSubs = CoverNoteContext::assemble($this->header(), $this->tasks(), 100000, $c);
        // Find a budget between "through subtasks" and "through comments".
        $full = $c($withSubs);
        $budget = $full - 3; // just short of the last comment block
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), $budget, $c);
        $this->assertStringContainsString('Hero [done]', $out, 'subtasks kept');
        $this->assertStringNotContainsString('Deferred pagination', $out, 'comments dropped first');
    }

    public function testSubtasksDropWhenOnlyDescriptionsFit(): void
    {
        $c = $this->counter();
        $skeletonOnly = CoverNoteContext::assemble($this->header(), [], 0, $c);
        // Budget = skeleton + first description block only.
        $firstDesc = "\nTask \"Homepage\" — description: Rebuilt the homepage hero and nav.";
        $budget = $c($skeletonOnly . $firstDesc);
        $out = CoverNoteContext::assemble($this->header(), $this->tasks(), $budget, $c);
        $this->assertStringContainsString('Rebuilt the homepage hero', $out);
        $this->assertStringNotContainsString('[done]', $out, 'subtasks dropped when only descriptions fit');
    }

    public function testEmptyTasksYieldSkeletonOnly(): void
    {
        $out = CoverNoteContext::assemble($this->header(), [], 100000, $this->counter());
        $this->assertStringContainsString('INVOICE CONTEXT', $out);
        $this->assertStringNotContainsString('description:', $out);
    }

    public function testTaxLineOmittedWhenDisabled(): void
    {
        $h = $this->header();
        $h['tax'] = ['enabled' => false, 'rate' => 0.0, 'amount' => 0.0];
        $out = CoverNoteContext::assemble($h, [], 0, $this->counter());
        $this->assertStringNotContainsString('Tax (', $out);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `Error: Class "Kanboard\Plugin\TimeInvoice\Model\CoverNoteContext" not found`.

- [ ] **Step 3: Write the implementation**

Create `Model/CoverNoteContext.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

/**
 * Pure, budget-aware context assembler for the AI cover note. No DB, no
 * framework. Tiers the payload by value (skeleton → descriptions → subtasks →
 * comments) and greedily adds whole units while they fit the token budget,
 * stopping entirely at the first unit that does not fit. The skeleton is always
 * present, even when it alone exceeds the budget.
 */
class CoverNoteContext
{
    /**
     * @param array    $header      project/client/range/line_items/totals/currency
     * @param array    $tasks       list of {title,description,subtasks[],comments[]}
     * @param int      $budget      max tokens for the whole payload
     * @param callable $countTokens fn(string): int
     */
    public static function assemble(array $header, array $tasks, int $budget, callable $countTokens): string
    {
        $payload = self::skeleton($header);

        // Tier 2 — descriptions.
        foreach ($tasks as $t) {
            $desc = self::flat((string) ($t['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — description: " . $desc;
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        // Tier 3 — subtasks (title + done-state).
        foreach ($tasks as $t) {
            if (empty($t['subtasks'])) {
                continue;
            }
            $parts = array_map(
                fn ($s) => ($s['title'] ?? '') . (! empty($s['done']) ? ' [done]' : ' [open]'),
                $t['subtasks']
            );
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — subtasks: " . implode('; ', $parts);
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        // Tier 4 — comments (first to drop on small models).
        foreach ($tasks as $t) {
            if (empty($t['comments'])) {
                continue;
            }
            $parts = array_map(fn ($c) => self::flat((string) ($c['text'] ?? '')), $t['comments']);
            $block = "\nTask \"" . ($t['title'] ?? '') . "\" — comments: " . implode(' | ', $parts);
            if ($countTokens($payload . $block) <= $budget) {
                $payload .= $block;
            } else {
                return $payload;
            }
        }

        return $payload;
    }

    private static function skeleton(array $h): string
    {
        $sym = $h['currency']['symbol'] ?? '$';
        $lines = [
            'INVOICE CONTEXT',
            'Project: ' . ($h['project'] ?? ''),
            'Client: ' . ($h['client'] ?? ''),
            'Period: ' . ($h['range']['start'] ?? '') . ' to ' . ($h['range']['end'] ?? '')
                . ' (' . ($h['granularity'] ?? '') . ')',
            'Line items:',
        ];
        foreach ($h['line_items'] ?? [] as $li) {
            $hours = rtrim(rtrim(number_format((float) ($li['hours'] ?? 0), 2), '0'), '.');
            $lines[] = '- ' . ($li['label'] ?? '') . ': ' . $hours . 'h';
        }
        $lines[] = 'Subtotal: ' . $sym . number_format((float) ($h['subtotal'] ?? 0), 2);
        if (! empty($h['tax']['enabled'])) {
            $lines[] = 'Tax (' . (float) ($h['tax']['rate'] ?? 0) . '%): '
                . $sym . number_format((float) ($h['tax']['amount'] ?? 0), 2);
        }
        $lines[] = 'Total: ' . $sym . number_format((float) ($h['total'] ?? 0), 2);
        return implode("\n", $lines);
    }

    /** Collapse runs of whitespace/newlines to single spaces and trim. */
    private static function flat(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — all `CoverNoteContextTest` tests green.

- [ ] **Step 5: Commit**

```bash
git add Model/CoverNoteContext.php Test/CoverNoteContextTest.php
git commit -m "feat: pure budget-aware cover-note context assembler"
```

---

### Task 3: `CoverNoteGenerator` — orchestration

**Files:**
- Create: `Model/CoverNoteGenerator.php`
- Modify: `Plugin.php:11-12` (register `coverNoteGenerator` container service)
- Test: `Test/CoverNoteGeneratorTest.php`

**Interfaces:**
- Consumes: `CoverNoteContext::assemble(...)` (Task 2); `InvoiceBuilder::lineItems(array $breakdown, float $rate): array` and `InvoiceBuilder::totals(array $lineItems, bool $taxEnabled, float $taxRate): array` (existing); container services `timeReportModel`, `taskFinderModel`, `subtaskModel`, `commentModel`.
- Produces: `CoverNoteGenerator::generate(array $ctx, object $registry, object $catalog): ?string`.
  - `$ctx`: `{project_id:int, user_id:int, start:string, end:string, granularity:string, rate:float, tax_enabled:bool, tax_rate:float, project_name:string, client:array, currency:array, style:string, profile_id:?string}`
  - `$registry`: object with `structured(array $messages, string $schema, ?string $profileId): array`.
  - `$catalog`: object with `getInputBudget(?string,int): int` and `countTokens(string,?string): int`.
  - Returns the trimmed cover-note string, or `null` when the model returns nothing.
  - Constants: `OUTPUT_RESERVE = 512`, `SCHEMA` (JSON string), `DEFAULT_STYLE`.
  - `systemPrompt(string $style): string` (public static, for the controller/tests).
  - `gatherTasks(array $taskIds, int $projectId): array` (protected — test seam).

**Note:** `$registry`/`$catalog` are passed as parameters (not constructed here) because the AiConnector classes may be absent at plugin-load time; the controller constructs them only behind the `aiReady()` gate. This keeps the generator free of `class_exists` concerns and lets tests pass anonymous fakes.

- [ ] **Step 1: Write the failing test**

Create `Test/CoverNoteGeneratorTest.php`:

```php
<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\TimeInvoice\Model\CoverNoteGenerator;

/** Test subclass: overrides the finder-backed gather step with fixed records. */
class CoverNoteGeneratorForTest extends CoverNoteGenerator
{
    public array $gathered = [
        ['title' => 'Homepage', 'description' => 'Rebuilt hero.',
         'subtasks' => [['title' => 'Hero', 'done' => true]],
         'comments' => [['text' => 'Approved.']]],
    ];
    public array $sawTaskIds = [];

    protected function gatherTasks(array $taskIds, int $projectId): array
    {
        $this->sawTaskIds = $taskIds;
        return $this->gathered;
    }
}

/** Fake AiConnector ProviderRegistry — records the messages, returns a canned note. */
class FakeRegistry
{
    public array $messages = [];
    public string $schema = '';
    public function structured(array $messages, string $schema, ?string $profileId): array
    {
        $this->messages = $messages;
        $this->schema = $schema;
        return ['cover_note' => 'We rebuilt the homepage hero this period.'];
    }
}

/** Fake ModelCatalog — fixed budget + a chars/4 counter. */
class FakeCatalog
{
    public function getInputBudget(?string $profileId = null, int $reserve = 1024): int { return 100000; }
    public function countTokens(string $text, ?string $profileId = null): int { return (int) ceil(mb_strlen($text) / 4); }
}

class CoverNoteGeneratorTest extends Base
{
    private function ctx(): array
    {
        return [
            'project_id' => 5, 'user_id' => 1,
            'start' => '2026-08-01', 'end' => '2026-08-31', 'granularity' => 'task',
            'rate' => 150.0, 'tax_enabled' => false, 'tax_rate' => 0.0,
            'project_name' => 'Acme', 'client' => ['name' => 'Acme Inc'],
            'currency' => ['code' => 'USD', 'symbol' => '$'],
            'style' => '', 'profile_id' => null,
        ];
    }

    private function withReport(array $breakdown): void
    {
        $this->container['timeReportModel'] = fn ($c) => new class($breakdown) {
            public function __construct(private array $b) {}
            public function report($pid, $s, $e, $g, $d, $u) { return ['breakdown' => $this->b]; }
        };
    }

    public function testGathersUnionOfBreakdownTaskIds(): void
    {
        $this->withReport([
            ['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11, 12]],
            ['key' => 'b', 'label' => '#2', 'hours' => 2.0, 'task_count' => 1, 'task_ids' => [12, 13]],
        ]);
        $gen = new CoverNoteGeneratorForTest($this->container);
        $note = $gen->generate($this->ctx(), new FakeRegistry(), new FakeCatalog());
        $this->assertSame('We rebuilt the homepage hero this period.', $note);
        sort($gen->sawTaskIds);
        $this->assertSame([11, 12, 13], $gen->sawTaskIds, 'deduped union of breakdown[].task_ids');
    }

    public function testSystemPromptCarriesStyleAndPayloadCarriesContext(): void
    {
        $this->withReport([['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11]]]);
        $reg = new FakeRegistry();
        $ctx = $this->ctx();
        $ctx['style'] = 'Terse and formal.';
        $gen = new CoverNoteGeneratorForTest($this->container);
        $gen->generate($ctx, $reg, new FakeCatalog());
        $this->assertSame('system', $reg->messages[0]['role']);
        $this->assertStringContainsString('Terse and formal.', $reg->messages[0]['content']);
        $this->assertSame('user', $reg->messages[1]['role']);
        $this->assertStringContainsString('INVOICE CONTEXT', $reg->messages[1]['content']);
        $this->assertStringContainsString('Rebuilt hero.', $reg->messages[1]['content']);
        $this->assertStringContainsString('cover_note', $reg->schema);
    }

    public function testDefaultStyleUsedWhenBlank(): void
    {
        $prompt = CoverNoteGenerator::systemPrompt('');
        $this->assertStringContainsString(CoverNoteGenerator::DEFAULT_STYLE, $prompt);
    }

    public function testReturnsNullWhenModelYieldsEmpty(): void
    {
        $this->withReport([['key' => 'a', 'label' => '#1', 'hours' => 5.0, 'task_count' => 1, 'task_ids' => [11]]]);
        $emptyReg = new class {
            public function structured(array $m, string $s, ?string $p): array { return ['cover_note' => '   ']; }
        };
        $gen = new CoverNoteGeneratorForTest($this->container);
        $this->assertNull($gen->generate($this->ctx(), $emptyReg, new FakeCatalog()));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `Class "Kanboard\Plugin\TimeInvoice\Model\CoverNoteGenerator" not found`.

- [ ] **Step 3: Write the implementation**

Create `Model/CoverNoteGenerator.php`:

```php
<?php

namespace Kanboard\Plugin\TimeInvoice\Model;

use Kanboard\Core\Base;

/**
 * Orchestrates AI cover-note generation: gather the billed tasks (via
 * TimeReport's breakdown[].task_ids + Kanboard finders), size the prompt to the
 * model's context window (AiConnector ModelCatalog), assemble tiered context
 * (CoverNoteContext), and generate a structured note (AiConnector
 * ProviderRegistry). The AiConnector objects are passed in — the caller
 * constructs them only behind the aiReady() gate.
 */
class CoverNoteGenerator extends Base
{
    public const OUTPUT_RESERVE = 512;
    public const SCHEMA = '{"type":"object","properties":{"cover_note":{"type":"string"}},"required":["cover_note"]}';
    public const DEFAULT_STYLE = 'Professional and concise, first-person plural ("we"), focused on outcomes delivered.';

    /**
     * @param array  $ctx      generation context (see plan Task 3 interface)
     * @param object $registry AiConnector ProviderRegistry (has structured())
     * @param object $catalog  AiConnector ModelCatalog (has getInputBudget()/countTokens())
     */
    public function generate(array $ctx, object $registry, object $catalog): ?string
    {
        $projectId   = (int) $ctx['project_id'];
        $userId      = (int) $ctx['user_id'];
        $granularity = (string) $ctx['granularity'];
        $rate        = (float) $ctx['rate'];
        $profileId   = $ctx['profile_id'] ?? null;

        $report    = $this->timeReportModel->report($projectId, $ctx['start'], $ctx['end'], $granularity, true, $userId);
        $breakdown = $report['breakdown'] ?? [];

        $taskIds = [];
        foreach ($breakdown as $row) {
            foreach (($row['task_ids'] ?? []) as $tid) {
                $taskIds[(int) $tid] = true;
            }
        }
        $taskIds = array_keys($taskIds);

        $items  = InvoiceBuilder::lineItems($breakdown, $rate);
        $totals = InvoiceBuilder::totals($items, ! empty($ctx['tax_enabled']), (float) $ctx['tax_rate']);
        $tasks  = $this->gatherTasks($taskIds, $projectId);

        $header = [
            'project'     => (string) ($ctx['project_name'] ?? ''),
            'client'      => (string) ($ctx['client']['name'] ?? ''),
            'range'       => ['start' => $ctx['start'], 'end' => $ctx['end']],
            'granularity' => $granularity,
            'line_items'  => $items,
            'subtotal'    => $totals['subtotal'],
            'tax'         => ['enabled' => ! empty($ctx['tax_enabled']), 'rate' => (float) $ctx['tax_rate'], 'amount' => $totals['tax']],
            'total'       => $totals['total'],
            'currency'    => $ctx['currency'] ?? ['code' => 'USD', 'symbol' => '$'],
        ];

        $system = self::systemPrompt((string) ($ctx['style'] ?? ''));
        $budget = max(0, $catalog->getInputBudget($profileId, self::OUTPUT_RESERVE) - $catalog->countTokens($system, $profileId));
        $payload = CoverNoteContext::assemble($header, $tasks, $budget, fn (string $s): int => $catalog->countTokens($s, $profileId));

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $payload],
        ];
        $result = $registry->structured($messages, self::SCHEMA, $profileId);
        $note   = trim((string) ($result['cover_note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    public static function systemPrompt(string $style): string
    {
        $style = trim($style) !== '' ? trim($style) : self::DEFAULT_STYLE;
        return 'You write concise, professional invoice cover notes for a solo consultant. '
            . 'Style: ' . $style . ' '
            . 'Summarize only the work described in the provided context. Do not invent work that is not present. '
            . 'Write 2 to 4 sentences of client-facing prose. Return only the cover note text.';
    }

    /**
     * Fetch title/description/subtasks/comments for the billed task ids, scoped to
     * the invoice's project. Protected so tests can substitute fixed records.
     *
     * @return list<array{title:string,description:string,subtasks:list<array{title:string,done:bool}>,comments:list<array{text:string}>}>
     */
    protected function gatherTasks(array $taskIds, int $projectId): array
    {
        $out = [];
        foreach ($taskIds as $tid) {
            $task = $this->taskFinderModel->getById((int) $tid);
            if (empty($task) || (int) ($task['project_id'] ?? 0) !== $projectId) {
                continue;
            }
            $subs = $this->subtaskModel->getAll((int) $tid);
            $comments = $this->commentModel->getAll((int) $tid);
            $out[] = [
                'title'       => (string) ($task['title'] ?? ''),
                'description' => (string) ($task['description'] ?? ''),
                'subtasks'    => array_map(
                    fn ($s) => ['title' => (string) ($s['title'] ?? ''), 'done' => ((int) ($s['status'] ?? 0)) === 2],
                    $subs
                ),
                'comments'    => array_map(fn ($c) => ['text' => (string) ($c['comment'] ?? '')], $comments),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Register the container service in `Plugin.php`**

Add to `initialize()`, next to the existing service registrations (after line 12):

```php
        $this->container['coverNoteGenerator'] = fn ($c) => new \Kanboard\Plugin\TimeInvoice\Model\CoverNoteGenerator($c);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — all `CoverNoteGeneratorTest` tests green.

- [ ] **Step 6: Commit**

```bash
git add Model/CoverNoteGenerator.php Plugin.php Test/CoverNoteGeneratorTest.php
git commit -m "feat: cover-note generator (gather → budget → assemble → structured)"
```

---

### Task 4: Settings — AI cover-note style instructions

**Files:**
- Modify: `Controller/SettingsController.php:9-22` (`currentSettings`), `:44-53` (`save`)
- Modify: `Template/invoice/settings.php` (add textarea)
- Test: `Test/SettingsControllerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: config key `timeinvoice_ai_style` (string); `currentSettings()['ai_style']`; a settings-page textarea named `ai_style`.

- [ ] **Step 1: Extend the settings tests (RED)**

Add to `Test/SettingsControllerTest.php` (inside the class):

```php
    public function testCurrentSettingsReadsAiStyle(): void
    {
        $this->container['configModel']->save(['timeinvoice_ai_style' => 'Terse and formal.']);
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $this->assertSame('Terse and formal.', $m->invoke($c)['ai_style']);
    }

    public function testCurrentSettingsAiStyleDefaultsEmpty(): void
    {
        $c = new SettingsController($this->container);
        $m = new ReflectionMethod($c, 'currentSettings');
        $m->setAccessible(true);
        $this->assertSame('', $m->invoke($c)['ai_style']);
    }

    public function testSaveWritesAiStyleAsAdmin(): void
    {
        $_SESSION['user'] = ['id' => 1, 'role' => \Kanboard\Core\Security\Role::APP_ADMIN];
        $this->silenceRedirects();
        $c = new SettingsController($this->container);
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'ai_style'   => 'First-person plural, outcome-focused.',
        ]);
        $c->save();
        $this->assertSame('First-person plural, outcome-focused.', $this->container['configModel']->get('timeinvoice_ai_style', ''));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `ai_style` key missing from `currentSettings()`; save does not persist it.

- [ ] **Step 3: Add `ai_style` to `currentSettings()`**

In `Controller/SettingsController.php`, add to the returned array in `currentSettings()` (after `number_format`):

```php
            'ai_style'      => $cfg->get('timeinvoice_ai_style', ''),
```

- [ ] **Step 4: Persist `ai_style` in `save()`**

In `save()`, add to the `configModel->save([...])` array:

```php
            'timeinvoice_ai_style'      => (string) ($v['ai_style'] ?? ''),
```

- [ ] **Step 5: Add the textarea to `Template/invoice/settings.php`**

Before the `<div class="form-actions">` block:

```php
    <?= $this->form->label(t('AI cover-note style instructions'), 'ai_style') ?>
    <?= $this->form->textarea('ai_style', $values) ?>
    <p class="form-help"><?= t('Guides the AI cover note tone (e.g. formal, concise, first-person plural). Leave blank for a sensible default.') ?></p>

```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — new `SettingsControllerTest` tests green; `TemplateAssetsTest` still green (the new `t()` strings contain no `%`).

- [ ] **Step 7: Commit**

```bash
git add Controller/SettingsController.php Template/invoice/settings.php Test/SettingsControllerTest.php
git commit -m "feat: settings field for AI cover-note style instructions"
```

---

### Task 5: Controller — `aiReady()` gate + form data wiring

**Files:**
- Modify: `Controller/InvoiceController.php` (add `aiRegistry`/`aiCatalog`/`aiReady`/`aiProfiles`/`aiDefaultProfile`; extend `form()`)
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: AiConnector `ProviderRegistry` (`isReady`, `listProfiles`, `getDefaultProfileId`) and `ModelCatalog`, by FQN via `class_exists` + `new`.
- Produces (protected methods): `aiRegistry(): ?object`, `aiCatalog(): ?object`, `aiReady(): bool`, `aiProfiles(): array`, `aiDefaultProfile(): string`. `form()` passes `ai_ready`, `ai_profiles`, `ai_default_profile` to the template.

**Note:** in the test harness AiConnector is not loaded, so `class_exists` is false and `aiReady()` returns false — that is the degrade path, and it is exactly what we assert here. The happy path is covered by the generator test (Task 3) and the live E2E (Task 8).

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
    public function testAiReadyFalseWithoutAiConnector(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'aiReady');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($c), 'no AiConnector classes loaded → not ready');
    }

    public function testAiProfilesEmptyWithoutAiConnector(): void
    {
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'aiProfiles');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke($c));
        $d = new ReflectionMethod($c, 'aiDefaultProfile');
        $d->setAccessible(true);
        $this->assertSame('', $d->invoke($c));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `aiReady` / `aiProfiles` methods do not exist.

- [ ] **Step 3: Add the AI gate helpers to `InvoiceController`**

Add these protected methods to `Controller/InvoiceController.php` (e.g. after `accessibleProjectIds`):

```php
    protected function aiRegistry(): ?object
    {
        $cls = '\\Kanboard\\Plugin\\AiConnector\\Model\\ProviderRegistry';
        return class_exists($cls) ? new $cls($this->container) : null;
    }

    protected function aiCatalog(): ?object
    {
        $cls = '\\Kanboard\\Plugin\\AiConnector\\Model\\ModelCatalog';
        return class_exists($cls) ? new $cls($this->container) : null;
    }

    /** Both AiConnector classes must exist (1.1.0+) AND a provider must be usable. */
    protected function aiReady(): bool
    {
        $reg = $this->aiRegistry();
        return $reg !== null && $this->aiCatalog() !== null && $reg->isReady();
    }

    protected function aiProfiles(): array
    {
        $reg = $this->aiRegistry();
        return $reg !== null ? $reg->listProfiles() : [];
    }

    protected function aiDefaultProfile(): string
    {
        $reg = $this->aiRegistry();
        return $reg !== null ? (string) $reg->getDefaultProfileId() : '';
    }
```

- [ ] **Step 4: Pass the AI data into the form view**

In `form()`, extend the view array passed to `TimeInvoice:invoice/form`:

```php
        $this->response->html($this->helper->layout->app('TimeInvoice:invoice/form', [
            'title'              => t('New invoice'),
            'project'            => $this->projectModel->getById($projectId),
            'values'             => $values,
            'ai_ready'           => $this->aiReady(),
            'ai_profiles'        => $this->aiProfiles(),
            'ai_default_profile' => $this->aiDefaultProfile(),
        ]));
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — the two new controller tests green; existing tests unaffected.

- [ ] **Step 6: Commit**

```bash
git add Controller/InvoiceController.php Test/InvoiceControllerTest.php
git commit -m "feat: AiConnector readiness gate + profile data for the invoice form"
```

---

### Task 6: Controller — `generateCoverNote` endpoint + route

**Files:**
- Modify: `Controller/InvoiceController.php` (add `coverNoteContext()` helper + `generateCoverNote()` action)
- Modify: `Plugin.php` (register the route)
- Test: `Test/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `aiReady()`, `aiRegistry()`, `aiCatalog()` (Task 5); `coverNoteGenerator` service (Task 3); `configModel` for `timeinvoice_ai_style`.
- Produces: `generateCoverNote()` (POST, CSRF, access-scoped, JSON `{note}` or `{error}`); protected `coverNoteContext(array $v, int $projectId, int $userId): array` (the mapped `$ctx` for the generator — extracted so it is unit-testable without emitting a response). Route `timeinvoice/generate-note` → `InvoiceController::generateCoverNote`.

- [ ] **Step 1: Write the failing test**

Add to `Test/InvoiceControllerTest.php`:

```php
    public function testCoverNoteContextMapsRequestValues(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'Acme']);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'coverNoteContext');
        $m->setAccessible(true);
        $v = [
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'granularity' => 'week',
            'rate' => '150', 'tax_enabled' => '1', 'tax_rate' => '10',
            'client_name' => 'Acme Inc', 'profile_id' => '',
        ];
        $ctx = $m->invoke($c, $v, $pid, 1);
        $this->assertSame($pid, $ctx['project_id']);
        $this->assertSame(1, $ctx['user_id']);
        $this->assertSame('week', $ctx['granularity']);
        $this->assertSame(150.0, $ctx['rate']);
        $this->assertTrue($ctx['tax_enabled']);
        $this->assertSame('Acme Inc', $ctx['client']['name']);
        $this->assertSame('Acme', $ctx['project_name']);
        $this->assertNull($ctx['profile_id'], 'empty profile_id maps to null (use default)');
    }

    public function testCoverNoteContextClampsBadGranularityAndDates(): void
    {
        $pid = (new \Kanboard\Model\ProjectModel($this->container))->create(['name' => 'P']);
        $c = new InvoiceController($this->container);
        $m = new ReflectionMethod($c, 'coverNoteContext');
        $m->setAccessible(true);
        $ctx = $m->invoke($c, ['granularity' => 'bogus', 'start_date' => 'nope', 'profile_id' => 'p2'], $pid, 1);
        $this->assertSame('task', $ctx['granularity'], 'invalid granularity falls back to task');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $ctx['start']);
        $this->assertSame('p2', $ctx['profile_id']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `coverNoteContext` method does not exist.

- [ ] **Step 3: Add `coverNoteContext()` + `generateCoverNote()` to `InvoiceController`**

```php
    /** Map request values → the CoverNoteGenerator context. Pure enough to unit-test. */
    protected function coverNoteContext(array $v, int $projectId, int $userId): array
    {
        return [
            'project_id'   => $projectId,
            'user_id'      => $userId,
            'start'        => $this->validDate($v['start_date'] ?? '', date('Y-m-01')),
            'end'          => $this->validDate($v['end_date'] ?? '', date('Y-m-d')),
            'granularity'  => in_array($v['granularity'] ?? '', ['day', 'week', 'task', 'total'], true) ? $v['granularity'] : 'task',
            'rate'         => (float) ($v['rate'] ?? 0),
            'tax_enabled'  => ! empty($v['tax_enabled']),
            'tax_rate'     => (float) ($v['tax_rate'] ?? 0),
            'project_name' => (string) ($this->projectModel->getById($projectId)['name'] ?? ''),
            'client'       => ['name' => (string) ($v['client_name'] ?? '')],
            'currency'     => $this->globalDefaults()['currency'] ?? ['code' => 'USD', 'symbol' => '$'],
            'style'        => (string) $this->configModel->get('timeinvoice_ai_style', ''),
            'profile_id'   => ($v['profile_id'] ?? '') !== '' ? (string) $v['profile_id'] : null,
        ];
    }

    /** POST — generate an AI cover note as JSON for the draft form. */
    public function generateCoverNote(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $projectId = (int) $this->request->getIntegerParam('project_id');

        if (! in_array($projectId, $this->accessibleProjectIds($userId), true) || ! $this->hasTimeReport()) {
            $this->response->json(['error' => t('Not available for this project.')], 400);
            return;
        }
        if (! $this->aiReady()) {
            $this->response->json(['error' => t('AI cover notes need the AiConnector plugin (1.1.0+) with a configured provider.')], 400);
            return;
        }

        $ctx  = $this->coverNoteContext($this->request->getValues(), $projectId, $userId);
        $note = $this->coverNoteGenerator->generate($ctx, $this->aiRegistry(), $this->aiCatalog());

        if ($note === null) {
            $this->response->json(['error' => t('The model returned no cover note. Try again or pick a different provider.')], 502);
            return;
        }
        $this->response->json(['note' => $note]);
    }
```

- [ ] **Step 4: Register the route in `Plugin.php`**

Add alongside the other invoice routes (after the `timeinvoice/pdf` route):

```php
        $this->route->addRoute('timeinvoice/generate-note', 'InvoiceController', 'generateCoverNote', 'TimeInvoice');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — the two `coverNoteContext` tests green.

- [ ] **Step 6: Commit**

```bash
git add Controller/InvoiceController.php Plugin.php Test/InvoiceControllerTest.php
git commit -m "feat: generateCoverNote endpoint returning JSON cover note"
```

---

### Task 7: Form button + profile picker + delegated JS

**Files:**
- Modify: `Template/invoice/form.php` (AI block, gated on `ai_ready`)
- Modify: `Assets/js/timeinvoice.js` (delegated generate handler)
- Modify: `Assets/css/timeinvoice.css` (loading affordance — optional minimal)
- Test: `Test/TemplateAssetsTest.php`

**Interfaces:**
- Consumes: `ai_ready`, `ai_profiles`, `ai_default_profile` (Task 5); route `timeinvoice/generate-note` (Task 6).
- Produces: a `.timeinvoice-generate-note` button with `data-url`, a `.timeinvoice-profile` select; JS that POSTs the serialized form and fills `textarea[name='notes']`.

- [ ] **Step 1: Write the failing test**

Add to `Test/TemplateAssetsTest.php`:

```php
    public function testFormHasAiBlockGatedOnAiReady(): void
    {
        $src = file_get_contents($this->root() . '/Template/invoice/form.php');
        $this->assertStringContainsString('ai_ready', $src, 'AI block must be gated on ai_ready');
        $this->assertStringContainsString('timeinvoice-generate-note', $src, 'generate button present');
        $this->assertStringContainsString('timeinvoice-profile', $src, 'profile select present');
        $this->assertStringContainsString("'generateCoverNote'", $src, 'button targets the generate action');
    }

    public function testJsHasGenerateHandler(): void
    {
        $src = file_get_contents($this->root() . '/Assets/js/timeinvoice.js');
        $this->assertStringContainsString('timeinvoice-generate-note', $src);
        $this->assertStringContainsString("name='notes'", $src, 'handler writes into the notes textarea');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: FAIL — `form.php` has no AI block; `timeinvoice.js` has no generate handler.

- [ ] **Step 3: Add the AI block to `Template/invoice/form.php`**

Insert immediately **before** the `<div class="form-actions">` block (so it sits under the notes textarea, inside the form):

```php
    <?php if (! empty($ai_ready)): ?>
        <div class="timeinvoice-ai">
            <?= $this->form->label(t('AI provider'), 'profile_id') ?>
            <select class="timeinvoice-profile" name="profile_id">
                <?php foreach ($ai_profiles as $p): ?>
                    <option value="<?= $this->text->e($p['id']) ?>"<?= $p['id'] === $ai_default_profile ? ' selected' : '' ?>><?= $this->text->e($p['label']) ?></option>
                <?php endforeach ?>
            </select>
            <button type="button" class="btn timeinvoice-generate-note"
                    data-url="<?= $this->url->href('InvoiceController', 'generateCoverNote', array('plugin' => 'TimeInvoice')) ?>">
                <?= t('Generate cover note') ?>
            </button>
        </div>
    <?php endif ?>

```

- [ ] **Step 4: Add the delegated handler to `Assets/js/timeinvoice.js`**

Inside the IIFE, after the existing `.timeinvoice-send` handler:

```javascript
    // Generate an AI cover note and drop it into the notes textarea.
    jQuery(document).on("click", ".timeinvoice-generate-note", function (e) {
        e.preventDefault();
        var $btn = jQuery(this);
        var $form = $btn.closest("form");
        var url = $btn.data("url");
        $btn.prop("disabled", true).addClass("timeinvoice-loading");
        jQuery.ajax({
            url: url,
            method: "POST",
            dataType: "json",
            data: $form.serialize()
        }).done(function (res) {
            if (res && res.note) {
                $form.find("textarea[name='notes']").val(res.note);
            } else {
                window.alert((res && res.error) || "No cover note returned.");
            }
        }).fail(function (xhr) {
            var msg = "Cover note generation failed.";
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) { msg = j.error; } } catch (err) {}
            window.alert(msg);
        }).always(function () {
            $btn.prop("disabled", false).removeClass("timeinvoice-loading");
        });
    });
```

The `$form.serialize()` payload includes the CSRF hidden field (`csrf_token`) and the `profile_id` select, so `checkCSRFForm()` passes and the chosen profile is sent — no manual field assembly needed.

- [ ] **Step 5: Add a minimal loading style to `Assets/css/timeinvoice.css`**

Append:

```css
.timeinvoice-ai { margin: 10px 0; }
.timeinvoice-loading { opacity: 0.6; cursor: progress; }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — new `TemplateAssetsTest` tests green; `testNoInlineHandlersInTemplates` still green (button uses a class + `data-url`, no `on*=` attribute); `testNoUnescapedPercentInSingleArgTranslations` still green.

- [ ] **Step 7: Commit**

```bash
git add Template/invoice/form.php Assets/js/timeinvoice.js Assets/css/timeinvoice.css Test/TemplateAssetsTest.php
git commit -m "feat: cover-note generate button, profile picker, and CSP-safe handler"
```

---

### Task 8: Live end-to-end verification (Ollama direct + metamodels proxy)

**Files:** none (live verification against the dev stack). Record evidence in the SDD report.

**Interfaces:** Consumes the whole feature. No unit test — this task's deliverable is documented live evidence, mirroring the v1.0.0 Task 14 E2E that caught the render-crash and PDF-encoding bugs.

**Preconditions:** the dev stack `testing/docker-compose.dev.yml` on `:8081` (admin/admin) mounts TimeInvoice, TimeReport, and AiConnector. Ollama is local with `qwen3.8:latest` pulled. Edit only on the host, never inside the container. Drive the dev DB via curl/PDO, never the production MCP.

- [ ] **Step 1: Restart the dev stack and confirm all three plugins load**

Run (from monorepo root):
```bash
docker compose -f testing/docker-compose.dev.yml restart kanboard
```
Verify in the UI (Settings → Plugins) that TimeInvoice 1.1.0, TimeReport (≥1.3.0), and AiConnector 1.1.0 are all enabled.

- [ ] **Step 2: Configure two AiConnector profiles**

In Settings → AI Connector, add:
1. An **Ollama** profile — model `qwen3.8:latest`, base URL `http://host.docker.internal:11434/v1` (or the reachable Ollama host from the container; confirm reachability). Keyless.
2. A **metamodels-proxy** profile — provider OpenAI-compatible (or Ollama), base URL = the metamodels proxy endpoint, model = the id the proxy exposes.
Set one as default. Confirm `Test Connection` succeeds for each.

Expected evidence: both profiles listed; test-connection OK.

- [ ] **Step 3: Set a cover-note style and open a draft**

In Settings → Invoice settings, set `AI cover-note style instructions` (e.g. "Formal, concise, first-person plural."). On project 20 ("TimeReport Demo"), open a new invoice draft with a date range that has tracked hours.

Expected: the draft form shows the **AI provider** select (both profiles) and a **Generate cover note** button (they appear only because `aiReady()` is true).

- [ ] **Step 4: Generate through the Ollama profile**

Select the Ollama profile, click **Generate cover note**. Wait for completion.

Expected evidence: the notes textarea fills with 2–4 sentences of plausible prose referencing the billed tasks; no JS console errors (CSP-safe); the button re-enables. Save the draft and reopen — the note persists in the field. Send the invoice and confirm the note is frozen into the snapshot (visible on the PDF / stored record).

- [ ] **Step 5: Regenerate through the metamodels-proxy profile**

Select the metamodels-proxy profile, click Generate again.

Expected evidence: the field is replaced with a fresh note produced via the proxy — proving the same TimeInvoice code path drives both backends purely by profile selection (the two-app E2E). Record both notes.

- [ ] **Step 6: Verify graceful degrade**

Disable the AiConnector plugin (or remove all provider profiles so `isReady()` is false), reload the draft form.

Expected evidence: the AI provider select and Generate button are **gone**; the notes field remains a normal editable textarea; saving/sending an invoice still works. Re-enable AiConnector afterward.

- [ ] **Step 7: Record evidence and fix any live-only defects**

Document all evidence in the SDD report. If any defect surfaces (provider error handling, JSON round-trip, CSP, budget sizing on the small model), fix it with a test-first regression where a unit test can express it, then re-run `./testing/run-plugin-tests.sh TimeInvoice` and re-verify live. Commit any fixes.

```bash
git commit -am "test: live E2E evidence + any regression fixes for AI cover note"
```

---

### Task 9: Release v1.1.0 + directory listing

**Files:**
- Modify (external repo): `kanboard-modmenu-directory/plugins.json` (TimeInvoice entry)

**Interfaces:** Consumes the finished, green, live-verified plugin. Outward-facing — publishes a public release.

**Note:** version/dependency metadata was already set in Task 1; this task tags, verifies CI, and updates the directory. Confirm with the user before pushing the tag and the directory change (outward-facing actions).

- [ ] **Step 1: Final green check**

Run: `./testing/run-plugin-tests.sh TimeInvoice`
Expected: PASS — full suite green. Record the count.

- [ ] **Step 2: Confirm version alignment**

Verify `plugin.json` `version` == `Plugin.php::getPluginVersion()` == `1.1.0`.

- [ ] **Step 3: Push and tag (in the TimeInvoice repo)**

```bash
git push origin main
git tag v1.1.0
git push origin v1.1.0
```

- [ ] **Step 4: Verify the release CI + asset**

Confirm the zip-on-tag workflow run succeeds and `TimeInvoice-1.1.0.zip` downloads HTTP 200 with a single `TimeInvoice/` top-level folder, `plugin.json`+`Plugin.php` at the top, `Test/` excluded, FPDF vendored.

- [ ] **Step 5: Update the ModMenu directory entry**

In `kanboard-modmenu-directory/plugins.json`, update the TimeInvoice entry: raise `requires` TimeReport to `1.3.0` and add `recommends` AiConnector `1.1.0` (bare-version, array-of-objects, matching the house style — surgical text edit, do not reserialize the whole file). Commit and push. Confirm the raw feed serves the updated entry.

- [ ] **Step 6: Commit the directory change**

```bash
# in the kanboard-modmenu-directory repo
git commit -am "TimeInvoice 1.1.0: TimeReport 1.3.0 floor + recommends AiConnector 1.1.0"
git push
```

---

## Self-Review

**1. Spec coverage:**
- Optional AI cover note into the existing notes field → Tasks 6, 7 (generate → fill textarea; freezes via existing snapshot path).
- `recommends` AiConnector 1.1.0 + `requires` TimeReport 1.3.0 → Task 1.
- Gate checks both AiConnector classes + `isReady()` → Task 5 (`aiReady`).
- `ProviderRegistry::structured()` + `{cover_note}` schema → Task 3.
- `ModelCatalog` budgeting via `getInputBudget()` − `countTokens(system)` → Task 3.
- Priority-tiered, budget-trimmed context (skeleton→desc→subtasks→comments) → Task 2.
- Billed task set from `breakdown[].task_ids`, enriched via Kanboard finders → Task 3.
- Generate button + profile picker on the form, CSP-safe JS → Task 7.
- Settings style instructions → Task 4.
- POST + CSRF + access scope + JSON → Task 6.
- Multi-user out of scope (requester-only 6-arg `report()`) → Task 3 (six-arg call).
- Live E2E (Ollama + metamodels proxy, degrade) → Task 8. Release + directory → Task 9.
No gaps found.

**2. Placeholder scan:** No TBDs. Every code step carries complete code. The only conditional is Task 1's plan-time check that `task_ids` exists at the 1.3.0 tag (a verification instruction with a concrete fallback: raise the floor), not a placeholder.

**3. Type consistency:** `CoverNoteContext::assemble(array,array,int,callable): string` is defined in Task 2 and called identically in Task 3. `CoverNoteGenerator::generate(array $ctx, object $registry, object $catalog): ?string` is produced in Task 3 and called with those exact arg types in Task 6. `coverNoteContext()` returns the `$ctx` shape Task 3 consumes (keys match: project_id, user_id, start, end, granularity, rate, tax_enabled, tax_rate, project_name, client, currency, style, profile_id). `InvoiceBuilder::lineItems/totals` signatures match the existing file. `aiReady/aiRegistry/aiCatalog/aiProfiles/aiDefaultProfile` defined in Task 5, used in Task 6. Route string `timeinvoice/generate-note` → `generateCoverNote` consistent across Tasks 6 and 7. Config key `timeinvoice_ai_style` consistent across Tasks 4 and 6.
