<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class PluginManagerTest extends Base
{
    private $root;
    private $active;
    private $disabled;
    private $manager;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/modmenu-mgr-' . uniqid();
        $this->active = $this->root . '/plugins';
        $this->disabled = $this->root . '/disabled';
        mkdir($this->active, 0777, true);
        mkdir($this->disabled, 0777, true);
        $this->manager = (new PluginManager($this->container))->setDirectories($this->active, $this->disabled);
    }

    public function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (! is_dir($d)) { return; }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = "$d/$f";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($d);
    }

    private function seedPlugin(string $dir, string $name, string $version): void
    {
        mkdir("$dir/$name", 0777, true);
        file_put_contents("$dir/$name/Plugin.php", "<?php\n");
        file_put_contents("$dir/$name/plugin.json", json_encode([
            'name' => $name, 'version' => $version, 'description' => "$name desc",
            'author' => 'Tester', 'homepage' => 'https://example.com',
        ]));
    }

    public function testListInstalledMergesActiveAndDisabled()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '2.1.0');

        $list = $this->manager->listInstalled();
        $byName = [];
        foreach ($list as $p) { $byName[$p['name']] = $p; }

        $this->assertSame('active', $byName['Alpha']['status']);
        $this->assertSame('1.0.0', $byName['Alpha']['version']);
        $this->assertSame('disabled', $byName['Beta']['status']);
        $this->assertSame('2.1.0', $byName['Beta']['version']);
    }

    public function testDisableMovesFolderToDisabledDir()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->manager->disable('Alpha');
        $this->assertDirectoryDoesNotExist("{$this->active}/Alpha");
        $this->assertDirectoryExists("{$this->disabled}/Alpha");
    }

    public function testEnableMovesFolderBack()
    {
        $this->seedPlugin($this->disabled, 'Beta', '2.0.0');
        $this->manager->enable('Beta');
        $this->assertDirectoryExists("{$this->active}/Beta");
        $this->assertDirectoryDoesNotExist("{$this->disabled}/Beta");
    }

    public function testUninstallRemovesFolder()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->manager->uninstall('Alpha');
        $this->assertDirectoryDoesNotExist("{$this->active}/Alpha");
    }

    public function testDisableRefusesSelf()
    {
        $this->seedPlugin($this->active, 'ModMenu', '1.0.0');
        $this->expectException(ModMenuException::class);
        $this->manager->disable('ModMenu');
    }

    public function testUninstallRefusesSelf()
    {
        $this->seedPlugin($this->active, 'ModMenu', '1.0.0');
        $this->expectException(ModMenuException::class);
        $this->manager->uninstall('ModMenu');
    }

    public function testNameGuardRejectsTraversal()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->disable('../evil');
    }

    public function testEnableRefusesTraversal()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->enable('../evil');
    }

    public function testUninstallRemovesDisabledPlugin()
    {
        $this->seedPlugin($this->disabled, 'Gamma', '3.0.0');
        $this->manager->uninstall('Gamma');
        $this->assertDirectoryDoesNotExist("{$this->disabled}/Gamma");
    }

    public function testFolderNameIsIdentityNotJsonName()
    {
        // Folder: WidgetBox, plugin.json "name": "Widget Box Deluxe", "title": "Widget Box"
        $dir = "{$this->active}/WidgetBox";
        mkdir($dir, 0777, true);
        file_put_contents("$dir/Plugin.php", "<?php\n");
        file_put_contents("$dir/plugin.json", json_encode([
            'name'    => 'Widget Box Deluxe',
            'title'   => 'Widget Box',
            'version' => '1.2.3',
        ]));

        $list = $this->manager->listInstalled();
        $byName = [];
        foreach ($list as $p) { $byName[$p['name']] = $p; }

        $this->assertArrayHasKey('WidgetBox', $byName, 'name key must be the folder name');
        $this->assertSame('WidgetBox', $byName['WidgetBox']['name'], 'name must equal folder name');
        $this->assertSame('Widget Box', $byName['WidgetBox']['title'], 'title must come from plugin.json title field');
    }

    public function testDisableRefusesWhenNotInstalled()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->disable('Ghost');
    }

    public function testHasUpdate()
    {
        $this->assertTrue(PluginManager::hasUpdate('1.0.0', '1.0.1'));
        $this->assertFalse(PluginManager::hasUpdate('2.0.0', '2.0.0'));
        $this->assertFalse(PluginManager::hasUpdate('2.0.1', '2.0.0'));
    }

    public function testInstallFromUrlRejectsPlainHttp()
    {
        // Security: plain http:// must be rejected before any network call is made.
        // The regex '#^https://#i' catches this immediately so no HTTP/zip fixture needed.
        $this->expectException(ModMenuException::class);
        $this->manager->installFromUrl('http://example.com/plugin.zip');
    }

    public function testInstalledMapShape()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '2.0.0');
        $map = $this->manager->installedMap();
        $this->assertSame('1.0.0', $map['Alpha']['version']);
        $this->assertSame('active', $map['Alpha']['status']);
        $this->assertSame('disabled', $map['Beta']['status']);
    }

    /**
     * Regression: enable/disable move() falls back to a recursive copy when
     * rename() fails across filesystems (EXDEV — the norm in Docker, where
     * plugins/ and data/ are separate mounts). Exercise copyTree() directly;
     * it is the fallback that made cross-filesystem disable/enable work live.
     */
    public function testCopyTreeCopiesNestedTree()
    {
        $src = $this->root . '/ct-src';
        $dst = $this->root . '/ct-dst';
        mkdir($src . '/sub', 0777, true);
        file_put_contents($src . '/Plugin.php', "<?php // top\n");
        file_put_contents($src . '/sub/quote.php', 'hello harmozi');

        $method = new \ReflectionMethod(PluginManager::class, 'copyTree');
        $method->setAccessible(true);
        $ok = $method->invoke($this->manager, $src, $dst);

        $this->assertTrue($ok, 'copyTree should return true on success');
        $this->assertFileExists($dst . '/Plugin.php');
        $this->assertSame('hello harmozi', file_get_contents($dst . '/sub/quote.php'));
    }

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
}
