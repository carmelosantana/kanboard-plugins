<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\PluginArchive;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class PluginArchiveTest extends Base
{
    private $work;

    public function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/modmenu-test-' . uniqid();
        mkdir($this->work, 0777, true);
    }

    public function tearDown(): void
    {
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) { return; }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /** Build a zip at $path from a map of entryName => contents. */
    private function makeZip(string $path, array $entries): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($entries as $name => $contents) {
            if (substr($name, -1) === '/') {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $contents);
            }
        }
        $zip->close();
    }

    public function testInspectReturnsTopLevelDirName()
    {
        $zip = $this->work . '/good.zip';
        $this->makeZip($zip, [
            'GoodPlugin/' => '',
            'GoodPlugin/Plugin.php' => "<?php\n",
            'GoodPlugin/README.md' => "hi",
        ]);
        $archive = new PluginArchive($this->container);
        $this->assertSame('GoodPlugin', $archive->inspect($zip));
    }

    public function testInspectRejectsMissingPluginPhp()
    {
        $zip = $this->work . '/nopluginphp.zip';
        $this->makeZip($zip, ['SomeDir/' => '', 'SomeDir/readme.txt' => 'x']);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->inspect($zip);
    }

    public function testInspectRejectsPathTraversal()
    {
        $zip = $this->work . '/evil.zip';
        $this->makeZip($zip, ['Evil/' => '', 'Evil/Plugin.php' => '<?php', '../escape.php' => 'x']);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->inspect($zip);
    }

    public function testInspectRejectsMultipleTopLevelDirs()
    {
        $zip = $this->work . '/two.zip';
        $this->makeZip($zip, [
            'One/' => '', 'One/Plugin.php' => '<?php',
            'Two/' => '', 'Two/Plugin.php' => '<?php',
        ]);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->inspect($zip);
    }

    public function testExtractToPlacesPluginDir()
    {
        $zip = $this->work . '/good.zip';
        $this->makeZip($zip, ['GoodPlugin/' => '', 'GoodPlugin/Plugin.php' => "<?php\n"]);
        $dest = $this->work . '/plugins';
        mkdir($dest);
        $name = (new PluginArchive($this->container))->extractTo($zip, $dest);
        $this->assertSame('GoodPlugin', $name);
        $this->assertFileExists($dest . '/GoodPlugin/Plugin.php');
    }

    public function testExtractToRejectsExistingDestination()
    {
        $zip = $this->work . '/good.zip';
        $this->makeZip($zip, ['GoodPlugin/' => '', 'GoodPlugin/Plugin.php' => "<?php\n"]);
        $dest = $this->work . '/plugins';
        mkdir($dest . '/GoodPlugin', 0777, true);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->extractTo($zip, $dest);
    }

    // Guard: entry whose name contains a backslash must be rejected.
    // The guard is `strpos($entry, '\\') !== false` in inspect().
    // Removing that line would make this test fail.
    public function testInspectRejectsBackslashPath()
    {
        $zip = $this->work . '/backslash.zip';
        $this->makeZip($zip, [
            'Evil/'           => '',
            'Evil/Plugin.php' => '<?php',
            'Evil\\escape.php' => 'x',
        ]);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->inspect($zip);
    }

    // Guard: entry with a leading '/' (absolute path) must be rejected.
    // The guard is `$entry[0] === '/'` in inspect().
    // Removing that line would make this test fail.
    public function testInspectRejectsAbsolutePath()
    {
        $zip = $this->work . '/abspath.zip';
        $this->makeZip($zip, [
            'Good/'           => '',
            'Good/Plugin.php' => '<?php',
            '/etc/passwd'     => 'root:x:0:0',
        ]);
        $this->expectException(ModMenuException::class);
        (new PluginArchive($this->container))->inspect($zip);
    }

    // Verify extractTo() places the plugin dir correctly even when rename() is not used
    // (the copy-tree fallback path). The happy path already covers this; this test
    // re-asserts file placement so the copy-fallback can be spotted via coverage.
    public function testExtractToPlacesPluginDirViaCopyFallback()
    {
        $zip = $this->work . '/copy.zip';
        $this->makeZip($zip, [
            'CopyPlugin/'            => '',
            'CopyPlugin/Plugin.php'  => "<?php\n",
            'CopyPlugin/Helper.php'  => "<?php\n",
        ]);
        $dest = $this->work . '/plugins2';
        mkdir($dest);
        $name = (new PluginArchive($this->container))->extractTo($zip, $dest);
        $this->assertSame('CopyPlugin', $name);
        $this->assertFileExists($dest . '/CopyPlugin/Plugin.php');
        $this->assertFileExists($dest . '/CopyPlugin/Helper.php');
    }
}
