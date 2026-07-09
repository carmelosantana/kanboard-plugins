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
}
