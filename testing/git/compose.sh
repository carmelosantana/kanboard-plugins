#!/usr/bin/env bash
# compose.sh — docker compose with the full kanboard-suite file set plus the git overlay.
#
# kb-suite (Kanboard on :8081) is shared with other sessions and runs with the dev, jsonrpc,
# chicane and bigboard files. Any compose command for the git stack must name the same set, or
# compose would recreate kb-suite without those mounts. Pass --no-deps and name the git services
# so kb-suite is left alone:
#   bash testing/git/compose.sh up -d --no-deps --build forgejo-db forgejo broker traefik
#   bash testing/git/compose.sh logs -f traefik
#   bash testing/git/compose.sh stop forgejo-db forgejo broker traefik
set -euo pipefail

TESTING="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec docker compose -p kanboard-suite \
  -f "$TESTING/docker-compose.dev.yml" \
  -f "$TESTING/docker-compose.jsonrpc-overlay.yml" \
  -f "$TESTING/docker-compose.chicane-overlay.yml" \
  -f "$TESTING/docker-compose.bigboard-overlay.yml" \
  -f "$TESTING/docker-compose.git-overlay.yml" \
  "$@"
