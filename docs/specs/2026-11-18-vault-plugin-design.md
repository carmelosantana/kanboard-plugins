# Vault: a KeePass-style password store for Kanboard (v1.0)

- **Status:** **approved by Carmelo (2026-11-18).** Review round 1 (builder check, Codex adversarial review, Codex security review) is folded in; see *Review log*. Spec review gate **passed** on 2026-11-18, after 5 rounds: the final Codex adversarial review says *approve*, and the Codex security review found no critical or high issue. Ready for planning.
- **Plugin:** `Vault` (directory and PHP namespace `Kanboard\Plugin\Vault`), repo `carmelosantana/kanboard-vault`. The name is my call; rename it freely before the repo exists.
- **Working folder:** `kanboard-plugins/Vault` (doesn't exist yet)
- **Also touches:** `Agents` (read-only use of its roster); the production Docker image (adds ext-sodium)
- **Research:** [docs/research/2026-11-18-vault-kdbx-libraries.md](../research/2026-11-18-vault-kdbx-libraries.md) covers the kdbx and Argon2 libraries, sodium availability and the supply-chain review.

## Why

Carmelo's team keeps client credentials in KeePassXC files and passes them around ad hoc. The suite already treats agents (API-only Kanboard users driven over MCP) as teammates, but there is no safe way to hand an agent a database password or an API key. Vault puts the team's secrets next to the work. It should feel like KeePassXC, share with people and agents, and send one-time links to clients. It starts as a team tool on projects.madeinnewburgh.com and will be open-sourced once proven, so the design must hold on installs run by strangers.

## Threat model

| In scope (v1 defends against) | Out of scope (v1 accepts) |
|---|---|
| **Stolen database backup, including `config.php` and env.** The thief must not be able to decrypt anything from a *current* backup without a user's passphrase (and hardware key, if one is enrolled). | **Compromise of the running server or PHP process.** The server decrypts in memory while it serves an unlocked user. An attacker with shell, DB-write or plugin-install rights can capture passphrases or swap code. Rolling back DB rows also counts as DB write. |
| **Leaked agent API token.** On its own it opens nothing in the vault. | **A malicious plugin, or XSS on the Kanboard origin.** Such script acts as the unlocked user. Vault reduces the exposure with escaping, page-local JS and `HttpOnly`, but can't remove it. |
| **Curious authenticated users, Kanboard app admins included.** They can't read entries that weren't shared with them, or entries whose share was revoked or expired. An admin resetting a user's *login* password or minting their API token gains nothing in Vault. The org recovery key, when enabled, is the explicit exception. | **Historical backups.** A backup taken before a passphrase change, a 2FA enrolment or a revocation stays decryptable with the credentials that were valid when it was taken. Key rotation protects *later* data only (see *Key rotation*). |
| **Interception of a one-time link after use.** A burned or expired link yields nothing, and chat-app link previews can't burn a link. | **Metadata in plaintext.** This covers row counts, sizes, timestamps, which principal holds a grant on which object id, and the audit columns listed under *Audit* (actor ids, action types, object ids, truncated IPs, user-agent family). |
| **Online guessing** of vault passphrases, recovery codes, agent vault keys and share links. | **A recipient who reads a secret and keeps it.** Revocation can't un-show a secret. |
| | **Malware on the user's device.** |

**Deployment preconditions** for these guarantees are listed in the docs and checked on the admin page:
- HTTPS.
- `PLUGIN_INSTALLER` false (Kanboard's default), and a plugin directory the web server can't write to. A Kanboard admin who can install plugins is equivalent to a server compromise.
- `TRUSTED_PROXY_HEADERS` set when Kanboard runs behind a reverse proxy.
- ext-sodium present.

## Decisions (from the Q&A)

| # | Decision |
|---|---|
| 1 | **Threat model** as in the table above. Team first, open source later. |
| 2 | **Key architecture B.** Each principal has an X25519 keypair. The private key is wrapped by a key derived from a **vault passphrase that is separate from the Kanboard login**. Each entry is encrypted with its own random key, which is sealed to every principal who holds a grant. The server decrypts in memory for each request. |
| 3 | **ext-sodium is required.** Production moves to a derived Kanboard image with `php84-sodium`. Vault refuses to activate without `sodium_crypto_pwhash`. |
| 4 | **Recovery is an admin policy:** *None* (the default, and Carmelo's choice), *Personal recovery code* (each user opts in), or *Personal code + org recovery key*. |
| 5 | **Agents read the entries granted to them.** They write only in their own "Agent-created" group, which their owner can see, and never overwrite entries a human shared with them. Each agent has a separate **vault key** besides its API token. Grants can expire, every read is audited, and reads are capped per hour. |
| 6 | **One-time shares open for anyone who has the link.** The browser encrypts, and the key lives only in the URL `#fragment`. A share allows one view, has an expiry and an optional passphrase, and needs a click to reveal (opening the page alone reveals nothing). The public endpoint is rate-limited, and the sender gets an audit entry and a notice. |
| 7 | **KeePassXC support means import and export, not live sync.** Both run in the browser, and the round trip doesn't have to be 1:1, but nothing may be locked in. Export writes KDBX 4.0 and is verified in CI by two independent readers. There is no plaintext CSV. If verification can't pass, export slips to v1.1 instead of shipping unverified. |
| 8 | **Hardware-key unlock (WebAuthn PRF) is in v1** as a cryptographic second factor. It's opt-in per user, an admin can make it mandatory, and **two keys must be enrolled.** |
| 9 | **The UI looks like KeePassXC:** a groups tree, an entry list, a detail pane, copy-to-clipboard and a password generator. |
| 10 | **Human grants have two levels, Read and Edit** (Carmelo, 2026-11-18). Edit means editing an entry and adding entries to a shared group. Only an object's owner can share it onward or revoke. An entry added to a shared group is owned by the **group owner**. |

## Approaches considered

| Approach | Verdict |
|---|---|
| **A.** One app master key in `config.php`/env that wraps the per-entry keys. | Rejected. It is only as safe as each install's backup hygiene, and Docker volume backups routinely capture config. It fails threat 1 on exactly the installs that open-sourcing invites. |
| **B.** Per-principal keypairs behind a vault passphrase; the server decrypts for each request **(chosen)**. | It defeats a stolen backup even when config is included. Sealing to public keys lets you share with users and agents who are offline. The costs: no recovery by default, and ext-sodium. |
| **C.** Zero-knowledge, with all decryption in the browser and a client-side kdbx as the store. | Rejected. Agents have no browser, so they'd need a second crypto client. Pure-JS Argon2 adds 10–20 s to every unlock. And it defends against server compromise, which is out of scope. |
| KDBX parsing in PHP, or `keepassxc-cli` on the server. | Rejected. No maintained PHP library reads Argon2 KDBX4, libsodium has no Argon2d, and `keepassxc-cli` pulls in Qt/X11 and is GPL. It's used **only in CI**. |

## Cryptography

Everything here uses ext-sodium. `crypto_v = 1` is stored on every encrypted row.

### Canonical encoding

All associated data (AD) and sealed plaintexts use one encoding, `enc(a, b, …)`: the ASCII tag `vault/v1`, followed by each field as a 4-byte big-endian length and then its bytes. Integers are encoded as 8-byte big-endian. No value is ever plain-concatenated.

| Ciphertext | Primitive | AD / sealed plaintext |
|---|---|---|
| Key wrap | XChaCha20-Poly1305-IETF | AD `enc("wrap", crypto_v, principal_id, method, credential_id or "", wrap_id)` |
| Entry payload | XChaCha20-Poly1305-IETF | AD `enc("entry", crypto_v, entry_id, owner_id, key_epoch)` |
| Group payload | XChaCha20-Poly1305-IETF | AD `enc("group", crypto_v, group_id, owner_id, key_epoch)` |
| Sealed DEK | `crypto_box_seal` (has no AD) | plaintext `enc("dek", object_type, object_id, key_epoch, principal_id, DEK)`. The fields are checked after unsealing. |
| Unlocked-session key | XChaCha20-Poly1305-IETF | AD `enc("session", crypto_v, sid_hash, user_id, created_at)` |
| One-time share | AES-256-GCM (WebCrypto) | AD `enc("share", 1, share_id, expires_at, has_passphrase)` |

After every unwrap, the server checks that `sodium_crypto_box_publickey_from_secretkey(sk)` equals the stored public key. If it doesn't, the unwrap counts as failed.

There is no rollback protection. Restoring old rows is DB write, which is out of scope. `version` and `key_epoch` exist to bind ciphertexts to rows and to detect concurrent edits, not to stop rollback.

### Principals and wraps

A **principal** is a human user or an agent that has enrolled in the vault. Each has an X25519 keypair. The public key is stored in plaintext. The secret key `sk` exists only inside **wraps**. Each wrap stores its **own** KDF parameters (`kdf_salt`, `kdf_ops`, `kdf_mem`), so every wrap can be upgraded on its own and an unused backup key never goes stale.

| Method | KEK | When it exists |
|---|---|---|
| `passphrase` | `BLAKE2b-256(key = Argon2id(passphrase, salt, ops, mem), msg = enc("kek", "passphrase"))` | A human with fewer than two hardware keys. |
| `passphrase+prf` (one per hardware key) | `BLAKE2b-256(key = Argon2id(passphrase, salt, ops, mem), msg = enc("kek", "prf", credential_id, prf_output))` | Each hardware key. When the second key is enrolled, the `passphrase` wrap is deleted and keys are **rotated** (see *Key rotation*). |
| `recovery` | `BLAKE2b-256(key = recovery_secret, msg = enc("kek", "recovery"))` | Only if the policy is at least *Personal code* and the user opted in. |
| `org` | `crypto_box_seal(enc("orgwrap", principal_id, sk), org_pk)` | Only if the policy is *Personal code + org key*. |
| `agent` | `BLAKE2b-256(key = vault_key, msg = enc("kek", "agent", agent_user_id))` | Agents only. |

- **Argon2id defaults:** `ops = 3`, `mem = 64 MiB`, with a random 16-byte salt per wrap. A wrap is re-derived with new defaults the next time it's used to unlock. The other wraps are upgraded when they're next used, because each one keeps its own parameters.
- **The recovery code** is 160 random bits, written as 32 Crockford-base32 characters in 8 groups of 4, plus 2 check characters (CRC-16 of the 20 bytes). Input is normalised: case-folded, separators dropped, and `I/L→1`, `O→0`. It must decode to exactly 20 bytes.
- **Passphrases** need at least 14 characters. A passphrase can't be the Kanboard username, and can't be the Kanboard login password (checked with `password_verify` against Kanboard's bcrypt hash). The generator has a 6-word passphrase mode.
- **Hygiene:** `sk`, KEKs and plaintexts are wiped with `sodium_memzero` after use, where PHP allows it.

### Entries, groups and grants

- **Objects.** Entries and groups are *objects*. Each object has a random 32-byte DEK and a `key_epoch` that starts at 1.
  - An entry payload is a JSON object holding title, username, password, URL, notes, tags, expiry, TOTP seed, and custom fields (each with a `protected` flag). It holds at most 256 KB of plaintext.
  - A group payload is `{name, icon, notes}`.
  - Titles, usernames, URLs and group names are all encrypted, so a backup doesn't reveal which services the team uses.
- **Grant sources, kept apart from key material:**
  - `vault_grants` holds one row per *source*: `(subject_type entry|group, subject_id, grantee_id, permission read|edit, granted_by, expires_at)`, unique on `(subject_type, subject_id, grantee_id)`.
  - `vault_keys` holds at most one *effective* sealed DEK per `(object_type, object_id, principal_id)`, tagged with its `key_epoch`.
- **Effective recipients** of an object are the owner, plus the grantees of every unexpired direct grant, plus the grantees of every unexpired grant on any ancestor group. The effective permission is the highest one among those live sources. A recompute runs on every grant change, every move between groups, and every expiry.
- **The vault lock (one serialisation domain).** Every operation that changes authorisation or ciphertext takes the vault lock **exclusively**. That covers grant, revoke, move, delete, expiry handling, re-key, rotation, entry and group edits, and agent re-enrolment. Every read that decrypts takes it **shared**. Vault is a team-scale store, so one install-wide lock is simpler than per-object lock ordering, and it's fast enough.
  - **MySQL and Postgres:** the lock is the singleton `vault_meta` row, taken with `SELECT … FOR UPDATE` (exclusive) or `FOR SHARE` (shared).
  - **SQLite:** writers issue a raw `BEGIN IMMEDIATE`, not PicoDB's `beginTransaction()`, which is deferred. Readers use one deferred read transaction, which gives a consistent WAL snapshot.

  All checks and all crypto for a write run **inside** the lock, against current rows. Nothing is prepared ahead of time, so no stale snapshot can be committed.
- **The read path** runs in one shared-locked transaction:
  1. Compute the effective recipients from live grants and the current ancestry.
  2. Check that the caller is one of them.
  3. Load the key row, and require that its `key_epoch` equals the object's.
  4. Decrypt.

  A key row on its own never grants access.
- **Adding a recipient** seals the current DEK to that principal's current public key, or to both keys while the principal is rotating (see *Key rotation*). A recipient is added by a grant, a grant on a parent group, or an entry moved into a shared group. Sealing works while the grantee is offline.
- **Moves** happen under the exclusive lock as one authorisation change over the whole moved subtree:
  1. Compute the old and new effective recipients for every object in the subtree.
  2. Seal keys for the recipients being added.
  3. Delete the key rows of the recipients being removed.
  4. Enqueue the affected objects for re-key.
  5. Change `parent_id`.

  All five steps commit together.
- **Revocation** is done by the owner, while unlocked, under the exclusive lock. For the object, and recursively for a group's entries and nested groups, Vault:
  1. deletes the grant and the removed key rows;
  2. generates a new DEK;
  3. re-encrypts the *current* payload with `key_epoch + 1`;
  4. seals the new DEK to the remaining recipients.

  It all commits at once. Afterwards the UI suggests rotating the secrets themselves.
- **Removals while nobody is unlocked** cover an expired grant, a deleted grantee, a disabled agent and a lowered policy. In **one** exclusive transaction, Vault deletes the removed principal's key rows and inserts the objects into `vault_rekey_queue`. Reads stop at once, because the read path checks live grants.
- **The re-key queue** is processed under the exclusive lock, by any request from an unlocked principal who still holds the object's DEK. Re-keying needs only the DEK and the recipients' public keys. Each item is idempotent:
  1. Re-key if `key_epoch` still equals the epoch recorded when the item was queued.
  2. Delete the queue row in the **same** transaction.

  A crash rolls the whole item back.
- **Removing a grant source** follows one procedure wherever it happens: an expiry (purge, read or write), an offline removal, or agent re-enrolment. In one exclusive transaction, Vault:
  1. enumerates **every** object the source covers (the subject plus, for a group, its whole subtree);
  2. for each one, works out which principals lose effective access;
  3. deletes their key rows and queues **every** affected object;
  4. only then deletes the source.

  So consuming a source can never leave a sibling or descendant without its barrier.
- **Expiry reconciliation on write.** Every payload write, under the exclusive lock, first looks for expired grants on that object and its ancestors, and removes each one with the procedure above, for its whole subtree. Grant expiry doesn't wait for the once-a-minute purge. A test edits immediately after expiry while the purge is throttled. A two-sibling test has entry A trigger the reconciliation of an expired group grant, then edits sibling B, and checks that B is re-keyed first.
- **Write barrier.** An object that has a queue row can't take a payload update until it has been re-keyed. The writer, who holds the DEK, re-keys it inline in the same transaction, then applies the edit. So data written after a removal is never encrypted under a DEK that a removed principal could recover from an old backup. Rotation uses the same barrier (below).
- **Edit permission** lets a grantee update an entry: a new `version`, with optimistic concurrency on `version`. It also lets them create entries in a shared group. A new entry is owned by the group owner and sealed to the group's effective recipients. Only the owner can grant, revoke, delete or move objects.

### Key rotation

Rotation gives a principal a new keypair and re-keys everything that principal can read. A backup taken **after** rotation is then useless to anyone holding pre-rotation credentials. Backups taken **before** rotation stay readable with the credentials that were valid then; the docs say so plainly.

**Triggers:** a passphrase change, completing hardware-key enrolment, using a recovery code, org recovery, lowering the policy, and **Rotate my keys**.

**The ceremony collects every factor that will survive the rotation before anything changes:**
- the new (or current) passphrase;
- a fresh assertion and PRF output from **each** hardware key being kept. A key that isn't presented is removed, after the user confirms. If hardware keys are required and fewer than two would remain, the ceremony enrols a replacement first.

Two kinds of wrap are rebuilt without the user present:
- The **recovery code** is always replaced: a new code is shown at the end, or the user may choose to have none.
- The **`org` wrap** is re-sealed to the org public key.

**State machine.** The rotation is stored in `vault_rotations`, one row per principal: `old_pk`, `new_pk`, `phase`. Each phase commits under the exclusive lock.
1. **start** (one transaction):
   - Generate `sk2`. For every surviving factor, store a **pending** wrap of `sk2`, marked `status = pending`.
   - Store a **bridge** in the `vault_rotations` row: `XChaCha20-Poly1305(sk1, key = BLAKE2b-256(key = sk2, msg = enc("bridge")), AD = enc("bridge", user_id, old_pk, new_pk))`. Every surviving factor opens `sk2`, and `sk2` opens the bridge to reach `sk1`.
   - **Delete the old active wraps** in the same transaction. From this point the old credentials (for example the old passphrase) open nothing, even in a backup taken afterwards.
   - Set the principal's state to `rotating`.
   - Lock all of the principal's *other* vault sessions.
   - Add `enc_sk_next` (`sk2` under the same `session_key`) to the current session row.

   From now on, any surviving factor recovers `sk2`, and through the bridge `sk1`. Vault checks both against their public keys (`old_pk`, `new_pk`) before using either.
2. **sweep:** in batches, one transaction each, for every object that has a key row for this principal at `principal_key_gen = old`:
   - re-check live access;
   - generate a new DEK, re-encrypt, and set `key_epoch + 1`;
   - seal to every effective recipient (the rotating principal gets `new_pk` only);
   - delete the old key rows.

   While the state is `rotating`, new grants seal to **both** keys, and the write barrier applies to every object still holding an old-generation row for the principal.
3. **finish** (one transaction):
   - Require that no key row for the principal is at the old generation. If any is, go back to *sweep*.
   - Flip the pending wraps to `status = active` (they already wrap `sk2` alone, so no factor has to be present). **Delete the bridge**, so `sk1` is gone from the live DB, set `public_key = new_pk` and `key_gen + 1`, move `enc_sk_next` into `enc_sk`, clear `rotating` and delete the `vault_rotations` row.

**Crash recovery.** If rotation stops at any point (a crash, an expired session, a closed tab), the next unlock with any surviving factor, for example the *new* passphrase, opens `sk2` and the bridge. It recovers and checks both keys, then **resumes** from the current phase. Nothing is ever sealed to a key that no stored wrap can open. The test deletes the initiating session, then resumes using only the new credentials.

**Recovery and org recovery** have no hardware key present, so they remove all hardware keys during the rotation. If hardware keys are required, the user must enrol two new keys at their next unlock.

**Tests:** interrupt after each phase and each batch, then resume. Grant a new object to the principal mid-rotation. After rotation, every surviving factor still unlocks, and pre-rotation credentials can't decrypt any post-rotation backup.

### Unlocked sessions

Kanboard stores PHP sessions **in the database**, so the unlocked `sk` never goes into the session.

1. **Unlock.** After throttling (below), the server derives the KEK, unwraps `sk` and checks the public key. It generates a random 32-byte `session_key` and stores `enc_sk = XChaCha20-Poly1305(sk, session_key)` in `vault_sessions`. The row is keyed by `sid_hash = BLAKE2b(kanboard_session_id)` and records `user_id`, `created_at`, `last_used_at`, `idle_expires_at` and `abs_expires_at`.
2. **Cookie.** `session_key` goes to the browser in the cookie `__Host-kb_vault`, set with `HttpOnly; Secure; SameSite=Strict; Path=/`. On plain HTTP the server **refuses to unlock**, except on `localhost`, where the dev stack uses a separate cookie, `kb_vault_dev`.
3. **Every vault request:**
   - The request must be a UI session request. API and Basic-auth requests never touch `vault_sessions`.
   - `sid_hash` is looked up, and `row.user_id` must equal the current user.
   - The user must still be active.
   - `idle_expires_at` and `abs_expires_at` must not have passed.
   - The server decrypts `sk`, uses it, wipes it, then slides `idle_expires_at` forward.
4. **Locking.**
   - **Triggers:** the idle timeout (default 15 min); the absolute timeout (8 h); Kanboard's `session.destroy` event (logout); "Lock now"; key rotation; user disable or delete.
   - **Login.** On Kanboard's `auth.success` event, Vault deletes every vault session belonging to that user. Kanboard keeps the old PHP session when it regenerates the id at login (`session_regenerate_id(false)`), so the old vault row has to be removed rather than left behind.
   - **Effect.** Locking deletes the row and clears the cookie.
   - **Session length.** The idle timeout can't outlive the Kanboard session. When `SESSION_DURATION=0`, PHP's `gc_maxlifetime` (24 min by default) caps the timeout, and the admin page shows the effective value.
5. **Login shortcuts don't unlock.** A Kanboard login, a remember-me cookie, a login-password reset or an API token never satisfies a vault unlock.

So a backup holds `enc_sk` without `session_key`, and a stolen cookie without the database is just random bytes.

### Throttling (all guessable secrets)

- **Atomic buckets.** All rate state lives in `vault_rate (bucket, tokens, updated_at)`. Each bucket is updated in a transaction that row-locks it (`BEGIN IMMEDIATE` on SQLite). A check and its update are never separate statements.
- **Order.** Throttling runs **before** any Argon2 work. A failure response is always generic: "Unlock failed".

| Secret | Buckets |
|---|---|
| Vault passphrase (and PRF unlock) | Per principal: after 5 failures, exponential back-off from 1 min, doubling, capped at 1 h. It never locks permanently. Per client IP: 30 failures per 10 min. After 5 consecutive failures, the user is notified by email and sees a banner at their next unlock. |
| Recovery code | The same buckets as the passphrase, kept separately. |
| Agent vault key | Per agent and IP pair: back-off as above. Because the bucket includes the IP, someone holding a leaked token on another IP can't lock out the real agent. The owner is notified after 5 failures. |
| Agent secret reads | A token bucket per agent: by default 60 reads an hour, with a burst of 10. The owner can lower it, and an admin sets a hard maximum. |
| Share reveal | Per client IP: 20 per 10 min and 200 per day. |

- **Concurrent Argon2 cap.**
  - Each principal may run **one** Argon2 derivation at a time. A second request gets 429.
  - Each install runs at most 4 derivations at once. A non-blocking `flock` on one of 4 slot files in `DATA_DIR/vault/` enforces this. When all are busy, the request gets HTTP 503 with `Retry-After: 2`, and nothing is derived.
  - Starving these slots is an availability problem, which is outside the threat model. The per-principal limit stops one account holding every slot.
- **Client IP.** Vault resolves the client IP itself, not with `Request::getIpAddress()`. That method trusts a configured header from **any** sender and returns its left-most value, which the client controls.
  - **Default.** The admin setting **Trusted proxy addresses** (`vault_trusted_proxy_cidrs`, empty by default) lists which senders may supply a forwarding header. If `REMOTE_ADDR` isn't in that list, Vault uses `REMOTE_ADDR` and ignores every forwarding header.
  - **`CF-Connecting-IP`** (Cloudflare Tunnel). Production likely runs behind a tunnel, where requests arrive from `cloudflared`. Set the trusted addresses to `cloudflared`'s address, for example `127.0.0.1/32` or its Docker network. The header must hold **exactly one** valid IP; anything else falls back to `REMOTE_ADDR`. The docs add that the origin must be reachable only through the tunnel.
  - **`X-Forwarded-For`.** Vault walks the list right to left, skipping addresses inside the trusted list. The first address outside it is the client.
  - **Warnings.** The admin page warns when forwarding headers arrive from an address that isn't trusted. It also warns when `REMOTE_ADDR` is a private or loopback address and no trusted proxy is configured.

### Hardware keys (WebAuthn PRF)

Both ceremonies are **fully verified on the server, without a library.**

- **Challenges** are 32 random bytes. They're single-use and expire after 2 min. They're stored in `vault_challenges`, bound to the user and the purpose.
- **Origin and RP ID.** Kanboard's *Application URL* setting must be a non-empty `https://` URL, or `http://localhost` on the dev stack. Otherwise hardware-key enrolment is refused; Kanboard would otherwise fall back to `http://localhost/`. The normalised origin and `rp_id` (the host) are pinned on each credential when it's registered.
- **The CBOR decoder fails closed.** It rejects:
  - indefinite lengths, tags, floats and simple values other than `true`, `false` and `null`;
  - duplicate map keys;
  - integers or lengths that aren't minimally encoded;
  - invalid UTF-8;
  - trailing bytes after the top-level item;
  - depth over 4, more than 64 items in total, or more than 4 KB of input;
  - any arithmetic overflow.
- **Registration** (attestation `none`):
  - `clientDataJSON` has `type = webauthn.create`, the stored challenge, and exactly the pinned origin, with `crossOrigin` absent or false.
  - The attestation object must have `fmt == "none"` and an empty `attStmt`.
  - `authData` is parsed with exact-length consumption: `rpIdHash` (32 bytes), flags, `signCount` (4 bytes), then the attested credential data (AAGUID 16 bytes, a 2-byte id length, the id, then the COSE key). No bytes may remain afterwards unless the ED flag is set, and then only a well-formed extensions map.
  - `rpIdHash` must equal `SHA-256(rp_id)`, UP must be set, and AT must be set.
  - **COSE keys** accepted:
    - `kty = 2` (EC2), `alg = -7` (ES256), `crv = 1` (P-256), with `x` and `y` exactly 32 bytes each. The point is checked to be on the curve: the SPKI is built from a fixed, reviewed DER prefix, then `openssl_pkey_get_public` must load it, and `openssl_pkey_get_details` must report `prime256v1` with the same `x` and `y`.
    - `kty = 1` (OKP), `alg = -8` (EdDSA), `crv = 6` (Ed25519), with `x` exactly 32 bytes.

    Any other combination, missing field or extra field in the key is rejected.
  - The client must report `prf.enabled`.
  - The server stores the credential id, the COSE key, `signCount`, `prf_salt`, the origin and the `rp_id`.
- **Unlock** (assertion):
  - The type must be `webauthn.get`, with the stored single-use challenge, the pinned origin, the matching `rpIdHash`, and UP set.
  - **UV policy: UP is required and UV is not.** The vault passphrase is the knowledge factor and the key proves possession. A key PIN is optional.
  - The credential id must belong to this user.
  - The signature over `authData ‖ SHA-256(clientDataJSON)` must verify: ES256 with `openssl_verify` (DER signature, SHA-256), EdDSA with `sodium_crypto_sign_verify_detached`.
  - **signCount:** if both stored and new values are non-zero and the new one isn't greater, the server rejects it and audits `hw_signcount`.
  - Only then is the client-reported PRF output used, together with the passphrase, to derive the KEK. The PRF output isn't covered by the signature, but every unlock needs a fresh signed assertion, so a replayed PRF value doesn't work online.
- **Enrolment, two keys required:**
  1. The user unlocks with their passphrase and registers a first key, which creates a `passphrase+prf` wrap.
  2. They register a second key. Its wrap is created, the bare `passphrase` wrap is deleted and **key rotation** runs.

  If an admin requires hardware keys, users who don't have two keys are sent straight to enrolment after a passphrase unlock and can't view entries until they finish.
- **Removing a key** requires unlocking with another key. While hardware keys are required, the count can't drop below two. When they're optional, dropping to one key restores a `passphrase` wrap, after a passphrase-plus-key unlock.
- **The relying-party ID is the Kanboard host,** and PRF outputs are bound to it. **Moving Kanboard to a new domain makes hardware-key wraps unusable.** The admin page shows the bound `rp_id`, and unlock reports a mismatch plainly. The docs say to export your vault, or remove your keys, before changing domains.
- **Tests** use vectors recorded from a Chrome DevTools virtual authenticator (ES256 and EdDSA, PRF on). Each rejection rule above also gets its own adversarial vector: duplicate keys, non-minimal lengths, trailing bytes, an off-curve point, wrong coordinate sizes, mismatched `alg`/`kty`/`crv`, a foreign origin, a reused challenge, and a signCount regression.
- **Gate:** if verification can't meet this bar, hardware keys slip to v1.1 rather than shipping without it.

### Recovery

| Policy | Behaviour |
|---|---|
| *None* (default) | There are no `recovery` or `org` wraps. A forgotten passphrase, or lost hardware keys, means that user's own entries are lost. The enrolment screen says so in plain words and suggests exporting to KeePassXC as a personal backup. Entries other people shared can be re-granted by their owners. |
| *Personal recovery code* | A user may generate a recovery code at enrolment, or later after an unlock. It's shown once, then confirmed by typing back group 3. **A recovery code bypasses hardware keys** (documented). Using it is atomic: verify the code, create the new passphrase wrap, delete the recovery wrap, lock every session, then run **key rotation**. A failed reset leaves the old code valid. |
| *Personal code + org key* | Everything above, plus each `sk` sealed to an org public key. |

**The org key.** `php cli vault:org-keygen` prints the org secret key **once**, as base32 with a checksum, and stores only the public key. When the policy is turned on, each user gets an `org` wrap at their next unlock, and sees a one-time banner explaining that the org key holder can now recover their vault.

**Org recovery** is `php cli vault:org-recover <username>`. It reads the org secret from stdin, sets a new passphrase and runs key rotation. It is CLI only, audited, and the user is notified.

**Lowering the policy** deletes the affected wraps at once, then queues key rotation for every user who had one. That rotation runs at each user's next unlock.

## Agents

Vault *recommends* Agents. Without Agents, agent features are hidden and the JSON-RPC methods refuse with `agents_unavailable`. Vault decides who is an agent with `AgentTable` roster membership, behind a `class_exists` guard.

**Enrolment**

- An agent's owner, or an app admin, clicks **Enable vault access** on the agent.
- The owner must already have a vault. Until they do, the button explains why it's disabled.
- The server creates the agent's keypair and a random 256-bit vault key, then stores the `agent` wrap. It also creates the agent's "Agent-created" group, owned by the agent and granted (Edit) to the owner.
- The vault key is shown **once**, with a ready-to-paste line, `KANBOARD_VAULT_KEY=…`.
- **Re-enrolment** is for a lost or suspected-leaked vault key. It needs the owner **unlocked**, and runs as one exclusive transaction:
  1. Create the agent's new keypair and wrap.
  2. **Agent-created objects:** the owner holds an Edit grant on them, and therefore their DEKs. Each is re-keyed (new DEK, `key_epoch + 1`) and sealed to the agent's new key and to the owner.
  3. **Objects the owner owns that were shared with the agent** are re-sealed to the agent's new key, then re-keyed.
  4. **Objects other users shared with the agent.** Vault first **deletes those grant sources**: every direct grant and every ancestor-group grant to this agent whose owner isn't the agent's owner. Each deletion is audited as `revoke`. Then it deletes the agent's key rows for those objects and queues them for re-key. A later re-key therefore can't seal anything to the agent's new key. Each of those owners gets a notice: "agent A was re-keyed; re-share?". Re-sharing is an ordinary new grant that only they can make.
  5. Delete the old wrap and the agent's rate state.

  A test proves an agent's owner can't restore a grant that another user controls, including after the re-key queue runs and grants are recomputed.

**Auth.** Every Vault JSON-RPC call needs:

- (a) Kanboard user authentication, where Vault itself requires that the Basic-auth password is the user's **API token** (`hash_equals` against `users.api_access_token`). Kanboard alone would also accept a login password. Agents created by Agents have unusable passwords, but adopted users may not.
- (b) The `X-Kanboard-Vault-Key` header, carrying the vault key in base64url.

The application (`jsonrpc`) user is refused. A non-agent user gets `not_agent`; the v1 API is for agents only. API tokens are stored in plaintext in `users`, so every backup holds them. The vault key is never stored, and that's what keeps a leaked token harmless.

**Methods**

| Method | Returns | Notes |
|---|---|---|
| `getVaultEntries(group_id = 0)` | `{ok, entries: [{entry_id, group_id, title, username, url, fields, expires_at, can_write}]}` | Only the objects the agent can read, by live grants. No secret values. |
| `getVaultSecret(entry_id, field = "password")` | `{ok, value}` | One field. It's audited and counts toward the read cap. |
| `createVaultEntry(fields)` | `{ok, entry_id}` | Always created in the agent's "Agent-created" group. |
| `updateVaultEntry(entry_id, expected_version, fields)` | `{ok, version}` | Only for entries in its "Agent-created" group that the agent created. Anything else gets `not_found`. |

Refusals come back as `{ok: false, reason}`. The reasons are `not_agent`, `bad_vault_key`, `rate_limited`, `not_found`, `conflict` and `agents_unavailable`. An entry the agent can't see always gives `not_found`.

**Agent client (decided 2026-11-18: follow-up).** Something has to read `KANBOARD_VAULT_KEY`, send the header and expose these four methods as tools: the `kanboard` MCP server. That work is a **separate follow-up in that server's own repo**, after Vault v1 ships. Vault v1 tests its API directly over JSON-RPC. Requirements for the follow-up: the vault key is read from a mode-600 profile or env, never logged, never echoed in tool output, and never sent anywhere but the `X-Kanboard-Vault-Key` header. `getVaultSecret` results are returned to the agent only, never written to MCP logs.

## One-time shares

**Creating a share**

- Shares are created from an entry field's ⋯ menu, or from **New one-time link** with pasted text. Pasted text never enters the vault.
- The browser generates a 256-bit content key `k` and a 128-bit burn capability `b`. It optionally takes a passphrase `p`. When there is a passphrase, Vault offers a generated 5-word one by default and warns that "a weak passphrase can be guessed offline".
- If `p` is set, the key is `HKDF-SHA256(k ‖ Argon2id(p, salt, t=3, m=64 MiB), info = "vault/share/v1")`. Argon2 comes from the vendored noble in a worker. Without `p`, the key is `k`. The payload is encrypted with AES-256-GCM, using a random IV and the AD from the encoding table.
- The browser first POSTs, with CSRF, a request for a **reservation**. The server returns a `share_id` (128 random bits) and an `expires_at`, so both can go into the AD. A reservation is bound to the creator and their session, lasts 10 minutes, and can be finalised once. The browser then POSTs, with CSRF, `{share_id, ciphertext, iv, salt, has_passphrase, BLAKE2b(b)}`, which consumes the reservation.
- The server keeps `BLAKE2b(share_id)` and `BLAKE2b(b)`. It never stores which entry a share came from.
- **The link:** `https://<host>/?controller=VaultShareController&action=show&plugin=Vault&id=<share_id>#<base64url(k ‖ b)>`.

**Limits**

- Expiry is 1 h, 1 d or 7 d. The default is 1 d, and an admin can lower the maximum.
- The payload is at most 64 KB.
- The sender can revoke an unviewed link at any time. Their list of links shows each link's expiry and a label, sealed to the sender's public key.

**Opening a share**

- The public page (`APP_PUBLIC`) is the **same static shell** for real, burned and fake ids: identical apart from Kanboard's per-session CSRF and session values. It always shows "Passphrase (only if the sender gave you one)", and it never looks the id up. The shell still costs a Kanboard session read and write, as every web request does, so throttling applies to Reveal, not to page views.
- **Reveal** POSTs `{id, b}` together with the page's session CSRF token. In one transaction (`BEGIN IMMEDIATE` on SQLite, `SELECT … FOR UPDATE` elsewhere), the server finds the row by `BLAKE2b(id)`, checks `BLAKE2b(b)` with `hash_equals`, deletes the row and returns the ciphertext. A wrong `b`, an expired row and a missing row all return the same generic "not available".
- Knowing only the id, for example from an access log, can't burn the link.
- Of two concurrent reveals, exactly one wins.
- A mistyped passphrase can be retried in the page, because the ciphertext stays in page memory.
- **Delivery trade-off (documented):** consumption is strict. A network drop after the delete burns the link, and the sender simply makes a new one. There's no claim or ack lease (YAGNI).

**Headers on Vault public responses:** `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `X-Frame-Options: DENY`.

**Audit and notice.** Created, revealed (time, truncated IP, user-agent family), expired and revoked events are audited. The reveal notice goes to the sender by Kanboard email if email is configured, and always appears in their Vault activity. A failed reveal is only a rate-limit tick.

## KeePassXC import and export

**Libraries (vendored, pinned by version and sha256 in `Assets/vendor/VENDOR.md`):**

- `kdbxweb` 2.1.1. Vault vendors the **non-minified** `dist/kdbxweb.js`, so local patches stay reviewable.
- `@noble/hashes` 2.4.0 Argon2 (pure JS, with signed npm provenance), wired in through `kdbxweb.CryptoEngine.setArgon2Impl`.
- No WebAssembly, so Kanboard's CSP is untouched.

**Where the code runs.** kdbxweb runs **on the page**, because workers have no `DOMParser`/`XMLSerializer` and kdbxweb would otherwise fall back to `@xmldom/xmldom`. Only Argon2 runs in a dedicated **module worker**: kdbxweb's Argon2 hook posts the job there and awaits the result. The builder must prove that the noble ESM files load as-is in a module worker. If they don't, one reproducible `esbuild` command is recorded in `VENDOR.md` and its output committed.

**Local patches to kdbxweb**, each recorded in `VENDOR.md` with the upstream issue and a test:

- Verify the final HMAC block.
- Preserve tabs and whitespace in field values.
- Zero keys on failure.

Vault uses the `@keenext` fork's diff only as a reference, and reviews every change by hand. The 4.1 write bug doesn't apply, because Vault writes 4.0.

### Import

1. **Pre-checks.** Before calling kdbxweb, Vault parses the outer KDBX header itself; it's a small TLV structure. The import is refused if:
   - the file is larger than 50 MB;
   - Argon2 asks for more than 1 GiB of memory, more than 100 iterations or more than 8 lanes;
   - AES-KDF asks for more than 10⁸ rounds (this guards against kdbxweb's freeze);
   - the cipher is Twofish;
   - the file is a YubiKey challenge-response database.

   For those last two the message is explicit. For YubiKey files it says to save a copy in KeePassXC without the YubiKey, import that copy, then delete it.
2. **Opening the file.** The user enters the master password and, optionally, a key file. A progress bar warns that Argon2 databases take 10–20 s. **Cancel** terminates the worker.
3. **Mapping.** Groups become Vault groups. For each entry Vault keeps the title, username, password, URL, notes, tags, expiry and custom fields (protection preserved). The TOTP `otp` field becomes a protected custom field.
4. **What's dropped.** Attachments are skipped in v1, and the import report shows how many were skipped. History, non-standard icons and auto-type are also dropped. The import is refused if it would create more than 20,000 entries or any single entry over 256 KB.
5. **Upload.** The results are POSTed in batches of 100, with CSRF. The server encrypts them under the importer's keys, into a new top-level group named `Imported <date>`. The file name isn't stored.

### Export

- **Who and what.** Export requires a fresh passphrase (and hardware-key) re-entry. It covers the user's own entries, with an option to include entries shared with them.
- **Process.**
  1. The server returns decrypted JSON over HTTPS (`no-store`).
  2. The page builds a **KDBX 4.0** file with Argon2id and ChaCha20, protected by a new export password the user chooses. The generator is offered.
  3. The browser downloads it. The file never goes through the server.
- **Audit.** Every export is audited.

### CI gate

1. `keepassxc-cli` generates fixtures for:
   - KDBX 3.1 with AES-KDF;
   - KDBX 4.0 with Argon2d and AES;
   - KDBX 4.0 with Argon2id and ChaCha20;
   - a database with a key file;
   - Unicode, tabs and whitespace in values;
   - 10,000 entries;
   - a truncated file, a bad HMAC block and a hostile KDF header.
2. The fixtures go through the real page code in headless Chrome.
3. The import must match field for field, and every malformed file must be refused cleanly.
4. The vault is then exported. **Both** `keepassxc-cli` and `pykeepass` must open the export, and find every entry with an identical title, username, password, URL, notes and custom fields.

If this gate fails, export slips to v1.1. Import can ship on its own gate.

## UI

Avatar menu → **Vault** (also in the dashboard sidebar). It's a full-width page in the Kanboard layout:

```
┌─ Vault ──────────────────────────────────── [Search…] [+ Entry] [⚙] [🔒 Lock] ┐
│ ▾ My vault          │ Title          Username        URL           Modified │
│   ▸ Clients         │ ▸ Hetzner      carmelo@…       hetzner.com   2 d      │
│   ▾ Infra           │   Postgres     app             db.int        5 h      │
│      DNS            │                                                        │
│ ▾ Shared with me    ├────────────────────────────────────────────────────────┤
│   ▸ Ops (from Ana)  │ Postgres                                   [Edit] [⋯] │
│ ▾ Agents            │ Username  app                              [⧉]        │
│   ▸ carmelo.claude  │ Password  ••••••••••   [👁] [⧉]                        │
│ ▸ One-time links    │ URL       https://db.int                   [⧉] [↗]    │
│ ▸ Activity          │ Notes …   Shared with: Ana (read), carmelo.claude      │
└─────────────────────┴────────────────────────────────────────────────────────┘
```

**States**

- *Locked:* an unlock form, with a passphrase field and a hardware-key prompt if the user has keys.
- *First visit:* enrolment. The user sets a passphrase, sees the recovery-policy notice, and optionally creates a recovery code or adds hardware keys.

**Secrets never go into the HTML**

- The list and the detail pane carry metadata only.
- Reveal and copy each fetch one field.
- **Every state change, and every request that returns a secret, is a POST** with Kanboard's CSRF token. That covers enrolment, unlock, entry and group changes, grants and revokes, rotation, adding or removing a key, recovery, settings, export start, import batches, agent enrolment, and share create, revoke and reveal.
- Responses that carry secrets are `no-store`.
- Every reveal or copy is audited as `read`.

**Escaping**

- All decrypted or imported values are rendered with `textContent` or Kanboard's server-side escaping, and never as HTML or Markdown.
- The `[↗]` link opens only `http:` and `https:` URLs (with `rel="noopener noreferrer"`). Any other scheme is shown as plain text.

**Copy** uses `navigator.clipboard.writeText`. After 30 s it tries to clear the clipboard by writing `""`, if the tab still has focus. A toast says "Copied — clears in 30 s".

**The generator** offers lengths from 8 to 128, a choice of character classes, an "exclude look-alikes" option, and a **passphrase** mode using the EFF large wordlist (CC BY 3.0, vendored). It uses `crypto.getRandomValues` with rejection sampling and shows an entropy estimate.

**The sharing dialog** picks a user or an enrolled agent, a permission (*Read* or *Edit*) and an optional expiry. When sharing a group, it warns how many entries that covers.

**Assets and headers**

- Plain JS (jQuery is available) lives in `Assets/js/`. **Vault's own templates emit their `<script>` tags with `$this->asset->js()`**, so nothing loads on other pages. `template:layout:js` can't be made conditional, so Vault doesn't use it.
- One stylesheet, which follows ShadcnTheme tokens when they're present.
- No inline JS.
- Every Vault response sets `X-Frame-Options: DENY` itself, whatever the global Kanboard setting. It also sets a per-response CSP: a copy of `$container['cspRules']` plus `frame-ancestors 'none'`, through that response's `withContentSecurityPolicy`. The app-wide rules aren't changed.

## Audit

`vault_audit` is append-only: the plugin never updates rows and deletes them only through retention. Each action has an **allowlisted** set of columns. There is no free-form `detail_json`.

**Columns:** `id, created_at, actor_user_id, actor_kind (human|agent|public|cli|system), action, object_type, object_id, share_hash, target_user_id, ip_trunc (IPv4 /24, IPv6 /48), ua_family (browser and OS major only), via (ui|api|cli), count (for batch actions)`.

**Actions:**

| Area | Actions |
|---|---|
| Sessions | `enroll`, `unlock`, `unlock_failed`, `lock`, `unlock_refused` (addendum A1) |
| Entries and groups | `read`, `create`, `update`, `delete` |
| Access | `grant`, `revoke`, `rekey`, `rotate`, `integrity_error` (addendum A1) |
| Bulk | `import`, `export` |
| One-time shares | `share_create`, `share_reveal`, `share_revoke`, `share_expire` |
| Throttling | `rate_limited`, `bad_vault_key` |
| Hardware keys | `hw_key_add`, `hw_key_remove`, `hw_signcount` |
| Recovery | `recovery_code_create`, `recovery_used`, `org_recovery` |
| Admin | `policy_change` |

**Per-action allowlist.** Every row has `id`, `created_at`, `action`, `actor_kind` and `via`. Beyond those, an action may fill **only** the columns listed here; every other column must be `NULL`. `AuditModel::record()` throws on an unknown action, or on a non-null value in a column that action isn't allowed to use. It never silently drops a value.

| Action | Also allowed (non-null) |
|---|---|
| `enroll`, `lock`, `hw_key_add`, `hw_key_remove`, `recovery_code_create` | `actor_user_id` |
| `unlock`, `unlock_failed`, `unlock_refused`, `recovery_used`, `hw_signcount` | `actor_user_id`, `ip_trunc`, `ua_family` |
| `read` | `actor_user_id`, `object_type`, `object_id`, `ip_trunc` |
| `create`, `update`, `delete`, `rekey`, `integrity_error` | `actor_user_id`, `object_type`, `object_id` |
| `grant`, `revoke` | `actor_user_id`, `object_type`, `object_id`, `target_user_id` |
| `rotate`, `import`, `export` | `actor_user_id`, `count` |
| `share_create`, `share_revoke` | `actor_user_id`, `share_hash` |
| `share_reveal` | `target_user_id` (the creator), `share_hash`, `ip_trunc`, `ua_family` |
| `share_expire` | `target_user_id` (the creator), `share_hash` |
| `rate_limited` | `target_user_id` (when the bucket belongs to a principal), `ip_trunc`, `count` |
| `bad_vault_key` | `actor_user_id` (the agent), `ip_trunc` |
| `org_recovery` | `target_user_id` |
| `policy_change` | `actor_user_id` |

**Who sees what**

- A user sees rows where they are the actor, or where the object or share is theirs.
- An agent's owner sees that agent's rows.
- App admins see `policy_change`, `org_recovery` and aggregate counts.
- Object titles are decrypted for display only for a viewer who holds that object's DEK.

**Retention** is 365 days, set by the admin.

**Logging rules.** No secret, passphrase, vault key, PRF output, share key, burn capability or decrypted value is ever written to `vault_audit`, the PHP error log or Kanboard's logger. A test greps every log the suite produces for the fixture secrets. Another test is table-driven from the allowlist above. For every action it asserts that disallowed columns are `NULL`, and that `record()` throws when one is filled.

## Admin settings

Settings → **Vault** holds:

- the recovery policy;
- whether hardware keys are required;
- the idle timeout (5–60 min, with the effective cap shown);
- the maximum one-time-link expiry;
- the reveal rate limit;
- the agent read-cap maximum;
- audit retention;
- the bound `rp_id` and origin (read-only);
- **Trusted proxy addresses** (`vault_trusted_proxy_cidrs`, a list of CIDRs, empty by default);
- the deployment checks: HTTPS, `PLUGIN_INSTALLER`, whether the plugin directory is writable, the proxy-header warning, and sodium.

Every change writes a `policy_change` row. There's no admin "view vault" or "reset user" action, and org recovery is CLI only.

## Data model

Binary values are stored base64. MySQL uses `LONGTEXT` for entry and group ciphertext, and `TEXT` elsewhere. Every user reference uses `FOREIGN KEY … REFERENCES users(id) ON DELETE CASCADE`, so deleting a user through the UI or the API cleans up. The schema files are `Schema/{Sqlite,Mysql,Postgres}.php`.

| Table | Key columns |
|---|---|
| `vault_meta` | `id = 1` (a singleton: the vault lock row), `schema_note` |
| `vault_principals` | `user_id` PK, `kind (human\|agent)`, `public_key`, `key_gen`, `state (active\|rotating)`, `created_at` |
| `vault_rotations` | `user_id` PK, `old_pk`, `new_pk`, `bridge_nonce`, `bridge` (`sk1` under a key derived from `sk2`), `phase (start\|sweep\|finish)`, `started_at` |
| `vault_wraps` | `id`, `user_id`, `method`, `credential_id`, `kdf_salt`, `kdf_ops`, `kdf_mem`, `status (active\|pending)`, `key_gen`, `nonce`, `wrapped_sk`, `crypto_v`, `created_at` |
| `vault_webauthn` | `id`, `user_id`, `credential_id`, `cose_public_key`, `sign_count`, `prf_salt`, `label`, `origin`, `rp_id`, `created_at`, `last_used_at` |
| `vault_challenges` | `id`, `user_id`, `purpose`, `challenge_hash`, `expires_at` |
| `vault_groups` | `id`, `owner_id`, `parent_id`, `kind (normal\|agent_created\|import)`, `key_epoch`, `nonce`, `ciphertext`, `crypto_v`, `position`, `created_at`, `updated_at` |
| `vault_entries` | `id`, `group_id`, `owner_id`, `created_by`, `version`, `key_epoch`, `nonce`, `ciphertext`, `crypto_v`, `created_at`, `updated_at` |
| `vault_grants` | `id`, `subject_type`, `subject_id`, `grantee_id`, `permission`, `granted_by`, `expires_at`, `created_at`; unique(`subject_type`,`subject_id`,`grantee_id`) |
| `vault_keys` | `object_type`, `object_id`, `principal_id`, `principal_key_gen`, `key_epoch`, `sealed_dek`; PK(`object_type`,`object_id`,`principal_id`,`principal_key_gen`). A second generation exists only while that principal is rotating. |
| `vault_rekey_queue` | `id`, `object_type`, `object_id`, `expected_key_epoch`, `created_at`; unique(`object_type`,`object_id`). Rotation needs no queue rows: its sweep finds the old-generation key rows. |
| `vault_sessions` | `sid_hash` PK, `user_id`, `nonce`, `enc_sk`, `nonce_next`, `enc_sk_next` (both only during rotation), `created_at`, `last_used_at`, `idle_expires_at`, `abs_expires_at` |
| `vault_shares` | `id_hash` PK, `burn_hash`, `creator_id`, `ciphertext`, `iv`, `salt`, `has_passphrase`, `sealed_label`, `created_at`, `expires_at` |
| `vault_share_reservations` | `id_hash` PK, `creator_id`, `sid_hash`, `expires_at` (10 min) |
| `vault_audit` | see *Audit* |
| `vault_rate` | `bucket` PK (e.g. `unlock:user:<id>`, `unlock:ip:<hash>`, `agentkey:<id>:<hash>`, `agentread:<id>`, `reveal:ip:<hash>`), `tokens`, `updated_at` |
| `vault_agent_settings` | `agent_user_id` PK, `read_cap_per_hour` |

**Deleting a user** removes their principal, wraps, sessions, grants and key rows, through the cascade or the plugin's cleanup. It also removes the objects they **own**, since nobody else can re-key them as owner. Entries other people added to that user's shared groups are owned by the group owner, so they go too. The docs warn admins about this, because Kanboard has no hook on the user-removal screen. Disabling an agent keeps its "Agent-created" group readable by the owner. Deleting the agent deletes the group.

**Purge.** Expired grants (deleted the same way as *removals while nobody is unlocked*), sessions, challenges, reservations and shares, plus audit rows past retention, are purged opportunistically on vault requests, at most once a minute. The same purge also runs from a plugin console command, `php cli vault:purge`. Kanboard's cron has no plugin hook, so the docs give a crontab line.

## Plugin shape and packaging

- **`plugin.json`:** version `1.0.0`, MIT, `kanboard_version >=1.2.47`, `php_version >=8.4`, `requires: []`, `recommends: [Agents]`.
- **Startup check.** `Plugin::initialize()` checks for `sodium_crypto_pwhash`. If it's missing, Vault registers only an admin notice and no routes.
- **Console commands:** `vault:org-keygen`, `vault:org-recover` and `vault:purge`. They're registered from `initialize()`, which works because plugins load after `CommandProvider`.
- **Releases.** Zip-on-tag CI with the tag equal to the version, and the zip ships `Assets/vendor/` and `docs/`. The ModMenu listing waits until the implementation passes its security reviews.
- **Production image.** `testing/` gains `Dockerfile.sodium`: `FROM kanboard/kanboard:<pinned>`, then `apk upgrade php84*` and `apk add php84-sodium`. The dev stack uses it, and it's the documented production path. Its CI builds the image and checks `php -m | grep sodium`.

## Testing

TDD with PHPUnit in the dev stack (`testing/run-plugin-tests.sh`), always running the **full** suite to completion. At minimum:

- **Crypto.**
  - Every wrap method round-trips.
  - A wrong passphrase, PRF output, vault key or recovery code fails closed.
  - Each AD field matters: changing any one fails decryption.
  - A sealed DEK whose fields belong to another object is rejected.
  - A public-key mismatch is rejected.
  - KDF parameters are upgraded per wrap, and after an upgrade the unused second hardware key still unlocks.
  - Recovery codes are normalised and their checksum is enforced.
- **Grants.**
  - Overlapping direct, parent-group and nested-group grants survive revoking or expiring any one source correctly.
  - The read path refuses when a live grant is gone, even if a key row is still there.
  - Revocation re-keys (the old sealed DEK can't decrypt the new ciphertext).
  - A revoke running concurrently with a read never returns post-revocation data to a revoked reader. This needs a real two-connection test on SQLite and on MySQL.
  - If a re-key crashes midway, the queue resumes it.
  - **Edit against revoke:** a concurrent edit is never overwritten by revocation, and never committed under the old DEK.
  - **Revoke against revoke:** recipients come out right.
  - **Move against read:** a reader removed by a subtree move never receives data written after the move.
  - **Write barrier:** after a grant expires, an edit from a session that was already unlocked is encrypted under a new DEK. A DEK recovered from a backup taken before the expiry can't decrypt it.
- **Rotation.**
  - Interrupt after each phase and each sweep batch, then resume from every surviving factor.
  - A grant made mid-rotation ends up sealed only to the new key.
  - After rotation, both hardware keys and the new recovery code unlock.
  - Pre-rotation credentials can't decrypt any object in a post-rotation dump.
  - Agent re-enrolment: the owner can't restore another user's grant.
- **Access.**
  - User B can't read A's entries.
  - Read-only users can't edit, and edit grants can't share onward.
  - An agent without a grant gets `not_found`.
  - The app token is refused, and so is a Basic-auth *login password* for an agent.
  - A non-agent user gets `not_agent`.
  - An agent can't update a shared entry.
- **Sessions.**
  - The DB row alone can't decrypt.
  - A row for user A plus a cookie used in user B's session is refused.
  - API requests ignore vault sessions.
  - Idle and absolute expiry, logout, disable, and rotation each lock the vault.
- **Throttling.**
  - Parallel unlock attempts never exceed the bucket.
  - The concurrent-Argon2 cap returns 503.
  - A forwarding header from an untrusted `REMOTE_ADDR` is ignored.
  - A multi-value or malformed `CF-Connecting-IP` falls back to `REMOTE_ADDR`.
  - The `X-Forwarded-For` walk skips only trusted proxies.
  - A second concurrent unlock from the same principal gets 429.
  - A login (`auth.success`) deletes the user's existing vault sessions.
  - A leaked agent token used from another IP doesn't lock out the real agent.
- **WebAuthn.**
  - The recorded vectors pass, and every adversarial vector listed under *Hardware keys* fails.
  - Challenges are single-use.
  - signCount regression is rejected.
- **Shares.**
  - Exactly one of two concurrent reveals wins.
  - An id without `b` can't burn the link.
  - The shell doesn't depend on the id (compared after masking the CSRF token).
  - Expiry, revoke and rate limits work.
- **Backup theft.** Populate fixtures, dump the DB plus config, and assert that no fixture plaintext (titles, usernames, passwords, notes, group names) appears anywhere in the dump.
- **Audit and log hygiene.** The allowlist test, plus a grep of every log for the fixture secrets.
- **KeePassXC.** The CI gate described above.
- **Browser E2E on the dev stack (:8081).**
  - Enrolment, unlock, creating an entry, copy, sharing (read and edit) with a second user, a one-time-link reveal in a fresh browser context, import, then export.
  - A CSP check: no console violations on Vault pages, and no Vault scripts on other pages.
  - The WebAuthn PRF flow, using a Chrome DevTools virtual authenticator.

## Security review gates

1. **Spec:** round 1 is done (see *Review log*). Round 2 re-runs `/codex:adversarial-review` and `/powerup:codex-security` on this revision. It must find no high-severity issues before planning starts.
   - **Round 2** (2026-11-18) found 6 high and 3 medium issues; all are resolved below. **Round 3** must re-check only the resolutions of round 2, and must find no high-severity issue before planning starts.
2. **Implementation:** both reviews run again on the finished branch before the 1.0.0 tag, and a third time after fixes if round 2 finds anything high-severity.

## Left to the builder

- Exact route and controller names.
- Template structure and CSS.
- Batch sizes for import and re-key.
- The wording of UI copy.
- The number of Argon2 slots, if 4 proves wrong for the production host.

## Non-goals (v1)

- Live `.kdbx` sync.
- A browser extension or autofill.
- Showing TOTP codes (seeds are stored; codes come in v1.1).
- Attachments.
- Entry history.
- Twofish.
- Importing YubiKey challenge-response databases.
- A JSON-RPC API for humans.
- An admin vault viewer.
- Defending against server compromise.
- Rollback protection.
- Per-project vaults: sharing is per user or agent.
- Updating the MCP client (a follow-up in its own repo).

## Addenda (as built)

Where the implementation refines or departs from the sections above. Each item names the ruling it comes from
(R*n*, recorded in the build ledger). Where an addendum and an earlier section disagree, the addendum is what was
built.

### A1. Audit

- **`integrity_error`** (R37). Columns: `actor_user_id`, `object_type`, `object_id`. It records an object the
  rotation sweep kept because its DEK opened but its payload or `crypto_v` check failed (R31 revised: "the DEK is
  kept: the object stays old-gen and is reported as integrity_error, and finish stays refused"). Why: "an R31
  integrity fault must be operator-visible", because "a silent integrity fault keeps sk1 in the bridge unseen".
  Written at most once a day per object.
- **`unlock_refused`** (R72). Columns: `actor_user_id`, `ip_trunc`, `ua_family`. It records an unlock, a
  recovery-code use or an enrolment refused while a Steward switch-user session is active (A10). It is never a failed
  guess: no streak, no back-off, no IP token.
- **`actor_kind = system`** (R39): "a system action in a web request needs a distinct actor_kind", for example a
  share expiry or a rate-limit summary written by the opportunistic purge.
- **`rate_limited` is aggregated** (R39, R42): one row per bucket per 10-minute window, written once the window has
  closed, so a guessing run can't flood `vault_audit`.
- **`ua_family`** is the browser and its major version, and the OS **without** a version (R42), built only from a
  fixed list of names.
- R38: "A move is recorded as update. A failed recovery code is recorded as unlock_failed and counts toward the 5."

### A2. Agent API reasons

Besides the spec's reasons, the API answers `payload_invalid` and `payload_too_large` for a bad or oversized
`fields` value, and `error` for an unexpected failure, which is logged by exception class only (R55: "The extra
reasons payload_invalid and error are accepted").

### A3. Schema versions 2–4

The schema files carry four versions. The test suite migrates a fresh database through all four on SQLite, MariaDB
11.4 and Postgres 17.

- **v1 refinements.** R5: "provenance columns use `ON DELETE SET NULL` instead of cascading:
  `vault_entries.created_by`, `vault_grants.granted_by`, `vault_audit.actor_user_id`/`target_user_id`." Ownership
  columns keep the cascade. R8: SQLite surrogate keys use `AUTOINCREMENT`, so ids are never reused.
- **v2** (R26, R35): a principal outlives its Kanboard user row. `vault_principals.user_id` loses its FK to `users`;
  a new `live_user_id` references `users` `ON DELETE SET NULL`; `vault_grants.grantee_id` and
  `vault_keys.principal_id` cascade off `vault_principals`. The purge finds principals whose user is gone, queues a
  re-key for every object they had a key or grant on, then deletes the principal. Why (R26): "Kanboard has no
  user-removal hook", and a cascade off `users` would delete keys without queueing re-keys. v2 also adds
  `vault_meta.org_pk` (the org public key lives on the lock row, never in settings), `vault_meta.last_purge_at`, and
  `vault_principals.rotate_requested` and `notices`.
- **v3** (R39, R32): `actor_kind` gains `system`; `vault_rotations` keeps the sweep cursor (`sweep_type`,
  `sweep_id`).
- **v4** (R56): `vault_share_reservations.share_expires_at` (the expiry is in the share's AD, so finalising stores
  exactly the reserved one); `vault_shares.crypto_v`; on MySQL the share ciphertext becomes `MEDIUMTEXT` (a 64 KB
  payload is about 87 KB in base64).

### A4. KDF parameters

| Use | Parameters |
|---|---|
| Vault wraps (server, libsodium) | Argon2id, `ops = 3`, `mem = 64 MiB`, a 16-byte salt per wrap (as specified). |
| One-time-link passphrase (browser, R59) | "Argon2id t3, m64MiB, p1, a 32-byte output, an empty HKDF salt, NFC passphrases"; the AD field types are pinned by a PHP test-vector file. |
| KeePassXC export (browser) | KDBX 4.0, Argon2id with 64 MiB, 8 iterations and 2 lanes, ChaCha20, GZip. About 7 s for 10,000 entries. |

### A5. KeePassXC import

- **YubiKey files** (R67): "yubikey_cr is detected at open time (an invalid_key on a CR-scheme file shows the
  YubiKey message and sends nothing), because KeePassXC writes no header marker".
- **Argon2 timeout** (R66): one Argon2 job may run for 120 s, then its worker is terminated. It fails closed.
- **Decompression cap** (R67): kdbxweb patch 04 caps the decompressed size at 256 MiB and stops a gzip stream that
  inflates past its declared size. A legitimate database larger than that, decompressed, can't be imported.
- **File size** (R67): "50 MB is read as 50 MiB."
- R67: the 20,000-entry limit "may overshoot by one batch under parallel requests, which is acceptable (it's a DoS
  bound, and per-batch and per-entry limits still hold)". Group notes and icons are dropped and reported.
- kdbxweb carries five local patches, not three: verify the final HMAC block (01), keep tabs (02), zero keys on a
  failed load (03), the decompression cap (04), and zero the save path's keys (05). Each is in `VENDOR.md` with a test.

### A6. KeePassXC export

- Export is lossy in three documented ways: U+FFFE and U+FFFF become U+FFFD; a value holding a control character
  other than tab and LF (or CR) is written protected so its bytes survive; tags are joined by `;`, so a tag holding
  `,`, `;` or `:` splits on re-import.
- Cancel can't recall the export POST, so the server still runs and audits the attempted export.

### A7. Hardware keys

- **signCount** (R60): "signCount uses the WebAuthn standard rule (either value non-zero means new must be
  greater), which is stricter than the spec's wording."
- **The nine design decisions** (R62, from the T20 build):
  1. Challenge purposes: `register`, `ceremony` (any assertion inside an unlocked ceremony), `unlock` (the locked
     page). A user may hold up to the key maximum plus two open challenges per purpose; expired ones are deleted at
     issue and by the purge.
  2. A challenge is consumed by one `DELETE … WHERE user_id AND purpose AND hash AND expires_at > now` in the same
     exclusive transaction as the verification, which commits even when verification fails, so a failed attempt
     burns it.
  3. `prf_salt = BLAKE2b-256(enc("prf_salt", register challenge))`: the server chooses it and stores nothing between
     challenge and registration.
  4. The credential id is stored as base64url of the `authData` id (the wrap AD carries that text; the KEK takes the
     raw bytes). The client's claimed id must equal it.
  5. Registration is a create, then an assertion by the new key (most keys return no PRF output at create), plus
     existing keys: the second key needs the first key's fresh assertion; a third or later key needs one existing key
     to prove the passphrase. The stored sign count is the post-assertion count.
  6. "Rotate with my keys", and a passphrase change for key users, **require every registered key**; a missing one
     is `rotation_needs_factors` (remove it first), rather than "removed after the user confirms". A fail-closed
     simplification of *Key rotation*.
  7. Removal: three or more keys → another key's assertion only; two to one → also the passphrase, proved with the
     remaining key, and a bare `passphrase` wrap is restored; the last key → the passphrase alone. Never below two
     while keys are required.
  8. The relying-party binding also refuses when the request's Host isn't the Application URL's host
     (`rp_id_mismatch`): the browser would fail the ceremony anyway, and this says why.
  9. UV: the browser asks for `userVerification: 'preferred'`; the server requires UP only.
- R62: "A PIN change on a key alters its PRF output, which fails closed"; the docs say to keep the second key.
  "WebAuthn needs JS, so no native fallback on the key page or the R54-with-keys path is accepted."
- For key users, a queued rotation (for example after the policy is lowered) runs at the next "Rotate with my keys"
  ceremony, not at unlock.
- R65: "A wrong PRF at enrolment or rotation counts as a failed guess, the same as unlock." Without JS, Enter on the
  two-key unlock form submits natively and counts as a failed guess.
- **Proxies and Cloudflare Tunnel** (R63): "rpBinding's Host check may refuse a proxy that rewrites Host. cloudflared
  forwards the original Host by default unless httpHostHeader is set." The README documents it.

### A8. Permissions and recovery

- **R47:** "Grants to agents are Read only, enforced in GrantModel::grant". This matches "Read + own writes": agents
  read what's shared and write only their own objects. A roster agent can't enrol as a human.
- **R50:** "renaming or editing a group's metadata requires the owner. Decision 10 defines Edit as 'edit entries and
  add entries', nothing more, so an Edit grantee on a group may edit and add entries inside it but not rename the
  group."
- **R54:** "vault/recovery-code requires the current passphrase through the same throttled check as the passphrase
  change", plus a fresh hardware-key assertion for users with keys. Why: "a session alone must not escalate to a
  persistent factor".
- **R9:** the recovery code's two check characters are the top 10 bits of CRC-16/T10-DIF (polynomial `0x8BB7`). An
  exhaustive test shows it catches every single substitution and every adjacent transposition.
- **R36:** a passphrase change re-seals the org wrap to the new key and drops the personal recovery wrap, with a
  one-time notice to make a new code.
- **R51:** the group-3 confirmation of a new recovery code runs in the browser only. Checking it on the server would
  mean storing part of the code, which weakens it offline; the risk is availability only.
- **R33:** unlock succeeds as soon as a pending rotation has produced and checked the new key; the rest of the
  rotation continues in batches on later requests.

### A9. Browser inputs (R70)

"Every input that takes the vault passphrase (unlock, export re-auth, enrolment, passphrase change, recovery) uses
autocomplete="off" plus data-1p-ignore, data-lpignore="true" and data-bwignore, and the E2E asserts exactly that. The
export password and share passphrases follow the same rule." Why: "a vault passphrase stored in the browser's
password manager widens the leaked-browser-profile path the threat model doesn't need". Best effort only: a browser
may still offer to save.

### A10. Transport, sessions and Steward

- **R20:** the plain-HTTP localhost exception needs both the environment variable `VAULT_DEV_INSECURE_LOCALHOST=1` and
  an exact localhost Host. The Host header alone isn't enough, because it fails open behind a localhost proxy.
- **R41:** Vault's HTTPS gate honours `X-Forwarded-Proto` only when `REMOTE_ADDR` is inside
  `vault_trusted_proxy_cidrs`, because Kanboard's own `Request::isHTTPS()` trusts it from any client.
- **R71** (the "other host or port" requirement): "another localhost port unlocking normally is acceptable. Cookies
  aren't port-scoped, and WebAuthn origin pinning (rp_id_mismatch, T20) is the pinning that matters. The RF1
  requirement that counts is 'no silent lock-out', and that is met."
- **R72** (Steward): when the Steward plugin's switch-user session is active (its `stewardSwitching` service reports
  `isSwitched()`), Vault refuses to unlock (passphrase or hardware key), to use a recovery code and to enrol, before
  any Argon2, throttle or secret, with error `switched`, audited as `unlock_refused`. An already-unlocked session in
  that browser locks on its next Vault request. Without Steward nothing changes; a Steward service that throws counts
  as switched (fail closed). Other devices' sessions stay unlocked, since they belong to the real user.
- A disabled user's vault sessions are deleted by the purge, because Kanboard ends a disabled user's session without
  a logout event.

### A11. One-time shares

- R57: "Token-bucket semantics for 20/10min and 200/day (about 400 in a rolling 24 h) match the unlock buckets." "The
  public reveal taking the install-wide lock is an availability trade-off outside the threat model."
- R58/R59: share labels are re-sealed to the new key during a rotation; mid-rotation a label reads as unavailable
  (fails closed).

### A12. Browser E2E (R68, R69)

The browser tests drive headless Chrome over the raw DevTools protocol with Node's built-in WebSocket, not
Playwright. Why (R68): "Carmelo prefers zero dependencies and the supply-chain policy; the existing pattern works."
So the E2E is Chromium only (R69); Firefox- and WebKit-only bugs are a known gap, named in the README.

## Review log

**Round 1, 2026-11-18.** Three reviews ran:

- a builder check (a fresh Opus subagent);
- `/codex:adversarial-review`;
- `/powerup:codex-security`. The run header shows `gpt-5.6-sol` was served. `daybreak_blue` was requested and accepted, but the CLI doesn't report the served program, so that part is unverified.

| Finding | Source | Resolution |
|---|---|---|
| High: a pre-enrolment backup plus a guessed passphrase recovers the `sk` that later data still depends on | adversarial | *Key rotation* on every credential change. The historical-backup limit is stated in the threat model. |
| High: KDF parameters stored per principal break the other PRF wraps after an upgrade | adversarial | KDF parameters are stored per wrap and upgraded independently, with a test. |
| High: human unlock has no throttling | adversarial, security | *Throttling*: atomic buckets, back-off, a concurrent-Argon2 cap and a notice. |
| High (security) / medium (adversarial): a grant row can't represent several sources | both | `vault_grants` (sources) is kept apart from `vault_keys` (effective keys). Reads re-check live grants, with tests for overlap and nesting. |
| High: the PRF output was a replayable bearer secret, with no assertion check | security | Full registration and assertion verification without a library, with a gate that slips the feature to v1.1. |
| High: kdbxweb is known to produce unsafe output; the reviewer suggested import-only | security | Carmelo's decision (import plus export) stands, behind a stronger gate: local patches, hostile fixtures, two independent readers, and export slipping to v1.1 if the gate fails. |
| Medium: the KDBX worker has no XML parser | adversarial | kdbxweb runs on the page and only Argon2 runs in the worker, so no xmldom is needed. |
| Medium: incomplete AD binding, and a false rollback claim | security | A canonical encoding with a full AD table, a public-key check after unwrap, and the rollback claim removed. |
| Medium: session binding and invalidation | security | UI sessions only, a `user_id` match, lock on login, logout, disable, delete and rotation, and the `__Host-` cookie. |
| Medium: share status leaked through the passphrase field, and anyone with the id could burn a link | security | One identical shell, a burn capability `b` in the fragment, CSRF, and the delivery trade-off documented. |
| Medium: share passphrases were PBKDF2 | security | Argon2id in the worker, generated passphrases, and AD binding. |
| Medium: agent throttling wasn't atomic, the lockout could be abused, and re-enrolment was incomplete | security | Atomic token buckets, buckets keyed by agent and IP, an admin hard cap, and transactional re-enrolment. |
| Medium: audit metadata went beyond the accepted leakage | security | Allowlisted columns, no `detail_json`, truncated IPs and UA families, no link between a share and its entry, no import file names. The accepted list was updated. |
| Medium: revocation versus concurrent reads, and crashes during a re-key | security | Prepare first, then swap in one transaction with a fixed lock order; reads re-validate; a resumable queue. |
| Low: recovery-code encoding and atomicity | security | Defined encoding and checksum, and an atomic reset followed by rotation. |
| Low: CSRF, XSS and clickjacking applied only in places | security | POST and CSRF on every state change and every secret read, `textContent` rendering, a URL scheme allowlist, and per-response `X-Frame-Options` plus `frame-ancestors`. |
| Out of model: an admin who installs plugins, or reads sessions from a shell | security | Recorded as deployment preconditions (`PLUGIN_INSTALLER` off, a read-only plugin directory). An admin resetting a login password is recorded as no bypass. |
| False assumption: the share page was "free" | builder | Kanboard opens a DB session on every request, so throttling applies to Reveal only. |
| False assumption: a user-removal template hook exists | builder | Dropped. Cleanup relies on `ON DELETE CASCADE` plus docs. |
| False assumption: `template:layout:js` could load scripts only on Vault pages | builder | Vault's templates emit their own script tags. |
| False assumption: API auth meant the token only | builder | Vault itself checks `hash_equals` against `api_access_token`. |
| Gap: plugins can't hook Kanboard's cron | builder | A `vault:purge` console command, an opportunistic purge, and a crontab line in the docs. |
| Gap: who re-keys when nobody is unlocked | builder | Key rows are removed at once, and `vault_rekey_queue` runs at the next unlock of someone able to re-key. |
| Gap: client IP behind a proxy, and a spoofable `X-Forwarded-For` | builder, plus my own check of `Request::getIpAddress` | Vault takes the right-most trusted address and shows a warning on the admin page. |
| Gap: attachments and entry size | builder | No attachments in v1, entries capped at 256 KB, `LONGTEXT` on MySQL. |
| Gap: an agent enrolled before its owner | builder | Refused until the owner has a vault. |
| Gap: `gc_maxlifetime` caps the idle timeout | builder | The effective cap is shown on the admin page. |
| Human grant permissions | builder | Carmelo: Read + Edit (decision 10). |
| The MCP client | builder | Carmelo: the safest option, a separate follow-up in the MCP server's repo, with key-handling requirements (see *Agents*). |
| Production behind a proxy | builder | Carmelo: likely a Cloudflare Tunnel. Added the `CF-Connecting-IP` rule and the origin-exposure precondition (see *Throttling*). |
| Spec approval | — | Carmelo approved, 2026-11-18. |

**Rounds 4 and 5, 2026-11-18.** Round 4 (the security review) rated the bridge and re-enrolment fixes **CLOSED**, and found one high issue: sibling objects left without a barrier, fixed below. Round 5 (the security review) rated the whole-subtree removal **CLOSED**, with no new critical or high issue. The final adversarial review's verdict was **approve**, with no material findings.

**Round 3, 2026-11-18** (both reviews re-checked the round-2 resolutions). Rated **CLOSED**: rotation ceremony, revocation snapshot, write barrier, subtree moves, WebAuthn, proxy trust, audit allowlist, `auth.success`, queue atomicity, share reservations. Three high findings, both reviews agreeing on the first two:

| Finding | Resolution |
|---|---|
| A changed factor (the new passphrase) couldn't open `sk1`, so rotation couldn't resume, and the old passphrase still opened `sk1` | Pending wraps hold `sk2`, and a **bridge** (`sk1` under a key derived from `sk2`) lives in the `vault_rotations` row. The old wraps are deleted at *start*. *Finish* flips the pending wraps to active, which needs no factor, and deletes the bridge. |
| Re-enrolment left third-party grants live, so the queue re-sealed to the agent | Third-party grant sources are **deleted** (audited, owners notified) before the key rows are deleted and the objects queued. |
| Expiry could come before the barrier existed (the purge runs once a minute) | **Expiry reconciliation** runs inside every payload write. |
| Round 4 (security review: bridge and re-enrolment **CLOSED**). High: reconciling an ancestor grant for one object left its siblings without a barrier | One **grant-source removal procedure** covers the whole subtree atomically before deleting the source, with a two-sibling test. |

**Round 2, 2026-11-18.** Two reviews ran: `/codex:adversarial-review` and `/powerup:codex-security`. The run header shows `gpt-5.6-sol` was served; the program was requested and accepted, but the served program is unverified. Round-1 items the security review rated **CLOSED**: KDF per wrap, throttling, kdbx export gate, XML worker, AD/rollback, shares, share passphrases, recovery encoding, CSRF/XSS, admin preconditions, and all builder items except those reopened below.

| Finding | Source | Resolution |
|---|---|---|
| High: rotation can't replace wraps whose secrets aren't present (the second key, the recovery code) | adversarial | A **ceremony** collects every surviving factor first. The recovery code is always replaced, the org wrap is re-sealed, and a key not presented is removed after the user confirms. |
| High: a crash after a rotation batch strands objects sealed only to the new key | adversarial, security | A durable **state machine** (`vault_rotations`, `status = pending` wraps written at *start*, two key generations, `enc_sk_next`). Any surviving factor opens both generations, so rotation resumes. |
| High: revocation commits a stale prepared snapshot | adversarial | One **vault lock**: every check and every bit of crypto runs inside the exclusive lock against current rows, with nothing prepared ahead. |
| High: deferred re-keys let old backups decrypt later edits | adversarial | **Write barrier:** a queued object is re-keyed inline before any payload update, and rotation uses the same barrier. |
| High: group moves weren't serialised with reads of their descendants | security | The vault lock (shared for reads, exclusive for writes), plus a defined subtree-move protocol in one transaction. SQLite uses a raw `BEGIN IMMEDIATE`, because PicoDB's transaction is deferred. |
| High: "re-grant all" for agents breaks owner-only sharing | security | Replaced: the owner restores only their own objects, other owners get re-share notices, and agent-created objects are re-keyed by the owner. A test covers it. |
| Medium: the in-house WebAuthn parser had no fail-closed contract | security | A strict CBOR rule list, `fmt = none`, an exact `authData` layout, EC2/P-256 and OKP/Ed25519 shapes with checks on the parsed key, a pinned https origin, an explicit **UP-required, UV-optional** policy, and adversarial vectors. |
| Medium: proxy headers trusted from any sender | security | `vault_trusted_proxy_cidrs`, exactly-one-IP `CF-Connecting-IP`, and a right-to-left `X-Forwarded-For` walk skipping trusted proxies. |
| Medium: the audit allowlist wasn't actually specified | security | A per-action table; `record()` throws on violations; a table-driven test. |
| Reopened: claimed lock-on-login was false | security | `auth.success` deletes the user's vault sessions. |
| Reopened: removal and enqueue weren't atomic; queue semantics missing | security | One transaction; idempotent items with `expected_key_epoch`, deleted in the re-key transaction. |
| Note: share-id reservation needed CSRF and binding | security | A reservation POST with CSRF, bound to creator and session, 10-minute TTL, consumed once. |
| Out of model: starving the Argon2 slots | security | One derivation at a time per principal (a second gets 429). Full fairness is left as an availability concern. |

