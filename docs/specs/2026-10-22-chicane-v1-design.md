# Chicane v1 — a Kanboard project as a race track

**Status:** spec, converged from the wayfinding map (local Kanboard #91, board "Chicane", 2026-10-22).
**Prototype:** `ChicanePrototype/`, branch `prototype/chicane` @ 5d97fe9 (local, throwaway; variant A won).
**Research:** [HTML5 2D engines with 3D](../research/2026-10-22-html5-2d-engines-with-3d.md), screenshots in
[research/chicane/](../research/chicane/).

## Why

A board shows *state*. It doesn't show how far the team has come, how much road is left, or where work has
stalled. Chicane renders a project as an animated race track. Each task is a corner and its subtasks are a
chicane. Finished road is paved, and the road ahead is raw. Each person's car sits on the task they're working
on, every comment is a spectator in the stands, and stalled or abandoned work leaves hazards on the road.
A standalone page, optionally public and optionally stripped of details, lets people outside the board watch
progress without seeing the work itself.

## Decisions

| # | Decision | Source |
|---|---|---|
| 1 | Name **Chicane**: plugin dir `Chicane/`, namespace `Kanboard\Plugin\Chicane`, own repo `carmelosantana/kanboard-chicane`, buildless like the rest of the suite. | Kanboard #92 |
| 2 | Renderer **PixiJS 8.22 + GSAP 3.15** (MotionPath), vendored at pinned versions, isometric 2.5D. LittleJS 1.28 and Phaser 4 lost. Kanboard's default CSP (`default-src 'self'`) forces vendoring; Pixi needs its `unsafe-eval` shim and a `ParticleBuffer` workaround (the shim patches its own copy). | Kanboard #93, #103 |
| 3 | One **shared track per project**. Each swimlane is a parallel racing lane. | Kanboard #94 |
| 4 | Course scope is a **season window**: open tasks plus tasks closed in the last N days (default 30, per project). | Kanboard #94 |
| 5 | **Unit = subtask**; a task with no subtasks = 1. Complexity weighting (`max(1, score, subtasks)`) stays an option, but on Beige Bullet every score is 0, so it's identical there. | Kanboard #95 |
| 6 | **Turns are permanent.** Each task is a lead-in straight plus a corner: a gentle sweeper with no subtasks, a chicane of one turn per subtask with them. Geometry depends only on task id and unit count, so finishing work never moves the road; it **paves** it. Progress is distance driven: the frontier is the first unpaved point. (Reversed the earlier "done work straightens", which made finished work look easy.) | Kanboard #95 |
| 7 | **Stopped-mid-flight hazards.** *Pothole* per unfinished subtask on a task that was closed or moved to Done. *Tyres/debris* on an in-progress task idle 3+ days, more with age. *Wreck* at the roadside for abandoned work (`wontfix`, or closed with no subtask done). *Oil slick* for reopened tasks (`task.open` events); cars slow through it. | Kanboard #95 |
| 8 | **Cars** = users. A car parks at the front of the open part of its driver's in-progress task. It is fast on straights and slow in turns (`speed = 520 / (1 + 160·|curvature|)` px/s). Users with no in-progress task sit in the pits. | Kanboard #96 |
| 9 | **One spectator per comment**, in a grandstand on the outside of its task's corner. Comments from the last 48 h cheer. | Kanboard #97 |
| 10 | **Visibility per project**: Off / Members only / Public full / Public map-only, via an unguessable share-token URL. Map-only hides task titles, comment text and real names (drivers become initials); it keeps the track, progress, cars, crowd and hazards. | Kanboard #98 |
| 11 | **Chat and elsewhere**: a share URL plus a token-authed JSON summary keyed by `project_id`, map-only by default. No iframe/oEmbed (Chat renders plain text and lives on another origin). | Kanboard #98 |
| 12 | **Live**: poll while the page is open, and on return play an eased "since your last visit" morph from the last seen state to now. Every animation eases. | Kanboard #99 |
| 13 | **Ecosystem feeds in v1**: Presence (online cars glow; offline are dimmed), DependencyPlugin / core "is blocked by" links (barrier + yellow flag), Agents (agent livery + badge), TimeReport (level + XP plate). AiConnector commentary is deferred. | Kanboard #100 |
| 14 | **Art**: original only (BattleLobby rule: no third-party IP or PII in public builds). Procedural Pixi art first; ComfyUI sprites are optional polish later. | Kanboard #101 |
| 15 | **Test data**: a re-runnable, read-only import script copies production Beige Bullet (project 155) into the local harness on :8081. | Kanboard #102 |
| 16 | **Morph aligns by task**, not by overall length fraction, so adding one task doesn't slide the whole course. | Kanboard #95, #103 |

## Changes

### Plugin skeleton (`Chicane/`)
- `Plugin.php`, `plugin.json`, buildless PHP/JS/CSS, zip-on-tag CI, ModMenu listing, following
  **kanboard:plugin-suite**. `recommends` lists Presence, Agents, TimeReport and DependencyPlugin, each with
  a reason. Nothing is required.
- Per-project settings live in project metadata: `chicane.visibility` (`off|members|public|map_only`,
  default `off`), `chicane.token` (random, 32 bytes, rotatable), `chicane.window_days` (default 30) and
  `chicane.units` (`subtask|complexity`).
- A project settings page sets visibility, window and units, and shows and rotates the share URL.
  Project managers can edit it.
- A "Race" link in the project header/board menu opens the members view.

### Routes and access
| Route | Access | Returns |
|---|---|---|
| `/chicane/{project_id}` | project members | race page (full detail) |
| `/chicane/{project_id}/state.json` | project members | state JSON, ETag |
| `/race/{token}` | public (`Role::APP_PUBLIC`) when visibility is `public` or `map_only` | race page |
| `/race/{token}/state.json` | same | state JSON, redacted when `map_only` |
| `/race/{token}/summary.json` | same | `{project_id, progress_pct, distance_to_finish, cars:[{user, task_id}], updated_at}` |

- Visibility `off`: the token routes return 404. `members`: the token routes return 404 and only the
  member routes work.
- **Redaction happens on the server.** In map-only, titles, comment text and real names never reach the
  client. Users become initials (the first letters of two words, or the first two letters of one word).
  The summary's `task_id` is `null`.
- The race page is a bare standalone template (no Kanboard chrome). It runs under Kanboard's default CSP
  unchanged; the plugin never calls `setContentSecurityPolicy()`, because that would replace the policy
  app-wide.

### State builder (PHP)
- `Model/RaceStateModel` builds the state from core models, with the same shape the prototype used:
  `{generated, window_days, swimlanes, columns, users, agent_uids, reopened, tasks:[{id, title, open,
  column_id, swimlane_id, owner_id, position, score, date_moved, date_completed, tags, blocked_by,
  subtasks:[{status, position}], comments:[{user_id, date}]}]}`.
- `reopened` comes from the project's `task.open` events within the window. It reads the full event
  history from the database, not the API's last 50 events.
- `agent_uids` come from the Agents plugin when it's active, otherwise `[]`. Online state comes from
  Presence when it's active; otherwise the online field is `null` and the cars render neutral.
- XP and level come from TimeReport when it's active; otherwise the level plate is hidden. There is no
  stand-in formula in v1.
- An ETag (hash of the state) lets polling return 304.

### Track model (`Assets/js/track.js`)
- Port the prototype's engine-agnostic model: ordering (closed by completion, then open by column →
  swimlane → position), turtle layout on a serpentine, centripetal Catmull-Rom resampled every 4 px,
  curvature, `done` per point, segments, cars, spectators, barriers and hazards.
- Keep the guards proven in the prototype: turn radius ≥ 1.15 × half the road width (no edge fold-over),
  never turning more than 100° off the row direction, spectators spread across their segment, and unique
  initials.
- Change `morph`: align the old and new paths per task id. Matching segments interpolate in place; new
  segments grow from zero length; removed segments shrink away.
- Zero-dependency Node-native tests (`node --test`) cover:
  - the same state gives the same geometry
  - completing a subtask moves 0 points
  - the minimum radius holds
  - each hazard rule
  - map-only input containing no titles or names
  - morph alignment by task

### Renderer (`Assets/js/race.js`, `Assets/css/race.css`, `Assets/vendor/`)
- Rewrite variant A properly; don't copy prototype code verbatim. It includes:
  - the isometric projection with a raised road and soft shadows
  - paved road (dark tarmac, racing line, kerbs and skid marks on paved corners, sponsor boards)
  - raw road (gravel, no paint, cones and survey stakes)
  - a paving sweep when work completes
  - start gantry, pit lane, chequered finish
  - stands rendered as meshes, with the crowd in a `ParticleContainer`
  - cars with livery and badges
  - the four hazards plus a legend
  - minimap and clickable jump
  - drag/inertia pan, cursor-anchored zoom, and an intro fly-to that ends on the leading car
- Budget: ≤ 8 ms of script per frame at Beige Bullet scale (≈700 segments, 2,100 spectators, 4,600
  props). Static geometry is cached and offscreen layers culled.
- `prefers-reduced-motion`: no fly-in; transitions shorten to 0.3 s.
- The HUD carries over from the prototype without the simulation buttons. It has a progress meter (units
  done / total, turns ahead, units to the flag) and a driver list.

### Live updates
- Poll `state.json` every 20 s while the tab is visible, using the ETag. Each change runs
  `transition(old, new)`.
- "Since your last visit": the last seen state goes in `localStorage` per viewer and URL (a per-viewer
  convenience). On load, if it differs, play the morph from it. A storage failure just skips the replay.

### Test harness
- `testing/import-board.sh <prod_project_id>` (kanboard-plugins repo) reads production through
  kb-lib, read-only, with parallel requests. It recreates the swimlanes, users, tasks, subtasks, comments,
  tags and blocked-by links on local :8081 using the harness token.
  - It reproduces reopened tasks by closing and then reopening them, so local `task.open` events exist.
  - It is idempotent per run: it wipes and recreates a project named `<name> (import)`.
- Mount `Chicane/` in `testing/docker-compose.dev.yml`.

## Non-goals (v1)
- AiConnector race commentary.
- PNG/SVG thumbnail of the track (Chat's option c).
- iframe or oEmbed embedding.
- Linking Chat rooms to a project. Chat has no project rooms yet (production map #6637); the summary
  endpoint is the future hook.
- 3D rendering, the LittleJS/pixel variant, and one track per swimlane.
- Writing to the board from the race page, or any interaction beyond viewing.
- Configurable hazard thresholds (idle stays at 3 days and "recent" comments at 48 h).

## Sequencing
1. **Import script**: Beige Bullet on local :8081, the test bed for everything after.
2. **Plugin skeleton + settings + routes + access**, including the visibility matrix and token rotation,
   with PHPUnit tests on access and 404s.
3. **State builder + redaction**: `state.json` and `summary.json`, with PHPUnit tests that map-only output
   contains no titles, comment text or names, and on ETag/304.
4. **Track model port + morph-by-task**, with the `node --test` suite.
5. **Renderer**, rewritten from prototype A against the real `state.json`; CSP checked in headless Chrome.
6. **Feeds**: Presence, Agents, TimeReport and blocked-by, each degrading cleanly when its plugin is absent.
7. **Live polling + since-last-visit morph.**
8. **Release**: README, ModMenu directory listing, tag `v1.0.0`.
