# Changelog

All notable changes to ModMenu are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.1.0] — 2026-07-09

### Added

- **Plugin dependency system.** Plugins declare `requires` (hard) and `recommends` (soft) dependencies in `plugin.json` (mirrored in the directory `plugins.json`).
  - `requires` blocks enable/install until each dependency is installed, active, and ≥ its `min_version`, with a one-click resolve that installs/enables the whole chain (transitive, deps-first).
  - Reverse protection: a plugin an active dependent hard-requires cannot be disabled or uninstalled — ModMenu names the dependents instead.
  - `recommends` surfaces a non-blocking "works better with" hint plus one-click install on the Installed and Browse tabs.
- New pure `DependencyResolver` model (classify / transitive plan / reverse dependents), fully unit-tested.

---

## [1.0.1] — 2026-07-03

### Fixed

- **List bullets removed** from the Sources list (`ul.modmenu-sources`), which
  rendered default disc bullets in the left margin outside the card content.

---

## [1.0.0] — 2026-07-03

### Added

- Standalone admin plugin manager with four tabs: Installed, Browse, Upload, Sources.
- Install from a directory source (multiple sources; ships a bundled default).
- WordPress-style `.zip` upload with safe validation (single top-level dir + Plugin.php, path-traversal + size/entry caps).
- Enable/Disable by moving a plugin folder between `plugins/` and `data/modmenu_disabled/` (data preserved; no restart).
- Update detection ("update available" badge) via installed-vs-directory version compare, with one-click update.
- Uninstall with a typed confirmation modal.
- Self-protection: ModMenu can never disable or uninstall itself.
- PHPUnit suite: PluginArchive, PluginManager, SourceRepository, DirectoryClient, controller admin gates.
