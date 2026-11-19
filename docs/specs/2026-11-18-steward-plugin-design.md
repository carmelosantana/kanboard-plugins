# Steward — admin tools, branding, Markdown and project pages for Kanboard (v1.0)

- **Status:** approved by Carmelo 2026-11-18; spec security review round 1 resolved, round 2 pending
- **Plugin:** `Steward` (directory and PHP namespace `Kanboard\Plugin\Steward`), repo `carmelosantana/kanboard-steward`
- **Working folder:** `kanboard-plugins/Steward` (new)
- **Research:** `reports/Kanboard admin tools gaps.md` and `research_notes/Kanboard admin tools gaps/` (harness, untracked)
- **Neighbours:** ShadcnTheme (gives up its favicon/logo upload to Steward, see §5.6), Resend (email
  transport; Steward's test-email button works with any transport), ModMenu (directory listing)

## Why

Kanboard is in maintenance mode, and its maintainer has answered admin requests for a decade with "write a
plugin". Carmelo runs several instances and wants one place for the admin tools Kanboard lacks. The first
need is **user switching**: an admin seeing Kanboard exactly as another user does, to support them and to
check permissions, without knowing their password. No Kanboard plugin does this today. Around it sit
related gaps: **branding** (the only upload plugin, Customizer, is unmaintained and lets non-admins reach
its settings), **Markdown** (the add-ons turn core's HTML escaping off, and the Mermaid and highlighting
plugins are abandoned), and **project pages**. Open-source projects run on Kanboard have no way to show
the public what they are working on. Signed-in users have no project home page either.

## Goals, non-goals, anti-goals

- **Goals (v1):** admin user switching with a switch-back banner and an audit log; an admin audit log page;
  favicon, logo and site name; a safer, richer Markdown renderer (task lists, heading anchors, syntax
  highlighting, Mermaid); a configurable project Home page for signed-in users; an opt-in public project
  page and an opt-in public project directory; a send-test-email button; a site-wide announcement banner.
  Each feature can be switched off on its own.
- **Non-goals (v1, later releases):** session/remember-me management, cron/health panel, enforced 2FA,
  maintenance mode, bulk user actions, backup/restore, clickable task-list checkboxes, footnotes,
  per-project images or cover art, theming/colours (ShadcnTheme's job), an email transport (Resend's job).
- **Anti-goals:** a switch that gives the admin more than the target user has, or hides who acted; an
  admin's credentials or identity anywhere client-side; any path for a non-admin to change instance
  settings; turning Markdown HTML escaping off; SVG or any active content served from uploads; a public
  page that reveals comments, files, emails, member lists or names (unless the project opted in to names);
  breaking the API or JSON-RPC.

## Decisions

| # | Decision | Settled |
|---|---|---|
| 1 | **One plugin, per-feature switches** (approach A). A switched-off feature registers no routes, hooks or assets, and its actions refuse (404); its ACL entries stay registered (§1). | Carmelo; ACL part from spec security review |
| 2 | **Name:** Steward. | Carmelo |
| 3 | **v1 features:** user switching + audit log, branding, Markdown, project Home + public page + directory, test email, announcement banner. Session management, health panel, enforced 2FA, maintenance mode, bulk users come later. | Carmelo |
| 4 | **Branding moves from ShadcnTheme to Steward;** ShadcnTheme's upload page is retired in its next release. | Carmelo |
| 5 | **Project pages: both** a signed-in Home and an opt-in public page. | Carmelo |
| 6 | **Public activity is anonymous by default,** with a per-project toggle to show names. | Carmelo |
| 7 | **Switching to another app admin is refused** (hard block, not a confirmation). Switching to yourself, to an inactive user, and from inside a switch are refused too. | Builder's call, stated to Carmelo (prior art: django-hijack default; GitLab nested-switch CVE-2021-39896) |
| 8 | **Account-security pages are blocked while switched, and writes fail closed:** password, 2FA, API token, email, username, avatar and sessions are refused for reading and writing; any state-changing request goes through only if its controller is on Steward's allow-list of everyday work controllers (§2). Other plugins' writes are refused unless they opt in. | Builder's call, stated to Carmelo; tightened by spec security review |
| 9 | **The admin's identity lives only in `$_SESSION`.** No cookie, no hidden field, no URL carries it. | Brief |
| 10 | **Test email goes only to the signed-in admin's own address.** No recipient field, so the button can't be used to send mail to others. | Builder's call |
| 11 | **Public pages ride on core's per-project public access** (the token link) plus a second Steward toggle, so switching public board access on never publishes a landing page by accident. A project can additionally be *listed*, which gives it a clean URL and a directory entry; the directory itself is off until an admin enables it. | Builder's call, stated to Carmelo |
| 12 | **Target Kanboard 1.2.47 (dev stack) and test on 1.2.57** (current upstream, which hardened sessions, 2FA and Markdown safe mode). `kanboard_version: >=1.2.47`. | Builder's call |
| 13 | **Uploads are re-encoded raster only.** PNG/JPEG/WebP/GIF in, PNG out via GD (the avatar pattern). SVG and ICO are rejected. | Builder's call |
| 14 | **Task lists render read-only** in v1 (disabled checkboxes); toggling them by click is later. | Builder's call |
| 15 | **A switched session expires** after 60 idle minutes (setting). Expiry **logs the browser out**; it does not return to the admin, so an unattended screen never regains admin rights. | Builder's call |
| 16 | **Login events are audited too** (success and failure, username as typed, IP), because they are cheap and admins asked for failed-login logging (core #4670). | Builder's call |
| 17 | **Starting a switch revokes the admin's own remember-me login** in that browser (the `remember_me` row and cookie), so an expiry or revoke logout can't be silently undone by core's remember-me sign-in. The admin signs in again after switching back if they want remember-me. | Builder's call (builder check) |
| 18 | **Public URLs live under `/explore`:** directory `/explore`, listed project `/explore/:identifier`. Core already owns `/p/:project_id` and `/projects`, and core routes match first. | Builder's call (builder check) |
| 19 | **Test email is sent synchronously,** bypassing the queue, by calling the active transport directly and catching exceptions. Success reads "Handed to *transport* without error, check your inbox", because core's SMTP/mail transports log failures instead of throwing. | Builder's call (builder check) |
| 20 | **The announcement banner's "public pages" means Steward's public pages only,** not core's public board/task views (those render without layout hooks; overriding them isn't worth the upgrade risk). | Builder's call (builder check) |
| 21 | **Switch back is a logout plus a normal sign-in.** "Switch back" ends the switch, logs the browser out and sends the admin to the login page, returning to the user's profile afterwards. Nothing in a switched session can regain admin rights, so a stolen or unattended switched session is worth only the target's rights. | Carmelo (spec review round 1) |

## 1. Shell: settings, switches, audit log

- **Settings page:** `Settings → Steward` via `template:config:sidebar`, rendered with `layout->config()`.
  One section per feature with an on/off switch and that feature's settings. Admin only. Config keys are
  namespaced `steward_*`; the save action accepts **only an allow-list of known keys** (never pass the
  request through to `configModel->save()`), and calls `getValues()` once per request.
- **Feature registry:** `Plugin::initialize()` reads the switches once and wires only the enabled features'
  routes, hooks and assets. Kanboard dispatches any controller named in the query string
  (`?controller=…&plugin=Steward`) whether or not a route exists, so **every Steward action also checks its
  feature switch first and returns 404 when it is off**, and ACL entries are registered for every controller
  regardless of switches. Defaults on fresh install: Markdown **on**, everything else **off** (nothing changes for users until an
  admin opts in, except safer/richer Markdown).
- **Ops kill switch:** `define('STEWARD_DISABLE_SWITCHING', true)` in `config.php` disables switching
  regardless of the UI switch, for an admin who loses UI access.
- **ACL:** every Steward controller action is registered explicitly. Admin actions as `APP_ADMIN`; the
  switch-back action as `APP_USER` (checked further in §2); public pages as `APP_PUBLIC`. Unregistered plugin
  controllers default to `APP_USER`, so nothing is left to the default.
- **Project ACL:** the project access map defaults to `PROJECT_VIEWER`, and core checks project access only
  when `project_id` is in the query string. Every per-project Steward action (header, links, panels, Public
  page, Listed, Show names) is registered in the **project** access map as `PROJECT_MANAGER`, reads
  `project_id` from the route/query only (never the form body), reloads the project server-side, and
  re-checks the manager role inside the action.
- **Audit log table** `steward_audit` (Sqlite, Mysql, Postgres **and** Mssql schemas, `Schema/*.php`):
  `id, created_at, event, actor_user_id, subject_user_id, switch_id, ip, user_agent (truncated 255),
  details (JSON, ≤ 4 KB)`. Index on `created_at`, `event` and `switch_id`. All values are escaped on the
  audit page (usernames as typed and user agents are attacker-controlled).
- **Events written in v1:** `switch.start`, `switch.end`, `switch.expired`, `switch.revoked` (original admin
  lost admin/was disabled), `switch.refused` (with reason), `switch.action` (every state-changing request
  made while switched: method, controller, action, and `task_id`/`project_id` when present),
  `login.success`, `login.failure` (username as typed, truncated to 64 chars; failure rows are capped at
  10,000, oldest dropped first), `branding.changed`, `banner.changed`, `email.test`, `settings.changed`
  (keys only, never values of secret settings), `public.changed` (a project's public/listed/names toggles).
- **Extension point:** `$this->container['stewardAudit']->log($event, $actorId, $subjectId, $details)` for
  other suite plugins; events from it must be namespaced `plugin.<name>.<event>` (others rejected), so they
  can't pass as Steward's own. Hook `steward:audit` fires after each write.
- **Audit page:** `Settings → Steward → Audit log`, admin only: newest first, paginated, filter by event,
  user, date range. Read-only; no delete button. **Retention:** 180 days (setting), pruned at most once a
  day on write.

## 2. User switching

**Flow.**
1. On a user's profile (`template:user:sidebar:actions`) an admin sees **Switch to this user** when the
   target is eligible. Clicking opens a confirmation modal naming the user; confirming POSTs with CSRF.
2. Server side, in order: feature on and kill switch off → actor is app admin → actor is not already
   switched → actor's own session has passed 2FA if the actor uses 2FA → target exists, is active, is not
   an app admin, is not the actor. Any failure: one uniform message ("You can't switch to this user"),
   `switch.refused` audited with the real reason. Authorisation is checked **before** the target lookup.
3. Switch: write `switch.start` first, with a fresh random `switch_id` (32 bytes, `random_bytes`); if the
   audit write fails, refuse the switch. Clear every session key, call `userSession->initialize($target)`
   (which itself regenerates with `false`), **then** `session_regenerate_id(true)` so neither the old nor the
   intermediate id stays alive, mark post-authentication (2FA) as validated (initialize resets it), and only
   then store `steward_switch = {switch_id, admin_id, admin_username, started_at, last_seen}`. Revoke the
   admin's remember-me session for this browser (Decision 17). **Do not** dispatch
   `auth.success` (it writes a `last_logins` row and may mint a remember-me cookie for the target) and
   remove `hasRememberMe`. Redirect to the dashboard (old CSRF tokens die with the
   old session id).
4. **Banner** (`template:layout:top`, plus an item in `template:header:dropdown`): "You are viewing
   Kanboard as **Ana** — Switch back". Switch back is a POST with CSRF.
5. **Switch back = log out and sign in again** (Decision 21). Steward never restores admin rights by
   itself. The POST audits `switch.end`, destroys the session (as core's logout does), starts a fresh one
   holding only `redirectAfterLogin` = the target's profile path (a local path, which core's
   `redirectAfterLogin()` accepts), and redirects to the login page. The admin signs in with password and
   2FA as usual. If the admin was demoted or disabled meanwhile, the login itself shows it; the audit still
   records `switch.end`.

**Guards on every request while switched** (a `before` hook on the dispatcher or `app.bootstrap`; builder
to pick the earliest point that runs for HTML requests). `app.bootstrap` also fires for the API and queue
workers, so the guard does nothing at all when there is no PHP session or no `steward_switch` key:
- Re-check the original admin (exists, active, app admin) — else log out, `switch.revoked`.
- Idle timeout (§Decision 15) — else log out, `switch.expired`. `last_seen` moves only on non-AJAX
  requests, so the board's auto-refresh polling can't keep an unattended switch alive.
- **Always refused (read and write), 403 "not while switched":** `UserModificationController`,
  `UserCredentialController`, `UserApiAccessController`, `TwoFactorController`, `AvatarFileController`
  (upload/remove), `UserViewController` session/password-reset/integration/external pages, every
  `User*`/`Config*`/`Plugin*` admin controller, and Steward's own settings.
- **Writes fail closed:** a non-GET request (or a GET to a known state-changing action) is allowed only when
  its controller is on Steward's allow-list of everyday work controllers (board, task, subtask, comment,
  task file, link, tag, project settings the target could already change). Other plugins' controllers are
  refused for writes unless the plugin registers them with `stewardSwitching->allowWrites([...])`. The full
  allow-list is enumerated in the plan and tested against core's controller list.
- **Other plugins can tell:** `$this->container['stewardSwitching']->isSwitched()` and a `steward:switch`
  hook let plugins holding secrets (e.g. Vault) refuse to unlock while switched; Vault should adopt it.
- Core's `checkCurrentSession` wipes the session if the target's role or active flag changes, then runs
  pre-authentication. With the admin's remember-me revoked (Decision 17) that leaves the browser logged out,
  which is safe. Document it.
- **Reverse-proxy auth:** when `REVERSE_PROXY_AUTH` is on, core re-authenticates from the proxy header on
  every request, which would undo a switch. Switching is unavailable in that mode (button hidden, action
  refused with a clear message). Database, LDAP and OAuth logins are tested individually.

**Logout while switched** ends everything (no return to admin). A real login clears any leftover
`steward_switch`. The API and JSON-RPC never open a PHP session, so they are unaffected; a test proves it.

**Attribution:** actions taken while switched are recorded by core as the target user (that is what
"viewing as" means). The audit log brackets them with `switch.start`/`switch.end` and records each
state-changing request as `switch.action` under the same `switch_id`, so the admin's actions can be told
apart from the target's own, made at the same time in another browser. The banner and the modal both say so.

## 3. Branding

- **Settings:** site logo, favicon, site name (plain text, ≤ 60 chars, escaped everywhere). Upload
  PNG/JPEG/WebP/GIF ≤ 2 MB; checked by `getimagesize` **before** GD decodes it: reject zero/invalid
  dimensions, either side > 4096 px, or > 16 megapixels, and any format the installed GD can't read. The
  new asset replaces the old one only after a successful decode **and** PNG encode. Re-encoded to PNG through GD (`Thumbnail`): logo fit within
  512×128, favicon to 32×32 and 180×180 (apple-touch). SVG and ICO rejected with a clear message. Stored in
  ObjectStorage under `steward/branding/`; config keeps paths and a content hash.
- **Serving:** `BrandingController::image` (`APP_PUBLIC`, so the login page works), slot from an allow-list,
  `Content-Type: image/png`, `X-Content-Type-Options: nosniff`, CSP `default-src 'none'; sandbox`, ETag =
  content hash, long cache with the hash in the URL.
- **Applying:** favicon links via `template:layout:head` (after core's, which browsers then prefer; verify in
  Chrome and Firefox; fall back to a `layout` override only if a browser picks core's). Logo and site name
  via a `header/title` template override and on the login page via `template:auth:login-form:before`.
  `<title>` uses the site name instead of "Kanboard" where core falls back to it.
- **Fallback:** any missing slot falls back to core's default.
- **Helper for other plugins:** `$this->helper->stewardBranding->logoUrl() / faviconUrl() / siteName()`
  returning `''` when unset, so ShadcnTheme can use the logo in its login card.

## 4. Markdown

Steward re-registers the `text` helper in `initialize()` (`$this->helper->register('text', ...)`) with a
subclass whose `markdown()` uses a Steward parser extending core's `Kanboard\Core\Markdown`. This one swap
covers templates, notification emails and the editor preview.

- **Safety (always on):** keep `setMarkupEscaped(true)` and **turn on** `setSafeMode(true)` (matches
  upstream 1.2.57, so behaviour doesn't regress on either version). Safe mode alone still allows
  `data:image/png|gif|jpeg;base64` URLs, so Steward's parser also **overrides URL filtering with its own
  scheme allow-list: `http`, `https`, `mailto` and relative URLs only**, for links and images; anything else
  becomes plain text. This holds in emails, where CSP doesn't apply.
- **Public-mode `#N` links:** core's public-link mode links `#N` using the token of *whichever* project
  owns task N. Steward's parser links `#N` in public mode only when the task's project token equals the
  token of the page being served (from the request); otherwise `#N` stays plain text. On Steward's own
  public pages `#N` and `@user` are always plain text (§5.3).
- **Fixes:** a heading containing `#N` keeps its text (core's unanchored regex in `blockCustomHeader` drops
  it).
- **Additions:** task lists (`- [ ]` / `- [x]` → disabled checkboxes); heading ids with a slug and a hover
  anchor link (prefixed `md-` to avoid id clashes, de-duplicated per render); `language-xxx` classes on
  fenced code (core already does) for highlighting; ```` ```mermaid ```` blocks rendered as
  `<pre class="mermaid">` text that the browser turns into a diagram.
- **Client side (vanilla JS, no jQuery so it works on public pages):** vendored **highlight.js** (common
  languages build) and **Mermaid**, each pinned, with SHA-256 and licence recorded in
  `Assets/vendor/README.md`; run `/powerup:supply-chain` before adding them. Init code lives in an external
  `steward-markdown.js` (core CSP forbids inline script). Mermaid loads **lazily**, only when the page holds
  a mermaid block, with `securityLevel: 'strict'`. Both re-run on content that core inserts later (the
  preview tab, modals) via a MutationObserver scoped to `.markdown`.
- **Open risk:** whether current Mermaid runs under core's CSP (`default-src 'self'`, no `unsafe-eval`).
  The plan starts with a spike; if it needs CSP changes, Steward merges into the existing policy (never
  replaces it) or Mermaid drops out of v1.
- **Emails:** no JS, so code stays plain and Mermaid stays as its source text.

## 5. Project pages

### 5.1 Project header

Per-project settings (project managers and admins, under the project's settings sidebar): a **tagline**
(plain text, ≤ 160 chars) and up to **five links** (label + `http(s)` URL, validated). The header block
(name, tagline, links) shows on the project Home and the public page.

### 5.2 Home (signed in)

- A **Home** entry in the project view switcher (`template:project-header:view-switcher-before-project-overview`).
  It does not change which view opens by default.
- **Panels**, chosen and ordered per project by its managers (defaults: header, description, progress,
  recent activity):
  - **Header** (§5.1)
  - **Description** — the project description through the Markdown renderer
  - **Progress** — open/closed counts and a bar per column
  - **Board snapshot** — read-only columns with task titles, linking to the tasks
  - **Recent activity** — core's project activity stream (last 20), as core renders it for members
  - **Recently completed** — tasks closed in the last 14 days
  - **Files** — project attachments
- Everything is permission-checked as the viewer: Home shows nothing the viewer can't already see.

### 5.3 Public page (opt-in)

- Per project, managers can turn on **Public page** only while core's **public access** is on (it reuses
  the project token). URL: `/public/project/:token`.
- **Panels** from a smaller public-safe set: header, description, progress, board snapshot, recently
  completed, activity. Never files, comments, member lists, emails, due dates of hidden fields, or task
  descriptions.
- **Description** renders with Steward's public mode: `#N` and `@user` are **plain text**, never links.
- **No core token on a Steward public page.** No link, panel or snapshot points into core's
  `/public/task`, `/public/board`, feed or iCal URLs, and the token appears nowhere in a listed page's HTML.
  The board snapshot's task titles are not links.
- **The toggle says what core public access already exposes.** Beside the Public page switch: core's public
  access, needed underneath, also publishes the read-only board (with assignees), RSS feed, iCal and task
  pages (with comments and subtasks) to anyone who has the token link. Steward's page doesn't share the
  token, but whoever already has it sees all that.
- **Activity** is Steward's own minimal rendering of a safe subset of events (task created, moved, closed,
  reopened): "*Login fix* moved to *Done*". **Names hidden by default**; a per-project **Show names**
  toggle adds the actor's display name (never username or email).
- Public pages carry `X-Robots-Tag: noindex` unless the project is *listed* (§5.4).
- Turning off core public access clears the token and takes the page down; turning it back on issues a new
  token, so the old link stops working (core has no separate "regenerate"). A listed project's
  `/explore/:identifier` URL comes back with it.

### 5.4 Listed projects and directory (opt-in)

- A project with a public page can also be **Listed** (manager toggle): clean URL `/explore/:identifier`
  (the project identifier; listing requires one) and an entry in the directory.
- **Directory** at `/explore` (Decision 18), off by default, enabled by
  an admin in Steward settings: listed projects with name, tagline and link. Nothing else.
- Toggling public/listed/names is audited (`public.changed`).

### 5.5 Rendering on public pages

Public pages use core's public layout (no header, no jQuery/`KB`). Steward's public CSS/JS are plain and
load there; branding (favicon, logo, site name) applies.

### 5.6 ShadcnTheme hand-over

- **Import, not auto-migrate:** when ShadcnTheme's `shadcn_logo_path`/`shadcn_favicon_path` exist and
  Steward's slots are empty, Steward's branding settings show **Import from ShadcnTheme**. Import re-encodes
  through the same pipeline (an SVG logo can't be imported; the admin is told to upload a PNG).
- **ShadcnTheme 1.2.0** (separate release, after Steward 1.0.0): removes its upload page, its
  `serveAsset` route and its `header/title` override; its login card uses
  `stewardBranding->logoUrl()` when Steward is present, else the product name. `recommends` Steward ≥ 1.0.0.
  It leaves its old config keys and files in place (Steward's import reads them), with a changelog note.
- Only Steward overrides `header/title` after that, so the two plugins never fight over one template.
- **Until ShadcnTheme 1.2.0 ships,** both override `header/title` and Steward's wins (loads later). With
  Steward branding on, an empty logo slot and the import not yet run, Steward's override falls back to
  ShadcnTheme's logo, so nobody's logo disappears in between.

## 6. Test email

On core's **Settings → Email** page (`template:config:email`) and in Steward settings: **Send test email**
(POST, CSRF, admin only). Sends a short message synchronously through the active transport (Decision 19)
to the signed-in admin's own email. On failure the flash shows only a generic message and a short
correlation id; the exception detail goes to the server log, with anything resembling a credential (SMTP
user/password, API keys, `Authorization` values) redacted. Audits `email.test` with the transport name,
outcome and correlation id. One send per admin per 30 seconds. Disabled with an explanation if the admin's
profile has no email address.

## 7. Announcement banner

- Admin sets: message (≤ 500 chars; rendered by an inline-only renderer whose output allow-list is text,
  emphasis, strong, inline code and `http(s)` links; images, HTML, headings, lists, code blocks and Mermaid
  are escaped as text), level (info / warning),
  optional start and end time, where it shows (signed-in pages; optionally also the login page and
  Steward's public pages, Decision 20), and whether users may dismiss it.
- Renders via `template:layout:top` (and `template:auth:login-form:before` for the login page), sharing the
  banner partial with the switch banner. The switch banner always sits above it.
- Dismissal is per user per banner version (`user_metadata`), so editing the message shows it again.

## 8. Security review gates

Steward changes who is who, and it can publish project data, so it gets the same gates as Vault:
- **Spec:** `/security-review` and `/powerup:codex-security` on this document before the plan.
- **Branch:** both again on the built branch, then `/codex:adversarial-review` once done.
- The repo is public: until a release ships, commit messages and docs describe security fixes neutrally.

## 9. Testing

- TDD; the full suite via `testing/run-plugin-tests.sh Steward`, run to completion, checking the real test
  count (the config stops at the first failure).
- **Switching tests** cover, at least: non-admin refused; admin→admin refused; self, inactive, nested refused
  with one message; CSRF missing/invalid refused; GET refused; session id changes on start and end; old
  session id is dead afterwards (with `SESSION_HANDLER=db`); `last_logins` untouched and no remember-me
  cookie; 2FA target not prompted; switch back destroys the session, lands on the login page, and after the
  admin signs in returns to the target's profile (no path restores the admin without a login); admin demoted/disabled mid-switch →
  logout; idle expiry → logout; board polling doesn't extend idle; admin's remember-me revoked at switch
  start (no silent re-login after expiry); both the old and the intermediate session ids are dead;
  account-security pages 403 for read and write; writes to a non-allow-listed core or plugin controller
  refused; every state-changing request audited as `switch.action` with the `switch_id`; audit-write
  failure refuses the switch; reverse-proxy mode refuses switching; the guard is a no-op for JSON-RPC and
  the queue worker; logout ends everything; every path audited.
- **Feature-switch tests:** with each feature off, every one of its actions returns 404 when called by
  query string (`?controller=…&action=…&plugin=Steward`) as admin, user and anonymous.
- **Project ACL tests:** a project viewer and a member each get 403 on every per-project Steward action; a
  POST whose body `project_id` differs from the query's is refused.
- **Branding tests:** SVG, ICO, polyglot (PNG header + script), oversize file, oversize dimensions /
  pixel-count (decompression bomb), non-image rejected; failed encode keeps the old asset; served
  headers; fallback; non-admin can't reach settings or upload; settings save ignores unknown keys.
- **Markdown tests:** escaping on; `javascript:`, `vbscript:`, `data:` (including the base64 image forms)
  neutralised in links and images, with mixed case, entities, whitespace/control characters and
  protocol-relative variants; `#N` heading fix; task lists; heading ids unique; mermaid/code classes;
  public mode never emits another project's token; banner renderer drops images/HTML/blocks.
- **Project pages tests:** public page 404 unless both toggles on; disabling and re-enabling public access
  kills the old link; a listed page's HTML contains no token string; no files,
  comments, emails or names leak (names only with the toggle); listed URL and directory only when enabled;
  Home never shows what the viewer can't see.
- **Live:** on :8081 (admin/admin, curl cookie jar) and on a 1.2.57 stack, in a browser for banner, favicon,
  highlighting and Mermaid.

## Left to the builder

Exact hook for the per-request switch guard; panel UI (drag order vs
up/down buttons); which highlight.js language bundle; the exact list of controllers blocked while switched
(derive from core's user and API-token controllers); how to stand up the 1.2.57 test stack.
