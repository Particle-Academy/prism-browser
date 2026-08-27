import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { chromium } from 'playwright';
import { assertUrl } from './security.mjs';

const host = process.env.PRISM_BROWSER_HOST ?? '127.0.0.1';
const port = Number(process.env.PRISM_BROWSER_PORT ?? 4319);
const token = process.env.PRISM_BROWSER_TOKEN;
if (!token || token.length < 32) throw new Error('PRISM_BROWSER_TOKEN must contain at least 32 characters');
if (!['127.0.0.1', '::1', 'localhost'].includes(host)) throw new Error('The local sidecar may bind only to loopback; use a separate authenticated remote adapter for remote engines');
const egressProxy = process.env.PRISM_BROWSER_EGRESS_PROXY;
if (!egressProxy && process.env.PRISM_BROWSER_ALLOW_UNVERIFIED_EGRESS !== '1') throw new Error('PRISM_BROWSER_EGRESS_PROXY is required; set PRISM_BROWSER_ALLOW_UNVERIFIED_EGRESS=1 only for explicit local development');

const browser = await chromium.launch({headless: true, ...(egressProxy ? {proxy:{server:egressProxy}} : {})});
const sessions = new Map();

async function observe(session) {
  const page = session.page;
  const byteBudget = Math.min(Number(session.policy?.max_observation_bytes ?? 65536), 262144);
  const locator = page.locator('a,button,input,textarea,select,[role],[contenteditable="true"]:visible');
  const count = Math.min(await locator.count(), 250);
  session.refs = new Map();
  const elements = [];
  for (let i = 0; i < count; i++) {
    const item = locator.nth(i);
    if (!(await item.isVisible().catch(() => false))) continue;
    const ref = `e${elements.length + 1}`;
    session.refs.set(ref, item);
    elements.push(await item.evaluate((node, ref) => ({
      ref,
      role: node.getAttribute('role') || ({A:'link',BUTTON:'button',INPUT:'textbox',TEXTAREA:'textbox',SELECT:'combobox'}[node.tagName] ?? 'interactive'),
      name: (node.getAttribute('aria-label') || node.innerText || node.getAttribute('placeholder') || node.getAttribute('name') || '').slice(0, 512),
      value: 'value' in node && node.type !== 'password' ? String(node.value).slice(0, 2048) : undefined,
      disabled: Boolean(node.disabled || node.getAttribute('aria-disabled') === 'true'),
    }), ref));
  }
  const bodyText = await page.locator('body').evaluate((node, max) => node.innerText.slice(0, max), byteBudget * 2);
  const visible = bodyText.split(/\n+/).map(x => x.trim()).filter(Boolean).slice(0, 300);
  session.observation = `obs_${crypto.randomUUID()}`;
  const url = new URL(page.url());
  const result = {mode:'browser', observation_id:session.observation, url:url.href, origin:url.origin, title:(await page.title()).slice(0, 512), elements, visible_text:visible, fallback:null, truncated:count === 250 || visible.length === 300};
  if (Buffer.byteLength(JSON.stringify(result)) > byteBudget) throw Object.assign(new Error(), {code:'observation_too_large'});
  return result;
}

async function operation(path, body) {
  if (path === 'open') {
    if (sessions.has(body.attachment)) throw Object.assign(new Error(), {code:'attachment_exists'});
    const storageState = body.checkpoint ? JSON.parse(Buffer.from(body.checkpoint, 'base64url').toString('utf8')) : undefined;
    const context = await browser.newContext({storageState, acceptDownloads:false});
    const page = await context.newPage();
    sessions.set(body.attachment, {context, page, refs:new Map(), observation:null, policy:null});
    return {ok:true};
  }
  const session = sessions.get(body.attachment);
  if (!session) throw Object.assign(new Error(), {code:'attachment_not_found'});
  if (path === 'navigate') {
    await assertUrl(body.url, body.policy);
    session.policy = body.policy;
    await session.context.route('**/*', async route => {
      try { await assertUrl(route.request().url(), session.policy); return route.continue(); }
      catch { return route.abort('blockedbyclient'); }
    });
    await session.page.goto(body.url, {waitUntil:'domcontentloaded'});
    await assertUrl(session.page.url(), session.policy);
    return observe(session);
  }
  if (path === 'observe') return observe(session);
  if (path === 'act') {
    if (body.action.observation_id !== session.observation) throw Object.assign(new Error(), {code:'stale_observation'});
    const locator = session.refs.get(body.action.ref);
    if (!locator) throw Object.assign(new Error(), {code:'unknown_ref'});
    const value = body.action.value;
    if (body.action.kind === 'click') await locator.click();
    else if (body.action.kind === 'fill') await locator.fill(String(value ?? ''));
    else if (body.action.kind === 'select') await locator.selectOption(String(value ?? ''));
    else if (body.action.kind === 'press') await locator.press(String(value ?? ''));
    else if (body.action.kind === 'hover') await locator.hover();
    else if (body.action.kind === 'scroll') await locator.evaluate((node, amount) => node.scrollBy(0, Number(amount ?? 500)), value);
    else throw Object.assign(new Error(), {code:'action_not_allowed'});
    await session.page.waitForTimeout(100);
    return observe(session);
  }
  if (path === 'checkpoint') {
    const state = await session.context.storageState({indexedDB:true});
    return {checkpoint:Buffer.from(JSON.stringify(state)).toString('base64url')};
  }
  if (path === 'close') { await session.context.close(); sessions.delete(body.attachment); return {ok:true}; }
  throw Object.assign(new Error(), {code:'operation_not_found'});
}

http.createServer(async (req, res) => {
  res.setHeader('content-type', 'application/json');
  const expected = Buffer.from(`Bearer ${token}`);
  const supplied = Buffer.from(req.headers.authorization ?? '');
  if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) { res.statusCode=401; return res.end(JSON.stringify({error:'unauthorized'})); }
  if (req.method !== 'POST' || !req.url?.startsWith('/v1/')) { res.statusCode=404; return res.end(JSON.stringify({error:'not_found'})); }
  const chunks=[]; let bytes=0;
  for await (const chunk of req) { bytes += chunk.length; if (bytes > 1024*1024) { res.statusCode=413; return res.end(JSON.stringify({error:'payload_too_large'})); } chunks.push(chunk); }
  try {
    const body=JSON.parse(Buffer.concat(chunks).toString('utf8'));
    const result=await operation(req.url.slice(4), body);
    res.end(JSON.stringify(result));
  } catch (error) {
    res.statusCode=422;
    res.end(JSON.stringify({error:error?.code ?? 'engine_failed'}));
  }
}).listen(port, host, () => process.stdout.write(`prism-browser sidecar listening on http://${host}:${port}\n`));

for (const signal of ['SIGINT','SIGTERM']) process.on(signal, async () => { await browser.close(); process.exit(0); });
