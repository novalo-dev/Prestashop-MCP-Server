#!/usr/bin/env python3
"""Generate the README badges as local SVG files.

Run:  npm i simple-icons && python3 .github/badges/generate.py


The badges are generated and committed rather than served by shields.io, so
the README depends on nothing outside the repository. Edit the BADGES table at
the bottom, then run the script from the repository root.
"""
import os
import re
from xml.sax.saxutils import escape

from PIL import ImageFont

ICONS = os.environ.get("SIMPLE_ICONS", "node_modules/simple-icons/icons")
FONT_PATH = os.environ.get(
    "BADGE_FONT", "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
FONT_SIZE = 11
PAD = 6       # horizontal padding either side of a text run
GAP = 4       # gap between the logo and the label
LOGO = 14     # logo square side
H = 20

_font = ImageFont.truetype(FONT_PATH, FONT_SIZE)


def width_of(text):
    return _font.getlength(text)


def logo_group(slug, colour, x):
    """One simple-icons glyph, recoloured and scaled onto the badge height."""
    with open(f"{ICONS}/{slug}.svg", encoding="utf-8") as fh:
        d = re.search(r'<path[^>]*\sd="([^"]+)"', fh.read()).group(1)
    scale = LOGO / 24.0           # simple-icons are drawn on a 24x24 grid
    y = (H - LOGO) / 2.0
    return (f'<g transform="translate({x:.1f} {y:.1f}) scale({scale:.5f})">'
            f'<path fill="{colour}" d="{d}"/></g>')


def text_node(x, label, colour):
    """Text plus its one-pixel drop shadow, both centred on x."""
    t = escape(label)
    return (f'<text x="{x:.1f}" y="15" fill="#010101" fill-opacity=".3" '
            f'text-anchor="middle">{t}</text>'
            f'<text x="{x:.1f}" y="14" fill="{colour}" '
            f'text-anchor="middle">{t}</text>')


def badge(label, message, colour, logo=None, logo_colour="#fff",
          label_bg="#555", message_text="#fff"):
    label_w = width_of(label)
    msg_w = width_of(message)
    logo_w = (LOGO + GAP) if logo else 0

    left = PAD + logo_w + label_w + PAD
    right = PAD + msg_w + PAD
    total = round(left + right)

    label_x = PAD + logo_w + label_w / 2.0
    msg_x = left + right / 2.0
    title = f"{label}: {message}"

    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{total}" height="{H}" '
        f'role="img" aria-label="{escape(title)}">'
        f'<title>{escape(title)}</title>'
        f'<linearGradient id="s" x2="0" y2="100%">'
        f'<stop offset="0" stop-color="#bbb" stop-opacity=".1"/>'
        f'<stop offset="1" stop-opacity=".1"/></linearGradient>'
        f'<clipPath id="r"><rect width="{total}" height="{H}" rx="3" fill="#fff"/></clipPath>'
        f'<g clip-path="url(#r)">'
        f'<rect width="{left:.1f}" height="{H}" fill="{label_bg}"/>'
        f'<rect x="{left:.1f}" width="{right:.1f}" height="{H}" fill="{colour}"/>'
        f'<rect width="{total}" height="{H}" fill="url(#s)"/>'
        f'</g>'
        + (logo_group(logo, logo_colour, PAD) if logo else '')
        + f'<g font-family="Verdana,DejaVu Sans,Geneva,sans-serif" font-size="11">'
        + text_node(label_x, label, "#fff")
        + text_node(msg_x, message, message_text)
        + '</g></svg>'
    )


BADGES = {
    "prestashop": dict(label="PrestaShop", message="8.0 to 9.x", colour="#DF0067",
                       logo="prestashop"),
    "php":        dict(label="PHP", message="8.1+", colour="#777BB4", logo="php"),
    "mcp":        dict(label="MCP", message="server", colour="#111111",
                       logo="modelcontextprotocol"),
    "multistore": dict(label="multistore", message="native", colour="#2E7D32"),
    "license":    dict(label="license", message="AFL-3.0", colour="#007EC6"),
    "version":    dict(label="version", message="1.0.0", colour="#4C1"),
    "prs":        dict(label="PRs", message="welcome", colour="#4C1"),
    "docs":       dict(label="docs", message="wiki", colour="#007EC6"),
}

out = os.path.join(os.path.dirname(os.path.abspath(__file__)))
os.makedirs(out, exist_ok=True)
for name, kw in BADGES.items():
    svg = badge(**kw)
    with open(f"{out}/{name}.svg", "w", encoding="utf-8") as fh:
        fh.write(svg)
    print(f"{name}.svg  {len(svg)} bytes")
