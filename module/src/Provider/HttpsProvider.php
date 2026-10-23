<?php
declare(strict_types=1);
namespace Respawn\Provider;

use Respawn\Diagnostic;
use Respawn\Reconstruction\Blueprint;
use RuntimeException;

final class HttpsProvider implements Provider
{
    private string $connection;

    public function __construct(private array $configuration, private ?\Closure $transport = null)
    {
        Configuration::validate($configuration);
        $this->connection = ConnectionIdentity::forConfiguration($configuration);
    }

    public function connectionIdentity(): string { return $this->connection; }

    public function capabilities(): array
    {
        $result = $this->call('capabilities', []);
        if (($result['identity'] ?? '') !== $this->configuration['sourcePbxId']
            || ($result['authorised'] ?? false) !== true || !is_array($result['capabilities'] ?? null)) {
            throw new RuntimeException('Provider identity or capability authorisation failed.');
        }
        $capabilities = [];
        foreach ($result['capabilities'] as $capability) {
            if (!is_string($capability) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $capability)) { throw new RuntimeException('PROVIDER_CAPABILITY_INVALID'); }
            $capabilities[] = $capability;
        }
        return ['identity' => $this->configuration['sourcePbxId'], 'connectionId' => $this->connection,
            'authorised' => true, 'capabilities' => array_values(array_unique($capabilities)),
            'providerId' => is_string($result['providerId'] ?? null) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $result['providerId']) ? $result['providerId'] : 'configured-provider'];
    }

    public function status(): array
    {
        $result = $this->call('status', []);
        if (!is_array($result['operations'] ?? null)) { throw new RuntimeException('Invalid provider status.'); }
        $operations = [];
        $quarantined = [];
        foreach ($result['operations'] as $operation) {
            try { $operations[] = $this->operation($operation); }
            catch (RuntimeException $exception) {
                $operationId = is_string($operation['operationId'] ?? null)
                    && preg_match('/^op-[a-zA-Z0-9._:-]{1,100}$/D', $operation['operationId']) ? $operation['operationId'] : null;
                $quarantined[] = ['operationId' => $operationId,
                    'code' => $this->safeOperationCode($exception->getMessage()), 'actionable' => false];
            }
        }
        $safe = ['connectionId' => $this->connection, 'operations' => $operations,
            'quarantinedOperations' => $quarantined];
        if (isset($result['publication'])) { $safe['publication'] = $this->sanitisePublication($result['publication']); }
        if (isset($result['capabilities']) && is_array($result['capabilities'])) {
            $safe['capabilities'] = array_values(array_filter($result['capabilities'], static fn ($capability): bool =>
                is_string($capability) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $capability)));
        }
        if (isset($result['authorised']) && is_bool($result['authorised'])) { $safe['authorised'] = $result['authorised']; }
        if (isset($result['enrolmentState']) && in_array($result['enrolmentState'], ['not_enrolled','pending','authorised','revoked','unknown'], true)) {
            $safe['enrolmentState'] = $result['enrolmentState'];
        }
        if (isset($result['pendingChanges']) && is_bool($result['pendingChanges'])) { $safe['pendingChanges'] = $result['pendingChanges']; }
        if (isset($result['quarantinedLegacy']) && is_array($result['quarantinedLegacy'])) {
            $safe['quarantinedLegacy'] = ['requiresExplicitReconciliation' => ($result['quarantinedLegacy']['requiresExplicitReconciliation'] ?? true) === true];
        }
        return $safe;
    }

    public function publish(string $pbxId, array $intent, bool $force = false): array
    {
        if ($pbxId !== $this->configuration['sourcePbxId']) { throw new RuntimeException('Publication identity mismatch.'); }
        if (in_array($intent['schema'] ?? '', ['respawn.reconstruction.v1','respawn.reconstruction.v2'], true)) {
            Blueprint::verifyHash($intent);
            Blueprint::ordered($intent);
            if ($intent['sourcePbxId'] !== $pbxId) { throw new RuntimeException('Blueprint identity mismatch.'); }
        } else {
            $expected = \Respawn\Domain\FirewallIntent::create($pbxId, $intent['intent']['enabled'], $intent['intent']['sources'], $intent['generatedAt']);
            if ($expected !== $intent) { throw new RuntimeException('Invalid firewall intent.'); }
        }
        $result = $this->call('publish', ['pbxId' => $pbxId, 'payload' => $intent, 'force' => $force,
            'idempotencyKey' => hash('sha256', $pbxId . ':' . $intent['contentHash'] . ':' . ($force ? bin2hex(random_bytes(8)) : 'normal'))]);
        if (($result['state'] ?? '') !== 'acknowledged' || ($result['ack']['hash'] ?? '') !== $intent['contentHash']
            || ($result['ack']['pbxId'] ?? '') !== $pbxId || ($result['ack']['connectionId'] ?? '') !== $this->connection
            || !is_string($result['ack']['blueprintId'] ?? null)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $result['ack']['blueprintId'])
            || !is_int($result['ack']['version'] ?? null) || $result['ack']['version'] < 1) {
            throw new RuntimeException('Publication was not authoritatively acknowledged. Retry or reconcile the original content hash.');
        }
        return ['state' => 'acknowledged', 'ack' => ['pbxId' => $pbxId, 'connectionId' => $this->connection,
            'hash' => $result['ack']['hash'], 'blueprintId' => $result['ack']['blueprintId'],
            'version' => $result['ack']['version'], 'at' => is_string($result['ack']['at'] ?? null) ? $result['ack']['at'] : null]];
    }

    public function launch(array $request): array
    {
        if (($request['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $request['idempotencyKey'] ?? '')
            || !in_array($request['intent'] ?? '', ['rehearsal', 'standby', 'recovery'], true)
            || !in_array($request['activationPolicy'] ?? '', ['none', 'manual'], true)
            || ($request['intent'] !== 'recovery' && $request['activationPolicy'] !== 'none')) {
            throw new RuntimeException('Invalid provider operation request.');
        }
        $capabilities = $this->capabilities()['capabilities'];
        if (!in_array($request['intent'], $capabilities, true) || array_diff($request['requestedCapabilities'] ?? [], $capabilities)) {
            throw new RuntimeException('Provider does not support required operation capabilities.');
        }
        $result = $this->operation($this->call('launch', $request));
        if (($result['request'] ?? null) !== $request) { throw new RuntimeException('Provider operation receipt does not bind the exact launch request.'); }
        return $result;
    }

    public function getOperation(string $operationId): array
    {
        return $this->operation($this->call('operation', ['operationId' => $operationId]), $operationId);
    }
    public function reconcile(string $idempotencyKey): array
    {
        $result = $this->call('reconcile', ['idempotencyKey' => $idempotencyKey]);
        if (($result['state'] ?? '') === 'not_found') {
            if (($result['connectionId'] ?? '') !== $this->connection || ($result['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
                || ($result['idempotencyKey'] ?? '') !== $idempotencyKey || ($result['authoritative'] ?? false) !== true) {
                throw new RuntimeException('PROVIDER_NOT_FOUND_RESPONSE_INVALID');
            }
            return ['state' => 'not_found', 'authoritative' => true, 'connectionId' => $this->connection,
                'pbxId' => $this->configuration['sourcePbxId'], 'idempotencyKey' => $idempotencyKey];
        }
        if (($result['request']['idempotencyKey'] ?? '') !== $idempotencyKey) { throw new RuntimeException('Reconciliation receipt does not bind the requested launch key.'); }
        return $this->operation($result);
    }
    public function advance(string $operationId, ?string $failAt = null): array
    {
        throw new RuntimeException('Harness advance is prohibited for real providers.');
    }
    public function cancel(string $operationId): array
    {
        $current = $this->getOperation($operationId);
        if ($current['state'] === 'cancelled') { return $current; }
        if (!in_array($current['state'], ['submitted','accepted','provisioning','configuration','connectivity',
            'routing_prepared','ready','cancelling','unknown','rollback_pending'], true)) {
            throw new RuntimeException('OPERATION_CANCEL_STATE_INVALID');
        }
        return $this->transition('cancel', $operationId, $current['revision'], $current['state'], ['cancelling','cancelled','failed','unknown']);
    }
    public function activate(string $operationId, int $revision, string $confirmation): array
    {
        $current = $this->getOperation($operationId);
        if (($current['readinessAssessment']['state'] ?? '') === 'expired') { throw new RuntimeException('READINESS_EXPIRED'); }
        if (($current['readinessAssessment']['state'] ?? '') !== 'current') { throw new RuntimeException('READINESS_EVIDENCE_MISSING_OR_INVALID'); }
            if ($current['state'] !== 'ready' || ($current['readinessAssessment']['state'] ?? '') !== 'current'
                || $current['revision'] !== $revision
            || ($current['request']['intent'] ?? '') !== 'recovery'
            || ($current['request']['activationPolicy'] ?? '') !== 'manual'
            || $confirmation !== 'activate:' . $operationId) { throw new RuntimeException('Unsafe or stale provider activation.'); }
        return $this->transition('activate', $operationId, $revision, $current['state'], ['activating','active','failed','unknown'],
            ['expectedRevision' => $revision, 'confirmation' => $confirmation]);
    }
    public function teardown(string $operationId, ?string $routingDisposition = null): array
    {
        $current = $this->getOperation($operationId);
        if ($current['state'] === 'torn_down') { return $current; }
        if (!in_array($current['state'], ['ready','active','cancelled','failed','unknown','teardown_partial','rollback_pending'], true)) {
            throw new RuntimeException('OPERATION_TEARDOWN_STATE_INVALID');
        }
        if ($current['state'] === 'active' && $routingDisposition !== 'operator-confirmed-deactivate') {
            throw new RuntimeException('Explicit active-routing disposition is required.');
        }
        return $this->transition('teardown', $operationId, $current['revision'], $current['state'],
            ['tearing_down','teardown_partial','torn_down','failed','unknown'], ['routingDisposition' => $routingDisposition]);
    }

    private function transition(string $method, string $operationId, int $revision, string $currentState,
        array $allowedStates, array $additional = []): array
    {
        $result = $this->operation($this->call($method, ['operationId' => $operationId,
            'expectedRevision' => $revision] + $additional), $operationId);
        if ($result['revision'] <= $revision || !in_array($result['state'], $allowedStates, true)) {
            throw new RuntimeException('REVISION_CONFLICT');
        }
        $result['transition'] = ['from' => $currentState, 'to' => $result['state'], 'expectedRevision' => $revision];
        return $result;
    }

    private function operation(array $result, ?string $expectedId = null): array
    {
        if (($result['connectionId'] ?? '') !== $this->connection || ($result['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || !preg_match('/^op-[a-zA-Z0-9._:-]{1,100}$/D', $result['operationId'] ?? '')
            || ($expectedId !== null && $expectedId !== $result['operationId'])
            || !is_int($result['revision'] ?? null) || $result['revision'] < 1
            || !in_array($result['state'] ?? '', ['submitted','accepted','provisioning','configuration','connectivity',
                'routing_prepared','ready','activating','active','cancelling','cancelled','tearing_down','teardown_partial',
                'torn_down','failed','unknown','rollback_pending','rolled_back'], true)) {
            throw new RuntimeException('Invalid or identity-mismatched provider operation.');
        }
        $development = $result['development'] ?? [];
        if (($result['simulated'] ?? false) === true || ($result['developmentOnly'] ?? false) === true
            || in_array($result['mode'] ?? '', ['MOCK','SIMULATED','DEVELOPMENT'], true)
            || ($result['verificationMode'] ?? '') === 'SIMULATED'
            || (is_array($development) && ($development['verificationMode'] ?? '') === 'SIMULATED')) {
            throw new RuntimeException('PROVIDER_OPERATION_SIMULATED_EVIDENCE_REJECTED');
        }
        foreach ($result['verification'] ?? [] as $evidence) {
            if (is_array($evidence) && (($evidence['simulated'] ?? false) === true
                || ($evidence['mode'] ?? '') === 'SIMULATED' || ($evidence['verificationMode'] ?? '') === 'SIMULATED')) {
                throw new RuntimeException('PROVIDER_OPERATION_SIMULATED_EVIDENCE_REJECTED');
            }
        }
        if (in_array($result['state'], ['ready', 'active'], true)) {
            $request = $result['request'] ?? [];
            if (($request['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
                || !is_string($request['blueprintId'] ?? null) || !is_int($request['blueprintVersion'] ?? null)
                || $request['blueprintVersion'] < 1 || !in_array($request['intent'] ?? '', ['rehearsal','standby','recovery'], true)
                || !in_array($request['activationPolicy'] ?? '', ['none','manual'], true)) {
                throw new RuntimeException('Readiness lacks its original blueprint and policy binding.');
            }
            $assessment = 'current';
            foreach (['blueprintCoverage','reconstruction','apply','configurationMatch','connectivity','routingPreparation',
                'registration', 'inboundCall', 'outboundCall', 'bidirectionalAudio'] as $check) {
                $evidence = $result['verification'][$check] ?? [];
                if ($evidence === []) { $assessment = 'missing'; continue; }
                $observed = $this->timestamp($evidence['observedAt'] ?? null);
                $expires = $this->timestamp($evidence['expiresAt'] ?? null);
                if (($result['verification'][$check]['passed'] ?? false) !== true
                    || !is_string($evidence['evidenceRef'] ?? null) || $evidence['evidenceRef'] === ''
                    || ($evidence['operationId'] ?? '') !== $result['operationId']
                    || ($evidence['blueprintId'] ?? '') !== $request['blueprintId']
                    || ($evidence['blueprintVersion'] ?? 0) !== $request['blueprintVersion']
                    || $observed === false || $expires === false
                    || $observed > time() + 30 || $expires > $observed + 300) {
                    throw new RuntimeException('PROVIDER_OPERATION_READINESS_INVALID');
                }
                if ($expires <= time() || $observed < time() - 60) { $assessment = 'expired'; }
            }
            $result['readinessAssessment'] = ['state' => $assessment];
        }
        if ($result['state'] === 'active' && (empty($result['authorityEpoch']) || empty($result['activationReceipt']))) {
            throw new RuntimeException('Active provider operation lacks authority and routing receipt.');
        }
        return $this->sanitiseOperation($result);
    }

    private function sanitiseOperation(array $result): array
    {
        $safe = [];
        foreach (['operationId','pbxId','connectionId','state','revision','createdAt','updatedAt','blueprintId',
            'blueprintVersion','intent','activationPolicy','providerId','authorityEpoch','activationReceipt'] as $field) {
            if (array_key_exists($field, $result)) { $safe[$field] = $result[$field]; }
        }
        if (isset($result['request'])) {
            $request = $result['request'];
            $safe['request'] = array_intersect_key($request, array_flip(['pbxId','blueprintId','blueprintVersion',
                'intent','activationPolicy','idempotencyKey','requestedCapabilities']));
        }
        if (isset($result['resources'])) {
            if (!is_array($result['resources']) || count($result['resources']) > 256) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
            $safe['resources'] = [];
            foreach ($result['resources'] as $resource) {
                if (!is_array($resource) || !is_string($resource['id'] ?? null) || strlen($resource['id']) > 160
                    || !is_string($resource['owner'] ?? null) || !is_bool($resource['shared'] ?? null)
                    || !is_string($resource['state'] ?? null)) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
                $safe['resources'][] = array_intersect_key($resource, array_flip(['id','owner','shared','state','dependsOn','kind','createdAt','removedAt']));
            }
        }
        if (isset($result['remainingResources'])) { $safe['remainingResources'] = $safe['resources'] ?? []; }
        if (isset($result['errors'])) {
            if (!is_array($result['errors']) || count($result['errors']) > 128) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
            $safe['errors'] = [];
            foreach ($result['errors'] as $error) {
                if (!is_array($error)) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
                $code = is_string($error['code'] ?? null) && preg_match('/^[A-Z0-9_]{3,64}$/D', $error['code'])
                    ? $error['code'] : 'PROVIDER_OPERATION_FAILED';
                $stage = is_string($error['stage'] ?? null) && preg_match('/^[a-z_]{2,40}$/D', $error['stage']) ? $error['stage'] : 'provider';
                $safe['errors'][] = Diagnostic::forCode($code, $stage,
                    is_string($error['correlationId'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $error['correlationId']) ? $error['correlationId'] : null,
                    in_array($error['outcome'] ?? '', ['failed','unknown'], true) ? $error['outcome'] : 'failed',
                    ($error['retryable'] ?? false) === true);
            }
        }
        if (isset($result['milestones'])) {
            if (!is_array($result['milestones']) || count($result['milestones']) > 64) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
            $safe['milestones'] = [];
            foreach ($result['milestones'] as $name => $timestamp) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._:-]{1,64}$/D', $name)
                    || !is_string($timestamp) || $this->timestamp($timestamp) === false) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
                $safe['milestones'][$name] = $timestamp;
            }
        }
        if (isset($result['verification'])) {
            if (!is_array($result['verification']) || count($result['verification']) > 64) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
            $safe['verification'] = [];
            foreach ($result['verification'] as $name => $evidence) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._:-]{1,64}$/D', $name) || !is_array($evidence)) {
                    throw new RuntimeException('PROVIDER_OPERATION_INVALID');
                }
                $receipt = array_intersect_key($evidence, array_flip(['passed','operationId','blueprintId','blueprintVersion','observedAt','expiresAt']));
                if (isset($evidence['evidenceRef'])) {
                    if (!is_string($evidence['evidenceRef']) || !preg_match('~^[A-Za-z0-9._:/-]{1,256}$~D', $evidence['evidenceRef'])) {
                        throw new RuntimeException('PROVIDER_OPERATION_INVALID');
                    }
                    $receipt['evidenceRef'] = $evidence['evidenceRef'];
                }
                $safe['verification'][$name] = $receipt;
            }
        }
        if (isset($result['readinessAssessment'])) {
            $assessment = $result['readinessAssessment']['state'] ?? '';
            if (!in_array($assessment, ['current','expired','missing','invalid'], true)) { throw new RuntimeException('PROVIDER_OPERATION_INVALID'); }
            $safe['readinessAssessment'] = ['state' => $assessment];
        }
        return $safe;
    }

    private function sanitisePublication(array $publication): array
    {
        $safe = [];
        if (isset($publication['ack']) && is_array($publication['ack'])) {
            $ack = $publication['ack'];
            if (($ack['pbxId'] ?? '') !== $this->configuration['sourcePbxId'] || ($ack['connectionId'] ?? '') !== $this->connection
                || !is_string($ack['hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $ack['hash'])
                || !is_string($ack['blueprintId'] ?? null) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $ack['blueprintId'])
                || !is_int($ack['version'] ?? null) || $ack['version'] < 1) { throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID'); }
            $safe['ack'] = ['pbxId' => $this->configuration['sourcePbxId'], 'connectionId' => $this->connection,
                'hash' => $ack['hash'], 'blueprintId' => $ack['blueprintId'], 'version' => $ack['version'],
                'at' => is_string($ack['at'] ?? null) ? $ack['at'] : null];
        }
        if (isset($publication['export']) && is_array($publication['export'])) {
            try {
                if (in_array($publication['export']['schema'] ?? '', ['respawn.reconstruction.v1','respawn.reconstruction.v2'], true)) {
                    Blueprint::verifyHash($publication['export']); Blueprint::ordered($publication['export']);
                    if (($publication['export']['sourcePbxId'] ?? '') !== $this->configuration['sourcePbxId']) { throw new RuntimeException('BLUEPRINT_SOURCE_IDENTITY_MISMATCH'); }
                    $safe['export'] = $publication['export'];
                } elseif (($publication['export']['schema'] ?? '') === 'respawn.firewall-intent.v1'
                    && ($publication['export']['pbxId'] ?? '') === $this->configuration['sourcePbxId']) {
                    $safe['export'] = $publication['export'];
                }
            } catch (\Throwable $exception) { throw new RuntimeException('PROVIDER_PUBLICATION_EXPORT_INVALID'); }
        }
        if (isset($publication['attempt']) && is_array($publication['attempt'])) {
            $attempt = $publication['attempt'];
            $safe['attempt'] = array_intersect_key($attempt, array_flip(['at','contentHash','hash','state','force']));
            if (isset($safe['attempt']['state']) && !in_array($safe['attempt']['state'], ['attempted','failed','acknowledged','unchanged','outcome_unknown','submitted_outcome_unknown'], true)) {
                $safe['attempt']['state'] = 'unknown';
            }
        }
        return $safe;
    }

    private function call(string $method, array $parameters): array
    {
        $request = ['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $this->connection,
            'method' => $method, 'pbxId' => $this->configuration['sourcePbxId'], 'parameters' => $parameters];
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) { throw new RuntimeException('Provider request exceeds 64 KiB.'); }
        $response = $this->transport ? ($this->transport)($request) : $this->https($encoded);
        $observed = is_array($response) ? $this->timestamp($response['observedAt'] ?? null) : false;
        $expires = is_array($response) ? $this->timestamp($response['expiresAt'] ?? null) : false;
        if (!is_array($response) || ($response['schema'] ?? '') !== 'respawn.provider-rpc.v2'
            || ($response['connectionId'] ?? '') !== $this->connection
            || ($response['pbxId'] ?? '') !== $this->configuration['sourcePbxId']
            || ($response['method'] ?? '') !== $method || ($response['ok'] ?? false) !== true
            || !is_array($response['result'] ?? null) || $observed === false || $expires === false
            || $observed > time() + 30 || $observed < time() - 60 || $expires <= time() || $expires > $observed + 300) {
            throw new RuntimeException('Provider response rejected; authoritative outcome is unknown.');
        }
        return $response['result'];
    }

    private function safeOperationCode(string $message): string
    {
        return preg_match('/^[A-Z0-9_]{3,64}$/D', $message) ? $message : 'PROVIDER_OPERATION_INVALID';
    }

    private function timestamp(mixed $value): int|false
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) { return false; }
        $normal = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normal);
        return $date && $date->format('Y-m-d\TH:i:sP') === $normal ? $date->getTimestamp() : false;
    }

    private function https(string $body): array
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('PHP cURL extension is required.'); }
        $path = $this->configuration['credentialRef'];
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) {
            throw new RuntimeException('Credential reference is not a protected file.');
        }
        $token = trim((string) file_get_contents($path));
        if (!preg_match('/^[a-zA-Z0-9._~-]{16,4096}$/D', $token)) { throw new RuntimeException('Invalid credential file.'); }
        $url = $this->configuration['endpoint'];
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host);
        if (!$addresses) { throw new RuntimeException('Provider DNS resolution failed.'); }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('Non-public provider address rejected by the initial transport profile.');
            }
        }
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $handle = curl_init($url);
        $response = '';
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $addresses[0]],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            }]);
        try {
            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($success === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Provider transport failed; reconcile operation before resubmission.');
            }
        } finally { curl_close($handle); }
        $result = json_decode($response, true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new RuntimeException('Invalid provider JSON.'); }
        return $result;
    }
}