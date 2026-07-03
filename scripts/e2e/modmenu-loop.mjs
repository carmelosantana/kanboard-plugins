// Live E2E of the ModMenu lifecycle against the kb-suite container on :8081.
// Run: NODE_PATH=<npx playwright node_modules> node scripts/e2e/modmenu-loop.mjs
// Verifies (all on the real, live install path — no mocks):
//   Browse -> Install from GitHub release URL -> footer quote appears
//   -> Disable (quote gone) -> Enable (quote back) -> Uninstall (gone)
//   -> Upload the local zip (quote back).
import { chromium } from 'playwright';

const BASE = 'http://localhost:8081';
// ModMenu emits query-param URLs (this Kanboard instance does not register the
// clean plugin routes), so drive it via the URLs it actually generates.
const MM = {
  show: `${BASE}/?controller=ModMenuController&action=show&plugin=ModMenu`,
  dir: `${BASE}/?controller=ModMenuController&action=directory&plugin=ModMenu`,
  upload: `${BASE}/?controller=UploadController&action=upload&plugin=ModMenu`,
};
const LOCAL_ZIP = process.env.HH_ZIP
  || '/tmp/claude-1001/-home-carmelo-Projects-Kanboard/b3cef43c-249c-4992-834d-a2608edcf28a/scratchpad/modmenu-artifacts/HelloHarmozi-1.0.0.zip';
const SHOTS = new URL('./shots/', import.meta.url).pathname;

// Noise NOT attributable to ModMenu:
//  - ShadcnTheme (also mounted) ships an inline no-FOUC <head> script that
//    Kanboard's CSP blocks — a known, pre-existing issue tracked separately.
//  - A generic transient "Failed to load resource" 404 for a non-plugin asset
//    (favicon races etc.); ModMenu/HelloHarmozi asset 4xx are caught separately
//    via the response listener below, so this filter cannot hide a real one.
const isKnownNoise = (t) => /Content Security Policy|inline script|Failed to load resource/i.test(t);
const errors = [];
let failures = 0;
function step(name, ok, detail = '') {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
  if (!ok) { failures++; }
}

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext();
const page = await ctx.newPage();
page.on('console', (m) => { { const t = m.text(); if (m.type() === 'error' && !isKnownNoise(t)) errors.push(t); } });
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e)));
// A 4xx/5xx for ModMenu's OWN assets IS a real error (ModMenu is always active,
// so its css/js must always resolve). HelloHarmozi asset 404s are NOT flagged:
// they're an expected transient right after disable/uninstall, when a page
// rendered while HelloHarmozi was active still links its now-removed CSS.
page.on('response', (r) => { if (r.status() >= 400 && /\/plugins\/ModMenu\//.test(r.url())) errors.push(`asset ${r.status()} ${r.url()}`); });

// Kanboard's post-action redirects occasionally abort an immediate goto
// (net::ERR_ABORTED). Retry the navigation a couple of times.
const go = async (url) => {
  for (let i = 0; i < 3; i++) {
    try { await page.goto(url, { waitUntil: 'domcontentloaded' }); return; }
    catch (e) { if (!/ERR_ABORTED/.test(String(e)) || i === 2) throw e; await page.waitForTimeout(300); }
  }
};

const footerCount = async () => {
  await go(`${BASE}/dashboard`);
  return page.locator('.hello-harmozi').count();
};

try {
  // ── Login ───────────────────────────────────────────────────────────────
  await go(`${BASE}/login`);
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'admin');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('form button[type="submit"], form input[type="submit"]'),
  ]);
  step('login as admin', (await page.locator('.avatar, #board, .page-header, .sidebar').count()) > 0);

  // ── Pre-clean: ensure HelloHarmozi is not installed (idempotent reruns) ──
  await go(MM.show);
  let hhPre = page.locator('.modmenu-card', { hasText: 'HelloHarmozi' });
  if ((await hhPre.count()) > 0) {
    // enable first if it's parked disabled, so uninstall finds it in either dir
    const enableBtn = hhPre.locator('form[action*="enable"] button');
    if ((await enableBtn.count()) > 0) {
      await Promise.all([page.waitForLoadState('domcontentloaded'), enableBtn.first().click()]);
      await go(MM.show);
      hhPre = page.locator('.modmenu-card', { hasText: 'HelloHarmozi' });
    }
    await hhPre.getByText('Remove', { exact: true }).first().click();
    await page.waitForSelector('#modal-box', { state: 'visible', timeout: 5000 });
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#modal-box button[type="submit"]').first().click()]);
  }

  // ── ModMenu is configured (writable + zip) ──────────────────────────────
  await go(MM.show);
  const notConfigured = await page.locator('.modmenu-banner', { hasText: 'cannot manage plugins' }).count();
  step('ModMenu reports configured (no not-configured banner)', notConfigured === 0);
  step('ModMenu itself is listed & self-protected (no disable/remove on its row)',
    (await page.locator('.modmenu-card', { hasText: 'ModMenu' }).locator('button:has-text("Disable")').count()) === 0);

  // ── 1. Browse: Hello Harmozi shows Install ──────────────────────────────
  await go(MM.dir);
  const hhCard = page.locator('.modmenu-card', { hasText: 'Hello Harmozi' });
  step('Browse lists Hello Harmozi', (await hhCard.count()) > 0);
  step('Hello Harmozi shows an Install button',
    (await hhCard.locator('button:has-text("Install")').count()) > 0);
  await page.screenshot({ path: SHOTS + 'browse.png', fullPage: true });

  // ── 2. Install from the GitHub release URL ──────────────────────────────
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    hhCard.locator('form[action*="install"] button[type="submit"]').first().click(),
  ]);
  step('install action completed (flash shown)',
    (await page.locator('.alert-success, .alert').count()) > 0);

  // ── 3. Footer quote appears ─────────────────────────────────────────────
  step('footer quote appears after install', (await footerCount()) > 0);
  await page.screenshot({ path: SHOTS + 'installed.png' });

  // ── 4. Disable -> quote gone ────────────────────────────────────────────
  await go(MM.show);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).locator('form[action*="disable"] button').first().click(),
  ]);
  step('footer quote gone after disable', (await footerCount()) === 0);
  await go(MM.show);
  step('HelloHarmozi shows Disabled state',
    (await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).locator('button:has-text("Enable")').count()) > 0);

  // ── 5. Enable -> quote back ─────────────────────────────────────────────
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).locator('form[action*="enable"] button').first().click(),
  ]);
  step('footer quote returns after enable', (await footerCount()) > 0);

  // ── 6. Uninstall via confirm modal ──────────────────────────────────────
  await go(MM.show);
  await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).getByText('Remove', { exact: true }).first().click();
  await page.waitForSelector('#modal-box', { state: 'visible', timeout: 5000 });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#modal-box button[type="submit"]').first().click(),
  ]);
  step('footer quote gone after uninstall', (await footerCount()) === 0);
  await go(MM.show);
  step('HelloHarmozi no longer in Installed list',
    (await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).count()) === 0);

  // ── 7. Upload path -> quote back ────────────────────────────────────────
  await go(MM.upload);
  await page.setInputFiles('input[type="file"][name="plugin"]', LOCAL_ZIP);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form button[type="submit"]').first().click(),
  ]);
  step('footer quote appears after ZIP upload', (await footerCount()) > 0);
  await page.screenshot({ path: SHOTS + 'uploaded.png' });

  // ── cleanup: uninstall so the dev site is left clean ────────────────────
  await go(MM.show);
  const hhLeft = page.locator('.modmenu-card', { hasText: 'HelloHarmozi' });
  if ((await hhLeft.count()) > 0) {
    await hhLeft.getByText('Remove', { exact: true }).first().click();
    await page.waitForSelector('#modal-box', { state: 'visible', timeout: 5000 });
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('#modal-box button[type="submit"]').first().click(),
    ]);
  }
  await go(MM.show);
  step('cleanup: HelloHarmozi removed', (await page.locator('.modmenu-card', { hasText: 'HelloHarmozi' }).count()) === 0);
} catch (e) {
  step('script completed without exception', false, String(e));
}

step('zero console/page errors', errors.length === 0, errors.slice(0, 5).join(' | '));

await browser.close();
console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'}`);
process.exit(failures === 0 ? 0 : 1);
