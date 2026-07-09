# ModMenu Dependency System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let plugins declare `requires` (hard) / `recommends` (soft) dependencies on other plugins, and have ModMenu enforce them at the enable/install/disable/uninstall choke points with a one-click resolve flow.

**Architecture:** A new **pure** `DependencyResolver` model holds all logic (classify a dep, build the transitive install/enable plan, find reverse dependents). `PluginManager` parses the metadata and orchestrates (gathers maps, gates its actions). The `ModMenuController` renders a confirm interstitial and executes the plan. The existing folder-move engine is untouched. Same split ModMenu already uses: pure models + thin controllers.

**Tech Stack:** PHP ≥ 8.4, Kanboard core ≥ 1.2.47 (buildless plugin), PHPUnit via `./testing/run-plugin-tests.sh ModMenu`, MIT.

## Global Constraints

- **Metadata home:** authoritative in each plugin's own `plugin.json`; mirrored into the directory `plugins.json`. Both keys optional.
- **Dep object shape:** `{ "plugin": string (required), "min_version": string|absent, "reason": string|absent }`. `min_version` compared with `version_compare($installed, $min, '>=')`.
- **Two keys:** `requires` = hard (gates activation, reverse-protected); `recommends` = soft (non-blocking prompt).
- **Backward compatible:** absent/malformed keys ⇒ empty list ⇒ zero behavior change. Never fatal on bad data — ignore malformed entries.
- **`DependencyResolver` is pure:** no filesystem or network I/O; every input (installedMap, catalog, per-plugin deps) is passed in.
- **Forward gate:** all `requires` satisfied → act; unmet + resolvable → one-click transitive resolve (deps-first, then target); unmet + unresolvable → hard block, no partial action. `recommends` never block.
- **Reverse gate:** disabling/uninstalling a plugin an ACTIVE plugin hard-`requires` (and is satisfied by) → block, name the dependents. `recommends` dependents never block.
- **Security:** every mutation (incl. the new `resolve`) is admin-gated (`requireAdmin()`) + CSRF-guarded (`checkCSRFForm()`). The confirm form posts only `name` + `action`; the controller **re-derives** the plan and all download URLs server-side from a fresh catalog fetch — never trust a posted plan or URL. Downloads stay https-only (existing guard).
- **Identity:** a plugin's operation key is ALWAYS its folder name (as `readMeta()` already enforces).
- **Version:** bump ModMenu `1.0.1 → 1.1.0` (feature release) in `plugin.json` and `Plugin.php`.
- **No new deps, no build step, no DB table** (dependency state is derived, not stored).

---

### Task 1: DependencyResolver — classification core

**Files:**
- Create: `ModMenu/Model/DependencyResolver.php`
- Test: `ModMenu/Test/DependencyResolverTest.php`

**Interfaces:**
- Produces:
  - `DependencyResolver::isSatisfied(array $dep, array $installedMap): bool` (static)
  - `DependencyResolver::classify(array $dep, array $installedMap, array $catalog): array` (static) → `['plugin','status','action','installed_version','min_version','reason','download']`, `status ∈ {satisfied,disabled,outdated,missing}`, `action ∈ {none,enable,update,install,unresolvable}`
  - `DependencyResolver->resolveForward(array $deps, string $kind, array $installedMap, array $catalog): array` → `['satisfied'=>bool, 'deps'=>array]` (each dep also carries `'kind'`)
- Consumes: `installedMap` = `name => ['version'=>string,'status'=>'active'|'disabled']`; `catalog` = `name => ['version'=>?string,'download'=>?string,'requires'=>?array]`.

- [ ] **Step 1: Write the failing test**

```php
// ModMenu/Test/DependencyResolverTest.php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\DependencyResolver;

class DependencyResolverTest extends Base
{
    private $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver($this->container);
    }

    private function dep(string $plugin, ?string $min = null): array
    {
        $d = ['plugin' => $plugin];
        if ($min !== null) { $d['min_version'] = $min; }
        return $d;
    }

    // ---- isSatisfied ----
    public function testIsSatisfiedTrueWhenActiveNoMin()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $this->assertTrue(DependencyResolver::isSatisfied($this->dep('Cal'), $map));
    }

    public function testIsSatisfiedFalseWhenMissing()
    {
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal'), []));
    }

    public function testIsSatisfiedFalseWhenDisabled()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal'), $map));
    }

    public function testIsSatisfiedRespectsMinVersion()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal', '1.1.0'), $map));
        $this->assertTrue(DependencyResolver::isSatisfied($this->dep('Cal', '1.0.0'), $map));
    }

    // ---- classify ----
    public function testClassifyMissingWithCatalogIsInstall()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), [], $catalog);
        $this->assertSame('missing', $c['status']);
        $this->assertSame('install', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyMissingWithoutCatalogIsUnresolvable()
    {
        $c = DependencyResolver::classify($this->dep('Cal'), [], []);
        $this->assertSame('missing', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
    }

    public function testClassifyDisabledIsEnable()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'disabled']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('enable', $c['action']);
    }

    public function testClassifyOutdatedWithNewerCatalogIsUpdate()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $catalog = ['Cal' => ['version' => '1.2.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('update', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyOutdatedWithoutCatalogIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
    }

    public function testClassifySatisfied()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('satisfied', $c['status']);
        $this->assertSame('none', $c['action']);
    }

    // ---- resolveForward ----
    public function testResolveForwardSatisfiedFlagAndKind()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $out = $this->resolver->resolveForward([$this->dep('Cal', '1.1.0')], 'requires', $map, []);
        $this->assertTrue($out['satisfied']);
        $this->assertSame('requires', $out['deps'][0]['kind']);
    }

    public function testResolveForwardUnsatisfiedWhenAnyUnmet()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->resolver->resolveForward([$this->dep('Cal', '1.1.0')], 'requires', $map, []);
        $this->assertFalse($out['satisfied']);
    }

    public function testResolveForwardIgnoresMalformedEntries()
    {
        $out = $this->resolver->resolveForward([['no_plugin' => 'x'], 'nonsense', []], 'requires', [], []);
        $this->assertTrue($out['satisfied']); // nothing valid to be unsatisfied
        $this->assertCount(0, $out['deps']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `Class "Kanboard\Plugin\ModMenu\Model\DependencyResolver" not found`.

- [ ] **Step 3: Write the implementation**

```php
// ModMenu/Model/DependencyResolver.php
<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;

/**
 * Pure dependency logic for ModMenu. No filesystem or network I/O — every input
 * (installed map, directory catalog, per-plugin deps) is passed in by
 * PluginManager. Classifies a plugin's declared dependencies, builds the ordered
 * plan to satisfy hard requirements, and finds reverse dependents that block
 * removal.
 *
 * A "dep object" is ['plugin' => string, 'min_version' => ?string, 'reason' => ?string].
 * A plugin declares two arrays of them: `requires` (hard) and `recommends` (soft).
 */
class DependencyResolver extends Base
{
    /**
     * Satisfied = installed AND active AND (no min_version OR installed >= min).
     */
    public static function isSatisfied(array $dep, array $installedMap): bool
    {
        $name = $dep['plugin'] ?? '';
        if ($name === '' || ! isset($installedMap[$name])) {
            return false;
        }
        $entry = $installedMap[$name];
        if (($entry['status'] ?? '') !== 'active') {
            return false;
        }
        $min = $dep['min_version'] ?? null;
        if ($min === null || $min === '') {
            return true;
        }
        return version_compare((string) ($entry['version'] ?? '0.0.0'), (string) $min, '>=');
    }

    /**
     * Classify one dependency into a status + the action needed to satisfy it.
     */
    public static function classify(array $dep, array $installedMap, array $catalog): array
    {
        $name   = (string) ($dep['plugin'] ?? '');
        $min    = isset($dep['min_version']) && $dep['min_version'] !== '' ? (string) $dep['min_version'] : null;
        $reason = (string) ($dep['reason'] ?? '');

        $installed        = $installedMap[$name] ?? null;
        $installedVersion = $installed['version'] ?? null;
        $catalogEntry     = $catalog[$name] ?? null;
        $download         = $catalogEntry['download'] ?? null;
        $catalogVersion   = $catalogEntry['version'] ?? null;

        $catalogMeetsMin = $download !== null
            && ($min === null || version_compare((string) $catalogVersion, $min, '>='));

        if ($installed === null) {
            $status = 'missing';
            $action = $download !== null ? 'install' : 'unresolvable';
        } elseif (($installed['status'] ?? '') === 'disabled') {
            $status = 'disabled';
            if ($min !== null && ! version_compare((string) $installedVersion, $min, '>=')) {
                // Present but too old even once enabled → needs a newer copy.
                $action = $catalogMeetsMin ? 'update' : 'unresolvable';
            } else {
                $action = 'enable';
            }
        } elseif ($min !== null && ! version_compare((string) $installedVersion, $min, '>=')) {
            $status = 'outdated';
            $action = $catalogMeetsMin ? 'update' : 'unresolvable';
        } else {
            $status = 'satisfied';
            $action = 'none';
        }

        return [
            'plugin'            => $name,
            'status'            => $status,
            'action'            => $action,
            'installed_version' => $installedVersion,
            'min_version'       => $min,
            'reason'            => $reason,
            'download'          => in_array($action, ['install', 'update'], true) ? $download : null,
        ];
    }

    /**
     * Resolve a plugin's declared deps of one kind ('requires' | 'recommends').
     */
    public function resolveForward(array $deps, string $kind, array $installedMap, array $catalog): array
    {
        $resolved  = [];
        $satisfied = true;
        foreach ($deps as $dep) {
            if (! is_array($dep) || empty($dep['plugin'])) {
                continue; // defensive: ignore malformed entries
            }
            $c = self::classify($dep, $installedMap, $catalog);
            $c['kind'] = $kind;
            if ($c['status'] !== 'satisfied') {
                $satisfied = false;
            }
            $resolved[] = $c;
        }
        return ['satisfied' => $satisfied, 'deps' => $resolved];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS — all `DependencyResolverTest` tests green, existing suite unaffected.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/DependencyResolver.php ModMenu/Test/DependencyResolverTest.php
git commit -m "feat(ModMenu): DependencyResolver classification core (isSatisfied/classify/resolveForward)"
```

---

### Task 2: DependencyResolver — reverse dependents

**Files:**
- Modify: `ModMenu/Model/DependencyResolver.php` (add `resolveReverse`)
- Test: `ModMenu/Test/DependencyResolverTest.php` (add cases)

**Interfaces:**
- Produces: `DependencyResolver->resolveReverse(string $target, array $installedPluginsDeps, array $installedMap): array` → list of `['plugin'=>string,'min_version'=>?string]`. `$installedPluginsDeps` = `name => ['status'=>'active'|'disabled','requires'=>array]`.

- [ ] **Step 1: Write the failing test** (append to `DependencyResolverTest`)

```php
    // ---- resolveReverse ----
    public function testResolveReverseFindsActiveHardDependent()
    {
        $installedMap = [
            'Cal' => ['version' => '1.1.0', 'status' => 'active'],
            'Dep' => ['version' => '1.0.0', 'status' => 'active'],
        ];
        $depsByPlugin = [
            'Cal' => ['status' => 'active', 'requires' => []],
            'Dep' => ['status' => 'active', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
        ];
        $blockers = $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap);
        $this->assertCount(1, $blockers);
        $this->assertSame('Dep', $blockers[0]['plugin']);
    }

    public function testResolveReverseIgnoresDisabledDependent()
    {
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Cal' => ['status' => 'active', 'requires' => []],
            'Dep' => ['status' => 'disabled', 'requires' => [['plugin' => 'Cal']]],
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }

    public function testResolveReverseIgnoresRecommendsOnlyDependent()
    {
        // 'Sched' only recommends Cal (not in its requires) → not a blocker.
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Cal'   => ['status' => 'active', 'requires' => []],
            'Sched' => ['status' => 'active', 'requires' => []], // recommends live elsewhere; reverse only reads requires
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }

    public function testResolveReverseIgnoresUnsatisfiedRequirement()
    {
        // Dep requires Cal >= 2.0.0 but Cal is 1.1.0 → requirement not currently met,
        // so removing Cal doesn't break an already-broken relationship.
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Dep' => ['status' => 'active', 'requires' => [['plugin' => 'Cal', 'min_version' => '2.0.0']]],
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `Call to undefined method ...::resolveReverse()`.

- [ ] **Step 3: Add the implementation** (append inside `DependencyResolver`)

```php
    /**
     * Which ACTIVE installed plugins hard-require $target and are satisfied by it
     * today? Only `requires` count; `recommends` never block removal. A dependent
     * whose requirement is already unmet is not a blocker (nothing to break).
     *
     * @param array $installedPluginsDeps  name => ['status'=>..., 'requires'=>[dep objects]]
     */
    public function resolveReverse(string $target, array $installedPluginsDeps, array $installedMap): array
    {
        $blockers = [];
        foreach ($installedPluginsDeps as $name => $info) {
            if ($name === $target || ($info['status'] ?? '') !== 'active') {
                continue;
            }
            foreach (($info['requires'] ?? []) as $dep) {
                if (! is_array($dep) || ($dep['plugin'] ?? '') !== $target) {
                    continue;
                }
                if (self::isSatisfied($dep, $installedMap)) {
                    $blockers[] = ['plugin' => (string) $name, 'min_version' => $dep['min_version'] ?? null];
                }
            }
        }
        return $blockers;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/DependencyResolver.php ModMenu/Test/DependencyResolverTest.php
git commit -m "feat(ModMenu): DependencyResolver::resolveReverse (active hard dependents)"
```

---

### Task 3: DependencyResolver — transitive resolve plan

**Files:**
- Modify: `ModMenu/Model/DependencyResolver.php` (add `resolveClosure` + private `walk`)
- Test: `ModMenu/Test/DependencyResolverTest.php` (add cases)

**Interfaces:**
- Produces: `DependencyResolver->resolveClosure(array $requires, array $installedMap, array $catalog): array` → ordered (deps-first, deduped) list of `['plugin'=>string,'action'=>string,'download'=>?string,'min_version'=>?string]`. `action` ∈ `enable|install|update|unresolvable`. Satisfied deps are omitted.

- [ ] **Step 1: Write the failing test** (append)

```php
    // ---- resolveClosure ----
    public function testResolveClosureSingleMissing()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $plan = $this->resolver->resolveClosure([$this->dep('Cal', '1.1.0')], [], $catalog);
        $this->assertCount(1, $plan);
        $this->assertSame('Cal', $plan[0]['plugin']);
        $this->assertSame('install', $plan[0]['action']);
    }

    public function testResolveClosureOmitsSatisfied()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $plan = $this->resolver->resolveClosure([$this->dep('Cal', '1.1.0')], $map, []);
        $this->assertSame([], $plan);
    }

    public function testResolveClosureIsDepsFirstAndDeduped()
    {
        // Dep(missing) requires Cal(missing). Plan must list Cal before Dep, once each.
        $catalog = [
            'Dep' => ['version' => '1.0.0', 'download' => 'https://x/dep.zip', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
            'Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Dep')], [], $catalog);
        $order = array_column($plan, 'plugin');
        $this->assertSame(['Cal', 'Dep'], $order);
    }

    public function testResolveClosureMarksUnresolvable()
    {
        // Missing and not in the catalog → a plan step the caller must block on.
        $plan = $this->resolver->resolveClosure([$this->dep('Ghost')], [], []);
        $this->assertCount(1, $plan);
        $this->assertSame('unresolvable', $plan[0]['action']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `Call to undefined method ...::resolveClosure()`.

- [ ] **Step 3: Add the implementation** (append inside `DependencyResolver`)

```php
    /**
     * Build the ordered plan (deps-first, deduped) needed to satisfy a plugin's
     * hard `requires`, transitively. Satisfied deps are omitted. A required dep
     * that cannot be auto-resolved appears with action 'unresolvable' so the
     * caller blocks instead of half-installing.
     *
     * @param array $requires  the starting plugin's `requires` list
     * @return array list of ['plugin','action','download','min_version']
     */
    public function resolveClosure(array $requires, array $installedMap, array $catalog): array
    {
        $plan = [];
        $seen = [];
        $this->walk($requires, $installedMap, $catalog, $plan, $seen);
        return $plan;
    }

    private function walk(array $requires, array $installedMap, array $catalog, array &$plan, array &$seen): void
    {
        foreach ($requires as $dep) {
            if (! is_array($dep) || empty($dep['plugin'])) {
                continue;
            }
            $name = (string) $dep['plugin'];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $c = self::classify($dep, $installedMap, $catalog);
            if ($c['status'] === 'satisfied') {
                continue;
            }

            // Recurse into this dep's own requires (from the catalog) FIRST, so the
            // plan is deps-first (post-order): grandchildren before children.
            $childRequires = $catalog[$name]['requires'] ?? [];
            if (is_array($childRequires) && $childRequires !== []) {
                $this->walk($childRequires, $installedMap, $catalog, $plan, $seen);
            }

            $plan[] = [
                'plugin'      => $name,
                'action'      => $c['action'],
                'download'    => $c['download'],
                'min_version' => $c['min_version'],
            ];
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/DependencyResolver.php ModMenu/Test/DependencyResolverTest.php
git commit -m "feat(ModMenu): DependencyResolver::resolveClosure (transitive deps-first plan)"
```

---

### Task 4: PluginManager — dependency metadata plumbing

**Files:**
- Modify: `ModMenu/Model/PluginManager.php` (`readMeta` + `normalizeDeps` + `installedPluginsDeps` + `unmetDepsFor`)
- Test: `ModMenu/Test/PluginManagerTest.php` (add cases + extend `seedPlugin`)

**Interfaces:**
- Consumes: `DependencyResolver` (Task 1).
- Produces:
  - `readMeta()` now includes `'requires'=>array, 'recommends'=>array` (normalized dep objects; `[]` when absent/malformed).
  - `PluginManager->installedPluginsDeps(): array` → `name => ['status'=>..., 'requires'=>array]`.
  - `PluginManager->unmetDepsFor(array $requires, array $recommends, array $catalog): array` → merged list of classified deps whose `status !== 'satisfied'` (each carries `kind`).

- [ ] **Step 1: Write the failing test** — first extend the existing `seedPlugin` helper to accept deps, then add cases (append to `PluginManagerTest`)

```php
    // Extended seeder: pass optional requires/recommends arrays.
    private function seedPluginWithDeps(string $dir, string $name, string $version, array $requires = [], array $recommends = []): void
    {
        mkdir("$dir/$name", 0777, true);
        file_put_contents("$dir/$name/Plugin.php", "<?php\n");
        $json = ['name' => $name, 'version' => $version];
        if ($requires !== [])   { $json['requires'] = $requires; }
        if ($recommends !== []) { $json['recommends'] = $recommends; }
        file_put_contents("$dir/$name/plugin.json", json_encode($json));
    }

    public function testReadMetaDefaultsDepsToEmpty()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0'); // existing seeder, no deps
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame([], $byName['Alpha']['requires']);
        $this->assertSame([], $byName['Alpha']['recommends']);
    }

    public function testReadMetaParsesDeps()
    {
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0',
            [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            [['plugin' => 'Cal', 'min_version' => '1.1.0', 'reason' => 'badges']]);
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame('Cal', $byName['Dep']['requires'][0]['plugin']);
        $this->assertSame('1.1.0', $byName['Dep']['requires'][0]['min_version']);
        $this->assertSame('badges', $byName['Dep']['recommends'][0]['reason']);
    }

    public function testReadMetaIgnoresMalformedDeps()
    {
        // requires is a string, and an element lacks 'plugin' → both dropped, not fatal.
        $this->seedPluginWithDeps($this->active, 'Bad', '1.0.0');
        $dir = "{$this->active}/Bad";
        file_put_contents("$dir/plugin.json", json_encode([
            'name' => 'Bad', 'version' => '1.0.0',
            'requires' => 'oops', 'recommends' => [['no_plugin' => 'x']],
        ]));
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame([], $byName['Bad']['requires']);
        $this->assertSame([], $byName['Bad']['recommends']);
    }

    public function testInstalledPluginsDepsShape()
    {
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->seedPlugin($this->disabled, 'Cal', '1.1.0');
        $deps = $this->manager->installedPluginsDeps();
        $this->assertSame('active', $deps['Dep']['status']);
        $this->assertSame('Cal', $deps['Dep']['requires'][0]['plugin']);
        $this->assertSame('disabled', $deps['Cal']['status']);
    }

    public function testUnmetDepsForReturnsOnlyUnsatisfied()
    {
        // Cal active & new enough → satisfied requires drop out; missing recommend stays.
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $catalog = [];
        $unmet = $this->manager->unmetDepsFor(
            [['plugin' => 'Cal', 'min_version' => '1.1.0']],           // satisfied requires
            [['plugin' => 'Extra', 'reason' => 'nice to have']],       // missing recommend
            $catalog
        );
        $this->assertCount(1, $unmet);
        $this->assertSame('Extra', $unmet[0]['plugin']);
        $this->assertSame('recommends', $unmet[0]['kind']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `requires` key missing / `installedPluginsDeps()` undefined.

- [ ] **Step 3: Implement** — in `PluginManager.php`:

Add `use Kanboard\Plugin\ModMenu\Model\DependencyResolver;` is unnecessary (same namespace); just reference `DependencyResolver`.

In `readMeta()`, add the two default keys to the base `$meta` array (after `'homepage' => ''`):

```php
            'homepage' => '',
            'requires' => [],
            'recommends' => [],
```

and inside the `if (is_array($json))` block (after the `homepage` line):

```php
                $meta['requires']   = self::normalizeDeps($json['requires'] ?? []);
                $meta['recommends'] = self::normalizeDeps($json['recommends'] ?? []);
```

Add these methods to `PluginManager`:

```php
    /**
     * Normalize a raw deps array into clean dep objects. Non-arrays and elements
     * without a 'plugin' key are dropped (never fatal).
     */
    private static function normalizeDeps($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) && ! empty($entry['plugin'])) {
                $out[] = [
                    'plugin'      => (string) $entry['plugin'],
                    'min_version' => isset($entry['min_version']) && $entry['min_version'] !== '' ? (string) $entry['min_version'] : null,
                    'reason'      => isset($entry['reason']) ? (string) $entry['reason'] : '',
                ];
            }
        }
        return $out;
    }

    /**
     * name => ['status' => 'active'|'disabled', 'requires' => [dep objects]] for
     * every installed plugin — the input the reverse-dependency check needs.
     */
    public function installedPluginsDeps(): array
    {
        $out = [];
        foreach ($this->listInstalled() as $p) {
            $out[$p['name']] = [
                'status'   => $p['status'],
                'requires' => $p['requires'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Classified unmet deps (requires + recommends) for one plugin, for display.
     * Satisfied deps are omitted; each entry carries its 'kind'.
     */
    public function unmetDepsFor(array $requires, array $recommends, array $catalog): array
    {
        $resolver = new DependencyResolver($this->container);
        $map = $this->installedMap();
        $all = array_merge(
            $resolver->resolveForward($requires, 'requires', $map, $catalog)['deps'],
            $resolver->resolveForward($recommends, 'recommends', $map, $catalog)['deps']
        );
        return array_values(array_filter($all, static fn ($d) => $d['status'] !== 'satisfied'));
    }
```

Note: `installedMap()` already returns `name => ['version','status']`; leave it as-is.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/PluginManager.php ModMenu/Test/PluginManagerTest.php
git commit -m "feat(ModMenu): PluginManager parses requires/recommends + dep-map helpers"
```

---

### Task 5: PluginManager — forward/reverse gates + resolve executor

**Files:**
- Modify: `ModMenu/Model/PluginManager.php` (`disable`/`uninstall` reverse guard; `forwardCheck`; `resolveAndActivate`)
- Test: `ModMenu/Test/PluginManagerTest.php` (add cases)

**Interfaces:**
- Produces:
  - `PluginManager->forwardCheck(array $requires, array $catalog): array` → `['satisfied'=>bool,'plan'=>array,'blocked'=>bool,'requires'=>array]`. `blocked` = plan contains an `unresolvable` step.
  - `PluginManager->resolveAndActivate(string $name, string $action, string $target, array $plan): void` — executes plan deps-first (`enable`/`installFromUrl`), then activates the target (`enable`/`installFromUrl($target)`); throws `ModMenuException` on any `unresolvable` step before touching anything.
  - `disable()`/`uninstall()` now throw `ModMenuException` when an active dependent hard-requires the target.

- [ ] **Step 1: Write the failing test** (append to `PluginManagerTest`)

```php
    public function testDisableBlockedByActiveDependent()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal', 'min_version' => '1.1.0']]);
        $this->expectException(ModMenuException::class);
        $this->manager->disable('Cal');
    }

    public function testUninstallBlockedByActiveDependent()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->expectException(ModMenuException::class);
        $this->manager->uninstall('Cal');
    }

    public function testDisableAllowedWhenDependentDisabled()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->disabled, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->manager->disable('Cal'); // no throw
        $this->assertDirectoryExists("{$this->disabled}/Cal");
    }

    public function testForwardCheckSatisfied()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $check = $this->manager->forwardCheck([['plugin' => 'Cal', 'min_version' => '1.1.0']], []);
        $this->assertTrue($check['satisfied']);
        $this->assertSame([], $check['plan']);
        $this->assertFalse($check['blocked']);
    }

    public function testForwardCheckNeedsConfirm()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $check = $this->manager->forwardCheck([['plugin' => 'Cal', 'min_version' => '1.1.0']], $catalog);
        $this->assertFalse($check['satisfied']);
        $this->assertFalse($check['blocked']);
        $this->assertSame('Cal', $check['plan'][0]['plugin']);
        $this->assertSame('install', $check['plan'][0]['action']);
    }

    public function testForwardCheckBlockedWhenUnresolvable()
    {
        $check = $this->manager->forwardCheck([['plugin' => 'Ghost']], []); // missing, no catalog
        $this->assertFalse($check['satisfied']);
        $this->assertTrue($check['blocked']);
    }

    public function testResolveAndActivateEnablesDepThenTarget()
    {
        // Cal is disabled; Dep is disabled and requires Cal. Enabling Dep must enable Cal first.
        $this->seedPlugin($this->disabled, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->disabled, 'Dep', '1.0.0', [['plugin' => 'Cal', 'min_version' => '1.1.0']]);
        $plan = [['plugin' => 'Cal', 'action' => 'enable', 'download' => null, 'min_version' => '1.1.0']];
        $this->manager->resolveAndActivate('Dep', 'enable', '', $plan);
        $this->assertDirectoryExists("{$this->active}/Cal");
        $this->assertDirectoryExists("{$this->active}/Dep");
    }

    public function testResolveAndActivateThrowsOnUnresolvableStepBeforeActing()
    {
        $this->seedPlugin($this->disabled, 'Dep', '1.0.0');
        $plan = [['plugin' => 'Ghost', 'action' => 'unresolvable', 'download' => null, 'min_version' => null]];
        try {
            $this->manager->resolveAndActivate('Dep', 'enable', '', $plan);
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            // Target must NOT have been enabled.
            $this->assertDirectoryExists("{$this->disabled}/Dep");
            $this->assertDirectoryDoesNotExist("{$this->active}/Dep");
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — reverse guard absent / `forwardCheck` + `resolveAndActivate` undefined.

- [ ] **Step 3: Implement** — in `PluginManager.php`:

In `disable()` add the guard before the move:

```php
    public function disable(string $name): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->assertNoActiveDependents($name);
        $this->move($name, $this->activeDir(), $this->disabledDir());
    }
```

In `uninstall()` add the guard after the self-guard:

```php
        $this->guardName($name);
        $this->guardSelf($name);
        $this->assertNoActiveDependents($name);
```

Add these methods:

```php
    private function assertNoActiveDependents(string $name): void
    {
        $resolver = new DependencyResolver($this->container);
        $blockers = $resolver->resolveReverse($name, $this->installedPluginsDeps(), $this->installedMap());
        if ($blockers !== []) {
            $names = implode(', ', array_map(static fn ($b) => $b['plugin'], $blockers));
            throw new ModMenuException(t('"%s" is required by: %s. Disable or remove those first.', $name, $names));
        }
    }

    /**
     * Forward verdict for activating a plugin with the given `requires`.
     * satisfied=true → act directly. Otherwise 'plan' is the deps-first closure;
     * 'blocked'=true when a required dep cannot be auto-resolved.
     */
    public function forwardCheck(array $requires, array $catalog): array
    {
        $resolver = new DependencyResolver($this->container);
        $map = $this->installedMap();
        $forward = $resolver->resolveForward($requires, 'requires', $map, $catalog);
        if ($forward['satisfied']) {
            return ['satisfied' => true, 'plan' => [], 'blocked' => false, 'requires' => $forward['deps']];
        }
        $plan = $resolver->resolveClosure($requires, $map, $catalog);
        $blocked = false;
        foreach ($plan as $step) {
            if ($step['action'] === 'unresolvable') {
                $blocked = true;
                break;
            }
        }
        return ['satisfied' => false, 'plan' => $plan, 'blocked' => $blocked, 'requires' => $forward['deps']];
    }

    /**
     * Execute a resolve plan deps-first, then activate the target. Aborts before
     * any action if the plan contains an unresolvable step (no partial activation).
     *
     * @param string $action  'enable' | 'install'
     * @param string $target  target's own download URL (only used when action='install')
     * @param array  $plan    ordered steps from forwardCheck()
     */
    public function resolveAndActivate(string $name, string $action, string $target, array $plan): void
    {
        foreach ($plan as $step) {
            if (($step['action'] ?? '') === 'unresolvable') {
                throw new ModMenuException(t('"%s" cannot be resolved automatically. Install it manually first.', $step['plugin'] ?? '?'));
            }
        }
        foreach ($plan as $step) {
            switch ($step['action']) {
                case 'enable':
                    $this->enable($step['plugin']);
                    break;
                case 'install':
                case 'update':
                    $this->installFromUrl((string) $step['download']);
                    break;
            }
        }
        if ($action === 'install') {
            $this->installFromUrl($target);
        } else {
            $this->enable($name);
        }
    }
```

Note: `enable()` stays ungated (no forward check inside it) — the controller is the forward gate, and `resolveAndActivate` has already satisfied the deps, so gating `enable()` here would be redundant and would break the executor.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/PluginManager.php ModMenu/Test/PluginManagerTest.php
git commit -m "feat(ModMenu): PluginManager forward/reverse gates + resolveAndActivate"
```

---

### Task 6: DirectoryClient — carry deps through + catalog map

**Files:**
- Modify: `ModMenu/Model/DirectoryClient.php` (add `catalogMap()`; confirm pass-through)
- Test: `ModMenu/Test/DirectoryClientTest.php` (add cases)

**Interfaces:**
- Produces: `DirectoryClient->catalogMap(): array` → `name => catalog entry` (entry carries `version`, `download`, `requires`, `recommends`, `status`). Thin network wrapper over `fetchAll()`.
- Guarantees: `annotate()` and `merge()` preserve `requires`/`recommends` fields untouched.

- [ ] **Step 1: Write the failing test** (append to `DirectoryClientTest`)

```php
    public function testAnnotatePreservesDependencyFields()
    {
        $plugins = [[
            'name' => 'Dep', 'version' => '1.0.0',
            'requires'   => [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            'recommends' => [['plugin' => 'Cal']],
        ]];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame('Cal', $out[0]['requires'][0]['plugin']);
        $this->assertSame('1.1.0', $out[0]['requires'][0]['min_version']);
        $this->assertSame('Cal', $out[0]['recommends'][0]['plugin']);
    }

    public function testMergePreservesDependencyFields()
    {
        $sourcesData = [[
            'url' => 'https://a.com/plugins.json',
            'plugins' => [[
                'name' => 'Dep', 'version' => '1.0.0',
                'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            ]],
        ]];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertSame('Cal', $merged[0]['requires'][0]['plugin']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: These two assertions PASS already (merge/annotate copy whole entries) — that is the point: they lock in the pass-through so a future refactor can't silently drop deps. If they pass immediately, proceed to add `catalogMap()`; if any fail, fix `annotate`/`merge` to stop dropping unknown keys. (`catalogMap()` is not yet defined, so no test references it until Step 3's usage in later tasks.)

- [ ] **Step 3: Add `catalogMap()`** to `DirectoryClient`:

```php
    /**
     * Merged directory catalog indexed by plugin name, for the dependency
     * resolver/controller. Network wrapper over fetchAll(); returns [] rather
     * than throwing when sources are unreachable (callers degrade gracefully:
     * fewer deps are auto-resolvable, nothing crashes).
     */
    public function catalogMap(): array
    {
        $map = [];
        foreach ($this->fetchAll()['plugins'] as $entry) {
            if (! empty($entry['name'])) {
                $map[$entry['name']] = $entry;
            }
        }
        return $map;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (pass-through cases green; suite unaffected).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/DirectoryClient.php ModMenu/Test/DirectoryClientTest.php
git commit -m "feat(ModMenu): DirectoryClient catalogMap() + lock dep-field pass-through"
```

---

### Task 7: Controller + route + resolve interstitial

**Files:**
- Modify: `ModMenu/Controller/ModMenuController.php` (`enable`/`install` branch; new `resolve`; `confirm` reverse blockers; helpers)
- Modify: `ModMenu/Plugin.php` (add `config/modmenu/plugin/resolve` route)
- Create: `ModMenu/Template/plugin/resolve.php`
- Test: `ModMenu/Test/ControllerAccessTest.php` (add `resolve` gate case)

**Interfaces:**
- Consumes: `PluginManager::forwardCheck/resolveAndActivate/installedPluginsDeps/installedMap`, `DirectoryClient::catalogMap`, `DependencyResolver::resolveReverse`.
- Produces: route action `resolve` (admin + CSRF); confirm interstitial rendered via `layout->config('ModMenu:plugin/resolve', ...)`.

- [ ] **Step 1: Write the failing test** (append to `ControllerAccessTest`)

```php
    public function testResolveForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->resolve();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `Call to undefined method ModMenuController::resolve()`.

- [ ] **Step 3: Implement.**

Add the route in `Plugin.php` `initialize()` (next to the other plugin routes):

```php
        $this->route->addRoute('config/modmenu/plugin/resolve', 'ModMenu:ModMenuController', 'resolve');
```

In `ModMenuController.php`, add `use Kanboard\Plugin\ModMenu\Model\DependencyResolver;` to the imports. Replace `enable()` and `install()` and add the helpers + `resolve()`; update `confirm()`:

```php
    public function enable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->forwardOrConfirm($this->postValue('name'), 'enable', '');
    }

    public function install()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $target = $this->postValue('archive_url');

        // Legacy path: an install form that posts only archive_url (no name) can't
        // pre-flight deps — install directly, unchanged behavior.
        if ($name === '') {
            $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($target), t('Plugin installed.'));
            $this->backToDirectory();
            return;
        }
        $this->forwardOrConfirm($name, 'install', $target);
    }

    public function resolve()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $action = $this->postValue('action') === 'install' ? 'install' : 'enable';

        // Re-derive the plan + URLs server-side from a fresh catalog — never trust the post.
        $manager  = $this->manager();
        $catalog  = $this->catalog();
        $requires = $this->requiresFor($name, $action, $catalog, $manager);
        $check    = $manager->forwardCheck($requires, $catalog);
        $target   = $action === 'install' ? (string) ($catalog[$name]['download'] ?? '') : '';

        $this->runAndFlash(
            fn (PluginManager $m) => $m->resolveAndActivate($name, $action, $target, $check['plan']),
            $action === 'install' ? t('Plugin and its dependencies installed.') : t('Plugin and its dependencies enabled.')
        );
        $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
    }

    public function confirm()
    {
        $this->requireAdmin();
        $name = $this->request->getStringParam('name');
        $manager = $this->manager();
        $resolver = new DependencyResolver($this->container);
        $blockers = $resolver->resolveReverse($name, $manager->installedPluginsDeps(), $manager->installedMap());
        $this->response->html($this->template->render('ModMenu:plugin/remove', [
            'name'     => $name,
            'blockers' => array_map(static fn ($b) => $b['plugin'], $blockers),
        ]));
    }

    // ── dependency helpers ──────────────────────────────────────────────────

    private function catalog(): array
    {
        return (new DirectoryClient($this->container))->catalogMap();
    }

    private function requiresFor(string $name, string $action, array $catalog, PluginManager $manager): array
    {
        if ($action === 'install') {
            return $catalog[$name]['requires'] ?? [];
        }
        $deps = $manager->installedPluginsDeps();
        return $deps[$name]['requires'] ?? [];
    }

    /**
     * Forward gate for enable/install: act directly when satisfied, block when a
     * requirement is unresolvable, otherwise render the resolve-plan confirmation.
     */
    private function forwardOrConfirm(string $name, string $action, string $target): void
    {
        $manager  = $this->manager();
        $catalog  = $this->catalog();
        $requires = $this->requiresFor($name, $action, $catalog, $manager);

        // Resolve the target's own download URL when installing without one.
        if ($action === 'install' && $target === '') {
            $target = (string) ($catalog[$name]['download'] ?? '');
        }

        $check = $manager->forwardCheck($requires, $catalog);

        if ($check['satisfied']) {
            $this->runAndFlash(function (PluginManager $m) use ($name, $action, $target) {
                $action === 'install' ? $m->installFromUrl($target) : $m->enable($name);
            }, $action === 'install' ? t('Plugin installed.') : t('Plugin enabled.'));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        if ($check['blocked']) {
            $this->flash->failure(t('"%s" has requirements that cannot be installed automatically. Install them manually first.', $name));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        $this->response->html($this->helper->layout->config('ModMenu:plugin/resolve', [
            'title'  => t('ModMenu'),
            'name'   => $name,
            'action' => $action,
            'plan'   => $check['plan'],
        ]));
    }

    private function backToDirectory()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }
```

Create `ModMenu/Template/plugin/resolve.php`:

```php
<div class="page-header"><h2><?= t('Resolve dependencies') ?></h2></div>
<div class="confirm">
    <p class="alert alert-info">
        <?= t('"%s" needs other plugins. ModMenu will set them up first:', $this->text->e($name)) ?>
    </p>
    <ul class="modmenu-plan">
        <?php foreach ($plan as $step): ?>
            <li>
                <?php if ($step['action'] === 'install'): ?>
                    <?= t('Install %s', $this->text->e($step['plugin'])) ?>
                <?php elseif ($step['action'] === 'update'): ?>
                    <?= t('Update %s', $this->text->e($step['plugin'])) ?>
                <?php else: ?>
                    <?= t('Enable %s', $this->text->e($step['plugin'])) ?>
                <?php endif ?>
                <?php if (! empty($step['min_version'])): ?> (&ge; <?= $this->text->e($step['min_version']) ?>)<?php endif ?>
            </li>
        <?php endforeach ?>
        <li><strong><?= $action === 'install' ? t('Install %s', $this->text->e($name)) : t('Enable %s', $this->text->e($name)) ?></strong></li>
    </ul>
    <form method="post" action="<?= $this->url->href('ModMenuController', 'resolve', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
        <input type="hidden" name="action" value="<?= $this->text->e($action) ?>">
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Confirm') ?></button>
            <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu']) ?>
        </div>
    </form>
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS — `testResolveForbiddenForNonAdmin` green; full suite green.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Controller/ModMenuController.php ModMenu/Plugin.php ModMenu/Template/plugin/resolve.php ModMenu/Test/ControllerAccessTest.php
git commit -m "feat(ModMenu): resolve action + forward confirm interstitial + reverse-aware confirm"
```

---

### Task 8: Templates — dependency hints on Installed/Browse + reverse block on Remove

**Files:**
- Modify: `ModMenu/Controller/ModMenuController.php` (`show()` annotates unmet deps)
- Modify: `ModMenu/Template/settings/installed.php` (unmet-dep lines)
- Modify: `ModMenu/Template/settings/directory.php` (pre-flight lines + hidden `name` on install)
- Modify: `ModMenu/Template/plugin/remove.php` (reverse-block branch)
- Modify: `ModMenu/Assets/css/modmenu.css` (dep hint styling)

**Interfaces:**
- Consumes: `PluginManager::unmetDepsFor` (Task 4), `catalog()` (Task 7).
- View-only additions; verified by live E2E (Task 9's E2E) — no new unit test (templates aren't unit-tested in this suite).

- [ ] **Step 1: Annotate unmet deps in `show()`** — replace the body of `show()` in `ModMenuController.php`:

```php
    public function show()
    {
        $this->requireAdmin();
        $manager = $this->manager();

        // Classify each installed plugin's unmet deps against install state only
        // (empty catalog → no network on the Installed tab; the Install/Enable
        // buttons re-resolve server-side). recommends surface as soft hints.
        $plugins = $manager->listInstalled();
        foreach ($plugins as &$p) {
            $p['unmet_deps'] = $manager->unmetDepsFor($p['requires'] ?? [], $p['recommends'] ?? [], []);
        }
        unset($p);

        $this->response->html($this->helper->layout->config('ModMenu:settings/installed', [
            'title' => t('ModMenu'),
            'tab' => 'installed',
            'plugins' => $plugins,
            'is_configured' => $manager->isConfigured(),
            'not_configured_reason' => $manager->notConfiguredReason(),
            'self_name' => PluginManager::SELF,
        ]));
    }
```

- [ ] **Step 2: Render unmet-dep lines** in `installed.php` — insert immediately after the `<?php if (! empty($p['description'])) ...` block and before the `<?php if ($p['name'] !== $self_name):` actions block:

```php
            <?php if (! empty($p['unmet_deps'])): ?>
                <div class="modmenu-deps">
                    <?php foreach ($p['unmet_deps'] as $dep): ?>
                        <?php $hard = $dep['kind'] === 'requires'; ?>
                        <div class="modmenu-dep modmenu-dep--<?= $hard ? 'required' : 'recommended' ?>">
                            <span class="modmenu-dep__label">
                                <?php if ($hard): ?>
                                    <?= t('Missing requirement: %s', $this->text->e($dep['plugin'])) ?>
                                <?php else: ?>
                                    <?= t('Works better with %s', $this->text->e($dep['plugin'])) ?>
                                <?php endif ?>
                                <?php if (! empty($dep['min_version'])): ?> (&ge; <?= $this->text->e($dep['min_version']) ?>)<?php endif ?>
                                <?php if (! empty($dep['reason'])): ?> — <?= $this->text->e($dep['reason']) ?><?php endif ?>
                            </span>
                            <?php if ($dep['status'] === 'disabled'): ?>
                                <form method="post" style="display:inline"
                                      action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
                                    <?= $this->form->csrf() ?>
                                    <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
                                    <button type="submit" class="btn btn-blue"><?= t('Enable %s', $this->text->e($dep['plugin'])) ?></button>
                                </form>
                            <?php elseif ($dep['status'] === 'missing'): ?>
                                <form method="post" style="display:inline"
                                      action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                                    <?= $this->form->csrf() ?>
                                    <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
                                    <button type="submit" class="btn btn-blue"><?= t('Install %s', $this->text->e($dep['plugin'])) ?></button>
                                </form>
                            <?php else: /* outdated */ ?>
                                <?= $this->url->link(t('Update via Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, 'btn') ?>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
```

Note: the `install` action posts only `name` (no `archive_url`); the controller (`forwardOrConfirm`) resolves the download URL from the catalog server-side.

- [ ] **Step 3: Browse pre-flight + hidden name** in `directory.php` — add a pre-flight line after the `modmenu-card__status` div (inside the card), and add a hidden `name` to the install form.

Pre-flight line (after the `description` `<p>`):

```php
            <?php if (! empty($p['requires'])): ?>
                <div class="modmenu-dep modmenu-dep--required">
                    <?= t('Requires:') ?>
                    <?php foreach ($p['requires'] as $i => $r): ?><?= $i ? ', ' : ' ' ?><?= $this->text->e($r['plugin']) ?><?php if (! empty($r['min_version'])): ?> &ge; <?= $this->text->e($r['min_version']) ?><?php endif ?><?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if (! empty($p['recommends'])): ?>
                <div class="modmenu-dep modmenu-dep--recommended">
                    <?= t('Recommends:') ?>
                    <?php foreach ($p['recommends'] as $i => $r): ?><?= $i ? ', ' : ' ' ?><?= $this->text->e($r['plugin']) ?><?php endforeach ?>
                </div>
            <?php endif ?>
```

In the `available` install form, add the hidden name field (so install pre-flights the target's own requires):

```php
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Install') ?></button>
                    </form>
```

- [ ] **Step 4: Reverse-block branch** in `remove.php` — wrap the existing confirm form:

```php
<div class="page-header"><h2><?= t('Remove plugin') ?></h2></div>
<div class="confirm">
    <?php if (! empty($blockers)): ?>
        <p class="alert alert-error">
            <?= t('"%s" is required by: %s. Disable or remove those first.', $this->text->e($name), $this->text->e(implode(', ', $blockers))) ?>
        </p>
        <div class="form-actions">
            <?= $this->url->link(t('Close'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, 'close-popover') ?>
        </div>
    <?php else: ?>
        <p class="alert alert-info">
            <?= t('Do you really want to remove "%s"? Its files will be deleted from the server.', $this->text->e($name)) ?>
        </p>
        <form method="post" action="<?= $this->url->href('ModMenuController', 'uninstall', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
            <?= $this->form->csrf() ?>
            <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
            <div class="form-actions">
                <button type="submit" class="btn btn-red"><?= t('Yes, remove it') ?></button>
                <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, 'close-popover') ?>
            </div>
        </form>
    <?php endif ?>
</div>
```

- [ ] **Step 5: CSS** — append to `ModMenu/Assets/css/modmenu.css`:

```css
.modmenu-deps { margin: 8px 0; display: flex; flex-direction: column; gap: 6px; }
.modmenu-dep { font-size: 13px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.modmenu-dep--required { color: #b91c1c; }
.modmenu-dep--recommended { color: #6b7280; }
.modmenu-plan { margin: 8px 0 16px 18px; }
```

- [ ] **Step 6: Run tests + verify no regression**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS — full suite green (templates add no unit tests; the `show()` change is covered by existing `ControllerAccessTest::testShowForbiddenForNonAdmin` plus manual/E2E in Task 9).

- [ ] **Step 7: Commit**

```bash
git add ModMenu/Controller/ModMenuController.php ModMenu/Template/settings/installed.php ModMenu/Template/settings/directory.php ModMenu/Template/plugin/remove.php ModMenu/Assets/css/modmenu.css
git commit -m "feat(ModMenu): dependency hints on Installed/Browse + reverse-block on Remove"
```

---

### Task 9: Docs, version bump, rollout data + live E2E

**Files:**
- Modify: `ModMenu/plugin.json` + `ModMenu/Plugin.php` (version `1.1.0`)
- Modify: `ModMenu/README.md` (field reference: `requires`/`recommends`)
- Modify: `ModMenu/CHANGELOG.md` (1.1.0 entry)
- Modify: `DependencyPlugin/plugin.json`, `SchedulerPlugin/plugin.json` (add `recommends`)
- Modify: `kanboard-modmenu-directory/plugins.json` (mirror `recommends` for Dependency + Scheduler)

**Interfaces:** none (data + docs). This task ships the rollout data that exercises the soft-dependency path end-to-end.

- [ ] **Step 1: Bump ModMenu version.** In `ModMenu/plugin.json` set `"version": "1.1.0"`. In `ModMenu/Plugin.php` `getPluginVersion()` return `'1.1.0'`.

- [ ] **Step 2: README field reference.** In `ModMenu/README.md`, add two rows to the `plugins.json` field-reference table:

```markdown
| `requires` | array | Hard dependencies — dep objects `{ "plugin": "Name", "min_version": "1.1.0" }`. ModMenu blocks activation until each is installed, active, and ≥ `min_version`, offering a one-click resolve. Reverse-protected: a required plugin can't be disabled/removed while an active dependent needs it. |
| `recommends` | array | Soft dependencies — same dep-object shape plus an optional `"reason"`. Non-blocking: ModMenu shows a "works better with" hint and a one-click install, but activation proceeds without them. |
```

And add a short subsection after the table:

```markdown
### Dependencies

A plugin declares dependencies on other plugins in its own `plugin.json` (authoritative) and, for directory listings, the same fields are mirrored into `plugins.json`:

```json
{
  "name": "DependencyPlugin",
  "requires":   [ { "plugin": "CalendarPlugin", "min_version": "1.1.0" } ],
  "recommends": [ { "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "adds calendar badges" } ]
}
```

- **`requires`** blocks enable/install until satisfied (with a one-click resolve that installs/enables the chain), and blocks disable/uninstall of anything an active plugin still needs.
- **`recommends`** only prompts an easy install; it never blocks.
- Both are optional and backward-compatible — a plugin without them behaves exactly as before.
```

- [ ] **Step 3: CHANGELOG.** Prepend a `1.1.0` entry to `ModMenu/CHANGELOG.md`:

```markdown
## [1.1.0] — 2026-07-09

### Added

- **Plugin dependency system.** Plugins declare `requires` (hard) and `recommends` (soft) dependencies in `plugin.json` (mirrored in the directory `plugins.json`).
  - `requires` blocks enable/install until each dependency is installed, active, and ≥ its `min_version`, with a one-click resolve that installs/enables the whole chain (transitive, deps-first).
  - Reverse protection: a plugin an active dependent hard-requires cannot be disabled or uninstalled — ModMenu names the dependents instead.
  - `recommends` surfaces a non-blocking "works better with" hint plus one-click install on the Installed and Browse tabs.
- New pure `DependencyResolver` model (classify / transitive plan / reverse dependents), fully unit-tested.

---
```

- [ ] **Step 4: Suite rollout data.** In `DependencyPlugin/plugin.json` add:

```json
    "recommends": [ { "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "adds blocked/blocker badges on calendar events" } ]
```

In `SchedulerPlugin/plugin.json` add:

```json
    "recommends": [
        { "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "shows the auto-moved badge on calendar events" },
        { "plugin": "DependencyPlugin", "reason": "lets the scheduler skip blocked tasks" }
    ]
```

(Insert as a top-level key in each JSON object; keep it valid JSON — add a comma after the previous last field.)

In `kanboard-modmenu-directory/plugins.json`, add the identical `recommends` arrays to the `DependencyPlugin` and `SchedulerPlugin` entries.

- [ ] **Step 5: Run the full suite + validate JSON.**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS — full ModMenu suite green.

Run: `php -r 'foreach (["ModMenu","DependencyPlugin","SchedulerPlugin"] as $p) { json_decode(file_get_contents("$p/plugin.json"), true, 512, JSON_THROW_ON_ERROR); } json_decode(file_get_contents("../kanboard-modmenu-directory/plugins.json"), true, 512, JSON_THROW_ON_ERROR); echo "JSON OK\n";'`
Expected: `JSON OK`.

- [ ] **Step 6: Live E2E (:8081).** With the dev Docker stack running, drive the real browser (admin/admin) to verify — enforcement uses **zip-installed** plugins because the bind-mounted suite plugins can't be disabled:

  1. On **Settings → ModMenu → Installed**, the DependencyPlugin card shows a *"Works better with CalendarPlugin"* hint (recommends), non-blocking.
  2. Upload a throwaway test plugin whose `plugin.json` declares `requires` on a not-yet-installed plugin that exists in a directory source → clicking **Enable** shows the resolve confirm listing the chain; **Confirm** installs the dep then enables the target.
  3. Declaring a `requires` on a plugin absent from every source → **Enable** hard-blocks with the "install manually first" flash.
  4. With an active dependent present, **Remove**/**Disable** the required plugin → blocked, dependents named.

Record the run in `.superpowers/sdd/modmenu-deps-e2e.md` (assertions + pass/fail).

- [ ] **Step 7: Commit**

```bash
git add ModMenu/plugin.json ModMenu/Plugin.php ModMenu/README.md ModMenu/CHANGELOG.md DependencyPlugin/plugin.json SchedulerPlugin/plugin.json
git commit -m "docs(ModMenu): v1.1.0 dependency docs + suite recommends rollout"
# directory repo is a separate git repo:
cd ../kanboard-modmenu-directory && git add plugins.json && git commit -m "feat: mirror recommends deps for Dependency/Scheduler" && cd -
```

---

## Rollout after merge (handled by the controller, post-SDD)

- `superpowers:finishing-a-development-branch` → merge `feat/modmenu-dependency-system` → master (confirm before pushing).
- Release: `scripts/package.sh ModMenu <out>` → `gh release create ModMenu-v1.1.0` → bump ModMenu entry in `kanboard-modmenu-directory/plugins.json` to 1.1.0 + push.
- Whole-branch review (opus) over `git merge-base master HEAD..HEAD` before merge — focus: resolver purity, gate coverage (all four actions), CSRF/admin on `resolve`, server-side plan re-derivation (no trusted post), backward-compat (absent keys), no partial activation on unresolvable.
