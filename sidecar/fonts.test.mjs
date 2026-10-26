import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { chromium } from 'playwright';
import { capture, readCaptureRequest, resolverRules } from './capture.mjs';
import { inspectFonts } from './fonts.mjs';
import { fixtureFont } from './test-fixtures/font.mjs';

const face = { family: 'Capture Test', weight: 400, style: 'normal' };
test('require_fonts validates every face before reaching Chromium', () => {
  const request = (require_fonts) => readCaptureRequest({ url: 'http://capture.test/', widths: [375], require_fonts });
  assert.deepEqual(request(undefined).requireFonts, []);
  assert.deepEqual(request([face]).requireFonts, [face]);
  assert.equal(request([{ ...face, family: '😀'.repeat(128), weight: 1000 }]).requireFonts[0].family.length, 256);
  for (const value of [null, {}, 'font', Array(17).fill(face), [null], [{ ...face, family: '' }],
    [{ ...face, family: 'x'.repeat(129) }], [{ ...face, family: '😀'.repeat(129) }], [{ ...face, family: '\ud800' }], [{ ...face, weight: '400' }], [{ ...face, weight: 0 }],
    [{ ...face, weight: 1001 }], [{ ...face, weight: 400.5 }], [{ ...face, style: 'NORMAL' }], [{ family: 'a' }]]) {
    assert.throws(() => request(value), (e) => e.code === 'invalid_capture_options', JSON.stringify(value));
  }
});

test('font loading and actual glyph rendering under script-src none in pinned Chromium', async (t) => {
  const font = fixtureFont();
  let slowLoads = 0;
  const server = http.createServer((req, res) => {
    if (req.url === '/font.ttf') { res.setHeader('content-type', 'font/ttf'); return res.end(font); }
    if (req.url === '/missing.ttf') { res.statusCode = 404; return res.end(); }
    if (req.url === '/slow.ttf') { slowLoads++; return; } // Context closure aborts this unresponsive font request.
    res.setHeader('content-type', 'text/html; charset=utf-8');
    res.setHeader('content-security-policy', "script-src 'none'");
    const path = req.url;
    let declaration = path === '/missing' ? '' : `@font-face {font-family:'Capture Test';src:url('${path === '/404' ? '/missing.ttf' : '/font.ttf'}');font-weight:100 900;}`;
    if (path === '/caps') declaration += Array.from({ length: 60 }, (_, i) => `@font-face{font-family:'unused${i}';src:url('/font.ttf')}`).join('');
    if (path === '/unloaded') declaration = "@font-face{font-family:'Capture Test';src:url('/font.ttf');unicode-range:U+1000}";
    if (path === '/slow') declaration = "@font-face{font-family:'Capture Test';src:url('/slow.ttf')}";
    const text = path === '/fallback' ? 'ZZZZ' : path === '/partial' ? 'AAAA😀' : 'AAAA';
    let content = `<p>${text}</p>`;
    if (path === '/caps') content = '<p hidden>ZZZZ</p>' + '<p>A</p>'.repeat(60);
    if (path === '/later-family') content = '<p style="font-family:sans-serif,\'Capture Test\'">ZZZZ</p>';
    if (path === '/separate-emoji') content = '<p>AAAA</p><p>😀</p>';
    if (path === '/different-weight') content = '<p style="font-weight:950">ZZZZ</p>';
    if (path === '/slow') content = '<p style="font-family:sans-serif">not loaded until the guard asks</p>';
    res.end(`<html><head><style>${declaration}p{font:normal 400 24px 'Capture Test',sans-serif}</style></head><body>${content}</body></html>`);
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  let browser;
  t.after(async () => { await browser?.close(); server.close(); });
  browser = await chromium.launch({ headless: true, args: [`--host-resolver-rules=${resolverRules(new Map([['capture.test', '127.0.0.1']]))}`] });
  const origin = `http://capture.test:${server.address().port}`;
  const run = (path, extra = {}, engine = browser) => capture(engine, [origin], readCaptureRequest({ url: origin + path, widths: [375], require_fonts: [face], timeout_ms: 10000, ...extra }));
  await t.test('a same-origin self-hosted face loads and supplies custom glyphs', async () => {
    const facts = (await run('/ok')).captures[0].facts;
    assert.equal(facts.fonts_measured, true);
    assert.ok(facts.fonts.some((f) => f.family.replaceAll('"', '') === face.family && f.status === 'loaded'));
    assert.ok(facts.rendered_fonts.some((f) => f.custom && f.glyph_count > 0));
    assert.equal((await run('/ok', { require_fonts: [{ ...face, family: '"cApTuRe TeSt"', weight: 500 }], measure: false })).captures[0].facts, undefined);
  });
  for (const [path, statuses] of [['/404', ['error', 'unloaded']], ['/missing', ['missing']], ['/unloaded', ['unloaded']], ['/fallback', ['fallback_rendered']], ['/different-weight', ['fallback_rendered']]]) {
    await t.test(`${path} refuses with bounded face detail, even without measurement`, async () => {
      await assert.rejects(() => run(path, { measure: false }), (e) => {
        assert.equal(e.code, 'capture_fonts_failed');
        assert.equal(e.details.failed_fonts_total, 1);
        assert.deepEqual(e.details.failed_fonts[0], { ...face, status: e.details.failed_fonts[0].status });
        assert.ok(statuses.includes(e.details.failed_fonts[0].status));
        return true;
      });
    });
  }
  await t.test('partial fallback for an emoji passes and reports custom and platform usage', async () => {
    const facts = (await run('/partial')).captures[0].facts;
    assert.ok(facts.rendered_fonts.some((f) => f.custom && f.glyph_count > 0));
    assert.ok(facts.rendered_fonts.some((f) => !f.custom && f.glyph_count > 0));
    assert.equal(facts.rendered_fonts.filter((f) => f.custom).reduce((n, f) => n + f.glyph_count, 0), 4);
    assert.equal(facts.rendered_fonts.filter((f) => !f.custom).reduce((n, f) => n + f.glyph_count, 0), 1);
    assert.equal((await run('/separate-emoji')).captures.length, 1);
  });
  await t.test('declared facts and visible text sampling stay bounded; hidden text is skipped', async () => {
    const facts = (await run('/caps')).captures[0].facts;
    assert.equal(facts.fonts.length, 50);
    assert.equal(facts.rendered_fonts.reduce((sum, f) => sum + f.glyph_count, 0), 50);
    assert.ok(facts.rendered_fonts.every((f) => f.custom));
  });
  await t.test('only the first computed family participates in the fallback guard', async () => {
    assert.equal((await run('/later-family')).captures.length, 1);
  });
  await t.test('weight and style must match a declared face', async () => {
    for (const extra of [{ weight: 950 }, { style: 'italic' }]) {
      await assert.rejects(() => run('/ok', { require_fonts: [{ ...face, ...extra }] }), (e) => e.code === 'capture_fonts_failed' && e.details.failed_fonts[0].status === 'missing');
    }
  });
  await t.test('an unresponsive fonts.load is bounded by the remaining capture budget', async () => {
    const started = Date.now();
    await assert.rejects(() => run('/slow', { timeout_ms: 1800, measure: false }), (e) => e.code === 'capture_fonts_failed' && e.details.failed_fonts[0].status === 'unloaded');
    assert.equal(slowLoads, 1);
    assert.ok(Date.now() - started < 4500);
  });
  await t.test('the default still captures missing and wholly fallback fonts', async () => {
    for (const path of ['/404', '/missing', '/fallback']) assert.equal((await run(path, { require_fonts: [] })).captures.length, 1);
  });
  await t.test('optional measurement failure cannot refuse a capture; required inspection still refuses', async () => {
    const engine = { newContext: async (options) => {
      const context = await browser.newContext(options);
      context.newCDPSession = async () => { throw new Error('injected CDP failure'); };
      return context;
    } };
    const result = (await run('/ok', { require_fonts: [] }, engine)).captures[0];
    assert.deepEqual([result.facts.fonts, result.facts.rendered_fonts, result.facts.fonts_measured], [[], [], false]);
    assert.ok(Buffer.from(result.png, 'base64').length > 0);
    await assert.rejects(() => run('/ok', {}, engine), (e) => e.code === 'capture_failed');
  });
  await t.test('optional measurement timeout preserves screenshot budget and returns unmeasured facts', async () => {
    let screenshotBudget;
    const engine = { newContext: async (options) => {
      const context = await browser.newContext(options);
      context.newCDPSession = () => new Promise(() => {});
      const newPage = context.newPage.bind(context);
      context.newPage = async () => {
        const page = await newPage(), screenshot = page.screenshot.bind(page);
        page.screenshot = (options) => { screenshotBudget = options.timeout; return screenshot(options); };
        return page;
      };
      return context;
    } };
    const result = (await run('/ok', { require_fonts: [], timeout_ms: 6000 }, engine)).captures[0];
    assert.deepEqual([result.facts.fonts, result.facts.rendered_fonts, result.facts.fonts_measured], [[], [], false]);
    assert.ok(screenshotBudget >= 1500, `screenshot received ${screenshotBudget}ms`);
    const short = (await run('/ok', { require_fonts: [], timeout_ms: 2000 })).captures[0];
    assert.equal(short.facts.fonts_measured, false);
  });
});

test('DevTools font observation cannot outlive the shared capture budget', async () => {
  let detached = false;
  const context = { newCDPSession: async () => ({ send: () => new Promise(() => {}), detach: async () => { detached = true; } }) };
  const page = { evaluate: async () => ({ fonts: [], failed: [] }) };
  const deadline = Date.now() + 40;
  const remaining = () => Math.max(1, deadline - Date.now());
  await assert.rejects(() => inspectFonts(context, page, [], remaining), (e) => e.code === 'capture_timeout');
  assert.equal(detached, true);
});
