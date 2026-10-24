<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/RoutingControlClient.php';

use Respawn\Integration\KamailioReference\RoutingControlClient;

$configuration = ['schema'=>'respawn.routing-control-client-config.v1',
    'endpoint'=>'https://routing-control.example.invalid/respawn/routing-control',
    'allowedHosts'=>['routing-control.example.invalid'], 'credentialRef'=>'/etc/respawn/routing-control.token',
    'activationEnabled'=>false];
$base = ['schema'=>'respawn.routing-control.v1','operationId'=>'op-reference','pbxId'=>'pbx-reference',
    'targetId'=>'target-reference','blueprintHash'=>str_repeat('a',64),'expectedRevision'=>3,'authorityEpoch'=>2,
    'idempotencyKey'=>str_repeat('b',64),'readinessEvidence'=>[]];
$transport = static function (array $request): array {
    return ['schema'=>'respawn.routing-control.v1','action'=>$request['action'],
        'state'=>$request['action'] === 'prepare' ? 'prepared' : 'verified',
        'operationId'=>$request['operationId'],'pbxId'=>$request['pbxId'],'targetId'=>$request['targetId'],
        'blueprintHash'=>$request['blueprintHash'],'revision'=>$request['expectedRevision'],
        'authorityEpoch'=>$request['authorityEpoch'],'observedAt'=>gmdate(DATE_ATOM),
        'expiresAt'=>gmdate(DATE_ATOM,time()+30),'evidenceRef'=>'synthetic-control-receipt'];
};
$client = new RoutingControlClient($configuration, $transport);
$prepare = $client->request($base + ['action'=>'prepare']);
$verify = $client->request($base + ['action'=>'verify']);
if (($prepare['state'] ?? '') !== 'prepared' || ($verify['state'] ?? '') !== 'verified') {
    throw new RuntimeException('FAIL: reference routing-control responses were not accepted.');
}
$activation = $client->request($base + ['action'=>'activate']);
if (($activation['state'] ?? '') !== 'blocked' || ($activation['failureCode'] ?? '') !== 'ROUTING_ACTIVATION_NOT_IMPLEMENTED') {
    throw new RuntimeException('FAIL: reference adapter did not fail closed on activation.');
}
$staleTransport = static function (array $request): array {
    return ['schema'=>'respawn.routing-control.v1','action'=>$request['action'],'state'=>'prepared',
        'operationId'=>$request['operationId'],'pbxId'=>$request['pbxId'],'targetId'=>$request['targetId'],
        'blueprintHash'=>$request['blueprintHash'],'revision'=>$request['expectedRevision'],
        'authorityEpoch'=>$request['authorityEpoch'],'observedAt'=>gmdate(DATE_ATOM,time()-120),
        'expiresAt'=>gmdate(DATE_ATOM,time()+30)];
};
$staleRejected = false;
try { (new RoutingControlClient($configuration, $staleTransport))->request($base + ['action'=>'prepare']); }
catch (RuntimeException $exception) { $staleRejected = $exception->getMessage() === 'ROUTING_CONTROL_RECEIPT_STALE'; }
if (!$staleRejected) { throw new RuntimeException('FAIL: stale routing receipt was accepted.'); }
echo "PASS: routing-control prepare/verify bindings, stale-receipt rejection, and activation block.\n";