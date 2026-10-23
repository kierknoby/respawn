#!/usr/bin/env python3
import argparse
from datetime import datetime
import json
from pathlib import Path

STAGES = ['provisioned', 'configuration_ready', 'connectivity_ready', 'verified_takeover', 'calls_verified']

def measure(evidence):
    if evidence.get('simulated') is not False or evidence.get('environment') != 'authorised-real-lab':
        raise ValueError('Synthetic or unauthorised evidence is not a real takeover measurement.')
    if not evidence.get('operationId') or not evidence.get('authorisationRef'):
        raise ValueError('Operation identity and separate lab authorisation reference required.')
    start = datetime.fromisoformat(evidence['invokedAt'].replace('Z', '+00:00'))
    if start.tzinfo is None:
        raise ValueError('Timezone required.')
    durations = {}
    previous = start
    for stage in STAGES:
        milestone = evidence['milestones'][stage]
        finish = datetime.fromisoformat(milestone['at'].replace('Z', '+00:00'))
        if finish.tzinfo is None or finish < previous or not milestone.get('authority') or not milestone.get('evidenceRef'):
            raise ValueError('Milestones must be ordered, attributed and supported by evidence.')
        durations[stage + '_seconds'] = (finish - start).total_seconds()
        previous = finish
    calls = evidence['callEvidence']
    for direction in ['inbound', 'outbound']:
        call = calls[direction]
        if call.get('answered') is not True or call.get('audioReceivedAtBothEnds') is not True or not call.get('receiptRef'):
            raise ValueError('Both calls need answer and bidirectional received-audio evidence.')
    previous_phase_end = start
    for phase in ['reconstruction', 'apply_verification', 'connectivity_routing']:
        interval = evidence['intervals'][phase]
        phase_start = datetime.fromisoformat(interval['startedAt'].replace('Z', '+00:00'))
        phase_end = datetime.fromisoformat(interval['completedAt'].replace('Z', '+00:00'))
        if (phase_start.tzinfo is None or phase_end.tzinfo is None or phase_start < previous_phase_end
            or phase_end < phase_start or phase_end > previous or not interval.get('authority') or not interval.get('evidenceRef')):
            raise ValueError('Phase intervals need ordered, attributed start/end receipts within the operation.')
        durations[phase + '_duration_seconds'] = (phase_end - phase_start).total_seconds()
        previous_phase_end = phase_end
    return {'operationId': evidence['operationId'], 'durations': durations,
            'warning': 'Derived from supplied evidence; receipts and real telephony are not independently verified by this utility.'}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Derive timings from separately authorised real-lab takeover receipts.')
    parser.add_argument('evidence', type=Path)
    args = parser.parse_args()
    print(json.dumps(measure(json.loads(args.evidence.read_text())), indent=2))