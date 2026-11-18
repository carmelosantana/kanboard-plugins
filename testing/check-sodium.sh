#!/usr/bin/env bash
# check-sodium.sh — fail unless the kb-suite container's PHP has ext-sodium (Vault needs it).
# Usage: bash testing/check-sodium.sh
set -euo pipefail

if docker exec kb-suite php -r 'exit(function_exists("sodium_crypto_pwhash")?0:1);'; then
  echo "sodium: ok"
else
  echo "sodium: MISSING in kb-suite (rebuild with testing/Dockerfile.sodium)" >&2
  exit 1
fi
