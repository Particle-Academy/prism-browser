/**
 * An original test-only TrueType, generated in memory: .notdef plus one rectangle
 * for U+0041. No third-party font, download, binary fixture or build dependency.
 * Its deliberate missing glyphs make whole/partial fallback observable via CDP.
 * Source and generated fixture follow this repository's MIT licence.
 */
export function fixtureFont() {
  const u16 = (n) => { const b = Buffer.alloc(2); b.writeUInt16BE(n & 0xffff); return b; };
  const u32 = (n) => { const b = Buffer.alloc(4); b.writeUInt32BE(n >>> 0); return b; };
  const words = (...n) => Buffer.concat(n.map(u16));
  const sum = (bytes) => {
    const padded = Buffer.alloc(Math.ceil(bytes.length / 4) * 4); bytes.copy(padded);
    let value = 0;
    for (let i = 0; i < padded.length; i += 4) value = (value + padded.readUInt32BE(i)) >>> 0;
    return value;
  };
  const head = Buffer.alloc(54);
  head.writeUInt32BE(0x10000, 0); head.writeUInt32BE(0x10000, 4); head.writeUInt32BE(0x5f0f3cf5, 12);
  head.writeUInt16BE(3, 16); head.writeUInt16BE(1000, 18);
  head.writeInt16BE(500, 40); head.writeInt16BE(700, 42); head.writeUInt16BE(8, 46); head.writeInt16BE(2, 48);
  const hhea = Buffer.alloc(36);
  hhea.writeUInt32BE(0x10000); hhea.writeInt16BE(800, 4); hhea.writeInt16BE(-200, 6);
  hhea.writeUInt16BE(600, 10); hhea.writeInt16BE(500, 16); hhea.writeInt16BE(1, 18); hhea.writeUInt16BE(2, 34);
  const maxp = Buffer.alloc(32);
  maxp.writeUInt32BE(0x10000); maxp.writeUInt16BE(2, 4); maxp.writeUInt16BE(4, 6);
  maxp.writeUInt16BE(1, 8); maxp.writeUInt16BE(1, 14);
  const os2 = Buffer.alloc(78);
  os2.writeInt16BE(600, 2); os2.writeUInt16BE(400, 4); os2.writeUInt16BE(5, 6);
  os2.writeUInt32BE(1, 42); os2.write('PRSM', 58); os2.writeUInt16BE(0x40, 62);
  os2.writeUInt16BE(65, 64); os2.writeUInt16BE(65, 66); os2.writeInt16BE(800, 68); os2.writeInt16BE(-200, 70);
  os2.writeUInt16BE(800, 74); os2.writeUInt16BE(200, 76);
  const names = [[1, 'Prism Capture Fixture'], [2, 'Regular'], [3, 'PrismCaptureFixture-1'],
    [4, 'Prism Capture Fixture'], [5, 'Version 1.000'], [6, 'PrismCaptureFixture']];
  const strings = names.map(([, value]) => Buffer.from(value, 'utf16le').swap16());
  let offset = 0;
  const records = names.map(([id], index) => {
    const record = words(3, 1, 0x409, id, strings[index].length, offset); offset += strings[index].length; return record;
  });
  const tables = new Map([
    ['OS/2', os2],
    ['cmap', Buffer.concat([words(0, 1, 3, 1), u32(12), words(4, 32, 0, 4, 4, 1, 0, 65, 0xffff, 0, 65, 0xffff, 1 - 65, 1, 0, 0)])],
    ['glyf', Buffer.concat([words(1, 0, 0, 500, 700, 3, 0), Buffer.from([1, 1, 1, 1]), words(0, 500, 0, -500, 0, 0, 700, 0)])],
    ['head', head], ['hhea', hhea], ['hmtx', words(600, 0, 600, 0)], ['loca', words(0, 0, 17)], ['maxp', maxp],
    ['name', Buffer.concat([words(0, names.length, 6 + names.length * 12), ...records, ...strings])],
    ['post', Buffer.concat([u32(0x30000), Buffer.alloc(28)])],
  ]);
  const count = tables.size, power = 2 ** Math.floor(Math.log2(count));
  const header = Buffer.concat([u32(0x10000), words(count, power * 16, Math.log2(power), count * 16 - power * 16)]);
  const directory = [], data = [];
  offset = 12 + count * 16;
  let headOffset;
  for (const [tag, table] of tables) {
    directory.push(Buffer.concat([Buffer.from(tag), u32(sum(table)), u32(offset), u32(table.length)]));
    if (tag === 'head') headOffset = offset;
    const padded = Buffer.alloc(Math.ceil(table.length / 4) * 4); table.copy(padded); data.push(padded); offset += padded.length;
  }
  const font = Buffer.concat([header, ...directory, ...data]);
  font.writeUInt32BE((0xb1b0afba - sum(font)) >>> 0, headOffset + 8);
  return font;
}
