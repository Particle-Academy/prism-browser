import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { chromium } from 'playwright';
import { capture, isDeclared, isNonPublic, parseOrigins, pinOrigins, readCaptureRequest, resolverRules } from './capture.mjs';

const code = (expected) => (error) => error.code === expected;

test('parseOrigins: exactly an origin, http or https, and never an IP literal', () => {
  assert.deepEqual(parseOrigins('https://flabs.gen, HTTP://Capture.Test:8080/'), ['https://flabs.gen', 'http://capture.test:8080']);
  assert.deepEqual(parseOrigins(''), []);
  for (const bad of ['https://flabs.gen/sites', 'https://flabs.gen?x=1', 'https://u:p@flabs.gen', 'ftp://flabs.gen', 'https://127.0.0.1', 'https://[::1]', 'flabs.gen']) {
    assert.throws(() => parseOrigins(bad), code('invalid_capture_origin'), bad);
  }
});

test('pinOrigins: loopback or public only, never a mix, never another private range', async () => {
  const fixed = (addresses) => async () => addresses.map((address) => ({ address, family: address.includes(':') ? 6 : 4 }));
  assert.equal((await pinOrigins(['https://flabs.gen'], fixed(['127.0.0.1']))).get('flabs.gen'), '127.0.0.1');
  assert.equal((await pinOrigins(['https://example.com'], fixed(['93.184.216.34']))).get('example.com'), '93.184.216.34');
  for (const addresses of [['10.0.0.7'], ['169.254.169.254'], ['100.100.100.200'], ['192.168.1.2'], ['127.0.0.1', '93.184.216.34'], ['64:ff9b::a9fe:a9fe'], ['fd00::1']]) {
    await assert.rejects(() => pinOrigins(['https://flabs.gen'], fixed(addresses)), code('capture_origin_private'), addresses.join());
  }
  await assert.rejects(() => pinOrigins(['https://flabs.gen'], fixed([])), code('capture_origin_unresolved'));
});

test('isNonPublic: the ranges the shared check misses are refused here too', () => {
  for (const address of ['100.64.0.1', '198.18.0.1', '64:ff9b::1', '2002:a9fe:a9fe::', '::ffff:10.0.0.1']) assert.equal(isNonPublic(address), true, address);
  assert.equal(isNonPublic('93.184.216.34'), false);
});

test('resolverRules: declared hosts pinned, everything else unresolvable', () => {
  assert.equal(resolverRules(new Map([['flabs.gen', '127.0.0.1'], ['v6.test', '::1']])), 'MAP flabs.gen 127.0.0.1, MAP v6.test [::1], MAP * ~NOTFOUND');
});

test('readCaptureRequest: bounds are enforced, defaults are explicit', () => {
  const ok = readCaptureRequest({ url: 'https://flabs.gen/', widths: [375, 768, 375] });
  assert.deepEqual(ok.widths, [375, 768]);
  assert.equal(ok.fullPage, true);
  assert.equal(ok.clip, false);
  for (const bad of [{ widths: [] }, { widths: [100] }, { widths: [5000] }, { widths: Array(9).fill(400) }, { widths: [375.5] },
    { widths: [375], max_height: 99999 }, { widths: [375], timeout_ms: 'x' }, { widths: [375], clip: 'yes' }, { url: 7, widths: [375] }]) {
    assert.throws(() => readCaptureRequest({ url: 'https://flabs.gen/', ...bad }), code('invalid_capture_options'), JSON.stringify(bad));
  }
});

test('isDeclared: origin equality, nothing looser', () => {
  const origins = ['https://flabs.gen'];
  assert.equal(isDeclared('https://flabs.gen/sites/render/1', origins), true);
  for (const url of ['https://flabs.gen.evil.test/', 'http://flabs.gen/', 'https://flabs.gen:8443/', 'not a url']) assert.equal(isDeclared(url, origins), false, url);
});

// Real Chromium, pinned as the sidecar pins it, against a page served on loopback.
test('capture, end to end in a pinned Chromium', async (t) => {
  const offOrigin = { hits: 0 };
  const off = http.createServer((req, res) => { offOrigin.hits += 1; res.end('x'); });
  await new Promise((r) => off.listen(0, '127.0.0.1', r));
  const pages = {
    '/narrow': '<html><body style="margin:0"><div style="width:600px;height:100px;background:#09f">wide</div><button style="width:20px;height:20px">x</button><a href="/n" style="display:inline-block;width:60px;height:60px">big enough</a></body></html>',
    '/tall': '<html><body style="margin:0"><div style="height:5000px;background:linear-gradient(#000,#fff)"></div></body></html>',
    '/ready': '<html><body>wait<script>setTimeout(() => document.documentElement.setAttribute("data-prism-capture-ready", ""), 300)</script></body></html>',
    '/never-ready': '<html><body>never</body></html>',
    '/leak': `<html><body><img src="http://leak.test:${off.address().port}/pixel.png"><img src="http://127.0.0.1:${off.address().port}/direct.png"></body></html>`,
    // require_images. CSP `script-src 'none'` on every one: the check must work on a page that runs no script.
    '/images-ok': '<html><body><img src="/pixel.png" width="10" height="10"><img src="/pixel.png?b" alt="b"></body></html>',
    '/images-404': '<html><body><img src="/pixel.png"><img src="/missing.png"></body></html>',
    '/images-corrupt': '<html><body><img src="/corrupt.png"></body></html>',
    '/images-lazy': '<html><body style="margin:0"><div style="height:20000px">tall</div><img loading="lazy" src="/pixel.png?lazy"></body></html>',
    '/images-svg': '<html><body><img src="/nosize.svg"></body></html>',
    '/images-srcset': '<html><body><img src="/pixel.png?1x" srcset="/pixel.png?1x 1x, /pixel.png?2x 2x"></body></html>',
  };
  // A real 1x1 PNG.
  const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
  const server = http.createServer((req, res) => {
    if (req.url === '/redirect') { res.statusCode = 302; res.setHeader('location', `http://leak.test:${off.address().port}/`); return res.end(); }
    const path = req.url.split('?')[0];
    if (path === '/pixel.png') { res.setHeader('content-type', 'image/png'); return res.end(PIXEL); }
    if (path === '/corrupt.png') { res.setHeader('content-type', 'image/png'); return res.end('not a png at all'); }
    if (path === '/nosize.svg') { res.setHeader('content-type', 'image/svg+xml'); return res.end('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'); }
    if (path === '/missing.png') { res.statusCode = 404; return res.end('missing'); }
    res.setHeader('content-type', 'text/html');
    if (req.url.startsWith('/images-')) res.setHeader('content-security-policy', "script-src 'none'");
    res.end(pages[req.url] ?? '<html><body>ok</body></html>');
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const origin = `http://capture.test:${server.address().port}`;
  const pins = await pinOrigins([origin], async () => [{ address: '127.0.0.1', family: 4 }]);
  // Cleanup is registered BEFORE the launch: a launch that throws (no browser installed) left both servers listening,
  // and node --test then never exited -- a CI job that hung for four hours instead of failing.
  let browser;
  t.after(async () => { await browser?.close(); server.close(); off.close(); });
  browser = await chromium.launch({ headless: true, args: [`--host-resolver-rules=${resolverRules(pins)}`] });
  const run = (path, extra = {}) => capture(browser, [origin], readCaptureRequest({ url: `${origin}${path}`, widths: [375], timeout_ms: 20000, ...extra }));
  const pngSize = (base64) => { const b = Buffer.from(base64, 'base64'); return { signature: b.subarray(0, 8).toString('hex'), width: b.readUInt32BE(16), height: b.readUInt32BE(20) }; };

  await t.test('PNGs at each width, the size the page measured, with the facts the sidecar measured', async () => {
    const result = await run('/narrow', { widths: [375, 1280] });
    assert.equal(result.captures.length, 2);
    const [phone, desktop] = result.captures;
    // The 600 px element overflows the 375 px viewport: the capture shows it, at its real width, and says so.
    assert.deepEqual(pngSize(phone.png), { signature: '89504e470d0a1a0a', width: 600, height: phone.captured_height });
    assert.equal(phone.width, 375);
    assert.equal(phone.captured_width, 600);
    assert.equal(pngSize(desktop.png).width, 1280);
    assert.equal(phone.facts.horizontal_overflow, true);
    assert.equal(desktop.facts.horizontal_overflow, false);
    assert.equal(phone.facts.small_targets_total, 1);
    assert.equal(phone.facts.small_targets[0].tag, 'button');
    assert.equal(phone.clipped, false);
  });

  await t.test('a page taller than allowed is refused, or clipped and said so when asked', async () => {
    await assert.rejects(() => run('/tall', { max_height: 1000 }), code('capture_too_large'));
    const clipped = (await run('/tall', { max_height: 1000, clip: true })).captures[0];
    assert.equal(clipped.clipped, true);
    assert.equal(clipped.page_height, 5000);
    assert.equal(pngSize(clipped.png).height, 1000);
    await assert.rejects(() => run('/narrow', { max_bytes: 1024 }), code('capture_too_large'));
  });

  await t.test('the page-declared readiness attribute is waited for, within the budget', async () => {
    assert.equal((await run('/ready', { wait_for_ready_attribute: true })).captures.length, 1);
    await assert.rejects(() => run('/never-ready', { wait_for_ready_attribute: true, timeout_ms: 2000 }), code('capture_timeout'));
  });

  await t.test('require_images: healthy images pass, and the facts name each selected source and size', async () => {
    const [capture] = (await run('/images-ok', { require_images: true })).captures;
    assert.equal(capture.facts.images_total, 2);
    assert.deepEqual(capture.facts.images.map((image) => [new URL(image.src).pathname + new URL(image.src).search, image.natural_width, image.natural_height]),
      [['/pixel.png', 1, 1], ['/pixel.png?b', 1, 1]]);
  });

  await t.test('require_images: a 404 or an undecodable image refuses the capture, naming the image', async () => {
    const failed = (path) => run(path, { require_images: true, timeout_ms: 8000 }).then(() => assert.fail('captured a broken page'), (error) => error);
    const missing = await failed('/images-404');
    assert.equal(missing.code, 'capture_images_failed');
    assert.equal(missing.details.failed_images_total, 1);
    assert.equal(new URL(missing.details.failed_images[0].src).pathname, '/missing.png');
    assert.equal(missing.details.failed_images[0].natural_width, 0);
    const corrupt = await failed('/images-corrupt');
    assert.equal(corrupt.code, 'capture_images_failed');
    assert.equal(new URL(corrupt.details.failed_images[0].src).pathname, '/corrupt.png');
    // Without the option the same broken page is captured, exactly as before.
    assert.equal((await run('/images-404')).captures.length, 1);
  });

  await t.test('require_images: a lazy image that never loads is refused as not complete, not waited on forever', async () => {
    const started = Date.now();
    const error = await run('/images-lazy', { require_images: true, timeout_ms: 6000 }).then(() => null, (e) => e);
    assert.ok(Date.now() - started < 9000, 'bounded by the capture budget');
    assert.equal(error?.code, 'capture_images_failed');
    assert.equal(error.details.failed_images[0].complete, false);
  });

  await t.test('require_images: an SVG with no intrinsic size is not mistaken for a broken image', async () => {
    // Measured in the pinned Chromium: the default object size, 300 x 150, so `natural_width > 0` holds.
    const [capture] = (await run('/images-svg', { require_images: true })).captures;
    assert.deepEqual([capture.facts.images[0].natural_width, capture.facts.images[0].natural_height], [300, 150]);
  });

  await t.test('require_images: srcset selection is reported as the source actually used', async () => {
    const [capture] = (await run('/images-srcset', { require_images: true })).captures;
    assert.equal(new URL(capture.facts.images[0].src).search, '?1x');
  });

  // The two layers, each on its own: either one alone must hold.
  await t.test('layer 1: the pinned browser resolves nothing undeclared, with no route check at all', async () => {
    const page = await browser.newPage();
    for (const url of [`http://leak.test:${off.address().port}/`, `http://127.0.0.1:${off.address().port}/`, `http://localhost:${off.address().port}/`]) {
      await assert.rejects(() => page.goto(url, { timeout: 5000 }), /ERR_NAME_NOT_RESOLVED/, url);
    }
    await page.close();
    assert.equal(offOrigin.hits, 0);
  });

  await t.test('layer 2: the route check alone stops an off-origin subresource, in a browser with no pinning', async () => {
    const open = await chromium.launch({ headless: true, args: [`--host-resolver-rules=MAP capture.test 127.0.0.1, MAP leak.test 127.0.0.1`] });
    try {
      await capture(open, [origin], readCaptureRequest({ url: `${origin}/leak`, widths: [375], timeout_ms: 20000 }));
    } finally {
      await open.close();
    }
    assert.equal(offOrigin.hits, 0);
  });

  await t.test('nothing undeclared is reached: not a redirect target, not a subresource by name or by IP', async () => {
    await assert.rejects(() => run('/redirect'), (error) => ['capture_origin_not_declared', 'capture_failed'].includes(error.code));
    await run('/leak');
    assert.equal(offOrigin.hits, 0);
    await assert.rejects(() => capture(browser, [origin], readCaptureRequest({ url: `http://127.0.0.1:${server.address().port}/`, widths: [375] })), code('capture_origin_not_declared'));
  });
});
