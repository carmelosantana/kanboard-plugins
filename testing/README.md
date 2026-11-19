# Kanboard Plugin Suite — Docker Test Harness

One Docker stack (Kanboard v1.2.47) that mounts the suite's plugins for local development, plus
the host-side PHPUnit runner that tests them.

## Prerequisites

- Docker + Docker Compose (Linux)
- `python3` on the host (used by `seed.sh` / `snapshot.sh` for safe SQL quoting and JSON parsing)
- Port **8081** free on the host

> **Note:** The `kanboard/kanboard` Alpine image does **not** ship the `sqlite3` CLI.
> The scripts talk to the DB through PHP PDO (`php -r "new PDO('sqlite:...')"`) executed inside the container.

---

## Quick start

All commands run from the **repo root** (`kanboard-plugins/`).

### 1. Fix host permissions (one-time)

The container web user (`nginx`) needs read/execute access to the bind-mounted plugin dirs:

```bash
for d in */plugin.json; do chmod -R o+rX "$(dirname "$d")"; done
```

### 2. Bring the stack up

```bash
docker compose -f testing/docker-compose.dev.yml up -d
```

Kanboard is now at **http://localhost:8081** (login `admin` / `admin`).

The image is pinned to `kanboard/kanboard:v1.2.47`, the release the suite targets. A data volume
first created by a newer image (the harness used `:latest`, v1.2.52, until 2026-11-17) carries a newer
schema; if Kanboard errors after the switch, start fresh with `down -v`.

**Chicane** and **Bigboard** are mounted by their own overlays (kept out of `docker-compose.dev.yml`). Bring the
stack up with it like this:

```bash
docker compose -f testing/docker-compose.dev.yml -f testing/docker-compose.jsonrpc-overlay.yml -f testing/docker-compose.chicane-overlay.yml up -d
```

The old `ChicanePrototype/tools/compose-overlay.yml` is no longer part of the harness: leave it off the command.

Add `-f testing/docker-compose.bigboard-overlay.yml` for Bigboard.

`docker-compose.dev.yml` bind-mounts every other plugin checkout at `/var/www/app/plugins/`. When you
add a plugin checkout, add its mount there too (the test runner needs no edit).

Verify mounts:

```bash
docker compose -f testing/docker-compose.dev.yml exec kanboard ls /var/www/app/plugins/
```

### 3. Seed test data

The seed script creates 5 projects, each with 3 tasks, 2 subtasks per task, 2 comments per task,
a per-task file, a shared file on tasks 1–2, and a project-level file.

**First run** — bootstraps and saves the API token to `testing/.env` (gitignored):

```bash
./testing/seed.sh
```

The script prints the created project IDs at the end. Note them for snapshot.

**Re-run** — removes previous `[SEED]`-prefixed projects and recreates them (idempotent):

```bash
./testing/seed.sh
```

#### Providing the API token manually

Copy the API token from **Settings → API** in the UI, then:

```bash
echo "API_TOKEN=<your-token>" > testing/.env
./testing/seed.sh
```

### 4. Snapshot (before / after a plugin action)

```bash
./testing/snapshot.sh <project_id> [project_id ...]
# Example:
./testing/snapshot.sh 2 3 4 5 6
```

Prints:
- Per-table row counts (projects, tasks, subtasks, comments, files, categories, …)
- Unique file paths recorded in the DB
- On-disk `data/files/` listing (one copy per task directory — Kanboard does **not** dedup files; the same content uploaded to two tasks produces two separate on-disk copies, one in each task's directory)
- Installed plugin directories

### 5. Import a production board (read-only)

Copies a production project into the harness as real fixture data (used by Chicane):

```bash
bash testing/import-board.sh <prod_project_id> [window_days=30]
# Example — Beige Bullet:
bash testing/import-board.sh 155 30
# imported project_id=25 tasks=703 subtasks=294 comments=2152 blocked=136
```

- **Production is only read**, through `~/MCP/kanboard-mcp/bin/kb-lib.sh` `req` (`get*` methods only;
  anything else is refused). Local writes use `API_TOKEN` from `testing/.env`.
- Imports the open tasks plus tasks closed in the last `window_days`, with columns, swimlanes,
  in-column order, subtasks (with status), comments (by their author), tags, `is blocked by` links
  between imported tasks, closed state, and reopen events found in `getProjectActivity` (last 50 events).
- Each production user becomes a local `imp.<username>` (display name kept, random password never printed).
- Creates `<prod name> (import)`, removing a previous project of exactly that name first, so re-runs are safe.
- Production timestamps (task creation/modification/moved/completed, comment creation/modification) are
  restored afterwards with one PDO transaction inside `kb-suite` (override with `KB_CONTAINER`).
- Not copied: files, time tracking.

### 6. Tear down

Keep data volume:

```bash
docker compose -f testing/docker-compose.dev.yml down
```

Destroy data volume too (fresh start):

```bash
docker compose -f testing/docker-compose.dev.yml down -v
```

---

## How the API token works

`seed.sh` looks for `API_TOKEN` in:
1. The shell environment (`export API_TOKEN=…`)
2. `testing/.env` (auto-created on first run)

If neither is set, `seed.sh` generates a random token, writes it into the SQLite DB via
`docker compose exec … sqlite3 …`, and saves it to `testing/.env`.

The token is the password for the built-in `jsonrpc` user:

```bash
curl -u "jsonrpc:$API_TOKEN" http://localhost:8081/jsonrpc.php \
  -d '{"jsonrpc":"2.0","method":"getVersion","id":1,"params":{}}'
```

`testing/.env` is gitignored — never commit it.

---

## Compose name & container

| Key            | Value           |
|----------------|-----------------|
| Compose name   | `kanboard-suite` |
| Container name | `kb-suite`      |
| Port           | `8081`          |
| Image          | `kanboard/kanboard:v1.2.47` |
| Data volume    | `kanboard-suite_kb-suite-data` |

---

## Git overlay (git hosting P1)

`docker-compose.git-overlay.yml` adds Forgejo 15 LTS (Postgres) behind Traefik, whose ForwardAuth
asks the `kanboard-git-broker` (sibling repo `../kanboard-git-broker`) about every repo request.
Only Traefik publishes a port: **http://git.localhost:8090** (bound to 127.0.0.1).

`kb-suite` is shared, so never `up` the git overlay with a partial file set: compose would recreate
Kanboard without the jsonrpc, Chicane, Bigboard and Pulse overlays. `testing/git/compose.sh` runs
`docker compose -p kanboard-suite` with all six files; name the git services and pass `--no-deps`:

```bash
bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
bash testing/git/bootstrap.sh            # Forgejo admin + broker token -> testing/git/.env, HMAC secrets -> testing/git/hmac.env,
                                         # Authentik (started here) + its secrets -> testing/git/authentik.env, OAuth2 plugin -> testing/git/vendor/
bash testing/git/compose.sh up -d --no-deps kanboard   # once, after hmac.env or vendor/OAuth2 first appears (recreates kb-suite)
bash testing/git/scenario.sh preflight   # expect "preflight: PASS (16/16)"
bash testing/git/scenario.sh identity    # expect "identity: PASS (24/24)"
```

`scenario.sh` runs the spec's numbered steps from one table (`bash testing/git/scenario.sh
list`; `all` runs each once). Besides `preflight`, the live steps act as agent
`githost-e2e-agent` (user 20) on project 28 and repo `kb-ghe2e/app`, with git's
`credential.helper` set to the broker's `git-credential-kanboard`, built on the host with
`cargo build` from `../kanboard-git-broker` (`BROKER_DIR`), or `KB_HELPER` for a prebuilt one:

| Step | Checks |
| --- | --- |
| `1` | the agent claims (or creates) its open task "scenario core path"; the helper's `get` prints `username`, `password`, `password_expiry_utc` |
| `2` | clone through Traefik, branch `agent/<login>/scenario-<ts>`, commit, push accepted |
| `3` | a low-risk PR: CI green on the dev runner, `kanboard/risk-gate` success from the broker; a2's approval (not in `git-reviewers`) does not count (merging now is 405); r1 schedules auto-merge, then approves, through Traefik (in that order: Forgejo checks a scheduled auto-merge only on a later status or review); merged on green |
| `4` | a change to `.forgejo/workflows/ci.yml`: the gate stays `pending` with CI green and r1's approval, an agent's forged `kanboard/risk-gate` post is 403 `kanboard_deny`; a `humans-high-risk` member's approval turns it `success` and the PR merges |
| `5a` | pushes to another agent's prefix and to `main` are refused by branch protection while the audit shows authz allowed them; an agent status post is 403 |
| `5c` | a force-push to `main` is refused by branch protection ("protected from force push") while authz allowed it; `main` does not move |
| `6` | a PR adding `ci/red` merges green and turns `main` red; the broker opens `revert/<sha7>` (auto-revert label, low risk); r1 approves; it merges and `main` is green; one revert PR per sha |
| `7` | `removeProjectUser`: the next fetch is denied; re-added (always, also on exit), a push to the own prefix works again; `closeTask`: the token is dead (`token_revoked`/`task_closed`) |
| `8` | `docker pause kb-suite`: fetch denied (`kanboard_unreachable`), always unpaused by a trap; then allowed |
| `9` | the broker recreated with `BROKER_FAULT_SYNC_ONCE=1` (cargo feature `fault-injection`, which only this overlay builds): removing a2 fails its nudge sync (500), Forgejo keeps a2 in `members`, and the reconcile loop repairs it within one 30 s tick; a2 re-added by a normal nudge, then the broker recreated without the flag (also on exit); all five teams compared with `githost/state` before, during (drift), after the repair and after re-adding. The two recreates cost the shared stack ~5 s of ForwardAuth each |
| `identity` | see [Identity](#identity-authentik) below: Kanboard and Forgejo logins through Authentik meet on the sub; no password web login |

They read `KANBOARD_USERNAME`, `KANBOARD_API_KEY` and `AGENT_API_TOKEN` (or
`AGENT_API_TOKEN_FILE`) from the environment or from the gitignored `testing/git/scenario.env`,
print `PASS`/`FAIL` with the broker's audit lines, and can be re-run: the core task is reused
and branch names are unique.

Load runs and measurements (Task 17; results in `testing/git/metrics.md`) use
`testing/git/load.sh`, which reuses `scenario.sh`'s helpers. It works with agents `load-a1..load-aN` on the :8081 dev
stack, never the Kanboard MCP tools:

```bash
LOAD_ROUNDS=2 bash testing/git/load.sh run 50 traefik traefik-net direct   # credential -> clone -> commit -> push, at once
bash testing/git/load.sh revlag                                            # git and web-UI revocation lag
bash testing/git/load.sh prune '^agent/[^/]+/load-'                        # delete leftover branches (broker paused, rules restored)
bash testing/git/load.sh teardown 50                                       # load agents out of project 28
```

### Merge gate and the dev CI runner (steps 3, 4, 5c, 6)

The broker's merge gate (README of `kanboard-git-broker`, "Merge gate") needs `BROKER_WEBHOOK_SECRET`
in `testing/git/.env` (bootstrap writes it) and the dev runner:

```bash
bash testing/git/bootstrap.sh                                         # also registers runner dev-plain -> testing/git/runner.env
bash testing/git/compose.sh --profile ci-dev up -d --no-deps ci-runner
bash testing/git/scenario.sh 3 4 5c 6
```

- **Runner** `kb-git-ci-runner` (profile `ci-dev`): `forgejo-runner` 13.2.1 (pinned by digest) in
  `one-job` mode with label `dev-plain`, host mode: jobs run as the runner user inside its own
  read-only container, with no Docker daemon and no host socket. Its only network is
  `kb-git-ci` (internal, no route out), shared with Traefik alone, so it and its jobs reach Forgejo
  only as `http://git.localhost:8090` through ForwardAuth, never `forgejo:3000` (step 3 checks it
  runs). Not an isolation option: Task 16 replaces it. A host-mode job can read the runner's own
  credential (same user); acceptable for a dev-only runner, not beyond.
- **CI:** `testing/git/fixtures/ci.yml` (workflow `ci`, job `test`). Forgejo reports it as
  `ci / test (pull_request)` on a PR, the required check on `main`, and `ci / test (push)` on `main`.
  It fails only on a push to `main` whose head commit adds or modifies `ci/red`, read from the event
  payload: a job cannot clone, because its Actions token is not a principal ForwardAuth allows.
  `main` gets the file through the high-risk flow (human approval) the first time a step needs CI.
- **Actors:** `githost-e2e-r1` (in group `git-reviewers`) and `githost-e2e-a2` (not) are created or
  reused with PDO as agents of `KANBOARD_USERNAME` and project members, each with a fresh random API
  token per run. The human is the identity step's `idtest-human`, made a project manager (so in
  `humans-high-risk`); its Forgejo token is minted with the Forgejo CLI once and kept in the
  gitignored `testing/git/scenario-human.token`.
- **Webhooks:** Forgejo calls `http://broker:8080/webhooks/forgejo` on the compose network
  (`[webhook] ALLOWED_HOST_LIST = broker` in `app.ini.tmpl`); Traefik has no route to it.

The overlay also wires Kanboard to the broker. It mounts `../GitHost` into `kb-suite`, and
gives `kb-suite` only `testing/git/hmac.env` (`KANBOARD_TO_BROKER_SECRET` and
`BROKER_TO_KANBOARD_SECRET`, one per direction; each side holds both, as HMAC needs) plus
`GITHOST_BROKER_URL=http://broker:8080`. The broker gets `testing/git/.env` (the Forgejo admin
token), `hmac.env` and `KANBOARD_URL=http://kb-suite`. Kanboard's php-fpm passes only
whitelisted variables to PHP, so the overlay replaces `/etc/php84/php-fpm.d/env.conf` with
`testing/git/php-fpm-env.conf`: the image's file plus the two GitHost lines. Re-copy it when
the Kanboard image changes. Adding these to `kb-suite` recreates it, which keeps its data
(volume `kanboard-suite_kb-suite-data`).

Membership reaches Forgejo two ways. The broker pulls `githost/state` every 30 s
(`BROKER_RECONCILE_SECS`). After each successful membership JSON-RPC call (`addProjectUser`,
`removeProjectUser`, `changeProjectUserRole`, the three `*ProjectGroup*` methods,
`addGroupMember` and `removeGroupMember`), GitHost also sends the broker an empty
`POST /projects/{id}/sync` per affected project. The broker's SQLite store and JSONL audit log
live in the volume `kanboard-suite_kb-git-broker-data` (`/var/lib/broker`). To read the audit
log, run `docker run --rm -v kanboard-suite_kb-git-broker-data:/d:ro --entrypoint cat kanboard/kanboard:v1.2.47 /d/audit/<YYYY-MM-DD>.jsonl`.

`compose.sh` expands to:
`docker compose -p kanboard-suite -f testing/docker-compose.dev.yml -f testing/docker-compose.jsonrpc-overlay.yml -f testing/docker-compose.chicane-overlay.yml -f testing/docker-compose.bigboard-overlay.yml -f testing/docker-compose.git-overlay.yml -f testing/docker-compose.pulse-overlay.yml`.

| Container | Role |
|-----------|------|
| `kb-git-traefik` | `127.0.0.1:8090`; routes in `testing/git/traefik/dynamic.yml` (file provider, watched) |
| `kb-git-forgejo` | Forgejo, rootless image, no published port; config from `testing/git/forgejo/app.ini.tmpl` on first start |
| `kb-git-db` | Postgres for Forgejo |
| `kb-git-broker` | ForwardAuth target `http://broker:8080/authz`; reads `testing/git/.env` and `testing/git/hmac.env`; pulls `http://kb-suite` every 30 s; receives Forgejo webhooks |
| `kb-git-ci-runner` | profile `ci-dev`: dev-only Forgejo Actions runner `dev-plain` (host mode, one job per container start) on the internal `kb-git-ci` network with Traefik only |
| `kb-auth-server`, `kb-auth-worker` | Authentik 2026.8.5 (no Redis since 2025.10), no published port; `http://auth.kb.test:8090` through Traefik; reads `testing/git/authentik.env`; blueprints from `testing/git/authentik/blueprints` |
| `kb-auth-db` | Postgres for Authentik (same pinned image as `kb-git-db`) |

- **Hostname:** Traefik listens on `:8090` inside the network too and has the network alias
  `git.localhost`, so `ROOT_URL=http://git.localhost:8090/` is one URL for host and containers. DNS
  inside containers resolves the alias, but curl/libcurl (so `git` and PHP curl) pin `*.localhost` to
  loopback; from a container use `--resolve git.localhost:8090:<traefik ip>` (curl) or
  `-c http.curloptResolve=git.localhost:8090:<traefik ip>` (git).
- **Forgejo config:** `app.ini.tmpl` is rendered only when the volume has no `app.ini`. After editing
  it, delete `/var/lib/gitea/custom/conf/app.ini` in the container (or the volume) and recreate `forgejo`.
- **Stop or remove** the git stack only: `bash testing/git/compose.sh stop forgejo-db forgejo broker traefik authentik-db authentik-server authentik-worker`
  (`rm -s -f` to remove; the volumes `kanboard-suite_kb-git-*` and `kanboard-suite_kb-auth-db-data` keep the data).
- **Broker integration tests** run on the compose network against `http://forgejo:3000` (Forgejo has
  no host port), with the admin token from `testing/git/.env`. With the git stack up:

  ```bash
  bash testing/git/compose.sh --profile it run --rm broker-it   # cargo test --test forgejo_it -- --ignored
  ```

  `broker-it` (profile `it`, never started by `up`) uses the broker Dockerfile's pinned Rust image,
  mounts `../kanboard-git-broker` read-only and caches the cargo registry and target dir in the
  volumes `kanboard-suite_kb-git-broker-it-*`. The tests create throwaway Forgejo users and a
  `kb-it…` org and delete them when they finish, pass or fail.

### Identity (Authentik)

Humans sign in to Kanboard and Forgejo through Authentik (OIDC). The join key is the OIDC `sub`:
Kanboard's OAuth2 plugin stores it in `users.oauth2_user_id`, and the broker pre-provisions each
human's Forgejo user on the `authentik` auth source with `login_name = sub`, so Forgejo's OIDC
login lands in that user. Agents never touch Authentik.

- **Hostname.** Authentik is `http://auth.kb.test:8090`, a second Traefik network alias. It is
  not `*.localhost` because libcurl pins those to loopback even inside containers, and Kanboard's
  OAuth2 back-channel is PHP curl in `kb-suite`. One issuer everywhere:
  `http://auth.kb.test:8090/application/o/<kanboard|forgejo>/`. Forgejo stays on
  `git.localhost`: its OIDC redirect URI is only ever followed by the browser, so it needs no
  second name. From the host, curl uses `--resolve auth.kb.test:8090:127.0.0.1`; for a browser,
  add one line to `/etc/hosts` (a manual step, nothing here edits it):

  ```
  127.0.0.1 auth.kb.test
  ```

- **Authentik** is configured only by `testing/git/authentik/blueprints/{kanboard,forgejo}.yaml`
  (confidential clients `kanboard` and `forgejo`, strict redirect URIs
  `http://localhost:8081/oauth/callback` and `http://git.localhost:8090/user/oauth2/authentik/callback`,
  `grant_types: [authorization_code]`, implicit consent, `sub_mode: hashed_user_id`, RS256 with the
  self-signed key). `bootstrap.sh` writes `testing/git/authentik.env` once (secret key, `akadmin`
  password and API token, both client secrets), starts Authentik and applies the blueprints through
  the API. The admin UI is `http://auth.kb.test:8090/if/admin/` as `akadmin` with
  `AUTHENTIK_BOOTSTRAP_PASSWORD` from that file.
- **Forgejo** (`app.ini.tmpl`): `ENABLE_INTERNAL_SIGNIN = false` (no password form, for anyone;
  the admin uses the `forgejo` CLI), `ENABLE_BASIC_AUTHENTICATION = true` (the broker's token mint
  basic-auths as agents on the API), `[oauth2_client] ENABLE_AUTO_REGISTRATION = false` and
  `ACCOUNT_LINKING = disabled`. `bootstrap.sh` re-renders `app.ini` when it lacks a template
  setting, and points the `authentik` source (same id) at Authentik with `forgejo admin auth
  update-oauth`. Traefik answers 403 on `/user/link_account_signin` and `_signup` (see below).
- **Kanboard** gets the third-party OAuth2 plugin from `testing/git/vendor/OAuth2` (gitignored,
  mounted read-only; `run-plugin-tests.sh --all` never sees it). `bootstrap.sh` writes its
  settings (`oauth2_*` ConfigModel keys) with PDO, and sets `application_url` to
  `http://localhost:8081/` only when it is empty: otherwise Kanboard builds the redirect URI from
  the container's own port (`http://localhost/oauth/callback`), which Authentik rejects. The login
  link is "Sign in with Authentik" on the login page (`/oauth/callback`). The plugin creates the
  Kanboard user on first login (`oauth2_account_creation = 1`, username = `preferred_username`).

**OAuth2 plugin (spec gap 6).** Supply-chain check 2026-11-18: `github.com/kanboard/plugin-oauth2`
(the Kanboard org; author Frédéric Guillot), pinned to master `607d0e6` (2026-02-11, nine months
old) because the only release, v1.0.2 (`f41ed17`, 2020), lacks eight later commits that touch PHP.
`testing/git/fetch-oauth2.sh` fetches that commit and checks a SHA-256 of the exported tree. The
code (about 750 lines of PHP) has no `eval`, shell, file or raw curl calls; HTTP goes through
Kanboard's own client, and the OAuth2 `state` check is Kanboard core's (`OAuthController`,
session-bound). On Kanboard 1.2.47 it loads (`getCompatibleVersion` `>=1.0.37`), migrates
(`plugin_schema_versions` oauth2 = 1, column `users.oauth2_user_id`), and its login creates the
user with `oauth2_user_id = sub`, `is_ldap_user = 1` and `disable_login_form = 1` (verified by the
`identity` step). Group sync is off (`oauth2_key_groups` empty).

**Verified behaviour** (Forgejo 15.0.11, Authentik 2026.8.5, scenario step `identity`):

| Question | Answer |
| --- | --- |
| Does a pre-provisioned user (source `authentik`, `login_name = sub`) receive the OIDC login? | Yes: same Forgejo user id as the broker's principal; nothing else is bound to the sub. |
| Unknown sub with auto-registration off? | Callback ends on `/user/link_account`, signed out; no user is created. With `ENABLE_AUTO_REGISTRATION = true` (mutation) Forgejo creates a second user with a different id. |
| Does `ENABLE_INTERNAL_SIGNIN = false` stop password web login? | `POST /user/login` answers 403 even with the correct password, and `/user/login` shows no password field. With it on (mutation) the same request signs in (303). |
| Does API basic auth still work? | Yes (`ENABLE_BASIC_AUTHENTICATION`): `GET /api/v1/user` with the user's password is 200. |
| Is the "link account" page closed by those settings? | **No.** `/user/link_account_signin` still checks a local password, binds the sub to that local user and signs in (verified with the Traefik rule removed). Traefik's `git-link-account` router answers 403 on it and on `_signup` (any case, extra or trailing slashes). |
| CSRF on Forgejo 15 forms? | No form token; Forgejo checks `Origin`/`Sec-Fetch-*` headers, which curl does not send. |
| Does Authentik need Redis? | No, not since 2025.10. Server + worker + Postgres only. |
| Authentik 2026.8 provider gotcha? | `grant_types` must list `authorization_code`; an empty list rejects the code flow ("Invalid grant_type for provider"). |

The `identity` step (curl only, Authentik's flow-executor API, re-runnable: the test users are
reused and get a fresh random password each run):

1. Authentik user `idtest-human` (API, akadmin token from `authentik.env`).
2. Kanboard OAuth2 login; PDO: `users.oauth2_user_id` = the Authentik `uid` (the `sub`).
3. `addProjectUser(28, …)` makes sync provision the human (it carries `oauth2_user_id`);
   `POST /principals` (HMAC-signed, from `kb-suite`) then answers 200 with the same login.
4. Forgejo OIDC login (SSO, no second password): the session's user id = that user's.
5. Local user `idtest-local` with a known password: web login 403, API basic auth 200.
6. Authentik user `idtest-unlinked`, never provisioned: lands on `/user/link_account`, nothing
   bound; `link_account_signin` with `idtest-local`'s password is 403 and links nothing.

`IDENTITY_PROVISION=0` (with a fresh `IDENTITY_USER`) skips step 3 and must fail.

---

## Unit tests (host-side PHPUnit)

Plugin unit tests run on the **host** against the real Kanboard v1.2.47 source using in-memory SQLite.
This avoids needing PHPUnit inside the Docker container (which is a production Alpine image with no dev tooling).

### One-time setup

```bash
# 1. Clone full Kanboard source (with tests/ and dev deps)
git clone --depth 1 --branch v1.2.47 https://github.com/kanboard/kanboard.git testing/kanboard-src

# 2. Install dev dependencies (includes PHPUnit 9.x)
composer install -d testing/kanboard-src
```

`testing/kanboard-src/` is gitignored — it is large and not part of this repo.
Plugin symlinks into `testing/kanboard-src/plugins/` are created automatically by the runner.

### Running a plugin's tests

```bash
# From repo root:
./testing/run-plugin-tests.sh ShadcnTheme   # one suite, PHPUnit output as-is
./testing/run-plugin-tests.sh --all         # every suite, then a pass/fail table
./testing/run-plugin-tests.sh --list        # the plugins it found
```

The script:
1. Finds the plugins: every directory beside `testing/` that holds a `plugin.json`.
2. Verifies `vendor/bin/phpunit` is present in `kanboard-src/`.
3. Symlinks each plugin into `kanboard-src/plugins/` if missing, and writes a bootstrap that
   registers every plugin's PSR-4 namespace.
4. Runs `vendor/bin/phpunit` from the Kanboard root with `tests/units.sqlite.xml` (in-memory SQLite).

`--all` runs every suite to completion even when one fails, writes one log per plugin to
`testing/kanboard-src/test-logs/`, prints a Markdown table (plugin, PASS/FAIL/SKIP, PHPUnit's
summary line, time) and exits 1 if any suite failed.

`PLUGINS_ROOT`, `KB_SRC` and `LOG_DIR` override the locations; the reusable CI workflow uses them to
run the same script against a fresh Kanboard checkout.

### Plugin test conventions

Each plugin's unit tests live in `<PluginName>/Test/` and extend `KanboardTests\units\Base` (which
sets up a full in-memory Kanboard container). No manual `require_once` is needed — the bootstrap
handles namespace registration for all plugins automatically.
