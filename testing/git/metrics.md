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
