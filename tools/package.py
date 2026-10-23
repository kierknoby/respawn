#!/usr/bin/env python3
from pathlib import Path
import io
import tarfile
import zipfile
import hashlib

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'artifacts'
OUT.mkdir(exist_ok=True)
site = ROOT / 'build/site'
if not (site / 'index.html').exists():
    raise SystemExit('Build the site first: python3 tools/build_site.py')
with zipfile.ZipFile(OUT / 'respawn-site-0.0.1.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(site.rglob('*')):
        if path.is_file():
            entry = zipfile.ZipInfo(path.relative_to(site).as_posix(), (2026, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o644 << 16
            archive.writestr(entry, path.read_bytes())
with tarfile.open(OUT / 'respawn-0.0.1.tgz', 'w:gz') as archive:
    sources = [(path, 'respawn/' + path.relative_to(ROOT / 'module').as_posix()) for path in sorted((ROOT / 'module').rglob('*')) if path.is_file()]
    sources += [(ROOT / 'LICENSE', 'respawn/LICENSE')]
    for path, name in sources:
        contents = path.read_bytes()
        entry = tarfile.TarInfo(name)
        entry.size = len(contents)
        entry.mode = 0o755 if name.endswith('/bin/respawn') else 0o644
        entry.mtime = 0
        archive.addfile(entry, io.BytesIO(contents))
with tarfile.open(OUT / 'respawn-pond-0.0.1.tgz', 'w:gz') as archive:
    sources = [path for folder in ['pond', 'module'] for path in sorted((ROOT / folder).rglob('*')) if path.is_file()]
    sources += [ROOT / 'LICENSE', ROOT / 'tools/preflight.php']
    for path in sources:
        contents = path.read_bytes()
        entry = tarfile.TarInfo('respawn-pond/' + path.relative_to(ROOT).as_posix())
        entry.size = len(contents)
        entry.mode = 0o644
        entry.mtime = 0
        archive.addfile(entry, io.BytesIO(contents))
checksums = []
for name in ['respawn-site-0.0.1.zip', 'respawn-0.0.1.tgz', 'respawn-pond-0.0.1.tgz']:
    path = OUT / name
    checksums.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + name)
(OUT / 'SHA256SUMS').write_text('\n'.join(checksums) + '\n')
print('Local artefacts:', OUT)