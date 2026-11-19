# Steward — admin tools, branding, Markdown and project pages for Kanboard (v1.0)

- **Status:** approved by Carmelo 2026-11-18; spec security review pending
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
| 1 | **One plugin, per-feature switches** (approach A). A switched-off feature registers no routes, hooks, ACL entries or assets. | Carmelo |
| 2 | **Name:** Steward. | Carmelo |
| 3 | **v1 features:** user switching + audit log, branding, Markdown, project Home + public page + directory, test email, announcement banner. Session management, health panel, enforced 2FA, maintenance mode, bulk users come later. | Carmelo |
| 4 | **Branding moves from ShadcnTheme to Steward;** ShadcnTheme's upload page is retired in its next release. | Carmelo |
| 5 | **Project pages: both** a signed-in Home and an opt-in public page. | Carmelo |
| 6 | **Public activity is anonymous by default,** with a per-project toggle to show names. | Carmelo |
| 7 | **Switching to another app admin is refused** (hard block, not a confirmation). Switching to yourself, to an inactive user, and from inside a switch are refused too. | Builder's call, stated to Carmelo (prior art: django-hijack default; GitLab nested-switch CVE-2021-39896) |
| 8 | **Account-security changes are blocked while switched:** password, 2FA, API token, email, username, and all user-management/admin pages (the target is not an admin, so ACL already covers most). | Builder's call, stated to Carmelo |
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

## 1. Shell: settings, switches, audit log

- **Settings page:** `Settings → Steward` via `template:config:sidebar`, rendered with `layout->config()`.
  One section per feature with an on/off switch and that feature's settings. Admin only. Config keys are
  namespaced `steward_*`; the save action accepts **only an allow-list of known keys** (never pass the
  request through to `configModel->save()`), and calls `getValues()` once per request.
- **Feature registry:** `Plugin::initialize()` reads the switches once and wires only the enabled features.
  Defaults on fresh install: Markdown **on**, everything else **off** (nothing changes for users until an
  admin opts in, except safer/richer Markdown).
- **Ops kill switch:** `define('STEWARD_DISABLE_SWITCHING', true)` in `config.php` disables switching
  regardless of the UI switch, for an admin who loses UI access.
- **ACL:** every Steward controller action is registered explicitly. Admin actions as `APP_ADMIN`; the
  switch-back action as `APP_USER` (checked further in §2); public pages as `APP_PUBLIC`. Unregistered plugin
  controllers default to `APP_USER`, so nothing is left to the default.
- **Audit log table** `steward_audit` (Sqlite, Mysql, Postgres **and** Mssql schemas, `Schema/*.php`):
  `id, created_at, event, actor_user_id, subject_user_id, ip, user_agent (truncated 255), details (JSON,
  ≤ 4 KB)`. Index on `created_at` and on `event`.
- **Events written in v1:** `switch.start`, `switch.end`, `switch.expired`, `switch.revoked` (original admin
  lost admin/was disabled), `switch.refused` (with reason), `login.success`, `login.failure`,
  `branding.changed`, `banner.changed`, `email.test`, `settings.changed` (keys only, never values of secret
  settings), `public.changed` (a project's public/listed/names toggles).
- **Extension point:** `$this->container['stewardAudit']->log($event, $actorId, $subjectId, $details)` for
  other suite plugins; and hook `steward:audit` fired after each write.
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
3. Switch: `session_regenerate_id(true)` (core's `UserSession::initialize` uses `false`, which keeps the old
   session alive; Steward regenerates itself, with `true`), clear every session key except Steward's own,
   set the user via `userSession->initialize($target)`, mark post-authentication (2FA) as validated, store
   `steward_switch = {admin_id, admin_username, started_at, last_seen}`. Revoke the admin's remember-me
   session for this browser (Decision 17). **Do not** dispatch
   `auth.success` (it writes a `last_logins` row and may mint a remember-me cookie for the target) and
   remove `hasRememberMe`. Audit `switch.start`. Redirect to the dashboard (old CSRF tokens die with the
   old session id).
4. **Banner** (`template:layout:top`, plus an item in `template:header:dropdown`): "You are viewing
   Kanboard as **Ana** — Switch back". Switch back is a POST with CSRF.
5. **Switch back:** read `admin_id` from the session only. Re-load the admin from the DB; if it no longer
   exists, is inactive or is no longer app admin, log the session out entirely (`switch.revoked`).
   Otherwise `session_regenerate_id(true)`, clear session keys, `initialize($admin)`, mark 2FA validated,
   audit `switch.end`, redirect to the target's profile.

**Guards on every request while switched** (a `before` hook on the dispatcher or `app.bootstrap`; builder
to pick the earliest point that runs for HTML requests):
- Re-check the original admin (exists, active, app admin) — else log out, `switch.revoked`.
- Idle timeout (§Decision 15) — else log out, `switch.expired`.
- Block the controllers/actions of Decision 8 with a 403 page explaining "not while switched".
- Core's `checkCurrentSession` wipes the session if the target's role or active flag changes; that ends the
  switch by logging out, which is safe. Document it.

**Logout while switched** ends everything (no return to admin). A real login clears any leftover
`steward_switch`. The API and JSON-RPC never open a PHP session, so they are unaffected; a test proves it.

**Attribution:** actions taken while switched are recorded by core as the target user (that is what
"viewing as" means). The audit log brackets them with `switch.start`/`switch.end`, giving the time window.
The banner and the modal both say so.

## 3. Branding

- **Settings:** site logo, favicon, site name. Upload PNG/JPEG/WebP/GIF ≤ 2 MB; checked by
  `getimagesize`/GD decode, not extension. Re-encoded to PNG through GD (`Thumbnail`): logo fit within
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

- **Safety (always on):** keep `setMarkupEscaped(true)`, and **turn on** `setSafeMode(true)` (blocks
  `javascript:`/`data:` link and image URLs, including in emails where CSP doesn't apply). This matches
  upstream 1.2.57's safe mode, so behaviour doesn't regress on either version.
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
- **Description** renders with core's public-link mode (`markdown($text, true)`), so `#N` and `@user`
  never point into private pages.
- **Activity** is Steward's own minimal rendering of a safe subset of events (task created, moved, closed,
  reopened): "*Login fix* moved to *Done*". **Names hidden by default**; a per-project **Show names**
  toggle adds the actor's display name (never username or email).
- Public pages carry `X-Robots-Tag: noindex` unless the project is *listed* (§5.4).
- Turning off core public access, or regenerating the token, takes the page down / moves it, as core does
  for the public board.

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
to the signed-in admin's own email; shows the outcome or the caught error (trimmed, no secrets) as a flash; audits `email.test` with the
transport name and outcome. Disabled with an explanation if the admin's profile has no email address.

## 7. Announcement banner

- Admin sets: message (Markdown, inline subset, through the safe renderer), level (info / warning),
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
  cookie; 2FA target not prompted; switch back restores the admin; admin demoted/disabled mid-switch →
  logout; idle expiry → logout; admin's remember-me revoked at switch start (no silent re-login after expiry); blocked pages 403 while switched; logout ends everything; JSON-RPC
  unaffected; every path audited.
- **Branding tests:** SVG, ICO, polyglot (PNG header + script), oversize, non-image rejected; served
  headers; fallback; non-admin can't reach settings or upload; settings save ignores unknown keys.
- **Markdown tests:** escaping on; `javascript:` links neutralised; `#N` heading fix; task lists; heading
  ids unique; mermaid/code classes; public-link mode.
- **Project pages tests:** public page 404 unless both toggles on; token regenerate moves it; no files,
  comments, emails or names leak (names only with the toggle); listed URL and directory only when enabled;
  Home never shows what the viewer can't see.
- **Live:** on :8081 (admin/admin, curl cookie jar) and on a 1.2.57 stack, in a browser for banner, favicon,
  highlighting and Mermaid.

## Left to the builder

Exact hook for the per-request switch guard; panel UI (drag order vs
up/down buttons); which highlight.js language bundle; the exact list of controllers blocked while switched
(derive from core's user and API-token controllers); how to stand up the 1.2.57 test stack.
