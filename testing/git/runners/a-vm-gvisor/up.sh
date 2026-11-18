#!/usr/bin/env bash
# Boots this isolation option's runner VM (provisioning it first if its inputs changed) and
# waits until its one-job runner is waiting for jobs. Idempotent. See ../vm.sh.
exec bash "$(dirname "${BASH_SOURCE[0]}")/../vm.sh" up "$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)")"
