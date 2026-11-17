// Captures ModMenu-directory screenshots of every listed plugin from the clean :8082 stack.
// Seeds neutral demo data (seed.mjs), sets up plugin state through the UI, starts a canned
// offline AI endpoint (fake-ai.mjs), then writes 1280x800-frame (16:10) WebP files to
//   <out>/<kebab-name>/<n>.webp
// Usage: see README.md next to this file.
//   node scripts/screenshots/capture.mjs [--out DIR] [--only Plugin,Plugin] [--directory PATH] [--keep-png]
// Shots tagged theme:'shadcn' run only when ShadcnTheme is mounted, all others only when it is not.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync, statSync, rmSync, existsSync, readFileSync } from 'node:fs';
import http from 'node:http';
import { join, resolve } from 'node:path';
import { KB_URL, DEMO_PASSWORD, seed, rpc } from './seed.mjs';
import { startFakeAi, FAKE_AI_PORT } from './fake-ai.mjs';

// require() (unlike import) honours NODE_PATH, so an existing Playwright install can be reused.
const { chromium } = createRequire(import.meta.url)('playwright');

const args = process.argv.slice(2);
const opt = (name, dflt) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : dflt; };
const OUT = resolve(opt('--out', new URL('./out', import.meta.url).pathname));
const ONLY = opt('--only', '') ? opt('--only', '').split(',') : null;
const KEEP_PNG = args.includes('--keep-png');
const W = 1280, H = 800, RATIO = W / H;
const PASS = { admin: 'admin', sam: DEMO_PASSWORD, priya: DEMO_PASSWORD, chris: DEMO_PASSWORD };

const q = (params) => '/?' + new URLSearchParams(params).toString();
const PROJECT = 1;

// ── Browser helpers ───────────────────────────────────────────────────────────
const browser = await chromium.launch({ headless: true });
const sessions = {};
async function as(user) {
  if (sessions[user]) return sessions[user];
  const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1, timezoneId: 'UTC' });
  const page = await ctx.newPage();
  await page.goto(KB_URL + '/login');
  await page.fill('input[name=username]', user);
  await page.fill('input[name=password]', PASS[user]);
  await Promise.all([page.waitForLoadState(), page.click('form button[type=submit]')]);
  return (sessions[user] = page);
}
async function go(page, path) {
  for (let i = 0; i < 3; i++) {
    try { await page.goto(KB_URL + path, { waitUntil: 'networkidle' }); return; }
    catch (e) { if (i === 2) throw e; await page.waitForTimeout(400); }
  }
}
async function submit(page, buttonSelector) {
  await Promise.all([page.waitForLoadState('networkidle'), page.click(buttonSelector)]);
}

// A 16:10 clip around an element's box (plus padding), clamped to the page.
async function frame(page, selector, { pad = 16, minWidth = 640, anchor = 'center' } = {}) {
  const box = await page.locator(selector).first().boundingBox();
  if (!box) throw new Error(`frame: ${selector} not found`);
  const pageW = W;
  const pageH = await page.evaluate(() => document.documentElement.scrollHeight);
  let w = Math.max(box.width + 2 * pad, minWidth), h = box.height + 2 * pad;
  if (w / h > RATIO) h = w / RATIO; else w = h * RATIO;
  w = Math.min(w, pageW); h = Math.round(w / RATIO);
  let x = box.x + box.width / 2 - w / 2;
  let y = anchor === 'top' ? box.y - pad : box.y + box.height / 2 - h / 2;
  x = Math.max(0, Math.min(x, pageW - w)); y = Math.max(0, Math.min(y, pageH - h));
  return { x: Math.round(x), y: Math.round(y), width: Math.round(w), height: h };
}

function toWebp(png, webp) {
  execFileSync('convert', [png, '-strip', '-quality', '80', '-define', 'webp:method=6', webp]);
  if (!KEEP_PNG) rmSync(png);
  const kb = statSync(webp).size / 1024;
  const dims = execFileSync('identify', ['-format', '%wx%h', webp]).toString();
  return { kb, dims };
}

// ── One-time UI setup (idempotent) ───────────────────────────────────────────
async function setup() {
  const admin = await as('admin');

  // AiConnector: an Ollama-style profile pointed at fake-ai.mjs, plus a hosted one.
  await go(admin, q({ controller: 'SettingsController', action: 'show', plugin: 'AiConnector' }));
  if (!(await admin.content()).includes('Local Ollama')) {
    for (const p of [
      { label: 'Local Ollama', provider: 'ollama', model: 'llama3.1:8b', base_url: `http://host.docker.internal:${FAKE_AI_PORT}` },
      { label: 'Claude Sonnet', provider: 'anthropic', model: 'claude-sonnet-4-5', base_url: '' },
    ]) {
      await admin.fill('input[name=label]', p.label);
      await admin.selectOption('select[name=provider]', p.provider);
      await admin.fill('input[name=model]', p.model);
      await admin.fill('input[name=base_url]', p.base_url);
      await submit(admin, 'form[action*="action=save"] button[type=submit], form[action*="ai-connector/save"] button[type=submit]');
      await go(admin, q({ controller: 'SettingsController', action: 'show', plugin: 'AiConnector' }));
    }
  }

  // TimeInvoice: business details, project client, one issued invoice.
  await go(admin, q({ controller: 'SettingsController', action: 'show', plugin: 'TimeInvoice' }));
  if ((await admin.inputValue('input[name=business_name]')) === '') {
    await admin.fill('input[name=business_name]', 'Northwind Studio');
    await admin.fill('textarea[name=business_address]', '12 Example Street\nSpringfield, ST 00000');
    await admin.fill('input[name=business_email]', 'billing@example.com');
    await admin.fill('input[name=rate]', '95');
    await submit(admin, 'form[action*="settings/save"] button[type=submit]');
    await go(admin, q({ controller: 'ProjectSettingsController', action: 'show', plugin: 'TimeInvoice', project_id: PROJECT }));
    await admin.fill('input[name=rate]', '95');
    await admin.fill('input[name=client_name]', 'Acme Outdoor Co.');
    await admin.fill('textarea[name=client_address]', '400 Sample Avenue\nRiverton, ST 00000');
    await admin.fill('input[name=client_email]', 'accounts@example.com');
    await submit(admin, 'form[action*="project/settings/save"] button[type=submit]');
    await go(admin, q({ controller: 'InvoiceController', action: 'form', plugin: 'TimeInvoice', project_id: PROJECT }));
    await admin.selectOption('select[name=granularity]', 'task');
    await admin.fill('textarea[name=notes]', 'Thank you for the opportunity to work on the new site.');
    await submit(admin, 'form[action*="timeinvoice/save"] button[type=submit]');
  }

  // Issue the invoice so it has a number and a total.
  await go(admin, q({ controller: 'InvoiceController', action: 'list', plugin: 'TimeInvoice', project_id: PROJECT }));
  const draft = admin.locator('table tr', { hasText: 'Draft' }).locator('a').first();
  if (await draft.count()) {
    await Promise.all([admin.waitForLoadState('networkidle'), draft.click()]);
    admin.once('dialog', (d) => d.accept());
    await Promise.all([admin.waitForLoadState('networkidle'), admin.click('a.timeinvoice-send')]);
  }

  // Agents: Sam owns two agent users, which pick up work on the demo board.
  const sam = await as('sam');
  await go(sam, q({ controller: 'AgentController', action: 'index', plugin: 'Agents' }));
  if (!(await sam.content()).includes('sam.claude')) {
    for (const kind of ['claude', 'codex']) {
      await go(sam, q({ controller: 'AgentController', action: 'index', plugin: 'Agents' }));
      await sam.selectOption('select[name=kind]', kind);
      await submit(sam, 'form[action*="agents/create"] button[type=submit]');
    }
  }
  const tasks = await rpc('getAllTasks', { project_id: PROJECT, status_id: 1 });
  if (!tasks.some((t) => t.title === 'Write image alt text')) {
    const inProgress = Number((await rpc('getColumns', { project_id: PROJECT })).find((c) => c.title === 'In progress').id);
    for (const [title, agent, color] of [['Write image alt text', 'sam.claude', 'teal'], ['Fix broken links report', 'sam.codex', 'grey']]) {
      const agentId = Number((await rpc('getUserByName', { username: agent })).id);
      await rpc('addProjectUser', { project_id: PROJECT, user_id: agentId, role: 'project-member' });
      await rpc('createTask', { title, project_id: PROJECT, column_id: inProgress, owner_id: agentId, color_id: color, creator_id: 2 });
    }
  }

  // Scheduler: master switch on; it then runs on the next page load and logs the run.
  await go(admin, q({ controller: 'SchedulerController', action: 'settings', plugin: 'SchedulerPlugin' }));
  if (!(await admin.isChecked('input[type=checkbox][name=master]'))) {
    await admin.check('input[type=checkbox][name=master]');
    await submit(admin, 'form[action*="scheduler/save"] button[type=submit]');
    await go(admin, q({ controller: 'SchedulerController', action: 'settings', plugin: 'SchedulerPlugin' }));
  }
}

// Presence: teammates online in their own sessions, so the strip lights up.
async function bringTeamOnline() {
  for (const u of ['priya', 'chris']) await go(await as(u), q({ controller: 'BoardViewController', action: 'show', project_id: PROJECT }));
}

// ModMenu Browse reads the directory from a local checkout (--directory), so it shows the new images.
async function pointModMenuAtDirectory(dir) {
  const net = execFileSync('docker', ['inspect', 'kb-shots', '--format', '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}']).toString().trim();
  const port = 8790;
  const server = http.createServer((req, res) => {
    const file = join(dir, decodeURIComponent(new URL(req.url, 'http://x').pathname));
    if (!file.startsWith(dir) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': file.endsWith('.json') ? 'application/json' : file.endsWith('.webp') ? 'image/webp' : 'application/octet-stream' });
    res.end(readFileSync(file));
  });
  await new Promise((r) => server.listen(port, '0.0.0.0', r));
  const url = `http://${net}:${port}/plugins.json`;
  const admin = await as('admin');
  const sources = q({ controller: 'ModMenuController', action: 'sources', plugin: 'ModMenu' });
  await go(admin, sources);
  if (!(await admin.content()).includes(url)) {
    await admin.fill('input[type=text][name=url]', url);
    await submit(admin, 'form[action*="addSource"] button[type=submit], form[action*="add"] button[type=submit]');
  }
  // Drop every other source so Browse lists the local copy only (throwaway stack).
  await go(admin, sources);
  for (;;) {
    const other = admin.locator('li.modmenu-card', { hasNotText: url }).locator('form[action*="emove"] button');
    if (!(await other.count())) break;
    await Promise.all([admin.waitForLoadState('networkidle'), other.first().click()]);
  }
  return server;
}

// ── Shot list ────────────────────────────────────────────────────────────────
const board = q({ controller: 'BoardViewController', action: 'show', project_id: PROJECT });
const taskView = (id) => q({ controller: 'TaskViewController', action: 'show', task_id: id });
const calendar = q({ controller: 'CalendarController', action: 'project', plugin: 'CalendarPlugin', project_id: PROJECT });
// Week view over working hours (the plugin exposes its FullCalendar instance as __calInstance).
const weekView = async (p) => {
  await p.click('button:has-text("week")');
  await p.evaluate(() => { window.__calInstance.setOption('slotMinTime', '08:00:00'); window.__calInstance.setOption('slotMaxTime', '19:00:00'); });
  await p.waitForTimeout(800);
};
const fixed = (x, y, width) => async () => ({ x, y, width, height: Math.round(width / RATIO) });
const SHOTS = [];
// Files land in <out>/<kebab of the plugin name>/<n>.webp, e.g. ModMenu -> mod-menu/1.webp.
const kebab = (name) => name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
const shot = (plugin, n, user, path, opts = {}) => SHOTS.push({ plugin, slug: kebab(plugin), n, user, path, ...opts });

// HelloHarmozi: the bottom of a board, cropped so the footer quote reads clearly.
shot('HelloHarmozi', 1, 'admin', board, {
  clip: async (p) => {
    const b = await p.locator('.hello-harmozi').first().boundingBox();
    const w = 768, h = w / RATIO;
    return { x: (W - w) / 2, y: Math.round(b.y + b.height + 24 - h), width: w, height: h };
  },
});

// ModMenu (run with --directory so Browse shows the directory's own screenshots)
shot('ModMenu', 1, 'admin', q({ controller: 'ModMenuController', action: 'directory', plugin: 'ModMenu' }), {
  prep: async (p) => { await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); },
});
shot('ModMenu', 2, 'admin', q({ controller: 'ModMenuController', action: 'show', plugin: 'ModMenu' }));

// ShadcnTheme (run with docker-compose.shots-shadcn.yml)
shot('ShadcnTheme', 1, 'admin', board, { theme: 'shadcn' });
shot('ShadcnTheme', 2, 'admin', taskView(7), { theme: 'shadcn' });
shot('ShadcnTheme', 3, null, '/login', { theme: 'shadcn', clip: (p) => frame(p, 'form', { minWidth: 960, pad: 120 }) });

// BulkProjectDelete: selection mode on the projects list, then the typed confirmation.
const bulkSelect = async (p) => {
  await p.click('text=Select projects');
  for (const name of ['Old Intranet (archived)', 'Office Move', 'Spring Newsletter']) {
    await p.locator('.table-list-row', { hasText: name }).locator('input[type=checkbox]').first().check();
  }
};
shot('BulkProjectDelete', 1, 'admin', q({ controller: 'ProjectListController', action: 'show' }), { prep: bulkSelect });
shot('BulkProjectDelete', 2, 'admin', q({ controller: 'ProjectListController', action: 'show' }), {
  prep: async (p) => { await bulkSelect(p); await p.click('text=/Delete 3 projects/'); await p.waitForTimeout(800); },
});

// FeatureSync: the read-only diff preview, then the form.
const featureSyncForm = async (p) => {
  await p.selectOption('select', { label: 'Website Launch' });
  await p.waitForTimeout(300);
  for (const f of ['Tags', 'Categories', 'Board Columns']) await p.getByLabel(f).check();
  for (const t of ['Mobile App v2', 'Customer Portal', 'Brand Refresh']) await p.getByLabel(t).check();
};
const featureSync = q({ controller: 'FeatureSyncController', action: 'index', plugin: 'FeatureSync' });
shot('FeatureSync', 1, 'admin', featureSync, {
  prep: async (p) => { await featureSyncForm(p); await submit(p, 'button:has-text("Continue to Preview")'); },
});
shot('FeatureSync', 2, 'admin', featureSync, {
  prep: async (p) => { await featureSyncForm(p); await p.evaluate(() => window.scrollTo(0, 0)); },
});

// AiConnector
shot('AiConnector', 1, 'admin', q({ controller: 'SettingsController', action: 'show', plugin: 'AiConnector' }));

// SubtaskGenerator: generate from the task, review the checklist.
shot('SubtaskGenerator', 1, 'admin', taskView(9), {
  prep: async (p) => {
    await p.click('text=Generate subtasks');
    await p.waitForSelector('#modal-content');
    await p.click('#modal-content button:has-text("Generate")');
    await p.waitForTimeout(1500);
  },
  clip: (p) => frame(p, '#modal-content', { minWidth: 960 }),
});

// CalendarPlugin: month, then week (due dates and planned time blocks).
shot('CalendarPlugin', 1, 'admin', calendar);
shot('CalendarPlugin', 2, 'admin', calendar, { prep: async (p) => { await p.click('button:has-text("list")'); await p.waitForTimeout(600); } });

// DependencyPlugin: blocked badges on the board, then the task panel.
shot('DependencyPlugin', 1, 'admin', board, { clip: fixed(0, 40, 960) });
shot('DependencyPlugin', 2, 'admin', taskView(7), {
  clip: async (p) => { const c = await frame(p, '.accordion-section:has-text("Dependencies")', { minWidth: 960, anchor: 'top', pad: 24 }); return { ...c, x: 240 }; },
});

// SchedulerPlugin: settings, then the detail of the run it logged.
shot('SchedulerPlugin', 1, 'admin', q({ controller: 'SchedulerController', action: 'settings', plugin: 'SchedulerPlugin' }));
shot('SchedulerPlugin', 2, 'admin', q({ controller: 'SchedulerController', action: 'log', plugin: 'SchedulerPlugin' }), {
  prep: async (p) => { await Promise.all([p.waitForLoadState('networkidle'), p.locator('table a').first().click()]); },
  clip: fixed(0, 0, 1024),
});

// Kensho (per user; private to Sam)
shot('Kensho', 1, 'sam', q({ controller: 'KenshoController', action: 'show', plugin: 'Kensho' }), { clip: fixed(0, 0, 1152) });

// TimeBlock: task panel, then planned blocks on the week calendar.
shot('TimeBlock', 1, 'admin', taskView(7), { clip: async (p) => { const c = await frame(p, 'h3:has-text("Time block"), .accordion-section:has-text("Time block")', { minWidth: 960 }); return { ...c, x: 240 }; } });
shot('TimeBlock', 2, 'admin', calendar, { prep: weekView });

// TimeReport: everyone's hours by user, with an AI summary from the canned endpoint.
shot('TimeReport', 1, 'admin', q({ controller: 'TimeReportController', action: 'index', plugin: 'TimeReport' }), {
  prep: async (p) => {
    await p.selectOption('select[name=project_id]', { label: 'Website Launch' });
    await p.click('button:has-text("This month")').catch(() => {});
    await p.selectOption('select[name=granularity]', 'user');
    await p.selectOption('select[name=scope]', 'all');
    await submit(p, 'button:has-text("Generate report")');
  },
});
shot('TimeReport', 2, 'admin', q({ controller: 'TimeReportController', action: 'index', plugin: 'TimeReport' }), {
  prep: async (p) => {
    await p.selectOption('select[name=project_id]', { label: 'Website Launch' });
    await p.click('button:has-text("This month")').catch(() => {});
    await p.selectOption('select[name=granularity]', 'task');
    await p.selectOption('select[name=scope]', 'all');
    await submit(p, 'button:has-text("Generate report")');
  },
});

// TimeInvoice: the invoice page (below the multi-user notice), then the list.
const invoices = q({ controller: 'InvoiceController', action: 'list', plugin: 'TimeInvoice', project_id: PROJECT });
shot('TimeInvoice', 1, 'admin', invoices, {
  prep: async (p) => { await Promise.all([p.waitForLoadState('networkidle'), p.locator('table a').first().click()]); },
  clip: async (p) => {
    const top = await p.locator('.alert').first().boundingBox().catch(() => null);
    const y = top ? Math.round(top.y + top.height + 4) : 40;
    return { x: 0, y, width: W, height: H };
  },
});

// Agents: the WIP queue by owner, then Sam's agent roster.
shot('Agents', 1, 'sam', q({ controller: 'WipController', action: 'index', plugin: 'Agents' }), {
  prep: async (p) => { await p.check('#agents-wip-unflagged'); },
});
shot('Agents', 2, 'sam', q({ controller: 'AgentController', action: 'index', plugin: 'Agents' }), { clip: fixed(0, 0, 768) });

// Presence: the member strip on the board, as Sam, with teammates online.
shot('Presence', 1, 'sam', board, { prep: bringTeamOnline, clip: fixed(0, 0, 640) });

// Chicane: the race track, then its settings.
shot('Chicane', 1, 'admin', q({ controller: 'RaceController', action: 'show', plugin: 'Chicane', project_id: PROJECT }), {
  prep: async (p) => { await p.waitForTimeout(2500); },
});
shot('Chicane', 2, 'admin', q({ controller: 'RaceSettingsController', action: 'show', plugin: 'Chicane', project_id: PROJECT }), { clip: fixed(0, 0, 1024) });

// ── Run ──────────────────────────────────────────────────────────────────────
const fakeAi = await startFakeAi().catch(() => null); // already running elsewhere is fine
const seeded = await seed();
await setup();
const dirServer = opt('--directory') ? await pointModMenuAtDirectory(resolve(opt('--directory'))) : null;

const login = await (await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1 })).newPage();
await login.goto(KB_URL + '/login');
const shadcn = (await login.content()).includes('plugins/ShadcnTheme');
console.log(`project ${seeded.projectId}; theme: ${shadcn ? 'shadcn' : 'stock'}; out: ${OUT}`);

let failures = 0;
for (const s of SHOTS) {
  if (ONLY && !ONLY.includes(s.slug) && !ONLY.includes(s.plugin)) continue;
  if ((s.theme === 'shadcn') !== shadcn) continue;
  const page = s.user ? await as(s.user) : login;
  try {
    await go(page, s.path);
    if (s.prep) await s.prep(page);
    await page.waitForTimeout(300);
    const dir = join(OUT, s.slug); mkdirSync(dir, { recursive: true });
    const png = join(dir, `${s.n}.png`), webp = join(dir, `${s.n}.webp`);
    const clip = s.clip ? await s.clip(page) : null;
    if (clip) {
      // Exact 16:10 in whole pixels; a short page gets blank room so the clip never truncates.
      clip.width = Math.round(clip.width / 16) * 16; clip.height = (clip.width / 16) * 10;
      clip.x = Math.max(0, Math.min(Math.round(clip.x), W - clip.width)); clip.y = Math.max(0, Math.round(clip.y));
      await page.evaluate((h) => { if (document.documentElement.scrollHeight < h) document.body.style.minHeight = `${h}px`; }, clip.y + clip.height);
    }
    await page.screenshot(clip ? { path: png, clip, fullPage: true } : { path: png });
    const { kb, dims } = toWebp(png, webp);
    console.log(`${kb > 120 ? 'BIG ' : 'ok  '} ${s.slug}/${s.n}.webp  ${dims}  ${kb.toFixed(1)} KB`);
  } catch (e) {
    failures++;
    console.log(`FAIL ${s.slug}/${s.n}  ${String(e.message).split('\n')[0]}`);
  }
}
await browser.close();
fakeAi?.close();
dirServer?.close();
process.exit(failures ? 1 : 0);
