# Presence — who's online in a Kanboard project (v1.0)

- **Status:** design settled; ready for planning
- **Wayfinder map:** Kanboard #5467 (board 94 "Kanboard"), with all 5 cruxes resolved on 2026-10-07. Parent effort: Battle Lobby (Kanboard #5363).
- **Plugin:** `Presence` (directory and PHP namespace `Kanboard\Plugin\Presence`), repo `carmelosantana/kanboard-presence`
- **Working folder:** `kanboard-plugins/Presence` (doesn't exist yet; this is the final remount target)
- **Also touches:** `Agents` (one batch method) and `BattleLobby` (consumes the event)

## Why

Battle Lobby wants a board to feel like a shared MMO lobby, where you can see your squad standing on the stage. Kanboard has no idea who is around: there's no presence model and no websockets, and many installs run on shared hosting. Presence adds a small, cheap, privacy-respecting "who's here now" signal that any Kanboard install can use. Battle Lobby only themes it, as lit party plates. Agents (API-only users such as `carmelo.claude`) join the party as bots, so humans can see when an agent teammate is working.

## Decisions

| # | Decision | Ticket |
|---|---|---|
| 1 | **Destination:** Presence v1.0 released publicly on ModMenu: a buildless suite plugin with a zip-on-tag release and a directory listing. | Kanboard #5467 |
| 2 | **States:** *online* means a visible tab heartbeat within the last 2 min. *Away* means any authenticated web request within the last 15 min. Anything older is *offline*. An *agent* is online after an authenticated JSON-RPC call within the last 5 min, and is never "away". | Kanboard #5438 |
| 3 | **Scope:** the lineup is a project's **active members** (direct and through groups). Presence is **global**: one row per user, lit if the user is active anywhere in Kanboard. It never reveals which project someone is looking at. | Kanboard #5438 |
| 4 | **Privacy:** on by default. A per-user **"Appear offline"** setting stops all writes for that user, so they always read as offline. An admin switch disables presence instance-wide. The only data stored is `user_id`, `last_seen` and `source`: no page, project, IP or user-agent history. | Kanboard #5438 |
| 5 | **Home:** a new suite plugin. It isn't part of Battle Lobby, Agents or TimeReport. Battle Lobby *recommends* Presence and themes it. | Kanboard #5438 |
| 6 | **Agents:** Presence *recommends* Agents. When Agents is installed, members on the Agents roster carry `is_bot` and a bot badge. Without Agents, human presence works unchanged and nothing is flagged. Detection is a `class_exists` guard plus a new batch method `AgentTable::agentIds(array $userIds): array`. Presence never reads the `agents` table itself. | Kanboard #5438, Kanboard #5469 |
| 7 | **Transport:** one plugin controller route (session auth plus CSRF). It's POSTed every 60 s while the tab is visible and returns the project lineup in the same response. It pauses while the tab is hidden (Page Visibility API) and backs off to 5 min after 30 min with no user input. No SSE and no JSON-RPC polling. | Kanboard #5438 |
| 8 | **Write hooks:** web activity is recorded in `Plugin::onStartup()` (`app.bootstrap`; the session is already open). Agent activity is recorded by a JSON-RPC middleware appended in `initialize()` through `$this->api->getMiddlewareHandler()->withMiddleware()`, which runs after core authentication. App-token (`jsonrpc`) calls have no user and are skipped. | Kanboard #5468 |
| 9 | **Performance:** a single `presence` table with one row per user, updated in place. Web writes are throttled by a timestamp kept in the PHP session (most requests need no DB access). API writes are throttled by a conditional `UPDATE … WHERE last_seen < ?`. Each lineup read is one query. No cron, no cleanup job. Kanboard `>=1.2.47`, PHP `>=8.4`, on SQLite, MySQL and Postgres. | Kanboard #5438, Kanboard #5468 |
| 10 | **Stock widget:** a compact avatar strip rendered through `template:project:header:after` on every project view. The heartbeat JS loads on **every authenticated page** through `template:layout:bottom`. No dashboard block in v1. | Kanboard #5470 |
| 11 | **Settings surfaces:** "Appear offline" lives on a Presence page in the user profile (`template:user:sidebar:actions`) and as a one-click item in the avatar menu (`template:header:dropdown`). The admin on/off checkbox is under Settings > Application (`template:config:application`). Thresholds are fixed constants in v1. | Kanboard #5471 |
| 12 | **Theme contract:** strip plates carry `data-user-id`, `data-presence="online\|away\|offline"` and `data-bot="0\|1"`. After the first paint and after every poll, Presence dispatches `presence:update` on `document`, with `detail = {project_id, members:[{user_id, name, avatar_html, state, is_bot, is_self}]}`. Battle Lobby **does not override templates** (BL decision 5, Kanboard #5367). It hides the stock strip with CSS and lights its own party plates from the event. | Kanboard #5438, amended 2026-10-07 |
| 13 | **Ordering:** members are sorted by state (online, away, offline), then by display name. The viewer is flagged `is_self` (Battle Lobby puts them in the centre slot). Battle Lobby collapses past ~6 plates to "+N"; the stock strip shows every member. | Kanboard #5438 |
| 14 | **Timing:** Presence is a separate effort. Battle Lobby v1.0 is not blocked: its plates render plain without Presence and lit when Presence is installed. | Kanboard #5438 |

## Changes

### New plugin `Presence/`

```
Presence/
  Plugin.php                     hooks, routes, API middleware, onStartup recorder, asset includes
  plugin.json                    name, version 1.0.0, MIT, kanboard_version >=1.2.47, php_version >=8.4,
                                 requires [], recommends [Agents >=0.4.0]
  Schema/Sqlite.php, Mysql.php, Postgres.php   version_1: presence table
  Model/PresenceModel.php        touch(), lineup(), state(), constants for thresholds
  Model/PresenceSettings.php     isEnabled() (app config), isHidden(user) / setHidden (user metadata)
  Model/AgentDetector.php        class_exists guard → AgentTable::agentIds(); else []
  Api/PresenceApiMiddleware.php  JsonRPC MiddlewareInterface; touch(uid, 'api') when a user session exists
  Controller/HeartbeatController.php  POST beat (CSRF, project_id optional) → JSON lineup
  Controller/SettingsController.php   user Presence page (show/save), avatar-menu toggle (CSRF)
  Template/project/strip.php     avatar strip with data-* attrs
  Template/layout/bottom.php     heartbeat mount: data-beat-url, data-csrf, data-project-id
  Template/user/sidebar.php, user/settings.php, header/dropdown.php, config/application.php
  Assets/js/presence.js          visibility-aware 60 s poll + idle backoff; updates strip; dispatches presence:update
  Assets/css/presence.css        strip, three states, bot badge, reduced-motion safe
  Locale/                        en_US source strings
  Test/                          PHPUnit (testing/run-plugin-tests.sh Presence)
  README.md, LICENSE, .github/workflows/release.yml (zip-on-tag)
```

**Table `presence`:** `user_id` INTEGER primary key (references users, cascade delete), `last_seen` INTEGER NOT NULL, `source` TEXT NOT NULL (`web` or `api`), `last_beat` INTEGER NOT NULL DEFAULT 0.
- `last_seen` is updated by any recorded activity, so it drives *away* and *agent online*.
- `last_beat` is updated only by the heartbeat route, so it drives *online*.

**`PresenceModel::touch(int $userId, string $source, bool $beat = false)`:**
- Returns at once if presence is disabled or the user is hidden.
- Web: skips the write if the session key `presence_last_write` is less than 30 s old.
- API: runs `UPDATE … SET last_seen=? WHERE user_id=? AND last_seen < now-60`. If no row was affected and no row exists, it INSERTs (a duplicate-key error is caught and ignored).
- A beat always sets `last_beat` (it arrives at most once per 60 s per tab), still under the 30 s session throttle.

**`PresenceModel::lineup(int $projectId, int $viewerId): array`:**
- Takes active members from `projectUserRoleModel->getAllUsers()`, LEFT JOINs `presence` once for those ids, and runs `AgentDetector` once.
- Derives each state with `state()`. Hidden users read offline. Members are sorted as in decision 13.
- `avatar_html` comes from `helper->avatar->small()`.
- The viewer must have project access, or the route returns 403.

**`state($row, $isBot, $now)`:**
- Bot: online if `last_seen >= now-300`, otherwise offline.
- Human: online if `last_beat >= now-120`; otherwise away if `last_seen >= now-900`; otherwise offline.

**Heartbeat JS:**
- Reads its config from `data-*` attributes (CSP forbids inline script).
- First beat on load. Then every 60 s while `document.visibilityState === 'visible'`, beating immediately when the tab becomes visible again.
- Interval goes to 5 min after 30 min with no `pointerdown` or `keydown`, and back to 60 s on the next input.
- On pages with a project, the response lineup updates the strip and fires `presence:update`. Otherwise it only pings.
- The admin switch off, or the viewer hidden: no assets and no strip are rendered.

### `Agents/`: one addition (minor bump, 0.4.0)

`Model/AgentTable::agentIds(array $userIds): array` returns the subset of `$userIds` that are on the roster, using one `IN (…)` query (an empty input returns `[]` without a query). It's covered by `AgentTableTest`. The README notes that Presence uses it.

### `BattleLobby/`: consumer (in the Battle Lobby effort, not this plan)

- `battle-lobby.js` listens for `presence:update` and sets `data-presence` / `data-bot` on its party plates, matched by `user_id`.
- `battle-lobby.css` hides `.presence-strip` while the lobby is active and styles the lit, away and offline plates and the bot badge.
- `plugin.json` gets `recommends Presence >=1.0.0`.

### ModMenu directory

Add a `kanboard-modmenu-directory` entry for Presence with `recommends: Agents`, following kanboard:plugin-suite.

## Non-goals

- Per-project presence ("on this board right now"), cursors, "viewing this task" and typing indicators.
- SSE, websockets, or a JSON-RPC presence procedure.
- Activity history, last-seen timestamps shown to other users, or analytics.
- Admin-tunable thresholds or poll interval.
- A dashboard "who's online" block.
- Timer-based or board-activity-based presence.
- Battle Lobby's own plate rendering (lives in the Battle Lobby effort).

## Sequencing

1. **Agents `agentIds()`** (TDD) and release Agents 0.4.0. Nothing else depends on its internals.
2. **Presence skeleton:** `Plugin.php`, `plugin.json`, schema for all three DBs, PluginVersionTest.
3. **PresenceModel:** `touch()` throttles, `state()` thresholds, and `lineup()` (members, groups, ordering, hidden, bots through a stubbed detector). Pure PHPUnit.
4. **Settings:** admin switch and per-user hidden flag; `touch()` and `lineup()` respect both.
5. **Write hooks:** the `onStartup()` web recorder and `PresenceApiMiddleware` (personal token records, app token skips).
6. **Heartbeat route:** CSRF, project access check, JSON lineup.
7. **UI:** strip template, layout mount, `presence.js` (visibility, backoff, event), CSS, profile page and avatar-menu toggle, Settings > Application checkbox.
8. **Verify on the dev stack** (`testing/docker-compose.dev.yml`): two browser sessions plus one agent token calling JSON-RPC. Check that the states flip within their thresholds and that only throttled writes happen.
9. **Release:** README, LICENSE, zip-on-tag workflow, v1.0.0 tag, ModMenu directory entry.
