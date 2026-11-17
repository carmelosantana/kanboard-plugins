# Git hosting P1 prototype — design

Date: 2026-11-17 · Map: Kanboard #817 (dev board, project "Git Hosting") · Research:
[docs/research/2026-11-17-git-hosting.md](../research/2026-11-17-git-hosting.md),
[docs/research/2026-11-17-kanboard-orgs-sso.md](../research/2026-11-17-kanboard-orgs-sso.md)

## Why

The suite's agents work Kanboard tickets but have no git home that knows about Kanboard. The
goal is a software factory: agents claim a ticket, branch, push, get CI, get reviewed by another
agent and merge without a human approving every PR. Kanboard project membership decides who
may touch which repos. Humans set direction, handle high-risk changes and audit.

P1 is a rough prototype of the one chosen topology. It runs on the :8081 dev stack and does one
scripted end-to-end scenario, measured. Its findings feed the MVP spec. Auth and security get one
chance, so P1 proves the security properties first and the convenience features second.

## Decisions

| # | Decision | Source |
| --- | --- | --- |
| 1 | Forgejo 15 LTS sidecar is the git host. Soft Serve (archived 2026-10-26), Radicle (private-repo flaw, 2026-09), Rust forges (immature) and a bare gateway (no PR/review machinery) are out. | Kanboard #818, #819 |
| 2 | Each repo belongs to exactly one Kanboard project; a project may have many repos. Kanboard project → one Forgejo org. | Kanboard #823 |
| 3 | Authentik owns human identity (MFA, offboarding). Kanboard (OAuth2 plugin) and Forgejo both log in via Authentik OIDC, joined on `sub`. Agents are Kanboard-issued principals with no IdP account. Kanboard project membership is the only authority for repo access. | Kanboard #820 |
| 4 | Revocation takes effect on the next git operation. | Kanboard #821 |
| 5 | Agents get git access only through `getGitCredential(task_id)`: a token scoped to that project's repos, TTL 1 h, revoked when the ticket closes or the agent leaves the project. Kanboard API tokens never authenticate git. | Kanboard #822 |
| 6 | Agents push only to `agent/<username>/<task_id>-<slug>` branches; no force-push to shared branches. | Kanboard #822 |
| 7 | Risk-tiered autonomous merge: CI green + approval from a different reviewer agent merges; high-risk paths need a human; main is protected against outdated merges and auto-reverted when red. | Kanboard #822 (amendment) |
| 8 | A separate broker service holds the Forgejo admin token and exposes only narrow verbs. An in-plugin admin token is allowed only as a mutually exclusive deployment mode, never a runtime fallback. | Kanboard #828 |
| 9 | Broker is written in Rust (axum). | Kanboard #828 |
| 10 | No authz cache: every git and repo-API request is checked live against Kanboard; Kanboard unreachable ⇒ deny. | Kanboard #828 |
| 11 | P1 is HTTPS-only git. Forgejo's SSH server is disabled. | Kanboard #828 |
| 12 | Agent-branch CI gets no secrets; secret-bearing jobs run only on protected branches after merge. | Kanboard #824 |
| 13 | Runner isolation is P1's experiment axis: (a) separate VM + gVisor, (b) same host, separate rootless daemon + gVisor, (c) Firecracker/Kata where KVM exists. | Kanboard #824, #827 |
| 14 | Production shape: git server is its own Dokploy app on the Kanboard VPS, repos on a dedicated volume, nightly off-host backup. | Kanboard #825 |
| 15 | Humans use the Forgejo web UI on its own subdomain via Authentik SSO; Kanboard tasks show branch/PR/CI status and deep-link out. | Kanboard #826 |

## Changes

### Components

| Component | What it is | Where |
| --- | --- | --- |
| **GitHost plugin** | New suite plugin (buildless PHP, own repo `kanboard-githost`, ModMenu conventions). Owns repo ↔ project links, agent credential requests, membership events and the authz answer. Holds no Forgejo credentials. | `GitHost/` (ignored by this harness, like other plugins) |
| **Broker** | Rust/axum service, own repo `kanboard-git-broker`. Holds the Forgejo admin token. The only thing that talks to Forgejo's admin API. Answers Traefik ForwardAuth. | Own container |
| **Forgejo 15 LTS** | Git host, PR review UI, Actions. Postgres backend (matches the production recommendation). SSH disabled, open registration disabled, local password login disabled for non-admins. | Own container |
| **Authentik** | OIDC IdP for humans. Configured by blueprint files committed with the prototype, not by clicking. | Own containers |
| **Traefik** | Reverse proxy in front of Forgejo with a ForwardAuth middleware pointing at the broker, mirroring Dokploy's Traefik. | Own container |
| **forgejo-runner** | One-job ephemeral runners under each isolation option. | Per option (a)/(b)/(c) |
| **git-credential-kanboard** | Tiny credential helper (shell, or part of the broker binary as a subcommand) that calls `getGitCredential` and returns `username`, `password`, `password_expiry_utc` to git. | Shipped with the broker |

Dev wiring lives in the harness as `testing/docker-compose.git-overlay.yml` plus
`testing/git/` (Authentik blueprints, Traefik config, scenario script), following the existing
overlay pattern.

### Trust boundaries

- **Kanboard ↔ broker:** one shared secret per direction, HMAC-signed requests with a timestamp
  (±60 s) and nonce. Kanboard holds only the "call broker" secret; the broker holds only the
  "call Kanboard authz" secret. Neither secret grants Forgejo admin or Kanboard admin.
- **Broker ↔ Forgejo:** the Forgejo site-admin token, in the broker's environment only.
- **Broker → Kanboard:** never the Kanboard global `jsonrpc` token. The plugin exposes one
  HMAC-authenticated authz route that returns allow/deny for `(kanboard_user_id, project_id,
  access)` and nothing else.
- **Agent → Kanboard:** the agent's existing per-user API token, used only to call
  `getGitCredential`.
- **Agent → git:** only broker-minted git tokens.

### Identity

- **Principal key:** Kanboard `user_id` for everyone. Humans also carry `oauth2_user_id` (the
  Authentik `sub`). Broker table `principals(kanboard_user_id, forgejo_user_id, kind
  human|agent, authentik_sub NULL)`.
- **Humans:** the plugin asks the broker to pre-provision a Forgejo user bound to the Authentik
  auth source with `login_name = sub`, so first SSO login lands in the existing account. P1 must
  confirm Forgejo's admin API accepts `source_id` + `login_name` on create; if not, the fallback is
  Forgejo's "link account on first login" and P1 records it.
- **Agents:** the broker creates a Forgejo user per agent (roster from the Agents plugin) with a
  random, never-revealed password and web login prohibited. P1 must confirm that
  `prohibit_login` still allows token use; if it blocks tokens, P1 uses a local-auth user with an
  unknown password and records it.
- **Kanboard ↔ Authentik:** Kanboard logs humans in through the generic OAuth2 plugin against
  Authentik. P1 must confirm the plugin works on Kanboard 1.2.47. Groups sync at login only, which
  is acceptable because git authz never relies on groups synced at login.

### Mapping Kanboard to Forgejo

- Project → org named `kb-<identifier lowercased>` (projects without an identifier get one set by
  the plugin before the first repo is attached).
- Teams per org: `managers` (admin), `members` (write), `viewers` (read), `reviewers` (write,
  approval allowlist), `humans-high-risk` (write, approval allowlist for high-risk paths).
- Kanboard project-manager → `managers`; project-member → `members`; project-viewer →
  `viewers`. Membership via Kanboard groups is expanded to users.
- Reviewer agents: users in the Kanboard group `git-reviewers` who are also project members join
  `reviewers`. Humans who are project managers join `humans-high-risk`.
- Team state in Forgejo exists for the web UI and branch protection. It is never the authz
  decision for git or repo-API requests (that is the live check).

### Live authz path (decisions 4, 10, 11)

Traefik ForwardAuth on every Forgejo route that touches a repo:
`/{org}/{repo}.git/*`, `/{org}/{repo}/info/lfs/*`, `/api/v1/repos/{org}/{repo}/*`. Runner routes
(`/api/actions/*`) and static assets are exempt.

For each request the broker:

1. Parses `{org}/{repo}` and the access needed (`upload-pack`/GET = read; `receive-pack` and
   mutating API verbs = write).
2. Resolves the principal: a broker-minted token by its SHA-256 hash in the broker's
   `tokens` table; otherwise a human's Forgejo token by calling Forgejo `GET /api/v1/user` with
   it (identity lookup only; the result maps to `kanboard_user_id` via `principals`).
3. Looks up `repo → project_id` in its own table (written when the plugin attaches a repo).
4. For a minted token, also checks it is unexpired, unrevoked and that its task is open.
5. Calls the plugin's authz route live. No cache. Timeout 2 s.
6. Returns 200 (allow) or 403; on any error or timeout, 403. Writes one audit line either way.

Forgejo then re-checks its own token and team permission, so a request must pass both layers.

Web UI pages served by Forgejo session cookie are not ForwardAuth-checked in P1; their
revocation latency is the push-sync latency (event-driven, seconds). P1 measures it and the MVP
decides whether to extend ForwardAuth to cookie sessions.

### Broker verbs (called by the plugin, HMAC)

| Verb | Effect |
| --- | --- |
| `POST /projects/{id}/sync` | Create the org/teams if missing; set team membership to exactly what Kanboard says (full state in the body). Idempotent. |
| `POST /projects/{id}/repos` | Create a repo in the project's org with branch protection applied; record `repo → project`. |
| `POST /principals` | Provision/update a human or agent Forgejo user. |
| `POST /tokens` | Mint a git token for `(agent, task, project)`: a Forgejo repo-scoped token (`write:repository` on the project's repos) created via the admin API, expiry 1 h; store its hash. P1 must confirm the admin API can create a repo-scoped token for another user; if it cannot, the broker uses `Sudo` to create it as that user and records it. |
| `DELETE /tokens?task_id=` / `?user_id=&project_id=` | Revoke in Forgejo and mark revoked. |
| `POST /reconcile` | Full sweep of all projects (also runs on a 5-minute timer); reports and repairs drift. |

### GitHost plugin

- **Project settings → Repositories tab:** list, create, attach (create only in P1; importing an
  existing repo is a non-goal). Shows clone URL and Forgejo deep link.
- **JSON-RPC:** `getGitCredential(task_id)` → `{username, token, expires_at, repos[]}`; callable
  only by a project member assigned to or working that task, task open.
  `getProjectRepositories(project_id)`, `createProjectRepository(project_id, name)`.
- **Events:** task close → broker `DELETE /tokens`. Kanboard 1.2.47 dispatches no events on
  project/group membership changes or user disable (verified in core models), so team state is
  pushed by (a) a JSON-RPC middleware that nudges `sync` after the membership API methods and
  (b) the broker pulling `githost/state` every 30 s. Disabled users are denied by the live
  check. Failures are logged and left to the next pull; they never block the Kanboard action.
- **Authz route:** HMAC-only, returns `{allow: bool}` for `(user_id, project_id, access)` from
  Kanboard's project permission model (role ≥ viewer for read, ≥ member for write; disabled users
  denied).
- **Task view:** branch, PR state and CI status for branches named `agent/*/<task_id>-*`, written
  by the broker from Forgejo webhooks into task metadata; deep links to Forgejo.
- Degrades cleanly when the broker is not configured (settings show "not connected"; no
  repo features).

### Merge policy (decision 7)

Branch protection on `main`, set by the broker when creating a repo:

- Push: nobody pushes directly; merges only via PR. Force-push disabled.
- Branch pattern `agent/*`: push allowed for `members`. ForwardAuth cannot see ref names (they
  are in the request body), so the own-prefix rule (`agent/<username>/*` only) is enforced by
  Forgejo branch protection: on each sync the broker writes one rule per agent, pattern
  `agent/<username>/**`, push allowlist = that agent, plus a rule for every other branch
  pattern that allows no agent pushes. P1 must confirm Forgejo picks the most specific rule
  (rule priority). If it cannot express this, the fallback is a server-side pre-receive hook
  asking the broker `(pusher, refs[])`; that needs Forgejo's git hooks enabled for the broker
  only, which P1 records as a security trade-off rather than adopting silently.
- Required approvals: 1, from the `reviewers` team allowlist; the author cannot approve.
- Required status checks: CI workflow result and `kanboard/risk-gate`.
- Block merge on outdated branch (serialises merges in place of a merge queue).
- **`kanboard/risk-gate`:** a commit status written only by the broker on PR webhooks. It lists
  the PR's changed paths; if any match the high-risk patterns it stays `pending` until a member of
  `humans-high-risk` approves, otherwise `success`. High-risk patterns (in the broker's config):
  `.forgejo/**`, `.github/**`, `**/*.lock`, `composer.json`, `package.json`, `pnpm-lock.yaml`,
  `Cargo.toml`, `Dockerfile*`, `docker-compose*`, `**/auth/**`, `**/security/**`, plus any path
  listed in the repo's `.kanboard/high-risk` file (itself high-risk). Because CI cannot write this
  status, an agent editing workflows cannot fake it.
- **Auto-merge:** the reviewer agent approves and schedules Forgejo's "merge when checks
  succeed".
- **Auto-revert:** on a failed CI run on `main`, the broker opens a revert PR of the last merge,
  labelled for priority review, and posts a comment on the Kanboard task. The revert PR goes
  through the same gate (it is usually low-risk and merges on green).

### CI (decisions 12, 13)

- Forgejo Actions with `forgejo-runner one-job` per job, labels per isolation option.
- No repo or org Actions secrets are readable by workflows triggered from `agent/*` branches:
  secrets are defined only at environment level bound to `main`. P1 verifies Forgejo's scoping
  achieves this; if it cannot, secret-bearing workflows live only in a separate deploy repo.
- Per job: CPU 2, memory 4 GB, pids 1024, wall time 30 min; network default-deny with an
  allowlisted registry proxy (npm, Packagist, crates.io, Docker Hub mirror).
- Isolation options run the same job matrix: (a) runner on a separate VM with Docker + `runsc`;
  (b) runner on the dev host with a separate rootless Docker daemon + `runsc`; (c) Kata or
  Firecracker if `/dev/kvm` is present (checked first on the production VPS type).

### Audit

The broker writes append-only JSON lines (one file per day, shipped with the nightly backup):
`ts, principal (kanboard_user_id, kind), token_id, task_id, project_id, repo, access, decision,
reason, latency_ms, request_id`. Admin-API calls the broker makes are logged the same way with
`verb`. Forgejo's own logs are kept as-is. The plugin records credential issuance in the task's
activity stream.

### Owner's manual steps and setup cost

| Step | When | Cost |
| --- | --- | --- |
| `docker compose -f testing/docker-compose.dev.yml -f testing/docker-compose.git-overlay.yml up -d` | P1 dev | ~4 extra containers (Forgejo, Postgres, Authentik server+worker+its Postgres/Redis, broker, Traefik); est. 1.5–2.5 GB RAM, measured in P1 |
| Install the third-party Kanboard OAuth2 plugin | P1 dev and production | One plugin to keep updated |
| Provide a VM for isolation option (a) | P1 | A second small VM or VPS (~$5–20/month) for the experiment |
| Install gVisor (`runsc`) on runner hosts | P1 | Root on that host, one package |
| Check `/dev/kvm` on the production VPS plan | P1 | One command; option (c) is skipped if absent |
| Enrol in Authentik with MFA/passkey | Production | Each human, once |
| Link existing Kanboard accounts to Authentik | Production cut-over | One-time per human |
| DNS + TLS for `git.` and `auth.` subdomains | Production | Two records in Dokploy |
| Upgrades: Forgejo (LTS yearly, patches monthly), Authentik, broker, runner | Production | Standing chore |
| Verify off-host backup restores (repos + Forgejo DB + broker DB + audit) | Production | Monthly chore |

Everything else (Forgejo admin bootstrap, Authentik providers, org/team/repo creation, agent
users, tokens) is scripted or done by the broker.

## Scenario and metrics

One script, `testing/git/scenario.sh`, run against each isolation option:

1. Agent `a1` (member) claims ticket T in project P; `getGitCredential(T)`.
2. Clone P's repo with the credential helper; branch `agent/a1/T-change`; commit; push.
3. Open a PR via the Forgejo API; CI runs; reviewer agent `r1` approves and schedules auto-merge;
   PR merges on green.
4. Same flow with a change to `.forgejo/workflows/ci.yml` → merge blocked on `kanboard/risk-gate`
   until a human manager approves.
5. Push to `agent/a2/...` with `a1`'s token → denied. Push to `main` → denied. Force-push →
   denied. Agent-branch workflow that prints secrets → none available.
6. Break `main` deliberately → revert PR opened and merged.
7. Remove `a1` from P in Kanboard → the next `git fetch` is denied (no wait). Close T → its
   token stops working.
8. Stop Kanboard → every git request denied; start it → allowed again.
9. Inject a failed sync call (broker returns 500 once) → the reconcile sweep repairs drift within
   one cycle.

| Metric | Target to beat (informs MVP, not a gate) |
| --- | --- |
| ForwardAuth decision latency p50/p95 | p95 < 50 ms |
| Clone / push latency p50/p95 at 1, 10, 50 concurrent agents | Overhead vs direct Forgejo < 20 % |
| Idle and loaded RAM per component | Measured |
| CI cold start per isolation option | Measured |
| Revocation lag (git, web UI) | git: next op; web UI: measured |
| Drift repaired after injected failures | 100 % within one sweep |
| Agent tool calls, ticket → merged | Measured |
| Setup steps / lines of glue (plugin, broker, compose) | Measured |

Findings go to `docs/research/<date>-git-hosting-p1-findings.md`, then the MVP spec.

## Non-goals (P1)

- SSH transport and SSH certificates (decision 11).
- Rust gateway, Gitea, Woodpecker and bare-gateway prototypes (decision 1).
- Importing existing repos, mirroring from GitHub, LFS, packages/registry.
- Repos shared across projects (decision 2).
- Kanboard-native code/PR views (decision 15).
- Mapping Kanboard application roles (admin/manager) from Authentik.
- Production deployment; P1 runs on the dev stack plus the option-(a) VM.
- ForwardAuth on Forgejo web-UI cookie sessions (measured, decided in the MVP spec).

## Sequencing

TDD throughout: broker with `cargo test` (unit + an integration test against a throwaway Forgejo
container), plugin with the harness PHPUnit runner, scenario script as the end-to-end test.

1. **Stack:** git overlay compose with Forgejo 15 LTS (Postgres), Traefik, Authentik
   (blueprints), broker skeleton returning 403 for everything. Exit: compose up, Forgejo reachable
   only through Traefik, every repo route denied.
2. **Plugin ↔ broker channel:** HMAC both directions, authz route in GitHost, broker verbs
   `principals` and `sync`. Exit: a Kanboard project produces an org with correct teams; tamper,
   replay and stale-timestamp tests rejected.
3. **Identity:** Kanboard OAuth2 plugin + Forgejo OIDC against Authentik; human pre-provisioning;
   agent users. Exit: a human logs into both via Authentik and lands in the pre-provisioned
   Forgejo account; agents cannot log into the web UI.
4. **Credentials and live authz:** `repos`, `tokens`, revoke, ForwardAuth, credential helper,
   audit log. Exit: scenario steps 1–2, 5 (cross-agent and main pushes), 7, 8 pass.
5. **Merge policy:** branch protection, prefix pre-receive hook, `risk-gate`, reviewer agent
   flow, auto-merge, auto-revert. Exit: scenario steps 3, 4, 5 (force-push), 6 pass.
6. **CI isolation:** runners for options (b), then (a), then (c) if KVM; no-secrets check.
   Exit: scenario step 5 (secrets) passes on each option; cold-start numbers recorded.
7. **Reconcile + measurement:** sweep timer, drift injection, load runs at 1/10/50 agents.
   Exit: step 9 passes; metrics table filled.
8. **Security review:** a second model (Codex via `codex:rescue`, or `code-review` at high effort)
   reviews broker, plugin and Traefik config against the trust boundaries above before the
   findings doc is written. Findings fixed or recorded.
9. **Findings:** write the findings doc; hand to the MVP spec.
