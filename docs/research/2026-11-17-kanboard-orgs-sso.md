# Kanboard v1.2.47: organisations and SSO

Researched 2026-11-17 against `testing/kanboard-src` (v1.2.47) and the GitHub repos of the official auth plugins. Read-only: no auth config was changed on dev or production.

## Short answer

- **Orgs: no.** Kanboard is single-tenant. It has users, groups, per-project roles and private projects, but no organisation entity: no org-scoped admin, no org settings, no isolation between orgs. Everything is one flat namespace under one set of global admins.
- **SSO: partly.** Core ships LDAP and reverse-proxy auth. There is no SAML or OIDC plugin; the only maintained route is the generic **OAuth2** plugin, which also works with OIDC providers and maps groups. GitHub and GitLab plugins are archived. Reverse-proxy auth gives login only: no groups, no roles.
- **Recommendation:** put **Authentik** in front as the single identity provider. Use the **OAuth2 plugin against Authentik's OIDC endpoint** for login and group sync. Build an **org layer as a suite plugin** (`Orgs`) on top of Kanboard groups. Roughly 2 days for SSO, 1–2 weeks for a useful org layer.

## 1. Orgs and multi-tenancy

### What exists

| Concept | Where | Notes |
|---|---|---|
| App roles | `app/Core/Security/Role.php` | `app-admin`, `app-manager`, `app-user` (+ `app-public`). Global, one per user. |
| Project roles | same | `project-manager`, `project-member`, `project-viewer`, per project. |
| Custom project roles | `project_roles` table | Per project, restrict what a role can do (e.g. move columns only). |
| Groups | `groups`, `group_has_users` | Flat, no nesting. `external_id` links a group to an LDAP/OAuth2 source. |
| Project membership | `project_has_users`, `project_has_groups` | A group can be granted a project role. This is the useful lever. |
| Private projects | `projects.is_private` | One owner, hidden from everyone else. |
| Everybody-visible | `projects.is_everybody_allowed` | Whole instance can see it. |
| Admin API / JSON-RPC | `jsonrpc.php` | Separate auth (app token or personal token); not tied to browser SSO. |

### What is missing for real organisations

| Gap | Effect |
|---|---|
| No org entity | No place to hang org settings, branding, billing or default groups. |
| No org-scoped admin | `app-admin` and `app-manager` are global. A manager can see user and group lists across all orgs. |
| User and group directory is global | Any user who can add project members sees every user and group. This is the main isolation leak (user search in project permissions). |
| No isolation | Isolation can only be approximated by convention: one group per org, project access only through that group, no `is_everybody_allowed`. Nothing enforces it. |
| No per-org settings | Config (`config` table) is instance-wide. |
| Plugin API has no hook for tenant scoping | A plugin has to filter queries itself, via template overrides, model hooks and API middleware. |

### Practical read

Two levels of "org" are achievable:

1. **Soft orgs (a convention):** an org is a Kanboard group (`org:acme`), synced from the IdP. Projects are shared with org groups only. Cheap, works today, no enforcement.
2. **Enforced orgs (a plugin):** a suite plugin adds an `orgs` table, org membership, an org-scoped admin role, and filters on user and group pickers and on project lists. Real but invasive; see section 5.

True multi-tenancy (hard isolation) means one Kanboard instance per org. Docker and Dokploy make that cheap if isolation matters more than a shared board.

## 2. SSO in core

### Provider architecture

`app/ServiceProvider/AuthenticationProvider.php` registers, in order: `TotpAuth`, `RememberMeAuth`, `DatabaseAuth`, `ReverseProxyAuth` (if `REVERSE_PROXY_AUTH`), `ApiAccessTokenAuth`, `LdapAuth` (if `LDAP_AUTH`). Plugins register more through `authenticationManager->register()`.

Interfaces in `app/Core/Security/`:

| Interface | For |
|---|---|
| `PreAuthenticationProviderInterface` | Runs on every request when not logged in (reverse proxy). |
| `PasswordAuthenticationProviderInterface` | Login form (Database, LDAP). |
| `OAuthAuthenticationProviderInterface` | Redirect flow (`getService`, `setCode`, `getUser`, `unlink`). |
| `PostAuthenticationProviderInterface` | After login (TOTP). |
| `SessionCheckProviderInterface` | Per-request session validity (reverse proxy). |

Whatever provider authenticates returns a `UserProviderInterface`. `UserSync` creates or updates the local user (matching on an external-id column). `UserProfile` then calls `GroupSync` with the provider's `getExternalGroupIds()`.

### LDAP

Fully configured by constants in `config.php` (`LDAP_*`). Supports bind types, user filter, group provider, `LDAP_GROUP_ADMIN_DN` and `LDAP_GROUP_MANAGER_DN` for role mapping, `LDAP_GROUP_SYNC`. This is the **only core path with real group and role mapping.** It needs an LDAP server; Authentik can expose an LDAP outpost, but that adds a moving part.

### Reverse-proxy auth (`REVERSE_PROXY_AUTH`)

`app/Auth/ReverseProxyAuth.php`:

- Reads the username from `$_SERVER[REVERSE_PROXY_USER_HEADER]` (default `REMOTE_USER`), plus `REVERSE_PROXY_EMAIL_HEADER` and `REVERSE_PROXY_FULLNAME_HEADER`.
- `isValidSession()` compares the header against the logged-in user on every request. If the proxy user changes, the session is invalidated.
- Creates the user on first sight; `REVERSE_PROXY_DEFAULT_DOMAIN` appends a domain to build an email.
- **Role:** `REVERSE_PROXY_DEFAULT_ADMIN` names one user as admin; otherwise `app-user`, or whatever role is already stored. No header-to-role mapping.
- **Groups:** `ReverseProxyUserProvider::getExternalGroupIds()` returns `[]`. No group mapping at all.
- New users get `is_ldap_user=1` and `disable_login_form=1`, so the password form is disabled for them.

Gotchas:

1. **Header spoofing.** The value is read from `$_SERVER`. If the header is a real HTTP header (`Remote-User`), set `REVERSE_PROXY_USER_HEADER=HTTP_REMOTE_USER`, and make sure the proxy strips any client-supplied copy. Kanboard must only be reachable through the proxy, not directly on a published port.
2. **Group-sync side effect (from reading `GroupSync`):** `removeGroups()` removes the user from every group that has an `external_id` and is not in the returned list. With reverse proxy returning `[]`, any externally linked group is wiped on each login. Keep reverse-proxy users in groups with no `external_id` (hand-managed), or do not mix with the OAuth2 plugin's synced groups. I read this from source and did not run it.
3. **API must bypass the gate.** `/jsonrpc.php` authenticates with its own app token or user token (Basic auth). A forward-auth gate that redirects unauthenticated requests will break MCP, Agents and any scripted client. Exempt `/jsonrpc.php` (and webhook paths) from the gate; RpcGuard already hardens dispatch there.
4. **One user per header value.** The username in Kanboard becomes whatever the IdP sends; rename rules in the IdP can orphan users.

### Forward-auth gate (Authelia, Authentik, Traefik)

Works, with the caveats above. Traefik `forwardAuth` middleware calls the outpost; on success it copies configured response headers onto the upstream request. Set `authResponseHeaders` to Authentik's `X-authentik-username`, `X-authentik-email` and `X-authentik-name`, then map them:

```php
define('REVERSE_PROXY_AUTH', true);
define('REVERSE_PROXY_USER_HEADER', 'HTTP_X_AUTHENTIK_USERNAME');
define('REVERSE_PROXY_EMAIL_HEADER', 'HTTP_X_AUTHENTIK_EMAIL');
define('REVERSE_PROXY_FULLNAME_HEADER', 'HTTP_X_AUTHENTIK_NAME');
```

Authelia works the same with `Remote-User`, `Remote-Email`, `Remote-Name`, `Remote-Groups`. `Remote-Groups` is sent but **Kanboard ignores it** (see above). Authelia and Authentik also provide per-domain access policy and their own MFA in front of Kanboard.

Because the gate sits at the proxy, it gives a single sign-on to every service behind it, which fits the "work hub controls access to my systems" goal. The price is no group or role information inside Kanboard.

### 2FA

Core `TotpAuth` (RFC 6238, `TOTP_ISSUER`), a **Post-authentication** provider: it runs after a password login (`DatabaseAuth`, LDAP, and OAuth login too, since post-auth is global). Users enrol in their profile. It does **not** apply to reverse-proxy logins, where the proxy is expected to handle MFA. With OAuth2, do MFA at the IdP. No WebAuthn/passkeys in core.

## 3. SSO plugins

Checked on GitHub, 2026-11-17.

| Plugin | Last push | Last release | Status | 1.2.47 | Groups / roles |
|---|---|---|---|---|---|
| `kanboard/plugin-oauth2` (generic) | 2026-02-12 | v1.0.2 (2020) | Maintained, active commits (Keycloak and Authentik examples added) | `>=1.0.37`, so yes | **Groups:** yes, from a configurable userinfo key (`oauth2_key_groups`), optional filter, optional fixed groups. Groups are auto-created. **Role:** none (`getRole()` returns `''`, the stored role is kept). |
| `kanboard/plugin-google-auth` | 2025-10-25 | v1.0.8 (2019) | Light maintenance | `>=1.0.37` | Signup groups only (add to named groups on first login). No role mapping. |
| `kanboard/plugin-github-auth` | 2020 | v1.0.3 (2017) | **Archived, not maintained** | Probably loads; do not use | None |
| `kanboard/plugin-gitlab-auth` | 2020 | v1.0.5 (2018) | **Archived, not maintained** | Probably loads; do not use | None |
| SAML | not found | | No `kanboard/plugin-saml-auth`; nothing in the official directory | | |
| OIDC (dedicated) | not found | | Nothing in the official directory. The generic OAuth2 plugin is the supported route and is documented to work with OIDC providers. | | |
| `cas-auth`, `imapuserauth` | 2019 | | Stale | | |

Notes on the OAuth2 plugin:

- It uses **userinfo endpoint** data, not the ID token, so there is no JWT or signature validation of its own, and no `nonce` handling. That is fine for confidential-client code flow over TLS.
- Group names from the IdP become Kanboard groups with `external_id = name`, and `GroupSync` adds and removes membership at every login. This makes the IdP the source of truth for membership.
- Role mapping is the gap. Admin and manager status must be set by hand, or by a small companion plugin (see section 5).
- "Allow account creation" and a domain restriction exist. New users get `is_ldap_user=1` and `disable_login_form=1` when creation is allowed.
- Release tag is old (2020) but `master` is newer than the tag. Pin by commit, or vendor it, because the Kanboard plugin installer fetches the tagged zip.
- Not tested here: I did not run it on the dev stack. Compatibility is from the declared `>=1.0.37` and the unchanged provider interfaces in 1.2.47.

## 4. Options

| # | Option | Login | Groups | Roles | Orgs | Effort | Risk |
|---|---|---|---|---|---|---|---|
| A | Status quo: local passwords + TOTP | local | manual | manual | none | 0 | No SSO; weak for a hub |
| B | Forward-auth gate (Authentik/Authelia) + `REVERSE_PROXY_AUTH` | IdP | none | one default admin | none | ~0.5 day | Header spoofing if Kanboard is reachable directly; API paths must bypass; group wipe quirk |
| C | **Authentik OIDC via OAuth2 plugin** | IdP | **synced** | manual | convention | ~1–2 days | Plugin is a thin, sparsely released upstream; role gap |
| D | LDAP via Authentik LDAP outpost | LDAP | synced | **mapped** (admin/manager DN) | convention | ~2 days | Extra service; password-form login only, no browser SSO |
| E | Write an `OIDC` suite plugin (ID token, roles claim, PKCE) | IdP | synced | **mapped** | pairs with org layer | ~1 week | We own auth code; needs careful review |
| F | One Kanboard instance per org | any | per instance | per instance | **hard isolation** | per-org ops | Cross-org work needs the API or links |

B and C can coexist as defence in depth: the gate protects the host, the OAuth2 login establishes the Kanboard identity. Do not run both as identity sources for the same users (see the group-wipe note).

## 5. Recommendation

1. **Now (about 1–2 days, low risk): Authentik + OAuth2 plugin (option C).**
   - Authentik OIDC provider, scopes `openid profile email groups`, a `groups` claim in userinfo.
   - Kanboard OAuth2 settings: User API URL = Authentik userinfo, username key `preferred_username`, ID key `sub`, groups key `groups`, group filter `org:*` style names.
   - Leave `REVERSE_PROXY_AUTH` off. Keep one local break-glass admin with the password form and TOTP.
   - Do MFA in Authentik.
   - Keep `/jsonrpc.php` on its own auth; do not gate it.
   - Verify on the :8081 dev stack first (it is not production), including the first-login admin case and what happens when a user leaves a group.
2. **Next (about 3–5 days): a small `OrgRoles` companion** that maps IdP claims to `app-admin` / `app-manager` (core has `UserModel->update(['role'=>...])` and a post-login event), because the OAuth2 plugin cannot. If we want this to be robust, this is the seed of option E (our own OIDC provider).
3. **Then (1–2 weeks): an `Orgs` suite plugin.** Table `orgs` and `org_users`, an org-admin role, a membership-backed group per org (reuse synced groups), and filters for the user and group pickers and project lists. Ship it in the suite style (buildless, requires `AiConnector`-style dependency declaration in the ModMenu directory). Scope it to soft isolation; document that hard isolation means option F.
4. **If hard tenant separation becomes a requirement:** run one instance per org behind Authentik, one OIDC application each.

### Effort and risk summary

| Step | Effort | Main risk | Mitigation |
|---|---|---|---|
| OAuth2 + Authentik | 1–2 days | Upstream thin; unverified on 1.2.47 | Test on :8081; pin to commit; keep break-glass admin |
| Role mapping plugin | 3–5 days | Privilege escalation via claims | Allow-list claim values; admin changes logged; default `app-user` |
| Orgs plugin | 1–2 weeks | Leaky isolation (pickers, API, search, activity feed, file URLs) | State "soft isolation" up front; add API middleware like RpcGuard; test each listing endpoint |
| Dedicated OIDC plugin | ~1 week | We own auth code | ID token signature and `nonce` checks; external review |

### Open questions for Carmelo

- Is Authentik already running (Dokploy)? If not, Authelia is lighter, but it has no OIDC group claims in userinfo as rich as Authentik's, and the OAuth2 route would need it as an OIDC provider.
- Are orgs *customers* (needs isolation, option F or the Orgs plugin) or *teams* (a group convention is enough)?

## 6. Notes for dependent designs

**Git hosting identity mapping.** Use the IdP's stable `sub` (Kanboard stores it as `oauth2_user_id`) as the join key between Kanboard users and git identities. The Kanboard username is not stable under reverse-proxy (it is whatever the header says) and is user-editable otherwise. Groups arrive as Kanboard groups with `external_id`, so a git-hosting plugin can resolve "who is in org X" from `group_has_users`.

**Projects as a work hub (access control).** Express access as IdP group → Kanboard group → project role (`project_has_groups`). Prefer groups to individual grants so a revoke at the IdP removes access at next login. Caveat: sync runs **at login only**, so revocation is not instant for a live session (sessions last until expiry or `SessionCheckProvider` invalidates). If instant revocation matters, the hub needs a forward-auth gate or a periodic reconcile job. API clients (MCP, agents) use Kanboard tokens and are **not** covered by SSO group changes.

## Sources

- `testing/kanboard-src/app/Auth/ReverseProxyAuth.php`, `app/User/ReverseProxyUserProvider.php`, `app/Core/Security/*`, `app/Core/User/{UserSync,GroupSync,UserProfile,UserProperty}.php`, `app/ServiceProvider/AuthenticationProvider.php`, `config.default.php`
- github.com/kanboard/plugin-oauth2, plugin-google-auth, plugin-github-auth, plugin-gitlab-auth (README, `Plugin.php`, user and auth provider sources, commit history)
- Official plugin directory (`kanboard/website` `plugins.json`)
- Not verified here: Authentik and Authelia header names (from their documented behaviour), and live behaviour of any of the above on the dev stack.
