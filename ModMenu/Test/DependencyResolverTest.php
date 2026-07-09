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
}
