# ModMenu — Kanboard Plugin Manager (Design Spec)

**Date:** 2026-07-03
**Status:** Approved (brainstorming) → ready for implementation planning
**Author:** Carmelo Santana

---

## 1. Summary

**ModMenu** is a standalone Kanboard plugin that turns Kanboard into a
self-service plugin manager — think of a GTA-style "mod menu" for your board.
It lets an admin **browse, install, upload, enable/disable, update, and
uninstall** plugins from within the Kanboard UI, sourced from one or more
plugin **directory** URLs.

Kanboard core already ships a plugin system (`PluginController` +
`Core\Plugin\Installer` + `Core\Plugin\Directory`) that can install / update /
uninstall from a URL and read a single hardcoded directory JSON. But it is
**disabled by default** (`PLUGIN_INSTALLER=false`, for security) and lacks the
features this project delivers. ModMenu is **self-contained** — it does its own
download/extract, enable/disable, and directory fetching, and works even when
core's `PLUGIN_INSTALLER` flag is off.

### Gap analysis (why ModMenu exists)

| Capability | Kanboard core | ModMenu |
|---|---|---|
| Install from URL | ✅ | ✅ |
| Uninstall (full removal) | ✅ | ✅ |
| **Enable / Disable** (hide dir, restore) | ❌ | ✅ |
| **Upload a zip** (WordPress-style) | ❌ (URL only) | ✅ |
| **Multiple / custom repo sources** | ❌ (one hardcoded URL) | ✅ |
| **"Already installed / update available" status** | ⚠️ minimal | ✅ |
| **Update detection + one-click update** | ⚠️ manual URL | ✅ badges |
| Works with `PLUGIN_INSTALLER=false` | ❌ | ✅ |

---

## 2. Goals & non-goals

### Goals
- One coherent admin UI to manage the full plugin lifecycle.
- Install from a directory listing (multiple sources, ours bundled as default).
- Upload a `.zip` and have the backend unzip + install it (WP-style).
- Enable/Disable a plugin by physically moving its folder (data preserved).
- Detect updates by comparing installed version to a source's listed version.
- A public, forkable **directory repo** users can view to learn the format and
  replicate on their own site.
- An end-to-end proof: a demo plugin, **Hello Harmozi**, exercised through the
  entire download → install → enable/disable → update → uninstall loop, plus the
  upload path.

### Non-goals (v1)
- No dependency resolution between plugins.
- No automatic/background updates (updates are one-click, admin-initiated).
- No plugin ratings/reviews/telemetry.
- No rollback of a plugin's DB schema on disable/uninstall (matches WordPress:
  disable preserves data; uninstall removes files only).
- Not splitting the existing `kanboard-plugins` monorepo into per-plugin repos.

---

## 3. Architecture

**Decision (approved): self-contained.** ModMenu implements its own logic rather
than wrapping core's gated `Installer`. This makes it a standalone plugin
manager independent of core's `PLUGIN_INSTALLER` flag and avoids a second,
redundant core `/plugin` UI appearing alongside ours.

Plugin name / namespace: `ModMenu` / `Kanboard\Plugin\ModMenu`. Admin-only.
Adds a **"ModMenu"** entry to the Settings sidebar linking to a full-page UI
(not a modal), organized as tabs: **Installed · Browse · Upload · Sources**.

### 3.1 Components (each has one clear responsibility)

| Unit | Responsibility | Depends on |
|---|---|---|
| `Plugin.php` | Register routes, admin sidebar link, load CSS/JS assets. No business logic. | Kanboard hooks |
| `Controller/ModMenuController.php` | Render the 4 tabs; handle enable / disable / uninstall / update / install-from-directory actions. Admin gate + CSRF. Thin — delegates to models. | `PluginManager`, `DirectoryClient`, `SourceRepository` |
| `Controller/UploadController.php` | Handle the `.zip` file upload → validate → install. Admin gate + CSRF. | `PluginArchive`, `PluginManager` |
| `Model/PluginManager.php` | Engine: list all plugins (active **and** disabled), enable, disable, uninstall, install-from-url, version-compare for updates, self-protection. | `PluginArchive` |
| `Model/PluginArchive.php` | Safe zip **validation + extraction**, shared by URL-install and upload. Path-traversal guard, structure checks, atomic extract-to-temp-then-move. | `ZipArchive` |
| `Model/DirectoryClient.php` | Fetch each source's `plugins.json`, merge, annotate each entry with installed/update status. | `SourceRepository`, `PluginManager`, httpClient |
| `Model/SourceRepository.php` | Persist the list of source URLs (in `configModel`, key `modmenu_sources`); seed our default source. | `configModel` |

### 3.2 State model — no new DB table

ModMenu derives the full plugin list by scanning **two directories**:

- **Active plugins:** `PLUGINS_DIR` (`/var/www/app/plugins`) — via Kanboard's
  `pluginLoader` for loaded plugins, plus a raw dir scan to catch any not yet
  loaded.
- **Disabled plugins:** `data/modmenu_disabled/` (under the persistent `data`
  volume, **outside** the scan path so Kanboard never loads them). Each disabled
  plugin's `name` / `version` is read from its on-disk `plugin.json`.

The only persisted config is the **source URL list** (`configModel` key
`modmenu_sources`), a JSON array seeded with the default directory URL.

### 3.3 Enable / Disable mechanism

Enable/disable **physically moves the plugin folder** between `PLUGINS_DIR` and
`data/modmenu_disabled/`. This is the only reliable mechanism: Kanboard scans
`PLUGINS_DIR` at every request bootstrap and there is no loader hook to skip an
individual plugin. Effect is immediate on the next page load — **no restart**.

- **Disable:** move `PLUGINS_DIR/<Name>` → `data/modmenu_disabled/<Name>`.
- **Enable:** move `data/modmenu_disabled/<Name>` → `PLUGINS_DIR/<Name>`.
- Disabling does **not** touch the DB/schema (data preserved, WP-style).

**Known constraint:** a **bind-mounted** plugin directory cannot be moved from
inside the container (you can't relocate a mount point). In the dev suite the
four existing plugins are bind-mounted, so disable/uninstall will fail on them
with a clear, plain-language error. This is *why* **Hello Harmozi** — installed
via zip into a folder ModMenu genuinely owns — is the clean end-to-end test
vehicle.

---

## 4. Feature behaviors

- **Browse** — merged listing from all configured sources. Each card shows
  title / description / version, screenshots (from the directory's asset URLs),
  and a status chip: `Install` / `✓ Installed` / `⬆ Update available` /
  `Disabled`.
- **Install (from directory)** — download the release-asset zip →
  `PluginArchive` validates + extracts into `PLUGINS_DIR`.
- **Update** — same path as install, but the old folder is removed first.
  Surfaced as an `⬆ Update available` badge when a source lists a higher
  `version` than the installed one (compared with `version_compare` /
  Kanboard's `Version`).
- **Enable / Disable** — move the folder in/out of `data/modmenu_disabled/`.
- **Uninstall** — confirm modal → remove the folder (from whichever dir holds
  it).
- **Upload** — choose a `.zip` → validate → extract into `PLUGINS_DIR`.
- **Sources** — list/add/remove directory source URLs; our directory ships as
  the built-in default (removable but re-addable).
- **Self-protection** — ModMenu can never disable or uninstall **itself**
  (button hidden in UI **and** guarded server-side).
- **Not-configured banner** — if `PLUGINS_DIR` is not writable or the PHP `zip`
  extension is missing, show a clear explainer (mirrors core's
  `Installer::isConfigured()`), including the bind-mount caveat.

### UI implementation constraints (from prior live E2E findings)
- All JS in **external** `Assets/js/*.js` injected via `template:layout:js`;
  **no inline `<script>`** (blocked by CSP `default-src 'self'`).
- Use **document-level event delegation** — Kanboard clones dropdown/menu
  `<ul>`s and re-renders modals via innerHTML, so directly-bound listeners die.
- `KB.http.postJson()` / `KB.http.get()` return a **request object**
  (`.success()/.error()`), **not** a Promise — never call `.then()`.
- Confirm/upload dialogs use Kanboard modals; read-only modals should close on
  backdrop click via `KB.modal.close()`, but never a modal containing a `<form>`
  (data-loss guard).
- Inline `<style>` / `style=""` **is** allowed by CSP (`style-src
  'unsafe-inline'`).

---

## 5. Directory repo & hosting

### 5.1 Directory repo (new, public): `carmelosantana/kanboard-modmenu-directory`

The forkable "listings" repo users view to learn the format and replicate.

```
kanboard-modmenu-directory/
  plugins.json          # machine-readable listing ModMenu fetches
  README.md             # how to structure your own directory + host releases
  assets/
    hello-harmozi/screenshot-1.png
    shadcn-theme/screenshot-1.png
    ...
```

`plugins.json` — array of entries; a superset of Kanboard's official schema so
it stays compatible with core's `Directory`:

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
  }
]
```

Fields consumed by ModMenu: `title`, `name`, `author`, `description`,
`version`, `compatible_version`, `homepage`, `download`, `screenshots`
(optional). Screenshot paths are resolved relative to the source's base URL.

### 5.2 Hosting model — GitHub Releases

- **Hello Harmozi** → its own repo `carmelosantana/HelloHarmozi`, with a
  `v1.0.0` GitHub **release** and a correctly-structured
  `HelloHarmozi-1.0.0.zip` (top-level folder = `HelloHarmozi/`) attached as a
  release asset. It is both the E2E test artifact and the reference "how to
  structure a plugin repo" example. **Pushed to GitHub, never into the test
  site's mounts.**
- **The 4 existing plugins** → push the current `kanboard-plugins` monorepo to
  GitHub (`carmelosantana/kanboard-plugins`) and cut **per-plugin tagged
  releases** on it (tag `<Name>-v<version>` → asset `<Name>-<version>.zip`).
  GitHub allows many tags/releases per repo, so no monorepo split is needed.
- **`package.sh`** — a small script that builds a clean `<Name>-<version>.zip`
  (top-level dir = `<Name>/`, excluding `Test/`, `.git`, dev scaffolding) and
  attaches it via `gh release create`. One script serves all plugins + Hello
  Harmozi.

**Outward-facing actions gate:** creating repos, pushing code, and publishing
releases are outward-facing. Implementation will pause for explicit go-ahead on
exact repo names and **public** visibility before anything is pushed. (The push
is authorized in principle by the user; the confirmation is per-action.)

---

## 6. Hello Harmozi (demo plugin)

Tiny homage to WordPress's Hello Dolly. A `template:layout:footer` hook (or the
nearest available footer hook) renders **one random quote** from a bundled
static array of ~20 Alex Hormozi lines, as a quiet footer line:

```
─────────────────────────────────────────────
Kanboard · "Discipline is doing it when you don't feel like it." —Alex Hormozi
```

Pure PHP where possible; any JS is external + CSP-safe. Its appearance /
disappearance in the footer is the live signal that install → enable/disable →
uninstall worked.

---

## 7. Security

ModMenu installs and runs code, so it is treated as a privileged tool with the
same trust boundary as WordPress's plugin installer:

- **Admin-only** (`isAdmin()`) on **every** controller action.
- **CSRF** token on **every** mutating action.
- `PluginArchive` validation:
  - HTTPS-only download URLs (reject non-`https?://`, validate URL).
  - Size cap and entry-count cap on the archive.
  - **Reject** any entry with `..` or an absolute path (traversal guard).
  - Require exactly **one top-level directory** containing a `Plugin.php`.
  - Refuse to overwrite an existing plugin unless it is an explicit **update**;
    never overwrite ModMenu itself.
  - Extract to a **temp dir first**, then atomic-move into place (no partial
    installs).
- Directory JSON is untrusted **data** — fields validated, never executed. A
  bad/unreachable source errors **in isolation**; other sources still render.

---

## 8. Error handling

- Non-writable `PLUGINS_DIR` / missing `zip` extension → not-configured banner
  with the specific reason.
- Bind-mounted plugin disable/uninstall → plain-language failure ("this
  plugin's folder can't be moved — it looks bind-mounted").
- Download / extraction failure → temp cleanup + reported flash message;
  installed state never left partial.
- Bad JSON / unreachable source → per-source error surfaced; others unaffected.

---

## 9. Testing

### PHPUnit
- `PluginArchive` — traversal rejection, structure validation (single top dir +
  `Plugin.php`), good/bad zip fixtures, size/entry caps.
- `PluginManager` — enable/disable folder-move logic (temp dirs), self-
  protection, version-compare for updates, uninstall.
- `DirectoryClient` — merge across sources + installed/update annotation from
  fixture JSON, per-source error isolation.
- `SourceRepository` — seed default, add/remove/persist.
- Controllers — admin-gate enforcement + CSRF on mutations.

### Live E2E (Playwright on :8081, per suite practice)
The **full Hello Harmozi loop**:
1. Browse → Hello Harmozi shows `Install`.
2. Install from the real release URL → footer quote appears.
3. Disable → footer quote gone; plugin shows `Disabled`.
4. Enable → footer quote returns.
5. Publish `v1.0.1` release → `⬆ Update available` badge → Update → new version
   live.
6. Uninstall → gone.
7. **Upload path** — upload `HelloHarmozi-1.0.0.zip` directly → installed.

Evidence: screenshots at each step + zero console errors.

---

## 10. Build order (for the implementation plan)

1. **ModMenu plugin** (manager) + PHPUnit suite.
2. **Hello Harmozi** plugin + its GitHub repo + `v1.0.0` release.
3. **Directory repo** + `plugins.json` + `package.sh`; push existing 4 plugins +
   per-plugin releases; wire our directory as ModMenu's default source.
4. **Live E2E** of the whole loop (install/enable/disable/update/uninstall +
   upload); fix findings; screenshots.

---

## 11. Open items to confirm at implementation time
- Exact repo names + **public** visibility before any push (outward-facing gate).
- Final list of ~20 Hormozi quotes for Hello Harmozi.
- Default directory source URL form (raw.githubusercontent vs GitHub Pages) once
  the directory repo exists.
