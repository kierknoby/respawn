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
    expected = {'respawn/' + path.relative_to(ROOT / 'module').as_posix()
        for path in (ROOT / 'module').rglob('*') if path.is_file()} | {'respawn/LICENSE'}
    assert set(names) == expected, 'Module archive must contain exactly module source and its licence.'
    forbidden_parts = {'tests', 'development', 'artifacts', 'build', 'credentials'}
    assert not any(forbidden_parts.intersection(Path(name).parts) for name in names)
    assert not any(name.endswith(('.secret', '.token', '.env')) for name in names)
    for required in ['module.xml', 'Respawn.class.php', 'page.respawn.php', 'bootstrap.php', 'Console/Respawn.class.php', 'LICENSE']:
        assert 'respawn/' + required in names
    assert archive.getmember('respawn/bin/respawn').mode == 0o755
    assert archive.extractfile('respawn/LICENSE').read() == (ROOT / 'LICENSE').read_bytes()
    for member in archive.getmembers():
        if not member.isfile() or member.name.endswith(('.png', '.jpg', '.gif', '.webp')):
            continue
        contents = archive.extractfile(member).read()
        assert b'-----BEGIN PRIVATE KEY-----' not in contents
        assert b'-----BEGIN RSA PRIVATE KEY-----' not in contents
with tarfile.open(ROOT / 'artifacts/respawn-pond-0.0.1.tgz') as archive:
    names = set(archive.getnames())
    expected = {'respawn-pond/' + path.relative_to(ROOT).as_posix()
        for folder in ['pond', 'module'] for path in (ROOT / folder).rglob('*') if path.is_file()} | {'respawn-pond/LICENSE', 'respawn-pond/tools/preflight.php'}
    assert names == expected, 'Pond package must contain only service, shared module code and licence.'
    assert 'respawn-pond/pond/public/index.php' in names
    cron = archive.extractfile('respawn-pond/pond/respawn-protection.cron').read()
    assert cron.endswith(b'\n') and b'>/dev/null' not in cron
    assert cron.strip() == b'* * * * * asterisk /usr/sbin/fwconsole respawn protection-reconcile'
    assert not any(name.endswith(('.sqlite', '.key', '.secret', '.token')) for name in names)
    assert not any('development/' in name or '/tests/' in name for name in names)
with zipfile.ZipFile(ROOT / 'artifacts/respawn-site-0.0.1.zip') as archive:
    names = archive.namelist()
    assert 'index.html' in names
    assert archive.read('.htaccess').decode() == 'ErrorDocument 404 /404.html\n'
    assert '404.html' in names
    assert 'contracts/v1.schema.json' in names
    for contract in ['pond-identity.v1.schema.json', 'river-federation.v1.schema.json',
        'river-agreement.v1.schema.json', 'river-receipt.v1.schema.json',
        'recovery-delegation.v1.schema.json', 'lake-fleet.v1.schema.json']:
        assert 'contracts/' + contract in names
    assert not any(name.startswith('development/') or 'local-pond' in name.lower() for name in names)
    assert not any(name.endswith('.php') or 'state.json' in name or 'credentials' in name.lower() for name in names)
print('PASS: module metadata/archive root/licence/wrapper and static cPanel ZIP structure')