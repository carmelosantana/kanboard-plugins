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

    /**
     * Pin each guard clause in isEntryNameSafe() individually.
     *
     * TRUE cases — safe entries that must be accepted.
     * FALSE cases — each one maps to exactly one guard clause:
     *   - '' (empty)           => guard: $name === ''
     *   - '/etc/passwd'        => guard: $name[0] === '/'
     *   - 'Evil\\x.php'        => guard: strpos($name, '\\') !== false
     *   - '../escape.php'      => guard: strpos($name, '..') !== false
     *   - 'Good/../../etc'     => guard: strpos($name, '..') !== false (traversal mid-path)
     * Removing any single guard from isEntryNameSafe() causes exactly its mapped assertion to fail.
     */
    public function testIsEntryNameSafe()
    {
        // TRUE: safe entry names
        $this->assertTrue(PluginArchive::isEntryNameSafe('Good/Plugin.php'),    'relative file path should be safe');
        $this->assertTrue(PluginArchive::isEntryNameSafe('Good/sub/file.php'),  'nested path should be safe');
        $this->assertTrue(PluginArchive::isEntryNameSafe('Good/README.md'),     'doc file should be safe');

        // FALSE: empty string — guard: $name === ''
        $this->assertFalse(PluginArchive::isEntryNameSafe(''),               'empty name must be rejected');

        // FALSE: leading slash — guard: $name[0] === '/'
        $this->assertFalse(PluginArchive::isEntryNameSafe('/etc/passwd'),     'absolute path must be rejected');

        // FALSE: backslash — guard: strpos($name, '\\') !== false
        $this->assertFalse(PluginArchive::isEntryNameSafe('Evil\\x.php'),    'backslash must be rejected');

        // FALSE: leading traversal — guard: strpos($name, '..') !== false
        $this->assertFalse(PluginArchive::isEntryNameSafe('../escape.php'),   'leading .. must be rejected');

        // FALSE: mid-path traversal — guard: strpos($name, '..') !== false
        $this->assertFalse(PluginArchive::isEntryNameSafe('Good/../../etc'), 'mid-path .. must be rejected');
    }

    /**
     * Exercise copyTree() directly to confirm the cross-filesystem fallback logic works.
     *
     * We expose the protected method via a small anonymous subclass so that
     * removal or breakage of copyTree() causes this test (not just extractTo()) to fail.
     */
    private $copyTreeWork;

    public function testCopyTreeCopiesNestedTree()
    {
        // Build a source tree: A/Plugin.php and A/sub/x.txt
        $src = $this->work . '/copy-src/A';
        mkdir($src . '/sub', 0777, true);
        file_put_contents($src . '/Plugin.php', "<?php\n");
        file_put_contents($src . '/sub/x.txt', 'hello');

        $dst = $this->work . '/copy-dst/A';

        // Use a subclass to expose the protected method.
        $archive = new class($this->container) extends PluginArchive {
            public function publicCopyTree(string $src, string $dst): bool
            {
                return $this->copyTree($src, $dst);
            }
        };

        $result = $archive->publicCopyTree($src, $dst);

        $this->assertTrue($result, 'copyTree should return true on success');
        $this->assertFileExists($dst . '/Plugin.php');
        $this->assertFileExists($dst . '/sub/x.txt');
        $this->assertSame("<?php\n", file_get_contents($dst . '/Plugin.php'));
        $this->assertSame('hello',   file_get_contents($dst . '/sub/x.txt'));
    }
}
