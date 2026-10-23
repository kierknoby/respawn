<?php
declare(strict_types=1);

use Respawn\Provider\MockProvider;
use Respawn\Storage\JsonStateStore;
use Respawn\Reconstruction\Blueprint;

$providerStore = new JsonStateStore($directory . '/provider');
$alpha = new MockProvider($providerStore, 'pbx-alpha');
$bravo = new MockProvider($providerStore, 'pbx-bravo');
$down = new MockProvider($providerStore, 'pbx-alpha', false);
check($alpha->capabilities()['realInfrastructure'] === false, 'mock capability response never claims real infrastructure');
check($down->publish('pbx-alpha', $blueprint)['state'] === 'failed', 'failed publication persists retryable attempt');
$ack = $alpha->publish('pbx-alpha', $blueprint)['ack'];
check($alpha->publish('pbx-alpha', $blueprint)['state'] === 'unchanged', 'acknowledged no-change publication suppressed');
check($alpha->publish('pbx-alpha', $blueprint, true)['ack']['version'] === 2, 'forced publication retains validation and creates acknowledgement');
$ack = $alpha->status()['publication']['ack'];
rejects(fn () => $alpha->publish('pbx-bravo', $blueprint), 'cross-PBX publication rejected');
$bravoBlueprint = Blueprint::create('pbx-bravo', $groups);
$bravo->publish('pbx-bravo', $bravoBlueprint);
check($bravo->status()['publication']['ack']['version'] === 1, 'two PBXs retain independent publication versions');
$request = ['pbxId' => 'pbx-alpha', 'blueprintId' => $ack['blueprintId'], 'blueprintVersion' => $ack['version'],
    'intent' => 'recovery', 'activationPolicy' => 'manual', 'idempotencyKey' => 'test-duplicate-key-0001'];
$operation = $alpha->launch($request);
$id = $operation['operationId'];
check($alpha->launch($request)['operationId'] === $id, 'duplicate click and lost launch response recover same operation');
rejects(fn () => $alpha->launch(array_replace($request, ['intent' => 'standby'])), 'unsafe standby activation rejected');
rejects(fn () => $bravo->getOperation($id), 'cross-PBX operation lookup rejected');
rejects(fn () => $alpha->launch(array_replace($request, ['idempotencyKey' => 'new-concurrent-key-0001'])), 'concurrent operation for same source blocked');
rejects(fn () => $down->getOperation($id), 'provider outage does not report cached authoritative success');
for ($stage = 0; $stage < 5; $stage++) { $operation = $alpha->advance($id); }
check($operation['state'] === 'ready' && $operation['configurationEvidence']['verifiedObjects'] === 2, 'mock lifecycle executes dependent reconstruction before readiness');
check((new MockProvider($providerStore, 'pbx-alpha'))->getOperation($id)['state'] === 'ready', 'refresh recovers persistent provider operation');
rejects(fn () => $alpha->activate($id, 1, 'activate:' . $id), 'stale revision activation rejected');
$operation = $alpha->activate($id, $operation['revision'], 'activate:' . $id);
check($operation['state'] === 'active' && !$operation['realCallsVerified'], 'mock activation never claims successful real calls');
rejects(fn () => $alpha->teardown($id), 'active routing teardown requires explicit disposition');
$resourceId = $operation['resources'][count($operation['resources']) - 1]['id'];
check($alpha->teardown($id, 'operator-confirmed-deactivate', $resourceId)['state'] === 'teardown_partial', 'partial cleanup retains dependencies');
check($alpha->teardown($id)['state'] === 'torn_down', 'partial teardown retry reconciles remaining owned resources');
check($alpha->teardown($id)['state'] === 'torn_down', 'teardown is idempotent');
$new = $alpha->launch(array_replace($request, ['idempotencyKey' => 'replacement-new-key-0002']));
$alpha->teardown($id);
check($alpha->getOperation($new['operationId'])['state'] === 'accepted', 'delayed old teardown cannot remove newer operation resources');
$alpha->cancel($new['operationId']);
check($alpha->teardown($new['operationId'])['state'] === 'torn_down', 'cancelled operation can be torn down');
foreach (['provisioning', 'configuration', 'connectivity', 'routing_prepared', 'ready'] as $failureStage) {
    $next = $alpha->launch(array_replace($request, ['idempotencyKey' => 'failure-test-' . $failureStage]));
    do { $next = $alpha->advance($next['operationId'], $failureStage); } while ($next['state'] !== 'failed');
    check($next['errors'][0]['stage'] === $failureStage, 'failure reported at ' . $failureStage);
    $alpha->teardown($next['operationId']);
}
check($alpha->reconcile('test-duplicate-key-0001')['operationId'] === $id, 'lookup by launch key recovers authoritative operation');
$protected = $alpha->launch(array_replace($request, ['idempotencyKey' => 'shared-resource-safety-001']));
$protectedId = $protected['operationId'];
$connectionId = $alpha->connectionIdentity();
$alpha->advance($protectedId);
$providerStore->update(static function (array &$state) use ($connectionId, $protectedId): void {
    $state['providerConnections'][$connectionId]['operations'][$protectedId]['resources'][0]['shared'] = true;
});
$alpha->cancel($protectedId);
$partial = $alpha->teardown($protectedId);
check($partial['state'] === 'teardown_partial' && $partial['resources'][0]['state'] !== 'removed', 'shared infrastructure is protected from cleanup');
$providerStore->update(static function (array &$state) use ($connectionId, $protectedId): void {
    $state['providerConnections'][$connectionId]['operations'][$protectedId]['resources'][0]['shared'] = false;
    $state['providerConnections'][$connectionId]['operations'][$protectedId]['resources'][0]['owner'] = 'op-foreign';
});
check($alpha->teardown($protectedId)['state'] === 'teardown_partial', 'foreign operation resource ownership blocks cleanup');
$providerStore->update(static function (array &$state) use ($connectionId, $protectedId): void {
    $state['providerConnections'][$connectionId]['operations'][$protectedId]['resources'][0]['owner'] = $protectedId;
});
check($alpha->teardown($protectedId)['state'] === 'torn_down', 'cleanup resumes after ownership is correctly reconciled');
removeTestTree($directory . '/provider');