#!/usr/bin/env python3
import functools
import http.server
import json
import os
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
    with zipfile.ZipFile(ROOT / 'artifacts/respawn-site-0.0.1.zip') as archive:
        assert all(not name.startswith('/') and '..' not in Path(name).parts for name in archive.namelist())
        archive.extractall(target)
    assert (target / 'index.html').is_file(), 'ZIP must have index.html at upload root'
    assert (target / '.htaccess').read_text() == 'ErrorDocument 404 /404.html\n'
    ET.parse(target / 'sitemap.xml')
    assert 'https://respawn.in1.click/sitemap.xml' in (target / 'robots.txt').read_text()
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
            browser = playwright.chromium.launch(env=environment)
            for width, height, label in [(1440, 1000, 'desktop'), (390, 844, 'mobile')]:
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
                    for href in page.locator('a[href], link[rel="stylesheet"], script[src], img[src]').evaluate_all('(elements) => elements.map(element => element.getAttribute("href") || element.getAttribute("src"))'):
                        parsed = urlsplit(href)
                        if not parsed.scheme and parsed.path:
                            assert (target / unquote(parsed.path)).is_file(), f'broken local link: {href}'
                    page.keyboard.press('Tab')
                    assert page.locator('.skip').evaluate('(element) => element === document.activeElement')
                    page.keyboard.press('Enter')
                    if label == 'mobile':
                        menu = page.locator('.menu')
                        menu.click()
                        assert menu.get_attribute('aria-expanded') == 'true'
                        assert page.locator('#navigation').is_visible()
                        page.keyboard.press('Escape')
                        assert page.locator('#navigation').is_hidden()
                    if slug == 'faqs':
                        first = page.locator('summary').first
                        first.focus()
                        page.keyboard.press('Enter')
                        assert page.locator('details').first.get_attribute('open') is not None
                        page.keyboard.press('Enter')
                    page.evaluate('document.activeElement.blur(); window.scrollTo(0, 0)')
                    page.screenshot(path=str(OUT / f'{slug}-{label}.png'), full_page=True)
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