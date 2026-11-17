// Seeds neutral, made-up demo data for directory screenshots through JSON-RPC.
// Idempotent: does nothing if the demo project already exists.
// Run: node scripts/screenshots/seed.mjs   (KB_URL defaults to the clean :8082 stack)
// Never point this at a stack that holds real data you then photograph wholesale.

export const KB_URL = process.env.KB_URL || 'http://localhost:8082';
export const PROJECT_NAME = 'Website Launch';
// Test-only credentials for the throwaway screenshot stack.
export const DEMO_PASSWORD = 'demo-pass-123';
export const USERS = [
  { username: 'sam', name: 'Sam Rivera', email: 'sam@example.com' },
  { username: 'priya', name: 'Priya Shah', email: 'priya@example.com' },
  { username: 'chris', name: 'Chris Doyle', email: 'chris@example.com' },
];

const EXTRA_PROJECTS = [
  ['Mobile App v2', ['Onboarding screens', 'Push notifications']],
  ['Customer Portal', ['Invoice history page', 'Password reset flow']],
  ['Brand Refresh', ['Logo explorations', 'Colour palette']],
  ['Spring Newsletter', ['Pick featured stories']],
  ['Office Move', ['Book movers', 'Label equipment']],
  ['Old Intranet (archived)', ['Export wiki pages']],
];

let rpcId = 0;
export async function rpc(method, params = {}) {
  const res = await fetch(`${KB_URL}/jsonrpc.php`, {
    method: 'POST',
    headers: { Authorization: 'Basic ' + Buffer.from('admin:admin').toString('base64') },
    body: JSON.stringify({ jsonrpc: '2.0', method, id: ++rpcId, params }),
  });
  const json = await res.json();
  if (json.error) throw new Error(`${method}: ${JSON.stringify(json.error)}`);
  return json.result;
}

const day = 86400;
const now = Math.floor(Date.now() / 1000);
const ymd = (offsetDays) => new Date((now + offsetDays * day) * 1000).toISOString().slice(0, 10);

export async function seed() {
  const existing = (await rpc('getAllProjects')).find((p) => p.name === PROJECT_NAME);
  if (existing) return { projectId: Number(existing.id), seeded: false };

  await rpc('updateUser', { id: 1, name: 'Jordan Lee', email: 'jordan@example.com' });
  const uid = { admin: 1 };
  for (const u of USERS) {
    const found = await rpc('getUserByName', { username: u.username });
    uid[u.username] = found ? Number(found.id)
      : await rpc('createUser', { username: u.username, password: DEMO_PASSWORD, name: u.name, email: u.email });
  }

  const pid = await rpc('createProject', { name: PROJECT_NAME, description: 'Relaunch of the marketing site.', owner_id: 1, identifier: 'WEB' });
  for (const u of USERS) await rpc('addProjectUser', { project_id: pid, user_id: uid[u.username], role: 'project-member' });

  const cols = Object.fromEntries((await rpc('getColumns', { project_id: pid })).map((c) => [c.title, Number(c.id)]));
  // Agents' WIP view keys off an "In progress" column.
  await rpc('updateColumn', { column_id: cols['Work in progress'], title: 'In progress' });
  const cat = {};
  for (const name of ['Design', 'Development', 'Content']) cat[name] = await rpc('createCategory', { project_id: pid, name });

  // [title, column, assignee, color, dueOffsetDays|null, category, hoursSpent, hoursEstimated, tags, closed]
  const plan = [
    ['Set up analytics dashboard', 'Backlog', 'chris', 'grey', 12, 'Development', 0, 4, ['tracking']],
    ['Write launch blog post', 'Backlog', 'priya', 'purple', 9, 'Content', 0, 3, ['copy']],
    ['Plan social media teasers', 'Backlog', null, 'orange', null, 'Content', 0, 2, ['copy']],
    ['Design pricing page', 'Ready', 'sam', 'blue', 4, 'Design', 1.5, 6, ['frontend']],
    ['Configure CDN caching', 'Ready', 'chris', 'green', 6, 'Development', 0, 3, ['performance']],
    ['Accessibility audit', 'Ready', 'priya', 'yellow', -2, 'Design', 0, 5, ['a11y']],
    ['Build homepage hero section', 'Work in progress', 'sam', 'blue', 2, 'Development', 4.5, 8, ['frontend']],
    ['Migrate product copy', 'Work in progress', 'priya', 'purple', -1, 'Content', 3, 6, ['copy']],
    ['Set up staging environment', 'Work in progress', 'chris', 'green', 1, 'Development', 2.5, 4, ['infra']],
    ['Create wireframes', 'Done', 'sam', 'blue', -9, 'Design', 6, 6, ['frontend'], true],
    ['Choose typography', 'Done', 'sam', 'teal', -7, 'Design', 2, 2, ['frontend'], true],
    ['Draft sitemap', 'Done', 'priya', 'purple', -6, 'Content', 1.5, 2, ['seo'], true],
    ['Register domain and DNS', 'Done', 'chris', 'green', -5, 'Development', 1, 1, ['infra'], true],
    ['Collect brand assets', 'Done', 'priya', 'orange', -3, 'Design', 2, 3, [], false],
  ];
  const tid = {};
  for (const [title, col, who, color, due, category, spent, est, tags, closed] of plan) {
    const id = await rpc('createTask', {
      title, project_id: pid, column_id: cols[col], owner_id: who ? uid[who] : 0, creator_id: 1, color_id: color,
      date_due: due === null ? '' : `${ymd(due)} 17:00`, category_id: cat[category], tags,
      time_spent: spent, time_estimated: est, description: `${title} for the new marketing site.`,
    });
    tid[title] = id;
    if (closed) await rpc('closeTask', { task_id: id });
  }

  const subtasks = {
    'Build homepage hero section': [['Headline and supporting copy', 'sam', 2, 1.5, 2], ['Responsive layout', 'sam', 3, 2, 1], ['Background illustration', 'sam', 2, 0, 0], ['Cross-browser QA', 'chris', 1, 0, 0]],
    'Migrate product copy': [['Export copy from old CMS', 'priya', 1, 1, 2], ['Rewrite feature blurbs', 'priya', 3, 2, 1], ['Proofread', 'sam', 1, 0, 0]],
    'Set up staging environment': [['Provision server', 'chris', 1, 1.5, 2], ['Deploy pipeline', 'chris', 2, 1, 1], ['Basic auth for reviewers', 'chris', 0.5, 0, 0]],
    'Design pricing page': [['Plan comparison table', 'sam', 2, 1.5, 2], ['Mobile layout', 'sam', 2, 0, 0], ['FAQ section', 'priya', 1, 0, 0]],
    'Create wireframes': [['Homepage', 'sam', 2, 2, 2], ['Pricing', 'sam', 2, 2, 2], ['Blog index', 'sam', 2, 2, 2]],
  };
  for (const [task, rows] of Object.entries(subtasks)) {
    for (const [title, who, est, spent, status] of rows) {
      await rpc('createSubtask', { task_id: tid[task], title, user_id: uid[who], time_estimated: est, time_spent: spent, status });
    }
  }

  const comments = [
    ['Build homepage hero section', 'priya', 'Headline copy is final. Can we try the shorter subheading on mobile?'],
    ['Build homepage hero section', 'sam', 'Yes, pushed a variant to staging. Illustration lands tomorrow.'],
    ['Migrate product copy', 'chris', 'Old CMS export is in the shared drive.'],
    ['Accessibility audit', 'priya', 'Starting with the navigation and form contrast.'],
  ];
  for (const [task, who, content] of comments) await rpc('createComment', { task_id: tid[task], user_id: uid[who], content });

  const blocks = Number((await rpc('getLinkByLabel', { label: 'blocks' })).id);
  await rpc('createTaskLink', { task_id: tid['Set up staging environment'], opposite_task_id: tid['Configure CDN caching'], link_id: blocks });
  await rpc('createTaskLink', { task_id: tid['Migrate product copy'], opposite_task_id: tid['Write launch blog post'], link_id: blocks });
  await rpc('createTaskLink', { task_id: tid['Design pricing page'], opposite_task_id: tid['Build homepage hero section'], link_id: blocks });

  // TimeBlock: planned working windows (task metadata, unix seconds as strings; the stack runs in UTC).
  const at = (offsetDays, hour) => {
    const d = new Date((now + offsetDays * day) * 1000); d.setUTCHours(hour, 0, 0, 0); return String(Math.floor(d / 1000));
  };
  await rpc('saveTaskMetadata', { task_id: tid['Build homepage hero section'], values: { timeblock_start: at(1, 9), timeblock_end: at(1, 12) } });
  await rpc('saveTaskMetadata', { task_id: tid['Design pricing page'], values: { timeblock_start: at(2, 14), timeblock_end: at(2, 17) } });
  await rpc('saveTaskMetadata', { task_id: tid['Accessibility audit'], values: { timeblock_start: at(0, 13), timeblock_end: at(0, 15) } });

  // Chicane race track visible to members; SchedulerPlugin opt-in for this project.
  await rpc('saveProjectMetadata', { project_id: pid, values: { 'chicane.visibility': 'members', 'scheduler.enabled': '1' } });

  // Extra projects: targets for FeatureSync and rows for BulkProjectDelete.
  for (const [name, tasks] of EXTRA_PROJECTS) {
    const id = await rpc('createProject', { name, owner_id: 1 });
    for (const title of tasks) await rpc('createTask', { title, project_id: id });
  }

  return { projectId: pid, seeded: true, tasks: tid, users: uid };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  console.log(JSON.stringify(await seed(), null, 2));
}
