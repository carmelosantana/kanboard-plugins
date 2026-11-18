#!/usr/bin/env bash
# fetch.sh — downloads the isolation options' pinned artifacts into testing/git/runners/.cache/
# (gitignored) and verifies each against the checksum pinned here. Nothing is used before it
# verifies; a mismatch deletes the download and exits 1. Re-runs skip verified files.
#
#   bash testing/git/runners/fetch.sh debian runsc runner     # option (a)
#   bash testing/git/runners/fetch.sh debian runner kata      # option (c)
#
# Pins (each release at least two weeks old when pinned, 2026-11-18; bump URL and hash together):
#   debian  Debian 13 genericcloud amd64 20261103-2651, SHA512 from cloud.debian.org SHA512SUMS
#   runsc   gVisor release 20261102.0 (runsc + containerd-shim-runsc-v1), SHA512 from its .sha512
#   runner  forgejo-runner v13.2.1 linux-amd64 (the version of the dev-plain image), SHA256 from
#           its .sha256, cross-checked against /bin/forgejo-runner in that pinned image
#   kata    Kata Containers 4.3.1 kata-go-static amd64 (the Go shim Docker works with), SHA256
#           from the GitHub release asset digest (the release publishes no checksum file)
set -euo pipefail

CACHE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.cache"

# <name> <file> <algo> <hash> <url>
ARTIFACTS=(
  "debian debian-13-genericcloud-amd64-20261103-2651.qcow2 sha512 d25999de0770d4cde1be72c299ba3ad94eac7ea8fd4d18f8cc86c69df69337e2e8a91d64465a9bfd8f2a78c566fcbcb0842eacc91c09efdef72d719d9f13b535 https://cloud.debian.org/images/cloud/trixie/20261103-2651/debian-13-genericcloud-amd64-20261103-2651.qcow2"
  "runsc gvisor-20261102.0.tar.zstd sha512 3c400f72be459b57b99b1c4f69be96bcd67eef3caf2078e595fc98e688b6b9a3af7802646b3dde7da8a9aba96d34f86be303e0430c7868ab2d13becdd403c0c1 https://storage.googleapis.com/gvisor/releases/release/20261102.0/x86_64/gvisor.tar.zstd"
  "runner forgejo-runner-13.2.1-linux-amd64 sha256 bb369fe1ecb50fdc983c0b1d993f19b0920df6e5864511892b3cdbf16d858b34 https://code.forgejo.org/forgejo/runner/releases/download/v13.2.1/forgejo-runner-13.2.1-linux-amd64"
  "kata kata-go-static-4.3.1-amd64.tar.zst sha256 66b35b06f6b41b56f8606ddf3ed3e5660b0971d1e309d903457c4056a0b9eff0 https://github.com/kata-containers/kata-containers/releases/download/4.3.1/kata-go-static-4.3.1-amd64.tar.zst"
)

# Prints "<file> <algo> <hash>" of artifact <name> (used by vm.sh to re-verify inside the VM).
pin() {
  local row name file algo hash url
  for row in "${ARTIFACTS[@]}"; do
    read -r name file algo hash url <<<"$row"
    [[ "$name" == "$1" ]] && { echo "$file $algo $hash"; return 0; }
  done
  return 1
}

verify() { # verify <file> <algo> <hash>
  [[ -f "$1" ]] && [[ "$("${2}sum" "$1" | cut -d' ' -f1)" == "$3" ]]
}

fetch() { # fetch <name>
  local row name file algo hash url
  for row in "${ARTIFACTS[@]}"; do
    read -r name file algo hash url <<<"$row"
    [[ "$name" == "$1" ]] || continue
    mkdir -p "$CACHE/dl"
    if verify "$CACHE/dl/$file" "$algo" "$hash"; then
      echo "fetch: $file verified ($algo)"
      return 0
    fi
    echo "fetch: downloading $url"
    rm -f "$CACHE/dl/$file.part"
    curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$CACHE/dl/$file.part" "$url"
    if verify "$CACHE/dl/$file.part" "$algo" "$hash"; then
      mv "$CACHE/dl/$file.part" "$CACHE/dl/$file"
      echo "fetch: $file verified ($algo)"
    else
      rm -f "$CACHE/dl/$file.part"
      echo "fetch: $file FAILED its $algo pin; deleted" >&2
      return 1
    fi
    return 0
  done
  echo "fetch: unknown artifact $1" >&2
  return 1
}

if [[ "${1:-}" == --pin ]]; then
  pin "$2"
  exit
fi
[[ $# -gt 0 ]] || { echo "usage: fetch.sh <debian|runsc|runner|kata>... | --pin <name>" >&2; exit 2; }
for a in "$@"; do fetch "$a"; done
