<?php
declare(strict_types=1);

use Respawn\Provider\HttpsProvider;
use Respawn\Provider\ConnectionIdentity;

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