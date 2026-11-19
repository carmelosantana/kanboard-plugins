// End-to-end gate for Vault's KeePassXC import (T22; spec: KeePassXC import and export → CI gate, import half).
// Zero dependencies (Node 22: built-in WebSocket and child_process). Drives headless Chrome over raw CDP against the
// :8081 dev stack (kb-suite, with VAULT_DEV_INSECURE_LOCALHOST=1), through Vault's real import page: Kanboard's CSP,
// the real DOMParser, kdbxweb, the Argon2 module worker, vault-kdbx-header.js and vault-import.js.
//
//   enrol a vault → import each KeePassXC fixture (KDBX 3.1 AES-KDF, 4.0 Argon2d + AES, 4.0 Argon2id + ChaCha20,
//   password + key file) through the file inputs → read every entry back through vault/list, vault/entry and
//   vault/field and compare it field for field with KPXC_EXPECTED → the crafted files (Twofish, Argon2 memory,
//   AES rounds, trailing bytes, truncated, a YubiKey challenge-response file) are refused with their message and
//   send nothing → no request carries a fixture file name, no CSP violation, nothing in localStorage.
//
// The throwaway user (vault-e2e-t22) is created and deleted by PDO inside kb-suite (dev only; never the production
// MCP). Chrome runs with background networking off.
//
// Run: node scripts/e2e/vault-import.mjs        CHROME=/path/to/chrome overrides /usr/bin/google-chrome.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { randomBytes } from 'node:crypto';

const BASE = process.env.KB_URL || 'http://localhost:8081';
// This script creates and deletes users: refuse anything but a local dev stack.
if (!['localhost', '127.0.0.1'].includes(new URL(BASE).hostname)) {
  console.error(`refusing: KB_URL must be http://localhost or http://127.0.0.1, not ${BASE}`);
  process.exit(2);
}
const CONTAINER = process.env.KB_CONTAINER || 'kb-suite';
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const FIXTURES = resolve(dirname(fileURLToPath(import.meta.url)), '../../Vault/Test/fixtures/kdbx');
const { PASSWORD, KPXC, KPXC_EXPECTED, FILES, CRAFTED } = await import(pathToFileURL(join(FIXTURES, 'fixtures.mjs')).href);
const USER = 'vault-e2e-t22';
const LOGIN = randomBytes(12).toString('base64url');
const PASSPHRASE = 'e2e ' + randomBytes(12).toString('base64url') + ' passphrase';
const page = (controller, action) => `${BASE}/?controller=${controller}&action=${action}&plugin=Vault`;

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
const userId = () => Number(one('SELECT id FROM users WHERE username = ?', [USER])?.id ?? 0);

let failures = 0;
function check(ok, what) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`);
  if (!ok) failures++;
}

// ── Chrome over CDP ─────────────────────────────────────────────────────────────────────────────────────────────
const profile = mkdtempSync(join(tmpdir(), 'vault-e2e-t22-'));
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
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
  try { dropUser(); } catch (e) { console.error(`cleanup: ${e.message}`); }
};
// The user and their vault (the principal's rows cascade). SQLite reuses a deleted user's id, and the purge sweeps
// an orphaned principal only once a minute (R26), so a run right after another would otherwise meet the last run's
// vault: an orphan (live_user_id NULL) at the new user's id is removed as the sweep would.
function dropUser() {
  sql('DELETE FROM vault_principals WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [USER]);
  sql('DELETE FROM users WHERE username = ?', [USER]);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

try {
  dropUser();
  sql("INSERT INTO users (username, name, role, is_active, password) VALUES (?, 'Vault E2E T22', 'app-user', 1, ?)", [USER], LOGIN);
  check(userId() > 0, 'dev user created by PDO');
  sql('DELETE FROM vault_principals WHERE user_id = ? AND live_user_id IS NULL', [userId()]);

  const portFile = join(profile, 'DevToolsActivePort');
  for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
  const [port, wsPath] = readFileSync(portFile, 'utf8').trim().split('\n');
  ws = new WebSocket(`ws://127.0.0.1:${port}${wsPath}`);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  let nextId = 1;
  const pending = new Map();
  const requests = [];
  const problems = [];
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve: ok, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(`${msg.error.message} ${msg.error.data || ''}`)) : ok(msg.result);
    } else if (msg.method === 'Network.requestWillBeSent') {
      requests.push({ url: msg.params.request.url, body: msg.params.request.postData || '' });
    } else if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
      problems.push(msg.params.entry.text);
    } else if (msg.method === 'Runtime.exceptionThrown') {
      problems.push(JSON.stringify(msg.params.exceptionDetails).slice(0, 300));
    }
  };
  const send = (method, params = {}, sessionId) => new Promise((ok, reject) => {
    const id = nextId++;
    pending.set(id, { resolve: ok, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const s = (m, p) => send(m, p, sessionId);
  await s('Page.enable');
  await s('Runtime.enable');
  await s('Log.enable');
  await s('Network.enable');
  await s('DOM.enable');

  const evaluate = async (expression) => {
    const r = await s('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 400));
    return r.result.value;
  };
  const waitFor = async (expression, what, ms = 60000) => {
    for (let t = 0; t < ms; t += 100) {
      try { if (await evaluate(expression)) return; } catch {}
      await sleep(100);
    }
    throw new Error(`timed out waiting for ${what} (at ${await evaluate("location.href + ' ' + document.title").catch(() => "?")})`);
  };
  const go = async (url) => {
    await s('Page.navigate', { url });
    await waitFor(`document.readyState === 'complete' && location.href === ${JSON.stringify(url)}`, `load ${url}`);
  };
  const api = (action, body) => evaluate(`window.KBVault.api(${JSON.stringify(action)}, ${JSON.stringify(body)})`);
  const setFiles = async (selector, paths) => {
    const { root } = await s('DOM.getDocument', { depth: 0 });
    const { nodeId } = await s('DOM.querySelector', { nodeId: root.nodeId, selector });
    await s('DOM.setFileInputFiles', { nodeId, files: paths });
  };
  const status = () => evaluate(`(() => { const e = document.querySelector('[data-vault-import-status]'); return {text: e.textContent, bad: e.classList.contains('vault-bad'), hidden: e.hidden}; })()`);

  // One import through the page: choose the file (and key file), type the password, press Import, wait for the end.
  const importFile = async (file, keyFile) => {
    await go(page('ImportController', 'index'));
    await setFiles('[data-vault-import-file]', [join(FIXTURES, file)]);
    if (keyFile) await setFiles('[data-vault-import-keyfile]', [join(FIXTURES, keyFile)]);
    await evaluate(`(() => { document.querySelector('[data-vault-import-password]').value = ${JSON.stringify(PASSWORD)};
      document.querySelector('[data-vault-import-form]').requestSubmit(); return true; })()`);
    await waitFor(`!document.querySelector('[data-vault-import-submit]').disabled && !document.querySelector('[data-vault-import-status]').hidden
      && document.querySelector('[data-vault-import-progress]').hidden`, `the import of ${file} to end`, 120000);
    return status();
  };

  // ── Login and enrolment ──────────────────────────────────────────────────────────────────────────────────────
  await go(`${BASE}/?controller=AuthController&action=login`);
  await evaluate(`(() => { const f = document.querySelector('form'); f.querySelector('[name=username]').value = ${JSON.stringify(USER)};
    f.querySelector('[name=password]').value = ${JSON.stringify(LOGIN)}; f.submit(); return true; })()`);
  await waitFor(`!location.href.includes('action=login') && document.readyState === 'complete'`, 'login');
  await go(page('VaultController', 'index'));
  await evaluate(`(() => { document.getElementById('vault-new-passphrase').value = ${JSON.stringify(PASSPHRASE)};
    document.getElementById('vault-new-confirm').value = ${JSON.stringify(PASSPHRASE)};
    document.querySelector('[data-vault-form="enrol"]').requestSubmit(); return true; })()`);
  await waitFor(`(document.getElementById('vault-app') || {getAttribute: () => ''}).getAttribute('data-vault-state') === 'unlocked'`, 'enrolled and unlocked');
  const uid = userId();
  check(await evaluate(`!!document.querySelector('[data-vault-link="import"]')`), 'the vault toolbar links to the import page');

  // ── The four KeePassXC fixtures ───────────────────────────────────────────────────────────────────────────────
  await go(page('ImportController', 'index'));
  const scripts = await evaluate(`[...document.querySelectorAll('script[src*="plugins/Vault/"]')].map((e) => new URL(e.src).pathname.replace(/^.*\\/Assets\\//, ''))`);
  check(JSON.stringify(scripts) === JSON.stringify(['js/vault.js', 'vendor/kdbxweb/kdbxweb.js', 'js/vault-kdbx.js', 'js/vault-kdbx-header.js', 'js/vault-import.js']), `page scripts: ${scripts.join(', ')}`);

  for (const [name, c] of Object.entries(KPXC)) {
    const before = (await api('tree', {})).groups.filter((g) => g.parent_id === null).map((g) => g.id);
    const t0 = Date.now();
    const st = await importFile(c.file, c.keyFile);
    check(!st.bad && /finished/i.test(st.text), `${name}: imported in ${Date.now() - t0} ms ("${st.text}")`);
    const report = await evaluate(`Object.fromEntries([...document.querySelectorAll('[data-vault-import-report] [data-field]')].map((e) => [e.getAttribute('data-field'), e.textContent]))`);
    check(report.created === '5' && report.groups === '4' && report.skipped_attachments === '1', `${name}: report ${JSON.stringify(report)}`);
    check(await evaluate(`document.querySelector('[data-vault-import-password]').value === '' && document.querySelector('[data-vault-import-file]').value === ''`), `${name}: password and file inputs cleared`);

    // Read everything back through the page's own endpoints and compare field for field.
    const tree = (await api('tree', {})).groups;
    const roots = tree.filter((g) => g.parent_id === null && !before.includes(g.id));
    check(roots.length === 1 && /^Imported \d{4}-\d{2}-\d{2}$/.test(roots[0].name), `${name}: one new group "${roots[0] && roots[0].name}"`);
    const byId = Object.fromEntries(tree.map((g) => [g.id, g]));
    const pathOf = (id) => {
      const out = [];
      for (let g = byId[id]; g && g.id !== roots[0].id; g = byId[g.parent_id]) out.unshift(g.name);
      return out;
    };
    const got = [];
    const under = (g) => g.id === roots[0].id || (g.parent_id !== null && byId[g.parent_id] !== undefined && under(byId[g.parent_id]));
    for (const g of tree.filter(under)) {
      for (const row of (await api('list', { group_id: g.id, offset: 0 })).entries) {
        const e = (await api('entry', { id: row.id })).entry;
        const field = async (f) => (await api('field', { id: row.id, field: f })).value;
        const fields = [];
        for (const f of e.fields) fields.push({ name: f.name, value: await field('field:' + f.name), protected: f.protected });
        got.push({ path: pathOf(g.id), payload: {
          title: e.title, username: e.username, password: await field('password'), url: e.url, notes: await field('notes'),
          tags: e.tags, expires_at: e.expires_at, otp: await field('otp'), fields,
        } });
      }
    }
    const key = (x) => JSON.stringify(x.path) + x.payload.title;
    got.sort((a, b) => key(a).localeCompare(key(b)));
    const want = [...KPXC_EXPECTED].sort((a, b) => key(a).localeCompare(key(b)));
    check(JSON.stringify(got) === JSON.stringify(want), `${name}: every entry matches field for field (${got.length} entries)`);
    if (JSON.stringify(got) !== JSON.stringify(want)) console.log(JSON.stringify(got), '\n', JSON.stringify(want));
  }

  // ── Refused files: the explicit message, and nothing sent ─────────────────────────────────────────────────────
  const importPost = (r) => /import\/(start|batch)|controller=ImportController&action=(start|batch)/.test(r.url);
  check(requests.filter(importPost).length === Object.keys(KPXC).length * 6, `the imports sent ${requests.filter(importPost).length} POSTs (a start and 5 batches each)`);
  const refusals = [
    [CRAFTED.twofish, /Twofish/],
    [FILES.hostileM, /1 GiB/],
    [CRAFTED.aesRounds4, /AES-KDF/],
    [CRAFTED.trailing, /damaged/],
    [CRAFTED.truncated, /damaged/],
    [CRAFTED.challengeResponse, /YubiKey/],
  ];
  for (const [file, want] of refusals) {
    const sent = requests.filter(importPost).length;
    const st = await importFile(file);
    const after = requests.filter(importPost).length;
    check(st.bad && want.test(st.text) && after === sent, `${file}: refused ("${st.text.slice(0, 90)}…"), nothing sent`);
  }

  // ── Hygiene ───────────────────────────────────────────────────────────────────────────────────────────────────
  const names = [...Object.values(KPXC).flatMap((c) => [c.file, c.keyFile].filter(Boolean)), ...Object.values(CRAFTED), 'kpxc-', '.kdbx', '.keyx'];
  const leaked = requests.filter((r) => names.some((n) => (r.url + r.body).includes(n)));
  check(leaked.length === 0, `no request carries a file name (${requests.length} requests)`);
  check(requests.every((r) => !(r.url + r.body).includes(encodeURIComponent(PASSWORD)) && !(r.url + r.body).includes(PASSWORD)), 'the master password is never sent');
  check(requests.filter(importPost).every((r) => !r.url.includes('import_id=') && !r.url.includes('entries=')), 'nothing of the import in a URL');
  // Kanboard's own scripts may keep UI state in localStorage; none of it may hold anything from the import.
  const storage = await evaluate('JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)])');
  check(!/Mail|pass word|otpauth|kdbx|Imported/.test(storage), `nothing from the import in browser storage (${storage.slice(0, 120)})`);
  const csp = problems.filter((p) => /Content Security Policy|Refused to/i.test(p));
  check(csp.length === 0, `no CSP violation${csp.length ? ': ' + csp.join(' | ') : ''}`);
  const audit = sql("SELECT action, COUNT(*) AS n, SUM(count) AS c FROM vault_audit WHERE actor_user_id = ? AND action = 'import'", [uid])[0];
  check(Number(audit.n) === Object.keys(KPXC).length * 5 && Number(audit.c) === Object.keys(KPXC).length * 5, `import audit rows: ${JSON.stringify(audit)}`);
  if (problems.length) console.log('console errors:', problems.join(' | ').slice(0, 800));
} catch (e) {
  failures++;
  console.error(`ERROR ${e.stack || e}`);
} finally {
  cleanup();
}
console.log(failures === 0 ? 'E2E PASS' : `E2E FAIL (${failures})`);
process.exit(failures === 0 ? 0 : 1);
