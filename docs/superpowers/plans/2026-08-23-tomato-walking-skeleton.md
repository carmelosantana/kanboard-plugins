# Tomato Walking Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the thinnest end-to-end slice of Tomato — a Tauri menu-bar timer that runs a 15-minute focus window, nudges you to break (DND-aware), and logs each completed work session to a Kanboard plugin, attributed to a real Kanboard user.

**Architecture:** Two components joined by one JSON-RPC contract. A Kanboard plugin (`Tomato`) exposes a single authenticated procedure `tomato.recordSession` that idempotently writes to a `tomato_session` table, attributing the row to the authenticated user. A Tauri v2 desktop applet runs a pure-TS timer state machine, renders a circular tray icon, shows a DND-aware break nudge, and posts completed sessions through a local SQLite outbox with retry.

**Tech Stack:** PHP ≥ 8.4 / Kanboard ≥ 1.2.47 plugin (buildless, PHPUnit via the suite harness); Tauri v2 + TypeScript + pnpm + Vitest applet (Rust only as a thin native shell).

## Global Constraints

- Kanboard core floor: **>= 1.2.47** (`plugin.json` `kanboard_version`, and `Plugin::getCompatibleVersion()` returns `>=1.2.47`).
- PHP floor: **>= 8.4** (`plugin.json` `php_version`).
- Plugin version for this slice: **1.0.0** — the same string in `plugin.json` and `Plugin::getPluginVersion()`; version tests assert it verbatim.
- Plugin is **buildless**: no composer deps, no build step. CSP-safe (no inline JS) — N/A for the skeleton (headless API, no templates).
- Session `user_id` is **always** derived from `$this->userSession->getId()` (auth context), **never** read from the JSON-RPC payload.
- `client_session_id` is the idempotency key: a repeat value returns the existing `session_id` with `{ok: true}` and inserts nothing.
- Applet: **all business logic lives in pure TS modules** under `src/core/` (unit-tested with Vitest); every native capability (tray, notification, window, keychain, SQLite, DND detection) sits behind a TS interface with an in-memory fake for tests and a Tauri-backed implementation for runtime.
- Applet secret handling: the Kanboard personal API token is stored in the **OS keychain**, never in a plaintext config file.
- Applet default cadence: window **15 min**, break **3 min** (both configurable).
- Plugin repo/dev-harness path: `kanboard-plugins/Tomato/`. Applet repo path: `~/Projects/Tomato/` (separate git repo; adjust if you prefer another location).
- Never mutate production Kanboard via MCP. The live dev stack is the `kb-suite` container on `:8081` (admin/admin); drive it via `docker exec` + PDO/curl only.

---

# Phase 1 — Kanboard plugin (independently shippable)

All Phase 1 paths are relative to the plugin root `Tomato/` unless noted. Run tests from the monorepo root with:

```bash
./testing/run-plugin-tests.sh Tomato
```

The first run auto-creates the `testing/kanboard-src/plugins/Tomato` symlink.

---

### Task 1: Plugin scaffold + metadata

**Files:**
- Create: `Tomato/plugin.json`
- Create: `Tomato/Plugin.php`
- Create: `Tomato/LICENSE`
- Create: `Tomato/Test/PluginMetaTest.php`
- Create: `Tomato/Test/PluginTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Kanboard\Plugin\Tomato\Plugin` with `getPluginName(): string` → `'Tomato'`, `getPluginVersion(): string` → `'1.0.0'`, `getCompatibleVersion(): string` → `'>=1.2.47'`, `getPluginAuthor()`, `getPluginDescription()`, `getPluginHomepage()`. `initialize(): void` (empty for now; the procedure is registered in Task 3).

- [ ] **Step 1: Write the failing metadata test**

Create `Tomato/Test/PluginMetaTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class PluginMetaTest extends Base
{
    private function manifest(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
    }

    public function testManifestName(): void
    {
        $this->assertSame('Tomato', $this->manifest()['name']);
    }

    public function testManifestVersion(): void
    {
        $this->assertSame('1.0.0', $this->manifest()['version']);
    }

    public function testManifestFloors(): void
    {
        $m = $this->manifest();
        $this->assertSame('>=1.2.47', $m['kanboard_version']);
        $this->assertSame('>=8.4', $m['php_version']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: FAIL — `plugin.json` does not exist (`file_get_contents` warning / null decode).

- [ ] **Step 3: Create `plugin.json`**

```json
{
    "name": "Tomato",
    "description": "Logs intentional 15-minute work sessions from the Tomato desktop applet, attributed to a Kanboard user.",
    "version": "1.0.0",
    "author": "Carmelo Santana",
    "license": "MIT",
    "homepage": "https://github.com/carmelosantana/kanboard-tomato",
    "kanboard_version": ">=1.2.47",
    "php_version": ">=8.4"
}
```

- [ ] **Step 4: Create `LICENSE`**

Copy the MIT license text (same as sibling plugins, e.g. `TimeReport/LICENSE`), with copyright `Carmelo Santana`.

```bash
cp ../TimeReport/LICENSE ./LICENSE
```

- [ ] **Step 5: Create `Plugin.php`**

```php
<?php

namespace Kanboard\Plugin\Tomato;

use Kanboard\Core\Plugin\Base;

/**
 * Tomato — ingest for intentional work sessions from the Tomato desktop applet.
 *
 * Headless: exposes a single authenticated JSON-RPC procedure and owns one
 * table. No routes, templates, or assets in this slice.
 */
class Plugin extends Base
{
    public function initialize(): void
    {
        // The API procedure is registered in Task 3.
    }

    public function getPluginName(): string
    {
        return 'Tomato';
    }

    public function getPluginDescription(): string
    {
        return 'Logs intentional 15-minute work sessions from the Tomato desktop applet, attributed to a Kanboard user.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-tomato';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
```

- [ ] **Step 6: Write the failing Plugin class test**

Create `Tomato/Test/PluginTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Tomato\Plugin;

class PluginTest extends Base
{
    private function plugin(): Plugin
    {
        return new Plugin($this->container);
    }

    public function testName(): void
    {
        $this->assertSame('Tomato', $this->plugin()->getPluginName());
    }

    public function testVersion(): void
    {
        $this->assertSame('1.0.0', $this->plugin()->getPluginVersion());
    }

    public function testCompatibleVersion(): void
    {
        $this->assertSame('>=1.2.47', $this->plugin()->getCompatibleVersion());
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: PASS — all of PluginMetaTest and PluginTest green.

- [ ] **Step 8: Commit**

```bash
cd Tomato && git init -q 2>/dev/null; git add plugin.json Plugin.php LICENSE Test/PluginMetaTest.php Test/PluginTest.php
git commit -m "feat(tomato-plugin): scaffold plugin + metadata (v1.0.0)"
```

---

### Task 2: Schema migration + `TomatoSessionModel`

**Files:**
- Create: `Tomato/Schema/Sqlite.php`
- Create: `Tomato/Schema/Mysql.php`
- Create: `Tomato/Schema/Postgres.php`
- Create: `Tomato/Model/TomatoSessionModel.php`
- Create: `Tomato/Test/TomatoSessionModelTest.php`

**Interfaces:**
- Consumes: `$this->container['db']` (Kanboard PicoDb) — available on any `Kanboard\Core\Base` subclass.
- Produces:
  - Namespace `Kanboard\Plugin\Tomato\Schema` with `const VERSION = 1;` and `function version_1(\PDO $pdo): void`.
  - `Kanboard\Plugin\Tomato\Model\TomatoSessionModel extends \Kanboard\Core\Base` with:
    - `record(int $userId, array $payload): int` — validates, idempotently inserts, returns the row id (existing id on duplicate `client_session_id`). Throws `\InvalidArgumentException` on missing/invalid required fields or `$userId <= 0`.
    - Required `$payload` keys: `client_session_id` (non-empty string), `machine_id` (non-empty string), `started_at` (int), `ended_at` (int). Optional: `machine_label` (string, default `''`), `active_seconds` (int, default 0), `windows_elapsed` (int, default 0), `breaks_taken` (int, default 0), `app_version` (string, default `''`).
  - Table name constant `TomatoSessionModel::TABLE = 'tomato_session'`.

- [ ] **Step 1: Write the failing model test**

Create `Tomato/Test/TomatoSessionModelTest.php`:

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Tomato\Model\TomatoSessionModel;

class TomatoSessionModelTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Tomato\Schema\version_1($this->container['db']->getConnection());
    }

    private function model(): TomatoSessionModel
    {
        return new TomatoSessionModel($this->container);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'client_session_id' => 'cs-1',
            'machine_id'        => 'm-1',
            'machine_label'     => 'thinkpad',
            'started_at'        => 1000,
            'ended_at'          => 1900,
            'active_seconds'    => 850,
            'windows_elapsed'   => 1,
            'breaks_taken'      => 1,
            'app_version'       => '1.0.0',
        ], $over);
    }

    public function testRecordInsertsAndReturnsId(): void
    {
        $id = $this->model()->record(7, $this->payload());
        $this->assertGreaterThan(0, $id);

        $row = $this->container['db']->table(TomatoSessionModel::TABLE)->eq('id', $id)->findOne();
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('cs-1', $row['client_session_id']);
        $this->assertSame(850, (int) $row['active_seconds']);
        $this->assertGreaterThan(0, (int) $row['created_at']);
    }

    public function testRecordIsIdempotentOnClientSessionId(): void
    {
        $m = $this->model();
        $first = $m->record(7, $this->payload());
        $second = $m->record(7, $this->payload(['active_seconds' => 9999]));

        $this->assertSame($first, $second);
        $count = $this->container['db']->table(TomatoSessionModel::TABLE)->eq('client_session_id', 'cs-1')->count();
        $this->assertSame(1, $count);
    }

    public function testRecordRejectsMissingClientSessionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model()->record(7, $this->payload(['client_session_id' => '']));
    }

    public function testRecordRejectsNonPositiveUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model()->record(0, $this->payload());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: FAIL — `Kanboard\Plugin\Tomato\Schema\version_1` and `TomatoSessionModel` do not exist.

- [ ] **Step 3: Create `Schema/Sqlite.php`**

```php
<?php

namespace Kanboard\Plugin\Tomato\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE tomato_session (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            machine_id TEXT NOT NULL,
            machine_label TEXT NOT NULL DEFAULT "",
            client_session_id TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            ended_at INTEGER NOT NULL,
            active_seconds INTEGER NOT NULL DEFAULT 0,
            windows_elapsed INTEGER NOT NULL DEFAULT 0,
            breaks_taken INTEGER NOT NULL DEFAULT 0,
            app_version TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL DEFAULT 0
        )
    ');
    $pdo->exec('CREATE UNIQUE INDEX tomato_session_client_uidx ON tomato_session(client_session_id)');
    $pdo->exec('CREATE INDEX tomato_session_user_idx ON tomato_session(user_id)');
}
```

- [ ] **Step 4: Create `Schema/Mysql.php`** (production parity; not exercised by tests)

```php
<?php

namespace Kanboard\Plugin\Tomato\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE tomato_session (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            machine_id VARCHAR(191) NOT NULL,
            machine_label VARCHAR(191) NOT NULL DEFAULT '',
            client_session_id VARCHAR(191) NOT NULL,
            started_at INT NOT NULL,
            ended_at INT NOT NULL,
            active_seconds INT NOT NULL DEFAULT 0,
            windows_elapsed INT NOT NULL DEFAULT 0,
            breaks_taken INT NOT NULL DEFAULT 0,
            app_version VARCHAR(64) NOT NULL DEFAULT '',
            created_at INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            UNIQUE KEY tomato_session_client_uidx (client_session_id),
            KEY tomato_session_user_idx (user_id)
        ) ENGINE=InnoDB CHARSET=utf8mb4
    ");
}
```

- [ ] **Step 5: Create `Schema/Postgres.php`** (production parity; not exercised by tests)

```php
<?php

namespace Kanboard\Plugin\Tomato\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE tomato_session (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            machine_id TEXT NOT NULL,
            machine_label TEXT NOT NULL DEFAULT '',
            client_session_id TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            ended_at INTEGER NOT NULL,
            active_seconds INTEGER NOT NULL DEFAULT 0,
            windows_elapsed INTEGER NOT NULL DEFAULT 0,
            breaks_taken INTEGER NOT NULL DEFAULT 0,
            app_version TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec('CREATE UNIQUE INDEX tomato_session_client_uidx ON tomato_session(client_session_id)');
    $pdo->exec('CREATE INDEX tomato_session_user_idx ON tomato_session(user_id)');
}
```

- [ ] **Step 6: Create `Model/TomatoSessionModel.php`**

```php
<?php

namespace Kanboard\Plugin\Tomato\Model;

use Kanboard\Core\Base;

/**
 * Persists one work session per Start→Stop stretch from the Tomato applet.
 * Idempotent on client_session_id; attributes rows to a caller-supplied user id.
 */
class TomatoSessionModel extends Base
{
    const TABLE = 'tomato_session';

    /**
     * @throws \InvalidArgumentException on invalid user id or missing required fields.
     */
    public function record(int $userId, array $payload): int
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A positive user id is required.');
        }

        $clientSessionId = (string) ($payload['client_session_id'] ?? '');
        $machineId       = (string) ($payload['machine_id'] ?? '');

        if ($clientSessionId === '' || $machineId === '') {
            throw new \InvalidArgumentException('client_session_id and machine_id are required.');
        }
        if (! isset($payload['started_at'], $payload['ended_at'])) {
            throw new \InvalidArgumentException('started_at and ended_at are required.');
        }

        $existing = $this->db->table(self::TABLE)
            ->eq('client_session_id', $clientSessionId)
            ->findOneColumn('id');

        if ($existing !== null && $existing !== false) {
            return (int) $existing;
        }

        $this->db->table(self::TABLE)->insert([
            'user_id'           => $userId,
            'machine_id'        => $machineId,
            'machine_label'     => (string) ($payload['machine_label'] ?? ''),
            'client_session_id' => $clientSessionId,
            'started_at'        => (int) $payload['started_at'],
            'ended_at'          => (int) $payload['ended_at'],
            'active_seconds'    => (int) ($payload['active_seconds'] ?? 0),
            'windows_elapsed'   => (int) ($payload['windows_elapsed'] ?? 0),
            'breaks_taken'      => (int) ($payload['breaks_taken'] ?? 0),
            'app_version'       => (string) ($payload['app_version'] ?? ''),
            'created_at'        => time(),
        ]);

        return (int) $this->db->getLastId();
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: PASS — all four TomatoSessionModelTest cases green.

- [ ] **Step 8: Commit**

```bash
cd Tomato && git add Schema/Sqlite.php Schema/Mysql.php Schema/Postgres.php Model/TomatoSessionModel.php Test/TomatoSessionModelTest.php
git commit -m "feat(tomato-plugin): tomato_session schema + idempotent session model"
```

---

### Task 3: `tomato.recordSession` JSON-RPC procedure + registration

**Files:**
- Create: `Tomato/Api/TomatoProcedure.php`
- Modify: `Tomato/Plugin.php` (register the procedure in `initialize()`)
- Create: `Tomato/Test/TomatoProcedureTest.php`

**Interfaces:**
- Consumes: `TomatoSessionModel::record(int, array): int`; `$this->userSession->getId()` (auth context); `$container['api']->getProcedureHandler()`.
- Produces: JSON-RPC method `tomato.recordSession` →
  `recordSession($client_session_id, $machine_id, $started_at, $ended_at, $machine_label = '', $active_seconds = 0, $windows_elapsed = 0, $breaks_taken = 0, $app_version = '')`
  returning `['ok' => true, 'session_id' => int]`. Throws when no authenticated user (`$this->userSession->getId()` is empty).

- [ ] **Step 1: Write the failing procedure test**

Create `Tomato/Test/TomatoProcedureTest.php`. It fakes `userSession` in the container so the procedure resolves a known user id, and installs the schema like the model test.

```php
<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Tomato\Api\TomatoProcedure;
use Kanboard\Plugin\Tomato\Model\TomatoSessionModel;

class TomatoProcedureTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Tomato\Schema\version_1($this->container['db']->getConnection());
    }

    private function withUser(?int $id): TomatoProcedure
    {
        $this->container['userSession'] = new class($id) {
            public function __construct(private ?int $id) {}
            public function getId() { return $this->id; }
        };
        return new TomatoProcedure($this->container);
    }

    public function testRecordSessionAttributesToAuthenticatedUser(): void
    {
        $result = $this->withUser(42)->recordSession('cs-9', 'm-9', 1000, 1900, 'box', 850, 1, 1, '1.0.0');

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan(0, $result['session_id']);

        $row = $this->container['db']->table(TomatoSessionModel::TABLE)->eq('id', $result['session_id'])->findOne();
        $this->assertSame(42, (int) $row['user_id']);
        $this->assertSame('cs-9', $row['client_session_id']);
    }

    public function testRecordSessionIsIdempotent(): void
    {
        $proc = $this->withUser(42);
        $a = $proc->recordSession('cs-same', 'm-9', 1000, 1900);
        $b = $proc->recordSession('cs-same', 'm-9', 1000, 1900);
        $this->assertSame($a['session_id'], $b['session_id']);
    }

    public function testRecordSessionRejectsUnauthenticated(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->withUser(null)->recordSession('cs-x', 'm-9', 1000, 1900);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: FAIL — `Kanboard\Plugin\Tomato\Api\TomatoProcedure` does not exist.

- [ ] **Step 3: Create `Api/TomatoProcedure.php`**

```php
<?php

namespace Kanboard\Plugin\Tomato\Api;

use Kanboard\Core\Base;
use Kanboard\Plugin\Tomato\Model\TomatoSessionModel;

/**
 * JSON-RPC surface for the Tomato applet. Registered as `tomato.recordSession`.
 * The session is attributed to the authenticated user; user id is never taken
 * from the payload.
 */
class TomatoProcedure extends Base
{
    public function recordSession(
        $client_session_id,
        $machine_id,
        $started_at,
        $ended_at,
        $machine_label = '',
        $active_seconds = 0,
        $windows_elapsed = 0,
        $breaks_taken = 0,
        $app_version = ''
    ): array {
        $userId = (int) $this->userSession->getId();
        if ($userId <= 0) {
            throw new \RuntimeException('tomato.recordSession requires an authenticated user (personal API token).');
        }

        $model = new TomatoSessionModel($this->container);
        $sessionId = $model->record($userId, [
            'client_session_id' => $client_session_id,
            'machine_id'        => $machine_id,
            'machine_label'     => $machine_label,
            'started_at'        => $started_at,
            'ended_at'          => $ended_at,
            'active_seconds'    => $active_seconds,
            'windows_elapsed'   => $windows_elapsed,
            'breaks_taken'      => $breaks_taken,
            'app_version'       => $app_version,
        ]);

        return ['ok' => true, 'session_id' => $sessionId];
    }
}
```

- [ ] **Step 4: Register the procedure in `Plugin.php`**

Replace the `initialize()` body and add the import:

```php
use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Tomato\Api\TomatoProcedure;
```

```php
    public function initialize(): void
    {
        $this->api->getProcedureHandler()->withClassAndMethod(
            'tomato.recordSession',
            new TomatoProcedure($this->container),
            'recordSession'
        );
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./testing/run-plugin-tests.sh Tomato`
Expected: PASS — all three TomatoProcedureTest cases green, and every earlier test still green.

- [ ] **Step 6: Manual live smoke test against the dev stack**

Deploy into the running `kb-suite` container and call the procedure as a real user. First mint a personal API token for a user in Settings → API, or reuse an existing one. Then:

Run (replace `USER` / `TOKEN`):
```bash
curl -s -u 'USER:TOKEN' \
  -H 'Content-Type: application/json' \
  http://localhost:8081/jsonrpc.php \
  -d '{"jsonrpc":"2.0","id":1,"method":"tomato.recordSession","params":{"client_session_id":"smoke-1","machine_id":"dev-box","started_at":1000,"ended_at":1900,"active_seconds":850,"windows_elapsed":1,"breaks_taken":1,"app_version":"0.0.0"}}'
```
Expected: `{"jsonrpc":"2.0","id":1,"result":{"ok":true,"session_id":<n>}}`. Re-running with the same `client_session_id` returns the same `session_id`.

Verify the row:
```bash
docker exec kb-suite sh -c 'sqlite3 /var/www/app/data/db.sqlite "SELECT id,user_id,client_session_id,active_seconds FROM tomato_session;"' 2>/dev/null || echo "adjust DB path/driver for the kb-suite container"
```
Expected: one `smoke-1` row attributed to the token's user id. (If the stack uses MySQL/Postgres, query via the appropriate client instead.)

- [ ] **Step 7: Commit**

```bash
cd Tomato && git add Api/TomatoProcedure.php Plugin.php Test/TomatoProcedureTest.php
git commit -m "feat(tomato-plugin): tomato.recordSession procedure (auth-attributed, idempotent)"
```

**Phase 1 is now independently shippable** — a headless Kanboard plugin that ingests work sessions. Follow the suite release process when ready (tag `v1.0.0`, CI zip, ModMenu listing). Release is out of scope for this plan.

---

# Phase 2 — Tauri v2 applet

All Phase 2 paths are relative to the applet repo root `~/Projects/Tomato/` unless noted. Pure logic under `src/core/` is unit-tested with Vitest (`pnpm test`); native capabilities are behind interfaces in `src/native/` and verified manually on Linux in Task 11.

---

### Task 4: Applet scaffold (Tauri v2 + pnpm + Vitest)

**Files:**
- Create: the Tauri v2 project at `~/Projects/Tomato/` (via scaffold), then
- Create: `~/Projects/Tomato/vitest.config.ts`
- Create: `~/Projects/Tomato/src/core/version.ts`
- Create: `~/Projects/Tomato/src/core/version.test.ts`

**Interfaces:**
- Produces: `APP_VERSION: string` from `src/core/version.ts` (single source of the applet version string, `'1.0.0'`), and a green Vitest run proving the toolchain works.

- [ ] **Step 1: Scaffold the Tauri v2 app**

Run:
```bash
mkdir -p ~/Projects/Tomato && cd ~/Projects/Tomato
pnpm create tauri-app@latest . --template vanilla-ts --manager pnpm --yes
pnpm install
```
If the interactive prompt appears despite `--yes`, choose: package name `tomato`, frontend `TypeScript`, template `Vanilla`. Expected: `src-tauri/` (Rust shell) and `src/` (TS frontend) exist; `pnpm tauri --version` prints a v2 version.

- [ ] **Step 2: Add Vitest**

Run:
```bash
cd ~/Projects/Tomato && pnpm add -D vitest
```
Add to `package.json` `"scripts"`: `"test": "vitest run"`, `"test:watch": "vitest"`.

- [ ] **Step 3: Write the failing smoke test**

Create `src/core/version.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { APP_VERSION } from "./version";

describe("APP_VERSION", () => {
  it("is semver 1.0.0", () => {
    expect(APP_VERSION).toBe("1.0.0");
  });
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./version`.

- [ ] **Step 5: Create `src/core/version.ts`**

```ts
export const APP_VERSION = "1.0.0";
```

- [ ] **Step 6: Run test to verify it passes**

Run: `pnpm test`
Expected: PASS — 1 test green.

- [ ] **Step 7: Commit**

```bash
cd ~/Projects/Tomato && git init -q 2>/dev/null; git add -A
git commit -m "chore(tomato-applet): scaffold Tauri v2 + pnpm + Vitest"
```

---

### Task 5: Timer state machine (pure)

**Files:**
- Create: `src/core/timer.ts`
- Create: `src/core/timer.test.ts`

**Interfaces:**
- Produces:
  - `type TimerState = "idle" | "running" | "break" | "stopped"`
  - `interface TimerConfig { windowMs: number; breakMs: number }`
  - `interface TimerSnapshot { state: TimerState; windowElapsedMs: number; activeMs: number; windowsElapsed: number; breaksTaken: number; nudged: boolean }`
  - `class TimerMachine`:
    - `constructor(config: TimerConfig)`
    - `start(nowMs: number): void` — idle/stopped → running; records session start.
    - `tick(nowMs: number): TimerEvent[]` — advances time; returns events since last tick.
    - `beginBreak(nowMs: number): void` — running → break (increments `breaksTaken`, resets the current window).
    - `endBreak(nowMs: number): void` — break → running.
    - `stop(nowMs: number): void` — running/break → stopped.
    - `snapshot(nowMs: number): TimerSnapshot`
    - `sessionBounds(): { startedAtMs: number; endedAtMs: number } | null` — set after `stop`.
  - `type TimerEvent = { type: "window-elapsed" } | { type: "nudge" }` — `window-elapsed` fires once each time an uninterrupted running window reaches `windowMs`; `nudge` fires with it (the applet turns it into a break prompt). `activeMs` accrues only while `running` (not during `break`).

- [ ] **Step 1: Write the failing tests**

Create `src/core/timer.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { TimerMachine } from "./timer";

const cfg = { windowMs: 15 * 60_000, breakMs: 3 * 60_000 };

describe("TimerMachine", () => {
  it("starts idle and transitions to running", () => {
    const t = new TimerMachine(cfg);
    expect(t.snapshot(0).state).toBe("idle");
    t.start(0);
    expect(t.snapshot(0).state).toBe("running");
  });

  it("emits a nudge + window-elapsed exactly once at the window boundary", () => {
    const t = new TimerMachine(cfg);
    t.start(0);
    expect(t.tick(cfg.windowMs - 1)).toEqual([]);
    const atBoundary = t.tick(cfg.windowMs);
    expect(atBoundary.map((e) => e.type).sort()).toEqual(["nudge", "window-elapsed"]);
    expect(t.tick(cfg.windowMs + 1000)).toEqual([]); // does not re-fire while still running
    expect(t.snapshot(cfg.windowMs + 1000).windowsElapsed).toBe(1);
  });

  it("accrues activeMs only while running, not during break", () => {
    const t = new TimerMachine(cfg);
    t.start(0);
    t.tick(60_000);
    t.beginBreak(60_000);
    t.tick(60_000 + cfg.breakMs); // break time should not count as active
    t.endBreak(60_000 + cfg.breakMs);
    t.tick(60_000 + cfg.breakMs + 30_000);
    const snap = t.snapshot(60_000 + cfg.breakMs + 30_000);
    expect(snap.activeMs).toBe(90_000);
    expect(snap.breaksTaken).toBe(1);
  });

  it("resets the current window after a break so the next window is full", () => {
    const t = new TimerMachine(cfg);
    t.start(0);
    t.tick(cfg.windowMs);          // window 1 elapses
    t.beginBreak(cfg.windowMs);
    t.endBreak(cfg.windowMs);
    expect(t.tick(cfg.windowMs + cfg.windowMs - 1)).toEqual([]); // not yet
    expect(t.tick(cfg.windowMs + cfg.windowMs).map((e) => e.type)).toContain("window-elapsed");
    expect(t.snapshot(cfg.windowMs + cfg.windowMs).windowsElapsed).toBe(2);
  });

  it("records session bounds on stop", () => {
    const t = new TimerMachine(cfg);
    t.start(500);
    t.tick(1500);
    t.stop(1500);
    expect(t.snapshot(1500).state).toBe("stopped");
    expect(t.sessionBounds()).toEqual({ startedAtMs: 500, endedAtMs: 1500 });
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./timer`.

- [ ] **Step 3: Implement `src/core/timer.ts`**

```ts
export type TimerState = "idle" | "running" | "break" | "stopped";

export interface TimerConfig {
  windowMs: number;
  breakMs: number;
}

export interface TimerSnapshot {
  state: TimerState;
  windowElapsedMs: number;
  activeMs: number;
  windowsElapsed: number;
  breaksTaken: number;
  nudged: boolean;
}

export type TimerEvent = { type: "window-elapsed" } | { type: "nudge" };

export class TimerMachine {
  private state: TimerState = "idle";
  private lastMs = 0;
  private windowElapsedMs = 0;
  private activeMs = 0;
  private windowsElapsed = 0;
  private breaksTaken = 0;
  private nudgedThisWindow = false;
  private startedAtMs: number | null = null;
  private endedAtMs: number | null = null;

  constructor(private readonly config: TimerConfig) {}

  start(nowMs: number): void {
    if (this.state === "running" || this.state === "break") return;
    this.state = "running";
    this.lastMs = nowMs;
    this.windowElapsedMs = 0;
    this.activeMs = 0;
    this.windowsElapsed = 0;
    this.breaksTaken = 0;
    this.nudgedThisWindow = false;
    this.startedAtMs = nowMs;
    this.endedAtMs = null;
  }

  tick(nowMs: number): TimerEvent[] {
    const delta = Math.max(0, nowMs - this.lastMs);
    this.lastMs = nowMs;
    const events: TimerEvent[] = [];

    if (this.state === "running") {
      this.activeMs += delta;
      this.windowElapsedMs += delta;
      if (!this.nudgedThisWindow && this.windowElapsedMs >= this.config.windowMs) {
        this.nudgedThisWindow = true;
        this.windowsElapsed += 1;
        events.push({ type: "window-elapsed" }, { type: "nudge" });
      }
    }
    return events;
  }

  beginBreak(nowMs: number): void {
    if (this.state !== "running") return;
    this.tick(nowMs);
    this.state = "break";
    this.breaksTaken += 1;
    this.lastMs = nowMs;
  }

  endBreak(nowMs: number): void {
    if (this.state !== "break") return;
    this.state = "running";
    this.lastMs = nowMs;
    this.windowElapsedMs = 0;
    this.nudgedThisWindow = false;
  }

  stop(nowMs: number): void {
    if (this.state === "idle" || this.state === "stopped") return;
    this.tick(nowMs);
    this.state = "stopped";
    this.endedAtMs = nowMs;
  }

  snapshot(nowMs: number): TimerSnapshot {
    return {
      state: this.state,
      windowElapsedMs: this.windowElapsedMs,
      activeMs: this.activeMs,
      windowsElapsed: this.windowsElapsed,
      breaksTaken: this.breaksTaken,
      nudged: this.nudgedThisWindow,
    };
  }

  sessionBounds(): { startedAtMs: number; endedAtMs: number } | null {
    if (this.startedAtMs === null || this.endedAtMs === null) return null;
    return { startedAtMs: this.startedAtMs, endedAtMs: this.endedAtMs };
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test`
Expected: PASS — all TimerMachine cases green.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/Tomato && git add src/core/timer.ts src/core/timer.test.ts
git commit -m "feat(tomato-applet): pure timer state machine with soft window nudges"
```

---

### Task 6: JSON-RPC client for `tomato.recordSession` (pure)

**Files:**
- Create: `src/core/rpc.ts`
- Create: `src/core/rpc.test.ts`

**Interfaces:**
- Consumes: a `FetchFn = (url: string, init: { method: string; headers: Record<string,string>; body: string }) => Promise<{ ok: boolean; status: number; json: () => Promise<any> }>` (an injectable subset of `fetch`).
- Produces:
  - `interface KanboardCreds { baseUrl: string; username: string; token: string }`
  - `interface SessionPayload { client_session_id: string; machine_id: string; machine_label: string; started_at: number; ended_at: number; active_seconds: number; windows_elapsed: number; breaks_taken: number; app_version: string }`
  - `async function recordSession(payload: SessionPayload, creds: KanboardCreds, fetchFn: FetchFn): Promise<{ ok: true; session_id: number }>` — builds the JSON-RPC 2.0 envelope for method `tomato.recordSession`, sets `Authorization: Basic base64(username:token)` and `Content-Type: application/json`, POSTs to `${baseUrl}/jsonrpc.php`, and returns `result`. Throws `RpcError` on HTTP failure or a JSON-RPC `error` object.
  - `class RpcError extends Error { constructor(message: string, readonly code?: number) }`

- [ ] **Step 1: Write the failing tests**

Create `src/core/rpc.test.ts`:

```ts
import { describe, it, expect, vi } from "vitest";
import { recordSession, RpcError, type SessionPayload, type KanboardCreds } from "./rpc";

const creds: KanboardCreds = { baseUrl: "https://kb.example.com", username: "carmelo", token: "tok123" };
const payload: SessionPayload = {
  client_session_id: "cs-1", machine_id: "m-1", machine_label: "box",
  started_at: 1000, ended_at: 1900, active_seconds: 850,
  windows_elapsed: 1, breaks_taken: 1, app_version: "1.0.0",
};

function fakeFetch(response: any, ok = true, status = 200) {
  return vi.fn(async (_url: string, _init: any) => ({
    ok, status, json: async () => response,
  }));
}

describe("recordSession", () => {
  it("posts a JSON-RPC envelope with Basic auth and returns result", async () => {
    const f = fakeFetch({ jsonrpc: "2.0", id: 1, result: { ok: true, session_id: 5 } });
    const res = await recordSession(payload, creds, f);
    expect(res).toEqual({ ok: true, session_id: 5 });

    const [url, init] = f.mock.calls[0];
    expect(url).toBe("https://kb.example.com/jsonrpc.php");
    expect(init.method).toBe("POST");
    expect(init.headers["Authorization"]).toBe("Basic " + btoa("carmelo:tok123"));
    const body = JSON.parse(init.body);
    expect(body.method).toBe("tomato.recordSession");
    expect(body.params.client_session_id).toBe("cs-1");
  });

  it("throws RpcError on a JSON-RPC error object", async () => {
    const f = fakeFetch({ jsonrpc: "2.0", id: 1, error: { code: -32000, message: "boom" } });
    await expect(recordSession(payload, creds, f)).rejects.toBeInstanceOf(RpcError);
  });

  it("throws RpcError on HTTP failure", async () => {
    const f = fakeFetch({}, false, 401);
    await expect(recordSession(payload, creds, f)).rejects.toBeInstanceOf(RpcError);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./rpc`.

- [ ] **Step 3: Implement `src/core/rpc.ts`**

```ts
export interface KanboardCreds {
  baseUrl: string;
  username: string;
  token: string;
}

export interface SessionPayload {
  client_session_id: string;
  machine_id: string;
  machine_label: string;
  started_at: number;
  ended_at: number;
  active_seconds: number;
  windows_elapsed: number;
  breaks_taken: number;
  app_version: string;
}

export type FetchFn = (
  url: string,
  init: { method: string; headers: Record<string, string>; body: string }
) => Promise<{ ok: boolean; status: number; json: () => Promise<any> }>;

export class RpcError extends Error {
  constructor(message: string, readonly code?: number) {
    super(message);
    this.name = "RpcError";
  }
}

export async function recordSession(
  payload: SessionPayload,
  creds: KanboardCreds,
  fetchFn: FetchFn
): Promise<{ ok: true; session_id: number }> {
  const body = JSON.stringify({
    jsonrpc: "2.0",
    id: 1,
    method: "tomato.recordSession",
    params: payload,
  });

  const res = await fetchFn(`${creds.baseUrl}/jsonrpc.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: "Basic " + btoa(`${creds.username}:${creds.token}`),
    },
    body,
  });

  if (!res.ok) {
    throw new RpcError(`HTTP ${res.status} from Kanboard`, res.status);
  }

  const json = await res.json();
  if (json.error) {
    throw new RpcError(json.error.message ?? "JSON-RPC error", json.error.code);
  }
  return json.result;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test`
Expected: PASS — all recordSession cases green.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/Tomato && git add src/core/rpc.ts src/core/rpc.test.ts
git commit -m "feat(tomato-applet): JSON-RPC client for tomato.recordSession"
```

---

### Task 7: Offline outbox + retry (pure)

**Files:**
- Create: `src/core/outbox.ts`
- Create: `src/core/outbox.test.ts`

**Interfaces:**
- Consumes: `SessionPayload` (Task 6);
  `interface OutboxStore { all(): Promise<SessionPayload[]>; add(p: SessionPayload): Promise<void>; remove(clientSessionId: string): Promise<void> }` (implemented in-memory for tests; SQLite-backed in Task 10);
  `type Sender = (p: SessionPayload) => Promise<void>` (throws to signal a failed send — wraps `recordSession`).
- Produces:
  - `class Outbox`:
    - `constructor(store: OutboxStore)`
    - `enqueue(p: SessionPayload): Promise<void>` — persists a pending session (idempotent on `client_session_id`).
    - `flush(send: Sender): Promise<{ sent: number; remaining: number }>` — attempts each pending payload; removes those that send successfully; stops early and keeps the rest on the first failure (they retry on the next flush). Never throws.

- [ ] **Step 1: Write the failing tests**

Create `src/core/outbox.test.ts`:

```ts
import { describe, it, expect, vi } from "vitest";
import { Outbox, type OutboxStore } from "./outbox";
import type { SessionPayload } from "./rpc";

function mkPayload(id: string): SessionPayload {
  return {
    client_session_id: id, machine_id: "m", machine_label: "",
    started_at: 0, ended_at: 1, active_seconds: 1,
    windows_elapsed: 0, breaks_taken: 0, app_version: "1.0.0",
  };
}

function memStore(): OutboxStore {
  let rows: SessionPayload[] = [];
  return {
    all: async () => [...rows],
    add: async (p) => { if (!rows.some((r) => r.client_session_id === p.client_session_id)) rows.push(p); },
    remove: async (id) => { rows = rows.filter((r) => r.client_session_id !== id); },
  };
}

describe("Outbox", () => {
  it("enqueue is idempotent on client_session_id", async () => {
    const store = memStore();
    const box = new Outbox(store);
    await box.enqueue(mkPayload("a"));
    await box.enqueue(mkPayload("a"));
    expect((await store.all()).length).toBe(1);
  });

  it("flush sends all pending and clears them on success", async () => {
    const store = memStore();
    const box = new Outbox(store);
    await box.enqueue(mkPayload("a"));
    await box.enqueue(mkPayload("b"));
    const send = vi.fn(async (_p: SessionPayload) => {});
    const res = await box.flush(send);
    expect(res).toEqual({ sent: 2, remaining: 0 });
    expect((await store.all()).length).toBe(0);
  });

  it("flush keeps payloads that fail to send and does not throw", async () => {
    const store = memStore();
    const box = new Outbox(store);
    await box.enqueue(mkPayload("a"));
    await box.enqueue(mkPayload("b"));
    const send = vi.fn(async (_p: SessionPayload) => { throw new Error("offline"); });
    const res = await box.flush(send);
    expect(res.sent).toBe(0);
    expect(res.remaining).toBe(2);
    expect((await store.all()).length).toBe(2);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./outbox`.

- [ ] **Step 3: Implement `src/core/outbox.ts`**

```ts
import type { SessionPayload } from "./rpc";

export interface OutboxStore {
  all(): Promise<SessionPayload[]>;
  add(p: SessionPayload): Promise<void>;
  remove(clientSessionId: string): Promise<void>;
}

export type Sender = (p: SessionPayload) => Promise<void>;

export class Outbox {
  constructor(private readonly store: OutboxStore) {}

  async enqueue(p: SessionPayload): Promise<void> {
    await this.store.add(p);
  }

  async flush(send: Sender): Promise<{ sent: number; remaining: number }> {
    const pending = await this.store.all();
    let sent = 0;
    for (const p of pending) {
      try {
        await send(p);
        await this.store.remove(p.client_session_id);
        sent += 1;
      } catch {
        break; // keep this and the rest for the next flush
      }
    }
    const remaining = (await this.store.all()).length;
    return { sent, remaining };
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test`
Expected: PASS — all Outbox cases green.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/Tomato && git add src/core/outbox.ts src/core/outbox.test.ts
git commit -m "feat(tomato-applet): offline outbox with retry-on-next-flush"
```

---

### Task 8: Tray ring progress (pure)

**Files:**
- Create: `src/core/ring.ts`
- Create: `src/core/ring.test.ts`

**Interfaces:**
- Produces:
  - `function ringProgress(windowElapsedMs: number, windowMs: number): number` — fraction in `[0, 1]`; `0` when `windowMs <= 0`; clamps above 1 back to 1 (over-run shows a full ring).
  - `function ringColor(progress: number): "green" | "amber" | "red"` — `< 0.7` green, `< 1` amber, `>= 1` red (drives the icon tint so an over-run window reads at a glance).

- [ ] **Step 1: Write the failing tests**

Create `src/core/ring.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { ringProgress, ringColor } from "./ring";

describe("ringProgress", () => {
  it("is 0 at start and 0.5 at half a window", () => {
    expect(ringProgress(0, 1000)).toBe(0);
    expect(ringProgress(500, 1000)).toBe(0.5);
  });
  it("clamps to 1 on over-run and guards windowMs<=0", () => {
    expect(ringProgress(2000, 1000)).toBe(1);
    expect(ringProgress(10, 0)).toBe(0);
  });
});

describe("ringColor", () => {
  it("maps progress to green/amber/red", () => {
    expect(ringColor(0.1)).toBe("green");
    expect(ringColor(0.8)).toBe("amber");
    expect(ringColor(1)).toBe("red");
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./ring`.

- [ ] **Step 3: Implement `src/core/ring.ts`**

```ts
export function ringProgress(windowElapsedMs: number, windowMs: number): number {
  if (windowMs <= 0) return 0;
  const p = windowElapsedMs / windowMs;
  if (p < 0) return 0;
  if (p > 1) return 1;
  return p;
}

export function ringColor(progress: number): "green" | "amber" | "red" {
  if (progress >= 1) return "red";
  if (progress >= 0.7) return "amber";
  return "green";
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/Tomato && git add src/core/ring.ts src/core/ring.test.ts
git commit -m "feat(tomato-applet): tray ring progress + color mapping"
```

---

### Task 9: DND-aware nudge decision (pure)

**Files:**
- Create: `src/core/nudge.ts`
- Create: `src/core/nudge.test.ts`

**Interfaces:**
- Produces:
  - `type DndState = "active" | "inactive" | "unknown"`
  - `type NudgeKind = "card" | "card-quiet" | "notification"`
  - `function decideNudge(dnd: DndState): NudgeKind` — `inactive → "card"` (assertive break card), `active → "notification"` (respect Focus; let the OS decide via a time-sensitive notice), `unknown → "card-quiet"` (show the card without stealing focus or sound).
  - `interface DndDetector { current(): Promise<DndState> }` — the interface the native layer (Task 10) implements; declared here so the pure decision and its consumer share one type.

- [ ] **Step 1: Write the failing tests**

Create `src/core/nudge.test.ts`:

```ts
import { describe, it, expect } from "vitest";
import { decideNudge } from "./nudge";

describe("decideNudge", () => {
  it("shows the assertive card when not in DND", () => {
    expect(decideNudge("inactive")).toBe("card");
  });
  it("falls back to a notification under DND/Focus", () => {
    expect(decideNudge("active")).toBe("notification");
  });
  it("shows a quiet card when DND state is unknown", () => {
    expect(decideNudge("unknown")).toBe("card-quiet");
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test`
Expected: FAIL — cannot resolve `./nudge`.

- [ ] **Step 3: Implement `src/core/nudge.ts`**

```ts
export type DndState = "active" | "inactive" | "unknown";
export type NudgeKind = "card" | "card-quiet" | "notification";

export interface DndDetector {
  current(): Promise<DndState>;
}

export function decideNudge(dnd: DndState): NudgeKind {
  switch (dnd) {
    case "inactive":
      return "card";
    case "active":
      return "notification";
    case "unknown":
    default:
      return "card-quiet";
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/Tomato && git add src/core/nudge.ts src/core/nudge.test.ts
git commit -m "feat(tomato-applet): DND-aware nudge decision"
```

---

### Task 10: Native wiring (tray, break card, notification, keychain, SQLite store, DND, config)

This task has no unit tests — it wires the pure modules to Tauri v2 and the OS. Verify it by building and running on Linux (Task 11 is the full end-to-end check). Keep every OS touchpoint behind the interfaces already defined so nothing here re-implements business logic.

**Files:**
- Create: `src/native/dnd.ts` (implements `DndDetector` via a Rust command)
- Create: `src/native/store.ts` (implements `OutboxStore` via `@tauri-apps/plugin-sql`)
- Create: `src/native/creds.ts` (keychain get/set via Rust commands)
- Create: `src/native/config.ts` (non-secret config via `@tauri-apps/plugin-store`)
- Create: `src/native/trayIcon.ts` (render ring → RGBA via OffscreenCanvas)
- Create: `src/main.ts` (wire tray menu, timer loop, nudge, outbox flush)
- Modify: `src-tauri/src/lib.rs` (register `store_token`, `get_token`, `dnd_state` commands)
- Modify: `src-tauri/Cargo.toml` (add `keyring` crate)
- Modify: `src-tauri/tauri.conf.json` and `src-tauri/capabilities/default.json` (tray, notification, sql, store permissions)
- Modify: `package.json` (add `@tauri-apps/plugin-sql`, `@tauri-apps/plugin-notification`, `@tauri-apps/plugin-store`)

**Interfaces:**
- Consumes: `TimerMachine`, `recordSession`, `Outbox`/`OutboxStore`, `ringProgress`/`ringColor`, `decideNudge`/`DndDetector`, `SessionPayload`, `KanboardCreds`, `APP_VERSION`.
- Produces: a running applet. No new exported types other than concrete implementations of `DndDetector` and `OutboxStore`.

- [ ] **Step 1: Add JS plugin deps and Rust keyring crate**

Run:
```bash
cd ~/Projects/Tomato
pnpm add @tauri-apps/plugin-sql @tauri-apps/plugin-notification @tauri-apps/plugin-store
cargo add --manifest-path src-tauri/Cargo.toml keyring@3
cargo add --manifest-path src-tauri/Cargo.toml tauri-plugin-sql --features sqlite
cargo add --manifest-path src-tauri/Cargo.toml tauri-plugin-notification
cargo add --manifest-path src-tauri/Cargo.toml tauri-plugin-store
```

- [ ] **Step 2: Rust commands for keychain + DND**

In `src-tauri/src/lib.rs`, add:

```rust
use keyring::Entry;

const KEYRING_SERVICE: &str = "tomato-applet";

#[tauri::command]
fn store_token(username: String, token: String) -> Result<(), String> {
    let entry = Entry::new(KEYRING_SERVICE, &username).map_err(|e| e.to_string())?;
    entry.set_password(&token).map_err(|e| e.to_string())
}

#[tauri::command]
fn get_token(username: String) -> Result<String, String> {
    let entry = Entry::new(KEYRING_SERVICE, &username).map_err(|e| e.to_string())?;
    entry.get_password().map_err(|e| e.to_string())
}

/// Best-effort DND: GNOME exposes show-banners=false when in Do Not Disturb.
/// Returns "active" | "inactive" | "unknown".
#[tauri::command]
fn dnd_state() -> String {
    use std::process::Command;
    let out = Command::new("gsettings")
        .args(["get", "org.gnome.desktop.notifications", "show-banners"])
        .output();
    match out {
        Ok(o) if o.status.success() => {
            let v = String::from_utf8_lossy(&o.stdout);
            if v.trim() == "false" { "active".into() }
            else if v.trim() == "true" { "inactive".into() }
            else { "unknown".into() }
        }
        _ => "unknown".into(),
    }
}
```

Register them in the builder (inside `run()`), alongside the plugins:

```rust
tauri::Builder::default()
    .plugin(tauri_plugin_sql::Builder::default().build())
    .plugin(tauri_plugin_notification::init())
    .plugin(tauri_plugin_store::Builder::default().build())
    .invoke_handler(tauri::generate_handler![store_token, get_token, dnd_state])
    // ...existing setup...
    .run(tauri::generate_context!())
    .expect("error while running tauri application");
```

- [ ] **Step 3: Native `DndDetector` (`src/native/dnd.ts`)**

```ts
import { invoke } from "@tauri-apps/api/core";
import type { DndDetector, DndState } from "../core/nudge";

export const nativeDnd: DndDetector = {
  async current(): Promise<DndState> {
    try {
      const s = await invoke<string>("dnd_state");
      return s === "active" || s === "inactive" ? s : "unknown";
    } catch {
      return "unknown";
    }
  },
};
```

- [ ] **Step 4: Native `OutboxStore` over SQLite (`src/native/store.ts`)**

```ts
import Database from "@tauri-apps/plugin-sql";
import type { OutboxStore } from "../core/outbox";
import type { SessionPayload } from "../core/rpc";

let dbp: Promise<Database> | null = null;
function db(): Promise<Database> {
  if (!dbp) {
    dbp = Database.load("sqlite:tomato.db").then(async (d) => {
      await d.execute(`CREATE TABLE IF NOT EXISTS outbox (
        client_session_id TEXT PRIMARY KEY,
        payload TEXT NOT NULL
      )`);
      return d;
    });
  }
  return dbp;
}

export const sqliteOutboxStore: OutboxStore = {
  async all() {
    const d = await db();
    const rows = await d.select<{ payload: string }[]>("SELECT payload FROM outbox");
    return rows.map((r) => JSON.parse(r.payload) as SessionPayload);
  },
  async add(p) {
    const d = await db();
    await d.execute(
      "INSERT OR IGNORE INTO outbox (client_session_id, payload) VALUES ($1, $2)",
      [p.client_session_id, JSON.stringify(p)]
    );
  },
  async remove(clientSessionId) {
    const d = await db();
    await d.execute("DELETE FROM outbox WHERE client_session_id = $1", [clientSessionId]);
  },
};
```

- [ ] **Step 5: Credentials + config helpers**

`src/native/creds.ts`:

```ts
import { invoke } from "@tauri-apps/api/core";

export async function storeToken(username: string, token: string): Promise<void> {
  await invoke("store_token", { username, token });
}
export async function getToken(username: string): Promise<string> {
  return invoke<string>("get_token", { username });
}
```

`src/native/config.ts`:

```ts
import { load } from "@tauri-apps/plugin-store";

export interface TomatoConfig {
  baseUrl: string;
  username: string;
  machineId: string;
  machineLabel: string;
  windowMs: number;
  breakMs: number;
}

const DEFAULTS: Omit<TomatoConfig, "baseUrl" | "username" | "machineId" | "machineLabel"> = {
  windowMs: 15 * 60_000,
  breakMs: 3 * 60_000,
};

export async function loadConfig(): Promise<Partial<TomatoConfig> & typeof DEFAULTS> {
  const store = await load("tomato-config.json", { autoSave: true });
  const raw = ((await store.get("config")) as Partial<TomatoConfig>) ?? {};
  return { ...DEFAULTS, ...raw };
}

export async function saveConfig(cfg: TomatoConfig): Promise<void> {
  const store = await load("tomato-config.json", { autoSave: true });
  await store.set("config", cfg);
}
```

- [ ] **Step 6: Tray ring renderer (`src/native/trayIcon.ts`)**

```ts
import { ringColor } from "../core/ring";

const HEX = { green: "#3fb950", amber: "#d29922", red: "#f85149" } as const;
const SIZE = 32;

/** Render a progress ring to RGBA bytes for Tauri's tray Image. */
export function renderRing(progress: number): { rgba: Uint8Array; width: number; height: number } {
  const canvas = new OffscreenCanvas(SIZE, SIZE);
  const ctx = canvas.getContext("2d")!;
  const cx = SIZE / 2, cy = SIZE / 2, r = SIZE / 2 - 4;

  ctx.clearRect(0, 0, SIZE, SIZE);
  ctx.lineWidth = 4;
  ctx.strokeStyle = "rgba(255,255,255,0.25)";
  ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();

  ctx.strokeStyle = HEX[ringColor(progress)];
  ctx.beginPath();
  ctx.arc(cx, cy, r, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * progress);
  ctx.stroke();

  const data = ctx.getImageData(0, 0, SIZE, SIZE).data;
  return { rgba: new Uint8Array(data.buffer), width: SIZE, height: SIZE };
}
```

- [ ] **Step 7: Wire it all in `src/main.ts`**

Create the tray, drive the timer on a 1s interval, update the icon, react to nudge events, and flush the outbox. Uses `crypto.randomUUID()` for `client_session_id` / `machine_id`.

```ts
import { TrayIcon } from "@tauri-apps/api/tray";
import { Menu } from "@tauri-apps/api/menu";
import { Image } from "@tauri-apps/api/image";
import { sendNotification, isPermissionGranted, requestPermission } from "@tauri-apps/plugin-notification";
import { TimerMachine } from "./core/timer";
import { ringProgress } from "./core/ring";
import { decideNudge } from "./core/nudge";
import { Outbox } from "./core/outbox";
import { recordSession, type SessionPayload, type KanboardCreds } from "./core/rpc";
import { APP_VERSION } from "./core/version";
import { nativeDnd } from "./native/dnd";
import { sqliteOutboxStore } from "./native/store";
import { renderRing } from "./native/trayIcon";
import { loadConfig } from "./native/config";
import { getToken } from "./native/creds";

async function main() {
  const cfg = await loadConfig();
  const machineId = cfg.machineId ?? crypto.randomUUID();
  const timer = new TimerMachine({ windowMs: cfg.windowMs, breakMs: cfg.breakMs });
  const outbox = new Outbox(sqliteOutboxStore);

  const menu = await Menu.new({
    items: [
      { id: "start", text: "Start", action: () => timer.start(Date.now()) },
      { id: "stop", text: "Stop", action: () => onStop() },
      { id: "quit", text: "Quit", action: () => { /* app exit */ } },
    ],
  });

  const tray = await TrayIcon.new({ menu, tooltip: "Tomato" });

  async function refreshIcon() {
    const snap = timer.snapshot(Date.now());
    const { rgba, width, height } = renderRing(ringProgress(snap.windowElapsedMs, cfg.windowMs));
    await tray.setIcon(await Image.new(rgba, width, height));
  }

  async function onNudge() {
    const kind = decideNudge(await nativeDnd.current());
    if (kind === "notification") {
      if (!(await isPermissionGranted())) await requestPermission();
      await sendNotification({ title: "Tomato", body: "Time to break." });
    } else {
      // "card" / "card-quiet": show a break-card window (WebviewWindow),
      // focusing it only when kind === "card". Window creation omitted here;
      // see the break-card window in src/break-card.html.
      await sendNotification({ title: "Tomato", body: "Time to break." });
    }
  }

  async function onStop() {
    timer.stop(Date.now());
    const bounds = timer.sessionBounds();
    if (!bounds) return;
    const snap = timer.snapshot(Date.now());
    const payload: SessionPayload = {
      client_session_id: crypto.randomUUID(),
      machine_id: machineId,
      machine_label: cfg.machineLabel ?? "",
      started_at: Math.floor(bounds.startedAtMs / 1000),
      ended_at: Math.floor(bounds.endedAtMs / 1000),
      active_seconds: Math.floor(snap.activeMs / 1000),
      windows_elapsed: snap.windowsElapsed,
      breaks_taken: snap.breaksTaken,
      app_version: APP_VERSION,
    };
    await outbox.enqueue(payload);
    await flush();
  }

  async function flush() {
    if (!cfg.baseUrl || !cfg.username) return;
    const token = await getToken(cfg.username).catch(() => "");
    if (!token) return;
    const creds: KanboardCreds = { baseUrl: cfg.baseUrl, username: cfg.username, token };
    await outbox.flush((p) => recordSession(p, creds, fetch as any).then(() => undefined));
  }

  setInterval(async () => {
    const events = timer.tick(Date.now());
    if (events.some((e) => e.type === "nudge")) await onNudge();
    await refreshIcon();
  }, 1000);

  await refreshIcon();
  await flush(); // drain anything queued from a previous run
}

main();
```

Note: the assertive **break-card window** (a small always-on-top `WebviewWindow` loading `src/break-card.html` that counts the break down and calls `timer.beginBreak`/`endBreak`) is created inside `onNudge` for the `card`/`card-quiet` branches; wire `alwaysOnTop: true` and `focus: kind === "card"`. Build the HTML/JS for it here and keep its logic minimal (display + two buttons that invoke the timer via Tauri events).

- [ ] **Step 8: Permissions + capabilities**

In `src-tauri/capabilities/default.json`, add to `permissions`: `"core:tray:default"`, `"notification:default"`, `"sql:default"`, `"sql:allow-execute"`, `"sql:allow-select"`, `"sql:allow-load"`, `"store:default"`. Ensure `src-tauri/tauri.conf.json` declares the app as a tray app (no main window shown on launch; set the main window `"visible": false`).

- [ ] **Step 9: Build and launch on Linux**

Run:
```bash
cd ~/Projects/Tomato && pnpm tauri dev
```
Expected: the app launches to the system tray (no main window); a ring icon appears; the tray menu shows Start / Stop / Quit. (Full behavior is verified in Task 11.) Install tray prerequisites if the icon is missing: `libayatana-appindicator3-1` (Debian/Ubuntu).

- [ ] **Step 10: Confirm the pure suite is still green**

Run: `pnpm test`
Expected: PASS — Task 5–9 tests unaffected by native wiring.

- [ ] **Step 11: Commit**

```bash
cd ~/Projects/Tomato && git add -A
git commit -m "feat(tomato-applet): native wiring — tray ring, DND nudge, keychain, SQLite outbox"
```

---

# Phase 3 — End-to-end verification

### Task 11: End-to-end walking-skeleton verification (manual)

**Files:** none (verification only). Capture evidence (terminal output + a screenshot of the tray/break card) in the commit message or a short `docs/verification-2026-08-23.md` in the applet repo.

**Interfaces:** exercises the whole pipe: applet timer → nudge → outbox → `tomato.recordSession` → `tomato_session` row.

- [ ] **Step 1: Point the applet at the dev stack as a real user**

Mint (or reuse) a personal API token for a Kanboard user on the `:8081` dev stack (Settings → API). Configure the applet: set `baseUrl=http://localhost:8081`, `username=<that user>`, a `machineLabel`, and store the token in the keychain (via the app's settings flow or a one-off `store_token` invoke). For a fast test, temporarily set `windowMs` and `breakMs` to ~`5_000` in the config so a window elapses in seconds.

- [ ] **Step 2: Run a full session**

Launch `pnpm tauri dev`. Click **Start**. Watch the tray ring fill; at the window boundary confirm the nudge appears (break card when not in DND). Toggle GNOME Do Not Disturb on and run another window; confirm it degrades to an OS notification. Click **Stop**.

- [ ] **Step 3: Confirm the row landed, attributed to the user**

Run (adjust DB path/driver for the container):
```bash
docker exec kb-suite sh -c 'sqlite3 /var/www/app/data/db.sqlite "SELECT id,user_id,machine_label,active_seconds,windows_elapsed,breaks_taken FROM tomato_session ORDER BY id DESC LIMIT 3;"'
```
Expected: a fresh row whose `user_id` matches the token's user and whose counts match the session you just ran.

- [ ] **Step 4: Confirm offline resilience**

Stop the Kanboard container (or set an unreachable `baseUrl`), run and Stop a session, confirm it stays queued (`SELECT count(*) FROM outbox` in `tomato.db` > 0 and no throw), then restore Kanboard and confirm the next Stop/launch flush drains it and inserts the row (idempotently — re-flushing does not duplicate).

- [ ] **Step 5: Record evidence + commit**

```bash
cd ~/Projects/Tomato && git add docs/verification-2026-08-23.md 2>/dev/null; git commit --allow-empty -m "test(tomato): end-to-end walking-skeleton verified on Linux"
```

---

## Self-Review notes (for the plan author)

- **Spec coverage:** JSON-RPC transport + per-user auth (Tasks 3, 6, 11); `tomato_session` schema + fields (Task 2); idempotency via `client_session_id` (Tasks 2, 3, 7); continuous session + soft nudge model (Task 5); DND-aware nudge (Tasks 9, 10); tray ring (Tasks 8, 10); offline outbox + keychain (Tasks 7, 10); Linux-only verification, macOS deferred (Task 11 / out of scope); single profile per install (config in Task 10). All spec §sections map to a task.
- **Out-of-scope guardrails held:** no activity/idle collectors, no token/AI usage, no multi-profile, no reporting, no Kensho, no macOS packaging.
- **Type consistency:** `SessionPayload` (rpc.ts) is the single payload type consumed by outbox.ts, store.ts, and main.ts; `DndDetector`/`DndState` defined once in nudge.ts and implemented in dnd.ts; `OutboxStore` defined in outbox.ts and implemented in store.ts; procedure params in Task 3 match the `params` object built in Task 6 and the columns in Task 2.
- **Naming reconciliation:** the JSON-RPC method is `tomato.recordSession` (registered via `withClassAndMethod` with an instance so the container is injected and the dotted name is preserved); the PHP method is `recordSession`.
