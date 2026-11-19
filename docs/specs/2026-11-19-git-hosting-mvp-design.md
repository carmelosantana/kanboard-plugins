# Git hosting MVP — design

Date: 2026-11-19 · Map: Kanboard #817 (dev board, project "Git Hosting") · P1: Kanboard #829 ·
Inputs: [P1 spec](2026-11-17-git-hosting-p1-design.md),
[P1 findings](../research/2026-11-19-git-hosting-p1-findings.md),
[P1 rulings](../research/2026-11-19-git-hosting-p1-rulings.md)

## Why

P1 proved the chosen topology on the dev stack. Kanboard project membership is the only authority
for git access, checked live on every git and repo-API request. Agents get 1-hour tokens confined
to `agent/<login>/**`, and merges to `main` need CI, a reviewer agent and, on high-risk paths, a
human. The MVP takes that topology to production for our own projects. First it closes what P1
left open, then it rehearses on a staging copy with real TLS, then it cuts over.

The P1 spec's decisions 1–15 stand unless this spec changes them. Everything below is either a new
decision (MVP-n) or a requirement P1 made explicit.

## Goals, non-goals, anti-goals

- **Goals:** production git hosting on the Kanboard VPS for agents and humans. Every security gap
  P1 recorded is closed or deliberately accepted. A staging rehearsal must pass before production.
  The broker and GitHost get remotes.
- **Non-goals:** SSH transport, LFS, importing or mirroring existing repos, Kanboard-native code
  views, ForwardAuth on web-UI cookie sessions, AGit and pushed notes, a public release of GitHost
  or a ModMenu listing, and Kanboard database tooling (a separate task, below).
- **Anti-goals:** anything that makes Forgejo team state, a cached answer or a Kanboard API token
  the authority for git access. Anything that gives an agent Forgejo repo admin or the plugin the
  Forgejo admin token. A production cut-over before staging has passed every exit check.

## Decisions

| # | Decision | Source |
| --- | --- | --- |
| MVP-1 | **Refs outside branches and tags stay refused for everyone.** The instance-wide `receive.hideRefs` (`refs/`, `!refs/heads/`, `!refs/tags/`) from GH-03 is the policy, so there is no AGit and no pushed notes or custom refs, for humans too. Production `app.ini` carries the same `[git.config] include.path`. No pre-receive hook. | Findings open decision 1; Carmelo 2026-11-19 |
| MVP-2 | **Ed25519 replaces HMAC on the Kanboard ↔ broker channel.** Each side holds only its own private key and the other side's public key, which meets the P1 spec's original wording (GH-07). | Open decision 4; Carmelo |
| MVP-3 | **Every reply on the channel is signed** over the request's nonce, both Kanboard's authz `{allow}` and the broker's verb replies. Network segmentation (MVP-4) is required as well; neither layer relies on the other. | Open decision 11 (final review M1); Carmelo |
| MVP-4 | **Production networks are segmented** (GH-10). Each database sits on a private network with its one application. Forgejo is reachable only from Traefik and the broker. | Open decision 3; brief |
| MVP-5 | **An approval counts only for the head it names.** The broker's merge gate stays `pending` until an official approval from the allowed team names the PR's current head commit. This closes Forgejo's partial-revert rule. | Open decision 10; Carmelo |
| MVP-6 | **Offboarding uses a broker verb, not ForwardAuth on cookies.** When a user is disabled in Kanboard or leaves every project, the broker sets `prohibit_login` on their Forgejo user and revokes their tokens. Removal from one project keeps relying on team sync (about 1 s, 30 s at worst). | Open decision 2; Carmelo |
| MVP-7 | **Project identifier changes are refused for projects that have repos.** Moving, renaming or emptying an org, and releasing a deleted project's identifier, become deliberate operator verbs. | Open decision 8; Carmelo |
| MVP-8 | **Runners: one-job gVisor runners (option a) on two hosts.** One is a VM on one of our machines ($0) and the other a flat-rate runner VPS (about $5–20 a month, running jobs back to back, not a VPS per job). Both carry the agent label, so CI keeps running when either is down. Option (b) is dropped. Option (c), Kata, is used on the VPS only if its plan exposes `/dev/kvm`. | Open decision 6; Carmelo |
| MVP-9 | **Production Kanboard runs on MariaDB 10.11 today** (`~/Projects/Kanboard/Production/docker-compose.yml`), so P1's SQLite lock concern does not carry over. Staging and the load test use MariaDB 10.11 too. GitHost's MySQL schema (`Schema/Mysql.php`), never executed in P1, must be run and pass the plugin suite on MariaDB before staging. The load test (S5) checks that the nonce write per broker call holds at 50 agents. No engine change is planned. | Open decision 5; Carmelo 2026-11-19 |
| MVP-10 | **Staging is a separate Dokploy project on the production VPS** under `staging.` subdomains with real TLS. It is slept or torn down after sign-off. **The 50-agent load test (S5) runs off the VPS**, on our own machine, against a copy of the staging stack with the same images and config, so it cannot starve production. | Brief; Carmelo |
| MVP-13 | **Every production and Dokploy change is a runbook that Carmelo runs.** That covers installing plugins on production Kanboard, adding keys, redeploys, Dokploy projects, DNS and staging. Agents work on dev, the code and the runbooks, and never hold production or Dokploy credentials. | Builder check; Carmelo |
| MVP-14 | **Backups: a local nightly dump now, off-host via the database-tools task.** The git stack dumps repos, the Forgejo DB, the broker DB and the audit log to a local volume each night. The off-host destination and its credentials come from the Kanboard database tools task, and **production cut-over waits for it**. | Builder check; Carmelo |
| MVP-15 | **Authentik with MFA is required for Kanboard admins, managers of projects that have repos, and every human who uses git.** Other humans, such as clients, keep password login. | Builder check; Carmelo |
| MVP-11 | **The broker and GitHost get private GitHub repos** (`carmelosantana/kanboard-git-broker`, `carmelosantana/kanboard-githost`). They go public, and GitHost is listed in ModMenu, only after production runs and a fresh security review passes. Nothing is pushed without Carmelo's OK at that step. | Brief; Carmelo |
| MVP-12 | **Engineering calls accepted as listed** under [Hardening](#hardening-accepted-engineering-calls): sweep delete cap, two-sided drift report, minted-token API denies, M2, M3 and M5. | Open decisions 7, 9, 12; final review M2, M3, M5; Carmelo |

## Production topology

Everything except the runners lives on the Kanboard VPS under Dokploy, in one Dokploy project
(`git`) beside the existing Kanboard app. Names below are the production ones; staging uses the
same layout under `staging.` (MVP-10).

| Host | Serves | Through |
| --- | --- | --- |
| `projects.madeinnewburgh.com` | Kanboard (existing) | Dokploy Traefik |
| `git.madeinnewburgh.com` | Forgejo, behind broker ForwardAuth | Dokploy Traefik → git-edge (below) |
| `auth.madeinnewburgh.com` | Authentik | Dokploy Traefik |

### Networks

| Network | Members | Why |
| --- | --- | --- |
| `kanboard-db` | Kanboard, its MariaDB | DB reachable only from its app. Production's phpMyAdmin also sits next to the database today; how it is reached is the database tools task's concern, but it must not join any network listed here |
| `forgejo-db` | Forgejo, its Postgres | same |
| `authentik-db` | Authentik server and worker, its Postgres and Redis | same |
| `git-core` | Forgejo, broker, git-edge | The only networks Forgejo shares with anything but its DB |
| `broker-kanboard` | broker, Kanboard | Signed channel (MVP-2, MVP-3) |
| Dokploy's shared network | Dokploy Traefik, Kanboard, Authentik server, git-edge | Public routing |

**git-edge** is a small Traefik inside the `git` compose stack. It carries P1's routers and
ForwardAuth (`testing/git/traefik/dynamic.yml`, hosts changed). Dokploy's own Traefik routes
`git.<domain>` to it. This exists because Dokploy attaches routed apps to one shared network, which
would let anything on that network reach Forgejo directly, bypassing ForwardAuth. Forgejo itself
never joins the shared network.

Forgejo reaches Authentik (OIDC discovery, token endpoint) and runners reach Forgejo through the
public hostnames, so no network links them directly. Forgejo webhooks go to the broker over
`git-core`.

**Staging exit check S3 proves this layout.** From a container on each network, only the listed
peers answer. In particular, Forgejo `:3000` and every database port are closed from Kanboard,
Authentik and Dokploy's shared network. This is P1's GH-10 probe with every result reversed.

### Runners (MVP-8)

| Runner | Host | Label | Jobs |
| --- | --- | --- | --- |
| agent runner 1 | libvirt VM on one of our machines; Docker with `runsc` as the default runtime; one job per runner process | `agent` | agent-branch CI |
| agent runner 2 | flat-rate runner VPS, same image and setup | `agent` | agent-branch CI |
| deploy runner | a separate VM on our machine, never shared with agent jobs | `deploy` | jobs in the `deploy-kb` org only |

- Runners connect out to `git.<domain>` only; nothing connects in. A runner's registration secret is
  delivered as a systemd credential the job never sees (P1 T16).
- Each runner host has an egress allowlist proxy on an isolated network (P1's `ci-proxy`). Fix
  carried from T16: the proxy allows only HTTPS on 443 to the allowlisted registries, not plain HTTP
  on any port.
- **Clone path for jobs** (T16 carry-over): ForwardAuth accepts Forgejo's per-job Actions token for
  read on the job's own repo. The broker validates it against Forgejo and checks the repo matches.
  It never grants write.
- The dev host-mode runner (`ci-dev` profile) does not exist in staging or production (GH-04).
- Deploy jobs hold the only Actions secrets, in the `deploy-kb` org outside the broker's `kb-`
  namespace (P1 gap 5).
- *My call:* in the MVP the deploy runner holds **no production credentials**. S8 proves only that
  an isolated `deploy-kb` job runs and can read a dummy secret. Real deploy pipelines, with their
  credentials and threat model, are a later task.

## Security changes

### Channel signatures (MVP-2, MVP-3)

- Each side has one Ed25519 key pair: Kanboard (GitHost) signs requests to the broker and its authz
  replies, and the broker signs its requests to Kanboard and its verb replies. Each side is
  configured with its own private key and the peer's public key. PHP uses sodium (built in); the
  broker uses `ed25519-dalek` (supply-chain checked before adding).
- **Request signature** covers method, path, query, timestamp, nonce and a SHA-256 of the body. The
  ±60 s window and the SQLite-persisted nonce cache (GH-08) stay.
- **Reply signature** covers the request's nonce, the reply status and a SHA-256 of the reply body.
  The caller rejects an unsigned, mis-signed or wrongly bound reply and fails closed: the broker
  denies, and the plugin reports `broker_unavailable`.
- **Rotation:** each side accepts up to two peer public keys (current and next). Rotating means
  adding the next key on the verifier, switching the signer, then removing the old key. A
  `keygen` subcommand on the broker binary prints a key pair for each side, so the plugin needs no
  key tooling of its own. Private keys live only in each container's environment or secret file,
  never in Kanboard's database.
- The plugin still treats a missing or malformed key configuration as "not connected" (like GH-07's
  equal-secret check).

### Approvals bound to the head (MVP-5)

- On every PR event (opened, synchronised, reviewed, review dismissed) the broker recomputes
  `kanboard/risk-gate`. It is `success` only when an official approval from the right team (the
  `reviewers` team; `humans-high-risk` too for high-risk paths) has `commit_id` equal to the PR's
  current head. Otherwise it stays `pending`. This applies to every PR, not just high-risk ones.
- `dismiss_stale_approvals` and `ignore_stale_approvals` on `main` stay. The gate is the layer that
  also catches partial reverts.
- Test: P1 scenario PR #141's partial-revert case must stay open until a fresh approval of the new
  head, then merge.

### Offboarding (MVP-6)

- New broker verb `POST /principals/{id}/offboard` (signed). It sets `prohibit_login` on the
  Forgejo user, deletes every minted and broker-known token, and removes the user from every team.
  `POST /principals/{id}/restore` undoes it when the user is re-enabled.
- Triggers: the 30 s state pull sees a user disabled in Kanboard, or a user who is in no project
  that has repos. Kanboard 1.2.47 sends no event for either, so there is no nudge.
- *My call:* offboarding applies to humans only. An agent loses git access through token revocation
  and project removal, and setting `prohibit_login` on an agent would break minting, which signs in
  as the agent. Disabling someone only in Authentik stops new sign-ins but is not a trigger. The
  runbook for leavers disables them in Kanboard, which is.

### Authentik coverage (MVP-15)

- GitHost already refuses git credentials and authz to humans without an Authentik `sub`, because
  pre-provisioning needs one.
- *My call:* GitHost's settings page lists every Kanboard admin, and every manager of a project with
  repos, who has no Authentik link. The production rollout runbook requires that list to be empty
  before cut-over. It is a report, not a lock, so nobody is locked out at cut-over.
- **Must verify on staging (S6):** after offboarding, the user's existing Forgejo browser session
  fails on its next request. If it does not, the MVP adds a session purge to the verb. Forgejo's
  CLI or a session-store delete, whichever is found to work, gets recorded. If neither exists, set
  `SESSION_LIFE_TIME` to 1 h and record the remaining bound.

### Org lifecycle (MVP-7)

- GitHost refuses to save a project identifier change when the project has repos. The message
  names the operator verb.
- Operator verbs are subcommands of the broker binary, run inside the broker container; they are
  not network routes:
  - `org move <project_id> <new_identifier>`: renames the Forgejo org (verified on staging), updates
    `owned_orgs` and repo rows, then lets the plugin save the new identifier.
  - `org empty <org>`: removes every team member of an org whose project is gone.
  - `org release <org>`: deletes or archives an orphan org, so its identifier can be reused.
  - `org adopt-legacy`: records Forgejo ids for orgs carried over from before GH-01, then
    reports any it could not verify.
- Every verb writes an audit line with `verb`, as admin-API calls already do.

### Hardening (accepted engineering calls)

| From | Change |
| --- | --- |
| Open decision 7 | The sweep deletes at most a fixed number of expired tokens per tick, after the state sync, so drift repair stays within one tick after mass revocations. |
| Open decision 9 | Reconcile reports repo rows that only GitHost, only the broker, or only Forgejo has. It repairs nothing automatically. |
| Open decision 12 | ForwardAuth denies minted agent tokens on `branch_protections`, `tag_protections`, `hooks`, `collaborators` and repo `PATCH`/`DELETE`, whatever the agent's Forgejo rights. |
| M2 | The authz-timeout audit line carries principal and repo. |
| M3 | Sync drops an active repo row after Forgejo answers 404 for it, as it already does for pending rows, and audits it. |
| M5 | `forgejo_full_name` is unique case-insensitively. The MySQL schema runs and passes the plugin suite on MariaDB 10.11 before staging (MVP-9). The Postgres schema runs once in tests. |
| Parked minor | A form-body token on `POST …/archive/*` goes through ForwardAuth: git-edge's credential router also matches POST requests to `archive/` paths. |
| Parked minor | The P1 docs' overclaim on stale approvals is corrected by MVP-5. |

## Staging rollout (MVP-10)

Staging is a separate Dokploy project on the production VPS: `projects.staging.`, `git.staging.`
and `auth.staging.madeinnewburgh.com`, with Let's Encrypt certificates. It has its own Kanboard,
Forgejo, broker, Authentik, keys and databases, and shares nothing with production but the VPS and
Dokploy's Traefik. Seed data is synthetic. Production data is never copied in. It runs only while
needed. Expect about 1.2 GiB of extra RAM while it is up (P1's idle numbers).

| # | Exit check | Pass bar |
| --- | --- | --- |
| S1 | P1 `scenario.sh all`, pointed at staging over HTTPS | every step passes |
| S2 | TLS on all three hosts, including git over HTTPS with the credential helper | valid chain; no plain-HTTP git route |
| S3 | Network probe (MVP-4) | only the listed peers reachable; Forgejo and the databases closed to everything else |
| S4 | Channel tamper tests: unsigned, mis-signed and replayed requests and replies; key rotation | all rejected; rotation causes no denials |
| S5 | Load at 50 concurrent agents through git-edge, Kanboard on MariaDB 10.11, run off the VPS on a copy of the staging stack (MVP-9, MVP-10) | 100 % clone and push success, zero `kanboard_unreachable`, no database errors in Kanboard's log, ForwardAuth p95 < 250 ms |
| S6 | Offboarding a signed-in human | git refused at once; web session ends on next request (or the recorded fallback bound) |
| S7 | Partial-revert PR (MVP-5) | stays open until the current head is approved |
| S8 | Runners: one job on each agent runner, one on the deploy runner; P1's isolation probe on both agent runners | jobs pass; probe passes; agent jobs see no secrets |
| S9 | Backup and restore: the local nightly dump (MVP-14) of repos, Forgejo DB, broker DB and audit log, restored into a scratch stack | restored stack passes S1 steps 1–2 |
| S10 | Identifier change on a project with repos, then `org move` | refused in the UI; the verb moves the org and git keeps working |

If S5 fails, cut-over waits. The failure is diagnosed and fixed, and S5 is re-run.

## Production rollout

Each step is a runbook Carmelo runs (MVP-13). Prerequisite: the off-host backup destination from
the database tools task is live (MVP-14).

1. Deploy the `git` Dokploy project and DNS for `git.` and `auth.`, with keys generated fresh (none
   reused from staging).
2. Install the pinned OAuth2 plugin (`607d0e6`) and GitHost on production Kanboard. Configure the
   broker keys, then redeploy Kanboard onto `broker-kanboard`. This is a short Kanboard restart, so
   pick a quiet window.
3. Admins, managers of projects with repos, and git users enrol in Authentik with MFA or a passkey,
   and their Kanboard accounts are linked (MVP-15). GitHost's unlinked list is empty.
4. Attach the first repo to one project, run scenario steps 1–3 on it, then open it to agents.
5. Point the nightly dump at the off-host destination. The first monthly restore check is scheduled.

Rollback: disable GitHost in Kanboard (git access is then denied, because authz fails closed),
then stop the `git` project. Repos and databases stay on their volumes.

## Repositories (MVP-11)

- First step of the plan, on Carmelo's OK: create the two private GitHub repos, add them as `origin`
  and push `main` (broker `4a11f4d`, GitHost `3759188` as of P1). Tags start at `v0.1.0` on the
  first staging deploy.
- The harness `master` commits from P1 are pushed separately, when Carmelo OKs it. They are
  unpushed today by his choice.
- GitHost's CI is the suite's standard zip-on-tag workflow. The broker's CI runs `cargo test`
  (default and `fault-injection` features) and clippy on GitHub Actions.

## Owner's manual steps and setup cost

| Step | When | Cost |
| --- | --- | --- |
| OK the GitHub repo creation and pushes | MVP start | Two private repos |
| DNS for `*.staging` (three records) and `git.`, `auth.` (two) | Staging, production | Five records in the DNS host |
| Provide the runner VM host: libvirt and a VM on one of our machines, `runsc` inside the VM | Before staging S8 | Root inside the VM only; $0 |
| Rent the runner VPS; check whether it exposes `/dev/kvm` | Before staging S8 | ~$5–20/month flat |
| Provide the deploy runner VM | Before staging S8 | A second small VM on our machine |
| Enrol in Authentik with MFA or a passkey; link the Kanboard account | Production cut-over | Each human, once |
| Upgrades: Forgejo (LTS yearly, patches monthly), Authentik, broker, runners, OAuth2 plugin | Production | Standing chore |
| Verify that the off-host backup restores | Production | Monthly chore |
| Run the staging and production runbooks (MVP-13) | Staging, cut-over | Several sessions of hands-on work; one Kanboard restart window |
| Provide the off-host backup destination through the database tools task (MVP-14) | Before cut-over | Depends on that task |

## Related task

**Kanboard database tools** is a separate task, proposed 2026-11-19 and specced on its own. It
covers database migration, backup and import, switching the live database by config, external
database sources, and off-site backup (FTP, S3-compatible or a UI download), with a Codex security
review. This MVP depends on it for the off-host backup destination (MVP-14): production cut-over
waits for it, and staging does not.

## Left to the builder

- Exact Dokploy compose files and network names, within the table above.
- The sweep's delete cap value, and how git-edge's config is templated per environment.
- How GitHost hooks the project-identifier save (model hook or form validator).
- The session-purge mechanism if S6 needs one.

## Sequencing

TDD throughout, on the :8081 dev stack until staging. The plan splits into these phases:

1. **Repos and MariaDB:** remotes and first push (on Carmelo's OK); GitHost's MySQL schema and
   plugin suite passing on MariaDB 10.11 in the dev stack.
2. **Channel:** Ed25519 requests, signed replies, rotation and `keygen`. Exit: the P1 channel tests
   and the new reply-tamper tests pass; `scenario.sh all` passes on dev.
3. **Policy:** head-bound approvals, offboarding verbs, the identifier refusal and the org operator
   verbs, plus the hardening list. Exit: unit tests, broker-it and scenario steps for each pass on
   dev.
4. **Runners:** the VM and VPS agent runners, the deploy runner, the proxy's HTTPS-only allowlist,
   and the job clone path. Exit: P1's isolation probe passes on both agent runners.
5. **Deploy templates and runbooks:** the Dokploy `git` project with git-edge and the segmented
   networks, the local nightly dump, and the staging and production runbooks (MVP-13).
6. **Staging:** Carmelo runs the staging runbook, then S1–S10 (S5 off the VPS). Exit: every check
   passes, results in a staging findings doc.
7. **Production:** Carmelo runs the rollout runbooks above, once the database tools task's off-host
   backup is live.
