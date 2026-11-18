#!/usr/bin/env bash
# up.sh — option (b), PARKED in P1: the runner on this host, under a separate rootless Docker
# daemon (rootlesskit, its own socket and data root) with gVisor (runsc) as its default runtime.
# It checks the prerequisites and exits 3 with the human steps; nothing here needs root, but the
# prerequisites do, and the P1 dev host has no passwordless sudo.
#
# Why it cannot run without root:
#   - newuidmap/newgidmap (package uidmap) are setuid-root helpers. rootlesskit needs them to map
#     the subordinate ID range (/etc/subuid) into the daemon's user namespace; an unprivileged
#     process may map only its own uid, and image layers own files as other uids.
#   - slirp4netns (or pasta) gives the rootless daemon its network namespace's uplink.
#   - runsc is a static binary and could live in a user path; the spec's manual step installs it
#     system-wide, so this config expects /usr/local/bin/runsc (the same pin as fetch.sh).
#
# The intended shape once they exist (daemon.json, runner.yaml beside this file):
#   dockerd-rootless.sh --config-file <this dir>/daemon.json \
#     --data-root ~/.local/share/kb-ci-rootless --exec-root "$XDG_RUNTIME_DIR/kb-ci-rootless" \
#     --host "unix://$XDG_RUNTIME_DIR/kb-ci-rootless/docker.sock"
#   kb-ci-jobs: an internal network of that daemon; the gateway container (socat to Traefik on
#   host 127.0.0.1:8090 through rootlesskit's port driver, and to the ci-proxy) the only other
#   member; then forgejo-runner one-job against that socket with runner.yaml, as in guest/.
# The rootless daemon's slirp4netns network has no allowlist of its own, so egress control rests
# on the internal network, unlike (a) and (c) where QEMU's restrict=on is the outer fence.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
missing=()
for bin in newuidmap newgidmap slirp4netns rootlesskit dockerd-rootless.sh; do
  command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
[[ -x /usr/local/bin/runsc ]] || missing+=("/usr/local/bin/runsc")
grep -q "^$(id -un):" /etc/subuid 2>/dev/null || missing+=("/etc/subuid entry for $(id -un)")
grep -q "^$(id -un):" /etc/subgid 2>/dev/null || missing+=("/etc/subgid entry for $(id -un)")

if ((${#missing[@]})); then
  cat >&2 <<EOF
up.sh (b-rootless-gvisor): PARKED. Missing: ${missing[*]}
Human steps (root), then run this again:
  sudo apt-get install -y uidmap slirp4netns
  bash testing/git/runners/fetch.sh runsc        # pinned gVisor 20261102.0, SHA512-verified
  sudo tar --zstd -xf testing/git/runners/.cache/dl/gvisor-20261102.0.tar.zstd -C /usr/local/bin
  # Only if /etc/subuid or /etc/subgid lacks $(id -un):
  sudo usermod --add-subuids 165536-231071 --add-subgids 165536-231071 $(id -un)
Ubuntu restricts unprivileged user namespaces (kernel.apparmor_restrict_unprivileged_userns=1);
rootlesskit's AppArmor profile (/etc/apparmor.d/rootlesskit) is what allows it here.
EOF
  exit 3
fi
echo "up.sh (b-rootless-gvisor): prerequisites present; the start path is not built in P1" \
  "(option b is parked; see the comments in $HERE/up.sh)" >&2
exit 3
