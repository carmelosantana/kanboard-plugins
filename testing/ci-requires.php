<?php
/**
 * ci-requires.php — print how to check out a plugin's `requires`, one line per dependency:
 *
 *   <PluginName> <git-url> <tag>
 *
 * Usage: php testing/ci-requires.php <path/to/plugin.json> [directory-feed-url-or-path]
 *
 * Each required plugin is resolved through the ModMenu directory feed: its `homepage` is the
 * repository and the tag in its `download` URL is the released version users install. Exits 1
 * when a dependency is not in the feed, or the listed release is older than `min_version`.
 */

declare(strict_types=1);

const DEFAULT_FEED = 'https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json';

$pluginJson = $argv[1] ?? '';
$feedSource = $argv[2] ?? DEFAULT_FEED;

if ($pluginJson === '' || !is_file($pluginJson)) {
    fwrite(STDERR, "usage: php ci-requires.php <plugin.json> [feed]\n");
    exit(2);
}

$plugin = json_decode((string) file_get_contents($pluginJson), true, flags: JSON_THROW_ON_ERROR);
$requires = $plugin['requires'] ?? [];
if ($requires === []) {
    exit(0);
}

$feed = json_decode((string) file_get_contents($feedSource), true, flags: JSON_THROW_ON_ERROR);
$listed = [];
foreach ($feed['plugins'] ?? $feed as $entry) {
    $listed[$entry['name']] = $entry;
}

$status = 0;
foreach ($requires as $dep) {
    $name = $dep['plugin'];
    $entry = $listed[$name] ?? null;
    if ($entry === null) {
        fwrite(STDERR, "{$name}: not listed in the directory feed\n");
        $status = 1;
        continue;
    }
    if (!preg_match('#/releases/download/(v[^/]+)/#', $entry['download'], $m)) {
        fwrite(STDERR, "{$name}: no release tag in download URL {$entry['download']}\n");
        $status = 1;
        continue;
    }
    $tag = $m[1];
    $min = (string) ($dep['min_version'] ?? '0');
    if (version_compare(ltrim($tag, 'v'), $min, '<')) {
        fwrite(STDERR, "{$name}: listed release {$tag} is older than min_version {$min}\n");
        $status = 1;
        continue;
    }
    echo $name, ' ', rtrim($entry['homepage'], '/'), '.git ', $tag, "\n";
}
exit($status);
