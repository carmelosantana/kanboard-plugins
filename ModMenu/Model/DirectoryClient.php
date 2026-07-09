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
}
