#!/usr/bin/env python3
from pathlib import Path
import tarfile
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
metadata = ET.parse(ROOT / 'module/module.xml').getroot()
assert metadata.findtext('rawname') == 'respawn'
assert metadata.findtext('version') == '0.0.1'
assert metadata.find('menuitems/respawn').attrib['category'] == 'Connectivity'
with tarfile.open(ROOT / 'artifacts/respawn-0.0.1.tgz') as archive:
    names = archive.getnames()
    assert all(name.startswith('respawn/') and '..' not in Path(name).parts for name in names)
    assert not any('LocalPond' in name or 'LocalDemoApplication' in name or 'LocalPondWorker' in name for name in names)
    for required in ['module.xml', 'Respawn.class.php', 'page.respawn.php', 'bootstrap.php', 'Console/Respawn.class.php', 'LICENSE']:
        assert 'respawn/' + required in names
    assert archive.getmember('respawn/bin/respawn').mode == 0o755
    assert archive.extractfile('respawn/LICENSE').read() == (ROOT / 'LICENSE').read_bytes()
with zipfile.ZipFile(ROOT / 'artifacts/respawn-site-0.0.1.zip') as archive:
    names = archive.namelist()
    assert 'index.html' in names
    assert archive.read('.htaccess').decode() == 'ErrorDocument 404 /404.html\n'
    assert '404.html' in names
    assert 'contracts/v1.schema.json' in names
    assert not any(name.startswith('development/') or 'local-pond' in name.lower() for name in names)
    assert not any(name.endswith('.php') or 'state.json' in name or 'credentials' in name.lower() for name in names)
print('PASS: module metadata/archive root/licence/wrapper and static cPanel ZIP structure')