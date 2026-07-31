#!/usr/bin/env python3
"""
Generate favicon.ico (and the matching SVG / touch icon) from the Mass Open
brand lockup in the top-left of the site.

The nav renders "Mass Open" with "Mass" in the accent blue and "Open" in white.
At favicon sizes the full wordmark is unreadable — 16x16 gives each of nine
letters under two pixels — so the icon keeps the *treatment* rather than the
letters: an "MO" monogram, M in accent blue and O in white, on the site's dark
navy. That is legible down to 16px and still reads as the same brand.

Run from the repo root:

    python3 tools/make-favicon.py
"""

import pathlib

from PIL import Image, ImageDraw, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent

# Straight from assets/css/style.css.
BG = (5, 8, 22, 255)          # --bg      #050816
ACCENT = (90, 169, 255, 255)  # --accent  #5aa9ff
WHITE = (255, 255, 255, 255)
BORDER = (90, 169, 255, 110)  # ~rgba(90,169,255,0.4), the nav's border colour

# Liberation Sans is metric-compatible with Arial/Helvetica, so it matches the
# site's "Helvetica Neue, Helvetica, Arial" stack.
FONT_PATH = "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"

# Sizes that go inside the .ico. 16/32/48 are what browsers and Windows
# actually ask for; the larger ones keep it sharp on high-DPI tabs.
ICO_SIZES = [16, 32, 48, 64, 128, 256]

SS = 8  # Supersampling factor — draw big, downsample for clean edges.


def render(size: int) -> Image.Image:
    """Render the monogram at `size` px, supersampled then reduced."""
    big = size * SS
    img = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # Rounded square background. Below 32px the border and corner radius eat
    # pixels the letters need, so they shrink away.
    radius = int(big * (0.18 if size >= 32 else 0.12))
    draw.rounded_rectangle([0, 0, big - 1, big - 1], radius=radius, fill=BG)

    if size >= 32:
        inset = max(1, int(big * 0.035))
        draw.rounded_rectangle(
            [inset, inset, big - 1 - inset, big - 1 - inset],
            radius=int(radius * 0.85),
            outline=BORDER,
            width=max(1, int(big * 0.022)),
        )

    # Fit "MO" to ~72% of the width, letter-spaced like the nav brand. The
    # proportion is deliberately the same at every size: browsers pick the
    # 32px frame for a 16pt tab on high-DPI screens, so a mark tuned
    # differently per size would look inconsistent between machines rather
    # than between contexts. 0.72 leaves the 16px frame breathing room
    # without shrinking the O into a blob.
    target = big * 0.72
    tracking = big * 0.02

    font_size = int(big * 0.5)
    for _ in range(48):
        font = ImageFont.truetype(FONT_PATH, font_size)
        w_m = draw.textlength("M", font=font)
        w_o = draw.textlength("O", font=font)
        if w_m + tracking + w_o >= target:
            break
        font_size += max(1, int(big * 0.01))

    font = ImageFont.truetype(FONT_PATH, font_size)
    w_m = draw.textlength("M", font=font)
    w_o = draw.textlength("O", font=font)
    total = w_m + tracking + w_o

    # Centre on the cap-height box rather than the font's full line box, so the
    # monogram sits optically centred instead of riding high.
    bbox = font.getbbox("MO")
    x = (big - total) / 2
    y = (big - (bbox[3] - bbox[1])) / 2 - bbox[1]

    draw.text((x, y), "M", font=font, fill=ACCENT)
    draw.text((x + w_m + tracking, y), "O", font=font, fill=WHITE)

    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    frames = [render(s) for s in ICO_SIZES]

    ico = ROOT / "favicon.ico"
    # Pillow writes every supplied size into the .ico when the base image is
    # the largest and `sizes` lists the rest.
    frames[-1].save(ico, format="ICO", sizes=[(s, s) for s in ICO_SIZES])
    print(f"wrote {ico.relative_to(ROOT)}  ({ico.stat().st_size:,} bytes, "
          f"{len(ICO_SIZES)} sizes: {', '.join(str(s) for s in ICO_SIZES)})")

    touch = ROOT / "assets" / "apple-touch-icon.png"
    render(180).save(touch, format="PNG")
    print(f"wrote {touch.relative_to(ROOT)}  ({touch.stat().st_size:,} bytes)")

    # Keep the SVG saying the same thing as the .ico, so browsers that prefer
    # one over the other still show the same mark.
    svg = ROOT / "assets" / "favicon.svg"
    svg.write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">\n'
        '  <rect width="64" height="64" rx="11.5" fill="#050816"/>\n'
        '  <rect x="2.2" y="2.2" width="59.6" height="59.6" rx="9.8" fill="none"\n'
        '        stroke="#5aa9ff" stroke-opacity="0.43" stroke-width="1.4"/>\n'
        '  <text x="32" y="43.5" text-anchor="middle"\n'
        '        font-family="Helvetica Neue, Helvetica, Arial, sans-serif"\n'
        '        font-weight="700" font-size="30" letter-spacing="1.3">\n'
        '    <tspan fill="#5aa9ff">M</tspan><tspan fill="#ffffff">O</tspan>\n'
        '  </text>\n'
        '</svg>\n'
    )
    print(f"wrote {svg.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
