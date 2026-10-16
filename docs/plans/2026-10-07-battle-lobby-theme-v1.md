# Battle Lobby theme v1.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Model rule:** implementer/fixer = **Opus**, reviewer = **Opus** (per-task and whole-branch). Pass `model: "opus"` explicitly on every Agent dispatch. Never Haiku.

**Goal:** Ship Battle Lobby v1.0, a buildless Kanboard 1.2.47 theme plugin that makes the board feel like a battle-royale game lobby. It has original characters layered over swappable original backgrounds, rarity-tier cards, storm due chips, READY UP, a victory overlay and a loading screen, with admin defaults and per-user options. It contains no Epic IP and is ready for a ModMenu release.

**Architecture:** Core template hooks plus external CSS and JS only, with no `setTemplateOverride`. PHP owns options (OptionsModel: schema, validation, user → admin → built-in resolution) and per-task presentation (LobbyHelper: tier, storm, `data-bl-*` payload). External JS reads the `data-bl-*` payload (CSP blocks inline script) and drives the lobby stage, overlays and the small JSON endpoints (ReadyUp, Feed, Thanks).

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 plugin API, PHPUnit via `testing/run-plugin-tests.sh`, vanilla ES2020 JS (classic script, CommonJS-exportable pure helpers tested with `node --test`), plain CSS (custom properties, `:has`, `backdrop-filter`), Python 3 + Pillow for the art pipeline, ComfyUI (Qwen-Image / Qwen-Image-Edit-2509 / BiRefNet).

**Spec:** `kanboard-plugins/docs/specs/2026-10-07-battle-lobby-theme-v1-design.md` (decisions settled on the wayfinder map Kanboard #5363). Read the spec before Task 1. Research and roster: `BattleLobby/reports/*.md`. Look and behaviour reference: `BattleLobby/prototype/lobby-theme.prototype.html`.

## Global Constraints

- Plugin dir and namespace `BattleLobby` / `Kanboard\Plugin\BattleLobby`; display name "Battle Lobby"; repo `carmelosantana/kanboard-battle-lobby`.
- `kanboard_version` `>=1.2.47`; `php_version` `>=8.4`; version `1.0.0`. The version must match across plugin.json, `Plugin.php` and CHANGELOG (CI enforces tag == version).
- License: MIT for code (`LICENSE`), CC BY 4.0 for original art and characters (`LICENSE-ART`), OFL 1.1 for fonts (`Assets/fonts/barlowcondensed/OFL.txt`).
- **No Epic IP anywhere in the repo or zip:** the strings `Fortnite`, `Epic Games` (except in the README disclaimer paragraph), `Burbank`, `Battle Bus`, `Tilted`, `V-Bucks` and `Victory Royale` are forbidden. A guard test enforces this (Task 2).
- **No PII:** no personal email or street address in any file. The author string is `Carmelo Santana`. Commit identity comes from `/powerup:who-am-i`.
- **CSP:** no inline `<script>` and no inline `on*=` handlers in templates. All JS is in `Assets/js/battle-lobby.js`.
- No template overrides. Hooks only (spec § Hook mount points).
- Fonts are self-hosted WOFF2 (Barlow Condensed 900 and 900 italic, Latin) with `font-display: swap`. Never reference `fonts.googleapis.com` or `fonts.gstatic.com` at runtime.
- Images ship as **WebP with alpha** (this replaces the spec's AVIF tier, because no AVIF encoder is available on this box and WebP is supported by every browser Kanboard 1.2.47 targets). Backgrounds are ≤ 400 KB each at 1920 px wide; characters total 2–4 MB; the plugin zip is ≤ 8 MB.
- Glass (`backdrop-filter`) only on the header, view tabs, board columns, options panels and the lobby stage. Task cards are always opaque. Default tint alpha is `0.78`; the user floor is `0.60`, with a warning below `0.75`.
- Every animation is disabled under `prefers-reduced-motion: reduce` or `motion=off`. `prefers-reduced-transparency: reduce` or no `backdrop-filter` support switches to solid surfaces. Pulses run at 1 Hz or slower.
- The tiers COMMON / UNCOMMON / RARE / EPIC / LEGENDARY always render as text as well as colour.
- TimeReport is *recommended* (`min_version` `1.5.0`) and never required. Battle Lobby v1.0 contains no progress, XP or pass-track logic; that integration is a follow-up plan once the TimeReport 1.5.0 contract exists (Kanboard #5375).
- ComfyUI (Task 11) is at `http://192.168.1.141:8188` and the GPU is shared. Submit only when `GET /queue` shows empty `queue_running` and `queue_pending`. Never interrupt, clear the queue or free VRAM.
- Do not `git push`, create the GitHub repo, tag a release or edit the ModMenu directory without Carmelo's explicit go.

## Review Focus

1. **Projects with a custom priority range** (for example `priority_start=1`, `priority_end=5`, or a range of only 0–1) still map to sensible tiers, and the highest priority reads LEGENDARY only when the range has more than 4 values. Pinned in Task 3 (`testTierUsesProjectRange*`).
2. **A user who has never saved options**, or whose stored JSON is corrupt or carries unknown or out-of-range keys, gets valid resolved options and no PHP warnings. Pinned in Task 2 (`testResolveIgnoresCorruptJson`, `testValidateClampsAndDrops`).
3. **READY UP when nothing is eligible:** no Ready column, no task owned by the user in Ready, Ready is the last column, or the user lacks access. The response is a clean JSON error and nothing moves. Pinned in Task 8.
4. **Board pages with hundreds of tasks and many swimlanes:** the victory detector does not fire for cards that were already in Done on load, does not fire twice for one card, and handles multiple swimlanes. Pinned in Task 7 (`newArrivals` tests).
5. **Pages without a board** (dashboard, settings, the login page with `no_layout`) render the reskin without JS errors, and no lineup appears where the placement rules say none should. Pinned in Task 7 (`placementFor` tests) and Task 10 (login hero renders its own payload).

---

## File Structure

```
BattleLobby/
  .gitignore                         local/, prototype/, research_notes/, testing/.env, *.zip
  .github/workflows/release.yml      zip-on-tag (excludes Test, testing, docs, reports, prototype, research_notes, local)
  plugin.json                        metadata + recommends TimeReport + conflicts ShadcnTheme
  Plugin.php                         hooks, routes, helper + model registration
  LICENSE, LICENSE-ART, README.md, CHANGELOG.md
  Model/OptionsModel.php             option schema, validate, admin/user storage, resolve
  Helper/LobbyHelper.php             tier(), tierLabel(), storm(), payloadAttributes(), isConflictActive()
  Controller/OptionsController.php   admin + user options pages (show/save/reset)
  Controller/ReadyUpController.php   POST: move user's top Ready task to the next column
  Controller/FeedController.php      GET recent project events (JSON); POST thanks comment
  Template/layout/head.php           font + background preload
  Template/layout/stage.php          lobby stage mount + data-bl-* payload (template:layout:bottom)
  Template/layout/conflict.php       admin banner when ShadcnTheme is also loaded (template:layout:top)
  Template/board/tier.php            tier label (template:board:private:task:before-title)
  Template/board/storm.php           storm chip (template:board:task:footer)
  Template/auth/hero.php             login hero + payload (template:auth:login-form:before)
  Template/config/sidebar.php        admin sidebar link
  Template/config/options.php        admin options form
  Template/user/sidebar.php          user sidebar link
  Template/user/options.php          user options form
  Template/options/_fields.php       shared form fields
  Assets/css/battle-lobby.css        tokens, reskin, board, stage, overlays, login, a11y fallbacks
  Assets/js/battle-lobby.js          pure helpers (exported for node tests) + DOM wiring
  Assets/fonts/barlowcondensed/      BarlowCondensed-Black.woff2, BarlowCondensed-BlackItalic.woff2, OFL.txt
  Assets/backgrounds/                map.webp, storm.webp, island.webp, victory.webp
  Assets/characters/                 manifest.json, <slug>-idle.webp, <slug>-victory.webp (12 × 2)
  Test/                              PHPUnit (*Test.php) + js/battle-lobby.test.cjs (node --test)
  tools/art/                         render_characters.py, pose_victory.py, encode_assets.py, contact_sheet.py
  testing/                           docker-compose.base.yml (exists), docker-compose.e2e.yml, capture_reference.py (exists), contrast_audit.py
  docs/kanboard-reference/           (exists) stock markup reference
  docs/research/                     reports moved from reports/
```

Parent repo (`kanboard-plugins`) changes: `testing/run-plugin-tests.sh` (add `BattleLobby` to the symlink list) and `ModMenu/` (Task 12, its own repo).

---

### Task 1: Repo bootstrap, metadata and IP/PII guard

**Files:**
- Create: `BattleLobby/.gitignore`, `BattleLobby/plugin.json`, `BattleLobby/Plugin.php`, `BattleLobby/LICENSE`, `BattleLobby/LICENSE-ART`, `BattleLobby/README.md`, `BattleLobby/CHANGELOG.md`, `BattleLobby/.github/workflows/release.yml`
- Create: `BattleLobby/Test/PluginMetaTest.php`, `BattleLobby/Test/PluginTest.php`, `BattleLobby/Test/IpGuardTest.php`
- Move: `BattleLobby/reports/*.md` → `BattleLobby/docs/research/`
- Modify: `kanboard-plugins/testing/run-plugin-tests.sh` (symlink list)

**Interfaces:**
- Produces: `Kanboard\Plugin\BattleLobby\Plugin` with `initialize()`, `getPluginName(): 'Battle Lobby'`, `getPluginVersion(): '1.0.0'`, `getCompatibleVersion(): '>=1.2.47'`, `isPhpCompatible(?int $versionId = null): bool`. Later tasks add hook and route registrations inside `initialize()`.

- [ ] **Step 1: Snapshot everything onto a throwaway `prototype` branch, then start an orphan `main`**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins/BattleLobby
git init -b prototype
git add -A
git commit -m "chore: prototype + research snapshot (throwaway branch, primary source)"
git checkout --orphan main
git rm -r -q --cached .
mkdir -p docs/research && mv reports/*.md docs/research/ && rmdir reports
```

Expected: `git branch` lists `main` and `prototype`; `prototype/`, `research_notes/` and the rest remain in the working tree, untracked on `main`.

- [ ] **Step 2: Write `.gitignore`**

```gitignore
local/
prototype/
research_notes/
testing/.env
*.zip
.DS_Store
```

- [ ] **Step 3: Add BattleLobby to the suite test runner**

In `kanboard-plugins/testing/run-plugin-tests.sh`, change both plugin lists (the usage line and the symlink `for p in …` loop) to include `BattleLobby` after `AiConnector`:

```bash
for p in AiConnector BattleLobby BulkProjectDelete CalendarPlugin DependencyPlugin FeatureSync Kensho ModMenu SchedulerPlugin ShadcnTheme SubtaskGenerator TimeBlock TimeReport TimeInvoice; do
```

Commit that one-line change in the parent repo (`git -C /home/carmelo/Projects/Kanboard/kanboard-plugins commit -m "test: register BattleLobby in plugin test runner" testing/run-plugin-tests.sh`).

- [ ] **Step 4: Write the failing tests**

`Test/PluginMetaTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
    }

    public function testNameVersionCompatLicense(): void
    {
        $j = $this->json();
        $this->assertSame('BattleLobby', $j['name']);
        $this->assertSame('1.0.0', $j['version']);
        $this->assertSame('>=1.2.47', $j['kanboard_version']);
        $this->assertSame('>=8.4', $j['php_version']);
        $this->assertSame('MIT', $j['license']);
        $this->assertSame('https://github.com/carmelosantana/kanboard-battle-lobby', $j['homepage']);
    }

    public function testVersionAgreesAcrossFiles(): void
    {
        $version = $this->json()['version'];
        $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $this->assertMatchesRegularExpression("/getPluginVersion\(\)[^}]*return '" . preg_quote($version, '/') . "'/s", $plugin);
        $this->assertStringContainsString('## ' . $version . ' — ', file_get_contents(dirname(__DIR__) . '/CHANGELOG.md'));
    }

    public function testRecommendsTimeReportAndConflictsShadcn(): void
    {
        $j = $this->json();
        $this->assertArrayNotHasKey('requires', $j);
        $this->assertSame(['TimeReport'], array_column($j['recommends'], 'plugin'));
        $this->assertSame('1.5.0', $j['recommends'][0]['min_version']);
        $this->assertSame(['ShadcnTheme'], $j['conflicts']);
    }
}
```

`Test/PluginTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Plugin;

class PluginTest extends Base
{
    public function testMetadata(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('Battle Lobby', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertSame('Carmelo Santana', $plugin->getPluginAuthor());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertStringContainsString('kanboard-battle-lobby', $plugin->getPluginHomepage());
        $this->assertStringContainsString('lobby', strtolower($plugin->getPluginDescription()));
    }

    public function testPhpGate(): void
    {
        $plugin = new Plugin($this->container);
        $this->assertTrue($plugin->isPhpCompatible(80400));
        $this->assertFalse($plugin->isPhpCompatible(80399));
    }

    public function testInitializeRuns(): void
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertTrue(true);
    }
}
```

`Test/IpGuardTest.php` (guards the whole shippable tree, so it applies to every later task):

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class IpGuardTest extends Base
{
    private const FORBIDDEN = ['fortnite', 'burbank', 'battle bus', 'tilted', 'v-bucks', 'victory royale'];
    private const SKIP_DIRS = ['/.git/', '/prototype/', '/research_notes/', '/docs/', '/testing/', '/Test/', '/local/', '/tools/'];

    /** @return iterable<string> */
    private function shippableFiles(): iterable
    {
        $root = dirname(__DIR__);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            $rel = '/' . ltrim(substr($path, strlen($root)), '/');
            foreach (self::SKIP_DIRS as $skip) {
                if (str_starts_with($rel, $skip)) {
                    continue 2;
                }
            }
            if (preg_match('/\.(php|js|css|json|md|yml|txt|html)$/', $path)) {
                yield $rel => $path;
            }
        }
    }

    public function testNoEpicMarksInShippableFiles(): void
    {
        foreach ($this->shippableFiles() as $rel => $path) {
            $src = strtolower(file_get_contents($path));
            if ($rel === '/README.md') {
                // The README may name the game only inside the required disclaimer paragraph.
                $src = preg_replace('/<!-- disclaimer -->.*<!-- \/disclaimer -->/s', '', $src);
            }
            foreach (self::FORBIDDEN as $word) {
                $this->assertStringNotContainsString($word, $src, "$rel contains forbidden mark '$word'");
            }
        }
    }

    public function testNoPiiInShippableFiles(): void
    {
        foreach ($this->shippableFiles() as $rel => $path) {
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@carmelosantana\.com/', $src, "$rel contains a personal email");
            $this->assertStringNotContainsStringIgnoringCase('newburgh', $src, "$rel contains a location");
        }
    }

    public function testNoRuntimeGoogleFonts(): void
    {
        foreach ($this->shippableFiles() as $rel => $path) {
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('fonts.googleapis.com', $src, "$rel loads Google Fonts at runtime");
            $this->assertStringNotContainsString('fonts.gstatic.com', $src, "$rel loads Google Fonts at runtime");
        }
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `cd /home/carmelo/Projects/Kanboard/kanboard-plugins && ./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL, because `plugin.json` and `Plugin.php` don't exist yet (file_get_contents warnings, class not found).

- [ ] **Step 6: Write the metadata and plugin skeleton**

`plugin.json`:

```json
{
    "name": "BattleLobby",
    "description": "Battle-royale lobby theme for Kanboard: original characters, rarity-tier cards, storm due dates, READY UP and a victory screen. Fan-made; not affiliated with any game publisher.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-battle-lobby",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4",
    "recommends": [
        { "plugin": "TimeReport", "min_version": "1.5.0", "reason": "adds quest-bar subtask progress, a milestone pass track and a level badge (the theme works fully without it)" }
    ],
    "conflicts": ["ShadcnTheme"]
}
```

`Plugin.php`:

```php
<?php

namespace Kanboard\Plugin\BattleLobby;

use Kanboard\Core\Plugin\Base;

/**
 * Battle Lobby — a battle-royale lobby theme for Kanboard.
 *
 * Hooks + external assets only (no template overrides). Options resolve
 * user → admin → built-in via OptionsModel; per-task presentation lives in
 * LobbyHelper; JS reads the data-bl-* payload (CSP-safe).
 */
class Plugin extends Base
{
    public function initialize(): void
    {
    }

    public function isPhpCompatible(?int $versionId = null): bool
    {
        return ($versionId ?? PHP_VERSION_ID) >= 80400;
    }

    public function getPluginName(): string
    {
        return 'Battle Lobby';
    }

    public function getPluginDescription(): string
    {
        return t('Battle-royale lobby theme: original characters over painted backgrounds, rarity-tier cards, storm due dates, READY UP and a victory screen.');
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
        return 'https://github.com/carmelosantana/kanboard-battle-lobby';
    }
}
```

`CHANGELOG.md`:

```markdown
# Changelog

## 1.0.0 — unreleased

- First release: battle-royale lobby theme with original characters, rarity-tier cards, storm due-date chips, READY UP, victory overlay, loading screen, login hero, admin defaults with per-user overrides.
```

`LICENSE`: the standard MIT text with `Copyright (c) 2026 Carmelo Santana`.

`LICENSE-ART`:

```text
The original artwork in Assets/backgrounds/ and Assets/characters/ is licensed under
Creative Commons Attribution 4.0 International (CC BY 4.0):
https://creativecommons.org/licenses/by/4.0/

Attribution: "Battle Lobby" by Carmelo Santana.
The fonts in Assets/fonts/ keep their own SIL Open Font License 1.1 (see OFL.txt there).
```

`README.md`:

```markdown
# Battle Lobby

A battle-royale lobby theme for Kanboard. Your board becomes a game lobby: a squad of original
characters stands on a stage over painted island art, cards wear rarity tiers by priority,
due dates become a closing storm, READY UP starts your next task, and shipping a task plays a
victory screen.

## Features

- Lobby stage with 12 original characters in 4 sets (Brawl, Wildlands, Nightfall, Mixed) or none
- Rarity-tier cards by priority (COMMON → LEGENDARY), storm due-date chips
- READY UP: move your top Ready task into progress
- Victory overlay when a card lands in the last column; loading screen; login hero
- Admin defaults (Settings → Battle Lobby) with per-user overrides (profile → Battle Lobby)
- Respects reduced motion and reduced transparency; self-hosted fonts, no external requests

Works better with **TimeReport** 1.5.0+ (quest-bar subtask progress, pass track, level badge).
Not compatible with **ShadcnTheme**: enable one theme at a time.

## Install

Install from ModMenu, or unzip into `plugins/BattleLobby/`. Requires Kanboard ≥ 1.2.47 and PHP ≥ 8.4.

## Local fan pack

Drop your own files into `plugins/BattleLobby/local/` (never committed, never shipped):
`local/fonts/display.woff2` replaces the heading font; `local/backgrounds/*.webp` add backgrounds.
Use only files you have the right to use.

## Licenses

Code: MIT (`LICENSE`). Original art and characters: CC BY 4.0 (`LICENSE-ART`). Fonts: SIL OFL 1.1.

<!-- disclaimer -->
## Disclaimer

Battle Lobby is a fan-made theme inspired by battle-royale game lobbies such as Fortnite. It is
not affiliated with, endorsed by, or sponsored by Epic Games. Portions of the materials used are
trademarks and/or copyrighted works of Epic Games, Inc. All rights reserved by Epic. This
material is not official and is not endorsed by Epic. No game assets, logos or fonts are included.
<!-- /disclaimer -->
```

`.github/workflows/release.yml`:

```yaml
name: release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build and publish plugin zip
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          NAME=$(grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          VERSION=$(grep -oE '"version"[[:space:]]*:[[:space:]]*"[^"]+"' plugin.json | head -1 | sed -E 's/.*"([^"]+)"$/\1/')
          if [ "v$VERSION" != "$GITHUB_REF_NAME" ]; then
            echo "::error::tag $GITHUB_REF_NAME does not match plugin.json v$VERSION"; exit 1
          fi
          mkdir -p /tmp/stage/"$NAME"
          rsync -a --exclude '.git' --exclude '.github' --exclude 'Test' --exclude 'testing' --exclude 'docs' \
            --exclude 'tools' --exclude 'prototype' --exclude 'research_notes' --exclude 'local' --exclude '.DS_Store' \
            ./ /tmp/stage/"$NAME"/
          ( cd /tmp/stage && zip -qr "$GITHUB_WORKSPACE/${NAME}-${VERSION}.zip" "$NAME" )
          SIZE=$(stat -c%s "${NAME}-${VERSION}.zip"); [ "$SIZE" -le 8388608 ] || { echo "::error::zip ${SIZE}B > 8MB"; exit 1; }
          gh release create "$GITHUB_REF_NAME" "${NAME}-${VERSION}.zip" --title "$GITHUB_REF_NAME" --notes "Release $GITHUB_REF_NAME"
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS (3 test classes, all green).

- [ ] **Step 8: Commit**

```bash
cd BattleLobby
git add .gitignore plugin.json Plugin.php LICENSE LICENSE-ART README.md CHANGELOG.md .github Test docs testing
git commit -m "feat: bootstrap Battle Lobby plugin (metadata, licenses, disclaimer, IP/PII guard)"
```

---

### Task 2: OptionsModel — schema, validation, storage, resolution

**Files:**
- Create: `BattleLobby/Model/OptionsModel.php`
- Modify: `BattleLobby/Plugin.php` (register `battleLobbyOptions` service)
- Test: `BattleLobby/Test/OptionsModelTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\BattleLobby\Model\OptionsModel` (extends `Kanboard\Core\Base`), exposed in the container as `$container['battleLobbyOptions']`:
  - `const CONFIG_KEY = 'battlelobby_options'` (app config, JSON) and `const META_KEY = 'battlelobby_options'` (user metadata, JSON)
  - `schema(): array<string, array{type:string, default:mixed, values?:list<string>, min?:int|float, max?:int|float}>`
  - `defaults(): array<string,mixed>`
  - `validate(array $input): array<string,mixed>`: known keys only, coerced and clamped, invalid enums dropped
  - `adminValues(): array`, `saveAdmin(array $input): bool`
  - `userValues(int $userId): array`, `saveUser(int $userId, array $input): bool`, `resetUser(int $userId): bool`
  - `resolve(?int $userId): array<string,mixed>`: defaults ← admin ← user, always a complete set of keys

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Model\OptionsModel;
use Kanboard\Model\UserModel;

class OptionsModelTest extends Base
{
    private function model(): OptionsModel
    {
        return new OptionsModel($this->container);
    }

    public function testDefaults(): void
    {
        $d = $this->model()->defaults();
        $this->assertSame([
            'background' => 'map', 'characters' => 'mixed', 'placement' => 'auto', 'motion' => 'auto',
            'glass' => true, 'blur' => 12, 'tint' => 0.78,
            'loading' => true, 'victory' => true, 'storm' => true, 'readyup' => true,
            'feed' => false, 'thanks' => false,
        ], $d);
    }

    public function testValidateClampsAndDrops(): void
    {
        $v = $this->model()->validate([
            'background' => 'lava',        // invalid enum → dropped
            'characters' => 'night',
            'blur' => '99',                // clamp to 24
            'tint' => '0.1',               // clamp to 0.60
            'glass' => '0',                // bool coercion
            'feed' => 'on',
            'evil' => 'x',                 // unknown → dropped
        ]);
        // validate() emits keys in schema order
        $this->assertSame(['characters' => 'night', 'glass' => false, 'blur' => 24, 'tint' => 0.6, 'feed' => true], $v);
    }

    public function testResolveLayersUserOverAdminOverDefaults(): void
    {
        $m = $this->model();
        $userId = (new UserModel($this->container))->create(['username' => 'squad1']);
        $this->assertTrue($m->saveAdmin(['background' => 'storm', 'characters' => 'brawl']));
        $this->assertTrue($m->saveUser($userId, ['characters' => 'night']));

        $r = $m->resolve($userId);
        $this->assertSame('storm', $r['background']);   // admin
        $this->assertSame('night', $r['characters']);   // user wins
        $this->assertSame(12, $r['blur']);              // built-in
        $this->assertSame('brawl', $m->resolve(null)['characters']); // anonymous → admin
    }

    public function testResetUserFallsBackToAdmin(): void
    {
        $m = $this->model();
        $userId = (new UserModel($this->container))->create(['username' => 'squad2']);
        $m->saveAdmin(['motion' => 'off']);
        $m->saveUser($userId, ['motion' => 'on']);
        $this->assertTrue($m->resetUser($userId));
        $this->assertSame('off', $m->resolve($userId)['motion']);
    }

    public function testResolveIgnoresCorruptJson(): void
    {
        $userId = (new UserModel($this->container))->create(['username' => 'squad3']);
        $this->container['configModel']->save([OptionsModel::CONFIG_KEY => '{not json']);
        $this->container['userMetadataModel']->save($userId, [OptionsModel::META_KEY => '["list","not","object"]']);
        $r = $this->model()->resolve($userId);
        $this->assertSame($this->model()->defaults(), $r);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL with `Class "Kanboard\Plugin\BattleLobby\Model\OptionsModel" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Kanboard\Plugin\BattleLobby\Model;

use Kanboard\Core\Base;

/**
 * Theme options: one schema, validated input, layered resolution
 * (built-in defaults ← admin app config ← per-user metadata).
 */
class OptionsModel extends Base
{
    public const CONFIG_KEY = 'battlelobby_options';
    public const META_KEY = 'battlelobby_options';

    public function schema(): array
    {
        return [
            'background' => ['type' => 'enum', 'values' => ['map', 'storm', 'island', 'none'], 'default' => 'map'],
            'characters' => ['type' => 'enum', 'values' => ['mixed', 'brawl', 'wild', 'night', 'none'], 'default' => 'mixed'],
            'placement'  => ['type' => 'enum', 'values' => ['auto', 'lineup', 'mascot', 'off'], 'default' => 'auto'],
            'motion'     => ['type' => 'enum', 'values' => ['auto', 'on', 'off'], 'default' => 'auto'],
            'glass'      => ['type' => 'bool', 'default' => true],
            'blur'       => ['type' => 'int', 'min' => 0, 'max' => 24, 'default' => 12],
            'tint'       => ['type' => 'float', 'min' => 0.60, 'max' => 0.95, 'default' => 0.78],
            'loading'    => ['type' => 'bool', 'default' => true],
            'victory'    => ['type' => 'bool', 'default' => true],
            'storm'      => ['type' => 'bool', 'default' => true],
            'readyup'    => ['type' => 'bool', 'default' => true],
            'feed'       => ['type' => 'bool', 'default' => false],
            'thanks'     => ['type' => 'bool', 'default' => false],
        ];
    }

    public function defaults(): array
    {
        return array_map(fn (array $s) => $s['default'], $this->schema());
    }

    public function validate(array $input): array
    {
        $out = [];
        foreach ($this->schema() as $key => $s) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            switch ($s['type']) {
                case 'enum':
                    if (is_string($raw) && in_array($raw, $s['values'], true)) {
                        $out[$key] = $raw;
                    }
                    break;
                case 'bool':
                    $out[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'int':
                    if (is_numeric($raw)) {
                        $out[$key] = max($s['min'], min($s['max'], (int) $raw));
                    }
                    break;
                case 'float':
                    if (is_numeric($raw)) {
                        $out[$key] = round(max($s['min'], min($s['max'], (float) $raw)), 2);
                    }
                    break;
            }
        }
        return $out;
    }

    public function adminValues(): array
    {
        return $this->decode($this->configModel->get(self::CONFIG_KEY, ''));
    }

    public function saveAdmin(array $input): bool
    {
        return (bool) $this->configModel->save([self::CONFIG_KEY => json_encode($this->validate($input))]);
    }

    public function userValues(int $userId): array
    {
        return $this->decode($this->userMetadataModel->get($userId, self::META_KEY, ''));
    }

    public function saveUser(int $userId, array $input): bool
    {
        return (bool) $this->userMetadataModel->save($userId, [self::META_KEY => json_encode($this->validate($input))]);
    }

    public function resetUser(int $userId): bool
    {
        return (bool) $this->userMetadataModel->save($userId, [self::META_KEY => '{}']);
    }

    public function resolve(?int $userId): array
    {
        $values = array_replace($this->defaults(), $this->adminValues());
        if ($userId) {
            $values = array_replace($values, $this->userValues($userId));
        }
        return $values;
    }

    /** Decode stored JSON and re-validate it; anything malformed yields []. */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || array_is_list($data)) {
            return [];
        }
        return $this->validate($data);
    }
}
```

In `Plugin.php` `initialize()`, add at the top:

```php
        $this->container['battleLobbyOptions'] = function ($c) {
            return new \Kanboard\Plugin\BattleLobby\Model\OptionsModel($c);
        };
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS (OptionsModelTest 5/5 plus the earlier classes).

Note: `array_is_list([])` is `true`, so stored `'{}'` decodes to `[]` and goes through the list branch, returning `[]`. That is intended.

- [ ] **Step 5: Commit**

```bash
git add Model/OptionsModel.php Plugin.php Test/OptionsModelTest.php
git commit -m "feat: OptionsModel with schema, validation and user → admin → built-in resolution"
```

---

### Task 3: LobbyHelper — tiers, storm state, data payload, conflict detection

**Files:**
- Create: `BattleLobby/Helper/LobbyHelper.php`
- Modify: `BattleLobby/Plugin.php` (register helper `battleLobby`)
- Test: `BattleLobby/Test/LobbyHelperTest.php`

**Interfaces:**
- Consumes: `$container['battleLobbyOptions']->resolve(?int)` (Task 2).
- Produces: `Kanboard\Plugin\BattleLobby\Helper\LobbyHelper` (extends `Kanboard\Core\Base`), reachable in templates as `$this->battleLobby->…`:
  - `tier(array $task, array $project): int` (0–4)
  - `tierLabel(int $tier): string` (`'COMMON'`, `'UNCOMMON'`, `'RARE'`, `'EPIC'`, `'LEGENDARY'`)
  - `storm(int $dateDue, int $now): array{state:string, label:string}` with states `none|far|soon|late`
  - `payloadAttributes(?int $userId, string $context): string`: an HTML attribute string. Keys: `data-bl-context` (`board|page|login`), `data-bl-assets`, `data-bl-readyup-url`, `data-bl-feed-url`, `data-bl-thanks-url`, `data-bl-csrf`, and `data-bl-<option>` for every option key
  - `isConflictActive(): bool`: true when the ShadcnTheme plugin class is loaded

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Helper\LobbyHelper;
use Kanboard\Plugin\BattleLobby\Model\OptionsModel;

class LobbyHelperTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['battleLobbyOptions'] = fn ($c) => new OptionsModel($c);
    }

    private function h(): LobbyHelper
    {
        return new LobbyHelper($this->container);
    }

    public function testTierDefaultRangeZeroToThree(): void
    {
        $p = ['priority_start' => 0, 'priority_end' => 3];
        $this->assertSame([0, 1, 2, 3], array_map(fn ($pr) => $this->h()->tier(['priority' => $pr], $p), [0, 1, 2, 3]));
    }

    public function testTierUsesProjectRangeWideRangeReachesLegendary(): void
    {
        $p = ['priority_start' => 1, 'priority_end' => 5];
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn ($pr) => $this->h()->tier(['priority' => $pr], $p), [1, 2, 3, 4, 5]));
    }

    public function testTierUsesProjectRangeNarrowRange(): void
    {
        $p = ['priority_start' => 0, 'priority_end' => 1];
        $this->assertSame(0, $this->h()->tier(['priority' => 0], $p));
        $this->assertSame(3, $this->h()->tier(['priority' => 1], $p)); // top of a short range = EPIC, never LEGENDARY
    }

    public function testTierClampsOutOfRangeAndMissing(): void
    {
        $p = ['priority_start' => 0, 'priority_end' => 3];
        $this->assertSame(0, $this->h()->tier(['priority' => -5], $p));
        $this->assertSame(3, $this->h()->tier(['priority' => 99], $p));
        $this->assertSame(0, $this->h()->tier([], []));
    }

    public function testTierLabels(): void
    {
        $this->assertSame(['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY'], array_map(fn ($t) => $this->h()->tierLabel($t), [0, 1, 2, 3, 4]));
    }

    public function testStormStates(): void
    {
        $now = 1_800_000_000;
        $this->assertSame(['state' => 'none', 'label' => ''], $this->h()->storm(0, $now));
        $this->assertSame(['state' => 'far', 'label' => '3d'], $this->h()->storm($now + 3 * 86400 + 10, $now));
        $this->assertSame(['state' => 'soon', 'label' => '5h'], $this->h()->storm($now + 5 * 3600, $now));
        $this->assertSame(['state' => 'soon', 'label' => '<1h'], $this->h()->storm($now + 600, $now));
        $this->assertSame(['state' => 'late', 'label' => '2d late'], $this->h()->storm($now - 2 * 86400 - 5, $now));
        $this->assertSame(['state' => 'late', 'label' => 'late'], $this->h()->storm($now - 60, $now));
    }

    public function testPayloadAttributesCarryOptionsAndEndpoints(): void
    {
        $this->container['battleLobbyOptions']->saveAdmin(['characters' => 'night', 'blur' => 4]);
        $attrs = $this->h()->payloadAttributes(null, 'board');
        $this->assertStringContainsString('data-bl-context="board"', $attrs);
        $this->assertStringContainsString('data-bl-characters="night"', $attrs);
        $this->assertStringContainsString('data-bl-blur="4"', $attrs);
        $this->assertStringContainsString('data-bl-glass="1"', $attrs);
        $this->assertStringContainsString('data-bl-feed="0"', $attrs);
        $this->assertStringContainsString('data-bl-assets="', $attrs);
        $this->assertStringContainsString('plugins/BattleLobby/Assets/', $attrs);
        $this->assertStringContainsString('data-bl-readyup-url="', $attrs);
        $this->assertStringContainsString('data-bl-csrf="', $attrs);
        $this->assertStringNotContainsString('<', $attrs);
    }

    public function testRejectsUnknownContext(): void
    {
        $this->assertStringContainsString('data-bl-context="page"', $this->h()->payloadAttributes(null, '"><script>'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL with `Class "Kanboard\Plugin\BattleLobby\Helper\LobbyHelper" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Kanboard\Plugin\BattleLobby\Helper;

use Kanboard\Core\Base;

/**
 * Presentation helpers for templates: priority tiers, storm due-date state,
 * and the data-bl-* payload external JS reads (CSP forbids inline script).
 */
class LobbyHelper extends Base
{
    private const LABELS = ['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY'];
    private const CONTEXTS = ['board', 'page', 'login'];

    /**
     * Map a task priority onto tiers 0..4 using the project's own range.
     * Ranges with ≤ 4 values map onto 0..3 (LEGENDARY needs a range wider than 4).
     */
    public function tier(array $task, array $project): int
    {
        $start = (int) ($project['priority_start'] ?? 0);
        $end = (int) ($project['priority_end'] ?? 3);
        if ($end <= $start) {
            return 0;
        }
        $priority = max($start, min($end, (int) ($task['priority'] ?? $start)));
        $span = $end - $start;
        $top = $span + 1 > 4 ? 4 : 3;
        return (int) round(($priority - $start) / $span * $top);
    }

    public function tierLabel(int $tier): string
    {
        return self::LABELS[max(0, min(4, $tier))];
    }

    /** @return array{state:string,label:string} */
    public function storm(int $dateDue, int $now): array
    {
        if ($dateDue <= 0) {
            return ['state' => 'none', 'label' => ''];
        }
        $delta = $dateDue - $now;
        if ($delta < 0) {
            $days = intdiv(-$delta, 86400);
            return ['state' => 'late', 'label' => $days > 0 ? $days . 'd late' : 'late'];
        }
        if ($delta < 86400) {
            $hours = intdiv($delta, 3600);
            return ['state' => 'soon', 'label' => $hours > 0 ? $hours . 'h' : '<1h'];
        }
        return ['state' => 'far', 'label' => intdiv($delta, 86400) . 'd'];
    }

    public function payloadAttributes(?int $userId, string $context): string
    {
        $context = in_array($context, self::CONTEXTS, true) ? $context : 'page';
        $attrs = [
            'context' => $context,
            'assets' => $this->helper->url->dir() . 'plugins/BattleLobby/Assets/',
            'readyup-url' => $this->helper->url->to('ReadyUpController', 'start', ['plugin' => 'BattleLobby']),
            'feed-url' => $this->helper->url->to('FeedController', 'recent', ['plugin' => 'BattleLobby']),
            'thanks-url' => $this->helper->url->to('FeedController', 'thanks', ['plugin' => 'BattleLobby']),
            'csrf' => $this->token->getReusableCSRFToken(),
        ];
        foreach ($this->container['battleLobbyOptions']->resolve($userId) as $key => $value) {
            $attrs[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $out = [];
        foreach ($attrs as $key => $value) {
            $out[] = sprintf('data-bl-%s="%s"', $key, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
        return implode(' ', $out);
    }

    public function isConflictActive(): bool
    {
        return class_exists('Kanboard\Plugin\ShadcnTheme\Plugin', false);
    }
}
```

In `Plugin.php` `initialize()`, after the options service:

```php
        $this->helper->register('battleLobby', \Kanboard\Plugin\BattleLobby\Helper\LobbyHelper::class);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS. If `url->to` with `plugin` fails in the unit container, check `tests/units/Base.php` for how sibling plugins (TimeReport `RowSummaryEndpointTest`) build plugin URLs, and match it.

- [ ] **Step 5: Commit**

```bash
git add Helper/LobbyHelper.php Plugin.php Test/LobbyHelperTest.php
git commit -m "feat: LobbyHelper tiers by project range, storm due state, data-bl payload"
```

---

### Task 4: Tokens, self-hosted fonts, token reskin, asset hooks, conflict banner

**Files:**
- Create: `BattleLobby/Assets/css/battle-lobby.css`, `BattleLobby/Assets/fonts/barlowcondensed/{BarlowCondensed-Black.woff2,BarlowCondensed-BlackItalic.woff2,OFL.txt}`
- Create: `BattleLobby/Template/layout/head.php`, `BattleLobby/Template/layout/conflict.php`
- Create (placeholder images, replaced in Task 11): `BattleLobby/Assets/backgrounds/{map,storm,island,victory}.webp`
- Modify: `BattleLobby/Plugin.php`
- Test: `BattleLobby/Test/AssetsTest.php`

**Interfaces:**
- Consumes: `$this->battleLobby->isConflictActive()` (Task 3).
- Produces: CSS custom properties `--bl-*` (listed below) and classes used by later tasks: `.bl-tier`, `.bl-storm`, `.bl-stage`, `.bl-char`, `.bl-overlay`, `.bl-toast`, `.bl-hero`, `.bl-cta`. Root attributes set by JS (Task 7): `html[data-bl-motion="off"]`, `html[data-bl-glass="0"]`, `html[data-bl-bg="<key>"]`.

- [ ] **Step 1: Fetch the fonts (Latin WOFF2) and the license**

```bash
cd BattleLobby && mkdir -p Assets/fonts/barlowcondensed
CSS=$(curl -s -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,900;1,900&display=swap")
# The latin block is the last @font-face of each style; take its src url.
URL_N=$(printf '%s' "$CSS" | awk '/font-style: normal/{f=1} f&&/\/\* latin \*\//{l=1} l&&/src:/{match($0,/https:[^)]+/);print substr($0,RSTART,RLENGTH);exit}')
URL_I=$(printf '%s' "$CSS" | awk '/font-style: italic/{f=1} f&&/\/\* latin \*\//{l=1} l&&/src:/{match($0,/https:[^)]+/);print substr($0,RSTART,RLENGTH);exit}')
curl -s -o Assets/fonts/barlowcondensed/BarlowCondensed-Black.woff2 "$URL_N"
curl -s -o Assets/fonts/barlowcondensed/BarlowCondensed-BlackItalic.woff2 "$URL_I"
curl -s -o Assets/fonts/barlowcondensed/OFL.txt https://raw.githubusercontent.com/google/fonts/main/ofl/barlowcondensed/OFL.txt
file Assets/fonts/barlowcondensed/*
```

Expected: two `Web Open Font Format (Version 2)` files of about 20–40 KB each, and `OFL.txt` beginning `Copyright 2017 The Barlow Project Authors`. This download happens at build time only; the plugin never calls Google at runtime.

- [ ] **Step 2: Placeholder backgrounds until Task 11 (these keep CSS and tests meaningful)**

```bash
python3 - <<'PY'
from PIL import Image
src = {'map': 'prototype/art/board-map.jpg', 'storm': 'prototype/art/header-storm.jpg',
       'island': 'prototype/art/loading-island.jpg', 'victory': 'prototype/art/victory-umbrella.jpg'}
import os; os.makedirs('Assets/backgrounds', exist_ok=True)
for k, p in src.items():
    im = Image.open(p).convert('RGB'); im.thumbnail((1920, 1920))
    im.save(f'Assets/backgrounds/{k}.webp', quality=78, method=6)
    print(k, os.path.getsize(f'Assets/backgrounds/{k}.webp'))
PY
```

Expected: four files, each under 400 KB.

- [ ] **Step 3: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Plugin;

class AssetsTest extends Base
{
    private function root(): string
    {
        return dirname(__DIR__);
    }

    public function testFontsAndLicenseShip(): void
    {
        foreach (['BarlowCondensed-Black.woff2', 'BarlowCondensed-BlackItalic.woff2', 'OFL.txt'] as $f) {
            $this->assertFileExists($this->root() . '/Assets/fonts/barlowcondensed/' . $f);
        }
        $this->assertSame('wOF2', substr(file_get_contents($this->root() . '/Assets/fonts/barlowcondensed/BarlowCondensed-Black.woff2'), 0, 4));
        $this->assertStringContainsString('SIL Open Font License', file_get_contents($this->root() . '/Assets/fonts/barlowcondensed/OFL.txt'));
    }

    public function testCssDeclaresSelfHostedFontAndTokens(): void
    {
        $css = file_get_contents($this->root() . '/Assets/css/battle-lobby.css');
        $this->assertStringContainsString("url('../fonts/barlowcondensed/BarlowCondensed-Black.woff2')", $css);
        $this->assertStringContainsString('font-display: swap', $css);
        foreach (['--bl-bg', '--bl-glass', '--bl-cta', '--bl-tier-4-in', '--bl-storm', '--bl-font-display'] as $token) {
            $this->assertStringContainsString($token . ':', $css);
        }
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('prefers-reduced-transparency', $css);
        $this->assertStringContainsString('@supports not (backdrop-filter', $css);
    }

    public function testCssKeepsTaskCardsOpaque(): void
    {
        $css = file_get_contents($this->root() . '/Assets/css/battle-lobby.css');
        $this->assertDoesNotMatchRegularExpression('/\.task-board[^{]*\{[^}]*backdrop-filter/s', $css, 'cards must never use backdrop-filter');
    }

    public function testBackgroundsWithinBudget(): void
    {
        foreach (['map', 'storm', 'island', 'victory'] as $k) {
            $f = $this->root() . "/Assets/backgrounds/$k.webp";
            $this->assertFileExists($f);
            $this->assertLessThanOrEqual(400 * 1024, filesize($f), "$k.webp over 400 KB");
        }
    }

    public function testAssetHooksRegistered(): void
    {
        (new Plugin($this->container))->initialize();
        $css = $this->container['hook']->getListeners('template:layout:css');
        $js = $this->container['hook']->getListeners('template:layout:js');
        $this->assertContains(['template' => 'plugins/BattleLobby/Assets/css/battle-lobby.css'], $css);
        $this->assertContains(['template' => 'plugins/BattleLobby/Assets/js/battle-lobby.js'], $js);
    }

    public function testHeadAndConflictTemplatesAreCspSafe(): void
    {
        foreach (['layout/head.php', 'layout/conflict.php'] as $t) {
            $src = file_get_contents($this->root() . '/Template/' . $t);
            $this->assertStringNotContainsString('<script', $src);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src);
        }
    }

    public function testConflictBannerRendersOnlyForAdminsWhenConflictActive(): void
    {
        $src = file_get_contents($this->root() . '/Template/layout/conflict.php');
        $this->assertStringContainsString('isConflictActive()', $src);
        $this->assertStringContainsString('isAdmin()', $src);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL (CSS file missing, hooks not registered, templates missing).

- [ ] **Step 5: Write the CSS (tokens, reskin, a11y fallbacks)**

`Assets/css/battle-lobby.css` (the base of this file; later tasks append clearly delimited sections):

```css
/* ===== Battle Lobby — tokens ===== */
@font-face { font-family: "BL Display"; src: url('../fonts/barlowcondensed/BarlowCondensed-Black.woff2') format('woff2'); font-weight: 900; font-style: normal; font-display: swap; }
@font-face { font-family: "BL Display"; src: url('../fonts/barlowcondensed/BarlowCondensed-BlackItalic.woff2') format('woff2'); font-weight: 900; font-style: italic; font-display: swap; }

:root {
  --bl-bg: #0d1421; --bl-surface: #1e223a; --bl-surface-2: #242948; --bl-card: #191e2e; --bl-line: #3a4258;
  --bl-tint: 0.78; --bl-blur: 12px;
  --bl-glass: rgba(16, 20, 35, var(--bl-tint));
  --bl-text: #f3f5fa; --bl-text-muted: #a8b0c4; --bl-text-on-cta: #0e1218;
  --bl-cta: #fcff00; --bl-cta-hover: #f7ff19; --bl-accent: #37d0ff;
  --bl-storm: #b04df9; --bl-health: #60d660; --bl-shield: #35b6ff; --bl-gold: #fbd444; --bl-danger: #ff4757;
  --bl-tier-0-in: #bebebe; --bl-tier-0-out: #646464; --bl-tier-0-edge: #b1b1b1;
  --bl-tier-1-in: #6abb1e; --bl-tier-1-out: #175117; --bl-tier-1-edge: #88e339;
  --bl-tier-2-in: #2cc0ff; --bl-tier-2-out: #143877; --bl-tier-2-edge: #37d0ff;
  --bl-tier-3-in: #c359ff; --bl-tier-3-out: #4c2483; --bl-tier-3-edge: #ea5eff;
  --bl-tier-4-in: #ea8d23; --bl-tier-4-out: #78371d; --bl-tier-4-edge: #e98d4b;
  --bl-font-display: "BL Display", "Arial Narrow", sans-serif;
  --bl-font-body: system-ui, "Noto Sans", "Segoe UI", sans-serif;
  --bl-skew: -6deg; --bl-radius: 4px;
  --bl-cta-clip: polygon(0% 16%, 100% 6%, 98% 100%, 2% 90%);
  --bl-ease: cubic-bezier(.2, .8, .2, 1); --bl-dur: 140ms;
}
html[data-bl-glass="0"] { --bl-glass: var(--bl-surface); }
@supports not (backdrop-filter: blur(1px)) { :root { --bl-glass: var(--bl-surface); } }
@media (prefers-reduced-transparency: reduce) { :root { --bl-glass: var(--bl-surface); --bl-blur: 0px; } }

/* ===== Reskin (all pages) ===== */
body { background: var(--bl-bg); color: var(--bl-text); font-family: var(--bl-font-body); }
a { color: var(--bl-accent); }
h1, h2, h3, .page-header h2, .title-container, .board-column-title {
  font-family: var(--bl-font-display); font-weight: 900; font-style: italic; text-transform: uppercase; letter-spacing: .01em;
}
header { background: var(--bl-glass); border-bottom: 1px solid rgba(255,255,255,.08); }
html:not([data-bl-glass="0"]) header { -webkit-backdrop-filter: blur(var(--bl-blur)); backdrop-filter: blur(var(--bl-blur)); }
.sidebar, .panel, .table-list, table.table-striped, .dropdown-submenu-open, #modal-box, .page-header, .views {
  background: var(--bl-surface); color: var(--bl-text); border-color: var(--bl-line);
}
.table-list-row, table.table-striped tr:nth-child(odd) td { background: var(--bl-card); }
input[type=text], input[type=password], input[type=email], input[type=number], textarea, select {
  background: var(--bl-surface-2); color: var(--bl-text); border: 1px solid var(--bl-line); border-radius: var(--bl-radius);
}
.btn { background: var(--bl-surface-2); color: var(--bl-text); border: 1px solid var(--bl-line); }
.btn-blue, .btn-primary, input[type=submit].btn-blue {
  background: var(--bl-cta); color: var(--bl-text-on-cta); border: 0; font-family: var(--bl-font-display);
  font-weight: 900; font-style: italic; text-transform: uppercase; clip-path: var(--bl-cta-clip);
}
.btn-blue:hover, .btn-primary:hover { background: var(--bl-cta-hover); }
.alert { background: var(--bl-surface-2); color: var(--bl-text); border-color: var(--bl-line); }

/* Admin conflict banner */
.bl-conflict { background: var(--bl-danger); color: #fff; padding: 8px 16px; font-weight: 700; text-align: center; }

/* ===== Accessibility: motion ===== */
html[data-bl-motion="off"] *, html[data-bl-motion="off"] *::before, html[data-bl-motion="off"] *::after { animation: none !important; transition: none !important; }
@media (prefers-reduced-motion: reduce) {
  html:not([data-bl-motion="on"]) *, html:not([data-bl-motion="on"]) *::before, html:not([data-bl-motion="on"]) *::after { animation: none !important; transition: none !important; }
}
```

`motion=on` deliberately overrides the OS preference only when the user chose it explicitly. JS (Task 7) sets `data-bl-motion` to the resolved value: `auto` stays `auto`, so the media query applies.

- [ ] **Step 6: Write the head and conflict templates and register the hooks**

`Template/layout/head.php`:

```php
<link rel="preload" href="<?= $this->url->dir() ?>plugins/BattleLobby/Assets/fonts/barlowcondensed/BarlowCondensed-BlackItalic.woff2" as="font" type="font/woff2" crossorigin>
```

`Template/layout/conflict.php`:

```php
<?php if ($this->battleLobby->isConflictActive() && $this->user->isAdmin()): ?>
<div class="bl-conflict" role="alert"><?= t('Battle Lobby and ShadcnTheme are both active. They restyle the same pages: disable one of them.') ?></div>
<?php endif ?>
```

In `Plugin.php` `initialize()`, append:

```php
        $this->hook->on('template:layout:css', ['template' => 'plugins/BattleLobby/Assets/css/battle-lobby.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/BattleLobby/Assets/js/battle-lobby.js']);
        $this->template->hook->attach('template:layout:head', 'BattleLobby:layout/head');
        $this->template->hook->attach('template:layout:top', 'BattleLobby:layout/conflict');
```

Create an empty `Assets/js/battle-lobby.js` containing only `'use strict';` (Task 7 fills it in), so the registered asset exists.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS. If `getListeners` doesn't exist on the hook object, use `$this->container['hook']->getListeners(...)` exactly as TimeReport's tests do (`grep -rn getListeners ../TimeReport/Test`), and adjust.

- [ ] **Step 8: Commit**

```bash
git add Assets Template/layout/head.php Template/layout/conflict.php Plugin.php Test/AssetsTest.php
git commit -m "feat: tokens, self-hosted Barlow Condensed, token reskin, a11y fallbacks, conflict banner"
```

---

### Task 5: Options pages — admin defaults and per-user overrides

**Files:**
- Create: `BattleLobby/Controller/OptionsController.php`
- Create: `BattleLobby/Template/config/sidebar.php`, `BattleLobby/Template/config/options.php`, `BattleLobby/Template/user/sidebar.php`, `BattleLobby/Template/user/options.php`, `BattleLobby/Template/options/_fields.php`
- Modify: `BattleLobby/Plugin.php` (routes + sidebar hooks), `BattleLobby/Assets/css/battle-lobby.css` (options section)
- Test: `BattleLobby/Test/OptionsControllerTest.php`

**Interfaces:**
- Consumes: `OptionsModel::schema()`, `saveAdmin()`, `saveUser()`, `resetUser()`, `adminValues()`, `resolve()` (Task 2).
- Produces: routes `battlelobby/config` (GET `show`, admin), `battlelobby/config/save` (POST `save`), `battlelobby/user/:user_id` (GET `user`), `battlelobby/user/:user_id/save` (POST `userSave`), `battlelobby/user/:user_id/reset` (POST `userReset`). Form field names equal the option keys.

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Controller\OptionsController;
use Kanboard\Plugin\BattleLobby\Model\OptionsModel;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Model\UserModel;

class OptionsControllerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['battleLobbyOptions'] = fn ($c) => new OptionsModel($c);
    }

    private function asUser(int $id, string $role): void
    {
        $this->container['sessionStorage']->user = ['id' => $id, 'role' => $role, 'username' => 'u' . $id];
    }

    public function testAdminSaveRequiresAdmin(): void
    {
        $uid = (new UserModel($this->container))->create(['username' => 'plain']);
        $this->asUser($uid, 'app-user');
        $this->expectException(AccessForbiddenException::class);
        (new OptionsController($this->container))->show();
    }

    public function testUserCannotEditAnotherUsersOptions(): void
    {
        $users = new UserModel($this->container);
        $a = $users->create(['username' => 'a']);
        $b = $users->create(['username' => 'b']);
        $this->asUser($a, 'app-user');
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], ['user_id' => $b]);
        $this->expectException(AccessForbiddenException::class);
        (new OptionsController($this->container))->user();
    }

    public function testFieldsTemplateCoversEverySchemaKey(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Template/options/_fields.php');
        foreach (array_keys((new OptionsModel($this->container))->schema()) as $key) {
            $this->assertStringContainsString("'$key'", $src, "form missing option '$key'");
        }
        $this->assertStringContainsString('0.75', $src, 'tint warning threshold');
    }

    public function testTemplatesAreCspSafeAndCsrfProtected(): void
    {
        foreach (['config/options.php', 'user/options.php'] as $t) {
            $src = file_get_contents(dirname(__DIR__) . '/Template/' . $t);
            $this->assertStringContainsString('form->csrf()', $src, "$t must carry CSRF");
            $this->assertStringNotContainsString('<script', $src);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL with `Class "…\Controller\OptionsController" not found`.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace Kanboard\Plugin\BattleLobby\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

/**
 * Admin defaults (Settings → Battle Lobby) and per-user overrides (profile → Battle Lobby).
 */
class OptionsController extends BaseController
{
    public function show(): void
    {
        $this->requireAdmin();
        $this->response->html($this->helper->layout->config('BattleLobby:config/options', [
            'title' => t('Settings') . ' &gt; ' . t('Battle Lobby'),
            'values' => array_replace($this->options()->defaults(), $this->options()->adminValues()),
            'schema' => $this->options()->schema(),
        ]));
    }

    public function save(): void
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->options()->saveAdmin($this->checkboxes($this->request->getValues()))
            ? $this->flash->success(t('Settings saved successfully.'))
            : $this->flash->failure(t('Unable to save your settings.'));
        $this->response->redirect($this->helper->url->to('OptionsController', 'show', ['plugin' => 'BattleLobby']));
    }

    public function user(): void
    {
        $user = $this->getOwnUser();
        $this->response->html($this->helper->layout->user('BattleLobby:user/options', [
            'title' => t('Battle Lobby'),
            'user' => $user,
            'values' => $this->options()->resolve((int) $user['id']),
            'schema' => $this->options()->schema(),
        ]));
    }

    public function userSave(): void
    {
        $user = $this->getOwnUser();
        $this->checkCSRFForm();
        $this->options()->saveUser((int) $user['id'], $this->checkboxes($this->request->getValues()))
            ? $this->flash->success(t('Settings saved successfully.'))
            : $this->flash->failure(t('Unable to save your settings.'));
        $this->response->redirect($this->helper->url->to('OptionsController', 'user', ['plugin' => 'BattleLobby', 'user_id' => $user['id']]));
    }

    public function userReset(): void
    {
        $user = $this->getOwnUser();
        $this->checkCSRFForm();
        $this->options()->resetUser((int) $user['id']);
        $this->flash->success(t('Your Battle Lobby options now follow the admin defaults.'));
        $this->response->redirect($this->helper->url->to('OptionsController', 'user', ['plugin' => 'BattleLobby', 'user_id' => $user['id']]));
    }

    private function options()
    {
        return $this->container['battleLobbyOptions'];
    }

    private function requireAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    /** Users edit only their own options; admins may edit anyone's. */
    private function getOwnUser(): array
    {
        $user = $this->getUser();
        if ((int) $user['id'] !== (int) $this->userSession->getId() && ! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
        return $user;
    }

    /** Unchecked checkboxes are absent from POST: write them as false explicitly. */
    private function checkboxes(array $values): array
    {
        foreach ($this->options()->schema() as $key => $s) {
            if ($s['type'] === 'bool' && ! array_key_exists($key, $values)) {
                $values[$key] = false;
            }
        }
        return $values;
    }
}
```

- [ ] **Step 4: Write the templates**

`Template/options/_fields.php` (shared by both pages; `$values` and `$schema` in scope):

```php
<?php
$labels = [
    'background' => t('Background'), 'characters' => t('Character set'), 'placement' => t('Character placement'),
    'motion' => t('Animations'), 'glass' => t('Frosted columns'), 'blur' => t('Blur strength (px)'),
    'tint' => t('Panel tint (0.60–0.95)'), 'loading' => t('Loading screen'), 'victory' => t('Victory screen'),
    'storm' => t('Storm due dates'), 'readyup' => t('READY UP button'), 'feed' => t('Activity feed toasts'),
    'thanks' => t('Thanks button on finished tasks'),
];
$choices = [
    'background' => ['map' => t('Island map'), 'storm' => t('Storm'), 'island' => t('Drop'), 'none' => t('None')],
    'characters' => ['mixed' => t('Mixed squad'), 'brawl' => t('Brawl'), 'wild' => t('Wildlands'), 'night' => t('Nightfall'), 'none' => t('None')],
    'placement' => ['auto' => t('Automatic'), 'lineup' => t('Lineup'), 'mascot' => t('Mascot'), 'off' => t('Off')],
    'motion' => ['auto' => t('Follow system setting'), 'on' => t('On'), 'off' => t('Off')],
];
?>
<fieldset class="bl-options">
<?php foreach (['background', 'characters', 'placement', 'motion'] as $key): ?>
    <?= $this->form->label($labels[$key], $key) ?>
    <?= $this->form->select($key, $choices[$key], $values) ?>
<?php endforeach ?>
<?= $this->form->label($labels['blur'], 'blur') ?>
<?= $this->form->number('blur', $values, [], ['min="0"', 'max="24"', 'step="1"']) ?>
<?= $this->form->label($labels['tint'], 'tint') ?>
<?= $this->form->text('tint', $values, [], ['inputmode="decimal"']) ?>
<p class="form-help"><?= t('Below 0.75 the background art starts to fight the text. Blur alone does not fix contrast.') ?></p>
<?php foreach (['glass', 'loading', 'victory', 'storm', 'readyup', 'feed', 'thanks'] as $key): ?>
    <?= $this->form->checkbox($key, $labels[$key], 1, (bool) $values[$key]) ?>
<?php endforeach ?>
</fieldset>
```

`Template/config/options.php`:

```php
<div class="page-header"><h2><?= t('Battle Lobby') ?></h2></div>
<p class="form-help"><?= t('Defaults for everyone on this instance. Each user can override them in their profile.') ?></p>
<form method="post" action="<?= $this->url->href('OptionsController', 'save', ['plugin' => 'BattleLobby']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->render('BattleLobby:options/_fields', ['values' => $values, 'schema' => $schema]) ?>
    <div class="form-actions"><button type="submit" class="btn btn-blue"><?= t('Save') ?></button></div>
</form>
<p class="form-help"><?= t('Fan-made theme. Not affiliated with or endorsed by any game publisher.') ?></p>
```

`Template/user/options.php`:

```php
<div class="page-header"><h2><?= t('Battle Lobby') ?></h2></div>
<form method="post" action="<?= $this->url->href('OptionsController', 'userSave', ['plugin' => 'BattleLobby', 'user_id' => $user['id']]) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->render('BattleLobby:options/_fields', ['values' => $values, 'schema' => $schema]) ?>
    <div class="form-actions"><button type="submit" class="btn btn-blue"><?= t('Save') ?></button></div>
</form>
<form method="post" action="<?= $this->url->href('OptionsController', 'userReset', ['plugin' => 'BattleLobby', 'user_id' => $user['id']]) ?>">
    <?= $this->form->csrf() ?>
    <button type="submit" class="btn"><?= t('Use admin defaults') ?></button>
</form>
```

`Template/config/sidebar.php`:

```php
<li <?= $this->app->checkMenuSelection('OptionsController', 'show', 'BattleLobby') ?>>
    <?= $this->url->link(t('Battle Lobby'), 'OptionsController', 'show', ['plugin' => 'BattleLobby']) ?>
</li>
```

`Template/user/sidebar.php`:

```php
<li <?= $this->app->checkMenuSelection('OptionsController', 'user', 'BattleLobby') ?>>
    <?= $this->url->link(t('Battle Lobby'), 'OptionsController', 'user', ['plugin' => 'BattleLobby', 'user_id' => $user['id']]) ?>
</li>
```

In `Plugin.php` `initialize()`, append:

```php
        $this->route->addRoute('battlelobby/config', 'OptionsController', 'show', 'BattleLobby');
        $this->route->addRoute('battlelobby/config/save', 'OptionsController', 'save', 'BattleLobby');
        $this->route->addRoute('battlelobby/user/:user_id', 'OptionsController', 'user', 'BattleLobby');
        $this->route->addRoute('battlelobby/user/:user_id/save', 'OptionsController', 'userSave', 'BattleLobby');
        $this->route->addRoute('battlelobby/user/:user_id/reset', 'OptionsController', 'userReset', 'BattleLobby');
        $this->template->hook->attach('template:config:sidebar', 'BattleLobby:config/sidebar');
        $this->template->hook->attach('template:user:sidebar:actions', 'BattleLobby:user/sidebar');
```

Append to the CSS:

```css
/* ===== Options pages ===== */
.bl-options label { display: block; margin-top: 10px; font-weight: 600; }
.bl-options select, .bl-options input[type=number], .bl-options input[type=text] { min-width: 220px; }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS. If constructing `Request` with params differs in 1.2.47, mirror how `../TimeReport/Test/TimeReportControllerTest.php` injects request values.

- [ ] **Step 6: Commit**

```bash
git add Controller/OptionsController.php Template/config Template/user Template/options Plugin.php Assets/css/battle-lobby.css Test/OptionsControllerTest.php
git commit -m "feat: admin default and per-user Battle Lobby options pages"
```

---

### Task 6: Board — tier labels, card frames, storm chips, skewed view tabs, glass columns, stage mount

**Files:**
- Create: `BattleLobby/Template/board/tier.php`, `BattleLobby/Template/board/storm.php`, `BattleLobby/Template/layout/stage.php`
- Modify: `BattleLobby/Plugin.php`, `BattleLobby/Assets/css/battle-lobby.css` (board section)
- Test: `BattleLobby/Test/BoardTemplatesTest.php`

**Interfaces:**
- Consumes: `$this->battleLobby->tier()`, `tierLabel()`, `storm()`, `payloadAttributes()` (Task 3); `$container['battleLobbyOptions']->resolve()` (Task 2).
- Produces: markup consumed by Task 7's JS:
  - `<span class="bl-tier" data-tier="N">LABEL</span>` before each card title
  - `<span class="bl-storm bl-storm-{state}">` in each card footer (omitted when `storm=false` or `state=none`)
  - `<div id="bl-stage" class="bl-stage" data-bl-…></div>` once per page from `template:layout:bottom`

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Plugin;
use Kanboard\Plugin\BattleLobby\Model\OptionsModel;

class BoardTemplatesTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
    }

    private function render(string $tpl, array $params): string
    {
        return $this->container['template']->render($tpl, $params);
    }

    public function testTierLabelUsesProjectRange(): void
    {
        $html = $this->render('BattleLobby:board/tier', [
            'task' => ['priority' => 5, 'project_id' => 1],
            'project' => ['priority_start' => 1, 'priority_end' => 5],
        ]);
        $this->assertStringContainsString('class="bl-tier"', $html);
        $this->assertStringContainsString('data-tier="4"', $html);
        $this->assertStringContainsString('LEGENDARY', $html);
    }

    public function testTierFallsBackToProjectLookupWhenNotPassed(): void
    {
        $pid = $this->container['projectModel']->create(['name' => 'p']);
        $html = $this->render('BattleLobby:board/tier', ['task' => ['priority' => 3, 'project_id' => $pid]]);
        $this->assertStringContainsString('data-tier="3"', $html);
        $this->assertStringContainsString('EPIC', $html);
    }

    public function testStormChipStatesAndToggle(): void
    {
        $soon = $this->render('BattleLobby:board/storm', ['task' => ['date_due' => time() + 3600 * 5]]);
        $this->assertStringContainsString('bl-storm-soon', $soon);
        $this->assertStringContainsString('aria-label=', $soon);
        $this->assertSame('', trim($this->render('BattleLobby:board/storm', ['task' => ['date_due' => 0]])));

        (new OptionsModel($this->container))->saveAdmin(['storm' => false]);
        $this->assertSame('', trim($this->render('BattleLobby:board/storm', ['task' => ['date_due' => time() + 600]])));
    }

    public function testStageCarriesPayloadAndIsCspSafe(): void
    {
        $html = $this->render('BattleLobby:layout/stage', []);
        $this->assertStringContainsString('id="bl-stage"', $html);
        $this->assertStringContainsString('data-bl-characters=', $html);
        foreach (['board/tier.php', 'board/storm.php', 'layout/stage.php'] as $t) {
            $src = file_get_contents(dirname(__DIR__) . '/Template/' . $t);
            $this->assertStringNotContainsString('<script', $src);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src);
        }
    }

    public function testCssFramesCardsByTierAndKeepsThemOpaque(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/Assets/css/battle-lobby.css');
        for ($t = 0; $t <= 4; $t++) {
            $this->assertStringContainsString('.task-board:has(.bl-tier[data-tier="' . $t . '"])', $css);
        }
        $this->assertStringContainsString('.bl-storm-soon', $css);
        $this->assertStringContainsString('.views', $css);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL (templates not found).

- [ ] **Step 3: Write the templates**

First add two template-facing passthroughs to `Helper/LobbyHelper.php`. Helpers are singletons, so the instance cache holds for the whole board render:

```php
    /** @var array<int,array> */
    private array $projects = [];

    /** Project row, cached per request (the board hook passes only $task). */
    public function project(int $projectId): array
    {
        return $this->projects[$projectId] ??= ($projectId ? ($this->projectModel->getById($projectId) ?: []) : []);
    }

    /** Resolved options for templates. */
    public function options(?int $userId): array
    {
        return $this->container['battleLobbyOptions']->resolve($userId);
    }
```

Append to `Test/LobbyHelperTest.php`:

```php
    public function testProjectIsCachedAndOptionsPassThrough(): void
    {
        $pid = $this->container['projectModel']->create(['name' => 'cache']);
        $h = $this->h();
        $this->assertSame('cache', $h->project($pid)['name']);
        $this->container['projectModel']->update(['id' => $pid, 'name' => 'renamed']);
        $this->assertSame('cache', $h->project($pid)['name']);
        $this->assertSame([], $h->project(0));
        $this->assertSame('mixed', $h->options(null)['characters']);
    }
```

`Template/board/tier.php` (the core hook passes only `$task`):

```php
<?php
$project ??= $this->battleLobby->project((int) ($task['project_id'] ?? 0));
$tier = $this->battleLobby->tier($task, $project);
?>
<span class="bl-tier" data-tier="<?= $tier ?>"><?= $this->battleLobby->tierLabel($tier) ?></span>
```

`Template/board/storm.php`:

```php
<?php
$opts = $this->battleLobby->options($this->user->getId() ?: null);
$storm = $this->battleLobby->storm((int) ($task['date_due'] ?? 0), time());
?>
<?php if ($opts['storm'] && $storm['state'] !== 'none'): ?>
<span class="bl-storm bl-storm-<?= $storm['state'] ?>" aria-label="<?= $this->text->e(t('Due') . ': ' . $storm['label']) ?>">
    <i class="fa <?= $storm['state'] === 'far' ? 'fa-clock-o' : ($storm['state'] === 'soon' ? 'fa-bolt' : 'fa-exclamation-triangle') ?>" aria-hidden="true"></i>
    <?= $this->text->e($storm['label']) ?>
</span>
<?php endif ?>
```

`Template/layout/stage.php`:

```php
<div id="bl-stage" class="bl-stage" aria-hidden="true" <?= $this->battleLobby->payloadAttributes($this->user->getId() ?: null, 'page') ?>></div>
```

(JS upgrades the context to `board` when `#board-container` exists. The PHP side cannot know the page in a layout hook.)

In `Plugin.php` `initialize()`, append:

```php
        $this->template->hook->attach('template:board:private:task:before-title', 'BattleLobby:board/tier');
        $this->template->hook->attach('template:board:public:task:before-title', 'BattleLobby:board/tier');
        $this->template->hook->attach('template:board:task:footer', 'BattleLobby:board/storm');
        $this->template->hook->attach('template:layout:bottom', 'BattleLobby:layout/stage');
```

- [ ] **Step 4: Append the board CSS**

```css
/* ===== Board ===== */
html[data-bl-bg] body { background: var(--bl-bg) var(--bl-bg-image, none) center / cover fixed no-repeat; }
.views { display: flex; gap: 4px; background: transparent; border: 0; }
.views li { transform: skew(var(--bl-skew)); background: rgba(0,0,0,.35); border: 0; }
.views li > * { display: inline-block; transform: skew(calc(var(--bl-skew) * -1)); font-family: var(--bl-font-display); font-weight: 900; font-style: italic; text-transform: uppercase; }
.views li.active { background: var(--bl-cta); }
.views li.active a, .views li.active { color: var(--bl-text-on-cta); }

#board th.board-column-header, #board td.board-column-expanded, #board td .board-task-list {
  background: var(--bl-glass); color: var(--bl-text); border-color: rgba(255,255,255,.08);
}
html:not([data-bl-glass="0"]) #board th.board-column-header,
html:not([data-bl-glass="0"]) #board td.board-column-expanded {
  -webkit-backdrop-filter: blur(var(--bl-blur)) saturate(1.2); backdrop-filter: blur(var(--bl-blur)) saturate(1.2);
}
.board-column-title { font-size: 1.3em; }

/* Cards: always opaque, shop-card frame by tier */
.task-board { background: var(--bl-card) !important; color: var(--bl-text); border: 0; border-radius: var(--bl-radius); overflow: hidden;
  transform: skew(var(--bl-skew)); transition: transform var(--bl-dur) var(--bl-ease); }
.task-board > * { transform: skew(calc(var(--bl-skew) * -1)); }
.task-board:hover { transform: skew(var(--bl-skew)) translateY(-3px); }
.task-board:has(.bl-tier[data-tier="0"]) { box-shadow: inset 0 -6px 0 var(--bl-tier-0-edge); }
.task-board:has(.bl-tier[data-tier="1"]) { box-shadow: inset 0 -6px 0 var(--bl-tier-1-edge); }
.task-board:has(.bl-tier[data-tier="2"]) { box-shadow: inset 0 -6px 0 var(--bl-tier-2-edge); }
.task-board:has(.bl-tier[data-tier="3"]) { box-shadow: inset 0 -6px 0 var(--bl-tier-3-edge); }
.task-board:has(.bl-tier[data-tier="4"]) { box-shadow: inset 0 -6px 0 var(--bl-tier-4-edge), 0 0 0 2px var(--bl-tier-4-edge); animation: bl-legend 2.4s ease-in-out infinite; }
@keyframes bl-legend { 50% { box-shadow: inset 0 -6px 0 var(--bl-tier-4-edge), 0 0 0 2px var(--bl-tier-4-edge), 0 0 18px rgba(234,141,35,.5); } }
.bl-tier { display: block; margin: -4px -6px 4px; padding: 10px 8px 4px; font: 900 11px/1 var(--bl-font-display); letter-spacing: .08em; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.6); }
.bl-tier[data-tier="0"] { background: radial-gradient(circle at 50% 30%, var(--bl-tier-0-in), var(--bl-tier-0-out)); }
.bl-tier[data-tier="1"] { background: radial-gradient(circle at 50% 30%, var(--bl-tier-1-in), var(--bl-tier-1-out)); }
.bl-tier[data-tier="2"] { background: radial-gradient(circle at 50% 30%, var(--bl-tier-2-in), var(--bl-tier-2-out)); }
.bl-tier[data-tier="3"] { background: radial-gradient(circle at 50% 30%, var(--bl-tier-3-in), var(--bl-tier-3-out)); }
.bl-tier[data-tier="4"] { background: radial-gradient(circle at 50% 30%, var(--bl-tier-4-in), var(--bl-tier-4-out)); }
.task-board-title, .task-board-title a { font-family: var(--bl-font-display); font-weight: 900; font-style: italic; text-transform: uppercase; color: var(--bl-text); }

.bl-storm { display: inline-flex; gap: 4px; align-items: center; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 30px; }
.bl-storm-far { background: rgba(255,255,255,.1); color: var(--bl-text-muted); }
.bl-storm-soon { background: rgba(176,77,249,.25); color: #e2b8ff; animation: bl-pulse 2s ease-in-out infinite; }
.bl-storm-late { background: rgba(255,71,87,.25); color: #ff9aa5; }
@keyframes bl-pulse { 50% { box-shadow: 0 0 0 4px rgba(176,77,249,.25); } }
```

The legendary pulse runs at 2.4s per cycle, and storm pulse at 2s: both under 1 Hz.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add Template/board Template/layout/stage.php Plugin.php Assets/css/battle-lobby.css Test/BoardTemplatesTest.php
git commit -m "feat: board tier frames, storm chips, skewed view tabs, glass columns, stage mount"
```

---

### Task 7: Front-end core — payload, background, lineup, parallax, victory, loading screen

**Files:**
- Modify: `BattleLobby/Assets/js/battle-lobby.js`
- Modify: `BattleLobby/Assets/css/battle-lobby.css` (stage and overlays section)
- Create: `BattleLobby/Assets/characters/manifest.json` (initial version listing the current idle cutouts; Task 11 finalises it)
- Test: `BattleLobby/Test/js/battle-lobby.test.cjs`

**Interfaces:**
- Consumes: `#bl-stage[data-bl-*]` (Task 6); `.bl-hero[data-bl-*]` (Task 10, same attribute names); `Assets/characters/manifest.json`; `Assets/backgrounds/<key>.webp`.
- Produces, exported for tests via `module.exports` (pure functions, no DOM):
  - `readPayload(dataset: DOMStringMap): Payload`, which converts `data-bl-*` into typed `{context, assets, csrf, urls:{readyup,feed,thanks}, opts:{background, characters, placement, motion, glass:boolean, blur:number, tint:number, loading, victory, storm, readyup, feed, thanks}}`
  - `placementFor(context: 'board'|'page'|'login', placement: string): 'lineup'|'mascot'|'off'`. `auto` gives board → lineup, login → mascot, page → off.
  - `squadFor(manifest: Manifest, set: string): string[]` returns up to 4 slugs, lead first; `none` returns `[]`
  - `lineupOrder(slugs: string[]): Array<{slug:string, role:'back'|'side'|'lead'}>` returns the order `[c?, a?, lead, b?]`
  - `snapshotDone(columns: Array<{swimlane:string, isLast:boolean, taskIds:string[]}>): Set<string>`, the set of task ids in last columns
  - `newArrivals(before: Set<string>, after: Set<string>): string[]`
  - `motionAllowed(opt: string, prefersReduced: boolean): boolean`

- [ ] **Step 1: Write the failing node test**

`Test/js/battle-lobby.test.cjs`:

```js
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const bl = require('../../Assets/js/battle-lobby.js');

const manifest = {
  characters: { a: { set: 'brawl' }, b: { set: 'brawl' }, c: { set: 'night' }, d: { set: 'wild' }, e: { set: 'brawl' }, f: { set: 'brawl' }, g: { set: 'brawl' } },
  sets: { mixed: ['d', 'a', 'c'], brawl: ['a', 'b', 'e', 'f', 'g'], night: ['c'], wild: ['d'] },
};

test('readPayload types booleans and numbers', () => {
  const p = bl.readPayload({ blContext: 'board', blAssets: '/a/', blCsrf: 't', blReadyupUrl: '/r', blFeedUrl: '/f', blThanksUrl: '/t',
    blBackground: 'map', blCharacters: 'night', blPlacement: 'auto', blMotion: 'auto', blGlass: '0', blBlur: '8', blTint: '0.8',
    blLoading: '1', blVictory: '1', blStorm: '1', blReadyup: '1', blFeed: '0', blThanks: '0' });
  assert.equal(p.context, 'board');
  assert.equal(p.opts.glass, false);
  assert.equal(p.opts.blur, 8);
  assert.equal(p.opts.tint, 0.8);
  assert.equal(p.urls.readyup, '/r');
});

test('placementFor applies auto rules per context', () => {
  assert.equal(bl.placementFor('board', 'auto'), 'lineup');
  assert.equal(bl.placementFor('login', 'auto'), 'mascot');
  assert.equal(bl.placementFor('page', 'auto'), 'off');
  assert.equal(bl.placementFor('page', 'mascot'), 'mascot');
  assert.equal(bl.placementFor('board', 'off'), 'off');
});

test('squadFor caps at four, keeps order, handles none and unknown', () => {
  assert.deepEqual(bl.squadFor(manifest, 'brawl'), ['a', 'b', 'e', 'f']);
  assert.deepEqual(bl.squadFor(manifest, 'none'), []);
  assert.deepEqual(bl.squadFor(manifest, 'nope'), []);
});

test('lineupOrder puts the lead in the middle with flankers', () => {
  assert.deepEqual(bl.lineupOrder(['L', 'A', 'B', 'C']).map(x => x.slug + ':' + x.role), ['C:back', 'A:side', 'L:lead', 'B:side']);
  assert.deepEqual(bl.lineupOrder(['L']).map(x => x.role), ['lead']);
  assert.deepEqual(bl.lineupOrder([]), []);
});

test('victory: only cards newly arriving in a last column, across swimlanes', () => {
  const before = bl.snapshotDone([
    { swimlane: '1', isLast: false, taskIds: ['1', '2'] }, { swimlane: '1', isLast: true, taskIds: ['9'] },
    { swimlane: '2', isLast: true, taskIds: [] },
  ]);
  const after = bl.snapshotDone([
    { swimlane: '1', isLast: false, taskIds: ['2'] }, { swimlane: '1', isLast: true, taskIds: ['9', '1'] },
    { swimlane: '2', isLast: true, taskIds: ['5'] },
  ]);
  assert.deepEqual(bl.newArrivals(before, after).sort(), ['1', '5']);
  assert.deepEqual(bl.newArrivals(after, after), []);
});

test('motionAllowed honours explicit choice over OS preference', () => {
  assert.equal(bl.motionAllowed('auto', true), false);
  assert.equal(bl.motionAllowed('auto', false), true);
  assert.equal(bl.motionAllowed('on', true), true);
  assert.equal(bl.motionAllowed('off', false), false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd BattleLobby && node --test Test/js/`
Expected: FAIL with `bl.readPayload is not a function`.

- [ ] **Step 3: Write the initial manifest**

`Assets/characters/manifest.json` (Task 11 adds `seed` and `prompt_id` per character and the `-victory` files):

```json
{
  "version": 1,
  "characters": {
    "saffron": { "name": "Saffron", "set": "brawl" },
    "sol-grande": { "name": "Sol Grande", "set": "brawl" },
    "copperjab": { "name": "Copperjab", "set": "brawl" },
    "glyph": { "name": "Glyph", "set": "brawl" },
    "tundra": { "name": "Tundra", "set": "wild" },
    "ironbraid": { "name": "Ironbraid", "set": "wild" },
    "tabby-vance": { "name": "Tabby Vance", "set": "wild" },
    "mikan": { "name": "Mikan", "set": "wild" },
    "velvet": { "name": "Velvet", "set": "night" },
    "ossian": { "name": "Ossian", "set": "night" },
    "moonfang": { "name": "Moonfang", "set": "night" },
    "lanternbone": { "name": "Lanternbone", "set": "night" }
  },
  "sets": {
    "mixed": ["tabby-vance", "saffron", "tundra", "velvet"],
    "brawl": ["sol-grande", "saffron", "glyph", "copperjab"],
    "wild": ["tabby-vance", "tundra", "mikan", "ironbraid"],
    "night": ["moonfang", "velvet", "ossian", "lanternbone"]
  }
}
```

Copy the current idle cutouts so the stage has real images before Task 11:

```bash
mkdir -p Assets/characters
for s in saffron sol-grande copperjab glyph tundra ironbraid tabby-vance mikan velvet ossian moonfang lanternbone; do
  cp "prototype/art/characters/$s.webp" "Assets/characters/$s-idle.webp"
  cp "prototype/art/characters/$s.webp" "Assets/characters/$s-victory.webp"   # temporary: Task 11 replaces with real victory poses
done
```

- [ ] **Step 4: Write the JS**

`Assets/js/battle-lobby.js`:

```js
'use strict';
/* Battle Lobby — pure helpers (exported for node tests) + DOM wiring (browser only). CSP-safe: no eval, no inline. */
(function (root) {
  var BOOL = ['glass', 'loading', 'victory', 'storm', 'readyup', 'feed', 'thanks'];
  var NUM = ['blur', 'tint'];
  var ENUM = ['background', 'characters', 'placement', 'motion'];

  function readPayload(ds) {
    var opts = {};
    ENUM.forEach(function (k) { opts[k] = ds['bl' + k[0].toUpperCase() + k.slice(1)] || ''; });
    BOOL.forEach(function (k) { opts[k] = ds['bl' + k[0].toUpperCase() + k.slice(1)] === '1'; });
    NUM.forEach(function (k) { opts[k] = Number(ds['bl' + k[0].toUpperCase() + k.slice(1)] || 0); });
    return {
      context: ds.blContext || 'page', assets: ds.blAssets || '', csrf: ds.blCsrf || '',
      urls: { readyup: ds.blReadyupUrl || '', feed: ds.blFeedUrl || '', thanks: ds.blThanksUrl || '' },
      opts: opts,
    };
  }

  function placementFor(context, placement) {
    if (placement && placement !== 'auto') return placement;
    return { board: 'lineup', login: 'mascot' }[context] || 'off';
  }

  function squadFor(manifest, set) {
    var ids = (manifest && manifest.sets && manifest.sets[set]) || [];
    return ids.slice(0, 4);
  }

  function lineupOrder(slugs) {
    var lead = slugs[0], a = slugs[1], b = slugs[2], c = slugs[3];
    return [[c, 'back'], [a, 'side'], [lead, 'lead'], [b, 'side']]
      .filter(function (x) { return x[0]; })
      .map(function (x) { return { slug: x[0], role: x[1] }; });
  }

  function snapshotDone(columns) {
    var s = new Set();
    columns.forEach(function (c) { if (c.isLast) c.taskIds.forEach(function (id) { s.add(String(id)); }); });
    return s;
  }

  function newArrivals(before, after) {
    var out = [];
    after.forEach(function (id) { if (!before.has(id)) out.push(id); });
    return out;
  }

  function motionAllowed(opt, prefersReduced) {
    if (opt === 'on') return true;
    if (opt === 'off') return false;
    return !prefersReduced;
  }

  var api = { readPayload: readPayload, placementFor: placementFor, squadFor: squadFor, lineupOrder: lineupOrder,
    snapshotDone: snapshotDone, newArrivals: newArrivals, motionAllowed: motionAllowed };

  if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
  root.BattleLobby = api;

  /* ---------------- DOM wiring (browser only) ---------------- */
  var doc = root.document;

  function el(tag, cls, attrs) {
    var e = doc.createElement(tag);
    if (cls) e.className = cls;
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }

  function charImg(p, slug, pose) {
    var img = el('img', '', { src: p.assets + 'characters/' + slug + '-' + pose + '.webp', alt: '', decoding: 'async', loading: 'lazy', width: '300', height: '500' });
    return img;
  }

  function applyRoot(p, motionOk) {
    var h = doc.documentElement;
    h.setAttribute('data-bl-motion', p.opts.motion === 'auto' ? 'auto' : p.opts.motion);
    h.setAttribute('data-bl-glass', p.opts.glass ? '1' : '0');
    h.setAttribute('data-bl-bg', p.opts.background);
    h.style.setProperty('--bl-blur', p.opts.blur + 'px');
    h.style.setProperty('--bl-tint', String(p.opts.tint));
    if (p.opts.background !== 'none') h.style.setProperty('--bl-bg-image', 'url("' + p.assets + 'backgrounds/' + p.opts.background + '.webp")');
    if (!motionOk) h.setAttribute('data-bl-motion', 'off');
  }

  function renderStage(stage, p, manifest) {
    var squad = squadFor(manifest, p.opts.characters);
    var mode = placementFor(p.context, p.opts.placement);
    if (!squad.length || mode === 'off') return;
    var wrap = el('div', mode === 'lineup' ? 'bl-lineup' : 'bl-mascot');
    (mode === 'lineup' ? lineupOrder(squad) : [{ slug: squad[0], role: 'lead' }]).forEach(function (c, i) {
      var fig = el('figure', 'bl-char bl-char-' + c.role, { style: '--bl-d:' + (i * 0.7) + 's' });
      fig.appendChild(charImg(p, c.slug, 'idle'));
      if (mode === 'lineup') {
        var tag = el('span', 'bl-char-tag');
        tag.textContent = (manifest.characters[c.slug] || {}).name || c.slug;
        fig.appendChild(tag);
      }
      wrap.appendChild(fig);
    });
    stage.appendChild(wrap);
  }

  function boardColumns() {
    var cols = [];
    doc.querySelectorAll('#board tr.board-swimlane').forEach(function (row, r) {
      var lists = row.querySelectorAll('.board-task-list.board-column-expanded');
      lists.forEach(function (list, i) {
        cols.push({ swimlane: String(r), isLast: i === lists.length - 1,
          taskIds: Array.prototype.map.call(list.querySelectorAll('.task-board[data-task-id]'), function (t) { return t.getAttribute('data-task-id'); }) });
      });
    });
    return cols;
  }

  function victory(p, manifest) {
    var squad = squadFor(manifest, p.opts.characters);
    var ov = el('div', 'bl-overlay bl-victory', { role: 'status' });
    var box = el('div', 'bl-victory-box');
    if (squad.length) box.appendChild(charImg(p, squad[Math.floor(Math.random() * squad.length)], 'victory'));
    var h = el('h1', 'bl-victory-title'); h.textContent = '#1 VICTORY';
    box.appendChild(h); ov.appendChild(box); doc.body.appendChild(ov);
    var remove = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.addEventListener('click', remove);
    root.setTimeout(remove, doc.documentElement.getAttribute('data-bl-motion') === 'off' ? 900 : 2600);
  }

  function watchVictory(p, manifest) {
    var board = doc.getElementById('board-container');
    if (!board || !p.opts.victory) return;
    var seen = snapshotDone(boardColumns());
    new root.MutationObserver(function () {
      var now = snapshotDone(boardColumns());
      if (newArrivals(seen, now).length) victory(p, manifest);
      seen = now;
    }).observe(board, { childList: true, subtree: true });
  }

  function loadingScreen(p, manifest) {
    if (!p.opts.loading) return;
    doc.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || a.target === '_blank') return;
      if (!/\/board\/\d+/.test(a.getAttribute('href') || '')) return;
      var timer = root.setTimeout(function () {
        var ov = el('div', 'bl-overlay bl-loading', { role: 'status' });
        var squad = squadFor(manifest, p.opts.characters);
        if (squad.length) ov.appendChild(charImg(p, squad[0], 'idle'));
        var tip = el('p', 'bl-loading-tip'); tip.textContent = 'TIP: press "?" on any board to see keyboard shortcuts.';
        var bar = el('div', 'bl-loading-bar'); bar.appendChild(el('i'));
        ov.appendChild(tip); ov.appendChild(bar); doc.body.appendChild(ov);
      }, 300);
      root.addEventListener('pagehide', function () { root.clearTimeout(timer); }, { once: true });
    });
  }

  function parallax(motionOk) {
    if (!motionOk) return;
    root.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') return;
      doc.documentElement.style.setProperty('--bl-px', ((e.clientX / root.innerWidth - 0.5) * -24).toFixed(1));
    }, { passive: true });
  }

  function boot() {
    var mount = doc.getElementById('bl-stage') || doc.querySelector('.bl-hero[data-bl-assets]');
    if (!mount) return;
    var p = readPayload(mount.dataset);
    if (p.context === 'page' && doc.getElementById('board-container')) p.context = 'board';
    var reduced = root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var motionOk = motionAllowed(p.opts.motion, reduced);
    applyRoot(p, motionOk);
    root.fetch(p.assets + 'characters/manifest.json', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { characters: {}, sets: {} }; })
      .then(function (manifest) {
        renderStage(mount.id === 'bl-stage' ? mount : mount.querySelector('.bl-hero-stage') || mount, p, manifest);
        watchVictory(p, manifest);
        loadingScreen(p, manifest);
        parallax(motionOk);
        root.dispatchEvent(new root.CustomEvent('battlelobby:ready', { detail: { payload: p, manifest: manifest } }));
      })
      .catch(function () { /* art is decoration: never break the page */ });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot); else boot();
})(typeof window !== 'undefined' ? window : this);
```

Tasks 8 and 9 hook into the `battlelobby:ready` event, which carries `{payload, manifest}`, so this file stays the single source for payload parsing.

- [ ] **Step 5: Append the stage and overlay CSS**

```css
/* ===== Lobby stage ===== */
.bl-stage { position: fixed; inset: auto 0 0 0; height: 0; z-index: 20; pointer-events: none; }
.bl-lineup { position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); display: flex; align-items: flex-end; height: min(40vh, 320px); }
.bl-lineup::before { content: ""; position: absolute; left: -6%; right: -6%; bottom: -18px; height: 60px; border-radius: 50%;
  background: radial-gradient(closest-side, rgba(55,208,255,.55), rgba(55,208,255,.12) 70%, transparent); }
.bl-mascot { position: fixed; right: 10px; bottom: 0; height: min(34vh, 280px); }
.bl-char { position: relative; margin: 0 -22px; height: 100%; display: flex; align-items: flex-end; justify-content: center;
  transform: translateX(calc(var(--bl-px, 0) * 1px)); transition: transform .25s var(--bl-ease); }
.bl-mascot .bl-char { margin: 0; }
.bl-char img { height: var(--bl-h, 100%); width: auto; transform-origin: 50% 100%; animation: bl-breathe 4.2s ease-in-out infinite; animation-delay: var(--bl-d, 0s);
  filter: drop-shadow(-6px 0 10px rgba(55,208,255,.18)) drop-shadow(0 8px 14px rgba(0,0,0,.35)); }
.bl-char::after { content: ""; position: absolute; bottom: -6px; left: 15%; right: 15%; height: 22px; border-radius: 50%; background: radial-gradient(closest-side, rgba(0,0,0,.55), transparent); }
.bl-char-lead { z-index: 3; } .bl-char-side { --bl-h: 88%; z-index: 2; } .bl-char-back { --bl-h: 80%; z-index: 1; filter: brightness(.85); }
.bl-char-tag { position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%); z-index: 4; white-space: nowrap; background: rgba(0,0,0,.65);
  padding: 2px 10px; border-radius: 4px; font: 900 13px var(--bl-font-display); font-style: italic; text-transform: uppercase; color: var(--bl-text); }
@keyframes bl-breathe { 50% { transform: scaleY(1.012) translateY(-2px); } }
html[data-bl-motion="off"] .bl-char { transform: none; }
#board-container { padding-bottom: min(40vh, 320px); }

/* ===== Overlays ===== */
.bl-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; background: rgba(5,8,20,.6); }
.bl-victory { cursor: pointer; animation: bl-fade 2.6s forwards; }
.bl-victory-box { text-align: center; animation: bl-pop .5s var(--bl-ease) both; }
.bl-victory-box img { height: 300px; display: block; margin: 0 auto -30px; }
.bl-victory-title { margin: 0; font: 900 96px/0.9 var(--bl-font-display); font-style: italic; color: var(--bl-cta); text-shadow: 0 2px 0 rgba(0,0,0,.35), 0 0 10px rgba(0,0,0,.5); }
@keyframes bl-pop { from { transform: scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes bl-fade { 0%, 80% { opacity: 1; } 100% { opacity: 0; } }
html[data-bl-motion="off"] .bl-victory { animation: none; }
.bl-loading { background: var(--bl-bg) url("../backgrounds/island.webp") center / cover; place-items: end center; padding: 40px; }
.bl-loading img { position: absolute; right: 4vw; bottom: 0; height: 70vh; }
.bl-loading-tip { position: relative; background: var(--bl-glass); padding: 12px 16px; border-radius: var(--bl-radius); max-width: 640px; font-weight: 600; }
.bl-loading-bar { position: relative; width: min(360px, 80vw); height: 10px; background: rgba(0,0,0,.5); transform: skew(var(--bl-skew)); overflow: hidden; }
.bl-loading-bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, var(--bl-accent), var(--bl-cta)); animation: bl-load 4s var(--bl-ease) infinite; }
@keyframes bl-load { 80%, 100% { width: 100%; } }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `node --test Test/js/ && cd .. && ./testing/run-plugin-tests.sh BattleLobby`
Expected: node 6/6 pass; PHPUnit still green, including IpGuardTest over the new JS and CSS.

- [ ] **Step 7: Commit**

```bash
cd BattleLobby && git add Assets Test/js
git commit -m "feat: lobby stage, parallax, victory detection, loading screen (pure helpers under node --test)"
```

---

### Task 8: READY UP — move the user's top Ready task into progress

**Files:**
- Create: `BattleLobby/Controller/ReadyUpController.php`
- Modify: `BattleLobby/Plugin.php` (route), `BattleLobby/Assets/js/battle-lobby.js` (CTA), `BattleLobby/Assets/css/battle-lobby.css` (CTA)
- Test: `BattleLobby/Test/ReadyUpControllerTest.php`, `BattleLobby/Test/js/battle-lobby.test.cjs` (append)

**Interfaces:**
- Consumes: `battlelobby:ready` event `{payload, manifest}` (Task 7); `payload.urls.readyup`, `payload.csrf`, `payload.opts.readyup`.
- Produces:
  - `POST battlelobby/ready-up` (`ReadyUpController::start`), body `project_id`, `csrf_token`. It returns JSON `{ok:true, task_id, from_column_id, to_column_id}` or `{ok:false, error:'no_ready_column'|'ready_is_last'|'nothing_ready'|'forbidden'|'csrf'}` with HTTP 200 (errors are expected outcomes, not crashes).
  - `ReadyUpController::pick(int $projectId, int $userId): array{task?:array, from?:int, to?:int, error?:string}`, public so the selection logic can be tested without HTTP.
  - JS export `readyUpBody(projectId: string, csrf: string): string` (URL-encoded form body).

- [ ] **Step 1: Write the failing PHP test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Controller\ReadyUpController;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFinderModel;
use Kanboard\Model\UserModel;

class ReadyUpControllerTest extends Base
{
    private int $pid;
    private int $uid;
    private array $cols;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = (new UserModel($this->container))->create(['username' => 'runner']);
        $this->pid = $this->container['projectModel']->create(['name' => 'Squad']);
        // Default columns: Backlog, Ready, Work in progress, Done
        $this->cols = array_column($this->container['columnModel']->getAll($this->pid), 'id', 'title');
    }

    private function task(string $col, int $priority, int $owner): int
    {
        return (new TaskCreationModel($this->container))->create([
            'project_id' => $this->pid, 'title' => "t-$col-$priority", 'column_id' => $this->cols[$col],
            'priority' => $priority, 'owner_id' => $owner,
        ]);
    }

    private function c(): ReadyUpController
    {
        return new ReadyUpController($this->container);
    }

    public function testPicksHighestPriorityOwnedReadyTaskAndTargetsNextColumn(): void
    {
        $this->task('Ready', 1, $this->uid);
        $top = $this->task('Ready', 3, $this->uid);
        $this->task('Ready', 3, 0);            // unassigned: ignored
        $this->task('Backlog', 3, $this->uid); // wrong column: ignored
        $r = $this->c()->pick($this->pid, $this->uid);
        $this->assertSame($top, (int) $r['task']['id']);
        $this->assertSame((int) $this->cols['Ready'], $r['from']);
        $this->assertSame((int) $this->cols['Work in progress'], $r['to']);
    }

    public function testNothingReady(): void
    {
        $this->task('Ready', 2, 0);
        $this->assertSame('nothing_ready', $this->c()->pick($this->pid, $this->uid)['error']);
    }

    public function testNoReadyColumn(): void
    {
        $this->container['columnModel']->update($this->cols['Ready'], 'Queue');
        $this->assertSame('no_ready_column', $this->c()->pick($this->pid, $this->uid)['error']);
    }

    public function testReadyIsLastColumn(): void
    {
        foreach (['Work in progress', 'Done'] as $t) {
            $this->container['columnModel']->remove($this->cols[$t]);
        }
        $this->task('Ready', 2, $this->uid);
        $this->assertSame('ready_is_last', $this->c()->pick($this->pid, $this->uid)['error']);
    }

    public function testMoveActuallyMovesTheTask(): void
    {
        $id = $this->task('Ready', 2, $this->uid);
        $r = $this->c()->pick($this->pid, $this->uid);
        $this->assertTrue($this->c()->move($this->pid, $r));
        $this->assertSame((int) $this->cols['Work in progress'], (int) (new TaskFinderModel($this->container))->getById($id)['column_id']);
    }
}
```

Append to `Test/js/battle-lobby.test.cjs`:

```js
test('readyUpBody encodes project and token', () => {
  assert.equal(bl.readyUpBody('7', 'a b&c'), 'project_id=7&csrf_token=a%20b%26c');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh BattleLobby; (cd BattleLobby && node --test Test/js/)`
Expected: FAIL (controller class missing; `bl.readyUpBody is not a function`).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace Kanboard\Plugin\BattleLobby\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Model\TaskModel;

/**
 * READY UP: move the current user's highest-priority task in the "Ready" column
 * into the next column. Errors are expected outcomes returned as JSON.
 */
class ReadyUpController extends BaseController
{
    public function start(): void
    {
        if (! $this->token->validateReusableCSRFToken((string) $this->request->getRawValue('csrf_token'))) {
            $this->response->json(['ok' => false, 'error' => 'csrf']);
            return;
        }
        $projectId = (int) ($this->request->getRawValue('project_id') ?: $this->request->getIntegerParam('project_id'));
        if (! $projectId || ! $this->helper->user->hasProjectAccess('BoardViewController', 'show', $projectId)) {
            $this->response->json(['ok' => false, 'error' => 'forbidden']);
            return;
        }
        $pick = $this->pick($projectId, (int) $this->userSession->getId());
        if (isset($pick['error'])) {
            $this->response->json(['ok' => false, 'error' => $pick['error']]);
            return;
        }
        $this->response->json($this->move($projectId, $pick)
            ? ['ok' => true, 'task_id' => (int) $pick['task']['id'], 'from_column_id' => $pick['from'], 'to_column_id' => $pick['to']]
            : ['ok' => false, 'error' => 'move_failed']);
    }

    /** @return array{task?:array,from?:int,to?:int,error?:string} */
    public function pick(int $projectId, int $userId): array
    {
        $columns = array_values($this->columnModel->getAll($projectId)); // ordered by position
        $readyIdx = null;
        foreach ($columns as $i => $c) {
            if (strcasecmp(trim($c['title']), 'Ready') === 0) {
                $readyIdx = $i;
                break;
            }
        }
        if ($readyIdx === null) {
            return ['error' => 'no_ready_column'];
        }
        if (! isset($columns[$readyIdx + 1])) {
            return ['error' => 'ready_is_last'];
        }
        $from = (int) $columns[$readyIdx]['id'];
        $tasks = array_filter(
            $this->taskFinderModel->getAll($projectId, TaskModel::STATUS_OPEN),
            fn (array $t) => (int) $t['column_id'] === $from && (int) $t['owner_id'] === $userId
        );
        if (! $tasks) {
            return ['error' => 'nothing_ready'];
        }
        usort($tasks, fn ($a, $b) => [(int) $b['priority'], (int) $a['position']] <=> [(int) $a['priority'], (int) $b['position']]);
        return ['task' => $tasks[0], 'from' => $from, 'to' => (int) $columns[$readyIdx + 1]['id']];
    }

    public function move(int $projectId, array $pick): bool
    {
        return (bool) $this->taskPositionModel->movePosition(
            $projectId, (int) $pick['task']['id'], $pick['to'], 1, (int) $pick['task']['swimlane_id']
        );
    }
}
```

In `Plugin.php` `initialize()`: `$this->route->addRoute('battlelobby/ready-up', 'ReadyUpController', 'start', 'BattleLobby');`

- [ ] **Step 4: Wire the CTA in JS (export plus listener)**

In `Assets/js/battle-lobby.js`, add next to the other pure helpers and include it in `api`:

```js
  function readyUpBody(projectId, csrf) {
    return 'project_id=' + encodeURIComponent(projectId) + '&csrf_token=' + encodeURIComponent(csrf);
  }
```

`api` becomes `{ …, motionAllowed: motionAllowed, readyUpBody: readyUpBody }`. Inside the browser section, before `boot`, add:

```js
  function readyUp(p) {
    var board = doc.getElementById('board-container');
    if (!board || !p.opts.readyup) return;
    var pid = (board.getAttribute('data-project-id') || (doc.querySelector('#board') || {}).className || '').replace(/\D+/g, '');
    if (!pid) return;
    var btn = el('button', 'bl-cta', { type: 'button' });
    btn.textContent = 'READY UP';
    btn.addEventListener('click', function () {
      btn.disabled = true;
      root.fetch(p.urls.readyup, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: readyUpBody(pid, p.csrf) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) { root.location.reload(); return; }
          btn.disabled = false;
          btn.textContent = { nothing_ready: 'NOTHING READY', no_ready_column: 'NO READY COLUMN', ready_is_last: 'READY IS LAST' }[res.error] || 'TRY AGAIN';
          root.setTimeout(function () { btn.textContent = 'READY UP'; }, 2500);
        })
        .catch(function () { btn.disabled = false; });
    });
    doc.body.appendChild(btn);
  }
```

At the end of the manifest `.then` in `boot()`, add the call `readyUp(p);`.

The board's project id: `#board` carries the class `board-project-<id>` (verified in `app/Template/board/table_container.php`), so the regex extracts it. `#board-container` has no `data-project-id` in 1.2.47; the attribute check only future-proofs.

- [ ] **Step 5: Append the CTA CSS**

```css
/* ===== READY UP ===== */
.bl-cta { position: fixed; right: 28px; bottom: 28px; z-index: 30; background: var(--bl-cta); color: var(--bl-text-on-cta); border: 0;
  padding: 12px 28px; font: 900 26px var(--bl-font-display); font-style: italic; text-transform: uppercase; clip-path: var(--bl-cta-clip); cursor: pointer;
  transition: transform var(--bl-dur) var(--bl-ease), background var(--bl-dur); }
.bl-cta:hover { background: var(--bl-cta-hover); transform: scale(1.04); }
.bl-cta:focus-visible { outline: 3px solid var(--bl-accent); outline-offset: 4px; }
.bl-cta[disabled] { opacity: .7; cursor: progress; }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby && (cd BattleLobby && node --test Test/js/)`
Expected: PASS (ReadyUpControllerTest 5/5; node 7/7). If `columnModel->update($id, $title)` takes a different signature in 1.2.47, check `app/Model/ColumnModel.php` and adapt only the test.

- [ ] **Step 7: Commit**

```bash
cd BattleLobby && git add Controller/ReadyUpController.php Plugin.php Assets Test
git commit -m "feat: READY UP moves your top Ready task into progress"
```

---

### Task 9: Activity feed toasts and the Thanks button (both default off)

**Files:**
- Create: `BattleLobby/Controller/FeedController.php`
- Modify: `BattleLobby/Plugin.php` (routes), `BattleLobby/Assets/js/battle-lobby.js`, `BattleLobby/Assets/css/battle-lobby.css`
- Test: `BattleLobby/Test/FeedControllerTest.php`, `BattleLobby/Test/js/battle-lobby.test.cjs` (append)

**Interfaces:**
- Consumes: `battlelobby:ready` flow (Task 7); `payload.urls.feed`, `payload.urls.thanks`, `payload.opts.feed`, `payload.opts.thanks`.
- Produces:
  - `GET battlelobby/feed?project_id=N` (`FeedController::recent`) → `{ok:true, events:[{id:int, title:string, date:int}]}` (max 5, newest first; titles plain text) or `{ok:false, error:'forbidden'}`.
  - `POST battlelobby/thanks` (`FeedController::thanks`) with `task_id` and `csrf_token`. It adds one comment, `🙏 GG — thanks for shipping this!`, by the current user and returns `{ok:true, comment_id}`, or `{ok:false, error:'forbidden'|'csrf'|'already_thanked'}`. Only one thanks per user per task.
  - `FeedController::events(int $projectId): array` (public, testable).
  - JS export `toastText(event: {title:string}): string` (strips tags, clamps to 120 chars).

- [ ] **Step 1: Write the failing tests**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Controller\FeedController;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;

class FeedControllerTest extends Base
{
    public function testEventsAreLimitedPlainAndNewestFirst(): void
    {
        $pid = $this->container['projectModel']->create(['name' => 'Feed']);
        $tc = new TaskCreationModel($this->container);
        for ($i = 1; $i <= 7; $i++) {
            $tc->create(['project_id' => $pid, 'title' => "<b>task $i</b>"]);
        }
        $events = (new FeedController($this->container))->events($pid);
        $this->assertLessThanOrEqual(5, count($events));
        foreach ($events as $e) {
            $this->assertSame(['id', 'title', 'date'], array_keys($e));
            $this->assertStringNotContainsString('<', $e['title']);
        }
        $dates = array_column($events, 'date');
        $sorted = $dates; rsort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function testThanksOncePerUserPerTask(): void
    {
        $uid = (new UserModel($this->container))->create(['username' => 'gg']);
        $pid = $this->container['projectModel']->create(['name' => 'T']);
        $tid = (new TaskCreationModel($this->container))->create(['project_id' => $pid, 'title' => 'ship it']);
        $c = new FeedController($this->container);
        $this->assertIsInt($c->addThanks($tid, $uid));
        $this->assertSame('already_thanked', $c->addThanks($tid, $uid));
    }
}
```

Append to the node test:

```js
test('toastText strips markup and clamps length', () => {
  assert.equal(bl.toastText({ title: '<b>Alex</b> moved #4' }), 'Alex moved #4');
  assert.equal(bl.toastText({ title: 'x'.repeat(200) }).length, 120);
  assert.equal(bl.toastText({}), '');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh BattleLobby; (cd BattleLobby && node --test Test/js/)`
Expected: FAIL (class missing, `toastText` missing).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace Kanboard\Plugin\BattleLobby\Controller;

use Kanboard\Controller\BaseController;

/**
 * Activity feed toasts (recent project events) and the one-click "thanks" comment.
 * Both features ship default-off (options `feed`, `thanks`).
 */
class FeedController extends BaseController
{
    public const THANKS = '🙏 GG — thanks for shipping this!';

    public function recent(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        if (! $projectId || ! $this->helper->user->hasProjectAccess('BoardViewController', 'show', $projectId)) {
            $this->response->json(['ok' => false, 'error' => 'forbidden']);
            return;
        }
        $this->response->json(['ok' => true, 'events' => $this->events($projectId)]);
    }

    public function thanks(): void
    {
        if (! $this->token->validateReusableCSRFToken($this->request->getRawValue('csrf_token'))) {
            $this->response->json(['ok' => false, 'error' => 'csrf']);
            return;
        }
        $task = $this->taskFinderModel->getById((int) $this->request->getRawValue('task_id'));
        if (! $task || ! $this->helper->user->hasProjectAccess('BoardViewController', 'show', (int) $task['project_id'])) {
            $this->response->json(['ok' => false, 'error' => 'forbidden']);
            return;
        }
        $r = $this->addThanks((int) $task['id'], (int) $this->userSession->getId());
        $this->response->json(is_int($r) ? ['ok' => true, 'comment_id' => $r] : ['ok' => false, 'error' => $r]);
    }

    /** @return list<array{id:int,title:string,date:int}> */
    public function events(int $projectId): array
    {
        $out = [];
        foreach ($this->helper->projectActivity->getProjectEvents($projectId, 5) as $e) {
            $out[] = [
                'id' => (int) $e['id'],
                'title' => trim(strip_tags(html_entity_decode((string) ($e['event_title'] ?? ''), ENT_QUOTES))),
                'date' => (int) $e['date_creation'],
            ];
        }
        usort($out, fn ($a, $b) => $b['date'] <=> $a['date']);
        return array_slice($out, 0, 5);
    }

    /** @return int|string comment id, or 'already_thanked' */
    public function addThanks(int $taskId, int $userId)
    {
        foreach ($this->commentModel->getAll($taskId) as $c) {
            if ((int) $c['user_id'] === $userId && $c['comment'] === self::THANKS) {
                return 'already_thanked';
            }
        }
        return (int) $this->commentModel->create(['task_id' => $taskId, 'user_id' => $userId, 'comment' => self::THANKS]);
    }
}
```

In `Plugin.php` `initialize()`:

```php
        $this->route->addRoute('battlelobby/feed', 'FeedController', 'recent', 'BattleLobby');
        $this->route->addRoute('battlelobby/thanks', 'FeedController', 'thanks', 'BattleLobby');
```

- [ ] **Step 4: Wire the JS**

Pure helper (add to `api`):

```js
  function toastText(event) {
    var t = String((event && event.title) || '').replace(/<[^>]*>/g, '').trim();
    return t.length > 120 ? t.slice(0, 120) : t;
  }
```

Browser section (call both from the manifest `.then` in `boot()` as `feed(p); thanks(p);`):

```js
  function feed(p) {
    var boardEl = doc.querySelector('#board');
    if (!p.opts.feed || !boardEl) return;
    var pid = (boardEl.className.match(/board-project-(\d+)/) || [])[1];
    if (!pid) return;
    root.fetch(p.urls.feed + (p.urls.feed.indexOf('?') < 0 ? '?' : '&') + 'project_id=' + pid, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) return;
        var box = el('div', 'bl-feed', { role: 'log', 'aria-live': 'polite' });
        res.events.slice(0, 3).reverse().forEach(function (e, i) {
          var t = el('div', 'bl-toast', { style: 'animation-delay:' + (i * 0.6) + 's' });
          t.textContent = toastText(e);
          box.appendChild(t);
        });
        doc.body.appendChild(box);
        root.setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 8000);
      })
      .catch(function () {});
  }

  function thanks(p) {
    if (!p.opts.thanks) return;
    doc.querySelectorAll('#board tr.board-swimlane').forEach(function (row) {
      var lists = row.querySelectorAll('.board-task-list.board-column-expanded');
      var last = lists[lists.length - 1];
      if (!last) return;
      last.querySelectorAll('.task-board[data-task-id]').forEach(function (card) {
        var b = el('button', 'bl-thanks', { type: 'button', title: 'Say thanks' });
        b.textContent = '🙏';
        b.addEventListener('click', function (e) {
          e.stopPropagation(); b.disabled = true;
          root.fetch(p.urls.thanks, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'task_id=' + encodeURIComponent(card.getAttribute('data-task-id')) + '&csrf_token=' + encodeURIComponent(p.csrf) })
            .then(function (r) { return r.json(); })
            .then(function (res) { b.textContent = res.ok || res.error === 'already_thanked' ? 'GG' : '🙏'; })
            .catch(function () { b.disabled = false; });
        });
        card.appendChild(b);
      });
    });
  }
```

CSS:

```css
/* ===== Feed toasts + thanks ===== */
.bl-feed { position: fixed; left: 16px; bottom: 16px; z-index: 30; display: grid; gap: 6px; }
.bl-toast { background: rgba(0,0,0,.7); color: var(--bl-text); padding: 6px 12px; border-radius: 8px; font-size: 12px; animation: bl-feedin 6s both; }
@keyframes bl-feedin { 0% { opacity: 0; transform: translateX(-10px); } 8%, 85% { opacity: 1; transform: none; } 100% { opacity: 0; } }
.bl-thanks { position: absolute; right: 6px; bottom: 10px; border: 0; background: rgba(255,255,255,.12); color: var(--bl-text); border-radius: 6px; padding: 2px 6px; cursor: pointer; }
.task-board { position: relative; }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby && (cd BattleLobby && node --test Test/js/)`
Expected: PASS. If `getProjectEvents` needs a logged-in session in the unit container, set `$this->container['sessionStorage']->user` to an admin as the OptionsControllerTest does, and keep the assertions.

- [ ] **Step 6: Commit**

```bash
cd BattleLobby && git add Controller/FeedController.php Plugin.php Assets Test
git commit -m "feat: activity feed toasts and thanks button (default off)"
```

---

### Task 10: Login hero

**Files:**
- Create: `BattleLobby/Template/auth/hero.php`
- Modify: `BattleLobby/Plugin.php`, `BattleLobby/Assets/css/battle-lobby.css`
- Test: `BattleLobby/Test/LoginHeroTest.php`

**Interfaces:**
- Consumes: `payloadAttributes(null, 'login')` (Task 3); Task 7 `boot()` finds `.bl-hero[data-bl-assets]` when `#bl-stage` is absent (the login page uses `no_layout`, so `template:layout:bottom` does not render there) and renders into `.bl-hero-stage`.
- Produces: `<div class="bl-hero" data-bl-…><div class="bl-hero-title">…</div><div class="bl-hero-stage"></div></div>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\BattleLobby\Plugin;

class LoginHeroTest extends Base
{
    public function testHeroCarriesLoginPayloadAndStage(): void
    {
        (new Plugin($this->container))->initialize();
        $html = $this->container['template']->render('BattleLobby:auth/hero', []);
        $this->assertStringContainsString('class="bl-hero"', $html);
        $this->assertStringContainsString('data-bl-context="login"', $html);
        $this->assertStringContainsString('class="bl-hero-stage"', $html);
        $src = file_get_contents(dirname(__DIR__) . '/Template/auth/hero.php');
        $this->assertStringNotContainsString('<script', $src);
    }

    public function testLoginHookRegistered(): void
    {
        (new Plugin($this->container))->initialize();
        $this->assertContains('BattleLobby:auth/hero', $this->container['template']->hook->getHooks('template:auth:login-form:before') ?? []);
    }
}
```

(If the template hook object exposes registrations under a different method name, use the one the sibling plugin tests use; check with `grep -rn "hook->" ../TimeReport/Test`.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL (template missing).

- [ ] **Step 3: Write the template, hook and CSS**

`Template/auth/hero.php`:

```php
<div class="bl-hero" <?= $this->battleLobby->payloadAttributes(null, 'login') ?>>
    <div class="bl-hero-title"><?= t('Battle Lobby') ?><small><?= t('Squad up. Ship it.') ?></small></div>
    <div class="bl-hero-stage" aria-hidden="true"></div>
</div>
```

`Plugin.php` `initialize()`: `$this->template->hook->attach('template:auth:login-form:before', 'BattleLobby:auth/hero');`

CSS:

```css
/* ===== Login hero ===== */
.form-login { position: relative; z-index: 2; background: var(--bl-glass); padding: 24px; border-radius: var(--bl-radius); }
html:not([data-bl-glass="0"]) .form-login { -webkit-backdrop-filter: blur(var(--bl-blur)); backdrop-filter: blur(var(--bl-blur)); }
.bl-hero-title { font: 900 56px/0.9 var(--bl-font-display); font-style: italic; text-transform: uppercase; text-align: center; margin: 32px 0 16px;
  text-shadow: 0 2px 0 rgba(0,0,0,.35), 0 0 10px rgba(0,0,0,.5); }
.bl-hero-title small { display: block; font: 600 16px var(--bl-font-body); color: var(--bl-text-muted); text-shadow: none; text-transform: none; }
.bl-hero-stage .bl-mascot { right: 4vw; height: min(70vh, 560px); }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd BattleLobby && git add Template/auth Plugin.php Assets/css/battle-lobby.css Test/LoginHeroTest.php
git commit -m "feat: login hero with mascot over the selected background"
```

---

### Task 11: Art finalisation — fixes, victory poses, encoding, manifest (HUMAN GATE)

**Files:**
- Create: `BattleLobby/tools/art/render_characters.py` (moved from `prototype/render_characters.py`, output path changed to `tools/art/out/`), `tools/art/pose_victory.py`, `tools/art/encode_assets.py`, `tools/art/contact_sheet.py`
- Modify: `BattleLobby/Assets/characters/*`, `BattleLobby/Assets/characters/manifest.json`, `BattleLobby/Assets/backgrounds/*`
- Test: `BattleLobby/Test/CharacterAssetsTest.php`
- `.gitignore`: add `tools/art/out/`

**Interfaces:**
- Consumes: the manifest shape from Task 7 (`characters.{slug}.{name,set}`, `sets.{set}[]`).
- Produces: for every slug, `Assets/characters/<slug>-idle.webp` and `<slug>-victory.webp` with alpha. Manifest entries gain `seed` (int) and `prompt_id` (string) for the hero and for the victory pose (`victory_seed`, `victory_prompt_id`).

- [ ] **Step 1: Write the failing test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class CharacterAssetsTest extends Base
{
    private function manifest(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Assets/characters/manifest.json'), true);
    }

    public function testEveryCharacterHasIdleAndVictoryWithAlpha(): void
    {
        foreach ($this->manifest()['characters'] as $slug => $c) {
            foreach (['idle', 'victory'] as $pose) {
                $f = dirname(__DIR__) . "/Assets/characters/$slug-$pose.webp";
                $this->assertFileExists($f);
                $head = file_get_contents($f, false, null, 0, 64);
                $this->assertSame('RIFF', substr($head, 0, 4));
                $this->assertStringContainsString('VP8X', $head, "$slug-$pose.webp must be extended WebP (alpha)");
            }
            $this->assertIsInt($c['seed'] ?? null, "$slug missing seed");
            $this->assertIsString($c['victory_prompt_id'] ?? null, "$slug missing victory_prompt_id");
            $this->assertNotSame(
                md5_file(dirname(__DIR__) . "/Assets/characters/$slug-idle.webp"),
                md5_file(dirname(__DIR__) . "/Assets/characters/$slug-victory.webp"),
                "$slug victory pose is still a copy of idle"
            );
        }
    }

    public function testSetsReferenceKnownCharactersAndBudgetHolds(): void
    {
        $m = $this->manifest();
        foreach ($m['sets'] as $set => $ids) {
            foreach ($ids as $id) {
                $this->assertArrayHasKey($id, $m['characters'], "set $set references unknown $id");
            }
        }
        $total = 0;
        foreach (glob(dirname(__DIR__) . '/Assets/characters/*.webp') as $f) {
            $total += filesize($f);
        }
        $this->assertLessThanOrEqual(4 * 1024 * 1024, $total, 'characters over 4 MB');
        $this->assertCount(24, glob(dirname(__DIR__) . '/Assets/characters/*.webp'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL. The victory files are copies of idle, and the manifest has no seeds.

- [ ] **Step 3: Move the generator and apply the two fixes**

```bash
cd BattleLobby && mkdir -p tools/art && git mv -f prototype/render_characters.py tools/art/render_characters.py 2>/dev/null || cp prototype/render_characters.py tools/art/render_characters.py
sed -i 's#os.path.join(os.path.dirname(os.path.abspath(__file__)), "art", "characters")#os.path.join(os.path.dirname(os.path.abspath(__file__)), "out")#' tools/art/render_characters.py
```

In `tools/art/render_characters.py`, edit the two roster prompts. The tail on Ossian came from "no tail" priming, so remove all tail wording:

- `ossian`: replace `fully human body with no tail, leaning on a silver-tipped cane` with `standing upright with both feet on the ground, leaning on a silver-tipped cane`.
- `tabby-vance`: replace `plain fingerless gloves with human hands, a chunky grapple launcher shaped like a paw print on her wrist` with `plain fingerless gloves on human hands, a compact teal grappling-hook launcher strapped to her forearm`.

Then wait for an idle queue and render only those two:

```bash
curl -s http://192.168.1.141:8188/queue | python3 -c "import json,sys;q=json.load(sys.stdin);print(len(q['queue_running']),len(q['queue_pending']))"
python3 tools/art/render_characters.py ossian tabby-vance
```

Expected: `0 0` before submitting; two lines printing the slug, size and prompt id; PNG and WebP files written to `tools/art/out/`. Open both and confirm: Ossian has no tail, and Tabby's hands are human with no claws. If either is wrong, re-run with a changed seed (edit the `seed =` line to add `+ 1`) and repeat.

- [ ] **Step 4: Write the victory-pose script (Qwen-Image-Edit-2509 plus BiRefNet)**

`tools/art/pose_victory.py`:

```python
"""Render each character's victory pose from its approved idle hero with Qwen-Image-Edit-2509, then cut out with BiRefNet.
Polite on the shared GPU: waits for an empty queue before every prompt, never interrupts.
Usage: python3 tools/art/pose_victory.py [slug ...]   (reads tools/art/out/<slug>.png, writes tools/art/out/<slug>-victory.png)"""
import io, json, os, sys, time, urllib.parse, urllib.request, uuid
from PIL import Image

BASE = os.environ.get("COMFYUI_URL", "http://192.168.1.141:8188")
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "out")
POSES = {
    "saffron": "bowing with fist pressed to open palm, sash flying", "sol-grande": "flexing both arms overhead, cape flaring",
    "copperjab": "both gloves raised overhead in triumph", "glyph": "landing from a flip in a low crouch, visor glowing",
    "tundra": "roaring with the axe raised overhead", "ironbraid": "slamming the maul into the ground, dust ring",
    "tabby-vance": "mid-air backflip, then saluting with two fingers", "mikan": "crossing both blades, twin tails flaring",
    "velvet": "sweeping the cape open with a burst of cartoon bats", "ossian": "tipping an invisible hat with a playful bow",
    "moonfang": "howling joyfully at the sky", "lanternbone": "raising the glowing lantern staff high, cheering",
}
INSTRUCTION = ("Same character, same outfit, same face, same colours, same rendering style and lighting. Change only the pose: {pose}. "
               "Full body with feet visible, centered, solid flat mid-grey studio background, the image contains only the character.")

def get(path):
    with urllib.request.urlopen(BASE + path, timeout=60) as r:
        return r.read()

def post(path, body):
    req = urllib.request.Request(BASE + path, json.dumps(body).encode(), {"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())

def wait_idle():
    while True:
        q = json.loads(get("/queue"))
        if not q["queue_running"] and not q["queue_pending"]:
            return
        time.sleep(10)

def upload(path, name):
    boundary = uuid.uuid4().hex
    data = open(path, "rb").read()
    body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"image\"; filename=\"{name}\"\r\nContent-Type: image/png\r\n\r\n").encode() + data + \
           (f"\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\ntemp\r\n--{boundary}--\r\n").encode()
    req = urllib.request.Request(BASE + "/upload/image", body, {"Content-Type": f"multipart/form-data; boundary={boundary}"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())["name"]

def graph(image_name, prompt, seed):
    return {
        "1": {"class_type": "UNETLoader", "inputs": {"unet_name": "qwen-image-edit/qwen_image_edit_2509_fp8_e4m3fn.safetensors", "weight_dtype": "default"}},
        "2": {"class_type": "LoraLoaderModelOnly", "inputs": {"lora_name": "qwen-image-edit-lightning/Qwen-Image-Edit-2509-Lightning-4steps-V1.0-bf16.safetensors", "strength_model": 1, "model": ["1", 0]}},
        "3": {"class_type": "ModelSamplingAuraFlow", "inputs": {"shift": 3, "model": ["2", 0]}},
        "4": {"class_type": "CFGNorm", "inputs": {"strength": 1, "model": ["3", 0]}},
        "5": {"class_type": "CLIPLoader", "inputs": {"clip_name": "qwen/qwen_2.5_vl_7b_fp8_scaled.safetensors", "type": "qwen_image", "device": "default"}},
        "6": {"class_type": "VAELoader", "inputs": {"vae_name": "qwen-image/qwen_image_vae.safetensors"}},
        "7": {"class_type": "LoadImage", "inputs": {"image": image_name}},
        "8": {"class_type": "ImageScaleToTotalPixels", "inputs": {"upscale_method": "lanczos", "megapixels": 1, "resolution_steps": 8, "image": ["7", 0]}},
        "9": {"class_type": "TextEncodeQwenImageEditPlus", "inputs": {"prompt": prompt, "clip": ["5", 0], "vae": ["6", 0], "image1": ["8", 0]}},
        "10": {"class_type": "TextEncodeQwenImageEditPlus", "inputs": {"prompt": "", "clip": ["5", 0], "vae": ["6", 0], "image1": ["8", 0]}},
        "11": {"class_type": "VAEEncode", "inputs": {"pixels": ["8", 0], "vae": ["6", 0]}},
        "12": {"class_type": "KSampler", "inputs": {"seed": seed, "steps": 4, "cfg": 1, "sampler_name": "euler", "scheduler": "simple", "denoise": 1,
                                                    "model": ["4", 0], "positive": ["9", 0], "negative": ["10", 0], "latent_image": ["11", 0]}},
        "13": {"class_type": "VAEDecode", "inputs": {"samples": ["12", 0], "vae": ["6", 0]}},
        "700": {"class_type": "LoadBackgroundRemovalModel", "inputs": {"bg_removal_name": "birefnet.safetensors"}},
        "701": {"class_type": "RemoveBackground", "inputs": {"bg_removal_model": ["700", 0], "image": ["13", 0]}},
        "702": {"class_type": "MaskToImage", "inputs": {"mask": ["701", 0]}},
        "20": {"class_type": "PreviewImage", "inputs": {"images": ["13", 0]}},
        "21": {"class_type": "PreviewImage", "inputs": {"images": ["702", 0]}},
    }

def fetch(img):
    return Image.open(io.BytesIO(get("/view?" + urllib.parse.urlencode({"filename": img["filename"], "subfolder": img["subfolder"], "type": img["type"]}))))

def main():
    meta_path = os.path.join(OUT, "victory.json")
    meta = json.load(open(meta_path)) if os.path.exists(meta_path) else {}
    for slug in sys.argv[1:] or list(POSES):
        src = os.path.join(OUT, slug + ".png")
        flat = Image.open(src).convert("RGBA")
        bg = Image.new("RGBA", flat.size, (128, 128, 128, 255)); bg.alpha_composite(flat)
        tmp = os.path.join(OUT, f".{slug}-flat.png"); bg.convert("RGB").save(tmp)
        seed = 20261007 + sum(map(ord, slug))
        wait_idle()
        name = upload(tmp, f"bl-{slug}.png")
        pid = post("/prompt", {"prompt": graph(name, INSTRUCTION.format(pose=POSES[slug]), seed), "client_id": str(uuid.uuid4())})["prompt_id"]
        while True:
            h = json.loads(get("/history/" + pid)).get(pid)
            if h and h.get("outputs", {}).get("21"):
                break
            if h and h.get("status", {}).get("status_str") == "error":
                sys.exit(f"{slug} failed: {h['status']}")
            time.sleep(3)
        rgb = fetch(h["outputs"]["20"]["images"][0]).convert("RGB")
        mask = fetch(h["outputs"]["21"]["images"][0]).convert("L").resize(rgb.size)
        rgba = rgb.copy(); rgba.putalpha(mask)
        rgba = rgba.crop(mask.point(lambda v: 255 if v > 16 else 0).getbbox())
        rgba.save(os.path.join(OUT, f"{slug}-victory.png"), optimize=True)
        os.remove(tmp)
        meta[slug] = {"victory_seed": seed, "victory_prompt_id": pid}
        json.dump(meta, open(meta_path, "w"), indent=1)
        print(slug, rgba.size, pid, flush=True)

if __name__ == "__main__":
    main()
```

Before running, copy the approved idle PNGs into `tools/art/out/` (the prototype outputs). Ossian and Tabby are already there from Step 3:

```bash
for s in saffron sol-grande copperjab glyph tundra ironbraid mikan velvet moonfang lanternbone; do cp prototype/art/characters/$s.png tools/art/out/$s.png; done
cp prototype/art/characters/manifest.json tools/art/out/heroes.json
python3 tools/art/pose_victory.py
```

Expected: 12 lines, each with slug, size and prompt id. If a node input name differs (for example `image1` on `TextEncodeQwenImageEditPlus`), read `GET /object_info/TextEncodeQwenImageEditPlus` and match the names exactly. `fastfood/workflows/sheet-qwen-edit.json` is the known-good wiring.

- [ ] **Step 5: Contact sheet for review (HUMAN GATE)**

`tools/art/contact_sheet.py`:

```python
"""Grid of every character's idle and victory cutouts on the navy lobby colour, for human review."""
import os
from PIL import Image
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "out")
ORDER = ["saffron", "sol-grande", "copperjab", "glyph", "tundra", "ironbraid", "tabby-vance", "mikan", "velvet", "ossian", "moonfang", "lanternbone"]
sheet = Image.new("RGBA", (12 * 220, 2 * 380), (13, 20, 33, 255))
for i, slug in enumerate(ORDER):
    for row, suffix in enumerate(["", "-victory"]):
        im = Image.open(os.path.join(OUT, slug + suffix + ".png")); im.thumbnail((210, 370))
        sheet.alpha_composite(im, (i * 220 + 5, row * 380 + 375 - im.size[1]))
sheet.convert("RGB").save(os.path.join(OUT, "contact-sheet.jpg"), quality=85)
print(os.path.join(OUT, "contact-sheet.jpg"))
```

Run `python3 tools/art/contact_sheet.py`, send the sheet to Carmelo (SendUserFile), and **stop for review**. Re-roll any character he flags: change that slug's seed and re-run the relevant script for that slug only. Check every character against the avoid-lists in `docs/research/Game character archetypes for theme.md`, including no Kratos look, no Catwoman cluster and no third-party mascots. Continue only after an explicit approval message from Carmelo.

- [ ] **Step 6: Encode the WebP assets and finalise the manifest**

`tools/art/encode_assets.py`:

```python
"""Encode approved cutouts to WebP-with-alpha into Assets/characters and merge seeds/prompt ids into manifest.json."""
import json, os
from PIL import Image
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
ASSETS = os.path.join(HERE, "..", "..", "Assets", "characters")
manifest_path = os.path.join(ASSETS, "manifest.json")
manifest = json.load(open(manifest_path))
heroes = json.load(open(os.path.join(OUT, "heroes.json")))
victory = json.load(open(os.path.join(OUT, "victory.json")))
total = 0
for slug in manifest["characters"]:
    for pose, src in (("idle", f"{slug}.png"), ("victory", f"{slug}-victory.png")):
        im = Image.open(os.path.join(OUT, src)).convert("RGBA")
        im.thumbnail((520, 820))
        dst = os.path.join(ASSETS, f"{slug}-{pose}.webp")
        im.save(dst, "WEBP", quality=80, method=6, exact=False)
        total += os.path.getsize(dst)
    manifest["characters"][slug].update({"seed": heroes[slug]["seed"], "prompt_id": heroes[slug]["prompt_id"], **victory[slug]})
json.dump(manifest, open(manifest_path, "w"), indent=2)
print(f"characters total {total/1024/1024:.2f} MB")
```

Run: `python3 tools/art/encode_assets.py`
Expected: `characters total` between 2.00 and 4.00 MB. If it is over 4, lower `quality` to 72 or the thumbnail height to 720, then re-run.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: PASS (CharacterAssetsTest 2/2 plus everything else).

- [ ] **Step 8: Commit**

```bash
cd BattleLobby && printf 'tools/art/out/\n' >> .gitignore
git add .gitignore tools/art/*.py Assets/characters Test/CharacterAssetsTest.php
git commit -m "feat: final roster art — Ossian/Tabby fixes, victory poses, WebP cutouts, manifest seeds"
```

---

### Task 12: ModMenu — optional `conflicts` declaration (warn, never block)

**Files (ModMenu repo, `kanboard-plugins/ModMenu`):**
- Modify: `ModMenu/Model/PluginManager.php` (`readMeta()`), `ModMenu/Model/DirectoryClient.php` (pass-through), the Installed and Browse templates that render dependency hints (find them with `grep -rn "recommends" ModMenu/Template`)
- Test: `ModMenu/Test/ConflictsTest.php`

**Interfaces:**
- Produces: `readMeta()` returns `conflicts: string[]` (default `[]`). `PluginManager::activeConflicts(string $name): string[]` returns the names of installed active plugins that conflict with `$name` in either direction. Templates show the non-blocking line "Conflicts with X: enable only one."

- [ ] **Step 1: Write the failing test**

Mirror the fixtures ModMenu's existing dependency tests use (`grep -rln "recommends" ModMenu/Test` and copy that file's setup for temp plugin directories). Then:

```php
public function testReadMetaParsesConflictsDefaultEmpty(): void
{
    $dir = $this->makePlugin('Alpha', ['conflicts' => ['Beta']]);
    $this->makePlugin('Gamma', []);
    $m = $this->manager();
    $this->assertSame(['Beta'], $m->readMeta('Alpha')['conflicts']);
    $this->assertSame([], $m->readMeta('Gamma')['conflicts']);
}

public function testActiveConflictsIsSymmetric(): void
{
    $this->makePlugin('Alpha', ['conflicts' => ['Beta']]);
    $this->makePlugin('Beta', []);
    $m = $this->manager();
    $this->assertSame(['Beta'], $m->activeConflicts('Alpha'));
    $this->assertSame(['Alpha'], $m->activeConflicts('Beta'));
}

public function testConflictsNeverBlockEnable(): void
{
    $this->makePlugin('Alpha', ['conflicts' => ['Beta']]);
    $this->makeDisabledPlugin('Beta', []);
    $this->manager()->enable('Beta');   // must not throw
    $this->assertDirectoryExists($this->pluginsDir() . '/Beta');
}
```

`makePlugin`, `makeDisabledPlugin`, `manager` and `pluginsDir` are the helper names in ModMenu's existing dependency test. If they are named differently there, use those names; don't create parallel helpers.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: FAIL (undefined index `conflicts`; undefined method `activeConflicts`).

- [ ] **Step 3: Implement**

In `readMeta()`, alongside `requires` and `recommends`:

```php
            'conflicts' => array_values(array_filter((array) ($json['conflicts'] ?? []), 'is_string')),
```

Add to `PluginManager`:

```php
    /** Installed, active plugins that conflict with $name (declared on either side). Warning-only. */
    public function activeConflicts(string $name): array
    {
        $mine = $this->readMeta($name)['conflicts'] ?? [];
        $out = [];
        foreach ($this->listActive() as $other) {
            if ($other === $name) {
                continue;
            }
            if (in_array($other, $mine, true) || in_array($name, $this->readMeta($other)['conflicts'] ?? [], true)) {
                $out[] = $other;
            }
        }
        sort($out);
        return $out;
    }
```

Use the existing method that lists active plugin names (it may be named differently from `listActive`; reuse it). In the Installed tab row template, under the existing recommends hint:

```php
<?php foreach ($this->app->pluginManagerForModMenu->activeConflicts($plugin['name']) as $c): ?>
    <div class="modmenu-hint modmenu-conflict"><?= t('Conflicts with %s: enable only one.', $this->text->e($c)) ?></div>
<?php endforeach ?>
```

Use whatever variable or helper the template already uses to reach PluginManager; don't add a new container entry if one exists. In `DirectoryClient`, make sure `conflicts` is carried through `annotate`/`merge` the same way `recommends` is, and show "Conflicts with X" on Browse entries.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh ModMenu`
Expected: PASS, including all pre-existing ModMenu tests.

- [ ] **Step 5: Commit (in the ModMenu repo)**

```bash
cd ../ModMenu && git add -A Model Template Test
git commit -m "feat: optional plugin.json conflicts — warn on install/enable, never block"
```

Bump ModMenu's version per its own CHANGELOG convention in the same commit only if its PluginMeta test requires it. Don't tag or push.

---

### Task 13: Budgets, e2e verification and release prep (no publish)

**Files:**
- Create: `BattleLobby/testing/docker-compose.e2e.yml`, `BattleLobby/testing/contrast_audit.py`, `BattleLobby/docs/release-checklist.md`
- Modify: `BattleLobby/CHANGELOG.md` (date), `BattleLobby/testing/capture_reference.py` (output dir argument)
- Test: `BattleLobby/Test/BudgetTest.php`

**Interfaces:**
- Consumes: the whole plugin.
- Produces: evidence (command plus output) for the release checklist, and a local zip built by the same rsync rules as CI.

- [ ] **Step 1: Write the failing budget test**

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class BudgetTest extends Base
{
    public function testShippableTreeUnderEightMegabytes(): void
    {
        $root = dirname(__DIR__);
        $skip = '#/(\.git|\.github|Test|testing|docs|tools|prototype|research_notes|local|reports)(/|$)#';
        $total = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! preg_match($skip, substr($f->getPathname(), strlen($root)))) {
                $total += $f->getSize();
            }
        }
        $this->assertLessThanOrEqual(8 * 1024 * 1024, $total, sprintf('shippable tree %.2f MB > 8 MB', $total / 1048576));
    }

    public function testContrastAuditPassed(): void
    {
        $report = dirname(__DIR__) . '/docs/contrast-audit.json';
        $this->assertFileExists($report, 'run testing/contrast_audit.py first');
        foreach (json_decode(file_get_contents($report), true) as $bg => $r) {
            $this->assertGreaterThanOrEqual(4.5, $r['body_ratio'], "$bg body text below AA");
            $this->assertGreaterThanOrEqual(3.0, $r['heading_ratio'], "$bg headings below 3:1");
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./testing/run-plugin-tests.sh BattleLobby`
Expected: FAIL on `testContrastAuditPassed` (no report yet).

- [ ] **Step 3: Write the contrast audit**

`testing/contrast_audit.py`. For each background it finds the brightest 10% luminance region (90th percentile pixel), composites the default glass (`rgba(16,20,35,0.78)`) over it, and computes the WCAG ratio of `--bl-text` (`#f3f5fa`, body) and `--bl-text-muted` (`#a8b0c4`, used as the 3:1 floor for the heading check):

```python
"""WCAG contrast of theme text over the default glass on the brightest region of each shipped background."""
import json, os
from PIL import Image

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
GLASS = (16, 20, 35); ALPHA = 0.78
TEXT = (0xf3, 0xf5, 0xfa); MUTED = (0xa8, 0xb0, 0xc4)

def lum(rgb):
    def ch(c):
        c = c / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = (ch(x) for x in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def ratio(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)

out = {}
for name in ["map", "storm", "island", "victory"]:
    im = Image.open(os.path.join(ROOT, "Assets", "backgrounds", f"{name}.webp")).convert("RGB").resize((192, 108))
    px = sorted(im.getdata(), key=lum)
    bright = px[int(len(px) * 0.9)]                       # 90th percentile = brightest 10% region
    under = tuple(round(ALPHA * g + (1 - ALPHA) * b) for g, b in zip(GLASS, bright))
    out[name] = {"bright_px": bright, "composited": under,
                 "body_ratio": round(ratio(TEXT, under), 2), "heading_ratio": round(ratio(MUTED, under), 2)}
json.dump(out, open(os.path.join(ROOT, "docs", "contrast-audit.json"), "w"), indent=1)
print(json.dumps(out, indent=1))
```

Run: `python3 testing/contrast_audit.py`
Expected: every `body_ratio` ≥ 4.5 and `heading_ratio` ≥ 3.0. If any fail, raise the `--bl-tint` default in both CSS and `OptionsModel` (for example to 0.82), keep `testDefaults` in sync by updating its expected `tint`, and re-run.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./testing/run-plugin-tests.sh BattleLobby && (cd BattleLobby && node --test Test/js/)`
Expected: all PHPUnit classes and all node tests pass.

- [ ] **Step 5: End-to-end on a plain Kanboard with only Battle Lobby mounted**

`testing/docker-compose.e2e.yml`:

```yaml
# Plain Kanboard + Battle Lobby only (no ShadcnTheme), port 8083.
#   docker compose -f BattleLobby/testing/docker-compose.e2e.yml up -d
name: kb-battlelobby-e2e
services:
  kanboard:
    image: kanboard/kanboard:latest
    container_name: kb-battlelobby-e2e
    ports: ["8083:80"]
    volumes:
      - kb-battlelobby-e2e-data:/var/www/app/data
      - ../:/var/www/app/plugins/BattleLobby:ro
volumes:
  kb-battlelobby-e2e-data:
```

Then:

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins
chmod -R o+rX BattleLobby
docker compose -f BattleLobby/testing/docker-compose.e2e.yml up -d
until curl -s -o /dev/null -w '%{http_code}' http://localhost:8083/ | grep -qE '200|302'; do sleep 2; done
sed -e 's#^COMPOSE_FILE=.*#COMPOSE_FILE="BattleLobby/testing/docker-compose.e2e.yml"#' \
    -e 's#^BASE_URL=.*#BASE_URL="http://localhost:8083"#' \
    -e 's#^ENV_FILE=.*#ENV_FILE="BattleLobby/testing/.env"#' testing/seed.sh > /tmp/seed-bl-e2e.sh
bash /tmp/seed-bl-e2e.sh
curl -sI http://localhost:8083/login | grep -i content-security-policy
```

Expected: seeding prints `Seed complete`; the CSP header is present.

Drive it in the browser pane (log in with the harness default dev credentials from `testing/README.md`) and capture screenshots of: login (hero visible), board `/board/1` (tier frames, storm chips, lineup, READY UP), Settings → Battle Lobby, profile → Battle Lobby. Then:

1. Drag a task into Done. The victory overlay appears once; dragging another card within Done does not trigger it.
2. Put a task owned by admin into Ready and click READY UP. It moves to the next column.
3. Set Characters to None and Animations to Off in the profile. There is no lineup and no animation, and the page has no console errors (`read_console_messages`).
4. Emulate `prefers-reduced-motion` (resize_window colorScheme is unrelated; use the Animations=Auto setting with OS reduced motion if the pane supports it, otherwise rely on step 3 plus the CSS test).

Re-run the reference capture against the themed instance for the record. First make `capture_reference.py` read `BASE = os.environ.get("KB_BASE", "http://localhost:8082")` and `OUT = os.environ.get("KB_OUT", <its current OUT path>)`, then:

```bash
KB_BASE=http://localhost:8083 KB_OUT=BattleLobby/docs/themed-screenshots python3 BattleLobby/testing/capture_reference.py
```

- [ ] **Step 6: Build the zip locally with the CI rules and check its size**

```bash
cd BattleLobby && rm -rf /tmp/bl-stage && mkdir -p /tmp/bl-stage/BattleLobby
rsync -a --exclude '.git' --exclude '.github' --exclude 'Test' --exclude 'testing' --exclude 'docs' --exclude 'tools' \
  --exclude 'prototype' --exclude 'research_notes' --exclude 'local' --exclude '.DS_Store' ./ /tmp/bl-stage/BattleLobby/
(cd /tmp/bl-stage && zip -qr BattleLobby-1.0.0.zip BattleLobby && ls -l BattleLobby-1.0.0.zip && unzip -l BattleLobby-1.0.0.zip | tail -1)
unzip -l /tmp/bl-stage/BattleLobby-1.0.0.zip | grep -iE 'prototype|research|reports|Test/' || echo "clean: no dev folders in zip"
```

Expected: zip ≤ 8 MB; `clean: no dev folders in zip`.

- [ ] **Step 7: Release checklist and CHANGELOG date**

`docs/release-checklist.md`, filled in with the actual command outputs from Steps 3–6:

```markdown
# Battle Lobby 1.0.0 release checklist

- [ ] PHPUnit green — `./testing/run-plugin-tests.sh BattleLobby` → <paste summary line>
- [ ] node tests green — `node --test Test/js/` → <paste summary line>
- [ ] Contrast audit — `docs/contrast-audit.json` (all body ≥ 4.5, headings ≥ 3.0)
- [ ] Zip ≤ 8 MB, no dev folders — <paste ls -l line>
- [ ] e2e screenshots — docs/themed-screenshots/
- [ ] Character contact sheet approved by Carmelo — <date>
- [ ] README disclaimer present; IpGuardTest green
- [ ] Carmelo go for: create GitHub repo carmelosantana/kanboard-battle-lobby, push, tag v1.0.0, ModMenu directory entry
```

Change `## 1.0.0 — unreleased` in CHANGELOG to `## 1.0.0 — <today's date>`. `PluginMetaTest` only requires the `## 1.0.0 — ` prefix, so it stays green.

- [ ] **Step 8: Commit and stop**

```bash
git add Test/BudgetTest.php testing docs CHANGELOG.md
git commit -m "chore: budgets, contrast audit, e2e harness, release checklist for 1.0.0"
```

Report the checklist to Carmelo and **stop**. Publishing (GitHub repo, push, tag, ModMenu directory entry) happens only after his explicit go.
