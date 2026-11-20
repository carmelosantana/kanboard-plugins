#!/usr/bin/env bash
# run-realdb-tests.sh — Run one plugin's PHPUnit suite against a real MariaDB or Postgres.
#
# Usage (from repo root):
#   ./testing/run-realdb-tests.sh <PluginName> mariadb|postgres
#
# Starts a throwaway database container (official image, pinned by digest, data on tmpfs, no host files mounted) and
# runs ./testing/run-plugin-tests.sh <PluginName> with DB_DRIVER=mysql|postgres in a throwaway PHP container built
# from the kb-suite dev image (stock Kanboard plus sodium; it has pdo_mysql and pdo_pgsql, which the host PHP may
# lack) plus php84-tokenizer and opcache.save_comments=1 for PHPUnit. Both share an internal Docker network (no route out)
# and are removed afterwards. Only testing/run-plugin-tests.sh, testing/kanboard-src and the plugin are mounted, all
# read-only (so is kanboard-src/.git: nothing in the container can plant a hook that later runs on the host). The
# paths a run regenerates, kanboard-src/tests/plugin-bootstrap.php and PHPUnit's tests/.phpunit.result.cache, are
# throwaway host files mounted over them. testing/.env and testing/git/*.env stay on the host, which a preflight in the
# container checks before the suite runs. Exit code: the suite's (97: preflight).
#
# Env: KB_IMAGE (default: built here from testing/Dockerfile.sodium, whose base is pinned by digest).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="${1:-}"
ENGINE="${2:-}"
KB_IMAGE="${KB_IMAGE:-}"
MARIADB_IMAGE='mariadb:11.4@sha256:611a2fcc5fa7c6ceb8644c6f74b25ede004ff6c3a6b38c8f8c23d3bbf6c26430'
POSTGRES_IMAGE='postgres:17-alpine@sha256:eee6c6e1b0894fa4fe0075eb054af9fd1cf39a35321c2d4110626cfe506411fb'

if [[ -z "$PLUGIN" || ! -f "${REPO_ROOT}/${PLUGIN}/plugin.json" || ! "$ENGINE" =~ ^(mariadb|postgres)$ ]]; then
  echo "Usage: $0 <PluginName> mariadb|postgres"
  exit 1
fi

id="realdb-$$-$RANDOM"
net="$id-net"
db="$id-db"
password="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')"
scratch="$(mktemp -d)"
cleanup() {
  docker rm -f -v "$db" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
  rm -rf "$scratch"
}
trap cleanup EXIT

docker network create --internal "$net" >/dev/null
if [[ "$ENGINE" == mariadb ]]; then
  docker run -d --name "$db" --network "$net" --tmpfs /var/lib/mysql:rw \
    -e MARIADB_ROOT_PASSWORD="$password" -e MARIADB_DATABASE=kanboard_unit_test "$MARIADB_IMAGE" >/dev/null
  ready=(healthcheck.sh --connect --innodb_initialized)
  env=(-e DB_DRIVER=mysql -e DB_HOSTNAME="$db" -e DB_USERNAME=root -e DB_PASSWORD="$password")
else
  # Kanboard's units.postgres.xml fixes the user and password to postgres; the server is reachable only on $net.
  docker run -d --name "$db" --network "$net" --tmpfs /var/lib/postgresql/data:rw \
    -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=kanboard_unit_test "$POSTGRES_IMAGE" >/dev/null
  ready=(pg_isready -U postgres -h 127.0.0.1)
  env=(-e DB_DRIVER=postgres -e DB_HOSTNAME="$db")
fi
for _ in $(seq 1 90); do
  docker exec "$db" "${ready[@]}" >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$db" "${ready[@]}" >/dev/null
echo "$ENGINE ready ($([[ "$ENGINE" == mariadb ]] && echo "$MARIADB_IMAGE" || echo "$POSTGRES_IMAGE")); running $PLUGIN with DB_DRIVER=${env[1]#DB_DRIVER=}"

# The Kanboard-plus-sodium base, unless KB_IMAGE names one: built from stdin (no build context).
if [[ -z "$KB_IMAGE" ]]; then
  KB_IMAGE=realdb-kb-sodium:local
  docker build -q -t "$KB_IMAGE" - < "${REPO_ROOT}/testing/Dockerfile.sodium" >/dev/null
fi

# PHPUnit needs ext-tokenizer, which the Kanboard image leaves out (Alpine package, same repository as php84-sodium),
# and doc comments: the image sets opcache.save_comments=0, which hides @dataProvider from PHPUnit.
php_image=realdb-php:local
docker build -q -t "$php_image" - >/dev/null <<DOCKERFILE
FROM $KB_IMAGE
RUN apk add --no-cache php84-tokenizer && printf 'opcache.save_comments=1\\n' > /etc/php84/conf.d/zz-realdb-tests.ini
DOCKERFILE

# Preflight, inside the PHP container before the suite: no host secret is visible (testing/.env and testing/git/*.env
# stay outside the mounts; only the existence test runs, nothing is read), there is no default route (the network
# is --internal: the suite reaches the database and nothing else), and kanboard-src and its .git can't be written.
# Exit 97 on a breach, before any test runs.
preflight='for f in testing/.env testing/git; do if test -e "$f"; then echo "preflight: $f is visible in the container" >&2; exit 97; fi; done
if awk '"'"'NR > 1 && $2 == "00000000" { found = 1 } END { exit !found }'"'"' /proc/net/route; then echo "preflight: the container has a default route (egress)" >&2; exit 97; fi
for d in testing/kanboard-src testing/kanboard-src/.git; do if touch "$d/.realdb-write-probe" 2>/dev/null; then rm -f "$d/.realdb-write-probe"; echo "preflight: $d is writable" >&2; exit 97; fi; done
echo "preflight: no testing/.env or testing/git, no default route, kanboard-src and its .git read-only"'

# The read-only tree needs, on the host, the plugin's symlink and the mount points of the regenerated files.
kb_src="${REPO_ROOT}/testing/kanboard-src"
[[ -e "${kb_src}/plugins/${PLUGIN}" ]] || ln -s "${REPO_ROOT}/${PLUGIN}" "${kb_src}/plugins/${PLUGIN}"
for f in plugin-bootstrap.php .phpunit.result.cache; do
  [[ -e "${kb_src}/tests/$f" ]] || : > "${kb_src}/tests/$f"
  : > "${scratch}/$f"
done

docker run --rm --network "$net" --user "$(id -u):$(id -g)" --entrypoint bash \
  -v "${REPO_ROOT}/testing/run-plugin-tests.sh:${REPO_ROOT}/testing/run-plugin-tests.sh:ro" \
  -v "${kb_src}:${kb_src}:ro" \
  -v "${scratch}/plugin-bootstrap.php:${kb_src}/tests/plugin-bootstrap.php:rw" \
  -v "${scratch}/.phpunit.result.cache:${kb_src}/tests/.phpunit.result.cache:rw" \
  -v "${REPO_ROOT}/${PLUGIN}:${REPO_ROOT}/${PLUGIN}:ro" \
  -e HOME=/tmp "${env[@]}" -w "$REPO_ROOT" "$php_image" -c "$preflight"' && exec "$0" "$1"' \
  "${REPO_ROOT}/testing/run-plugin-tests.sh" "$PLUGIN"
