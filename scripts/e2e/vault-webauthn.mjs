// End-to-end gate for Vault's hardware keys (T20; spec: Hardware keys → Gate). Zero dependencies (Node 22: built-in
// WebSocket and child_process). Drives headless Chrome over raw CDP against the :8081 dev stack (kb-suite, with
// VAULT_DEV_INSECURE_LOCALHOST=1 and Application URL http://localhost:8081/), with two CDP virtual authenticators
// (CTAP 2.1, PRF on), through Vault's real pages and vault-webauthn.js:
//
//   enrol a vault → add key A (its passphrase wrap stays) → add key B (the passphrase wrap goes, the vault rotates)
//   → lock → unlock with passphrase + key A → replay that exact unlock POST (refused: its challenge is spent)
//   → the passphrase alone (refused) → a wrong passphrase with key B (refused) → unlock with key B
//   → remove key B with key A (a passphrase wrap is restored) → the passphrase alone unlocks again.
//
// Each authenticator answers only the ceremony meant for it: a page-side gate (installed with CDP, test only) holds
// every navigator.credentials call until this script has turned user presence on for the right authenticator and
// off for the other, so Chrome's choice between two plugged-in keys is never left to chance.
//
// The throwaway user (vault-e2e-t20) is created and deleted by PDO inside kb-suite (dev only; never the production
// MCP). Chrome runs with background networking off (T19 M2).
//
// Run: node scripts/e2e/vault-webauthn.mjs        CHROME=/path/to/chrome overrides /usr/bin/google-chrome.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.KB_URL || 'http://localhost:8081';
const CONTAINER = process.env.KB_CONTAINER || 'kb-suite';
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const USER = 'vault-e2e-t20';
const LOGIN = randomBytes(12).toString('base64url');
const PASSPHRASE = 'e2e ' + randomBytes(12).toString('base64url') + ' passphrase';
const page = (controller, action, extra = '') => `${BASE}/?controller=${controller}&action=${action}&plugin=Vault${extra}`;

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
const profile = mkdtempSync(join(tmpdir(), 'vault-e2e-t20-'));
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
  try { sql('DELETE FROM users WHERE username = ?', [USER]); } catch (e) { console.error(`cleanup: ${e.message}`); }
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

try {
  sql('DELETE FROM users WHERE username = ?', [USER]);
  sql("INSERT INTO users (username, name, role, is_active, password) VALUES (?, 'Vault E2E T20', 'app-user', 1, ?)", [USER], LOGIN);
  check(userId() > 0, 'dev user created by PDO');

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
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(`${msg.error.message} ${msg.error.data || ''}`)) : resolve(msg.result);
    }
  };
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const s = (m, p) => send(m, p, sessionId);
  await s('Page.enable');
  await s('Runtime.enable');

  // Test-only page instrumentation: the credential gate.
  await s('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
    const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\\+/g, '-').replace(/\\//g, '_').replace(/=+$/, '');
    window.__e2e = { calls: [], gate: 0 };
    const wait = (n) => new Promise((r) => { const t = () => (window.__e2e.gate >= n ? r() : setTimeout(t, 20)); t(); });
    if (navigator.credentials) {
      const realGet = navigator.credentials.get.bind(navigator.credentials);
      const realCreate = navigator.credentials.create.bind(navigator.credentials);
      navigator.credentials.get = async (o) => {
        const n = window.__e2e.calls.push({ kind: 'get', allow: (o.publicKey.allowCredentials || []).map((c) => b64u(c.id)) });
        await wait(n);
        return realGet(o);
      };
      navigator.credentials.create = async (o) => {
        const n = window.__e2e.calls.push({ kind: 'create', exclude: (o.publicKey.excludeCredentials || []).map((c) => b64u(c.id)) });
        await wait(n);
        return realCreate(o);
      };
    }
  })();` });

  const evaluate = async (expression) => {
    const r = await s('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 400));
    return r.result.value;
  };
  const waitFor = async (expression, what, ms = 20000) => {
    for (let t = 0; t < ms; t += 100) {
      try { if (await evaluate(expression)) return; } catch {}
      await sleep(100);
    }
    throw new Error(`timed out waiting for ${what}`);
  };
  const go = async (url) => {
    await s('Page.navigate', { url });
    await waitFor(`document.readyState === 'complete' && location.href === ${JSON.stringify(url)}`, `load ${url}`);
  };
  const state = () => evaluate(`(document.getElementById('vault-app') || {getAttribute: () => null}).getAttribute('data-vault-state')`);

  // Virtual authenticators: presence is switched per ceremony by serve().
  await s('WebAuthn.enable', { enableUI: false });
  const authenticators = {};
  const addAuthenticator = async (name) => {
    const { authenticatorId } = await s('WebAuthn.addVirtualAuthenticator', { options: {
      protocol: 'ctap2', ctap2Version: 'ctap2_1', transport: 'usb', hasResidentKey: true,
      hasUserVerification: true, isUserVerified: true, hasPrf: true, automaticPresenceSimulation: false,
    } });
    authenticators[name] = authenticatorId;
  };
  const credsOf = async (name) => (await s('WebAuthn.getCredentials', { authenticatorId: authenticators[name] })).credentials
    .map((c) => Buffer.from(c.credentialId, 'base64').toString('base64url'));
  // Releases the next held credential call to authenticator `who` (or the one holding an allowed credential).
  const serve = async (who, expectKind) => {
    await waitFor(`window.__e2e.calls.length > window.__e2e.gate`, `a ${expectKind} call`);
    const call = (await evaluate('window.__e2e.calls'))[await evaluate('window.__e2e.gate')];
    if (call.kind !== expectKind) throw new Error(`expected ${expectKind}, got ${call.kind}`);
    let target = who;
    if (!target) {
      for (const name of Object.keys(authenticators)) {
        if ((await credsOf(name)).some((id) => call.allow.includes(id))) { target = name; break; }
      }
    }
    for (const [name, id] of Object.entries(authenticators)) {
      await s('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId: id, enabled: name === target });
    }
    await evaluate('window.__e2e.gate++');
    return call;
  };
  const api = (action, body) => evaluate(`window.KBVault.api(${JSON.stringify(action)}, ${JSON.stringify(body)})`);
  const statusText = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ''`);

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
  check(one('SELECT COUNT(*) AS n FROM vault_principals WHERE user_id = ?', [uid]).n === 1, 'vault enrolled');

  // ── Key A: create + its own PRF get. The passphrase wrap stays. ───────────────────────────────────────────────
  await addAuthenticator('A');
  const register = async (label, steps, count) => {
    await go(page('HardwareKeyController', 'index'));
    await evaluate(`(() => { document.getElementById('vault-hw-label').value = ${JSON.stringify(label)};
      document.getElementById('vault-hw-passphrase').value = ${JSON.stringify(PASSPHRASE)};
      document.querySelector('[data-vault-action="hw-register"]').click(); return true; })()`);
    for (const [who, kind] of steps) await serve(who, kind);
    await waitFor(`document.querySelectorAll('[data-vault-hw-key]').length === ${count}`, `${label} registered`);
  };
  await register('Key A', [['A', 'create'], ['A', 'get']], 1);
  const wraps = () => sql('SELECT method, status, key_gen FROM vault_wraps WHERE user_id = ? ORDER BY id', [uid]);
  check(one('SELECT COUNT(*) AS n FROM vault_webauthn WHERE user_id = ?', [uid]).n === 1, 'key A stored');
  check(JSON.stringify(wraps().map((w) => w.method).sort()) === JSON.stringify(['passphrase', 'passphrase+prf']), 'key A: the passphrase wrap stays, one prf wrap');
  const rowA = one('SELECT credential_id, prf_salt, origin, rp_id, sign_count FROM vault_webauthn WHERE user_id = ?', [uid]);
  check(rowA.origin === BASE && rowA.rp_id === 'localhost', `binding pinned (${rowA.origin}, ${rowA.rp_id})`);
  check((await credsOf('A')).includes(rowA.credential_id), 'the stored id is the authenticator credential id');

  // ── Key B: create on B, its PRF get, then key A presents. The passphrase wrap goes; the vault rotates. ────────
  await addAuthenticator('B');
  await register('Key B', [['B', 'create'], ['B', 'get'], ['A', 'get']], 2);
  const after = wraps();
  check(after.length === 2 && after.every((w) => w.method === 'passphrase+prf' && w.status === 'active' && Number(w.key_gen) === 2), 'key B: two prf wraps at key_gen 2, no passphrase wrap');
  check(one('SELECT key_gen FROM vault_principals WHERE user_id = ?', [uid]).key_gen == 2 && one('SELECT COUNT(*) AS n FROM vault_rotations WHERE user_id = ?', [uid]).n === 0, 'rotation finished');
  const credB = (await credsOf('B'))[0];

  // ── Lock, then unlock with passphrase + key A ────────────────────────────────────────────────────────────────
  const lock = async () => {
    await api('lock', {});
    await go(page('VaultController', 'index'));
    await waitFor(`document.getElementById('vault-app').getAttribute('data-vault-state') === 'locked'`, 'locked');
  };
  const unlockWithKey = async (passphrase, who) => {
    await evaluate(`(() => { document.getElementById('vault-passphrase').value = ${JSON.stringify(passphrase)};
      document.querySelector('[data-vault-action="hw-unlock"]').click(); return true; })()`);
    await serve(who, 'get');
  };
  await lock();
  check(await evaluate(`!!document.querySelector('[data-vault-action="hw-unlock"]') && !document.querySelector('[data-vault-form="unlock"] button[type="submit"]')`), 'locked page: hardware-key unlock, no passphrase-only button with two keys');
  await unlockWithKey(PASSPHRASE, 'A');
  await waitFor(`document.getElementById('vault-app') && document.getElementById('vault-app').getAttribute('data-vault-state') === 'unlocked'`, 'unlocked with key A');
  check(true, 'unlock with passphrase + key A');
  check(Number(one('SELECT sign_count FROM vault_webauthn WHERE credential_id = ?', [rowA.credential_id]).sign_count) > Number(rowA.sign_count), 'key A signCount advanced');

  // Replay: the same ceremony run by hand in the locked page, so its exact assertion and PRF output can be posted
  // twice. The first unlocks; after a lock, the identical second is refused (its challenge was consumed).
  await lock();
  const captured = await (async () => {
    const p = evaluate(`(async () => {
      const ch = await window.KBVault.api('hw_challenge', { purpose: 'unlock' });
      const unb = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
      const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\\+/g, '-').replace(/\\//g, '_').replace(/=+$/, '');
      const byCred = {}; ch.credentials.forEach((c) => { byCred[c.id] = { first: unb(c.prf_salt) }; });
      const a = await navigator.credentials.get({ publicKey: { challenge: unb(ch.challenge), rpId: ch.rp_id, userVerification: 'preferred',
        allowCredentials: ch.credentials.map((c) => ({ type: 'public-key', id: unb(c.id) })), extensions: { prf: { evalByCredential: byCred } } } });
      const ext = a.getClientExtensionResults();
      const assertion = { credential_id: b64u(a.rawId), clientDataJSON: b64u(a.response.clientDataJSON), authenticatorData: b64u(a.response.authenticatorData),
        signature: b64u(a.response.signature), prf: b64u(ext.prf.results.first) };
      const first = await window.KBVault.api('hw_unlock', { passphrase: ${JSON.stringify(PASSPHRASE)}, assertion });
      await window.KBVault.api('lock', {});
      const replay = await window.KBVault.api('hw_unlock', { passphrase: ${JSON.stringify(PASSPHRASE)}, assertion });
      return { first, replay };
    })()`);
    await serve('A', 'get');
    return p;
  })();
  check(captured.first.ok === true, 'manual ceremony unlocks');
  check(captured.replay.ok === false && captured.replay.reason === 'unlock_failed', `the replayed PRF + assertion is refused (${JSON.stringify(captured.replay)})`);

  const alone = await api('unlock', { passphrase: PASSPHRASE });
  check(alone.ok === false && alone.reason === 'unlock_failed', 'the passphrase alone is refused with two keys');

  await go(page('VaultController', 'index'));
  await unlockWithKey('a wrong passphrase entirely', 'B');
  await waitFor(`(document.querySelector('[data-vault-form="unlock"] .vault-error') || {}).textContent.length > 0 && !document.querySelector('[data-vault-action="hw-unlock"]').disabled`, 'wrong-passphrase answer');
  check((await statusText('[data-vault-form="unlock"] .vault-error')).length > 0 && (await state()) === 'locked', 'key B with a wrong passphrase is refused');
  await unlockWithKey(PASSPHRASE, 'B');
  await waitFor(`document.getElementById('vault-app') && document.getElementById('vault-app').getAttribute('data-vault-state') === 'unlocked'`, 'unlocked with key B');
  check(true, 'unlock with passphrase + key B');

  // ── Remove key B with key A: one key left, so the passphrase wrap is restored ────────────────────────────────
  await go(page('HardwareKeyController', 'index'));
  await evaluate(`(() => { window.confirm = () => true; document.getElementById('vault-hw-remove-passphrase').value = ${JSON.stringify(PASSPHRASE)};
    document.querySelector('[data-vault-hw-remove="${credB}"]').click(); return true; })()`);
  await serve('A', 'get');
  await waitFor(`document.querySelectorAll('[data-vault-hw-key]').length === 1`, 'key B removed');
  check(JSON.stringify(wraps().map((w) => w.method).sort()) === JSON.stringify(['passphrase', 'passphrase+prf']), 'key B removed: the passphrase wrap is back');
  await lock();
  const back = await api('unlock', { passphrase: PASSPHRASE });
  check(back.ok === true, 'the passphrase alone unlocks again with one key');
  const audit = sql("SELECT action, COUNT(*) AS n FROM vault_audit WHERE actor_user_id = ? AND action IN ('hw_key_add', 'hw_key_remove', 'rotate') GROUP BY action ORDER BY action", [uid]);
  check(JSON.stringify(audit.map((r) => [r.action, Number(r.n)])) === JSON.stringify([['hw_key_add', 2], ['hw_key_remove', 1], ['rotate', 1]]), `audit ${JSON.stringify(audit)}`);
} catch (e) {
  console.error(`FAIL ${e.message}`);
  failures++;
} finally {
  cleanup();
}
check(userId() === 0, 'dev user removed');
console.log(failures === 0 ? 'E2E PASS' : `E2E FAIL (${failures})`);
process.exitCode = failures === 0 ? 0 : 1;
