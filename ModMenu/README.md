# ModMenu — Kanboard Plugin Manager

A standalone plugin manager for Kanboard. Install from directory sources, upload
a zip, enable or disable installed plugins, detect and apply updates, and
uninstall — all from one admin settings page, with no server restart required.

> **Note:** Screenshots will be added after the companion directory and Hello Harmozi
> demo plugin are published.

---

## What it does

ModMenu adds a **Settings → ModMenu** page with four tabs:

| Tab | Purpose |
|---|---|
| **Installed** | Lists every plugin currently in `plugins/` (active) or `data/modmenu_disabled/` (disabled), with enable, disable, and remove actions. |
| **Browse** | Fetches plugin listings from the configured directory sources and shows install/update buttons for each entry. |
| **Upload** | WordPress-style zip upload — drop a `.zip` archive to install a plugin directly. |
| **Sources** | Manage the directory source URLs that Browse fetches from. Ships with one bundled default source. |

---

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4 with the `zip` extension enabled
- The `plugins/` directory must be writable by the web-server process

If either the `zip` extension or write permission is missing, ModMenu shows an
explanatory banner on the Installed tab and disables all mutation actions.

---

## Installation

1. Download or clone this repository into your Kanboard `plugins/` directory:

   ```
   plugins/
   └── ModMenu/
       ├── Plugin.php
       ├── plugin.json
       └── ...
   ```

   The directory name **must** be `ModMenu` (case-sensitive).

2. In Kanboard, go to **Settings → ModMenu** to confirm the plugin loaded.
   No database migration is needed.

---

## Enable / Disable mechanism

ModMenu toggles plugins by **moving their folder** between two locations on disk
— no database entry, no restart:

| State | Folder location |
|---|---|
| Active | `plugins/<PluginName>/` |
| Disabled | `data/modmenu_disabled/<PluginName>/` |

Enabling a disabled plugin moves it back into `plugins/`. Because the folder is
moved (not copied or deleted), all plugin data and configuration are fully
preserved during a disable/enable cycle.

### Bind-mount caveat

When Kanboard runs in Docker with a plugin folder bind-mounted from the host
(the typical dev-suite setup), the container cannot move or delete that folder
— the OS refuses to rename or rmdir a mount point. As a result:

- **Bind-mounted plugins** (e.g. the four dev-suite plugins) **cannot be
  disabled, enabled from the disabled state, or uninstalled** via ModMenu.
  ModMenu will show an error flash when you try.
- **Zip-installed plugins** live inside the named Docker volume (`/var/www/app/plugins`
  is writable), so install, enable, disable, and uninstall all work normally for
  those.

ModMenu surfaces the appropriate error message when a folder-move fails so the
cause is clear.

---

## Security posture

| Concern | What ModMenu does |
|---|---|
| **Admin-only** | Every controller action calls `isAdmin()` at the top; non-admins receive `AccessForbiddenException`. |
| **CSRF protection** | Every mutation POST is guarded by Kanboard's standard `checkCSRFForm()`. |
| **Zip validation (size)** | Archives larger than 50 MB are rejected before opening. |
| **Zip validation (entry count)** | Archives with more than 5 000 entries are rejected. |
| **Zip validation (structure)** | Archive must contain exactly one top-level directory, and that directory must contain a `Plugin.php`. Any other structure is rejected. |
| **Path-traversal protection** | Every zip entry name is checked: entries starting with `/`, containing `..`, or containing `\` are rejected. |
| **Self-protection** | ModMenu cannot disable, uninstall, or install over itself. |

---

## Directory sources and `plugins.json` format

The **Browse** tab fetches a `plugins.json` file from each configured source URL
and displays the results. The **Sources** tab lets you add or remove source URLs.
ModMenu ships with one default source; you can point it at any `https://` URL that
returns a JSON array of plugin objects.

### Adding a custom source

1. Go to **Settings → ModMenu → Sources**.
2. Enter the full `https://` URL to a `plugins.json` file and click **Add source**.
3. Switch to the **Browse** tab — ModMenu fetches all sources and merges the results
   (first source wins for duplicate plugin names).

### `plugins.json` field reference

Each entry in the JSON array is an object. All fields are optional except `name`.

| Field | Type | Description |
|---|---|---|
| `name` | string | **Required.** Must match the plugin folder name exactly (case-sensitive). Used as the unique identifier for status checks. |
| `title` | string | Human-readable display name shown in the Browse tab. Falls back to `name` if absent. |
| `author` | string | Plugin author name. |
| `description` | string | Short description shown under the title. |
| `version` | string | Semantic version string (e.g. `"1.2.0"`). Used for update detection: if this is greater than the installed version, an "Update available" badge appears. |
| `compatible_version` | string | Minimum Kanboard version (e.g. `">=1.2.47"`). Informational; ModMenu does not enforce this. |
| `homepage` | string | URL to the plugin's home page or repository. |
| `download` | string | URL to the `.zip` archive. ModMenu downloads this URL when the admin clicks Install or Update. |
| `screenshots` | array | List of screenshot URLs (or paths relative to the `plugins.json` URL). Displayed as thumbnails in the Browse tab. |

Example:

```json
[
  {
    "name": "BulkProjectDelete",
    "title": "Bulk Project Delete",
    "author": "Carmelo Santana",
    "description": "Delete multiple projects in one action.",
    "version": "1.0.1",
    "compatible_version": ">=1.2.47",
    "homepage": "https://github.com/carmelosantana/BulkProjectDelete",
    "download": "https://github.com/carmelosantana/BulkProjectDelete/archive/refs/heads/main.zip",
    "screenshots": ["screenshots/list.png"]
  }
]
```

---

## Development

Tests live in `Test/` and run against the Kanboard 1.2.47 core source:

```bash
# From the kanboard-plugins repo root:
./testing/run-plugin-tests.sh ModMenu
```

See [`CHANGELOG.md`](CHANGELOG.md) for what shipped in each release.

---

## License

MIT — see [LICENSE](LICENSE) for full text.
