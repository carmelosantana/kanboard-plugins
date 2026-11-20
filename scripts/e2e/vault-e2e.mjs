// Browser end-to-end run of Vault's click paths (T24; spec: Testing → Browser E2E on the dev stack; plan Review Focus
// 1, 4 and 5). Zero dependencies (Node 22: built-in WebSocket, net and child_process). Drives headless Chrome over raw
// CDP (R68/R69: Chromium only) against the :8081 dev stack (kb-suite, VAULT_DEV_INSECURE_LOCALHOST=1, Application URL
// http://localhost:8081/), through Vault's real pages and scripts under Kanboard's CSP:
//
//   e2e-a enrols and unlocks → locks and unlocks again through the toolbar and the form → makes a group and an entry
//   in the editor (Unicode, emoji, tabs, a custom field) → reveals and copies it (the clipboard holds it, the toast's
//   "clears in 30 s" happens, and a refused clipboard says "failed", not "clears") → shares it with e2e-b, Read (B
//   reads, can't edit), then Edit (B edits, A reads B's change) → one-time links without and with a passphrase (the
//   Argon2id module worker runs in the page), each revealed once in a fresh browser context, the second reveal refused
//   → imports a KeePassXC file → exports (a wrong passphrase is refused; the file opens with the export password in
//   Node's kdbxweb) → B exports with "shared with me": only live grants (a revoked and an expired one are left out)
//   → Review Focus 5: two tabs edit one entry, the second save gets the conflict message → Review Focus 1: the vault
//   reached at another host refuses plainly (https_required, the HTTPS notice) and counts no failed guess; another port
//   on localhost works → Review Focus 4: disabling A while unlocked locks the vault at the next request → the CSP sweep
//   (no violation on any Vault page, no Vault script on Kanboard's own pages) and the password-manager attributes.
// Hardware keys are vault-webauthn.mjs's (carry 2); this run does not repeat them.
//
// Users e2e-a and e2e-b and their shared project are made and removed with PDO inside kb-suite (dev only; never the
// production MCP). Chrome runs with background networking off.
//
// Run: node scripts/e2e/vault-e2e.mjs        CHROME=/path/to/chrome overrides /usr/bin/google-chrome.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, existsSync } from 'node:fs';
import { createServer, connect } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { randomBytes } from 'node:crypto';

const BASE = process.env.KB_URL || 'http://localhost:8081';
// This script creates and deletes users: refuse anything but the local dev stack.
if (!['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) {
  console.error(`refusing: KB_URL must be http://localhost or http://127.0.0.1, not ${BASE}`);
  process.exit(2);
}
const PORT = Number(new URL(BASE).port || 80);
const CONTAINER = process.env.KB_CONTAINER || 'kb-suite';
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const VAULT = resolve(dirname(fileURLToPath(import.meta.url)), '../../Vault');
const FIXTURES = join(VAULT, 'Test/fixtures/kdbx');
const { PASSWORD: KDBX_PASSWORD, KPXC } = await import(pathToFileURL(join(FIXTURES, 'fixtures.mjs')).href);
const { loadKdbxPage } = await import(pathToFileURL(join(VAULT, 'Test/js/kdbx-page.mjs')).href);

const PROJECT = 'Vault E2E T24';
const A = { username: 'e2e-a', login: randomBytes(12).toString('base64url'), passphrase: 'e2e a ' + randomBytes(12).toString('base64url') + ' phrase' };
const B = { username: 'e2e-b', login: randomBytes(12).toString('base64url'), passphrase: 'e2e b ' + randomBytes(12).toString('base64url') + ' phrase' };
const tag = randomBytes(4).toString('hex');
const ENTRY = {
  title: `E2E entry ${tag} ✓`,
  username: 'e2e user\twith tab',
  password: `pw-${randomBytes(9).toString('base64url')}-ü🔑`,
  url: 'https://e2e.example.test/login',
  notes: `line one 🙂\n\tline two  `,
  tags: ['e2e', 'ünïcode'],
  field: { name: 'api key', value: `field-${randomBytes(6).toString('hex')}\t` },
};
const B_PASSWORD = `b-edited-${randomBytes(9).toString('base64url')}`;
const ONETIME_TEXT = `one-time ${randomBytes(6).toString('hex')} — ✓ 🙂\ttab`;
const ONETIME_TEXT_2 = `one-time with passphrase ${randomBytes(6).toString('hex')}`;
const EXPORT_PASSWORD = 'export ' + randomBytes(10).toString('base64url') + ' ü';
const page = (controller, action, base = BASE) => `${base}/?controller=${controller}&action=${action}&plugin=Vault`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── Dev database (PDO inside kb-suite; the query and its parameters go in on stdin) ──────────────────────────────
const PHP = `$in = json_decode(stream_get_contents(STDIN), true);
$p = new PDO('sqlite:/var/www/app/data/db.sqlite');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('PRAGMA foreign_keys = ON');
if (isset($in['hash'])) { $in['params'][] = password_hash($in['hash'], PASSWORD_BCRYPT); }
$s = $p->prepare($in['sql']);
$s->execute($in['params']);
echo json_encode($s->columnCount() ? $s->fetchAll(PDO::FETCH_ASSOC) : []);`;
function sql(query, params = [], hash) {
  const r = spawnSync('docker', ['exec', '-i', CONTAINER, 'php', '-r', PHP], { input: JSON.stringify({ sql: query, params, ...(hash ? { hash } : {}) }), encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`sql failed: ${r.stderr || r.stdout}`);
  return JSON.parse(r.stdout || '[]');
}
const one = (q, p) => sql(q, p)[0] ?? null;
const idOf = (username) => Number(one('SELECT id FROM users WHERE username = ?', [username])?.id ?? 0);
// The users and their vaults (a principal's rows cascade; an orphan left at a reused id is removed as the purge would),
// and the shared project.
function dropFixtures() {
  for (const u of [A, B]) {
    sql('DELETE FROM vault_principals WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [u.username]);
    sql('DELETE FROM users WHERE username = ?', [u.username]);
  }
  sql('DELETE FROM projects WHERE name = ?', [PROJECT]);
}

let failures = 0;
function check(ok, what) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`);
  if (!ok) failures++;
}

// ── Another port: a plain TCP relay from a random localhost port to the dev stack (Review Focus 1) ───────────────
const relay = createServer((c) => {
  const up = connect(PORT, '127.0.0.1');
  c.pipe(up).pipe(c);
  const end = () => { c.destroy(); up.destroy(); };
  c.on('error', end);
  up.on('error', end);
});
await new Promise((r) => relay.listen(0, '127.0.0.1', r));
const RELAY = `http://localhost:${relay.address().port}`;

// ── Chrome over CDP ─────────────────────────────────────────────────────────────────────────────────────────────
const profile = mkdtempSync(join(tmpdir(), 'vault-e2e-t24-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', '--disable-gpu',
  '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-default-apps',
  '--disable-extensions', '--disable-domain-reliability', '--disable-client-side-phishing-detection', '--no-pings',
  '--metrics-recording-only', '--disable-features=OptimizationHints,MediaRouter,Translate,AutofillServerCommunication',
  'about:blank',
], { stdio: 'ignore' });

let ws;
const cleanup = () => {
  try { ws?.close(); } catch {}
  chrome.kill('SIGKILL');
  relay.close();
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
  try { dropFixtures(); } catch (e) { console.error(`cleanup: ${e.message}`); }
};

const sessions = new Map(); // sessionId → page
const downloads = []; // Browser.downloadWillBegin / downloadProgress
let send;

// One page (a CDP target) in a browser context, with its own event log.
async function newPage(contextId) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank', ...(contextId ? { browserContextId: contextId } : {}) });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const p = { targetId, sessionId, contextId, requests: [], responses: [], problems: [], workers: [], visited: [] };
  sessions.set(sessionId, p);
  const s = (m, params) => send(m, params, sessionId);
  p.s = s;
  for (const d of ['Page', 'Runtime', 'Log', 'Network', 'DOM']) await s(d + '.enable');
  await s('Emulation.setFocusEmulationEnabled', { enabled: true });
  await s('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: false, flatten: true });
  p.evaluate = async (expression) => {
    const r = await s('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 400));
    return r.result.value;
  };
  p.waitFor = async (expression, what, ms = 30000) => {
    for (let t = 0; t < ms; t += 100) {
      try { if (await p.evaluate(expression)) return; } catch {}
      await sleep(100);
    }
    throw new Error(`timed out waiting for ${what} (at ${await p.evaluate("location.href + ' ' + document.title + ' toast=' + ((document.querySelector('[data-vault-toast]') || {}).textContent || '')").catch(() => '?')})`);
  };
  // exact: wait for this very URL; otherwise for any loaded page on its path (a page may rewrite its own address).
  p.go = async (url, exact = true) => {
    await s('Page.navigate', { url });
    const u = new URL(url);
    await p.waitFor(exact ? `document.readyState === 'complete' && location.href === ${JSON.stringify(url)}`
      : `document.readyState === 'complete' && location.origin === ${JSON.stringify(u.origin)} && location.search.startsWith(${JSON.stringify(u.search.slice(0, 40))})`, `load ${url}`);
    p.visited.push(await p.evaluate('location.href'));
  };
  p.api = (action, body = {}) => p.evaluate(`window.KBVault.api(${JSON.stringify(action)}, ${JSON.stringify(body)})`);
  p.click = (selector) => p.evaluate(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) throw new Error('no ' + ${JSON.stringify(selector)}); e.click(); return true; })()`);
  // A real mouse click at the element's centre (a user gesture as the browser sees it).
  p.mouse = async (selector) => {
    const r = await p.evaluate(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); e.scrollIntoView({block: 'center'}); const b = e.getBoundingClientRect(); return {x: b.x + b.width / 2, y: b.y + b.height / 2}; })()`);
    for (const type of ['mousePressed', 'mouseReleased']) await s('Input.dispatchMouseEvent', { type, x: r.x, y: r.y, button: 'left', clickCount: 1 });
  };
  p.text = (selector) => p.evaluate(`(document.querySelector(${JSON.stringify(selector)}) || {}).textContent || ''`);
  p.setFiles = async (selector, paths) => {
    const { root } = await s('DOM.getDocument', { depth: 0 });
    const { nodeId } = await s('DOM.querySelector', { nodeId: root.nodeId, selector });
    await s('DOM.setFileInputFiles', { nodeId, files: paths });
  };
  p.state = () => p.evaluate(`(document.getElementById('vault-app') || {getAttribute: () => null}).getAttribute('data-vault-state')`);
  p.login = async (user, base = BASE) => {
    await p.go(`${base}/?controller=AuthController&action=login`);
    await p.evaluate(`(() => { const f = document.querySelector('form'); f.querySelector('[name=username]').value = ${JSON.stringify(user.username)};
      f.querySelector('[name=password]').value = ${JSON.stringify(user.login)}; f.submit(); return true; })()`);
    await p.waitFor(`!location.href.includes('action=login') && document.readyState === 'complete'`, `${user.username} login`);
  };
  p.close = () => send('Target.closeTarget', { targetId });
  // An idle page leaves Kanboard (about:blank), so its background requests (other plugins' polls) don't race the
  // active page's session writes: Kanboard core's SessionHandler::write reads then writes in one deferred SQLite
  // transaction and fails with "database is locked" when another request wrote in between (see the T24 report).
  p.park = () => p.go('about:blank');
  return p;
}
async function newContext() {
  const { browserContextId } = await send('Target.createBrowserContext', { disposeOnDetach: true });
  return browserContextId;
}

// Every attribute the password-manager rule cares about, per password-ish input on the page.
const AUTOCOMPLETE = `[...document.querySelectorAll('input[type=password], form')].map((e) => ({
  tag: e.tagName.toLowerCase(), id: e.id || Object.keys(e.dataset).join(','), ac: e.getAttribute('autocomplete'),
  pm: e.getAttribute('data-lpignore') === 'true' && ['data-1p-ignore', 'data-bwignore'].every((a) => e.hasAttribute(a)) }))`;

try {
  dropFixtures();
  for (const u of [A, B]) {
    sql("INSERT INTO users (username, name, role, is_active, password) VALUES (?, ?, 'app-user', 1, ?)", [u.username, `Vault ${u.username}`], u.login);
    u.id = idOf(u.username);
    // A deleted user's orphaned principal at a reused id would otherwise greet this run.
    sql('DELETE FROM vault_principals WHERE user_id = ? AND live_user_id IS NULL', [u.id]);
  }
  sql("INSERT INTO projects (name, is_active, token, last_modified) VALUES (?, 1, '', ?)", [PROJECT, Math.floor(Date.now() / 1000)]);
  const projectId = Number(one('SELECT id FROM projects WHERE name = ?', [PROJECT]).id);
  for (const u of [A, B]) sql("INSERT INTO project_has_users (project_id, user_id, role) VALUES (?, ?, 'project-member')", [projectId, u.id]);
  check(A.id > 0 && B.id > 0, `e2e-a (${A.id}) and e2e-b (${B.id}) created by PDO, members of "${PROJECT}"`);

  const portFile = join(profile, 'DevToolsActivePort');
  for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
  const [port, wsPath] = readFileSync(portFile, 'utf8').trim().split('\n');
  ws = new WebSocket(`ws://127.0.0.1:${port}${wsPath}`);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  let nextId = 1;
  const pending = new Map();
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve: ok, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(`${msg.error.message} ${msg.error.data || ''}`)) : ok(msg.result);
      return;
    }
    if (msg.method === 'Browser.downloadWillBegin') downloads.push({ guid: msg.params.guid, name: msg.params.suggestedFilename, state: 'started' });
    if (msg.method === 'Browser.downloadProgress') {
      const d = downloads.find((x) => x.guid === msg.params.guid);
      if (d) d.state = msg.params.state;
    }
    const p = sessions.get(msg.sessionId);
    if (!p) return;
    if (msg.method === 'Network.requestWillBeSent') {
      p.requests.push({ url: msg.params.request.url, body: msg.params.request.postData || '', type: msg.params.type });
    } else if (msg.method === 'Network.responseReceived') {
      p.responses.push({ url: msg.params.response.url, type: msg.params.type, status: msg.params.response.status, headers: msg.params.response.headers });
    } else if (msg.method === 'Log.entryAdded' && ['error', 'warning'].includes(msg.params.entry.level)) {
      p.problems.push({ text: msg.params.entry.text, source: msg.params.entry.source, url: msg.params.entry.url || '' });
    } else if (msg.method === 'Runtime.exceptionThrown') {
      p.problems.push({ text: JSON.stringify(msg.params.exceptionDetails).slice(0, 300), source: 'exception' });
    } else if (msg.method === 'Target.attachedToTarget') {
      p.workers.push({ type: msg.params.targetInfo.type, url: msg.params.targetInfo.url });
    }
  };
  send = (method, params = {}, sessionId) => new Promise((ok, reject) => {
    const id = nextId++;
    pending.set(id, { resolve: ok, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const allPages = [];
  const opened = async (ctx) => { const p = await newPage(ctx); allPages.push(p); return p; };

  // ── A: enrol, lock, unlock ───────────────────────────────────────────────────────────────────────────────────
  const ctxA = await newContext();
  const a = await opened(ctxA);
  await a.login(A);
  await a.go(page('VaultController', 'index'));
  check(JSON.stringify(await a.evaluate(AUTOCOMPLETE)) === JSON.stringify([
    { tag: 'form', id: 'vaultForm', ac: null, pm: false },
    { tag: 'input', id: 'vault-new-passphrase', ac: 'off', pm: true },
    { tag: 'input', id: 'vault-new-confirm', ac: 'off', pm: true },
  ]), `enrol form (R70): the passphrase inputs are autocomplete=off with the 1p/lp/bw ignore hints (${JSON.stringify(await a.evaluate(AUTOCOMPLETE))})`);
  // R70 changed only attributes: the T13 generator partial still fills both enrolment fields and shows the value.
  await a.click('[data-vault-form="enrol"] [data-vault-action="generate"]');
  await a.waitFor(`document.getElementById('vault-new-passphrase').value.split('-').length >= 6`, 'the generator filled the new passphrase');
  const gen = await a.evaluate(`({ p: document.getElementById('vault-new-passphrase').value, c: document.getElementById('vault-new-confirm').value,
    show: document.querySelector('[data-vault-gen-show]').textContent })`);
  check(gen.p === gen.c && gen.p === gen.show, `enrol: the generator fills passphrase and confirmation and shows it (${gen.p.split('-').length} words)`);
  await a.evaluate(`(() => { document.getElementById('vault-new-passphrase').value = ${JSON.stringify(A.passphrase)};
    document.getElementById('vault-new-confirm').value = ${JSON.stringify(A.passphrase)};
    document.querySelector('[data-vault-form="enrol"] button[type="submit"]').click(); return true; })()`);
  await a.waitFor(`(document.getElementById('vault-app') || {getAttribute: () => ''}).getAttribute('data-vault-state') === 'unlocked'`, 'A enrolled and unlocked');
  check(Number(one('SELECT COUNT(*) AS n FROM vault_principals WHERE user_id = ?', [A.id]).n) === 1, 'A: enrolled through the page');

  await a.click('[data-vault-action="lock"]');
  await a.waitFor(`(document.getElementById('vault-app') || {getAttribute: () => ''}).getAttribute('data-vault-state') === 'locked'`, 'A locked from the toolbar');
  check(Number(one('SELECT COUNT(*) AS n FROM vault_sessions WHERE user_id = ?', [A.id]).n) === 0, 'A: Lock removes the vault session');
  const lockedAc = await a.evaluate(AUTOCOMPLETE);
  check(lockedAc.length === 2 && lockedAc[1].id === 'vault-passphrase' && lockedAc[1].ac === 'off' && lockedAc[1].pm, `unlock form (R70): the passphrase input is autocomplete=off with the 1p/lp/bw ignore hints (${JSON.stringify(lockedAc)})`);
  await a.evaluate(`(() => { document.getElementById('vault-passphrase').value = 'not the passphrase at all';
    document.querySelector('[data-vault-form="unlock"] button[type="submit"]').click(); return true; })()`);
  await a.waitFor(`(document.querySelector('[data-vault-form="unlock"] .vault-error') || {}).textContent === 'Unlock failed.'`, 'wrong passphrase refused');
  check(await a.evaluate(`document.getElementById('vault-passphrase').value === ''`), 'a refused unlock clears the passphrase field');
  await a.evaluate(`(() => { document.getElementById('vault-passphrase').value = ${JSON.stringify(A.passphrase)};
    document.querySelector('[data-vault-form="unlock"] button[type="submit"]').click(); return true; })()`);
  await a.waitFor(`(document.getElementById('vault-app') || {getAttribute: () => ''}).getAttribute('data-vault-state') === 'unlocked'`, 'A unlocked through the form');
  check(true, 'A: unlock through the form');

  // ── A: a group and an entry through the editor ───────────────────────────────────────────────────────────────
  const GROUP = `E2E group ${tag}`;
  await a.evaluate(`(() => { window.prompt = () => ${JSON.stringify(GROUP)}; document.querySelector('[data-vault-action="new-group"]').click(); return true; })()`);
  await a.waitFor(`[...document.querySelectorAll('[data-vault-tree="own"] .vault-node')].some((b) => b.textContent === ${JSON.stringify(GROUP)})`, 'the new group in the tree');
  const groupId = Number(await a.evaluate(`[...document.querySelectorAll('[data-vault-tree="own"] .vault-node')].find((b) => b.textContent === ${JSON.stringify(GROUP)}).getAttribute('data-group-id')`));
  const openGroup = async (p, id) => {
    await p.waitFor(`!!document.querySelector('[data-vault-tree="own"] [data-group-id="${id}"]')`, `group ${id} in the tree`);
    await p.click(`[data-vault-tree="own"] [data-group-id="${id}"]`);
    await p.waitFor(`document.querySelector('[data-vault-list-title]').textContent !== ''`, 'the group list');
  };
  const openRow = async (p, title) => {
    await p.waitFor(`[...document.querySelectorAll('[data-vault-rows] tr')].some((tr) => tr.cells[0] && tr.cells[0].textContent === ${JSON.stringify(title)})`, `row ${title}`);
    await p.evaluate(`[...document.querySelectorAll('[data-vault-rows] tr')].find((tr) => tr.cells[0].textContent === ${JSON.stringify(title)}).click()`);
    await p.waitFor(`!document.querySelector('[data-vault-detail]').hidden && document.querySelector('[data-vault-detail] [data-field="title"]').textContent === ${JSON.stringify(title)}`, `detail of ${title}`);
    return Number(await p.evaluate(`document.querySelector('[data-vault-detail]').getAttribute('data-vault-entry-id')`));
  };
  const toast = (p, want, what) => p.waitFor(`(() => { const t = document.querySelector('[data-vault-toast]'); return !t.hidden && ${want}.test(t.textContent); })()`, what);
  await openGroup(a, groupId);
  await a.click('[data-vault-action="new-entry"]');
  await a.waitFor(`!document.querySelector('[data-vault-form="edit"]').hidden`, 'the editor');
  const editorAc = await a.evaluate(`(() => { const f = document.querySelector('[data-vault-form="edit"]'); const pw = f.elements.password;
    return { form: f.getAttribute('autocomplete'), pw: pw.getAttribute('autocomplete'), pm: ['data-lpignore', 'data-1p-ignore', 'data-bwignore'].every((x) => pw.hasAttribute(x)),
      named: [...f.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=number]):not([type=date])')].filter((i) => i.name).every((i) => i.getAttribute('autocomplete') === 'off') }; })()`);
  check(editorAc.form === 'off' && editorAc.pw === 'off' && editorAc.pm && editorAc.named, `entry editor: form and inputs autocomplete=off, password-manager ignore hints (${JSON.stringify(editorAc)})`);
  await a.evaluate(`(() => { const f = document.querySelector('[data-vault-form="edit"]').elements;
    f.title.value = ${JSON.stringify(ENTRY.title)}; f.username.value = ${JSON.stringify(ENTRY.username)}; f.password.value = ${JSON.stringify(ENTRY.password)};
    f.url.value = ${JSON.stringify(ENTRY.url)}; f.notes.value = ${JSON.stringify(ENTRY.notes)}; f.tags.value = ${JSON.stringify(ENTRY.tags.join(', '))};
    document.querySelector('[data-vault-action="add-field"]').click();
    const row = document.querySelector('.vault-edit-field'); row.querySelector('.vault-field-name').value = ${JSON.stringify(ENTRY.field.name)};
    row.querySelector('.vault-field-value').value = ${JSON.stringify(ENTRY.field.value)};
    document.querySelector('[data-vault-form="edit"] button[type="submit"]').click(); return true; })()`);
  await toast(a, '/^Saved\\.$/', 'the Saved toast');
  const entryId = await openRow(a, ENTRY.title);
  const stored = (await a.api('entry', { id: entryId })).entry;
  const field = async (p, id, f) => (await p.api('field', { id, field: f })).value;
  check(stored.username === ENTRY.username && stored.url === ENTRY.url && JSON.stringify(stored.tags) === JSON.stringify(ENTRY.tags)
    && await field(a, entryId, 'password') === ENTRY.password && await field(a, entryId, 'notes') === ENTRY.notes
    && await field(a, entryId, 'field:' + ENTRY.field.name) === ENTRY.field.value, 'the entry made in the editor reads back byte for byte (Unicode, emoji, tabs)');
  check(await a.text('[data-vault-detail] [data-vault-value="password"]') === '••••••••', 'the password is masked in the detail pane');
  await a.click('[data-vault-detail] [data-vault-reveal="password"]');
  await a.waitFor(`document.querySelector('[data-vault-detail] [data-vault-value="password"]').textContent === ${JSON.stringify(ENTRY.password)}`, 'reveal');
  check(true, 'Show reveals the password');
  await a.click('[data-vault-detail] [data-vault-reveal="password"]');
  check(await a.text('[data-vault-detail] [data-vault-value="password"]') === '••••••••', 'Show again masks it');

  // ── Copy: the clipboard, the toast and its 30 s clear (carry 5) ──────────────────────────────────────────────
  await send('Browser.grantPermissions', { origin: BASE, browserContextId: ctxA, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] });
  const clip = () => a.evaluate('navigator.clipboard.readText()');
  const tCopy = Date.now();
  await a.mouse('[data-vault-detail] [data-vault-copy="password"]');
  await toast(a, '/^Copied — clears in 30 s$/', 'the copy toast');
  check(await clip() === ENTRY.password, 'Copy puts the password on the clipboard; the toast says "Copied — clears in 30 s"');
  await sleep(Math.max(0, tCopy + 26000 - Date.now()));
  check(await clip() === ENTRY.password, 'still on the clipboard at 26 s');
  await sleep(Math.max(0, tCopy + 32000 - Date.now()));
  check(await clip() === '', 'cleared at 30 s while the tab has focus (the toast\'s promise holds)');
  await send('Browser.setPermission', { permission: { name: 'clipboard-write', allowWithoutSanitization: false }, setting: 'denied', origin: BASE, browserContextId: ctxA });
  await a.evaluate(`document.querySelector('[data-vault-toast]').hidden = true`);
  await a.mouse('[data-vault-detail] [data-vault-copy="password"]');
  await a.waitFor(`!document.querySelector('[data-vault-toast]').hidden`, 'a toast for the refused copy');
  const refused = await a.text('[data-vault-toast]');
  check(!/clears/.test(refused) && /went wrong/.test(refused), `a refused clipboard write says "${refused}", never "clears in 30 s"`);
  await send('Browser.grantPermissions', { origin: BASE, browserContextId: ctxA, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] });

  // ── B: enrol; A shares the entry Read, then Edit ─────────────────────────────────────────────────────────────
  const ctxB = await newContext();
  const b = await opened(ctxB);
  await b.login(B);
  await b.go(page('VaultController', 'index'));
  await b.evaluate(`(() => { document.getElementById('vault-new-passphrase').value = ${JSON.stringify(B.passphrase)};
    document.getElementById('vault-new-confirm').value = ${JSON.stringify(B.passphrase)};
    document.querySelector('[data-vault-form="enrol"] button[type="submit"]').click(); return true; })()`);
  await b.waitFor(`(document.getElementById('vault-app') || {getAttribute: () => ''}).getAttribute('data-vault-state') === 'unlocked'`, 'B enrolled');
  await b.park();

  const shareWith = async (perm) => {
    await a.click('[data-vault-detail] [data-vault-action="share-entry"]');
    await a.waitFor(`!document.querySelector('[data-vault-share]').hidden && [...document.querySelectorAll('[data-vault-share-grantee] option')].some((o) => o.value === '${B.id}')`, 'B offered in the share dialog');
    await a.evaluate(`(() => { document.querySelector('[data-vault-share-grantee]').value = '${B.id}'; document.querySelector('[data-vault-share-perm]').value = '${perm}';
      document.querySelector('[data-vault-form="share"] button[type="submit"]').click(); return true; })()`);
    await a.waitFor(`document.querySelector('[data-vault-share-status]').textContent === 'Shared.' && [...document.querySelectorAll('[data-vault-share-grants] li')].some((li) => li.textContent.includes(${JSON.stringify(perm === 'edit' ? 'Edit' : 'Read')}))`, `the ${perm} grant listed`);
    const grants = await a.evaluate(`[...document.querySelectorAll('[data-vault-share-grants] li')].map((li) => li.textContent)`);
    await a.click('[data-vault-action="share-close"]');
    return grants;
  };
  let grants = await shareWith('read');
  check(grants.length === 1 && /Vault e2e-b/.test(grants[0]) && /Read/.test(grants[0]), `A shares the entry with e2e-b, Read (${JSON.stringify(grants)})`);
  const openShared = async () => {
    await b.go(page('VaultController', 'index'));
    await b.waitFor(`!document.querySelector('[data-vault-action="shared-entries"]').hidden`, 'B: Entries shared with me');
    await b.click('[data-vault-action="shared-entries"]');
    return openRow(b, ENTRY.title);
  };
  check(await openShared() === entryId, 'B sees the entry under "Entries shared with me"');
  await b.click('[data-vault-detail] [data-vault-reveal="password"]');
  await b.waitFor(`document.querySelector('[data-vault-detail] [data-vault-value="password"]').textContent === ${JSON.stringify(ENTRY.password)}`, 'B reveals');
  check(true, 'B reads the password');
  check(await b.evaluate(`document.querySelector('[data-vault-detail] [data-vault-action="edit"]').hidden && document.querySelector('[data-vault-detail] [data-vault-action="share-entry"]').hidden`), 'B with Read: no Edit and no Share button');
  const readOnlySave = await b.api('save', { type: 'entry', id: entryId, version: stored.version, payload: { title: 'hijacked' } });
  check(readOnlySave.ok === false, `B with Read can't save (${JSON.stringify(readOnlySave)})`);
  await b.park();

  grants = await shareWith('edit');
  check(grants.length === 1 && /Edit/.test(grants[0]), `A changes the grant to Edit (${JSON.stringify(grants)})`);
  await openShared();
  check(await b.evaluate(`!document.querySelector('[data-vault-detail] [data-vault-action="edit"]').hidden`), 'B with Edit: the Edit button shows');
  await b.click('[data-vault-detail] [data-vault-action="edit"]');
  await b.waitFor(`!document.querySelector('[data-vault-form="edit"]').hidden && document.querySelector('[data-vault-form="edit"]').elements.password.value === ${JSON.stringify(ENTRY.password)}`, 'B: the editor, filled');
  await b.evaluate(`(() => { document.querySelector('[data-vault-form="edit"]').elements.password.value = ${JSON.stringify(B_PASSWORD)};
    document.querySelector('[data-vault-form="edit"] button[type="submit"]').click(); return true; })()`);
  await toast(b, '/^Saved\\.$/', 'B: Saved');
  await b.park();
  check(await field(a, entryId, 'password') === B_PASSWORD && await field(a, entryId, 'notes') === ENTRY.notes, 'A reads B\'s edit; the other fields are unchanged');

  // ── One-time links, revealed in fresh browser contexts (carry 1) ─────────────────────────────────────────────
  await a.go(page('VaultController', 'index'));
  const oneTime = async (text, withPassphrase) => {
    await a.click('[data-vault-action="onetime-new"]');
    await a.waitFor(`!document.querySelector('[data-vault-onetime]').hidden`, 'the one-time dialog');
    await a.evaluate(`(() => { document.querySelector('[data-vault-ot-text]').value = ${JSON.stringify(text)};
      document.querySelector('[data-vault-ot-label]').value = 'e2e label'; return true; })()`);
    let passphrase = '';
    if (withPassphrase) {
      await a.click('[data-vault-ot-use-pass]');
      await a.waitFor(`document.querySelector('[data-vault-ot-pass]').value.split(' ').length >= 5 || document.querySelector('[data-vault-ot-pass]').value.split('-').length >= 5`, 'a generated passphrase');
      passphrase = await a.evaluate(`document.querySelector('[data-vault-ot-pass]').value`);
    }
    await a.click('[data-vault-action="onetime-create"]');
    await a.waitFor(`!document.querySelector('[data-vault-ot-result]').hidden && document.querySelector('[data-vault-ot-link]').value.includes('#')`, 'the link', 60000);
    const link = await a.evaluate(`document.querySelector('[data-vault-ot-link]').value`);
    await a.click('[data-vault-action="onetime-close"]');
    return { link, passphrase };
  };
  const reveal = async (link, passphrase) => {
    const ctx = await newContext();
    const r = await opened(ctx);
    await r.go(link, false);
    await r.waitFor(`!!window.KBVaultOnetime && !!document.querySelector('[data-vault-reveal-button]')`, 'the share page');
    const fragmentGone = await r.evaluate(`location.hash === ''`);
    if (passphrase) {
      await r.click('[data-vault-reveal-button]');
      await r.waitFor(`/needs the passphrase|not available/.test(document.querySelector('[data-vault-reveal-status]').textContent)`, 'the passphrase prompt', 60000);
      if (/needs the passphrase/.test(await r.text('[data-vault-reveal-status]'))) {
        await r.evaluate(`document.querySelector('[data-vault-reveal-passphrase]').value = ${JSON.stringify(passphrase)}`);
        await r.click('[data-vault-reveal-button]');
      }
    } else {
      await r.click('[data-vault-reveal-button]');
    }
    await r.waitFor(`!document.querySelector('[data-vault-reveal-result]').hidden || /not available/.test(document.querySelector('[data-vault-reveal-status]').textContent)`, 'the reveal', 60000);
    const out = { secret: await r.evaluate(`document.querySelector('[data-vault-reveal-result]').hidden ? null : document.querySelector('[data-vault-reveal-secret]').value`),
      status: await r.text('[data-vault-reveal-status]'), fragmentGone, workers: r.workers };
    await r.close();
    return out;
  };
  const ot1 = await oneTime(ONETIME_TEXT, false);
  check(ot1.link.startsWith(page('VaultShareController', 'show')) && /&id=[0-9a-f]{32}#[A-Za-z0-9_-]{64}$/.test(ot1.link), 'a one-time link without a passphrase');
  let r = await reveal(ot1.link);
  check(r.secret === ONETIME_TEXT && r.fragmentGone, 'revealed once in a fresh browser context, byte for byte; the #fragment is wiped from the address bar');
  r = await reveal(ot1.link);
  check(r.secret === null && /not available/.test(r.status), `a second reveal in another fresh context is refused ("${r.status}")`);
  const workersBefore = a.workers.length;
  const ot2 = await oneTime(ONETIME_TEXT_2, true);
  check(ot2.passphrase.length > 20, `a one-time link with a generated passphrase (${ot2.passphrase.split(/[ -]/).length} words)`);
  check(a.workers.slice(workersBefore).some((w) => w.type === 'worker' && /argon2-worker\.js/.test(w.url)), `the creator's page ran the Argon2id module worker (${JSON.stringify(a.workers.slice(workersBefore))})`);
  r = await reveal(ot2.link, ot2.passphrase);
  check(r.secret === ONETIME_TEXT_2, 'revealed with the passphrase in a fresh context');
  check(r.workers.some((w) => w.type === 'worker' && /argon2-worker\.js/.test(w.url)), 'the share page ran the Argon2id module worker');
  r = await reveal(ot2.link, ot2.passphrase);
  check(r.secret === null && /not available/.test(r.status), 'the passphrase link\'s second reveal is refused');
  const revealPosts = allPages.flatMap((p) => p.requests).filter((q) => /controller=VaultShareController&action=reveal/.test(q.url));
  check(revealPosts.length === 4 && revealPosts.every((q) => !q.url.includes('#') && !/[A-Za-z0-9_-]{64}/.test(q.body.replace(/csrf_token=[^&]*/, ''))), `the reveal POSTs carry no fragment key (${revealPosts.length})`);

  // ── Import, then export ──────────────────────────────────────────────────────────────────────────────────────
  const kpxc = KPXC.argon2id;
  await a.go(page('ImportController', 'index'));
  check(JSON.stringify(await a.evaluate(AUTOCOMPLETE)) === JSON.stringify([{ tag: 'form', id: 'vaultImportForm', ac: 'off', pm: false }, { tag: 'input', id: 'vault-import-password', ac: 'off', pm: true }]), 'import (R70): the KDBX password input is autocomplete=off with the 1p/lp/bw ignore hints');
  await a.setFiles('[data-vault-import-file]', [join(FIXTURES, kpxc.file)]);
  await a.evaluate(`(() => { document.querySelector('[data-vault-import-password]').value = ${JSON.stringify(KDBX_PASSWORD)};
    document.querySelector('[data-vault-import-submit]').click(); return true; })()`);
  await a.waitFor(`!document.querySelector('[data-vault-import-submit]').disabled && !document.querySelector('[data-vault-import-status]').hidden
    && document.querySelector('[data-vault-import-progress]').hidden`, 'the import', 180000);
  const importReport = await a.evaluate(`Object.fromEntries([...document.querySelectorAll('[data-vault-import-report] [data-field]')].map((e) => [e.getAttribute('data-field'), e.textContent]))`);
  check(importReport.created === '5' && !(await a.evaluate(`document.querySelector('[data-vault-import-status]').classList.contains('vault-bad')`)), `imported ${kpxc.file} (${JSON.stringify(importReport)})`);

  const exportRun = async (p, user, { passphrase, includeShared = false }) => {
    await p.go(page('ExportController', 'index'));
    const ac = await p.evaluate(AUTOCOMPLETE);
    const dir = mkdtempSync(join(profile, 'dl-'));
    await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: dir, browserContextId: p.contextId, eventsEnabled: true });
    const n = downloads.length;
    await p.evaluate(`(() => { document.querySelector('[data-vault-export-passphrase]').value = ${JSON.stringify(passphrase)};
      document.querySelector('[data-vault-export-shared]').checked = ${includeShared};
      document.querySelector('[data-vault-export-password]').value = ${JSON.stringify(EXPORT_PASSWORD)};
      document.querySelector('[data-vault-export-confirm]').value = ${JSON.stringify(EXPORT_PASSWORD)};
      document.querySelector('[data-vault-export-submit]').click(); return true; })()`);
    await p.waitFor(`!document.querySelector('[data-vault-export-submit]').disabled && !document.querySelector('[data-vault-export-status]').hidden
      && document.querySelector('[data-vault-export-progress]').hidden`, 'the export', 300000);
    const st = await p.evaluate(`(() => { const e = document.querySelector('[data-vault-export-status]'); return {text: e.textContent, bad: e.classList.contains('vault-bad')}; })()`);
    for (let i = 0; i < 100 && downloads.length > n && downloads[downloads.length - 1].state !== 'completed'; i++) await sleep(100);
    const file = readdirSync(dir).find((f) => f.endsWith('.kdbx'));
    let entries = null;
    if (file) {
      const { vault } = loadKdbxPage({ vault: true });
      const db = await vault.load(new Uint8Array(readFileSync(join(dir, file))), EXPORT_PASSWORD);
      entries = [];
      const val = (v) => (v && typeof v.getText === 'function' ? v.getText() : v ?? '');
      const walk = (g, path) => {
        for (const e of g.entries) entries.push({ path, title: val(e.fields.get('Title')), password: val(e.fields.get('Password')), notes: val(e.fields.get('Notes')) });
        for (const c of g.groups) walk(c, [...path, c.name]);
      };
      walk(db.getDefaultGroup(), []);
    }
    return { st, ac, file, entries, started: downloads.length > n };
  };
  let ex = await exportRun(a, A, { passphrase: 'not my passphrase at all' });
  check(JSON.stringify(ex.ac.map((x) => [x.id, x.ac, x.pm])) === JSON.stringify([['vaultExportForm', 'off', false], ['vault-export-passphrase', 'off', true], ['vault-new-passphrase', 'off', true], ['vault-new-confirm', 'off', true]]), `export (R70): re-auth and export password inputs autocomplete=off with the 1p/lp/bw ignore hints, form off (${JSON.stringify(ex.ac.map((x) => [x.id, x.ac, x.pm]))})`);
  check(ex.st.bad && !ex.started && !ex.file, `export with a wrong passphrase: refused ("${ex.st.text}"), nothing downloaded`);
  ex = await exportRun(a, A, { passphrase: A.passphrase });
  const mine = ex.entries && ex.entries.find((e) => e.title === ENTRY.title);
  check(!ex.st.bad && !!ex.file && ex.entries.length === 6 && mine && mine.password === B_PASSWORD && mine.notes === ENTRY.notes && JSON.stringify(mine.path) === JSON.stringify([GROUP]),
    `export: ${ex.file} opens with the export password in kdbxweb; ${ex.entries && ex.entries.length} entries, the editor's entry byte for byte ("${ex.st.text}")`);
  check(ex.entries.filter((e) => /^Imported \d{4}-\d{2}-\d{2}$/.test(e.path[0] || '')).length === 5, 'export: the five imported entries are in it');

  // B's "shared with me" export: only live grants. E2 is revoked through the dialog; E3's grant has expired.
  const e2 = (await a.api('save', { type: 'entry', group_id: groupId, payload: { title: `E2E revoked ${tag}`, password: 'revoked-pw' } })).id;
  const e3 = (await a.api('save', { type: 'entry', group_id: groupId, payload: { title: `E2E expired ${tag}`, password: 'expired-pw' } })).id;
  for (const id of [e2, e3]) {
    const g = await a.api('grant', { type: 'entry', id, grantee_id: B.id, perm: 'read', expires_at: '' });
    check(g.ok === true, `A shares entry ${id} with B (${JSON.stringify(g)})`);
  }
  await a.go(page('VaultController', 'index'));
  await openGroup(a, groupId);
  await openRow(a, `E2E revoked ${tag}`);
  await a.click('[data-vault-detail] [data-vault-action="share-entry"]');
  await a.waitFor(`!!document.querySelector('[data-vault-share-grants] [data-vault-revoke="${B.id}"]')`, 'the grant to revoke');
  await a.evaluate(`(() => { window.confirm = () => true; document.querySelector('[data-vault-revoke="${B.id}"]').click(); return true; })()`);
  await a.waitFor(`document.querySelectorAll('[data-vault-share-grants] li').length === 0 && !document.querySelector('[data-vault-share-rotate]').hidden`, 'the revoke');
  check(true, 'A revokes E2 through the share dialog; the rotate suggestion shows');
  sql('UPDATE vault_grants SET expires_at = ? WHERE subject_type = ? AND subject_id = ? AND grantee_id = ?', [Math.floor(Date.now() / 1000) - 60, 'entry', e3, B.id]);
  await b.go(page('VaultController', 'index'));
  ex = await exportRun(b, B, { passphrase: B.passphrase, includeShared: true });
  const bTitles = (ex.entries || []).map((e) => e.title).sort();
  check(!ex.st.bad && JSON.stringify(bTitles) === JSON.stringify([ENTRY.title]), `B's export with "shared with me": only the live grant (${JSON.stringify(bTitles)})`);
  ex = await exportRun(b, B, { passphrase: B.passphrase, includeShared: false });
  check(!ex.st.bad && ex.entries && ex.entries.length === 0, `B's export without it: nothing of A's (${ex.entries && ex.entries.length})`);
  await b.park();

  // ── Review Focus 5: two tabs, one entry; the second save gets the conflict message ──────────────────────────
  const a2 = await opened(ctxA);
  for (const p of [a, a2]) {
    await p.go(page('VaultController', 'index'));
    await openGroup(p, groupId);
    await openRow(p, ENTRY.title);
    await p.click('[data-vault-detail] [data-vault-action="edit"]');
    await p.waitFor(`!document.querySelector('[data-vault-form="edit"]').hidden && document.querySelector('[data-vault-form="edit"]').elements.password.value !== ''`, 'the editor in both tabs');
  }
  const TAB1 = `tab-one-${tag}`;
  await a.evaluate(`(() => { document.querySelector('[data-vault-form="edit"]').elements.password.value = ${JSON.stringify(TAB1)};
    document.querySelector('[data-vault-form="edit"] button[type="submit"]').click(); return true; })()`);
  await toast(a, '/^Saved\\.$/', 'tab 1 saved');
  await a2.evaluate(`(() => { document.querySelector('[data-vault-form="edit"]').elements.notes.value = 'tab two notes';
    document.querySelector('[data-vault-form="edit"] button[type="submit"]').click(); return true; })()`);
  await toast(a2, '/changed elsewhere/', 'tab 2 conflict');
  check(await a2.text('[data-vault-toast]') === 'This entry was changed elsewhere. Reload it, then edit again.', 'tab 2\'s save gets the conflict message');
  check(await field(a, entryId, 'password') === TAB1 && await field(a, entryId, 'notes') === ENTRY.notes, 'tab 1\'s save stands; tab 2 overwrote nothing');
  await a2.close();

  // ── Review Focus 1: another host or port ─────────────────────────────────────────────────────────────────────
  const failedBefore = Number(one("SELECT COUNT(*) AS n FROM vault_audit WHERE (target_user_id = ? OR actor_user_id = ?) AND action = 'unlock_failed'", [A.id, A.id]).n);
  for (const other of [`http://vault-e2e.localhost:${PORT}`, `http://127.0.0.1:${PORT}`]) {
    const ctx = await newContext();
    const o = await opened(ctx);
    await o.login(A, other);
    await o.go(page('VaultController', 'index', other));
    const view = await o.evaluate(`({ state: document.getElementById('vault-app').getAttribute('data-vault-state'), notice: (document.querySelector('.vault-https') || {}).textContent || '',
      form: !!document.querySelector('[data-vault-form="unlock"]') })`);
    check(view.state === 'locked' && /Vault needs HTTPS/.test(view.notice) && !view.form, `${other}: the locked page says plainly that Vault needs HTTPS, no unlock form (${JSON.stringify(view)})`);
    const u = await o.api('unlock', { passphrase: A.passphrase });
    check(u.ok === false && u.reason === 'https_required', `${other}: an unlock POST is refused with https_required, not unlock_failed (${JSON.stringify(u)})`);
    await o.close();
  }
  {
    const ctx = await newContext();
    const o = await opened(ctx);
    await o.login(A, RELAY);
    await o.go(page('VaultController', 'index', RELAY));
    check(await o.state() === 'locked', `${RELAY} (another port): a new session starts locked`);
    const u = await o.api('unlock', { passphrase: A.passphrase });
    check(u.ok === true, `${RELAY}: the passphrase unlocks there too (the dev opt-in is host-wide; no silent lock-out) (${JSON.stringify(u)})`);
    await o.close();
  }
  const failedAfter = Number(one("SELECT COUNT(*) AS n FROM vault_audit WHERE (target_user_id = ? OR actor_user_id = ?) AND action = 'unlock_failed'", [A.id, A.id]).n);
  check(failedAfter === failedBefore, `the other-host attempts counted no failed guess (${failedBefore} → ${failedAfter})`);
  // Each login above was an auth.success for A, which locks every vault session of A (spec: Throttling, "a login
  // deletes the user's existing vault sessions"): A's first tab is locked, and its passphrase still opens it.
  await a.go(page('VaultController', 'index'));
  check(await a.state() === 'locked', 'A\'s tab at localhost:8081 was locked by A\'s logins elsewhere');
  const back = await a.api('unlock', { passphrase: A.passphrase });
  await a.go(page('VaultController', 'index'));
  check(back.ok === true && await a.state() === 'unlocked', 'and unlocks again at localhost:8081 (no lock-out)');

  // ── Review Focus 4: A is disabled while unlocked → the next vault request locks ──────────────────────────────
  check(Number(one('SELECT COUNT(*) AS n FROM vault_sessions WHERE user_id = ?', [A.id]).n) >= 1, 'A has a live vault session');
  sql('UPDATE users SET is_active = 0 WHERE id = ?', [A.id]);
  const afterDisable = await a.evaluate(`fetch(JSON.parse(document.getElementById('vault-app').getAttribute('data-endpoints')).tree, {method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, body: 'csrf_token=' + encodeURIComponent(document.getElementById('vault-app').getAttribute('data-csrf'))})
    .then(async (r) => ({ status: r.status, url: r.url, body: (await r.text()).slice(0, 4000) }))`);
  check(!afterDisable.body.includes(GROUP) && !/"ok":\s*true/.test(afterDisable.body), `disabled A's next vault request gets nothing (${afterDisable.status} ${afterDisable.url.replace(BASE, '')}: ${JSON.stringify(afterDisable.body.replace(/\s+/g, ' ').slice(0, 70))})`);
  // Kanboard answers that request itself (it flushes a disabled user's session before any controller runs), so the
  // vault session row is removed by the next purge (Purger, T24 fix), which any vault request runs once a minute.
  sql('UPDATE vault_meta SET last_purge_at = NULL WHERE id = 1');
  await b.go(page('VaultController', 'index'));
  await b.park();
  check(Number(one('SELECT COUNT(*) AS n FROM vault_sessions WHERE user_id = ?', [A.id]).n) === 0, 'the next purge (B\'s next vault page) removes every vault session of disabled A');
  sql('UPDATE users SET is_active = 1 WHERE id = ?', [A.id]);
  await a.login(A);
  await a.go(page('VaultController', 'index'));
  check(await a.state() === 'locked', 'enabled again and logged in: the vault is locked');

  // ── CSP sweep and Kanboard's own pages ───────────────────────────────────────────────────────────────────────
  const kbPages = [`${BASE}/?controller=DashboardController&action=show`, `${BASE}/?controller=BoardViewController&action=show&project_id=${projectId}`,
    `${BASE}/?controller=ProjectListController&action=show`, `${BASE}/?controller=UserViewController&action=show&user_id=${A.id}`];
  for (const url of kbPages) {
    const n = a.requests.length;
    await a.go(url);
    const scripts = await a.evaluate(`[...document.scripts].map((s) => s.src).filter((s) => /plugins\\/Vault\\//.test(s))`);
    const fetched = a.requests.slice(n).filter((q) => /plugins\/Vault\//.test(q.url) && /\.js(\?|$)/.test(q.url));
    check(scripts.length === 0 && fetched.length === 0, `${url.replace(BASE, '')}: no Vault script (${JSON.stringify(scripts)})`);
  }
  const csp = allPages.flatMap((p) => p.problems).filter((x) => x.source === 'security' || /Content Security Policy|Refused to/i.test(x.text));
  const vaultVisited = [...new Set(allPages.flatMap((p) => p.visited).filter((u) => /plugin=Vault/.test(u)).map((u) => new URL(u).searchParams.get('controller')))];
  check(csp.length === 0, `no CSP violation on any page (${vaultVisited.join(', ')})${csp.length ? ': ' + JSON.stringify(csp).slice(0, 600) : ''}`);
  const docs = allPages.flatMap((p) => p.responses).filter((x) => x.type === 'Document' && /plugin=Vault/.test(x.url) && x.status === 200);
  const header = (h, n) => Object.entries(h || {}).find(([k]) => k.toLowerCase() === n)?.[1] || '';
  check(docs.length > 10 && docs.every((d) => /frame-ancestors 'none'/.test(header(d.headers, 'content-security-policy')) && /DENY/i.test(header(d.headers, 'x-frame-options'))),
    `every Vault page (${docs.length}) answers with its CSP plus frame-ancestors 'none' and X-Frame-Options DENY`);
  const errors = allPages.flatMap((p) => p.problems).filter((x) => x.source === 'exception' || (x.source === 'javascript'));
  check(errors.length === 0, `no script error on any page${errors.length ? ': ' + JSON.stringify(errors).slice(0, 600) : ''}`);
  const secrets = [A.passphrase, B.passphrase, ENTRY.password, B_PASSWORD, EXPORT_PASSWORD, ONETIME_TEXT, ONETIME_TEXT_2, ot2.passphrase];
  const urls = allPages.flatMap((p) => p.requests).map((q) => q.url);
  check(urls.every((u) => secrets.every((x) => !u.includes(x) && !u.includes(encodeURIComponent(x)))), `no secret in any request URL (${urls.length} requests)`);
  const exportPosts = allPages.flatMap((p) => p.requests).filter((q) => /controller=ExportController&action=export(&|$)/.test(q.url));
  check(exportPosts.every((q) => !q.body.includes(encodeURIComponent(EXPORT_PASSWORD)) && !q.body.includes(EXPORT_PASSWORD)), 'the export password never leaves the browser');
} catch (e) {
  failures++;
  console.error(`ERROR ${e.stack || e}`);
} finally {
  cleanup();
}
check(idOf(A.username) === 0 && idOf(B.username) === 0, 'dev users removed');
console.log(failures === 0 ? 'E2E PASS' : `E2E FAIL (${failures})`);
process.exit(failures === 0 ? 0 : 1);
