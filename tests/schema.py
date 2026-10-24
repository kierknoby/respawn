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

for contract_name in ['pond-identity.v1.schema.json', 'river-federation.v1.schema.json',
    'river-agreement.v1.schema.json', 'river-receipt.v1.schema.json',
    'recovery-delegation.v1.schema.json', 'lake-fleet.v1.schema.json']:
    contract = json.loads((ROOT / 'contracts' / contract_name).read_text())
    Draft202012Validator.check_schema(contract)

pond_contract = Draft202012Validator(json.loads((ROOT / 'contracts/pond-identity.v1.schema.json').read_text()))
pond_contract.validate({'schema':'respawn.pond-identity.v1','pondId':'pond-dev-a',
    'createdAt':'2026-10-23T00:00:00Z','state':'active','keyIds':['pond-dev-a-key-1']})
river_contract = Draft202012Validator(json.loads((ROOT / 'contracts/river-federation.v1.schema.json').read_text()))
river_contract.validate({'schema':'respawn.river-federation.v1','messageId':'msg-1',
    'senderPondId':'pond-dev-a','senderKeyId':'pond-dev-a-key-1','recipientPondId':'pond-dev-b','federationId':'fed-a-b',
    'tenantId':'tenant-test','method':'blueprint.replicate','idempotencyKey':'a'*64,
    'nonce':'c'*32,'payloadHash':'d'*64,'createdAt':'2026-10-23T00:00:00Z',
    'expiresAt':'2026-10-23T00:05:00Z','payload':{}})
agreement_contract = Draft202012Validator(json.loads((ROOT / 'contracts/river-agreement.v1.schema.json').read_text()))
agreement_contract.validate({'schema':'respawn.river-agreement.v1','federationId':'fed-a-b',
    'pondIds':['pond-dev-a','pond-dev-b'],'tenantId':'tenant-test','resourceScope':{'sourcePbxIds':['pbx-alpha']},
    'allowedMethods':['blueprint.replication.v1'],'state':'active',
    'approvals':[{'pondId':'pond-dev-a','actorId':'operator-a','keyId':'key-a','approvedAt':'2026-10-23T00:00:00Z','approvalRef':'approval:a'},
        {'pondId':'pond-dev-b','actorId':'operator-b','keyId':'key-b','approvedAt':'2026-10-23T00:00:00Z','approvalRef':'approval:b'}],
    'createdAt':'2026-10-23T00:00:00Z','expiresAt':'2026-10-24T00:00:00Z'})
receipt_contract = Draft202012Validator(json.loads((ROOT / 'contracts/river-receipt.v1.schema.json').read_text()))
receipt_contract.validate({'schema':'respawn.river-receipt.v1','state':'durably_retained_simulated',
    'federationId':'fed-a-b','transactionId':'xfer-'+'a'*64,'idempotencyKey':'a'*64,
    'senderPondId':'pond-dev-a','recipientPondId':'pond-dev-b','tenantId':'tenant-test',
    'sourcePbxId':'pbx-alpha','revisionId':'rev-'+'b'*32,'parentRevisionId':None,
    'contentHash':'c'*64,'replicaId':'replica-1','retainedAt':'2026-10-23T00:00:00Z','mode':'SIMULATED'})
delegation_contract = Draft202012Validator(json.loads((ROOT / 'contracts/recovery-delegation.v1.schema.json').read_text()))
delegation_contract.validate({'schema':'respawn.recovery-delegation.v1','delegationId':'delegation-1',
    'tenantId':'tenant-test','sourcePbxId':'pbx-alpha','revisionId':'rev-'+'b'*32,'contentHash':'c'*64,
    'recoveryPondId':'pond-dev-b','replacementPbxId':'pbx-replacement','allowedActions':['blueprint.retrieve'],
    'issuedBy':'operator-a','issuedAt':'2026-10-23T00:00:00Z','expiresAt':'2026-10-23T01:00:00Z',
    'nonce':'d'*32,'state':'active'})
lake_contract = Draft202012Validator(json.loads((ROOT / 'contracts/lake-fleet.v1.schema.json').read_text()))
lake_contract.validate({'schema':'respawn.lake-fleet-inventory.v1','tenantId':'tenant-test','fleetId':'fleet-test',
    'ponds':['pond-dev-a','pond-dev-b'],'pbxs':[{'sourcePbxId':'pbx-alpha','primaryPondId':'pond-dev-a',
        'protectionState':'replicated','replicas':[{'pondId':'pond-dev-b','revisionId':'rev-'+'a'*32,
            'contentHash':'b'*64,'state':'confirmed'}]}], 'updatedAt':'2026-10-23T00:00:00Z'})
print('PASS: independent Draft 2020-12 validation for Pond identity, River agreements/messages/receipts, recovery delegation and Lake fleet inventory contracts')

rpc_schema = json.loads((ROOT / 'contracts/provider-rpc.v2.schema.json').read_text())
Draft202012Validator.check_schema(rpc_schema)
rpc_validator = Draft202012Validator(rpc_schema)
rpc_validator.validate({'schema':'respawn.provider-rpc.v2','connectionId':'https-v1:reference',
    'method':'retainRevision','pbxId':'pbx-alpha','parameters':{},'requestId':'a'*32,
    'sentAt':'2026-10-24T12:00:00Z'})
rpc_validator.validate({'schema':'respawn.provider-rpc.v2','connectionId':'https-v1:reference',
    'method':'retainRevision','pbxId':'pbx-alpha','ok':True,'observedAt':'2026-10-24T12:00:01Z',
    'expiresAt':'2026-10-24T12:01:00Z','result':{}})
rpc_validator.validate({'ok':False,'error':'POND_REQUEST_REJECTED'})
invalid_rpc = {'schema':'respawn.provider-rpc.v2','connectionId':'https-v1:reference',
    'method':'retainRevision','pbxId':'pbx-alpha','parameters':{},'requestId':'a'*32,
    'sentAt':'2026-10-24T12:00:00Z','secret':'must-not-be-an-envelope-field'}
assert list(rpc_validator.iter_errors(invalid_rpc)), 'Unknown RPC envelope field accepted'

routing_schema = json.loads((ROOT / 'contracts/routing-control.v1.schema.json').read_text())
Draft202012Validator.check_schema(routing_schema)
routing_validator = Draft202012Validator(routing_schema)
routing_request = {'schema':'respawn.routing-control.v1','action':'prepare','operationId':'op-test',
    'pbxId':'pbx-alpha','targetId':'target-test','blueprintHash':'a'*64,'expectedRevision':2,
    'authorityEpoch':1,'idempotencyKey':'b'*64,'readinessEvidence':[]}
routing_validator.validate(routing_request)
activation_request = dict(routing_request, action='activate', readinessEvidence=[
    {'check':check,'state':'passed','evidenceRef':'evidence-'+str(index),
     'observedAt':'2026-10-24T12:00:00Z','expiresAt':'2026-10-24T12:01:00Z'}
    for index, check in enumerate(['blueprintCoverage','reconstruction','apply','configurationMatch',
        'connectivity','routingPreparation','registration','inboundCall','outboundCall','bidirectionalAudio'])])
routing_validator.validate(activation_request)
incomplete_activation = dict(routing_request, action='activate')
assert list(routing_validator.iter_errors(incomplete_activation)), 'Activation without complete evidence accepted'
active_receipt = {'schema':'respawn.routing-control.v1','action':'activate','state':'active',
    'operationId':'op-test','pbxId':'pbx-alpha','targetId':'target-test','blueprintHash':'a'*64,
    'revision':2,'authorityEpoch':1,'observedAt':'2026-10-24T12:00:01Z','expiresAt':'2026-10-24T12:01:00Z',
    'evidenceRef':'evidence-active','activationReceipt':'receipt-active'}
routing_validator.validate(active_receipt)
active_receipt.pop('activationReceipt')
assert list(routing_validator.iter_errors(active_receipt)), 'Active routing receipt without activation receipt accepted'
failed_receipt = {'schema':'respawn.routing-control.v1','action':'verify','state':'unavailable',
    'operationId':'op-test','pbxId':'pbx-alpha','targetId':'target-test','blueprintHash':'a'*64,
    'revision':2,'authorityEpoch':1,'observedAt':'2026-10-24T12:00:01Z','expiresAt':'2026-10-24T12:01:00Z'}
assert list(routing_validator.iter_errors(failed_receipt)), 'Unavailable routing response without failure code accepted'
print('PASS: Stream envelope and routing-control conformance schemas validate positive cases and reject unsafe unknown/incomplete data')