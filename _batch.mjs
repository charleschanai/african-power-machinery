import { Jimp, loadFont } from 'jimp';
import { SANS_128_BLACK } from 'jimp/fonts';
import { spawnSync } from 'node:child_process';

const SRC = 'C:/Users/DELL/Desktop/KADI USHINDI.png';
const DST = 'C:/Users/DELL/Desktop/KADI 2026/';

// Box widths, all centred on x 1070, which is the centre of the text column.
// The widest one still stays inside the clean paper in the name band.
const BOXES = [
    { w: 1120, x0: 510, x1: 1630 },
    { w: 1440, x0: 350, x1: 1790 },
    { w: 1650, x0: 245, x1: 1895 },
];

const NAMES = [
    'Hellen Makupa',
    'Mr & Mrs John mtui',
    'Doreen Mosha',
    'Ester shirima',
    'Aunt Hidaya',
    'Restuta Saambili',
    'Alice Moses',
    'Anord Shirima',
    'Victer Mleo',
];

const font = await loadFont(SANS_128_BLACK);
function measure(text) {
    const im = new Jimp({ width: 6000, height: 500, color: 0x00000000 });
    im.print({ font, x: 40, y: 40, text });
    const W = im.bitmap.width, d = im.bitmap.data;
    let l = W, r = -1, t = 500, b = -1;
    for (let y = 0; y < 500; y++) for (let x = 0; x < W; x++) {
        const i = (y * W + x) * 4;
        if (d[i + 3] > 40) { if (x < l) l = x; if (x > r) r = x; if (y < t) t = y; if (y > b) b = y; }
    }
    return r < 0 ? null : { w: r - l + 1, h: b - t + 1 };
}

console.log('upana kwenye SANS_128, na ukingo unaotakiwa:\n');
let num = 32;
const skipped = [];
for (const name of NAMES) {
    const m = measure(name);
    const box = BOXES.find(b => b.w >= m.w);
    if (!box) { skipped.push(name); console.log(`   ${name.padEnd(22)} ${String(m.w).padStart(5)}  *** HAIWEZI KUFITA, inahitaji kujadiliwa ***`); continue; }
    const cond = box.w / m.w;
    const note = cond >= 1 ? 'asilia' : cond >= 0.84 ? `kukonywa ${(cond * 100).toFixed(0)}%` : `*** SHUKA ${(cond * 100).toFixed(0)}% ***`;
    console.log(`   ${name.padEnd(22)} ${String(m.w).padStart(5)} x ${String(m.h).padStart(3)}  ukingo ${box.w}px (x ${box.x0}-${box.x1})  ${note}`);

    const out = `${DST}KADI USHINDI - ${String(num).padStart(2, '0')} ${name}.png`;
    const r = spawnSync(process.execPath, [
        'generate_composite.mjs', SRC, 'x', out,
        `--patch=${box.x0},587,${box.x1},708@${name}`,
        '--ink=144,93,14', '--patch-only',
    ], { encoding: 'utf8' });
    let report = {};
    try { report = JSON.parse(r.stdout.trim().split('\n').filter(Boolean).pop()); } catch { }
    const t = report?.text?.[0];
    console.log(`      -> ${t ? `${t.glyphs}  upana ${t.rect[1]}px` : 'KOSA'}\n`);
    num++;
}

if (skipped.length) {
    console.log(`\n*** majina yaliyokosa kufita, yamechukiliwa: ${skipped.join(', ')} ***`);
    process.exit(1);
}