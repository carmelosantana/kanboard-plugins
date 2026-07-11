# Kensho — Design & Spec

- **Date:** 2026-07-11
- **Status:** Approved via brainstorming (+ deep-research grounding); source of truth for a single-agent SDD build.
- **Plugin/repo:** new — `Kensho` / `kanboard-kensho`. Name 見性 ("seeing one's true nature").
- **Version:** 1.0.0. Full three-surface product.
- **One-line:** a private, per-user reflection companion that mines your *own* Kanboard activity, lets you record who you want to be, and helps you reflect on whether the two align — with an LLM as a collaborator, never an authority.

## Goal & reframing

The user's ask ("use our data to help find the purpose of our life") is deliberately **inverted** by this
design, on strong research grounds: an LLM must **never compute and pronounce someone's life purpose.**
Kensho helps the *user* see their patterns and construct their *own* narrative; the AI proposes and
questions, the user decides. This reframing is the core design constraint, not a disclaimer.

## Research grounding (verified — these ARE requirements, not background)

From a 26-source, adversarially-verified deep-research pass (see the session's research report; key sources
cited inline):

1. **Values leave recoverable signatures in activity data** — distinct values map to codeable activity
   categories (PMC6401649). → Mining Kanboard tasks/categories/tags/time is a valid premise.
2. **…but only partially and contextually** — single tasks/gaps are weak signals; only *aggregated*
   patterns carry meaning, and even then incompletely (Schwartz, PMC6401649). → Never infer identity from
   one task or a quiet week.
3. **Direct precedent works:** eValuATE (CHI 2026, 10.1145/3772318.3791113) = activity → value-annotation →
   visualization → reflection, and it changed behavior. → Replicate that *pipeline shape* (Kensho mines
   existing logs instead of manual day-reconstruction).
4. **Aligned-time scoring is proven prior art:** TimeAlign "Alignment Score" (planned-vs-actual per
   category) and RescueTime Productivity Pulse (0–100 weighted, category-default + per-item override) are
   transferable. VLQ (10.1007/BF03395706): importance × consistency, importance−consistency = discrepancy =
   exactly "do my actions match who I want to be."
5. **Model use as a non-linear reflection cycle, not a pipeline to a mandated action** (lived-informatics,
   UbiComp 2015). People track for behavior-change *or* instrumentation *or* curiosity — for many,
   understanding IS the goal. → Each surface is independently useful; don't force a goal/action step; don't nag.
6. **Lapses are normal, not failure** (UbiComp 2015, four lapse types; high non-use rates). → Quiet Kanboard
   periods = ordinary lapse; the LLM must never frame gaps as moral failure.
7. **LLM = supervised collaborator** (arXiv:2510.18456) — theme-finding needs human reflexive supervision.
   → The user validates/edits every AI-proposed theme, area, or reflection. AI output is a scaffold.
8. **Reductive quantification & non-neutral categories** (Sharon & Zandbergen 2017) — tracked categories
   embed normative ideals. → Life areas + weights are **user-defined and contestable**, never imposed
   Schwartz/ikigai taxonomies; any score is explicitly "a chosen proxy, not a measurement of your worth."
9. **Data → self only via narrative** (same). → AI augments reflection; the *user writes the story*.
10. **Privacy + sycophancy** (APA Health Advisory, Nov 2025) — introspective data is sensitive; LLMs flatter.
    → Keep data self-hosted; AI is opt-in per run with an explicit "what is sent" summary; prompts instruct
    the model to **challenge rather than validate** and to avoid authoritative purpose-claims.

## Product principles (binding)

- **Reflection companion, not purpose oracle.** No surface ever outputs "your purpose is X."
- **User-owned, contestable dimensions.** Areas, groupings, importance, weights = the user's.
- **AI proposes, user disposes.** Every AI artifact is editable and requires user confirmation to persist.
- **Gentle, non-judgmental voice** (authentic ikigai / Ken Mogi spirit): everyday joys, small things, no shame.
- **Non-linear:** view your patterns without setting goals; set goals without running AI; all independently usable.
- **Private by construction:** per-user; self-hosted; AI opt-in; minimal data egress; nothing sent except on an explicit reflect action to the user's chosen provider.
- **Degrades to fully manual** when AiConnector is absent (AiConnector is a `recommends`, not `requires`).

---

## 1. Data mining layer — `KenshoDataModel` (extends `Kanboard\Core\Base`)

Read-only aggregation of the current user's own activity. No writes.

- `aggregate(int $userId, int $windowDays): array` — the core signal builder.
  - Accessible projects: `projectPermissionModel->getActiveProjectIds($userId)` (`ProjectPermissionModel.php:170`).
  - Tasks: the user's own tasks (`owner_id = $userId`) in accessible projects with `date_modification >= now − window` (open + closed). Pull `project_id, category_id, column_id, time_spent, time_estimated, is_active, date_completed, date_creation, title`. (Direct PicoDb query on `tasks`, mirroring `TimeBlockModel.php:100-117`'s join style; or `TaskFinderModel::getUserQuery($userId)` `TaskFinderModel.php:60` as a base.)
  - Tags: `TaskTagModel::getTagsByTaskIds(array $taskIds)` (`TaskTagModel.php:62`).
  - Activity / active-days: `project_activities` where `creator_id = $userId` and `date_creation >= cutoff` (there is NO built-in per-user getter — filter `creator_id` directly, `ProjectActivityModel.php:39`); derive distinct active-days + `event_name` counts.
  - Logged time: task `time_spent` + subtask `SubtaskTimeTrackingModel::getUserTimesheet($userId)` (`:113`).
  - Returns raw **dimensions**: `[{type:'project'|'category'|'tag', id, label, task_count, time_spent, time_estimated, completion_rate, active_days, sample_titles:[≤5]}]`. Labels via `ProjectModel::getList()`, `CategoryModel::getList()`, `TagModel`.
- Window default 90 days; user-adjustable (30/90/180/365).

## 2. Storage — `KenshoProfileModel` (per-user JSON in `user_metadata`)

Key `kensho_profile`; value = JSON string via `userMetadataModel->save($userId, ['kensho_profile'=>$json])` /
`get($userId,'kensho_profile','')` (the pattern ShadcnTheme uses, `MetadataModel::save/get`). **No DB migration.**

Schema (v1):
```
{
  "version": 1,
  "window_days": 90,
  "settings": { "show_score": true },
  "areas": [
    {
      "id": "area_health",            // slug, stable
      "label": "Health",
      "importance": 9,                // 1..10 (VLQ importance)
      "identity": "Someone who moves daily and rests well.",
      "goals": ["Run 3x/week"],
      "weight": 1.0,                  // optional score weighting (default 1)
      "sources": { "project_ids":[4], "category_ids":[12], "tag_ids":[3,9] }
    }
  ],
  "reflections": [                    // saved snapshots (append-only history)
    { "id":"r_...", "date":1783000000, "window_days":90, "score":62,
      "discrepancies":[ {"area_id":"area_health","importance_share":18.0,"actual_share":4.2,"discrepancy":13.8} ],
      "narrative":"…user-editable reflection text…", "provider_profile":"default" }
  ]
}
```
`KenshoProfileModel`: `getProfile($userId): array` (decode + defaults), `saveProfile($userId, array)`,
`upsertArea()/removeArea()`, `addReflection()`. Corrupt/absent JSON → empty default (defensive).

## 3. The three surfaces (one dashboard, `Template/dashboard/*`)

**Surface A — Areas & themes** *(ask: "identify areas, categories, things about us")*
Shows the §1 aggregated dimensions (your projects/categories/tags with effort + sample titles). The user
groups dimensions into **life areas**. If AiConnector ready: a "Propose areas" action sends the aggregate to
the LLM, which returns suggested groupings + rationale; **the user edits/confirms before anything saves**.
Without AI: manual grouping. Independently usable as a pure "here's where my effort actually goes" view.

**Surface B — Goals & desired identity** *(ask: "a section to explain our goals")*
Per area: an **importance** slider (1–10) + a short **desired-self statement** + optional goals. Optional
"Clarify with AI" turns a rough note into a crisper identity statement + concrete goals (proven pattern:
TimeAlign) — again edit-before-save. Fully usable without AI (just type).

**Surface C — Alignment reflection** *(ask: "do the things we do align with who we want to be?")*
The **discrepancy view** (qualitative, always available): per area, stated importance vs actual effort share,
with the gap surfaced plainly ("Health: you rate this 9/10; it's 4% of your logged effort"). Optional **0–100
alignment score** (opt-in via `settings.show_score`; §5), shown with a permanent, plain-language caveat that
it's a chosen proxy, not a verdict on you. If AiConnector ready: "Reflect" generates a **gentle narrative of
observations + questions** (never a verdict/score-judgment), which the user can edit and save as a snapshot.

## 4. AiConnector integration — `KenshoAiGate` + three `structured()` calls

Consume exactly like SubtaskGenerator. Instantiate `new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container)`;
**never** assume a container service (there is none). Gate mirrors `SubtaskGenerator/Model/AiGate.php:25-39`:
`KenshoAiGate::isEnabled()` = PHP ≥ 8.4 AND `class_exists(ProviderRegistry::class)` AND `(new ProviderRegistry($container))->isReady()`.
Provider pickable per-run via `$profileId` (seed the modal from `listProfiles()` + `getDefaultProfileId()`,
like `GeneratorController.php:53-59`). All calls blocking `ProviderRegistry::structured($messages, $schemaJson, $profileId): array` (`ProviderRegistry.php:286`); `$schemaJson = json_encode($schema)`.

1. **Propose areas** — input: aggregated dimension labels + signals + sample **titles only** (no descriptions/comments in v1). Output schema: `{ areas:[{ label, rationale, dimension_refs:[{type,id}], importance_hint? }] }`.
2. **Clarify goals** — input: area label + user's rough note. Output: `{ identity, goals:[string], reflection_question }`.
3. **Reflect** — input: computed discrepancies + the user's identity statements. Output: `{ observations:[string], questions:[string], gentle_note }` — **no** verdict/score/rating field by construction.

**System-prompt requirements (all three):** gentle, non-authoritative, second-person; explicitly instruct the
model to **challenge rather than flatter** (counter sycophancy), to treat low/absent activity as an ordinary
lapse not a failure, to avoid any claim about the user's "purpose" or worth, and to defer to the user's own
meaning. Reflect additionally: output questions the user answers themselves, not conclusions.

**Degradation:** when the gate is closed — hide every AI action, keep all manual flows + the discrepancy view +
optional score fully working. Every AI route re-checks the gate and throws `AccessForbiddenException` when closed
(mirror `GeneratorController.php:36-38`).

## 5. Alignment math (deterministic, transparent, unit-tested)

Per area, `effort` = Σ `time_spent` across its mapped dimensions (fallback to `task_count` when the user logs no
time — flagged in the UI). Include an implicit **"Unassigned"** bucket for effort not mapped to any area.
- `actual_share_i` = 100 × effort_i / Σ effort (percent; includes Unassigned).
- `importance_share_i` = 100 × (importance_i × weight_i) / Σ(importance × weight) over user areas (Unassigned
  importance = 0).
- `discrepancy_i` = importance_share_i − actual_share_i (positive = under-invested vs stated importance).
- **Optional composite score** (opt-in): `score = round(100 − 0.5 × Σ_i |importance_share_i − actual_share_i|)`,
  bounded [0,100] (this is 100 × the overlap between the "intended" and "actual" effort distributions). Shown
  only with its caveat. The formula is documented in-product so it's inspectable, not a black box.

Both distributions and the formula are the user's chosen proxy; the UI says so.

## 6. Controller, routes, permissions — `KenshoController`

All actions operate on `userSession->getId()`'s own data (personal; no project-admin gate; login required).
- `kensho` → `show` (GET): the dashboard.
- `kensho/analyze` (POST, CSRF): recompute §1 aggregate (or GET with reusable token).
- `kensho/areas` (POST, CSRF): save areas/groupings.
- `kensho/goals` (POST, CSRF): save importance/identity/goals.
- `kensho/propose` · `kensho/clarify` · `kensho/reflect` (POST, CSRF, **AI-gated**): the three AI calls.
- `kensho/reflection` (POST, CSRF): save an edited reflection snapshot.
- `checkCSRFForm()` on every mutation (`GeneratorController.php:97,178`). AI actions gate on `KenshoAiGate`.
- Entry point: a "Kensho" link in the header user dropdown (`template:header:dropdown`, like ShadcnTheme's
  theme toggle) and/or the user dashboard sidebar — the plan picks the exact hook.
- Live gotcha: reach config/action pages over curl via the query-string form
  (`?controller=KenshoController&action=show&plugin=Kensho`); clean multi-segment routes 404 over curl. Login:
  GET `/login` → post `csrf_token` to `/login/check` (admin/admin).

## 7. UI / assets (buildless, CSP-safe)

- Templates render the three surfaces; external `Assets/js` only, **no inline `<script>`** (CSP
  `default-src 'self'`); reusable-CSRF or form-CSRF for fetch endpoints. Escape ALL output.
- Style with ShadcnTheme's `kbx-*` primitives (`kbx-toolbar`, `kbx-btn`, `kbx-badge`, `kbx-modal`,
  `kbx-checkbox`) so it looks native under the theme and degrades sanely without it (the primitives ship
  hardcoded fallbacks). Ship a small plugin CSS for layout only.
- **Privacy affordance:** before any AI call, show a concise "what will be sent" summary (area labels,
  aggregated numbers, ≤5 sample task titles per dimension, your identity statements) and a provider picker;
  the call only fires on explicit confirm.

## 8. plugin.json & dependencies

- `name` "Kensho", `version` "1.0.0", `kanboard_version` ">=1.2.47", `php_version` ">=8.4",
  `homepage` "https://github.com/carmelosantana/kanboard-kensho", author/license per suite (MIT).
- `recommends`: `AiConnector >= 1.0.0` (reason: AI-assisted area proposal, goal clarification, and reflective
  narrative; Kensho works fully manually without it) and `ShadcnTheme >= 1.1.0` (reason: themed styling via the
  `kbx-*` primitives). **No `requires`.**

## 9. Non-goals (v1)

No team/cross-user analytics (personal + private). No authoritative "your purpose is X." No nagging /
notifications / cron. No new time-capture (uses existing signal). **Not** the four-circle ikigai Venn quiz. No
data egress beyond the user's chosen AI provider on an explicit action. No sending task descriptions/comments to
the LLM in v1 (titles + aggregates only). No DB migration.

## 10. Testing

- `KenshoDataModel::aggregate` — seed projects/tasks/categories/tags/time/activity → assert aggregates are
  window-filtered, access-filtered, and per-dimension correct.
- Alignment math (§5) — deterministic unit tests with known inputs (shares sum to 100; discrepancy signs;
  score bounds [0,100]; Unassigned handling; task_count fallback).
- `KenshoProfileModel` — user_metadata JSON round-trip; corrupt/absent → default; area upsert/remove; reflection append.
- `KenshoAiGate` — class_exists-false path (manual mode); the three `structured()` calls exercised with a
  **mocked/stubbed** ProviderRegistry asserting schema-shaped handling (NO network in unit tests).
- `KenshoController` — access (login), CSRF gates on every mutation, AI routes forbidden when gate closed.
- `PluginTest` — name, version 1.0.0, routes/hooks registered.
- **Task-0 spike (recommended):** prove end-to-end that `ProviderRegistry::structured()` returns a
  schema-shaped array for a trivial reflection prompt and that the gate degrades — before building surfaces
  (mirrors the CalendarPlugin FullCalendar spike).
- Live smoke on `:8081` (best-effort, shared stack): dashboard renders; create areas; set importance; see the
  discrepancy view + optional score; with a configured AiConnector provider, run one Reflect and confirm a
  gentle, non-verdict narrative comes back and saves.

## 11. Dev harness & release (orchestrator owns outward steps)

- New plugin: create `Kensho/`; **additively** wire the harness — append
  `- ../Kensho:/var/www/app/plugins/Kensho` to `testing/docker-compose.dev.yml` and add `Kensho` to BOTH lists
  in `testing/run-plugin-tests.sh` (append only; do not disturb other plugins' lines). Leave these two harness
  edits UNCOMMITTED — they belong to the demoted `kanboard-plugins` repo; the orchestrator commits them.
- `git init` `Kensho/` locally (branch `main`, no remote). The agent does NOT create a GitHub repo, push, tag,
  or release. On hand-back the **orchestrator** creates `kanboard-kensho` (+ CI per the repo-split runbook),
  releases v1.0.0, and adds the `kanboard-modmenu-directory` entry with its `recommends`.
