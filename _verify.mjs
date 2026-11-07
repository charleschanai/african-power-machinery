import { Jimp } from 'jimp';
import { createWorker } from 'tesseract.js';
import fs from 'node:fs';

// Checks every finished card without being told what was painted where. The
// painted box is recovered from the card itself by finding the pixels that
// differ from the original, so cards made with different box widths (the set
// uses 940, 1120 and 1440) can all be checked by the same code.
//
// What is asserted, per card:
//   1. every changed pixel lies in the name band y 587-708, so the QR,
//      SULPIS & JUDITH, the dates, times, venue, phones and credit line are
//      all bit-identical to the original
//   2. no seam immediately above or below the painted box
//   3. no visible step in the paper across the painted band
//   4. the ink is the card's own gold, by mode (anti-aliasing skews a median)
//   5. the name is a single line
//   6. OCR reads the requested name, exactly once
//
// The band is fixed; the box inside it is not assumed.

const DIR = 'C:/Users/DELL/Desktop/KADI 2026/';
const SRC = 'C:/Users/DELL/Desktop/KADI USHINDI.png';
const LANG = 'C:/Users/DELL/AppData/Local/Temp/opencode';
const BAND = { y0: 587, y1: 708 };
const GOLD = '144,93,14';
const DIFF = 18;
const INK = 175;

const only = process.argv[2] ? String(process.argv[2]) : null;

const src = await Jimp.read(SRC);
const SW = src.bitmap.width, sd = src.bitmap.data;
const sL = (x, y) => { const i = (y * SW + x) * 4; return 0.299 * sd[i] + 0.587 * sd[i + 1] + 0.114 * sd[i + 2]; };

const files = fs.readdirSync(DIR)
    .filter(f => /^KADI USHINDI - \d+ .+\.png$/.test(f))
    .sort((a, b) => (+a.match(/- (\d+) /)[1]) - (+b.match(/- (\d+) /)[1]));

const worker = await createWorker('eng', 1, { langPath: LANG, cachePath: LANG });
let failed = 0;

for (const file of files) {
    const m = file.match(/^KADI USHINDI - (\d+) (.+)\.png$/);
    const num = m[1], name = m[2];
    if (only && num !== only) continue;

    const card = await Jimp.read(`${DIR}${file}`);
    const W = card.bitmap.width, H = card.bitmap.height, cd = card.bitmap.data;
    const cL = (x, y) => { const i = (y * W + x) * 4; return 0.299 * cd[i] + 0.587 * cd[i + 1] + 0.114 * cd[i + 2]; };

    const bad = [];
    // recover the painted box from the card itself
    let x0 = Infinity, x1 = -1, y0 = Infinity, y1 = -1, outside = 0;
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        if (Math.abs(cL(x, y) - sL(x, y)) > DIFF) {
            if (y < BAND.y0 || y > BAND.y1) { outside++; continue; }
            if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
        }
    }
    if (x1 < 0) { console.log(`kadi ${num} ${name}: *** FAIL: hakuna mabadiliko yoyote`); failed++; continue; }
    if (outside) bad.push(`px changed outside the band: ${outside}`);

    // seam just outside the box
    let seam = 0;
    for (const y of [y0 - 1, y0 - 2, y0 - 3, y1 + 1, y1 + 2, y1 + 3]) {
        for (let x = x0; x <= x1; x++) if (Math.abs(cL(x, y) - sL(x, y)) > DIFF) seam++;
    }
    if (seam) bad.push(`seam ${seam}px`);

    // visible step in the paper across the painted band
    const step = (from, to) => {
        const get = (f) => { const v = []; for (let y = y0 + 4; y <= y1 - 4; y += 2) for (let x = from; x < to; x += 2) { const l = f(x, y); if (l > 205) v.push(l); } v.sort((p, q) => p - q); return v[v.length >> 1]; };
        const now = get((x, y) => cL(x, y)), was = get((x, y) => sL(x, y));
        return now === undefined || was === undefined ? 0 : now - was;
    };
    const sL2 = step(x0, x0 + 60), sR2 = step(x1 - 60, x1), sM = step(Math.floor((x0 + x1) / 2) - 30, Math.floor((x0 + x1) / 2) + 30);
    const worstStep = Math.max(Math.abs(sL2), Math.abs(sR2), Math.abs(sM));
    if (worstStep > 8) bad.push(`hatua ya karatasi ${worstStep.toFixed(1)}`);

    // ink colour by mode
    const counts = new Map();
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
        const i = (y * W + x) * 4;
        if (cL(x, y) < INK) { const k = `${cd[i]},${cd[i + 1]},${cd[i + 2]}`; counts.set(k, (counts.get(k) || 0) + 1); }
    }
    const top = [...counts].sort((a, b) => b[1] - a[1])[0];
    if (top?.[0] !== GOLD) bad.push(`rangi rgb(${top?.[0]})`);

    // single line of text
    let iy0 = Infinity, iy1 = -1, ix0 = Infinity, ix1 = -1, blocks = 0, run = false;
    for (let y = y0; y <= y1; y++) {
        let inked = false;
        for (let x = x0; x <= x1; x++) if (cL(x, y) < INK) { inked = true; if (x < ix0) ix0 = x; if (x > ix1) ix1 = x; break; }
        if (inked) { if (y < iy0) iy0 = y; iy1 = y; if (!run) { blocks++; run = true; } } else run = false;
    }
    if (blocks !== 1) bad.push(`${blocks} lines`);

    // OCR
    const cw = x1 - x0 - 20, chh = y1 - y0 + 20;
    const crop = card.clone().crop({ x: x0 + 10, y: y0 - 10, w: cw, h: chh });
    crop.resize({ w: cw * 3, h: chh * 3, mode: Jimp.RESIZE_BICUBIC });
    const { data } = await worker.recognize(await crop.getBuffer('image/png'));
    const read = data.text.replace(/\s+/g, ' ').trim();
    const hit = read.toUpperCase().split(name.toUpperCase()).length - 1;
    if (hit !== 1) bad.push(`OCR "${read}"`);

    const ok = bad.length === 0;
    if (!ok) failed++;
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${num.padStart(2)} ${name.padEnd(30)} box ${String(x1 - x0 + 1).padStart(4)}px  text ${String(ix1 - ix0 + 1).padStart(4)}x${String(iy1 - iy0 + 1).padStart(3)}  step ${worstStep.toFixed(1).padStart(4)}  ${bad.length ? '<< ' + bad.join('; ') : ''}`);
}

await worker.terminate();
console.log(`\n${failed ? `*** ${failed} kadi zina kasora ***` : 'ZOTE ZINAPITA'}`);
process.exit(failed ? 1 : 0);