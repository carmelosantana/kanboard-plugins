# Git hosting P1 metrics

## Idle RAM (Task 2, 2026-11-18)

Git stack up, bootstrapped, no repos, no traffic for ~20 s. Host: Linux, 78.5 GiB.
`docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' kb-git-traefik kb-git-forgejo kb-git-db kb-git-broker kb-suite`

```
NAME             CPU %     MEM USAGE / LIMIT
kb-git-traefik   0.00%     18.77MiB / 78.53GiB
kb-git-forgejo   0.10%     163MiB / 78.53GiB
kb-git-db        0.00%     52.89MiB / 78.53GiB
kb-git-broker    0.00%     1.465MiB / 78.53GiB
kb-suite         0.00%     32.47MiB / 78.53GiB
```

Git overlay total: about 236 MiB idle (Forgejo 163, Postgres 53, Traefik 19, broker 1.5).
Authentik is not in the stack yet; the spec's 1.5–2.5 GB estimate includes it.

Images: Forgejo 15.0.11-rootless, Postgres 17.12-alpine3.24, Traefik v3.7.19, broker built
locally (`kanboard-git-broker:dev`, 13.7 MB).

## Hostname note

Host side: `http://git.localhost:8090` (Traefik's only published port, 127.0.0.1). Container side:
the same URL, via Traefik's network alias `git.localhost` and its in-network `:8090` entrypoint.
Docker DNS resolves the alias in containers, but curl/libcurl (and so `git` and PHP's curl
extension) pin `*.localhost` to loopback, so in-container clients need
`--resolve`/`http.curloptResolve` until the hostname question is settled.

## CI cold start per isolation option (Task 16, 2026-11-18)

`bash testing/git/scenario.sh coldstart` (20 `workflow_dispatch` runs per option, one at a time,
each 5 s after the one-job runner restarted). Job: one `echo` step in `kb-ci-job:1` with the spec
limits (CPU 2, 4 GiB, pids 1024). Neither runtime shows the pids limit in the job's own cgroup
(`max`): on (a) it is enforced on the gVisor sandbox's cgroup on the runner VM (exceeding it kills
the sandbox); on (c) the job's guest kernel is capped at `threads-max` 1024, about 936 usable
beside the guest's own threads (exceeding it kills the guest).

- **setup**: the runner's own log timestamps, "received task" to the first step's output. One
  clock. This is the runtime's container start (sandbox or microVM) plus act's job setup.
- **end-to-end**: dispatch returned on the host to the first step's output, corrected by the VM's
  measured clock skew (no NTP in the restricted VM: -510 ms for (a), -688 ms for (c) this run).
  It adds Forgejo's queueing and the runner's 2 s poll (`fetch_interval`), so about 1 s of it is
  polling, the same for every option.

| Option | What it really is | n | setup p50 | setup p95 | end-to-end p50 | end-to-end p95 |
| --- | --- | --- | --- | --- | --- | --- |
| (a) `vm-gvisor` | Same physical host, separate kernel: a QEMU/KVM VM (4 vCPU, 6 GiB, no nested virtualization) with Docker 26.1.5 and gVisor `runsc` release-20261102.0 (systrap) as the default runtime | 20 | 0.63 s | 1.25 s | 2.15 s | 2.92 s |
| (b) `rootless-gvisor` | PARKED: needs root apt installs (`uidmap`, `slirp4netns`) and `runsc` on the host; see `testing/git/runners/b-rootless-gvisor/up.sh` | – | – | – | – | – |
| (c) `kvm-kata` | Kata Containers 4.3.1 (Go shim, QEMU) as the default runtime of a QEMU/KVM VM (4 vCPU, 9 GiB): each job is an L2 microVM under nested KVM | 20 | 17.39 s | 18.44 s | 18.48 s | 19.97 s |

Hardware: AMD Ryzen 7 3800X (7 cores / 14 threads visible), 78.5 GiB RAM, Linux 7.0.0-43-generic,
QEMU 8.2.2 with KVM (`kvm_amd nested=1`), ZFS-backed thin qcow2 disks. Cost: $0 (local), not the
spec's $5–20/month separate VM, so (a) is not a separate-host measurement (ruling F15).

Caveats: (c) pays nested virtualization: the L2 job VM boots under an L1 VM, so 17 s says little
about Kata or Firecracker on a host with direct `/dev/kvm`. Whether the production VPS type exposes
`/dev/kvm` is still an open MVP question. Footprint after the runs: QEMU RSS 1.2 GiB (a) and
1.6 GiB (c); provisioned overlays 958 MiB (a) and 1.9 GiB (c) on top of
the 325 MiB Debian image; the allowlist proxy (tinyproxy) 2 MiB.

## Load, latency, revocation, drift (Task 17, 2026-11-18)

Host as above (Ryzen 7 3800X, 14 threads, 78.5 GiB), the shared dev stack as it runs for other
sessions: kb-suite is the dev Kanboard (SQLite, php-fpm `pm.max_children = 5`), not tuned for
this run (ruling: record what breaks, do not tune). Repo `kb-ghe2e/app` (private, ~290 KiB).

**How:** `bash testing/git/load.sh run <N> traefik traefik-net direct` with `LOAD_ROUNDS` 20 (N=1),
5 (N=10) and 2 (N=50). Agents `load-a1..load-a50` are Kanboard agents on the :8081 dev stack
(PDO + JSON-RPC, idempotent), each with one open task. Each round mints N credentials at once
(`git-credential-kanboard get` -> `getGitCredential` -> broker `POST /tokens`), then runs N
clone + commit + push at once. Clone and push are per-agent wall times; percentiles are nearest
rank over every agent of every round; push times are over the agents whose clone succeeded.
Targets (ruling F11, Forgejo has no host port):

- `traefik`: git on the host to `http://git.localhost:8090` (Traefik + ForwardAuth). The agent path.
- `traefik-net`: git in a throwaway container (the pinned Forgejo image) on the overlay network
  to Traefik's `:8090`.
- `direct`: the same container to `http://forgejo:3000`, with no Traefik and no ForwardAuth. This
  is the baseline; the token is the same broker-minted Forgejo token.

`traefik-net` against `direct` is the like-for-like overhead (same client, same network).

### Clone / push latency (ms) and success

| N | target | credential p50 / p95 | clone p50 / p95 | push p50 / p95 | clone ok | clone+push ok |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | traefik | 546 / 575 | 355 / 385 | 488 / 568 | 20/20 | 20/20 |
| 1 | traefik-net | 555 / 574 | 400 / 478 | 511 / 534 | 20/20 | 20/20 |
| 1 | direct | 534 / 624 | 349 / 372 | 454 / 483 | 20/20 | 20/20 |
| 10 | traefik | 1037 / 1573 | 545 / 670 | 572 / 621 | 27/50 | 21/50 |
| 10 | traefik-net | 1063 / 1631 | 657 / 789 | 623 / 744 | 35/50 | 25/50 |
| 10 | direct | 1099 / 1779 | 797 / 999 | 1030 / 1203 | 50/50 | 50/50 |
| 50 | traefik | 3139 / 4942 | 2310 / 3312 | 2561 / 3565 | 57/100 | 49/100 |
| 50 | traefik-net | 4145 / 6333 | 2952 / 3809 | 3091 / 4164 | 54/100 | 48/100 |
| 50 | direct | 4201 / 6366 | 3999 / 5152 | 5321 / 6769 | 100/100 | 100/100 |

**Overhead at N=1** (`traefik-net` vs `direct`): clone p50 +15 % (p95 +28 %), push p50 +13 %
(p95 +11 %). The p50 meets the spec's "< 20 %"; the clone p95 does not, on 20 samples. The
host-side `traefik` run against `direct` shows clone +2 % and push +7 % at p50, but its client
differs.

**N=10 and N=50 fail through Traefik.** Every credential minted (0 failures at any N). But 23
of 50 clones at N=10 and 43 of 100 at N=50 got HTTP 403 through Traefik, and so did 6 to 10
pushes after a good clone. Every failure is a ForwardAuth deny `kanboard_unreachable`: 29 at
N=10 (`traefik`), 51 at N=50. Direct to Forgejo, everything passed. **The highest level that passed
through Traefik is N=1.** The cause is in kb-suite, not in the broker, Traefik or Forgejo. The
broker logs `kanboard body: trailing characters at line 1 column 29`, which is the authz JSON
`{"allow":true,"reason":"ok"}` (28 bytes) followed by a PHP fatal. kb-suite logs `PicoDb\SQLException
... database is locked` from `SessionHandler->write()` in `session_write_close()`. Kanboard
starts and writes a PHP session for the HMAC authz route, and concurrent session inserts lock
the dev SQLite database. The broker treats that body as no answer and fails closed, as
specified. The latencies of the passing Traefik ops at N=10 and N=50 are lower than direct
because about half the load never reached Forgejo, so they cannot be compared. To fix for the
MVP: the authz and state routes should not open a session (or Kanboard should run on
Postgres/MySQL), and the 50-agent run should be repeated after the fix. Task 18 decides.

### ForwardAuth decision latency (broker audit `latency_ms`, every authz line in the window)

| N | target | n | p50 | p95 | max | decisions |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | traefik | 140 | 16 ms | 25 ms | 32 ms | allow/ok 100, deny/no_principal 40 |
| 1 | traefik-net | 140 | 19 ms | 38 ms | 69 ms | allow/ok 100, deny/no_principal 40 |
| 10 | traefik | 244 | 16 ms | 41 ms | 87 ms | allow/ok 138, deny/no_principal 77, deny/kanboard_unreachable 29 |
| 10 | traefik-net | 277 | 17 ms | 46 ms | 125 ms | allow/ok 167, deny/no_principal 85, deny/kanboard_unreachable 25 |
| 50 | traefik | 504 | 32 ms | 148 ms | 212 ms | allow/ok 296, deny/no_principal 157, deny/kanboard_unreachable 51 |
| 50 | traefik-net | 487 | 30 ms | 77 ms | 133 ms | allow/ok 281, deny/no_principal 154, deny/kanboard_unreachable 52 |

p95 < 50 ms holds at N=1 and N=10 and fails at N=50. The window is every authz line of the
shared stack in the run's seconds, so another session's git traffic would be pooled in. The
counts match the expected two `no_principal` per agent exactly, so none was. `no_principal` is git's first request
without a credential, the 401 challenge, two per agent (clone and push).

### RAM (MiB, `docker stats`, peak of ~1 s samples over each run)

Each cell is the highest peak over the three targets. The Forgejo cells at N=10 and N=50 name
the target, because direct pushes reached Forgejo twice as often as the Traefik runs, where about
half was denied before Forgejo.

| Component | idle before | N=1 peak | N=10 peak | N=50 peak | idle after |
| --- | --- | --- | --- | --- | --- |
| Traefik | 23.3 | 30.1 | 33.7 | 44.1 | 41.8 |
| Forgejo | 164.4 | 212.9 | 456.0 (direct) | 1115.1 (direct) / 747.5 (Traefik) | 207.4 |
| Postgres (Forgejo) | 66.2 | 70.4 | 68.9 | 143.4 | 87.7 |
| Broker | 3.4 | 6.5 | 7.4 | 10.5 | 9.1 |
| kb-suite (Kanboard) | 45.2 | 46.5 | 50.6 | 61.1 | 44.8 |
| Authentik server / worker / db | 594 / 265 / 76 | flat | flat | flat (594 / 265 / 77) | – |

Authentik takes no part in git traffic. Its ~935 MiB is the largest fixed cost of the stack.

### Revocation lag

`bash testing/git/load.sh revlag`, 5 runs:

- **git**: an agent token minted while the agent was a member. After `removeProjectUser` returns,
  the very next `git fetch` is denied (ForwardAuth asks Kanboard every request). It took 31-63 ms,
  and no operation succeeded after removal. Scenario step 7 asserts this.
- **web UI** (Forgejo cookie session from the Authentik login; not ForwardAuth-checked in P1 by
  spec): the human's API token through Traefik is 403 at once. The private repo page in the
  signed-in browser session turns 404 625-756 ms after removal. That is the plugin's nudge, then
  the broker's sync dropping the team membership. The session itself stays signed in: Forgejo
  is not told, and the lag is bounded by the nudge, or by one reconcile tick (30 s) if the nudge
  is lost (step 9's case). Re-adding gives access back within 1 s.

### Drift repair

Scenario step 9 recreates the broker with `BROKER_FAULT_SYNC_ONCE=1` (cargo feature
`fault-injection`, dev build only). It removes a2 from the project, and the nudge's sync fails
with 500 before it touches Forgejo, so a2 stays in team `members`. The reconcile loop then has
to make all five Forgejo teams equal the plugin's `githost/state` again. The step's bound is one
tick (`BROKER_RECONCILE_SECS` = 30) plus 5 s for the sweep, so 35 s.

Observed, one injected failure per run, three recorded runs: **2 of 3 repaired within the 35 s
bound**, both at 25 s. The third run took 46 s and missed the bound, so step 9 failed there. The
cause was the sweep's token-delete backlog, described below. After the backlog drained, every run
repaired within the bound (24-25 s, including the fix-round runs). The spec target, "100 % within
one sweep", holds only when the sweep is not held up by token deletes.

The bound assumes a fast sweep. Each sweep first deletes expired and revoked tokens in Forgejo
(`tokens::sweep`), and only then pulls the state. Right after `load.sh teardown 50` had revoked a
few hundred load-agent tokens, the broker's first sweep spent over 60 s on these deletes. In
that `scenario.sh all` run, the drift was repaired 46 s after the failure, so the step failed. Once
the backlog drained, a sweep took about 1 s again. MVP note: bound the token deletes per sweep,
or run them after the state sync, so that repair latency does not depend on the token backlog.

### Agent tool calls, ticket -> merged

Counted from the scenario's happy path (steps 1 -> 3, `scenario.sh`). One call is one action an
agent issues: a Kanboard API call, a git command, a file edit or a Forgejo API call. The
credential helper is invisible to the agent: git calls it, so it costs no call. Author `a1`, 8
calls: claim the task (1 Kanboard call), clone, branch, edit, add, commit, push, open the PR (1
Forgejo API call). Reviewer `r1`, 2 calls: schedule auto-merge, approve. **Total: 10** (11 if the
reviewer reads the diff first). CI, the risk gate, the merge and the task panel are not agent
calls.

### Setup steps and lines of glue

`tokei` is not installed, so the counts are `git ls-files` + `wc -l` (lines including comments and
blanks) at broker 37d475a, GitHost 37cac30, and the harness at this commit.

| Part | Lines |
| --- | --- |
| Broker (Rust): `src/` | 8,945 |
| Broker: `tests/` | 9,209 |
| GitHost plugin (PHP incl. templates), without `Test/` | 2,208 |
| GitHost `Test/` | 3,139 |
| Compose and config: `docker-compose.git-overlay.yml` 269, Traefik `dynamic.yml` 95, Authentik blueprints 94, Forgejo `app.ini.tmpl` 95, `php-fpm-env.conf` 182 | 735 |
| Setup scripts: `bootstrap.sh` 358, `compose.sh` 24, `fetch-oauth2.sh` 50 | 432 |
| CI isolation runners (`runners/`, `runner/`, Task 16) | ~500 shell plus configs |
| Test harness: `scenario.sh` 2,013, `load.sh` 451 | 2,464 |

Setup takes four commands: compose up, `bootstrap.sh`, recreate kb-suite once, and the dev runner
(testing/README.md). It also takes one manual step, `testing/git/scenario.env` with the Kanboard
admin and agent API credentials. The runner VMs of Task 16 need their own `up.sh`, and option
(b) needs root.
