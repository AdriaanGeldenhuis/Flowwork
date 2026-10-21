/*
 * Generates every icon from the wax seals on the Flowwork home page
 * (home.php, /mail/css/mail.css .fwm-brand__tile and /home/css/home.css
 * .fwh-tile__seal): a round seal in the module's colour, an inner gold ring
 * and the module's line icon in champagne gold.
 *
 *   FlowWork  - the Flowwork logo: bordeaux seal with the cube
 *   FlowMail  - the Mail tile:     #9b3048 seal with the envelope
 *   FlowDrive - the Drive tile:    #4f5d6b seal with the cloud
 *
 * Outputs
 *   <res>/mipmap-<dpi>/ic_launcher[_mail|_drive]_foreground.webp   adaptive foreground: ring + icon (108dp)
 *   <res>/mipmap-<dpi>/ic_launcher[_mail|_drive][_round].webp      legacy icons (API 24-25)
 *   <res>/drawable-<dpi>/ic_stat_flowwork|ic_stat_mail|ic_stat_drive.webp  status-bar icons (24dp)
 *   <res>/drawable/ic_launcher[_mail|_drive]_background.xml        adaptive background: the seal's gradient
 *   <flowwork>/favicon.svg, favicon.ico, apple-touch-icon.png       the Flowwork logo for browsers
 *   <flowdrive>/public/favicon.svg, favicon.ico, apple-touch-icon.png  the Drive seal for FlowDrive
 *   <preview>/icons_preview.png                                     everything side by side (optional)
 *
 * Needs Playwright with Chromium (npm i playwright; npx playwright install chromium).
 * Usage: node make_icons.mjs --res <app/src/main/res> --flowwork <web root>
 *                            [--flowdrive <FlowDrive root>] [--preview <dir>]
 */
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const args = {};
for (let i = 2; i < process.argv.length; i += 2) args[process.argv[i].replace(/^--/, '')] = process.argv[i + 1];
if (!args.res || !args.flowwork) {
    console.error('Usage: node make_icons.mjs --res <res dir> --flowwork <web root> [--flowdrive <dir>] [--preview <dir>]');
    process.exit(1);
}

// ------------------------------------------------------------------ design

const GOLD = '#d8bd8a';                   // --gold-bright, the icon on every seal
const RING = 'rgba(234,210,162,0.42)';    // the seal's inner ring

// Line icons (24 x 24, stroked), the same symbols the web app uses
const GLYPHS = {
    cube: '<path d="M20 7 12 3 4 7v10l8 4 8-4V7Z"/><path d="m12 12 8-5"/><path d="M12 12v9"/><path d="M12 12 4 7"/>',
    mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    cloud: '<path d="M17.5 19a4.5 4.5 0 0 0 .4-9A6.5 6.5 0 0 0 5.4 11.6 3.8 3.8 0 0 0 6.2 19Z"/><path d="m9.5 14.5 2.5-2.5 2.5 2.5"/><path d="M12 12v5"/>',
};

const hex = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
const toHex = (c) => '#' + c.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
// color-mix(in srgb, c p, other)
const mix = (c, p, other) => toHex(hex(c).map((v, i) => v * p + hex(other)[i] * (1 - p)));
// The module tiles' seal: color-mix(--c 62%, #fff) -> --c -> color-mix(--c 58%, #000)
const seal = (c) => ({ hi: mix(c, 0.62, '#ffffff'), mid: c, lo: mix(c, 0.58, '#000000') });

const ICONS = [
    // The Flowwork logo uses its own hand-picked seal (mail.css .fwm-brand__tile)
    { key: 'flowwork', res: 'ic_launcher', stat: 'ic_stat_flowwork', label: 'FlowWork', glyph: 'cube', seal: { hi: '#b24660', mid: '#7a1f30', lo: '#470f1d' } },
    { key: 'mail', res: 'ic_launcher_mail', stat: 'ic_stat_mail', label: 'FlowMail', glyph: 'mail', seal: seal('#9b3048') },
    { key: 'drive', res: 'ic_launcher_drive', stat: 'ic_stat_drive', label: 'FlowDrive', glyph: 'cloud', seal: seal('#4f5d6b') },
];

/** The seal's radial gradient, CSS "circle at 34% 28%" (farthest corner) over a box at (x, y) of size s. */
function gradient(id, sl, x, y, s) {
    const r = Math.hypot(0.66, 0.72) * s;
    return `<radialGradient id="${id}" gradientUnits="userSpaceOnUse" cx="${x + 0.34 * s}" cy="${y + 0.28 * s}" r="${r}">`
        + `<stop offset="0" stop-color="${sl.hi}"/><stop offset=".46" stop-color="${sl.mid}"/><stop offset="1" stop-color="${sl.lo}"/></radialGradient>`;
}

/** A line icon centred on (cx, cy), `size` units across, stroke in icon units. */
function glyph(name, cx, cy, size, stroke, color = GOLD) {
    const k = size / 24;
    return `<g transform="translate(${cx - 12 * k} ${cy - 12 * k}) scale(${k})" fill="none" stroke="${color}" stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round">${GLYPHS[name]}</g>`;
}

/** The seal's pressed edge: a light rim on top, a shadow at the bottom. */
function edge(cx, cy, r, w) {
    return `<linearGradient id="edge" x1="0" y1="0" x2="0" y2="1">`
        + `<stop offset="0" stop-color="#ffdcc8" stop-opacity=".38"/><stop offset=".42" stop-color="#ffdcc8" stop-opacity="0"/>`
        + `<stop offset=".62" stop-color="#1e0008" stop-opacity="0"/><stop offset="1" stop-color="#1e0008" stop-opacity=".42"/></linearGradient>`
        + `<circle cx="${cx}" cy="${cy}" r="${r - w / 2}" fill="none" stroke="url(#edge)" stroke-width="${w}"/>`;
}

const svg = (w, h, body) => `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">${body}</svg>`;

/**
 * The seal on its own, in a 100 x 100 box, as on the home page.
 * bold: a heavier icon and ring that stay crisp at favicon sizes (16-32 px).
 */
function sealSvg(ic, { bold = false, square = false } = {}) {
    const body = square
        ? `<rect width="100" height="100" fill="url(#g)"/>`
        : `<circle cx="50" cy="50" r="50" fill="url(#g)"/>${edge(50, 50, 50, 3)}`;
    const ring = bold ? `<circle cx="50" cy="50" r="42.5" fill="none" stroke="${RING}" stroke-width="3"/>`
        : `<circle cx="50" cy="50" r="40" fill="none" stroke="${RING}" stroke-width="2.5"/>`;
    const g = bold ? glyph(ic.glyph, 50, 50, 60, 2.25) : glyph(ic.glyph, 50, 50, square ? 46 : 43, 1.7);
    return svg(100, 100, `<defs>${gradient('g', ic.seal, 0, 0, 100)}</defs>${body}${ring}${g}`);
}

// Android adaptive icon: 108dp layers, the launcher shows the middle 72dp
// (masked to a circle, squircle, ...). The background is the seal's gradient
// over that 72dp window; the foreground carries the ring and the icon.
const A = 108, WIN = 72, O = (A - WIN) / 2;

function foregroundSvg(ic) {
    return svg(A, A, `<circle cx="54" cy="54" r="${0.4 * WIN}" fill="none" stroke="${RING}" stroke-width="${0.025 * WIN}"/>`
        + glyph(ic.glyph, 54, 54, 0.46 * WIN, 1.7));
}

function fullAdaptiveSvg(ic) { // background + foreground, for legacy icons and previews
    return svg(A, A, `<defs>${gradient('g', ic.seal, O, O, WIN)}</defs><rect width="${A}" height="${A}" fill="url(#g)"/>`
        + `<circle cx="54" cy="54" r="${0.4 * WIN}" fill="none" stroke="${RING}" stroke-width="${0.025 * WIN}"/>`
        + glyph(ic.glyph, 54, 54, 0.46 * WIN, 1.7));
}

/** 48dp legacy icon: a 44dp round seal, or a rounded square of the same, with 2dp margin. */
function legacySvg(ic, round) {
    const clip = round ? `<circle cx="24" cy="24" r="22"/>` : `<rect x="2" y="2" width="44" height="44" rx="9.7"/>`;
    const k = 44 / WIN;
    return svg(48, 48, `<defs>${gradient('g', ic.seal, 2, 2, 44)}<clipPath id="c">${clip}</clipPath></defs>`
        + `<g clip-path="url(#c)"><rect width="48" height="48" fill="url(#g)"/>`
        + `<circle cx="24" cy="24" r="${0.4 * WIN * k}" fill="none" stroke="${RING}" stroke-width="${0.025 * WIN * k}"/>`
        + glyph(ic.glyph, 24, 24, 0.46 * 44, 1.7) + (round ? edge(24, 24, 22, 1.3) : '') + '</g>');
}

/** Status-bar icon: the line icon alone in white, 24dp with a 1dp margin. */
function statusSvg(ic) {
    return svg(24, 24, glyph(ic.glyph, 12, 12, 24 * 22 / 20, 1.9, '#ffffff'));
}

/** Adaptive background as a vector drawable: the seal's radial gradient over the 72dp window. */
function backgroundXml(ic) {
    const argb = (h) => '#FF' + h.slice(1).toUpperCase();
    const s = ic.seal;
    return `<?xml version="1.0" encoding="utf-8"?>
<!--
   ${ic.label} adaptive-icon background: the wax seal of the Flowwork home page,
   a radial gradient ${s.hi} -> ${s.mid} -> ${s.lo} lit from the top left of the
   visible 72dp window. Generated by app files/tools/icons/make_icons.mjs.
-->
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    xmlns:aapt="http://schemas.android.com/aapt"
    android:width="108dp"
    android:height="108dp"
    android:viewportWidth="108"
    android:viewportHeight="108">
    <path android:pathData="M0,0h108v108h-108z">
        <aapt:attr name="android:fillColor">
            <gradient
                android:type="radial"
                android:centerX="${(O + 0.34 * WIN).toFixed(2)}"
                android:centerY="${(O + 0.28 * WIN).toFixed(2)}"
                android:gradientRadius="${(Math.hypot(0.66, 0.72) * WIN).toFixed(2)}">
                <item android:offset="0" android:color="${argb(s.hi)}" />
                <item android:offset="0.46" android:color="${argb(s.mid)}" />
                <item android:offset="1" android:color="${argb(s.lo)}" />
            </gradient>
        </aapt:attr>
    </path>
</vector>
`;
}

// ------------------------------------------------------------------ render

const browser = await chromium.launch();
const page = await browser.newPage();

/** Rasterise an SVG to `size` px; type 'png' or 'webp' (lossless). Returns a Buffer. */
async function render(svgText, size, type = 'png') {
    const url = await page.evaluate(async ({ svgText, size, type }) => {
        const img = new Image();
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgText);
        await img.decode();
        const c = document.createElement('canvas');
        c.width = c.height = size;
        const x = c.getContext('2d');
        x.imageSmoothingQuality = 'high';
        x.drawImage(img, 0, 0, size, size);
        return c.toDataURL('image/' + type, 1); // Chromium encodes WebP at quality 1 losslessly
    }, { svgText, size, type });
    return Buffer.from(url.split(',')[1], 'base64');
}

/** A .ico holding PNG images (Windows Vista+ and every browser read these). */
function ico(pngs) { // [[size, Buffer]]
    const head = Buffer.alloc(6 + 16 * pngs.length);
    head.writeUInt16LE(0, 0);
    head.writeUInt16LE(1, 2);
    head.writeUInt16LE(pngs.length, 4);
    let offset = head.length;
    pngs.forEach(([size, buf], i) => {
        const e = 6 + 16 * i;
        head.writeUInt8(size >= 256 ? 0 : size, e);
        head.writeUInt8(size >= 256 ? 0 : size, e + 1);
        head.writeUInt16LE(1, e + 4);   // colour planes
        head.writeUInt16LE(32, e + 6);  // bits per pixel
        head.writeUInt32LE(buf.length, e + 8);
        head.writeUInt32LE(offset, e + 12);
        offset += buf.length;
    });
    return Buffer.concat([head, ...pngs.map(([, b]) => b)]);
}

const DENSITIES = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 };

for (const ic of ICONS) {
    for (const [dpi, k] of Object.entries(DENSITIES)) {
        const mdir = join(args.res, `mipmap-${dpi}`);
        const ddir = join(args.res, `drawable-${dpi}`);
        mkdirSync(mdir, { recursive: true });
        mkdirSync(ddir, { recursive: true });
        writeFileSync(join(mdir, `${ic.res}_foreground.webp`), await render(foregroundSvg(ic), Math.round(108 * k), 'webp'));
        writeFileSync(join(mdir, `${ic.res}.webp`), await render(legacySvg(ic, false), Math.round(48 * k), 'webp'));
        writeFileSync(join(mdir, `${ic.res}_round.webp`), await render(legacySvg(ic, true), Math.round(48 * k), 'webp'));
        writeFileSync(join(ddir, `${ic.stat}.webp`), await render(statusSvg(ic), Math.round(24 * k), 'webp'));
    }
    mkdirSync(join(args.res, 'drawable'), { recursive: true });
    writeFileSync(join(args.res, 'drawable', `${ic.res}_background.xml`), backgroundXml(ic));
}

/** favicon.svg + favicon.ico (16, 32, 48) + apple-touch-icon.png (180, square: iOS rounds it). */
async function webIcons(ic, dir) {
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'favicon.svg'), sealSvg(ic, { bold: true }) + '\n');
    const sizes = [16, 32, 48];
    const pngs = [];
    for (const s of sizes) pngs.push([s, await render(sealSvg(ic, { bold: s < 48 }), s)]);
    writeFileSync(join(dir, 'favicon.ico'), ico(pngs));
    writeFileSync(join(dir, 'apple-touch-icon.png'), await render(sealSvg(ic, { square: true }), 180));
}

await webIcons(ICONS[0], args.flowwork);
if (args.flowdrive) await webIcons(ICONS[2], join(args.flowdrive, 'public'));

if (args.preview) {
    // One row per icon: favicon 16/32, seal, legacy square/round, adaptive circle/squircle, status icon
    mkdirSync(args.preview, { recursive: true });
    const cells = [];
    for (const ic of ICONS) {
        const row = [];
        for (const s of [16, 32]) row.push([await render(sealSvg(ic, { bold: true }), s), s]);
        row.push([await render(sealSvg(ic), 96), 96]);
        row.push([await render(legacySvg(ic, false), 96), 96]);
        row.push([await render(legacySvg(ic, true), 96), 96]);
        const full = await render(fullAdaptiveSvg(ic), 144);
        row.push([full, 96, 'circle'], [full, 96, 'squircle']);
        row.push([await render(statusSvg(ic), 48), 48, 'dark']);
        cells.push(row);
    }
    const html = '<body style="margin:0;background:#f2ece3;font:13px sans-serif">' + cells.map((row) => '<div style="display:flex;gap:18px;align-items:center;padding:14px">'
        + row.map(([b, s, mask]) => {
            const src = 'data:image/png;base64,' + b.toString('base64');
            if (mask === 'dark') return `<div style="background:#202124;padding:8px;border-radius:8px"><img src="data:image/webp;base64,${b.toString('base64')}" width="${s}" height="${s}"></div>`;
            if (mask) return `<div style="width:${s}px;height:${s}px;overflow:hidden;border-radius:${mask === 'circle' ? '50%' : '30%'}"><img src="${src}" style="width:${s * 1.5}px;margin:-${s / 4}px"></div>`;
            return `<img src="${src}" width="${s}" height="${s}">`;
        }).join('') + '</div>').join('') + '</body>';
    await page.setViewportSize({ width: 820, height: 420 });
    await page.setContent(html);
    await page.screenshot({ path: join(args.preview, 'icons_preview.png'), fullPage: true });
}

await browser.close();
console.log('done');
