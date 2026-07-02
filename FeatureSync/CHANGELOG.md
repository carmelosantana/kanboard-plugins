# Changelog

## 0.1.0 — 2026-07-02

- Initial skeleton: plugin loads, admin-only Feature Sync page reachable from Settings sidebar.
- Controller `FeatureSyncController::index()` guards admin access; renders 5-step workflow shell.
- Step wiring (source → features → targets → preview → apply) to follow in tasks 02–06.
