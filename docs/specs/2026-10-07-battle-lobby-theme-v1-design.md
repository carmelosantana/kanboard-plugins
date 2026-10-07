# Battle Lobby — battle-royale lobby theme for Kanboard (v1.0)

- **Status:** design settled; ready for planning
- **Wayfinder map:** Kanboard #5363 (board 94 "Kanboard"); all 11 cruxes resolved 2026-10-07
- **Plugin:** `BattleLobby` (dir + PHP namespace `Kanboard\Plugin\BattleLobby`), repo `carmelosantana/kanboard-battle-lobby`
- **Working folder:** `kanboard-plugins/BattleLobby` (renamed FortniteTheme → DropZone → BattleLobby; no git repo yet)
- **Research:** `BattleLobby/reports/Fortnite fan theme for Kanboard.md`, `BattleLobby/reports/Game character archetypes for theme.md`
- **Prototype (throwaway, primary source):** `BattleLobby/prototype/lobby-theme.prototype.html` (layout A + B quest bars + options page)
- **Stock markup reference:** `BattleLobby/docs/kanboard-reference/` (captured from the plain instance in `BattleLobby/testing/docker-compose.base.yml`, port 8082)

## Why

Fans of battle-royale games want a Kanboard that feels like loading a game and waiting in the lobby with your squad: a dark navy lobby over bright painted art, a party of characters on a stage, rarity-tier cards, a big yellow READY UP button, and a victory screen when you ship. Done well, it also encourages collaboration, because a project board becomes a shared lobby.

The theme must be **publicly releasable** on the ModMenu directory. Epic's Fan Content Policy forbids using its marks to identify fan content, and every Epic GitHub takedown on record involved ripped assets, cheats or game imagery. So Battle Lobby carries the *feel* (palette, shapes, motion) and none of Epic's *property* (name, logo, Burbank font, assets, characters, named locations). Colours, styling and art style are not copyrightable (37 CFR 202.1). The decisions below are not legal advice.

## Decisions

| # | Decision | Ticket |
|---|---|---|
| 1 | **Destination:** Battle Lobby v1.0 released publicly on ModMenu. No Epic IP, no PII in the repo. | Kanboard #5363 |
| 2 | **Layout:** hybrid. Prototype layout A's lobby shell (skewed top view tabs, character lineup footer with party plates and READY UP, shop-card priority tiers) plus layout B's quest-bar subtasks on cards and a milestone pass track above the board. Layout C (drop map) and B's left rail are out. | Kanboard #5364 |
| 3 | **Era:** Classic only (skew, rarity gradients, italic Barlow Condensed caps). Modern (rounded, upright Archivo) is deferred to v1.x. The `data-era` token switch stays in the CSS, but no option is exposed. | Kanboard #5365 |
| 4 | **Pages:** full lobby treatment on the **Board** page, the **Login** page and the **loading screen**. Every other page (dashboard, list, task view and modals, settings, activity, analytics, search) gets a token-only reskin: palette, fonts, glass panels, buttons. | Kanboard #5366 |
| 5 | **Mechanism:** core template hooks plus external CSS/JS only. **No `setTemplateOverride`.** Mount points are listed under Changes. CSP blocks inline script, so config travels in `data-*` attributes. | Kanboard #5367 |
| 6 | **Options:** admin defaults (Settings → Battle Lobby, app config) plus per-user overrides (profile → Battle Lobby, userMetadata). Each value resolves user → admin → built-in. | Kanboard #5368 |
| 7 | **Characters:** all 12 original characters, in **idle and victory** poses (about 24 cutouts, AVIF → WebP → PNG fallback, 2–4 MB). The sets are Brawl, Wildlands, Nightfall, Mixed and None. Characters are a **live layer** over the background, never baked into the art. Before release: fix Ossian's tail and Tabby's clawed paw grapple, re-roll anything Carmelo flags, and Carmelo reviews the contact sheet. | Kanboard #5369 |
| 8 | **Game feel:** victory overlay on task close (default on), storm due-date chips (on), READY UP start-next-task (on), activity-feed toasts and thank-the-driver (shipped, default off), loading screen (on). No sound. | Kanboard #5370 |
| 9 | **Coexistence:** mutually exclusive with ShadcnTheme. Battle Lobby declares `"conflicts": ["ShadcnTheme"]`, ModMenu warns on install (needs ModMenu support, see Changes), and Battle Lobby shows an admin banner if both are loaded. Dark only; a light variant is deferred. | Kanboard #5371 |
| 10 | **Release:** named **Battle Lobby**; MIT for code, CC BY 4.0 for original art and characters, fonts keep OFL 1.1. The README carries the Fan Content Policy 1.10 disclaimer. The fan pack is a gitignored `local/` folder. | Kanboard #5372 |
| 11 | **Budgets:** AA contrast over the brightest region of every background at the default tint; reduced-motion and reduced-transparency fallbacks; tiers shown with text as well as colour; blur on about 10 surfaces only; zip of 8 MB or less. | Kanboard #5373 |
| 12 | **Progress data lives in TimeReport,** not a new plugin and not Battle Lobby. TimeReport gains subtask bars, project and milestone progress, and XP (UI, API, agent tooling) and replaces the third-party TaskProgressBar. Battle Lobby *recommends* TimeReport and themes its markup; without it, the quest bars, pass track and level badge are hidden. | Kanboard #5374, Kanboard #5375 |
| 13 | **Fonts:** Barlow Condensed 900 and 900 italic, self-hosted WOFF2 (Latin subset) with `OFL.txt`. Self-hosting avoids the Google Fonts CDN GDPR risk (LG München I, 3 O 17493/20). | map pre-chart |
| 14 | **Render style:** one shared toon-PBR hybrid for every character (two-band shading, thin ink outline, warm key light, cyan rim light). Full cel-shading is deferred. | map pre-chart |
| 15 | **Glass:** frosted glass on the header, columns and options panels only. Task cards stay opaque. Default tint alpha is 0.78. | map pre-chart |

## Changes

### New plugin `BattleLobby/`

```
BattleLobby/
  Plugin.php                 hooks, routes, helper registration, conflict banner
  plugin.json                name, version, license, kanboard_version >=1.2.47, php_version >=8.4,
                             recommends TimeReport >=1.5.0 (progress), conflicts [ShadcnTheme]
  Controller/ConfigController.php   admin + user option pages (show/save, CSRF)
  Model/OptionsModel.php            option schema, defaults, resolve(user → admin → built-in), validation
  Helper/LobbyHelper.php            tier from priority, storm state from due date, data-* config payload
  Template/
    layout/bottom.php        lobby stage mount: lineup, party plates, READY UP, overlays, config data-*
    layout/head.php          font + active background preload
    board/tier.php           before-title tier label (data-tier="0..4", text COMMON…LEGENDARY)
    board/storm.php          task-footer storm chip (icon + text)
    auth/hero.php            login hero (character + art) around the login form
    config/sidebar.php, config/options.php, user/sidebar.php, user/options.php
    config/conflict_banner.php
  Assets/css/battle-lobby.css   tokens (--bl-*), classic era, reskin, board, login, overlays
  Assets/js/battle-lobby.js     reads data-* config; lineup, parallax, loading screen, victory, READY UP, feed toasts
  Assets/fonts/barlowcondensed/ *.woff2 + OFL.txt
  Assets/backgrounds/        board-map, header-storm, loading-island, victory art (AVIF/WebP, ≤400 KB each)
  Assets/characters/         manifest.json + <slug>-idle / <slug>-victory (AVIF/WebP/PNG)
  local/                     gitignored fan pack (font override, extra art) — loaded if present
  LICENSE (MIT), LICENSE-ART (CC BY 4.0), README.md (disclaimer), CHANGELOG.md, Test/
```

**Hook mount points** (Kanboard 1.2.47, verified in source):

| Surface | Hook |
|---|---|
| CSS / JS | `template:layout:css`, `template:layout:js` |
| Lobby stage, overlays, config payload | `template:layout:bottom` |
| Font and background preload | `template:layout:head` |
| Pass track (from TimeReport) | `template:project:header:after`, rendered only when TimeReport progress is present |
| Tier label | `template:board:private:task:before-title`; card frame styled via `.task-board:has(.bl-tier[data-tier="N"])` |
| Storm chip | `template:board:task:footer` (TimeReport's quest bar renders on the same hook) |
| Login hero | `template:auth:login-form:before` / `:after` |
| Admin options | `template:config:sidebar` + route `battlelobby/config` |
| User options | `template:user:sidebar:actions` + route `battlelobby/user` |

**Tier mapping:** priority 0 → Common, 1 → Uncommon, 2 → Rare, 3 → Epic. The project's highest priority value maps to Legendary when the project's priority range exceeds 3. Each tier renders a radial gradient frame, a 6px stripe and a text label (tokens from the research report).

**Storm chip:** no due date → none; more than 24h away → grey clock with "Nd"; under 24h → purple storm pulsing at 1 Hz or slower; overdue → red with "Nd late".

**READY UP:** moves the current user's highest-priority Ready task into the In progress column through an existing core route. It is visible only on the board page, and only when such a task exists.

**Victory overlay:** fires after a task close or move to Done from the board. It shows a random squad member's victory pose, "#1 VICTORY" and confetti for about 2.5s; clicking skips it. Under reduced motion it becomes a static fade under 1s.

**Loading screen:** an overlay on board navigation, shown only if the page has not painted within 300 ms. It has full-bleed art, one squad member, a keyboard-shortcut "TIP" and a progress bar.

**Options schema:** background (`map`, `storm`, `island`, `none`); character set (`mixed`, `brawl`, `wild`, `night`, `none`); placement (`auto`, `lineup`, `mascot`, `off`); motion (`auto`, `on`, `off`); frosted columns (bool); blur px (0–24); tint alpha (0.60–0.95, with a warning below 0.75); and toggles for loading screen, victory, storm chips, READY UP, feed toasts and level badge. Each user value falls back to the admin value, then to the built-in default.

**Characters pipeline (asset build, not runtime):**
1. Approved hero images come from `prototype/render_characters.py` (Qwen-Image Lightning, mid-grey background, shared style suffix, fixed per-character seeds).
2. Qwen-Image-Edit-2509 produces victory poses from each hero.
3. BiRefNet makes the cutouts.
4. One colour-match pass runs across the roster.
5. The images are encoded to AVIF, WebP and PNG, and `manifest.json` records slug, name, set, seed and prompt id.

Every character is screened against its avoid-list in the character report (for example: no Kratos look, no Catwoman cluster, no Minions).

### ModMenu (small addition)

`readMeta()` parses an optional `conflicts: ["PluginName", …]`. Install and enable show a non-blocking warning, "Conflicts with ShadcnTheme: disable one", and the Installed tab marks active conflicting pairs. Nothing is enforced. The directory entry passes `conflicts` through.

### TimeReport (separate effort, Kanboard #5375)

TimeReport progress (subtask bars through `template:board:task:footer`, project/milestone progress, XP) ships as **TimeReport 1.5.0**, with its own map, spec and plan. Battle Lobby only consumes its rendered markup and a small `data-*` contract, which is defined in TimeReport's spec.

### Directory listing

Add a `kanboard-modmenu-directory` entry for BattleLobby with the description "Battle-royale lobby theme for Kanboard: original characters, rarity-tier cards, READY UP and a victory screen. Fan-made; not affiliated with Epic Games." Also list `recommends` and `conflicts`.

## Non-goals (v1.0)

- Modern era option and light variant.
- Lobby-style dashboard, task "item inspect" view and drop-map layout (C).
- Sound effects.
- Any Epic name, mark, font, asset, character or named location in the repo or zip.
- img2img or LoRA trained on game content.
- Monetisation.
- Building progress or XP logic inside Battle Lobby (that is TimeReport's job).
- An "online now" presence indicator. It is being grilled separately and becomes a v1.x fast follow.
- Template overrides of core files.
- Support for running alongside ShadcnTheme.

## Sequencing

1. **Repo bootstrap.** `git init` BattleLobby, add `.gitignore` (`local/`, `prototype/`, `research_notes/`, `testing/.env`), LICENSE, LICENSE-ART, README with the disclaimer, plugin.json and a skeleton `Plugin.php` with tests. Move the prototype onto a throwaway `prototype` branch; main keeps only validated decisions.
2. **Tokens and reskin.** Add `battle-lobby.css` tokens and the classic era, the self-hosted fonts and the token-only reskin of all pages. Check against `docs/kanboard-reference` screenshots.
3. **Options.** OptionsModel (schema, resolve, validation, tests first), admin and user pages, and the `data-*` config payload.
4. **Board.** Tier label hook, `:has` card frames, storm chip, skewed view tabs, glass columns, and the background layer.
5. **Lobby stage.** Character lineup and mascot from `manifest.json`, party plates, READY UP, parallax and breathing with reduced-motion fallbacks.
6. **Overlays.** Victory, loading screen, feed toasts and thank-the-driver (default off), and the login hero.
7. **Art finalisation.** Fix Ossian and Tabby, produce victory poses, cut out, colour-match and encode; Carmelo reviews the contact sheet; final backgrounds.
8. **Budgets pass.** Contrast audit over every background, reduced-motion and reduced-transparency checks, zip of 8 MB or less, and the CSP check (no inline script).
9. **ModMenu `conflicts`** (small, can run in parallel with steps 2–8).
10. **Release.** CHANGELOG, tag v1.0.0, zip-on-tag CI, directory entry. The TimeReport 1.5.0 integration (pass track, quest bars, level badge) lights up whenever that release lands; Battle Lobby v1.0 does not wait on it.
