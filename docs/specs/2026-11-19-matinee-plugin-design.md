# Matinee: share Plex libraries into Kanboard projects (v1)

Status: **approved by the overnight security gate**. §11 holds binding amendments, and §11 wins where it
differs from an earlier section. Carmelo has not signed this off yet; the morning brief asks him to. Written
overnight 2026-11-19 from the
[decision map](../research/2026-11-19-matinee-decision-map.md). Every row in the Decisions table cites a map
ruling (`R…`) rather than a board ticket, because the map stayed in markdown (R0). The security review gate
is §10. Accepted risks are §9.

## Why

Carmelo wants Kanboard users to connect their own Plex server and share it, either per project or across the
whole Kanboard, using their own Plex token. Project members should be able to browse and play that media
inside Kanboard, watch together, and keep shared playlists.

Veranda (`~/Projects/local-plex`) already solved the hard parts for a single-owner LAN gateway:
- the token stays server-side;
- media reaches the browser as signed refs;
- the proxy paths are regex-guarded;
- HLS playlists are rewritten;
- a stale session claim is refused.

Matinee ports those patterns to a multi-owner, multi-project PHP plugin, under Kanboard's no-long-running-PHP
constraint.

## Goals, non-goals, anti-goals

**Goals**
- Any permitted user connects a Plex server with a token that is encrypted at rest and never reaches a browser.
- The owner shares chosen libraries, or the whole server, with a project or (admins only) with everyone.
- Members browse and play music and video in Kanboard. They watch together in sync (play, pause and seek
  within about 2 s). They keep project playlists.
- Every request is re-authorized live, so revocation is immediate.

**Non-goals (v1)**
- Plex PIN sign-in. Paste-in token only (R4).
- plex.tv friend invites (Q7).
- Importing the owner's Plex playlists (R12).
- Linking media to tasks.
- Sonos or casting.
- A Veranda backend (R2).
- Sub-second sync.
- Offline downloads.

**Anti-goals**
- Never write to the owner's Plex account: no playlists, no watch state, no ratings.
- Never expose the token: no "show token", not even to admins.
- Never act as a generic HTTP proxy.

## Decisions

| # | Decision | Source |
|---|---|---|
| D1 | Name Matinee, repo `kanboard-matinee`, namespace `Kanboard\Plugin\Matinee`, MIT | R1 |
| D2 | PHP talks to Plex directly through one `PlexClient` with an injectable transport. No Veranda backend in v1. | R2 |
| D3 | Any active user may connect a server unless the admin sets "admins only" | R3 |
| D4 | Paste-in token, verified against the server before it is stored | R4 |
| D5 | XChaCha20-Poly1305 with a server-held key (`MATINEE_KEY` env, else `DATA_DIR/matinee/secret.key`); AD binds the row and owner; KDF subkeys for tokens and refs | R5 |
| D6 | The token never leaves the server: header only, scrubbed from everything | R6 |
| D7 | Ported `UrlGuard` on every request, IP pinning, no redirects, `machineIdentifier` pinning | R7 |
| D8 | Share = (server, sections or whole server, project or everyone). Kanboard roles map to abilities in one policy class. | R8 |
| D9 | Browser gets only HMAC-signed, user-bound, expiring refs; share re-checked on every use | R9 |
| D10 | Video goes through Plex HLS transcode with a rewritten playlist and vendored hls.js. Audio uses ranged responses capped at 8 MiB. Lazy transcode sweep. Per-server stream cap. | R10 |
| D11 | Watch-together polls every 2 s, with version-checked controls (409 on stale) | R11 |
| D12 | Matinee-native project playlists; nothing written to Plex | R12 |
| D13 | Own tables via `Schema/`; settings in `config` | R13 |
| D14 | No required plugins; needs `ext-sodium` and `ext-curl` | R14 |
| D15 | UI lives in user settings, the project sidebar, the dashboard and admin settings | R15 |
| D16 | Built in phases P1 to P5. P1 is the foundation. | R16 |

## What the owner does by hand (setup cost)

| Who | Step | Cost |
|---|---|---|
| Admin | Install the plugin (manual zip, or ModMenu once listed). The PHP image already has sodium and curl. | 1 min |
| Admin | Optionally tick "allow LAN Plex servers" if Plex is on a private network reachable from Kanboard | 1 click |
| Admin | **Back up `DATA_DIR/matinee/secret.key`** (or set `MATINEE_KEY`). Losing it means every owner re-pastes their token. | One-time chore, a line in the README |
| Owner | Find the server URL. Prefer the `https://<ip-dashed>.<hash>.plex.direct:32400` form, from Plex Web → Settings → Network, or from the plex.tv resources list. | ~5 min the first time |
| Owner | Find the Plex token (Plex support article "Finding an authentication token", via "View XML" in Plex Web) and paste it | ~3 min |
| Owner | Pick the libraries and the audience for each share | 1 min per share |

No Plex account is needed for viewers. Nothing has to be installed on the Plex server.

## 1. Data model (`Schema/`, the Steward pattern, all four drivers)

**P1 tables**

- `matinee_servers`
  - Identity and ownership: `id`, `owner_user_id` (FK users, ON DELETE CASCADE), `name` (≤100),
    `base_url` (≤255, normalised scheme+host+port, no path), `machine_id` (≤64).
  - Token: `token_nonce` (blob/text b64), `token_ct` (b64), `key_id` (≤16, which key sealed it).
  - Status: `is_enabled` (int, an admin kill switch), `status` (`ok` | `unreachable` | `reconnect`),
    `last_checked_at`.
  - Timestamps: `created_at`, `updated_at`.
- `matinee_shares`
  - `id`, `server_id` (FK, CASCADE), `created_by`, `audience` (`project` | `everyone`).
  - `project_id` (nullable FK projects, CASCADE), `whole_server` (int), `is_enabled`, `created_at`.
  - Unique (`server_id`, `audience`, `project_id`).
- `matinee_share_sections`
  - `share_id` (FK, CASCADE), `section_key` (≤20, digits). PK (`share_id`, `section_key`).

**Later phases**
- P3: `matinee_transcodes` (`id`, `server_id`, `session` uuid, `user_id`, `last_seen_at`, `stopped`).
- P4: `matinee_rooms` (`id`, `share_id`, `host_user_id`, `ref`, `state`, `position_ms`, `rate`,
  `updated_at_ms`, `version`, `host_only`, `last_poll_at`).
- P5: `matinee_playlists` and `matinee_playlist_items`.

## 2. Secrets (`Crypto/`)

- `KeyStore` resolves the 32-byte root key.
  - When `MATINEE_KEY` (base64, 32 bytes after decoding) is set, it is used and `key_id = "env"`.
  - Otherwise `DATA_DIR/matinee/secret.key`, `key_id = "file"`.
  - The key file is created atomically on first use (tmp + rename, 0600), and its directory with 0700.
  - A malformed env key, or a key file of the wrong size, fails closed with an admin-visible notice. Matinee
    never generates a replacement over an existing bad file.
- `Subkeys`: `sodium_crypto_kdf_derive_from_key(32, 1, "mtntoken", root)` for tokens and
  `(32, 2, "mtnrefs_", root)` for refs. The context strings are exactly 8 bytes.
- `TokenBox::seal(token, serverId, ownerId)` → `{nonce, ct}`. `open(...)` does the reverse.
  - AD is `"matinee:token:v1|{serverId}|{ownerId}"`.
  - Authentication failure throws `CryptoException` with a fixed message. The server is then marked
    `reconnect`.
  - Plaintexts are `sodium_memzero`ed where PHP allows, and parameters are `#[\SensitiveParameter]`.
- Sealing happens after the row insert, because the AD needs the id. The row is created and the token sealed
  in one transaction. A failure rolls back.

## 3. Outbound HTTP (`Http/`)

- `UrlGuard` is ported from AiConnector's `UrlGuard`: same CIDR tables, numeric-shorthand refusal,
  allow-list syntax, resolver test seam.
  - Config keys are `matinee_allowed_hosts` and `matinee_allow_private`.
  - The base-URL validator accepts only `http`/`https`. It rejects userinfo, query, fragment and any path
    other than empty or `/`. Ports 1–65535.
- `PlexTransport` is an interface: `get(url, headers, opts): {status, headers, body}` and
  `stream(url, headers, opts, sink)`.
- `CurlTransport` is the real one.
  - It runs `UrlGuard::check` on every call.
  - Connection: `CURLOPT_RESOLVE` = `host:port:ip`, `CURLOPT_FOLLOWLOCATION` false,
    `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` http(s), TLS verification on (`SSL_VERIFYPEER` true,
    `VERIFYHOST` 2) with no way to turn it off.
  - Timeouts: connect 5 s, total 15 s for JSON, header deadline 15 s, 60 s for transcode starts. JSON bodies
    are capped at 8 MiB.
  - Error messages carry neither the URL query nor the token. They say e.g. "Plex request failed (timeout)".
- `PlexClient` (P1 surface) takes `(baseUrl, token, clientId, transport)`.
  - `identity()` → `machineIdentifier`, version.
  - `sections()` → `[{key, title, type}]`, with type allow-listed: movie, show, artist, photo.
  - It sends `X-Plex-Token`, `X-Plex-Client-Identifier` (a per-install uuid in config
    `matinee_client_id`) and `X-Plex-Product: Matinee`, with `Accept: application/json`.
  - Parsing whitelists fields. Non-200 or invalid JSON throws `PlexException` (scrubbed).
  - When a `MediaContainer.machineIdentifier` is present and differs from the pinned id, it throws
    `PlexException::serverChanged`.
- `PlexScrub::stripToken(url)` removes any query key that matches `x-plex-token` in any case. It is used on
  every Plex-returned URI before reuse (P3 playlists).

## 4. Servers (P1)

- **Connect** (user settings → "Plex servers" → Add):
  - The user enters name, URL and token, behind a CSRF form check.
  - Matinee checks D3, then `UrlGuard`, then `identity()`, then `sections()` (which proves the token works).
  - It then stores the server: insert, seal and update.
  - On failure it flashes a generic error naming the stage (URL refused / unreachable / token rejected) and
    keeps nothing.
- **Edit:** name, URL and token. A blank token keeps the stored one. A changed URL requires re-entering the
  token (the AiConnector rule: a credential belongs to the endpoint it was entered for). A changed
  `machineIdentifier` on re-verify requires the token too.
- **Verify now:** re-runs identity and sections, and updates `status`.
- **Remove:** owner or admin. Shares cascade.
- **Admin list** (Admin settings → Matinee): every server with its owner, host, status and share count, plus
  enable/disable. No token and no edit.
- Server rows are only ever read through `ServerModel`. Every read of a token goes through
  `ServerModel::tokenFor(server)`, which is the single decrypt site.

## 5. Shares and policy (P1)

- **Create or edit share** (from the server's page, owner only): pick "whole server" or sections from a live
  `sections()` call, then pick an audience. A project is chosen from the projects where the owner is a
  project manager, and "Everyone" is offered only to app admins.
- **Section keys** are validated `^\d{1,20}$`, and must exist in the live list at save time.
- `SharePolicy` is the only place abilities are decided. It is pure and table-tested.
  - `canConnectServer(user)`
  - `canManageServer(user, server)` (owner or admin, for disable and remove only)
  - `canEditShare(user, share)` (the owner)
  - `canUnlinkShare(user, share)` (owner, the project's managers, admin)
  - `visibleShares(user, projectId|null)`
  - `canBrowse(user, share)`
  - `canControlRoom(user, share)` (member+ or everyone-audience)
  - `canEditPlaylist(user, share)` (member+ or everyone-audience)
  - `sectionAllowed(share, sectionKey, liveSections)` (whole-server covers only the sections currently on
    the server)
- Every check needs an active user, an enabled server and an enabled share. Project shares need a current
  project role from `ProjectUserRoleModel::getUserRole` (which includes group roles). An app admin with no
  project role gets no browse ability; they still get admin disable and unlink.
- `visibleShares` is computed per request, never cached across requests.

## 6. Signed refs and browse (P2)

- `RefSigner`: `body = b64url(json(payload))` and `sig = b64url(hmac_sha256(refKey, body))`. Verification
  checks the signature first, with `hash_equals`, then the expiry.
- The payload is `{v:1, u:userId, sh:shareId, s:section, k:kind, r:resource, rk?:ratingKey, ct?:container, exp}`.
- `kind` ∈ `browse | art | media | hls`, each with an anchored resource regex. The art regex is Veranda's
  `ART_PATH_RE`.
  - browse: `^/library/(sections/\d{1,20}/all|metadata/\d{1,20}/children)$`
  - media: `^/library/parts/\d{1,20}/\d{1,20}/file\.[a-z0-9]{1,8}$`
- **On use:**
  - the ref is verified;
  - `u` must equal the current user;
  - the share is re-authorized (§5);
  - the item's `librarySectionID`, from the Plex metadata, must equal `s`, and `s` must still be allowed;
  - the resource must match its kind's regex.
- **Browse pages** live under the project's "Media" page and the dashboard's "Media". They render Plex
  metadata as escaped text only, through Kanboard's template escaping. Images go through `/art?ref=&size=`
  with sizes 144, 512 and 1080, the Veranda transcode URL, and a 15 s idle timeout.
- Responses send `Cache-Control: private` and `X-Content-Type-Options: nosniff`. The content type comes from
  an allow-list (image/jpeg, image/png, image/webp; audio/mpeg, audio/mp4, audio/flac, audio/ogg; video/mp4;
  application/vnd.apple.mpegurl; video/mp2t).

## 7. Playback (P3)

**Audio**
- A media ref streams from Plex using direct parts for mp3, and Plex's `start.mp3` transcode otherwise.
- With a `Range` header, Matinee forwards a range clamped to at most 8 MiB and answers `206` with an accurate
  `Content-Range`. With no `Range`, it answers with the first 8 MiB as a `206`. Browsers handle both.
- The transcoded mp3 has no ranges. It is a long request that counts toward the stream cap.

**Video**
- An hls ref starts a session through Plex's `/video/:/transcode/universal/start.m3u8`, using Veranda's
  parameters and device headers.
- Matinee records a `matinee_transcodes` row, rewrites the playlist (Veranda's `rewrite`), and proxies each
  child request.
  - `u` must start with `/video/:/transcode/universal/`, after resolution against the upstream URL. The
    token is stripped.
  - The session must belong to the same user and the ref must still be valid.

**Housekeeping**
- **Lazy sweep:** any Matinee request with probability 1/10, and every HLS start, stops sessions with
  `last_seen_at` older than 120 s.
- **Stream cap:** HLS sessions and mp3 transcodes active per server, default 4, set by the admin. When it is
  hit, Matinee returns 503 "server busy".

**Player**
- `<video>` and `<audio>`, plus hls.js 1.7.1 vendored under `Assets/vendor/`, after a supply-chain check
  (sha256 recorded in the README). Native HLS on Safari.
- All JS is in injected assets. No inline script (CSP).

## 8. Watch-together (P4) and playlists (P5)

**Rooms**
- A room belongs to a share and lives on a project's Media page. Starting one needs `canControlRoom`.
- `GET room/{id}/state` → `{state, ref, position_ms, rate, updated_at_ms, version, host_only, server_now_ms, watchers}`.
  - `watchers` is the number of distinct users who polled in the last 10 s, plus their names when they share
    the project.
- `POST room/{id}/control {action: play|pause|seek|load, position_ms, ref?, version}`:
  - CSRF-checked.
  - The stored `version` must equal the one the client sent. Otherwise the reply is `409` with the current
    state.
  - On success the version is incremented, and `updated_at_ms` is set to the server clock.
- `host_only` means only the host may control.
- `load` needs a ref the caller may use under the same share.
- Clients poll every 2 s. They correct drift over 1.5 s by seeking, and are paused while the tab is hidden
  (they resync on focus). Rooms with no poll for 30 min are deleted lazily.

**Playlists**
- `{share_id, name, created_by}` with items `{rating_key, section, position}`.
- `canEditPlaylist` gates editing. Items in sections that are no longer allowed are hidden.
- "Play in room" loads the next item when the current one ends. It is a host-client action, version-checked.

## 9. Accepted risks

- **Key file plus DB:** together they reveal every token. That is the same trust boundary as Kanboard's
  `config.php`, and it is documented.
- **The owner's Plex sees all traffic as the owner.** Plex activity and "now playing" show Matinee, not the
  Kanboard viewer. Matinee sets `X-Plex-Device-Name: Matinee (<kanboard username>)` so the owner can tell
  viewers apart. The username is the only Kanboard data sent to Plex.
- **Whole-server shares** follow sections the owner adds later. That is the point of "whole server", and the
  share page says so.
- **Video transcoding load** lands on the owner's Plex. The stream cap bounds it.
- **The audience drifts with membership (S15).** A project share's audience follows the project's current
  membership. The share page says so, and the owner can unlink it at any time.
- **A server owner can read what viewers watch**, through Plex's dashboard. The project Media page says so
  ("Playback is visible to the server owner").

## 10. Security review gates

Matinee holds full-account credentials and makes outbound requests to user-supplied URLs, so it gets the
Steward and Vault gates:
- **Spec:** an independent adversarial security review of this document (SSRF, token leakage, access
  control, ref forgery, playlist/HLS rewrite injection, CSRF, DoS). Findings are recorded in §11 before the
  plan.
- **Each phase branch:** `/security-review` on the built diff before merge to main. `/powerup:codex-security`
  and `/codex:adversarial-review` run before the first public release (Carmelo's call).
- The repo is private until Carmelo decides. Commit messages describe security fixes neutrally.

## 11. Spec security review findings (binding amendments)

An independent adversarial review (Opus, 2026-11-19) checked this spec against the Kanboard 1.2.47 source,
AiConnector's `UrlGuard` and Veranda. Its verdict was **FAIL as written**, because S1–S4 were High.

**Every amendment below is adopted.** Where an earlier section differs, this section wins. With these in
place the gate is **PASS**. The phase that must land each one is in brackets.

- **S1 High: a private network needs an explicit target, not a blanket opt-in** [P1]
  - `matinee_allow_private` is replaced by `matinee_private_targets`, an admin list of entries like
    `host-or-CIDR[:port]`.
  - A private address passes only if it matches an entry. There is no blanket private opt-in.
  - Allowed ports are 32400, 443 and 80, unless an entry names another.
  - Connect and verify return **one generic failure** ("could not connect to that server with that token")
    for refused, unreachable and rejected alike. The detailed stage is logged server-side only, with no URL
    query and no token.
  - Each user may connect or verify **at most 10 times per 10 minutes** and own **at most 3 servers**. The
    admin sets both limits.
- **S2 High: the HLS `u` parameter** [P3]
  - `u` is a path only. It must match
    `^/video/:/transcode/universal/session/[0-9a-f-]{36}/[A-Za-z0-9/_.-]{1,200}\.(m3u8|ts|m4s|vtt|aac)(\?[A-Za-z0-9=&_.-]{0,512})?$`,
    and must not contain `..`, `%`, `@`, `\` or `//`.
  - The session uuid in the path must equal the `matinee_transcodes.session` row for this user.
  - The upstream URL is built only from the stored `base_url` plus `u`.
  - The rewriter treats every URI as `u`, including the `URI=` attributes of `EXT-X-KEY`, `MAP` and `MEDIA`.
  - Any absolute or off-origin URI fails the whole playlist (502).
- **S3 High: protect FPM workers** [P3]
  - Long streams have three caps: per server (4), per user (2) and global (default 5, admin-set). The README
    documents that the global cap should stay under a quarter of `pm.max_children`.
  - The count and the insert run in one transaction.
  - Every proxy request sets `CURLOPT_TIMEOUT`: art 20 s, segment 60 s, mp3 transcode 15 min.
  - Every proxy request also sets `CURLOPT_LOW_SPEED_LIMIT=1024` and `LOW_SPEED_TIME=15`, and
    `ignore_user_abort(false)`.
  - The lazy sweep stops at most 2 sessions per request, with a 3 s timeout each.
- **S4 High: rooms never store a ref** [P4]
  - A room stores `{section, rating_key, kind}`, never a ref.
  - `load` verifies the caller's ref, re-checks the section and stores the decoded item.
  - `GET state` re-runs `canBrowse(user, room.share)` and mints a fresh ref for that viewer.
  - The share always comes from the room row.
- **S5 Medium: authorize from the object, never from the URL's project** [P1+]
  - Controllers ignore any URL `project_id` for authorization.
  - They load the share, room, playlist or server, take the project from that row, and decide access in
    `SharePolicy`.
- **S6 Medium: role states** [P1]
  - `SharePolicy` reads `users.is_active` from the DB on every request, for both the viewer and the server
    owner. A disabled owner makes their shares inert.
  - An inactive project (`projects.is_active = 0`) allows nothing.
  - A custom project role counts as `member` for browse, rooms and playlists, never as a manager.
  - The manager check runs again on share edit.
  - An everyone-share stops working if its creator is no longer an app admin.
- **S7 Medium: bind the endpoint into the AD** [P1]
  - The AD is `matinee:token:v1|{serverId}|{ownerId}|{base_url}|{machine_id}`.
  - Changing `base_url` or `machine_id` therefore always needs the token to be re-sealed.
  - The nonce is 24 random bytes on every seal.
- **S8 Medium: pinning and the guard** [P1]
  - The resolve entry is `host:{effectivePort}:{ip}`, with IPv6 written as `[ip]`.
  - Set `CURLOPT_PROXY=''` and `CURLOPT_NOPROXY='*'`.
  - After each transfer, abort if `CURLINFO_PRIMARY_IP` differs from the pinned IP.
  - Judge the embedded IPv4 in `::ffff:0:0/96`, `::ffff:0:0:0/96` and `64:ff9b::/96` / `64:ff9b:1::/48`.
  - Block `fec0::/10`.
  - Use `CURLOPT_PROTOCOLS_STR` / `CURLOPT_REDIR_PROTOCOLS_STR` set to `http,https`.
  - A test drives a resolver seam that answers public, then private.
- **S9 Medium: leak paths** [P1, P2]
  - Every parameter that carries a token or headers is `#[\SensitiveParameter]`.
  - `CURLOPT_VERBOSE` and `CURLINFO_HEADER_OUT` are never set, and no `curl_getinfo` output is logged.
  - On a non-2xx, upstream bodies are never relayed. Matinee answers with a fixed 502.
  - Only `Content-Type`, `Content-Length`, `Content-Range` and `Accept-Ranges` are forwarded.
  - Matinee pages send `Referrer-Policy: same-origin`.
  - The README recommends `MATINEE_KEY`, or a key path outside the DB volume. It warns that a SQLite `data/`
    backup holds both the key and the tokens.
- **S10 Medium: Kanboard CSP and CSRF behaviour** [P3, P4]
  - On Matinee player routes only, add `media-src 'self' blob:` and `worker-src 'self' blob:` to
    `$container['cspRules']`. `script-src` is never changed.
  - Room and playlist AJAX uses `getReusableCSRFToken` with `checkReusableCSRFParam`. Kanboard's form tokens
    are single-use.
  - Control bodies are capped at 4 KiB, and `position_ms` is clamped to 0..24 h.
- **S11 Medium: harden proxied responses** [P2]
  - A missing or non-allow-listed upstream Content-Type gets a 502. It is never defaulted.
  - Proxied responses add `Content-Security-Policy: default-src 'none'; sandbox`, `Content-Disposition: inline`
    and `Cross-Origin-Resource-Policy: same-origin`.
  - JSON bodies are capped at **2 MiB** (this replaces §3's 8 MiB) and decoded with depth 32.
  - Browse always pages with `X-Plex-Container-Size` ≤ 200.
  - Browse query parameters are allow-listed: start, size, and sort from an enum.
- **S12 Low: key creation race and key ids** [P1]
  - The key file is created with `fopen(…, 'x')` under `flock`, then re-read, and the bytes on disk are used.
  - `key_id` is the first 8 hex characters of `BLAKE2b(root, key "mtn-kid")`.
  - `KeyStore` exposes every configured key (env and file). Opening tries the row's `key_id`. A row sealed
    under a non-active key is re-sealed with the active key on the next successful open.
- **S13 Low: refs and sections** [P2, P5]
  - Ref lifetimes: browse and art 1 h, media and hls 8 h.
  - A ref with `v≠1`, or with `exp` beyond now plus the maximum, is rejected.
  - Metadata without `librarySectionID` (playlists, collections, hubs) is denied.
  - Whole-server covers only sections whose type is allow-listed.
  - A playlist `rating_key` is section-checked when added and again when played.
- **S14 Low: device name** [P2]
  - `X-Plex-Device-Name` uses the username filtered to `[A-Za-z0-9._ -]{1,40}`, or `user #{id}` when nothing
    is left.
- **S15 Low: accepted risk, added to §9**
  - A project share's audience follows the project's current membership. A project manager can widen it by
    adding members.
  - The share page says so, and the owner can unlink at any time.

**Corrections to this spec's claims:**
- Kanboard form CSRF tokens are single-use (S10).
- The default CSP blocks MSE `blob:` media (S10).
- Kanboard does not end disabled users' sessions (S6).
- `getUserRole` ignores archived projects and returns custom role names (S6).
- `ProjectAuthorizationMiddleware` lets admins into every project and checks only a URL `project_id` (S5).
- `*.plex.direct` names resolve to the dashed IP whatever the hash, so the IP check is the only control.
  Home servers on a LAN are the normal case, which is why S1 matters.

## 12. Testing

TDD. Run the full harness suite with `./testing/run-plugin-tests.sh --all`, to completion, checking the real
test count.

**P1 must cover at least the following.**

*Key and crypto*
- `KeyStore`:
  - creates the file at 0600 and the directory at 0700;
  - reuses the existing file;
  - refuses a wrong-size file and never overwrites it;
  - prefers the env key and refuses a bad env key.
- Seal and open round-trip. Open fails on a tampered ct, a wrong server id, a wrong owner id and a wrong key.

*URL guard and client*
- `UrlGuard` table:
  - public host passes;
  - loopback, RFC 1918, CGNAT and ULA are refused without the opt-in and pass with it;
  - link-local, 169.254.169.254, 100.100.100.200, multicast and `::` are refused even with the opt-in;
  - numeric shorthand is refused;
  - userinfo, query, fragment, path and non-http schemes are refused;
  - a host whose DNS returns one public and one private address is refused without the opt-in;
  - the allow-list matches exactly and by `*.` suffix.
- `PlexClient` with a fake transport:
  - the token is sent only as a header;
  - section types are filtered;
  - a non-200 throws a scrubbed message (asserted: no token, no query in `getMessage()`);
  - a machine-id mismatch throws `serverChanged`;
  - invalid JSON throws.
- `PlexScrub` strips `X-Plex-Token` in every case variant, and in the middle of the query.

*Servers and policy*
- `ServerModel`:
  - connect stores no plaintext token (a DB row scan asserts the token string is absent);
  - a URL change without a new token is refused;
  - remove cascades shares;
  - users who aren't the owner or an admin can't edit or remove;
  - "admins only" mode refuses users.
- `SharePolicy` table across viewer, member, manager, non-member, owner, admin, disabled user, disabled
  server, disabled share and everyone-audience. Everyone-share creation is admin-only. A project share needs
  the sharer to be the project manager.

*Controllers*
- Every POST is CSRF-checked.
- Every route refuses anonymous users.
- Templates never contain the token: render with a known fake token and assert it is absent from the output.

**Live (P1):**
- On :8081, connect a fake Plex. A small PHP fixture server in `Test/fixtures/fake-plex/` answers
  `/identity` and `/library/sections`. It runs inside the dev network, which needs the LAN opt-in.
- Check that the token is absent from page HTML and from the DB.

## Sequencing

**P1** (this plan, built first)
- Skeleton, schema v1 (servers, shares, share_sections).
- Crypto: KeyStore, Subkeys, TokenBox.
- Http: UrlGuard, CurlTransport, PlexClient, PlexScrub.
- ServerModel, ShareModel, SharePolicy.
- User settings UI for servers and shares, and admin settings.

**Later phases**
- **P2:** RefSigner, browse pages, art proxy.
- **P3:** playback, the transcode table, the sweep, hls.js vendoring (supply-chain gate).
- **P4:** rooms.
- **P5:** playlists.

Each phase gets its own plan file, its own `/security-review`, and a full-suite run.

## Left to the builder

- Exact template layout.
- How the share editor lays out sections (checkbox list).
- The admin-notice placement for a missing extension or a bad key.
