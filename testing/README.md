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
./testing/run-plugin-tests.sh Agents/.claude/worktrees/my-branch   # a checkout at a path
```

The script:
1. Finds the plugins: every directory beside `testing/` that holds a `plugin.json`.
2. Verifies `vendor/bin/phpunit` is present in `kanboard-src/`.
3. Symlinks each plugin into `kanboard-src/plugins/` if missing, and writes a bootstrap that
   registers every plugin's PSR-4 namespace.
4. Runs `vendor/bin/phpunit` from the Kanboard root with `tests/plugin-units.sqlite.xml`: a copy
   of Kanboard's `tests/units.sqlite.xml` (in-memory SQLite) without `stopOnError` and
   `stopOnFailure`. Kanboard's own config stops at the first failure, which hid the real test count
   (one suite showed 20 tests), so every run now finishes and prints the full count.

**Testing a worktree or another checkout.** Pass a path instead of a name. The plugin name comes
from the `namespace Kanboard\Plugin\X;` line in its `Plugin.php`, and the run gets its own temp
plugins directory (Kanboard reads `PLUGINS_DIR` from the environment) where that name points at
your path. The shared `kanboard-src/plugins/` links stay as they are, so you never switch branches
in a shared plugin checkout to test a change. The :8081 stack still mounts `../<Plugin>`, so a live
check of a worktree needs its own overlay (not provided yet).

**Running from a harness worktree.** In a linked worktree of this repo, the runner falls back to
the main checkout's `testing/kanboard-src/` and plugin checkouts, since both are gitignored.

`testing/run-plugin-tests.test.sh` covers these cases against fixture plugins and a throwaway
Kanboard tree; run it after changing the runner.

`--all` runs every suite to completion even when one fails, writes one log per plugin to
`testing/kanboard-src/test-logs/`, prints a Markdown table (plugin, PASS/FAIL/SKIP, PHPUnit's
summary line, time) and exits 1 if any suite failed.

`PLUGINS_ROOT`, `KB_SRC` and `LOG_DIR` override the locations; the reusable CI workflow uses them to
run the same script against a fresh Kanboard checkout.

### Plugin test conventions

Each plugin's unit tests live in `<PluginName>/Test/` and extend `KanboardTests\units\Base` (which
sets up a full in-memory Kanboard container). No manual `require_once` is needed — the bootstrap
handles namespace registration for all plugins automatically.
