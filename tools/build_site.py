#!/usr/bin/env python3
import html
import json
from pathlib import Path
import shutil
import xml.etree.ElementTree as ET
from xml.sax.saxutils import escape
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
VERSION = ET.parse(ROOT / 'module/module.xml').getroot().findtext('version')
OUT = ROOT / 'build/site'
PAGES = json.loads((ROOT / 'website/pages.json').read_text())
OUT.mkdir(parents=True, exist_ok=True)
shutil.copytree(ROOT / 'website/assets', OUT / 'assets', dirs_exist_ok=True)
shutil.copytree(ROOT / 'contracts', OUT / 'contracts', dirs_exist_ok=True)

def graph():
    image = Image.new('RGB', (1600, 270), '#000000')
    draw = ImageDraw.Draw(image)
    font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf'
    font = ImageFont.truetype(font_path, 20) if Path(font_path).exists() else ImageFont.load_default(size=20)
    small = ImageFont.truetype(font_path, 15) if Path(font_path).exists() else ImageFont.load_default(size=15)
    items = [('01', 'EXPORT', 'Supported module state'), ('02', 'BLUEPRINT', 'Typed operations + references'),
             ('03', 'RECONSTRUCT', 'Dependencies + read-back'), ('04', 'VERIFY', 'Configuration + calls + audio')]
    for index, (number, title, detail) in enumerate(items):
        left = 30 + index * 395
        draw.line((left, 50, left + 330, 50), fill='#808080', width=1)
        draw.text((left, 75), number, font=font, fill='#c29200')
        draw.text((left, 120), title, font=font, fill='white')
        draw.text((left, 168), detail, font=small, fill='#dedede')
        if index < 3:
            draw.line((left + 340, 130, left + 375, 130), fill='#808080', width=2)
            draw.polygon([(left + 375, 130), (left + 367, 125), (left + 367, 135)], fill='#808080')
    image.save(OUT / 'assets/reconstruction.png')
    mobile_image = Image.new('RGB', (720, 220), '#000000')
    mobile_draw = ImageDraw.Draw(mobile_image)
    for index, (number, title, detail) in enumerate(items):
        left = 12 + (index % 2) * 360
        top = (index // 2) * 110
        mobile_draw.line((left, top + 8, left + 320, top + 8), fill='#808080')
        mobile_draw.text((left, top + 25), number, font=font, fill='#c29200')
        mobile_draw.text((left + 48, top + 25), title, font=font, fill='white')
        mobile_draw.text((left, top + 60), detail, font=small, fill='#dedede')
    mobile_image.save(OUT / 'assets/reconstruction-mobile.png')

graph()
navigation = [('overview', 'Overview'), ('how-it-works', 'How It Works'), ('one-click', 'Respawn'),
              ('operators', 'Operators'), ('providers', 'Developers'), ('faqs', 'FAQs'), ('status', 'Status')]
for index, (slug, page) in enumerate(PAGES.items()):
    filename = 'index.html' if slug == 'index' else slug + '.html'
    url = 'https://respawn.in1.click/' + ('' if slug == 'index' else filename)
    title = html.escape(page['title'])
    description = html.escape(page['description'], quote=True)
    nav = ''.join(f'<a href="{key}.html"' + (' aria-current="page"' if key == slug else '') + f'>{label}</a>' for key, label in navigation)
    sections = ''.join(f'<section class="band {"light" if count % 2 == 0 else "dark"}"><div class="container editorial layout-{count % 3}"><div><span class="number">{count + 1:02d} / RESPAWN</span><h2>{html.escape(heading)}</h2></div><p>{html.escape(body)}</p></div></section>' for count, (heading, body) in enumerate(page['sections']))
    faqs = ''.join(f'<details><summary>{html.escape(question)}</summary><p>{html.escape(answer)}</p></details>' for question, answer in page.get('faqs', []))
    if faqs:
        sections += '<section class="band light"><div class="container faq-list">' + faqs + '</div></section>'
    if slug == 'providers':
        sections += '<section class="band light"><div class="container"><a href="contracts/v2.schema.json">Download the executable reconstruction v2 schema</a> / <a href="contracts/v1.schema.json">Provider and legacy contract vocabulary</a></div></section>'
    graph_html = '<figure class="container build-graph"><picture><source media="(max-width:700px)" srcset="assets/reconstruction-mobile.png"><img src="assets/reconstruction.png" width="1600" height="270" alt="Export supported module state, retain typed blueprint operations, reconstruct in dependency order, then verify configuration and real calls."></picture><figcaption>RECONSTRUCTION CONTRACT / LIVE TAKEOVER NOT YET VERIFIED</figcaption></figure>' if slug == 'index' else ''
    code = ''
    if slug in ['index', 'how-it-works', 'providers', 'operators']:
                code = '''<section class="band gold"><div class="container editorial layout-code"><div><span class="number">THE COMPLETE SELECTED CHAIN</span><h2>Users. Devices. Destinations.</h2><p>Reconstruct users before fixed PJSIP devices, then groups in dependency order. Original secret references and a final apply boundary belong to the build.</p></div><pre tabindex="0"><code>{
    "id": "ringgroup:600",
    "adapter": "ringgroups.v1",
    "schemaVersion": 1,
    "object": {"type": "ringgroup", "identity": "600"},
    "action": "ensure",
    "dependsOn": ["pjsip:1001", "ringgroup:601"],
    "requiredCapabilities": ["ringgroups.v1"],
    "preconditions": ["absent_or_equivalent", "no_foreign_owner"],
    "parameters": {"description": "Alpha primary", "strategy": "ringall",
        "timeout": 20, "members": ["1001"], "destination": "601"},
    "secretRefs": {},
    "postconditions": ["saved_state_matches", "secret_identity_preserved"],
    "verification": "readback.v1"
}
// excerpt, not a complete blueprint</code></pre></div></section>'''
    footer_links = ''.join(f'<a href="{("index.html" if key == "index" else key + ".html")}">{html.escape(value["title"])}</a>' for key, value in PAGES.items())
    output = f'''<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}{' | Respawn' if slug != 'index' else ' | Blueprint-led FreePBX Reconstruction'}</title>
<meta name="description" content="{description}"><meta name="theme-color" content="#000000">
<link rel="canonical" href="{url}"><meta property="og:title" content="{title} | Respawn"><meta property="og:description" content="{description}"><meta property="og:type" content="website"><meta property="og:url" content="{url}"><meta property="og:image" content="https://respawn.in1.click/assets/reconstruction.png">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body><a class="skip" href="#main">Skip to content</a>
<div class="preview"><div class="container"><span>{VERSION} / DEVELOPMENT SCAFFOLD</span><a href="status.html">No production takeover verified</a></div></div>
<header><div class="container navigation"><a class="brand" href="index.html" aria-label="Respawn home"><span aria-hidden="true" class="mark">R</span> RESPAWN</a><button class="menu" type="button" aria-expanded="false" aria-controls="navigation" aria-label="Open navigation" hidden>&#9776;</button><nav id="navigation" aria-label="Main navigation">{nav}</nav></div></header>
<main id="main"><section class="hero"><div class="container"><p class="eyebrow">{html.escape(page['eyebrow'])}</p><h1>{title}</h1><p class="standfirst">{html.escape(page['intro'])}</p><p class="lede">{html.escape(page['description'])}</p><div class="hero-links"><a class="button primary" href="one-click.html">One-Click Respawn <span aria-hidden="true">&#8599;</span></a><a class="text-link" href="https://github.com/kierknoby/respawn">Explore the GPLv3+ source <span aria-hidden="true">&#8599;</span></a></div></div>{graph_html}</section>
{sections}{code}<section class="band blue"><div class="container closing"><div><span class="number">BUILD / VERIFY / RECONCILE</span><h2>Keep the evidence with the operation.</h2><p>Configuration ready, routing prepared and live takeover are different claims.</p></div><a class="button plain" href="lifecycle.html">Read the lifecycle &#8594;</a></div></section></main>
<footer><div class="container"><div class="footer-grid"><div><a class="brand" href="index.html">RESPAWN</a><p>Open-source FreePBX Connectivity module.<br>Blueprint-led reconstruction. Provider-controlled takeover.</p><p>Kieran Knowles-Byrne / @kierknoby<br>FreePBX UK</p></div><nav aria-label="Footer">{footer_links}</nav><div><h2>Project Boundaries</h2><p>The Pond is one controller implementation. Provider services are separately licensed. Alternative connectors and controllers are welcome.</p><p>Experimental. No warranty. No public management console.</p></div></div><div class="footer-bottom"><span>GPLv3+ / 2026 Respawn contributors</span><span>Engineering assisted by GitHub Copilot</span><a href="#main">Back to top &#8593;</a></div></div></footer></body></html>'''
    (OUT / filename).write_text(output)

(OUT / 'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: https://respawn.in1.click/sitemap.xml\n')
locations = ['https://respawn.in1.click/' + ('' if slug == 'index' else slug + '.html') for slug in PAGES]
(OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' + ''.join('<url><loc>' + escape(url) + '</loc></url>' for url in locations) + '</urlset>\n')
not_found = f'''<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page Not Found | Respawn</title><meta name="description" content="The requested Respawn project page could not be found. Return to the project overview or browse the documentation.">
<meta name="robots" content="noindex,follow"><meta name="theme-color" content="#000000"><meta property="og:type" content="website"><meta property="og:title" content="Page Not Found | Respawn"><meta property="og:description" content="Return to the Respawn FreePBX reconstruction project."><meta property="og:image" content="https://respawn.in1.click/assets/reconstruction.png">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body><a class="skip" href="#main">Skip to content</a>
<div class="preview"><div class="container"><span>{VERSION} / DEVELOPMENT SCAFFOLD</span><a href="status.html">Development status</a></div></div>
<header><div class="container navigation"><a class="brand" href="index.html" aria-label="Respawn home"><span aria-hidden="true" class="mark">R</span> RESPAWN</a><button class="menu" type="button" aria-expanded="false" aria-controls="navigation" aria-label="Open navigation" hidden>&#9776;</button><nav id="navigation" aria-label="Main navigation">{nav}</nav></div></header>
<main id="main"><section class="hero"><div class="container"><p class="eyebrow">RESPAWN / PAGE NOT FOUND</p><h1>404 / Page not found</h1><p class="standfirst">That route is not in this blueprint.</p><p class="lede">The address may be out of date. Return to the project overview, reconstruction guide or current development status.</p><div class="hero-links"><a class="button primary" href="index.html">Project home <span aria-hidden="true">&#8594;</span></a><a class="text-link" href="how-it-works.html">How reconstruction works</a><a class="text-link" href="status.html">Development status</a></div></div></section></main>
<footer><div class="container"><div class="footer-grid"><div><a class="brand" href="index.html">RESPAWN</a><p>Open-source FreePBX Connectivity module.<br>Blueprint-led reconstruction. Provider-controlled takeover.</p></div><nav aria-label="Footer">{footer_links}</nav><div><h2>Project boundaries</h2><p>Experimental project. No real FreePBX reconstruction or live takeover is verified.</p></div></div><div class="footer-bottom"><span>GPLv3+ / 2026 Respawn contributors</span><a href="#main">Back to top &#8593;</a></div></div></footer></body></html>'''
(OUT / '404.html').write_text(not_found)
(OUT / '.htaccess').write_text('ErrorDocument 404 /404.html\n')
print(f'Built {len(PAGES)} static pages in {OUT}')