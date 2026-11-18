#!/usr/bin/env bash
# Powers this isolation option's runner VM off and drops its per-boot disk. See ../vm.sh.
exec bash "$(dirname "${BASH_SOURCE[0]}")/../vm.sh" down "$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)")"
