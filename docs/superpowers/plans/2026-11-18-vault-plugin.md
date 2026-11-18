# Vault Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development. Every implementer and fixer is **Opus**, and every reviewer, task and whole-branch, is **Opus**. Pass `model:` explicitly on every dispatch. Never use Haiku. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Vault 1.0.0. It is a KeePass-style password store for Kanboard with per-principal keypairs behind a vault passphrase, sharing with users and agents, one-time links, WebAuthn PRF two-factor unlock, and KeePassXC import and export.

**Architecture:**
- Vault is a buildless Kanboard plugin: PHP 8.4, plain JS and jQuery, no bundler.
- All server-side crypto uses ext-sodium. Entry and group payloads are encrypted under per-object DEKs, which are sealed to principals' X25519 keys.
- A single install-wide vault lock serialises every authorisation change. KDBX parsing and the one-time-share crypto run in the browser.

**Tech Stack:** PHP 8.4 with ext-sodium and openssl, Kanboard 1.2.47 (PicoDb), PHPUnit through `testing/run-plugin-tests.sh`, node's built-in test runner for JS, Playwright for E2E (already in use by `scripts/e2e/`), vendored `kdbxweb` 2.1.1 and `@noble/hashes` 2.4.0, and `keepassxc-cli` plus `pykeepass` in CI only.

**Spec:** `docs/specs/2026-11-18-vault-plugin-design.md` (approved, security gate passed). Implementers read the spec sections named in each task. The spec is the source of truth for every value this plan doesn't repeat.

## Global Constraints

- Plugin `Vault`, namespace `Kanboard\Plugin\Vault`, repo `carmelosantana/kanboard-vault`, version `1.0.0`, MIT, `kanboard_version >=1.2.47`, `php_version >=8.4`, `requires: []`, `recommends: [Agents]`.
- If `function_exists('sodium_crypto_pwhash')` is false, Vault registers only an admin notice and no routes.
- No inline JS. Vault templates emit their own `<script>` tags with `$this->asset->js()`. Vault never uses `template:layout:js` or `template:layout:css`.
- Every state change, and every request that returns a secret, is a POST with Kanboard's CSRF token. Responses that carry secrets are `Cache-Control: no-store`.
- Every Vault response sets `X-Frame-Options: DENY` and a per-response CSP: a copy of `$container['cspRules']` plus `frame-ancestors 'none'`.
- Decrypted or imported values are rendered with `textContent` or `$this->text->e()`, never as HTML or Markdown. Outbound links allow only `http:` and `https:`.
- Every encrypted row carries `crypto_v = 1`. All AD and sealed plaintexts use `Encoding::enc()` (Task 2), never raw concatenation.
- No secret, passphrase, vault key, PRF output, share key, burn capability or decrypted value is ever logged or audited.
- SQLite writes under the vault lock use a raw `BEGIN IMMEDIATE`, never PicoDb's `beginTransaction()`.
- Before vendoring or updating any npm, Packagist or pip artifact, run `/powerup:supply-chain`.
- Commits carry no AI attribution. Tests are TDD, and the **full** suite (`./testing/run-plugin-tests.sh --all`) runs to completion before each task's commit.
- **Never use the `kanboard` or `kanboard-admin` MCP tools for mutations.** They point at production. Dev E2E runs against http://localhost:8081 only.

## Review Focus

1. **A vault created on the dev stack's HTTP origin, then reached through another host or port:** the cookie and origin pinning must fail with a clear message, not a silent lock-out. Owned by Task 11 (unlock on plain HTTP off localhost is refused with `vault.https_required`) and Task 20 (a mismatched origin reports `rp_id_mismatch`).
2. **Very large vaults (10,000 entries) on the list, search, re-key sweep and rotation:** these must stay usable. Owned by Task 8 (rotation over 10,000 entries finishes in batches, with each batch's transaction under 2 s on SQLite) and Task 12 (the list endpoint paginates at 200 rows).
3. **Unicode, emoji, tabs and long notes** survive create, edit, share, import and export byte for byte. Owned by Task 6 (round-trip test) and Task 23 (export gate fixtures).
4. **A user whose Kanboard account is disabled while they're unlocked:** the next vault request locks. Owned by Task 5 (`testDisabledUserLocks`).
5. **Two browser tabs with the same unlocked session:** they edit the same entry, and the second save gets a `conflict` result, not silent overwrite. Owned by Task 6 (`testStaleVersionConflicts`).

---

## Phase A: Foundation

### Task 1: Scaffold, schema v1 and the sodium gate

Spec: *Plugin shape and packaging*, *Data model*.

**Files:**
- Create: `Vault/plugin.json`, `Vault/Plugin.php`, `Vault/LICENSE`, `Vault/README.md`, `Vault/Schema/Sqlite.php`, `Vault/Schema/Mysql.php`, `Vault/Schema/Postgres.php`
- Create: `Vault/.github/workflows/release.yml` (copy `Presence/.github/workflows/release.yml` as it is)
- Test: `Vault/Test/SchemaTest.php`, `Vault/Test/PluginVersionTest.php`

**Interfaces:**
- Produces: `const VERSION = 1;` and `function version_1($pdo)` in each Schema file, creating every table in the spec's *Data model* table (`vault_meta` with its single row `id = 1` inserted, `vault_principals`, `vault_rotations`, `vault_wraps`, `vault_webauthn`, `vault_challenges`, `vault_groups`, `vault_entries`, `vault_grants`, `vault_keys`, `vault_rekey_queue`, `vault_sessions`, `vault_share_reservations`, `vault_shares`, `vault_audit`, `vault_rate`, `vault_agent_settings`). Every user reference has `FOREIGN KEY … REFERENCES users(id) ON DELETE CASCADE`. On MySQL, entry and group `ciphertext` are `LONGTEXT`.
- Produces: `Plugin::isSupported(): bool`, true when `function_exists('sodium_crypto_pwhash')`.

- [ ] **Step 1:** Write `SchemaTest::testCreatesEveryTable`. It runs `version_1` on the test PDO, then inserts and reads one row in each table. Write `testVaultMetaHasSingletonRow` (asserts `vault_meta` holds exactly the row `id = 1`). Write `testCascadeOnUserDelete` (insert a principal for user 2, delete user 2, and assert the principal row is gone). Write `testAllThreeDialectsDeclareVersion1`, matching the Presence pattern. Write `PluginVersionTest::testVersionsAgree`, asserting `plugin.json` version == `getPluginVersion()` == `'1.0.0'`.
- [ ] **Step 2:** Run `./testing/run-plugin-tests.sh Vault`. Expected: FAIL (missing files).
- [ ] **Step 3:** Implement the schemas and `Plugin.php`. `initialize()` returns after registering the notice through `template:layout:top` when `!isSupported()`. Add `../Vault:/var/www/app/plugins/Vault` to `testing/docker-compose.dev.yml`.
- [ ] **Step 4:** Run `./testing/run-plugin-tests.sh Vault`. Expected: PASS. Then run `./testing/run-plugin-tests.sh --all`. Expected: every suite passes.
- [ ] **Step 5:** Create the repo: `cd Vault && git init && gh repo create carmelosantana/kanboard-vault --private --source . ` (it stays private until the implementation security gate). Commit with `feat: scaffold Vault plugin, schema v1`.

### Task 2: The dev image with ext-sodium

Spec: *Plugin shape and packaging*: production image.

**Files:**
- Create: `testing/Dockerfile.sodium`
- Modify: `testing/docker-compose.dev.yml` (the `kanboard` service: `build: {context: ., dockerfile: Dockerfile.sodium}` replaces `image:`)
- Test: `testing/check-sodium.sh`

**Interfaces:**
- Produces: the `kb-suite` container, with `php -m` listing `sodium`.

- [ ] **Step 1:** Write `testing/check-sodium.sh`, which runs `docker exec kb-suite php -r 'exit(function_exists("sodium_crypto_pwhash")?0:1);'` and prints `sodium: ok` or fails.
- [ ] **Step 2:** Run `bash testing/check-sodium.sh`. Expected: FAIL (the stock image has no sodium).
- [ ] **Step 3:** Write the Dockerfile:
  ```
  FROM kanboard/kanboard:v1.2.47
  RUN apk upgrade --no-cache php84 'php84-*' && apk add --no-cache php84-sodium
  ```
  Pin the base image digest in a comment.
- [ ] **Step 4:** Run `docker compose -f testing/docker-compose.dev.yml up -d --build && bash testing/check-sodium.sh`. Expected: `sodium: ok`. http://localhost:8081 still logs in as admin. Other plugins still load: `curl -s localhost:8081 | grep -c kanboard` is greater than 0.
- [ ] **Step 5:** Commit in the dev-harness repo with `testing: Kanboard dev image with ext-sodium for Vault`, committing only these files.

### Task 3: Crypto core

Spec: *Cryptography → Canonical encoding*, *Principals and wraps*.

**Files:**
- Create: `Vault/Crypto/Encoding.php`, `Vault/Crypto/Aead.php`, `Vault/Crypto/Kdf.php`, `Vault/Crypto/Wraps.php`, `Vault/Crypto/Sealer.php`, `Vault/Crypto/RecoveryCode.php`, `Vault/Crypto/CryptoException.php`
- Test: `Vault/Test/Crypto/EncodingTest.php`, `AeadTest.php`, `WrapsTest.php`, `SealerTest.php`, `RecoveryCodeTest.php`

**Interfaces** (all static, pure, and free of DB access):
- `Encoding::enc(string|int ...$fields): string`: the tag `"vault/v1"`, then for each field a u32 big-endian length followed by its bytes. An int is encoded as 8-byte big-endian.
- `Aead::seal(string $plaintext, string $key, string $ad): array{nonce:string,ct:string}` and `Aead::open(string $nonce, string $ct, string $key, string $ad): string`, using XChaCha20-Poly1305-IETF. `open` throws `CryptoException` on failure.
- `Kdf::argon2id(string $secret, string $salt, int $ops, int $mem): string` (32 bytes), and `Kdf::kek(string $ikm, string ...$context): string` (BLAKE2b-256 keyed by `$ikm`, over `enc("kek", ...$context)`).
- `Wraps::wrap(string $sk, string $kek, int $principalId, string $method, string $credentialId, int $wrapId): array` and `Wraps::unwrap(array $row, string $kek, string $expectedPk): string`. `unwrap` throws when `sodium_crypto_box_publickey_from_secretkey($sk) !== $expectedPk`.
- `Sealer::sealDek(string $dek, string $objectType, int $objectId, int $keyEpoch, int $principalId, string $pk): string` and `Sealer::openDek(string $sealed, string $keypair, string $objectType, int $objectId, int $keyEpoch, int $principalId): string`. `openDek` throws on a field mismatch.
- `RecoveryCode::generate(): array{secret:string,display:string}` and `RecoveryCode::parse(string $input): string` (20 bytes). `parse` throws on a checksum or length error. The format is Crockford base32, 8 groups of 4, plus 2 check characters (CRC-16), with `I/L→1` and `O→0`.
- Defaults: `Kdf::DEFAULT_OPS = 3`, `Kdf::DEFAULT_MEM = 67108864`.

- [ ] **Step 1:** Write the failing tests. Each one also names the mutation it kills:
  - `EncodingTest::testLengthPrefixedNotConcatenated`: asserts `enc('ab','c') !== enc('a','bc')` (kills plain concatenation).
  - `testIntIsEightBytes`: asserts `strlen(enc(1)) === 8 + 4 + 8` (kills a 4-byte int).
  - `AeadTest::testEachAdFieldMatters`: changes each AD field in turn and expects `CryptoException` (kills ignoring the AD).
  - `WrapsTest::testRoundTripPerMethod` covers `passphrase`, `passphrase+prf`, `recovery` and `agent`.
  - `WrapsTest::testWrongKekFails`.
  - `WrapsTest::testPublicKeyMismatchFails`: unwrap succeeds cryptographically but the expected pk differs, so it throws (kills a missing pk check).
  - `SealerTest::testForeignObjectRejected`: seal for entry 1 and open as entry 2, which throws (kills skipping the field check).
  - `SealerTest::testEpochBound`.
  - `RecoveryCodeTest::testNormalisesCaseAndSeparators`: `parse(strtolower(str_replace('-', ' ', $display)))` equals the secret.
  - `RecoveryCodeTest::testChecksumEnforced`: one character flipped throws.
  - `RecoveryCodeTest::testExactLength`.
- [ ] **Step 2:** Run `./testing/run-plugin-tests.sh Vault`. Expected: FAIL.
- [ ] **Step 3:** Implement the classes. Call `sodium_memzero` on KEKs and secret keys in `finally` blocks.
- [ ] **Step 4:** Run `./testing/run-plugin-tests.sh Vault`. Expected: PASS. Then run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(crypto): canonical encoding, AEAD, KDF, wraps, sealed DEKs, recovery codes`.

### Task 4: Vault lock, throttling, Argon2 slots and client IP

Spec: *Entries, groups and grants → the vault lock*, *Throttling*.

**Files:**
- Create: `Vault/Model/VaultLock.php`, `Vault/Model/RateLimiter.php`, `Vault/Model/ArgonSlots.php`, `Vault/Model/ClientIp.php`
- Test: `Vault/Test/VaultLockTest.php`, `RateLimiterTest.php`, `ArgonSlotsTest.php`, `ClientIpTest.php`

**Interfaces:**
- `VaultLock::exclusive(callable $fn): mixed` and `VaultLock::shared(callable $fn): mixed`.
  - SQLite: `exclusive` runs raw `BEGIN IMMEDIATE`, then `COMMIT`, or `ROLLBACK` if the callable throws. `shared` runs a deferred `BEGIN`.
  - MySQL and Postgres: both run a transaction plus `SELECT id FROM vault_meta WHERE id = 1 FOR UPDATE` (exclusive) or `FOR SHARE` (shared).
  - Nesting: calling `exclusive` inside `exclusive` re-uses the outer transaction. Calling `exclusive` inside `shared` throws `LogicException`.
- `RateLimiter::consume(string $bucket, float $capacity, float $refillPerSec, float $cost = 1.0): bool` is a token bucket, row-locked inside `VaultLock::exclusive`.
- `RateLimiter::backoffSeconds(string $bucket): int` and `RateLimiter::recordFailure(string $bucket): int`: 5 failures allowed, then back-off from 60 s, doubling, capped at 3600 s.
- `RateLimiter::recordSuccess(string $bucket): void`.
- `ArgonSlots::acquire(int $principalId): ?ArgonSlot`: a per-principal `flock` on `DATA_DIR/vault/p-<id>.lock` plus one of 4 global `DATA_DIR/vault/slot-N.lock` files, all non-blocking. Returns `null` when busy. `ArgonSlot::release(): void`.
- `ClientIp::resolve(Request $r, array $trustedCidrs): string` follows the spec's rules: `CF-Connecting-IP` must hold exactly one IP, and `X-Forwarded-For` is walked right to left. `ClientIp::truncate(string $ip): string` returns IPv4 /24 or IPv6 /48.

- [ ] **Step 1:** Write the failing tests:
  - `VaultLockTest::testExclusiveRollsBackOnThrow`.
  - `VaultLockTest::testSqliteUsesBeginImmediate`. Two PDO connections to one temp-file DB: connection A holds `exclusive`, and B's `exclusive` gets `SQLITE_BUSY` before A commits. This kills a deferred `BEGIN`.
  - `RateLimiterTest::testParallelConsumeNeverExceedsCapacity`: `pcntl_fork` 8 workers over a file DB, capacity 5, and exactly 5 succeed.
  - `RateLimiterTest::testBackoffDoublesAndCaps`: 60, 120, 240 … 3600.
  - `ArgonSlotsTest::testSecondAcquireSamePrincipalIsNull`.
  - `ArgonSlotsTest::testFifthGlobalAcquireIsNull`.
  - `ClientIpTest::testUntrustedRemoteIgnoresHeaders`.
  - `ClientIpTest::testCfConnectingIpExactlyOne`, where `"1.2.3.4, 5.6.7.8"` gives `REMOTE_ADDR`.
  - `ClientIpTest::testXffWalksRightSkippingTrusted`: with trusted `10.0.0.0/8`, the XFF `"6.6.6.6, 9.9.9.9, 10.0.0.2"` gives `9.9.9.9`. This kills a left-most read.
  - `ClientIpTest::testTruncate`.
- [ ] **Step 2:** Run `./testing/run-plugin-tests.sh Vault`. Expected: FAIL.
- [ ] **Step 3:** Implement the classes. Tests that need `pcntl` skip with a message when it's missing.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: vault lock, atomic rate limits, Argon2 slots, trusted-proxy client IP`.

### Task 5: Principals, enrolment, unlock and vault sessions

Spec: *Principals and wraps*, *Unlocked sessions*, *Throttling* (passphrase rows).

**Files:**
- Create: `Vault/Model/PrincipalModel.php`, `Vault/Model/UnlockService.php`, `Vault/Model/VaultSession.php`, `Vault/Subscriber/AuthSubscriber.php`
- Modify: `Vault/Plugin.php` (subscribe to `session.destroy` and `auth.success`)
- Test: `Vault/Test/EnrolUnlockTest.php`, `Vault/Test/VaultSessionTest.php`

**Interfaces:**
- `PrincipalModel::enrolHuman(int $userId, string $passphrase): void` checks the passphrase is at least 14 characters, isn't the username, and doesn't match the login password (`password_verify` against `users.password`). It throws `VaultException` with code `passphrase_weak` or `passphrase_reuse`.
- `PrincipalModel::get(int $userId): ?array`.
- `UnlockService::unlock(int $userId, string $passphrase, ?array $prf = null, string $ip): string` returns the `session_key`. Order of work: throttle, then slot, then Argon2, then unwrap. On failure it throws `VaultException('unlock_failed')`, a single generic code. It upgrades the wrap's KDF parameters when they're below the defaults.
- `VaultSession::create(int $userId, string $sk): string` returns the `session_key`. `VaultSession::secretKey(): ?string`: inside the request it checks the `sid_hash`, `user_id` match, `is_active`, both expiries and that the request isn't an API request; returns `sk` or `null`. Also `VaultSession::lock(int $userId, ?string $sidHash = null): void` and `VaultSession::cookieName(): string`, which returns `__Host-kb_vault`, or `kb_vault_dev` when the host is `localhost` over HTTP.
- Constants: idle timeout 900 s by default, absolute 28800 s, idle capped at the effective Kanboard session lifetime.

- [ ] **Step 1:** Write the failing tests:
  - `testEnrolRejectsShortAndLoginPassword`.
  - `testUnlockWrongPassphraseIsGeneric`: the message equals `unlock_failed` for a wrong passphrase and for an unknown user alike.
  - `testBackoffAfterFiveFailures`: the sixth attempt is refused without calling Argon2. Assert this with a `Kdf` spy counter, which kills throttling that runs after Argon2.
  - `testDbRowAloneCannotDecrypt`: decrypting `enc_sk` with the wrong `session_key` throws.
  - `testRowUserMismatchRefused`.
  - `testApiRequestIgnoresVaultSession`.
  - `testDisabledUserLocks`.
  - `testIdleAndAbsoluteExpiry`.
  - `testLogoutLocks` (dispatch `session.destroy`).
  - `testAuthSuccessDeletesUsersVaultSessions`.
  - `testKdfUpgradeOnUnlock`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the classes.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: enrolment, throttled unlock, cookie-split vault sessions`.

### Task 6: Encrypted entries and groups (owner only)

Spec: *Entries, groups and grants* (objects, read path, edit concurrency).

**Files:**
- Create: `Vault/Model/ObjectStore.php`, `Vault/Model/EntryPayload.php`
- Test: `Vault/Test/ObjectStoreTest.php`

**Interfaces:**
- `ObjectStore::createGroup(int $actorId, ?int $parentId, array $payload): int`, `createEntry(int $actorId, int $groupId, array $payload): int`, `readEntry(int $actorId, int $entryId, string $sk): array`, `readField(int $actorId, int $entryId, string $field, string $sk): string`, `updateEntry(int $actorId, int $entryId, int $expectedVersion, array $payload, string $sk): int` (the new version; throws `VaultException('conflict')`), `listEntries(int $actorId, int $groupId, string $sk, int $offset = 0, int $limit = 200): array` (metadata only), and `deleteObject(int $actorId, string $type, int $id): void`.
- `EntryPayload::validate(array $p): array` allows only these keys: `title`, `username`, `password`, `url`, `notes`, `tags`, `expires_at`, `otp`, `fields` (each `{name, value, protected}`). It enforces 256 KB or less of JSON, and throws `payload_too_large` or `payload_invalid`.
- Every read and write runs inside `VaultLock` (`shared` for reads, `exclusive` for writes).

- [ ] **Step 1:** Write the failing tests:
  - `testRoundTripUnicodeTabsEmoji`: a title with `"Ünïcode\t🔑"` and notes over 100 KB come back byte-identical.
  - `testCiphertextHasNoPlaintext`: grep the DB rows for the title and expect no match.
  - `testOtherUserCannotRead`: `not_found`.
  - `testStaleVersionConflicts`.
  - `testPayloadTooLarge`: 256 KB plus 1.
  - `testAdBinding`: copy entry 1's ciphertext into entry 2's row, and the read throws.
  - `testListIsMetadataOnly`: no `password` key.
  - `testListPaginates200`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the classes.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: encrypted entries and groups with AD binding and optimistic versions`.

### Task 7: Grants, revocation, the re-key queue, the write barrier and moves

Spec: *Entries, groups and grants*, every bullet from *Adding a recipient* through *Edit permission*.

**Files:**
- Create: `Vault/Model/GrantModel.php`, `Vault/Model/RecipientResolver.php`, `Vault/Model/Rekeyer.php`
- Modify: `Vault/Model/ObjectStore.php` (the read path checks live grants; writes run expiry reconciliation, then the barrier)
- Test: `Vault/Test/GrantTest.php`, `Vault/Test/GrantConcurrencyTest.php`

**Interfaces:**
- `RecipientResolver::effective(string $type, int $id, int $now): array<int principalId, 'owner'|'edit'|'read'>`, built from live, unexpired direct and ancestor grants.
- `GrantModel::grant(int $ownerId, string $type, int $id, int $granteeId, string $perm, ?int $expiresAt, string $ownerSk): void` seals to every recipient being added, across the subtree.
- `GrantModel::revoke(int $ownerId, string $type, int $id, int $granteeId, string $ownerSk): void` re-keys inline.
- `GrantModel::removeSource(int $grantId): void` is the spec's grant-source removal procedure, run inside the caller's exclusive lock.
- `GrantModel::move(int $ownerId, string $type, int $id, int $newParentId, string $ownerSk): void`.
- `Rekeyer::rekey(string $type, int $id, string $anyHolderSk, int $holderId): void` and `Rekeyer::drainQueue(int $holderId, string $holderSk, int $max = 50): int`.
- `ObjectStore::beforeWrite(string $type, int $id, int $actorId, string $sk): void` reconciles expired grants on the object and its ancestors through `removeSource`, then re-keys inline if the object is queued.
- Edit grantees can `updateEntry` and create entries in the group (owned by the group owner). Only the owner can grant, revoke, move or delete.

- [ ] **Step 1:** Write the failing tests:
  - `testOverlappingSourcesSurviveOneRevoke`: a direct grant plus a parent-group grant; revoking the direct one keeps access.
  - `testNestedGroupGrant`.
  - `testExpiredGrantRefusedOnRead`, using a mocked clock.
  - `testKeyRowAloneDoesNotGrant`: insert a stray key row, and the read is `not_found`.
  - `testRevokeRekeys`: the old sealed DEK can't open the new ciphertext.
  - `testReadOnlyCannotEdit`.
  - `testEditCannotGrant`.
  - `testEntryAddedByEditorOwnedByGroupOwner`.
  - `testMoveOutOfSharedGroupRemovesAccessAndQueues`.
  - `testWriteBarrierRekeysQueuedObject`.
  - `testEditImmediatelyAfterExpiryRekeysFirst`, with the purge throttled.
  - `testTwoSiblingExpiry`: A triggers reconciliation of an expired group grant, then B is edited, and B is re-keyed first. This kills a subject-only removal.
  - `testQueueIdempotentOnEpochChange`.
  - `GrantConcurrencyTest` uses two PDO connections on a file DB. `testEditVsRevoke`: neither write is lost, and the edit isn't under the old DEK. `testMoveVsRead`: a reader removed by the move never gets post-move data.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the classes.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: grant sources, effective recipients, re-key queue, write barrier, subtree moves`.

### Task 8: Key rotation

Spec: *Key rotation* (ceremony, state machine, bridge, crash recovery).

**Files:**
- Create: `Vault/Model/RotationService.php`
- Modify: `Vault/Model/UnlockService.php` (resume when `vault_rotations` has a row), `Vault/Model/GrantModel.php` (seal to both keys while `state = rotating`)
- Test: `Vault/Test/RotationTest.php`

**Interfaces:**
- `RotationService::start(int $userId, string $sk1, array $survivingFactors): void`. `$survivingFactors` is a list of `{method, kek, credential_id?}`.
- `RotationService::sweep(int $userId, string $sk1, string $sk2, int $batch = 100): int` (the number of objects left).
- `RotationService::finish(int $userId): void`.
- `RotationService::resume(int $userId, string $sk2): array{sk1:string,sk2:string}` opens the bridge and checks both public keys.
- Triggers wired here: passphrase change (`PrincipalModel::changePassphrase`) and **Rotate my keys** (`RotationService::requestManual`). Later tasks wire their own triggers: Task 9 (recovery and policy), Task 20 (hardware-key enrolment).

- [ ] **Step 1:** Write the failing tests:
  - `testOldPassphraseOpensNothingAfterStart`.
  - `testResumeWithOnlyNewPassphraseAfterSessionDeleted`.
  - `testCrashAfterEachSweepBatchThenResume`: throw from a hook after batch k, for k = 0..3.
  - `testGrantDuringRotationEndsSealedToNewKeyOnly`.
  - `testFinishRefusesWhileOldGenRowsRemain`.
  - `testPreRotationCredsCannotDecryptPostRotationDump`: take a full DB dump plus the old passphrase, and decrypting every entry fails.
  - `testOtherSessionsLockedAtStart`.
  - `testRotation10kEntriesBatchesUnder2s`: assert each batch transaction takes less than 2 s on SQLite. Mark it `@group slow`; it still runs in `--all`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the service.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: crash-safe key rotation with sk2-keyed bridge`.

### Task 9: Recovery policy, the org key and the CLI commands

Spec: *Recovery*, *Data model → Purge*.

**Files:**
- Create: `Vault/Model/RecoveryService.php`, `Vault/Model/VaultSettings.php`, `Vault/Console/OrgKeygenCommand.php`, `Vault/Console/OrgRecoverCommand.php`, `Vault/Console/PurgeCommand.php`, `Vault/Model/Purger.php`
- Modify: `Vault/Plugin.php` (register the console commands with `$this->cli->add(...)`)
- Test: `Vault/Test/RecoveryTest.php`, `Vault/Test/PurgeTest.php`

**Interfaces:**
- `VaultSettings::get(string $key): mixed` and `set(string $key, mixed $value, int $actorId): void` store to Kanboard config under `vault_*` and audit `policy_change`. Keys: `vault_recovery_policy` (`none|code|code_org`, default `none`), `vault_hw_required` (`0|1`), `vault_idle_timeout`, `vault_share_max_expiry`, `vault_reveal_limit`, `vault_agent_cap_max`, `vault_audit_retention_days` (365), `vault_trusted_proxy_cidrs` (default `''`).
- `RecoveryService::createCode(int $userId, string $sk): string` (the display form), `useCode(int $userId, string $code, string $newPassphrase): void` (atomic, then rotation), `setPolicy(string $policy, int $actorId): void` (lowering it deletes wraps and queues rotation), `orgKeygen(): string` (the secret display), and `orgRecover(string $username, string $orgSecret, string $newPassphrase): void`.
- `Purger::run(int $now): array` (counts) runs at most once per 60 s opportunistically. `vault:purge` runs it unconditionally.

- [ ] **Step 1:** Write the failing tests:
  - `testPolicyNoneHasNoRecoveryWraps`.
  - `testCodeUseIsAtomicAndConsumes`: a failure partway leaves the old code valid.
  - `testCodeRateLimitedLikePassphrase`.
  - `testOrgWrapAddedAtNextUnlockWithBanner`.
  - `testLoweringPolicyDeletesWraps`.
  - `testOrgRecoverRemovesHardwareKeysAndRotates`.
  - `testPurgeRemovesExpiredViaRemovalProcedure`.
  - `testOrgKeygenPrintsOnceStoresPublicOnly`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the services and commands.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: recovery policies, org key CLI, purge`.

### Task 10: Audit with a per-action allowlist

Spec: *Audit*.

**Files:**
- Create: `Vault/Model/AuditModel.php`
- Modify: every service from Tasks 5–9, to call `AuditModel::record()`
- Test: `Vault/Test/AuditTest.php`, `Vault/Test/LogHygieneTest.php`

**Interfaces:**
- `AuditModel::ALLOW`: a const array copied verbatim from the spec's per-action table.
- `AuditModel::record(string $action, array $cols): void` throws `LogicException` on an unknown action or a disallowed non-null column.
- `AuditModel::visibleTo(int $viewerId, int $offset, int $limit): array` follows the spec's *Who sees what*. `AuditModel::purge(int $retentionDays): int`.
- `Vault/Test/Support/Fixtures.php` (created here and used by later tests): `Fixtures::SECRETS` is a list of plaintext markers placed in every fixture.

- [ ] **Step 1:** Write the failing tests. `AuditTest::testAllowlistTableDriven` is a data provider over `ALLOW`: for each action, filling a disallowed column throws. `testUnknownActionThrows`. `testVisibility` covers the owner, an agent's owner and an admin. `LogHygieneTest::testNoFixtureSecretInLogs` runs every service against the fixtures, then greps the Kanboard log and the PHP error log captured by the test bootstrap for every `Fixtures::SECRETS` value.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the model and wire the calls.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: allowlisted audit log and log hygiene test`.

## Phase B: Web UI

### Task 11: Vault page shell, enrolment and unlock flows, and security headers

Spec: *UI → States*, *Assets and headers*, *Unlocked sessions*.

**Files:**
- Create: `Vault/Controller/VaultController.php`, `Vault/Controller/BaseVaultController.php`, `Vault/Template/vault/{locked,enrol,shell}.php`, `Vault/Assets/js/vault.js`, `Vault/Assets/css/vault.css`
- Modify: `Vault/Plugin.php` (routes `vault`, `vault/unlock`, `vault/lock`, `vault/enrol`; an avatar-menu link through `template:header:dropdown`; a dashboard sidebar link)
- Test: `Vault/Test/VaultControllerTest.php`, `Vault/Test/js/vault.test.mjs`

**Interfaces:**
- `BaseVaultController::respond(...)` sets `X-Frame-Options: DENY`, the per-response CSP (`$this->container['cspRules'] + ['frame-ancestors' => "'none'"]`) and `no-store`. `requireUnlocked(): string` returns `sk` or redirects to the locked view. `requirePostCsrf(): void`.
- The unlock POST returns JSON `{ok}` or `{ok:false, reason:'unlock_failed'|'rate_limited'|'busy'|'https_required'}`. `busy` maps to 503 with `Retry-After: 2`, and `rate_limited` to 429.
- In `vault.js`, `window.KBVault` exposes `api(action, body): Promise<object>` (a POST with `csrf_token`) and `lockOnIdle(ms)`.

- [ ] **Step 1:** Write the failing tests:
  - `testHeadersOnEveryVaultResponse` (XFO, `frame-ancestors`, `no-store`).
  - `testGlobalCspUnchanged`: a non-Vault page's CSP has no `frame-ancestors`.
  - `testUnlockRequiresPostAndCsrf`.
  - `testHttpsRequiredOffLocalhost`.
  - `testVaultScriptsOnlyOnVaultPages`: render the dashboard and assert `vault.js` is absent.
  - `vault.test.mjs`: `api()` sends `csrf_token`.
- [ ] **Step 2:** Run the Vault suite and `node --test Vault/Test/js`. Expected: FAIL.
- [ ] **Step 3:** Implement the controllers, templates and JS.
- [ ] **Step 4:** Run `--all` and `node --test Vault/Test/js`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(ui): vault shell, enrol and unlock, hardened headers`.

### Task 12: Three-pane browser, reveal and copy, entry editor

Spec: *UI* (layout sketch, secrets never in HTML, copy, escaping).

**Files:**
- Create: `Vault/Controller/EntryController.php`, `Vault/Template/vault/{tree,list,detail,edit}.php`, `Vault/Assets/js/vault-browser.js`
- Test: `Vault/Test/EntryControllerTest.php`, `Vault/Test/js/vault-browser.test.mjs`

**Interfaces:**
- POST endpoints `vault/tree`, `vault/list` (paginated at 200), `vault/entry`, `vault/field` and `vault/save`. All return JSON. `vault/field` returns `{value}` and audits `read`.
- `KBVault.render(node, data)` uses only `textContent` and `setAttribute`. `KBVault.safeHref(url): string|null` allows only `http:` and `https:`. `KBVault.copy(text)` clears after 30 000 ms if the document still has focus.

- [ ] **Step 1:** Write the failing tests:
  - `testDetailHtmlHasNoSecret`: the rendered HTML doesn't contain the password.
  - `testFieldEndpointAuditsRead`.
  - `testFieldNoStore`.
  - `vault-browser.test.mjs::renders <img onerror> title as text`.
  - `safeHref rejects javascript: and data:`.
  - `copy schedules a clear at 30000ms`.
- [ ] **Step 2:** Run the Vault suite and the node tests. Expected: FAIL.
- [ ] **Step 3:** Implement the endpoints, templates and JS.
- [ ] **Step 4:** Run `--all` and the node tests. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(ui): KeePass-style browser, reveal and copy, editor`.

### Task 13: Password and passphrase generator

Spec: *UI → The generator*.

**Files:**
- Create: `Vault/Assets/js/vault-generator.js`, `Vault/Assets/vendor/eff_large_wordlist.txt` (with `CC BY 3.0` noted in `Vault/Assets/vendor/VENDOR.md`)
- Test: `Vault/Test/js/vault-generator.test.mjs`

**Interfaces:**
- `KBVaultGen.password({length:8..128, lower, upper, digits, symbols, excludeLookalikes}): string`
- `KBVaultGen.passphrase({words:6, sep:'-'}): string`
- `KBVaultGen.entropyBits(opts): number`
- `KBVaultGen.uniform(n): number` uses rejection sampling over `crypto.getRandomValues`.

- [ ] **Step 1:** Write the failing tests:
  - `uniform(10) over 100k draws: every bucket within 5% of 10k` (kills a plain `% n`).
  - `excludeLookalikes never yields 0O1lI`.
  - `password honours every selected class`.
  - `passphrase has 6 words from the list`.
  - `entropy for 6 words is about 77.5 bits`.
  - `wordlist has 7776 lines and its sha256 matches VENDOR.md`.
- [ ] **Step 2:** Run `node --test Vault/Test/js`. Expected: FAIL.
- [ ] **Step 3:** Implement the generator.
- [ ] **Step 4:** Run `node --test Vault/Test/js`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(ui): password and passphrase generator`.

### Task 14: Sharing dialog, grants UI and activity view

Spec: *UI → The sharing dialog*, *Audit → Who sees what*.

**Files:**
- Create: `Vault/Controller/ShareGrantController.php`, `Vault/Template/vault/{share_dialog,activity}.php`, `Vault/Assets/js/vault-share.js`
- Test: `Vault/Test/ShareGrantControllerTest.php`

**Interfaces:** POST `vault/grant {type, id, grantee_id, perm: read|edit, expires_at?}`, `vault/revoke {type, id, grantee_id}` and `vault/activity {offset}`. These call `GrantModel` and `AuditModel::visibleTo`. Sharing a group returns `{covered_entries: n}` for the confirmation step.

- [ ] **Step 1:** Write the failing tests:
  - `testNonOwnerGrantForbidden`.
  - `testGroupGrantReportsCoveredCount`.
  - `testRevokeSuggestsRotation`: the response has `suggest_rotate: true`.
  - `testActivityHidesOthersRows`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the controller, templates and JS.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(ui): sharing dialog, revoke, activity`.

### Task 15: Admin settings and deployment checks

Spec: *Admin settings*, *Threat model → Deployment preconditions*.

**Files:**
- Create: `Vault/Controller/SettingsController.php`, `Vault/Template/config/vault.php`, `Vault/Model/DeploymentChecks.php`
- Modify: `Vault/Plugin.php` (`template:config:sidebar` link, route `vault/settings`)
- Test: `Vault/Test/SettingsTest.php`, `Vault/Test/DeploymentChecksTest.php`

**Interfaces:** `DeploymentChecks::run(Request $r): array<string, {ok:bool, message:string}>`. The keys are `https`, `plugin_installer`, `plugin_dir_writable`, `proxy_headers` and `sodium`. The settings form allows an idle timeout of 5–60 and shows the effective cap: `min(setting, session lifetime)`, where the session lifetime is `SESSION_DURATION` or `ini_get('session.gc_maxlifetime')` when that's 0.

- [ ] **Step 1:** Write the failing tests:
  - `testNonAdminForbidden`.
  - `testEveryChangeAuditsPolicyChange`.
  - `testProxyWarningWhenPrivateRemoteAndNoTrustedCidrs`.
  - `testEffectiveIdleCapShown`.
  - `testRpIdReadOnly`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the controller, template and model.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat: admin settings and deployment checks`.

## Phase C: Agents

### Task 16: Agent enrolment, re-enrolment and the JSON-RPC API

Spec: *Agents* (all of it).

**Files:**
- Create: `Vault/Model/AgentVault.php`, `Vault/Api/VaultAgentProcedure.php`, `Vault/Template/vault/agents.php`
- Modify: `Vault/Plugin.php`: `$this->api->getProcedureHandler()->withObject(new Api\VaultAgentProcedure($this->container))`, following the `Agents/Plugin.php:38-40` pattern and its prefixed-class-name ACL note
- Test: `Vault/Test/AgentVaultTest.php`, `Vault/Test/VaultAgentProcedureTest.php`

**Interfaces:**
- `AgentVault::enrol(int $ownerId, int $agentId, string $ownerSk): string` returns the vault key in base64url. It refuses with `owner_not_enrolled`. It creates the "Agent-created" group (`kind = agent_created`), owned by the agent and granted Edit to the owner.
- `AgentVault::reenrol(int $ownerId, int $agentId, string $ownerSk): string` follows the spec's 5 steps in one exclusive transaction.
- `AgentVault::authenticate(): array{agent_id:int, sk:string}`:
  - checks `hash_equals($user['api_access_token'], $basicPassword)`;
  - refuses the `jsonrpc` user;
  - checks roster membership through `class_exists(AgentTable::class)` and `isAgent`;
  - reads the `X-Kanboard-Vault-Key` header;
  - applies throttling per agent and IP.
- Procedures (each returns `{ok, ...}` or `{ok:false, reason}`): `getVaultEntries($group_id = 0)`, `getVaultSecret($entry_id, $field = 'password')`, `createVaultEntry($fields)` and `updateVaultEntry($entry_id, $expected_version, $fields)`. Reasons: `not_agent`, `bad_vault_key`, `rate_limited`, `not_found`, `conflict`, `agents_unavailable`.
- The read cap is a token bucket `agentread:<id>`, 60 per hour with a burst of 10, `min(owner setting, admin max)`.

- [ ] **Step 1:** Write the failing tests:
  - `testLoginPasswordRefusedTokenAccepted`: an adopted user with a real password.
  - `testAppTokenRefused`.
  - `testNonAgentNotAgent`.
  - `testMissingOrWrongVaultKey`.
  - `testLeakedTokenOtherIpDoesNotLockRealAgent`.
  - `testUnseenEntryIsNotFound`.
  - `testCannotUpdateSharedEntry`.
  - `testCreateGoesToAgentCreatedOwnerSees`.
  - `testReadCapAtomic` (forked workers).
  - `testReenrolDeletesThirdPartyGrantsAndQueueDoesNotRestore`: after re-enrolment, run `drainQueue` as another user, and the agent still gets `not_found`.
  - `testReenrolRekeysAgentCreatedObjects`.
  - `testAgentsPluginAbsentGivesAgentsUnavailable`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the model and the procedure.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(agents): vault key enrolment, token-only auth, JSON-RPC methods`.

## Phase D: One-time shares

### Task 17: Share server (reservation, storage, atomic reveal, public page)

Spec: *One-time shares*.

**Files:**
- Create: `Vault/Model/ShareModel.php`, `Vault/Controller/VaultShareController.php`, `Vault/Template/share/show.php`
- Modify: `Vault/Plugin.php` (`VaultShareController::show` and `::reveal` registered with `Role::APP_PUBLIC`)
- Test: `Vault/Test/ShareModelTest.php`, `Vault/Test/ShareConcurrencyTest.php`, `Vault/Test/VaultShareControllerTest.php`

**Interfaces:**
- `ShareModel::reserve(int $creatorId, string $sidHash, string $expiry /* 1h|1d|7d */): array{share_id:string, expires_at:int}`
- `ShareModel::finalize(int $creatorId, string $sidHash, array $body): void`
- `ShareModel::reveal(string $id, string $burn, string $ip): ?array{ciphertext,iv,salt}` uses `BEGIN IMMEDIATE`, compares `hash_equals` on `BLAKE2b(burn)`, deletes, and returns. It returns `null` for every failure.
- `ShareModel::revoke(int $creatorId, string $idHash): void` and `ShareModel::listMine(int $creatorId, string $sk): array`.
- Limits: a payload of 64 KB or less. The reveal rate is 20 per 10 min and 200 per day per IP, through `RateLimiter`.

- [ ] **Step 1:** Write the failing tests:
  - `testIdAloneCannotBurn`.
  - `testWrongBurnSameGenericNull`.
  - `testExpiredNull`.
  - `testRevokeBeforeView`.
  - `ShareConcurrencyTest::testExactlyOneOfTwoReveals` (two connections).
  - `testShellIndependentOfId`: render `show` for a real, a burned and a fake id, mask the CSRF and session values, and the outputs are equal.
  - `testPublicHeaders`: `no-store`, `no-referrer`, `noindex`, XFO.
  - `testRevealNeedsCsrf`.
  - `testReservationSingleUseAndBound`.
  - `testRateLimit`.
  - `testNoEntryLinkStored`: no `vault_shares` column references an entry.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the model, controller and template.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(shares): reservations, burn capability, atomic one-time reveal`.

### Task 18: Share browser crypto and the Argon2 worker

Spec: *One-time shares → Creating a share*, *KeePassXC → Libraries* (noble Argon2 in a module worker).

**Files:**
- Create: `Vault/Assets/vendor/noble-hashes/*` (vendored after `/powerup:supply-chain`; pinned in `VENDOR.md`), `Vault/Assets/js/argon2-worker.mjs`, `Vault/Assets/js/vault-onetime.js`, `Vault/Assets/js/vault-reveal.js`
- Test: `Vault/Test/js/vault-onetime.test.mjs`, `Vault/Test/js/argon2-worker.test.mjs`

**Interfaces:**
- `argon2-worker.mjs` takes the message `{id, type:'argon2id'|'argon2d', password, salt, t, m, p, dkLen}` and replies `{id, hash}` or `{id, error}`.
- `KBVaultOnetime.encrypt({plaintext, shareId, expiresAt, passphrase?}): Promise<{ciphertext, iv, salt, has_passphrase, burn_hash, fragment}>`. The key is `HKDF-SHA256(k ‖ Argon2id(p, salt, 3, 64 MiB), "vault/share/v1")`, and the AD is `enc("share", 1, share_id, expires_at, has_passphrase)`, matching the PHP `Encoding::enc` byte for byte.
- `KBVaultOnetime.decrypt({ciphertext, iv, salt, has_passphrase, fragment, shareId, expiresAt, passphrase?})`.

- [ ] **Step 1:** Write the failing tests:
  - `enc() matches PHP vector`: commit a vector file `Vault/Test/fixtures/enc-vectors.json` produced by PHP in this task, and assert the JS output equals it.
  - `round trip with and without passphrase`.
  - `wrong passphrase rejects and ciphertext stays usable for retry`.
  - `changing any AD field fails decryption`.
  - `noble files load as-is in a module worker` (run through `node --experimental-vm-modules` with the worker), or the documented esbuild fallback is recorded in `VENDOR.md`.
  - `argon2id KAT from RFC 9106` passes.
- [ ] **Step 2:** Run `node --test Vault/Test/js`. Expected: FAIL.
- [ ] **Step 3:** Implement the worker and the share JS.
- [ ] **Step 4:** Run `node --test Vault/Test/js` and `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(shares): browser encryption, Argon2 worker, reveal page`.

## Phase E: Hardware keys

### Task 19: WebAuthn verifier (CBOR, authData, COSE)

Spec: *Hardware keys*: origin and RP ID, the CBOR rule list, Registration, Unlock.

**Files:**
- Create: `Vault/WebAuthn/Cbor.php`, `Vault/WebAuthn/AuthData.php`, `Vault/WebAuthn/CoseKey.php`, `Vault/WebAuthn/Verifier.php`
- Create: `Vault/Test/fixtures/webauthn/*.json`, recorded from a Chrome DevTools virtual authenticator (ES256 and EdDSA, PRF on) with `scripts/e2e/record-webauthn.mjs`
- Test: `Vault/Test/WebAuthn/CborTest.php`, `AuthDataTest.php`, `CoseKeyTest.php`, `VerifierTest.php`

**Interfaces:**
- `Cbor::decode(string $bytes): mixed` throws `CborException` on every rule in the spec's CBOR list.
- `AuthData::parse(string $bytes, bool $expectAttested): AuthData` (with properties `rpIdHash`, `flags`, `signCount`, `credentialId`, `coseKey`).
- `CoseKey::fromCbor(array $map): CoseKey` (`alg` is -7 or -8). `CoseKey::verify(string $data, string $sig): bool`. `CoseKey::spki(): string`, checked through `openssl_pkey_get_details` (curve `prime256v1`, matching x and y).
- `Verifier::register(array $clientData, string $attObj, string $challenge, string $origin, string $rpId): array` (the credential record) and `Verifier::assert(array $cred, string $clientDataJson, string $authData, string $sig, string $challenge, string $origin, string $rpId): int` (the new signCount). The policy: UP required, UV optional.

- [ ] **Step 1:** Write the failing tests. Each rejection rule gets one test:
  - `testDuplicateMapKey`, `testNonMinimalInt`, `testIndefiniteLength`, `testTrailingBytes`, `testDepthOver4`, `testOver64Items`, `testInvalidUtf8`.
  - `testFmtNotNone`, `testAttStmtNotEmpty`, `testOffCurvePoint`, `testWrongCoordSize`, `testAlgKtyMismatch`.
  - `testForeignOrigin`, `testCrossOriginTrue`, `testWrongRpIdHash`, `testUpMissing`, `testUvAbsentAccepted`.
  - `testSignCountRegression`, `testRecordedVectorsPass`.
- [ ] **Step 2:** Run the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the verifier classes.
- [ ] **Step 4:** Run `--all`. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(webauthn): fail-closed CBOR/COSE verifier`.

### Task 20: Hardware-key enrolment and unlock

Spec: *Hardware keys → Enrolment, two keys required*, *Removing a key*, *relying-party ID*, *Gate*.

**Files:**
- Create: `Vault/Model/HardwareKeys.php`, `Vault/Controller/HardwareKeyController.php`, `Vault/Assets/js/vault-webauthn.js`, `Vault/Template/vault/hwkeys.php`
- Modify: `Vault/Model/UnlockService.php` (`$prf` path), `Vault/Model/RotationService.php` (enrolment trigger)
- Test: `Vault/Test/HardwareKeysTest.php`, plus `scripts/e2e/vault-webauthn.mjs` (virtual authenticator)

**Interfaces:**
- `HardwareKeys::challenge(int $userId, string $purpose): string`: single-use, valid for 120 s.
- `HardwareKeys::registerKey(int $userId, string $sk, array $response, string $passphrase): void`. Registering the second key deletes the bare `passphrase` wrap and starts rotation.
- `HardwareKeys::removeKey(int $userId, string $credentialId, array $otherKeyAssertion): void`.
- `HardwareKeys::rpBinding(): array{origin, rp_id}` reads Kanboard's application URL. It throws `rp_id_mismatch` or `application_url_required`.
- The JS calls `navigator.credentials.create` and `get` with `extensions.prf` (`eval` and `evalByCredential`), and refuses a key when `prf.enabled` is missing.

- [ ] **Step 1:** Write the failing tests:
  - `testSecondKeyDeletesPassphraseWrapAndRotates`.
  - `testRequiredPolicyForcesEnrolment`.
  - `testCannotDropBelowTwoWhenRequired`.
  - `testReplayedPrfWithoutFreshAssertionFails`.
  - `testRpIdMismatchMessage`.
  - `testApplicationUrlRequired`.
  - `testRecoveryRemovesKeys`.
- [ ] **Step 2:** Run the Vault suite and the E2E script. Expected: FAIL.
- [ ] **Step 3:** Implement the model, controller, template and JS.
- [ ] **Step 4:** Run `--all` and `node scripts/e2e/vault-webauthn.mjs`. Expected: PASS. **Gate:** if this task's verification can't pass, stop and report. Hardware keys then slip to v1.1, per the spec.
- [ ] **Step 5:** Commit with `feat: hardware-key (WebAuthn PRF) two-factor unlock`.

## Phase F: KeePassXC

### Task 21: Vendor and patch kdbxweb

Spec: *KeePassXC import and export → Libraries*, *Where the code runs*, *Local patches*.

**Files:**
- Create: `Vault/Assets/vendor/kdbxweb/kdbxweb.js` (non-minified 2.1.1), `Vault/Assets/vendor/kdbxweb/patches/*.patch`, an updated `Vault/Assets/vendor/VENDOR.md`
- Create: `Vault/Assets/js/vault-kdbx.js`, which wires `kdbxweb.CryptoEngine.setArgon2Impl` to `argon2-worker.mjs` (Task 18)
- Test: `Vault/Test/js/kdbx-patches.test.mjs`

**Interfaces:** `KBVaultKdbx.load(fileBytes, password, keyFileBytes?): Promise<Kdb>` and `KBVaultKdbx.cancel()`, which terminates the worker.

- [ ] **Step 1:** Run `/powerup:supply-chain` for `kdbxweb@2.1.1` and `@noble/hashes@2.4.0`. Record the publish dates, maintainers, provenance and sha256 in `VENDOR.md`.
- [ ] **Step 2:** Write the failing tests:
  - `final HMAC block is verified`: a fixture whose last block is tampered rejects.
  - `tabs and whitespace preserved`.
  - `keys zeroed on failed load`: spy on `fill(0)`.
  - `vendored file sha256 matches VENDOR.md after patches`.
- [ ] **Step 3:** Run `node --test Vault/Test/js`. Expected: FAIL.
- [ ] **Step 4:** Apply each patch by hand, using the `@keenext` fork's diff as a reference only. Record each patch with its upstream issue link.
- [ ] **Step 5:** Run `node --test Vault/Test/js`. Expected: PASS. Commit with `vendor: kdbxweb 2.1.1 with reviewed local patches`.

### Task 22: Import

Spec: *Import* (pre-checks, mapping, dropped items, upload).

**Files:**
- Create: `Vault/Assets/js/vault-kdbx-header.js`, `Vault/Assets/js/vault-import.js`, `Vault/Controller/ImportController.php`, `Vault/Template/vault/import.php`
- Create: `Vault/Test/fixtures/kdbx/*.kdbx`, generated by `Vault/Test/fixtures/kdbx/make-fixtures.sh` with `keepassxc-cli`
- Test: `Vault/Test/js/kdbx-header.test.mjs`, `Vault/Test/ImportControllerTest.php`

**Interfaces:**
- `KBVaultKdbxHeader.check(bytes): {ok:true, version, kdf} | {ok:false, reason}`. Reasons: `too_large` (> 50 MB), `argon_mem` (> 1 GiB), `argon_iter` (> 100), `argon_lanes` (> 8), `aes_rounds` (> 1e8), `twofish`, `yubikey_cr`.
- POST `vault/import/batch {group_path[], entries[≤100]}` returns `{created}`. The target group is `Imported <Y-m-d>`. The file name is never stored.
- The import report shows `skipped_attachments`. Imports over 20 000 entries, or with any entry over 256 KB, are refused.

- [ ] **Step 1:** Write the failing tests. One `kdbx-header` test per reason code, each against its own crafted header fixture. `testBatchOver100Refused`, `testNoFilenameStored`, `testMappingFieldForField`: the KDBX 3.1 AES-KDF, 4.0 Argon2d+AES, 4.0 Argon2id+ChaCha20 and key-file fixtures each import with title, username, password, URL, notes, tags, expiry, custom fields and protection matching, and `otp` becomes a protected field.
- [ ] **Step 2:** Run the node tests and the Vault suite. Expected: FAIL.
- [ ] **Step 3:** Implement the header check, the import JS, the controller and the template.
- [ ] **Step 4:** Run `--all` and the node tests. Expected: PASS.
- [ ] **Step 5:** Commit with `feat(kdbx): guarded browser import`.

### Task 23: Export and the CI gate

Spec: *Export*, *CI gate*.

**Files:**
- Create: `Vault/Assets/js/vault-export.js`, `Vault/Controller/ExportController.php`, `Vault/.github/workflows/kdbx-gate.yml`, `Vault/Test/kdbx-gate/run.sh`, `Vault/Test/kdbx-gate/verify.py` (pykeepass)
- Test: `Vault/Test/ExportControllerTest.php`, plus the gate script

**Interfaces:**
- POST `vault/export {include_shared}` requires a fresh re-authentication (the passphrase, plus a key if one is enrolled). It returns decrypted JSON, `no-store`, and audits `export`.
- `KBVaultExport.build(entries, password): Promise<Uint8Array>` writes KDBX 4.0 with Argon2id and ChaCha20.
- `run.sh` does the following:
  1. It generates the fixtures (all the spec's cases: Unicode, tabs, 10 000 entries, truncated, bad HMAC, hostile KDF).
  2. It drives import and then export in headless Chrome through Playwright against a throwaway Kanboard container built from `testing/Dockerfile.sodium`.
  3. It runs `keepassxc-cli ls` and `show`, plus `python3 -I verify.py`, on the export, comparing every field.

  It exits 0 only if every check passes.

- [ ] **Step 1:** Write the failing tests:
  - `testExportRequiresFreshReauth`.
  - `testExportAudited`.
  - `testIncludeSharedOptional`.
  - Write `run.sh` and `verify.py` with the field-for-field comparison.
- [ ] **Step 2:** Run `bash Vault/Test/kdbx-gate/run.sh`. Expected: FAIL (no export yet).
- [ ] **Step 3:** Implement the export JS and the controller.
- [ ] **Step 4:** Run `bash Vault/Test/kdbx-gate/run.sh`. Expected: `kdbx gate: PASS` with both readers. **Gate:** if it can't pass, remove the export UI behind `VaultSettings` flag `vault_export_enabled = 0`, note *export slips to v1.1* in the README, and report.
- [ ] **Step 5:** Commit with `feat(kdbx): verified KDBX 4.0 export and CI gate`.

## Phase G: Proof and release

### Task 24: The backup-theft test and browser E2E

Spec: *Testing* (backup theft, E2E), *Review Focus*.

**Files:**
- Create: `Vault/Test/BackupTheftTest.php`, `scripts/e2e/vault-e2e.mjs`

**Interfaces:**
- `BackupTheftTest::testDumpHasNoPlaintext` populates `Fixtures` (users, groups, entries, shares, grants, an agent and an import), dumps the SQLite file plus `config.php` and env, then greps for every `Fixtures::SECRETS` value. Expected: zero hits.
- `vault-e2e.mjs` runs against :8081 and covers:
  - enrolment, unlock, create, copy;
  - sharing (read, then edit) with user `e2e-b`, created through PDO in the container and never through MCP;
  - a one-time link revealed in a fresh browser context;
  - import, then export;
  - a CSP sweep: no console violations on Vault pages, and no `vault*.js` on the dashboard;
  - Review Focus 1, 4 and 5.

- [ ] **Step 1:** Write both tests.
- [ ] **Step 2:** Run `./testing/run-plugin-tests.sh Vault` and `node scripts/e2e/vault-e2e.mjs`. Expected: PASS. If anything fails, the fix goes into the owning task's files with a regression test.
- [ ] **Step 3:** Run `./testing/run-plugin-tests.sh --all` to completion. Expected: every suite passes.
- [ ] **Step 4:** Commit with `test: backup-theft proof and Vault E2E`.

### Task 25: Docs, implementation security gate and release

Spec: *Security review gates*, *Plugin shape and packaging*.

**Files:**
- Modify: `Vault/README.md` (install with the sodium image, Cloudflare Tunnel and trusted proxies, crontab `vault:purge`, domain-change warning, recovery policies, the historical-backup limit)
- Create: `Vault/docs/security.md` (the threat model, linking the spec)

- [ ] **Step 1:** Write the README and `docs/security.md`.
- [ ] **Step 2:** Run `/codex:adversarial-review` and `/powerup:codex-security` on the branch. Fix every critical or high finding with a regression test, then re-run both until neither reports critical or high. Record each round in `Vault/docs/security.md`.
- [ ] **Step 3:** Run a whole-branch Opus review (superpowers:requesting-code-review), then `--all` and the E2E. Expected: PASS.
- [ ] **Step 4:** Ask Carmelo before tagging, before making the repo public, and before any ModMenu listing. On his yes, tag `v1.0.0`; CI publishes the zip.
- [ ] **Step 5:** File the follow-up for the `kanboard` MCP client (spec *Agents → Agent client*) as a new task.
