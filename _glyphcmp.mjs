import { Jimp, loadFont } from 'jimp';
import { SANS_128_BLACK } from 'jimp/fonts';
import fs from 'node:fs';

// OCR is not evidence: it read card 6 as "Jamiani", card 9 as "Vir" and card 11
// as "Fudith", all correctly printed. So the card's own pixels are compared
// against an independent render of the requested text, on ink coverage rather
// than colour:
//
//   coverage = (paper - pixel) / (paper - ink)
//
// Each side is normalised by its own two endpoints, so gold-on-cream on the card
// and black-on-white here compare directly. IoU of the two coverage masks is the
// decisive number: 1.0000 would be the same bitmap.
//
// ONE TRAP, HIT TWICE NOW: the card's decorative frame runs to x 0 and x 2132
// and is nearly black, so scanning the full width counts two phantom glyphs.
// The comparison is confined to the painted box.

const DIR = 'C:/Users/DELL/Desktop/KADI 2026/';
const SRC = 'C:/Users/DELL/Desktop/KADI USHINDI.png';
const BAND = { y0: 587, y1: 708 };

const only = process.argv[2] ? process.argv.slice(2) : null;

const src = await Jimp.read(SRC);
const SW = src.bitmap.width, sd = src.bitmap.data;
const sL = (x, y) => { const i = (y * SW + x) * 4; return 0.299 * sd[i] + 0.587 * sd[i + 1] + 0.114 * sd[i + 2]; };

function blank(w, h, r, g, b) {
    const im = new Jimp({ width: w, height: h, color: 0x00000000 });
    const d = im.bitmap.data;
    for (let i = 0; i < d.length; i += 4) { d[i] = r; d[i + 1] = g; d[i + 2] = b; d[i + 3] = 255; }
    return im;
}

const font = await loadFont(SANS_128_BLACK);
function reference(name) {
    const ref = blank(3000, 400, 255, 255, 255);
    ref.print({ font, x: 60, y: 60, text: name });
    const W = ref.bitmap.width, d = ref.bitmap.data;
    const L = (x, y) => { const i = (y * W + x) * 4; return 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; };
    if (L(5, 5) < 250) throw new Error('reference background is not white');
    // not at a fixed coordinate: a name starting with "A" has nothing at x 70
    let darkest = 999;
    for (let y = 0; y < 400; y++) for (let x = 0; x < W; x++) { const l = L(x, y); if (l < darkest) darkest = l; }
    if (darkest > 128) throw new Error(`reference text did not draw (darkest ${darkest.toFixed(0)})`);
    return { W, cov: (x, y) => (255 - L(x, y)) / 255 };
}

function segments(from, to, isInked) {
    const out = []; let cur = null;
    for (let x = from; x <= to; x++) {
        if (isInked(x)) { if (!cur) cur = { x0: x, x1: x }; else cur.x1 = x; }
        else if (cur) { if (cur.x1 - cur.x0 >= 6) out.push(cur); cur = null; }
    }
    if (cur && cur.x1 - cur.x0 >= 6) out.push(cur);
    return out;
}

const files = fs.readdirSync(DIR)
    .filter(f => /^KADI USHINDI - \d+ .+\.png$/.test(f))
    .sort((a, b) => (+a.match(/- (\d+) /)[1]) - (+b.match(/- (\d+) /)[1]));

let failed = 0;
for (const file of files) {
    const m = file.match(/^KADI USHINDI - (\d+) (.+)\.png$/);
    const num = m[1], name = m[2];
    if (only && !only.includes(num)) continue;

    const card = await Jimp.read(`${DIR}${file}`);
    const W = card.bitmap.width, cd = card.bitmap.data;
    const cL = (x, y) => { const i = (y * W + x) * 4; return 0.299 * cd[i] + 0.587 * cd[i + 1] + 0.114 * cd[i + 2]; };

    let x0 = Infinity, x1 = -1, y0 = Infinity, y1 = -1;
    for (let y = BAND.y0; y <= BAND.y1; y++) for (let x = 0; x < W; x++)
        if (Math.abs(cL(x, y) - sL(x, y)) > 18) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }

    let paper = 0, pn = 0;
    for (let y = BAND.y0; y <= BAND.y1; y += 2) for (let x = x0; x <= x1; x += 2) { const l = cL(x, y); if (l > 205) { paper += l; pn++; } }
    const P = paper / pn;
    const counts = new Map();
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
        const i = (y * W + x) * 4;
        if (cL(x, y) < 175) { const k = `${cd[i]},${cd[i + 1]},${cd[i + 2]}`; counts.set(k, (counts.get(k) || 0) + 1); }
    }
    const [ir, ig, ib] = [...counts].sort((a, b) => b[1] - a[1])[0][0].split(',').map(Number);
    const I = 0.299 * ir + 0.587 * ig + 0.114 * ib;

    // The changed region is the REPINTED PAPER, which is wider than the text.
    // Aligning the reference to that is what collapsed IoU on card 01, and is the
    // same mistake that produced the unexplained 0.3119 on card 6. Align on the
    // ink itself, measured from the coverage mask.
    const inked = (x, y) => (P - cL(x, y)) / (P - I) >= 0.5;
    let ix0 = Infinity, ix1 = -1, iy0 = Infinity, iy1 = -1;
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) if (inked(x, y)) {
        if (x < ix0) ix0 = x; if (x > ix1) ix1 = x; if (y < iy0) iy0 = y; if (y > iy1) iy1 = y;
    }
    if (ix1 < 0) { console.log(`FAIL ${num} ${name}: hakuna maandishi`); failed++; continue; }

    const cardSegs = segments(ix0, ix1, (x) => { for (let y = iy0; y <= iy1; y++) if (inked(x, y)) return true; return false; });

    const ref = reference(name);
    let rx0 = Infinity, rx1 = -1, ry0 = Infinity, ry1 = -1;
    for (let y = 0; y < 400; y++) for (let x = 0; x < ref.W; x++) if (ref.cov(x, y) >= 0.5) { if (x < rx0) rx0 = x; if (x > rx1) rx1 = x; if (y < ry0) ry0 = y; if (y > ry1) ry1 = y; }
    const refSegs = segments(rx0, rx1, (x) => { for (let y = ry0; y <= ry1; y++) if (ref.cov(x, y) >= 0.5) return true; return false; });

    const bad = [];
    if (cardSegs.length !== refSegs.length) bad.push(`herufi ${cardSegs.length} vs ${refSegs.length}`);
    let worstW = 0;
    const n = Math.min(cardSegs.length, refSegs.length);
    for (let i = 0; i < n; i++) {
        const a = cardSegs[i].x1 - cardSegs[i].x0 + 1, b = refSegs[i].x1 - refSegs[i].x0 + 1;
        const d = Math.abs(a - b) / b; if (d > worstW) worstW = d;
    }
    if (worstW > 0.06) bad.push(`upana ${(worstW * 100).toFixed(1)}%`);

    // IoU with both ink boxes aligned at their origins
    const cw = ix1 - ix0 + 1, ch = iy1 - iy0 + 1, rw = rx1 - rx0 + 1, rh = ry1 - ry0 + 1;
    let inter = 0, union = 0;
    for (let y = 0; y < Math.max(ch, rh); y++) for (let x = 0; x < Math.max(cw, rw); x++) {
        let a = 0, b = 0;
        if (x < cw && y < ch) for (let yy = iy0; yy <= iy1; yy++) if (inked(ix0 + x, yy)) { a = 1; break; }
        if (x < rw && y < rh) for (let yy = ry0; yy <= ry1; yy++) if (ref.cov(rx0 + x, yy) >= 0.5) { b = 1; break; }
        if (a || b) { union++; if (a && b) inter++; }
    }
    const iou = union ? inter / union : 0;
    if (iou < 0.85) bad.push(`IoU ${iou.toFixed(3)}`);

    if (bad.length) failed++;
    console.log(`${bad.length ? 'FAIL' : 'OK  '} ${num.padStart(2)} ${name.padEnd(28)} herufi ${String(cardSegs.length).padStart(2)}/${String(refSegs.length).padStart(2)}  upana ${(worstW * 100).toFixed(1).padStart(5)}%  IoU ${iou.toFixed(4)}  ${bad.length ? '<< ' + bad.join('; ') : ''}`);
}

console.log(`\n${failed ? `*** ${failed} zina tatizo ***` : 'ZOTE ZINAFANA NA JINA LILILOTAKIWA'}`);
process.exit(failed ? 1 : 0);