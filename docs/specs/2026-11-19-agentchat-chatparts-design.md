# AgentChat v1 and chatparts: project chat rooms with a knowledge-base bot

- **Status:** approved by Carmelo 2026-11-19
- **Deliverables:** two repos
  - `carmelosantana/chatparts`: a buildless, framework-agnostic kit of server-rendered chat/agent UI components (PHP view classes, CSS tokens, plain JS, htmx, a Lucide sprite). GitHub topics `htmx-agent-ui`, `htmx`, `php-agents`, `ai-chat`.
  - `carmelosantana/kanboard-agent-chat`: the Kanboard suite plugin `AgentChat` (namespace `Kanboard\Plugin\AgentChat`), which vendors chatparts.
- **Working folders:** `kanboard-plugins/AgentChat` (new) and `~/Projects/chatparts` (new, my call: outside the Kanboard harness because it is not a Kanboard plugin)
- **Prototype:** the "AgentChat interface" design canvas, https://claude.ai/artifact/AF1BtKMhJ9RDCFHKCmcioa (desktop room, phone, kit sheet, task drawer)
- **Research:** `reports/AI chat UI patterns for htmx.md` and its notes in `research_notes/AI chat UI patterns for htmx/` (AI SDK UI message model, AI Elements components, htmx + PHP streaming, Lucide vs Phosphor)
- **Earlier brief:** `~/MCP/kanboard-mcp/docs/integration/kanboard-chat-plugin-brief.md` (the agent-chat port). It becomes v2 of this plugin; see "Path to v2".

## Why

Carmelo runs projects with people and AI agents on the same Kanboard. Two needs meet here:

1. **A project chatbot for humans.** People on a project should be able to ask what was decided, what is
   left, or what a creator said in an interview, and get an answer grounded in the project's tasks,
   comments and transcripts (Save-it's Instagram archive first, other transcript agents later), with
   sources they can click.
2. **One room for everyone.** The kanboard-mcp agent chat lives outside Kanboard on Carmelo's box. It should
   move into Kanboard later, into the same rooms. So the room, message and UI model has to be shaped once,
   now, so agents (and more bots) join later without a rewrite.

Carmelo also wants the chat UI to become a **standard he keeps growing**: Alpaca Bot's chat came out well,
and its components should be abstracted into a reusable kit instead of being copied plugin by plugin. The
research found that no maintained buildless PHP + htmx agent UI kit exists; every PHP option is tied to
Laravel/Livewire/Filament or Symfony, and framework-agnostic kits are client-side JS widgets. chatparts
fills that gap, with php-agents as its reference backend.

## Goals, non-goals, anti-goals

- **Goals (v1)**
  - A **Chat** tab on every project: one room per project, where members post and the project bot answers
    when @-mentioned, streaming, with numbered citations, a sources row, a tool strip and a receipt.
  - An **"Ask the bot" drawer** on the task page: a private question scoped to that task (and optionally
    the whole project), with a "Share to room" button.
  - A **knowledge table and push API** so Save-it (and later other transcript agents) can put transcripts
    into a project; the bot searches it alongside tasks and comments.
  - **chatparts** as its own repo with the components AgentChat v1 uses, vendored into AgentChat by a
    hash-checked copy, and documented so Alpaca Bot can adopt it later.
  - A Claude-style **composer**: auto-growing box, a small controls bar, removable image thumbnails, a
    **context-budget ring** with a breakdown, send that turns into stop.
  - Hard **run limits** and **read-as-the-asker** security.
- **Non-goals (v1)**
  - The agent-coordination port (rings, tasks, re-rings, loop guard, sessions, captains, leases, wake,
    inbox) and retiring the Node chat server: that is v2. The Node chat keeps running meanwhile.
  - Team rooms and DMs (v2), unread badges across the app, notifications/email for chat.
  - Bot writes of any kind (creating tasks, commenting, moving cards).
  - Embeddings/vector search, web fetch, MCP tools for the bot.
  - Porting Alpaca Bot onto chatparts (a later, separate change in that repo).
  - Changes to Save-it itself (its push client is a follow-up in the save-it repo; v1 ships the API and a
    fixture importer to test it).
- **Anti-goals** (failure even if it "works")
  - The bot revealing or citing anything the asker cannot open in Kanboard.
  - A build step, bundler, npm install at release time, or a CDN dependency in either repo.
  - A reconnecting SSE stream that starts a second paid model run.
  - The plugin breaking or showing AI UI when AiConnector is absent or unconfigured.
  - chatparts components that know about Kanboard or WordPress.

## Decisions

| # | Decision | Settled |
|---|---|---|
| 1 | **Chatbot first.** One plugin. v1 = shared room/message model, the UI kit, project rooms and the bot as a participant. Agent port is v2 on the same tables. | Carmelo, 2026-11-19 |
| 2 | **Interface:** Alpaca Bot's look as the base (bubble grid, receipt, code blocks, greys, Kanboard blue accent); Claude-style composer: box grows with the message, small controls bar, send never stretches, images as removable thumbnails, context size shown with a breakdown (Coqui's "budgeting"). | Carmelo, 2026-11-19 (prototype review) |
| 3 | **Icons: Lucide**, shipped as a vendored same-origin SVG sprite (no CDN, no web font). | Carmelo, 2026-11-19 |
| 4 | **The kit lives in its own repo**, framework-agnostic; consumers vendor it as a hash-checked copy. | Carmelo, 2026-11-19 |
| 5 | **Name: chatparts**; `htmx-agent-ui` is a GitHub topic, not the name. | Carmelo, 2026-11-19 |
| 6 | **Save-it data lives in Kanboard's database**: an AgentChat knowledge table fed by a `knowledgeUpsert` JSON-RPC call; the bot queries the database through read-only search tools, never raw SQL. | Carmelo, 2026-11-19 |
| 7 | **Who may ask the bot:** project members and managers by default; each project can change it (managers only / members and managers / everyone incl. viewers). | Carmelo, 2026-11-19 |
| 8 | **Model to mirror:** Vercel AI SDK UI's message parts and AI Elements' component set and states; React is a reference only. | Carmelo, 2026-11-19 |
| 9 | **php-agents improvements** (stop mid-stream, streamed tool-call input, live usage) are explored separately; v1 works on php-agents v0.17.0 and picks them up when released. | Carmelo, 2026-11-19 (separate session) |

Everything else below marked **(my call)** is a proposal Carmelo can overturn.

## Approaches considered

**Scope of v1**

- **A. Chatbot first (chosen).** Builds the shared model, kit and bot; humans get value soon; the Node chat
  keeps working until v2. Risk: v2 has to fit the agent semantics onto these tables; the data model below
  keeps room kinds, sender kinds and a cursor id for exactly that.
- B. Agent port first: the brief as written. Biggest, most test-bound slice; humans see nothing new.
- C. Two plugins (AgentChat + ProjectBot): cleaner seams, but two repos and a hard `requires` for one UI.

**Where the kit lives**

- **A. Own repo, vendored (chosen).** One source of truth for Kanboard and WordPress consumers.
- B. A folder inside AgentChat: faster start, copies drift, WordPress can't share it.
- C. A Composer package: real versioning, but suite plugins commit `vendor/`, so it is A with more ceremony.

**Getting Save-it data in**

- **A. Knowledge table + push API (chosen).** Idempotent upserts, one door for every transcript agent.
- B. Save-it writes tasks/comments: floods the board.
- C. The bot reads `save-it.db` directly: only works on Carmelo's box; the hosted Kanboard can't see it.

**Streaming transport (my call)**

- **A. htmx 2 + `htmx-ext-sse`, two requests per bot turn (chosen):** a CSRF-checked POST stores the
  message and returns the bubbles; a GET with a single-use stream id opens the SSE stream; fragments are
  HTML with out-of-band swaps.
- B. `fetch` + `ReadableStream` hand-parsed (Alpaca Bot's way): works with custom headers, but more JS and
  not htmx-native.
- C. No streaming, poll for the finished answer: cheapest on PHP-FPM, but 10–60 s of nothing on screen.

## Architecture

```
Browser (Kanboard page)                         Kanboard (PHP-FPM)
  chatparts.js + htmx 2 + htmx-ext-sse            AgentChat plugin
  ├─ room poll   GET  /agentchat/poll  ─────────►  ├─ RoomController (poll, send, stop, context)
  ├─ send        POST /agentchat/send  ─────────►  ├─ StreamController (SSE)  ── BotRunner
  ├─ stream      GET  /agentchat/stream?sid ────►  │                              ├─ ChatAgent (AbstractAgent subclass)
  └─ stop        POST /agentchat/stop  ─────────►  │                              ├─ tools: search_tasks, get_task,
                                                    │                              │   search_comments, search_knowledge
                                                    │                              └─ AiConnector ProviderRegistry
                                                    ├─ JSON-RPC: knowledgeUpsert, knowledgeDelete,
                                                    │            chatSendMessage, chatRoomMessages
                                                    └─ vendor/chatparts (hash-checked copy)
```

- **Room updates** use htmx polling every 4 s (`hx-trigger="every 4s"`, paused when the tab is hidden), as
  Presence does. Cheap on FPM; no long-lived connection per viewer. **(my call)**
- **Bot replies** stream over SSE **only to the asker**. Other viewers see the bot bubble appear via the
  poll in a "answering…" state, then the finished message. **(my call)**
- **AI path** goes only through AiConnector: `ProviderRegistry::buildProvider($profileId)` gives a php-agents
  provider; AgentChat runs its own `AbstractAgent` subclass so it can pass `RunLimits`, `providerOptions`
  and a cancellation token (the convenience `Agent` class cannot).

### Prerequisite: AiConnector on php-agents ^0.17

AiConnector 1.1.6 bundles php-agents **0.16.1**. `RunLimits`, `ImageResolverInterface` and the new finish
reasons need **0.17.0**. Plan step 1 is an AiConnector release that bumps `carmelosantana/php-agents` to
`^0.17.0` (and its default Anthropic model), with its own tests. AgentChat declares
`recommends: [{plugin: "AiConnector", min_version: "<that release>"}]` and its gate also checks
`class_exists(\CarmeloSantana\PHPAgents\Agent\RunLimits::class)` (exact namespace confirmed from AiConnector's
vendor at build time).

## chatparts (the kit)

### What it is

A small set of PHP view classes, one CSS file, one JS file and an icon sprite, that render an AI-SDK-style
message model as HTML and stream it with htmx. No framework, no build, no runtime dependencies beyond PHP
8.4 (htmx and its SSE extension are vendored files). Hosts plug in through one adapter interface.

### Layout (my call)

```
chatparts/
├── src/                     PHP 8.4, namespace ChatParts\
│   ├── Model/               Message, Part (TextPart, ReasoningPart, ToolPart, SourcePart, FilePart, DataPart),
│   │                        Participant, MessageStatus, ToolState, FinishReason
│   ├── View/                Component base + Hx attribute writer (ported from Alpaca Bot), and the components below
│   ├── Stream/              SseWriter (headers, flush, heartbeat, OOB frames), StreamEvents
│   ├── Host/HostAdapter.php url(), csrfField(), escape(), markdown(), avatarFor(), t() — the only host seam
│   └── Markdown/SafeText.php minimal fallback renderer (escape, paragraphs, autolink)
├── assets/
│   ├── chatparts.css        tokens (--cp-*) + components, light/dark
│   ├── chatparts.js         composer, stick-to-bottom, stream glue, context popover, copy buttons (vanilla, CSP-safe)
│   ├── htmx.min.js          htmx 2.0.x (pinned, vendored)
│   ├── htmx-ext-sse.js      2.2.x (pinned, vendored)
│   └── icons.svg            Lucide <symbol> sprite (~30 icons)
├── scripts/
│   ├── build-icons.ts       zero-dependency Node-native TS: fetches pinned Lucide SVGs from the tagged GitHub
│   │                        release, verifies sha256 against icons.lock, writes icons.svg (committed output)
│   └── icons.lock           icon names, Lucide version, checksums
├── tests/                   PHPUnit render tests; node --test for the pure JS helpers
├── LICENSE (MIT) + THIRD_PARTY (Lucide ISC, htmx 0BSD)
└── README.md                how to vendor, the adapter, the component list, the stream protocol
```

### Message model (mirrors AI SDK `UIMessage`)

A message is a participant, a status and an **ordered list of typed parts**. Parts are what is stored and
what is rendered; the plain text is derived for search and previews.

| Part | Fields | States / notes |
|---|---|---|
| `text` | `id`, `text` | streaming → done |
| `reasoning` | `id`, `text`, `duration_ms` | collapsed `<details>` "Thought for N s" |
| `tool` | `id`, `name`, `input`, `output_summary`, `error` | `pending`, `running`, `done`, `error`, `denied` (approval states reserved for v2) |
| `source` | `id`, `n`, `title`, `url`, `kind` (`task`, `comment`, `knowledge`), `ref` | numbered; cited inline as `[n]` |
| `file` | `id`, `name`, `mime`, `url`, `thumb_url` | images shown as thumbnails |
| `data` | `id`, `type`, `payload` | host-defined (v2: ring/task badges) |

Message fields: `id`, `room`, `participant {kind: human|agent|bot|system, id, name}`, `status`
(`submitted`, `streaming`, `done`, `stopped`, `error`), `parts[]`, `metadata {model, usage {input, output,
reasoning, cache}, finish_reason, duration_ms, tool_count, asked_by}`, `created_at`.

### Components (v1 set)

| Component | Renders | Prototype artboard |
|---|---|---|
| `Conversation` | `role="log"` scroller, stick-to-bottom, "N new messages ↓" jump pill, empty state | Main |
| `MessageBubble` | avatar, meta row (name, kind badge, time), parts in order, actions (copy), `data-state` | Main, Kit |
| `Avatar` | initials with a fixed tone per id; shape by kind (round person, rounded-square agent, dark tile bot) | Kit |
| `Badge` / `Pill` | kind badge (Agent, Bot), session/role pills | Kit |
| `Response` | final text part via the host's markdown renderer; streaming text as escaped raw text with a caret | Kit |
| `Reasoning` | `<details>` with duration | Kit |
| `ToolStrip` | `<details>` summary ("Searched 3 sources · 2 tools") + call list; `data-state` per call | Kit |
| `InlineCitation` + `SourceList` | `[n]` chips and the numbered sources row; links only | Kit |
| `Receipt` | one muted line: bot · model · tools · "answered for X (Role)", or the stop reason in words | Kit |
| `Composer` | `<form>`; auto-grow textarea (`field-sizing: content` + JS fallback, max ~10 lines); thumbnails with ×; controls bar (+, context ring, send/stop); Enter sends, Shift+Enter newline, IME-safe; paste/drop images; Backspace in an empty box removes the last attachment; text kept until the send succeeds | Main, Kit |
| `ContextMeter` | SVG ring with % used + popover breakdown (instructions, history, tools, sources cap, message + images, reply reserve) | Main |
| `MentionPicker` | `@` list of bot, agents, people | Kit |
| `Notice` | info / warning / error line | Kit |
| `PresenceList` | grouped People / Agents / Bots rail | Main |
| `Drawer` | right-side panel shell (task drawer) | Drawer |
| `Icon` | `<svg aria-hidden><use href="…icons.svg#name"/></svg>`; the button carries the label | all |

Deferred to later kit releases: approval/confirmation cards, branch/regenerate, chain-of-thought, task
queue, plan, voice. Ring/task badges (`RingBadge`) and the session pills' actions ship with AgentChat v2.

### Stream protocol (HTML over SSE)

The server runs the reducer; the browser only swaps HTML. Events on one SSE stream per bot turn:

| SSE event | Data | Swap |
|---|---|---|
| `delta` | escaped text chunk | `beforeend` into the streaming text part |
| `reasoning` | escaped text chunk | `beforeend` into the reasoning part (opened while streaming) |
| `parts` | one or more OOB fragments (tool strip, status) | `hx-swap-oob` by id |
| `final` | the whole rendered bubble (markdown, citations, sources, receipt) | OOB `outerHTML` replace |
| `done` | empty | `sse-close="done"` closes the source |

- Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`; output
  buffers and `zlib.output_compression` off, `session_write_close()` before the run, a `:` heartbeat every
  15 s.
- **Reconnect trap:** the stream always ends with `final` + `done`. A used, unknown or finished stream id
  returns **204**, which stops EventSource reconnecting. A run is only ever started once per stream id.
- Accessibility: the bubble has `aria-busy="true"` while streaming; one visually hidden `role="status"`
  region announces "project-bot answered" on `final` (AI Elements has no live regions; this kit adds them).

### Theming and tokens

`--cp-*` custom properties on the chat root: surface, panel, text, muted, border, accent, accent-dark,
code-bg, code-text, radius, focus width. Defaults are Alpaca Bot's greys and Kanboard's blue; a host maps
its own tokens (ShadcnTheme can override them). Dark mode via `prefers-color-scheme` and a host class.
Kind is told apart by shape and label, never colour alone; all text meets 4.5:1.

### Distribution

Tagged releases (`vX.Y.Z`). Consumers copy `src/`, `assets/` and `LICENSE`/`THIRD_PARTY` into
`vendor-chatparts/` with a sync script that verifies a `CHECKSUMS` file from the tag (the same pattern as
Carmelo's marketplace skill sync). No Composer, no npm at runtime.

## AgentChat v1 (the plugin)

### Placement

- **Project Chat tab:** a project header/sidebar link (hook `template:project:dropdown` or the
  project-header hook, whichever puts it beside Board/List in this Kanboard version) to
  `/project/{id}/chat`. Layout as the Main artboard: thread + composer, and a right rail (People, Agents,
  Bots; open tasks and leases appear only in v2).
- **Task drawer:** an "Ask the bot" button on the task view (`template:task:show:top` or the sidebar hook)
  opens the Drawer: private Q&A, chips for "This task #N" and "Whole project", "Share to room" posts the
  question and answer into the project room.
  **(my call)** The drawer is a running private thread per user and task (messages with
  `visibility=private`, `private_to`, `task_id`), reopened where it left off. Its context history is that
  private thread only, never the public room, so other people's and agents' room posts cannot steer a
  private answer.
- Phone layout as the Mobile artboard; the rail moves behind a People button.

### Participants (my call)

- **People and agents are Kanboard users.** An agent is a user on the Agents plugin roster (Agents is a
  `recommends`; without it there is no Agent badge, everyone is a person).
- **The bot is not a Kanboard user.** It is a plugin-defined participant `bot:project` with no login, no
  token and no project role, so it can never act outside a run started by a person. Its reads always run as
  the asker.

### Data model (plugin tables, schema migrations for SQLite, MySQL, Postgres, as Presence does)

The suite usually avoids migrations, but chat history, runs and searchable knowledge need real tables and
indexes.

| Table | Columns (key ones) | Notes |
|---|---|---|
| `agentchat_rooms` | `id`, `kind` (`project`; v2 adds `team`, `dm`), `project_id` NULL, `name`, `created_at` | one `project` room per project, created on first use; membership is live from Kanboard permissions (no members table in v1) |
| `agentchat_messages` | `id` (autoincrement; also the poll cursor), `room_id`, `sender_kind` (`human`,`agent`,`bot`,`system`), `sender_id` (user id; NULL for bot/system), `visibility` (`room` or `private`), `private_to` (user id for drawer Q&A), `status`, `parts` (JSON text), `body_text` (derived plain text), `metadata` (JSON), `client_msg_id`, `reply_to`, `task_id` NULL, `created_at`, `updated_at` | `UNIQUE(room_id, sender_kind, sender_id, client_msg_id)`; index `(room_id, id)` |
| `agentchat_runs` | `id`, `message_id`, `room_id`, `asked_by`, `stream_token_hash`, `status` (`pending`,`running`,`done`,`stopped`,`error`), `stop_requested`, `started_at`, `finished_at`, `usage` (JSON) | single-use stream ids, stop flag, concurrency and rate counts |
| `agentchat_files` | `id`, `message_id`, `room_id`, `uploaded_by`, `name`, `mime`, `size`, `storage_key`, `thumb_key`, `created_at` | stored through Kanboard's ObjectStorage under `agentchat/`; served by a controller that checks room access |
| `agentchat_knowledge` | `id`, `project_id`, `source` (e.g. `save-it`), `external_id`, `title`, `body`, `url`, `author`, `occurred_at`, `metadata` (JSON), `created_at`, `updated_at` | `UNIQUE(project_id, source, external_id)` |
| full-text index on knowledge | SQLite: FTS5 external-content table + triggers (FTS5 is available in kb-suite's PHP 8.4.27); MySQL: InnoDB `FULLTEXT(title, body)`; Postgres: generated `tsvector` + GIN | v1 searches tasks and comments with bounded `LIKE` queries through Kanboard's models; only knowledge gets FTS **(my call)** |

Per-project settings live in `project_metadata` (`agentchat_bot_enabled`, `agentchat_ask_role`,
`agentchat_ai_profile`); global limits in Kanboard's config table under `agentchat_*`.

### The bot run

1. **POST `/agentchat/send`** (CSRF): validates room access and body (1–8000 chars, ≤ 4 images, each ≤ 5 MB,
   PNG/JPEG/WebP/GIF), stores the human message. If the text mentions `@bot` (or the request comes from the
   drawer) and the asker's project role is allowed and the gate is open: checks rate and concurrency
   limits, creates the bot message (`status=submitted`) and a run with a random stream id (only its hash is
   stored), and returns the human bubble plus an empty bot bubble wired with
   `hx-ext="sse" sse-connect="/agentchat/stream?sid=…" sse-close="done"`. Limits hit → a `Notice`, no run.
2. **GET `/agentchat/stream?sid=…`**: session cookie + matching unused sid for the same user, else 204.
   Marks the run `running`, builds the agent and streams events (above). Observer mapping from php-agents
   v0.17.0:

   | php-agents event | Stream |
   |---|---|
   | `agent.text_delta` | `delta` |
   | `agent.reasoning` | `reasoning` |
   | `agent.tool_call` | `parts` (ToolStrip row `running`) |
   | `agent.tool_result` / `agent.tool_error` | `parts` (row `done` / `error`; "Denied by policy:" → `denied`) |
   | `agent.budget_warning`, `agent.warning` | logged; no UI |
   | `run()` returns `Output` | `final` (content, sources, usage, finish reason) then `done` |

   Answers delivered only through the `done` tool or a reasoning fallback are not streamed as text; `final`
   always carries the full answer.
3. **POST `/agentchat/stop`** (CSRF): sets `stop_requested`; the agent's cancellation token reads it. On
   v0.17.0 it takes effect between iterations and before tool calls; the receipt says "stopped by you", the
   partial text is kept. A client that disconnects is treated as stop, and its partial reply is stored with
   `status=stopped`.

**The agent (my call on the prompt shape):** instructions name the project and the asker and state that
retrieved content is untrusted data, never instructions; answers must cite sources as `[n]` from tool
results only. Tools (all read-only, all parameterised, all filtered by the asker's access):

| Tool | Does |
|---|---|
| `search_tasks(query, status?, limit≤10)` | open and closed tasks of the room's project (drawer: task first) |
| `get_task(task_id)` | one task with description, subtasks and the last 20 comments, only if the asker can open it |
| `search_comments(query, limit≤10)` | comments in the project |
| `search_knowledge(query, source?, limit≤8)` | FTS over the project's knowledge documents |

Each tool result registers its items as candidate sources (title, URL to the task, comment anchor or
knowledge viewer); the bot's `[n]` markers are matched to them; unmatched markers are dropped; sources
the asker cannot open are never emitted.

**Images (my call):** images stay in the room for people in every case. They are sent to the bot through a
php-agents `ImageResolverInterface` over Kanboard's ObjectStorage only when the project setting
`agentchat_bot_images` is on (default on). With it off, or when the provider rejects image input, the
composer's thumbnails show "the bot won't see images" and the run continues on text alone.

**Context budget:** `ModelCatalog::getContextWindow($profile)` and `countTokens()`. Order of filling:
instructions, tool definitions, the drawer's task (if any), room history (last 40 messages, oldest dropped
first), retrieved sources (capped at 8 000 tokens), the new message + images, a 4 000-token reply reserve.
The `ContextMeter` shows these parts; `GET /agentchat/context?room=…&draft_len=…` returns its fragment.

### Security

- **Reads run as the asker.** Every tool call re-checks the asker's access with Kanboard's project
  permission model for the room's project. Admins gain nothing through the bot. Membership is the boundary
  (Kanboard has no per-task privacy).
- **Who may ask:** project members and managers by default; per-project setting (managers only / members and
  managers / everyone with access incl. viewers). Viewing the room needs only project access.
- **Who may post (my call):** anyone who can edit the project's tasks (members and managers, plus app
  admins) may post, upload images and call `chatSendMessage`; project viewers get the thread without a
  composer, mirroring Kanboard's read-only role. Posting is independent of the "who may ask" setting: a
  member outside it can talk, but `@bot` is not offered to them.
- **Private drawer answers** (`visibility=private`) are readable only by `private_to`; "Share to room"
  copies the latest question and answer as two new room messages.
- **Prompt injection:** retrieved text is fenced as untrusted data in the prompt; the bot has no write,
  network or send tools; rendered answers allow only same-origin images and links that Kanboard's markdown
  renderer allows (Kanboard's `Markdown` (Parsedown, safe mode) via the host adapter; it already links
  `#N` to tasks). Streaming text is escaped with `htmlspecialchars()` before `beforeend` swaps.
- **CSP:** no inline script; htmx configured through `<meta name="htmx-config">` with `allowEval:false`,
  `allowScriptTags:false`; all assets same-origin.
- **Knowledge API:** `knowledgeUpsert` / `knowledgeDelete` need an authenticated user (personal token) who
  is a manager of the target project or app admin; body ≤ 200 000 chars, metadata ≤ 16 KB JSON; idempotent
  on `(project_id, source, external_id)`. A dedicated Save-it Kanboard user with manager role on the archive
  project is the intended caller **(my call)**.
- **Keys** never leave AiConnector; AgentChat never reads or logs them.

### Run limits (defaults; all plugin settings)

| Limit | Default | Enforced by |
|---|---|---|
| Iterations | 6 | `maxIterations` |
| Tool calls | 8 per run, 4 per turn | `RunLimits::maxToolCallsPerRun/PerTurn` |
| Wall time | 90 s | `RunLimits::maxRunSeconds` |
| Response bytes | 256 KB | `RunLimits::maxResponseBytes` |
| Input tokens | model budget from `ModelCatalog` minus reply reserve | `RunLimits::maxInputTokens` |
| Concurrent answers | 1 per user, 4 per instance | `agentchat_runs` count before a run starts |
| Questions | 30 per user per hour | `agentchat_runs` count |

The instance limit must stay below `pm.max_children` (kb-suite dev: 5; Kanboard's docker default: 20),
because **each streaming answer holds one PHP-FPM worker** for its whole length. The settings page shows
this warning. Finish reasons map to plain words in the receipt (`BudgetExhausted` → "stopped: run budget
reached", `Refusal` → "the provider refused", `ContentFilter`, `ContextWindowExceeded`, `MaxIterations`,
`Error` → "couldn't answer", stop → "stopped by you").

### Agents in v1 (my call)

Two JSON-RPC procedures so agents can already read and post in a project room as themselves:
`chatSendMessage {project_id, body, client_msg_id?}` and `chatRoomMessages {project_id, after?, limit?}`.
No rings, tasks, sessions or wake: those stay in the Node chat until v2. Registered with
`getProcedureHandler()->withObject()` as the Agents plugin does. Agent posts can @mention the bot under the
same rules as people.

### Degrading

- AiConnector absent, unconfigured, too old, or php-agents < 0.17: the room works for people and agents;
  `@bot` is not offered in the picker; the bot rail entry and the drawer button are hidden; a `Notice`
  explains it to managers; the send/stream/stop/context routes for bot runs return 403.
- Agents plugin absent: no Agent badges. Presence plugin absent: no online dots.
- JS disabled: messages render and the composer posts as a plain form (no streaming; the bot answer
  appears on the next page load).

### Path to v2 (agent port)

v2 adds tables for rings, tasks, read cursors, sessions, per-project orchestrators and leases, room kinds
`team` and `dm`, the rest of the brief's JSON-RPC procedures, the loop guard and limits, and the overseer
features (presence with sessions, Make captain, open tasks, leases) in the rail, using chatparts'
`data` parts and a `RingBadge` component. The v1 tables already carry what v2 needs from them: room kinds,
sender kinds, an autoincrement cursor id, `client_msg_id` idempotency and JSON metadata for
`target_session` / `sender_session`.

## Constraints

- Buildless PHP 8.4 / vanilla JS / plain CSS; tag == `plugin.json` version == `getPluginVersion()`.
- `recommends` (array shape, bare versions): AiConnector (bot), Agents (badges), Presence (online dots).
  No `requires`.
- Edits on the host only; the :8081 dev stack (kb-suite, SQLite, admin/admin) for E2E. The Kanboard MCP is
  production and is never used for changes.
- TDD, small commits, no AI attribution; supply-chain review before vendoring htmx, the SSE extension or
  Lucide files.

## Risks to retire first (plan step 2, a spike)

1. **Streaming through Kanboard:** a plugin controller that sends SSE, flushes, and exits without
   Kanboard's Response buffering it (unverified).
2. **OOB swaps inside SSE frames** with htmx 2.0.x + `htmx-ext-sse` 2.2.x (confirmed by reading the 2.0.4
   source only).
3. **nginx / PHP-FPM buffering** in kb-suite and the production stack.
4. **Worker cost:** measure how many concurrent streamed answers the dev pool sustains.

If 1 or 2 fails, fall back to transport B (fetch + ReadableStream, chatparts' own small parser).

**Production (settled with Carmelo, 2026-11-19):** Carmelo can tune production and applies changes
himself; the builder never touches production. From his compose file, production is the stock
`kanboard/kanboard:v1.2.55` image (nginx + PHP-FPM 8.4.27 in one container) on **MariaDB**, published on
port 32048 behind an outer proxy that terminates TLS. The upstream image's config (read from the
harness's Kanboard source, `docker/etc/`) already suits SSE:

- `pm.max_children = 20`, so the instance default of 4 concurrent answers leaves 16 workers for the board.
- nginx honours the `X-Accel-Buffering: no` header the stream sends (no `fastcgi_ignore_headers`), and
  `gzip_types` does not include `text/event-stream`, so the container needs **no change**.
- `fastcgi_read_timeout` is nginx's default 60 s; the 15 s heartbeat keeps the stream inside it.

What can still buffer is the **outer proxy** in front of port 32048. AgentChat's README ships snippets for
nginx (`proxy_buffering off; proxy_read_timeout 120s;` on `/agentchat/stream`), Traefik and Cloudflare,
and Carmelo verifies streaming on production after install. MariaDB uses the MySQL schema (InnoDB
`FULLTEXT` works there); the plan's MySQL migration test runs against MariaDB to match.

## What "done" means (my call)

Tagged releases of AiConnector (php-agents ^0.17), chatparts (with `CHECKSUMS`) and AgentChat, in that
order, each pushed only after Carmelo's go-ahead at that point; AgentChat listed in the ModMenu directory.
Until chatparts is tagged, AgentChat vendors it from the local checkout with the same sync script, then
re-syncs from the tag before its own release. Installing on production is Carmelo's step.

## Left to the builder

- Exact Kanboard hooks for the project tab and task button in 1.2.47 (dev) and 1.2.55 (production), and the route names.
- PHP class names inside both repos beyond those named here; the exact Lucide icon list (≈30).
- The knowledge viewer page (a simple read-only page per document so sources have a URL).
- Wording of notices and receipts beyond the ones listed; translations go through `t()`.
- Test fixtures: a fake provider for runs; a Save-it-shaped fixture importer for `knowledgeUpsert`.

## Amendments (2026-11-19, during implementation)

These rulings from the build ledger change the text above. Where they disagree, the amendment wins.

- **Ruling 9: a refused stream answers 200 + `done`, not 204.** A used, unknown, finished or foreign
  stream id gets HTTP 200 with a single `event: done` (empty data). This replaces "204" in the stream
  protocol's reconnect trap and in step 2 of the bot run. Why: a spike showed htmx-ext-sse 2.2.4
  reconnects on any closed source, 204 included. chatparts.js also drops `sse-connect` on
  `htmx:sseError`. The intent stands: no reconnect loop, never a second run.
- **Ruling 57: the stream returns 403 only without AiConnector.** `/agentchat/stream` answers 403 only
  when AiConnector is absent or too old; then no SSE element is ever rendered, so nothing reconnects.
  An unconfigured AiConnector or a project with its bot off gets 200 + `done` as in ruling 9. Why: it
  keeps the 403 that tests expect without breaking the no-reconnect rule.
- **Ruling 58: degrading keeps send, stop and context working.** This replaces "the send/stream/stop/
  context routes for bot runs return 403" in Degrading. Without AiConnector, send stores a plain people
  message (no run), context answers 204 (no ring), stop has nothing to stop, and only stream answers 403
  (ruling 57). Why: people chat must keep working when AI is absent, and a 403 on send would break it.
- **Ruling 61: asking is independent of posting.** Decision 7 ("everyone incl. viewers") is binding:
  asking needs the project's ask role and the AI gate, not post rights. A viewer under "everyone" asks in
  the task drawer (a private thread, text only, since image upload stays with posters) but still cannot
  post in the room, so `@bot` in the room stays with members and managers. Why: the earlier code required
  post rights and so silently dropped viewers from "everyone".
