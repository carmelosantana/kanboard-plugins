# Changelog

All notable changes to ShadcnTheme are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-07-03

### Fixed

- **No flash of unstyled (white) content between page navigations** — the theme
  class was only applied by `theme-switcher.js` at the end of `<body>`, so every
  full-page navigation painted the default white page before flipping to dark.
  The no-FOUC logic now lives in an external, **blocking** `<script>` in `<head>`
  (`Assets/js/theme-preload.js`; Kanboard's CSP blocks inline scripts and
  `asset->js()` adds `defer`), which stamps the theme class on `<html>` before the
  first paint. A base rule paints the dark surface for `html.theme-dark`. Verified
  the first `requestAnimationFrame` already reports the dark background.
- **Text inputs now line up with selects** — inputs rendered ~42px (content-box +
  `display:flex`) while selects were ~32px, so the two controls were visibly
  misaligned (e.g. Email settings). Inputs are now `box-sizing: border-box` with
  no flex, matching selects at 32px.

### Changed

- **List bullets removed from `.listing` containers** — Kanboard's `.listing`
  `<ul>` rows are data rows, not prose, so the default disc bullets (which render
  outside the content in the left margin) are gone. Markdown prose keeps its
  bullets.

## [1.0.1] - 2026-07-03

### Fixed

- **Theme-toggle / "System" icon now appears in the user dropdown** — the icon was
  driven by an inline `<script>` in `header/theme_toggle.php`, which Kanboard's
  `default-src 'self'` CSP blocks, so no icon ever activated. Activation moved to the
  external, CSP-safe `theme-switcher.js`; the system icon is the default so an icon
  always paints. Removed a duplicate dropdown entry the switcher was injecting.
- **Theme toggle is now clickable** — Kanboard clones the dropdown `<ul>` when it
  opens, so the visible copy carried no click listener (direct binding was dead on
  the clone). Switched to a document-level delegated handler that catches clicks on
  any copy. The toggle label also keeps its "Theme:" prefix so it reads as a theme
  control, not a "System" nav link, and its icon is aligned (fa-fw width) with the
  sibling dropdown items.
- **`saveThemeToServer` no longer throws** — it called `.then()` on
  `KB.http.postJson()`, which returns a request object (`.success()/.error()`), not a
  Promise; every theme toggle raised an uncaught "then is not a function". Now uses
  the correct request API inside a try/catch.
- **Backdrop click dismisses read-only modals** — the theme's modal handler hid the
  modal box on backdrop click but never removed the overlay (it re-clicked an overlay
  that has no core listener, since Kanboard opens medium/large/small modals with
  `overlayClickDestroy=false`), leaving a stuck dimmed screen (e.g. "My activity
  stream"). It now closes the modal properly — but never a modal containing a
  `<form>`, matching Kanboard's data-loss guard.

### Changed (design pass round 2)

- **Board columns are borderless** — removed the boxed outline around each column
  (and the global table hairlines on the board) for a cleaner surface.
- **Task cards stand out more** — colour tint raised (20% → 28%), a soft elevation
  shadow added, and task titles are brighter + semibold for readability.

### Changed

- **Minimalist ("Apple-like") pass across the dark theme:**
  - **Forms** — unified all field/fieldset borders to the single subtle `--border`
    hairline (`--input` lowered to match); focus is now one soft ring instead of a
    hard outline + heavy box-shadow.
  - **Tables** — removed the rounded, muted-filled header "pill" and table
    shadow/overflow; headers are now flat with hairline row dividers.
  - **Fieldsets** — flattened: no boxed border with a legend perched on it; the
    legend renders as a plain section label separated by whitespace.
  - **Projects list** — flat, transparent list header with hairline dividers
    (fewer borders).
  - **Typography** — condensed heading scale so more content fits on screen.
  - **Comments** — rounded, padded card backgrounds.
  - **Dropdown links** — neutral white text; the violet accent is reserved for the
    hover background.
  - **Colored task cards** — stronger colour tint (12% → 20%) and a 4px left rail so
    they stand out; card titles/links forced to legible light foreground.

## [1.0.0] - 2026-07-02

### Added

- **Dark-first theme with no FOUC** — a synchronous inline script injected in `<head>`
  reads `localStorage` and applies the `data-theme` attribute before first paint,
  eliminating the flash-of-unstyled-content on dark mode.
- **Light / dark / system theme toggle** — per-user preference is stored in
  `user_metadata` (`shadcn_theme_mode`) and exposed through a dropdown entry in the
  page header.  Guest users fall back to the `$_SESSION` value.
- **shadcn/ui-inspired design tokens** — CSS custom properties for color, radius,
  shadow, and typography in both `shadcn-light.css` and `shadcn-dark.css`.
  Accent and contrast tokens (`--accent`, `--accent-foreground`, `--destructive`, etc.)
  plus `kbx-` primitive variables provide a consistent palette across light and dark
  variants.
- **Dark-mode task colors (B1/B3 fix)** — all Kanboard task card color classes
  (`.color-yellow`, `.color-blue`, etc.) are overridden in dark mode with accessible,
  perceptually appropriate shades so colored tasks remain readable on dark backgrounds.
- **Header / navigation fix (B2)** — the top-bar and sidebar navigation are correctly
  styled in both themes; text and icon contrast meet WCAG AA on dark backgrounds.
- **Login card** — the login page receives a centered card layout with the uploaded
  logo (or product name) above the form fields and cohesive branding styles
  (`shadcn-login.css`, `auth/login_header` template hook).
- **Favicon / logo upload settings page** — an admin-only settings page
  (`/shadcn-theme/settings`) allows uploading a custom favicon (`.ico`, `.png`, `.svg`)
  and logo (`.png`, `.jpg`, `.gif`, `.svg`, `.webp`).  Files are stored via Kanboard's
  ObjectStorage and paths are persisted in `configModel`.  A `serveAsset` endpoint
  delivers files to unauthenticated users so the favicon and logo appear on the login
  page.
- **Header logo override** — when a logo is configured the top-bar brand fragment is
  replaced via `template:layout:head` and a `setTemplateOverride` so the custom image
  appears instead of the Kanboard wordmark.
- **Admin sidebar link** — a "Theme" entry is added to the Settings sidebar for quick
  navigation to the plugin's settings page.
- **PHPUnit test suite** — `PluginTest`, `SettingsControllerTest`, and
  `ThemeControllerTest` covering plugin metadata, access control (admin gate, CSRF),
  configModel persistence, and userMetadata theme-mode round-trips.

[1.0.0]: https://github.com/carmelosantana/kanbanboard-shadcn-theme/releases/tag/v1.0.0
