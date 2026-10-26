/** Font observations use trusted DevTools evaluation, including under script-src 'none'. */
export const FONT_LIMITS = { required: 16, declared: 50, rendered: 20, string: 128, elements: 50, textNodes: 5000, loadMs: 5000 };
const refusal = (code, details) => Object.assign(new Error(code), { code, ...(details ? { details } : {}) });
const cut = (value) => [...value].slice(0, FONT_LIMITS.string).join('');
const familyKey = (value) => value.trim().replace(/^(["'])(.*)\1$/s, '$2').toLowerCase();

export function readRequiredFonts(value = []) {
  if (!Array.isArray(value) || value.length > FONT_LIMITS.required || value.some((face) => !face || typeof face !== 'object'
    || typeof face.family !== 'string' || !face.family.isWellFormed() || !face.family.replace(/^[ \t\n\r\v\f]+|[ \t\n\r\v\f]+$/g, '') || [...face.family].length > FONT_LIMITS.string
    || !Number.isInteger(face.weight) || face.weight < 1 || face.weight > 1000 || !['normal', 'italic', 'oblique'].includes(face.style))) {
    throw refusal('invalid_capture_options');
  }
  return value.map(({ family, weight, style }) => ({ family, weight, style }));
}

/** Runs in the page. Matching a loaded face does not establish that it supplied any glyphs. */
async function inspectFaces({ required, limits, loadMs }) {
  const key = (value) => value.trim().replace(/^(["'])(.*)\1$/s, '$2').toLowerCase();
  const covers = (descriptor, weight) => {
    const values = descriptor.trim().replace(/^normal$/, '400').replace(/^bold$/, '700').split(/\s+/).map(Number);
    return values.length <= 2 && values.every(Number.isFinite) && weight >= values[0] && weight <= values.at(-1);
  };
  const matches = (face, wanted) => key(face.family) === key(wanted.family)
    && covers(face.weight, wanted.weight) && face.style.split(/\s+/)[0] === wanted.style;
  const failed = [];
  const quote = (value) => '"' + value.replace(/["\\\x00-\x1f\x7f]/g, (char) => `\\${char.codePointAt(0).toString(16)} `) + '"';
  // A single bounded load covers all requested faces, rather than spending a fresh budget on every face.
  await Promise.all(required.map(async (wanted) => {
    let exists = false;
    for (const face of document.fonts) if (matches(face, wanted)) { exists = true; break; }
    if (!exists) { failed.push({ ...wanted, status: 'missing' }); return; }
    let timer;
    try {
      await Promise.race([
        document.fonts.load(`${wanted.style} ${wanted.weight} 16px ${quote(wanted.family.trim().replace(/^(["'])(.*)\1$/s, '$2'))}`),
        new Promise((resolve) => { timer = setTimeout(resolve, loadMs); }),
      ]);
    } catch { /* FontFace.status below distinguishes a load error from an unsettled face. */ }
    finally { clearTimeout(timer); }
    let loaded = false, error = false;
    for (const face of document.fonts) {
      if (!matches(face, wanted)) continue;
      loaded ||= face.status === 'loaded'; error ||= face.status === 'error';
    }
    if (!loaded) failed.push({ ...wanted, status: error ? 'error' : 'unloaded' });
  }));
  const fonts = [];
  for (const face of document.fonts) {
    if (fonts.length >= limits.declared) break;
    const cut = (value) => [...value].slice(0, limits.string).join('');
    fonts.push({ family: cut(face.family), weight: cut(face.weight), style: cut(face.style), status: face.status });
  }
  return { fonts, failed };
}

/** Runs through Runtime.evaluate: bounded, visible elements with direct, non-whitespace text. */
function sampleText(limits) {
  const nodes = [], metadata = [], seen = new Set();
  const walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
  let node, scanned = 0;
  while (scanned++ < limits.textNodes && nodes.length < limits.elements && (node = walker.nextNode())) {
    const parent = node.parentElement;
    if (!parent || seen.has(parent) || !node.textContent.trim()) continue;
    seen.add(parent);
    const style = getComputedStyle(parent), rect = parent.getBoundingClientRect();
    if (!parent.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) || !rect.width || !rect.height) continue;
    // The first family may itself contain a comma inside quotes. Never split a quoted name at that comma.
    const first = style.fontFamily.match(/^\s*(?:"([^"]*)"|'([^']*)'|([^,]*))/);
    metadata.push({ family: first?.[1] ?? first?.[2] ?? first?.[3] ?? '' });
    nodes.push(parent);
  }
  return { nodes, metadata };
}

/**
 * A bounded sample is evidence, not an exhaustive glyph audit. For each required family, zero custom
 * glyphs across its sampled text is refused. Partial fallback is allowed: emoji, symbols and unicode-range subsets
 * can legitimately use platform fonts alongside the loaded face. Both kinds of usage are reported as facts.
 */
export async function inspectFonts(context, page, required, remaining) {
  const bounded = async (promise) => {
    let timer;
    try {
      return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(refusal('capture_timeout')), remaining()); })]);
    } finally { clearTimeout(timer); }
  };
  const result = await bounded(page.evaluate(inspectFaces, { required, limits: FONT_LIMITS, loadMs: Math.min(FONT_LIMITS.loadMs, Math.max(1, remaining() - 500)) }));
  if (result.failed.length) throw refusal('capture_fonts_failed', { failed_fonts: result.failed.slice(0, FONT_LIMITS.required), failed_fonts_total: result.failed.length });
  const session = await bounded(context.newCDPSession(page));
  const send = (method, params) => bounded(session.send(method, params));
  const rendered = new Map(), usage = required.map(() => ({ total: 0, custom: 0 }));
  try {
    await send('DOM.enable');
    await send('CSS.enable');
    await send('DOM.getDocument', { depth: 0 });
    const { result: sample } = await send('Runtime.evaluate', { expression: `(${sampleText.toString()})(${JSON.stringify(FONT_LIMITS)})`, objectGroup: 'prism-fonts' });
    if (!sample.objectId) throw refusal('capture_failed');
    const { result: metadata } = await send('Runtime.callFunctionOn', { objectId: sample.objectId, functionDeclaration: 'function() { return this.metadata; }', returnByValue: true });
    const { result: properties } = await send('Runtime.getProperties', { objectId: sample.objectId, ownProperties: true });
    const nodes = properties.find((p) => p.name === 'nodes')?.value?.objectId;
    if (!nodes || !Array.isArray(metadata.value) || metadata.value.length > FONT_LIMITS.elements) throw refusal('capture_failed');
    const { result: elements } = await send('Runtime.getProperties', { objectId: nodes, ownProperties: true });
    for (let i = 0; i < metadata.value.length; i++) {
      const objectId = elements.find((p) => p.name === String(i))?.value?.objectId;
      if (!objectId) throw refusal('capture_failed');
      const { nodeId } = await send('DOM.requestNode', { objectId });
      const { fonts } = await send('CSS.getPlatformFontsForNode', { nodeId });
      const meta = metadata.value[i];
      for (const font of fonts) {
        if (!Number.isSafeInteger(font.glyphCount) || font.glyphCount < 0 || typeof font.isCustomFont !== 'boolean' || typeof font.familyName !== 'string') throw refusal('capture_failed');
        const key = JSON.stringify([font.familyName, font.isCustomFont]);
        if (!rendered.has(key) && rendered.size < FONT_LIMITS.rendered) rendered.set(key, { family: cut(font.familyName), glyph_count: 0, custom: font.isCustomFont });
        const entry = rendered.get(key);
        if (entry) {
          entry.glyph_count += font.glyphCount;
          if (!Number.isSafeInteger(entry.glyph_count)) throw refusal('capture_failed');
        }
        required.forEach((face, index) => {
          if (familyKey(face.family) !== familyKey(meta.family)) return;
          usage[index].total += font.glyphCount;
          if (font.isCustomFont) usage[index].custom += font.glyphCount;
        });
      }
    }
  } finally {
    // Detaching also releases every remote object; page/context closure remains the outer cleanup boundary.
    await bounded(session.detach());
  }
  const failed = required.filter((_, i) => usage[i].total > 0 && usage[i].custom === 0).map((face) => ({ ...face, status: 'fallback_rendered' }));
  if (failed.length) throw refusal('capture_fonts_failed', { failed_fonts: failed, failed_fonts_total: failed.length });
  return { fonts: result.fonts, rendered_fonts: [...rendered.values()], fonts_measured: true };
}
