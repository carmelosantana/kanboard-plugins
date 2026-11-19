// Records WebAuthn test vectors for Vault's verifier (Vault/Test/fixtures/webauthn/*.json).
// Zero dependencies (Node 22: built-in WebSocket, fetch, http). No real hardware key is involved:
// headless Chrome gets a CDP virtual authenticator (ctap2, PRF on), and for each algorithm
// (ES256, EdDSA) the page runs one navigator.credentials.create (attestation "none") and one
// navigator.credentials.get with a PRF eval. The virtual credential's private key is exported
// too, so the PHP tests can re-sign mutated assertions (it exists only inside this run).
//
// Run: node scripts/e2e/record-webauthn.mjs [outDir]
//   CHROME=/path/to/chrome overrides /usr/bin/google-chrome.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtempSync, readFileSync, rmSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = process.argv[2] || join(HERE, '../../Vault/Test/fixtures/webauthn');
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const b64u = (buf) => Buffer.from(buf).toString('base64url');

// The page only hosts the ceremonies; the script drives them through Runtime.evaluate.
const server = createServer((req, res) => {
  res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
  res.end('<!doctype html><title>webauthn recorder</title><p>recorder</p>');
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const origin = `http://localhost:${server.address().port}`;

const profile = mkdtempSync(join(tmpdir(), 'vault-webauthn-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', '--disable-gpu', 'about:blank',
], { stdio: 'ignore' });

let ws;
const cleanup = () => {
  try { ws?.close(); } catch {}
  chrome.kill('SIGKILL');
  server.close();
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
};

try {
  // Chrome writes the chosen port to DevToolsActivePort in the profile.
  const portFile = join(profile, 'DevToolsActivePort');
  for (let i = 0; i < 100 && !existsSync(portFile); i++) await new Promise((r) => setTimeout(r, 100));
  const [port, path] = readFileSync(portFile, 'utf8').trim().split('\n');
  ws = new WebSocket(`ws://127.0.0.1:${port}${path}`);
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
  await s('Page.navigate', { url: `${origin}/` });
  for (let i = 0; i < 50; i++) {
    const r = await s('Runtime.evaluate', { expression: 'document.readyState + location.origin', returnByValue: true });
    if (r.result.value === `complete${origin}`) break;
    await new Promise((res) => setTimeout(res, 100));
  }
  await s('WebAuthn.enable', { enableUI: false });

  const evaluate = async (expression) => {
    const r = await s('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
  };

  // Shared in-page helpers: base64url both ways.
  const helpers = `
    const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\\+/g, '-').replace(/\\//g, '_').replace(/=+$/, '');
    const unb64u = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
  `;

  mkdirSync(OUT, { recursive: true });
  for (const [name, alg] of [['es256', -7], ['eddsa', -8]]) {
    const { authenticatorId } = await s('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2', ctap2Version: 'ctap2_1', transport: 'usb',
        hasResidentKey: true, hasUserVerification: true, isUserVerified: true,
        hasPrf: true, automaticPresenceSimulation: true,
      },
    });
    const createChallenge = randomBytes(32);
    const getChallenge = randomBytes(32);
    const userId = randomBytes(16);
    const prfSalt = randomBytes(32);

    const created = await evaluate(`(async () => { ${helpers}
      const c = await navigator.credentials.create({ publicKey: {
        rp: { id: 'localhost', name: 'Vault' },
        user: { id: unb64u('${b64u(userId)}'), name: 'vector', displayName: 'vector' },
        challenge: unb64u('${b64u(createChallenge)}'),
        pubKeyCredParams: [{ type: 'public-key', alg: ${alg} }],
        attestation: 'none',
        authenticatorSelection: { userVerification: 'preferred' },
        extensions: { prf: {} },
      } });
      return { id: c.id, rawId: b64u(c.rawId), type: c.type,
        clientDataJSON: b64u(c.response.clientDataJSON),
        attestationObject: b64u(c.response.attestationObject),
        clientExtensionResults: c.getClientExtensionResults() };
    })()`);

    const asserted = await evaluate(`(async () => { ${helpers}
      const a = await navigator.credentials.get({ publicKey: {
        rpId: 'localhost',
        challenge: unb64u('${b64u(getChallenge)}'),
        allowCredentials: [{ type: 'public-key', id: unb64u('${created.rawId}') }],
        userVerification: 'preferred',
        extensions: { prf: { eval: { first: unb64u('${b64u(prfSalt)}') } } },
      } });
      const ext = a.getClientExtensionResults();
      const prf = ext.prf && ext.prf.results ? { results: { first: b64u(ext.prf.results.first) } } : null;
      return { id: a.id, rawId: b64u(a.rawId),
        clientDataJSON: b64u(a.response.clientDataJSON),
        authenticatorData: b64u(a.response.authenticatorData),
        signature: b64u(a.response.signature),
        userHandle: a.response.userHandle ? b64u(a.response.userHandle) : null,
        prf };
    })()`);

    const { credentials } = await s('WebAuthn.getCredentials', { authenticatorId });
    const cred = credentials.find((c) => Buffer.from(c.credentialId, 'base64').toString('base64url') === created.rawId);
    if (!cred) throw new Error(`virtual credential not found for ${name}`);
    await s('WebAuthn.removeVirtualAuthenticator', { authenticatorId });

    if (created.clientExtensionResults?.prf?.enabled !== true) throw new Error(`${name}: prf.enabled missing`);
    if (!asserted.prf) throw new Error(`${name}: PRF results missing on get`);

    const fixture = {
      _note: 'Recorded by scripts/e2e/record-webauthn.mjs from a Chrome DevTools virtual authenticator. Test-only key material.',
      alg, rpId: 'localhost', origin, chrome: (await send('Browser.getVersion')).product,
      register: {
        challenge: b64u(createChallenge), rawId: created.rawId,
        clientDataJSON: created.clientDataJSON, attestationObject: created.attestationObject,
        clientExtensionResults: created.clientExtensionResults,
      },
      assert: {
        challenge: b64u(getChallenge), rawId: asserted.rawId,
        clientDataJSON: asserted.clientDataJSON, authenticatorData: asserted.authenticatorData,
        signature: asserted.signature, prfSalt: b64u(prfSalt), prf: asserted.prf,
      },
      // PKCS#8 DER, base64url; lets tests re-sign mutated assertions.
      privateKey: Buffer.from(cred.privateKey, 'base64').toString('base64url'),
    };
    const file = join(OUT, `${name}.json`);
    writeFileSync(file, JSON.stringify(fixture, null, 2) + '\n');
    console.log(`wrote ${file}`);
  }
} catch (e) {
  console.error(`FAIL ${e.message}`);
  process.exitCode = 1;
} finally {
  cleanup();
}
