# LAN Party: a game launcher for Kanboard (v1)

- **Status:** spec drafted and self-approved 2026-11-19 overnight under Carmelo's "decide and record"
  instruction. Rulings and their costs: `docs/superpowers/plans/lanparty-wayfinding.md`. Open questions
  for Carmelo are listed there and at the end of this file.
- **Plugin:** `LanParty` (namespace `Kanboard\Plugin\LanParty`), MIT, repo `carmelosantana/kanboard-lan-party`
  (not created yet, waiting on Carmelo's OK).
- **Working folder:** `kanboard-plugins/LanParty` (its own git repo, gitignored by the harness like the others).
- **Neighbours:** `LanPartyEngines` (future GPL-2 engine pack), AgentChat (phase 3 sharing), Pulse (phase 3
  activity source), Agents (agent users are left off leaderboards).

## Why

Carmelo wants a fun corner of the suite: a Steam/Netflix-style launcher where the team plays short browser
games, posts scores and challenges each other, with room for open-source shooter engines at a LAN party.

## Goals, non-goals, anti-goals

- **Goals:** a launcher page with rows of games; per-game leaderboards; challenge links; rooms where players
  meet; real-time play between browsers on the same network; a stable game-module contract so games
  (originals and engine packs) are built separately and in parallel.
- **Non-goals (v1):** shooter multiplayer, AI features, play-hour limits, an achievements system,
  uploading third-party game zips through the UI, mobile-first layouts for the games.
- **Anti-goals:** recreating any existing game (names, characters, art, levels, text); bundling commercial
  game data; GPL code inside the MIT plugin; a long-running PHP process (SSE, WebSocket server, daemon);
  game code running with the user's Kanboard session; a build step.

## Decisions

The wayfinding map has the full table (18 rulings). The ones that shape the code:

| # | Decision |
|---|---|
| 1 | Name **LAN Party**, id `LanParty`, tagline "Party Party". |
| 2 | One MIT plugin = launcher + SDK host + scoreboard + rooms + the three originals. Other plugins add games by appending to `$container['lanpartyGames']`. |
| 3 | Engines live in a separate GPL-2 plugin `LanPartyEngines`: Doom (Chocolate Doom WASM) + Freedoom first, Quake + LibreQuake second, Duke deferred (BUILD licence). |
| 5 | Games run in `<iframe sandbox="allow-scripts">`, never `allow-same-origin`. All access goes through the postMessage SDK. |
| 6 | The parent fetches game assets and admin-supplied files and transfers `ArrayBuffer`s; game folders are static files. |
| 7 | Rooms: a polled relay (1 s in an open room with the tab visible, 5 s hidden); real-time via WebRTC data channels signalled through the room log. No SSE, no WebSocket server. |
| 9 | Own tables, three DB drivers. |
| 10 | Scores: client-reported, server-bounded (`scoring.max`, integer, 5 s rate limit per user and game). |
| 15 | Every signed-in user plays and sees all leaderboards; agent users are excluded from leaderboards. |
| 16 | Saves: one JSON blob per user per game, ≤ 64 KB, in a plugin table `lanparty_saves` (amended during build: core `user_has_metadata.value` is VARCHAR(255) on MySQL/Postgres). |

## Architecture

```
Browser                                                     Kanboard (PHP-FPM)
 launcher page  (lanparty.js, CSP-clean, jQuery/KB)          LanParty plugin
  ├─ GET  /lanparty                     ───────────────►     LauncherController::index   (rows, hero)
  ├─ GET  /lanparty/game/{slug}         ───────────────►     LauncherController::game    (leaderboard, play)
  ├─ POST /lanparty/score               ───────────────►     ScoreController::submit     (JSON, CSRF)
  ├─ GET  /lanparty/scores/{slug}       ───────────────►     ScoreController::board      (JSON)
  ├─ POST /lanparty/save | GET /load    ───────────────►     SaveController              (JSON, CSRF)
  ├─ POST /lanparty/room/*  (phase 2)   ───────────────►     RoomController (create/join/leave/send/poll)
  └─ host bridge (lanparty-host.js)                          GameRegistry ◄── $container['lanpartyGames']
        ▲  postMessage (structured clone, transferables)      ScoreModel, RoomModel, SaveModel
        ▼                                                     Schema/{Sqlite,Mysql,Postgres}.php
 <iframe sandbox="allow-scripts" src="/plugins/LanParty/Games/<slug>/index.html#lp=<nonce>">
   game code + lanparty-sdk.js   (opaque origin: no cookies, no parent DOM)
```

- **Routes** are registered with `$this->route->addRoute()`; links use `$this->helper->url->href()`.
- **JSON endpoints** check the session and Kanboard's reusable CSRF token, which the host bridge posts as
  `csrf_token` (`checkReusableCSRFParam()`, as Presence does). The game never sees it. Responses are `application/json` through `$this->response->json()`.
- **Assets:** `lanparty.css` and `lanparty.js`/`lanparty-host.js` are injected only on LanParty routes
  (controller layout), never sitewide, except a tiny header-link icon that needs no asset.

## Game-module contract (v1, frozen)

### Folder

```
<Plugin>/Games/<slug>/
  game.json          manifest (below)
  index.html         static entry, loads ../../../LanParty/Assets/js/lanparty-sdk.js  (see "SDK path")
  cover.svg|png      card art, 2:3 portrait
  hero.svg|png|jpg   banner art, 21:9 (optional; falls back to cover)
  *.js, *.css, *.wasm, art/, levels/ …   anything else the game needs
```

- `slug`: `^[a-z][a-z0-9-]{1,31}$`, unique across all registered games (first registration wins, later
  duplicates are logged and skipped).
- **No path segment may start with `data`** and no file may end in `.log`/`.sqlite`/`.php`: Kanboard's stock
  nginx rule `location ~ /data` 404s any URI containing `/data`, and `.php` would execute. The registry
  rejects a game whose folder breaks this rule.
- **SDK path:** every game sits at `plugins/<Plugin>/Games/<slug>/`, so every game loads the SDK with the
  same relative classic script tag: `<script src="../../../LanParty/Assets/js/lanparty-sdk.js"></script>`.
  The host passes the nonce in the iframe URL fragment (`#lp=<nonce>`).

### Manifest `game.json`

```json
{
  "slug": "saltroad",
  "title": "Saltroad",
  "tagline": "Lead a salt caravan to the sea.",
  "version": "0.1.0",
  "kind": "original",
  "licence": "MIT",
  "content_licence": "CC-BY-4.0",
  "entry": "index.html",
  "art": { "cover": "cover.svg", "hero": "hero.svg" },
  "players": { "min": 1, "max": 1 },
  "modes": ["solo", "async"],
  "scoring": { "order": "high", "unit": "salt", "max": 1000000 },
  "files": []
}
```

| Field | Rule |
|---|---|
| `slug`, `title`, `version`, `entry` | required strings; `title` ≤ 40 chars; `entry` a relative path inside the folder, no `..` |
| `tagline` | optional, ≤ 80 chars |
| `kind` | `original` or `engine` |
| `licence`, `content_licence` | SPDX ids; required (the launcher shows them on the game page) |
| `art.cover` | required; `art.hero` optional |
| `players.min`, `players.max` | integers, 1 ≤ min ≤ max ≤ 16 |
| `modes` | non-empty subset of `solo`, `async`, `realtime` |
| `scoring` | optional (a game without it has no leaderboard). `order` `high`/`low`; `unit` ≤ 12 chars; `max` integer ≥ 1 |
| `files` | admin-supplied file slots: `[{ "id", "label", "accept": [".wad"], "required": bool }]`; empty for originals |

A registered game is the manifest plus `plugin` (owning plugin name) and `base_url`
(`/plugins/<Plugin>/Games/<slug>/`), computed by the registry.

### Registration

```php
// in any plugin's initialize()
$this->container['lanpartyGames'] = array_merge(
    $this->container['lanpartyGames'] ?? [],
    [ ['plugin' => 'LanPartyEngines', 'path' => __DIR__ . '/Games/doom'] ]
);
```

`LanParty` registers its own `Games/*` folders the same way. `GameRegistry` reads each `game.json`,
validates it, and returns the valid games, keyed by slug. Invalid manifests are skipped with a logged reason
(an admin sees them on the settings page). Admin enable/disable is stored in config `lanparty_disabled` (CSV).

### SDK (`lanparty-sdk.js`, loaded by the game)

Global `LanParty`, every call returns a Promise. Transport: `parent.postMessage({lp:1, id, nonce, op, args}, '*')`;
the host only answers messages whose `event.source` is the game's iframe window and whose `nonce` matches
the one it put in the iframe's URL fragment; replies go to that window only.

| Call | Result |
|---|---|
| `ready()` | `{ player: {id, name, avatar_url}, game: {slug, title, version}, challenge: {level, seed} \| null }`; must be called first |
| `asset(path)` | `ArrayBuffer` of a file inside the game's folder (parent fetches `base_url + path`, rejects `..`) |
| `file(id)` | `ArrayBuffer` of an admin-supplied file slot, or rejects `{code:'missing'}` |
| `submitScore({score, level?, seed?, meta?})` | `{ rank, best, accepted }`; integer score, `meta` ≤ 1 KB JSON |
| `leaderboard({period?: 'all'\|'week', limit?})` | `[{rank, user_id, name, score, level, date}]` |
| `save(obj)` / `load()` | stores / returns the user's JSON blob for this game (≤ 64 KB) |
| `share({level?, seed?, text})` | copies a challenge link + text to the clipboard (host does it; the frame has no clipboard) |
| `exit()` | closes the game and returns to the launcher |
| `room.*` | phase 2: `create()`, `join(code)`, `leave()`, `send(data)`, `on('message'\|'peers', fn)`, `rtc.channel(label)` |

Errors reject with `{code, message}`; codes: `bad_request`, `forbidden`, `missing`, `rate_limited`,
`too_large`, `unavailable`.

## Data model

```
lanparty_scores
  id PK, game_slug VARCHAR(32), user_id FK users ON DELETE CASCADE, score BIGINT,
  level VARCHAR(32) NULL, seed VARCHAR(64) NULL, meta TEXT NULL, created_at INTEGER
  index (game_slug, score), index (user_id, game_slug)

lanparty_saves
  user_id FK users ON DELETE CASCADE, game_slug VARCHAR(32), data TEXT (MEDIUMTEXT on MySQL), updated_at INTEGER;
  PK (user_id, game_slug)

lanparty_rooms                 (phase 2)
  id PK, code CHAR(6) unique, game_slug, host_user_id, status ('open','playing','closed'),
  max_players, created_at, updated_at

lanparty_room_members          (phase 2)
  room_id FK, user_id FK, joined_at, last_seen; PK(room_id, user_id)

lanparty_room_messages         (phase 2)
  id PK (the sequence), room_id FK, user_id, to_user_id NULL (NULL = everyone), kind ('chat','state','signal'),
  body TEXT ≤ 16 KB, created_at
```

Rooms: a member who hasn't polled in 30 s is dropped; an empty room closes; messages older than 1 h and
closed rooms older than 1 day are deleted lazily during polls (no cron).

**Leaderboard query:** best score per user (max for `high`, min for `low`), agent users excluded when the
Agents plugin is present, ordered, ties broken by the earlier `created_at`. `week` = last 7 days.

## Launcher UI

- **Entry points:** a "LAN Party" item in the header avatar menu (`template:header:dropdown`) and in the
  dashboard sidebar (`template:dashboard:sidebar`).
- **`/lanparty`:** dark full-width page inside Kanboard's layout. Hero banner (featured game: most played
  this week, else first original). Rows, each a horizontally scrolling strip of 2:3 cards:
  *Continue playing* (games with your scores or saves), *LAN Party Originals*, *Engine games* (only when a
  `kind:engine` game is registered), and in phase 2 *Rooms open now*. Each card: cover, title, players
  badge, your best.
- **`/lanparty/game/{slug}`:** hero art, tagline, licences, leaderboard (All time / This week tabs), Play.
  A challenge link opens this page with `?level=&seed=` and a "Beat <name>'s <score>" banner.
- **Playing:** Play opens a full-viewport overlay holding the iframe (`allow="fullscreen; gamepad"`),
  with a slim bar: game title, Fullscreen, Exit. Esc never closes it (games use Esc); Exit does.
- Respects `prefers-reduced-motion` (no card zoom). Keyboard: cards are focusable links; arrow keys move
  along a row.

## Security

- Opaque-origin sandbox (no `allow-same-origin`, no `allow-top-navigation`, no `allow-popups`).
- The host validates every SDK message: source window, nonce, op whitelist, argument types/sizes.
- `asset()` paths are normalised client-side and checked again server-side when a controller serves
  admin files; `..`, absolute paths and URL schemes are rejected.
- Admin file uploads (engine data) go to `DATA_DIR/lanparty/<slug>/<file-id>`, served only to signed-in users
  through `FileController::get`, with the extension checked against the slot's `accept`.
- Scores: integer only, `0 ≤ score ≤ scoring.max`, `level` matched by `^[A-Za-z0-9_.-]{0,32}$` and `seed` by `^[A-Za-z0-9_.-]{0,64}$`,
  `meta` ≤ 1 KB valid JSON. Rate limit per user and game: 5 s (checked against the last row).
- No game ever receives the CSRF token, session cookie or another user's data beyond leaderboard names.

## Testing

- **PHPUnit** (harness runner): manifest validation (each rule, valid and invalid), registry merge and
  duplicate handling, the `/data` path rule, score submit bounds, rate limit, leaderboard ranking (high/low,
  ties, week window, agent exclusion), save size limit, controller auth/CSRF, plugin metadata/version.
- **node:test** (`Test/js/*.test.mjs`, zero dependency): host bridge message validation (wrong source,
  wrong nonce, unknown op), SDK request/response matching, path normalisation.
- **Live E2E** on :8081 once the plugin is mounted (needs Carmelo's OK to restart `kb-suite`): launcher
  renders, sample game loads in the sandbox, score round-trip, leaderboard updates.

## Phases

1. **Launcher core (tonight):** skeleton, registry + manifest validator, scores schema/model/controller,
   saves, launcher + game pages, host bridge + SDK, a built-in sample game (`Games/pop`: a 30-second
   original click-the-bubbles game that exercises `ready/asset/submitScore/save/share/exit`).
2. **Rooms:** schema, RoomModel/Controller, polling client, WebRTC signalling, a room UI, `realtime` mode
   in the sample game.
3. **Originals:** Saltroad, Gusher, Wicklings, one session each in its own worktree; then sharing to
   AgentChat and a Pulse activity source.
4. **Engine pack:** `LanPartyEngines` (GPL-2): Doom + Freedoom, then Quake + LibreQuake; later the
   WebSocket-over-WebRTC shim for shooter multiplayer.

## Open questions (for Carmelo)

See the wayfinding map: name, Duke/BUILD licence, original game names, play hours, agents league, repo +
dev-stack mount.
