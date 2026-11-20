# LAN Party Launcher (Phase 1) Implementation Plan

> **For agentic workers:** use superpowers:subagent-driven-development. Implementer = Opus, reviewer = Opus,
> `model:` passed on every dispatch. Test-first on every task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** `LanParty` v0.1.0 (unreleased): the launcher, game registry, scoreboard, saves, host bridge + SDK and
a sample game, with the game-module contract frozen so the original games and the engine pack can be built
in parallel.
**Method:** SDD (the brief asked for it).
**Spec:** `docs/specs/2026-11-19-lanparty-design.md`. Rulings: `docs/superpowers/plans/lanparty-wayfinding.md`.
**Architecture:** `ManifestValidator` (pure) checks `game.json`; `GameRegistry` merges `$container['lanpartyGames']`
and returns valid games; `ScoreModel` and `SaveModel` own storage; `ScoreController`/`SaveController` are JSON
endpoints; `LauncherController` renders the launcher and game pages; `lanparty-host.js` (parent) and
`lanparty-sdk.js` (in the sandboxed iframe) speak the postMessage protocol; `Games/pop` exercises it.
**Tech stack:** PHP 8.4, Kanboard 1.2.47 plugin API, PHPUnit (harness runner), plain CSS, vanilla JS +
`node --test` (zero dependency).

**Board:** not seeded. The Kanboard MCP is production and the :8081 board is shared with sessions working
tonight; seed with `kanboard-seed.sh` once Carmelo picks a board (morning question 6).

## Global constraints

- Folder `kanboard-plugins/LanParty`, its own git repo (branch `main`), namespace `Kanboard\Plugin\LanParty`,
  MIT, author `Carmelo Santana`, homepage `https://github.com/carmelosantana/kanboard-lan-party`,
  `kanboard_version` `>=1.2.47`, `php_version` `>=8.4`, version `0.1.0` in `plugin.json` and
  `getPluginVersion()`.
- `plugin.json` deps, verbatim: `"requires": []`, `"recommends": [{ "plugin": "Agents", "min_version": "0.5.0", "reason": "keeps agent users off the leaderboards" }]`.
- Buildless, no inline JS or `on*=` attributes (CSP), no new JS libraries, jQuery/`KB` optional (the host
  bridge is plain DOM so `node --test` can load it).
- Commits in the LanParty repo only touch LanParty files; no AI attribution; no push, no remote, no tag.
- Tests: `./testing/run-plugin-tests.sh LanParty` from the harness root; JS: `node --test LanParty/Test/js/`.
  The last task runs `./testing/run-plugin-tests.sh --all` to completion.
- Frozen strings (asserted, don't rename): routes `lanparty`, `lanparty/game/:slug`, `lanparty/score`,
  `lanparty/scores/:slug`, `lanparty/save`, `lanparty/load/:slug`; tables `lanparty_scores`; config key
  `lanparty_disabled`; user-metadata key prefix `lanparty.save.`; container key `lanpartyGames`; SDK global
  `LanParty`; message envelope key `lp`; error codes `bad_request`, `forbidden`, `missing`, `rate_limited`,
  `too_large`, `unavailable`.

## Review focus

1. **Sandbox escape:** the iframe never has `allow-same-origin`; the host ignores messages from any other
   window or with a wrong nonce; `asset()` rejects `..`, absolute paths and schemes.
2. **Score bounds:** non-integer, negative, over-`max`, missing-`scoring` game and rate-limited submits are
   all refused with the right code and write nothing.
3. **Leaderboard correctness:** best-per-user for `high` and `low`, tie → earlier `created_at` wins, `week`
   window, agent users excluded only when Agents is installed and migrated.
4. **Registry hygiene:** duplicate slugs (first wins), invalid manifests skipped with a reason, `/data`
   segments and `.php` files rejected, disabled games hidden from players but listed for admins.

---

### Task 1: Scaffold the plugin repo

**Files:** create `LanParty/plugin.json`, `LanParty/Plugin.php`, `LanParty/LICENSE` (copy Presence's MIT),
`LanParty/README.md` (stub: what it is, install, licences, the no-recreations rule),
`LanParty/.github/workflows/release.yml` (verbatim copy of `Pulse/.github/workflows/release.yml`),
`LanParty/.gitignore` (`.DS_Store`), test `LanParty/Test/PluginVersionTest.php`.

- [ ] Write `PluginVersionTest`: `plugin.json` version `0.1.0` equals `getPluginVersion()`; `requires` is `[]`;
  every `recommends` entry has keys exactly `plugin, min_version, reason` with a bare `x.y.z`. Fails if the
  `Plugin.php` version is changed to `0.1.1`.
- [ ] `git init -b main LanParty`, run `./testing/run-plugin-tests.sh LanParty`: fails (no Plugin class).
- [ ] Write `plugin.json` and `Plugin.php` (getters only, empty `initialize()`).
- [ ] Run: passes. Commit `chore: scaffold LanParty plugin`.

### Task 2: Manifest validator

**Files:** `LanParty/Model/ManifestValidator.php`, `LanParty/Test/ManifestValidatorTest.php`.

Interface: `validate(array $manifest): array` returns a list of error strings (empty = valid), one per broken
rule, worded `"<field>: <problem>"`. Rules exactly as the spec's manifest table (slug regex, title ≤ 40,
tagline ≤ 80, kind, licence + content_licence non-empty strings, entry relative without `..` or leading `/`,
art.cover required, players 1 ≤ min ≤ max ≤ 16, modes non-empty subset, scoring optional with order/unit/max,
files slots with `id` matching the slug regex, `label`, `accept` list of `.ext`, `required` bool).

- [ ] Tests: one valid fixture passes; one test per rule with a minimal mutation of the fixture asserting the
  error mentions that field. Fails if any single rule check is deleted.
- [ ] Run: fails. Implement. Run: passes. Commit `feat: validate game manifests`.

### Task 3: Game registry

**Files:** `LanParty/Model/GameRegistry.php`, `LanParty/Test/GameRegistryTest.php`,
fixtures under `LanParty/Test/fixtures/games/{good,dupe,bad,datapath,phpfile}/`, `LanParty/Plugin.php`
(register own `Games/*` into `lanpartyGames`; register `gameRegistry` service).

Interface:
- `all(): array` → slug ⇒ game (manifest + `plugin`, `base_url` `plugins/<plugin>/Games/<slug>/` (relative: templates prefix it with `$this->helper->url->dir()` so sub-folder installs work), `path`), valid
  and enabled only, in registration order.
- `allForAdmin(): array` → every entry incl. disabled (`enabled` flag) and invalid (`errors` list).
- `get(string $slug): ?array` → enabled valid game or null.
- `setEnabled(string $slug, bool $on): void` → config `lanparty_disabled` CSV.

Entries come from `$container['lanpartyGames']` (`[['plugin' => ..., 'path' => ...]]`). A folder fails when
`game.json` is missing/invalid JSON, `ManifestValidator` errors, the manifest `slug` differs from the folder
name, any relative path segment starts with `data`, or any file ends in `.php`, `.log`, `.sqlite`. `base_url`
is derived from the path's position under the plugin folder (`<plugin>/Games/<slug>`); a path not shaped
like that is invalid.

- [ ] Tests: good game listed with base_url; duplicate slug → first wins, second in admin list with error
  `duplicate slug`; each bad fixture rejected with its reason; disabled game hidden from `all()`/`get()` but in
  `allForAdmin()`; empty/missing container key → `[]`. Fails if the `/data` check is removed (datapath fixture
  has `levels/database.json`).
- [ ] Run: fails. Implement. Run: passes. Commit `feat: game registry over lanpartyGames`.

### Task 4: Scores schema and model

**Files:** `LanParty/Schema/{Sqlite,Mysql,Postgres}.php` (`version_1`: `lanparty_scores` per spec, indexes),
`LanParty/Model/ScoreModel.php`, `LanParty/Model/AgentFilter.php` (same shape as Presence's `AgentDetector`),
`LanParty/Test/ScoreModelTest.php`.

Interface:
- `submit(array $game, int $userId, mixed $score, ?string $level, ?string $seed, mixed $meta, ?int $now = null): array`
  → `['accepted' => true, 'rank' => int, 'best' => int]` or throws `ScoreRejected` (`code` property:
  `bad_request`, `rate_limited`, `unavailable` when the game has no `scoring`).
- `leaderboard(array $game, string $period = 'all', int $limit = 10, ?int $now = null): array` → rows
  `rank, user_id, name, score, level, date`.
- `best(string $slug, int $userId, string $order): ?int`.
- `playedSlugs(int $userId): array`, `mostPlayedSince(int $since): ?string` (for the launcher rows).

- [ ] Tests: accepts an in-range int; rejects float, string, negative, > max, bad level/seed chars, meta > 1 KB
  or non-JSON-encodable; second submit within 5 s → `rate_limited`, at 5 s → accepted (inject `$now`);
  leaderboard best-per-user high and low; tie broken by earlier `created_at`; `week` excludes 8-day-old rows;
  agent user excluded when `AgentFilter` is given a stub class returning that id, included when the class is
  absent. Each test names its mutation in a comment.
- [ ] Run: fails. Implement (bucket in PHP, no DB-specific SQL). Run: passes. Commit `feat: scores and leaderboards`.

### Task 5: Saves

**Files:** `LanParty/Model/SaveModel.php`, `LanParty/Test/SaveModelTest.php`.

Interface: `save(int $userId, string $slug, mixed $data): void` (throws `ScoreRejected('too_large')` above
65 536 bytes of JSON, `bad_request` when not encodable); `load(int $userId, string $slug): mixed` (null when
none). Stored in `user_metadata` key `lanparty.save.<slug>`.

- [ ] Tests: round trip; per-user and per-game isolation; 65 536 bytes OK, 65 537 rejected.
- [ ] Run: fails. Implement. Run: passes. Commit `feat: per-user game saves`.

### Task 6: JSON endpoints

**Files:** `LanParty/Controller/ScoreController.php` (`submit`, `board`), `LanParty/Controller/SaveController.php`
(`save`, `load`), routes in `LanParty/Plugin.php`, `LanParty/Test/ScoreControllerTest.php`,
`LanParty/Test/SaveControllerTest.php`.

POST bodies are form fields (`slug`, `score`, `level`, `seed`, `meta` as JSON string, `data` as JSON string,
`csrf_token`). Errors respond `json(['error' => ['code' => ..., 'message' => ...]], <status>)` with 400/403/404/
413/429/503. Unknown or disabled slug → 404 `missing`.

- [ ] Tests (Presence's mocked-`Response` pattern): submit happy path returns rank/best; missing CSRF throws
  `AccessForbiddenException`; unknown slug 404; rate limit 429; board returns rows for `period=week`; save/load
  round trip; oversize save 413.
- [ ] Run: fails. Implement. Run: passes. Commit `feat: score and save endpoints`.

### Task 7: Launcher and game pages

**Files:** `LanParty/Controller/LauncherController.php` (`index`, `game`), `LanParty/Template/launcher/{index,game,card,row}.php`,
`LanParty/Template/header/dropdown.php`, `LanParty/Template/dashboard/sidebar.php`, `LanParty/Assets/css/lanparty.css`,
hooks + routes in `LanParty/Plugin.php`, `LanParty/Test/LauncherControllerTest.php`.

- `index`: hero (most played this week, else first `original`, else first game; none → empty state "No games
  installed"), rows *Continue playing* (played slugs), *LAN Party Originals* (`kind: original`), *Engine games*
  (`kind: engine`, row omitted when empty). Card: cover `<img>` from `base_url + art.cover`, title, players
  badge (`1P` or `1–4P`), your best score.
- `game`: hero art, tagline, licences, leaderboard (all/week, server-rendered), Play button with
  `data-lp-slug`, `data-lp-src` (`base_url + entry`), `data-lp-csrf` (reusable token), `data-lp-endpoints`
  (JSON of route URLs), challenge banner when `?level=&seed=` and `&from=<user_id>` present.
- Assets: `lanparty.css`, `lanparty-host.js` injected through the hooks only when the controller is
  `LauncherController` (template-hook `callable` guard or route check).

- [ ] Tests: index lists a registered fixture game's title and cover URL; engine row absent with no engine
  games; disabled game absent; game page for unknown slug → 404; Play button carries the four data attributes
  and the page has no `<script>` without `src` and no `on[a-z]+=` attribute (CSP guard).
- [ ] Run: fails. Implement. Run: passes. Commit `feat: launcher and game pages`.

### Task 8: Host bridge and SDK

**Files:** `LanParty/Assets/js/lanparty-host.js`, `LanParty/Assets/js/lanparty-sdk.js`,
`LanParty/Test/js/host.test.mjs`, `LanParty/Test/js/sdk.test.mjs`.

Protocol: request `{lp: 1, id, nonce, op, args}` → reply `{lp: 1, id, ok: true, result}` or
`{lp: 1, id, ok: false, error: {code, message}}`. Ops: `ready, asset, file, submitScore, leaderboard, save,
load, share, exit`. Both files export a pure core for tests (`module.exports` when `module` exists, global
otherwise): host `normalizeAssetPath(path)` (null when unsafe), `validateRequest(msg, expectedNonce)` (returns
`{code}` or null), `createHost({iframe, nonce, endpoints, csrf, fetch, clipboard, onExit})`; SDK
`createClient({post, nonce})` with request/response matching by `id`.

The host opens the overlay, creates `<iframe sandbox="allow-scripts" allow="fullscreen; gamepad"
src="<src>#lp=<nonce>">` (nonce from `crypto.getRandomValues`), listens to `message` on `window`, and answers
only when `event.source === iframe.contentWindow` and the nonce matches; replies with
`iframe.contentWindow.postMessage(reply, '*')` (the frame's origin is opaque, so `'*'` is required; the
target window is fixed). `asset` fetches `base_url + normalised path` and replies with the `ArrayBuffer` in the
transfer list.

- [ ] Tests: `normalizeAssetPath` accepts `art/a.png`, `./x.js`, rejects `../x`, `/x`, `a/../../x`,
  `http://x`, `//x`, `data:…`, backslashes; `validateRequest` rejects wrong nonce, unknown op, missing id,
  non-object; host ignores a message from another source (fake event) and answers the right one;
  `submitScore` POSTs form fields with `csrf_token`; SDK resolves/rejects by id and ignores replies with
  unknown ids. Fails if the source check is removed.
- [ ] Run `node --test LanParty/Test/js/`: fails. Implement. Run: passes. Commit `feat: postMessage host bridge and game SDK`.

### Task 9: Sample game `pop`

**Files:** `LanParty/Games/pop/{game.json,index.html,pop.js,cover.svg,hero.svg}`, `LanParty/Test/SampleGameTest.php`.

An original 30-second game: coloured bubbles rise, click to pop, combo multiplier, then a results card with
Submit / Share / Again / Exit. Uses `ready`, `asset` (loads `levels.json`), `submitScore`, `save`/`load` (best
local streak), `share`, `exit`. Original art (simple SVG). Manifest: `kind: original`, MIT / CC-BY-4.0,
`players 1/1`, `modes ["solo","async"]`, `scoring {order: high, unit: "pops", max: 100000}`.

- [ ] Tests: the shipped `pop` manifest validates; the registry lists `pop` from the real plugin folder;
  no file under `LanParty/Games/` breaks the path rules; `index.html` loads `../../../LanParty/Assets/js/lanparty-sdk.js`
  and has no inline `<script>` (keeps it portable even though the frame has no CSP).
- [ ] Run: fails. Implement. Run: passes. Commit `feat: pop sample game`.

### Task 10: Full suite, README, contract doc

**Files:** `LanParty/README.md`, `LanParty/docs/game-contract.md` (the frozen contract for game authors,
copied from the spec's contract section plus a minimal `index.html` example), `LanParty/CHANGELOG.md`.

- [ ] Run `./testing/run-plugin-tests.sh --all` to completion; every suite passes (or pre-existing failures
  are recorded with their output, untouched).
- [ ] Run `node --test LanParty/Test/js/`.
- [ ] Commit `docs: README, game contract, changelog`.

## Later phases (separate plans)

- **Phase 2 rooms:** schema v2 (rooms, members, messages), `RoomModel`, `RoomController`, SDK `room.*`, WebRTC
  signalling over `kind: signal`, ICE servers config, "Rooms open now" row.
- **Phase 3 originals:** Saltroad, Gusher, Wicklings, one session each on a branch `game/<slug>` in its own
  `git worktree` of the LanParty repo, placed outside the harness root (e.g. `~/Projects/Kanboard/lanparty-worktrees/<slug>`)
  so the harness runner never sees two `LanParty` plugins.
- **Phase 4 engines:** `LanPartyEngines` (GPL-2), Doom + Freedoom, then Quake + LibreQuake.
