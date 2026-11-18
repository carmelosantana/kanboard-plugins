#!/bin/sh
# one-job.sh: entrypoint of the dev-only CI runner (compose profile ci-dev). Runs exactly one job,
# then exits; compose restarts the container for the next one.
#
# The connection (uuid and token, derived from RUNNER_SECRET as `forgejo forgejo-cli actions
# register` derives them) goes into a 0600 config in the container's own home, never on argv.
# Jobs run in host mode, as this same user in this container: they can read that config. That is
# the dev-plain trade-off (no isolation, no Docker daemon, no host socket); Task 16 replaces this
# runner with the isolation options.
set -eu
: "${RUNNER_SECRET:?RUNNER_SECRET unset (testing/git/runner.env, written by bootstrap.sh)}"
: "${RUNNER_URL:=http://git.localhost:8090}"
: "${RUNNER_LABEL:=dev-plain}"

# The first 16 hex characters, as ASCII bytes, are the runner UUID; the whole secret is its token.
uuid_hex="$(printf '%.16s' "$RUNNER_SECRET" | od -An -tx1 | tr -d ' \n')"
uuid="$(printf '%s' "$uuid_hex" | sed -E 's/^(.{8})(.{4})(.{4})(.{4})(.{12})$/\1-\2-\3-\4-\5/')"
token="$RUNNER_SECRET"
unset RUNNER_SECRET

cd "$HOME"
umask 077
cat >config.yml <<CFG
log:
  level: info
runner:
  capacity: 1
  timeout: 30m
  fetch_interval: 2s
  labels: ["${RUNNER_LABEL}:host"]
cache:
  enabled: false
host:
  workdir_parent: ${HOME}/work
server:
  connections:
    dev:
      url: ${RUNNER_URL}
      uuid: ${uuid}
      token: ${token}
CFG
rm -rf "$HOME/work"
exec forgejo-runner -c config.yml one-job --wait
