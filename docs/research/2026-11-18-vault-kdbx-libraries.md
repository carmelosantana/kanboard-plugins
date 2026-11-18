# Vault plugin: KDBX library research

Date: 2026-11-17. Read-only research; tarballs inspected (not executed) in a scratch directory.
Sources: `npm view`, `gh api`, Packagist p2 API, OSV API, Alpine v3.23 APKINDEX + secdb,
`docker run kanboard/kanboard:latest` (v1.2.x, Alpine 3.23.3, PHP 8.4.19, built 2026-04-04).
Threat model (from coordinator): stolen DB backup + leaked agent token; server compromise out of scope for v1.

## Constraints confirmed

| Fact | Evidence |
|---|---|
| Kanboard image has **no** `sodium`, `xmlreader`, `ffi` | `php -m` in `kanboard/kanboard:latest` and `:v1.2.47`; `function_exists('sodium_crypto_secretbox')` → false |
| Image has OpenSSL 3.5.5 with `aes-256-ecb`, `aes-256-cbc`, `chacha20`, `chacha20-poly1305` | `openssl_get_cipher_methods()` |
| `password_algos()` = `2y, argon2i, argon2id` — no argon2d; `password_hash` makes its own salt and an encoded string, so it cannot act as a raw KDF with KeePass's salt | in image |
| libsodium (and ext-sodium) only expose `ARGON2I13`, `ARGON2ID13`; **no Argon2d anywhere** | local PHP 8.4.27: `SODIUM_CRYPTO_PWHASH_ALG_*` = only those two |
| Kanboard CSP default: `default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:` | `app/ServiceProvider/ClassProvider.php:187` |
| A plugin can change CSP only with `Plugin\Base::setContentSecurityPolicy(array)`, which **replaces the whole app-wide array** (last plugin wins) | `app/Core/Plugin/Base.php:27` |
| KeePassXC defaults: KDBX 4, **Argon2d** v0x13, **64 MiB**, parallelism `min(idealThreadCount, 4)`, iterations benchmarked to **1000 ms** (slider 100–5000 ms); cipher AES-256 (ChaCha20, Twofish optional) | `src/crypto/kdf/Argon2Kdf.h` (`ARGON2_DEFAULT_MEMORY = 1<<16` KiB, `PARALLELISM = 4`), `Argon2Kdf.cpp:38`, `Kdf.h:54 DEFAULT_ENCRYPTION_TIME = 1000`, `DatabaseSettingsWidgetEncryption.cpp:62,108`. Latest KeePassXC 2.8.0, 2026-10-21 |

## 1. JS KDBX libraries

| Package | Latest / published | Repo activity | Maint. (npm) | Licence | Runtime deps | Browser dist | KDBX3.1 / 4.x | Ciphers / KDFs | Notes |
|---|---|---|---|---|---|---|---|---|---|
| **kdbxweb** | 2.1.1 / 2021-09-06 | last commit 2024-12-24 (docs, by Aetherinox); 455★, 16 open issues/PRs, several from 2025–26 | 1 (antelle) | MIT | `@xmldom/xmldom ^0.7.4`, `fflate ^0.7.1` | **UMD** `dist/kdbxweb.min.js` 134,767 B (36 KB gz); fflate bundled; xmldom + `crypto` are externals, unused in browser (uses `globalThis.DOMParser`, WebCrypto) | 3.1 + 4.0 + 4.1 read/write | AES-256, ChaCha20 (pure JS), Salsa20 inner; AES-KDF, Argon2d, Argon2id. **No Twofish.** | **Needs external Argon2** via `CryptoEngine.setArgon2Impl()`; it throws "argon2 not implemented" otherwise. Only `new Function` is webpack's global fallback after `globalThis` → CSP-safe |
| @keenext/kdbxweb | 2.2.0-keenext.2 / 2026-10-11 | GitHub fork created 2026-10-18, 0★ | 1 (jaheme127) | MIT | `fflate ^0.8.3` | **ESM only** (`dist/index.js` 179 KB); would need a one-time IIFE build | as upstream + fixes | adds **Twofish**; WebCrypto only | FORK.md lists ~20 real fixes: corrupt KDBX 4.1 writes (#49/#62), tab stripping (#63), ChaCha20 KDBX3 IV, missing final-block HMAC check, key zeroing on failure, AES-KDF 2^50-round freeze, AbortSignal. Very young, single unknown maintainer, npm publish predates the repo |
| passxyzlib | 2.2.0 / 2025-12-19 | — | 1 | MIT | xmldom 0.8, fflate 0.7 | — | kdbxweb fork | — | small fork; skip |
| @hazae41/kdbx | 0.2.22 / 2026-06-10 | 2★ | 1 | MIT (repo) | 4 @hazae41/* deps (own argon2/chacha20) | ESM | KDBX 4 only | — | young, single-maintainer ecosystem; skip |
| kdbx-wasm | 0.3.1 / 2026-08-24 | — | 1 | MIT | — | WASM | — | — | WASM (CSP change) + unknown maintainer; skip |
| keepass.io, keepass, kdbx | 2015–2022 | dead | — | — | — | — | KDBX3 / Node only | — | skip |

kdbxweb crypto needs → **WebCrypto covers all of it** except Argon2 and ChaCha20/Salsa20: SHA-256, SHA-512, HMAC-SHA256, AES-256-CBC (`subtle.encrypt/decrypt`), AES-KDF (simulated ECB rounds via CBC over zero blocks), random. ChaCha20 and Salsa20 ship as pure JS inside kdbxweb. **No extra AES/ChaCha/HMAC library is needed (Q6).** WebCrypto needs a secure context (HTTPS or localhost).

Upstream known issues relevant to us: #49 "creates corrupted kdbx 4 database" (2023, open), #48 date overflow on KeePassDX files, #63 tabs stripped from fields, #54 "painful integration of argon2 in browser". Import is mostly fine; **export with upstream is risky**.

## 2. Argon2 in the browser

| Package | Latest / published | Maint. | Licence | Browser file | WASM? (CSP) | Argon2d? | Notes |
|---|---|---|---|---|---|---|---|
| **@noble/hashes** | 2.4.0 / 2026-08-27 | 1 (paulmillr) | MIT | ESM only on npm; GitHub release standalone `noble-hashes.min.js` (IIFE `nobleHashes`) exists up to 2.0.1 but **exports only `argon2id`** → we'd build our own IIFE of `argon2.js` once | **No** — pure JS, CSP unchanged | yes (`argon2d`, `argon2dAsync`) | 0 runtime deps, **npm provenance (SLSA v1)**, PGP-signed releases, 945★, 4 open issues. README: argon2 not in audit scope; "5x slower than native". Own bench: argon2id t=1 m=128 MB = 276 ms/op |
| **hash-wasm** | 4.13.0 / **2026-11-07** (10 days; previous 4.12.0 was 2024-11-19) | 2 (daninet, sdombi94) | MIT | `dist/argon2.umd.min.js` 30,094 B (all-in-one 216 KB) | **Yes** — `WebAssembly.compile/instantiate` of embedded base64 → needs `script-src 'self' 'wasm-unsafe-eval'` | yes (d, i, id) | 1,174★; no provenance (npm signatures only); no install scripts. 4.13.0 diff = 12 commits by the long-time author (deps, TS, bcrypt fix, CI hardening) — plausible, but a fresh release after a 2-year gap: pin 4.12.0 or wait/diff |
| argon2-browser | 1.18.0 / 2021-06-05 | 1 (antelle) | MIT | `argon2-bundled.min.js` 45,921 B + `.wasm` | Yes | yes | last commit 2021-11, 20 open issues; unmaintained. kdbxweb's own tests used it |

**Performance estimate at KeePassXC defaults** (Argon2d, 64 MiB, p≤4, iterations calibrated to 1 s native multithreaded). Not measured (brief forbids executing downloads); derived from published numbers:
- JS is single-threaded unless we hand lanes to workers, so it pays roughly `p ×` the native wall time, times the JS/WASM slowdown.
- noble pure JS: ~2.2 ms per MiB-pass (276 ms / 128 MiB) on the author's machine; ~3–5× slower than native per core → **~10–20 s on a fast desktop, longer on laptops/phones**, for a database calibrated to 1 s.
- hash-wasm WASM: ~2× faster than argon2-browser (README: 438 vs 213 ops, Chrome 131, Ryzen 9 7900X) → **~4–8 s**.
- Memory: 64 MiB `Uint32Array` — fine. Run it in a **Web Worker** (`new Worker('/plugins/Vault/Assets/argon2-worker.js')` is allowed by `default-src 'self'`) so the page stays responsive and can show progress/cancel.

## 3. PHP KDBX libraries (Packagist)

| Package | Latest / date | Maint. | Licence | KDBX support | Verdict |
|---|---|---|---|---|---|
| hankit/keepassphp | 1.0.1 / 2026-03-09 | 1 | MIT | read/write KDBX 3.x and 4.x **only with AES-256 + AES-KDF**; README says Argon2d, Argon2id and ChaCha20 are **not** supported; requires ext-sodium + ext-xmlreader (both missing in image) | no |
| xp-forge/keepass | v2.1.0 / 2024-03-24 | 1 | BSD-3 | KDBX 3 read-only (no Argon2/KDBX4 classes); needs xp-framework/core | no |
| keepassphp/keepassphp | v1.1.0-RC2 / 2018 | 1 | MIT | KDBX 3; abandoned | no |
| xclydes/x-keepass-php | 1.0.0 / 2020 | 1 | MIT | KDBX 3; abandoned | no |
| vielhuber/keepassmcp | 1.1.0 / 2026-11-16 (1 day old) | 1 | MIT | shells out to Python **pykeepass**; README: "no PHP library reads KDBX 4.x reliably"; needs PHP ^8.5 | no |

Confirmed: no PHP path to Argon2d. libsodium never shipped Argon2d (only i/id), `password_hash` cannot be a raw KDF, pure-PHP Argon2d at 64 MiB × N would take minutes. PHP *can* do everything else with OpenSSL (AES-KDF via CBC-over-zeros, AES-256-CBC, ChaCha20, HMAC-SHA256, gzip) — so a server-side reader would cover KDBX 3.1 and KDBX 4 with AES-KDF only, i.e. **not** KeePassXC default files.

## 4. keepassxc-cli server-side

| Item | Finding |
|---|---|
| Alpine 3.23 | `community/keepassxc 2.7.11-r0` (upstream is 2.8.0), licence `GPL-2.0-only OR GPL-3.0-only`, 4.4 MB pkg / 8.7 MB installed **plus Qt5 Core/Gui/Widgets/DBus/Network/Svg/X11Extras, libX11, botan3, argon2, pcsclite, libusb, qrencode** (tens of MB); no CLI-only split package |
| In kanboard image | not present; installing needs a derived image — a buildless plugin cannot assume it |
| Licence | calling a GPL binary as a separate process is aggregation; the plugin need not be GPL. Shipping it inside an image we distribute means GPL source-offer duties for that image |
| Fit | `keepassxc-cli export --format xml` / `import` work, but the master password goes to the server and Qt deps bloat the image. Optional "admin-installed" fallback at most |

## 5. libsodium / sodium_compat (scope addition)

| Item | Finding |
|---|---|
| paragonie/sodium_compat | v2.5.2 and v1.24.2, 2026-08-18; previous v2.5.0 2025-12-30. ISC. Packagist maintainer: 1 (`paragonie-scott`); commits mostly `paragonie-security` (801). 166M installs, 933★, 1 open issue. No install scripts (Composer `scripts`: none). Requires PHP ^8.1, 64-bit |
| Audit | README: "**has not been formally audited** by an independent third party". I found no NCC report referenced in the repo; adopted by Joomla, Magento, and **WordPress core** (`wp-includes/sodium_compat`, updated to 1.24.2 on 2026-11-10) |
| Advisories | OSV: GHSA-mrfv-m5wm-5w6w / CVE-2025-69277 (Ed25519 point validation, medium), fixed in 2.5.0 / 1.24.0. v2.5.1–2.5.2 are further Ed25519 / boundary-check hardening. Not relevant to secretbox/AEAD |
| Coverage (pure PHP) | `crypto_secretbox`, `crypto_box_seal`/`_open`, `crypto_aead_xchacha20poly1305_ietf_*`, `crypto_secretstream_xchacha20poly1305_*` — yes |
| `crypto_pwhash` | **not polyfilled**: throws `SodiumException` without ext-sodium (`crypto_pwhash_is_available()` to detect). `sodium_memzero` also throws without the extension |
| ext-sodium on the image | `community/php84-sodium 8.4.27-r0` exists (deps: `php84-common`, `libsodium.so.26`; `main/libsodium 1.0.20-r1`, secdb lists CVE-2025-69277 fixed). Image ships PHP **8.4.19**, so `apk add php84-sodium` pulls `php84-common 8.4.27` → a partial PHP upgrade; do `apk upgrade php84*` together, in a derived image (`FROM kanboard/kanboard:<pin>` + `RUN apk add --no-cache php84-sodium`). Official image does **not** enable sodium. Easy for the user's own deployment; not something a plugin can require of other installs |
| Without sodium | OpenSSL gives `chacha20-poly1305` and `aes-256-gcm` AEAD; a KDF can be `hash_pbkdf2('sha256', …, 600000)`. Or derive the vault key in the browser (noble argon2id) and keep the server zero-knowledge |

## 6. Supply-chain review (powerup:supply-chain checklist)

| Candidate | Publish age | Install scripts | Provenance | Maintainers / ownership | Transitive deps (vendored file) | Advisories (OSV) | Risk |
|---|---|---|---|---|---|---|---|
| kdbxweb 2.1.1 UMD | 5 y old | none | none | 1 npm maintainer (antelle); GitHub upkeep now by Aetherinox since 2024 — ownership drift, no new npm release | fflate (bundled); xmldom not bundled | kdbxweb none. **xmldom 0.7.x has 15 GHSAs** (incl. high GHSA-wh4c-j3r5-mjhp), but it is only loaded under Node, never by the browser UMD | Low for browser import if vendored + hashed; stale |
| @keenext/kdbxweb | 5 weeks | none | none | 1 unknown maintainer, 0★, npm before repo | fflate 0.8 | none | **Medium-high** as a dependency; use only as a *reference* for patches after reading its diff |
| @noble/hashes 2.4.0 | 82 days | none | **SLSA provenance** | 1 well-known maintainer; stable | 0 deps | none | Low |
| hash-wasm 4.13.0 | **10 days** (2-year gap before it) | none (`prepublishOnly` runs only for the publisher) | none | 2 maintainers, same author | 0 deps | none | Low-medium: wait/diff 4.13.0 or pin 4.12.0 |
| argon2-browser 1.18.0 | 5 y | none | none | 1, unmaintained | — | none | Medium (stale WASM blob) |
| fflate (bundled in kdbxweb) | — | none | none | 1 (101arrowz); 2026-07-20 back-port releases 0.4.9/0.5.4/0.6.11/0.7.5 in one burst — unusual, worth a look if we bundle fflate ourselves | 0 | none | Low |
| paragonie/sodium_compat 2.5.2 | 3 months | none | n/a (Packagist; GitHub tags) | 1 Packagist maintainer, company-owned, stable | 0 | fixed CVE-2025-69277 | Low |
| keepassxc (Alpine) | 2025-12 build | apk | Alpine signed | KeePassXC team | Qt5/X11 stack | none for 2.7.11 found | Low trust risk, high footprint |

Not checked: Socket.dev pages (JS-rendered; not fetched). Nothing tripped the <48 h rule; `vielhuber/keepassmcp` (1 day) is rejected anyway.

## Recommendation

1. **Import in the browser**: vendor `kdbxweb@2.1.1/dist/kdbxweb.min.js` (MIT, UMD, 135 KB) as-is, plus a **pure-JS Argon2** — a one-time IIFE build of `@noble/hashes@2.4.0` `argon2.js` (`argon2dAsync`, `argon2idAsync`) wired through `kdbxweb.CryptoEngine.setArgon2Impl`, run inside a Web Worker with a progress bar and cancel. This keeps Kanboard's CSP untouched and decrypts KeePassXC default files (Argon2d, AES or ChaCha20). Expect ~10–20 s on a desktop.
2. **Optional faster path**: hash-wasm `argon2.umd.min.js` (30 KB) only if the admin opts into adding `'wasm-unsafe-eval'`. Note that `setContentSecurityPolicy` replaces the app-wide CSP, so it clobbers other plugins' CSP changes. Pin 4.12.0 or review the 4.13.0 diff first.
3. **v1 = import only.** Export through upstream kdbxweb risks corrupt KDBX 4.1 files (#49). For later export, cherry-pick fixes from keenext's FORK.md into our own vendored build after reading the diff, rather than depending on the fork.
4. **Not supported in v1**: Twofish-encrypted databases (upstream kdbxweb can't read them). Also tell users that "KeePassXC → Export → XML/CSV" always works.
5. **Server-side vault crypto**: sodium_compat (secretbox, box_seal, XChaCha20-Poly1305) works on the stock image; `crypto_pwhash` needs ext-sodium. Either derive keys client-side (noble argon2id), use PBKDF2 server-side, or document `php84-sodium` in a derived image (upgrade php84* together).
6. Vendor every JS file with a recorded SHA-256, source URL and version in the plugin's repo. Never `npm install` into the plugin.

## Risks

- Upstream kdbxweb is effectively frozen (no npm release since 2021); we own any bugs we hit.
- Pure-JS Argon2d is slow, and a crafted file can demand huge memory/iterations → clamp parameters (e.g. memory ≤ 256 MiB, abort after N s) before deriving.
- Decrypted secrets live in browser memory; JS cannot reliably zero strings.
- Unaudited code paths: noble argon2 (outside its audit scope), sodium_compat (no formal audit).
- A CSP widening for WASM is global and fragile across plugins.
