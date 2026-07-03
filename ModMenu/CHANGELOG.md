# Changelog

All notable changes to ModMenu are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
