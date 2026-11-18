#!/bin/sh
# kb-runner.sh — ExecStart of kb-runner.service inside a runner VM: writes the runner config from
# /etc/kb-runner/runner.yaml and runs one job. systemd restarts it for the next job.
# The registration secret is a systemd credential (QEMU fw_cfg from vm.sh): the config points
# token_url at it, so it is never on argv or in a config file. The uuid is its first 16
# characters as hex bytes, as forgejo-cli actions register derives it.
set -eu
cred="${CREDENTIALS_DIRECTORY:?no credentials}/kb-runner-secret"
[ -s "$cred" ] || { echo "kb-runner: credential kb-runner-secret missing" >&2; exit 1; }
uuid="$(head -c 16 "$cred" | od -An -tx1 | tr -d ' \n' |
  sed -E 's/^(.{8})(.{4})(.{4})(.{4})(.{12})$/\1-\2-\3-\4-\5/')"
umask 077
sed "s/@@UUID@@/${uuid}/" /etc/kb-runner/runner.yaml >"${RUNTIME_DIRECTORY:?}/config.yml"
cd "${STATE_DIRECTORY:?}"
echo "KB-RUNNER-READY"
exec /usr/local/bin/forgejo-runner -c "${RUNTIME_DIRECTORY}/config.yml" one-job --wait
