#!/usr/bin/env python3
from pathlib import Path
import json
from PIL import Image, ImageDraw
from playwright.sync_api import sync_playwright
import argparse

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--reference', action='store_true', help='Read-only public reference inspection')
args = parser.parse_args()
screens = ROOT / 'artifacts/screenshots'
for viewport in ['desktop', 'mobile']:
    files = sorted(path for path in screens.glob('*-' + viewport + '.png') if not path.name.startswith('review-'))
    sheet = Image.new('RGB', (1500, 1250), 'white')
    draw = ImageDraw.Draw(sheet)
    for index, path in enumerate(files):
        image = Image.open(path).convert('RGB')
        image.thumbnail((290, 570))
        left, top = (index % 5) * 300, (index // 5) * 625
        draw.text((left + 4, top + 4), path.stem, fill='black')
        sheet.paste(image, (left, top + 25))
    sheet.save(screens / ('review-' + viewport + '.png'))
if args.reference:
    target = ROOT / 'artifacts/reference'
    target.mkdir(exist_ok=True)
    evidence = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        for width, height, label in [(1440, 1000, 'desktop'), (390, 844, 'mobile')]:
            page = browser.new_page(viewport={'width': width, 'height': height})
            for slug in ['index', 'overview', 'faqs']:
                url = 'https://ai.dial-tone.uk/' + ('' if slug == 'index' else slug + '.html')
                response = page.goto(url, wait_until='networkidle', timeout=45000)
                page.screenshot(path=str(target / f'{slug}-{label}.png'), full_page=True)
                evidence.append({'url': url, 'viewport': label, 'status': response.status,
                    'h1': page.locator('h1').inner_text(),
                    'typography': page.locator('h1').evaluate('(element) => ({font:getComputedStyle(element).fontFamily,size:getComputedStyle(element).fontSize})'),
                    'horizontalOverflow': page.evaluate('document.documentElement.scrollWidth > innerWidth')})
            page.close()
        browser.close()
    (target / 'inspection.json').write_text(json.dumps(evidence, indent=2) + '\n')
print('Local visual-review sheets generated' + ('; public reference screenshots retained' if args.reference else ''))