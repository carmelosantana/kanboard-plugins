# Git hosting P1: what the prototype proved, and what the MVP must decide

Written 2026-11-19 at the end of the P1 prototype (spec
[docs/specs/2026-11-17-git-hosting-p1-design.md](../specs/2026-11-17-git-hosting-p1-design.md), 18 tasks).
The prototype is Kanboard 1.2.47 with the GitHost plugin, a Rust broker (`kanboard-git-broker`),
Forgejo 15.0.11 behind Traefik ForwardAuth, Authentik for humans, and one-job CI runners. It runs on
the :8081 dev stack. Every number below was measured there. The raw tables are in
[testing/git/metrics.md](../../testing/git/metrics.md).

**Verdict.** The chosen topology works. Kanboard project membership is the only authority for git
access, and it is checked live on every git and repo-API request. Revocation takes effect on the
next git operation, in 31-63 ms. Agents get 1-hour tokens limited to their project's repos. Their
branch writes are confined to `agent/<login>/**`: pushes from the start, and PR merges only since
the final review's fix (I1; before it an agent could merge a PR into another agent's prefix or a
human's branch, seen live). Since Task 18 they are also kept off every tag and every ref outside
branches and tags. An agent never gets Forgejo repo admin, whatever its Kanboard role (I2). Merges
to `main` need CI, an approval from a reviewer agent and, for high-risk paths, a human. Before the
final review's fix (C1) the reviewer's approval of an older head still counted, so an author could
push unreviewed code after approval and have it merged (seen live in the IT); `main` now dismisses
stale approvals. One Forgejo rule remains: a push that only drops part of an approved change keeps
the approval (see [Final whole-branch review](#final-whole-branch-review)). The second-model security review
found six High issues. Five are fixed, test-first (GH-01, 02, 03, 05, 06): GH-03 by tag protection
and, for notes, custom refs and AGit, by Forgejo configuration. GH-04 affects only the dev runner and has no
production counterpart. Five Medium issues were found: four are fixed, and the fifth (GH-10) is a
production network requirement, recorded below. The one Low issue is fixed. The worst
operational defect P1 found was the ForwardAuth deny storm under load, caused by the Kanboard
session write. It is fixed: at 50 concurrent agents every clone and push now succeeds through
ForwardAuth. Four things are left for the MVP spec. Refs outside branches and tags are refused
for humans too (AGit is off). ForwardAuth does not see web UI cookie sessions, and after a project
identifier change the old org's web-UI rights are never revoked. The production network must be
segmented. The final whole-branch review's fixes and what it left open are at the end.

## Metrics

Host: AMD Ryzen 7 3800X (14 threads), 78.5 GiB, Linux 7.0. All local, $0. The stack is shared
with other sessions. Kanboard is the dev image on SQLite with `pm.max_children = 5`, untuned on
purpose.

| Metric | Target (spec) | Result |
| --- | --- | --- |
| ForwardAuth decision latency | p95 < 50 ms | N=1: p95 25-38 ms (met). N=10: p95 77-81 ms; N=50: p95 156-196 ms, both on a saturated host (load average 27-29 on 14 threads) and with every call now reaching Kanboard. Missed at N≥10 |
| Clone / push overhead vs direct Forgejo, N=1 | < 20 % | clone p50 +15 % (p95 +28 %), push p50 +13 % (p95 +11 %), like-for-like `traefik-net` vs `direct` (T17, 20 samples) |
| Clone / push success through ForwardAuth, N=10 / N=50 | all succeed | Task 17: 21/50 (N=10) and 49/100 (N=50) through Traefik, about half denied `kanboard_unreachable`. Task 18, after the session fix: **50/50 and 100/100**, with 0 `kanboard_unreachable` and every credential minted |
| Clone / push latency at N=10 / N=50 | measured | through Traefik (host side): clone p50 1.3 s / 3.1 s, push p50 2.2 s / 5.5 s; direct to Forgejo: clone 1.2 s / 5.4 s, push 1.8 s / 8.9 s. Like-for-like overhead at N=10: clone p50 +22 %, push p50 +12 %; at N=50 Traefik was faster than direct (-15 % / -10 %), because ForwardAuth paces what reaches Forgejo. Busy host: compare within a run |
| Idle RAM | measured | Forgejo 163 MiB, Postgres 53, Traefik 19, broker 1.5, Kanboard 32; git overlay about 236 MiB. Authentik adds about 935 MiB (server 594, worker 265, its Postgres 76) and is the largest fixed cost |
| Loaded RAM, N=50 peak | measured | Forgejo 1279 MiB, its Postgres 187, Traefik 50, broker 11, Kanboard 60. Authentik flat |
| CI cold start, setup p50 / p95 | measured | (a) `vm-gvisor` 0.63 s / 1.25 s; (c) `kvm-kata` 17.39 s / 18.44 s (nested KVM); (b) PARKED |
| Revocation lag, git | next operation | 31-63 ms after `removeProjectUser`; no operation succeeded after removal (5 runs) |
| Revocation lag, web UI | measured | the private repo page turns 404 625-756 ms after removal (plugin nudge, then team sync); bound: one reconcile tick (30 s) if the nudge is lost, **except after a project identifier change**: the old `kb-<old>` org's teams are never emptied, so a member removed later keeps web-UI access to the old org's repos with no time limit (T8 gap, open decision 8). The cookie session itself is never revoked |
| Drift repaired after an injected sync failure | 100 % within one sweep | 2 of 3 recorded runs within the 35 s bound (25 s each); the third took 46 s because a token-delete backlog held up the sweep. After the backlog drained, every run repaired in 24-25 s |
| Agent tool calls, ticket to merged | measured | 10 (author 8, reviewer 2); the credential helper costs none |
| Setup | measured | four commands plus one env file; broker 8,945 lines of Rust in `src/`, plugin 2,208 lines of PHP, compose and config 735 lines, setup scripts 432 lines (T17 counts) |

### Load through ForwardAuth, before and after Task 18

Before (Task 17), about half of all git operations through Traefik were denied at N=10 and N=50.
The broker logged `kanboard body: trailing characters`. Kanboard had started and written a PHP
session for every HMAC authz call. Under concurrency the SQLite session INSERT failed with
`database is locked` after the JSON had been sent, and PHP appended the error to the response. The
broker failed closed on the malformed body, as specified, and denied the request
(`kanboard_unreachable`).

The fix is in GitHost `695a1dd`. The four broker-facing routes now start no session. That means
no cookie, no session read, no garbage collection and no write at shutdown. They also turn
`display_errors` off. Any exception becomes a bodyless 503 instead of text after the JSON. The
broker stays strictly fail-closed on malformed bodies.

| N | target | clone+push ok, T17 | clone+push ok, T18 | `kanboard_unreachable`, T17 → T18 | clone p50 / push p50, T18 |
| --- | --- | --- | --- | --- | --- |
| 10 | traefik | 21/50 | 50/50 | 29 → 0 | 1335 / 2241 ms |
| 10 | traefik-net | 25/50 | 50/50 | 25 → 0 | 1429 / 1965 ms |
| 10 | direct | 50/50 | 50/50 | – | 1169 / 1751 ms |
| 50 | traefik | 49/100 | 100/100 | 51 → 0 | 3130 / 5485 ms |
| 50 | traefik-net | 48/100 | 100/100 | 52 → 0 | 4526 / 7957 ms |
| 50 | direct | 100/100 | 100/100 | – | 5355 / 8885 ms |

The T18 runs were on a much busier host than T17's (load average 27-29), so the direct baseline
slowed too. Full tables, ForwardAuth latency and RAM: metrics.md, "Load through ForwardAuth after
the session fix".

## Verified behaviour: the spec's open questions (gaps 1-6)

| Gap | Question | Forgejo 15.0.11 / Kanboard 1.2.47 | Consequence for the MVP |
| --- | --- | --- | --- |
| 1 | Does the admin API create a user bound to an auth source? | Yes. `POST /api/v1/admin/users` with `source_id` + `login_name` (= Authentik `sub`) answers 201, and the first OIDC login lands in that user (same id). | Humans are pre-provisioned; no "link on first login". Keep `ENABLE_AUTO_REGISTRATION = false` and `ACCOUNT_LINKING = disabled`. |
| 2 | Does `prohibit_login` still allow token use? | No. A token answers 403 once `prohibit_login` is set. | Agents are local users with a random password the broker never stores, and login is not prohibited. `ENABLE_INTERNAL_SIGNIN = false` stops password web login for everyone. Traefik must also block `/user/link_account_signin` and `_signup`, because Forgejo 15 ignores that switch there. |
| 3 | Can the admin API mint a repo-scoped token for another user? | It can scope a token to repos (`repositories`, verified 200 on own repo, 403 on others). It cannot mint for another user, with or without `Sudo` (401), and cannot delete another user's token either. | The broker sets a fresh 32-byte password and basic-auths as the agent, one call per user at a time. Forgejo 15 tokens have no expiry, so the broker enforces the hour: the live check plus deletion in the sweep. |
| 4 | Does Forgejo pick the most specific branch protection rule? | No. Plain names win; among globs the first created that matches applies. Same-second rules come back in an undefined order. | The broker keeps the catch-all rule newest (re-created under an alternate spelling, `**` / `{**}`, a second later). The pre-receive-hook fallback was not needed for branches. Tags and other refs: see GH-03. |
| 5 | Are Actions secrets scopable to an environment bound to `main`? | No. Forgejo 15 has no environment secrets (no column, no endpoint). Repo and org secrets **are** handed to `push` workflows on same-repo `agent/*` branches (mutation proved it). | The fallback is load-bearing: secret-bearing workflows live only in a separate deploy repo, in an org outside the `kb-` namespace (`deploy-kb/app-deploy`). |
| 6 | Does the Kanboard OAuth2 plugin work on 1.2.47? | Yes, at master `607d0e6` (the only tag, v1.0.2, is from 2020 and lacks eight PHP commits). Supply-chain checked and hash-pinned. Groups sync only at login, and git authz never uses them. | Pin by commit, not tag, and re-check on update. |

## Runner isolation: recommendation

Run untrusted agent-branch CI on **option (a): a dedicated VM with Docker and gVisor (`runsc`) as the
default runtime, one job per runner process**, behind restricted egress (Traefik and an allowlist
proxy only).

- It passed every containment check in scenario step 5d. Jobs had no Docker socket, no volumes and
  no runner credential. They could not reach the runner VM's sshd or the host. They had no internet
  except the proxy, and the job cgroup was CPU 2 / 4 GiB with pids bounded on the sandbox.
- It starts a job in 0.63 s (p50) and 1.25 s (p95), and costs about 1.2 GiB RSS for a 4 vCPU VM.
- Locally it ran on the same physical host. In production it should be its own small VPS, so that a
  gVisor escape lands on a machine that holds nothing.

Option (c), Kata Containers (one microVM per job), gives the strongest per-job boundary. It passed
the same checks, but it needs `/dev/kvm` on the runner host. Measured under nested KVM it takes
17 s per job, which says little about bare metal. The MVP should check whether the production VPS
type exposes `/dev/kvm`. If it does, (c) on that host is the stronger choice for agent-branch jobs.

Option (b), same host with a separate rootless Docker daemon and gVisor, is **PARKED pending root
installs** on the host: `uidmap` (newuidmap), `slirp4netns` and `runsc`. No root-free path was
found. It was not measured. Its isolation story is weaker than (a) anyway, because it shares a kernel
with Kanboard.

The dev-only host-mode runner (`kb-git-ci-runner`, profile `ci-dev`, scenario steps 3, 4 and 6)
must not exist in production (GH-04).

Carried from Task 16 for the MVP:

- CI jobs have no read path through ForwardAuth for Forgejo's job token. Real CI needs a clone path.
- Deploy jobs should get their own runner label, so they never share a runner VM with agent jobs.
- The proxy relays plain HTTP to allowlisted hosts on any port.

## Web UI cookie sessions: decision

**Measured.** ForwardAuth checks every git and repo-API request, but not Forgejo pages served on a
session cookie (spec non-goal). Since the final review (I3) that includes the web UI's Raw, Download
ZIP, README images and LFS files (`raw/`, `archive/`, `media/`) when they carry no credential: before,
Traefik sent them through ForwardAuth, whose 401 made a signed-in browser show a Basic prompt (seen
live: 401 `realm="Kanboard git"` on all three). Now a cookie-only request reaches Forgejo's own
session check (200 for the signed-in member), an anonymous one gets Forgejo's 404 for a private repo,
and the same routes with a token are still checked by the broker (scenario preflight, identity and
step 2). Smart-HTTP and the git-lfs API stay behind ForwardAuth with or without a credential. After `removeProjectUser`, the human's API token is refused at once.
The private repo page in the signed-in browser turns 404 625-756 ms later, once the plugin's nudge
has made the broker drop the team membership. If the nudge is lost, the bound is one reconcile tick
(30 s). The session itself stays signed in. Forgejo is never told, and the user still sees every
repo they can still reach. The 30 s bound does not hold after a project identifier change (T8):
the broker syncs only the new `kb-<new>` org, and the old org, still a known org of a project that
exists, is never emptied (`reconcile.rs` `empty_dropped` skips present projects). Members removed
after the change keep their team rights on the old org's repos in the web UI, `managers` admin
included, with no time limit. Git and API access are unaffected (ForwardAuth asks Kanboard live).
Read from the code, not reproduced live.

**Recommendation: do not extend ForwardAuth to cookie sessions in the MVP.** Close the remaining
gap with offboarding instead:

1. Repo visibility in the web UI is already Forgejo team membership, which the broker converges in
   about 0.7 s, and at worst in 30 s. For a human removed from one project, that is the revocation
   path, and it is fast enough for read access to a web page.
2. For offboarding (the user is disabled in Kanboard or Authentik, or leaves every project), the
   broker should set `prohibit_login` on the human's Forgejo user. Gap 2 shows Forgejo then refuses
   that user's credentials. **The MVP must verify that an existing session is also dropped on its
   next request.** If it is not, add a session purge through the Forgejo CLI to the offboarding verb.
3. Putting cookie sessions behind ForwardAuth would mean resolving a Forgejo session to a user on
   every page view. That is a Forgejo round trip plus a Kanboard call, over routes the spec
   deliberately left to Forgejo. It would buy at most 30 s on a read path.

This is a product decision. If "no read after removal, ever" is required, the alternative is a
ForwardAuth rule for `/{org}/{repo}` web routes that resolves the session via `GET /api/v1/user` with
the cookie. Its cost would have to be measured first.

## Second-model security review (Task 18)

Codex reviewed the broker, the GitHost plugin, the Traefik config and `bootstrap.sh` statically
against the spec's trust boundaries. It ran read-only, with no Docker and no network. The
requested model was `gpt-5.6-sol` with `daybreak_blue`. The request was accepted, but the CLI does
not say which model served it: **requested, unverified**. Its findings were treated as claims. Each
was reproduced against the code, and against the dev stack where possible, before any fix. Every
fix has a failing test first.

| ID | Sev | Finding | Verified | Disposition |
| --- | --- | --- | --- | --- |
| GH-01 | High | Broker adopts any pre-existing `kb-<identifier>` org, and unrecorded repos in it | Yes (unit tests: sync of a pre-seeded org answered 200) | **Fixed** broker `8a92775`: `owned_orgs` records each org the broker creates, with its Forgejo id. A foreign org, another project's org, or a same-name org with a new id is refused (409) before any write. A repo with no row is never adopted. |
| GH-02 | High | Repo recorded before branch protection, so a protection failure leaves a known, unprotected repo | Yes (unit test: injected protection failure left the repo in ForwardAuth's map) | **Fixed** broker `8a92775`: rows are `pending` from before the create until protection and webhook succeed. Pending is `unknown_repo` to ForwardAuth and invisible to minting. A retry or the next sync activates it. |
| GH-03 | High | Branch rules do not cover tags or other refs | Yes, live: an agent pushed `refs/tags/x`, `refs/tags/release/x`, `refs/notes/*`, `refs/kb-probe/*`, and an AGit push to `refs/for/main/<topic>` opened PR #120 | **Fixed for tags** broker `6ff3b84` (IT `b282208`) + harness `8f29237`: one tag protection `*`; its allowlist is the human writers (the admin when there are none, because Forgejo refuses an empty one). Step 5a now asserts that git tag pushes and `POST /tags` are refused. **Notes, custom refs and AGit: fixed by configuration** (review round 1), harness: `[git.config] include.path` in `forgejo/app.ini.tmpl` loads `forgejo/receive.gitconfig` (`receive.hideRefs = refs/`, `!refs/heads/`, `!refs/tags/`). git receive-pack refuses any update to a hidden ref, and Forgejo already relies on this for `refs/pull/` (its own default entry). Probed live: before, the agent pushed `refs/notes/x`, `refs/custom/x` and `refs/for/main/<topic>` (exit 0; the AGit push opened PR #129); after, all three are refused with "deny updating a hidden ref", while `agent/<login>/x` and a human's branch push are still accepted. Step 5a checks the three refs (RED 16/24 before, 24/24 after). Cost: it is instance-wide, so humans can no longer push notes, custom refs or AGit `refs/for/*` (a human `refs/notes/x` push was refused in the probe). P1's flow does not use them; fetching such refs is unaffected. |
| GH-04 | High | Dev host-mode runner exposes its registration secret to jobs | Yes: `RUNNER_SECRET` is in the env of `kb-git-ci-runner`, which runs jobs in host mode as the same uid | **Not fixed: dev-only.** The runner exists for scenario steps 3, 4 and 6 (T14 ruling). The isolation options deliver the secret as a systemd credential the job never sees (T16, 5d). Production must use only isolated one-job runners. |
| GH-05 | High | Public PHP machine routes: SQLite lock DoS and diagnostic leakage | Yes, live: each signed authz call set `KB_SID` and added a `sessions` row (210 → 211). T17 load: about 50 % `kanboard_unreachable` at N=10 and N=50 | **Fixed** GitHost `695a1dd` (the session and diagnostics fix above). Live after: no cookie, sessions unchanged. Load: N=10 50/50 and N=50 100/100 clone+push through Traefik (were 21/50 and 49/100) |
| GH-06 | High | Bootstrap puts the admin token in process arguments | Yes: `token_status` passed it in `-H` to `docker exec` and curl | **Fixed** harness `80ac253`: sent on stdin as a curl config. `testing/git/bootstrap-argv-test.sh` runs the helpers against a shim `docker` with a sentinel token. RED: it found the token in argv. GREEN: argv clean, stdin carries it. |
| GH-07 | Medium | One HMAC key for both directions, against the spec | Yes (`GITHOST_HMAC_SECRET` on both sides) | **Fixed** broker `c3e6dc8`, GitHost `df82f80`, harness `b7bac09`: `KANBOARD_TO_BROKER_SECRET` and `BROKER_TO_KANBOARD_SECRET`. The broker will not start if they are equal, and the plugin treats equal as unconfigured. Spec correction: with HMAC each side must hold both keys, so the spec's "Kanboard holds only the call-broker secret" is impossible. Getting it would take asymmetric signatures (MVP decision). |
| GH-08 | Medium | Broker replay cache is memory only, so replays work after a restart | Yes (unit test: same request accepted by a new `AppState` over the same DB) | **Fixed** broker `bdb103a`: accepted nonces are also stored in SQLite for 120 s. A DB error fails closed (503). |
| GH-09 | Medium | Human token authz binds to a mutable login, not the Forgejo id | Yes (authz table row: same login, other id answered 200 allow) | **Fixed** broker `9e49934`: `whoami` returns `{id, login}`, and both must match the principal. |
| GH-10 | Medium | Control plane and databases share one Docker network | Yes, live: from `kb-suite`, `kb-git-db:5432`, `kb-auth-db:5432` and `forgejo:3000` (bypassing ForwardAuth) are all open | **Not fixed: dev topology.** All of the shared dev stack sits on one compose network. Splitting it would recreate shared databases and break the load baseline (`direct`) and `broker-it`. **MVP requirement:** each database on a private network with its one application; Forgejo reachable only from Traefik and the broker. |
| GH-11 | Medium | Task status and revert comments trust the task id in the branch, not the login | Yes (unit test: `agent/outsider/<task>-x` wrote the panel) | **Fixed** GitHost `54b5b20`: the branch login must be the Forgejo login (the broker's rule) of the task's or a subtask's assignee, otherwise `branch_login_not_assigned`. |
| GH-12 | Low | Plugin accepts expired, overlong or foreign-repo credentials from the broker | Yes (unit tests) | **Fixed** GitHost `4393d0c`: expiry must be in `(now, now + 1 h + 60 s]`, and every repo must be one of the project's. Otherwise `broker_unavailable`, with nothing recorded. |

Also from the Task 17 carry-overs. `BROKER_FAULT_SYNC_ONCE` now names the project whose nudge fails
(broker `f1d146d`, harness `b44f7b7`), so another session's nudge cannot consume step 9's fault.

## Open decisions for the MVP spec

1. **Refs outside branches and tags.** P1 refuses them with configuration
   (`receive.hideRefs`, GH-03), for every pusher. The MVP decides whether that is the policy:
   keep it (no AGit, no pushed notes for humans either), or, if humans need AGit or notes, scope
   the refusal to agents with a pre-receive hook asking the broker (needs Forgejo git hooks, the
   trust trade-off the spec named). The production `app.ini` must carry the same `[git.config]`
   include.
2. **Web UI revocation.** Adopt the offboarding path above, or put cookie sessions behind
   ForwardAuth. Either way, verify that `prohibit_login` ends an existing session.
3. **Network segmentation** (GH-10) in the Dokploy deployment, as above.
4. **Channel keys.** Keep directional HMAC (both sides hold both keys), or move to Ed25519, so each
   side holds only its own signing key. Ed25519 would meet the spec's original wording.
5. **Kanboard's database.** The session fix removed the lock storm. Each broker call still writes a
   nonce row. Measure Kanboard on SQLite at the MVP's agent count, or run it on Postgres.
6. **Runner placement.** Use (a) on a separate VPS, or (c) where `/dev/kvm` exists. Decide whether
   (b) is worth the root installs. Give deploy jobs their own runner. Add a clone path for jobs.
7. **Sweep latency.** Bound the token deletes per sweep, or run them after the state sync, so drift
   repair stays within one tick after mass revocations (T17).
8. **Org lifecycle.** An org now belongs to one project for good. A deleted project's identifier
   cannot be reused by a new project (409) until an operator moves or deletes the org. Orgs created
   before Task 18 were carried over on trust (their id learnt at the next sync). The MVP needs an
   operator verb for this, and a clean migration. **Identifier changes** leave the old org's teams
   populated for good (T8 gap above): web-UI rights of later-removed members, admin included, are
   never revoked there. Cheap P1-level mitigation: the plugin refuses identifier changes for
   projects with repos. The MVP should rename or empty the old org.
9. **Plugin and broker repo drift.** The dev plugin has a repo row (`kb-ghe2e/githost-e2e`) that
   neither the broker nor Forgejo has. Reconcile should report repo rows on either side that the
   other lacks.
10. **Stale approvals: partial reverts.** Forgejo 15 dismisses an approval on push only when the
    new head differs, on some path, from both the approved head and the base. A push that only
    drops part of the approved change keeps the approval and merges (seen live, scenario PR #141).
    Each file is then one the reviewer saw, but the combination is not what they approved. If that
    matters, the broker's gate could stay `pending` until an official approval names the current
    head (the auto-merge restore already requires that).
11. **Authenticated authz answers** (final review M1). The broker signs its authz request to
    Kanboard, but not Kanboard's `{allow}` reply. On GH-10's flat network anything that can answer
    as `kb-suite` can say "allow". Sign the reply over the request nonce, or make GH-10's
    segmentation a hard requirement.
12. **Minted tokens and admin API paths.** Defence in depth: the broker could deny minted tokens on
    `branch_protections`, `tag_protections`, `hooks`, `collaborators` and repo `PATCH`/`DELETE`,
    whatever the agent's Forgejo rights.

## Final whole-branch review

The final review (read-only) found one Critical and four Important issues. Each was reproduced on
the dev stack before the fix, and each fix has a failing test first.

| ID | Finding | Seen before the fix | Disposition |
| --- | --- | --- | --- |
| C1 | Stale approvals counted on `main`, and the broker's auto-merge restore re-scheduled without checking them | IT: rev scheduled and approved head X, dev pushed Y adding a file, CI and gate green on Y: the PR merged, by rev | **Fixed** broker `278e659` (the restore needs an official approval of the current head; unit test with no, older-head, stale, unofficial and rejecting reviews) and `d6cbca3` (`main`: `dismiss_stale_approvals` and `ignore_stale_approvals`). The IT and scenario step 3 now check that Y stays open, that the author's own merge is 405, and that r1's approval of Y merges it. Partial reverts: open decision 10 |
| I1 | Agents could merge PRs into other protected branches (no merge allowlists) | IT: dev's PR into `agent/<rev>/target` merged, 200 | **Fixed** broker `d6cbca3`: every glob rule's merge allowlist is its push allowlist (catch-all: the human writers, or nobody). IT: dev's merges into rev's prefix and into a human's branch are refused, the owners' merges allowed. Scenario 5a through Traefik: `405 User not allowed to merge PR`, a2's branch unchanged |
| I2 | An agent with the Kanboard project-manager role became a Forgejo repo admin (`managers`) | `StateBuilderTest` pinned an agent in `managers` | **Fixed** GitHost `3759188` (an agent manager is listed in `members`; managers and humans_high_risk hold humans only) and broker `f6796fc` (defence in depth: sync never puts an agent principal in `managers` or `humans-high-risk`; one listed as a manager gets `members`) |
| I3 | Signed-in humans got a Basic prompt on raw, archive and media | without a credential: 401 `realm="Kanboard git"`; with a session cookie: 401 | **Fixed** harness `8ca8321` (Traefik): those routes reach ForwardAuth only with a credential. Scenario: anonymous 404, cookie 200, agent token 200 with an `ok` audit line |
| I4 | This document overclaimed | | Corrected above (verdict, web-UI bound, open decisions 8 and 10-12) |
| M3 | An active repo deleted in Forgejo fails its project's sync on every tick; the sync comment said it is dropped | | Comment fixed, broker `f6796fc`; logic left for the MVP (fails closed) |
| M4 | A token create's unexpected-status error echoed Forgejo's body, which carries the token | unit test: the mint answer held the token's `sha1` | **Fixed** broker `cd3dec3`: token-endpoint errors carry a redacted body |
| M1, M2 | Unsigned authz replies; the authz timeout audit line drops principal and repo | | Recorded: open decision 11; M2 for the MVP (the fallback needs `parse_target`'s result threaded out) |
| M5 | `githost_repos.forgejo_full_name` is unique case-sensitively on SQLite and Postgres | | Recorded. **The MySQL and Postgres schema (`GitHost/Schema/Mysql.php`, `Postgres.php`) has never been executed**: P1 ran SQLite only. Run it once before any non-SQLite install |
| T14 | An older, narrower Forgejo admin token was still valid on the dev stack | | Deleted (its Forgejo `access_token` row); the current token in `testing/git/.env` still answers 200 |
