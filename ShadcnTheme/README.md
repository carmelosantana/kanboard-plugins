# ShadcnTheme — Kanboard Plugin

A modern Kanboard theme inspired by [shadcn/ui](https://ui.shadcn.com/) design
principles.  Ships dark mode as the default, with a per-user light / dark / system
toggle, accessible task card colors, a branded login card, and an admin settings
page for uploading a custom favicon and logo.

> **Note:** Screenshots will be added in a follow-up.

---

## Features

| Feature | Detail |
|---|---|
| **Dark-first with no FOUC** | A synchronous script in `<head>` reads `localStorage` and sets the theme before first paint — no flash on page load. |
| **Light / dark / system toggle** | Dropdown in the page header.  Preference stored per user in `user_metadata`; guest users fall back to the session. |
| **shadcn/ui design tokens** | CSS custom properties (`--background`, `--foreground`, `--accent`, `--border`, `--radius`, etc.) plus `kbx-` primitive variables for a consistent palette across both themes. |
| **Dark-mode task colors** | All Kanboard task card color classes are overridden in dark mode with perceptually appropriate, WCAG AA-compliant shades. |
| **Header / navigation** | Top bar and sidebar correctly contrast in both light and dark themes. |
| **Login card** | Centered card layout with uploaded logo (or product name) above the form. |
| **Favicon & logo upload** | Admin-only settings page to upload a custom favicon and logo; served via a public-accessible endpoint so they appear on the login page. |
| **Header logo override** | Custom logo replaces the Kanboard wordmark in the top bar when configured. |

---

## Requirements

- **Kanboard** >= 1.2.47
- **PHP** >= 8.4
- **Browser** — an evergreen browser that supports `:has()` and `color-mix()` (Chrome 105+, Firefox 121+, Safari 15.4+)

---

## Installation

1. Download or clone this repository into your Kanboard `plugins/` directory:

   ```
   plugins/
   └── ShadcnTheme/
       ├── Plugin.php
       ├── plugin.json
       └── ...
   ```

   The directory name **must** be `ShadcnTheme` (case-sensitive).

2. In Kanboard, go to **Settings → Plugins** and confirm ShadcnTheme appears as
   enabled.  No further configuration is required — the dark theme loads immediately.

---

## Using the theme toggle

The theme toggle appears in the user dropdown in the top-right of every page.
Click it to cycle between **Dark**, **Light**, and **System** (follows the OS
preference).  Your choice is saved per-user and remembered across sessions.

---

## Theme settings (favicon & logo upload)

Admin users can upload a custom favicon and logo at **Settings → Theme**
(`/shadcn-theme/settings`).

- **Logo** — displayed in the top-bar brand area and above the login form.
  Accepted formats: `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.webp` (max 2 MB).
- **Favicon** — replaces the default Kanboard favicon.
  Accepted formats: `.ico`, `.png`, `.svg` (max 2 MB).

Files are stored via Kanboard's ObjectStorage (`data/files/shadcn-theme/…`) and
served through a public-accessible route so they are visible even on the login page.
Existing files can be removed using the "Remove" checkboxes on the settings page.

---

## Development

Tests live in `Test/` and run against the Kanboard 1.2.47 core source:

```bash
# From the kanboard-plugins repo root:
./testing/run-plugin-tests.sh ShadcnTheme
```

See [`CHANGELOG.md`](CHANGELOG.md) for what shipped in each release.

---

## License

MIT — see [LICENSE](LICENSE) for full text.
