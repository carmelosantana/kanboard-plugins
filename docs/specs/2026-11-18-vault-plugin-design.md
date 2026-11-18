# Vault: a KeePass-style password store for Kanboard (v1.0)

- **Status:** design settled with Carmelo (Q&A, 2026-11-17 to 18). Review round 1 (builder check, Codex adversarial review, Codex security review) has been folded in; see *Review log*. A few points are marked **[pending Carmelo]**.
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
| 10 | **Human grants have two levels, Read and Edit [pending Carmelo].** Edit means editing an entry and adding entries to a shared group. Only an object's owner can share it onward or revoke. An entry added to a shared group is owned by the **group owner**. |

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
- **Read path, in one transaction** (`BEGIN IMMEDIATE` on SQLite; `SELECT … FOR UPDATE` on the object row elsewhere):
  1. Re-check that the caller is an effective recipient, using the live grants.
  2. Load the key row, and require that its `key_epoch` equals the object's.
  3. Decrypt.

  A key row on its own never grants access.
- **Adding a recipient** (a grant, a grant on a parent group, or an entry moved into a shared group) seals the current DEK to that principal's public key. This works while the grantee is offline.
- **Revocation by the owner, while unlocked:**
  1. Outside the transaction, prepare a new DEK, the re-encrypted payload with `key_epoch + 1`, and the new sealed DEKs for the remaining recipients.
  2. Inside one transaction, locking the object row first and then child objects in id order, swap them all in and delete the old grant and key rows.

  The swap is recursive for a group (its entries and nested groups). Afterwards, the UI suggests rotating the secrets themselves.
- **Removals while nobody is unlocked** cover an expired grant, a deleted grantee, a disabled or re-enrolled agent, and a lowered policy. The removed principal's `vault_keys` rows are deleted **at once**, and reads stop as well, because the read path checks live grants. The object then goes into `vault_rekey_queue`. The queue is processed in batches the next time the owner, or any other recipient who can re-key it, unlocks. Until then the live DB no longer holds the removed principal's sealed copy. Only historical backups do.
- **Edit permission** lets a grantee update an entry: a new `version`, the same DEK and `key_epoch`, with optimistic concurrency on `version`. It also lets them create entries in a shared group. A new entry is owned by the group owner and sealed to the group's effective recipients. Only the owner can grant, revoke, delete or move objects.

### Key rotation

Rotation runs when a user changes their passphrase, completes hardware-key enrolment, uses or deletes a recovery code, when the policy is lowered, or when they press **Rotate my keys**. It:

1. creates a new keypair;
2. re-keys every object the principal **owns or can read** (new DEK, `key_epoch + 1`, re-encrypt, re-seal to all effective recipients);
3. replaces all of the principal's wraps;
4. locks all of the principal's other vault sessions.

Rotation runs as a resumable job (`vault_rekey_queue` with `reason = rotation`), processed in transactional batches while the user is unlocked. A progress bar shows. The new wraps are swapped in last, in one transaction. The documentation states the limit plainly: **backups taken before a rotation stay readable with the credentials that were valid then.**

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
   - **Login.** Kanboard regenerates the session id only at login, so a new login never inherits an unlock. The old row is orphaned and later purged.
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

- **Concurrent Argon2 cap.** At most 4 Argon2 derivations run at once per install. A non-blocking `flock` on one of 4 slot files in `DATA_DIR/vault/` enforces this. When every slot is busy, the request gets HTTP 503 with `Retry-After: 2`, and nothing is derived.
- **Client IP.** Vault doesn't use `Request::getIpAddress()` as it is. That method returns the left-most address in a trusted header, which the client controls. Vault applies the same trusted-header rule but takes the **right-most** address, the one the nearest proxy appended. With no trusted header configured it uses `REMOTE_ADDR`. The admin page warns when `REMOTE_ADDR` is private or loopback, forwarding headers are present, and `TRUSTED_PROXY_HEADERS` is empty.

### Hardware keys (WebAuthn PRF)

Both ceremonies are **fully verified on the server, without a library.**

- **Challenges** are 32 random bytes. They're single-use and expire after 2 min. They're stored in `vault_challenges`, bound to the user and the purpose.
- **Registration** (attestation `none`; credential algorithms `ES256 (-7)` and `EdDSA (-8)` only):
  - `clientDataJSON` must have `type` = `webauthn.create`, the right challenge, and an `origin` equal to the origin of Kanboard's configured application URL.
  - The attestation object is parsed with a minimal, strict CBOR decoder. It accepts only definite-length maps, byte strings, text strings and integers, and has depth and size limits.
  - `rpIdHash` must equal `SHA-256(rp_id)`, and the UP flag must be set.
  - The server stores the credential id, the COSE public key and `signCount`. The credential is rejected if the client doesn't report `prf.enabled`.
- **Unlock** (assertion):
  - The type must be `webauthn.get`, and the challenge, origin, `rpIdHash` and UP flag must all check out (UV preferred).
  - The credential must belong to this user.
  - The signature over `authData ‖ SHA-256(clientDataJSON)` must verify: ES256 with `openssl_verify` after converting COSE to a SubjectPublicKeyInfo (SPKI) key, EdDSA with `sodium_crypto_sign_verify_detached`.
  - **signCount:** if both values are non-zero and the new one isn't greater, the server rejects it and notifies the user.
  - Only then is the PRF output used, together with the passphrase, to derive the KEK.
  - The PRF output isn't covered by the authenticator's signature. Replaying a captured PRF value online still fails, because each unlock needs a fresh signed assertion.
- **Enrolment, two keys required:**
  1. The user unlocks with their passphrase and registers a first key, which creates a `passphrase+prf` wrap.
  2. They register a second key. Its wrap is created, the bare `passphrase` wrap is deleted and **key rotation** runs.

  If an admin requires hardware keys, users who don't have two keys are sent straight to enrolment after a passphrase unlock and can't view entries until they finish.
- **Removing a key** requires unlocking with another key. While hardware keys are required, the count can't drop below two. When they're optional, dropping to one key restores a `passphrase` wrap, after a passphrase-plus-key unlock.
- **The relying-party ID is the Kanboard host,** and PRF outputs are bound to it. **Moving Kanboard to a new domain makes hardware-key wraps unusable.** The admin page shows the bound `rp_id`, and unlock reports a mismatch plainly. The docs say to export your vault, or remove your keys, before changing domains.
- **Tests** use vectors recorded from a Chrome DevTools virtual authenticator (ES256 and EdDSA, PRF on), plus tampered variants of each field.
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
- **Re-enrolment** is one transaction: it deletes the old wrap, grants, key rows and rate state, then creates the new keypair and wrap. The owner then gets a **re-grant all** button that re-seals the previous grant list while they're unlocked.

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

**Agent client [pending Carmelo].** Something has to read `KANBOARD_VAULT_KEY`, send the header and expose these four methods as tools: presumably the `kanboard` MCP server. My proposal is a follow-up in that server's own repo. Vault v1 tests the API directly over JSON-RPC.

## One-time shares

**Creating a share**

- Shares are created from an entry field's ⋯ menu, or from **New one-time link** with pasted text. Pasted text never enters the vault.
- The browser generates a 256-bit content key `k` and a 128-bit burn capability `b`. It optionally takes a passphrase `p`. When there is a passphrase, Vault offers a generated 5-word one by default and warns that "a weak passphrase can be guessed offline".
- If `p` is set, the key is `HKDF-SHA256(k ‖ Argon2id(p, salt, t=3, m=64 MiB), info = "vault/share/v1")`. Argon2 comes from the vendored noble in a worker. Without `p`, the key is `k`. The payload is encrypted with AES-256-GCM, using a random IV and the AD from the encoding table.
- The browser first asks the server for a `share_id` (128 random bits) and an `expires_at`, so both can go into the AD. It then posts `{share_id, ciphertext, iv, salt, has_passphrase, BLAKE2b(b)}`.
- The server keeps `BLAKE2b(share_id)` and `BLAKE2b(b)`. It never stores which entry a share came from.
- **The link:** `https://<host>/?controller=VaultShareController&action=show&plugin=Vault&id=<share_id>#<base64url(k ‖ b)>`.

**Limits**

- Expiry is 1 h, 1 d or 7 d. The default is 1 d, and an admin can lower the maximum.
- The payload is at most 64 KB.
- The sender can revoke an unviewed link at any time. Their list of links shows each link's expiry and a label, sealed to the sender's public key.

**Opening a share**

- The public page (`APP_PUBLIC`) is the **same static shell** for real, burned and fake ids. It always shows "Passphrase (only if the sender gave you one)", and it never looks the id up. The shell still costs a Kanboard session read and write, as every web request does, so throttling applies to Reveal, not to page views.
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

**Columns:** `id, created_at, actor_user_id, actor_kind (human|agent|public|cli), action, object_type, object_id, share_hash, target_user_id, ip_trunc (IPv4 /24, IPv6 /48), ua_family (browser and OS major only), via (ui|api|cli), count (for batch actions)`.

**Actions:**

| Area | Actions |
|---|---|
| Sessions | `enroll`, `unlock`, `unlock_failed`, `lock` |
| Entries and groups | `read`, `create`, `update`, `delete` |
| Access | `grant`, `revoke`, `rekey`, `rotate` |
| Bulk | `import`, `export` |
| One-time shares | `share_create`, `share_reveal`, `share_revoke`, `share_expire` |
| Throttling | `rate_limited`, `bad_vault_key` |
| Hardware keys | `hw_key_add`, `hw_key_remove`, `hw_signcount` |
| Recovery | `recovery_code_create`, `recovery_used`, `org_recovery` |
| Admin | `policy_change` |

**Who sees what**

- A user sees rows where they are the actor, or where the object or share is theirs.
- An agent's owner sees that agent's rows.
- App admins see `policy_change`, `org_recovery` and aggregate counts.
- Object titles are decrypted for display only for a viewer who holds that object's DEK.

**Retention** is 365 days, set by the admin.

**Logging rules.** No secret, passphrase, vault key, PRF output, share key, burn capability or decrypted value is ever written to `vault_audit`, the PHP error log or Kanboard's logger. A test greps every log the suite produces for the fixture secrets. Another test asserts that each audit action writes only its allowlisted columns.

## Admin settings

Settings → **Vault** holds:

- the recovery policy;
- whether hardware keys are required;
- the idle timeout (5–60 min, with the effective cap shown);
- the maximum one-time-link expiry;
- the reveal rate limit;
- the agent read-cap maximum;
- audit retention;
- the bound `rp_id` (read-only);
- the deployment checks: HTTPS, `PLUGIN_INSTALLER`, whether the plugin directory is writable, the proxy-header warning, and sodium.

Every change writes a `policy_change` row. There's no admin "view vault" or "reset user" action, and org recovery is CLI only.

## Data model

Binary values are stored base64. MySQL uses `LONGTEXT` for entry and group ciphertext, and `TEXT` elsewhere. Every user reference uses `FOREIGN KEY … REFERENCES users(id) ON DELETE CASCADE`, so deleting a user through the UI or the API cleans up. The schema files are `Schema/{Sqlite,Mysql,Postgres}.php`.

| Table | Key columns |
|---|---|
| `vault_principals` | `user_id` PK, `kind (human\|agent)`, `public_key`, `created_at` |
| `vault_wraps` | `id`, `user_id`, `method`, `credential_id`, `kdf_salt`, `kdf_ops`, `kdf_mem`, `nonce`, `wrapped_sk`, `crypto_v`, `created_at` |
| `vault_webauthn` | `id`, `user_id`, `credential_id`, `cose_public_key`, `sign_count`, `prf_salt`, `label`, `rp_id`, `created_at`, `last_used_at` |
| `vault_challenges` | `id`, `user_id`, `purpose`, `challenge_hash`, `expires_at` |
| `vault_groups` | `id`, `owner_id`, `parent_id`, `kind (normal\|agent_created\|import)`, `key_epoch`, `nonce`, `ciphertext`, `crypto_v`, `position`, `created_at`, `updated_at` |
| `vault_entries` | `id`, `group_id`, `owner_id`, `created_by`, `version`, `key_epoch`, `nonce`, `ciphertext`, `crypto_v`, `created_at`, `updated_at` |
| `vault_grants` | `id`, `subject_type`, `subject_id`, `grantee_id`, `permission`, `granted_by`, `expires_at`, `created_at`; unique(`subject_type`,`subject_id`,`grantee_id`) |
| `vault_keys` | `object_type`, `object_id`, `principal_id`, `key_epoch`, `sealed_dek`; PK(`object_type`,`object_id`,`principal_id`) |
| `vault_rekey_queue` | `id`, `object_type`, `object_id`, `reason (removal\|rotation)`, `principal_id` (for rotation), `created_at` |
| `vault_sessions` | `sid_hash` PK, `user_id`, `nonce`, `enc_sk`, `created_at`, `last_used_at`, `idle_expires_at`, `abs_expires_at` |
| `vault_shares` | `id_hash` PK, `burn_hash`, `creator_id`, `ciphertext`, `iv`, `salt`, `has_passphrase`, `sealed_label`, `created_at`, `expires_at` |
| `vault_audit` | see *Audit* |
| `vault_rate` | `bucket` PK (e.g. `unlock:user:<id>`, `unlock:ip:<hash>`, `agentkey:<id>:<hash>`, `agentread:<id>`, `reveal:ip:<hash>`), `tokens`, `updated_at` |
| `vault_agent_settings` | `agent_user_id` PK, `read_cap_per_hour` |

**Deleting a user** removes their principal, wraps, sessions, grants and key rows, through the cascade or the plugin's cleanup. It also removes the objects they **own**, since nobody else can re-key them as owner. Entries other people added to that user's shared groups are owned by the group owner, so they go too. The docs warn admins about this, because Kanboard has no hook on the user-removal screen. Disabling an agent keeps its "Agent-created" group readable by the owner. Deleting the agent deletes the group.

**Purge.** Expired grants, sessions, challenges and shares, plus audit rows past retention, are purged opportunistically on vault requests, at most once a minute. The same purge also runs from a plugin console command, `php cli vault:purge`. Kanboard's cron has no plugin hook, so the docs give a crontab line.

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
  - A spoofed left-most `X-Forwarded-For` value doesn't change the bucket.
  - A leaked agent token used from another IP doesn't lock out the real agent.
- **WebAuthn.**
  - The recorded vectors pass, and every tampered field fails.
  - Challenges are single-use.
  - signCount regression is rejected.
- **Shares.**
  - Exactly one of two concurrent reveals wins.
  - An id without `b` can't burn the link.
  - The shell is byte-identical for real, burned and fake ids.
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
- Updating the MCP client [pending Carmelo].

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
| Open: human grant permissions; the MCP client; whether production runs behind a proxy | builder | **[pending Carmelo]** |
