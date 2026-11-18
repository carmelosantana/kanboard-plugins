# Work hub, slice 1: remote desktop into Carmelo's session

- **Status:** design agreed 2026-11-18 (approach A); open items below need answers before go-live
- **Effort:** "Kanboard as a work hub". The rest of the shortlist is listed under *Later slices*.
- **Research it builds on:** `docs/research/2026-11-17-kanboard-orgs-sso.md` (Kanboard has no orgs and can't be the login for other services).

## Why

Carmelo wants Kanboard (`projects.madeinnewburgh.com`) to be the place he starts his work from and controls access from. The first concrete need is remote desktop into his own workstation from anywhere, in a browser, with nothing to install on the client. Later he wants the same hub to give a few trusted people access to selected systems, tied to project membership.

## Shortlist (agreed order)

1. **Remote desktop through the hub** (this spec). Carmelo only, his own logged-in session.
2. **Project-membership sync** (slice 2). A small Kanboard `Hub` plugin keeps a Cloudflare Access list in step with a project's members, so taking someone off the project takes their access away.
3. **VPN.** Tailscale or Headscale, for when a browser desktop isn't enough.
4. **Project radio.** Built on Veranda (`~/Projects/local-plex`), with Presence showing who is listening.
5. **Per-person desktop accounts** (deferred). Separate Linux accounts for trusted people. This is "a much bigger ask, worth testing and planning for" and gets its own design. It is not part of slice 1.

## Goals

- Carmelo opens a link from Kanboard, signs in through Cloudflare Access, and lands on his own GNOME desktop in the browser.
- Nothing on this machine is reachable from the internet except through Cloudflare Access.
- Works when he is away from home with no VPN.

## Non-goals (slice 1)

- Other users or per-person accounts (shortlist item 5).
- Access driven by Kanboard project membership (slice 2).
- Any Kanboard code. The slice 1 launcher is a plain link.
- A VPN, file transfer beyond what Guacamole gives, or audio.

## Anti-goals

- Writing home-grown auth code that guards a desktop.
- Opening any inbound port on the router or the workstation.
- Letting Kanboard sessions or tokens become a key to the desktop. Taking over Kanboard must not hand someone the workstation.

## Current state (checked 2026-11-18)

- Workstation: Ubuntu 24.04.5, GNOME on X11, a VM on the TrueNAS box.
- **GNOME Remote Desktop is already on.** It runs in user (desktop-sharing) mode with RDP enabled and not view-only, on port **3389, listening on all interfaces**. That is the session we want to reach.
- Docker is running here (Kanboard dev stack, kb-git Traefik, and others).
- `cloudflared` is not installed. None of Carmelo's personal domains has a Cloudflare zone. The only one in Cloudflare is `base.vctrs.io`, which is VCTRS work and not used here.
- Production Kanboard runs remotely on Dokploy and has no trust link to this machine.

## Approaches considered

| | Shape | Verdict |
|---|---|---|
| **A. Cloudflare Access in front of Guacamole** | Cloudflare Tunnel, then Access (MFA), then Guacamole, then GNOME RDP. Kanboard is only a launcher. | **Chosen.** Most security for the least code. Membership sync comes in slice 2 through the Cloudflare API. |
| B. Kanboard `Hub` plugin as the gate | A signed ticket handed from Kanboard to a proxy that checks it on every request (forward-auth) | Rejected for now. It is home-grown auth guarding a desktop, and it makes Kanboard the prize. |
| C. Authentik as the login for everything | OIDC into Kanboard and Guacamole | Deferred. Only worth it once many services need one login, and removal only takes effect at the next login. |

## Design

```
browser ──https──▶ Cloudflare edge ── Access policy (Carmelo only, MFA)
                         │
                   Cloudflare Tunnel (outbound only)
                         │
   workstation:  cloudflared ─▶ guacamole (web) ─▶ guacd ─▶ GNOME Remote Desktop :3389
                 (Docker network "hub", nothing published to the host
                  except what guacd needs to reach :3389)
```

1. **Hostname.** One hostname on a Cloudflare zone (see open item 1), for example `desk.<domain>`.
2. **Cloudflare Tunnel.** `cloudflared` runs as a container on the `hub` network and connects outbound only. The ingress maps the hostname to `http://guacamole:8080`. Any other path gets a 404.
3. **Cloudflare Access** (Zero Trust, Free plan):
   - A self-hosted application on the hostname.
   - Policy: Allow, include **Carmelo's email only**. Login method is the one-time code by email, or GitHub if he prefers (open item 2).
   - Session duration **8h** (my call; shorter if he wants).
   - Turn on "Validate the Access JWT at the origin": Guacamole does not read it, so nothing that skips Access can reach Guacamole anyway, because Guacamole is not published.
4. **Guacamole.** Official `guacamole/guacamole` and `guacamole/guacd` images, pinned to exact versions. Postgres or the built-in database, whichever the builder finds simplest; one admin user only.
   - **Second factor inside Guacamole:** the TOTP extension is on (my call). Cloudflare is the outer wall, and Guacamole login plus TOTP is the inner one, so one stolen email inbox is not enough.
   - One connection, "Workstation": RDP to the host's GNOME Remote Desktop, security mode `nla` or `tls`, `ignore-cert` only because it is the host's self-signed cert on a private hop.
   - The GNOME RDP credentials live only in Guacamole's database. They are never in the repo or in Kanboard.
   - Clipboard on. Drive or file transfer **off** in slice 1.
5. **Lock down 3389.** GNOME RDP currently listens on every interface. Firewall it (ufw) so only `127.0.0.1` and the Docker `hub` subnet can reach it. Today anything on the LAN can try it.
6. **Where the files live.** A new private repo `carmelosantana/work-hub` (my call) holds `compose.yml`, `.env.example`, the `cloudflared` config template, the ufw rules script and a README runbook. Secrets (tunnel token, Guacamole DB password, TOTP seed) stay in `.env` or Docker secrets and are never committed. The stack uses `restart: unless-stopped`.
7. **Kanboard launcher (slice 1).** Carmelo adds a "Desktop" link to the description of a "Hub" project on production by hand. No plugin and no MCP writes. Slice 2's `Hub` plugin replaces this with proper tiles.

## Security

This exposes Carmelo's own logged-in session. That session holds `~/.claude.json`, SSH keys and cloud credentials, so a compromise means *everything*. The layers:

- No inbound ports. The tunnel is outbound only.
- Cloudflare Access with MFA and a single-email allowlist.
- Guacamole login plus TOTP.
- 3389 firewalled to localhost and the Docker network.
- Cloudflare Access logs and Guacamole connection history are kept for review.

**Gate:** run `/powerup:codex-security` on this spec and on the `work-hub` repo before the hostname goes live. Revoking access means turning off the Access app or the tunnel, and both are one click in the Cloudflare dashboard. The README says so on its first screen.

## Open items (Carmelo's to answer before go-live)

1. **Which domain hosts the hostname.** None of his zones is on Cloudflare yet, and Cloudflare Access needs one. My proposal is to move a parked personal domain (for example `carmelosantana.net`) to Cloudflare Free, so `madeinnewburgh.com`'s DNS stays untouched.
2. **Login method:** email one-time code, or GitHub.
3. **Reboots and lock screen.** GNOME desktop sharing in user mode only works while he is logged in. After a reboot there is nothing to connect to until someone logs in at the console. The options are: accept that; turn on auto-login (weaker at the console of a VM nobody can reach physically, so arguably fine); or switch GNOME RDP to system "remote login" mode, which gives a separate session rather than his live one. The builder must also confirm a locked screen can be unlocked remotely.

## Left to the builder

- Exact image versions, Postgres vs. built-in DB, network names.
- How guacd reaches the host's 3389 (`host-gateway` extra_host vs. host networking for guacd alone). Pick the narrowest.
- Acceptance check, recorded in the README with evidence:
  1. From a phone on mobile data, the hostname shows Cloudflare Access first.
  2. After Access, Guacamole asks for login and TOTP.
  3. The desktop appears and accepts input.
  4. `nc -zv <lan-ip> 3389` from another LAN machine is refused.
  5. Disabling the Access app blocks the next request.

## Later slices (not designed here)

- **Slice 2, membership sync:** a `Hub` suite plugin that maps a Kanboard project to a Cloudflare Access group and pushes members' emails through the API on member add/remove events, plus a periodic reconcile. It also renders launcher tiles. It needs its own security pass, because the plugin holds a Cloudflare API token scoped to Access only.
- **VPN, project radio, per-person accounts:** see the shortlist.
