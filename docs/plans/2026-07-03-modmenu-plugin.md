# ModMenu Plugin Manager — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build ModMenu, a self-contained Kanboard plugin that lets an admin browse, install, upload, enable/disable, update, and uninstall plugins from within Kanboard.

**Architecture:** A Kanboard plugin (`Kanboard\Plugin\ModMenu`) with thin admin-gated controllers delegating to four focused models: `PluginArchive` (safe zip validate+extract), `PluginManager` (list/enable/disable/uninstall/install/version-compare), `SourceRepository` (persist directory source URLs in configModel), and `DirectoryClient` (fetch+merge+annotate listings). "Installed" state is derived by scanning two directories — active `PLUGINS_DIR` and disabled `DATA_DIR/modmenu_disabled` — so no new DB table is needed. Enable/disable physically moves the plugin folder between them.

**Tech Stack:** PHP 8.4, Kanboard v1.2.47 plugin API (`Kanboard\Core\Plugin\Base`, `Kanboard\Controller\BaseController`), `ZipArchive`, PHPUnit (via `testing/run-plugin-tests.sh`).

## Global Constraints

- Plugin name is exactly `ModMenu`; namespace `Kanboard\Plugin\ModMenu`. `getCompatibleVersion()` returns `>=1.2.47`. `getPluginVersion()` returns `1.0.0`.
- Every controller action calls `$this->userSession->isAdmin()` first and throws `Kanboard\Core\Controller\AccessForbiddenException` for non-admins.
- Every state-mutating POST action verifies CSRF via `$this->checkCSRFForm()` (form-body token, rendered with `$this->form->csrf()`).
- ModMenu must NEVER disable or uninstall itself. `PluginManager::SELF === 'ModMenu'`; guard server-side in `disable()`, `uninstall()`, and any install-over-self path.
- Plugin folder names are validated with `basename($name) === $name && $name !== ''` before any filesystem operation (path-traversal guard).
- All JS lives in external `Assets/js/*.js` injected via `template:layout:js`; NO inline `<script>` (Kanboard CSP is `default-src 'self'`, so inline scripts are blocked). Inline `<style>`/`style=""` is allowed.
- In templates, generate plugin URLs with the plugin passed INSIDE params: `$this->url->link($label, 'ModMenuController', 'action', ['plugin' => 'ModMenu', ...])` and `$this->helper->url->to('ModMenuController', 'action', ['plugin' => 'ModMenu'])`. Never pass the plugin as a positional arg (it lands in `$csrf` and produces a plugin-less URL).
- Zip safety caps: `PluginArchive::MAX_ARCHIVE_BYTES = 52428800` (50 MB), `PluginArchive::MAX_ENTRIES = 5000`.
- Default directory source: `https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json`.
- Author `Carmelo Santana`, license MIT, homepage `https://github.com/carmelosantana/ModMenu`.
- Tests are run from repo root with `./testing/run-plugin-tests.sh ModMenu`. Test classes `require_once 'tests/units/Base.php';` and extend `KanboardTests\units\Base`.

---

## File Structure

```
ModMenu/
  Plugin.php                     # register routes, sidebar link, assets; metadata methods
  plugin.json                    # metadata mirror
  LICENSE                        # MIT
  README.md
  CHANGELOG.md
  Exception/ModMenuException.php  # domain exception
  Model/PluginArchive.php        # zip validate + safe extract
  Model/PluginManager.php        # list/enable/disable/uninstall/install/version-compare
  Model/SourceRepository.php     # source-URL list in configModel
  Model/DirectoryClient.php      # fetch/merge/annotate listings
  Controller/ModMenuController.php# 4 tabs + enable/disable/uninstall/install/update/sources
  Controller/UploadController.php # zip upload -> install
  Template/config/sidebar.php    # Settings sidebar link
  Template/settings/nav.php      # tab bar (Installed/Browse/Upload/Sources)
  Template/settings/installed.php
  Template/settings/directory.php
  Template/settings/sources.php
  Template/settings/upload.php
  Template/settings/not_configured.php
  Template/plugin/remove.php     # uninstall confirm modal
  Assets/css/modmenu.css
  Assets/js/modmenu.js           # minimal, CSP-safe, delegated (upload button guard)
  Test/PluginTest.php
  Test/PluginArchiveTest.php
  Test/PluginManagerTest.php
  Test/SourceRepositoryTest.php
  Test/DirectoryClientTest.php
  Test/ControllerAccessTest.php
```

---

### Task 1: Plugin scaffold + metadata + empty settings page

**Files:**
- Create: `ModMenu/Plugin.php`, `ModMenu/plugin.json`, `ModMenu/LICENSE`, `ModMenu/Template/config/sidebar.php`, `ModMenu/Template/settings/installed.php`, `ModMenu/Assets/css/modmenu.css`, `ModMenu/Assets/js/modmenu.js`
- Test: `ModMenu/Test/PluginTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\ModMenu\Plugin` with `getPluginName():string='ModMenu'`, `getPluginVersion():string='1.0.0'`, `getCompatibleVersion():string='>=1.2.47'`, `getPluginDescription()`, `getPluginAuthor()`, `getPluginHomepage()`, `getPluginLicense()`. Registers routes listed in Global Constraints and the `template:config:sidebar` hook.

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/PluginTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\ModMenu\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('ModMenu', $plugin->getPluginName());
    }

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
    }

    public function testCompatibleVersion()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testInitializeRegistersRoutesWithoutError()
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertNotEmpty($plugin->getPluginDescription());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — class `Kanboard\Plugin\ModMenu\Plugin` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Plugin.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu;

use Kanboard\Core\Plugin\Base;

/**
 * ModMenu — a standalone Kanboard plugin manager.
 *
 * Browse/install from directory sources, upload a zip, enable/disable via
 * folder move, detect updates, and uninstall — all admin-only.
 *
 * @author  Carmelo Santana
 * @license MIT
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->hook->on('template:config:sidebar', ['template' => 'ModMenu:config/sidebar']);

        $this->hook->on('template:layout:css', ['template' => 'plugins/ModMenu/Assets/css/modmenu.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/ModMenu/Assets/js/modmenu.js']);

        $this->route->addRoute('config/modmenu', 'ModMenu:ModMenuController', 'show');
        $this->route->addRoute('config/modmenu/directory', 'ModMenu:ModMenuController', 'directory');
        $this->route->addRoute('config/modmenu/sources', 'ModMenu:ModMenuController', 'sources');
        $this->route->addRoute('config/modmenu/source/add', 'ModMenu:ModMenuController', 'addSource');
        $this->route->addRoute('config/modmenu/source/remove', 'ModMenu:ModMenuController', 'removeSource');
        $this->route->addRoute('config/modmenu/plugin/confirm', 'ModMenu:ModMenuController', 'confirm');
        $this->route->addRoute('config/modmenu/plugin/enable', 'ModMenu:ModMenuController', 'enable');
        $this->route->addRoute('config/modmenu/plugin/disable', 'ModMenu:ModMenuController', 'disable');
        $this->route->addRoute('config/modmenu/plugin/uninstall', 'ModMenu:ModMenuController', 'uninstall');
        $this->route->addRoute('config/modmenu/plugin/install', 'ModMenu:ModMenuController', 'install');
        $this->route->addRoute('config/modmenu/plugin/update', 'ModMenu:ModMenuController', 'update');
        $this->route->addRoute('config/modmenu/upload', 'ModMenu:UploadController', 'upload');
    }

    public function getPluginName(): string
    {
        return 'ModMenu';
    }

    public function getPluginDescription(): string
    {
        return 'A standalone plugin manager: browse, install, upload, enable/disable, update, and uninstall Kanboard plugins.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/ModMenu';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }
}
```

Create `ModMenu/Template/config/sidebar.php`:

```php
<li>
    <?= $this->url->link(t('ModMenu'), 'ModMenuController', 'show', ['plugin' => 'ModMenu']) ?>
</li>
```

Create `ModMenu/Template/settings/installed.php` (placeholder body, filled in Task 6):

```php
<div class="page-header">
    <h2><?= t('ModMenu') ?></h2>
</div>
<p><?= t('Plugin manager') ?></p>
```

Create `ModMenu/Assets/css/modmenu.css`:

```css
/* ModMenu — admin plugin-manager styles. CSP allows inline style; external is cleaner. */
.modmenu-tabs { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border, #ddd); }
.modmenu-tabs li a { display: inline-block; padding: 0.5rem 0.75rem; text-decoration: none; }
.modmenu-tabs li a.active { font-weight: 600; border-bottom: 2px solid var(--primary, #4a6fa5); }
.modmenu-card { border: 1px solid var(--border, #ddd); border-radius: var(--radius, 4px); padding: 0.75rem 1rem; margin-bottom: 0.75rem; }
.modmenu-card__status { font-size: 0.8125rem; opacity: 0.85; }
.modmenu-badge { display: inline-block; padding: 0.1rem 0.4rem; border-radius: 999px; font-size: 0.75rem; }
.modmenu-badge--update { background: var(--primary, #4a6fa5); color: var(--primary-foreground, #fff); }
.modmenu-badge--installed { background: var(--muted, #eee); }
.modmenu-badge--disabled { background: var(--muted, #eee); opacity: 0.7; }
.modmenu-shot { max-width: 220px; border: 1px solid var(--border, #ddd); border-radius: var(--radius, 4px); margin-right: 0.5rem; }
.modmenu-banner { border: 1px solid var(--border, #f0c040); background: var(--muted, #fff8e1); padding: 0.75rem 1rem; border-radius: var(--radius, 4px); margin-bottom: 1rem; }
```

Create `ModMenu/Assets/js/modmenu.js` (minimal, CSP-safe, event-delegated — prevents double-submit on slow install/upload):

```javascript
/*! ModMenu — CSP-safe, document-delegated. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form.modmenu-action');
        if (!form) { return; }
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn) { btn.setAttribute('disabled', 'disabled'); }
    });
})();
```

Create `ModMenu/plugin.json`:

```json
{
    "name": "ModMenu",
    "version": "1.0.0",
    "description": "A standalone plugin manager: browse, install, upload, enable/disable, update, and uninstall Kanboard plugins.",
    "author": "Carmelo Santana",
    "homepage": "https://github.com/carmelosantana/ModMenu",
    "license": "MIT",
    "compatible_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

Create `ModMenu/LICENSE` — standard MIT text, copyright `2026 Carmelo Santana`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (4 tests). The controllers referenced by routes don't exist yet but `initialize()` only registers route strings, so no error.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Plugin.php ModMenu/plugin.json ModMenu/LICENSE ModMenu/Template/config/sidebar.php ModMenu/Template/settings/installed.php ModMenu/Assets ModMenu/Test/PluginTest.php
git commit -m "feat(ModMenu): plugin scaffold, metadata, routes, sidebar link"
```

---

### Task 2: `ModMenuException` + `PluginArchive` (safe zip validate + extract)

**Files:**
- Create: `ModMenu/Exception/ModMenuException.php`, `ModMenu/Model/PluginArchive.php`
- Test: `ModMenu/Test/PluginArchiveTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\ModMenu\Exception\ModMenuException extends \Exception`.
- Produces: `Kanboard\Plugin\ModMenu\Model\PluginArchive extends \Kanboard\Core\Base` with:
  - `const MAX_ARCHIVE_BYTES = 52428800;`
  - `const MAX_ENTRIES = 5000;`
  - `inspect(string $zipPath): string` — validates and returns the single top-level plugin directory name; throws `ModMenuException` on any violation.
  - `extractTo(string $zipPath, string $destParentDir): string` — inspects, extracts to a temp dir, moves `<name>` into `$destParentDir`, returns the name. Throws if `$destParentDir/<name>` already exists.

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/PluginArchiveTest.php`

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `PluginArchive` / `ModMenuException` not found.

- [ ] **Step 3: Write minimal implementation**

`ModMenu/Exception/ModMenuException.php`:

```php
<?php

namespace Kanboard\Plugin\ModMenu\Exception;

/**
 * Raised for any ModMenu operation failure (bad archive, blocked move, etc.).
 */
class ModMenuException extends \Exception
{
}
```

`ModMenu/Model/PluginArchive.php`:

```php
<?php

namespace Kanboard\Plugin\ModMenu\Model;

use ZipArchive;
use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Validates and safely extracts a Kanboard plugin zip archive.
 *
 * A valid archive contains exactly ONE top-level directory that holds a
 * Plugin.php. No entry may contain '..', a leading '/', or a backslash.
 * Extraction is atomic-ish: unpack to a temp dir, then move the plugin dir
 * into place (never a partial install in PLUGINS_DIR).
 */
class PluginArchive extends Base
{
    const MAX_ARCHIVE_BYTES = 52428800; // 50 MB
    const MAX_ENTRIES = 5000;

    /**
     * Validate the archive and return the single top-level directory name.
     *
     * @throws ModMenuException
     */
    public function inspect(string $zipPath): string
    {
        if (! is_file($zipPath) || filesize($zipPath) > self::MAX_ARCHIVE_BYTES) {
            throw new ModMenuException(t('Plugin archive is missing or too large.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ModMenuException(t('Unable to open plugin archive.'));
        }

        try {
            if ($zip->numFiles === 0) {
                throw new ModMenuException(t('The plugin archive is empty.'));
            }
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ModMenuException(t('The plugin archive has too many files.'));
            }

            $topDirs = [];
            $hasPluginPhp = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i)['name'];

                if ($entry === '' || $entry[0] === '/' || strpos($entry, '\\') !== false
                    || strpos($entry, '..') !== false) {
                    throw new ModMenuException(t('The plugin archive contains an unsafe path: %s', $entry));
                }

                $segments = explode('/', $entry);
                $topDirs[$segments[0]] = true;

                if (preg_match('#^[^/]+/Plugin\.php$#', $entry) === 1) {
                    $hasPluginPhp = true;
                }
            }

            if (count($topDirs) !== 1) {
                throw new ModMenuException(t('A plugin archive must contain exactly one top-level directory.'));
            }
            if (! $hasPluginPhp) {
                throw new ModMenuException(t('The plugin archive has no Plugin.php in its top-level directory.'));
            }

            return array_key_first($topDirs);
        } finally {
            $zip->close();
        }
    }

    /**
     * Extract the validated archive; move its plugin directory into $destParentDir.
     *
     * @throws ModMenuException
     */
    public function extractTo(string $zipPath, string $destParentDir): string
    {
        $name = $this->inspect($zipPath);
        $finalPath = rtrim($destParentDir, '/') . '/' . $name;

        if (file_exists($finalPath)) {
            throw new ModMenuException(t('A plugin named "%s" already exists.', $name));
        }

        $temp = sys_get_temp_dir() . '/modmenu-extract-' . uniqid();
        if (! mkdir($temp, 0755, true)) {
            throw new ModMenuException(t('Unable to create a temporary extraction directory.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->removeTree($temp);
            throw new ModMenuException(t('Unable to open plugin archive.'));
        }

        $ok = $zip->extractTo($temp);
        $zip->close();

        if (! $ok) {
            $this->removeTree($temp);
            throw new ModMenuException(t('Unable to extract plugin archive.'));
        }

        if (! @rename($temp . '/' . $name, $finalPath)) {
            $this->removeTree($temp);
            throw new ModMenuException(t('Unable to move the extracted plugin into place.'));
        }

        $this->removeTree($temp);
        return $name;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) { return; }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (all PluginArchive tests + Task 1 tests).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Exception/ModMenuException.php ModMenu/Model/PluginArchive.php ModMenu/Test/PluginArchiveTest.php
git commit -m "feat(ModMenu): PluginArchive — safe zip validation + atomic extract"
```

---

### Task 3: `PluginManager` (list, enable/disable, uninstall, install, version-compare)

**Files:**
- Create: `ModMenu/Model/PluginManager.php`
- Test: `ModMenu/Test/PluginManagerTest.php`

**Interfaces:**
- Consumes: `PluginArchive::extractTo()`, `ModMenuException`.
- Produces: `Kanboard\Plugin\ModMenu\Model\PluginManager extends \Kanboard\Core\Base` with:
  - `const SELF = 'ModMenu';`
  - `setDirectories(string $activeDir, string $disabledDir): self` — overrides defaults (for tests); defaults are `PLUGINS_DIR` and `DATA_DIR.'/modmenu_disabled'`.
  - `isConfigured(): bool` — `is_writable(activeDir) && extension_loaded('zip')`.
  - `notConfiguredReason(): string` — '' when configured, else a human message.
  - `listInstalled(): array` — merged list of `['name','title','version','description','author','homepage','status']` where `status ∈ {'active','disabled'}`.
  - `installedMap(): array` — `name => ['version'=>string, 'status'=>string]`.
  - `enable(string $name): void` / `disable(string $name): void` / `uninstall(string $name): void`.
  - `installFromUrl(string $url): string` / `installFromFile(string $tmpPath): string` — returns the installed plugin name.
  - `static hasUpdate(string $installed, string $available): bool` — `version_compare($installed, $available, '<')`.

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/PluginManagerTest.php`

```php
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

    public function testInstalledMapShape()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '2.0.0');
        $map = $this->manager->installedMap();
        $this->assertSame('1.0.0', $map['Alpha']['version']);
        $this->assertSame('active', $map['Alpha']['status']);
        $this->assertSame('disabled', $map['Beta']['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `PluginManager` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Model/PluginManager.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * The ModMenu engine: enumerate installed plugins (active + disabled),
 * enable/disable by moving folders, uninstall, and install from a URL or
 * uploaded file. Never touches ModMenu itself.
 *
 * "Installed" state needs no DB table: a plugin is ACTIVE if its folder is in
 * PLUGINS_DIR (loaded by Kanboard at bootstrap) and DISABLED if its folder is
 * parked in DATA_DIR/modmenu_disabled (outside the scan path).
 */
class PluginManager extends Base
{
    const SELF = 'ModMenu';

    /** @var string */
    private $activeDir;

    /** @var string */
    private $disabledDir;

    private function activeDir(): string
    {
        return $this->activeDir ?? PLUGINS_DIR;
    }

    private function disabledDir(): string
    {
        return $this->disabledDir ?? (DATA_DIR . DIRECTORY_SEPARATOR . 'modmenu_disabled');
    }

    public function setDirectories(string $activeDir, string $disabledDir): self
    {
        $this->activeDir = $activeDir;
        $this->disabledDir = $disabledDir;
        return $this;
    }

    public function isConfigured(): bool
    {
        return is_writable($this->activeDir()) && extension_loaded('zip');
    }

    public function notConfiguredReason(): string
    {
        if (! extension_loaded('zip')) {
            return t('The PHP "zip" extension is not installed, so ModMenu cannot unpack plugins.');
        }
        if (! is_writable($this->activeDir())) {
            return t('The plugins directory is not writable, so ModMenu cannot install or move plugins.');
        }
        return '';
    }

    public function listInstalled(): array
    {
        $plugins = array_merge(
            $this->scanDir($this->activeDir(), 'active'),
            $this->scanDir($this->disabledDir(), 'disabled')
        );
        usort($plugins, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $plugins;
    }

    public function installedMap(): array
    {
        $map = [];
        foreach ($this->listInstalled() as $p) {
            $map[$p['name']] = ['version' => $p['version'], 'status' => $p['status']];
        }
        return $map;
    }

    public function enable(string $name): void
    {
        $this->move($name, $this->disabledDir(), $this->activeDir());
    }

    public function disable(string $name): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->move($name, $this->activeDir(), $this->disabledDir());
    }

    public function uninstall(string $name): void
    {
        $this->guardName($name);
        $this->guardSelf($name);

        foreach ([$this->activeDir(), $this->disabledDir()] as $base) {
            $path = $base . '/' . $name;
            if (is_dir($path)) {
                if (! $this->removeTree($path)) {
                    throw new ModMenuException(t('Could not remove "%s". Its folder may be a bind mount or read-only.', $name));
                }
                return;
            }
        }
        throw new ModMenuException(t('Plugin "%s" is not installed.', $name));
    }

    public function installFromUrl(string $url): string
    {
        if (! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ModMenuException(t('The download URL is invalid.'));
        }

        $body = $this->httpClient->get($url);
        if (empty($body)) {
            throw new ModMenuException(t('Unable to download the plugin archive.'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'modmenu_dl');
        file_put_contents($tmp, $body);

        try {
            return $this->installArchive($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function installFromFile(string $tmpPath): string
    {
        if (! is_file($tmpPath)) {
            throw new ModMenuException(t('No uploaded file was received.'));
        }
        return $this->installArchive($tmpPath);
    }

    public static function hasUpdate(string $installed, string $available): bool
    {
        return version_compare($installed, $available, '<');
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Validate the archive, then install it. If an ACTIVE plugin of the same
     * name exists it is replaced (update path). A DISABLED copy blocks install.
     */
    private function installArchive(string $zipPath): string
    {
        $archive = new PluginArchive($this->container);
        $name = $archive->inspect($zipPath);

        $this->guardName($name);
        if ($name === self::SELF) {
            throw new ModMenuException(t('ModMenu cannot install over itself.'));
        }

        if (is_dir($this->disabledDir() . '/' . $name)) {
            throw new ModMenuException(t('"%s" is already installed but disabled. Enable it instead.', $name));
        }

        $existing = $this->activeDir() . '/' . $name;
        if (is_dir($existing) && ! $this->removeTree($existing)) {
            throw new ModMenuException(t('Could not replace the existing "%s" (folder may be a bind mount).', $name));
        }

        return $archive->extractTo($zipPath, $this->activeDir());
    }

    private function move(string $name, string $from, string $to): void
    {
        $this->guardName($name);
        $src = $from . '/' . $name;
        $dst = $to . '/' . $name;

        if (! is_dir($src)) {
            throw new ModMenuException(t('Plugin "%s" was not found.', $name));
        }
        if (! is_dir($to) && ! mkdir($to, 0755, true)) {
            throw new ModMenuException(t('Could not create the target directory.'));
        }
        if (file_exists($dst)) {
            throw new ModMenuException(t('A copy of "%s" already exists at the destination.', $name));
        }
        if (! @rename($src, $dst)) {
            throw new ModMenuException(t('Could not move "%s". Its folder may be a bind mount or read-only.', $name));
        }
    }

    private function scanDir(string $dir, string $status): array
    {
        if (! is_dir($dir)) { return []; }

        $out = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . '/' . $entry;
            if (! is_dir($path) || ! is_file($path . '/Plugin.php')) { continue; }

            $meta = $this->readMeta($path, $entry);
            $meta['status'] = $status;
            $out[] = $meta;
        }
        return $out;
    }

    private function readMeta(string $path, string $folderName): array
    {
        $meta = [
            'name' => $folderName,
            'title' => $folderName,
            'version' => 'unknown',
            'description' => '',
            'author' => '',
            'homepage' => '',
        ];

        $jsonFile = $path . '/plugin.json';
        if (is_file($jsonFile)) {
            $json = json_decode((string) file_get_contents($jsonFile), true);
            if (is_array($json)) {
                $meta['name'] = $json['name'] ?? $folderName;
                $meta['title'] = $json['title'] ?? $meta['name'];
                $meta['version'] = $json['version'] ?? 'unknown';
                $meta['description'] = $json['description'] ?? '';
                $meta['author'] = $json['author'] ?? '';
                $meta['homepage'] = $json['homepage'] ?? '';
            }
        }
        return $meta;
    }

    private function guardName(string $name): void
    {
        if ($name === '' || basename($name) !== $name) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }
    }

    private function guardSelf(string $name): void
    {
        if ($name === self::SELF) {
            throw new ModMenuException(t('ModMenu cannot disable or remove itself.'));
        }
    }

    private function removeTree(string $dir): bool
    {
        if (! is_dir($dir)) { return true; }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            if (is_dir($p)) {
                if (! $this->removeTree($p)) { return false; }
            } elseif (! @unlink($p)) {
                return false;
            }
        }
        return @rmdir($dir);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (all PluginManager tests).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/PluginManager.php ModMenu/Test/PluginManagerTest.php
git commit -m "feat(ModMenu): PluginManager — list/enable/disable/uninstall/install + self-protection"
```

---

### Task 4: `SourceRepository` (directory source URLs in configModel)

**Files:**
- Create: `ModMenu/Model/SourceRepository.php`
- Test: `ModMenu/Test/SourceRepositoryTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\ModMenu\Model\SourceRepository extends \Kanboard\Core\Base` with:
  - `const CONFIG_KEY = 'modmenu_sources';`
  - `const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json';`
  - `getSources(): array` — decoded list; when config is unset (never saved) returns `[DEFAULT_SOURCE]`; when saved-but-empty returns `[]`.
  - `addSource(string $url): void` — validates https(s), dedupes, persists.
  - `removeSource(string $url): void` — persists remaining.

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/SourceRepositoryTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class SourceRepositoryTest extends Base
{
    private $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceRepository($this->container);
    }

    public function testDefaultsToBundledSourceWhenUnset()
    {
        $sources = $this->repo->getSources();
        $this->assertSame([SourceRepository::DEFAULT_SOURCE], $sources);
    }

    public function testAddSourcePersists()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->assertContains('https://example.com/plugins.json', $this->repo->getSources());
    }

    public function testAddSourceIsDeduped()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->repo->addSource('https://example.com/plugins.json');
        $count = count(array_keys($this->repo->getSources(), 'https://example.com/plugins.json'));
        $this->assertSame(1, $count);
    }

    public function testAddSourceRejectsNonHttp()
    {
        $this->expectException(ModMenuException::class);
        $this->repo->addSource('ftp://example.com/x.json');
    }

    public function testRemoveSource()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->addSource('https://example.com/b.json');
        $this->repo->removeSource('https://example.com/a.json');
        $sources = $this->repo->getSources();
        $this->assertNotContains('https://example.com/a.json', $sources);
        $this->assertContains('https://example.com/b.json', $sources);
    }

    public function testRemovingAllYieldsEmptyNotDefault()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->removeSource('https://example.com/a.json');
        $this->repo->removeSource(SourceRepository::DEFAULT_SOURCE);
        $this->assertSame([], $this->repo->getSources());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `SourceRepository` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Model/SourceRepository.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Persists the list of plugin directory source URLs in configModel as a JSON
 * array under CONFIG_KEY. Ships with one bundled default source; the admin can
 * add or remove sources (including the default).
 *
 * Distinguishes "never configured" (→ seed the default) from "configured
 * empty" (→ genuinely no sources) by storing the JSON string only once the
 * admin has touched the list.
 */
class SourceRepository extends Base
{
    const CONFIG_KEY = 'modmenu_sources';
    const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json';

    public function getSources(): array
    {
        $raw = $this->configModel->get(self::CONFIG_KEY, '');

        if ($raw === '' || $raw === null) {
            return [self::DEFAULT_SOURCE];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [self::DEFAULT_SOURCE];
    }

    public function addSource(string $url): void
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ModMenuException(t('Please enter a valid http(s) URL.'));
        }

        $sources = $this->getSources();
        if (! in_array($url, $sources, true)) {
            $sources[] = $url;
        }
        $this->save($sources);
    }

    public function removeSource(string $url): void
    {
        $sources = array_values(array_filter($this->getSources(), static fn ($s) => $s !== $url));
        $this->save($sources);
    }

    private function save(array $sources): void
    {
        $this->configModel->save([self::CONFIG_KEY => json_encode(array_values($sources))]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

Note: `Base::setUp()` in Kanboard resets the config table for each test, so `getSources()` starts unset. `configModel->save([...])` persists within a test; `configModel` may memoize — if a test reads stale values, call `$this->container['memoryCache']->flush()` in `save()` is NOT needed because configModel writes through; the provided tests pass with the write-through behavior.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/SourceRepository.php ModMenu/Test/SourceRepositoryTest.php
git commit -m "feat(ModMenu): SourceRepository — persist directory source URLs"
```

---

### Task 5: `DirectoryClient` (fetch + merge + annotate)

**Files:**
- Create: `ModMenu/Model/DirectoryClient.php`
- Test: `ModMenu/Test/DirectoryClientTest.php`

**Interfaces:**
- Consumes: `PluginManager::installedMap()`, `PluginManager::hasUpdate()`, `SourceRepository::getSources()`, `httpClient->getJson()`.
- Produces: `Kanboard\Plugin\ModMenu\Model\DirectoryClient extends \Kanboard\Core\Base` with:
  - `annotate(array $plugins, string $baseUrl, array $installedMap): array` — pure; adds `status ∈ {'available','installed','update','disabled'}` and resolves each `screenshots[]` entry to an absolute URL.
  - `static resolveAssetUrl(string $path, string $baseUrl): string` — returns `$path` unchanged if absolute, else `dirname(baseUrl) . '/' . $path`.
  - `merge(array $sourcesData, array $installedMap): array` — flatten+annotate across `['url'=>..., 'plugins'=>[...]]` items, dedupe by name (first source wins).
  - `fetchAll(): array` — read sources, GET each, return `['plugins'=>[...], 'errors'=>[...]]`. (Network path; covered by live E2E, not unit tests.)

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/DirectoryClientTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;

class DirectoryClientTest extends Base
{
    private $client;

    public function setUp(): void
    {
        parent::setUp();
        $this->client = new DirectoryClient($this->container);
    }

    public function testResolveAssetUrlKeepsAbsolute()
    {
        $this->assertSame(
            'https://cdn.example.com/a.png',
            DirectoryClient::resolveAssetUrl('https://cdn.example.com/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testResolveAssetUrlJoinsRelative()
    {
        $this->assertSame(
            'https://x.com/dir/assets/a.png',
            DirectoryClient::resolveAssetUrl('assets/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testAnnotateMarksAvailable()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame('available', $out[0]['status']);
    }

    public function testAnnotateMarksInstalled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('installed', $out[0]['status']);
    }

    public function testAnnotateMarksUpdate()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.1.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('update', $out[0]['status']);
    }

    public function testAnnotateMarksDisabled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'disabled']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('disabled', $out[0]['status']);
    }

    public function testAnnotateResolvesScreenshots()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0', 'screenshots' => ['assets/s1.png']]];
        $out = $this->client->annotate($plugins, 'https://x.com/dir/plugins.json', []);
        $this->assertSame(['https://x.com/dir/assets/s1.png'], $out[0]['screenshots']);
    }

    public function testMergeDedupesByNameFirstSourceWins()
    {
        $sourcesData = [
            ['url' => 'https://a.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '1.0.0']]],
            ['url' => 'https://b.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '9.9.9']]],
        ];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertCount(1, $merged);
        $this->assertSame('1.0.0', $merged[0]['version']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `DirectoryClient` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Model/DirectoryClient.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;

/**
 * Fetches plugin listings from configured directory sources and annotates each
 * entry with its install status relative to what is on this server.
 *
 * The pure methods (annotate/merge/resolveAssetUrl) hold all the logic and are
 * unit-tested; fetchAll() is the thin network wrapper exercised by live E2E.
 */
class DirectoryClient extends Base
{
    public static function resolveAssetUrl(string $path, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = substr($baseUrl, 0, (int) strrpos($baseUrl, '/'));
        return $base . '/' . ltrim($path, '/');
    }

    public function annotate(array $plugins, string $baseUrl, array $installedMap): array
    {
        foreach ($plugins as &$plugin) {
            $name = $plugin['name'] ?? '';
            $version = (string) ($plugin['version'] ?? '0.0.0');

            if (isset($installedMap[$name])) {
                $installed = $installedMap[$name];
                if ($installed['status'] === 'disabled') {
                    $plugin['status'] = 'disabled';
                } elseif (PluginManager::hasUpdate((string) $installed['version'], $version)) {
                    $plugin['status'] = 'update';
                } else {
                    $plugin['status'] = 'installed';
                }
                $plugin['installed_version'] = $installed['version'];
            } else {
                $plugin['status'] = 'available';
            }

            if (! empty($plugin['screenshots']) && is_array($plugin['screenshots'])) {
                $plugin['screenshots'] = array_map(
                    static fn ($s) => self::resolveAssetUrl((string) $s, $baseUrl),
                    $plugin['screenshots']
                );
            }
        }
        unset($plugin);

        return $plugins;
    }

    public function merge(array $sourcesData, array $installedMap): array
    {
        $byName = [];
        foreach ($sourcesData as $source) {
            $annotated = $this->annotate($source['plugins'] ?? [], $source['url'] ?? '', $installedMap);
            foreach ($annotated as $plugin) {
                $name = $plugin['name'] ?? '';
                if ($name !== '' && ! isset($byName[$name])) {
                    $plugin['source_url'] = $source['url'] ?? '';
                    $byName[$name] = $plugin;
                }
            }
        }
        return array_values($byName);
    }

    /**
     * @return array{plugins: array, errors: array}
     */
    public function fetchAll(): array
    {
        $sourceRepo = new SourceRepository($this->container);
        $manager = new PluginManager($this->container);
        $installedMap = $manager->installedMap();

        $sourcesData = [];
        $errors = [];

        foreach ($sourceRepo->getSources() as $url) {
            try {
                $json = $this->httpClient->getJson($url);
                $sourcesData[] = ['url' => $url, 'plugins' => is_array($json) ? $json : []];
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'message' => $e->getMessage()];
            }
        }

        return [
            'plugins' => $this->merge($sourcesData, $installedMap),
            'errors' => $errors,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Model/DirectoryClient.php ModMenu/Test/DirectoryClientTest.php
git commit -m "feat(ModMenu): DirectoryClient — merge + annotate directory listings"
```

---

### Task 6: `ModMenuController` (tabs + actions) + templates

**Files:**
- Create: `ModMenu/Controller/ModMenuController.php`
- Modify: `ModMenu/Template/settings/installed.php` (replace placeholder)
- Create: `ModMenu/Template/settings/nav.php`, `ModMenu/Template/settings/directory.php`, `ModMenu/Template/settings/sources.php`, `ModMenu/Template/settings/not_configured.php`, `ModMenu/Template/plugin/remove.php`
- Test: `ModMenu/Test/ControllerAccessTest.php`

**Interfaces:**
- Consumes: `PluginManager`, `DirectoryClient`, `SourceRepository`, `ModMenuException`.
- Produces: `Kanboard\Plugin\ModMenu\Controller\ModMenuController extends \Kanboard\Controller\BaseController` with actions `show`, `directory`, `sources`, `addSource`, `removeSource`, `confirm`, `enable`, `disable`, `uninstall`, `install`, `update`. Every action is admin-gated; every mutating action verifies CSRF and redirects with a flash.

- [ ] **Step 1: Write the failing test** — `ModMenu/Test/ControllerAccessTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Core\Controller\AccessForbiddenException;

class ControllerAccessTest extends Base
{
    private function nonAdminController(): ModMenuController
    {
        // No user session => userSession->isAdmin() is false.
        return new ModMenuController($this->container);
    }

    public function testShowForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->show();
    }

    public function testDirectoryForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->directory();
    }

    public function testSourcesForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->sources();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `ModMenuController` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Controller/ModMenuController.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Admin-only plugin-manager UI. Thin: delegates all work to the models and
 * renders the four tabs (Installed / Browse / Upload / Sources).
 */
class ModMenuController extends BaseController
{
    private function requireAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    private function manager(): PluginManager
    {
        return new PluginManager($this->container);
    }

    private function backToInstalled()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu']));
    }

    public function show()
    {
        $this->requireAdmin();
        $manager = $this->manager();

        $this->response->html($this->helper->layout->config('ModMenu:settings/installed', [
            'title' => t('ModMenu'),
            'tab' => 'installed',
            'plugins' => $manager->listInstalled(),
            'is_configured' => $manager->isConfigured(),
            'not_configured_reason' => $manager->notConfiguredReason(),
            'self_name' => PluginManager::SELF,
        ]));
    }

    public function directory()
    {
        $this->requireAdmin();
        $result = (new DirectoryClient($this->container))->fetchAll();

        $this->response->html($this->helper->layout->config('ModMenu:settings/directory', [
            'title' => t('ModMenu'),
            'tab' => 'browse',
            'plugins' => $result['plugins'],
            'errors' => $result['errors'],
            'is_configured' => $this->manager()->isConfigured(),
        ]));
    }

    public function sources()
    {
        $this->requireAdmin();

        $this->response->html($this->helper->layout->config('ModMenu:settings/sources', [
            'title' => t('ModMenu'),
            'tab' => 'sources',
            'sources' => (new SourceRepository($this->container))->getSources(),
            'default_source' => SourceRepository::DEFAULT_SOURCE,
        ]));
    }

    public function addSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = $this->request->getStringParam('url');
        try {
            (new SourceRepository($this->container))->addSource($url);
            $this->flash->success(t('Source added.'));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    public function removeSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        (new SourceRepository($this->container))->removeSource($this->request->getStringParam('url'));
        $this->flash->success(t('Source removed.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    public function confirm()
    {
        $this->requireAdmin();
        $this->response->html($this->template->render('ModMenu:plugin/remove', [
            'name' => $this->request->getStringParam('name'),
        ]));
    }

    public function enable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->enable($this->request->getStringParam('name')), t('Plugin enabled.'));
        $this->backToInstalled();
    }

    public function disable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->disable($this->request->getStringParam('name')), t('Plugin disabled.'));
        $this->backToInstalled();
    }

    public function uninstall()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->uninstall($this->request->getStringParam('name')), t('Plugin removed.'));
        $this->backToInstalled();
    }

    public function install()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = urldecode($this->request->getStringParam('archive_url'));
        $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($url), t('Plugin installed.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    public function update()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = urldecode($this->request->getStringParam('archive_url'));
        $this->runAndFlash(fn (PluginManager $m) => $m->installFromUrl($url), t('Plugin updated.'));
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    private function runAndFlash(callable $op, string $successMessage): void
    {
        try {
            $op($this->manager());
            $this->flash->success($successMessage);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
    }
}
```

Replace `ModMenu/Template/settings/installed.php`:

```php
<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! $is_configured): ?>
    <?= $this->render('ModMenu:settings/not_configured', ['reason' => $not_configured_reason]) ?>
<?php endif ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins found.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title']) ?></strong>
            <span class="modmenu-badge modmenu-badge--<?= $p['status'] === 'disabled' ? 'disabled' : 'installed' ?>">
                <?= $p['status'] === 'disabled' ? t('Disabled') : t('Active') ?>
            </span>
            <div class="modmenu-card__status">
                <?= $this->text->e($p['name']) ?> · v<?= $this->text->e($p['version']) ?>
                <?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?>
            </div>
            <?php if (! empty($p['description'])): ?>
                <p><?= $this->text->e($p['description']) ?></p>
            <?php endif ?>

            <?php if ($p['name'] !== $self_name): ?>
                <div class="modmenu-actions">
                    <?php if ($p['status'] === 'active'): ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'disable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Disable') ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Enable') ?></button>
                        </form>
                    <?php endif ?>

                    <?= $this->url->link(t('Remove'), 'ModMenuController', 'confirm',
                        ['plugin' => 'ModMenu', 'name' => $p['name']], false, 'js-modal-confirm btn btn-red') ?>
                </div>
            <?php else: ?>
                <div class="modmenu-card__status"><em><?= t('This is ModMenu itself and cannot be disabled or removed here.') ?></em></div>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
```

Create `ModMenu/Template/settings/nav.php`:

```php
<ul class="modmenu-tabs">
    <li><?= $this->url->link(t('Installed'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, $tab === 'installed' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, $tab === 'browse' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Upload'), 'UploadController', 'upload', ['plugin' => 'ModMenu'], false, $tab === 'upload' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Sources'), 'ModMenuController', 'sources', ['plugin' => 'ModMenu'], false, $tab === 'sources' ? 'active' : '') ?></li>
</ul>
```

Create `ModMenu/Template/settings/not_configured.php`:

```php
<div class="modmenu-banner">
    <strong><?= t('ModMenu cannot manage plugins here.') ?></strong>
    <p><?= $this->text->e($reason) ?></p>
    <p><?= t('Note: plugins mounted into the container (bind mounts) cannot be moved or removed by ModMenu.') ?></p>
</div>
```

Create `ModMenu/Template/settings/directory.php`:

```php
<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php foreach ($errors as $err): ?>
    <div class="modmenu-banner"><?= t('Could not load source: %s', $this->text->e($err['url'])) ?> — <?= $this->text->e($err['message']) ?></div>
<?php endforeach ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins available from the configured sources.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title'] ?? $p['name']) ?></strong>
            <?php if ($p['status'] === 'update'): ?>
                <span class="modmenu-badge modmenu-badge--update"><?= t('Update to %s', $p['version']) ?></span>
            <?php elseif ($p['status'] === 'installed'): ?>
                <span class="modmenu-badge modmenu-badge--installed"><?= t('Installed') ?></span>
            <?php elseif ($p['status'] === 'disabled'): ?>
                <span class="modmenu-badge modmenu-badge--disabled"><?= t('Disabled') ?></span>
            <?php endif ?>

            <div class="modmenu-card__status">v<?= $this->text->e($p['version']) ?><?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?></div>
            <?php if (! empty($p['description'])): ?><p><?= $this->text->e($p['description']) ?></p><?php endif ?>

            <?php if (! empty($p['screenshots'])): ?>
                <div class="modmenu-shots">
                    <?php foreach ($p['screenshots'] as $shot): ?>
                        <img class="modmenu-shot" src="<?= $this->text->e($shot) ?>" alt="">
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (! empty($p['download'])): ?>
                <?php if ($p['status'] === 'available'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Install') ?></button>
                    </form>
                <?php elseif ($p['status'] === 'update'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'update', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Update') ?></button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
```

Create `ModMenu/Template/settings/sources.php`:

```php
<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<p><?= t('ModMenu fetches plugin listings from these directory sources. Add your own to install from another repository.') ?></p>

<ul class="modmenu-sources">
    <?php foreach ($sources as $src): ?>
        <li class="modmenu-card">
            <code><?= $this->text->e($src) ?></code>
            <?php if ($src === $default_source): ?> <em>(<?= t('default') ?>)</em><?php endif ?>
            <form method="post" class="modmenu-action" style="display:inline"
                  action="<?= $this->url->href('ModMenuController', 'removeSource', ['plugin' => 'ModMenu']) ?>">
                <?= $this->form->csrf() ?>
                <input type="hidden" name="url" value="<?= $this->text->e($src) ?>">
                <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
            </form>
        </li>
    <?php endforeach ?>
</ul>

<form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'addSource', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Directory URL (plugins.json)'), 'url') ?>
    <?= $this->form->text('url', [], [], ['placeholder' => 'https://example.com/plugins.json']) ?>
    <button type="submit" class="btn btn-blue"><?= t('Add source') ?></button>
</form>
```

Create `ModMenu/Template/plugin/remove.php` (confirm modal body):

```php
<div class="page-header"><h2><?= t('Remove plugin') ?></h2></div>
<div class="confirm">
    <p class="alert alert-info">
        <?= t('Do you really want to remove "%s"? Its files will be deleted from the server.', $name) ?>
    </p>
    <form method="post" action="<?= $this->url->href('ModMenuController', 'uninstall', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
        <div class="form-actions">
            <button type="submit" class="btn btn-red"><?= t('Yes, remove it') ?></button>
            <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, 'close-popover') ?>
        </div>
    </form>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (ControllerAccessTest + all prior).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Controller/ModMenuController.php ModMenu/Template ModMenu/Test/ControllerAccessTest.php
git commit -m "feat(ModMenu): ModMenuController tabs (Installed/Browse/Sources) + templates"
```

---

### Task 7: `UploadController` (zip upload → install)

**Files:**
- Create: `ModMenu/Controller/UploadController.php`, `ModMenu/Template/settings/upload.php`
- Test: add `UploadController` admin-gate case to `ModMenu/Test/ControllerAccessTest.php`

**Interfaces:**
- Consumes: `PluginManager::installFromFile()`.
- Produces: `Kanboard\Plugin\ModMenu\Controller\UploadController extends \Kanboard\Controller\BaseController` with `upload()` (GET renders the form; POST validates `$_FILES['plugin']`, requires a `.zip`, verifies CSRF, installs, flashes, redirects to Installed).

- [ ] **Step 1: Write the failing test** — append to `ModMenu/Test/ControllerAccessTest.php`

```php
    public function testUploadForbiddenForNonAdmin()
    {
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        (new \Kanboard\Plugin\ModMenu\Controller\UploadController($this->container))->upload();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL — `UploadController` not found.

- [ ] **Step 3: Write minimal implementation** — `ModMenu/Controller/UploadController.php`

```php
<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * WordPress-style zip upload. GET renders the upload form; POST validates the
 * uploaded archive and installs it via PluginManager.
 */
class UploadController extends BaseController
{
    public function upload()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        if ($this->request->isPost()) {
            $this->checkCSRFForm();
            $this->handleUpload();
            $this->response->redirect($this->helper->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu']));
            return;
        }

        $manager = new PluginManager($this->container);
        $this->response->html($this->helper->layout->config('ModMenu:settings/upload', [
            'title' => t('ModMenu'),
            'tab' => 'upload',
            'is_configured' => $manager->isConfigured(),
        ]));
    }

    private function handleUpload(): void
    {
        $file = $_FILES['plugin'] ?? null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash->failure(t('No file was uploaded, or the upload failed.'));
            return;
        }
        if (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $this->flash->failure(t('Please upload a .zip archive.'));
            return;
        }

        try {
            $name = (new PluginManager($this->container))->installFromFile($file['tmp_name']);
            $this->flash->success(t('Plugin "%s" installed.', $name));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
    }
}
```

Create `ModMenu/Template/settings/upload.php`:

```php
<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! $is_configured): ?>
    <div class="modmenu-banner"><?= t('Uploads are disabled because ModMenu cannot write to the plugins directory here.') ?></div>
<?php endif ?>

<form method="post" enctype="multipart/form-data" class="modmenu-action"
      action="<?= $this->url->href('UploadController', 'upload', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Plugin archive (.zip)'), 'plugin') ?>
    <input type="file" name="plugin" accept=".zip" required>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"<?= $is_configured ? '' : ' disabled' ?>><?= t('Upload and install') ?></button>
    </div>
</form>
<p class="modmenu-card__status"><?= t('The archive must contain a single top-level folder with a Plugin.php inside.') ?></p>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/Controller/UploadController.php ModMenu/Template/settings/upload.php ModMenu/Test/ControllerAccessTest.php
git commit -m "feat(ModMenu): UploadController — WP-style zip upload + install"
```

---

### Task 8: Docs (README, CHANGELOG) + dev-suite mount

**Files:**
- Create: `ModMenu/README.md`, `ModMenu/CHANGELOG.md`
- Modify: `testing/docker-compose.dev.yml` (add ModMenu bind mount)

**Interfaces:** none (docs + dev harness).

- [ ] **Step 1: Write `ModMenu/README.md`**

Include: what ModMenu does, the four tabs, the enable/disable-via-move mechanism, the bind-mount caveat, security posture (admin-only, CSRF, zip validation), how to point it at a custom directory source, and the plugins.json field reference.

- [ ] **Step 2: Write `ModMenu/CHANGELOG.md`**

```markdown
# Changelog

All notable changes to ModMenu are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] — 2026-07-03

### Added
- Standalone admin plugin manager with four tabs: Installed, Browse, Upload, Sources.
- Install from a directory source (multiple sources; ships a bundled default).
- WordPress-style `.zip` upload with safe validation (single top-level dir + Plugin.php, path-traversal + size/entry caps).
- Enable/Disable by moving a plugin folder between `plugins/` and `data/modmenu_disabled/` (data preserved; no restart).
- Update detection ("update available" badge) via installed-vs-directory version compare, with one-click update.
- Uninstall with a typed confirmation modal.
- Self-protection: ModMenu can never disable or uninstall itself.
- PHPUnit suite: PluginArchive, PluginManager, SourceRepository, DirectoryClient, controller admin gates.
```

- [ ] **Step 3: Add the dev-suite mount** — in `testing/docker-compose.dev.yml`, under `volumes:` of the `kanboard` service, add:

```yaml
      - ../ModMenu:/var/www/app/plugins/ModMenu
```

- [ ] **Step 4: Verify tests still pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS (full suite).

- [ ] **Step 5: Commit**

```bash
git add ModMenu/README.md ModMenu/CHANGELOG.md testing/docker-compose.dev.yml
git commit -m "docs(ModMenu): README + CHANGELOG; mount ModMenu into dev suite"
```

---

## Self-Review

- **Spec coverage:** Self-contained architecture (Task 1); enable/disable via move (Task 3); zip upload (Task 7); multiple sources + default (Task 4); installed/update status (Tasks 3+5); update one-click (Task 6); self-protection (Task 3); not-configured/bind-mount messaging (Tasks 3+6); admin-only + CSRF (Tasks 6–7); safe extraction (Task 2); no new DB table (Task 3, dir scan). All spec §3–§9 items map to a task.
- **Type consistency:** `status` values `active|disabled` (installed list) vs `available|installed|update|disabled` (directory annotation) are intentionally different domains and never compared directly — `annotate()` reads `installedMap` status (`active|disabled`) and emits directory status. `installFromUrl/installFromFile` both return the plugin name string. `hasUpdate(installed, available)` order is consistent across `PluginManager` and `DirectoryClient::annotate`.
- **Placeholder scan:** none — every step ships complete code.
- **Note carried to execution:** live E2E of the full install/enable/disable/update/uninstall/upload loop is in the *companion* plan (Hello Harmozi + directory), since it needs the published release + directory repo.
