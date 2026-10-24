#!/usr/bin/env python3
from datetime import datetime
from pathlib import Path
import io
import tarfile
import zipfile
import hashlib
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'artifacts'
OUT.mkdir(exist_ok=True)
VERSION = ET.parse(ROOT / 'module/module.xml').getroot().findtext('version')
site = ROOT / 'build/site'
if not (site / 'index.html').exists():
    raise SystemExit('Build the site first: python3 tools/build_site.py')

def write_source_zip(name, prefix, sources):
    with zipfile.ZipFile(OUT / name, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(sources):
            entry = zipfile.ZipInfo(prefix + '/' + path.relative_to(ROOT).as_posix(), (2026, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o644 << 16
            archive.writestr(entry, path.read_bytes())

now = datetime.now().replace(microsecond=0)
website_timestamp = (now.year, now.month, now.day, now.hour, now.minute, now.second - now.second % 2)
with zipfile.ZipFile(OUT / f'respawn-site-{VERSION}.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(site.rglob('*')):
        if path.is_file():
            entry = zipfile.ZipInfo(path.relative_to(site).as_posix(), website_timestamp)
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o644 << 16
            archive.writestr(entry, path.read_bytes())
with tarfile.open(OUT / f'respawn-{VERSION}.tgz', 'w:gz') as archive:
    sources = [(path, 'respawn/' + path.relative_to(ROOT / 'module').as_posix()) for path in sorted((ROOT / 'module').rglob('*')) if path.is_file()]
    sources += [(ROOT / 'LICENSE', 'respawn/LICENSE')]
    for path, name in sources:
        contents = path.read_bytes()
        entry = tarfile.TarInfo(name)
        entry.size = len(contents)
        entry.mode = 0o755 if name.endswith('/bin/respawn') else 0o644
        entry.mtime = 0
        archive.addfile(entry, io.BytesIO(contents))
with tarfile.open(OUT / f'respawn-pond-{VERSION}.tgz', 'w:gz') as archive:
    sources = [path for folder in ['pond', 'module'] for path in sorted((ROOT / folder).rglob('*')) if path.is_file()]
    sources += [ROOT / 'contracts/provider-rpc.v2.schema.json', ROOT / 'docs/https-profile.md',
        ROOT / 'docs/provider-contracts.md', ROOT / 'LICENSE', ROOT / 'tools/preflight.php']
    for path in sources:
        contents = path.read_bytes()
        entry = tarfile.TarInfo('respawn-pond/' + path.relative_to(ROOT).as_posix())
        entry.size = len(contents)
        entry.mode = 0o644
        entry.mtime = 0
        archive.addfile(entry, io.BytesIO(contents))
pond_sources = [path for folder in ['pond', 'module'] for path in (ROOT / folder).rglob('*') if path.is_file()]
pond_sources += [ROOT / 'contracts/provider-rpc.v2.schema.json', ROOT / 'docs/https-profile.md',
    ROOT / 'docs/provider-contracts.md', ROOT / 'LICENSE', ROOT / 'tools/preflight.php']
write_source_zip(f'respawn-pond-{VERSION}.zip', 'respawn-pond', pond_sources)

my_connect_sources = [path for path in (ROOT / 'integrations/my-connect-reference').rglob('*') if path.is_file()]
my_connect_sources += [path for path in (ROOT / 'module').rglob('*') if path.is_file()]
my_connect_sources += [ROOT / 'contracts/provider-rpc.v2.schema.json', ROOT / 'docs/https-profile.md',
    ROOT / 'docs/provider-contracts.md', ROOT / 'LICENSE']
write_source_zip(f'respawn-my-connect-reference-{VERSION}.zip', 'respawn-my-connect-reference', my_connect_sources)

kamailio_sources = [path for path in (ROOT / 'integrations/kamailio-reference').rglob('*') if path.is_file()]
kamailio_sources += [ROOT / 'contracts/routing-control.v1.schema.json', ROOT / 'LICENSE']
write_source_zip(f'respawn-kamailio-reference-{VERSION}.zip', 'respawn-kamailio-reference', kamailio_sources)

checksums = []
names = [f'respawn-site-{VERSION}.zip', f'respawn-{VERSION}.tgz', f'respawn-pond-{VERSION}.tgz',
    f'respawn-pond-{VERSION}.zip', f'respawn-my-connect-reference-{VERSION}.zip',
    f'respawn-kamailio-reference-{VERSION}.zip']
for name in names:
    path = OUT / name
    checksums.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + name)
(OUT / 'SHA256SUMS').write_text('\n'.join(checksums) + '\n')
for name in names:
    if name.endswith('.zip'):
        digest = hashlib.sha256((OUT / name).read_bytes()).hexdigest()
        (OUT / f'{name}.sha256').write_text(digest + '  ' + name + '\n')
print('Local artefacts:', OUT)