# Changelog

All notable changes to ShadcnTheme are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
