#!/usr/bin/env python3
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
document = json.loads(subprocess.check_output([
    'php', '-r', 'require "module/bootstrap.php"; echo json_encode(Respawn\\Reconstruction\\SimulatedPbx::blueprint("pbx-alpha"), JSON_THROW_ON_ERROR);'
], cwd=ROOT, text=True))

def canonical(value):
    if isinstance(value, dict):
        return {key: canonical(value[key]) for key in sorted(value)}
    if isinstance(value, list):
        return [canonical(item) for item in value]
    if isinstance(value, float):
        raise TypeError('floating point values are not part of the v2 canonical profile')
    if isinstance(value, int) and abs(value) > 9007199254740991:
        raise TypeError('integer exceeds interoperable exact range')
    if value is None or isinstance(value, (str, bool, int)):
        return value
    raise TypeError('unsupported canonical JSON type')

payload = dict(document)
payload.pop('contentHash')
encoded = json.dumps(canonical(payload), ensure_ascii=True, separators=(',', ':'), allow_nan=False).encode()
actual = hashlib.sha256(encoded).hexdigest()
assert actual == document['contentHash'], 'Independent Python canonical bytes/hash differ from PHP v2 vector'
reordered = json.loads(json.dumps(document, indent=4))
reordered = dict(reversed(list(reordered.items())))
reordered.pop('contentHash')
assert hashlib.sha256(json.dumps(canonical(reordered), ensure_ascii=True, separators=(',', ':')).encode()).hexdigest() == actual
for invalid in [1.25, 9007199254740992, object()]:
    try:
        canonical(invalid)
    except TypeError:
        continue
    raise AssertionError('Unsupported canonical value accepted')
print('PASS: independent Python/PHP v2 canonical JSON/hash vector, reordered objects, formatting and unsupported value rejection')
