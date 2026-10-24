#!/usr/bin/env python3
import html
import json
import re
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
    image = Image.new('RGB', (1600, 550), '#000000')
    draw = ImageDraw.Draw(image)
    font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf'
    font = ImageFont.truetype(font_path, 25) if Path(font_path).exists() else ImageFont.load_default(size=25)
    small = ImageFont.truetype(font_path, 20) if Path(font_path).exists() else ImageFont.load_default(size=20)
    paths = [
        ('LOCAL PROTECTION', ['FreePBX', 'The Puddle', 'Revert'],
         'Local history; no Stream or Pond. Native undo is not yet executable.'),
        ('INDEPENDENT PROTECTION', ['The Puddle', 'The Stream', 'The Pond'],
         'Capture, authenticated publication and read-back: demonstrated live.'),
        ('DISASTER RECOVERY', ['The Pond', 'Authorised reconstruction', 'Replacement FreePBX'],
         'Recovery objective; provisioning, credentials and telephony still need proof.'),
    ]
    for index, (label, nodes, detail) in enumerate(paths):
        top = index * 180
        draw.line((25, top + 10, 1575, top + 10), fill='#808080')
        draw.text((25, top + 28), label, font=small, fill='#c29200')
        for position, node in enumerate(nodes):
            left = [25, 525, 1120][position]
            draw.text((left, top + 78), node, font=font, fill='white')
            if position < 2:
                arrow = [410, 1025][position]
                draw.line((arrow, top + 95, arrow + 55, top + 95), fill='#808080', width=2)
                draw.polygon([(arrow + 55, top + 95), (arrow + 45, top + 89), (arrow + 45, top + 101)], fill='#808080')
        draw.text((25, top + 130), detail, font=small, fill='#dedede')
    image.save(OUT / 'assets/reconstruction.png')
    mobile_image = Image.new('RGB', (720, 940), '#000000')
    mobile_draw = ImageDraw.Draw(mobile_image)
    notes = [('Local history. No Stream or Pond.', 'Native undo not yet executable.'),
             ('Authenticated publication and read-back.', 'Demonstrated on two servers.'),
             ('Replacement recovery objective.', 'Not a completed live recovery test.')]
    for index, (label, nodes, detail) in enumerate(paths):
        top = index * 310
        mobile_draw.line((18, top + 10, 702, top + 10), fill='#808080')
        mobile_draw.text((18, top + 30), label, font=small, fill='#c29200')
        for position, node in enumerate(nodes):
            mobile_draw.text((45, top + 76 + position * 49), node, font=font, fill='white')
            if position < 2:
                arrow_top = top + 107 + position * 49
                mobile_draw.polygon([(25, arrow_top + 10), (20, arrow_top), (30, arrow_top)], fill='#808080')
        for line_index, line in enumerate(notes[index]):
            mobile_draw.text((18, top + 238 + line_index * 29), line, font=small, fill='#dedede')
    mobile_image.save(OUT / 'assets/reconstruction-mobile.png')

graph()
def format_text(text):
    return re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', html.escape(text))

navigation = [('why-respawn', 'Why Respawn?'), ('overview', 'Overview'), ('how-it-works', 'How It Works'), ('one-click', 'Respawn'),
              ('operators', 'Operators'), ('providers', 'Developers'), ('faqs', 'FAQs'), ('status', 'Status')]
for index, (slug, page) in enumerate(PAGES.items()):
    filename = 'index.html' if slug == 'index' else slug + '.html'
    url = 'https://respawn.in1.click/' + ('' if slug == 'index' else filename)
    title = html.escape(page['title'])
    description = html.escape(page['description'], quote=True)
    nav = ''.join(f'<a href="{key}.html"' + (' aria-current="page"' if key == slug else '') + f'>{label}</a>' for key, label in navigation)
    sections = ''.join(f'<section id="{re.sub(r"[^a-z0-9]+", "-", heading.lower()).strip("-")}" class="band {"light" if count % 2 == 0 else "dark"}"><div class="container editorial layout-{count % 3}"><div><span class="number">{count + 1:02d} / RESPAWN</span><h2>{html.escape(heading)}</h2></div><p>{html.escape(body)}</p></div></section>' for count, (heading, body) in enumerate(page['sections']))
    for count, argument in enumerate(page.get('essay', [])):
        paragraphs = ''.join(f'<p>{format_text(paragraph)}</p>' for paragraph in argument['paragraphs'])
        path = '<p class="argument-path" aria-label="Independent protection: The Puddle through The Stream to The Pond"><span>The Puddle</span><span aria-hidden="true">&#8594;</span><span>The Stream</span><span aria-hidden="true">&#8594;</span><span>The Pond</span></p>' if count == 1 else ''
        sections += f'<section class="band argument" aria-labelledby="argument-{count}"><div class="container argument-copy"><span class="number">{count + 1:02d} / WHY RESPAWN</span><h2 id="argument-{count}">{html.escape(argument["heading"])}</h2>{path}{paragraphs}</div></section>'
    faqs = ''.join(f'<details><summary>{html.escape(question)}</summary><p>{html.escape(answer)}</p></details>' for question, answer in page.get('faqs', []))
    if faqs:
        sections += '<section class="band light"><div class="container faq-list">' + faqs + '</div></section>'
    if slug == 'providers':
        sections += '<section class="band light"><div class="container resource-links"><a href="contracts/provider-rpc.v2.schema.json">Stream RPC schema</a><a href="contracts/v2.schema.json">Executable reconstruction schema</a><a href="contracts/routing-control.v1.schema.json">Routing-control schema</a></div></section>'
    welcome_html = ''
    if 'welcome' in page:
        welcome = page['welcome']
        paragraphs = []
        for paragraph in welcome['paragraphs']:
            emphasis = ' welcome-statement' if re.fullmatch(r'\*\*[^*]+\*\*', paragraph) else ''
            if paragraph == '**And it works.**':
                emphasis += ' welcome-affirmation'
            rendered = format_text(paragraph)
            paragraphs.append(f'<p class="welcome-paragraph{emphasis}">{rendered}</p>')
        welcome_html = '<section class="band light welcome" id="welcome" aria-labelledby="welcome-heading"><div class="container welcome-layout"><div class="welcome-heading"><span class="number">FROM THE PEOPLE BUILDING RESPAWN</span><h2 id="welcome-heading">' + html.escape(welcome['heading']) + '</h2></div><div class="welcome-copy">' + ''.join(paragraphs) + '<a class="welcome-next" href="why-respawn.html">Why we\'re building Respawn <span aria-hidden="true">&#8594;</span></a></div></div></section>'
    operations_html = ''
    if 'operations' in page:
        operations = ''.join(f'<article class="operation"><p class="number">{html.escape(prompt)}</p><h3>{html.escape(name)}</h3><p>{html.escape(body)}</p><a href="{html.escape(link, quote=True)}">Explore {html.escape(name)} <span aria-hidden="true">&#8594;</span></a></article>' for name, prompt, body, link in page['operations'])
        operations_html = '<section id="three-operations" class="band dark" aria-labelledby="operations-heading"><div class="container"><span class="number">THREE LEVELS OF FAILURE</span><h2 id="operations-heading">Three operations. Different jobs.</h2><div class="operations">' + operations + '</div></div></section>'
    graph_html = '<section id="architecture" class="band dark"><div class="container"><h2>Three paths, not one recovery pipeline.</h2><figure class="build-graph"><picture><source media="(max-width:700px)" srcset="assets/reconstruction-mobile.png"><img src="assets/reconstruction.png" width="1600" height="550" alt="Local protection: FreePBX to The Puddle to Revert, with no Stream or Pond. Independent protection: The Puddle through The Stream to The Pond, demonstrated live. Disaster recovery objective: The Pond through authorised reconstruction to replacement FreePBX, not yet live-tested."></picture><figcaption>Regenerate reconciles a validated blueprint on the existing PBX. Native executable Revert, Regenerate and full Respawn recovery remain unproven.</figcaption></figure></div></section>' if slug in ['index', 'how-it-works'] else ''
    milestone_html = ''
    if 'milestone' in page:
        milestone = page['milestone']
        milestone_html = f'''<section class="band blue milestone" id="live-milestone" aria-labelledby="milestone-heading"><div class="container"><span class="number">0.1.0 / OPERATOR-CONFIRMED LIVE TEST / {html.escape(milestone['date']).upper()}</span><h2 id="milestone-heading">{html.escape(milestone['heading'])}</h2><p>{html.escape(milestone['body'])}</p><dl class="evidence"><div><dt>Configuration</dt><dd>Complete</dd></div><div><dt>Independent retention</dt><dd>Read-back verified</dd></div><div><dt>Credentials</dt><dd>Not configured</dd></div><div><dt>Recovery readiness</dt><dd>False</dd></div></dl><p class="evidence-boundary">{html.escape(milestone['boundary'])}</p><a href="status.html">Review the evidence and remaining gates &#8594;</a></div></section>'''
    code = ''
    if slug == 'providers':
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
    if slug == 'operators':
        code = '''<section class="band dark"><div class="container editorial layout-code"><div><span class="number">EXPLICIT CAPTURE-ONLY WORKFLOW</span><h2>Capture. Inspect. Publish. Verify.</h2><p>Replace REVISION_ID with the captured revision. Review completeness and the object list before publication. A successful retained capture still reports recoveryReady: false.</p></div><pre tabindex="0"><code>fwconsole respawn revision-capture
fwconsole respawn revision-list
fwconsole respawn revision-show --revision-id=REVISION_ID
fwconsole respawn revision-publish --revision-id=REVISION_ID
fwconsole respawn revision-verify --revision-id=REVISION_ID</code></pre></div></section>'''
    resources_html = ''
    if slug in ['index', 'operators', 'providers', 'status']:
        resources_html = '''<section class="band light"><div class="container resources"><div><span class="number">READ / REVIEW / CONTRIBUTE</span><h2>Help prove the next milestone.</h2><p>Review the code, reproduce supported cases in an authorised lab and contribute version-specific module knowledge. Report gaps without sharing credentials or private configuration.</p></div><nav class="resource-links" aria-label="Technical documentation"><a href="https://github.com/kierknoby/respawn">GPLv3+ source on GitHub</a><a href="https://github.com/kierknoby/respawn/blob/0-1-1_Dev/docs/puddle-capture.md">Capture and operator guide</a><a href="https://github.com/kierknoby/respawn/blob/0-1-1_Dev/pond/README.md">Standalone Pond installation</a><a href="https://github.com/kierknoby/respawn/blob/0-1-1_Dev/docs/capabilities-and-roadmap.md">Detailed capabilities and roadmap</a><a href="https://github.com/kierknoby/respawn/blob/0-1-1_Dev/docs/validation.md">Test evidence and limitations</a></nav></div></section>'''
    footer_links = ''.join(f'<a href="{("index.html" if key == "index" else key + ".html")}">{html.escape(value["title"])}</a>' for key, value in PAGES.items())
    output = f'''<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}{' | Respawn' if slug != 'index' else ' | FreePBX Configuration Protection And Recovery'}</title>
<meta name="description" content="{description}"><meta name="theme-color" content="#000000">
<link rel="canonical" href="{url}"><meta property="og:title" content="{title} | Respawn"><meta property="og:description" content="{description}"><meta property="og:type" content="website"><meta property="og:url" content="{url}"><meta property="og:image" content="https://respawn.in1.click/assets/reconstruction.png">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body class="page-{slug}"><a class="skip" href="#main">Skip to content</a>
<div class="preview"><div class="container"><span>{VERSION} / WORKING PROTOTYPE</span><a href="status.html">Live configuration protection verified / Recovery execution experimental</a></div></div>
<header><div class="container navigation"><a class="brand" href="index.html" aria-label="Respawn home"><span aria-hidden="true" class="mark">R</span> RESPAWN</a><button class="menu" type="button" aria-expanded="false" aria-controls="navigation" aria-label="Open navigation" hidden>&#9776;</button><nav id="navigation" aria-label="Main navigation">{nav}</nav></div></header>
<main id="main"><section class="hero"><div class="container"><p class="eyebrow">{html.escape(page['eyebrow'])}</p><h1>{title}</h1><p class="standfirst">{html.escape(page['intro'])}</p><p class="lede">{html.escape(page['description'])}</p><div class="hero-links"><a class="button primary" href="{'#welcome' if slug == 'index' else '#argument-0' if slug == 'why-respawn' else 'operators.html'}">{html.escape(page['welcome']['heading']) if slug == 'index' else 'Read the case' if slug == 'why-respawn' else 'Operator guide'} <span aria-hidden="true">&#8595;</span></a><a class="text-link" href="https://github.com/kierknoby/respawn">Explore the GPLv3+ source <span aria-hidden="true">&#8599;</span></a></div></div></section>
{welcome_html}{operations_html}{graph_html}{milestone_html}{sections}{code}{resources_html}<section class="band blue"><div class="container closing"><div><span class="number">PROTECT / VERIFY / RECOVER</span><h2>Keep the evidence with the operation.</h2><p>Configuration protection is demonstrated. Complete recovery still needs proof.</p></div><a class="button plain" href="lifecycle.html">Read the lifecycle &#8594;</a></div></section></main>
<footer><div class="container"><div class="footer-grid"><div><a class="brand" href="index.html">RESPAWN</a><p>Open-source FreePBX configuration protection.<br>Local history. Independent retention. Recovery on demand.</p><p>Kieran Knowles-Byrne / @kierknoby<br>FreePBX UK</p></div><nav aria-label="Footer">{footer_links}</nav><div><h2>Project Boundaries</h2><p>Provider-neutral. Self-hosted or managed retention. Recovery services remain separate integrations.</p><p>Experimental. No warranty. No public management console.</p></div></div><div class="footer-bottom"><span>GPLv3+ / 2026 Respawn contributors</span><span>Engineering assisted by GitHub Copilot</span><a href="#main">Back to top &#8593;</a></div></div></footer></body></html>'''
    (OUT / filename).write_text(output)

(OUT / 'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: https://respawn.in1.click/sitemap.xml\n')
locations = ['https://respawn.in1.click/' + ('' if slug == 'index' else slug + '.html') for slug in PAGES]
(OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' + ''.join('<url><loc>' + escape(url) + '</loc></url>' for url in locations) + '</urlset>\n')
not_found = f'''<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page Not Found | Respawn</title><meta name="description" content="The requested Respawn project page could not be found. Return to the project overview or browse the documentation.">
<meta name="robots" content="noindex,follow"><meta name="theme-color" content="#000000"><meta property="og:type" content="website"><meta property="og:title" content="Page Not Found | Respawn"><meta property="og:description" content="Return to the Respawn FreePBX reconstruction project."><meta property="og:image" content="https://respawn.in1.click/assets/reconstruction.png">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body><a class="skip" href="#main">Skip to content</a>
<div class="preview"><div class="container"><span>{VERSION} / WORKING PROTOTYPE</span><a href="status.html">Project status and evidence</a></div></div>
<header><div class="container navigation"><a class="brand" href="index.html" aria-label="Respawn home"><span aria-hidden="true" class="mark">R</span> RESPAWN</a><button class="menu" type="button" aria-expanded="false" aria-controls="navigation" aria-label="Open navigation" hidden>&#9776;</button><nav id="navigation" aria-label="Main navigation">{nav}</nav></div></header>
<main id="main"><section class="hero"><div class="container"><p class="eyebrow">RESPAWN / PAGE NOT FOUND</p><h1>404 / Page not found</h1><p class="standfirst">That route is not in this blueprint.</p><p class="lede">The address may be out of date. Return to the project overview, reconstruction guide or current development status.</p><div class="hero-links"><a class="button primary" href="index.html">Project home <span aria-hidden="true">&#8594;</span></a><a class="text-link" href="how-it-works.html">How reconstruction works</a><a class="text-link" href="status.html">Development status</a></div></div></section></main>
<footer><div class="container"><div class="footer-grid"><div><a class="brand" href="index.html">RESPAWN</a><p>Open-source FreePBX Connectivity module.<br>Blueprint-led reconstruction. Provider-controlled takeover.</p></div><nav aria-label="Footer">{footer_links}</nav><div><h2>Project boundaries</h2><p>Experimental project. No real FreePBX reconstruction or live takeover is verified.</p></div></div><div class="footer-bottom"><span>GPLv3+ / 2026 Respawn contributors</span><a href="#main">Back to top &#8593;</a></div></div></footer></body></html>'''
(OUT / '404.html').write_text(not_found)
(OUT / '.htaccess').write_text('ErrorDocument 404 /404.html\n')
print(f'Built {len(PAGES)} static pages in {OUT}')