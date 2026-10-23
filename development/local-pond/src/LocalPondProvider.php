<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Provider\Provider;
use Respawn\Provider\RetainedBlueprintProvider;
use Respawn\Reconstruction\Blueprint;
use RuntimeException;

final class LocalPondProvider implements Provider, RetainedBlueprintProvider
{
    private string $connectionId;
    private bool $dropNextLaunchResponse;

    public function __construct(private readonly array $configuration)
    {
        $this->validateConfiguration();
        $this->connectionId = 'local-pond-dev-v1:' . hash('sha256', json_encode([
            'adapter' => 'respawn.provider-rpc.v2', 'endpoint' => rtrim($configuration['endpoint'], '/'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->dropNextLaunchResponse = ($configuration['dropNextLaunchResponse'] ?? false) === true;
    }

    public function connectionIdentity(): string { return $this->connectionId; }

    public function capabilities(): array
    {
        $result = $this->call('capabilities', []);
        if (($result['identity'] ?? '') !== $this->configuration['sourcePbxId']
            || ($result['authorised'] ?? false) !== true || !is_array($result['capabilities'] ?? null)) {
            throw new RuntimeException('PROVIDER_CAPABILITY_INVALID');
        }
        return ['identity' => $result['identity'], 'connectionId' => $this->connectionId,
            'providerId' => 'local-pond-development', 'authorised' => true,
            'capabilities' => array_values(array_filter($result['capabilities'], static fn ($value): bool =>
                is_string($value) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $value)))];
    }

    public function status(): array
    {
        $result = $this->call('status', []);
        if (!is_array($result['operations'] ?? null)) { throw new RuntimeException('PROVIDER_RESPONSE_REJECTED'); }
        $operations = [];
        foreach ($result['operations'] as $operation) { $operations[] = $this->operation($operation); }
        return ['connectionId' => $this->connectionId, 'operations' => $operations,
            'publication' => $result['publication'] ?? null,
            'capabilities' => $result['capabilities'] ?? [], 'authorised' => true,
            'enrolmentState' => 'authorised'];
    }

    public function publish(string $pbxId, array $intent, bool $force = false): array
    {
        if ($pbxId !== $this->configuration['sourcePbxId']) { throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID'); }
        Blueprint::verifyHash($intent);
        Blueprint::ordered($intent);
        if (($intent['sourcePbxId'] ?? '') !== $pbxId) { throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID'); }
        $result = $this->call('publish', ['pbxId' => $pbxId, 'payload' => $intent, 'force' => $force,
            'idempotencyKey' => hash('sha256', $pbxId . ':' . $intent['contentHash'] . ':' . ($force ? bin2hex(random_bytes(8)) : 'normal'))]);
        $ack = $result['ack'] ?? [];
        if (($result['state'] ?? '') !== 'acknowledged' || ($ack['pbxId'] ?? '') !== $pbxId
            || ($ack['connectionId'] ?? '') !== $this->connectionId || ($ack['hash'] ?? '') !== $intent['contentHash']
            || !is_string($ack['blueprintId'] ?? null) || !is_int($ack['version'] ?? null) || $ack['version'] < 1) {
            throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID');
        }
        return ['state' => 'acknowledged', 'ack' => $ack];
    }

    public function retainRevision(string $pbxId, array $blueprint, string $revisionId, string $idempotencyKey): array
    {
        if ($pbxId !== $this->configuration['sourcePbxId']) { throw new RuntimeException('PROVIDER_RETENTION_REQUEST_INVALID'); }
        Blueprint::verifyHash($blueprint);
        Blueprint::ordered($blueprint);
        $result = $this->call('retainRevision', ['pbxId' => $pbxId, 'revisionId' => $revisionId,
            'contentHash' => $blueprint['contentHash'], 'payload' => $blueprint, 'idempotencyKey' => $idempotencyKey]);
        $ack = $result['ack'] ?? null;
        if (($result['state'] ?? '') !== 'durably_retained' || !is_array($ack)
            || ($ack['pbxId'] ?? '') !== $pbxId || ($ack['connectionId'] ?? '') !== $this->connectionId
            || ($ack['revisionId'] ?? '') !== $revisionId || ($ack['hash'] ?? '') !== $blueprint['contentHash']
            || !is_string($ack['publicationId'] ?? null) || !is_string($ack['retentionReceipt'] ?? null)
            || ($ack['idempotencyKey'] ?? '') !== $idempotencyKey
            || !is_int($ack['version'] ?? null) || $ack['version'] < 1) {
            throw new RuntimeException('PROVIDER_DURABLE_ACK_INVALID');
        }
        return ['state' => 'durably_retained', 'ack' => $ack];
    }

    public function retrieveRevision(string $pbxId, string $revisionId): array
    {
        if ($pbxId !== $this->configuration['sourcePbxId']) { throw new RuntimeException('PROVIDER_RETRIEVAL_REQUEST_INVALID'); }
        $result = $this->call('retrieveRevision', ['pbxId' => $pbxId, 'revisionId' => $revisionId]);
        $blueprint = $result['blueprint'] ?? null;
        if (($result['state'] ?? '') !== 'retained' || ($result['pbxId'] ?? '') !== $pbxId
            || ($result['connectionId'] ?? '') !== $this->connectionId || ($result['revisionId'] ?? '') !== $revisionId
            || !is_string($result['publicationId'] ?? null) || !is_array($blueprint)) {
            throw new RuntimeException('PROVIDER_RETRIEVAL_BINDING_INVALID');
        }
        Blueprint::verifyHash($blueprint);
        return ['pbxId' => $pbxId, 'connectionId' => $this->connectionId, 'revisionId' => $revisionId,
            'publicationId' => $result['publicationId'], 'blueprint' => $blueprint, 'mode' => 'SIMULATED'];
    }

    public function retentionEvidenceMode(): string { return 'SIMULATED'; }

    public function launch(array $request): array
    {
        if (($request['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $request['idempotencyKey'] ?? '')) {
            throw new RuntimeException('PROVIDER_OPERATION_INVALID');
        }
        $operation = $this->operation($this->call('launch', $request));
        if ($this->dropNextLaunchResponse) {
            $this->dropNextLaunchResponse = false;
            throw new RuntimeException('LOCAL_DEMO_ACCEPTED_RESPONSE_LOST');
        }
        if (($operation['request'] ?? null) !== $request) { throw new RuntimeException('PROVIDER_RESPONSE_REJECTED'); }
        return $operation;
    }

    public function getOperation(string $operationId): array
    {
        return $this->operation($this->call('operation', ['operationId' => $operationId]), $operationId);
    }

    public function reconcile(string $idempotencyKey): array
    {
        $result = $this->call('reconcile', ['idempotencyKey' => $idempotencyKey]);
        if (($result['state'] ?? '') === 'not_found') {
            if (($result['authoritative'] ?? false) !== true || ($result['connectionId'] ?? '') !== $this->connectionId
                || ($result['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
                || ($result['idempotencyKey'] ?? '') !== $idempotencyKey) {
                throw new RuntimeException('PROVIDER_RESPONSE_REJECTED');
            }
            return $result;
        }
        if (($result['request']['idempotencyKey'] ?? '') !== $idempotencyKey) { throw new RuntimeException('PROVIDER_RESPONSE_REJECTED'); }
        return $this->operation($result);
    }

    public function advance(string $operationId, ?string $failAt = null): array
    {
        throw new RuntimeException('OPERATOR_ACTION_INVALID');
    }

    public function cancel(string $operationId): array
    {
        $operation = $this->getOperation($operationId);
        if (in_array($operation['state'], ['cancelled', 'failed', 'torn_down'], true)) { return $operation; }
        return $this->operation($this->call('cancel', ['operationId' => $operationId,
            'expectedRevision' => $operation['revision']]));
    }

    public function activate(string $operationId, int $revision, string $confirmation): array
    {
        return $this->operation($this->call('activate', ['operationId' => $operationId,
            'expectedRevision' => $revision, 'confirmation' => $confirmation]));
    }

    public function teardown(string $operationId, ?string $routingDisposition = null): array
    {
        $current = $this->getOperation($operationId);
        return $this->operation($this->call('teardown', ['operationId' => $operationId,
            'expectedRevision' => $current['revision'], 'routingDisposition' => $routingDisposition]));
    }

    private function operation(mixed $value, ?string $expectedId = null): array
    {
        if (!is_array($value) || ($value['connectionId'] ?? '') !== $this->connectionId
            || ($value['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || !preg_match('/^op-[a-zA-Z0-9._:-]{1,100}$/D', $value['operationId'] ?? '')
            || ($expectedId !== null && $value['operationId'] !== $expectedId)
            || !is_int($value['revision'] ?? null) || $value['revision'] < 1
            || !in_array($value['state'] ?? '', ['accepted','provisioning','configuration','connectivity',
                'routing_prepared','active','cancelling','cancelled','failed','teardown_partial','torn_down'], true)
            || !is_array($value['request'] ?? null)) {
            throw new RuntimeException('PROVIDER_RESPONSE_REJECTED');
        }
        $safe = array_intersect_key($value, array_flip(['operationId','connectionId','pbxId','request','blueprintId',
            'blueprintVersion','state','revision','createdAt','updatedAt','resources','errors','milestones','development']));
        if (($safe['development']['verificationMode'] ?? '') !== 'SIMULATED') {
            throw new RuntimeException('LOCAL_DEMO_EVIDENCE_MODE_INVALID');
        }
        return $safe;
    }

    private function call(string $method, array $parameters): array
    {
        $request = ['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $this->connectionId,
            'method' => $method, 'pbxId' => $this->configuration['sourcePbxId'], 'parameters' => $parameters];
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536) { throw new RuntimeException('OPERATOR_REQUEST_TOO_LARGE'); }
        $token = $this->readToken();
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' =>
            "Content-Type: application/json\r\nAuthorization: Bearer " . $token . "\r\nConnection: close\r\n",
            'content' => $body, 'timeout' => 5, 'ignore_errors' => true, 'protocol_version' => 1.1]]);
        $responseBody = @file_get_contents($this->configuration['endpoint'], false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($responseBody)) { throw new RuntimeException('PROVIDER_CONNECTION_FAILED'); }
        try { $response = json_decode($responseBody, true, 24, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('PROVIDER_RESPONSE_REJECTED'); }
        $now = time();
        $observed = $this->timestamp($response['observedAt'] ?? null);
        $expires = $this->timestamp($response['expiresAt'] ?? null);
        if (!is_array($response) || ($response['schema'] ?? '') !== 'respawn.provider-rpc.v2'
            || ($response['connectionId'] ?? '') !== $this->connectionId
            || ($response['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || ($response['method'] ?? '') !== $method || !is_bool($response['ok'] ?? null)
            || $observed === false || $expires === false
            || $observed > $now + 30 || $observed < $now - 60 || $expires <= $now || $expires > $observed + 300) {
            throw new RuntimeException('PROVIDER_RESPONSE_REJECTED');
        }
        if ($response['ok'] === false) {
            if (preg_match('/^[A-Z0-9_]{3,64}$/D', $response['error']['code'] ?? '')) {
                throw new RuntimeException($response['error']['code']);
            }
            throw new RuntimeException('PROVIDER_RESPONSE_REJECTED');
        }
        if (!preg_match('/\s2[0-9]{2}\s/', $statusLine)) { throw new RuntimeException('PROVIDER_CONNECTION_FAILED'); }
        if (!is_array($response['result'] ?? null)) { throw new RuntimeException('PROVIDER_RESPONSE_REJECTED'); }
        return $response['result'];
    }

    private function readToken(): string
    {
        $path = $this->configuration['credentialRef'];
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) {
            throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_UNAVAILABLE');
        }
        $token = trim((string) file_get_contents($path));
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_INVALID'); }
        return $token;
    }

    private function validateConfiguration(): void
    {
        $url = parse_url($this->configuration['endpoint'] ?? '');
        $identity = $this->configuration['sourcePbxId'] ?? '';
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !is_int($url['port'] ?? null) || $url['port'] < 1024 || $url['port'] > 65535
            || !preg_match('~^/(?:alternate/)?rpc$~D', $url['path'] ?? '')
            || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $identity)
            || !is_string($this->configuration['credentialRef'] ?? null)) {
            throw new RuntimeException('LOCAL_DEMO_LOOPBACK_CONFIGURATION_INVALID');
        }
    }

    private function timestamp(mixed $value): int|false
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        return $date && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date->getTimestamp() : false;
    }
}