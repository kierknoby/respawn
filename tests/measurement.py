#!/usr/bin/env python3
import importlib.util
from pathlib import Path

module_spec = importlib.util.spec_from_file_location('measure', Path(__file__).resolve().parents[1] / 'tools/measure.py')
module = importlib.util.module_from_spec(module_spec)
module_spec.loader.exec_module(module)
for evidence in [{'simulated': True}, {'simulated': False, 'environment': 'mock'}]:
    try:
        module.measure(evidence)
    except ValueError:
        continue
    raise AssertionError('Mock evidence accepted as real measurement')
fabricated = {'simulated': False, 'environment': 'authorised-real-lab', 'operationId': 'UNIT-TEST-ONLY',
    'authorisationRef': 'FABRICATED-UNIT-TEST-NOT-AUTHORISATION', 'invokedAt': '2026-01-01T00:00:00Z',
    'milestones': {}, 'intervals': {}, 'callEvidence': {}}
for name, seconds in zip(module.STAGES, [10, 40, 50, 60, 70]):
    fabricated['milestones'][name] = {'at': f'2026-01-01T00:0{seconds // 60}:{seconds % 60:02d}Z', 'authority': 'unit-test', 'evidenceRef': 'fabricated'}
for name, start, end in [('reconstruction', 10, 30), ('apply_verification', 30, 40), ('connectivity_routing', 40, 50)]:
    fabricated['intervals'][name] = {'startedAt': f'2026-01-01T00:00:{start:02d}Z',
        'completedAt': f'2026-01-01T00:00:{end:02d}Z', 'authority': 'unit-test', 'evidenceRef': 'fabricated'}
for direction in ['inbound', 'outbound']:
    fabricated['callEvidence'][direction] = {'answered': True, 'audioReceivedAtBothEnds': True, 'receiptRef': 'fabricated'}
result = module.measure(fabricated)
assert result['durations']['reconstruction_duration_seconds'] == 20
assert result['durations']['apply_verification_duration_seconds'] == 10
fabricated['intervals']['apply_verification']['startedAt'] = '2026-01-01T00:00:20Z'
try:
    module.measure(fabricated)
except ValueError:
    pass
else:
    raise AssertionError('Overlapping/out-of-order phase receipts accepted')
print('PASS: synthetic/unauthorised timings rejected; fabricated unit-input phase arithmetic and overlap rules checked; no real measurement claimed')