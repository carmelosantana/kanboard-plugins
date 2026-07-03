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
     * True when a zip entry name is safe to extract: non-empty, no parent-dir
     * traversal ("..") anywhere, no absolute path (leading "/"), no backslash
     * (Windows-style separators / traversal). This is the security gate for
     * every archive entry.
     */
    public static function isEntryNameSafe(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || strpos($name, '..') !== false) {
            return false;
        }
        return true;
    }

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

                if (! self::isEntryNameSafe($entry)) {
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

        $extracted = $temp . '/' . $name;
        if (@rename($extracted, $finalPath)) {
            $this->removeTree($temp);
            return $name;
        }

        // Cross-filesystem fallback: rename() fails with EXDEV when temp dir and
        // destination are on different filesystems (e.g. /tmp on tmpfs vs plugins on a volume).
        if (! $this->copyTree($extracted, $finalPath)) {
            $this->removeTree($temp);
            if (is_dir($finalPath)) { $this->removeTree($finalPath); }
            throw new ModMenuException(t('Unable to move the extracted plugin into place.'));
        }

        $this->removeTree($temp);
        return $name;
    }

    /** Recursively copy a directory tree; returns true on success. */
    protected function copyTree(string $src, string $dst): bool
    {
        if (! @mkdir($dst, 0755, true)) { return false; }
        $entries = @scandir($src);
        if ($entries === false) { return false; }
        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                if (! $this->copyTree($s, $d)) { return false; }
            } else {
                if (! @copy($s, $d)) { return false; }
            }
        }
        return true;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) { return; }
        $entries = scandir($dir);
        if ($entries === false) { @rmdir($dir); return; }
        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
