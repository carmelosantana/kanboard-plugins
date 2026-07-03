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
        // guardSelf() is intentionally omitted: ModMenu is always active and can
        // never be in the disabled dir, so re-enabling it is impossible in practice
        // and harmless in theory — no protection is needed here.
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
        if (! preg_match('#^https://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ModMenuException(t('The download URL must be a valid https:// URL.'));
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
        if (@rename($src, $dst)) {
            return;
        }

        // rename() fails with EXDEV when $src and $dst are on different
        // filesystems — the norm in Docker, where plugins/ (image layer) and
        // data/ (a volume) are separate mounts. Fall back to a recursive copy
        // followed by removing the source, so enable/disable works across the
        // plugins/data filesystem boundary.
        if ($this->copyTree($src, $dst)) {
            if ($this->removeTree($src)) {
                return;
            }
            throw new ModMenuException(t('Moved "%s" but could not remove the original. Its folder may be a bind mount or read-only.', $name));
        }

        $this->removeTree($dst); // clean up any partial copy
        throw new ModMenuException(t('Could not move "%s". Its folder may be a bind mount or read-only.', $name));
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (! is_dir($src)) { return false; }
        if (! is_dir($dst) && ! @mkdir($dst, 0755, true)) { return false; }

        $entries = scandir($src);
        if ($entries === false) { return false; }

        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                if (! $this->copyTree($s, $d)) { return false; }
            } elseif (! @copy($s, $d)) {
                return false;
            }
        }
        return true;
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
                // 'name' is ALWAYS the folder name — it is the operation key used
                // by enable/disable/uninstall. Using plugin.json "name" here would
                // break those operations if the json name differs from the folder.
                $meta['name'] = $folderName;
                $meta['title'] = $json['title'] ?? $json['name'] ?? $folderName;
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
