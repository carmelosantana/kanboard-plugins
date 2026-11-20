# Matinee (Plex sharing plugin): decision map and rulings

Wayfinding record for a new suite plugin, charted overnight 2026-11-19 while Carmelo was away. Every crux
below was decided without a human answer. Each ruling carries its cost if wrong. Open items for Carmelo are
at the end.

**The ask, in Carmelo's words:** "A plex plugin. Users can add their plex server and share with per project
or whole server. Using there plex token they share. Sharing playlists and watching video together would be
nice. We have a plex project called venna ? Something like that it may help view our kanboard."

**Destination (inferred, not confirmed):** a buildless Kanboard 1.2.47 plugin. A Kanboard user connects their
Plex server with their token. They share chosen libraries, or the whole server, with a project or with
everyone. Members browse and play that media inside Kanboard without ever seeing the token. They can also
watch together in sync and keep shared playlists. Tonight's target is an approved spec, a plan, and a tested
foundation.

**Where the map lives:** this file. The Kanboard MCP points at production, so nothing went on a board. A
dev-board copy on :8081 is optional and was skipped (R0).

## Prior art read

- **Veranda** (`~/Projects/local-plex`, read-only): a LAN guest gateway. The Plex owner token stays
  server-side. Clients get **HMAC-signed, expiring media refs** (`<body>.<sig>`, constant-time verify, section
  re-checked against the live allowlist on every use). The **exposed library set** is default-deny. **Strict
  path regexes** guard every proxied Plex path (`ART_PATH_RE`, `TRANSCODE_PREFIX`). **HLS playlists are
  rewritten** so child URIs route back through the proxy, with `X-Plex-Token` stripped case-insensitively.
  Plex transcode sessions have an idle sweep and a `/stop`. The token goes **only in headers**, and no URL or
  token appears in error text. **TLS verification is always on**: plex.direct certs are public, so connect by
  hostname. **Session claims** mean a transport or volume call carries the session it believes it is driving,
  and a stale one is refused, so a guest can't drive a session someone else now owns.
- **AiConnector `UrlGuard`**: the suite's SSRF guard. It has a host allow-list and a LAN opt-in. It always
  blocks link-local, metadata, multicast and reserved ranges, rejects numeric-shorthand hosts, and pins the
  connection to the checked IP with redirects off.
- **Vault crypto**: XChaCha20-Poly1305 with a per-seal nonce and associated data. Vault's key hierarchy is
  end-to-end and keyed by user passphrases, which a server streaming media at request time cannot use.
- **AgentChat SSE spike**: each open SSE stream pins one PHP-FPM worker for its whole life. The effective
  `pm.max_children` is 20. With 20 streams open, the 21st page load stalled for 9.5 s.
- **Steward**: own tables through `Schema/{Sqlite,Mysql,Postgres,Mssql}.php`, plus a spec security-review gate.

## Rulings

### R0: The map is markdown in `docs/research/`, not board tickets
**Why:** The MCP is production, and the brief allowed a markdown map.
**Cost if wrong:** Low. The map can be transcribed onto a board later.

### R1: The name is **Matinee** (`kanboard-matinee`, namespace `Kanboard\Plugin\Matinee`)
**Why:** It is a short, non-trademark name for watching together, and it fits the suite (Steward, Vault,
Pulse). "Plex" can't go in the name: it is a trademark.
**Cost if wrong:** Low until the first release, then a rename.

### R2: PHP talks to Plex directly. Veranda is not a backend in v1.
- Port Veranda's patterns to PHP: signed refs, path regexes, playlist rewrite, header-only token, scrubbed
  errors, and TLS always verified.
- Keep every Plex call behind one `PlexClient` class (with injectable transport) so a Veranda-backed client
  could slot in later.
**Why:**
- Veranda is a single-owner, LAN-only, no-login guest gateway. It holds one token from env and has no notion
  of many owners or of Kanboard users.
- Routing through it would mean a shared-secret trust link, a second deployable and its own release cadence.
  It would buy nothing that the ported patterns don't give us.
- Matinee must also work where no Veranda runs, such as a hosted Kanboard reaching a remote Plex.
**Cost if wrong:** Medium. A Veranda adapter would be a second `PlexClient` implementation plus a ref-signing
handshake. The interface boundary keeps it contained.

### R3: Any active Kanboard user may connect their own Plex server, behind an admin switch
- Admins choose who may connect servers: "everyone" (the default, per the ask) or "admins only".
- The connecting user becomes the server's **owner**.
**Why:** It is what Carmelo asked for. The admin switch exists because each server URL is an SSRF surface
(R7).
**Cost if wrong:** Low. It is one setting default.

### R4: Token entry is a paste-in password field in v1. Plex PIN sign-in comes later.
- The field is write-only, never echoed, and has a placeholder when a token is stored.
- Saving a token verifies it against the server (`GET /identity` plus `GET /library/sections`) before storing.
**Why:** It is the smallest correct thing. The plex.tv PIN flow (`/api/v2/pins`) is nicer but adds an
outbound host and polling.
**Cost if wrong:** Low. PIN sign-in is additive (Q2).

### R5: Tokens are encrypted at rest with a server-held key. Vault's E2E model is not used.
- Use XChaCha20-Poly1305 (libsodium) with a fresh nonce per seal.
- The AD is `matinee:token:v1|server_id|owner_user_id`, so a ciphertext can't be moved to another row or owner.
- The key is 32 bytes from `MATINEE_KEY` (base64 env) when set. Otherwise it comes from
  `DATA_DIR/matinee/secret.key`, created on first use with mode 0600 in a 0700 directory.
- Subkeys are derived with `sodium_crypto_kdf_derive_from_key`: one for tokens, one for signed refs (R9).
- A DB-only backup or leak therefore yields no usable tokens.
**Why:** Streaming happens at request time for users other than the owner, so the server must be able to
decrypt without anyone's passphrase. Vault's design is the wrong shape for that.
**Cost if wrong:**
- Losing the key file makes every stored token unreadable. Matinee then shows "reconnect this server" and
  the owner pastes the token again. Nothing else is lost.
- Someone with the key file and the DB gets the tokens. That is accepted: it is the same trust boundary as
  `config.php`.

### R6: The token never leaves the server
- It is never rendered, never in any URL Matinee builds (header `X-Plex-Token` only), and never in logs,
  exceptions, flash messages or API output.
- It is stripped from any Plex-returned URL before that URL is reused, case-insensitively, including inside
  HLS playlists.
- Admins can see that a server exists and disable it, but there is no "show token" anywhere.
**Why:** A Plex token is a full-account credential.
**Cost if wrong:** None. It is a pure restriction.

### R7: The server URL is an SSRF surface. Port `UrlGuard` into Matinee, don't depend on AiConnector.
- Only `http` and `https`. No userinfo, no fragment, and no path beyond `/`.
- Host rules:
  - Numeric-shorthand hosts are refused.
  - Every resolved address is classified.
  - **Blocked** (always refused, even with the opt-in): link-local, cloud metadata, unspecified, multicast
    and reserved.
  - **Private** (RFC 1918, loopback, CGNAT, ULA) is refused unless the admin's LAN opt-in is on.
  - An admin host allow-list (`*.plex.direct` style entries) is optional.
- Every request is pinned to the checked IP (`CURLOPT_RESOLVE`). No redirects. `CURLOPT_PROTOCOLS` is
  http/https only. Timeouts are set.
- The guard re-runs on **every** request, not only at save, so DNS changes can't move a saved server.
- At connect time Matinee records Plex's `machineIdentifier` from `/identity`. Every later JSON response
  that carries a `machineIdentifier` must match it. A mismatch means "server changed, reconnect".
- Responses are only ever parsed into whitelisted fields. Raw upstream bodies reach the browser only on the
  media and art paths, and only for paths that pass Veranda's regexes, with an allow-listed content type.
- Loopback is "private", so a Plex on the Kanboard host needs the LAN opt-in.
**Why:** Any user may add a server URL (R3), and the server makes the request.
**Superseded in part by the spec security gate (S1):** a blanket LAN opt-in is replaced by an admin list of private targets (host or CIDR plus port). Connect errors are generic, and attempts are rate-limited.
**Cost if wrong:** If the default (LAN off) is too strict for Carmelo's home Plex, an admin ticks one box.
That is the right default for a public Kanboard.

### R8: A share is (server, content scope, audience)
- **Content scope:** a chosen set of library section keys, or "whole server", which follows sections added
  later. Default-deny: nothing is shared until chosen.
- **Audience:**
  - A **project**: the sharer must be a project manager of that project, or an app admin.
  - **Everyone**: every active user. Only app admins may create everyone-shares, because they reach every
    account.
- **Kanboard role → Matinee ability (project shares):**

  | Role | Browse and play | Start or control a watch room | Edit project playlists | Remove the share from the project |
  |---|---|---|---|---|
  | project viewer | yes | join only | no | no |
  | project member | yes | yes | yes | no |
  | project manager | yes | yes | yes | yes (unlinks it, can't edit the owner's server) |
  | server owner | always, on their own server | yes | yes | yes, and edits scope |
  | app admin | n/a unless in the audience | n/a | n/a | yes (disable any share or server) |

- Everyone-shares give every active user viewer-plus-member abilities (browse, play, rooms). Only the owner
  and admins manage them.
- Every proxied request re-checks the share live, so removing a share or server, losing project membership,
  or a disabled user cuts access on the next request.
**Why:** It matches both readings of "per project or whole server", and it reuses Kanboard's own roles, so
there are no new ACL concepts.
**Cost if wrong:** Low to medium. The ability table is one policy class (Q1).

### R9: Media reaches the browser only as signed, expiring refs, proxied by Matinee
- Port Veranda's `MediaRef`: HMAC-SHA256 with the R5 ref subkey, base64url `body.sig`, constant-time verify.
- The payload is `{share_id, server_id, section, kind, resource, ratingKey?, exp}`, also bound to the
  Kanboard user id, so a ref copied to another account is refused.
- On use the ref is verified, then the share is re-authorized live (R8). The resource path is re-validated
  against its kind's regex.
- TTL: 6 h for browse and art refs. Media and HLS refs last as long as their session.
**Why:** It is Veranda's proven model. The client can only reach what the server already vetted.
**Cost if wrong:** Low.

### R10: Playback goes through the PHP proxy, shaped so no FPM worker is pinned
- **Video:** everything goes through the Plex universal transcoder as **HLS**. Matinee rewrites the playlist,
  as Veranda's `rewrite.ts` does.
  - Segments and playlists are short requests.
  - Browsers without native HLS use a vendored `hls.js` (Veranda vets 1.7.1). Vendoring goes through
    `/powerup:supply-chain` first.
- **Audio:** direct parts for mp3, and Plex's mp3 transcode for other formats.
  - Range responses are **capped at 8 MiB per request** (206 with a partial range), so the browser keeps
    issuing ranged requests and no worker stays on one stream.
  - A transcoded mp3 stream has no ranges. It is the one long request, and it counts toward the cap below.
- **Session teardown:** transcode sessions are tracked in a table and swept lazily. Any Matinee request
  stops sessions idle for more than 120 s via `/video/:/transcode/universal/stop`. Plex's own timeout is the
  backstop.
- **Concurrency:** the admin sets a cap on concurrent long streams per server (default 4) and gets a clear
  "server busy" when it is hit.
**Why:** Kanboard has no long-running PHP. The SSE spike showed that pinned workers starve the site at 20.
**Cost if wrong:** Medium. If HLS proves flaky on some Plex versions, add a direct-play path for mp4/h264.

### R11: Watch-together is polling, not SSE
- A **room** belongs to a share and carries:
  - the media ref and the playing/paused state;
  - `position_ms` at `updated_at`, plus the rate;
  - a monotonically increasing `version`;
  - the host user id.
- Clients poll `GET room/state` every 2 s, a short request. The server sends `server_now_ms` so clients can
  estimate clock offset.
- Expected position = `position_ms + (now − updated_at)` while playing. A client more than 1.5 s off seeks.
- Controls (play, pause, seek, change item) are a POST carrying the `version` the client last saw. This is
  Veranda's session claim, ported.
  - A stale version gets 409 plus the current state, so two people seeking at once can't fight silently.
  - Any member in the room may control by default. The host can lock the room to host-only.
- A room expires after 30 min with no polls. There's no presence list beyond "seen in the last 10 s"
  (derived from polls).
**Why:**
- SSE pins a worker per viewer (spike). A 2 s poll is cheap and good enough for "pause for everyone".
- Sub-second sync is not a goal.
**Cost if wrong:** Low to medium. A later SSE upgrade can reuse the same state model if FPM capacity allows.

### R12: Shared playlists are Matinee-native project playlists. Nothing is written to Plex.
- Each playlist belongs to a share audience (a project or everyone). Its items are
  `(share_id, ratingKey, section)`.
- Members add, remove and reorder. Viewers play only.
- A playlist can start a watch room's queue.
- Items whose section is no longer shared are hidden, not deleted, so they come back if the section is
  re-shared.
- Importing the owner's own Plex playlists (filtered to shared sections) is **later**.
**Why:**
- Writing to Plex would mutate the owner's account on other people's say-so.
- Plex playlists are per-account and mix in unshared sections.
**Cost if wrong:** Low. Import is additive (Q3).

### R13: Storage is Matinee's own tables via `Schema/`, the Steward pattern
- The tables are `matinee_servers`, `matinee_shares`, `matinee_share_sections`, `matinee_rooms`,
  `matinee_playlists`, `matinee_playlist_items` and `matinee_transcodes`. Each phase adds only the tables it
  needs.
- Settings (the R3 switch, the R7 allow-list and LAN opt-in, the R10 cap) live in Kanboard `config`.
**Why:** Encrypted per-server rows, share joins and room versions don't fit the metadata tables.
**Cost if wrong:** Low.

### R14: No required dependencies
- AgentChat is a `recommends` later: posting "started watching X" into the project room.
- No AiConnector.
- `php-sodium` and `php-curl` are PHP extension requirements, checked at runtime with a clear admin notice.
**Why:** Matinee stands alone.
**Cost if wrong:** None.

### R15: Where it appears in Kanboard
- **User settings → Plex servers** (connect, verify, remove, share).
- **Project sidebar → Media** (browse the shares visible in this project, play, rooms, playlists).
- **Dashboard → Media** (everyone-shares).
- **Admin settings → Matinee** (R3, R7 and R10 settings, and a server list with disable).
- Linking a media item to a task is **later**.
**Why:** Carmelo's line that Veranda "may help view our kanboard" reads as "reuse its viewing UX". The
browse and player layouts borrow from Veranda's design handoff, kept to plain CSS on Kanboard's look.
**Cost if wrong:** Low (Q4).

### R16: Phasing
- **P1** (foundation, building tonight): plugin skeleton, schema, key handling and token crypto, URL guard,
  Plex client, server connect/verify/remove, section listing, and the share model and policy.
- **P2:** browse with signed refs and the art proxy.
- **P3:** playback (audio ranged, video HLS with vendored hls.js).
- **P4:** watch-together rooms.
- **P5:** playlists.
- Each phase is releasable on its own, but **nothing is pushed or released without Carmelo**.
**Why:** Token handling and SSRF are the risky core, so they come first, with tests.
**Cost if wrong:** Low.

## Questions for Carmelo (morning)

- **Q1:** "Share with per project or whole server". I read it as two things: *content* (chosen libraries or
  the whole server) and *audience* (a project, or everyone, which only admins may create). Is that right?
  Should non-admins be able to share with everyone?
- **Q2:** Is pasting the token acceptable for v1, or do you want "Sign in with Plex" (the PIN flow) before
  the first release?
- **Q3:** Should shared playlists also import your existing Plex playlists (read-only, filtered to shared
  libraries)? Or are Matinee-native project playlists enough?
- **Q4:** "It may help view our kanboard": did you mean borrowing Veranda's viewing UI (my reading), or
  actually running Veranda alongside Kanboard as the player?
- **Q5:** Your Plex is presumably on your LAN while Kanboard is at projects.madeinnewburgh.com. Can that host
  reach Plex at all, through a plex.direct URL or remote access? This decides whether the LAN opt-in or a
  public Plex URL is the real-world path.
- **Q6:** The name: Matinee OK?
- **Q7:** Should there also be an option for real Plex friend invites (plex.tv shared-server API, for people
  with Plex accounts)? I left it out of v1 because it needs everyone to have a Plex account and the API is
  unofficial.
- **Q8:** Plex remote access often uses a custom forwarded port. Public addresses currently accept only 32400, 443 and 80. Should admins be able to allow extra public ports? This comes from the UrlGuard review, finding M5.
