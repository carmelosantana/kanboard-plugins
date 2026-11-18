#!/usr/bin/env bash
# proxy-connect.sh — QEMU guestfwd command for kb-proxy:3128 in the runner VMs (vm.sh). QEMU runs
# it once per guest connection with the connection on stdin/stdout; it relays to the ci-proxy
# container's current address on the compose network, so the proxy publishes no host port (F14).
set -euo pipefail
ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "${1:-kb-git-ci-proxy}" | awk '{ print $1 }')"
exec socat - "TCP:${ip}:3128"
