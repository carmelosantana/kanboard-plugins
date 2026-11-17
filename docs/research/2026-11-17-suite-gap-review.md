# Suite gap review — 2026-11-17

A read-only review of all 20 plugins, the dev harness (`testing/`, `scripts/`) and `docs/`, looking
for feature gaps, workflow friction, missed classes of security issue, and inconsistency. No plugin
code was changed.

**Impact** H/M/L · **Effort** S (≤ half a day) / M (1–3 days) / L (a release of its own).
Items in each tier are ranked by impact ÷ effort. Security items are written at headline level here;
file-level detail is kept out of this public repo until the fixes ship.

## Headline

- **The suite has no automated test gate.** Every plugin has a PHPUnit suite, but CI runs only
  `release.yml`. There's no command to run all suites, and the harness lists of plugins have
  drifted from the 20 that exist. Most of the bugs below would have been caught by a test that runs.
- **Three plugins need a security patch release:** TimeInvoice, ModMenu and AiConnector.
  TimeReport needs a small one.
- **Shared code is copied, not shared.** The AI-availability gate, the "accessible projects" logic,
  `requireAdmin()` and `validDate()` are reimplemented per plugin, and the copies already disagree.
  For example, admins see everything in CalendarPlugin but not in TimeBlock.
- **The theme contract is half-built.** The `--kbx-*` tokens that Kensho, BulkProjectDelete and
  ShadcnTheme use are defined nowhere, so dark mode falls back to light hex values. ShadcnTheme's
  server-side theme save has never worked.

---

## Tier 1 — Quick wins (high value, small effort)

| # | Item | Plugin(s) | Impact | Effort |
|---|------|-----------|:------:|:------:|
| 1 | **Enforce invoice status transitions.** Saving or marking paid must only work on the right status. Snapshots must bill the draft owner's hours, not the acting user's. Add transition tests. | TimeInvoice | H | S |
| 2 | **Tie each install to its catalog entry.** Pass the expected plugin name into install/update and refuse a zip whose top folder differs. ModMenuUpdater already does this. | ModMenu | H | S |
| 3 | **Re-require the provider key when a profile's base URL changes.** Validate scheme and host, and make LAN/Ollama endpoints an explicit opt-in. | AiConnector | H | S |
| 4 | **Honour `kanboard_version`.** 17 of 20 `plugin.json` files use `kanboard_version`, as the plugin-suite skill says, but ModMenu's compatibility check reads only `compatible_version`, so the check is skipped for most of the suite. | ModMenu | H | S |
| 5 | **Republish SubtaskGenerator 1.1.1 to the directory.** v1.1.1 was released on 2026-08-30, but `plugins.json` still lists 1.1.0, so ModMenu users have never been offered it. | directory | M | S |
| 6 | **Fix dependency metadata.** Presence's `recommends` uses `{name, version}` instead of `{plugin, min_version}`, so ModMenu resolves it to `''`. Raise the AiConnector floor in SubtaskGenerator and Kensho from 1.0.0 to 1.1.4, the security fix. BattleLobby recommends TimeReport but has no integration code yet, and its ShadcnTheme conflict is declared in one direction only. | Presence, SubtaskGenerator, Kensho, BattleLobby | M | S |
| 7 | **Kensho's compatibility check is never called.** It defines `getCompatibleKanboardVersion()`, but core calls `getCompatibleVersion()`. Its test asserts the wrong method, so it passes. | Kensho | M | S |
| 8 | **Escape CSV formulas.** Prefix `'` to cells that start with `= + - @`, tab or CR. Task titles and AI summaries flow into the CSV. | TimeReport | M | S |
| 9 | **Bound AI request time.** php-agents uses a 300 s idle timeout with no `max_duration`, so every AI route can hold a PHP-FPM worker for 5+ minutes. Inject a client with a ~45 s `max_duration` in `ProviderRegistry`, and add `AbortController` in the JS callers. | AiConnector (+ all AI callers) | M | S |
| 10 | **Add a run-all-suites harness command and fix the plugin lists.** `run-plugin-tests.sh` hard-codes 18 names and misses ModMenuUpdater and HelloHarmozi. `docker-compose.dev.yml` doesn't mount BattleLobby, Presence, RpcGuard or ModMenuUpdater. The harness `README.md` and `testing/README.md` still describe "four plugins" with placeholders. Derive the list from `*/plugin.json`. | harness | M | S |
| 11 | **Fix ShadcnTheme server persistence or delete it.** The route is registered as `ShadcnTheme:ThemeController`, which the router's `[a-zA-Z_0-9]` sanitizer rejects. The JS posts without a CSRF token. The `model:user-metadata:save` listener is a hook core never fires. `initializeTheme()` queries the DB on every request, API calls included. The theme is read from `localStorage` anyway. The same colon form breaks ModMenuUpdater's route, and AiConnector registers routes with no plugin argument. | ShadcnTheme, ModMenuUpdater, AiConnector | M | S |
| 12 | **Define the `--kbx-*` token layer in ShadcnTheme**, with light and dark values. Then replace the hard-coded hex in Agents, TimeReport, TimeInvoice, TimeBlock, Chicane and Presence. | ShadcnTheme + 6 | M | S→M |
| 13 | **Show project names on TimeBlock calendar events.** `extendedProps.project` is the project id, so the badge and popover read "12". | TimeBlock | M | S |
| 14 | **Remove per-request and per-card query hot spots.** Agents runs `WipView::flags()` on every page for every user. The TimeBlock badge runs two metadata queries per card. The Presence heartbeat builds unused `avatar_html` for every member. | Agents, TimeBlock, Presence | M | S |
| 15 | **Refuse to adopt admin accounts as agents**, so an agent's owner can never disable an admin. | Agents | M | S |
| 16 | **Hide actions that will 403** (Scheduler's project toggle, TimeInvoice's Issue, Delete and Mark paid) from viewers. Use role ≥ manager instead of `=== PROJECT_MANAGER`, so custom project roles work. | SchedulerPlugin, TimeInvoice | L | S |
| 17 | **Smaller hardening.** Return a generic error from AiConnector's Test Connection. BulkProjectDelete should check `confirm_word` on the server and log deletes. Add `JSON_HEX_TAG` to Chicane's inline JSON. Cap uncompressed size in ModMenu's zip check. RpcGuard should log, and add a test, when its runtime assumptions stop holding. | several | L | S |

## Tier 2 — Workflow (pays back on every release)

| # | Item | Impact | Effort |
|---|------|:------:|:------:|
| 18 | **CI test workflow per plugin.** Add a reusable workflow, ideally in one repo called via `workflow_call`, that checks out Kanboard v1.2.47, symlinks sibling `requires` plugins and runs PHPUnit on PRs and before the release job. Today, 4 different `release.yml` variants exist, HelloHarmozi has none, and none runs tests. | H | M |
| 19 | **One version source.** Every `Plugin.php` hard-codes `getPluginVersion()` alongside `plugin.json`. Read it from `plugin.json` (a 3-line helper), or make the release job fail on mismatch. Only TimeReport, TimeInvoice, Agents and Chicane have a meta test comparing the two; SubtaskGenerator and FeatureSync assert a fixed string. | M | S |
| 20 | **Automated directory sync.** Run a directory-repo workflow, or have the release job open a PR against `kanboard-modmenu-directory`. Validate that `download` resolves, that the zip's top folder equals `name`, and that `version` matches the tag. Record `sha256` for each entry. This would have caught #5 and makes #21 possible. | H | M |
| 21 | **Integrity and safety for ModMenu installs.** Accept https-only sources. Verify the `sha256` per entry before extracting. Add a load-failure rollback for every plugin, not only ModMenu itself. Run the dependency check on update, not only on install. ModMenuUpdater should verify before it loads staged code. | H | M |
| 22 | **Pin the harness image.** `docker-compose.dev.yml` uses `kanboard/kanboard:latest` while unit tests pin v1.2.47. Pin `v1.2.47` so the live stack and the tests agree. | M | S |
| 23 | **Refresh `docs/2026-07-05-suite-roadmap.md`.** It lists FeatureSync and SubtaskGenerator at 1.0.0 and has no rows for AiConnector, Kensho, Agents, Chicane, TimeReport and others. | L | S |

## Tier 3 — Shared foundations (reduce drift)

| # | Item | Impact | Effort |
|---|------|:------:|:------:|
| 24 | **Export the AI gate from AiConnector** as `ProviderRegistry::gate()` / `validProfileId()`. Delete the three `AiGate` copies (SubtaskGenerator, Kensho, TimeReport; identical apart from comments) and TimeInvoice's inline version. | M | M |
| 25 | **AI usage metering and caps** in `ProviderRegistry::structured()`: a per-user and per-plugin counter, an input-length cap, an optional daily budget, and an admin usage page. Today any member can drive the instance's paid key through free-text prompts, with no attribution. Pair this with requiring edit rights, not view rights, for "regenerate" actions in TimeReport and TimeInvoice. | M | M |
| 26 | **One "accessible projects / role" helper.** Admin bypass differs in CalendarPlugin (all projects), TimeBlock (none), TimeReport and TimeInvoice (membership required, but admins can report on others) and Scheduler (admin or manager). Pick one rule and share it. | M | M |
| 27 | **Settings placement convention.** Today it's config sidebar, `config:application`, `config:integrations`, project sidebar, dashboard sidebar or header dropdown. Settle on admin settings in the config sidebar, per-project settings in the project sidebar and per-user settings in the user profile, and record it in the plugin-suite skill. | L | M |
| 28 | **i18n baseline.** No plugin ships `Locale/`, so `t()` is a no-op. JS strings are hard-coded in BattleLobby, TimeInvoice, Chicane and the CalendarPlugin popover. CSV headers are English. Kensho loads a `Locale/` dir that doesn't exist. BulkProjectDelete builds sentences from fragments. BattleLobby matches a column literally named "Ready". Adopt TimeReport's data-attribute pattern for JS strings, then add one locale to prove the pipe. | L | M |
| 29 | **JS tests.** 11 of 13 plugins with JS have none. A smoke test of ShadcnTheme's switcher would have caught #11. | M | M |

## Tier 4 — Feature gaps and integrations

| # | Item | Plugin(s) | Impact | Effort |
|---|------|-----------|:------:|:------:|
| 30 | **Team invoices.** TimeReport has been multi-user since 1.3.0, but TimeInvoice still bills only the current user, behind a warning banner. Add "Bill hours for". | TimeInvoice | H | M |
| 31 | **Planned vs actual.** Show TimeBlock hours against TimeReport's logged hours per task and day, in reports and card meters. Nothing outside TimeBlock reads `timeblock_*` today. | TimeBlock ↔ TimeReport | M | M |
| 32 | **Scheduler respects time blocks.** Moving an overdue task leaves its block in the past, and de-clump counts tasks, not planned hours. Cascade to dependents and per-project overrides are still deferred. | SchedulerPlugin ↔ TimeBlock | M | M |
| 33 | **Calendar editing.** Time blocks are `editable=false`. Every roadmap §0 item is unbuilt: resize-to-duration, inline edit, create-by-click, undo, WIP warnings. The unscheduled sidebar ignores assignee, category and completed filters and is unbounded. FullCalendar hard-codes `firstDay:1`, ignoring the user's week start. | CalendarPlugin, TimeBlock | M | L |
| 34 | **ModMenu update UX.** Update-all exists only in the model, on `feat/update-all-conflicts`. Add an update badge or notification and show available updates in the Installed tab. Show changelog and homepage links in Browse. Uninstall should offer to drop the plugin's tables and config keys. A failed dependency install partway through should roll back. | ModMenu | M | M |
| 35 | **FeatureSync add-missing compares on event + action only**, so a second rule of the same type with different params is skipped. Include params in the key, and validate target ids. | FeatureSync | M | S |
| 36 | **Agents least privilege.** Add a per-agent allow-list of procedures or a documented "agent role", so task text that agents read (prompt-injection surface) can't drive arbitrary mutations. | Agents | M | M |
| 37 | **DependencyPlugin hardening and next step.** Look up link types by id, not the English label (a renamed label breaks it silently). Drop the duplicate links panel. Make "can't close/move while blocked" an option. Add the deferred graph view. | DependencyPlugin | L–M | S–M |
| 38 | **Unbuilt AI hooks** worth a brainstorm: TimeBlock block suggestions from estimate plus free slots, a plain-language Scheduler run summary, Chicane commentary (spec non-goal), and AiConnector's streaming/tools surface (spec §13). | various | L | M |
| 39 | **EnhancedTaskPlugin** (recurring tasks, snooze, smart date-picker) is still only on the roadmap. | new | M | L |

## What checked out

- Every AI caller goes through AiConnector, and AI features degrade cleanly when it's absent or
  unconfigured.
- Every state-changing route checks CSRF. There are no inline `<script>` handlers, no
  `eval`/`new Function` and no external CDNs; core's CSP (`default-src 'self'`) holds across the
  suite. Chicane's one inline block is `application/json`.
- Templates escape well, and no model output reaches `innerHTML`.
- `plugin.json` versions match the latest tags in all 20 repos.

---

## Recommended next chips

1. **Security patch round** (#1–#3, #8, #15, #17): TimeInvoice 1.2.4, ModMenu 1.3.4,
   AiConnector 1.1.5, TimeReport 1.7.2 and Agents 0.4.2, each with failing tests first.
2. **Metadata & compat fixes** (#4–#7): ModMenu reads `kanboard_version`, fix Presence/Kensho
   metadata, raise the AiConnector floors and republish SubtaskGenerator 1.1.1 to the directory.
3. **Suite CI** (#18, #19, #10, #22): a reusable test workflow, one version source, a run-all
   harness command and a pinned image.
4. **Directory pipeline + ModMenu integrity** (#20, #21): automated `plugins.json` updates with
   `sha256`, then verified installs and rollback.
5. **Theme tokens** (#11, #12): define `--kbx-*`, fix or remove ShadcnTheme's server save, and sweep
   hard-coded colours.
6. **AiConnector shared gate + metering + timeouts** (#9, #24, #25).
7. **Team invoices** (#30).
8. **Planned vs actual** (#31, then #32).
