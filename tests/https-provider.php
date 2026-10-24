<?php
declare(strict_types=1);

use Respawn\Provider\HttpsProvider;
use Respawn\Provider\ConnectionIdentity;
use Respawn\Reconstruction\ConfigurationCapture;

$resultOverride = null;
$stale = false;
$wrongIdentity = false;
$badTimestamp = false;
$transport = function (array $request) use (&$resultOverride, &$stale, &$wrongIdentity, &$badTimestamp): array {
    $result = $resultOverride ?? ['identity' => 'pbx-alpha', 'authorised' => true, 'capabilities' => ['rehearsal']];
    return ['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $request['connectionId'], 'method' => $request['method'],
        'pbxId' => $wrongIdentity ? 'pbx-bravo' : 'pbx-alpha', 'ok' => true,
        'observedAt' => $badTimestamp ? 'now' : gmdate(DATE_ATOM, time() - ($stale ? 600 : 0)), 'expiresAt' => gmdate(DATE_ATOM, time() + 30), 'result' => $result];
};
$https = new HttpsProvider($configuration, $transport);
check($https->capabilities()['authorised'] === true, 'HTTPS adapter validates synthetic authenticated capability envelope without network');
$captureBlueprint = \Respawn\Reconstruction\SimulatedPbx::blueprint('pbx-alpha');
$captureModules = [];
foreach ($captureBlueprint['modules'] as $name=>$version) { $captureModules[$name] = ['available'=>true,'version'=>$version,'discoveryState'=>'known']; }
$captureCollections = ['core.users'=>['state'=>'captured','objects'=>[]],
    'core.pjsip'=>['state'=>'captured','objects'=>[]],'ringgroups'=>['state'=>'captured','objects'=>[]]];
foreach (\Respawn\Reconstruction\BuildSpec::plan($captureBlueprint) as $operation) {
    $objectType = $operation['object']['type'];
    $collection = match ($objectType) { 'user'=>'core.users','pjsip'=>'core.pjsip',default=>'ringgroups' };
    $captureCollections[$collection]['objects'][] = ['id'=>$operation['object']['identity'],'type'=>$operation['adapter'],
        'state'=>'captured','fields'=>$operation['parameters']];
}
$capture = ConfigurationCapture::create('pbx-alpha',$captureModules,$captureCollections,
    ['state'=>'not_configured','references'=>[],'recoveryReady'=>false,'reasonCode'=>'SECRET_MANIFEST_MISSING'],
    ['CREDENTIALS:SECRET_MANIFEST_MISSING']);
$captureRevisionId = 'rev-' . str_repeat('f',32);
$captureKey = hash('sha256',$https->connectionIdentity() . "\0pbx-alpha\0" . $captureRevisionId . "\0" . $capture['contentHash']);
$resultOverride = ['state'=>'durably_retained_capture','ack'=>['pbxId'=>'pbx-alpha',
    'connectionId'=>$https->connectionIdentity(),'revisionId'=>$captureRevisionId,'captureId'=>$captureRevisionId,
    'contentHash'=>$capture['contentHash'],'idempotencyKey'=>$captureKey,'retentionReceipt'=>'capture-receipt-test',
    'publicationId'=>'capture-publication-test','version'=>1,'recoveryReady'=>false]];
$captureAck = $https->retainConfigurationCapture('pbx-alpha',$captureRevisionId,$capture);
check(($captureAck['ack']['contentHash'] ?? '') === $capture['contentHash']
    && ($captureAck['recoveryReady'] ?? true) === false,
    'Stream accepts a durable capture receipt without upgrading its recovery-readiness claim');
$resultOverride = ['state'=>'retained_configuration_capture','pbxId'=>'pbx-alpha',
    'connectionId'=>$https->connectionIdentity(),'revisionId'=>$captureRevisionId,'ack'=>$captureAck['ack'],
    'capture'=>$capture,'recoveryReady'=>false];
$readBack = $https->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId);
check(($readBack['capture']['contentHash'] ?? '') === $capture['contentHash']
    && ($readBack['capture'] ?? null) === $capture,
    'Stream verifies exact independent capture read-back against the local content hash');
$resultOverride['capture']['collections']['core.users']['objects'][0]['fields']['name'] = 'Changed after retention';
rejects(fn () => $https->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId),
    'capture read-back rejects retained bytes with a mismatched canonical hash');
$retainedBlueprint = \Respawn\Reconstruction\SimulatedPbx::blueprint('pbx-alpha');
$retainedRevisionId = 'rev-' . str_repeat('a', 32);
$retainedPublicationId = 'pub-' . str_repeat('b', 32);
$resultOverride = ['state' => 'durably_retained', 'ack' => ['pbxId' => 'pbx-alpha',
    'connectionId' => $https->connectionIdentity(), 'revisionId' => $retainedRevisionId,
    'blueprintId' => 'bp-' . substr($retainedBlueprint['contentHash'], 0, 24),
    'publicationId' => $retainedPublicationId, 'version' => 1, 'hash' => $retainedBlueprint['contentHash'],
    'idempotencyKey' => str_repeat('c', 64), 'retentionReceipt' => 'provider-receipt-1']];
$retentionAck = $https->retainRevision('pbx-alpha', $retainedBlueprint, $retainedRevisionId, str_repeat('c', 64));
check(($retentionAck['ack']['hash'] ?? '') === $retainedBlueprint['contentHash'
    ] && $https->retentionEvidenceMode() === 'INJECTED_TRANSPORT',
    'HTTPS retention contract requires a content-bound durable receipt');
$resultOverride['ack']['mode'] = 'SIMULATED';
rejects(fn () => $https->retainRevision('pbx-alpha', $retainedBlueprint, $retainedRevisionId, str_repeat('c', 64)),
    'HTTPS retention rejects simulated durable acknowledgements');
$resultOverride = ['state' => 'retained', 'pbxId' => 'pbx-alpha', 'connectionId' => $https->connectionIdentity(),
    'revisionId' => $retainedRevisionId, 'publicationId' => $retainedPublicationId, 'blueprint' => $retainedBlueprint];
check($https->retrieveRevision('pbx-alpha', $retainedRevisionId)['blueprint'] === $retainedBlueprint,
    'HTTPS retrieval returns and validates the independently retained blueprint');
$resultOverride['blueprint']['operations'][0]['parameters']['description'] = 'Tampered';
rejects(fn () => $https->retrieveRevision('pbx-alpha', $retainedRevisionId),
    'HTTPS retrieval rejects a blueprint whose canonical hash no longer validates');
$resultOverride = ['state' => 'durably_retained', 'ack' => ['pbxId' => 'pbx-bravo',
    'connectionId' => $https->connectionIdentity(), 'revisionId' => $retainedRevisionId,
    'blueprintId' => 'bp-other', 'publicationId' => $retainedPublicationId, 'version' => 1,
    'hash' => $retainedBlueprint['contentHash'], 'retentionReceipt' => 'provider-receipt-1']];
rejects(fn () => $https->retainRevision('pbx-alpha', $retainedBlueprint, $retainedRevisionId, str_repeat('c', 64)),
    'HTTPS retention rejects a cross-PBX durable acknowledgement');
$syntheticSecret = bin2hex(random_bytes(24));
$secretReference = 'secret:pbx-alpha:1001:test-v1';
$resultOverride = ['state' => 'durably_escrowed', 'sourcePbxId' => 'pbx-alpha',
    'connectionId' => $https->connectionIdentity(), 'secretReference' => $secretReference,
    'idempotencyKey' => str_repeat('d', 64), 'encryptedAtRest' => true, 'keyId' => 'test-kms-key-1',
    'vaultReceipt' => 'synthetic-vault-receipt'];
$escrowReceipt = $https->escrowCredential('pbx-alpha', $secretReference, $syntheticSecret, str_repeat('d', 64));
check($escrowReceipt['encryptedAtRest'] === true && !str_contains(json_encode($escrowReceipt), $syntheticSecret),
    'credential escrow requires encrypted-at-rest receipt without returning plaintext');
$resultOverride = ['state' => 'retrieved', 'authenticatedPbxId' => 'pbx-alpha', 'sourcePbxId' => 'pbx-alpha',
    'secretReference' => $secretReference, 'secret' => $syntheticSecret, 'encryptedAtRest' => true,
    'keyId' => 'test-kms-key-1', 'vaultReceipt' => 'synthetic-vault-receipt'];
check($https->retrieveCredential('pbx-alpha', $secretReference)['secret'] === $syntheticSecret,
    'credential retrieval binds the authorised identity and immutable secret reference');
$resultOverride['encryptedAtRest'] = false;
rejects(fn () => $https->retrieveCredential('pbx-alpha', $secretReference),
    'credential retrieval rejects a vault response without encrypted-at-rest evidence');
$resultOverride = null;
$wrongIdentity = true;
rejects(fn () => $https->capabilities(), 'HTTPS adapter rejects cross-PBX response envelope');
$wrongIdentity = false;
$stale = true;
rejects(fn () => $https->status(), 'stale provider status rejected');
$stale = false;
$badTimestamp = true;
rejects(fn () => $https->capabilities(), 'relative or non-RFC3339 provider timestamp rejected');
$badTimestamp = false;
$resultOverride = ['operationId' => 'op-test', 'pbxId' => 'pbx-alpha', 'connectionId' => ConnectionIdentity::forConfiguration($configuration), 'revision' => 1, 'state' => 'ready'];
rejects(fn () => $https->getOperation('op-test'), 'ready without call/audio/configuration evidence rejected');
$resultOverride['verification'] = [];
$resultOverride['request'] = ['pbxId' => 'pbx-alpha', 'blueprintId' => 'bp-alpha', 'blueprintVersion' => 1,
    'intent' => 'recovery', 'activationPolicy' => 'manual', 'idempotencyKey' => 'original-launch-test-key-0001'];
foreach (['blueprintCoverage','reconstruction','apply','configurationMatch','connectivity','routingPreparation', 'registration', 'inboundCall', 'outboundCall', 'bidirectionalAudio'] as $checkName) {
    $resultOverride['verification'][$checkName] = ['passed' => true, 'evidenceRef' => 'synthetic-test-receipt',
        'operationId' => 'op-test', 'blueprintId' => 'bp-alpha', 'blueprintVersion' => 1,
        'observedAt' => gmdate(DATE_ATOM), 'expiresAt' => gmdate(DATE_ATOM, time() + 30)];
}
check($https->getOperation('op-test')['state'] === 'ready', 'complete synthetic readiness contract accepted, not real evidence proof');
$resultOverride['development'] = ['verificationMode' => 'SIMULATED'];
rejects(fn () => $https->getOperation('op-test'), 'production HTTPS adapter rejects a development-simulated operation');
unset($resultOverride['development']);
$resultOverride['verification']['connectivity']['mode'] = 'SIMULATED';
rejects(fn () => $https->getOperation('op-test'), 'production HTTPS adapter rejects simulated readiness evidence');
unset($resultOverride['verification']['connectivity']['mode']);
$applyReceipt = $resultOverride['verification']['apply'];
unset($resultOverride['verification']['apply']);
$missingApply = $https->getOperation('op-test');
check(($missingApply['readinessAssessment']['state'] ?? '') === 'missing', 'Object/call success without apply evidence remains visible as missing readiness.');
rejects(fn () => $https->activate('op-test', 1, 'activate:op-test'), 'Missing apply evidence blocks activation.');
$resultOverride['verification']['apply'] = $applyReceipt;
$resultOverride['verification']['apply']['blueprintVersion'] = 2;
rejects(fn () => $https->getOperation('op-test'), 'readiness evidence for another blueprint version rejected');
$resultOverride['verification']['apply'] = $applyReceipt;
rejects(fn () => $https->reconcile('different-launch-key-0002'), 'reconciliation for a different original launch key rejected');
$resultOverride['state'] = 'active';
rejects(fn () => $https->getOperation('op-test'), 'active operation needs controller authority and routing receipt');
rejects(fn () => $https->advance('op-test'), 'mock advance prohibited on HTTPS provider');