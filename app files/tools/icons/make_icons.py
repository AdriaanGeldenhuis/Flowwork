"""
Generates every launcher + notification icon for the FlowWork Android app.

  FlowWork  - the brand "F", extracted from assets/logo.webp, on purple
  FlowMail  - envelope, rendered in the same glossy 3D style, on red
  FlowDrive - cloud with an upload arrow, same style, on orange

There is deliberately no monochrome (themed-icon) layer: with Android's
themed icons switched on it would repaint all three in the same colour.

Outputs (under <res>):
  mipmap-<dpi>/ic_launcher[_mail|_drive]_foreground.webp   adaptive foreground (108dp)
  mipmap-<dpi>/ic_launcher[_mail|_drive].webp              legacy square icon (API 24-25)
  mipmap-<dpi>/ic_launcher[_mail|_drive]_round.webp        legacy round icon (API 25)
  drawable-<dpi>/ic_stat_flowwork|ic_stat_mail|ic_stat_drive.webp  status-bar icons (24dp)

Usage: python make_icons.py <logo.webp> <res dir> <preview dir>
"""
import math
import sys

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

LOGO, RES, PREVIEW = sys.argv[1], sys.argv[2], sys.argv[3]

DENSITIES = {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}
PX = 16                      # master resolution: px per dp
LAYER = 108 * PX             # adaptive layer, 108dp
SAFE_R = 30.5 * PX           # glyph must sit inside the 66dp safe circle (33dp radius) with margin

# One palette per icon. Gradient ends are chosen so the visible 72dp window
# runs from the first colour (top-left) to the second (bottom-right); the
# same ends are in drawable/ic_launcher[_mail|_drive]_background.xml.
PALETTES = {
    # FlowWork purple, sampled from assets/logo.webp: #7C2EA6 -> #2F0461
    'purple': dict(bg_tl=(143, 57, 183), bg_br=(28, 0, 80), side_near=(150, 126, 196), side_far=(112, 88, 164),
                   shadow=(16, 0, 48), face_bottom=(221, 218, 228)),
    # FlowMail red: #E53935 -> #7A0A14
    'red': dict(bg_tl=(255, 69, 61), bg_br=(95, 0, 12), side_near=(222, 150, 156), side_far=(178, 102, 110),
                shadow=(48, 0, 6), face_bottom=(228, 220, 222)),
    # FlowDrive orange: #FF9828 -> #D6540A
    'orange': dict(bg_tl=(255, 169, 48), bg_br=(204, 67, 3), side_near=(232, 170, 128), side_far=(196, 126, 84),
                   shadow=(70, 24, 0), face_bottom=(230, 224, 218)),
}
FACE_TOP = (244, 242, 247)
RIM = (255, 252, 255)


def use(palette):
    """Switch the colours the render functions below read."""
    global BG_TL, BG_BR, SIDE_NEAR, SIDE_FAR, SHADOW, FACE_BOTTOM
    p = PALETTES[palette]
    BG_TL, BG_BR = p['bg_tl'], p['bg_br']
    SIDE_NEAR, SIDE_FAR, SHADOW, FACE_BOTTOM = p['side_near'], p['side_far'], p['shadow'], p['face_bottom']


use('purple')


# ---------------------------------------------------------------- helpers

def background(size):
    """Diagonal gradient of the current palette, identical to its drawable/ic_launcher*_background.xml."""
    t = np.add.outer(np.arange(size), np.arange(size)) / (2.0 * (size - 1))
    out = np.zeros((size, size, 3), np.float32)
    for c in range(3):
        out[..., c] = BG_TL[c] + (BG_BR[c] - BG_TL[c]) * t
    return Image.fromarray(out.astype(np.uint8), 'RGB').convert('RGBA')


def shift(mask, dx, dy):
    """Shift a float mask by integer pixels, filling with 0."""
    out = np.zeros_like(mask)
    h, w = mask.shape
    xs, xd = (0, dx) if dx >= 0 else (-dx, 0)
    ys, yd = (0, dy) if dy >= 0 else (-dy, 0)
    out[yd:h - ys if ys else h, xd:w - xs if xs else w] = mask[ys:h - yd if yd else h, xs:w - xd if xd else w]
    return out


def lerp(a, b, t):
    return tuple(a[i] + (b[i] - a[i]) * t for i in range(3))


def render_3d(face):
    """Glossy white face + lavender extrusion (down-right) + soft drop shadow, like the logo's F."""
    size = face.shape[0]
    depth = int(1.9 * PX)
    side = np.zeros_like(face)
    side_tone = np.zeros_like(face)
    for k in range(1, depth + 1):
        s = shift(face, int(round(k * 0.45)), k)
        newly = np.clip(s - side, 0, 1)
        side = np.maximum(side, s)
        side_tone += newly * (k / depth)
    side_only = np.clip(side - face, 0, 1)
    tone = np.clip(side_tone / np.maximum(side, 1e-6), 0, 1)

    body = np.maximum(face, side)
    shadow = Image.fromarray((shift(body, int(0.8 * PX), int(2.2 * PX)) * 255).astype(np.uint8), 'L')
    shadow = np.asarray(shadow.filter(ImageFilter.GaussianBlur(2.6 * PX))).astype(np.float32) / 255.0

    yy = np.linspace(0, 1, size)[:, None].repeat(size, 1)
    rgb = np.zeros((size, size, 3), np.float32)
    a = np.zeros((size, size), np.float32)

    # shadow
    for c in range(3):
        rgb[..., c] = SHADOW[c]
    a = shadow * 0.62

    def over(color_rgb, alpha):
        nonlocal rgb, a
        na = alpha + a * (1 - alpha)
        safe = np.where(na > 0, na, 1)
        for c in range(3):
            rgb[..., c] = (color_rgb[..., c] * alpha + rgb[..., c] * a * (1 - alpha)) / safe
        a = na

    side_rgb = np.zeros_like(rgb)
    for c in range(3):
        side_rgb[..., c] = SIDE_NEAR[c] + (SIDE_FAR[c] - SIDE_NEAR[c]) * tone
    over(side_rgb, side_only)

    face_rgb = np.zeros_like(rgb)
    for c in range(3):
        face_rgb[..., c] = FACE_TOP[c] + (FACE_BOTTOM[c] - FACE_TOP[c]) * yy
    # bright rim on the upper-left edges (light comes from the top-left)
    rim = np.clip(face - shift(face, int(0.5 * PX), int(0.6 * PX)), 0, 1)
    rim = np.asarray(Image.fromarray((rim * 255).astype(np.uint8)).filter(
        ImageFilter.GaussianBlur(0.25 * PX))).astype(np.float32) / 255.0
    for c in range(3):
        face_rgb[..., c] = face_rgb[..., c] * (1 - rim * 0.8) + RIM[c] * rim * 0.8
    over(face_rgb, face)

    return Image.fromarray(np.dstack([np.clip(rgb, 0, 255), np.clip(a, 0, 1) * 255]).astype(np.uint8), 'RGBA')


def fit_into_safe_zone(alpha_full, radius=SAFE_R):
    """Scale/translate a glyph mask so its minimum enclosing circle sits centred in the layer."""
    ys, xs = np.nonzero(alpha_full > 0.35)
    pts = np.stack([xs, ys], 1).astype(np.float32)
    (cx, cy), r = cv2.minEnclosingCircle(pts)
    scale = radius / r
    m = np.array([[scale, 0, LAYER / 2 - cx * scale], [0, scale, LAYER / 2 - cy * scale]], np.float32)
    return m


def warp(img_np, m):
    return cv2.warpAffine(img_np, m, (LAYER, LAYER), flags=cv2.INTER_CUBIC, borderValue=0)


def save_webp(img, path, lossless=True):
    img.save(path, 'WEBP', lossless=lossless, quality=100, method=6)


# ---------------------------------------------------------------- glyphs

def f_glyph():
    """The brand F from the master logo: returns (rgba layer with original shading, face mask)."""
    im = np.asarray(Image.open(LOGO).convert('RGB')).astype(np.float32)
    r, g, b = im[..., 0], im[..., 1], im[..., 2]
    alpha = np.clip((g - 48.0) / (95.0 - 48.0), 0.0, 1.0)
    alpha[(r + g + b) < 30] = 0
    # keep the two strokes of the F, drop the tile's rim highlight
    n, labels, stats, _ = cv2.connectedComponentsWithStats((alpha > 0.3).astype(np.uint8), 8)
    order = sorted(range(1, n), key=lambda i: -stats[i, cv2.CC_STAT_AREA])[:2]
    keep = np.isin(labels, order).astype(np.uint8)
    keep = cv2.dilate(keep, np.ones((5, 5), np.uint8))
    alpha *= keep
    bgc = np.array([85, 15, 135], np.float32)
    a3 = alpha[..., None]
    fg = np.clip((im - bgc * (1 - a3)) / np.where(a3 > 0.02, a3, 1.0), 0, 255)
    # face = the bright part (G > ~170); the extrusion is darker lavender
    face = np.clip((g - 150.0) / (200.0 - 150.0), 0, 1) * alpha

    m = fit_into_safe_zone(alpha)
    fg_w = warp(fg, m)
    alpha_w = warp(alpha, m)
    face_w = np.clip(warp(face, m), 0, 1)
    body = np.clip(alpha_w, 0, 1)

    # synthesized drop shadow (the original's shadow is baked into its tile)
    sh = Image.fromarray((shift(body, int(0.8 * PX), int(2.2 * PX)) * 255).astype(np.uint8), 'L')
    sh = np.asarray(sh.filter(ImageFilter.GaussianBlur(2.6 * PX))).astype(np.float32) / 255.0 * 0.62
    rgb = np.zeros((LAYER, LAYER, 3), np.float32)
    for c in range(3):
        rgb[..., c] = (np.clip(fg_w[..., c], 0, 255) * body + SHADOW[c] * sh * (1 - body)) / np.maximum(
            body + sh * (1 - body), 1e-6)
    a = body + sh * (1 - body)
    layer = Image.fromarray(np.dstack([rgb, np.clip(a, 0, 1) * 255]).astype(np.uint8), 'RGBA')
    return layer, body, face_w


def draw_mask(draw_fn):
    ss = 2  # extra supersampling for the hand-drawn shapes
    img = Image.new('L', (LAYER * ss, LAYER * ss), 0)
    d = ImageDraw.Draw(img)
    draw_fn(d, PX * ss)
    img = img.resize((LAYER, LAYER), Image.LANCZOS)
    return np.asarray(img).astype(np.float32) / 255.0


def thick_line(d, pts, width, u, fill):
    """Polyline with round joins and caps."""
    w = width * u
    for (x0, y0), (x1, y1) in zip(pts, pts[1:]):
        d.line([(x0 * u, y0 * u), (x1 * u, y1 * u)], fill=fill, width=int(round(w)))
    for x, y in pts:
        d.ellipse([x * u - w / 2, y * u - w / 2, x * u + w / 2, y * u + w / 2], fill=fill)


def mail_mask():
    def draw(d, u):
        # envelope body, 52 x 38 dp
        d.rounded_rectangle([28 * u, 35 * u, 80 * u, 73 * u], radius=6.5 * u, fill=255)
        # carved flap: a soft V from the top corners down to the centre
        thick_line(d, [(31.5, 39.5), (54, 58), (76.5, 39.5)], 3.6, u, 0)
    return draw_mask(draw)


def drive_mask():
    def draw(d, u):
        def circle(cx, cy, r):
            d.ellipse([(cx - r) * u, (cy - r) * u, (cx + r) * u, (cy + r) * u], fill=255)
        oy = 3.5  # optical centring
        circle(57, 44.5 + oy, 15.5)
        circle(40.5, 54 + oy, 11)
        circle(72.5, 53.5 + oy, 10.5)
        d.rounded_rectangle([29.5 * u, (50 + oy) * u, 83 * u, (69 + oy) * u], radius=9.5 * u, fill=255)
        # carved upload arrow
        thick_line(d, [(56, 63 + oy), (56, 46 + oy)], 3.6, u, 0)
        thick_line(d, [(48.5, 53 + oy), (56, 45.5 + oy), (63.5, 53 + oy)], 3.6, u, 0)
    return draw_mask(draw)


# ---------------------------------------------------------------- outputs

def legacy(fg_layer, shape):
    """48dp legacy icon: visible 72dp window of bg+fg, as a rounded tile or circle."""
    full = Image.alpha_composite(background(LAYER), fg_layer)
    o = int(18 * PX)
    win = full.crop((o, o, LAYER - o, LAYER - o))          # 72dp
    tile = 44 * PX
    win = win.resize((tile, tile), Image.LANCZOS)
    mask = Image.new('L', (tile * 2, tile * 2), 0)
    d = ImageDraw.Draw(mask)
    if shape == 'round':
        d.ellipse([0, 0, tile * 2 - 1, tile * 2 - 1], fill=255)
    else:
        d.rounded_rectangle([0, 0, tile * 2 - 1, tile * 2 - 1], radius=int(tile * 2 * 0.22), fill=255)
    mask = mask.resize((tile, tile), Image.LANCZOS)
    out = Image.new('RGBA', (48 * PX, 48 * PX), (0, 0, 0, 0))
    out.paste(win, (2 * PX, 2 * PX), mask)
    return out


def status_icon(alpha):
    """24dp white silhouette with a 1dp margin, for the status bar / notification header."""
    ys, xs = np.nonzero(alpha > 0.35)
    x0, x1, y0, y1 = xs.min(), xs.max(), ys.min(), ys.max()
    crop = alpha[y0:y1 + 1, x0:x1 + 1]
    target = 22 * PX
    s = target / max(crop.shape)
    w, h = max(1, int(crop.shape[1] * s)), max(1, int(crop.shape[0] * s))
    img = Image.fromarray((np.clip(crop, 0, 1) * 255).astype(np.uint8), 'L').resize((w, h), Image.LANCZOS)
    a = Image.new('L', (24 * PX, 24 * PX), 0)
    a.paste(img, ((24 * PX - w) // 2, (24 * PX - h) // 2))
    out = Image.new('RGBA', a.size, (255, 255, 255, 0))
    out.putalpha(a)
    return out


def write_set(name, fg_layer, mono_alpha, stat_name):
    import os
    sq, rd = legacy(fg_layer, 'square'), legacy(fg_layer, 'round')
    stat = status_icon(mono_alpha)
    for dpi, scale in DENSITIES.items():
        mdir = f'{RES}/mipmap-{dpi}'
        ddir = f'{RES}/drawable-{dpi}'
        os.makedirs(mdir, exist_ok=True)
        os.makedirs(ddir, exist_ok=True)
        lp = int(round(108 * scale))
        save_webp(fg_layer.resize((lp, lp), Image.LANCZOS), f'{mdir}/{name}_foreground.webp')
        ip = int(round(48 * scale))
        save_webp(sq.resize((ip, ip), Image.LANCZOS), f'{mdir}/{name}.webp')
        save_webp(rd.resize((ip, ip), Image.LANCZOS), f'{mdir}/{name}_round.webp')
        if stat_name:
            sp = int(round(24 * scale))
            save_webp(stat.resize((sp, sp), Image.LANCZOS), f'{ddir}/{stat_name}.webp')
    return sq


def preview(name, fg_layer):
    """Circle, squircle, rounded-square and teardrop masks side by side, plus the raw layer."""
    full = Image.alpha_composite(background(LAYER), fg_layer)
    o = int(18 * PX)
    win = full.crop((o, o, LAYER - o, LAYER - o)).resize((216, 216), Image.LANCZOS)
    tiles = []
    for kind in ('circle', 'squircle', 'rounded', 'teardrop'):
        m = Image.new('L', (864, 864), 0)
        d = ImageDraw.Draw(m)
        if kind == 'circle':
            d.ellipse([0, 0, 863, 863], fill=255)
        elif kind == 'squircle':
            d.rounded_rectangle([0, 0, 863, 863], radius=300, fill=255)
        elif kind == 'rounded':
            d.rounded_rectangle([0, 0, 863, 863], radius=140, fill=255)
        else:
            d.rounded_rectangle([0, 0, 863, 863], radius=432, fill=255)
            d.rectangle([432, 432, 863, 863], fill=255)
        m = m.resize((216, 216), Image.LANCZOS)
        t = Image.new('RGBA', (216, 216), (245, 245, 245, 255))
        t.paste(win, (0, 0), m)
        tiles.append(t)
    raw = full.resize((216, 216), Image.LANCZOS)
    d = ImageDraw.Draw(raw)
    d.ellipse([216 / 2 - 66, 216 / 2 - 66, 216 / 2 + 66, 216 / 2 + 66], outline=(255, 60, 60), width=2)
    tiles.append(raw)
    sheet = Image.new('RGBA', (len(tiles) * 232, 232), (255, 255, 255, 255))
    for i, t in enumerate(tiles):
        sheet.paste(t, (8 + i * 232, 8))
    sheet.save(f'{PREVIEW}/{name}_masks.png')
    return sheet


use('purple')
f_layer, f_alpha, _ = f_glyph()
m_face = mail_mask()
d_face = drive_mask()
m_fit = fit_into_safe_zone(m_face, SAFE_R * 0.86)
d_fit = fit_into_safe_zone(d_face, SAFE_R * 0.91)
m_face = np.clip(warp(m_face, m_fit), 0, 1)
d_face = np.clip(warp(d_face, d_fit), 0, 1)
use('red')
m_layer = render_3d(m_face)
use('orange')
d_layer = render_3d(d_face)

ICONS = (
    ('ic_launcher', 'purple', f_layer, f_alpha, 'ic_stat_flowwork', 'FlowWork'),
    ('ic_launcher_mail', 'red', m_layer, m_face, 'ic_stat_mail', 'FlowMail'),
    ('ic_launcher_drive', 'orange', d_layer, d_face, 'ic_stat_drive', 'FlowDrive'),
)
for name, palette, layer, mono, stat, _label in ICONS:
    use(palette)
    write_set(name, layer, mono, stat)
    preview(name, layer)

# home-screen style preview: the three icons on a wallpaper-ish background, with labels
home = Image.new('RGBA', (760, 300), (36, 52, 71, 255))
d = ImageDraw.Draw(home)
for i, (name, palette, layer, _mono, _stat, label) in enumerate(ICONS):
    use(palette)
    full = Image.alpha_composite(background(LAYER), layer)
    o = int(18 * PX)
    win = full.crop((o, o, LAYER - o, LAYER - o)).resize((150, 150), Image.LANCZOS)
    m = Image.new('L', (600, 600), 0)
    ImageDraw.Draw(m).ellipse([0, 0, 599, 599], fill=255)
    m = m.resize((150, 150), Image.LANCZOS)
    x = 60 + i * 240
    home.paste(win, (x, 50), m)
    d.text((x + 75 - len(label) * 3, 220), label, fill=(255, 255, 255, 255))
home.save(f'{PREVIEW}/home_preview.png')

# status icons preview on a dark bar
bar = Image.new('RGBA', (300, 60), (30, 30, 30, 255))
for i, n in enumerate(('ic_stat_flowwork', 'ic_stat_mail', 'ic_stat_drive')):
    ic = Image.open(f'{RES}/drawable-xxxhdpi/{n}.webp').convert('RGBA').resize((48, 48), Image.LANCZOS)
    bar.paste(ic, (20 + i * 90, 6), ic)
bar.save(f'{PREVIEW}/status_preview.png')
print('done')
