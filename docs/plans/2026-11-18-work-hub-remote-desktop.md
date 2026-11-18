# Work hub slice 1, remote desktop: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Carmelo opens `https://desk.carmelosantana.net`, passes Cloudflare Access (GitHub) and Guacamole (password + TOTP), and drives his own logged-in GNOME desktop in the browser.

**Architecture:** A Docker Compose stack in a new private repo `~/Projects/work-hub`: `cloudflared` (outbound tunnel), `guacamole` (web), `guacd`, and `postgres`, all on one internal network named `hub` with no published ports. `guacd` reaches the host's GNOME Remote Desktop on 3389 through `host-gateway`, and a host firewall rule stops anything else from reaching 3389.

**Tech Stack:** Docker Compose, `guacamole/guacamole` and `guacamole/guacd` 1.6.x, `postgres` 17, `cloudflare/cloudflared`, nftables, Bash check scripts.

**Spec:** `kanboard-plugins/docs/specs/2026-11-18-work-hub-remote-desktop-design.md`

## Global Constraints

- **Ask Carmelo before any step that changes DNS, Cloudflare or the firewall.** Tasks 3 and 4 are marked ⛔ at those steps.
- No inbound ports on the router or the host. Nothing in `compose.yml` has `ports:`.
- Every image is pinned to an exact version at least 2 weeks old. No `latest`, no major-only tags.
- Secrets (tunnel token, DB password, Guacamole credentials, GNOME RDP credentials, TOTP seed) are never committed. They live in `.env` (gitignored) or the Guacamole DB.
- The `hub` network subnet is fixed at `172.31.250.0/24`. The firewall rule (Task 3) depends on it.
- Kanboard is untouched: no plugin code, no MCP writes. Carmelo adds the launcher link by hand.
- Commits as Carmelo, with no AI attribution. The repo stays private.

## Review Focus

1. **The default `guacadmin`/`guacadmin` login survives.** It must be gone before the tunnel exists. Task 2 adds the test that it fails.
2. **A port gets published.** A debug override is left in place, or someone adds `ports:`. `scripts/check.sh` fails on any published port in the effective config (Task 1).
3. **A request reaches Guacamole without Access.** It hits the tunnel by another hostname or a path Access does not cover. The catch-all ingress answers 404, and cloudflared validates the Access JWT (Task 4).
4. **The firewall rule is gone after a reboot.** Task 3 checks it after a reboot.
5. **The GNOME session is locked or logged out.** The expected behaviour is a clear Guacamole connection error rather than a hang. Task 5 records what actually happens.

---

### Task 1: Repo skeleton, compose stack, static checks

**Files:**
- Create: `~/Projects/work-hub/{compose.yml,.env.example,.gitignore,README.md,scripts/check.sh,db/.gitkeep}`

**Interfaces:**
- Produces:
  - Services `guacamole`, `guacd`, `postgres` and `cloudflared` (the last under compose profile `tunnel`, so it doesn't start until Task 4).
  - Network `hub` (`172.31.250.0/24`).
  - `scripts/check.sh` exits 0 on success, and on failure prints `FAIL: <reason>` and exits non-zero.

- [ ] **Step 1: Write `scripts/check.sh` first.** Each assertion runs against `docker compose config --format json` (with `--profile tunnel`):
  - no service has `ports`
  - every `image` matches `:[0-9]+\.[0-9]+(\.[0-9]+)?` and none ends in `:latest`
  - network `hub` has subnet `172.31.250.0/24`
  - `guacd` has `extra_hosts` `host.docker.internal:host-gateway`
  - `.env` is listed in `.gitignore`
  - `git ls-files` contains no `.env`
  - `git grep -nE 'TUNNEL_TOKEN=.{20,}'` finds nothing
- [ ] **Step 2: Run `bash scripts/check.sh`.** Expected: FAIL (no `compose.yml`).
- [ ] **Step 3: Write `compose.yml`, `.env.example` and `.gitignore`.**
  - Every service gets `restart: unless-stopped`.
  - `guacamole` sets `GUACD_HOSTNAME=guacd`, `POSTGRESQL_*` from `.env` and `TOTP_ENABLED=true`.
  - `postgres` keeps its data in a named volume and mounts `./db/initdb.sql` read-only into `/docker-entrypoint-initdb.d/`.
  - `cloudflared` runs `tunnel --no-autoupdate run` with `TUNNEL_TOKEN` from `.env`.
  - Generate `db/initdb.sql` with `docker run --rm guacamole/guacamole:<ver> /opt/guacamole/bin/initdb.sh --postgresql > db/initdb.sql` and commit it. It is schema only, with no secrets.
- [ ] **Step 4: Run `bash scripts/check.sh`.** Expected: exit 0. Then `docker compose up -d` and `docker compose ps`: three services healthy or running, no published ports.
- [ ] **Step 5: Create the private repo and commit.** Run `git init`, then `gh repo create carmelosantana/work-hub --private --source . --push`.

### Task 2: Guacamole admin, TOTP and the Workstation connection

**Files:**
- Create: `scripts/smoke.sh`, `compose.local.yml` (gitignored, used only while testing)
- Modify: `README.md` (local-test section), `.gitignore`

**Interfaces:**
- Consumes: the Task 1 stack.
- Produces:
  - One Guacamole admin user (name chosen by Carmelo) with TOTP enrolled.
  - Connection `Workstation`: RDP, hostname `host.docker.internal`, port 3389, security `nla`, `ignore-cert=true`, clipboard on, drive off.
  - `scripts/smoke.sh` runs `curl` from a throwaway container on `hub`:
    - `GET http://guacamole:8080/guacamole/` must return 200.
    - `POST /guacamole/api/tokens` with `guacadmin`/`guacadmin` must return 403.

- [ ] **Step 1: Write `scripts/smoke.sh`** with the two assertions above.
- [ ] **Step 2: Run it.** Expected: FAIL on the second assertion, because the default admin still exists.
- [ ] **Step 3: Set up Guacamole.** Bring the stack up with `compose.local.yml` (binds `127.0.0.1:8085:8080` only) and open `http://127.0.0.1:8085/guacamole`.
  - Carmelo creates his admin and enrols TOTP. Then delete `guacadmin`.
  - Create the `Workstation` connection with the values above. Carmelo types the GNOME RDP credentials (`grdctl` shows the username; he sets the password).
- [ ] **Step 4: Verify.** Run `bash scripts/smoke.sh`: exit 0. Carmelo connects to `Workstation` locally, sees his desktop and can type. Then `docker compose -f compose.yml up -d`, so the override is gone, and `bash scripts/check.sh` exits 0.
- [ ] **Step 5: Commit** (smoke script, README, `.gitignore`).

### Task 3: Firewall 3389 to localhost and the hub network ⛔ ask first

**Files:**
- Create: `firewall/workhub.nft`, `scripts/firewall-install.sh`, `scripts/firewall-check.sh`

**Interfaces:**
- Produces:
  - nft table `inet workhub`, chain `input` (`type filter hook input priority -10`).
  - Rule: `tcp dport 3389 iifname != "lo" ip saddr != 172.31.250.0/24 drop`, with an equivalent IPv6 rule that drops everything not from `lo`.
  - The install script copies the file to `/etc/nftables.d/workhub.nft`, adds an `include` to `/etc/nftables.conf` (only once, so it is safe to rerun) and enables `nftables.service`.

Use a standalone nft table, not ufw. If ufw is inactive, turning it on would change every other port on this machine.

- [ ] **Step 1: Write `scripts/firewall-check.sh`.**
  - `sudo nft list table inet workhub` exits 0.
  - `docker run --rm --network hub busybox nc -zv -w2 host.docker.internal 3389` succeeds.
  - Carmelo runs, from another LAN machine (the TrueNAS box), `nc -zv -w2 <workstation-LAN-IP> 3389`, and it fails or times out.
- [ ] **Step 2: Run the check.** Expected: FAIL (the table is missing).
- [ ] **Step 3: ⛔ Install.** Show Carmelo the rule file. When he says yes, run `sudo bash scripts/firewall-install.sh`.
- [ ] **Step 4: Run the check again.** Expected: all three pass. Reboot (Carmelo's call on timing), log in and run it again: still passes.
- [ ] **Step 5: Commit.**

### Task 4: Cloudflare zone, tunnel, GitHub login, Access app ⛔ ask first

**Files:**
- Modify: `README.md` (Cloudflare runbook and how to revoke), `.env` (local only)
- Create: `scripts/edge-check.sh`

**Interfaces:**
- Consumes: the Task 1 `cloudflared` service (profile `tunnel`) and `TUNNEL_TOKEN`.
- Produces:
  - Zone `carmelosantana.net` on Cloudflare Free.
  - A remote-managed tunnel `work-hub` with ingress `desk.carmelosantana.net → http://guacamole:8080`, catch-all `http_status:404`, and origin request `access.required=true` with the team name and the app's AUD tag.
  - Zero Trust login method: GitHub.
  - Access app on `desk.carmelosantana.net`: policy Allow, include Carmelo's GitHub email only, session duration 8h.
  - `scripts/edge-check.sh`:
    - `curl -sI https://desk.carmelosantana.net/guacamole/` gives 302 with `location` on `*.cloudflareaccess.com`.
    - `curl -sI https://<other>.carmelosantana.net` does not reach Guacamole (404, or no DNS record).

- [ ] **Step 1: Write `scripts/edge-check.sh`.** Run it. Expected: FAIL (no DNS).
- [ ] **Step 2: ⛔ Move the zone.** Carmelo confirms. Add the zone in Cloudflare. Note its current Porkbun DNS records first and recreate them in Cloudflare. Change the nameservers at Porkbun (dashboard, or the Porkbun API with Carmelo's yes). Wait for `dig NS carmelosantana.net +short` to show Cloudflare.
- [ ] **Step 3: ⛔ Set up Zero Trust.** Carmelo confirms. Create the GitHub identity provider, the Access app and policy, then the tunnel and public hostname. Put the token in `.env` and run `docker compose --profile tunnel up -d`.
- [ ] **Step 4: Verify.** Run `bash scripts/edge-check.sh`: exit 0. Run `bash scripts/check.sh`: exit 0.
- [ ] **Step 5: Commit** (README runbook with no secrets, edge check).

### Task 5: Acceptance, security pass, launcher link

**Files:**
- Modify: `README.md` (an acceptance-evidence section with date, commands and outputs)

- [ ] **Step 1: Acceptance (spec items 1–5), recording evidence for each.**
  1. From a phone on mobile data, Access appears first.
  2. Then Guacamole login, then TOTP.
  3. The desktop works.
  4. LAN `nc` to 3389 is refused (from Task 3).
  5. Disable the Access app; the next request is blocked. Re-enable it.
- [ ] **Step 2: Lock screen.** Lock the session (`loginctl lock-session`), connect remotely, and record whether it can be unlocked. Log out at the console, connect, and record the error Guacamole shows. These are Review Focus item 5. Behaviour is accepted as is; this step only records it.
- [ ] **Step 3: Security pass.** Run `/powerup:codex-security` over the spec and the `work-hub` repo. Fix or record every finding before calling slice 1 done.
- [ ] **Step 4: Launcher.** Carmelo adds a "Desktop → https://desk.carmelosantana.net" link to a "Hub" project description on production Kanboard, by hand.
- [ ] **Step 5: Commit and push** the README evidence. Update the `work-hub.md` note in the shared notes folder.
