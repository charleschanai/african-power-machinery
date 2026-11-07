import QRCode from 'qrcode';
import zx from '@zxing/library';
import { BlendMode, Jimp, loadFont } from 'jimp';
import { SANS_10_BLACK, SANS_12_BLACK, SANS_14_BLACK, SANS_16_BLACK, SANS_32_BLACK, SANS_64_BLACK, SANS_128_BLACK } from 'jimp/fonts';
import { argv } from 'process';

const [imageUrl, qrText, outputPath, ...extraArgs] = argv.slice(2);

if (!imageUrl || !qrText || !outputPath) {
    console.error(
        'Usage: node generate_composite.mjs <imageUrl> <qrText> <outputPath> [creditText] [--ink=R,G,B] [--patch=x0,y0,x1,y1=newText] [--patch-only]'
    );
    process.exit(1);
}

/**
 * Optional trailing arguments:
 *   creditText              a footer line, auto-placed in the clearest strip
 *                           along the bottom of the card
 *   --patch=x0,y0,x1,y1=T   replace the text inside that rectangle (coordinates
 *                           are in source-image pixels and scale automatically)
 *   --ink=R,G,B              print patched text in this colour instead of the one
 *                           sampled from the card. Needed whenever the patch box
 *                           is empty, because an empty box has no ink to sample
 *                           and the fallback is near-black - which on a card
 *                           printed in gold looks like a printing fault.
 *   --patch-only            edit the text on a finished card and leave the code
 *                           it already carries alone, rather than encoding a new
 *                           one. For a card that has already been through this
 *                           tool, re-encoding would resize the image and print a
 *                           second code; rewriting just the one line keeps every
 *                           other pixel of the finished card.
 *
 * Both are additive and optional, so existing three-argument callers are
 * unaffected.
 */
const patchOnly = extraArgs.includes('--patch-only');

/* Patched-ink colour. Matched by mode, not median: see INK_FALLBACK for why. */
let inkOverride = null;
const inkArg = extraArgs.find((a) => a.startsWith('--ink='));
if (inkArg) {
    const channels = inkArg.slice('--ink='.length).split(',').map((v) => v.trim());
    // A regex rather than Number: this only ever wants plain 0-255 channels, and
    // /^\d{1,3}$/ cannot be fooled the way a bare isFinite check can - a missing
    // argument to isFinite reads as false and rejects every value.
    const usable = channels.length === 3 && channels.every((v) => /^\d{1,3}$/.test(v) && Number(v) <= 255);
    if (!usable) {
        // Same reasoning as --patch: this arrives over exec(), so no stack trace.
        // The parsed channels are in the message because "too many commas" and
        // "a channel out of range" look identical from the outside otherwise.
        console.error(`Bad --ink argument: ${JSON.stringify(inkArg)}`);
        console.error(`Parsed ${JSON.stringify(channels)} - expected 3 channels, each a whole number 0-255`);
        process.exit(1);
    }
    inkOverride = channels.map(Number);
}

const FLAGS = new Set(['--patch-only']);
const creditText = extraArgs.find((a) => !a.startsWith('--patch=') && !a.startsWith('--ink=') && !FLAGS.has(a)) || null;
const patches = [];
for (const arg of extraArgs.filter((a) => a.startsWith('--patch='))) {
    const at = arg.indexOf('@');
    const [x0, y0, x1, y1] = arg.slice('--patch='.length, at < 0 ? undefined : at).split(',').map(Number);
    const text = at < 0 ? '' : arg.slice(at + 1);
    if (![x0, y0, x1, y1].every(Number.isFinite) || x1 <= x0 || y1 <= y0 || !text) {
        // Callers reach this over exec(), so a clean line beats a stack trace.
        console.error(`Bad --patch argument: ${arg}`);
        console.error('Expected --patch=x0,y0,x1,y1=newText');
        process.exit(1);
    }
    patches.push({ x0, y0, x1, y1, text });
}

/* ------------------------------------------------------------------ *
 * Image helpers
 * ------------------------------------------------------------------ */

const MONO = { dark: '#000000ff', light: '#ffffffff' };

/** Per-pixel Rec.601 luma plane for the current bitmap. */
function toLuma(img) {
    const { width, height, data } = img.bitmap;
    const luma = new Float64Array(width * height);
    for (let p = 0, i = 0; p < width * height; p++, i += 4) {
        luma[p] = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    }
    return luma;
}

/** Otsu method: best split between a dark backdrop and a light card. */
function otsu(luma) {
    const hist = new Array(256).fill(0);
    for (const v of luma) hist[Math.max(0, Math.min(255, Math.round(v)))]++;

    const total = luma.length;
    let sum = 0;
    for (let t = 0; t < 256; t++) sum += t * hist[t];

    let sumB = 0, wB = 0, best = 0, threshold = 128;
    for (let t = 0; t < 256; t++) {
        wB += hist[t];
        if (!wB) continue;
        const wF = total - wB;
        if (!wF) break;
        sumB += t * hist[t];
        const between = wB * wF * (sumB / wB - (sum - sumB) / wF) ** 2;
        if (between > best) {
            best = between;
            threshold = t;
        }
    }
    return threshold;
}

/**
 * Locate the card inside the photo.
 *
 * Templates are usually the card on its own, photos have the card floating on
 * a darker backdrop. A row/column only counts as "card" when its longest
 * uninterrupted bright run spans most of that axis, so stray highlights in the
 * backdrop cannot drag the bounds outwards.
 */
function detectCardFace(img) {
    const { width: W, height: H } = img.bitmap;
    const luma = toLuma(img);
    const bright = otsu(luma) + 10;

    const longestRun = (get, length) => {
        let best = 0, start = -1;
        for (let i = 0; i <= length; i++) {
            if (i < length && get(i) > bright) {
                if (start < 0) start = i;
            } else if (start >= 0) {
                if (i - start > best) best = i - start;
                start = -1;
            }
        }
        return best;
    };

    let y0 = H, y1 = -1, x0 = W, x1 = -1;
    for (let y = 0; y < H; y++) {
        if (longestRun((x) => luma[y * W + x], W) >= W * 0.55) {
            if (y < y0) y0 = y;
            if (y > y1) y1 = y;
        }
    }
    for (let x = 0; x < W; x++) {
        if (longestRun((y) => luma[y * W + x], H) >= H * 0.55) {
            if (x < x0) x0 = x;
            if (x > x1) x1 = x;
        }
    }

    // Nothing convincing found (or the "card" is just the whole frame) →
    // treat the entire image as the card.
    if (y1 < 0 || x1 < 0 || x1 - x0 < W * 0.35 || y1 - y0 < H * 0.35) {
        return { x0: 0, y0: 0, x1: W - 1, y1: H - 1, detected: false };
    }
    return { x0, y0, x1, y1, detected: true };
}

/* ------------------------------------------------------------------ *
 * Empty space inside the card
 * ------------------------------------------------------------------ */

const CLEAN_LUMA = 200; // a QR needs an unbroken light backdrop to scan

/**
 * Largest completely light square inside the card, found exactly with the
 * standard maximal-square recurrence rather than by trying sizes in steps.
 * A stepped search silently skips the sizes in between, which on this card meant
 * missing a 368 px pocket because 370 and 366 were both occupied and the next
 * candidate down was far too small - that pushed the placement into an upscale
 * large enough to break detection. Ties go to the square nearest the bottom-right
 * corner, so the code lands where the eye already looks.
 */
function largestEmptySquare(img, face) {
    const { width: W, height: H } = img.bitmap;
    const luma = toLuma(img);
    const dp = new Int32Array((W + 1) * (H + 1));
    let best = null;

    for (let row = 1; row <= H; row++) {
        for (let col = 1; col <= W; col++) {
            const x = col - 1;
            const y = row - 1;
            if (luma[y * W + x] <= CLEAN_LUMA) continue; // dark pixel, no square ends here
            const v =
                1 +
                Math.min(
                    dp[(row - 1) * (W + 1) + col],
                    dp[row * (W + 1) + col - 1],
                    dp[(row - 1) * (W + 1) + col - 1]
                );
            dp[row * (W + 1) + col] = v;

            const x0 = col - v;
            const y0 = row - v;
            if (x0 < face.x0 || y0 < face.y0 || x > face.x1 || y > face.y1) continue;

            if (!best || v > best.size || (v === best.size && x + y > best.x + best.y + best.size)) {
                best = { x: x0, y: y0, size: v };
            }
        }
    }
    return best;
}

/* ------------------------------------------------------------------ *
 * QR sizing
 * ------------------------------------------------------------------ */

/**
 * Six pixels per module is the floor for a code that a phone can still find
 * when the card is photographed from across a room. Measured against this
 * card: 4 px/module reads only when the image is viewed at full size, 5 px
 * only sometimes, and 6 px survives being shrunk to half size.
 */
const MIN_PIXELS_PER_MODULE = 6;

/** A QR wider than this starts eating the design instead of sitting on it. */
const MAX_QR_FRACTION = 0.45;

/**
 * Quiet-zone / redundancy trade-offs, best first. A one-module quiet zone is
 * below spec and unreliable in the wild, so the smallest allowed is two.
 */
const CANDIDATES = [
    { margin: 2, ecc: 'M' },
    { margin: 2, ecc: 'L' },
    { margin: 4, ecc: 'M' },
    { margin: 4, ecc: 'L' },
];

const ECC_RANK = { M: 0, L: 1 };
/**
 * Error correction first, then the narrowest acceptable quiet zone: measured
 * against this card, a 2-module quiet zone reads exactly as reliably as a
 * 4-module one, but the narrower band leaves 24 more pixels of design visible.
 */
const rank = (p) => (ECC_RANK[p.ecc] ?? 9) * 10 + p.margin;

/** Symbol size in modules, quiet zone included. */
async function moduleCount(qrText, { margin, ecc }) {
    // scale:1 renders exactly one pixel per module, so the width is the count.
    const probe = await Jimp.read(
        await QRCode.toBuffer(qrText, { errorCorrectionLevel: ecc, margin, scale: 1, color: MONO })
    );
    return probe.bitmap.width;
}

/* ------------------------------------------------------------------ *
 * Encoding
 * ------------------------------------------------------------------ */

/**
 * The qrcode package picks a mask pattern by scoring, and pattern 0 comes out of
 * it unreadable: for the 146-byte payload on this card every other pattern
 * (1,2,3,4,5,7) decodes and pattern 0 never does, at any quiet-zone width. The
 * symbol it builds is correct module for module - it simply does not scan, which
 * is the one failure a card cannot afford, since a wedding guest finds out at
 * the door.
 *
 * So the mask is never taken on trust. Each candidate is decoded before it is
 * accepted, and the first that reads back is the one that goes on the card.
 */
const MASK_PATTERNS = [null, 1, 2, 3, 4, 5, 6, 7];

/**
 * Read a rendered QR back the way a phone would, and report whether it returned
 * exactly the text that went in.
 */
async function decodes(buffer, expected) {
    const { MultiFormatReader, RGBLuminanceSource, BinaryBitmap, HybridBinarizer, DecodeHintType, BarcodeFormat } = zx;
    const { width, height, data } = (await Jimp.read(buffer)).bitmap;
    // RGBLuminanceSource wants one luminance byte per pixel, not RGBA.
    const luma = new Uint8ClampedArray(width * height);
    for (let p = 0, i = 0; p < luma.length; p++, i += 4) {
        luma[p] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;
    }
    try {
        const hints = new Map();
        hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE]);
        const text = new MultiFormatReader()
            .decode(new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(luma, width, height))), hints)
            .getText();
        return text === expected;
    } catch {
        return false;
    }
}

/**
 * Render the QR at a pixel size, and refuse to hand back one that does not scan.
 * Returns the buffer plus the mask pattern that actually made it readable.
 */
async function renderQr(qrText, config, size) {
    const base = {
        errorCorrectionLevel: config.ecc,
        margin: config.margin,
        scale: size / config.modules,
        color: MONO,
    };
    for (const maskPattern of MASK_PATTERNS) {
        const buffer = await QRCode.toBuffer(qrText, maskPattern === null ? base : { ...base, maskPattern });
        if (await decodes(buffer, qrText)) return { buffer, maskPattern };
    }
    throw new Error(
        `no mask pattern produced a readable QR for this text at ${config.modules} modules; ` +
            'the payload is likely too long for the chosen error correction level'
    );
}

/* ------------------------------------------------------------------ *
 * Printed text
 * ------------------------------------------------------------------ */

/**
 * Open Sans faces, largest first, so any line can be fitted to its box.
 *
 * These are pre-baked bitmap faces, not outlines: the only way up in size is the
 * next face up, which is why the 128 exists. A 128px line is 584px of ink for
 * eight characters, roughly the height of the card's own date line, so it reads
 * as part of the design rather than as an annotation.
 */
const FONT_FILES = [SANS_128_BLACK, SANS_64_BLACK, SANS_32_BLACK, SANS_16_BLACK, SANS_14_BLACK, SANS_12_BLACK, SANS_10_BLACK];

/**
 * A service credit is a footnote, so it is never allowed to reach the display
 * sizes. Named by face rather than by index: adding a larger font at the front
 * of FONT_FILES shifts every index, and an index that silently starts meaning a
 * bigger face is how a footnote ends up shouting.
 */
const CREDIT_MAX_FONT = FONT_FILES.indexOf(SANS_16_BLACK);

/**
 * How far a line may be squeezed horizontally before it is better off at the
 * next size down. Past this the letters look drawn rather than printed.
 *
 * 0.84 is set from the card's own opening line, which needs 0.848 to sit in its
 * box at the display size. Halving the height instead of narrowing the line by
 * a sixth is the worse trade every time: a heading that is half the weight of
 * everything under it reads as a footnote, while a sixth narrower still reads
 * as the same line of type.
 */
const MIN_CONDENSE = 0.84;

const fontCache = new Map();
function fontFor(index) {
    if (!fontCache.has(index)) fontCache.set(index, loadFont(FONT_FILES[index]));
    return fontCache.get(index);
}

/**
 * Tight ink box of `text`, measured by actually drawing it. The metrics Jimp
 * reports for bitmap fonts are the page size, not the glyphs, so they cannot be
 * trusted for fitting a line into a gap.
 */
async function inkBox(font, text) {
    // Wide enough for the 128px face on the longest line this tool is given.
    // A 52-character name runs to about 3400px there, and a canvas that clips it
    // does not fail - it reports the clipped width, so an over-long line looks
    // like it fits and gets printed running off the edge of the card.
    const W = 6000,
        H = 600;
    const canvas = new Jimp({ width: W, height: H, color: 0x00000000 });
    canvas.print({ font, x: 100, y: 100, text });
    const { data } = canvas.bitmap;
    let x0 = W,
        y0 = H,
        x1 = -1,
        y1 = -1;
    for (let y = 0; y < H; y++)
        for (let x = 0; x < W; x++) {
            if (data[(y * W + x) * 4 + 3] > 24) {
                if (x < x0) x0 = x;
                if (x > x1) x1 = x;
                if (y < y0) y0 = y;
                if (y > y1) y1 = y;
            }
        }
    return x1 < 0 ? null : { w: x1 - x0 + 1, h: y1 - y0 + 1, dx: x0 - 100, dy: y0 - 100 };
}

/**
 * Largest face whose ink fits inside w x h, with the glyph box alongside.
 * `maxIndex` walks FONT_FILES from the front only, so a caller that wants quiet
 * text can hold it back from the display sizes without a second size table.
 */
async function fitFont(text, w, h, maxIndex = 0) {
    for (let i = maxIndex; i < FONT_FILES.length; i++) {
        const font = await fontFor(i);
        const box = await inkBox(font, text);
        if (box && box.w <= w && box.h <= h) return { font, ...box };
    }
    return null;
}

/**
 * A box for a line that could not be measured, so nothing downstream has to
 * null-check one. Infinity rather than a zero box on purpose: a line we cannot
 * measure has to lose the face, not win it.
 */
const UNMEASURED = { w: Infinity, h: Infinity, dx: 0, dy: 0 };
const boxOr = async (font, text) => (await inkBox(font, text)) ?? UNMEASURED;

/** Greedy word wrap at one face. Null when a single word cannot fit the width. */
async function wrapAt(font, text, maxW) {
    const lines = [];
    let current = '';
    let currentBox = null;
    for (const word of text.split(/\s+/).filter(Boolean)) {
        const trial = current ? `${current} ${word}` : word;
        const trialBox = await boxOr(font, trial);
        if (trialBox.w > maxW && current) {
            // A line that starts right after a break has never been measured on
            // its own, and the very next word can overflow it immediately - two
            // breaks in a row. Pushing it unmeasured put a null box on the
            // stack, which no face reached until 128px made the boxes narrow
            // enough to wrap twice running.
            lines.push({ text: current, box: currentBox ?? (await boxOr(font, current)) });
            current = word;
            currentBox = null;
        } else {
            current = trial;
            currentBox = trialBox;
        }
    }
    if (!current) return null;
    // A line left over from a break has never been measured on its own.
    if (currentBox === null) currentBox = await boxOr(font, current);
    if (currentBox.w > maxW) return null;
    lines.push({ text: current, box: currentBox });

    // Greedy wrapping strands single short words on the last line - "Keni"
    // above "Rombo" reads as a misprint rather than a title. If the tail is
    // that lopsided, hand the previous line's last word down to sit with it.
    if (lines.length >= 2) {
        const tail = lines[lines.length - 1];
        const prev = lines[lines.length - 2];
        if (tail.box.w < prev.box.w * 0.35 && prev.text.includes(' ')) {
            const words = prev.text.split(' ');
            const moved = words.pop();
            const candidate = `${moved} ${tail.text}`;
            const box = await boxOr(font, candidate);
            if (box.w <= maxW) {
                lines[lines.length - 2] = { text: words.join(' '), box: await boxOr(font, words.join(' ')) };
                lines[lines.length - 1] = { text: candidate, box };
            }
        }
    }
    return lines;
}

/**
 * Largest face whose text fits inside w x h, wrapping onto as many lines as it
 * needs. Wrapping is what keeps a long line legible: a 52-character name forced
 * onto one row has to drop to a 31px face, while the same name over two rows
 * holds a 62px face in the same space. Line pitch is set from the tallest
 * line's ink so a block of three lines keeps the same rhythm as one line.
 *
 * A line that misses the box by a few percent is squeezed to fit rather than
 * dropped to the next size. On the card's opening line the difference is the
 * whole look of the card: at 64px it holds 59px of ink, matching the line it
 * replaces, while the next size down gives 30px and turns the heading into
 * half the weight of everything around it. Below MIN_CONDENSE the squeeze
 * starts to read as distortion, so the fit gives up and takes a smaller face.
 */
async function fitBlock(text, w, h, maxIndex = 0) {
    // Wrapping happens at the width a condensed line could still reach, so a
    // line that only needs squeezing is not split across two rows first.
    for (let i = maxIndex; i < FONT_FILES.length; i++) {
        const font = await fontFor(i);
        const lines = await wrapAt(font, text, w / MIN_CONDENSE);
        if (!lines) continue;
        const inkH = Math.max(...lines.map((l) => l.box.h));
        const pitch = Math.round(inkH * 1.35);
        const naturalW = Math.max(...lines.map((l) => l.box.w));
        const blockH = (lines.length - 1) * pitch + inkH;
        if (blockH > h) continue;
        if (naturalW <= w) return { font, lines, pitch, w: naturalW, h: blockH, condense: 1 };
        if (naturalW <= w / MIN_CONDENSE) return { font, lines, pitch, w, h: blockH, condense: w / naturalW };
    }
    return null;
}

/** Draw a wrapped block centred on (cx, cy), each line on its own baseline. */
function drawBlock(img, fit, rgb, cx, cy) {
    const top = Math.round(cy - fit.h / 2);
    fit.lines.forEach((line, i) => {
        const lineY = top + i * fit.pitch + line.box.h / 2;
        if (fit.condense < 1) drawCondensed(img, fit.font, line.box, line.text, rgb, cx, lineY, fit.condense);
        else drawCentered(img, fit.font, line.box, line.text, rgb, cx, lineY);
    });
}

/** Draw one line narrowed to `factor` of its natural width. */
function drawCondensed(img, font, box, text, rgb, cx, cy, factor) {
    const layer = new Jimp({ width: box.w, height: box.h, color: 0x00000000 });
    layer.print({ font, x: -box.dx, y: -box.dy, text });
    tint(layer, rgb);
    const w = Math.max(1, Math.round(box.w * factor));
    layer.resize({ w, h: box.h });
    img.composite(layer, Math.round(cx - w / 2), Math.round(cy - box.h / 2), {
        mode: BlendMode.SRC_OVER,
        originX: 0,
        originY: 0,
    });
}

/** Repaint every non-transparent pixel one colour, keeping the edges. */
function tint(img, [r, g, b]) {
    const { data } = img.bitmap;
    for (let i = 0; i < data.length; i += 4) {
        if (data[i + 3] === 0) continue;
        data[i] = r;
        data[i + 1] = g;
        data[i + 2] = b;
    }
}

/**
 * Colour used when a patch box has no ink in it to copy.
 *
 * Near-black, not a guess at the card's palette. A box is empty precisely
 * because the name is going somewhere new, and these cards are printed in
 * several different colours (gold names, brown body text), so any colour
 * picked here is a guess that can be wrong on the next card. Failing to a
 * neutral makes the caller notice, and --ink makes the choice explicit.
 */
const INK_FALLBACK = [0x1a, 0x1a, 0x1a];

/** Median colour of the dark ink already in a box - the colour to match. */
function sampleInk(img, x, y, w, h) {
    const { width: W, data } = img.bitmap;
    const found = [];
    for (let row = y; row < y + h; row++) {
        for (let col = x; col < x + w; col++) {
            const i = (row * W + col) * 4;
            const l = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            if (l < 140) found.push([data[i], data[i + 1], data[i + 2]]);
        }
    }
    if (found.length < 8) return null;
    found.sort((a, b) => a[0] + a[1] + a[2] - (b[0] + b[1] + b[2]));
    return found[found.length >> 1];
}

/**
 * Erase a rectangle by repainting the surrounding background into it.
 *
 * Each row is filled from its own sample, because the card background is a
 * soft vertical gradient and one flat colour leaves a seam straight across it.
 *
 * The samples come from directly above and below the row, in the row's own
 * columns, and only from outside the box. Reaching sideways for them is what
 * put a 22-luma seam on the opening line: this card is lit unevenly, its outer
 * margins sitting near 216 while the middle sits near 239, so a sideways
 * sample repaints the middle in the margins' colour. Sampling straight up and
 * down keeps the horizontal position, so the patch matches what it covers.
 */
function paintBackground(img, x, y, w, h) {
    const { width: W, height: H, data } = img.bitmap;
    const paper = (col, row) => {
        if (col < 0 || col >= W || row < 0 || row >= H) return null;
        const i = (row * W + col) * 4;
        const r = data[i],
            g = data[i + 1],
            b = data[i + 2];
        return 0.299 * r + 0.587 * g + 0.114 * b > 205 ? [r, g, b] : null;
    };

    for (let row = y; row < y + h; row++) {
        const samples = [];
        // Reach past the far edge of the box, not just 40px. A box taller than
        // 80px has rows in the middle where 40px in either direction is still
        // inside the box, and those rows used to fall through to the sideways
        // fallback - which on this card is the difference between the middle's
        // paper at 245 and the margins' at 238, a 7-luma band straight across
        // the new text. Reaching to the far edge keeps every row sampling
        // vertically. The loop still stops at 60 samples, so on a short box
        // this changes nothing at all.
        const reach = 40 + h;
        for (let dy = 2; dy <= reach && samples.length < 60; dy += 2) {
            for (const src of [row - dy, row + dy]) {
                if (src >= y && src < y + h) continue;
                for (let col = x; col < x + w; col += 7) {
                    const c = paper(col, src);
                    if (c) samples.push(c);
                }
            }
        }
        // Nothing above or below to judge by: fall back to the sides.
        if (samples.length < 6) {
            for (let dx = 2; dx <= 60 && samples.length < 30; dx++) {
                for (const col of [x - dx, x + w - 1 + dx]) {
                    const c = paper(col, row);
                    if (c) samples.push(c);
                }
            }
        }
        // Still too little light: leave the row alone rather than paint a guess.
        if (samples.length < 6) continue;
        samples.sort((a, b) => a[0] + a[1] + a[2] - (b[0] + b[1] + b[2]));
        const [r, g, b] = samples[samples.length >> 1];
        for (let col = x; col < x + w; col++) {
            const i = (row * W + col) * 4;
            data[i] = r;
            data[i + 1] = g;
            data[i + 2] = b;
            data[i + 3] = 255;
        }
    }
}

/** Draw `text` centred on (cx, cy). */
function drawCentered(img, font, box, text, rgb, cx, cy) {
    const layer = new Jimp({ width: box.w, height: box.h, color: 0x00000000 });
    // Printing at the negated glyph origin makes the layer's corner the ink corner.
    layer.print({ font, x: -box.dx, y: -box.dy, text });
    tint(layer, rgb);
    img.composite(layer, Math.round(cx - box.w / 2), Math.round(cy - box.h / 2), {
        mode: BlendMode.SRC_OVER,
        originX: 0,
        originY: 0,
    });
}

/**
 * The clear horizontal strip nearest the bottom of the card. This card's design
 * runs right to the lower edge, so the footer has to find its own gap rather
 * than assume one.
 *
 * A row qualifies on the width of its widest unbroken light run, not on how
 * dark it is overall: the decorative border either side of the footer strip
 * makes those rows "dirty" by percentage while the gap between them is still
 * plenty wide to letter. Judging by darkness alone skipped the strip and put
 * the footer in the middle of the card instead.
 */
function findFooterBand(img, face) {
    const { width: W, data } = img.bitmap;
    const need = Math.round((face.x1 - face.x0) * 0.4);
    const clear = [];
    for (let y = face.y0; y <= face.y1; y++) {
        let longest = 0,
            run = 0;
        for (let x = face.x0; x <= face.x1; x++) {
            const i = (y * W + x) * 4;
            if (0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2] >= 200) {
                if (++run > longest) longest = run;
            } else run = 0;
        }
        clear.push(longest >= need);
    }

    const runs = [];
    for (let i = 0; i < clear.length; ) {
        if (!clear[i]) {
            i++;
            continue;
        }
        let j = i;
        while (j < clear.length && clear[j]) j++;
        runs.push({ y0: face.y0 + i, y1: face.y0 + j - 1, h: j - i });
        i = j;
    }
    if (!runs.length) return null;

    // Bottom-most band tall enough to letter; failing that, the tallest.
    const usable = runs.filter((r) => r.h >= 18);
    return usable.length ? usable[usable.length - 1] : runs.reduce((a, b) => (b.h > a.h ? b : a));
}

/**
 * The scannability floor is an absolute pixel count: 45 modules at 6 px is 270
 * px whether the artwork around it is 900 px wide or 4000 px. Clear space on
 * the card, on the other hand, scales with the image. So a photo can be too
 * small to host the symbol even though it has a completely empty pocket - the
 * pocket is just narrower than the code. Scaling the artwork up grows the
 * pocket without shrinking the code, which is the only way to keep a full-size
 * symbol off the printed text. The QR itself is re-rendered at the target module
 * size afterwards, so it stays pixel-sharp rather than being stretched.
 */
const UPSCALE_STEPS = [1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4];

/** A symbol wedged edge to edge into its pocket is measurably harder to find. */
const POCKET_CLEARANCE = 1.25;

try {
    const source = await Jimp.read(imageUrl);

    // --patch-only edits a card that has already been through this tool. It
    // keeps the finished pixels as they are, so the code already on the card
    // stays exactly where it was placed, and only the named lines are rewritten.
    const config = patchOnly
        ? { ecc: null, margin: 0, modules: 0, ppm: 0, size: 0 }
        : { ...[...CANDIDATES].sort((a, b) => rank(a) - rank(b))[0] };
    if (!patchOnly) {
        // Smallest symbol that clears the scannability floor, preferring the
        // strongest error correction and then the narrowest quiet zone. Sizing at
        // the floor rather than as large as the card allows keeps it small enough
        // to have a chance of fitting in the design.
        config.modules = await moduleCount(qrText, config);
        config.ppm = MIN_PIXELS_PER_MODULE;
        config.size = config.modules * MIN_PIXELS_PER_MODULE;
    }

    let cardImage = null;
    let face = null;
    let pocket = null;
    let scale = 1;

    for (const S of patchOnly ? [1] : UPSCALE_STEPS) {
        const candidate =
            S === 1
                ? source.clone()
                : source.clone().resize({
                      w: Math.round(source.bitmap.width * S),
                      h: Math.round(source.bitmap.height * S),
                  });
        const f = detectCardFace(candidate);
        const room = patchOnly ? null : largestEmptySquare(candidate, f);
        if (patchOnly) {
            cardImage = candidate;
            face = f;
            pocket = null;
            scale = 1;
            break;
        }
        if (room && room.size >= config.size * POCKET_CLEARANCE) {
            cardImage = candidate;
            face = f;
            pocket = room;
            scale = S;
            break;
        }
    }

    // Prefer a pocket that leaves the printed text completely untouched. Only
    // when no amount of scaling opens one do we fall back to an opaque plate
    // over the bottom-right corner - covering the design is the right trade when
    // it is the only way left to reach a scannable size.
    if (!cardImage) {
        cardImage = source.clone();
        face = detectCardFace(cardImage);
    }

    const cardSpan = Math.min(face.x1 - face.x0 + 1, face.y1 - face.y0 + 1);
    let slot = null;
    let coversArtwork = false;
    let ppm = config.ppm;
    let size = config.size;

    if (patchOnly) {
        // Nothing to place: the code is already on the card.
    } else if (pocket) {
        // Leave the symbol at the scannability floor and spend the pocket on
        // clear space instead. Growing the modules to fill the pocket looked
        // like free legibility and measured as worthless: at full size every
        // module size from 6 to 8 px read 20/20. Under rescaling - the card
        // being shrunk for a chat app, a screenshot, a smaller print - it was
        // actively harmful. Swept every card width from 1450 down to 300 px,
        // four reads each:
        //
        //   6 px/module   43 of 47 widths clean, nothing failed above 500 px
        //   7 px/module   42 of 47,  failures at 1350 and 1225 px
        //   8 px/module   37 of 47,  failures at 1400, 1350, 1225, 1200, 1175,
        //                             1125, 1075, 1050 px
        //
        // A symbol that exactly fills its pocket has no slack, so its module
        // grid lands on aliased positions far more often once the image is
        // resampled. The empty space around the smaller code is what keeps it
        // readable at the sizes people actually share cards at.
        size = config.size;
        slot = {
            x: pocket.x + Math.floor((pocket.size - size) / 2),
            y: pocket.y + Math.floor((pocket.size - size) / 2),
            size,
        };
    } else {
        // No empty pocket at any scale. Give the symbol the largest module size
        // the card can carry, and lay it over the corner.
        coversArtwork = true;
        const cap = Math.floor(cardSpan * MAX_QR_FRACTION);
        ppm = Math.max(1, Math.min(MIN_PIXELS_PER_MODULE, Math.floor(cap / config.modules)));
        size = config.modules * ppm;
        const inset = Math.max(10, Math.round(cardSpan * 0.02));
        // Hug the bottom-right corner, but never spill outside the card.
        slot = {
            x: Math.max(face.x0 + inset, face.x1 - inset - size + 1),
            y: Math.max(face.y0 + inset, face.y1 - inset - size + 1),
            size,
        };
    }

    /* --- printed text, applied before the code so the code is never touched --- */

    const notes = [];

    for (const p of patches) {
        // Patch rectangles arrive in source-image pixels; scale them with the art.
        const x = Math.round(p.x0 * scale);
        const y = Math.round(p.y0 * scale);
        const w = Math.round((p.x1 - p.x0) * scale);
        const h = Math.round((p.y1 - p.y0) * scale);

        // Match the ink already there so the replacement is the same colour,
        // unless the caller named one. An empty box samples nothing, so this is
        // also where an unset --ink shows up as near-black text.
        const sampled = sampleInk(cardImage, x, y, w, h);
        const rgb = inkOverride || sampled || INK_FALLBACK;
        paintBackground(cardImage, x, y, w, h);

        const fit = await fitBlock(p.text, w, h);
        if (!fit) {
            notes.push({ patch: p.text, applied: false, reason: 'text does not fit the box' });
            continue;
        }
        drawBlock(cardImage, fit, rgb, x + w / 2, y + h / 2);
        notes.push({
            patch: p.text,
            applied: true,
            rect: [x, y, w, h],
            lines: fit.lines.map((l) => l.text),
            glyphs: `${fit.w}x${fit.h}`,
            ink: rgb,
            ink_source: inkOverride ? 'override' : sampled ? 'sampled from the box' : 'fallback',
        });
    }

    if (creditText) {
        const band = findFooterBand(cardImage, face);
        const maxW = Math.round((face.x1 - face.x0) * 0.7);
        // Start at the smallest face a credit should ever use: letting the fit
        // reach for the display sizes makes it compete with the event details.
        const fit = band && (await fitFont(creditText, maxW, band.h, CREDIT_MAX_FONT));
        if (fit) {
            // Mid grey, not black: this is a service credit, not part of the event.
            drawCentered(cardImage, fit.font, fit, creditText, [0x46, 0x46, 0x46], (face.x0 + face.x1) / 2, (band.y0 + band.y1) / 2);
            notes.push({ credit: creditText, applied: true, band: [band.y0, band.h], glyphs: `${fit.w}x${fit.h}` });
        } else {
            notes.push({ credit: creditText, applied: false, reason: 'no clear strip tall enough at the bottom' });
        }
    }

    let rendered = null;
    if (!patchOnly) {
        rendered = await renderQr(qrText, config, size);
        const qr = await Jimp.read(rendered.buffer);

        const topLeft = { mode: BlendMode.SRC_OVER, originX: 0, originY: 0 };
        if (coversArtwork) {
            // An opaque plate keeps the quiet zone valid even over printed artwork.
            const plate = new Jimp({ width: size, height: size, color: 0xffffffff });
            cardImage.composite(plate, slot.x, slot.y, topLeft);
        }
        cardImage.composite(qr, slot.x, slot.y, topLeft);
    }

    await cardImage.write(outputPath);

    console.log(
        JSON.stringify({
            success: true,
            path: outputPath,
            image_size: { width: cardImage.bitmap.width, height: cardImage.bitmap.height },
            upscale: scale === 1 ? null : scale,
            card_face: { x0: face.x0, y0: face.y0, x1: face.x1, y1: face.y1, detected: face.detected },
            patch_only: patchOnly,
            qr_slot: patchOnly ? null : { x: slot.x, y: slot.y, size },
            qr_symbol: patchOnly
                ? null
                : {
                      modules: config.modules,
                      pixels_per_module: size / config.modules,
                      error_correction: config.ecc,
                      quiet_zone_modules: config.margin,
                      mask_pattern: rendered.maskPattern ?? 'library default',
                  },
            covers_artwork: patchOnly ? null : coversArtwork,
            text: notes,
        })
    );
} catch (e) {
    console.error(JSON.stringify({ success: false, error: e.message }));
    if (process.env.COMPOSITE_DEBUG) console.error(e.stack);
    process.exit(1);
}
