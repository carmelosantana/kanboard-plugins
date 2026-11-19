# AgentChat v1 + chatparts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship project chat rooms in Kanboard where members talk and an @-mentioned project bot answers from tasks, comments and pushed transcripts, built on a new reusable buildless kit, chatparts.

**Architecture:** chatparts (own repo, `~/Projects/chatparts`, namespace `ChatParts\`) holds the message-parts model, PHP view components, an HTML-over-SSE stream writer, CSS tokens, vanilla JS and a Lucide sprite; it knows nothing about Kanboard. AgentChat (`kanboard-plugins/AgentChat`, namespace `Kanboard\Plugin\AgentChat`) vendors chatparts by hash-checked copy, owns the tables, rooms, permissions, knowledge store, JSON-RPC and the bot run, and reaches AI only through AiConnector's `ProviderRegistry` plus its own php-agents `AbstractAgent` subclass.

**Tech Stack:** PHP 8.4, Kanboard 1.2.47 (dev) / 1.2.55 (prod), SQLite / MySQL(MariaDB) / Postgres, htmx 2.0.x + htmx-ext-sse 2.2.x (vendored), Lucide (vendored sprite), carmelosantana/php-agents ^0.17.0 via AiConnector, PHPUnit (harness), `node --test` for pure JS.

**Spec:** `docs/specs/2026-11-19-agentchat-chatparts-design.md` (approved 2026-11-19). Executors read it alongside this plan; section names below refer to it.

## Global Constraints

- Buildless: no bundler, no npm/composer step at release or runtime in chatparts or AgentChat; what is committed ships.
- PHP ≥ 8.4; Kanboard `>=1.2.47`; tag `vX.Y.Z` == `plugin.json` `version` == `getPluginVersion()`.
- `recommends` only, array shape, bare versions: AiConnector (bot), Agents (badges), Presence (online dots). No `requires`.
- AI degrades to nothing: AiConnector absent/unconfigured/too old or php-agents < 0.17 → no bot UI, bot routes 403, the room still works.
- CSP: no inline `<script>`, no eval; htmx via `<meta name="htmx-config" content='{"allowEval":false,"allowScriptTags":false}'>`; all assets same-origin.
- chatparts code never references Kanboard or WordPress; every host need goes through `ChatParts\Host\HostAdapter`.
- Message body 1–8000 characters (`mb_strlen` after trim); ≤ 4 images per message, each ≤ 5 MB, PNG/JPEG/WebP/GIF.
- Run limits (defaults, all settings): 6 iterations; 8 tool calls/run, 4/turn; 90 s; 256 KB response bytes; input tokens = ModelCatalog budget − 4 000 reply reserve; 1 concurrent answer per user, 4 per instance; 30 questions per user per hour.
- Context fill order: instructions, tool definitions, drawer task, history (last 40, oldest dropped first), sources (cap 8 000 tokens), new message + images, 4 000 reply reserve.
- Knowledge: `UNIQUE(project_id, source, external_id)`; body ≤ 200 000 chars; metadata ≤ 16 KB JSON; writes by project manager or app admin only.
- Who may ask (project setting `agentchat_ask_role`): `managers` | `members` (default: members and managers) | `everyone`. Who may post: members, managers, app admins; viewers read only.
- Bot is participant `bot:project`, never a Kanboard user; every read runs as the asker.
- Dev stack only (:8081, `kb-suite`, admin/admin). Never use the Kanboard MCP (production) for changes. Edit on the host, never inside the container.
- TDD, small commits, no AI attribution in commits. Run `/powerup:supply-chain` before vendoring htmx, the SSE extension or Lucide files.
- Pushing, tagging, creating GitHub repos and ModMenu listing each wait for Carmelo's go-ahead at that point.

## Review Focus

1. **Room IDOR:** a logged-in user who is not in project B calls `/agentchat/poll?room=<B's room>` or `/agentchat/send` for it → 403, no data. Test in Task 11 (`AccessPolicyTest::testOutsiderCannotViewOrPost`) and Task 12 (`RoomControllerTest::testPollForeignRoomIsForbidden`).
2. **Cross-project read through a tool:** the model calls `get_task` with a task id from a project the asker can't open → tool result "not found", no source registered. Test in Task 15 (`ToolsTest::testGetTaskOutsideProjectIsNotFound`).
3. **Stream replay:** the same stream id requested twice, after `done`, or by another user → 204 and the provider is called exactly once. Test in Task 17 (`StreamControllerTest::testReusedSidReturns204AndNoSecondRun`).
4. **Hostile content:** a message body or knowledge body with `<script>`, `<img src=https://evil/?q=…>` or `javascript:` links → escaped while streaming; external images and unsafe links absent in the final render. Tests in Task 5 (`ResponseTest::testStreamingTextIsEscaped`) and Task 16 (`BotRunnerTest::testFinalRenderDropsExternalImages`).
5. **Length and encoding edges:** 8 000 emoji accepted, 8 001 rejected, whitespace-only rejected, a resubmit with the same `client_msg_id` stores one message. Test in Task 11 (`MessageModelTest::testBodyLimitsCountCharacters`, `testClientMsgIdIsIdempotent`).

---

## Phase 0 — Prerequisite

### Task 1: AiConnector on php-agents ^0.17.0

**Files:**
- Modify: `AiConnector/composer.json` (`"carmelosantana/php-agents": "^0.17.0"`), `AiConnector/composer.lock`, `AiConnector/vendor/**` (regenerated, committed)
- Modify: `AiConnector/plugin.json`, `AiConnector/Plugin.php` (version `1.2.0`), `AiConnector/CHANGELOG.md`
- Modify: default Anthropic model wherever `claude-sonnet-4-20250514` is set → `claude-sonnet-5-5`
- Test: `AiConnector/Test/PhpAgentsVersionTest.php`

**Interfaces:**
- Produces: AiConnector `1.2.0` exposing `\CarmeloSantana\PHPAgents\Agent\RunLimits`, `AbstractAgent` (v0.17 constructor with `$providerOptions`, `?RunLimits $runLimits`, cancellation token), `ProviderRegistry::buildProvider(?string $profileId)`, `ModelCatalog::getContextWindow(string $profileId): int`, `ModelCatalog::countTokens(string $text, string $profileId): int`.

- [ ] **Step 1:** `git -C AiConnector fetch --tags` (local mains lag origin); confirm `1.2.0` is not taken.
- [ ] **Step 2: Failing test** `PhpAgentsVersionTest::testBundlesRunLimits` asserts `class_exists(\CarmeloSantana\PHPAgents\Agent\RunLimits::class)` and `testDefaultAnthropicModel` asserts the default is `claude-sonnet-5-5`.
- [ ] **Step 3:** Run `./testing/run-plugin-tests.sh AiConnector` → FAIL (class missing).
- [ ] **Step 4:** Run `/powerup:supply-chain` for the bump, then `composer update carmelosantana/php-agents -d AiConnector`; update the model default; bump versions to `1.2.0`.
- [ ] **Step 5:** Run `./testing/run-plugin-tests.sh AiConnector` → all PASS; reload :8081 and run AiConnector's Test Connection once.
- [ ] **Step 6: Commit** in the AiConnector repo: `feat: php-agents 0.17 (RunLimits, image resolvers), default Sonnet 5.5`. Tag/release only on Carmelo's go-ahead.

---

## Phase 1 — Retire the transport risks

### Task 2: Streaming spike (throwaway)

**Files:**
- Create (throwaway branch `spike/sse` in a scratch plugin dir `kanboard-plugins/AgentChatSpike/`, deleted after): `Plugin.php`, `Controller/SpikeController.php`, `Template/spike.php`, `Assets/htmx.min.js`, `Assets/htmx-ext-sse.js`
- Create (kept): `/home/carmelo/.config/Claude/side-session-notes/local_cd65249b-67bf-4fed-beda-19acba12926e/agentchat-sse-spike.md`

- [ ] **Step 1:** Supply-chain check, then download pinned htmx `2.0.x` (latest 2.0 patch) and `htmx-ext-sse` `2.2.x` from the npm tarballs; record versions and sha256 in the notes file.
- [ ] **Step 2:** `SpikeController::stream()` writes headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`), ends all output buffers, `session_write_close()`, emits 20 `event: delta` frames 200 ms apart, one `event: parts` frame holding `<div id="spike-status" hx-swap-oob="true">done-oob</div>`, then `event: done`, then `exit`.
- [ ] **Step 3:** Page with `hx-ext="sse" sse-connect="/spike/stream" sse-close="done"` and `sse-swap="delta" hx-swap="beforeend"`; open on :8081.
- [ ] **Step 4: Verify** in the browser pane: text arrives incrementally (timestamps in a console log show ≥ 15 distinct arrival times), `#spike-status` reads `done-oob`, the EventSource closes, no CSP errors in the console, and a second request to a stream that returns 204 does not reconnect.
- [ ] **Step 5:** Measure: open 5 concurrent spike streams against kb-suite (`pm.max_children = 5`) and record whether a sixth normal page load stalls.
- [ ] **Step 6:** Write findings (pass/fail per spec risk 1–4, versions, sha256) to the notes file. If risk 1 or 2 fails, stop and report: Tasks 7 and 17 switch to transport B. Delete the spike dir; nothing is committed to any repo.

---

## Phase 2 — chatparts

All chatparts tests run with: `~/Projects/Kanboard/kanboard-plugins/testing/kanboard-src/vendor/bin/phpunit -c ~/Projects/chatparts/phpunit.xml` and `node --test ~/Projects/chatparts/tests/js/`.

### Task 3: Repo scaffold and message model

**Files:**
- Create: `~/Projects/chatparts/{README.md,LICENSE,THIRD_PARTY.md,phpunit.xml,autoload.php,.gitignore}`
- Create: `src/Model/{Message.php,Participant.php,MessageStatus.php,ToolState.php,FinishReason.php}`
- Create: `src/Model/Part/{Part.php,TextPart.php,ReasoningPart.php,ToolPart.php,SourcePart.php,FilePart.php,DataPart.php}`
- Test: `tests/Model/MessageTest.php`

**Interfaces:**
- Produces:
  - `enum MessageStatus: string { Submitted='submitted'; Streaming='streaming'; Done='done'; Stopped='stopped'; Error='error'; }`
  - `enum ToolState: string { Pending='pending'; Running='running'; Done='done'; Error='error'; Denied='denied'; }`
  - `enum FinishReason: string { Stop, Stopped, BudgetExhausted, Refusal, ContentFilter, ContextWindowExceeded, MaxIterations, Error }` with `public function receiptText(): string`
  - `final readonly class Participant(string $kind /* human|agent|bot|system */, ?string $id, string $name)`
  - `abstract class Part { public string $id; abstract public function type(): string; abstract public function toArray(): array; public static function fromArray(array $a): Part; }`
  - `TextPart(id, text)`, `ReasoningPart(id, text, ?int durationMs)`, `ToolPart(id, name, array input, ToolState state, ?string outputSummary, ?string error)`, `SourcePart(id, int n, title, url, kind /* task|comment|knowledge */, ref)`, `FilePart(id, name, mime, url, ?thumbUrl)`, `DataPart(id, type, array payload)`
  - `final class Message(id, string room, Participant participant, MessageStatus status, Part[] parts, array metadata, int createdAt)` with `toArray(): array`, `static fromArray(array): self`, `plainText(): string` (text parts joined), `withPart(Part): self`, `replacePart(Part): self` (by id).

- [ ] **Step 1: Failing tests:** `testRoundTripPreservesPartOrderAndTypes` (all six part kinds survive `toArray`/`fromArray` in order); `testPlainTextJoinsTextPartsOnly` (reasoning and tool text excluded); `testReplacePartById`; `testUnknownPartTypeThrows` (`InvalidArgumentException`); `testReceiptText` (`BudgetExhausted` → `"stopped: run budget reached"`, `Refusal` → `"the provider refused"`, `Stopped` → `"stopped by you"`, `Error` → `"couldn't answer"`).
- [ ] **Step 2:** Run phpunit → FAIL.
- [ ] **Step 3:** Implement the classes; `autoload.php` is a PSR-4 `spl_autoload_register` for `ChatParts\` → `src/`.
- [ ] **Step 4:** Run phpunit → PASS.
- [ ] **Step 5:** `git init`, commit `feat: message parts model`.

### Task 4: View base, host seam, icons

**Files:**
- Create: `src/View/Component.php`, `src/View/Hx.php` (port of Alpaca Bot `src/View/Component.php`, `src/View/Hx.php` minus WordPress calls), `src/View/Icon.php`, `src/Host/HostAdapter.php`, `src/Markdown/SafeText.php`
- Create: `scripts/build-icons.ts`, `scripts/icons.lock`, `assets/icons.svg` (generated, committed)
- Test: `tests/View/HxTest.php`, `tests/View/IconTest.php`, `tests/Markdown/SafeTextTest.php`, `tests/js/build-icons.test.mjs`

**Interfaces:**
- Produces:
  - `interface HostAdapter { url(string $route, array $params = []): string; csrfField(): string; escape(string $s): string; markdown(string $text): string; avatarFor(Participant $p): array /* {initials, tone} */; t(string $s, mixed ...$args): string; assetUrl(string $file): string; }`
  - `abstract class Component { __construct(HostAdapter $host); abstract render(): string; __toString(): string; protected tag(string $name, array $attrs, string $inner = ''): string /* null attr dropped */ }`
  - `final class Hx { static attrs(array $hx): array }` — closed allowlist (`get, post, target, swap, trigger, ext, sse-connect, sse-swap, sse-close, vals, headers, swap-oob, indicator, disabled-elt`); unknown key → `InvalidArgumentException`.
  - `final class Icon extends Component { __construct(HostAdapter $host, string $name, string $class = 'cp-icon') }` → `<svg class="cp-icon" aria-hidden="true" focusable="false"><use href="{assetUrl('icons.svg')}#{name}"/></svg>`; unknown name → exception (names from `icons.lock`).
  - `final class SafeText { static render(string $text): string }` — escape, paragraphs, autolink `https?://` only.
- Icon set (Lucide names): `arrow-up, square, plus, paperclip, image, x, copy, check, pencil, bot, user, users, search, wrench, brain, book-open, quote, triangle-alert, bell-ring, lock, message-circle, mic, gauge, chevron-down, chevron-right, loader-circle, arrow-down, circle-check, circle-x, share-2, info`.

- [ ] **Step 1: Failing tests:** `HxTest::testRejectsUnknownAttribute`, `testJsonEncodesValsAndHeaders`; `IconTest::testRendersUseWithAriaHidden`, `testUnknownIconThrows`; `SafeTextTest::testEscapesHtml` (`<script>` → `&lt;script&gt;`), `testAutolinksOnlyHttp` (`javascript:alert(1)` not linked); `build-icons.test.mjs`: `checksum mismatch aborts` and `sprite contains one <symbol id> per lock entry`.
- [ ] **Step 2:** Run both test commands → FAIL.
- [ ] **Step 3:** Implement PHP classes. `build-icons.ts` (Node-native TS, zero deps, run with `node --experimental-strip-types`): reads `icons.lock` (`{"lucide": "<version>", "icons": {"name": "sha256", …}}`), fetches each SVG from `https://raw.githubusercontent.com/lucide-icons/lucide/<version>/icons/<name>.svg`, verifies sha256, wraps each as `<symbol id="name" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">…</symbol>`, writes `assets/icons.svg`. Supply-chain check first; pin the current Lucide release tag.
- [ ] **Step 4:** Run the script once, then both test commands → PASS.
- [ ] **Step 5: Commit** `feat: view base, host adapter, Lucide sprite`.

### Task 5: Message components

**Files:**
- Create: `src/View/{Avatar,Badge,MessageBubble,Response,Reasoning,ToolStrip,InlineCitation,SourceList,Receipt,Notice}.php`
- Test: `tests/View/MessageBubbleTest.php`, `tests/View/ResponseTest.php`, `tests/View/ToolStripTest.php`, `tests/View/SourcesTest.php`, `tests/Support/FakeHost.php`

**Interfaces:**
- Consumes: Task 3 model, Task 4 `Component`, `HostAdapter`, `Icon`.
- Produces (all `extends Component`, `render(): string`):
  - `Avatar(host, Participant)` — class `cp-avatar cp-avatar--{kind}`; bot renders the `bot` icon.
  - `MessageBubble(host, Message, bool $streaming = false)` — `<article id="cp-msg-{id}" class="cp-msg cp-msg--{kind}" data-state="{status}" aria-busy="{streaming}">`; children in part order; text part container id `cp-text-{partId}`; reasoning container id `cp-reasoning-{partId}`; tool strip id `cp-tools-{messageId}`.
  - `Response(host, TextPart, bool $streaming)` — streaming: `htmlspecialchars` raw text with class `cp-caret`; done: `host->markdown()` then citation rewrite `[n]` → `InlineCitation`.
  - `ToolStrip(host, string $messageId, ToolPart[] $tools)` — `<details>` summary "Searched N sources · M tools" (N = sources count passed via metadata), one `<li data-state>` per call.
  - `SourceList(host, SourcePart[])`, `InlineCitation(host, int $n, ?SourcePart)` — a marker with no matching source renders nothing.
  - `Receipt(host, array $metadata)` — `"{bot} · {model} · {n} tools · answered for {asker} ({role})"` or `FinishReason::receiptText()`.
  - `Notice(host, string $level /* info|warning|error */, string $text)`.

- [ ] **Step 1: Failing tests:** `ResponseTest::testStreamingTextIsEscaped` (`<img src=x onerror=1>` appears escaped); `testFinalUsesHostMarkdownAndRewritesCitations` (`FakeHost::markdown` wraps in `<p>`; `[1]` with a SourcePart n=1 becomes `<a class="cp-cite" href="{url}">1</a>`; `[9]` with no source is removed); `MessageBubbleTest::testPartsRenderInOrder`, `testBotBubbleHasBadgeAndDarkTile`, `testStreamingSetsAriaBusy`; `ToolStripTest::testStatesBecomeDataState`; `SourcesTest::testOnlyGivenSourcesRender`.
- [ ] **Step 2:** phpunit → FAIL.
- [ ] **Step 3:** Implement, porting markup and class names from Alpaca Bot's `src/View/Chat/{MessageBubble,Receipt,Notice}.php` with the `cp-` prefix.
- [ ] **Step 4:** phpunit → PASS.
- [ ] **Step 5: Commit** `feat: message components`.

### Task 6: Shell components

**Files:**
- Create: `src/View/{Conversation,Composer,ContextMeter,MentionPicker,PresenceList,Drawer}.php`
- Test: `tests/View/ComposerTest.php`, `tests/View/ContextMeterTest.php`, `tests/View/ConversationTest.php`

**Interfaces:**
- Produces:
  - `Conversation(host, Message[] $messages, string $pollUrl, int $afterId)` — `<div class="cp-conversation" role="log" aria-live="off">` with `hx-get="{pollUrl}?after={afterId}" hx-trigger="every 4s [document.visibilityState=='visible']" hx-swap="beforeend"`, a jump pill `#cp-jump` and `<div class="cp-sr" role="status" id="cp-announce"></div>`.
  - `Composer(host, string $action, array $opts)` — `$opts`: `placeholder`, `room`, `allowImages` (bool), `canAsk` (bool), `contextUrl`, `stopUrl`; renders `<form class="cp-box">` with `csrfField()`, hidden `client_msg_id`, textarea `name="message"`, thumbs container `#cp-thumbs`, file input `name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden`, controls bar (+, context ring button with `hx-get={contextUrl}`, send button `data-state="ready"`).
  - `ContextMeter(host, array $segments, int $window)` — `$segments`: `[['key','label','tokens'], …]`; renders ring (`stroke-dasharray` = `pct*2πr` of `2πr`, r = 7) and the popover rows; `pct` rounded to whole percent in the label.
  - `MentionPicker(host, array $entries)`; `PresenceList(host, array $groups /* people, agents, bots */)`; `Drawer(host, string $title, string $subtitle, string $body)`.

- [ ] **Step 1: Failing tests:** `ComposerTest::testHasCsrfAndClientMsgId`, `testNoAtBotHintWhenCannotAsk`, `testImageInputHiddenWhenImagesNotAllowed`; `ContextMeterTest::testRingDashFromSegments` (window 200 000, segments summing 21 100 → label `11%`, dash `5.2 44.0`), `testZeroTokenSegmentsHidden`; `ConversationTest::testPollUrlCarriesCursor`.
- [ ] **Step 2:** phpunit → FAIL. **Step 3:** implement. **Step 4:** phpunit → PASS.
- [ ] **Step 5: Commit** `feat: conversation, composer, context meter, rail, drawer`.

### Task 7: HTML-over-SSE stream writer

**Files:**
- Create: `src/Stream/SseWriter.php`, `src/Stream/StreamEvents.php`
- Test: `tests/Stream/SseWriterTest.php`

**Interfaces:**
- Produces:
  - `final class SseWriter { __construct(callable $out = null /* defaults to echo+flush */, callable $clock = null); open(): void /* headers, ob_end_flush loop, zlib off */; event(string $name, string $html): void; heartbeat(): void /* ": hb\n\n" */; maybeHeartbeat(int $everySeconds = 15): void; close(): void /* writes final 'done' */ }`
  - `final class StreamEvents { const DELTA='delta', REASONING='reasoning', PARTS='parts', FINAL='final', DONE='done'; static oob(string $html, string $mode = 'true'): string /* injects hx-swap-oob on the root element */ }`
  - Multi-line HTML is framed as one `data:` line per line.
- If Task 2 recorded a failure for risk 2, this task instead produces `assets/chatparts-stream.js` (fetch + ReadableStream parser of the same events) and `SseWriter` stays the same.

- [ ] **Step 1: Failing tests:** `testMultilineHtmlIsFramedPerLine`; `testCloseAlwaysSendsDone`; `testHeartbeatAfterFifteenSeconds` (fake clock); `testOobInjectsAttributeOnRoot`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: SSE stream writer`.

### Task 8: CSS tokens and client JS

**Files:**
- Create: `assets/chatparts.css`, `assets/chatparts.js`, `assets/htmx.min.js`, `assets/htmx-ext-sse.js` (versions/sha256 from Task 2 notes)
- Create: `src/js-lib/` is not used; pure helpers live at the top of `chatparts.js` and are exported for tests via `if (typeof module !== 'undefined') module.exports = {...}`
- Test: `tests/js/composer.test.mjs`, `tests/js/scroll.test.mjs`

**Interfaces:**
- Produces in `chatparts.js` (pure, tested): `rowsFor(text: string, maxRows = 10, wrap = 90): number`; `shouldSubmit(e: {key, shiftKey, isComposing}, disabled: boolean): boolean`; `isNearBottom(scrollTop, clientHeight, scrollHeight, threshold = 48): boolean`; `nextAttachments(list, action)` (`add` respects max 4 and 5 MB and the four MIME types; `removeLast`; `remove(id)`).
- DOM wiring (delegated, `htmx.onLoad`): auto-grow, Enter/Shift+Enter/IME, paste and drop images into thumbnails, Backspace-on-empty removes the last thumbnail, send→stop swap while `[data-state=streaming]`, stick-to-bottom with `#cp-jump`, announce `"{name} answered"` into `#cp-announce` on `sse:final`, copy buttons, context popover toggle.
- CSS: `--cp-surface:#f0f0f1; --cp-panel:#fff; --cp-text:#1d2327; --cp-muted:#646970; --cp-border:#c3c4c7; --cp-accent:#2271b1; --cp-accent-dark:#135e96; --cp-code-bg:#1d2327; --cp-code-text:#f0f0f1; --cp-radius:16px;` plus a dark set under `prefers-color-scheme: dark` and `.cp-dark`; component styles follow the prototype canvas (Main and Components artboards); `field-sizing: content` on the textarea.

- [ ] **Step 1: Failing tests:** `rowsFor('', …) === 1`, `rowsFor('a\n'.repeat(30)) === 10`; `shouldSubmit` false when `isComposing` or `shiftKey` or disabled; `isNearBottom` boundary at threshold; `nextAttachments` rejects a 6th image, a 5 MB + 1 byte image and `image/svg+xml`.
- [ ] **Step 2–4:** `node --test tests/js/` FAIL → implement → PASS.
- [ ] **Step 5:** Visual check: a static `examples/kit.html` (committed, uses only the assets) renders every component state; screenshot it in the browser pane, light and dark, 1440 and 390 wide.
- [ ] **Step 6: Commit** `feat: tokens, styles and client behaviour`.

### Task 9: Vendoring and release tooling

**Files:**
- Create: `scripts/checksums.sh` (writes `CHECKSUMS`: sha256 of every file under `src/`, `assets/`, `LICENSE`, `THIRD_PARTY.md`), `scripts/sync-into.sh <target-dir>` (copies those paths into `<target>/vendor-chatparts/` and verifies against `CHECKSUMS`; exits 1 on mismatch), `.github/workflows/release.yml` (on tag: verify `CHECKSUMS` is current, attach a zip)
- Modify: `README.md` (install by sync, the `HostAdapter` contract, component table, stream protocol table, theming tokens)
- Test: `tests/js/sync.test.mjs` (runs the scripts on a temp dir)

- [ ] **Step 1: Failing tests:** `sync copies and verifies`; `sync refuses a tampered file`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `chore: checksums, sync script, release workflow`. Creating `carmelosantana/chatparts` on GitHub, adding topics `htmx-agent-ui htmx php-agents ai-chat`, and tagging `v0.1.0` wait for Carmelo's go-ahead.

---

## Phase 3 — AgentChat

All AgentChat PHP tests run with `./testing/run-plugin-tests.sh AgentChat` from `kanboard-plugins/`.

### Task 10: Plugin skeleton, schema, gate

**Files:**
- Create: `AgentChat/{plugin.json,Plugin.php,LICENSE,README.md,.github/workflows/release.yml}` (release.yml verbatim from another suite plugin)
- Create: `AgentChat/Schema/{Sqlite,Mysql,Postgres}.php` (`const VERSION = 1;`, `version_1($pdo)`)
- Create: `AgentChat/Model/AiGate.php`, `AgentChat/scripts/sync-chatparts.sh` (calls chatparts' `sync-into.sh`), `AgentChat/vendor-chatparts/**` (synced)
- Test: `AgentChat/Test/{SchemaTest,AiGateTest,PluginVersionTest,ChatPartsVendorTest}.php`

**Interfaces:**
- Produces:
  - Tables exactly as the spec's data model: `agentchat_rooms`, `agentchat_messages` (incl. `UNIQUE(room_id, sender_kind, sender_id, client_msg_id)`, index `(room_id, id)`, index `(private_to, task_id)`), `agentchat_runs`, `agentchat_files`, `agentchat_knowledge` (`UNIQUE(project_id, source, external_id)`), plus FTS: SQLite `agentchat_knowledge_fts` (FTS5, external content, insert/update/delete triggers); MySQL `FULLTEXT KEY ft_title_body (title, body)` (InnoDB); Postgres generated `search tsvector` + GIN.
  - `final class AiGate { static isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null, ?string $connectorVersion = null): bool }` — PHP ≥ 80400 AND `class_exists(ProviderRegistry)` AND AiConnector version ≥ `1.2.0` AND `class_exists(RunLimits)` AND `ProviderRegistry::isReady()`.
  - `plugin.json`: name `AgentChat`, version `0.1.0`, `recommends`: `[{"plugin":"AiConnector","min_version":"1.2.0","reason":"Project bot answers"},{"plugin":"Agents","min_version":"0.5.0","reason":"Agent badges"},{"plugin":"Presence","min_version":"1.0.1","reason":"Online dots"}]`.
  - `Plugin::initialize()` loads `vendor-chatparts/autoload.php`.

- [ ] **Step 1: Failing tests:** `SchemaTest::testSqliteCreatesAllTables`, `testKnowledgeFtsTriggersIndexInsertUpdateDelete` (insert "hero autoplay", FTS finds it; update body; delete; FTS empty), `testAllThreeDialectsDeclareVersion1`; `AiGateTest` drives the five branches; `PluginVersionTest` checks the three version sources agree; `ChatPartsVendorTest::testVendoredFilesMatchChecksums`.
- [ ] **Step 2:** run → FAIL. **Step 3:** implement; run `scripts/sync-chatparts.sh ~/Projects/chatparts`. **Step 4:** run → PASS.
- [ ] **Step 5: MariaDB check:** `docker run -d --rm --name ac-mariadb -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=kanboard_unit_test -p 33306:3306 mariadb:11.8`, then run `SchemaTest` with `testing/kanboard-src/tests/units.mysql.xml` pointed at it (DB_HOSTNAME 127.0.0.1, port 33306) → PASS, including a FULLTEXT `MATCH … AGAINST` smoke query; stop the container.
- [ ] **Step 6:** `chmod -R o+rX AgentChat`, `docker compose -f testing/docker-compose.dev.yml restart`; the plugin appears enabled on :8081 with no errors in `docker logs kb-suite`.
- [ ] **Step 7: Commit** (`git init` in `AgentChat/`) `feat: skeleton, schema v1, AI gate, vendored chatparts`.

### Task 11: Rooms, messages, access policy

**Files:**
- Create: `AgentChat/Model/{RoomModel,MessageModel,AccessPolicy,ProjectSettings}.php`
- Test: `AgentChat/Test/{RoomModelTest,MessageModelTest,AccessPolicyTest}.php`

**Interfaces:**
- Produces:
  - `RoomModel::forProject(int $projectId): array` (get or create `kind=project`), `RoomModel::get(int $roomId): ?array`.
  - `MessageModel::create(int $roomId, Participant $p, array $parts, array $opts = []): int` — `$opts`: `client_msg_id`, `visibility` (`room|private`), `private_to`, `task_id`, `status`, `metadata`; validates body via `MessageModel::validateBody(string): ?string` (error text or null); duplicate `client_msg_id` returns the existing id.
  - `MessageModel::listAfter(int $roomId, int $viewerId, int $afterId, int $limit = 50): array` (room messages plus the viewer's own private ones never; private only via `privateThread`), `privateThread(int $roomId, int $userId, int $taskId, int $limit = 40): array`, `update(int $id, array $fields): void`, `toChatPartsMessage(array $row): \ChatParts\Model\Message`.
  - `AccessPolicy::canView(int $userId, int $projectId): bool`, `canPost(...)`: bool (member/manager/app admin), `canAsk(int $userId, int $projectId): bool` (reads `ProjectSettings::askRole()`), `canManage(...)`: bool.
  - `ProjectSettings(container, int $projectId)`: `botEnabled(): bool` (default true), `askRole(): string` (default `members`), `aiProfile(): ?string`, `botImages(): bool` (default true), setters; storage `project_metadata` keys `agentchat_bot_enabled`, `agentchat_ask_role`, `agentchat_ai_profile`, `agentchat_bot_images`.

- [ ] **Step 1: Failing tests:** `MessageModelTest::testBodyLimitsCountCharacters` (8 000 × "😀" ok, 8 001 rejected, "   \n" rejected), `testClientMsgIdIsIdempotent`, `testListAfterExcludesPrivate`, `testPrivateThreadOnlyForOwner`; `AccessPolicyTest::testOutsiderCannotViewOrPost`, `testViewerCanViewButNotPost`, `testAskRoleMatrix` (3 settings × manager/member/viewer), `testAppAdminCanPostEverywhere`.
- [ ] **Step 2–4:** FAIL → implement (use Kanboard's `projectPermissionModel` / `projectUserRoleModel` and `Role` constants) → PASS.
- [ ] **Step 5: Commit** `feat: rooms, messages, access policy`.

### Task 12: Host adapter, Chat tab, poll and send (people only)

**Files:**
- Create: `AgentChat/Helper/KanboardHost.php` (implements `ChatParts\Host\HostAdapter`), `AgentChat/Controller/RoomController.php`, `AgentChat/Template/room/{page,messages,rail}.php`, `AgentChat/Template/layout/htmx-meta.php`, `AgentChat/Model/ParticipantResolver.php`
- Modify: `AgentChat/Plugin.php` (routes `project/:project_id/chat`, `agentchat/poll`, `agentchat/send`; project-header tab hook; asset hooks only on chat pages)
- Test: `AgentChat/Test/{KanboardHostTest,RoomControllerTest,ParticipantResolverTest}.php`

**Interfaces:**
- Consumes: Task 11 models; chatparts components.
- Produces:
  - `KanboardHost::markdown()` uses Kanboard's `$helper->text->markdown()` and then strips `<img>` whose `src` is not same-origin; `url()` uses `$helper->url->to()`; `assetUrl()` → `plugins/AgentChat/vendor-chatparts/assets/{file}`.
  - `ParticipantResolver::forUser(int $userId): Participant` — `kind=agent` when the Agents roster lists the user (class exists check), else `human`; `ParticipantResolver::bot(): Participant` → `('bot', null, 'project-bot')`.
  - `RoomController::show()` (GET `/project/{id}/chat`), `poll()` (GET `?room&after` → `MessageBubble` fragments, 204 when nothing new), `send()` (POST, CSRF; stores the message; returns the bubble; bot handling is added in Task 17).

- [ ] **Step 1: Failing tests:** `RoomControllerTest::testPollForeignRoomIsForbidden`, `testPollReturns204WhenNothingNew`, `testSendRequiresCsrf`, `testViewerGetsNoComposer`, `testSendStoresAndRendersBubble`; `KanboardHostTest::testMarkdownDropsExternalImages`, `testMarkdownLinksTaskRefs` (`#12` → task link); `ParticipantResolverTest::testAgentRosterUserIsAgent`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: E2E on :8081** (browser pane): admin opens a project's Chat tab, posts two messages from two users (second user via another session), each sees the other's within ~4 s; viewer sees no composer; no CSP errors.
- [ ] **Step 6: Commit** `feat: project chat tab, poll and send`.

### Task 13: Knowledge store and API

**Files:**
- Create: `AgentChat/Model/KnowledgeModel.php`, `AgentChat/Api/AgentChatKnowledgeProcedure.php`, `AgentChat/Controller/KnowledgeController.php` (read-only viewer), `AgentChat/Template/knowledge/show.php`, `AgentChat/scripts/import-saveit-fixture.php`, `AgentChat/Test/fixtures/saveit-sample.json`
- Test: `AgentChat/Test/{KnowledgeModelTest,KnowledgeProcedureTest}.php`

**Interfaces:**
- Produces:
  - `KnowledgeModel::upsert(int $projectId, string $source, string $externalId, array $doc): int` (`title`, `body`, `url`, `author`, `occurred_at`, `metadata`); `delete(int $projectId, string $source, string $externalId): bool`; `search(int $projectId, string $query, ?string $source = null, int $limit = 8): array` (dialect switch: FTS5 `MATCH` with a quoted, sanitised query; MySQL `MATCH … AGAINST (… IN NATURAL LANGUAGE MODE)`; Postgres `search @@ plainto_tsquery`), each row with `id, title, url, snippet (≤ 300 chars), occurred_at, source`.
  - JSON-RPC (class name prefixed for core ACL, params untyped, only RPC methods on the class): `knowledgeUpsert($project_id, $source, $external_id, $title, $body, $url = '', $author = '', $occurred_at = 0, $metadata = [])`, `knowledgeDelete($project_id, $source, $external_id)`; errors via exceptions Kanboard maps to JSON-RPC errors.
  - Viewer route `agentchat/knowledge/:id` (access = `canView` for its project).

- [ ] **Step 1: Failing tests:** `KnowledgeModelTest::testUpsertIsIdempotent` (same key twice → one row, updated body), `testSearchFindsByBodyWords`, `testSearchScopedToProject`, `testLimits` (200 001-char body and 16 KB + 1 metadata rejected), `testFtsQuerySanitised` (query `"hero" OR *` does not throw); `KnowledgeProcedureTest::testMemberCannotUpsert`, `testManagerCanUpsert`, `testAppAdminCanUpsert`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Run `php AgentChat/scripts/import-saveit-fixture.php` against :8081 through `jsonrpc.php` with a dev token for a manager user; the viewer page shows one imported transcript.
- [ ] **Step 6: Commit** `feat: knowledge store and push API`.

### Task 14: Agent JSON-RPC (post and read)

**Files:**
- Create: `AgentChat/Api/AgentChatRoomProcedure.php`
- Test: `AgentChat/Test/RoomProcedureTest.php`

**Interfaces:**
- Produces: `chatSendMessage($project_id, $body, $client_msg_id = '')` → `{message_id}`; `chatRoomMessages($project_id, $after = 0, $limit = 50)` → `[{id, sender_kind, sender_name, text, created_at}]`. Both use `AccessPolicy` for the token's user; registered with `getProcedureHandler()->withObject()`. A body that mentions `@bot` from an allowed asker starts a non-streamed run (Task 16's `BotRunner::runToCompletion`) once Task 16 exists; until then it is stored only.

- [ ] **Step 1: Failing tests:** `testOutsiderTokenIsRefused`, `testPostAppearsInRoomPoll`, `testIdempotentClientMsgId`, `testProcedureSurfaceHasOnlyTwoMethods` (reflection, as in `Agents/Test/ProcedureSurfaceTest.php`).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: agents post and read via JSON-RPC`.

### Task 15: Bot tools and source registry

**Files:**
- Create: `AgentChat/Bot/Tools/{SearchTasksTool,GetTaskTool,SearchCommentsTool,SearchKnowledgeTool}.php`, `AgentChat/Bot/SourceRegistry.php`, `AgentChat/Bot/AskerScope.php`
- Test: `AgentChat/Test/Bot/{ToolsTest,SourceRegistryTest}.php`

**Interfaces:**
- Consumes: php-agents `ToolInterface` (`name()`, `description()`, `parameters()`, `execute(array $input): ToolResult`, `toFunctionSchema()`), `KnowledgeModel::search`.
- Produces:
  - `AskerScope(container, int $userId, int $projectId, ?int $taskId)` with `canReadTask(int $taskId): bool`.
  - Tools take `(AskerScope $scope, SourceRegistry $sources)`; limits: `search_tasks` ≤ 10, `search_comments` ≤ 10, `search_knowledge` ≤ 8, `get_task` returns description, subtasks and the last 20 comments; text fields truncated to 2 000 chars each; results are JSON with a `n` per item.
  - `SourceRegistry::register(string $kind, string $ref, string $title, string $url): int /* n, stable per ref */`, `all(): SourcePart[]`, `resolve(int $n): ?SourcePart`.

- [ ] **Step 1: Failing tests:** `ToolsTest::testGetTaskOutsideProjectIsNotFound` (task in a project the asker isn't in → result text "not found", registry empty), `testSearchTasksScopedToRoomProject`, `testSearchCommentsLimit`, `testKnowledgeResultsRegisterSources`, `testUnknownArgumentRejected` (php-agents rejects undeclared args; assert the tool's schema has `additionalProperties:false`); `SourceRegistryTest::testSameRefSameNumber`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: read-only bot tools scoped to the asker`.

### Task 16: Chat agent and bot runner

**Files:**
- Create: `AgentChat/Bot/ChatAgent.php` (extends `CarmeloSantana\PHPAgents\Agent\AbstractAgent`), `AgentChat/Bot/Instructions.php`, `AgentChat/Bot/BotRunner.php`, `AgentChat/Bot/RunEventSink.php` (interface), `AgentChat/Bot/StoreSink.php`, `AgentChat/Bot/DbCancellationToken.php`, `AgentChat/Model/LimitsSettings.php`
- Test: `AgentChat/Test/Bot/{BotRunnerTest,InstructionsTest,LimitsSettingsTest}.php`, `AgentChat/Test/Bot/FakeProvider.php`

**Interfaces:**
- Consumes: Tasks 11, 15; AiConnector `ProviderRegistry::buildProvider(?string)`; php-agents `RunLimits`, `CallbackObserver`, `Output`.
- Produces:
  - `LimitsSettings(container)`: `maxIterations()=6`, `maxToolCallsPerRun()=8`, `maxToolCallsPerTurn()=4`, `maxRunSeconds()=90.0`, `maxResponseBytes()=262144`, `maxConcurrentPerUser()=1`, `maxConcurrentInstance()=4`, `questionsPerHour()=30`; config keys `agentchat_*`.
  - `interface RunEventSink { delta(string $text): void; reasoning(string $text): void; toolCall(ToolPart $p): void; toolResult(ToolPart $p): void; final(\ChatParts\Model\Message $m): void; }`
  - `BotRunner(container, ?ProviderInterface $providerOverride = null)`: `run(int $runId, RunEventSink $sink): \ChatParts\Model\Message` and `runToCompletion(int $runId): \ChatParts\Model\Message` (uses `StoreSink`). Builds history (room: last 40 room messages; drawer: `privateThread`), instructions, tools, `RunLimits` from `LimitsSettings`, `DbCancellationToken($runId)` (reads `agentchat_runs.stop_requested`), maps observer events to the sink per the spec's table, then from `Output`: text → final `TextPart`, `[n]` matched via `SourceRegistry` (unmatched dropped), metadata `{model, usage, finish_reason, duration_ms, tool_count, asked_by}`; stores the message with status `done`, `stopped` or `error`; updates the run row.
  - `Instructions::build(array $project, array $asker, ?array $task): string` — names project and asker, says retrieved content is untrusted data, cite `[n]` from tool results only, answer in the asker's language, say so when nothing relevant is found.

- [ ] **Step 1: Failing tests** (all with `FakeProvider` scripting stream chunks and tool calls): `testStreamsDeltasThenFinal`; `testToolCallAndResultReachSink`; `testCitationsMatchRegistryAndUnknownDropped`; `testFinalRenderDropsExternalImages`; `testBudgetExhaustedStopsWithReceipt` (provider requests 9 tool calls → status `stopped`, receipt "stopped: run budget reached"); `testStopRequestedEndsRunAndKeepsPartial`; `testDrawerHistoryIsPrivateThreadOnly` (a room message containing "IGNORE PREVIOUS" is not in the provider's input); `testDoneToolAnswerAppearsInFinal`; `InstructionsTest::testMarksRetrievedContentUntrusted`; `LimitsSettingsTest::testDefaults`.
- [ ] **Step 2–4:** FAIL → implement → PASS. Confirm `AbstractAgent::__construct` argument order from `AiConnector/vendor/carmelosantana/php-agents/src/Agent/AbstractAgent.php` (v0.17) before writing `ChatAgent`.
- [ ] **Step 5: Commit** `feat: chat agent and bot runner`.

### Task 17: Run lifecycle, stream and stop

**Files:**
- Create: `AgentChat/Model/RunModel.php`, `AgentChat/Controller/StreamController.php`, `AgentChat/Bot/SseSink.php` (implements `RunEventSink` over `ChatParts\Stream\SseWriter`)
- Modify: `AgentChat/Controller/RoomController.php` (`send()` starts a run when `@bot` and `canAsk` and gate open; new `stop()`), `AgentChat/Plugin.php` (routes `agentchat/stream`, `agentchat/stop`)
- Test: `AgentChat/Test/{RunModelTest,StreamControllerTest,SendBotTest}.php`

**Interfaces:**
- Produces:
  - `RunModel::start(int $messageId, int $roomId, int $askedBy): array{run_id:int, sid:string}` (sid = 32 random bytes hex; stores `hash('sha256', sid)`; throws `RateLimited` / `Busy` per `LimitsSettings`); `claim(string $sid, int $userId): ?array` (atomic `pending`→`running`; null if unknown, used, finished or other user); `requestStop(int $runId, int $userId): bool`; `finish(int $runId, string $status, array $usage): void`.
  - `StreamController::stream()` → 204 when `claim()` is null; else `SseWriter::open()`, `BotRunner::run($runId, new SseSink($writer, $host))`, `close()`; `connection_aborted()` checked on each event → `requestStop`.
  - `RoomController::send()` with `@bot`: returns human bubble + empty bot bubble with `hx-ext="sse" sse-connect="{stream url}?sid=…" sse-close="done"`; limits hit → `Notice` and no run; gate closed or `!canAsk` → message stored, no run, no error.
  - `RoomController::stop()` POST, CSRF.

- [ ] **Step 1: Failing tests:** `StreamControllerTest::testReusedSidReturns204AndNoSecondRun` (FakeProvider call count stays 1), `testOtherUsersSidReturns204`; `RunModelTest::testRateLimitThirtyPerHour`, `testOneConcurrentPerUser`, `testInstanceLimitFour`, `testSidStoredHashedOnly`; `SendBotTest::testMentionStartsRunWhenAllowed`, `testMentionIgnoredWhenGateClosed`, `testMentionIgnoredForViewerRole` (with `askRole=members`), `testStopRequiresCsrf`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: E2E on :8081** with a real AiConnector profile (Ollama or a cheap hosted model): `@bot what tasks mention X?` streams text, the tool strip updates, sources link to the tasks, the receipt appears; Stop during a tool round ends with "stopped by you"; reload during streaming leaves a `stopped` message and no second run (check `agentchat_runs`).
- [ ] **Step 6: Commit** `feat: streamed bot answers with stop`.

### Task 18: Context budget

**Files:**
- Create: `AgentChat/Bot/ContextBudget.php`; route `agentchat/context` in `RoomController::context()`
- Test: `AgentChat/Test/Bot/ContextBudgetTest.php`

**Interfaces:**
- Produces: `ContextBudget(container)::breakdown(int $roomId, int $userId, ?int $taskId, string $draft, int $imageCount): array{window:int, segments: list<array{key,label,tokens}>}` with keys `instructions, tools, task, history, sources_cap, message, reply_reserve`; tokens via `ModelCatalog::countTokens` (images 1 600 each); `sources_cap` fixed 8 000; `reply_reserve` 4 000. `BotRunner` uses `breakdown()['window'] - 4000` for `RunLimits::maxInputTokens` and drops oldest history first to fit.

- [ ] **Step 1: Failing tests:** `testSegmentsInFillOrder`; `testHistoryTrimmedOldestFirstToFitWindow` (8 192 window); `testMessageNeverTrimmed`; `testUnknownModelFallsBackToCatalogDefault`.
- [ ] **Step 2–4:** FAIL → implement → PASS. The composer's ring calls `context` with `draft_len` debounced 500 ms (chatparts JS already wires `hx-get`).
- [ ] **Step 5: Commit** `feat: context budget meter`.

### Task 19: Images

**Files:**
- Create: `AgentChat/Model/FileModel.php`, `AgentChat/Controller/FileController.php`, `AgentChat/Bot/StorageImageResolver.php` (implements php-agents `ImageResolverInterface`)
- Modify: `RoomController::send()` (accept `images[]`), `BotRunner` (attach images when `ProjectSettings::botImages()`)
- Test: `AgentChat/Test/{FileModelTest,FileControllerTest}.php`, `AgentChat/Test/Bot/ImageResolverTest.php`

**Interfaces:**
- Produces: `FileModel::store(int $roomId, int $messageId, int $userId, array $upload): int` (validates count ≤ 4, size ≤ 5 MB, MIME by `finfo` in the four types; stores via `objectStorage` under `agentchat/{room}/{uuid}`; thumbnail via Kanboard's `Thumbnail`); `FileController::show()` / `thumb()` check `canView` (private messages: owner only); `StorageImageResolver::resolve(string $reference): ResolvedImage` accepts only `agentchat-file:{id}` references the run's asker can view.

- [ ] **Step 1: Failing tests:** `FileModelTest::testRejectsSvgAndOversize`, `testMimeSniffedNotTrusted` (PNG bytes named `.gif` stored as `image/png`; HTML bytes named `.png` rejected); `FileControllerTest::testOutsiderGets403`; `ImageResolverTest::testRefusesForeignFile`; `BotRunnerTest::testImagesSkippedWhenSettingOff`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: image attachments`.

### Task 20: Task drawer

**Files:**
- Create: `AgentChat/Controller/DrawerController.php`, `AgentChat/Template/drawer/{button,panel}.php`
- Modify: `AgentChat/Plugin.php` (task view hook for the button; routes `agentchat/drawer`, `agentchat/drawer/send`, `agentchat/drawer/share`)
- Test: `AgentChat/Test/DrawerControllerTest.php`

**Interfaces:**
- Produces: `DrawerController::show()` (private thread for user+task, `Drawer` component), `send()` (stores `visibility=private`, `private_to`, `task_id`; always starts a run when allowed, no `@bot` needed), `share()` (POST, CSRF: copies the latest question and answer as two room messages, the bot copy keeps parts and metadata).

- [ ] **Step 1: Failing tests:** `testThreadIsPerUserAndTask`, `testOtherUserCannotOpenThread`, `testShareCopiesLatestPairToRoom`, `testButtonHiddenWhenGateClosed`, `testDrawerRunScopesToTaskFirst` (FakeProvider sees the task in its input).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: E2E on :8081:** open a task, ask in the drawer, answer streams, reopen shows the thread, Share posts both into the room.
- [ ] **Step 6: Commit** `feat: ask-the-bot drawer on tasks`.

### Task 21: Settings and degrading

**Files:**
- Create: `AgentChat/Controller/SettingsController.php`, `AgentChat/Template/settings/{project,global}.php`
- Modify: `AgentChat/Plugin.php` (project settings sidebar hook, app config hook)
- Test: `AgentChat/Test/{SettingsControllerTest,DegradeTest}.php`

**Interfaces:**
- Produces: project settings (managers): bot on/off, ask role (three values), AI profile (validated against `ProviderRegistry::listProfiles()` ids), send images to bot; global settings (app admin): every `LimitsSettings` value, with the warning text "Each streaming answer holds one PHP worker. Keep the instance limit below your PHP-FPM pm.max_children." 

- [ ] **Step 1: Failing tests:** `SettingsControllerTest::testMemberCannotChangeProjectSettings`, `testInvalidProfileRejected`, `testLimitsMustBePositive`; `DegradeTest::testNoAiConnectorHidesBotUi` (no `@bot` in picker, no drawer button, `/agentchat/stream` 403), `testNoAgentsPluginMeansNoBadges`, `testNoJsFormPostWorks` (POST without `HX-Request` redirects back to the room with the message stored).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat: project and global settings; degrade paths`.

### Task 22: Docs, full verification, release prep

**Files:**
- Modify: `AgentChat/README.md` (what it does, settings, permissions table, knowledge API with a curl example, proxy snippets for nginx `proxy_buffering off; proxy_read_timeout 120s;` on `/agentchat/stream`, Traefik and Cloudflare, the `pm.max_children` note, MariaDB note), `AgentChat/CHANGELOG.md`
- Create (harness): `/home/carmelo/.config/Claude/side-session-notes/local_cd65249b-67bf-4fed-beda-19acba12926e/agentchat-v1-handover.md`

- [ ] **Step 1:** `./testing/run-plugin-tests.sh --all` to completion → every suite PASS (paste the table into the handover note); chatparts phpunit + `node --test` PASS.
- [ ] **Step 2:** Full E2E on :8081 in the browser pane at 1440 and 390 wide, light and dark: room, @bot stream with sources, stop, drawer + share, image attach/remove, context popover, viewer read-only, outsider 403, AiConnector disabled → bot UI gone. Screenshots into the notes folder.
- [ ] **Step 3:** `./scripts/package.sh AgentChat` produces a single-folder zip with no `Test/`; versions agree (`0.1.0`).
- [ ] **Step 4: Commit** `docs: README, changelog`.
- [ ] **Step 5 (go-ahead gated, in order):** AiConnector `v1.2.0` tag → chatparts repo + `v0.1.0` tag → AgentChat re-sync from the chatparts tag, repo `carmelosantana/kanboard-agent-chat`, `v0.1.0` tag → ModMenu directory entry (`kanboard:plugin-suite` `references/directory.md`). Each step waits for Carmelo's "go" at that point.
