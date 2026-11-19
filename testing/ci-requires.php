<?php
/**
 * ci-requires.php — print how to check out a plugin's `requires`, one line per dependency:
 *
 *   <PluginName> <git-url> <tag>
 *
 * Usage: php testing/ci-requires.php <path/to/plugin.json> [directory-feed-url-or-path]
 *
 * Each `requires` and `recommends` plugin is resolved through the ModMenu directory feed: its
 * `homepage` is the repository and the tag in its `download` URL is the released version users
 * install. Recommended plugins are checked out too, because tests may load their classes (the
 * AI features stub AiConnector's ProviderRegistry). Exits 1 when a required plugin is not in the
 * feed or its listed release is older than `min_version`; the same problem with a recommended
 * plugin only prints a warning and skips it.
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
$deps = [];
foreach (['requires' => true, 'recommends' => false] as $key => $hard) {
    foreach ($plugin[$key] ?? [] as $dep) {
        $deps[$dep['plugin']] ??= $dep + ['hard' => $hard];
    }
}
if ($deps === []) {
    exit(0);
}

$feed = json_decode((string) file_get_contents($feedSource), true, flags: JSON_THROW_ON_ERROR);
$listed = [];
foreach ($feed['plugins'] ?? $feed as $entry) {
    $listed[$entry['name']] = $entry;
}

$status = 0;
// fail(): a required plugin fails the run; a recommended one is a warning and is skipped.
$fail = static function (array $dep, string $message) use (&$status): void {
    fwrite(STDERR, ($dep['hard'] ? '' : 'warning (recommends, skipped): ') . $message . "\n");
    if ($dep['hard']) {
        $status = 1;
    }
};
foreach ($deps as $dep) {
    $name = $dep['plugin'];
    $entry = $listed[$name] ?? null;
    if ($entry === null) {
        $fail($dep, "{$name}: not listed in the directory feed");
        continue;
    }
    if (!preg_match('#/releases/download/(v[^/]+)/#', $entry['download'], $m)) {
        $fail($dep, "{$name}: no release tag in download URL {$entry['download']}");
        continue;
    }
    $tag = $m[1];
    $min = (string) ($dep['min_version'] ?? '0');
    if (version_compare(ltrim($tag, 'v'), $min, '<')) {
        $fail($dep, "{$name}: listed release {$tag} is older than min_version {$min}");
        continue;
    }
    echo $name, ' ', rtrim($entry['homepage'], '/'), '.git ', $tag, "\n";
}
exit($status);
