#!/bin/bash
# provision.sh — runs once inside a runner VM while vm.sh provisions it (cloud-init runcmd, as
# root, with ordinary user-mode networking). Usage: provision.sh <seed-url> <option-dir>
# Installs Docker from Debian (pinned versions, apt-verified), re-verifies each downloaded artifact
# against its pin, installs the option's runtime and forgejo-runner, builds the job image, smoke-
# tests one job container, prints KB-PROVISION-OK on the serial console and powers off.
set -euo pipefail
SEED="$1"
OPT="$2"
say() { echo "KB-PROVISION $*" >/dev/ttyS0; echo "KB-PROVISION $*"; }
trap 'say FAILED at line $LINENO; sync; poweroff' ERR
get() { python3 -c 'import sys,urllib.request as u; u.urlretrieve(sys.argv[1], sys.argv[2])' "$SEED/$1" "$2"; }

mkdir -p /root/kb /etc/kb-runner
cd /root/kb
for f in pins daemon.json runner.yaml kb-runner.sh kb-runner.service kb-jobs-firewall.service \
  jobs-firewall.nft job.Dockerfile; do
  get "files/$f" "$f"
done
say "start ${OPT}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  docker.io=26.1.5+dfsg1-9+deb13u1 docker-cli=26.1.5+dfsg1-9+deb13u1 containerd=1.7.24~ds1-6+deb13u1 runc=1.1.15+ds1-2+b4 \
  nftables zstd >/dev/null
say "docker $(dpkg-query -W -f '${Version}' docker.io)"

# Each artifact again, checked against the pin vm.sh passed (the same pins as fetch.sh).
while read -r name file algo hash; do
  get "dl/$file" "$file"
  echo "$hash  $file" | "${algo}sum" -c --quiet
  say "verified $name $file ($algo)"
  case "$name" in
    runner) install -m 0755 "$file" /usr/local/bin/forgejo-runner ;;
    runsc)
      # runsc, its shim and gvisor-bin/ (the sentry sidecar runsc starts from beside itself).
      tar --zstd -xf "$file" -C /usr/local/bin
      say "runsc $(/usr/local/bin/runsc --version | head -1)" ;;
    kata)
      # Only what the QEMU hypervisor path needs (the tarball also carries GPU and TEE images).
      tar --zstd -xf "$file" -C / --exclude='*nvidia*' --exclude='*confidential*' \
        --exclude='*mariner*' --exclude='*-debug*' --exclude='*experimental*' --exclude='*initrd*'
      ln -sf /opt/kata/bin/containerd-shim-kata-v2 /usr/local/bin/containerd-shim-kata-v2
      mkdir -p /etc/kata-containers
      cp /opt/kata/share/defaults/kata-containers/configuration-qemu.toml /etc/kata-containers/configuration.toml
      # Kata drops the OCI pids limit from the guest's container cgroup and applies it to the
      # sandbox's cgroup here, where it counts QEMU's threads, not the job's processes. The
      # guest VM belongs to one job, so cap the guest kernel instead: threads-max 1024.
      sed -i 's/^kernel_params = "\(.*\)"$/kernel_params = "\1 sysctl.kernel.threads-max=1024"/' \
        /etc/kata-containers/configuration.toml
      grep -q 'threads-max=1024"$' /etc/kata-containers/configuration.toml
      echo vhost_vsock >/etc/modules-load.d/kata.conf
      modprobe vhost_vsock
      say "kata $(/opt/kata/bin/kata-runtime --version | head -1); /dev/kvm: $(test -c /dev/kvm && echo yes || echo no)" ;;
  esac
  rm -f "$file"
done <pins

# The job image, built with the stock runtime before the option's runtime becomes the default.
mkdir -p empty
docker build -q -t kb-ci-job:1 -f job.Dockerfile empty >/dev/null
say "job image kb-ci-job:1 $(docker image inspect -f '{{.Id}}' kb-ci-job:1)"

install -m 0644 daemon.json /etc/docker/daemon.json
systemctl restart docker
say "docker default runtime: $(docker info -f '{{.DefaultRuntime}}')"
# Smoke test: one container with the job limits, under the default runtime.
smoke="$(docker run --rm --cpus 2 --memory 4g --pids-limit 1024 kb-ci-job:1 sh -c \
  'echo "kernel $(uname -r) | dmesg $(dmesg 2>/dev/null | head -1) | cpu $(cat /sys/fs/cgroup/cpu.max 2>&1) | mem $(cat /sys/fs/cgroup/memory.max 2>&1) | pids $(cat /sys/fs/cgroup/pids.max 2>&1)"' 2>&1)" ||
  { say "smoke FAILED: $(echo "$smoke" | tr '\n' ' ')"; false; }
say "smoke: $(echo "$smoke" | tr '\n' ' ')"

# The runner: an unprivileged user in the docker group, the service and its config template.
useradd --system --create-home --home-dir /var/lib/kbrunner --groups docker kbrunner
install -m 0755 kb-runner.sh /usr/local/libexec/kb-runner.sh
install -m 0644 runner.yaml /etc/kb-runner/runner.yaml
install -m 0644 kb-runner.service kb-jobs-firewall.service /etc/systemd/system/
install -m 0644 jobs-firewall.nft /etc/kb-runner/jobs-firewall.nft
# Run phase names (vm.sh guestfwd): Traefik and the registry proxy.
printf '10.0.2.100 git.localhost\n10.0.2.101 kb-proxy\n' >>/etc/hosts
systemctl enable kb-jobs-firewall.service kb-runner.service
# The run phase has no network for these, and no datasource for cloud-init.
systemctl disable apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true
touch /etc/cloud/cloud-init.disabled
apt-get clean
fstrim -av >/dev/null 2>&1 || true
say "OK ${OPT}"
sync
poweroff
