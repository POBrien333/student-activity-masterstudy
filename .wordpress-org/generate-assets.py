"""Generate WordPress.org banner + icon assets.

Original artwork: a book with an activity pulse. Deliberately NOT the MasterStudy
"MS" logo — that is StyleMix's trademark and using it would imply an official
affiliation the plugin explicitly disclaims.
"""
import os

from PIL import Image, ImageDraw, ImageFont

OUT = os.path.join(
    r"E:\Projects\Swing Dance Home\Backups"
    r"\swingdancehome-com-20260824-165623-4x7qpi0lvmzx\plugins"
    r"\student-activity-masterstudy",
    ".wordpress-org",
)
os.makedirs(OUT, exist_ok=True)

BLUE = (59, 91, 219)        # #3B5BDB  primary
BLUE_DARK = (47, 73, 175)   # #2F49AF  spine / depth
PAGE = (255, 255, 255)
PAGE_EDGE = (226, 230, 240)
WHITE = (255, 255, 255)

FONT_BLACK = r"C:\Windows\Fonts\seguibl.ttf"
FONT_BOLD = r"C:\Windows\Fonts\segoeuib.ttf"

SS = 4  # supersampling factor


def rr(draw, box, r, fill):
    draw.rounded_rectangle(box, radius=r, fill=fill)


def draw_icon(draw, x, y, size, reverse=False):
    """Book with a pulse line, drawn into a square of `size` at (x, y).

    reverse=True renders it light-on-blue, for sitting inside the blue badge —
    a blue cover on a blue field loses all its edges once scaled to 128px.
    """
    u = size / 100.0  # 1 unit = 1% of icon size

    cover = PAGE if reverse else BLUE
    spine = (198, 211, 250) if reverse else BLUE_DARK
    panel = BLUE if reverse else PAGE
    pulse = PAGE if reverse else BLUE
    pages = ((188, 202, 245), (214, 223, 255)) if reverse else (PAGE_EDGE, (245, 247, 251))

    def p(*vals):
        return [x + v * u if i % 2 == 0 else y + v * u for i, v in enumerate(vals)]

    # Page block peeking out at the bottom (gives the book some depth).
    rr(draw, p(16, 74, 88, 90), 6 * u, pages[0])
    rr(draw, p(16, 70, 88, 86), 6 * u, pages[1])

    # Cover.
    rr(draw, p(12, 10, 88, 82), 10 * u, cover)

    # Spine down the left edge.
    rr(draw, p(12, 10, 30, 82), 10 * u, spine)
    draw.rectangle(p(24, 10, 30, 82), fill=spine)

    # Page panel.
    rr(draw, p(38, 24, 78, 64), 4 * u, panel)

    # Activity pulse across the panel.
    pts = [(41, 50), (48, 50), (52, 34), (58, 58), (63, 44), (68, 50), (75, 50)]
    line = []
    for px, py in pts:
        line.extend([x + px * u, y + py * u])
    draw.line(line, fill=pulse, width=max(1, int(4.4 * u)), joint="curve")

    # Round the polyline ends so it reads cleanly when scaled down.
    r_end = 2.2 * u
    for px, py in (pts[0], pts[-1]):
        cx, cy = x + px * u, y + py * u
        draw.ellipse([cx - r_end, cy - r_end, cx + r_end, cy + r_end], fill=pulse)


def fit_font(path, text, target_px):
    """Largest size whose cap-height roughly matches target_px."""
    size = target_px
    while size > 8:
        f = ImageFont.truetype(path, size)
        h = f.getbbox("Hxy")[3] - f.getbbox("Hxy")[1]
        if h <= target_px:
            return f
        size -= 2
    return ImageFont.truetype(path, 8)


def centred(draw, font, text, cx, top, fill):
    l, t, r, b = draw.textbbox((0, 0), text, font=font)
    draw.text((cx - (r - l) / 2 - l, top - t), text, font=font, fill=fill)
    return b - t


def make_banner(w, h, path):
    W, H = w * SS, h * SS
    img = Image.new("RGB", (W, H), WHITE)
    d = ImageDraw.Draw(img)

    band_top = int(H * 0.50)
    d.rectangle([0, band_top, W, H], fill=BLUE)

    icon = int(H * 0.40)
    draw_icon(d, (W - icon) // 2, int(H * 0.05), icon)

    f1 = fit_font(FONT_BLACK, "Student Activity", int(H * 0.17))
    f2 = fit_font(FONT_BOLD, "for MasterStudy LMS", int(H * 0.105))

    y = band_top + int(H * 0.085)
    y += centred(d, f1, "Student Activity", W // 2, y, WHITE) + int(H * 0.055)
    centred(d, f2, "for MasterStudy LMS", W // 2, y, (214, 223, 255))

    img.resize((w, h), Image.LANCZOS).save(path, "PNG", optimize=True)
    print("  {:24s} {}x{}".format(os.path.basename(path), w, h))


def make_icon(size, path):
    S = size * SS
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    rr(d, [0, 0, S, S], S * 0.22, BLUE)
    # Inset the book so the rounded-square badge frames it.
    draw_icon(d, int(S * 0.14), int(S * 0.13), int(S * 0.72), reverse=True)
    img.resize((size, size), Image.LANCZOS).save(path, "PNG", optimize=True)
    print("  {:24s} {}x{}".format(os.path.basename(path), size, size))


if __name__ == "__main__":
    make_banner(1544, 500, os.path.join(OUT, "banner-1544x500.png"))
    make_banner(772, 250, os.path.join(OUT, "banner-772x250.png"))
    make_icon(256, os.path.join(OUT, "icon-256x256.png"))
    make_icon(128, os.path.join(OUT, "icon-128x128.png"))
