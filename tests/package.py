#!/usr/bin/env python3
from pathlib import Path
import hashlib
import tarfile
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
metadata = ET.parse(ROOT / 'module/module.xml').getroot()
version = metadata.findtext('version')
assert metadata.findtext('rawname') == 'respawn'
assert metadata.find('menuitems/respawn').attrib['category'] == 'Connectivity'
with tarfile.open(ROOT / f'artifacts/respawn-{version}.tgz') as archive:
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
with tarfile.open(ROOT / f'artifacts/respawn-pond-{version}.tgz') as archive:
    names = set(archive.getnames())
    expected = {'respawn-pond/' + path.relative_to(ROOT).as_posix()
        for folder in ['pond', 'module'] for path in (ROOT / folder).rglob('*') if path.is_file()} | {
            'respawn-pond/LICENSE', 'respawn-pond/tools/preflight.php',
            'respawn-pond/contracts/provider-rpc.v2.schema.json', 'respawn-pond/docs/https-profile.md',
            'respawn-pond/docs/provider-contracts.md'}
    assert names == expected, 'Pond package must contain only service, shared module code and licence.'
    assert 'respawn-pond/pond/public/index.php' in names
    cron = archive.extractfile('respawn-pond/pond/respawn-protection.cron').read()
    assert cron.endswith(b'\n') and b'>/dev/null' not in cron
    assert cron.strip() == b'* * * * * asterisk /usr/sbin/fwconsole respawn protection-reconcile'
    assert not any(name.endswith(('.sqlite', '.key', '.secret', '.token')) for name in names)
    assert not any('development/' in name or '/tests/' in name for name in names)
with zipfile.ZipFile(ROOT / f'artifacts/respawn-pond-{version}.zip') as archive:
    names = set(archive.namelist())
    expected = {'respawn-pond/' + path.relative_to(ROOT).as_posix()
        for folder in ['pond', 'module'] for path in (ROOT / folder).rglob('*') if path.is_file()} | {
            'respawn-pond/LICENSE', 'respawn-pond/tools/preflight.php',
            'respawn-pond/contracts/provider-rpc.v2.schema.json', 'respawn-pond/docs/https-profile.md',
            'respawn-pond/docs/provider-contracts.md'}
    assert names == expected, 'Pond ZIP must contain only service, shared module code and licence.'
    assert 'respawn-pond/pond/public/index.php' in names
    for required in ['pond/install.sh','pond/nginx.conf.example','pond/php-fpm.conf.example',
        'pond/php-fpm-master.conf.example','pond/respawn-pond-php-fpm.service.example',
        'pond/bin/pond.php','pond/Pond.php','pond/README.md']:
        assert 'respawn-pond/' + required in names, f'Pond installer archive is missing {required}.'
    assert not any(name.endswith(('.sqlite', '.key', '.secret', '.token')) for name in names)
    assert not any('development/' in name or '/tests/' in name for name in names)
    for info in archive.infolist():
        assert info.date_time == (2026, 1, 1, 0, 0, 0), 'Pond ZIP timestamps must be reproducible.'
checksums = (ROOT / 'artifacts/SHA256SUMS').read_text()
pond_zip_name = f'respawn-pond-{version}.zip'
pond_zip_hash = hashlib.sha256((ROOT / 'artifacts' / pond_zip_name).read_bytes()).hexdigest()
assert f'{pond_zip_hash}  {pond_zip_name}\n' in checksums
assert (ROOT / 'artifacts' / f'{pond_zip_name}.sha256').read_text() == f'{pond_zip_hash}  {pond_zip_name}\n'
with zipfile.ZipFile(ROOT / 'artifacts' / f'respawn-site-{version}.zip') as archive:
    names = archive.namelist()
    assert 'index.html' in names
    assert archive.read('.htaccess').decode() == 'ErrorDocument 404 /404.html\n'
    assert '404.html' in names
    assert 'contracts/v1.schema.json' in names
    for contract in ['pond-identity.v1.schema.json', 'river-federation.v1.schema.json',
        'river-agreement.v1.schema.json', 'river-receipt.v1.schema.json',
        'recovery-delegation.v1.schema.json', 'lake-fleet.v1.schema.json',
        'provider-rpc.v2.schema.json', 'routing-control.v1.schema.json']:
        assert 'contracts/' + contract in names
    assert not any(name.startswith('development/') or 'local-pond' in name.lower() for name in names)
    assert not any(name.endswith('.php') or 'state.json' in name or 'credentials' in name.lower() for name in names)

my_connect_name = f'respawn-my-connect-reference-{version}.zip'
my_connect_sources = [path for path in (ROOT / 'integrations/my-connect-reference').rglob('*') if path.is_file()]
my_connect_sources += [path for path in (ROOT / 'module').rglob('*') if path.is_file()]
my_connect_sources += [ROOT / 'contracts/provider-rpc.v2.schema.json', ROOT / 'docs/https-profile.md',
    ROOT / 'docs/provider-contracts.md', ROOT / 'LICENSE']
with zipfile.ZipFile(ROOT / 'artifacts' / my_connect_name) as archive:
    expected = {'respawn-my-connect-reference/' + path.relative_to(ROOT).as_posix() for path in my_connect_sources}
    assert set(archive.namelist()) == expected, 'my-connect reference ZIP must be self-contained and scope-limited.'
    assert not any(name.endswith(('.token', '.secret', '.env', '.sqlite')) for name in archive.namelist())
    assert all(info.date_time == (2026, 1, 1, 0, 0, 0) for info in archive.infolist())

kamailio_name = f'respawn-kamailio-reference-{version}.zip'
kamailio_sources = [path for path in (ROOT / 'integrations/kamailio-reference').rglob('*') if path.is_file()]
kamailio_sources += [ROOT / 'contracts/routing-control.v1.schema.json', ROOT / 'LICENSE']
with zipfile.ZipFile(ROOT / 'artifacts' / kamailio_name) as archive:
    expected = {'respawn-kamailio-reference/' + path.relative_to(ROOT).as_posix() for path in kamailio_sources}
    assert set(archive.namelist()) == expected, 'Kamailio reference ZIP must include its contract and no unrelated runtime.'
    assert not any(name.endswith(('.token', '.secret', '.env', '.sqlite')) for name in archive.namelist())
    assert all(info.date_time == (2026, 1, 1, 0, 0, 0) for info in archive.infolist())

for name in [f'respawn-site-{version}.zip', my_connect_name, kamailio_name]:
    digest = hashlib.sha256((ROOT / 'artifacts' / name).read_bytes()).hexdigest()
    assert f'{digest}  {name}\n' in checksums
    assert (ROOT / 'artifacts' / f'{name}.sha256').read_text() == f'{digest}  {name}\n'
print('PASS: module metadata/archive root/licence/wrapper and static cPanel ZIP structure')