# Git hosting P1: execution rulings

Every decision taken during execution of [the P1 plan](../plans/2026-11-17-git-hosting-p1.md) without a human answer, in the order made, each with its cost if wrong. The spec was the binding authority. Companion to [the findings](2026-11-19-git-hosting-p1-findings.md).

## Pre-flight rulings (F1–F15, all adopted)

### F1: ForwardAuth route set has bypasses (High, spec plus T2/T11)
- Match `^/{org}/{repo}(\.git)?/(info/refs|git-upload-pack|git-receive-pack|info/lfs/.*|HEAD|objects/.*)` plus `/{org}/{repo}/(raw|archive|media)/.*`.
- Have Traefik send every request that carries an `Authorization` header or `?token=` on a non-exempt path through ForwardAuth.
- In T2, add preflight assertions for the no-suffix form and `raw`.
- Record this as a spec amendment in the ledger.
**Why:** It is cheap now, and the spec's intent ("every git and repo-API request is checked live") is explicit.
**Cost if wrong:** Some extra ForwardAuth calls on web traffic.

### F2: `kanboard/risk-gate` is forgeable by agents (High, spec "Merge policy", T14 step 4)
- The broker's ForwardAuth denies `POST .../statuses/*` (and the commit-status-creating endpoints) for every principal except the broker's own identity.
- Add a T11 table row "agent posts status → 403 `kanboard_deny`".
- Add a negative case to T14 scenario step 4.
- In the ledger, record that the spec's "CI cannot write it" reasoning is incomplete.
**Why:** Decision 7's high-risk gate is otherwise void.
**Cost if wrong:** None. It is a pure restriction.

### F3: Interface shapes for the authz route, `task_closed` and `getGitCredential` are unfrozen (Medium-high, spec "Live authz path" step 4, T4/T10/T11/T12)
**Why:** The live check is the only revocation guarantee (decisions 4 and 10).
**Cost if wrong:** One extra field.

### F4: Principals have no producer, and sync comes before principals (Medium-high, T5/T6/T7, spec "Identity")
- Move `src/db.rs` (all three tables) into T6.
- State member entries become `{user_id, username, kind: human|agent, oauth2_user_id?}`. `kind` = agent when the user is in the Agents plugin's `agents` table.
- Sync auto-provisions any missing principal through the T7 module. T7 lands the module and IT, and T6 stubs it to "skip and log unknown user" until then.
- Sync issues `DELETE /tokens?user_id=&project_id=` for every member it removes.
**Why:** It keeps the plugin free of admin semantics, has one code path, and covers "revoked when the agent leaves the project" (decision 5).
**Cost if wrong:** The plugin-side `POST /principals` call is lost, which matters only for humans who log into Forgejo before their first sync. That window is up to 30 s, and the live check still governs git.

### F5: The nudge design is broken (Medium, T8, ground rule "Spec amendment")
- The middleware only records the affected project ids, from the params, or for group methods from `ProjectGroupRoleModel`.
- `register_shutdown_function` fires `POST /projects/{id}/sync` with an empty body, which means "pull `githost/state` for this project now", once per affected project, after the response.
- The test asserts one nudge per affected project, that nudges happen after the DB change, and that a broker failure leaves the result unchanged.
**Why:** It keeps one state source (`githost/state`) and avoids the race.
**Cost if wrong:** Team state lags until the 30 s pull, which the spec already tolerates.

### F6: Org bootstrap chicken-and-egg (Medium, T5 step 1 / T9)
**Cost if wrong:** Small.

### F7: Plugin scaffolding gaps and spec features with no task (Medium, T3/T5/T8/T10/T15, spec "GitHost plugin", "Audit")
**Cost if wrong:** Small. These are spec-mandated.

### F8: Spec self-contradictions (Low-medium)
1. Follow the Merge-policy section. T9's "stop and ask" stands.
2. Accept one 30 s loop as the full sweep, because it diffs Forgejo against state and so repairs drift both ways. Record it as an amendment, since step 9's "within one cycle" means 30 s.
3. Accept the deferral, because identity is independent of steps 2–12.
**Cost if wrong:** On (2), a 30 s full sweep costs more Forgejo API calls at scale. Measure it in T17.

### F9: Broker scaffolding defects a reviewer would flag (Medium, T1/T6/T11/T17)
- T1 creates `src/lib.rs` (`pub fn app(state) -> Router`) with a thin `main.rs`.
- T6 creates `src/audit.rs` (a JSONL writer with one file per day), and T11 extends it.
- T11 adds `tests/authz_deny.rs` to its file list.
- T17 puts `BROKER_FAULT_SYNC_ONCE` behind a cargo feature `fault-injection` that is off by default. The overlay builds with it for the dev stack only.
**Cost if wrong:** Small.

### F10: HMAC vectors and scenario naming (Medium, T3/T11/T12)
- The harness file stays the source of truth, and the broker vendors a copy at `tests/fixtures/hmac-vectors.json` with a `sha256` check against the harness file when it is present. This follows the hash-checked vendoring house style.
- Canonical `path` = path plus `?` plus the raw query string as sent, with no reordering. Add a "tampered query" vector.
- A replay vector is a 2-step case: the first is accepted, the second rejected.
- `scenario.sh` uses the numeric steps from the spec only. T11 implements `8` (outage) and the token parts of `7` instead of `credential`/`revoke`/`outage`.
**Cost if wrong:** Small.

### F11: Integration-test reachability and ordering (Medium, T2/T6/T7/T9/T10/T17)
- Add a compose service `broker-it` under profile `it`, which runs `cargo test -- --ignored` on the overlay network against `http://forgejo:3000`. The ground-rule command becomes `docker compose ... --profile it run --rm broker-it`.
- T17's baseline runs `load.sh` from a container on the same network against `forgejo:3000`.
- T9's IT mints a token directly through the admin API and pushes to `forgejo:3000`. It asserts branch-protection behaviour only, because ForwardAuth is T11/T12's concern.
- The T2 invariant "no host port" holds.
**Cost if wrong:** One extra compose service.

### F12: Cross-task ordering for CI, webhooks and the Authentik source (Medium, T7/T13/T14/T16)
- T14 creates `testing/git/fixtures/ci.yml` and a dev-only plain `runc` `forgejo-runner one-job` labelled `dev-plain`. It is not an isolation option, it is removed or kept unlabelled for T16, and it lives in the overlay under profile `ci-dev`.
- T14 lists `app.ini.tmpl` and sets `ALLOWED_HOST_LIST = broker`.
- T7's bootstrap creates a placeholder OAuth2 auth source named `authentik` (`forgejo admin auth add-oauth`), which T13 reconfigures in place, keeping the same `source_id`.
- T13 lists `bootstrap.sh`.
**Cost if wrong:** T14 is blocked until T16, or T7 is blocked until T13.

### F13: Shared dev stack disruption (Medium, local; T2 step 4, T11 step 4, T13)
- Every `up` uses all current overlays plus git: `-f dev -f jsonrpc -f chicane -f bigboard -f git-overlay`. Write this once as `COMPOSE_FILE` in `testing/git/README` or `scenario.sh`.
- The git overlay mounts `../GitHost:/var/www/app/plugins/GitHost`.
- The outage step uses `docker pause kb-suite` / `unpause`. That is equivalent for fail-closed, because requests hit the 2 s timeout, and it lasts seconds.
- Mount OAuth2 from `testing/git/vendor/OAuth2` (gitignored), which is not a root-level dir, so test discovery skips it. Record its compatibility separately.
**Cost if wrong:** Another session loses Chicane or Bigboard until someone re-ups.

### F14: Local ports and hostnames (Medium, local; T2/T13/T16)
- Publish Traefik only as `127.0.0.1:8090:8090`, with the Traefik entrypoint also on `:8090` internally.
- Give Traefik the network aliases `git.localhost` and `auth.localhost` on the compose network, so containers resolve them to Traefik on the same URL the host uses.
- Use one issuer, `http://auth.localhost:8090/application/o/<slug>/`, everywhere.
- The VM in F15 gets `/etc/hosts` entries pointing at the host gateway.
- No other service publishes ports.
**Cost if wrong:** T13's OIDC discovery fails on issuer mismatch, and runners cannot register.

### F15: CI isolation is local-only now (Medium, local; T16, spec decision 13 and "Owner's manual steps")
| Option | Local setup |
| --- | --- |
| (a) | A local QEMU/KVM VM: cloud image plus cloud-init with Docker and `runsc`. Record it in findings as "same physical host, separate kernel". It is not a separate-host measurement, and its cost is $0, not $5–20/month. |
| (b) | A rootless dockerd under the user, with `runsc` registered in **its** `daemon.json`. |
| (c) | Kata through a separate rootful dockerd or containerd instance with its own socket and data-root. |
- **Never** add runtimes to the main Docker daemon or restart it, because 30+ unrelated containers run there.
- Installing `runsc` and Kata binaries is a root step for Carmelo, and the supply-chain check comes first.
- The production VPS `/dev/kvm` check cannot be answered locally. Record it as an open MVP question.
- Add a `squid` or `tinyproxy` allowlist service (`npm`, Packagist, crates.io, Docker Hub) to T16's file list, or record that P1 tested egress-deny only.
- T16 step 1 begins by checking whether Forgejo 15 supports environment-scoped secrets. If it does not, the spec fallback applies (secret-bearing workflows only in a separate deploy repo), and 5d asserts that no secret is reachable from `agent/*`.
**Cost if wrong:** The option (a) numbers mislead the MVP if they are not labelled.

## Execution rulings

- Ruling: harness commits stay on `master` of kanboard-plugins (no worktree) — plan's ground rules forbid branch switching in this shared checkout and all prior P1 docs landed on master; broker and GitHost are new standalone repos on their own `main` — cost if wrong: harness commits need a cherry-pick onto a branch.
- Ruling: CI option (a) "separate VM" is emulated with a local KVM VM; no remote host exists — cost if wrong: option (a) numbers are not representative of a real second VPS.
- Ruling: adopt every proposed F1–F15 ruling as written in preflight.md — they tighten security (F1, F2, F3, F10) or fix ordering/local-only gaps without changing the spec's decisions — cost if wrong: a task carries an extra assertion or file. Each dispatch carries the rulings relevant to it.
- Ruling (F15): root installs (runsc/Kata into the system daemon) are a human step; Task 16 first tries user-space options (rootless dockerd with runsc, QEMU/KVM VM) and parks anything needing root — cost if wrong: option (b)/(c) measured later.
- Ruling: `*.localhost` is forced to loopback inside containers — keep `git.localhost:8090` for host/browser in T2; T13 (OIDC issuer) and T16 (runners) must solve container-side resolution (non-.localhost name or curloptResolve) — cost if wrong: hostname rename across compose/app.ini later.
- Ruling: web UI cookie calls to /api/v1 and raw hitting the broker is expected; T11 must allow Forgejo web-session requests (spec: humans use the Forgejo UI) — carry to T11 dispatch.
- Ruling: `/api/actions_pipeline/*` exemption deferred to T16 — carry to T16 dispatch.
- Ruling: Task 2 review Important (git-web catch-all without ForwardAuth) — amend my dispatch ruling to match spec §live authz ("Web UI pages served by Forgejo session cookie are not ForwardAuth-checked in P1"); spec is binding; no code change — cost if wrong: cookie-session revocation latency = push-sync latency, which P1 measures anyway.
- Ruling: the broker signs the path-form URL `/?controller=GitAuthzController&action=authz&plugin=GitHost` (works with URL rewrite on or off) and treats any non-200 as deny — carry to T11 — cost if wrong: one URL constant.
- Ruling: ⚠️ MySQL/Postgres schema DDL (v1, v2) never executed — park to final review; P1 dev stack runs SQLite — cost if wrong: a syntax fix before any MySQL/Postgres install.
- Ruling: revoking tokens on any team removal (incl. role change) — accepted, fail-closed direction — cost if wrong: an agent re-requests a token after a role change.
- Ruling: running broker container not yet wired (no audit volume, no GITHOST_HMAC_SECRET) — carry to T8 (reconcile loop is the first task that needs the live broker) — cost if wrong: none until then.
- Ruling: orgs private — matches "access by project membership" — cost if wrong: one flag.
- Ruling: broker→Forgejo plain http inside the compose network is fine; HTTPS-only covers traffic leaving the host network — cost if wrong: TLS between containers in MVP.
- Ruling (for T10): admin token cannot mint tokens for another user (Sudo → 401). Mint flow = admin PATCHes the agent's password to a fresh CSPRNG value, immediately basic-auths as the agent to POST /users/{u}/tokens, discards the password; serialize per user — cost if wrong: one more Forgejo round trip per mint; password-rotation race if two mints overlap (mitigated by per-user lock).
- Ruling (for T13): spec's "local password login disabled for non-admins" goes into app.ini.tmpl in T13 (sign-in form/internal sign-in off) while keeping API basic auth on for the T10 mint flow; record what Forgejo 15 actually allows — cost if wrong: agents could web-login with a password nobody knows.
- Ruling (T8 live wiring): T8 mounts ../GitHost into kb-suite and recreates kb-suite once (data is in named volume kanboard-suite_kb-suite-data; other sessions idle); HMAC secret lives in a separate gitignored testing/git/hmac.env shared by broker and kb-suite — never git/.env, which holds the Forgejo admin token the plugin must not see — cost if wrong: ~10 s Kanboard downtime for other sessions.
- Ruling: agents cannot force-push their own protected agent/<u>/ branches (Forgejo 15 limit) — accepted for P1; agents push new commits or new branches — cost if wrong: agent rebase workflows need a new branch name.
- Ruling (carry to T11): live authz passes task_id so moved/deleted/closed tasks deny; ForwardAuth covers /api/v1/repos/*; dev API token for user 20 on :8081 stays (dev-only, labelled) — cost if wrong: a stale dev token on the dev board.
- Ruling (F1 spec amendment): every request carrying Authorization or ?token= on a non-exempt path goes through ForwardAuth; route shapes per F1 — recorded as spec amendment.
- Ruling (F2): ForwardAuth denies commit-status writes for everyone but the broker identity; spec's "CI cannot write it" reasoning was incomplete.
- Ruling (F13): outage step uses `docker pause kb-suite`, not stop.
- Ruling: no-credential requests get 401 + Basic challenge (still a deny, reason no_principal) instead of 403 — git only invokes its credential helper on 401; all other denials stay 403 — cost if wrong: one status code.
- Ruling: agents reach git only with minted tokens (other agent tokens → no_principal) — matches decision 5 — accepted.
- Ruling: ⚠️ Actions job reaching forgejo:3000 directly → Task 16 must keep runners off the Forgejo network (only via Traefik) — cost if wrong: status forging from CI.
- Ruling (T12): local stack is http on 127.0.0.1:8090; the helper emits credentials for protocol=http only when host is loopback or *.localhost, otherwise https only — spec HTTPS-only preserved — cost if wrong: one condition.
- Ruling (F10): scenario step names become numeric only (credential→1 2, revoke→7, outage→8).
- Ruling: nudge sync cancelled when plugin's 2 s client times out (axum drops the handler) left a stale catch-all ahead of agent rule (1 in 5 runs) — fix now in T12: detached background sync, 202 ack — cost if wrong: none, restriction-only failure mode.
- Ruling: removal denies as token_revoked (plugin revokes immediately) — accepted, fail-closed earlier. One token per git command (~30/run) — accepted for P1, T17 measures. Leftover agent/*/scenario-* branches — accepted (protected), cleanup by admin in T17.
- Ruling (T13 hostnames): libcurl (git, curl, PHP) hard-maps *.localhost to loopback, so the OIDC issuer must not be *.localhost. Use a non-.localhost alias on Traefik for Authentik (e.g. auth.kb.test:8090), reached in containers via the Docker alias and on the host via curl --resolve; a browser on the host needs one /etc/hosts line, an optional manual step for Carmelo — cost if wrong: issuer rename.
- Ruling (T13 human step): Carmelo approved installing the OAuth2 plugin (relayed 2026-11-17); the supply-chain check still gates it — stop if it fails.
- Ruling: Forgejo 15 /user/link_account_signin ignores ENABLE_INTERNAL_SIGNIN — Traefik 403 rule blocks it; production must carry the same block (spec amendment, record for MVP) — cost if wrong: password-based account linking.
- Ruling: application_url=http://localhost:8081/ set on shared dev kb-suite — needed for OAuth redirect; dev only.
- Ruling: ⚠️ ENABLE_INTERNAL_SIGNIN off for admins too (Forgejo has no per-role switch; admin uses CLI) — accepted, stricter than ruling. ⚠️ agent web-login asserted on stand-in user in scenario, real agent principal in broker IT (4514040) — accepted, covered.
- Ruling (T14): required CI check context must equal what Forgejo Actions actually emits for fixtures/ci.yml — update protection.rs constant (was placeholder 'ci'); dev-plain runner (profile ci-dev) must not reach forgejo:3000 directly, only Traefik (T11 ⚠️); ALLOWED_HOST_LIST=broker; F2 negative case in step 4.
- Ruling: Actions job tokens have no read path through ForwardAuth — carry to T16 (runner isolation + job token read path). dev-plain host-mode runner secret exposure — dev-only, replaced in T16.
- Ruling: I3 (spec's Kanboard comment on auto-revert) deferred to Task 15, which builds the task↔branch/PR mapping it needs — cost if wrong: one more verb in T15.
- Ruling: risk = union of two-dot and merge-base git diffs computed with real git in the broker (bare mirror), any git error → pending; immediate pending on PR events — cost if wrong: extra false-positive human approvals (fail-closed direction).
- Ruling: T14 follow-up — a pull_request event on the same PR at an already-evaluated head re-pends the gate, which makes Forgejo drop a scheduled auto-merge (PR #49 stuck). Fix after T15 review, in the T14 owner's code: no re-pend for the same PR+head+base, and re-schedule auto-merge for agent PRs that lost it — cost if wrong: stuck PRs in the factory.
- Ruling (T16 order): run (a) local KVM VM first (no root needed), then (c) via nested virt inside a VM or user-level Firecracker if feasible, then (b) — (b) needs root apt installs (uidmap, slirp4netns) and runsc install; anything needing sudo is parked as an exact human-step list, not attempted — cost if wrong: (b) measured after Carmelo's step.
- Ruling (T16 pids): pids 1024 enforced on the gVisor sandbox cgroup (a) / guest threads-max (c) instead of the job's own cgroup — accepted; the enforced boundary is what bounds an untrusted job — cost if wrong: fork-bomb headroom ~936 in (c), noted in findings.
- Ruling (T16 I1): rename the deploy org outside the kb- prefix + 5d deny check now; the general broker defect (adopts any pre-existing kb-* org) goes to Task 18 as a security finding, fixed test-first there — cost if wrong: one more broker fix round in T18.
- Ruling (T17 load 403s): measurement is correct and recorded; the defect (GitHost HMAC endpoint emits PHP error / session write under concurrency) is a real availability bug → Task 18 must-fix, test-first, then re-run load at 10/50 through Traefik and update metrics.md — cost if wrong: P1 findings understate ForwardAuth reliability.
- Ruling (T18 flow): Codex reviews (read-only); an Opus implementer then fixes findings test-first incl. T18 carries, re-runs full suites + load, writes findings doc; Opus task review after — cost if wrong: one extra round.
- Ruling (final I3): raw/archive/media/LFS go through ForwardAuth only when a credential is present (as git-credentialed already does); credential-less cookie requests fall to Forgejo's own session auth, same as the P1 web UI per spec — cost if wrong: cookie-session raw access unchecked by broker until MVP decides cookie ForwardAuth.
- Ruling (final I2): agents never map to the Forgejo managers team regardless of Kanboard role; managers = humans only — cost if wrong: agent PMs lose repo-admin they should never have had.
- Final: parked — form-body token on POST /{o}/{r}/archive/* skips ForwardAuth (Forgejo's own 401; GET content still broker-checked) — Ruling: real, Minor, deferred to MVP (add form Content-Type match to git-credentialed router) — cost if wrong: a credentialed archive-start POST unaudited by broker.
- Final: parked — broker README/comments (README 229, 605; webhooks.rs:342; protection.rs:97-98) and findings.md:15,132 overclaim vs decision 10 / form-body case — Ruling: doc-only, deferred; surfaced to Carmelo — cost if wrong: readers over-trust stale-approval guarantee.
