# LAN Party original games: session briefs

One session per game, each in its own git worktree of the LanParty repo, branching from the commit that
finished phase 1 (contract frozen). Each game is a static folder `LanParty/Games/<slug>/` built against
`LanParty/docs/game-contract.md`. All three are **original**: own names, art, levels and text. They must not
reference or imitate any existing game's name, characters, art, maps, levels, UI or text.

## Shared rules for every game session

- **Worktree:** `git -C ~/Projects/Kanboard/kanboard-plugins/LanParty worktree add ~/Projects/Kanboard/lanparty-worktrees/<slug> -b game/<slug> <phase-1 head>`.
  Keep it outside `kanboard-plugins/`, so the harness test runner never sees two `LanParty` plugins. Commit on
  `game/<slug>` only. No push, remote, tag or merge. No AI attribution.
- **Buildless:** plain ES2017 + `<canvas>`, one classic `<script>` per file, no modules, no dependencies, no
  external requests, no inline `<script>`/`on*=` attributes. Load the SDK with
  `<script src="../../../LanParty/Assets/js/lanparty-sdk.js"></script>`. Copy the shape of `Games/pop`.
- **Contract:**
  - No path segment starts with `data`.
  - No `%`, `?`, `#` or `.php` in any asset path.
  - Assets load through `LanParty.asset()`.
  - Scores are integers in `0..scoring.max`.
  - Saves are plain objects ≤ 64 KB.
  - Challenges carry `level` + `seed`, so use a seeded PRNG.
- **Tests (TDD):**
  - Pure game logic (simulation, scoring, level parsing, PRNG) lives in its own file and is exported with
    the `module.exports` guard. It's tested with `node:test`: `node --test LanParty/Test/js/*.test.mjs` from the worktree's parent harness copy, or `node --test <worktree>/Test/js/<slug>*.test.mjs`.
  - Add a PHP test that the manifest validates and passes the registry's path rules.
- **Art:** hand-written SVG or canvas-drawn shapes. Cover is 2:3, hero is 21:9. Licence MIT, content CC-BY-4.0.
- **Accessibility:** keyboard-playable, `prefers-reduced-motion` respected, colour isn't the only signal.
- **Done:** playable from title screen to results; score submit, share and save/load wired; tests green; a short `README.md` in the game folder (how to play, levels, credits).

## Saltroad (trail-journey survival), slug `saltroad`

You lead a salt caravan from the inland pans to a coastal market across ~20 waypoints: dunes, wells, a
canyon pass, a river ford, trade posts.
- **Each day:** pick pace (steady / hard / rest) and rations (full / half / lean).
- **Resources:** water, food, camel strength, coin, salt (the cargo).
- **Events:** draws from a seeded deck (sandstorm, lame camel, oasis, bandit toll, trader offer, lost track), each
  with 2–3 choices and visible odds.
- **Trading:** at posts, trade salt for water/food/camels.
- **Score:** salt bars delivered × days-bonus (`scoring.order high`, unit `bars`).
- **Levels:** `"1"` short route, `"2"` long route.
- **Writing:** original text throughout. Diary entries are short, wry and humane, with no named real-world peoples.
- **Players:** `players 1/1`, `modes ["solo","async"]`. Async: a shared seed makes everyone face the same deck.

## Gusher (oil pipe-flow puzzle), slug `gusher`

A grid board with a wellhead that starts pumping crude after a countdown.
- **Laying pipe:** you place pipe pieces from a 5-piece preview queue: straight, bend, cross, valve and
  tank-junction. A placed piece can be overwritten at a time cost.
- **Flow:** crude flows cell by cell and fills tanks on the way. A spill (an open end) ends the round.
- **Score:** barrels banked (cells filled + tank bonuses), unit `barrels`.
- **Levels:** 10 hand-made levels in a JSON file (not under a `data*` folder), plus seeded "endless" boards.
- **Look:** fluid fill rendered on canvas; a calm industrial palette with an original look.
- **Players:** `players 1/1`, `modes ["solo","async"]`.

## Wicklings (lead-the-walkers puzzle), slug `wicklings`

Tiny candle-folk drop from a door and walk until they hit a wall, then turn.
- **Goal:** get N of M to the lantern.
- **Jobs:** you assign limited jobs to individuals: **digger** (down), **blocker** (stops and turns others),
  **bridger** (lays 8 planks), **climber** (scales walls) and **floater** (safe falls).
- **Hazards:** water snuffs a wickling, and long falls do too.
- **Score:** wicklings saved × 100 + time bonus, unit `wicks`.
- **Levels:** 12 hand-made levels in a JSON tile format.
- **Engine:** deterministic fixed-step simulation, so a level and seed replay identically.
- **Players:** `players 1/1`, `modes ["solo","async"]`.

## After the three games

- **Phase 2 (rooms + WebRTC):** needs its own plan from the spec's phase 2.
- **Phase 4 (`LanPartyEngines`, GPL-2):** a new repo. Doom (Chocolate Doom WASM lineage) + Freedoom first, then Quake + LibreQuake. Duke waits on Carmelo's BUILD-licence answer.
