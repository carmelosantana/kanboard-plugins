# Git hosting P1 prototype Implementation Plan

**Goal:** Stand up the P1 prototype (Forgejo 15 LTS + Rust broker + GitHost plugin + Authentik + Traefik ForwardAuth + one-job runners) on the :8081 dev stack, pass the nine-step scenario, and record the metrics that feed the MVP spec.
**Method:** SDD. Implementer = Opus, reviewer = Opus (each task and the whole branch). Pass `model:` explicitly on every dispatch. Never Haiku.
**Spec:** docs/specs/2026-11-17-git-hosting-p1-design.md
**Architecture:**
- **GitHost plugin** (new repo `kanboard-githost`, checked out at `GitHost/`, ignored by this harness): repo ↔ project table, the HMAC-only authz and state routes, `getGitCredential`, the Repositories tab, the task panel. Holds no Forgejo credential.
- **Broker** (new repo `kanboard-git-broker`, checked out at `/home/carmelo/Projects/Kanboard/kanboard-git-broker`): Rust/axum. Holds the Forgejo admin token. Answers Traefik ForwardAuth. Exposes the verbs `sync`, `repos`, `principals`, `tokens`, `reconcile`. Writes the audit JSONL. Ships the `git-credential-kanboard` subcommand.
- **Harness** (this repo): `testing/docker-compose.git-overlay.yml` and `testing/git/` (Traefik config, Authentik blueprints, HMAC test vectors, `scenario.sh`).

## Ground rules for every task

- **Repos:**
  - Plugin work commits in `GitHost/`, broker work in the broker repo, and harness work in this repo.
  - Other sessions share this checkout. Commit only your own files, and never switch branches or stash.
  - `.gitignore` here has another session's uncommitted edits. Add `/GitHost/` with a one-line `git add -p` hunk.
- **Dependencies:** run `/powerup:supply-chain` before adding any crate, Composer package, container image or third-party Kanboard plugin. Pin exact versions, and pin image digests.
- **Dev only:** never use the Kanboard MCP tools, which point at production. Drive :8081 with curl/JSON-RPC and inspect it with PDO in `kb-suite`.
- **Plugin tests:** `./testing/run-plugin-tests.sh GitHost` runs the one suite. Before calling a task done, run `./testing/run-plugin-tests.sh --all` to completion.
- **Broker tests:**
  - `cargo test` runs the unit tests.
  - `cargo test --test forgejo_it -- --ignored` runs the integration tests. These need the overlay stack up.
- **Spec amendment:** Kanboard 1.2.47 dispatches no events from `ProjectUserRoleModel`, `ProjectGroupRoleModel` or `GroupMemberModel`, so there are no membership events to hook. Push-sync therefore works two ways:
  - The broker pulls `githost/state` every 30 s.
  - A GitHost JSON-RPC middleware nudges `sync` after the membership API methods (`addProjectUser`, `removeProjectUser`, `changeProjectUserRole`, `addProjectGroup`, `removeProjectGroup`, `changeProjectGroupRole`, `addGroupMember`, `removeGroupMember`).

  Only Forgejo team state depends on push-sync. Git access never does, because the live check decides it.
- **Frozen strings:** later tasks assert these, so freeze them where they are introduced.

  | Group | Strings |
  | --- | --- |
  | Status context | `kanboard/risk-gate` |
  | Teams | `managers`, `members`, `viewers`, `reviewers`, `humans-high-risk` |
  | Org prefix | `kb-` |
  | Branch prefix | `agent/<username>/` |
  | Reviewer group | `git-reviewers` |
  | `getGitCredential` error codes | `not_member`, `task_closed`, `not_assigned`, `no_repos`, `broker_unavailable` |
  | Audit `decision` values | `allow`, `deny` |
  | Audit `reason` values | `no_principal`, `unknown_repo`, `token_expired`, `token_revoked`, `task_closed`, `kanboard_deny`, `kanboard_unreachable`, `ok` |
  | HMAC headers | `X-GitHost-Timestamp`, `X-GitHost-Nonce`, `X-GitHost-Signature` |

### Task 1: Broker skeleton that denies everything

**Files:**
- `kanboard-git-broker/Cargo.toml`
- `src/main.rs`, `src/authz.rs`
- `tests/authz_deny.rs`
- `Dockerfile` (multi-stage, distroless)
- `README.md`

1. `git init` the broker repo. Pin `axum`, `tokio`, `serde` and `tracing` after the supply-chain check.
2. Write the failing test `tests/authz_deny.rs`. For each of 5 sample ForwardAuth requests (clone, push, API GET, API POST, unknown path), `GET /authz` with the Traefik `X-Forwarded-*` headers returns 403, and `GET /healthz` returns 200.
   - It fails if `/authz` returns 200 for any input. Mutation: default branch returns `StatusCode::OK`.
3. Run `cargo test`. Expect a compile or route failure.
4. Implement the axum router: `/healthz` returns 200, `/authz` returns 403.
5. Run `cargo test`. Expect `test result: ok. 2 passed`.
6. Commit "broker: deny-all ForwardAuth skeleton".

### Task 2: Git overlay stack behind Traefik

**Files:**
- `testing/docker-compose.git-overlay.yml`
- `testing/git/traefik/dynamic.yml`
- `testing/git/forgejo/app.ini.tmpl`
- `testing/git/bootstrap.sh`, which creates the Forgejo admin and token and writes them to the broker's env file `testing/git/.env` (gitignored)
- `testing/git/scenario.sh`, with the `preflight` step only
- `.gitignore` (add `testing/git/.env`)

Services:
- `forgejo`: Forgejo 15 LTS, pinned patch tag and digest.
- `forgejo-db`: Postgres.
- `traefik`, with a ForwardAuth middleware pointing at `http://broker:8080/authz` on the repo routes the spec lists, and runner and asset routes exempt.
- `broker`: built from the broker repo path.

Forgejo settings: `DISABLE_SSH=true`, `DISABLE_REGISTRATION=true`, no published port of its own.

1. Write the failing check `scenario.sh preflight`. It asserts:
   - Forgejo is not reachable on any host port.
   - `http://git.localhost:8090/api/healthz` returns 200 through Traefik.
   - `/kb-x/r.git/info/refs?service=git-upload-pack` returns 403.
   - `/api/v1/repos/kb-x/r` returns 403.

   It fails if the ForwardAuth middleware is missing from the repo routes. Mutation: drop the middleware from the router, and the repo routes return 404 instead of 403.
2. Run `bash testing/git/scenario.sh preflight`. Expect FAIL, because the stack is not up.
3. Write the compose file, the Traefik config and `bootstrap.sh`, which is idempotent.
4. Run:
   - `docker compose -f testing/docker-compose.dev.yml -f testing/docker-compose.git-overlay.yml up -d`
   - `bash testing/git/bootstrap.sh`
   - `bash testing/git/scenario.sh preflight`

   Expect `preflight: PASS (4/4)`.
5. Record idle RAM: run `docker stats --no-stream` and append it to `testing/git/metrics.md`.
6. Commit "testing: git overlay stack behind Traefik ForwardAuth".

### Task 3: HMAC channel with shared test vectors

**Files:**
- `testing/git/hmac-vectors.json` (harness)
- broker `src/hmac.rs`, `tests/hmac_vectors.rs`
- `GitHost/Model/Hmac.php`, `GitHost/Test/HmacTest.php`
- `GitHost/plugin.json`, `GitHost/Plugin.php`: minimal, so the suite is discovered

How a request is signed:
- The signature is `hex(HMAC-SHA256(secret, ts + "\n" + nonce + "\n" + METHOD + "\n" + path + "\n" + sha256hex(body)))`.
- `ts` must be within ±60 s.
- A nonce is rejected if it was seen in the last 120 s. Nonces are stored in a bounded LRU on the broker and a `githost_nonces` table in the plugin, pruned on write.

1. Write the vectors file: 1 valid case, and invalid cases for tampered body, tampered path, wrong secret, ts +61 s, ts −61 s, and a replayed nonce. Write failing tests on both sides that load the same file.
   - They fail if the timestamp check is skipped. Mutation: `abs(now - ts) <= 60` becomes `true`.
2. Run `cargo test hmac` and `./testing/run-plugin-tests.sh GitHost`. Expect both to fail.
3. Implement both sides using constant-time compare (`hash_equals`, `subtle`).
4. Rerun. Expect every vector's verdict to match the file on both sides.
5. Commit in each repo: "hmac: signed requests with shared vectors".

### Task 4: GitHost authz route

**Files:**
- `GitHost/Plugin.php`: route `githost/authz`, `applicationAccessMap` set to `APP_PUBLIC`, HMAC enforced in the controller
- `GitHost/Controller/GitAuthzController.php`
- `GitHost/Model/AuthzPolicy.php`
- `GitHost/Test/AuthzPolicyTest.php`, `GitHost/Test/GitAuthzControllerTest.php`

This is not a JSON-RPC method. Every method on a `withObject` procedure is remotely callable (research #4880), and this route must answer only HMAC-signed broker calls.

1. Write the failing table test for `AuthzPolicy::decide(user_id, project_id, access)`:

   | Case | Result |
   | --- | --- |
   | Viewer reads | allow |
   | Viewer writes | deny |
   | Member writes | allow |
   | Manager writes | allow |
   | Member through a group writes | allow |
   | Disabled user | deny |
   | Non-member | deny |
   | Unknown project | deny |
   | Inactive project | deny |

   Write a controller test: an unsigned request returns 401, a signed one returns `{"allow":true|false}`.
   - The policy test fails if viewers get write. Mutation: `>= ROLE_VIEWER` for write.
2. Run `./testing/run-plugin-tests.sh GitHost`. Expect failures.
3. Implement it using `ProjectUserRoleModel::getUserRole` (which covers groups), `UserModel::isActive` and `ProjectModel::isActive`.
4. Rerun. Expect it to pass.
5. Commit "githost: HMAC-only authz route".

### Task 5: Repo table and state endpoint

**Files:**
- `GitHost/Schema/{Sqlite,Mysql,Postgres}.php`: `githost_repos(id, project_id, name, forgejo_full_name, created_at)`, unique on `(project_id, name)`, `forgejo_full_name` unique
- `GitHost/Model/RepoTable.php`
- `GitHost/Model/StateBuilder.php`
- `GitHost/Controller/GitStateController.php` (route `githost/state`, HMAC)
- `GitHost/Test/StateBuilderTest.php`

1. Write a failing test that `StateBuilder::build()` returns only projects with at least one repo. Each project has `{project_id, identifier, org, repos[], members:{managers[],members[],viewers[],reviewers[],humans_high_risk[]}}`. The member lists:
   - expand groups into users;
   - drop disabled users;
   - put reviewers = project members who are in the `git-reviewers` group;
   - put humans_high_risk = project managers who are not in the Agents table.

   It fails if group members are not expanded. Mutation: read only `project_has_users`.
2. Run the suite. Expect failures.
3. Implement it. `org` is `kb-` plus the lowercased identifier. A project with no identifier is excluded and logged; Task 9 sets the identifier.
4. Rerun. Expect it to pass.
5. Commit "githost: repo table and HMAC state endpoint".

### Task 6: Broker Forgejo client and `sync`

**Files:**
- broker `src/forgejo.rs`, `src/sync.rs`, `src/routes/projects.rs`
- `tests/sync_unit.rs` (mock HTTP server)
- `tests/forgejo_it.rs`

1. First verify the team endpoints against the stack's `/swagger.v1.json`: org teams create and list, team members add, remove and list. Record them in the broker README.
2. Write a failing unit test for `POST /projects/{id}/sync` (HMAC). Given a target state, it:
   - creates a missing org;
   - creates the 5 teams with permissions admin, write, read, write, write;
   - adds missing members;
   - removes extra members;
   - is a no-op on the second call (zero mutating requests).

   It fails if removals are skipped. Mutation: add-only diff.
3. Write a failing integration test against the stack: sync a project twice, then remove a member and sync again, and that member is gone from Forgejo.
4. Run `cargo test` and `cargo test --test forgejo_it -- --ignored`. Expect failures.
5. Implement it. Every admin-API call writes an audit line (`verb`, target, status).
6. Rerun. Expect both to pass.
7. Commit "broker: idempotent project sync".

### Task 7: Principals for humans and agents

**Files:**
- broker `src/principals.rs`, `src/db.rs` (SQLite `principals`, `repos`, `tokens` tables, migrations)
- `tests/principals_unit.rs`, `tests/forgejo_it.rs`

1. Write failing integration tests:
   - **Agent:** `POST /principals {kind:"agent"}` creates a Forgejo user with a random password that is never returned and web login prohibited. A token minted for that user can call `GET /api/v1/user`, but web login with the password fails.
   - **Human:** `POST /principals {kind:"human", authentik_sub}` creates a user bound to the Authentik auth source with `login_name = sub`.
   - Both tests record what Forgejo actually does in the README "Verified behaviour" table. These are spec gaps 2 and 1.
   - The agent test fails if the token stops working when login is prohibited. That is a real Forgejo behaviour to detect, not a mutation. Mutation for the unit test: the password is echoed in the response, and the test asserts it is absent.
2. Run the integration tests. Expect failures.
3. Implement them. If `prohibit_login` blocks tokens, switch to the spec's fallback and update the README table.
4. Rerun. Expect both to pass.
5. Commit "broker: provision human and agent principals".

### Task 8: Reconcile pull and API nudge

**Files:**
- broker `src/reconcile.rs` (30 s timer plus `POST /reconcile`), `tests/reconcile_unit.rs`
- `GitHost/Api/MembershipNudgeMiddleware.php`
- `GitHost/Model/BrokerClient.php` (HMAC client, 2 s timeout, never throws into Kanboard)
- `GitHost/Test/MembershipNudgeTest.php`

1. Write failing tests.
   - Broker: given a mock `githost/state` and a mock Forgejo with one injected 500 on the first `sync`, the next reconcile tick repairs it. The test asserts the final team members equal the state. It fails if errors abort the sweep. Mutation: `?` on the first project error.
   - Plugin: each of the 8 membership methods triggers exactly one `BrokerClient::nudge(project_id)` (group methods nudge every project the group is in), and a broker failure does not change the JSON-RPC result. It fails if the nudge exception propagates. Mutation: remove the try/catch.
2. Run both suites. Expect failures.
3. Implement them.
4. Rerun both. Expect them to pass.
5. Commit in each repo: "reconcile: 30 s pull and API nudge".

### Task 9: Create repos with branch protection and the Repositories tab

**Files:**
- broker `src/routes/repos.rs`, `src/protection.rs`, `tests/forgejo_it.rs`
- `GitHost/Controller/RepositoryController.php`
- `GitHost/Template/project/repositories.php`
- `GitHost/Api/GitHostProcedure.php` (`getProjectRepositories`, `createProjectRepository`)
- `GitHost/Plugin.php` (sidebar hook)
- `GitHost/Test/RepositoryProcedureTest.php`

1. Write a failing integration test: `POST /projects/{id}/repos {name}` creates `kb-<ident>/<name>` with this protection:
   - `main`: no direct push, force-push off, 1 approval from the `reviewers` allowlist, required checks = the CI workflow and `kanboard/risk-gate`, block on outdated branch.
   - One rule `agent/<username>/**` per agent, with push allowlist = that agent.
   - A catch-all rule allowing no agent pushes.

   The test then pushes as agent a1 to `agent/a1/x` (accepted), `agent/a2/x` (rejected) and `main` (rejected). Record whether Forgejo picks the most specific rule (spec gap 4). It fails if per-agent rules are omitted. Mutation: a single `agent/**` rule for `members`.
2. Write a failing plugin test: `createProjectRepository` sets a missing project identifier, calls the broker, and stores the row. Only a project manager may call it.
3. Run both. Expect failures.
4. Implement them. If rule priority cannot express the prefix rule, stop and record the spec's pre-receive fallback as an open decision for Carmelo. Do not enable git hooks silently.
5. Rerun. Expect them to pass.
6. Commit in each repo: "repos: create with branch protection".

### Task 10: Minted tokens and `getGitCredential`

**Files:**
- broker `src/routes/tokens.rs`, `tests/tokens_unit.rs`, `tests/forgejo_it.rs`
- `GitHost/Api/GitHostProcedure.php` (`getGitCredential`)
- `GitHost/Subscriber/TaskCloseSubscriber.php` (`task.close` → `DELETE /tokens?task_id=`)
- `GitHost/Test/GetGitCredentialTest.php`

1. Write the failing plugin test for `getGitCredential(task_id)`. It returns `{username, token, expires_at, repos[]}` for a member who is the assignee or a subtask assignee of an open task in a project with repos. Otherwise it returns `{error: <code>}`, using the frozen codes in this order: `not_member`, `task_closed`, `not_assigned`, `no_repos`, `broker_unavailable`.
   - It fails if a closed task still issues a token. Mutation: skip `is_active`.
2. Write a failing broker integration test (spec gap 3): `POST /tokens` creates a token that is repo-scoped to the project's repos with `write:repository` and expires in 1 h. It works on that repo and gets 403 or 404 on another project's repo. `DELETE` makes it fail immediately.
   - It fails if the scope covers all repos. Mutation: omit the repo list.
3. Run both. Expect failures.
4. Implement them. If the admin API cannot mint for another user, use `Sudo` and record it.
5. Rerun. Expect both to pass.
6. Commit in each repo: "tokens: per-task scoped git credentials".

### Task 11: Live ForwardAuth with audit

**Files:**
- broker `src/authz.rs`, `src/audit.rs`, `tests/authz_table.rs`
- `testing/git/scenario.sh` (steps `credential`, `revoke`, `outage`)

1. Write a failing table test with mocks for Kanboard and Forgejo. Each row expects the frozen `reason` and an HTTP status.

   | Case | Result |
   | --- | --- |
   | No auth header | 403 `no_principal` |
   | Unknown repo | 403 `unknown_repo` |
   | Expired token | 403 `token_expired` |
   | Revoked token | 403 `token_revoked` |
   | Task closed | 403 `task_closed` |
   | Kanboard denies | 403 `kanboard_deny` |
   | Kanboard times out after 2 s | 403 `kanboard_unreachable` |
   | Kanboard returns 500 | 403 `kanboard_unreachable` |
   | Valid agent token, read | 200 `ok` |
   | Valid agent token, `receive-pack` | 200 `ok`, with `access=write` sent to Kanboard |
   | Human Forgejo token | 200 `ok`, resolved through `GET /api/v1/user` |

   Each request writes exactly one audit line with every field in the spec. The test fails if a timeout returns allow (mutation: `Err(_) => allow`) or if push is classified as read (mutation: `access=read` for `receive-pack`).
2. Run `cargo test authz`. Expect failures.
3. Implement it with no cache. Path parsing covers the three route shapes in the spec.
4. Rerun. Expect it to pass. Then run `scenario.sh outage`, which runs `docker stop kb-suite` and then a clone, and expect the clone to be denied. After restart, expect it to be allowed.
5. Commit "broker: live ForwardAuth with audit, fail closed".

### Task 12: Credential helper and scenario steps 1, 2, 5a, 7, 8

**Files:**
- broker `src/bin/git-credential-kanboard.rs`, which reads `KB_ENDPOINT`, `KB_USER` and the API token from a 0600 file, never from argv
- `tests/helper_protocol.rs`
- `testing/git/scenario.sh` (steps 1, 2, 5a, 7, 8)

1. Write a failing protocol test: on `get` with `protocol=https host=git.localhost path=kb-x/r.git`, the helper prints `username=`, `password=` and `password_expiry_utc=`. On `store` and `erase` it prints nothing. It reads the task id from the env var `KB_TASK_ID`.
   - It fails if `password_expiry_utc` is missing. Mutation: drop that line.
2. Write the failing scenario steps:
   - 1–2: claim, credential, clone, branch, push.
   - 5a: push to another agent's prefix and to `main` is denied.
   - 7: remove the agent from the project, and the next fetch is denied. Close the task, and the token is dead.
   - 8: Kanboard down means denied.
3. Run `cargo test --bin git-credential-kanboard` and `bash testing/git/scenario.sh 1 2 5a 7 8`. Expect failures.
4. Implement it and wire up the scenario.
5. Rerun. Expect `PASS` for each step.
6. Commit in each repo: "credential helper and scenario core path".

### Task 13: Authentik identity for Kanboard and Forgejo

**Files:**
- `testing/git/authentik/blueprints/{kanboard,forgejo}.yaml`
- `testing/docker-compose.git-overlay.yml` (authentik server, worker, db, redis)
- `testing/git/scenario.sh` (step `identity`)

This task has a human step. Installing the third-party Kanboard OAuth2 plugin needs Carmelo's go-ahead after the supply-chain check. Check its version and source, then mount it the way `docker-compose.chicane-overlay.yml` mounts Chicane.

1. Write the failing `scenario.sh identity`. It:
   - creates an Authentik test user through the API;
   - has the broker pre-provision the Forgejo human;
   - logs in to Forgejo via OIDC with the flow-executor API and curl, and asserts it lands in the pre-provisioned account (same Forgejo user id);
   - logs in to Kanboard via the OAuth2 plugin and asserts `users.oauth2_user_id = sub`;
   - asserts that web login for an agent user fails.

   It fails if Forgejo auto-creates a second account. Mutation: skip pre-provisioning, and the user ids differ.
2. Run it. Expect failure.
3. Write the blueprints and configure the Forgejo OIDC source in `bootstrap.sh`. Record OAuth2 plugin compatibility with 1.2.47 (spec gap 6).
4. Rerun. Expect `identity: PASS`.
5. Commit "testing: Authentik identity for Kanboard and Forgejo".

### Task 14: Risk gate, reviewer auto-merge and auto-revert

**Files:**
- broker `src/riskgate.rs`, `src/webhooks.rs` (Forgejo webhook secret, PR and workflow_run events), `src/revert.rs`
- `tests/riskgate_unit.rs`, `tests/forgejo_it.rs`
- `testing/git/scenario.sh` (steps 3, 4, 5c, 6)

1. Write a failing unit test for the path matcher:
   - Every spec pattern matches its example: `.forgejo/workflows/ci.yml`, `.github/x`, `pnpm-lock.yaml`, `Cargo.toml`, `Dockerfile.dev`, `docker-compose.yml`, `src/auth/x.php`, `lib/security/y.rs`, `composer.json`, `package.json`, `a/b.lock`.
   - Paths listed in `.kanboard/high-risk` match, and that file is itself high-risk.
   - `src/app.rs` does not match.

   It fails if `.forgejo/**` is dropped (mutation). The status context is exactly `kanboard/risk-gate`.
2. Write failing scenario steps:
   - 3: reviewer agent r1 approves and schedules auto-merge, and the PR merges on green.
   - 4: a workflow change stays pending on risk-gate until a `humans-high-risk` member approves.
   - 5c: force-push to `main` is denied.
   - 6: a red `main` gets a revert PR that then merges.
3. Run them. Expect failures.
4. Implement them. The risk-gate status is written with the broker's admin identity, which CI tokens cannot use.
5. Rerun. Expect it to pass.
6. Commit "broker: risk gate, auto-merge, auto-revert".

### Task 15: Task panel for branches, PRs and CI

**Files:**
- broker `src/webhooks.rs` (POST signed status to `githost/task-status`)
- `GitHost/Controller/TaskStatusController.php` (HMAC)
- `GitHost/Template/task/panel.php`
- `GitHost/Plugin.php` (`template:task:sidebar` hook)
- `GitHost/Test/TaskStatusTest.php`

1. Write a failing plugin test: a signed status for branch `agent/a1/42-fix` writes task 42's metadata (`githost_branch`, `githost_pr_url`, `githost_pr_state`, `githost_ci`). The panel renders the deep links. A branch whose task id is in another project is ignored.
   - It fails if the project check is skipped. Mutation: write to any task id.
2. Run the suite. Expect failure.
3. Implement it.
4. Rerun. Expect it to pass.
5. Commit "githost: task panel for branch, PR and CI".

### Task 16: CI runners per isolation option, with no secrets on agent branches

**Files:**
- `testing/git/runners/{b-rootless-gvisor,a-vm-gvisor,c-kvm}/` (compose or systemd plus `runner.yaml`)
- `testing/git/fixtures/ci.yml`
- `testing/git/fixtures/leak-secrets.yml`
- `testing/git/scenario.sh` (step 5d, `coldstart`)

Human steps:
- Option (a) needs Carmelo to provide a VM.
- Option (c) runs only if `test -e /dev/kvm` passes on the target. Record the result for the production VPS type.

1. Write the failing step 5d. On an `agent/*` branch, `leak-secrets.yml` echoes every Actions secret and variable. The step asserts the job log contains none of the test secret values defined at environment level for `main`. Also assert that the job's cgroup limits read CPU 2, memory 4G and pids 1024, and that `curl https://example.com` fails while the registry proxy is reachable.
   - It fails if the secret is defined at repo level (mutation in the bootstrap), so the step proves scoping (spec gap 5).
2. Run `bash testing/git/scenario.sh 5d`. Expect failure.
3. Implement the runners in this order: (b), then (a), then (c). Each is `forgejo-runner one-job` with `runtime: runsc`, or the Kata or Firecracker runtime for (c).
4. Rerun 5d per option. Expect `PASS`. Run `coldstart` (20 runs per option) and append p50/p95 to `testing/git/metrics.md`.
5. Commit "testing: one-job runners per isolation option".

### Task 17: Load and measurements

**Files:**
- `testing/git/load.sh`, which runs N concurrent agents, each doing credential → clone → commit → push
- `testing/git/metrics.md`
- `testing/git/scenario.sh` (step 9)

1. Write the failing step 9. Stop the broker's sync to Forgejo once (fault flag), change membership, re-enable it, and assert the teams match Kanboard within one 30 s tick.
2. Run it. Expect failure until the fault flag exists (`BROKER_FAULT_SYNC_ONCE=1`).
3. Implement the flag. Then run `load.sh` at 1, 10 and 50 agents, through Traefik and also directly to Forgejo on an internal port for the overhead baseline.
4. Fill the spec's metrics table in `metrics.md` with:
   - ForwardAuth p50/p95 from the audit `latency_ms`;
   - clone and push p50/p95;
   - RAM at idle and under load;
   - revocation lag for git and the web UI;
   - drift repair;
   - agent tool calls from ticket to merge;
   - lines of glue (`tokei` per repo).
5. Run `bash testing/git/scenario.sh all`. Expect all steps 1–9 `PASS`.
6. Commit "testing: P1 load runs and metrics".

### Task 18: Security review and findings

**Files:**
- `docs/research/2026-11-xx-git-hosting-p1-findings.md` (harness)
- review fixes in the owning repos

1. Run a second-model review of the broker, the GitHost plugin, the Traefik config and `bootstrap.sh` against the spec's trust boundaries:
   - `codex:rescue`, or `/code-review high` per repo, with the spec as context.
   - Ask specifically about: HMAC replay, ForwardAuth bypass routes, token scoping, the admin-token location, fail-closed paths, CI secret exposure, and agent prefix escape.
2. Fix each finding test-first: add a failing test that reproduces it, fix it, and rerun the full suites (`cargo test`, `--ignored`, `./testing/run-plugin-tests.sh --all`, `scenario.sh all`). Record any finding that is not fixed, with the reason.
3. Write the findings doc. It covers:
   - the metrics table;
   - the "Verified behaviour" answers to spec gaps 1–6;
   - the runner-isolation recommendation;
   - web-UI revocation lag and whether ForwardAuth should cover cookie sessions;
   - open decisions for the MVP spec.
4. Commit "docs: git hosting P1 findings".
