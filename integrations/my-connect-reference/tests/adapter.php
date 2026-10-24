<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/module/bootstrap.php';
require dirname(__DIR__) . '/src/MyConnectStyleStream.php';

use Respawn\Integration\MyConnectReference\MyConnectStyleStream;
use Respawn\Provider\HttpsProvider;
use Respawn\Reconstruction\SimulatedPbx;

$configuration = ['schema'=>'respawn.provider-config.v1', 'adapterId'=>'https-json-v1',
    'endpoint'=>'https://pond.example.invalid/rpc', 'credentialRef'=>'/etc/respawn/source.token',
    'sourcePbxId'=>'pbx-reference', 'allowedHosts'=>['pond.example.invalid']];
$blueprint = SimulatedPbx::blueprint('pbx-reference');
$revisionId = 'rev-' . str_repeat('a', 32);
$publicationId = 'pub-' . str_repeat('b', 32);
$connection = '';
$transport = static function (array $request) use (&$connection, $blueprint, $revisionId, $publicationId): array {
    $connection = $request['connectionId'];
    $method = $request['method'];
    $parameters = $request['parameters'];
    $result = match ($method) {
        'retainRevision' => ['state'=>'durably_retained', 'recoveryHeadRevisionId'=>$revisionId,
            'ack'=>['pbxId'=>'pbx-reference','connectionId'=>$connection,'revisionId'=>$revisionId,
                'hash'=>$blueprint['contentHash'],'blueprintId'=>'bp-' . substr($blueprint['contentHash'], 0, 24),
                'publicationId'=>$publicationId,'idempotencyKey'=>$parameters['idempotencyKey'],
                'retentionReceipt'=>'receipt-reference-1','version'=>1]],
        'retrieveRevision', 'latestRevision' => ['state'=>'retained','pbxId'=>'pbx-reference',
            'connectionId'=>$connection,'revisionId'=>$revisionId,'publicationId'=>$publicationId,'blueprint'=>$blueprint],
        default => throw new RuntimeException('UNEXPECTED_METHOD')
    };
    return ['schema'=>'respawn.provider-rpc.v2','connectionId'=>$connection,'method'=>$method,
        'pbxId'=>'pbx-reference','ok'=>true,'observedAt'=>gmdate(DATE_ATOM),
        'expiresAt'=>gmdate(DATE_ATOM, time()+30),'result'=>$result];
};
$adapter = new MyConnectStyleStream(new HttpsProvider($configuration, $transport));
$ack = $adapter->retainOrderedRevision('pbx-reference', $blueprint, $revisionId, str_repeat('c', 64), null);
if (($ack['state'] ?? '') !== 'durably_retained' || ($ack['recoveryHeadRevisionId'] ?? '') !== $revisionId) {
    throw new RuntimeException('FAIL: reference adapter did not preserve the ordered durable acknowledgement.');
}
$retained = $adapter->retrieveLatestRevision('pbx-reference');
if (($retained['revisionId'] ?? '') !== $revisionId || ($retained['blueprint']['contentHash'] ?? '') !== $blueprint['contentHash']) {
    throw new RuntimeException('FAIL: reference adapter did not return the exact retained blueprint.');
}
if ($adapter->retentionEvidenceMode() !== 'INJECTED_TRANSPORT') {
    throw new RuntimeException('FAIL: injected test transport was reported as external provider evidence.');
}
echo "PASS: my-connect-style reference delegates the versioned Stream contract; injected transport remains non-external evidence.\n";