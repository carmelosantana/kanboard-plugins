# ModMenu Plugin Dependency System — Design

- **Date:** 2026-07-09
- **Status:** Approved (brainstormed this session) → writing-plans → subagent-driven-development
- **Plugin:** `ModMenu` (in `kanboard-plugins`, Kanboard v1.2.47+, PHP ≥ 8.4, buildless, MIT)
- **Goal:** Let plugins declare dependencies on other plugins — **hard** (`requires`, gates activation) and **soft** (`recommends`, prompts an easy install) — and have ModMenu enforce/surface them at the points where it controls plugin state.

---

## 1. Problem & scope

Some suite plugins are *better together* (DependencyPlugin's calendar badges need CalendarPlugin ≥ 1.1.0; SchedulerPlugin's badges/skip-blocked lean on CalendarPlugin + DependencyPlugin). Future plugins will be *hard-dependent*. ModMenu is the one place that installs/enables/disables/removes plugins, so it is where dependency awareness belongs.

### Key architectural constraint

**Kanboard core has no dependency concept.** `Core/Plugin/Base.php` exposes only name/version/author/homepage/compatible-version; `Core/Plugin/Loader.php` scans `plugins/` in directory order and initializes every folder with no ordering guarantee, gate, or runtime dependency check.

Therefore this system governs **ModMenu's own actions**, not the raw PHP loader. ModMenu "activates" a plugin by moving its folder into `plugins/`; the guarantee is: *ModMenu will not enable/install a plugin whose hard dependencies are unsatisfied, and will not disable/remove a plugin that an active plugin hard-requires.* It cannot stop a folder already present in `plugins/` (bind-mounted or hand-dropped) from loading — that remains each plugin's own graceful-degradation responsibility (the suite already soft-no-ops).

**Bind-mount note:** the dev-suite plugins are bind-mounted and already cannot be disabled/enabled/uninstalled via ModMenu. Enforcement therefore has teeth for **zip-installed** plugins in the writable volume; metadata *display* (hints, pre-flight) applies to all.

**Backward compatibility:** both dependency keys are optional. Absent ⇒ empty dep list ⇒ zero behavior change for every existing plugin and catalog entry.

---

## 2. Metadata schema

Two kinds, each an array of **dep objects**. Authoritative in the plugin's own `plugin.json`; mirrored into the directory `plugins.json`. Because `DirectoryClient::merge()` copies the whole entry object, the mirror is carried through with no new catalog-parsing code — only the resolver reads the fields.

A dep object:

```jsonc
{ "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "adds blocked/blocker badges on calendar events" }
```

- `plugin` (**required**, string) — the folder-name identifier, the same key `installedMap()` uses.
- `min_version` (optional, string) — minimum installed version that satisfies; absent ⇒ any version. Compared via `version_compare($installed, $min, '>=')`.
- `reason` (optional, string) — short human string shown in the prompt/block message.

Declared under two keys:

```jsonc
{
  "name": "DependencyPlugin",
  "version": "1.0.0",
  "requires":   [ { "plugin": "CalendarPlugin", "min_version": "1.1.0" } ],
  "recommends": [ { "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "calendar badges" } ]
}
```

- **`requires`** = hard — gates activation (forward) and is reverse-protected.
- **`recommends`** = soft — never blocks; drives the easy-install prompt.

Malformed entries (missing `plugin`, non-array value, non-object element) are ignored defensively, not fatal.

---

## 3. `DependencyResolver` (pure model, no I/O)

All dependency logic lives here as pure functions of its inputs — the same testable shape as `DirectoryClient`'s pure methods. `PluginManager` gathers the maps and delegates.

### Per-dep status → action

| status | meaning | forward `requires` | action |
|---|---|---|---|
| `satisfied` | installed, active, version ≥ min (or no min) | ok | `none` |
| `disabled` | installed but parked in the disabled dir | unsatisfied | `enable` |
| `outdated` | active but version < min | unsatisfied | `update` (if catalog has a newer version) else `unresolvable` |
| `missing` | not on disk at all | unsatisfied | `install` (if in catalog) else `unresolvable` |

### API

```php
// Resolve a plugin's declared deps of one kind against current state + catalog.
resolveForward(array $deps, array $installedMap, array $catalog): array
// → [
//     'satisfied' => bool,                    // true iff every dep is status=satisfied
//     'deps' => [
//        [ 'plugin' => 'CalendarPlugin', 'kind' => 'requires'|'recommends',
//          'status' => 'satisfied'|'disabled'|'outdated'|'missing',
//          'installed_version' => '1.0.0'|null, 'min_version' => '1.1.0'|null,
//          'reason' => '...'|'', 'action' => 'none'|'enable'|'update'|'install'|'unresolvable',
//          'download' => 'https://...'|null ],  // download present when action ∈ {install, update}
//        ...
//     ],
//   ]

// Which ACTIVE installed plugins hard-require $target (and are currently satisfied by it)?
resolveReverse(string $target, array $installedPluginsDeps, array $installedMap): array
// $installedPluginsDeps: [ pluginName => ['status'=>..., 'requires'=>[dep objects]], ... ]
// → [ [ 'plugin' => 'DependencyPlugin', 'min_version' => '1.1.0' ], ... ]   (empty ⇒ safe to disable/remove)

// Transitive closure of unmet `requires` for a forward resolve, walking catalog `requires`.
resolveClosure(string $pluginName, array $installedMap, array $catalog): array
// → ordered list of {plugin, action, download} to execute deps-first (deduped; stops at satisfied nodes)
```

- `installedMap` (existing) = `name → { version, status }`.
- `catalog` = merged directory listing, `name → entry` (entry carries `version`, `download`, `requires`, `recommends`).
- `installedPluginsDeps` = per-installed-plugin `{status, requires}`, built by `PluginManager` from `readMeta()`.

Purity: the resolver performs no filesystem or network I/O; `PluginManager` supplies every map.

---

## 4. Enforcement — the four gates

| Action | Gate | Behavior |
|---|---|---|
| `enable(X)` / install `X` | **forward** | Run `resolveForward(X.requires)`. All satisfied → proceed. Any unsatisfied but resolvable → return a **needs-confirm** payload (the closure). Any unsatisfied + unresolvable → **block** (`ModMenuException`) with instructions. `recommends` never blocks. |
| `disable(X)` / `uninstall(X)` | **reverse** | Run `resolveReverse(X)`. Any active plugin hard-requires X → **block** (`ModMenuException`) naming the dependents. Empty → proceed. |

### One-click resolve flow (forward, unsatisfied + resolvable)

1. Admin clicks Enable (Installed tab) or Install (Browse tab) on X.
2. Controller runs the forward check; on needs-confirm it renders a **resolve confirm** modal listing the ordered plan (e.g. *"install CalendarPlugin 1.1.0 → enable DependencyPlugin"*).
3. On confirm, `PluginManager` executes the closure **deps-first, then the target**, each step via the existing `enable()` / `installFromUrl()` engine (folder-move + extract, unchanged). Any step failing (e.g. a dep turns out unresolvable) aborts before touching the target — **no partial activation**.

The resolve set is the **transitive closure** of unmet `requires` (§3 `resolveClosure`). For the current suite that is one level deep; the walk is cheap and keeps one confirm correct for future chains.

### Security

- The new `resolve` action is **admin-gated + CSRF-guarded**, like every ModMenu mutation.
- The confirm form posts only `name` + `action` (`enable`|`install`) + CSRF token. The controller **re-derives** the plan and all download URLs server-side from a fresh catalog fetch — **no plan or URL travels in the form** (prevents a tampered-plan / attacker-URL injection). Downloads remain https-only (existing guard).

---

## 5. UI surfaces

All reuse existing patterns (`modmenu-card`, `modmenu-badge`, `js-modal-confirm`, `form->csrf()`).

- **Installed tab** — under each card, render only *unmet* deps (satisfied stay silent):
  - unmet `recommends` → soft hint + one-click button: *"Works better with CalendarPlugin — Install"* (non-blocking; the easy-install prompt).
  - unmet `requires` (rare post-enable; possible after a force-remove) → red line: *"Missing requirement: CalendarPlugin ≥ 1.1.0"* + Resolve button.
- **Enable button** → posts to `enable`; controller runs the forward check and either moves immediately, renders the resolve confirm, or flashes an unresolvable-block failure.
- **Browse tab** → pre-flight line under each entry: *"Requires CalendarPlugin ≥ 1.1.0 (will be installed)"* / *"Recommends CalendarPlugin."* Install resolves the chain the same way.
- **Disable / Remove** → reverse check. Remove already routes through `confirm()`; when blocked it shows the dependent list and omits the "Yes, remove" button (Cancel only). Disable flashes the block message.
- **New `Template/plugin/resolve.php`** — mirrors `remove.php`; lists the ordered plan with Confirm + Cancel.

---

## 6. Components / files

- **Create** `Model/DependencyResolver.php` — pure resolver (§3).
- **Modify** `Model/PluginManager.php` — `readMeta()` parses `requires`/`recommends` (default `[]`); new `forwardCheck($name)` / `reverseCheck($name)` orchestration that gathers maps and delegates; `enable()`/`uninstall()`/`disable()`/`installArchive()` call the gates; new `resolveAndActivate($name, $action)` executing the closure deps-first.
- **Modify** `Controller/ModMenuController.php` — `enable`/`install` render the resolve confirm on needs-confirm; new `resolve` action (admin + CSRF) executes the plan; `confirm`/`disable`/`uninstall` surface reverse blocks.
- **Modify** `Model/DirectoryClient.php` — carry `requires`/`recommends` through `annotate`/`merge` (verify pass-through; add pre-flight status if needed).
- **Create** `Template/plugin/resolve.php`; **modify** `Template/settings/installed.php` + `Template/settings/directory.php` for dep lines.
- **Add route** `config/modmenu/plugin/resolve`.

---

## 7. Testing (goal: fully tested, ready to deploy)

- **Unit — `DependencyResolver` (the bulk, pure & fast):** status matrix satisfied/disabled/outdated/missing; `min_version` compare boundaries (equal, above, below, absent); `requires` vs `recommends`; `resolveClosure` transitive + dedup + deps-first order; unresolvable (not in catalog); `resolveReverse` (dependents found / none / recommends-ignored).
- **Unit — `PluginManager`:** `readMeta()` parses both keys and defaults to `[]` when absent (backward-compat); `forwardCheck` returns needs-confirm vs proceed; `reverseCheck` throws when an active dependent exists. Reuses the existing `setDirectories()` harness.
- **Unit — `ControllerAccessTest`:** the new `resolve` action rejects non-admins and enforces CSRF.
- **Live E2E (:8081):** with **zip-installed** plugins (bind-mounted suite plugins can't be disabled) — declare a real `requires`, prove Enable blocks then one-click resolves; prove reverse block on Disable/Remove; verify DependencyPlugin's real `recommends` hint for CalendarPlugin renders.

---

## 8. Rollout (data, not core code)

After the code ships, add the fields to each suite plugin's `plugin.json` **and** mirror into the directory `plugins.json`:

- DependencyPlugin → `recommends` CalendarPlugin ≥ 1.1.0.
- SchedulerPlugin → `recommends` CalendarPlugin ≥ 1.1.0 **and** DependencyPlugin.

No hard `requires` in the suite today; the first real `requires` (a future dependent plugin, or a test fixture) exercises the block path. Bump ModMenu to the next minor (feature release) and update its README `plugins.json` field reference with `requires`/`recommends`.

---

## 9. Non-goals / deferred

- Runtime loader enforcement (core has no hook; each plugin degrades gracefully itself).
- Conflict declarations ("X conflicts with Y"), OR-groups, max-version ceilings — not needed by the suite; add later if a real case appears.
- Auto-disable cascades on removal (we *block* instead, which is safer and matches the approved decision).
