#!/usr/bin/env python3
import copy
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
def php(code, document=None):
    return subprocess.run(['php', '-r', 'require "module/bootstrap.php"; ' + code], cwd=ROOT,
                          input=None if document is None else json.dumps(document), text=True, capture_output=True)
generated = php('echo json_encode(Respawn\\Reconstruction\\SimulatedPbx::blueprint("pbx-alpha"), JSON_THROW_ON_ERROR);')
assert generated.returncode == 0, generated.stderr
original = json.loads(generated.stdout)
def independent_hash(document):
    plain = {key: value for key, value in document.items() if key != 'contentHash'}
    return hashlib.sha256(json.dumps(plain, sort_keys=True, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
assert independent_hash(original) == original['contentHash']
verify = 'Respawn\\Reconstruction\\Blueprint::verifyHash(json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR));'
reordered = dict(reversed(list(original.items())))
reordered['operations'][0] = dict(reversed(list(reordered['operations'][0].items())))
reordered['operations'][0]['parameters'] = dict(reversed(list(reordered['operations'][0]['parameters'].items())))
assert php(verify, reordered).returncode == 0, 'Reordered root/nested keys rejected'
for path in ['artifacts/demo/pbx-alpha-blueprint.json', 'artifacts/demo/pbx-bravo-blueprint.json']:
    if (ROOT / path).is_file():
        included = json.loads((ROOT / path).read_text())
        assert php(verify, included).returncode == 0, 'Existing included v2 hash became incompatible'
for kind in ['parameter', 'dependency', 'revision', 'array']:
    modified = copy.deepcopy(original)
    if kind == 'parameter':
        modified['operations'][0]['parameters']['description'] += ' changed'
    elif kind == 'dependency':
        modified['operations'][0]['dependsOn'].append('user:9999')
    elif kind == 'revision':
        device = next(item for item in modified['operations'] if item['object']['type'] == 'pjsip')
        device['secretRefs']['authPassword'] += '-changed'
    else:
        modified['coverage']['excluded'].reverse()
    assert php(verify, modified).returncode != 0, kind + ' change not detected'
unicode_vector = copy.deepcopy(original)
unicode_vector['coverage']['excluded'] += ['caf\u00e9 / \U0001f4de', 'line\nseparator\u2028']
unicode_vector['contentHash'] = independent_hash(unicode_vector)
assert php(verify, unicode_vector).returncode == 0, 'Python/PHP escaping vector differs'
for unsupported in [1.0, 1.5, 9007199254740992]:
    assert php('Respawn\\Reconstruction\\BuildSpec::seal(["value" => json_decode(stream_get_contents(STDIN), true)]);', unsupported).returncode != 0
print('PASS: independent Python/PHP v2 canonical bytes, existing hash compatibility, key/format invariance, meaningful array/parameter/dependency/revision changes and unsupported numeric rejection')