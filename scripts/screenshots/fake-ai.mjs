// Canned, offline stand-in for an OpenAI-compatible / Ollama endpoint, so AI features can be
// photographed without a real provider or key. Point an AiConnector "Ollama" profile at
// http://host.docker.internal:11999 (capture.mjs does this). Never use it for anything real.
import http from 'node:http';

export const FAKE_AI_PORT = Number(process.env.FAKE_AI_PORT || 11999);

const SUBTASKS = {
  subtasks: [
    { title: 'Write headline and subheading variants' },
    { title: 'Build responsive hero layout' },
    { title: 'Add background illustration with lazy loading' },
    { title: 'Wire primary call-to-action button' },
    { title: 'Run cross-browser and mobile QA' },
  ],
};
const PROSE = 'Steady week: the hero section and product copy moved forward, staging is live, '
  + 'and the remaining work is design polish and QA before launch.';

function answer(body) {
  const wantsJson = Boolean(body.response_format) || JSON.stringify(body.messages || '').includes('subtasks');
  return wantsJson ? JSON.stringify(SUBTASKS) : PROSE;
}

export function startFakeAi(port = FAKE_AI_PORT) {
  const server = http.createServer((req, res) => {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => {
      const body = raw ? JSON.parse(raw) : {};
      const model = body.model || 'demo-model';
      if (req.url.startsWith('/api/tags') || req.url.startsWith('/v1/models')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ models: [{ name: 'demo-model', model: 'demo-model' }], data: [{ id: 'demo-model', object: 'model' }] }));
        return;
      }
      if (req.url.startsWith('/api/show')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ model_info: { 'general.context_length': 32768 }, capabilities: ['completion'] }));
        return;
      }
      const content = answer(body);
      const usage = { prompt_tokens: 120, completion_tokens: 60, total_tokens: 180 };
      if (body.stream) {
        res.writeHead(200, { 'Content-Type': 'text/event-stream' });
        res.write(`data: ${JSON.stringify({ id: 'c1', object: 'chat.completion.chunk', model, choices: [{ index: 0, delta: { role: 'assistant', content }, finish_reason: null }] })}\n\n`);
        res.write(`data: ${JSON.stringify({ id: 'c1', object: 'chat.completion.chunk', model, choices: [{ index: 0, delta: {}, finish_reason: 'stop' }], usage })}\n\n`);
        res.end('data: [DONE]\n\n');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        id: 'c1', object: 'chat.completion', created: Math.floor(Date.now() / 1000), model,
        choices: [{ index: 0, message: { role: 'assistant', content }, finish_reason: 'stop' }], usage,
      }));
    });
  });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '0.0.0.0', () => resolve(server));
  });
}

if (import.meta.url === `file://${process.argv[1]}`) {
  await startFakeAi();
  console.log(`fake AI listening on :${FAKE_AI_PORT}`);
}
