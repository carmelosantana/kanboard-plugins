# Hello Harmozi + Directory Repo + E2E — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **DEPENDS ON:** the ModMenu plugin plan (`2026-07-03-modmenu-plugin.md`) must be implemented first — the E2E tasks here install/enable/disable/update/uninstall through ModMenu's UI.

**Goal:** Ship the "Hello Harmozi" demo plugin, publish all plugins to GitHub via Releases, stand up a forkable directory repo, and verify ModMenu's full lifecycle live end-to-end.

**Architecture:** Hello Harmozi is a tiny Kanboard plugin that prints a random Alex Hormozi quote in the page footer via the `template:layout:bottom` hook. A `package.sh` script builds correctly-structured `<Name>-<version>.zip` artifacts. GitHub Releases host the zips; a separate `kanboard-modmenu-directory` repo holds `plugins.json` + README + screenshots. A Playwright script drives the live Docker instance on :8081 through install → enable/disable → update → uninstall + upload.

**Tech Stack:** PHP 8.4 (Hello Harmozi), Bash + `zip` + `gh` CLI (packaging/publishing), Playwright (channel `chrome`, headless) against the `kb-suite` container on :8081 (login admin/admin), PHPUnit.

## Global Constraints

- **Outward-facing gate:** Tasks 4–7 create GitHub repos, push code, and publish releases. Before executing ANY of them, STOP and get the user's explicit go-ahead on exact repo names and **public** visibility. Do not push, create repos, or publish releases without that confirmation in-session.
- GitHub account: `carmelosantana` (gh authenticated). Git identity for these public/OSS repos: `Carmelo Santana <597820+carmelosantana@users.noreply.github.com>` (the privacy alias for public commits). Set per-repo, do not change global config.
- Hello Harmozi: name exactly `HelloHarmozi`, namespace `Kanboard\Plugin\HelloHarmozi`, version `1.0.0`, `compatible_version >=1.2.47`, MIT, author `Carmelo Santana`.
- Hello Harmozi must be pushed to GitHub only — it is NEVER added to `testing/docker-compose.dev.yml` (it is installed *through ModMenu* during E2E, proving the download/install path).
- Release artifact naming: tag `<PluginName>-v<version>` (Hello Harmozi uses `v<version>` in its own repo), asset `<PluginName>-<version>.zip`, whose single top-level folder is `<PluginName>/`.
- Directory repo: `carmelosantana/kanboard-modmenu-directory`, default branch `main`. `plugins.json` lives at repo root; ModMenu's default source URL is `https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json`.
- E2E runs against the already-running `kb-suite` container (`docker compose -f testing/docker-compose.dev.yml up -d`). Use a fresh Playwright context to avoid CSS/JS disk cache. Zero console errors is a pass criterion.
- Never commit or log secrets. `gh` uses its own stored auth; do not echo tokens.

---

## File Structure

```
HelloHarmozi/                     # its own GitHub repo (not in the dev suite)
  Plugin.php
  plugin.json
  LICENSE
  README.md
  CHANGELOG.md
  Model/Quotes.php                # the quote list + random picker
  Template/footer/quote.php       # renders the footer line
  Assets/css/hello-harmozi.css
  Test/PluginTest.php
  Test/QuotesTest.php

kanboard-plugins/ (this repo)
  scripts/package.sh              # build <Name>-<version>.zip artifacts
  scripts/e2e/modmenu-loop.mjs    # Playwright full-lifecycle verification

kanboard-modmenu-directory/       # separate GitHub repo
  plugins.json
  README.md
  assets/hello-harmozi/screenshot-1.png
  assets/<other-plugins>/...
```

---

### Task 1: Hello Harmozi — quotes model (TDD)

**Files:**
- Create: `HelloHarmozi/Model/Quotes.php`
- Test: `HelloHarmozi/Test/QuotesTest.php`

**Interfaces:**
- Produces: `Kanboard\Plugin\HelloHarmozi\Model\Quotes` with `static all(): array` (≥15 non-empty strings) and `static random(): string` (one element of `all()`).

Work in a scratch checkout of the new `HelloHarmozi` repo (created in Task 4) OR build the plugin under `/home/carmelo/Projects/Kanboard/HelloHarmozi/` and push in Task 4. Tests run by symlinking the plugin into `testing/kanboard-src/plugins/` — the runner does this automatically when the plugin dir is reachable; for a plugin outside `kanboard-plugins/`, copy it to `kanboard-plugins/HelloHarmozi/` temporarily to run the suite, or run PHPUnit directly. Simplest: develop it at `kanboard-plugins/HelloHarmozi/` (git-ignored from the suite by not mounting it), then push that tree to its own repo in Task 4.

- [ ] **Step 1: Write the failing test** — `HelloHarmozi/Test/QuotesTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\HelloHarmozi\Model\Quotes;

class QuotesTest extends Base
{
    public function testAllReturnsManyNonEmptyStrings()
    {
        $all = Quotes::all();
        $this->assertGreaterThanOrEqual(15, count($all));
        foreach ($all as $q) {
            $this->assertIsString($q);
            $this->assertNotEmpty(trim($q));
        }
    }

    public function testRandomReturnsAKnownQuote()
    {
        $all = Quotes::all();
        for ($i = 0; $i < 20; $i++) {
            $this->assertContains(Quotes::random(), $all);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh HelloHarmozi`
Expected: FAIL — `Quotes` not found.

- [ ] **Step 3: Write minimal implementation** — `HelloHarmozi/Model/Quotes.php`

```php
<?php

namespace Kanboard\Plugin\HelloHarmozi\Model;

/**
 * A small, bundled set of Alex Hormozi quotes. A Hello Dolly homage —
 * self-contained, no network, no config.
 */
class Quotes
{
    /** @return string[] */
    public static function all(): array
    {
        return [
            'The market pays for value. Nothing else.',
            'Volume negates luck.',
            'Discipline is doing it when you don\'t feel like it.',
            'You are one skill away from changing your entire life.',
            'The person who needs the exchange less always has the upper hand.',
            'Boredom is the enemy, not some evil person.',
            'Your greatest source of leverage is the work you\'re avoiding.',
            'Rejection is a tax you pay to reach your goals.',
            'Do more. That\'s it. That\'s the tweet.',
            'Confidence is just evidence you\'ve kept promises to yourself.',
            'You don\'t need more time, you need fewer distractions.',
            'The goal is not to be busy. The goal is to be effective.',
            'Nobody is coming to save you. Good. That means it\'s up to you.',
            'Fall in love with the process and the results will come.',
            'Successful people do what unsuccessful people won\'t.',
            'The best time to double down is when it\'s working.',
            'Make the offer so good people feel stupid saying no.',
            'Patience plus persistence equals compounding.',
        ];
    }

    public static function random(): string
    {
        $all = self::all();
        return $all[array_rand($all)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh HelloHarmozi`
Expected: PASS.

- [ ] **Step 5: Commit** (in the HelloHarmozi working tree)

```bash
git add HelloHarmozi/Model/Quotes.php HelloHarmozi/Test/QuotesTest.php
git commit -m "feat(HelloHarmozi): bundled Hormozi quotes model"
```

---

### Task 2: Hello Harmozi — plugin wiring + footer render

**Files:**
- Create: `HelloHarmozi/Plugin.php`, `HelloHarmozi/plugin.json`, `HelloHarmozi/LICENSE`, `HelloHarmozi/Template/footer/quote.php`, `HelloHarmozi/Assets/css/hello-harmozi.css`
- Test: `HelloHarmozi/Test/PluginTest.php`

**Interfaces:**
- Consumes: `Quotes::random()`.
- Produces: `Kanboard\Plugin\HelloHarmozi\Plugin` with the standard metadata methods and an `initialize()` that hooks `template:layout:bottom` → `HelloHarmozi:footer/quote` and injects the CSS via `template:layout:css`.

- [ ] **Step 1: Write the failing test** — `HelloHarmozi/Test/PluginTest.php`

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\HelloHarmozi\Plugin;

class PluginTest extends Base
{
    public function testMetadata()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('HelloHarmozi', $plugin->getPluginName());
        $this->assertSame('1.0.0', $plugin->getPluginVersion());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
        $this->assertNotEmpty($plugin->getPluginDescription());
    }

    public function testInitializeRuns()
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertTrue(true); // hooks registered without error
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh HelloHarmozi`
Expected: FAIL — `Plugin` not found.

- [ ] **Step 3: Write minimal implementation** — `HelloHarmozi/Plugin.php`

```php
<?php

namespace Kanboard\Plugin\HelloHarmozi;

use Kanboard\Core\Plugin\Base;

/**
 * Hello Harmozi — a Hello Dolly homage. Prints a random Alex Hormozi quote as a
 * quiet footer line on every page. No settings, no network.
 *
 * @author  Carmelo Santana
 * @license MIT
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/HelloHarmozi/Assets/css/hello-harmozi.css']);
        $this->hook->on('template:layout:bottom', ['template' => 'HelloHarmozi:footer/quote']);
    }

    public function getPluginName(): string
    {
        return 'HelloHarmozi';
    }

    public function getPluginDescription(): string
    {
        return 'A random Alex Hormozi quote in your footer. A Hello Dolly homage.';
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
        return 'https://github.com/carmelosantana/HelloHarmozi';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }
}
```

Create `HelloHarmozi/Template/footer/quote.php`:

```php
<?php use Kanboard\Plugin\HelloHarmozi\Model\Quotes; ?>
<div class="hello-harmozi" role="note">
    <span class="hello-harmozi__quote"><?= $this->text->e(Quotes::random()) ?></span>
    <span class="hello-harmozi__attr">— Alex Hormozi</span>
</div>
```

Create `HelloHarmozi/Assets/css/hello-harmozi.css`:

```css
/* Hello Harmozi — quiet footer line. Inline style is CSP-allowed; external is cleaner. */
.hello-harmozi { text-align: center; font-size: 0.8125rem; opacity: 0.7; padding: 0.75rem 1rem 1.25rem; }
.hello-harmozi__attr { font-style: italic; margin-left: 0.35rem; }
```

Create `HelloHarmozi/plugin.json`:

```json
{
    "name": "HelloHarmozi",
    "version": "1.0.0",
    "description": "A random Alex Hormozi quote in your footer. A Hello Dolly homage.",
    "author": "Carmelo Santana",
    "homepage": "https://github.com/carmelosantana/HelloHarmozi",
    "license": "MIT",
    "compatible_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

Create `HelloHarmozi/LICENSE` — MIT, copyright `2026 Carmelo Santana`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./testing/run-plugin-tests.sh HelloHarmozi`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add HelloHarmozi/Plugin.php HelloHarmozi/plugin.json HelloHarmozi/LICENSE HelloHarmozi/Template HelloHarmozi/Assets HelloHarmozi/Test/PluginTest.php
git commit -m "feat(HelloHarmozi): footer quote via template:layout:bottom hook"
```

- [ ] **Step 6: Write `HelloHarmozi/README.md` + `HelloHarmozi/CHANGELOG.md`** and commit — README explains it's a Hello Dolly homage and doubles as the reference "how a ModMenu-installable plugin repo is structured" example; CHANGELOG has a `1.0.0 — 2026-07-03` "Added" entry.

---

### Task 3: `package.sh` — build correctly-structured plugin zips (TDD)

**Files:**
- Create: `scripts/package.sh`
- Test: `scripts/package.test.sh` (a small bash assertion script)

**Interfaces:**
- Produces: `scripts/package.sh <PluginDir> <OutDir>` → writes `<OutDir>/<Name>-<version>.zip` whose single top-level entry is `<Name>/…`, excluding `Test/`, `.git/`, `*.md` dev scaffolding is KEPT (README is fine), and prints the artifact path. `<Name>` = basename of `<PluginDir>`; `<version>` read from `plugin.json`.

- [ ] **Step 1: Write the failing test** — `scripts/package.test.sh`

```bash
#!/usr/bin/env bash
# Smoke test for package.sh: builds a fixture plugin zip and asserts structure.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Fixture plugin
mkdir -p "$TMP/src/DemoPlugin/Test"
cat > "$TMP/src/DemoPlugin/Plugin.php" <<'PHP'
<?php
PHP
cat > "$TMP/src/DemoPlugin/plugin.json" <<'JSON'
{ "name": "DemoPlugin", "version": "2.3.4" }
JSON
echo "junk" > "$TMP/src/DemoPlugin/Test/foo.php"

OUT="$("$HERE/package.sh" "$TMP/src/DemoPlugin" "$TMP/out")"
[ -f "$TMP/out/DemoPlugin-2.3.4.zip" ] || { echo "FAIL: expected DemoPlugin-2.3.4.zip"; exit 1; }

# Top-level entry must be DemoPlugin/, and Test/ excluded
LIST="$(unzip -Z1 "$TMP/out/DemoPlugin-2.3.4.zip")"
echo "$LIST" | grep -q '^DemoPlugin/Plugin.php$' || { echo "FAIL: missing DemoPlugin/Plugin.php"; exit 1; }
echo "$LIST" | grep -q 'Test/' && { echo "FAIL: Test/ should be excluded"; exit 1; }

echo "package.test.sh PASS"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `chmod +x scripts/package.test.sh && ./scripts/package.test.sh`
Expected: FAIL — `package.sh` not found / not executable.

- [ ] **Step 3: Write minimal implementation** — `scripts/package.sh`

```bash
#!/usr/bin/env bash
# package.sh <PluginDir> <OutDir>
# Build a Kanboard-plugin release zip whose single top-level folder is the
# plugin name, excluding dev-only paths (Test/, .git/). Prints the artifact path.
set -euo pipefail

SRC="${1:?usage: package.sh <PluginDir> <OutDir>}"
OUT="${2:?usage: package.sh <PluginDir> <OutDir>}"

NAME="$(basename "$SRC")"
VERSION="$(grep -oE '"version"[[:space:]]*:[[:space:]]*"[^"]+"' "$SRC/plugin.json" | head -1 | sed -E 's/.*"([^"]+)"$/\1/')"
[ -n "$VERSION" ] || { echo "ERROR: could not read version from $SRC/plugin.json" >&2; exit 1; }

mkdir -p "$OUT"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Copy the plugin under a top-level folder = plugin name, excluding dev paths.
rsync -a --exclude '.git' --exclude 'Test' --exclude '.DS_Store' "$SRC/" "$STAGE/$NAME/"

ARTIFACT="$OUT/${NAME}-${VERSION}.zip"
rm -f "$ARTIFACT"
( cd "$STAGE" && zip -q -r "$ARTIFACT" "$NAME" )

echo "$ARTIFACT"
```

- [ ] **Step 4: Run test to verify it passes**

Run: `chmod +x scripts/package.sh && ./scripts/package.test.sh`
Expected: `package.test.sh PASS`. (Requires `rsync`, `zip`, `unzip` — install if missing.)

- [ ] **Step 5: Commit**

```bash
git add scripts/package.sh scripts/package.test.sh
git commit -m "feat(scripts): package.sh — build correctly-structured plugin release zips"
```

---

### Task 4: Publish Hello Harmozi to its own GitHub repo + v1.0.0 release  ⚠️ OUTWARD-FACING

**STOP** — confirm with the user: repo name `carmelosantana/HelloHarmozi`, **public**. Do not proceed without a yes.

**Files:** none in this repo (operates on GitHub + the HelloHarmozi working tree).

- [ ] **Step 1: Create the repo and push**

```bash
cd /home/carmelo/Projects/Kanboard/HelloHarmozi   # the HelloHarmozi working tree
git init -b main
git config user.name  "Carmelo Santana"
git config user.email "597820+carmelosantana@users.noreply.github.com"
git add -A
git commit -m "feat: Hello Harmozi 1.0.0 — a Hello Dolly homage for Kanboard"
gh repo create carmelosantana/HelloHarmozi --public --source=. --remote=origin --push
```

- [ ] **Step 2: Build the release artifact**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins
./scripts/package.sh /home/carmelo/Projects/Kanboard/HelloHarmozi /tmp/modmenu-artifacts
# -> /tmp/modmenu-artifacts/HelloHarmozi-1.0.0.zip
```

- [ ] **Step 3: Publish the GitHub release with the zip attached**

```bash
gh release create v1.0.0 /tmp/modmenu-artifacts/HelloHarmozi-1.0.0.zip \
  --repo carmelosantana/HelloHarmozi \
  --title "HelloHarmozi 1.0.0" \
  --notes "A random Alex Hormozi quote in your footer. A Hello Dolly homage."
```

- [ ] **Step 4: Verify the asset URL resolves**

```bash
curl -sIL https://github.com/carmelosantana/HelloHarmozi/releases/download/v1.0.0/HelloHarmozi-1.0.0.zip | grep -i '200\|content-type'
```
Expected: a successful (200) response for a zip. Record this exact URL — it goes into `plugins.json`.

---

### Task 5: Publish the existing 4 plugins as per-plugin releases  ⚠️ OUTWARD-FACING

**STOP** — confirm with the user: push this `kanboard-plugins` repo to `carmelosantana/kanboard-plugins` (**public**?) and cut per-plugin releases. Confirm visibility (this repo contains `testing/` scaffolding; ensure `testing/.env` stays git-ignored — verify before pushing).

- [ ] **Step 1: Pre-push safety check**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-plugins
git status --ignored --short | grep -E 'testing/\.env|kanboard-src' || true   # confirm ignored
git ls-files | grep -E 'testing/\.env' && { echo "ABORT: .env is tracked"; exit 1; } || echo "ok: .env not tracked"
```

- [ ] **Step 2: Push the repo** (branch `feat/modmenu-plugin-manager` or merge to `master` first per the user's finishing-a-branch choice)

```bash
gh repo create carmelosantana/kanboard-plugins --public --source=. --remote=origin --push
```

- [ ] **Step 3: Build + release each plugin** (repeat for ShadcnTheme, BulkProjectDelete, FeatureSync, SubtaskGenerator)

```bash
for P in ShadcnTheme BulkProjectDelete FeatureSync SubtaskGenerator; do
  ART="$(./scripts/package.sh "./$P" /tmp/modmenu-artifacts)"
  VER="$(basename "$ART" | sed -E "s/^$P-(.*)\.zip/\1/")"
  gh release create "${P}-v${VER}" "$ART" \
    --repo carmelosantana/kanboard-plugins \
    --title "${P} ${VER}" \
    --notes "Release ${VER} of ${P}."
done
```

- [ ] **Step 4: Record each asset URL** — form: `https://github.com/carmelosantana/kanboard-plugins/releases/download/<Plugin>-v<ver>/<Plugin>-<ver>.zip`. These go into `plugins.json`.

---

### Task 6: Stand up the directory repo (`kanboard-modmenu-directory`)  ⚠️ OUTWARD-FACING

**STOP** — confirm repo name `carmelosantana/kanboard-modmenu-directory`, **public**.

**Files:** (in a new working tree `/home/carmelo/Projects/Kanboard/kanboard-modmenu-directory/`)
- Create: `plugins.json`, `README.md`, `assets/hello-harmozi/screenshot-1.png` (+ other plugin screenshots as available).

- [ ] **Step 1: Author `plugins.json`** using the exact release URLs recorded in Tasks 4–5:

```json
[
  {
    "title": "Hello Harmozi",
    "name": "HelloHarmozi",
    "author": "Carmelo Santana",
    "description": "A random Alex Hormozi quote in your footer. A Hello Dolly homage.",
    "version": "1.0.0",
    "compatible_version": ">=1.2.47",
    "homepage": "https://github.com/carmelosantana/HelloHarmozi",
    "download": "https://github.com/carmelosantana/HelloHarmozi/releases/download/v1.0.0/HelloHarmozi-1.0.0.zip",
    "screenshots": ["assets/hello-harmozi/screenshot-1.png"]
  },
  {
    "title": "Shadcn Theme",
    "name": "ShadcnTheme",
    "author": "Carmelo Santana",
    "description": "Modern shadcn/ui-inspired theme with light/dark toggle.",
    "version": "1.0.1",
    "compatible_version": ">=1.2.47",
    "homepage": "https://github.com/carmelosantana/kanboard-plugins",
    "download": "https://github.com/carmelosantana/kanboard-plugins/releases/download/ShadcnTheme-v1.0.1/ShadcnTheme-1.0.1.zip",
    "screenshots": ["assets/shadcn-theme/screenshot-1.png"]
  }
]
```
(Add BulkProjectDelete, FeatureSync, SubtaskGenerator entries the same way, using their recorded release URLs and versions.)

- [ ] **Step 2: Write `README.md`** — explain the directory format field-by-field, show how to fork this repo and host your own `plugins.json`, and how to point ModMenu's **Sources** tab at it. This is the artifact users read to replicate the setup.

- [ ] **Step 3: Add screenshots** — capture a Hello Harmozi footer screenshot (from the live site after E2E install, Task 7) into `assets/hello-harmozi/screenshot-1.png`; add others as available.

- [ ] **Step 4: Push**

```bash
cd /home/carmelo/Projects/Kanboard/kanboard-modmenu-directory
git init -b main
git config user.name  "Carmelo Santana"
git config user.email "597820+carmelosantana@users.noreply.github.com"
git add -A
git commit -m "feat: initial ModMenu plugin directory (Hello Harmozi + suite)"
gh repo create carmelosantana/kanboard-modmenu-directory --public --source=. --remote=origin --push
```

- [ ] **Step 5: Verify** the raw URL serves valid JSON:

```bash
curl -s https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json | python3 -m json.tool >/dev/null && echo "plugins.json valid"
```
Expected: `plugins.json valid`. This URL is already ModMenu's `SourceRepository::DEFAULT_SOURCE`.

---

### Task 7: Live E2E — the full ModMenu lifecycle on :8081

**Files:**
- Create: `scripts/e2e/modmenu-loop.mjs`

**Preconditions:** ModMenu plugin plan implemented and mounted; container up (`docker compose -f testing/docker-compose.dev.yml up -d`); Tasks 4 & 6 done so the default source + Hello Harmozi release are live. Playwright available (`npx playwright` with channel `chrome`).

**Interfaces:** none (verification script).

- [ ] **Step 1: Write the Playwright script** — `scripts/e2e/modmenu-loop.mjs`

```javascript
// Full ModMenu lifecycle against the live kb-suite container on :8081.
// Run: node scripts/e2e/modmenu-loop.mjs
import { chromium } from 'playwright';

const BASE = 'http://localhost:8081';
const shots = [];
const errors = [];

function step(name, ok, detail = '') {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
  if (!ok) process.exitCode = 1;
}

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const ctx = await browser.newContext();
const page = await ctx.newPage();
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
page.on('pageerror', e => errors.push(String(e)));

// Login
await page.goto(`${BASE}/login`);
await page.fill('input[name="username"]', 'admin');
await page.fill('input[name="password"]', 'admin');
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');

const footerVisible = async () =>
  (await page.locator('.hello-harmozi').count()) > 0;

// 1. Browse — Hello Harmozi shows Install
await page.goto(`${BASE}/config/modmenu/directory`);
await page.waitForLoadState('networkidle');
step('Browse lists Hello Harmozi',
  (await page.getByText('Hello Harmozi').count()) > 0);

// 2. Install from the release URL
const installForm = page.locator('form[action*="install"]').filter({ has: page.locator('input[value*="HelloHarmozi-1.0.0.zip"]') }).first();
await installForm.locator('button[type="submit"]').click();
await page.waitForLoadState('networkidle');

// 3. Footer quote appears
await page.goto(`${BASE}/dashboard`);
await page.waitForLoadState('networkidle');
step('Footer quote appears after install', await footerVisible());
await page.screenshot({ path: 'scripts/e2e/shots/harmozi-installed.png' });

// 4. Disable -> quote gone
await page.goto(`${BASE}/config/modmenu`);
const disableForm = page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).locator('form[action*="disable"]').first();
await disableForm.locator('button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await page.goto(`${BASE}/dashboard`);
step('Footer quote gone after disable', !(await footerVisible()));

// 5. Enable -> quote back
await page.goto(`${BASE}/config/modmenu`);
const enableForm = page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).locator('form[action*="enable"]').first();
await enableForm.locator('button[type="submit"]').click();
await page.waitForLoadState('networkidle');
await page.goto(`${BASE}/dashboard`);
step('Footer quote returns after enable', await footerVisible());

// 6. Uninstall via confirm modal
await page.goto(`${BASE}/config/modmenu`);
await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).getByText('Remove').first().click();
await page.waitForSelectorState?.('#modal-box', 'visible').catch(() => {});
await page.locator('#modal-box button:has-text("Yes, remove it")').click();
await page.waitForLoadState('networkidle');
await page.goto(`${BASE}/dashboard`);
step('Footer quote gone after uninstall', !(await footerVisible()));

// 7. Upload path — re-install by uploading the same zip
await page.goto(`${BASE}/config/modmenu/upload`);
await page.setInputFiles('input[type="file"][name="plugin"]', '/tmp/modmenu-artifacts/HelloHarmozi-1.0.0.zip');
await page.click('form.modmenu-action button[type="submit"]');
await page.waitForLoadState('networkidle');
await page.goto(`${BASE}/dashboard`);
step('Footer quote appears after ZIP upload', await footerVisible());
await page.screenshot({ path: 'scripts/e2e/shots/harmozi-uploaded.png' });

// Clean up: uninstall so the dev site is left clean
await page.goto(`${BASE}/config/modmenu`);
await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).getByText('Remove').first().click();
await page.locator('#modal-box button:has-text("Yes, remove it")').click().catch(() => {});
await page.waitForLoadState('networkidle');

step('Zero console errors', errors.length === 0, errors.join(' | '));

await browser.close();
```

- [ ] **Step 2: Run it**

```bash
mkdir -p scripts/e2e/shots
node scripts/e2e/modmenu-loop.mjs
```
Expected: every `step(...)` prints `PASS`; screenshots written to `scripts/e2e/shots/`. If any step fails, read the ModMenu source it exercises, fix, bump ModMenu's version (asset cache-bust), reload, and re-run — do not edit the test to pass.

- [ ] **Step 3: Update the update-badge check** — after confirming the base loop, publish a `HelloHarmozi v1.0.1` (bump `plugin.json` to `1.0.1`, re-`package.sh`, `gh release create v1.0.1`, and add/update the entry in `plugins.json` to `1.0.1`). Then extend the script (or run manually) to: install 1.0.0 → Browse shows `⬆ Update to 1.0.1` → click Update → installed shows 1.0.1. Verify and screenshot `harmozi-update.png`.

- [ ] **Step 4: Commit the E2E script + screenshots**

```bash
git add scripts/e2e/modmenu-loop.mjs scripts/e2e/shots
git commit -m "test(ModMenu): live E2E of full install/enable/disable/update/uninstall + upload loop"
```

- [ ] **Step 5: Deliver evidence to the user** — share the PASS log + the `harmozi-installed.png`, `harmozi-uploaded.png`, and `harmozi-update.png` screenshots.

---

## Self-Review

- **Spec coverage:** Hello Harmozi footer plugin (Tasks 1–2, spec §6); GitHub Releases hosting (Tasks 4–5, spec §5.2); forkable directory repo + plugins.json format (Task 6, spec §5.1); `package.sh` (Task 3, spec §5.2); full E2E loop incl. upload + update (Task 7, spec §9). All map to spec sections.
- **Outward-facing gates:** Tasks 4, 5, 6 each open with an explicit STOP/confirm on repo name + public visibility (matches spec §5.2 and the operating rules on publishing).
- **Placeholder scan:** the only intentionally-deferred values are the recorded release URLs (produced in Tasks 4–5, consumed in Task 6) and the additional plugin `plugins.json` entries — these are data captured at run time, not code gaps; the shape is fully specified.
- **Consistency:** artifact naming (`<Name>-<version>.zip`, top-level `<Name>/`) is uniform across `package.sh`, the release steps, and the E2E upload path. The default source URL matches `SourceRepository::DEFAULT_SOURCE` from the ModMenu plan.
- **Cross-plan dependency:** stated up top — ModMenu plan lands first; this plan's E2E drives ModMenu's UI. `.env`/secrets guarded before any push (Task 5 Step 1).
```
