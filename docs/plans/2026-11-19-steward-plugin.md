# Steward v1.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `Steward` v1.0.0, a buildless Kanboard plugin with per-feature switches for admin user switching (with audit log), branding, a safer/richer Markdown renderer, project Home/public pages with an `/explore` directory, a test-email button and an announcement banner; then ShadcnTheme 1.2.0 hands branding over.

**Architecture:** One plugin; `FeatureFlags` decides what `Plugin::initialize()` wires, and a `FeatureGate` on `app.bootstrap` 404s every action of a disabled feature before ACL runs. `AuditLog` (own table, 4 DB drivers) is shared. Switching is a `SwitchService` (start/end) plus a `SwitchGuard` on `app.bootstrap` that enforces the per-action `SwitchActionTable` and audits switched writes before they run. Markdown swaps the `text` helper for a subclass using `StewardMarkdown extends Kanboard\Core\Markdown`. Project pages read per-project settings from `project_has_metadata`.

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 plugin API (also tested on 1.2.57), PHPUnit via core's harness, plain CSS, vanilla JS (`node --test` for JS units), vendored highlight.js and Mermaid.

**Spec:** `docs/specs/2026-11-18-steward-plugin-design.md` — Decisions 1–21 and §1–§10 are binding. Read it before any task.

## Global Constraints

- Name `Steward`, namespace `Kanboard\Plugin\Steward`, repo `carmelosantana/kanboard-steward`, folder `kanboard-plugins/Steward` (own git repo, branch `main`; add `/Steward/` to the harness `.gitignore`).
- `plugin.json`: version `1.0.0` (= `getPluginVersion()` = tag `v1.0.0`), `kanboard_version` `>=1.2.47`, `php_version` `>=8.4`, MIT, author `Carmelo Santana`, homepage `https://github.com/carmelosantana/kanboard-steward`, `"requires": []`, `"recommends": [{ "plugin": "Resend", "min_version": "1.0.0", "reason": "a working email transport for the test-email button" }]`.
- Buildless. No inline JS (CSP). Vendored JS only after `/powerup:supply-chain` vetting, pinned with SHA-256 in `Assets/vendor/README.md`.
- Config keys prefixed `steward_`; settings save accepts only `SettingsSchema` keys; `getValues()` once per request.
- Every controller action registered in the application ACL (and project ACL for per-project actions, `PROJECT_MANAGER`); `project_id` from the query only.
- Fresh-install defaults: `markdown` on; `switching`, `branding`, `project_pages`, `public_pages`, `explore`, `test_email`, `banner` off.
- Never use the Kanboard MCP (production). Live checks on :8081 (admin/admin, curl cookie jar).
- Tests: `./testing/run-plugin-tests.sh Steward` from the harness root, run to completion, check the real test count. JS: `node --test Steward/Test/js/`.
- Commits: no AI attribution; neutral wording for security fixes until v1.0.0 ships; push explicitly (`git push origin main`).

## Review Focus

1. **A switched admin double-clicks or opens two tabs:** a second `start` while switched is refused with the uniform message and audited `switch.refused` (reason `nested`); a stale tab's switch-back POST after logout lands on the login page, not an error. (Tasks 7 and 9 tests.)
2. **Admin whose own account has no email / uses LDAP / uses 2FA:** test-email button disabled with the reason; switching works for LDAP-authenticated admins; an admin with 2FA enabled but not yet validated this session is refused. (Tasks 7, 8, 23 tests.)
3. **Project with no identifier asks to be listed:** the Listed toggle is disabled with "Set a project identifier first"; a POST forcing it is refused. (Task 19 test.)
4. **Description written in old Markdown habits:** `## Fix for #42`, `- [X]` (capital X), `* [ ]` bullets, nested task lists, `[link](JavaScript:alert(1))` and HTML entities render sensibly and safely. (Tasks 16–17 tests.)
5. **Uploading a phone photo as the logo:** a 4000×3000 JPEG (under the 16 MP cap) is accepted and resized; a 6000×4000 one gets "Image too large (max 4096 px a side, 16 megapixels)". (Task 12 tests.)

---

## Phase 0 — Spikes

### Task 1: Mermaid-under-CSP spike (throwaway)

**Files:** scratch only (`Steward/.spike/`, gitignored); writes `Steward/docs/mermaid-csp-spike.md`.

- [ ] **Step 1:** Vet `mermaid` (latest 11.x) and `@highlightjs/cdn-assets` (latest 11.x) with `/powerup:supply-chain`: publish age ≥ 14 days, licence, maintainers, no install scripts used (download the dist file only). Record versions and SHA-256.
- [ ] **Step 2:** On :8081, with core's CSP unchanged (`default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:`), load the vendored `mermaid.min.js` from a plugin asset and render a flowchart, sequence and gantt diagram from `<pre class="mermaid">` via an external init file with `securityLevel: 'strict'`. Check the browser console for CSP violations (Chrome and Firefox).
- [ ] **Step 3:** Write the result (works / needs which CSP directive) to `docs/mermaid-csp-spike.md`. If it needs `unsafe-eval` or worker/blob sources, Mermaid drops from v1: mark the Mermaid steps of Tasks 15, 17 and 18 skipped and tell Carmelo. If it needs only an additive directive, Task 18 merges it into the existing policy (read current rules, add, `setContentSecurityPolicy` with the merged set).

## Phase 1 — Shell

### Task 2: Scaffold

**Files:**
- Create: `Steward/plugin.json`, `Steward/Plugin.php`, `Steward/LICENSE` (copy Pulse's), `Steward/README.md` (stub), `Steward/CHANGELOG.md`, `Steward/.gitignore` (`.spike/`), `Steward/.github/workflows/release.yml` (verbatim copy of `Pulse/.github/workflows/release.yml`)
- Modify: harness `.gitignore` (add `/Steward/`)
- Test: `Steward/Test/PluginVersionTest.php`

**Interfaces:** Produces `Kanboard\Plugin\Steward\Plugin` with getters matching Global Constraints.

- [ ] **Step 1:** `mkdir Steward && git -C Steward init -b main`.
- [ ] **Step 2: Failing test** `PluginVersionTest::testVersionsAgree`: plugin.json `version === getPluginVersion() === '1.0.0'`; `requires === []`; each `recommends` entry has `plugin`, `min_version` (`/^\d+\.\d+\.\d+$/`), `reason`.
- [ ] **Step 3:** Run → FAIL. Write the files. Run → PASS.
- [ ] **Step 4:** Commit `chore: scaffold Steward plugin` (plugin repo) and `chore: ignore the Steward plugin checkout` (harness).

### Task 3: Settings schema, feature flags and settings page

**Files:**
- Create: `Model/SettingsSchema.php`, `Model/FeatureFlags.php`, `Controller/StewardSettingsController.php`, `Template/config/sidebar.php`, `Template/config/settings.php`
- Modify: `Plugin.php`
- Test: `Test/SettingsTest.php`

**Interfaces:**
- Produces:
  - `SettingsSchema::FEATURES = ['markdown','switching','branding','project_pages','public_pages','explore','test_email','banner']`
  - `SettingsSchema::defaults(): array<string,string>` — every `steward_*` key with its default (features per Global Constraints; `steward_switch_idle_minutes` `'60'`; `steward_audit_retention_days` `'180'`).
  - `SettingsSchema::filter(array $values): array` — returns only known keys, casts booleans to `'1'`/`'0'`, clamps numbers (idle 5–480, retention 7–3650).
  - `FeatureFlags::isEnabled(string $feature): bool` (container key `stewardFeatures`); `switching` is false whenever `STEWARD_DISABLE_SWITCHING` is defined true **or** `REVERSE_PROXY_AUTH` is true.
  - Routes: `StewardSettingsController::show|save` (`APP_ADMIN`).

- [ ] **Step 1: Failing tests:** `testDefaultsOnFreshInstall` (markdown on, others off); `testFilterDropsUnknownKeys` (`['steward_banner'=>'1','application_url'=>'x','api_token'=>'y']` → only `steward_banner`); `testKillSwitchConstant` and `testReverseProxyDisablesSwitching` (run in separate processes, `@runInSeparateProcess`); `testNonAdminGetsForbidden` on `show` and `save`; `testSaveWritesOnlySchemaKeys`. (The `settings.changed` audit assertion is added in Task 6, once the audit store exists.)
- [ ] **Step 2:** Run → FAIL. Implement; hook `template:config:sidebar`; render with `$this->helper->layout->config()`.
- [ ] **Step 3:** Run → PASS. Commit `feat: settings page and feature switches`.

### Task 4: Feature gate (disabled features 404 before ACL)

**Files:** Create `Model/FeatureGate.php`; Modify `Plugin.php`; Test `Test/FeatureGateTest.php`

**Interfaces:**
- Produces: `FeatureGate::MAP` — `array<string,string>` from `'Controller::action'` (Steward controllers only, `*` allowed for whole controller) to feature name; `FeatureGate::check(string $plugin, string $controller, string $action): bool` (true = allowed). Listener on `app.bootstrap` that, when the router resolves `plugin=Steward` to a disabled feature, sends a 404 and exits before ACL middleware. No-op when there is no router context (API, queue).
- Every controller added by later tasks must add its row to `MAP`; `testEveryControllerActionIsMapped` (below) keeps it honest as tasks add controllers.

- [ ] **Step 1: Failing tests:** `testControllerNamesAreUnique` (every class in `Steward/Controller` starts with `Steward` and no core `app/Controller` class or other installed plugin's controller has the same short name); for each feature off, `?controller=X&action=Y&plugin=Steward` returns 404 as anonymous, user and admin, with identical body; with it on, ACL applies as normal; `testEveryControllerActionIsMapped` (reflection over `Steward/Controller/*`).
- [ ] **Step 2:** Run → FAIL. Implement; ACL entries for every Steward controller are registered unconditionally in `initialize()`.
- [ ] **Step 3:** Run → PASS. Commit `feat: disabled features answer 404 before ACL`.

### Task 4b: Runtime ACL check

**Files:** Create `Model/AclCheck.php`; Modify `Plugin.php`; Test `Test/AclCheckTest.php`

**Interfaces:** `AclCheck::EXPECTED` — Steward's application and project ACL rules; `AclCheck::verify(): bool` compares them with `applicationAccessMap`/`projectAccessMap` on `app.bootstrap` (after all plugins loaded); on mismatch sets `stewardAclBroken`, logs the conflicting rule, `FeatureGate` then 403s every Steward action and `SwitchPolicy` refuses with reason `acl_conflict`.

- [ ] **Step 1: Failing tests:** clean install verifies; a fake plugin re-registering `StewardSettingsController` as `APP_PUBLIC` → every Steward action 403 and switching refused.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: refuse to run with tampered ACL rules`.

### Task 5: Audit log store

**Files:**
- Create: `Schema/Sqlite.php`, `Schema/Mysql.php`, `Schema/Postgres.php`, `Schema/Mssql.php` (`VERSION = 1`, table `steward_audit` per spec §1), `Model/AuditLog.php`
- Test: `Test/AuditLogTest.php`

**Interfaces:**
- Produces (container `stewardAudit`):
  - `AuditLog::log(string $event, ?int $actorId, ?int $subjectId, array $details = [], ?string $switchId = null): bool` — false on DB failure (never throws); details JSON-encoded and cut to 4096 bytes; `user_agent` cut to 255; ip via `request->getIpAddress()`; fires `steward:audit`. Rejects (returns false) events from outside Steward unless `str_starts_with($event, 'plugin.')` with shape `plugin.<name>.<event>`; Steward's own calls go through `logInternal()`.
  - `AuditLog::find(array $filters, int $limit, int $offset): array`, `count(array $filters): int` (filters: `event`, `user_id` matches actor or subject, `from`, `to`).
  - `AuditLog::prune(): void` — deletes rows older than retention; runs at most once a day (`steward_audit_last_prune`); caps `login.failure` rows at 10,000 (oldest first).
  - `AuditLog::newSwitchId(): string` — `bin2hex(random_bytes(32))`.

- [ ] **Step 1: Failing tests:** insert/find round-trip; details truncation; event `switch.start` rejected from `log()` but accepted from `logInternal()`; `plugin.vault.unlock` accepted; prune by age and once-a-day; failure cap at 10,000 (insert 10,005 with a fast bulk helper); `log()` returns false when the table is missing (drop it in the test).
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: audit log table and store`.

### Task 6: Audit page and login events

**Files:** Create `Controller/StewardAuditController.php`, `Template/audit/index.php`, `Subscriber/LoginSubscriber.php`; Modify `Plugin.php`, `FeatureGate::MAP`; Test `Test/AuditControllerTest.php`, `Test/LoginSubscriberTest.php`

**Interfaces:** `StewardAuditController::index` (`APP_ADMIN`; always available, not behind a feature). `LoginSubscriber` listens to `auth.success` (`login.success`) and `auth.failure` (`login.failure`, username from the event cut to 64 chars).

- [ ] **Step 1: Failing tests:** settings save audits `settings.changed` with key names only (never values); non-admin 403; filters by event/user/date; pagination 50/page; a row whose username is `<script>alert(1)</script>` and user agent `"><img src=x onerror=1>` renders escaped; no delete route exists; login success/failure write rows.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: audit log page and login events`.

## Phase 2 — User switching

### Task 7: Switch policy (who may switch to whom)

**Files:** Create `Model/SwitchPolicy.php`; Test `Test/SwitchPolicyTest.php`

**Interfaces:**
- Produces: `SwitchPolicy::canStart(array $actor, ?array $target, bool $alreadySwitched, bool $actorPostAuthValidated): ?string` — `null` = allowed, else a reason code: `disabled`, `not_admin`, `nested`, `2fa_pending`, `not_found`, `inactive`, `target_admin`, `self`. Order of checks exactly as spec §2 step 2 (authorisation before target lookup: `not_admin` is returned even when `$target` is null).
- `SwitchPolicy::UNIFORM_MESSAGE = "You can't switch to this user"`.

- [ ] **Step 1: Failing tests:** one test per reason code; `not_admin` beats `not_found`; app-manager actor is `not_admin`; `2fa_pending` only when the actor has `twofactor_activated`.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: switch eligibility policy`.

### Task 8: Switch service — start

**Files:** Create `Model/SwitchService.php`, `Controller/StewardSwitchController.php`; Modify `Plugin.php`, `FeatureGate::MAP`; Test `Test/SwitchStartTest.php`

**Interfaces:**
- Produces (container `stewardSwitching`):
  - `SwitchService::start(int $targetId): ?string` — returns null on success, else the reason code. Steps in this order (spec §2.3): policy → `logInternal('switch.start', …, $switchId)` (false → refuse with reason `audit_failed`) → revoke the admin's remember-me (`rememberMeSessionModel->remove()` for the cookie's session id, confirm deleted, then `rememberMeCookie->remove()`) → `session_regenerate_id(true)` (once) → `session_unset()` → `SwitchService::writeUser(array $target)` (copies `UserSession::initialize()` minus its `session_regenerate_id(false)`) → `userSession->setPostAuthenticationAsValidated()` → `session_set('steward_switch', ['switch_id','admin_id','admin_username','started_at','last_seen'])`; never dispatches `auth.success`; removes `hasRememberMe`. Remember-me revocation happens **before** the session changes: delete the row, re-query to confirm it's gone, else refuse with reason `rememberme_failed` (session untouched).
  - `SwitchService::isSwitched(): bool`, `current(): ?array` (the `steward_switch` value).
  - `StewardSwitchController::start` — POST only, `checkCSRFForm()`, `user_id` from the form; `APP_ADMIN`. Refusal: flash `UNIFORM_MESSAGE`, audit `switch.refused` with the reason, redirect to the target's profile (or user list). Success: redirect to dashboard.

- [ ] **Step 1: Failing tests:** GET → refused; missing/bad CSRF → refused; success sets `user.id` to target, `steward_switch.admin_id` to admin, 2FA validated; `last_logins` row count unchanged; no `remember_me` row for the target; the admin's `remember_me` row removed; `switch.start` row with a 64-hex `switch_id`; audit failure (drop table) → refused `audit_failed`, session unchanged; remember-me row that can't be deleted (mock the model to leave it) → refused `rememberme_failed`, session unchanged; an LDAP-authenticated admin (`is_ldap_user=1`) can switch; `writeUser()` produces the same `user` session array as core's `initialize()` for the same user (secrets stripped); with `SESSION_HANDLER=db`, the pre-switch session id is absent from the `sessions` table afterwards.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: start a user switch`.

### Task 9: Switch back (logout + sign-in) and expiry

**Files:** Modify `Model/SwitchService.php`, `Controller/StewardSwitchController.php`; Test `Test/SwitchEndTest.php`

**Interfaces:**
- `SwitchService::end(string $reason): void` — reasons `end` (user pressed Switch back), `expired`, `revoked`. Audits `switch.<reason>` (`switch.end` for `end`) with the switch id, calls `sessionManager->close()`, then `session_start()` and `session_set('redirectAfterLogin', <target profile path>)` only for `end`.
- `StewardSwitchController::back` — POST + CSRF, `APP_USER`, refused unless `isSwitched()`; calls `end('end')` and redirects to `AuthController::login`.
- Logout while switched: listener on `SessionManager::EVENT_DESTROY` audits `switch.end` (reason `logout`) once. A real login (`auth.success`) clears any leftover `steward_switch`.

- [ ] **Step 1: Failing tests:** back → session destroyed, login page, `redirectAfterLogin` equals the target's profile path and is relative; after a normal login as admin the redirect lands there; no code path sets `user` to the admin; back when not switched → 403; GET back → refused; stale back after logout → login page; core logout while switched audits once.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: switch back by logging out`.

### Task 10: Switch guard and the per-action table

**Files:** Create `Model/SwitchActionTable.php`, `Model/SwitchGuard.php`, `Template/switch/forbidden.php`; Modify `Plugin.php`; Test `Test/SwitchGuardTest.php`, `Test/SwitchActionTableTest.php`

**Interfaces:**
- `SwitchActionTable::CORE` — `array<string,'read'|'write'|'blocked'>` keyed `'Controller::action'` covering **every public action of every controller in `app/Controller` of 1.2.47**. Blocked: everything in spec §2 "Always refused" (incl. `OAuthController::*`, `UserModificationController::*`, `UserCredentialController::*`, `UserApiAccessController::*`, `TwoFactorController::*`, `AvatarFileController::upload|remove`, `UserViewController` sessions/password-reset/integrations/external/share/notifications pages), all `Config*`, `Plugin*`, `User*` admin controllers, `ProjectCreationController` is `write` (the target may create private projects), Steward's `StewardSettingsController`. GET-mutations (e.g. `TaskStatusController::close|open`, `TaskModificationController::assignToMe|start`, `SubtaskStatusController::change|timer`, `BoardAjaxController::collapse|expand`) are `write`.
- `SwitchActionTable::classify(string $plugin, string $controller, string $action): string` — core pairs from `CORE`; unknown core pairs → `blocked`; plugin pairs from registrations, else `blocked`.
- `SwitchService::allow(string $plugin, array $actions): void` — `['Controller::action' => 'read'|'write']`, keyed by plugin.
- `SwitchGuard` on `app.bootstrap`, **registered unconditionally** (not behind the `switching` flag), after `FeatureGate`: no-op unless a PHP session is active and `steward_switch` is set. Then, in order: switching disabled (flag off, kill switch or reverse-proxy auth) → `end('revoked')` with reason `disabled`; admin re-check (exists, active, `Role::APP_ADMIN`) else `end('revoked')`; idle check (`time() - last_seen > idle*60`) else `end('expired')`; classify; `blocked` → 403 template; `write` or any non-GET → `logInternal('switch.action', admin, target, {method, controller, action, task_id, project_id}, switchId)` and 503 if it returns false; update `last_seen` only when not `X-Requested-With: XMLHttpRequest`.
- Ships registrations for suite plugins' view pages (read): Pulse, Presence, Bigboard, TimeReport report view, Agents roster view — only if present (`class_exists`).

- [ ] **Step 1: Failing tests:** `testTableCoversEveryCoreAction` (reflect over `testing/kanboard-src/app/Controller/*.php` public methods excluding `Base`; every pair present); table-driven test over all `CORE` rows asserting allow/403 and an audit row for writes; unknown plugin action → 403 even on GET; plugin A's registration doesn't allow plugin B's `Controller::action` of the same name; demoted admin mid-switch → logged out, `switch.revoked`; idle 61 min → logged out `switch.expired`; AJAX requests don't move `last_seen`; audit insert failure on a write → 503 and the task is unchanged; guard is a no-op for a JSON-RPC call and a queue job (`JobHandler` path); a switched session survives no request after switching is turned off (UI switch and kill-switch constant both tested).
- [ ] **Step 2:** Run → FAIL. Build `CORE` by reading each core controller (record conditional mutations in comments). Implement. Run → PASS.
- [ ] **Step 3:** Commit `feat: guard every request while switched`.

### Task 11: Switching UI

**Files:** Create `Template/user/switch_button.php`, `Template/switch/confirm.php`, `Template/switch/banner.php`, `Template/header/switch_back.php`, `Assets/css/steward.css`; Modify `Plugin.php`; Test `Test/SwitchUiTest.php`

**Interfaces:** `template:user:sidebar:actions` → button (only for admins, only when `SwitchPolicy` would allow, hidden when switching is off or reverse-proxy auth is on); the button opens `StewardSwitchController::confirm` (GET, modal; `APP_ADMIN`) whose form POSTs to `start`. `template:layout:top` → banner "You are viewing Kanboard as **{name}** — Switch back" (a POST form), always above the announcement banner. `template:header:dropdown` → "Switch back" item (POST form). Banner partial `switch/banner.php` takes `level`, `message_html`, `form` so Task 26 reuses it.

- [ ] **Step 1: Failing tests:** button hidden for non-admin, for admin targets, for self, for inactive users, when feature off, with reverse-proxy auth; confirm modal names the user and says actions are recorded as them; banner renders only when switched and escapes the name; dropdown item present when switched.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: switch button, confirmation and banner`.

## Phase 3 — Branding

### Task 12: Image pipeline

**Files:** Create `Model/ImageProcessor.php`; Test `Test/ImageProcessorTest.php`, fixtures in `Test/fixtures/` (tiny PNG/JPEG/WebP/GIF, an SVG, an ICO, a PNG-header-plus-`<script>` polyglot, a 1×1 PNG whose header claims 50000×50000)

**Interfaces:**
- `ImageProcessor::process(string $tmpPath, int $sizeBytes, array $targets): array` — `$targets` like `['logo' => [512,128,'fit'], 'favicon32' => [32,32,'cover'], 'favicon180' => [180,180,'cover']]`; returns `['ok'=>bool, 'error'=>?string, 'blobs'=>array<string,string> /* PNG bytes */, 'hash'=>string]`. Checks in order: size ≤ 2 MB; `getimagesize` type in PNG/JPEG/WEBP/GIF (SVG, ICO, anything else → "Unsupported image type"); width/height > 0, ≤ 4096 each, ≤ 16,000,000 px ("Image too large (max 4096 px a side, 16 megapixels)"); GD decode; resize; `imagepng` encode. Never touches storage.

- [ ] **Step 1: Failing tests:** each fixture's outcome; output starts with the PNG signature and re-decodes; polyglot output contains no `<script`; 4000×3000 JPEG accepted (generate in-test with GD).
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: image pipeline for branding uploads`.

### Task 13: Branding settings, storage and serving

**Files:** Create `Controller/StewardBrandingController.php`, `Template/config/branding.php`, `Model/Branding.php`; Modify `SettingsSchema` (`steward_site_name`, `steward_logo_path`, `steward_logo_hash`, `steward_favicon32_path`, `steward_favicon180_path`, `steward_favicon_hash`), `FeatureGate::MAP`; Test `Test/BrandingControllerTest.php`

**Interfaces:**
- `Branding::save(string $slot, array $blobs, string $hash): void` writes to ObjectStorage `steward/branding/<slot>-<hash>.png`, swaps config, then deletes the old object; `Branding::remove(string $slot)`; `Branding::siteName(): string` (≤ 60 chars, trimmed).
- `StewardBrandingController::save` (`APP_ADMIN`, POST, CSRF) — upload logo/favicon, remove, site name; audits `branding.changed` (slot, action).
- `StewardBrandingController::image` (`APP_PUBLIC`) — `slot` in `['logo','favicon32','favicon180']`, else 404; headers `Content-Type: image/png`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, ETag = hash, `Cache-Control: public, max-age=31536000, immutable` when `h=<hash>` matches.

- [ ] **Step 1: Failing tests:** non-admin can't save; failed processing keeps the old asset and config; image serving headers; unknown slot 404; site name 61 chars rejected; `settings.changed`/`branding.changed` audited.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: branding settings and image serving`.

### Task 14: Apply branding (+ ShadcnTheme fallback and import)

**Files:** Create `Helper/StewardBrandingHelper.php`, `Template/layout/favicon.php`, `Template/header/title.php` (override of core `header/title`), `Template/auth/logo.php`; Modify `Plugin.php`, `StewardBrandingController` (`import`); Test `Test/BrandingApplyTest.php`

**Interfaces:**
- Helper `stewardBranding`: `logoUrl(): string`, `faviconUrl(int $size): string`, `siteName(): string` — `''` when unset. While the Steward logo slot is empty and `shadcn_logo_path` is set, `logoUrl()` returns ShadcnTheme's serve URL (`?controller=SettingsController&action=serveAsset&plugin=ShadcnTheme&slot=logo`) so no logo disappears (spec §5.6).
- `template:layout:head` → favicon `<link>`s (32 + apple-touch 180); `header/title` override shows logo or site name (falls back to core markup); `template:auth:login-form:before` → logo/site name; `<title>` fallback uses the site name.
- `BrandingController::import` (`APP_ADMIN`, POST, CSRF): reads `shadcn_logo_path`/`shadcn_favicon_path` from ObjectStorage, runs them through `ImageProcessor`, saves; SVG → flash "Your ShadcnTheme logo is an SVG; upload a PNG instead". Button shown only when those keys exist and Steward's slots are empty.

- [ ] **Step 1: Failing tests:** each slot set/unset renders the right markup; site name escaped; Shadcn fallback; import happy path (PNG) and SVG refusal; the override renders core's default when nothing is set.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: apply logo, favicon and site name`.

## Phase 4 — Markdown

### Task 15: Vendor highlight.js and Mermaid

**Files:** Create `Assets/vendor/highlight.min.js`, `Assets/vendor/highlight-github.css`, `Assets/vendor/mermaid.min.js` (skip if Task 1 dropped Mermaid), `Assets/vendor/README.md` (name, version, source URL, SHA-256, licence); Test `Test/VendorTest.php`

- [ ] **Step 1: Failing test** `testVendorHashesMatchReadme`: compute SHA-256 of each vendored file, compare with the README table.
- [ ] **Step 2:** Run → FAIL. Download the dist files vetted in Task 1 into a fresh empty directory, verify hashes, copy in. Run → PASS. Commit `chore: vendor highlight.js and Mermaid`.

### Task 16: Steward Markdown parser — safety

**Files:** Create `Core/StewardMarkdown.php`, `Helper/StewardTextHelper.php`; Modify `Plugin.php`; Test `Test/MarkdownSafetyTest.php`

**Interfaces:**
- `StewardMarkdown extends Kanboard\Core\Markdown` — constructor `(Container $c, bool $isPublicLink, ?string $requestToken = null, bool $plainRefs = false)`; sets `setMarkupEscaped(true)`, `setSafeMode(true)`, `setBreaksEnabled(true)`.
- URL allow-list: override `filterUnsafeUrlInAttribute` / `sanitiseElement` so `href`/`src` are kept only when scheme is `http`, `https`, `mailto`, or the URL is relative (no scheme, not `//`); else the element becomes its text.
- Public mode: `#N` links only if `taskFinderModel->getProjectToken(N) === $requestToken` (token from `request->getStringParam('token')` when the helper builds the parser); `$plainRefs` → `#N` and `@user` stay plain text.
- Fix `blockCustomHeader`: a heading containing `#N` keeps its text (linkify inside the heading instead of replacing it).
- `StewardTextHelper extends Kanboard\Helper\TextHelper` overriding `markdown($text, $isPublicLink = false)` and adding `markdownPublic(string $text): string` (plain refs). Registered with `$this->helper->register('text', StewardTextHelper::class)` when `markdown` is on.

- [ ] **Step 1: Failing tests:** raw HTML escaped; links/images neutralised for `javascript:`, `JaVaScRiPt:`, `java&#x73;cript:`, `\x01javascript:`, ` javascript:`, `vbscript:`, `data:text/html…`, `data:image/png;base64,…`, `//evil.example/x`; `http`, `https`, `mailto`, `/relative`, `?controller=…` kept; `## Notes on #42` keeps "Notes on"; public mode with request token A: `#N` in project A links with A's token, `#M` in project B is plain text; `markdownPublic` renders `#N` and `@admin` as text; notification email body (render a `task.create` email template) uses the safe parser.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: safer Markdown renderer`.

### Task 17: Markdown additions

**Files:** Modify `Core/StewardMarkdown.php`; Test `Test/MarkdownFeaturesTest.php`

**Interfaces:** task list items (`[ ]`, `[x]`, `[X]` after a `-`/`*`/`+`/ordered marker) → `<li class="task-list-item"><input type="checkbox" disabled[ checked]> …`; headings get `id="md-<slug>"` (lowercase ASCII, `-` joined, de-duplicated `-2`, `-3` per render) and a `<a class="md-anchor" href="#md-<slug>">#</a>`; fenced ```` ```mermaid ```` → `<pre class="mermaid">` with escaped source (unless Mermaid was dropped: then stays a normal code block).

- [ ] **Step 1: Failing tests:** each task-list form incl. nested and capital X; heading id slugs incl. unicode → stripped, duplicates; `<h2>` with `#42` gets an id from its text; mermaid block escaped (`<script>` inside stays text); code fence keeps `class="language-php"`.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: task lists, heading anchors and Mermaid blocks`.

### Task 18: Client-side highlighting and Mermaid

**Files:** Create `Assets/js/steward-markdown.js`, `Assets/css/markdown.css`; Modify `Plugin.php`; Test `Test/js/markdown.test.mjs`, `Test/AssetsTest.php`

**Interfaces:** `steward-markdown.js` (vanilla, no jQuery): exports `highlightWithin(root)` and `renderMermaidWithin(root)` for tests; on DOMContentLoaded runs both on `document`; a MutationObserver on `document.body` runs them on added nodes that are or contain `.markdown`. Mermaid script is injected lazily (a `<script src>` to the vendored file) only if a `pre.mermaid` exists, then `mermaid.initialize({startOnLoad:false, securityLevel:'strict'})` and `mermaid.run({nodes})`. Assets added via `template:layout:js`/`:css`, and on public layouts too.

- [ ] **Step 1: Failing tests:** JS: highlight runs once per block (marks `data-steward-hl`); mermaid loader not injected without a block; injected once with several. PHP: assets registered only when `markdown` on; no inline `<script>` emitted.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Live: preview tab and a modal both highlight. Commit `feat: syntax highlighting and Mermaid in the browser`.

## Phase 5 — Project pages

### Task 19: Per-project settings

**Files:** Create `Model/ProjectPageSettings.php`, `Controller/StewardProjectSettingsController.php`, `Template/project/settings.php`, `Template/project/sidebar.php`; Modify `Plugin.php` (project ACL), `FeatureGate::MAP`; Test `Test/ProjectPageSettingsTest.php`

**Interfaces:**
- `ProjectPageSettings` (stores in `project_has_metadata`, keys `steward_*`): `get(int $projectId): array{tagline:string, links:list<array{label:string,url:string}>, panels:list<string>, public_panels:list<string>, public:bool, listed:bool, show_names:bool}`; `save(int $projectId, array $values): array $errors`. Validation: tagline ≤ 160 plain text; ≤ 5 links, label ≤ 40, url `http(s)` only; panels ⊆ Home panel set (`header, description, progress, board, activity, completed, files`); public panels ⊆ (`header, description, progress, board, completed, activity`); `public` requires core `is_public`; `listed` requires `public` and a non-empty project `identifier`; audits `public.changed` when public/listed/show_names change.
- `StewardProjectSettingsController::show|save` — project ACL `PROJECT_MANAGER`, `project_id` via `getProject()` (query), form body `project_id` ignored/refused if different. Sidebar link via `template:project:sidebar`.

- [ ] **Step 1: Failing tests:** viewer and member get 403 on show and save; manager and admin succeed; body `project_id` ≠ query → refused; each validation; listed without identifier → error "Set a project identifier first"; public without core public access → error; audit rows.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: per-project page settings`.

### Task 20: Project Home

**Files:** Create `Controller/StewardProjectHomeController.php`, `Model/PanelData.php`, `Template/project_home/show.php`, `Template/project_home/panel_*.php`, `Template/project_header/home_link.php`; Modify `Plugin.php`, `FeatureGate::MAP`; Test `Test/ProjectHomeTest.php`

**Interfaces:**
- `PanelData::progress(int $projectId): array` (per column open count, closed count, % closed), `board(int $projectId, bool $public): array` (columns with task titles/ids; open tasks only), `completed(int $projectId, int $days = 14): array`, `activity(int $projectId, int $limit = 20): array` (core events, member view).
- `StewardProjectHomeController::show` — project ACL `PROJECT_VIEWER` (core default), renders chosen panels in order; Home link via `template:project-header:view-switcher-before-project-overview`.

- [ ] **Step 1: Failing tests:** non-member 403; panels in configured order; description through the Steward renderer; board snapshot of a project the viewer can see only; files panel lists attachments; defaults when unset.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: project Home page`.

### Task 21: Public project page

**Files:** Create `Controller/StewardPublicController.php`, `Model/PublicActivity.php`, `Template/public/project.php`, `Template/public/panel_*.php`, `Assets/css/public.css`; Modify `Plugin.php` (route `public/project/:token`, ACL `APP_PUBLIC`), `FeatureGate::MAP`; Test `Test/PublicProjectTest.php`

**Interfaces:**
- `StewardPublicController::show` — `token` → `projectModel->getByToken()`; 404 unless project `is_public`, Steward `public` on and feature `public_pages` on. Response headers `X-Robots-Tag: noindex` unless listed, and always `Referrer-Policy: no-referrer` (also on `/explore`). Uses `layout` with `no_layout`-style public chrome (Steward template), branding applies.
- `PublicActivity::recent(int $projectId, bool $showNames, int $limit = 20): list<array{text:string, at:int}>` — only `task.create`, `task.move.column`, `task.close`, `task.open`; text "*{title}* moved to *{column}*" etc.; actor display name (`name`, else nothing — never username/email) prefixed only when `$showNames`.
- Description via `text->markdownPublic()`; board snapshot titles are not links.

- [ ] **Step 1: Failing tests:** 404 for each missing toggle; response HTML contains no `token=` and no project token string; no comment text, file name, email, username in output (seed each with a sentinel string and assert absent); names absent by default, present with the toggle; `#N` and `@user` plain; disable-then-enable public access → old URL 404, new URL works; `Referrer-Policy: no-referrer` present.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: public project page`.

### Task 22: Listed projects and `/explore`

**Files:** Create `Controller/StewardExploreController.php`, `Template/public/explore.php`; Modify `Plugin.php` (routes `explore`, `explore/:identifier`, ACL `APP_PUBLIC`), `FeatureGate::MAP`; Test `Test/ExploreTest.php`

**Interfaces:** `StewardExploreController::index` — 404 unless feature `explore` on; lists projects with `public`+`listed`+core `is_public`, sorted by name: name, tagline, link to `/explore/<identifier>`. `StewardExploreController::show` — resolves identifier, same checks, renders `StewardPublicController`'s page (no `noindex`). Neither page contains the core token.

- [ ] **Step 1: Failing tests:** directory off → 404; unlisted project absent; listed shows name/tagline only; `/explore/<id>` contains no token; identifier lookup case-insensitive; core routes `/p/1` and `/projects` still reach core.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: public project directory`.

## Phase 6 — Admin extras

### Task 23: Test email

**Files:** Create `Controller/StewardTestEmailController.php`, `Template/config/test_email.php`; Modify `Plugin.php` (`template:config:email` + Steward settings), `FeatureGate::MAP`; Test `Test/TestEmailTest.php`

**Interfaces:** `StewardTestEmailController::send` (`APP_ADMIN`, POST, CSRF): recipient = current user's email only; refused with "Add an email address to your profile first" when empty; 30 s cooldown per admin (`user_metadata` `steward_test_email_at`) → "Please wait a few seconds"; sends synchronously via `emailClient->getTransport(MAIL_TRANSPORT or configured)->sendEmail(...)`; success flash "Handed to {transport} without error — check your inbox"; failure flash "The test email failed (ref {8-hex})" and logs the exception message with `/(password|passwd|secret|token|api[_-]?key|authorization)\s*[:=]\s*\S+/i` replaced by `$1=[redacted]`; audits `email.test` {transport, ok, ref}.

- [ ] **Step 1: Failing tests:** non-admin 403; no recipient field honoured (a posted `to` is ignored); empty email refused; cooldown; success with a fake transport; failure with a throwing fake transport whose message contains `password=hunter2` → flash has no exception text, log has `password=[redacted]`, audit row ok=false.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: send a test email`.

### Task 24: Announcement banner

**Files:** Create `Model/Banner.php`, `Core/InlineMarkdown.php`, `Controller/StewardBannerController.php`, `Template/config/banner.php`; Modify `Plugin.php`, `FeatureGate::MAP`; Test `Test/BannerTest.php`

**Interfaces:**
- `InlineMarkdown::render(string $text): string` — output allow-list: text, `<em>`, `<strong>`, `<code>`, `<a href>` with `http(s)` only (`rel="noopener noreferrer"`); everything else escaped as text.
- `Banner::current(): ?array{version:string, level:'info'|'warning', html:string, dismissible:bool, login:bool, public:bool}` — null outside start/end; `version` = hash of message+level+times.
- `StewardBannerController::save` (`APP_ADMIN`), `StewardBannerController::dismiss` (`APP_USER`, POST, CSRF; stores `steward_banner_dismissed` = version in `user_metadata`); audits `banner.changed`.
- Renders via `template:layout:top` below the switch banner, on the login page when `login`, on Steward public pages when `public`.

- [ ] **Step 1: Failing tests:** message > 500 chars rejected; images/HTML/headings/lists/code blocks/mermaid render as text; `javascript:` link text-only; schedule window; dismissal hides until the message changes; not dismissible → no button and dismiss refused; order below switch banner.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: announcement banner`.

## Phase 7 — Ship

### Task 25: Docs, full suite, 1.2.57 and live checks

**Files:** `README.md` (features, settings, security model incl. "switch back = sign in again", what core public access exposes, the `stewardSwitching->allow()` and `stewardAudit->log()` APIs), `CHANGELOG.md` (1.0.0), `docs/screenshots/` (optional); harness `testing/docker-compose.steward-overlay.yml` if a mount is needed.

- [ ] **Step 1:** `./testing/run-plugin-tests.sh Steward` to completion; record the count. `node --test Steward/Test/js/`.
- [ ] **Step 2:** Run the suite against a 1.2.57 checkout (`KB_SRC=<1.2.57 checkout> ./testing/run-plugin-tests.sh Steward`); fix or document differences (expect the Markdown safe-mode overlap and session hardening).
- [ ] **Step 3:** Live on :8081 with a curl cookie jar as admin and as a seeded user: switch → banner → blocked page 403 → task move audited → switch back → login → lands on profile; branding upload and favicon in Chrome/Firefox; Markdown preview with code + Mermaid; public page and `/explore` as anonymous (grep the HTML for the token); test email via Resend (or a mocked endpoint); banner. Screenshots to the shared notes folder.
- [ ] **Step 4:** Commit `docs: README and changelog for 1.0.0`.

### Task 26: Branch security gates

- [ ] **Step 1:** `/security-review` on the Steward repo's full history; fix confirmed findings (TDD), neutral commit messages.
- [ ] **Step 2:** `/powerup:codex-security` (Sol 5.6 + Daybreak Blue) on the built plugin; fix confirmed findings.
- [ ] **Step 3:** `/codex:adversarial-review`; fix confirmed findings; re-run the full suite to completion.
- [ ] **Step 4:** Report to Carmelo and ask for the release OK (repo creation, `v1.0.0` tag, ModMenu directory entry). Do not tag or list before he says yes.

### Task 27: ShadcnTheme 1.2.0 hand-over (separate repo, after Steward 1.0.0 is released)

**Files (ShadcnTheme repo):** Modify `Plugin.php` (drop `serveAsset` route, settings sidebar, `header/title` override; login card uses `$this->helper->stewardBranding->logoUrl()` when the helper exists), delete `Controller/SettingsController.php` upload code and its templates, `plugin.json` + `getPluginVersion()` → `1.2.0`, `recommends` Steward `1.0.0`, `CHANGELOG.md` ("Logo and favicon moved to Steward; your files stay put — use Steward's Import"); Test `Test/*`.

- [ ] **Step 1: Failing tests:** no `serveAsset` route; no `header/title` override; login card shows Steward's logo when present, product name otherwise; version 1.2.0 everywhere.
- [ ] **Step 2:** Run → FAIL. Implement. Run ShadcnTheme's full suite → PASS. Commit; ask Carmelo before tagging `v1.2.0` and bumping the directory entry.
