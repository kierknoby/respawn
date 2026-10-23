#!/usr/bin/env python3
import copy
import json
from pathlib import Path
import subprocess
from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[1]
schema = json.loads((ROOT / 'contracts/v2.schema.json').read_text())
Draft202012Validator.check_schema(schema)
validator = Draft202012Validator(schema)
for identity in ['pbx-alpha', 'pbx-bravo']:
    document = json.loads(subprocess.check_output(['php', '-r', 'require "module/bootstrap.php"; echo json_encode(Respawn\\Reconstruction\\SimulatedPbx::blueprint($argv[1]), JSON_THROW_ON_ERROR);', identity], cwd=ROOT, text=True))
    validator.validate(document)
    invalid = copy.deepcopy(document)
    invalid['operations'][0]['parameters']['arbitrary_shell'] = 'not-executable'
    assert list(validator.iter_errors(invalid)), 'Unknown operation field accepted'
    invalid = copy.deepcopy(document)
    invalid['coverage']['selectedComplete'] = False
    assert list(validator.iter_errors(invalid)), 'Incomplete selected coverage accepted'
print('PASS: independent Draft 2020-12 schema validation of both v2 chains and rejection of unsupported/incomplete operations')