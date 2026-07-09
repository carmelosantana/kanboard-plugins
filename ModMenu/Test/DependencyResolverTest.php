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

    public function testClassifyDisabledButTooOldWithNewerCatalogIsUpdate()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $catalog = ['Cal' => ['version' => '1.2.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('update', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyDisabledButTooOldWithoutCatalogIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
    }

    public function testClassifyOutdatedWhenCatalogVersionAlsoBelowMinIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $catalog = ['Cal' => ['version' => '1.0.5', 'download' => 'https://x/cal.zip']]; // still < 1.1.0
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
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
}
