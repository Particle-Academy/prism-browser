import net from 'node:net';
import dns from 'node:dns/promises';
import { isPrivateLiteral } from './security.mjs';
import { inspectFonts, readRequiredFonts } from './fonts.mjs';
// Note: isPrivateLiteral treats `localhost`-style NAMES as private too; an address is all it is given here.

/**
 * PAGE CAPTURE: screenshots of pages the OPERATOR declared, at the widths a caller asks for.
 *
 * Separate from the agent browser on purpose. The agent's policy refuses loopback and private addresses even when
 * allow-listed, and that stays exactly as it is. Capture is for a lab or app looking at ITS OWN pages, which are
 * often served on loopback, so it trusts only origins named in configuration (PRISM_BROWSER_CAPTURE_ORIGINS), never
 * one a model or a request supplies:
 *
 * - each declared host is resolved ONCE, at startup; an address in a private range other than loopback (10/8, the
 *   metadata endpoint, CGNAT, link-local, ...) refuses the whole configuration;
 * - captures run in their own Chromium, launched with --host-resolver-rules that MAP each declared host to the address
 *   verified for it and every other host to NOTFOUND. Measured with Playwright's Chromium: an undeclared host, `localhost`
 *   and a bare `127.0.0.1` all fail to resolve, and so does an off-origin subresource. There is no second lookup to
 *   rebind, and nothing undeclared to reach;
 * - every request is also checked against the declared origins at the route level, and service workers are blocked.
 *
 * Nothing the page is told comes from the caller: readiness is the load event, network idle and document.fonts.ready,
 * plus an optional attribute the PAGE sets on <html>; the measurements are this file's own script.
 */

export const READY_ATTRIBUTE = 'data-prism-capture-ready';

/** Bounds a request may ask for, and never exceed. */
export const LIMITS = {
  maxWidths: 8,
  minWidth: 240,
  maxWidth: 3840,
  viewportHeight: 900,
  maxHeight: 32768,
  maxBytes: 25 * 1024 * 1024,
  maxTimeoutMs: 120000,
  maxSmallTargets: 50,
  maxImages: 100,
  maxImageSrc: 2048,
  imageDecodeMs: 5000,
};

/** A refusal: a stable code, and optionally snake_case details the caller may read (e.g. `failed_images`). */
const refuse = (code, details = undefined) => Object.assign(new Error(code), { code, ...(details === undefined ? {} : { details }) });

/** A loopback address: 127/8 or ::1, in any spelling Node returns. */
export function isLoopback(address) {
  let ip = address.replace(/^\[|\]$/g, '').toLowerCase();
  if (ip.startsWith('::ffff:')) ip = ip.slice(7);
  if (ip === '::1') return true;
  return net.isIPv4(ip) && Number(ip.split('.')[0]) === 127;
}

/**
 * Not a public address: every range isPrivateLiteral knows, plus CGNAT (100.64/10) and the NAT64 / 6to4 / Teredo
 * prefixes, which are refused outright here rather than decoded -- a capture origin has no reason to be one.
 */
export function isNonPublic(address) {
  let ip = address.replace(/^\[|\]$/g, '').toLowerCase();
  if (ip.startsWith('::ffff:')) ip = ip.slice(7);
  if (isPrivateLiteral(ip)) return true;
  if (net.isIPv4(ip)) {
    const [a, b] = ip.split('.').map(Number);
    return (a === 100 && b >= 64 && b <= 127) || (a === 198 && (b === 18 || b === 19));
  }
  return ip.startsWith('64:ff9b:') || ip.startsWith('2002:') || ip.startsWith('2001:0:') || ip.startsWith('2001::');
}

/**
 * The declared origins, parsed: comma-separated `scheme://host[:port]`, http or https, nothing else in them. Throws
 * `invalid_capture_origin` for anything that is not exactly an origin.
 */
export function parseOrigins(value) {
  const origins = [];
  for (const raw of String(value ?? '').split(',').map((entry) => entry.trim()).filter(Boolean)) {
    let url;
    try { url = new URL(raw); } catch { throw refuse('invalid_capture_origin'); }
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash
      || (url.pathname !== '/' && url.pathname !== '') || raw.replace(/\/$/, '').toLowerCase() !== url.origin.toLowerCase()) {
      throw refuse('invalid_capture_origin');
    }
    if (net.isIP(url.hostname.replace(/^\[|\]$/g, ''))) throw refuse('invalid_capture_origin');
    origins.push(url.origin);
  }
  return [...new Set(origins)];
}

/**
 * Resolve each declared host once and choose the address its capture browser is pinned to. Loopback or public only:
 * a host resolving to any other private address refuses the configuration (`capture_origin_private`).
 */
export async function pinOrigins(origins, lookup = dns.lookup) {
  const pins = new Map();
  for (const origin of origins) {
    const host = new URL(origin).hostname;
    if (pins.has(host)) continue;
    const addresses = await lookup(host, { all: true, verbatim: true });
    if (addresses.length === 0) throw refuse('capture_origin_unresolved');
    for (const { address } of addresses) {
      if (!isLoopback(address) && isNonPublic(address)) throw refuse('capture_origin_private');
    }
    const loopback = addresses.filter(({ address }) => isLoopback(address));
    // Never a mix: a host that resolves both to loopback and to the internet is one whose answer could be chosen for it.
    if (loopback.length > 0 && loopback.length !== addresses.length) throw refuse('capture_origin_private');
    const chosen = addresses.find(({ family }) => family === 4) ?? addresses[0];
    pins.set(host, chosen.address);
  }
  return pins;
}

/** Chromium's resolver rules: each declared host to its verified address, everything else unresolvable. */
export function resolverRules(pins) {
  const maps = [...pins].map(([host, address]) => `MAP ${host} ${address.includes(':') ? `[${address}]` : address}`);
  return [...maps, 'MAP * ~NOTFOUND'].join(', ');
}

/** Whether a URL is on a declared origin. Anything that does not parse is not. */
export function isDeclared(raw, origins) {
  try { return origins.includes(new URL(raw).origin); } catch { return false; }
}

/** A capture request, checked against LIMITS: what the caller asked for, or `invalid_capture_options`. */
export function readCaptureRequest(body) {
  const widths = body?.widths;
  if (!Array.isArray(widths) || widths.length === 0 || widths.length > LIMITS.maxWidths
    || widths.some((w) => !Number.isInteger(w) || w < LIMITS.minWidth || w > LIMITS.maxWidth)) {
    throw refuse('invalid_capture_options');
  }
  const integer = (value, fallback, min, max) => {
    if (value === undefined || value === null) return fallback;
    if (!Number.isInteger(value) || value < min || value > max) throw refuse('invalid_capture_options');
    return value;
  };
  const flag = (value, fallback) => {
    if (value === undefined || value === null) return fallback;
    if (typeof value !== 'boolean') throw refuse('invalid_capture_options');
    return value;
  };
  if (typeof body.url !== 'string') throw refuse('invalid_capture_options');
  return {
    url: body.url,
    widths: [...new Set(widths)],
    fullPage: flag(body.full_page, true),
    clip: flag(body.clip, false),
    waitForReadyAttribute: flag(body.wait_for_ready_attribute, false),
    requireImages: flag(body.require_images, false),
    requireFonts: readRequiredFonts(body.require_fonts),
    measure: flag(body.measure, true),
    viewportHeight: integer(body.viewport_height, LIMITS.viewportHeight, 200, 4320),
    maxHeight: integer(body.max_height, 16384, 200, LIMITS.maxHeight),
    maxBytes: integer(body.max_bytes, 15 * 1024 * 1024, 1024, LIMITS.maxBytes),
    timeoutMs: integer(body.timeout_ms, 30000, 1000, LIMITS.maxTimeoutMs),
  };
}

/**
 * `require_images`: every <img> has loaded AND decoded, or the capture is refused with `capture_images_failed`
 * naming the images that did not. `load`, network idle, fonts and a static ready marker are all satisfied by a page
 * whose image 404s or fails to decode, so without this a broken page is captured as if it were the real one.
 *
 * Waits until every image has SETTLED (loaded or failed). An image that never settles -- a lazy image out of view --
 * is reported as not complete rather than waited on until the budget runs out. Each loaded image is then decoded,
 * bounded by LIMITS.imageDecodeMs. Read through the DevTools protocol, so it works on a page that allows no script.
 */
async function requireImages(page, remaining) {
  try {
    await page.waitForFunction(() => [...document.images].every((img) => img.complete), null, {
      timeout: Math.max(1, remaining() - 2000),
    });
  } catch (error) {
    if (error?.name !== 'TimeoutError') throw error;
  }
  const images = await page.evaluate(checkImages, { decodeMs: Math.min(LIMITS.imageDecodeMs, remaining()), maxSrc: LIMITS.maxImageSrc });
  const failed = images.filter((image) => !image.complete || !image.decoded || image.natural_width === 0);
  if (failed.length > 0) {
    throw refuse('capture_images_failed', {
      failed_images: failed.slice(0, LIMITS.maxImages).map(({ src, natural_width, natural_height, complete, decoded }) => (
        { src, natural_width, natural_height, complete, decoded })),
      failed_images_total: failed.length,
    });
  }
}

/** Runs in the page: each <img>'s state, decoding the loaded ones (bounded). */
async function checkImages({ decodeMs, maxSrc }) {
  return Promise.all([...document.images].map(async (img) => {
    let decoded = false;
    if (img.complete && img.naturalWidth > 0) {
      try {
        await Promise.race([img.decode(), new Promise((_, reject) => setTimeout(() => reject(new Error('decode timeout')), decodeMs))]);
        decoded = true;
      } catch {
        decoded = false;
      }
    }
    return {
      src: (img.currentSrc || img.getAttribute('src') || '').slice(0, maxSrc),
      natural_width: img.naturalWidth,
      natural_height: img.naturalHeight,
      complete: img.complete,
      decoded,
    };
  }));
}

/** The page's own facts, measured by this script: never one a caller supplies. */
function measurePage({ maxSmallTargets, maxImages, maxImageSrc }) {
  const doc = document.documentElement;
  const body = document.body;
  const selector = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[role=button],[role=link],[role=checkbox],[role=tab],[role=menuitem],[tabindex]:not([tabindex="-1"])';
  const small = [];
  let total = 0;
  for (const node of document.querySelectorAll(selector)) {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    if (rect.width === 0 || rect.height === 0 || style.visibility === 'hidden' || style.display === 'none') continue;
    if (rect.width >= 44 && rect.height >= 44) continue;
    total += 1;
    if (small.length >= maxSmallTargets) continue;
    const text = (node.getAttribute('aria-label') || node.textContent || node.getAttribute('value') || '').replace(/\s+/g, ' ').trim();
    small.push({
      tag: node.tagName.toLowerCase(),
      role: node.getAttribute('role'),
      text: text.slice(0, 80),
      x: Math.round(rect.left + window.scrollX), y: Math.round(rect.top + window.scrollY),
      width: Math.round(rect.width), height: Math.round(rect.height),
    });
  }
  // Each image's selected source and intrinsic size: what a host checks to know which srcset variant it got.
  const images = [...document.images];
  return {
    images: images.slice(0, maxImages).map((img) => ({
      src: (img.currentSrc || img.getAttribute('src') || '').slice(0, maxImageSrc),
      natural_width: img.naturalWidth,
      natural_height: img.naturalHeight,
    })),
    imagesTotal: images.length,
    viewportWidth: window.innerWidth,
    scrollWidth: Math.max(doc.scrollWidth, body ? body.scrollWidth : 0),
    scrollHeight: Math.max(doc.scrollHeight, body ? body.scrollHeight : 0),
    smallTargets: small,
    smallTargetsTotal: total,
  };
}

/**
 * Capture `request.url` at each width, in `browser` (the pinned capture browser). Refusals are codes:
 * `capture_origin_not_declared`, `capture_timeout`, `capture_too_large`, `capture_failed`, and with `require_images`
 * `capture_images_failed` (details: `failed_images`, `failed_images_total`).
 */
export async function capture(browser, origins, request) {
  if (!isDeclared(request.url, origins)) throw refuse('capture_origin_not_declared');
  const deadline = Date.now() + request.timeoutMs;
  const remaining = () => {
    const left = deadline - Date.now();
    if (left <= 0) throw refuse('capture_timeout');
    return left;
  };
  const results = [];
  for (const width of request.widths) {
    const context = await browser.newContext({
      viewport: { width, height: request.viewportHeight },
      deviceScaleFactor: 1,
      serviceWorkers: 'block',
      acceptDownloads: false,
    });
    try {
      await context.route('**/*', (route) => (isDeclared(route.request().url(), origins) ? route.continue() : route.abort('blockedbyclient')));
      const page = await context.newPage();
      let fontFacts = { fonts: [], rendered_fonts: [] };
      try {
        await page.goto(request.url, { waitUntil: 'load', timeout: remaining() });
        await page.waitForLoadState('networkidle', { timeout: remaining() });
        await page.waitForFunction(() => document.fonts.ready.then(() => true), null, { timeout: remaining() });
        if (request.waitForReadyAttribute) {
          await page.waitForFunction((name) => document.documentElement.hasAttribute(name), READY_ATTRIBUTE, { timeout: remaining() });
        }
        if (request.requireImages) await requireImages(page, remaining);
        if (request.measure || request.requireFonts.length) fontFacts = await inspectFonts(context, page, request.requireFonts, remaining);
      } catch (error) {
        if (error?.code) throw error;
        throw refuse(error?.name === 'TimeoutError' ? 'capture_timeout' : 'capture_failed');
      }
      // A redirect off the declared origins is refused, not followed into a screenshot.
      if (!isDeclared(page.url(), origins)) throw refuse('capture_origin_not_declared');
      const facts = await page.evaluate(measurePage, { maxSmallTargets: LIMITS.maxSmallTargets, maxImages: LIMITS.maxImages, maxImageSrc: LIMITS.maxImageSrc });
      const pageHeight = Math.max(facts.scrollHeight, request.viewportHeight);
      let clipped = false;
      let height = request.fullPage ? pageHeight : request.viewportHeight;
      if (height > request.maxHeight) {
        if (!request.clip) throw refuse('capture_too_large');
        height = request.maxHeight;
        clipped = true;
      }
      let png;
      try {
        png = await page.screenshot({
          type: 'png',
          animations: 'disabled',
          timeout: remaining(),
          ...(request.fullPage && !clipped ? { fullPage: true } : { fullPage: request.fullPage, clip: { x: 0, y: 0, width, height } }),
        });
      } catch (error) {
        if (error?.code) throw error;
        throw refuse(error?.name === 'TimeoutError' ? 'capture_timeout' : 'capture_failed');
      }
      if (png.length > request.maxBytes) throw refuse('capture_too_large');
      // A full page is as wide as its content: a page that overflows a 375 px viewport is captured at its real width,
      // never cropped to the viewport without saying so. The width actually captured is reported beside the one asked.
      results.push({
        width,
        captured_width: png.readUInt32BE(16),
        viewport_height: request.viewportHeight,
        page_height: pageHeight,
        captured_height: height,
        clipped,
        png: png.toString('base64'),
        ...(request.measure ? {
          facts: {
            scroll_width: facts.scrollWidth,
            horizontal_overflow: facts.scrollWidth > facts.viewportWidth,
            small_targets: facts.smallTargets,
            small_targets_total: facts.smallTargetsTotal,
            images: facts.images,
            images_total: facts.imagesTotal,
            ...fontFacts,
          },
        } : {}),
      });
    } finally {
      await context.close();
    }
  }
  return { url: request.url, captures: results };
}
