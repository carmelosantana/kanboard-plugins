#!/usr/bin/env bash
# vm.sh — host side of the VM-based isolation options (Task 16): (a) a-vm-gvisor and (c) c-kvm.
# Each option directory holds vm.env (size, label, ports), daemon.json (the VM's Docker runtime)
# and runner.yaml (the forgejo-runner config template); guest/ holds what runs inside the VM.
#
#   bash testing/git/runners/vm.sh up <option-dir>      provision if needed, boot, wait for the runner
#   bash testing/git/runners/vm.sh down <option-dir>    power off and drop the per-boot disk
#   bash testing/git/runners/vm.sh status <option-dir>
#   bash testing/git/runners/vm.sh ssh <option-dir> [command]    debug shell (key in .cache)
# Each option's up.sh and down.sh call this.
#
# Two phases, both QEMU/KVM as this user (no root on the host):
#   provision  Once per change of its inputs. A thin qcow2 overlay of the pinned Debian cloud
#              image boots with ordinary user-mode networking; cloud-init (NoCloud, seeded over
#              HTTP from 127.0.0.1 on the host) runs guest/provision.sh, which installs Docker and
#              the option's runtime, re-verifies every artifact against its pin, builds the job
#              image and powers off. The result is .cache/<option>/provisioned.qcow2.
#   (VM_CPU in vm.env sets -cpu; default host, which exposes nested virtualization.)
#   up         Each boot gets a fresh overlay of provisioned.qcow2 (nothing survives a down).
#              User-mode networking with restrict=on: the guest reaches nothing on the host or
#              the internet, except two guestfwd addresses, each a new connection per request:
#                10.0.2.100:8090 -> Traefik on host 127.0.0.1:8090 (git.localhost in the VM)
#                10.0.2.101:3128 -> the ci-proxy container (kb-proxy in the VM), no host port
#              The runner's registration secret reaches the VM as a systemd credential through
#              QEMU fw_cfg (a file path on argv, never the value); the debug ssh port listens on
#              host 127.0.0.1 only and the guest drops anything from its job bridges.
set -euo pipefail

RUNNERS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GIT_DIR_="$(cd "$RUNNERS/.." && pwd)"
CACHE="$RUNNERS/.cache"
FORGEJO_CONTAINER="${FORGEJO_CONTAINER:-kb-git-forgejo}"
PROXY_CONTAINER="${PROXY_CONTAINER:-kb-git-ci-proxy}"
BASE_IMAGE_NAME="debian"

cmd="${1:-}"
opt="${2:-}"
[[ -n "$cmd" && -n "$opt" && -f "$RUNNERS/$opt/vm.env" ]] || {
  echo "usage: vm.sh <up|down|status|ssh|provision> <option-dir with vm.env>" >&2
  exit 2
}
shift 2
# shellcheck source=/dev/null
. "$RUNNERS/$opt/vm.env"
: "${VM_NAME:?}" "${VM_LABEL:?}" "${VM_MEM:?}" "${VM_CPUS:?}" "${VM_DISK:?}" "${VM_SSH_PORT:?}" "${VM_ARTIFACTS:?}"
STATE="$CACHE/$opt"
PIDFILE="$STATE/qemu.pid"
SERIAL="$STATE/serial.log"
KEY="$STATE/ssh_key"
SECRET_FILE="$STATE/runner-secret"
mkdir -p "$STATE"
chmod 700 "$CACHE" "$STATE"

log() { echo "vm.sh ($opt): $*"; }
running() { [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }

ssh_vm() {
  ssh -i "$KEY" -p "$VM_SSH_PORT" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    -o LogLevel=ERROR -o ConnectTimeout=5 -o BatchMode=yes debian@127.0.0.1 "$@"
}

base_image() { echo "$CACHE/dl/$(bash "$RUNNERS/fetch.sh" --pin "$BASE_IMAGE_NAME" | cut -d' ' -f1)"; }

# Everything provisioning depends on; a change re-provisions on the next up.
provision_inputs_hash() {
  {
    cat "$RUNNERS/$opt/vm.env" "$RUNNERS/$opt/daemon.json" "$RUNNERS/$opt/runner.yaml" "$RUNNERS/guest/"*
    for a in $BASE_IMAGE_NAME $VM_ARTIFACTS; do bash "$RUNNERS/fetch.sh" --pin "$a"; done
  } | sha256sum | cut -d' ' -f1
}

provision() {
  local want port seed srv qpid
  want="$(provision_inputs_hash)"
  if [[ -f "$STATE/provisioned.qcow2" && "$(cat "$STATE/provisioned.stamp" 2>/dev/null)" == "$want" ]]; then
    return 0
  fi
  running && { log "inputs changed; stop the VM first (down.sh)"; return 1; }
  log "provisioning (inputs ${want:0:12}); this downloads Debian packages and takes a few minutes"
  # shellcheck disable=SC2086
  bash "$RUNNERS/fetch.sh" $BASE_IMAGE_NAME $VM_ARTIFACTS
  [[ -f "$KEY" ]] || ssh-keygen -q -t ed25519 -N '' -C "kb-ci-$opt-debug" -f "$KEY"

  # The NoCloud seed and the guest files, served on host loopback (the guest's 10.0.2.2).
  seed="$STATE/seed"
  rm -rf "$seed"
  mkdir -p "$seed/files" "$seed/dl"
  printf 'instance-id: %s-%s\nlocal-hostname: %s\n' "$VM_NAME" "${want:0:12}" "$VM_NAME" >"$seed/meta-data"
  port="$(python3 -I -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')"
  cat >"$seed/user-data" <<EOF
#cloud-config
# Provisioning only (vm.sh); the run phase boots with cloud-init disabled.
ssh_authorized_keys:
  - $(cat "$KEY.pub")
runcmd:
  - [python3, -c, "import urllib.request as u; u.urlretrieve('http://10.0.2.2:${port}/files/provision.sh', '/root/provision.sh')"]
  - [bash, /root/provision.sh, "http://10.0.2.2:${port}", "${opt}"]
EOF
  cp "$RUNNERS/guest/"* "$RUNNERS/$opt/daemon.json" "$RUNNERS/$opt/runner.yaml" "$seed/files/"
  : >"$seed/files/pins"
  for a in $VM_ARTIFACTS; do
    read -r f algo hash <<<"$(bash "$RUNNERS/fetch.sh" --pin "$a")"
    ln -s "$CACHE/dl/$f" "$seed/dl/$f"
    printf '%s %s %s %s\n' "$a" "$f" "$algo" "$hash" >>"$seed/files/pins"
  done
  python3 -I -m http.server --bind 127.0.0.1 --directory "$seed" "$port" >"$STATE/seed-http.log" 2>&1 &
  srv=$!
  # shellcheck disable=SC2064
  trap "kill $srv 2>/dev/null || true" EXIT

  rm -f "$STATE/provisioned.qcow2.tmp"
  qemu-img create -q -f qcow2 -F qcow2 -b "$(base_image)" "$STATE/provisioned.qcow2.tmp" "$VM_DISK"
  : >"$STATE/provision.log"
  timeout 2400 qemu-system-x86_64 -name "$VM_NAME-provision" -machine q35,accel=kvm -cpu "${VM_CPU:-host}" \
    -smp "$VM_CPUS" -m "$VM_MEM" -display none -serial "file:$STATE/provision.log" -monitor none \
    -drive "file=$STATE/provisioned.qcow2.tmp,if=virtio,discard=unmap" \
    -netdev "user,id=n0,hostfwd=tcp:127.0.0.1:${VM_SSH_PORT}-:22" -device virtio-net-pci,netdev=n0 \
    -smbios "type=1,serial=ds=nocloud;s=http://10.0.2.2:${port}/" &
  qpid=$!
  wait "$qpid" || true
  kill "$srv" 2>/dev/null || true
  trap - EXIT
  if grep -aq 'KB-PROVISION OK' "$STATE/provision.log"; then
    mv "$STATE/provisioned.qcow2.tmp" "$STATE/provisioned.qcow2"
    echo "$want" >"$STATE/provisioned.stamp"
    log "provisioned: $(grep -a 'KB-PROVISION OK' "$STATE/provision.log" | tail -1 | tr -d '\r')"
  else
    log "provisioning failed; last lines of $STATE/provision.log:"
    grep -a 'KB-PROVISION' "$STATE/provision.log" | tail -5 >&2 || true
    tail -20 "$STATE/provision.log" >&2
    return 1
  fi
}

# The runner's registration in Forgejo: a 40-hex secret (uuid = its first 16 characters), the
# same scheme as bootstrap.sh's dev-plain. Idempotent.
register() {
  local secret
  secret="$(cat "$SECRET_FILE" 2>/dev/null || true)"
  if [[ ! "$secret" =~ ^[0-9a-f]{40}$ ]]; then
    secret="$(od -An -tx1 -N20 /dev/urandom | tr -d ' \n')"
    (umask 077 && printf '%s' "$secret" >"$SECRET_FILE")
  fi
  printf '%s' "$secret" | docker exec -i -u git "$FORGEJO_CONTAINER" \
    forgejo forgejo-cli actions register --secret-stdin - --name "$VM_LABEL" --labels "$VM_LABEL" >/dev/null
  log "runner $VM_LABEL registered with Forgejo"
}

up() {
  if running; then
    log "already running (pid $(cat "$PIDFILE"))"
    grep -aqE 'KB-RUNNER-READY|declared successfully' "$SERIAL" && return 0
  else
    provision
    register
    bash "$GIT_DIR_/compose.sh" --profile ci up -d --no-deps --build ci-proxy >/dev/null 2>&1 ||
      { log "cannot start ci-proxy"; return 1; }
    rm -f "$STATE/run.qcow2" "$SERIAL"
    qemu-img create -q -f qcow2 -F qcow2 -b "$STATE/provisioned.qcow2" "$STATE/run.qcow2"
    qemu-system-x86_64 -name "$VM_NAME" -machine q35,accel=kvm -cpu "${VM_CPU:-host}" \
      -smp "$VM_CPUS" -m "$VM_MEM" -display none -serial "file:$SERIAL" -monitor none \
      -daemonize -pidfile "$PIDFILE" \
      -drive "file=$STATE/run.qcow2,if=virtio,discard=unmap" \
      -netdev "user,id=n0,restrict=on,hostfwd=tcp:127.0.0.1:${VM_SSH_PORT}-:22,guestfwd=tcp:10.0.2.100:8090-cmd:socat - TCP:127.0.0.1:8090,guestfwd=tcp:10.0.2.101:3128-cmd:bash $RUNNERS/proxy-connect.sh $PROXY_CONTAINER" \
      -device virtio-net-pci,netdev=n0 \
      -fw_cfg "name=opt/io.systemd.credentials/kb-runner-secret,file=$SECRET_FILE"
    log "booted (pid $(cat "$PIDFILE")); waiting for the runner"
  fi
  local i
  for i in $(seq 1 180); do
    # The runner's own "declared successfully" counts too: getty's escape sequences on the shared
    # serial console can clobber the marker line (seen in T18's scenario run).
    grep -aqE 'KB-RUNNER-READY|declared successfully' "$SERIAL" 2>/dev/null && { log "runner $VM_LABEL is waiting for jobs (${i} s)"; return 0; }
    running || { log "qemu exited; see $SERIAL"; return 1; }
    sleep 1
  done
  log "no KB-RUNNER-READY in $SERIAL after 180 s"
  tail -20 "$SERIAL" >&2
  return 1
}

down() {
  if running; then
    local pid
    pid="$(cat "$PIDFILE")"
    ssh_vm sudo systemctl poweroff >/dev/null 2>&1 || true
    for _ in $(seq 1 30); do kill -0 "$pid" 2>/dev/null || break; sleep 1; done
    kill "$pid" 2>/dev/null || true
    log "stopped"
  else
    log "not running"
  fi
  rm -f "$PIDFILE" "$STATE/run.qcow2"
}

case "$cmd" in
  provision) provision ;;
  up) up ;;
  down) down ;;
  status) running && echo "running (pid $(cat "$PIDFILE"))" || echo "stopped" ;;
  ssh) ssh_vm "$@" ;;
  *) echo "unknown command $cmd" >&2; exit 2 ;;
esac
