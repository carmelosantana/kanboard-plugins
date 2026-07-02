# Kanboard Plugin Suite — Docker Test Harness

One Docker stack that mounts all four plugins for local development and testing.

## Prerequisites

- Docker + Docker Compose (Linux)
- `python3` on the host (used by `seed.sh` / `snapshot.sh` for safe SQL quoting and JSON parsing)
- Port **8081** free on the host

> **Note:** The `kanboard/kanboard:latest` Alpine image does **not** ship the `sqlite3` CLI.
> The scripts talk to the DB through PHP PDO (`php -r "new PDO('sqlite:...')"`) executed inside the container.

---

## Quick start

All commands run from the **repo root** (`kanboard-plugins/`).

### 1. Fix host permissions (one-time)

The container web user (`nginx`) needs read/execute access to the bind-mounted plugin dirs:

```bash
chmod -R o+rX BulkProjectDelete ShadcnTheme FeatureSync SubtaskGenerator
```

### 2. Tear down the old single-plugin container (if running)

```bash
docker rm -f kb-theme-shadcn 2>/dev/null || true
# or: docker compose -p kanboard-theme-test down
```

### 3. Bring the stack up

```bash
docker compose -f testing/docker-compose.dev.yml up -d
```

Kanboard is now at **http://localhost:8081** (login `admin` / `admin`).

The four plugins are bind-mounted at `/var/www/app/plugins/`:

| Plugin dir         | Status   |
|--------------------|----------|
| BulkProjectDelete  | placeholder (real code in later tasks) |
| ShadcnTheme        | **active** — real code present |
| FeatureSync        | placeholder |
| SubtaskGenerator   | placeholder |

Verify mounts:

```bash
docker compose -f testing/docker-compose.dev.yml exec kanboard ls /var/www/app/plugins/
```

### 4. Seed test data

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

### 5. Snapshot (before / after a plugin action)

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
| Image          | `kanboard/kanboard:latest` |
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
./testing/run-plugin-tests.sh <PluginName>

# Example:
./testing/run-plugin-tests.sh ShadcnTheme
```

The script:
1. Verifies `kanboard-src/` and `vendor/bin/phpunit` are present.
2. Creates symlinks for all four plugins inside `kanboard-src/plugins/` if missing.
3. Prints a clear message if the plugin has no `Test/` directory yet.
4. Runs `vendor/bin/phpunit` from the Kanboard root with `tests/units.sqlite.xml` (in-memory SQLite)
   and a bootstrap that registers all plugin PSR-4 namespaces.

### Plugin test conventions

Each plugin's unit tests live in `<PluginName>/Test/` and extend `KanboardTests\units\Base` (which
sets up a full in-memory Kanboard container). No manual `require_once` is needed — the bootstrap
handles namespace registration for all plugins automatically.
