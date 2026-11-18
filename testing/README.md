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
Kanboard without the jsonrpc, Chicane and Bigboard overlays. `testing/git/compose.sh` runs
`docker compose -p kanboard-suite` with all five files; name the git services and pass `--no-deps`:

```bash
bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
bash testing/git/bootstrap.sh            # admin + broker token -> testing/git/.env, HMAC secret -> testing/git/hmac.env
bash testing/git/compose.sh up -d kanboard broker   # once, after hmac.env first appears (recreates kb-suite)
bash testing/git/scenario.sh preflight   # expect "preflight: PASS (16/16)"
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
| `5a` | pushes to another agent's prefix and to `main` are refused by branch protection while the audit shows authz allowed them; an agent status post is 403 |
| `7` | `removeProjectUser`: the next fetch is denied; re-added (always, also on exit), a push to the own prefix works again; `closeTask`: the token is dead (`token_revoked`/`task_closed`) |
| `8` | `docker pause kb-suite`: fetch denied (`kanboard_unreachable`), always unpaused by a trap; then allowed |

They read `KANBOARD_USERNAME`, `KANBOARD_API_KEY` and `AGENT_API_TOKEN` (or
`AGENT_API_TOKEN_FILE`) from the environment or from the gitignored `testing/git/scenario.env`,
print `PASS`/`FAIL` with the broker's audit lines, and can be re-run: the core task is reused
and branch names are unique.

The overlay also wires Kanboard to the broker. It mounts `../GitHost` into `kb-suite`, and
gives `kb-suite` only `testing/git/hmac.env` (`GITHOST_HMAC_SECRET`) plus
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
`docker compose -p kanboard-suite -f testing/docker-compose.dev.yml -f testing/docker-compose.jsonrpc-overlay.yml -f testing/docker-compose.chicane-overlay.yml -f testing/docker-compose.bigboard-overlay.yml -f testing/docker-compose.git-overlay.yml`.

| Container | Role |
|-----------|------|
| `kb-git-traefik` | `127.0.0.1:8090`; routes in `testing/git/traefik/dynamic.yml` (file provider, watched) |
| `kb-git-forgejo` | Forgejo, rootless image, no published port; config from `testing/git/forgejo/app.ini.tmpl` on first start |
| `kb-git-db` | Postgres for Forgejo |
| `kb-git-broker` | ForwardAuth target `http://broker:8080/authz`; reads `testing/git/.env` and `testing/git/hmac.env`; pulls `http://kb-suite` every 30 s |

- **Hostname:** Traefik listens on `:8090` inside the network too and has the network alias
  `git.localhost`, so `ROOT_URL=http://git.localhost:8090/` is one URL for host and containers. DNS
  inside containers resolves the alias, but curl/libcurl (so `git` and PHP curl) pin `*.localhost` to
  loopback; from a container use `--resolve git.localhost:8090:<traefik ip>` (curl) or
  `-c http.curloptResolve=git.localhost:8090:<traefik ip>` (git).
- **Forgejo config:** `app.ini.tmpl` is rendered only when the volume has no `app.ini`. After editing
  it, delete `/var/lib/gitea/custom/conf/app.ini` in the container (or the volume) and recreate `forgejo`.
- **Stop or remove** the git stack only: `bash testing/git/compose.sh stop forgejo-db forgejo broker traefik`
  (`rm -s -f` to remove; the volumes `kanboard-suite_kb-git-*` keep the data).
- **Broker integration tests** run on the compose network against `http://forgejo:3000` (Forgejo has
  no host port), with the admin token from `testing/git/.env`. With the git stack up:

  ```bash
  bash testing/git/compose.sh --profile it run --rm broker-it   # cargo test --test forgejo_it -- --ignored
  ```

  `broker-it` (profile `it`, never started by `up`) uses the broker Dockerfile's pinned Rust image,
  mounts `../kanboard-git-broker` read-only and caches the cargo registry and target dir in the
  volumes `kanboard-suite_kb-git-broker-it-*`. The tests create throwaway Forgejo users and a
  `kb-it…` org and delete them when they finish, pass or fail.

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
