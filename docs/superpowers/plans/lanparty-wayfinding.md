# LAN Party: wayfinding map

- **Status:** map charted and every crux ruled on 2026-11-19 (overnight, Carmelo asleep). Each ruling
  below is mine, made under his "decide and record" instruction, and states what it costs if it's wrong.
- **Where the map lives:** this file. The Kanboard MCP points at production, and the :8081 dev stack is
  shared with sessions that are working right now, so I kept the map off both boards. If Carmelo wants it
  on a board, every crux below is a ready ticket body.
- **Next:** spec `docs/specs/2026-11-19-lanparty-design.md` → plan `docs/plans/2026-11-19-lanparty-launcher.md`.

## Destination

Carmelo's words: "A game plugin. It'll feature a launcher like steam or Netflix. Comes with a launcher for
games that can support multiplayer like doom? , duke, quake. Id love to include an Oregon trial, pipes with
oil filling and lemmings type of games. … Our game Plugin wil act as a launcher, Score board, sharing etc.
Needs a fun name. LAN, party party?"

**Done (v1)** means a Kanboard suite plugin where any signed-in user opens a Netflix-style launcher, plays
three original browser games (trail-journey survival, oil pipe-flow puzzle, lead-the-walkers puzzle), posts
scores to per-game leaderboards, shares a challenge link, and meets colleagues in a room. Open-source
shooter engines (Doom first, with Freedoom) plug in through a separate GPL engine pack that uses the same
game-module contract.

Tonight's slice of done: map + rulings, spec, plan, launcher core built test-first, morning brief.

## Hard constraints (from the brief, not up for ruling)

- No recreations of existing games: original names, art, levels and text for the three genre games.
- Shooter engines only as open-source ports with free content (Freedoom, LibreQuake) or admin-supplied
  files. Never bundle commercial game data. Check each engine's licence.
- No long-running PHP. Buildless PHP 8.4, one repo per plugin, AiConnector optional.

## Cruxes and rulings

| # | Crux | Ruling | Cost if wrong |
|---|---|---|---|
| 1 | **Name** | Candidates: **LAN Party**, Party Party, Break Room, Coin-Op, Recess. **Ruling: LAN Party.** Plugin id `LanParty`, repo `carmelosantana/kanboard-lan-party`, UI title "LAN Party", tagline "Party Party" (his other idea lives on as the tagline and the room-full toast). Real-time rooms are LAN-first (crux 7), so the name is honest. | Low before the first release: rename is a namespace/route sed plus repo name. High after a ModMenu listing. |
| 2 | **Shape: one plugin or many** | **One MIT plugin, `LanParty`, that is the launcher, SDK host, scoreboard and rooms, and ships the three original games.** Other plugins contribute games by appending to a container array `lanpartyGames` (suite convention: append, never overwrite). | Medium: splitting originals out later means moving folders and a `recommends`. |
| 3 | **Where shooter engines live** | **A separate plugin `LanPartyEngines`, GPL-2.0, its own repo**, that registers engine games through `lanpartyGames`. Keeps the MIT launcher free of GPL code; the GPL pack ships source as required. Not built tonight. | Low: it is additive. |
| 4 | **Which engines** | **Doom: yes** (cloudflare/doom-wasm lineage, Chocolate Doom, GPL-2) with **Freedoom** (BSD-3) bundled. **Quake: yes, second** (a GPL-2 WebGL/WASM Quake port) with **LibreQuake** (media BSD-3, QuakeC GPL-2+; still beta). **Duke Nukem 3D: not in v1.** EDuke32's renderer is under Ken Silverman's BUILD licence (non-commercial, not GPL-compatible) and there is no free replacement content. The engine-pack contract accepts admin-supplied files, so it can come later if Carmelo accepts that licence. | Low: Duke is a question for Carmelo (Q2), not a dead end. |
| 5 | **Game isolation** | **Every game runs in an `<iframe sandbox="allow-scripts">` with no `allow-same-origin`.** The game gets an opaque origin: no Kanboard cookies, DOM or CSRF token. It talks to the launcher only through a `postMessage` SDK. Third-party GPL engine code never runs with the user's session. | Medium: if a game truly needs same-origin, adding it per-manifest is easy, but the security review would be needed. |
| 6 | **Loading game files from a sandboxed frame** | An opaque-origin frame can load classic `<script src>` from the host but `fetch()` of `.wasm`/data is cross-origin and the stock nginx sends no CORS headers. **Ruling: the SDK's `asset(path)` asks the parent, which fetches the file (same origin) and transfers the `ArrayBuffer`.** Admin-supplied files (WADs, PAKs) come the same way from an authenticated controller. Game folders are served as static files (nginx `try_files`, verified in `kb-suite`). | Medium: an install that blocks static `.html` under `plugins/` would need a small streaming controller (fallback noted in spec). |
| 7 | **Multiplayer transport** | **Two tiers on one room model.** (a) **Room relay over polling:** rooms, members and an append-only message log with sequence numbers, POSTed/polled every 1 s while the room is open and the tab visible, 5 s otherwise; enough for lobby chat, turn-based and asynchronous play. (b) **Real-time via WebRTC data channels**, peer-to-peer, with the SDP/ICE signalling carried by the same room log. No STUN/TURN by default (same network works with host candidates: a LAN party); an admin may set ICE server URLs. No SSE: a held-open SSE stream per player ties up a PHP-FPM worker for the whole match, and AgentChat already showed polling is cheap. No WebSocket server: Kanboard has none and the suite avoids sidecars. | Medium: if WebRTC proves flaky across Carmelo's networks, a TURN server (admin config) or an optional relay sidecar can be added without changing the SDK surface. |
| 8 | **Shooter multiplayer** | **Not in v1.** doom-wasm talks to a WebSocket router. Phase 2 bridges it: the engine's WebSocket is replaced in JS by a shim over the room's WebRTC mesh. v1 shooters are single-player. | Low: it's a later phase by design. |
| 9 | **Scoreboard storage** | **Own tables** (`lanparty_scores`, `lanparty_rooms`, `lanparty_room_members`, `lanparty_room_messages`) with Schema for SQLite, MySQL and Postgres, as Presence and AgentChat do. Metadata tables can't rank or page. | Low. |
| 10 | **Score integrity** | **Trust but bound.** Scores come from the client. The server enforces the manifest's `scoring.max`, an integer type, a per-user rate limit (one score per game per 5 s) and stores the client's `seed`/`level` so a challenge can be replayed. It's a team tool; no anti-cheat. | Low: a leaderboard gets gamed; an admin can delete a score. |
| 11 | **Sharing** | **Challenge links** in v1: a launcher URL carrying game, level and seed, plus "beat this" text that copies to the clipboard. **AgentChat** (when installed, `recommends`) gets a "post to room" in phase 3. **Pulse** gets a `games played` activity source in phase 3. | Low. |
| 12 | **Launcher UX** | Netflix-like: a hero banner for the featured game, then rows: Continue playing, Rooms open now, LAN Party Originals, Engine games (only when a pack is installed). Cards show cover art, player count and your best score. A game page shows the leaderboard (all-time, this week) and a Play button that opens the game full-bleed over the launcher. Header link + avatar-menu link. | Low: it's CSS and one template. |
| 13 | **Original games** | Three originals, each a buildless canvas game with its own art and levels: **Saltroad** (lead a salt caravan across a desert to the coast: rations, water, camels, storms, trade posts; score = salt delivered), **Gusher** (lay pipe segments before the crude reaches the open end; fill the tanks; score = barrels banked), **Wicklings** (lead a line of tiny candle-folk to the lantern by handing out jobs: digger, blocker, bridger, climber; score = wicklings saved). Names are working titles to re-check before release (Q3). | Low: names change before release. |
| 14 | **Game-module contract** | A game is a folder with `game.json` + a static `index.html` that loads `lanparty-sdk.js`. Manifest: `slug`, `title`, `tagline`, `version`, `kind` (`original`/`engine`), `licence`, `content_licence`, `entry`, `art.cover`, `art.hero`, `players.min/max`, `modes` (`solo`/`async`/`realtime`), `scoring` (`order` high/low, `unit`, `max`), `files` (admin-supplied file slots). SDK: `ready()`, `player()`, `asset()`, `file()`, `submitScore()`, `save()/load()`, `room.*`, `exit()`. **Fixed in the spec before any game work starts**, so game sessions can run in parallel. | High once games are built against it; that's why it's settled first. |
| 15 | **Who plays and who sees** | Any signed-in user can play and see every leaderboard (games aren't project data). Agent users (Agents plugin) are left off leaderboards by default. Admin: enable/disable each game, delete a score, set ICE servers, upload engine files. | Low. |
| 16 | **Save games** | `save()/load()` store one JSON blob per user per game (≤ 64 KB) in `user_metadata` (key `lanparty.save.<slug>`). No new table. | Low. |
| 17 | **AI** | **None in v1.** AiConnector stays optional and unused. Phase 3 idea: Saltroad's trail diary narrated by AI. | None. |
| 18 | **Build order and parallelism** | Phase 1 (tonight): launcher core, registry, scores, SDK bridge, a tiny built-in sample game that exercises the SDK. Phase 2: rooms + WebRTC. Phase 3: the three originals, **one session per game in its own git worktree of the LanParty repo**, branching from the commit that fixes the contract. Phase 4: `LanPartyEngines` (Doom/Freedoom, then Quake/LibreQuake). | Medium: parallel game sessions only make sense once the contract is stable. |

## Questions for Carmelo (morning)

1. **Name:** LAN Party (tagline "Party Party") OK? Alternatives: Party Party, Break Room, Coin-Op, Recess.
2. **Duke Nukem 3D:** EDuke32's renderer is BUILD-licensed (non-commercial, not GPL-compatible) and has no
   free content. Leave Duke out, or accept the BUILD licence for an admin-supplied-data-only Duke slot?
3. **Original game names:** Saltroad, Gusher, Wicklings OK as working titles? A trademark search is due before release.
4. **Play hours:** should an admin be able to limit play to set hours ("recess mode")? Ruled out of v1.
5. **Agents on leaderboards:** hidden by default. Want an "agents league" instead?
6. **Repo + dev-stack mount:** OK to create `carmelosantana/kanboard-lan-party` (private until release) and
   add a `testing/docker-compose.lanparty-overlay.yml`? I didn't recreate `kb-suite` tonight because other
   sessions were using it.
