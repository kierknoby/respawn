#!/usr/bin/env python3
import functools
import hashlib
import http.server
from html.parser import HTMLParser
import json
import os
import re
from pathlib import Path
import tempfile
import threading
import zipfile
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit, unquote
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'artifacts/screenshots'
OUT.mkdir(parents=True, exist_ok=True)
pages = json.loads((ROOT / 'website/pages.json').read_text())
results = []
home_copy = ' '.join([pages['index']['intro'], pages['index']['description']]
    + [heading + ' ' + body for heading, body in pages['index']['sections']])
assert 'warm spare' in home_copy.lower()
assert all(operation in home_copy for operation in ['Revert', 'Regenerate', 'Respawn'])
assert all(stage in home_copy.lower() for stage in ['capture', 'replacement', 'routing', 'audio'])
public_copy = json.dumps(pages)
assert not any(reference in public_copy for reference in ['WHMCS', 'DOMAINTAINS', 'AI Dial-Tone'])
assert 'native freepbx revision execution is blocked' in public_copy.lower()
assert 'telecommunications' not in public_copy.lower()
assert '\u2014' not in public_copy
welcome = pages['index']['welcome']
assert welcome['heading'] == 'Respawn'
assert hashlib.sha256(json.dumps(welcome['paragraphs'], ensure_ascii=False, separators=(',', ':')).encode()).hexdigest() == '9104905f3d3a54ee584779cbd9d42bda4e922f5c3e73e5fbd1c61658ee2b2426', 'Keep the approved introduction wording, including the telecoms correction.'
welcome_paragraphs = [re.sub(r'\*\*(.+?)\*\*', r'\1', paragraph) for paragraph in welcome['paragraphs']]
assert pages['index']['milestone']['date'] == '24 October 2026'
assert 'No Stream or Pond' in pages['index']['operations'][0][2]
argument = pages['why-respawn']['essay']
assert hashlib.sha256(json.dumps(argument, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest() == 'fc26f813a8f77fffc08c68826e26af67cceeb54660d44ba0c38495d97f6dc02c', 'Preserve the supplied argument and telecoms terminology.'

class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        if not os.path.exists(self.translate_path(self.path)):
            body = (Path(self.directory) / '404.html').read_bytes()
            self.send_response(404)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        super().do_GET()

with tempfile.TemporaryDirectory(prefix='respawn-site-') as temporary:
    target = Path(temporary)
    version = ET.parse(ROOT / 'module/module.xml').getroot().findtext('version')
    with zipfile.ZipFile(ROOT / 'artifacts' / f'respawn-site-{version}.zip') as archive:
        assert 'assets/road.svg' not in archive.namelist()
        assert all(not name.startswith('/') and '..' not in Path(name).parts for name in archive.namelist())
        archive.extractall(target)
    assert (target / 'index.html').is_file(), 'ZIP must have index.html at upload root'
    assert (target / '.htaccess').read_text() == 'ErrorDocument 404 /404.html\n'
    ET.parse(target / 'sitemap.xml')
    assert 'https://respawn.in1.click/sitemap.xml' in (target / 'robots.txt').read_text()
    class ResourceParser(HTMLParser):
        def __init__(self):
            super().__init__()
            self.references = []
            self.identifiers = set()
            self.welcome_paragraphs = []
            self.welcome_text = None

        def handle_starttag(self, tag, attrs):
            values = dict(attrs)
            if 'id' in values:
                assert values['id'] not in self.identifiers, f'duplicate ID: {values["id"]}'
                self.identifiers.add(values['id'])
            if tag == 'p' and 'welcome-paragraph' in values.get('class', '').split():
                self.welcome_text = ''
            for name in ('href', 'src'):
                if name in values:
                    self.references.append(values[name])
            for source in values.get('srcset', '').split(','):
                if source.strip():
                    self.references.append(source.strip().split()[0])

        def handle_data(self, data):
            if self.welcome_text is not None:
                self.welcome_text += data

        def handle_endtag(self, tag):
            if tag == 'p' and self.welcome_text is not None:
                self.welcome_paragraphs.append(self.welcome_text)
                self.welcome_text = None

    documents = {}
    for document in target.glob('*.html'):
        parser = ResourceParser()
        parser.feed(document.read_text())
        documents[document.resolve()] = parser
    assert documents[(target / 'index.html').resolve()].welcome_paragraphs == welcome_paragraphs
    for document, parser in documents.items():
        for reference in parser.references:
            parsed = urlsplit(reference)
            if parsed.scheme or parsed.netloc:
                continue
            resource = (document.parent / unquote(parsed.path)).resolve() if parsed.path else document
            assert target.resolve() in resource.parents and resource.is_file(), f'broken package reference: {reference}'
            if parsed.fragment and resource in documents:
                assert unquote(parsed.fragment) in documents[resource].identifiers, f'broken fragment: {reference}'
    print('PASS: extracted cPanel package links, fragments, assets and approved welcome text')
    handler = functools.partial(QuietHandler, directory=temporary)
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    base = f'http://127.0.0.1:{server.server_port}'
    try:
        with sync_playwright() as playwright:
            environment = dict(os.environ)
            local_libraries = ROOT / 'build/browser-deps/root/usr/lib/x86_64-linux-gnu'
            if local_libraries.exists():
                environment['LD_LIBRARY_PATH'] = str(local_libraries) + (':' + environment['LD_LIBRARY_PATH'] if environment.get('LD_LIBRARY_PATH') else '')
            try:
                browser = playwright.chromium.launch(env=environment)
            except Exception as error:
                if os.environ.get('CI') == 'true':
                    raise
                details = str(error).splitlines()
                reason = next((line.split('error while loading shared libraries:', 1)[1].strip()
                    for line in details if 'error while loading shared libraries:' in line), details[0])
                (ROOT / 'artifacts/site-validation.json').write_text(json.dumps([
                    {'validation': 'static package links and assets', 'passed': True},
                    {'validation': 'browser', 'skipped': True, 'reason': reason},
                ], indent=2) + '\n')
                print(f'SKIP: Playwright browser checks unavailable in this environment: {reason}')
                raise SystemExit(0)
            for width, height, label in [(1440, 1000, 'desktop'), (390, 844, 'mobile'), (320, 740, 'narrow')]:
                context = browser.new_context(viewport={'width': width, 'height': height}, reduced_motion='reduce')
                for slug in pages:
                    page = context.new_page()
                    errors = []
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    path = 'index.html' if slug == 'index' else slug + '.html'
                    response = page.goto(base + '/' + path, wait_until='networkidle')
                    assert response.status == 200
                    assert page.locator('h1').count() == 1
                    assert page.locator('link[rel="canonical"]').get_attribute('href').startswith('https://respawn.in1.click/')
                    assert page.locator('meta[name="description"]').get_attribute('content')
                    assert not errors, errors
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'overflow {slug} {label}'
                    assert page.locator('img').evaluate_all('(images) => images.every(image => image.complete && image.naturalWidth > 0)')
                    if slug == 'index':
                        assert page.locator('main > section').nth(1).get_attribute('id') == 'welcome'
                        assert page.locator('.welcome-copy > p').all_text_contents() == welcome_paragraphs
                        assert page.locator('.welcome-affirmation').inner_text() == 'And it works.'
                        assert page.locator('#welcome-heading').inner_text() == 'Respawn'
                        assert page.locator('.welcome').evaluate('(element) => getComputedStyle(element, "::before").backgroundImage === "none"')
                        assert page.locator('.operation h3').all_text_contents() == ['Revert', 'Regenerate', 'Respawn']
                        assert page.locator('.hero').bounding_box()['y'] + page.locator('.hero').bounding_box()['height'] < height, 'Leave the next section visible.'
                        assert page.locator('.welcome-next').get_attribute('href') == 'why-respawn.html'
                        assert page.locator('.welcome-statement').count() == 5
                    if slug == 'why-respawn':
                        assert page.locator('.argument h2').all_text_contents() == [section['heading'] for section in argument]
                        assert page.locator('.argument-copy > p:not(.argument-path)').all_text_contents() == [re.sub(r'\*\*(.+?)\*\*', r'\1', paragraph) for section in argument for paragraph in section['paragraphs']]
                        assert page.locator('#navigation a[aria-current]').get_attribute('href') == 'why-respawn.html'
                        assert page.locator('.welcome').count() == 0
                    for href in page.locator('a[href], link[rel="stylesheet"], script[src], img[src]').evaluate_all('(elements) => elements.map(element => element.getAttribute("href") || element.getAttribute("src"))'):
                        parsed = urlsplit(href)
                        if not parsed.scheme and parsed.path:
                            assert (target / unquote(parsed.path)).is_file(), f'broken local link: {href}'
                    page.keyboard.press('Tab')
                    assert page.locator('.skip').evaluate('(element) => element === document.activeElement')
                    page.keyboard.press('Enter')
                    if width <= 700:
                        menu = page.locator('.menu')
                        menu.click()
                        assert menu.get_attribute('aria-expanded') == 'true'
                        assert page.locator('#navigation').is_visible()
                        page.keyboard.press('Escape')
                        assert page.locator('#navigation').is_hidden()
                    if slug == 'faqs':
                        for item in page.locator('details').all():
                            item.locator('summary').focus()
                            page.keyboard.press('Enter')
                            assert item.get_attribute('open') is not None
                            page.keyboard.press('Enter')
                            assert item.get_attribute('open') is None
                    page.evaluate('document.activeElement.blur(); window.scrollTo(0, 0)')
                    page.screenshot(path=str(OUT / f'{slug}-{label}.png'), full_page=True)
                    assert not errors, errors
                    results.append({'page': path, 'viewport': label, 'passed': True})
                    page.close()
                error_page = context.new_page()
                error_response = error_page.goto(base + '/does-not-exist.html', wait_until='networkidle')
                assert error_response.status == 404, 'Missing routes must return a genuine HTTP 404.'
                assert error_page.title() == 'Page Not Found | Respawn'
                assert error_page.locator('h1').inner_text() == '404 / Page not found'
                assert error_page.locator('meta[name="robots"]').get_attribute('content') == 'noindex,follow'
                assert error_page.locator('link[rel="canonical"]').count() == 0
                assert error_page.locator('meta[property="og:title"]').get_attribute('content') == 'Page Not Found | Respawn'
                assert error_page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                assert error_page.get_by_role('link', name='Project home').count() == 1
                error_page.evaluate('document.activeElement.blur(); window.scrollTo(0, 0)')
                error_page.screenshot(path=str(OUT / f'404-{label}.png'), full_page=True)
                results.append({'page': '404.html', 'viewport': label, 'passed': True, 'httpStatusExpectedFromHost': 404})
                error_page.close()
                context.close()
            browser.close()
    finally:
        server.shutdown()
(ROOT / 'artifacts/site-validation.json').write_text(json.dumps(results, indent=2) + '\n')
print(f'PASS: {len(results)} extracted-package page/viewport checks; screenshots: {OUT}')