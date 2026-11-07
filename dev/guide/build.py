#!/usr/bin/env python3
# Builds the user guide PDF that PrestaShop Addons asks for, docs/readme_<iso>.pdf
# in the module zip, from docs/guide/<iso>.md and its images.
#
# Usage: python3 dev/guide/build.py <iso> <output.pdf>
#        python3 dev/guide/build.py en build/readme_en.pdf
#
# Needs the Python packages markdown, playwright and pypdf, and Playwright's
# Chromium.
# The Markdown stays readable on GitHub: GitHub's own callouts (> [!NOTE]) and
# heading ids ({#id}) are what this turns into boxes and clickable links.
import base64, html, io, os, re, sys

import markdown
import pypdf
from playwright.sync_api import sync_playwright

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
GUIDE = os.path.join(ROOT, 'docs', 'guide')

if len(sys.argv) != 3:
    sys.exit('Usage: python3 dev/guide/build.py <iso> <output.pdf>')
iso, output = sys.argv[1], os.path.abspath(sys.argv[2])
source = os.path.join(GUIDE, iso + '.md')
text = open(source, encoding='utf-8').read()

# The version on the cover is the one the zip carries.
version = re.search(r'<version><!\[CDATA\[([^\]]+)\]\]></version>', open(os.path.join(ROOT, 'config.xml'), encoding='utf-8').read()).group(1)

meta = {}
front = re.match(r'^---\n(.*?)\n---\n', text, re.S)
if front:
    for line in front.group(1).splitlines():
        key, _, value = line.partition(':')
        meta[key.strip()] = value.strip()
    text = text[front.end():]

body = markdown.markdown(text, extensions=['tables', 'attr_list', 'md_in_html'])


def figure(match):
    alt, src = html.unescape(match.group(1)), match.group(2)
    path = os.path.join(GUIDE, src)
    if not os.path.isfile(path):
        # A screenshot still to be taken: the PDF says so rather than failing.
        image = '<div class="placeholder">Screenshot to come: %s</div>' % html.escape(os.path.basename(src))
    else:
        raw = open(path, 'rb').read()
        # A PNG's width sits at bytes 16-20. An e-mail or a phone screen is
        # drawn smaller than a full back office page, as it is on screen.
        width = int.from_bytes(raw[16:20], 'big') / 10
        image = '<img src="data:image/png;base64,%s" style="width:%.0fmm" alt="">' % (base64.b64encode(raw).decode(), width)
    figure.count += 1

    return '<figure>%s<figcaption>Figure %d - %s</figcaption></figure>' % (image, figure.count, html.escape(alt))


figure.count = 0
body = re.sub(r'<p><img alt="([^"]*)" src="([^"]+)" ?/?></p>', figure, body)


# GitHub's callouts, > [!NOTE], > [!TIP] and > [!WARNING], become boxes.
body = re.sub(
    r'<blockquote>\s*<p>\[!(NOTE|TIP|WARNING)\]\s*(.*?)</blockquote>',
    lambda m: '<div class="callout %s"><p>%s</div>' % (m.group(1).lower(), m.group(2)),
    body,
    flags=re.S,
)

# The circled numbers of the text, as the badges drawn on the screenshots.
body = re.sub('[\u2460-\u2468]', lambda m: '<span class="b">%d</span>' % (ord(m.group(0)) - 0x245F), body)

# Chapters, numbered, and the contents page that links to each of them.
chapters = []


def chapter(match):
    attrs, title = match.group(1), match.group(2)
    anchor = re.search(r'id="([^"]+)"', attrs)
    chapters.append((anchor.group(1) if anchor else '', re.sub('<[^>]+>', '', title)))

    return '<h2%s><span class="n">%d</span>%s</h2>' % (attrs, len(chapters), title)


body = re.sub(r'<h2([^>]*)>(.*?)</h2>', chapter, body)
contents = ''.join(
    '<li><a href="#%s"><span class="num">%d</span>%s</a></li>' % (anchor, i, html.escape(title))
    for i, (anchor, title) in enumerate(chapters, 1)
)

logo = base64.b64encode(open(os.path.join(ROOT, 'logo.png'), 'rb').read()).decode()
page = open(os.path.join(os.path.dirname(__file__), 'template.html'), encoding='utf-8').read()
for key, value in {
    '{{lang}}': iso,
    '{{logo}}': logo,
    '{{title}}': html.escape(meta.get('title', 'User guide')),
    '{{subtitle}}': html.escape(meta.get('subtitle', '')),
    '{{version}}': html.escape(version),
    '{{contents}}': contents,
    '{{body}}': body,
}.items():
    page = page.replace(key, value)

os.makedirs(os.path.dirname(output), exist_ok=True)
footer = (
    '<div style="width:100%;font:8px Arial,sans-serif;color:#9a9d9f;padding:0 18mm;display:flex;justify-content:space-between">'
    "<span>O'So MCP Server " + html.escape(version) + ' - ' + html.escape(meta.get('title', 'User guide')) + '</span>'
    '<span class="pageNumber"></span></div>'
)
cover = re.sub(r'</section>.*</body>', '</section></body>', page, flags=re.S)
with sync_playwright() as p:
    browser = p.chromium.launch()
    tab = browser.new_page()
    # The margins are the template's @page rules, so the cover can bleed.
    tab.set_content(page, wait_until='load')
    tab.pdf(path=output, print_background=True, display_header_footer=True,
            header_template='<div></div>', footer_template=footer, prefer_css_page_size=True)
    # Chromium prints the footer on every page, the cover included: the cover
    # is printed again on its own, without it, and put in place of the first.
    tab.set_content(cover, wait_until='load')
    plain = tab.pdf(print_background=True, prefer_css_page_size=True)
    browser.close()

writer = pypdf.PdfWriter(clone_from=output)
writer.insert_page(pypdf.PdfReader(io.BytesIO(plain)).pages[0], 0)
writer.remove_page(1)
writer.add_metadata({'/Title': "O'So MCP Server " + version + ' - ' + meta.get('title', 'User guide'), '/Author': 'Novalo'})
with open(output, 'wb') as handle:
    writer.write(handle)
print(output)
