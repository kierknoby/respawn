<?php
declare(strict_types=1);
namespace Respawn;

use Respawn\Domain\FirewallIntent;
use Respawn\Firewall\FirewallSource;
use Respawn\Provider\ConnectionIdentity;
use Respawn\Provider\MockProvider;
use Respawn\Provider\Provider;
use Respawn\Reconstruction\Blueprint;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class Application
{
    public function __construct(private array $settings, private JsonStateStore $store, private FirewallSource $firewall,
        private ?Provider $provider = null, private ?\Closure $exportBlueprint = null,
        private ?\Closure $providerResolver = null) {}

    public static function fixtureBlueprint(string $identity): array
    {
        return Blueprint::create($identity, [
            ['number' => '600', 'description' => 'Primary', 'strategy' => 'ringall', 'timeout' => 20, 'members' => ['1001'], 'destination' => '601'],
            ['number' => '601', 'description' => 'Fallback', 'strategy' => 'ringall', 'timeout' => 15, 'members' => ['1002'], 'destination' => 'hangup'],
        ]);
    }

    public function run(string $command, array $arguments = []): array
    {
        $identity = $this->settings['identity'] ?? 'unconfigured';
        $mode = $this->settings['mode'] ?? 'disabled';
        $connectionId = $this->connectionId();
        $scope = self::scope($connectionId, $identity);
        $this->registerConnection($connectionId, $identity, $mode);
        $label = $mode === 'mock' ? 'MOCK / SYNTHETIC DATA / NO LIVE INFRASTRUCTURE'
            : ($this->provider ? 'PROVIDER CONTRACT ADAPTER / REAL INTEGRATION UNVERIFIED' : 'PROVIDER EXECUTION DISABLED');
        if ($command === 'export') {
            return FirewallIntent::create($identity, true, $this->firewall->trustedIpv4Sources(), gmdate(DATE_ATOM));
        }
        if ($command === 'verify') {
            return ['label' => $label, 'firewall' => $this->firewall->availability(),
                'configurationReconstruction' => 'contract-tested only', 'realTakeoverVerified' => false,
                'supportedChain' => 'selected Core users and fixed PJSIP devices -> dependent Ring Groups -> final apply/read-back',
                'gaps' => ['unselected extensions/devices', 'trunks', 'routes', 'voicemail', 'licences', 'media/history', 'real provider integration', 'live call and audio verification']];
        }
        $managementActions = ['status','reconcileSubmission','retrySubmission','operation','cancel','teardown','activate'];
        if ($mode !== 'mock' && $this->provider === null && !in_array($command, $managementActions, true)) {
            throw new RuntimeException('No production provider adapter is installed. Select explicit mock mode for synthetic demonstrations.');
        }
        if ($command === 'status' && $this->provider === null && $mode !== 'mock') {
            return $this->localOnlyStatus($connectionId, $identity, $label);
        }
        $provider = $this->provider ?? (($mode === 'mock') ? $this->providerFor($connectionId) : null);
        return match ($command) {
            'capabilities' => $provider->capabilities(),
            'status' => $this->statusWithFallback($provider, $connectionId, $identity, $label),
            'blueprint' => $this->blueprint($identity),
            'publish' => $this->publish($provider, $identity, $connectionId,
                ($arguments['area'] ?? 'blueprint') === 'firewall' ? $this->run('export') : $this->blueprint($identity),
                (bool) ($arguments['force'] ?? false)),
            'respawn' => $this->launch($provider, $arguments, $connectionId, $identity, $scope),
            'operation' => $this->manageOperation('operation', $arguments, $identity),
            'reconcile' => $provider?->reconcile($arguments['key'] ?? '') ?? throw new RuntimeException('ORIGINAL_PROVIDER_CONNECTION_UNAVAILABLE'),
            'reconcileSubmission' => $this->reconcileSubmission($arguments['submissionId'] ?? '', $identity),
            'retrySubmission' => $this->retrySubmission($arguments['submissionId'] ?? '', $identity),
            'advance' => $provider->advance($arguments['id'] ?? '', $arguments['failAt'] ?? null),
            'cancel' => $this->manageOperation('cancel', $arguments, $identity),
            'teardown' => $this->manageOperation('teardown', $arguments, $identity),
            'activate' => $this->manageOperation('activate', $arguments, $identity),
            default => throw new RuntimeException('Unsupported command.'),
        };
    }

    public function runSafely(string $command, array $arguments = []): array
    {
        try { return $this->run($command, $arguments); }
        catch (\Throwable $exception) {
            $reference = is_string($arguments['submissionId'] ?? null) ? $arguments['submissionId']
                : (is_string($arguments['id'] ?? null) ? $arguments['id'] : null);
            $diagnostic = Diagnostic::forThrowable($exception, $command, $reference);
            Diagnostic::log($diagnostic);
                $this->store->update(static function (array &$state) use ($diagnostic): void {
                    $state['diagnostics'][$diagnostic['correlationId']] = $diagnostic + ['recordedAt' => gmdate(DATE_ATOM)];
                });
            return ['state' => $diagnostic['outcome'], 'error' => $diagnostic];
        }
    }

    public function publicationSnapshot(array $acknowledgement): array
    {
        $identity = $this->settings['identity'] ?? '';
        $connectionId = $acknowledgement['connectionId'] ?? null;
        $hash = $acknowledgement['hash'] ?? null;
        if (!is_string($identity) || $identity === '' || !is_string($connectionId) || $connectionId === ''
            || !is_string($hash) || $hash === '') {
            throw new RuntimeException('PUBLICATION_SNAPSHOT_BINDING_INVALID');
        }
        $scope = self::scope($connectionId, $identity);
        $publication = $this->store->update(static fn (array &$state): ?array => $state['localPublications'][$scope] ?? null);
        $blueprint = $publication['export'] ?? null;
        if (($publication['pbxId'] ?? '') !== $identity || ($publication['connectionId'] ?? '') !== $connectionId
            || !is_array($blueprint) || ($blueprint['sourcePbxId'] ?? '') !== $identity
            || ($blueprint['contentHash'] ?? '') !== $hash
            || ($publication['ack']['pbxId'] ?? '') !== $identity
            || ($publication['ack']['connectionId'] ?? '') !== $connectionId
            || ($publication['ack']['hash'] ?? '') !== $hash
            || !is_string($acknowledgement['blueprintId'] ?? null)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $acknowledgement['blueprintId'])
            || ($publication['ack']['blueprintId'] ?? '') !== $acknowledgement['blueprintId']
            || !is_int($acknowledgement['version'] ?? null) || $acknowledgement['version'] < 1
            || ($publication['ack']['version'] ?? null) !== ($acknowledgement['version'] ?? null)) {
            throw new RuntimeException('PUBLICATION_SNAPSHOT_BINDING_INVALID');
        }
        Blueprint::verifyHash($blueprint);
        return ['blueprint' => $blueprint, 'connectionId' => $connectionId];
    }

    private function blueprint(string $identity): array
    {
        if (($this->settings['mode'] ?? '') === 'mock') { return \Respawn\Reconstruction\SimulatedPbx::blueprint($identity); }
        if ($this->exportBlueprint === null) { throw new RuntimeException('No real reconstruction exporter configured.'); }
        $document = ($this->exportBlueprint)();
        Blueprint::verifyHash($document);
        Blueprint::ordered($document);
        return $document;
    }

    private function connectionId(): string
    {
        if ($this->provider !== null) { return $this->provider->connectionIdentity(); }
        return ConnectionIdentity::forConfiguration($this->settings['providerConfiguration'] ?? [], $this->settings['mode'] ?? 'disabled');
    }

    private static function scope(string $connectionId, string $pbxId): string
    {
        return hash('sha256', $connectionId . "\0" . $pbxId);
    }

    private function registerConnection(string $connectionId, string $identity, string $mode): void
    {
        $scope = self::scope($connectionId, $identity);
        $publicConfig = ['mode' => $mode, 'pbxId' => $identity];
        if ($mode === 'provider' && is_array($this->settings['providerConfiguration'] ?? null)) {
            $publicConfig['providerConfiguration'] = $this->settings['providerConfiguration'];
        }
        $this->store->update(function (array &$state) use ($connectionId, $identity, $scope, $publicConfig): void {
            $state['providerConnections'][$scope] = ['connectionId' => $connectionId, 'pbxId' => $identity,
                'configuration' => $publicConfig, 'lastUsedAt' => gmdate(DATE_ATOM)];
            foreach ($state['localPublications'] ?? [] as $oldKey => $publication) {
                if (!isset($publication['connectionId']) && (($publication['pbxId'] ?? null) === $identity || !isset($publication['pbxId']))) {
                    $state['legacyQuarantine']['publications'][$oldKey] ??= ['pbxId' => $publication['pbxId'] ?? null,
                        'state' => 'unscoped_requires_reconciliation', 'reason' => 'provider connection identity was not recorded', 'record' => $publication];
                }
            }
            foreach ($state['submissions'] ?? [] as $key => $submission) {
                $legacyPbx = $submission['pbxId'] ?? $submission['request']['pbxId'] ?? null;
                if (!isset($submission['connectionId']) && ($legacyPbx === $identity || $legacyPbx === null)) {
                    $state['legacyQuarantine']['submissions'][$key] ??= ['pbxId' => $legacyPbx, 'state' => 'unscoped_requires_reconciliation',
                        'reason' => 'provider connection identity was not recorded', 'record' => $submission];
                }
            }
            foreach ($state['operations'] ?? [] as $key => $operation) {
                    $legacyPbx = $operation['pbxId'] ?? null;
                    if (!isset($operation['connectionId']) && ($legacyPbx === $identity || $legacyPbx === null)) {
                        $state['legacyQuarantine']['operations'][$key] ??= ['pbxId' => $legacyPbx,
                        'state' => 'unscoped_requires_reconciliation', 'reason' => 'provider connection identity was not recorded', 'record' => $operation];
                }
            }
        });
    }

    private function providerFor(string $connectionId): Provider
    {
        if ($this->provider !== null && hash_equals($this->provider->connectionIdentity(), $connectionId)) { return $this->provider; }
        $record = $this->store->update(function (array &$state) use ($connectionId): ?array {
            foreach ($state['providerConnections'] ?? [] as $connection) {
                if (($connection['connectionId'] ?? '') === $connectionId) { return $connection; }
            }
            return null;
        });
        if ($record === null) { throw new RuntimeException('ORIGINAL_PROVIDER_CONNECTION_RECORD_MISSING'); }
        if ($this->providerResolver !== null) {
            $provider = ($this->providerResolver)($record);
            if (!$provider instanceof Provider || !hash_equals($provider->connectionIdentity(), $connectionId)) {
                throw new RuntimeException('PROVIDER_CONNECTION_IDENTITY_MISMATCH');
            }
            return $provider;
        }
        if (($record['configuration']['mode'] ?? '') === 'mock') {
            return new MockProvider($this->store, $record['pbxId'], true, null, $connectionId);
        }
        if (($record['configuration']['mode'] ?? '') === 'provider'
            && is_array($record['configuration']['providerConfiguration'] ?? null)) {
            $provider = new \Respawn\Provider\HttpsProvider($record['configuration']['providerConfiguration']);
            if (!hash_equals($provider->connectionIdentity(), $connectionId)) { throw new RuntimeException('PROVIDER_CONNECTION_IDENTITY_MISMATCH'); }
            return $provider;
        }
        throw new RuntimeException('ORIGINAL_PROVIDER_CONNECTION_UNAVAILABLE');
    }

    private function publish(Provider $provider, string $identity, string $connectionId, array $document, bool $force): array
    {
        $hash = $document['contentHash'];
        $scope = self::scope($connectionId, $identity);
        $previous = $this->store->update(static fn (array &$state): ?array => $state['localPublications'][$scope]['ack'] ?? null);
        if (!$force && ($previous['hash'] ?? '') === $hash) {
            return ['state' => 'unchanged', 'ack' => $previous];
        }
        $this->store->update(static function (array &$state) use ($identity, $connectionId, $scope, $document, $hash): void {
            $entry = &$state['localPublications'][$scope];
            $entry['pbxId'] = $identity;
            $entry['connectionId'] = $connectionId;
            $entry['blueprintId'] = $document['contentHash'];
            $entry['blueprintVersion'] = 2;
            $entry['export'] = $document;
            $entry['attempt'] = ['at' => gmdate(DATE_ATOM), 'hash' => $hash, 'state' => 'submitted_outcome_unknown'];
        });
        try { $result = $provider->publish($identity, $document, $force); }
        catch (\Throwable $exception) {
            $this->store->update(static function (array &$state) use ($scope): void {
                $state['localPublications'][$scope]['attempt']['state'] = 'outcome_unknown';
            });
            throw $exception;
        }
        $this->store->update(static function (array &$state) use ($scope, $connectionId, $result): void {
            $state['localPublications'][$scope]['attempt']['state'] = $result['state'];
            $state['localPublications'][$scope]['attempt']['connectionId'] = $connectionId;
            if (isset($result['ack'])) { $state['localPublications'][$scope]['ack'] = $result['ack']; }
        });
        return $result;
    }

    private function launch(Provider $provider, array $arguments, string $connectionId, string $identity, string $scope): array
    {
        $key = $arguments['key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $key)) {
            throw new RuntimeException('A stable idempotency key is required.');
        }
        $submissionId = self::submissionId($scope, $key);
        $prior = $this->store->update(static function (array &$state) use ($submissionId): ?array {
            return $state['submissions'][$submissionId]['originalRequest'] ?? null;
        });
        if ($prior !== null) { throw new RuntimeException('SUBMISSION_ALREADY_EXISTS_USE_RECONCILE_OR_EXPLICIT_RETRY'); }
        if ($prior === null) { $this->assertNoUnresolvedOtherConnection($identity, $connectionId, $key); }
        $ack = $provider->status()['publication']['ack'] ?? null;
        if (!$ack && !$prior) { throw new RuntimeException('Publish and acknowledge a reconstruction blueprint first.'); }
        $blueprint = $prior ? null : $this->blueprint($identity);
        if (!$prior && ($ack['hash'] ?? '') !== $blueprint['contentHash']) {
            throw new RuntimeException('Pending reconstruction changes must be acknowledged before launch.');
        }
        $request = $prior ?? ['pbxId' => $this->settings['identity'], 'blueprintId' => $ack['blueprintId'],
            'blueprintVersion' => $ack['version'], 'intent' => $this->settings['intent'] ?? 'rehearsal',
            'activationPolicy' => $this->settings['activationPolicy'] ?? 'none',
            'idempotencyKey' => $key, 'requestedCapabilities' => ($blueprint['schema'] ?? '') === 'respawn.reconstruction.v2' ? ['reconstruction.v2','apply.freepbx'] : []];
        $this->store->update(static function (array &$state) use ($submissionId, $request, $connectionId): void {
            $state['submissions'][$submissionId] = ['submissionId' => $submissionId, 'connectionId' => $connectionId,
                'pbxId' => $request['pbxId'], 'blueprintId' => $request['blueprintId'],
                'blueprintVersion' => $request['blueprintVersion'], 'intent' => $request['intent'],
                'activationPolicy' => $request['activationPolicy'], 'idempotencyKey' => $request['idempotencyKey'],
                'originalRequest' => $request, 'state' => 'submitted_outcome_unknown', 'submittedAt' => gmdate(DATE_ATOM)];
        });
        try { $operation = $provider->launch($request); }
        catch (\Throwable $exception) {
            $this->store->update(static function (array &$state) use ($submissionId): void {
                if (isset($state['submissions'][$submissionId])) { $state['submissions'][$submissionId]['state'] = 'launch_outcome_unknown'; }
            });
            throw $exception;
        }
        $this->store->update(static function (array &$state) use ($submissionId, $connectionId, $operation): void {
            $state['submissions'][$submissionId]['state'] = 'authoritative_response_received';
            $state['submissions'][$submissionId]['operationId'] = $operation['operationId'];
            $state['operationRefs'][$connectionId . ':' . $operation['operationId']] = ['connectionId' => $connectionId,
                'pbxId' => $operation['pbxId'], 'operation' => $operation, 'lastSeenAt' => gmdate(DATE_ATOM)];
        });
        return $operation;
    }

    private static function submissionId(string $scope, string $key): string { return 'sub-' . hash('sha256', $scope . "\0" . $key); }

    private function reconcileSubmission(string $submissionId, string $identity): array
    {
        if (!preg_match('/^sub-[a-f0-9]{64}$/D', $submissionId)) { throw new RuntimeException('SUBMISSION_ID_INVALID'); }
        $entry = $this->store->update(static fn (array &$state): ?array => $state['submissions'][$submissionId] ?? null);
        if (!$entry || ($entry['pbxId'] ?? '') !== $identity || !isset($entry['connectionId'], $entry['originalRequest'])) {
            throw new RuntimeException('SUBMISSION_NOT_OWNED_OR_QUARANTINED');
        }
        if (($entry['state'] ?? '') === 'authoritative_not_found') { return $entry + ['state' => 'authoritative_not_found']; }
        $provider = $this->providerFor($entry['connectionId']);
        $operation = $provider->reconcile($entry['originalRequest']['idempotencyKey']);
        if (($operation['state'] ?? '') === 'not_found' && ($operation['authoritative'] ?? false) === true
            && ($operation['connectionId'] ?? '') === $entry['connectionId']
            && ($operation['pbxId'] ?? '') === $identity
            && ($operation['idempotencyKey'] ?? '') === $entry['originalRequest']['idempotencyKey']) {
            $updated = array_replace($entry, ['state' => 'authoritative_not_found', 'lastReconciledAt' => gmdate(DATE_ATOM)]);
        } else {
            $updated = array_replace($entry, ['state' => 'authoritative_response_received', 'operationId' => $operation['operationId'],
                'lastReconciledAt' => gmdate(DATE_ATOM)]);
            $this->store->update(static function (array &$state) use ($entry, $operation): void {
                $state['operationRefs'][$entry['connectionId'] . ':' . $operation['operationId']] = [
                    'connectionId' => $entry['connectionId'], 'pbxId' => $entry['pbxId'], 'operation' => $operation,
                    'lastSeenAt' => gmdate(DATE_ATOM)];
            });
        }
        $this->store->update(static function (array &$state) use ($submissionId, $updated): void { $state['submissions'][$submissionId] = $updated; });
        return $updated;
    }

    private function retrySubmission(string $submissionId, string $identity): array
    {
        if (!preg_match('/^sub-[a-f0-9]{64}$/D', $submissionId)) { throw new RuntimeException('SUBMISSION_ID_INVALID'); }
        $entry = $this->store->update(static fn (array &$state): ?array => $state['submissions'][$submissionId] ?? null);
        if (!$entry || ($entry['pbxId'] ?? '') !== $identity || !isset($entry['connectionId'], $entry['originalRequest'])) {
            throw new RuntimeException('SUBMISSION_NOT_OWNED_OR_QUARANTINED');
        }
        if (!in_array($entry['state'] ?? '', ['launch_outcome_unknown','authoritative_not_found'], true)) {
            throw new RuntimeException('SUBMISSION_RETRY_STATE_INVALID');
        }
        $provider = $this->providerFor($entry['connectionId']);
        $request = $entry['originalRequest'];
        if (($request['pbxId'] ?? '') !== $identity || ($request['idempotencyKey'] ?? '') !== ($entry['idempotencyKey'] ?? '')) {
            throw new RuntimeException('SUBMISSION_ORIGINAL_REQUEST_INVALID');
        }
        $this->store->update(static function (array &$state) use ($submissionId): void {
            $state['submissions'][$submissionId]['state'] = 'launch_outcome_unknown';
            $state['submissions'][$submissionId]['lastRetryAt'] = gmdate(DATE_ATOM);
        });
        try { $operation = $provider->launch($request); }
        catch (\Throwable $exception) {
            $this->store->update(static function (array &$state) use ($submissionId): void { $state['submissions'][$submissionId]['state'] = 'launch_outcome_unknown'; });
            throw $exception;
        }
        $this->store->update(static function (array &$state) use ($submissionId, $entry, $operation): void {
            $state['submissions'][$submissionId]['state'] = 'authoritative_response_received';
            $state['submissions'][$submissionId]['operationId'] = $operation['operationId'];
            $state['operationRefs'][$entry['connectionId'] . ':' . $operation['operationId']] = [
                'connectionId' => $entry['connectionId'], 'pbxId' => $entry['pbxId'],
                'operation' => $operation, 'lastSeenAt' => gmdate(DATE_ATOM)];
        });
        return $operation;
    }

    private function localOnlyStatus(string $connectionId, string $identity, string $label): array
    {
        return $this->statusWithFallback(null, $connectionId, $identity, $label);
    }

    private function localStatus(string $connectionId, string $identity): array
    {
        return $this->store->update(function (array &$state) use ($connectionId, $identity): array {
            $connections = [];
            foreach ($state['providerConnections'] ?? [] as $record) {
                if (($record['pbxId'] ?? '') !== $identity) { continue; }
                $configuration = $record['configuration'] ?? [];
                $safeConfiguration = ['mode' => $configuration['mode'] ?? 'unknown', 'pbxId' => $identity];
                if (isset($configuration['providerConfiguration']['endpoint'])) {
                    $safeConfiguration['endpoint'] = $configuration['providerConfiguration']['endpoint'];
                    $safeConfiguration['allowedHosts'] = $configuration['providerConfiguration']['allowedHosts'] ?? [];
                }
                $connections[] = ['connectionId' => $record['connectionId'], 'pbxId' => $identity,
                    'configuration' => $safeConfiguration, 'lastUsedAt' => $record['lastUsedAt'] ?? null];
            }
            $submissions = [];
            foreach ($state['submissions'] ?? [] as $id => $entry) {
                if (($entry['pbxId'] ?? '') === $identity) { $submissions[$id] = $entry; }
            }
            $operations = [];
            foreach ($state['operationRefs'] ?? [] as $reference) {
                if (($reference['pbxId'] ?? '') !== $identity) { continue; }
                $operation = $reference['operation'];
                $operation['connectionId'] = $reference['connectionId'];
                $operation['localKnowledge'] = $reference['connectionId'] === $connectionId ? 'last_authoritative' : 'requires_original_connection';
                $operations[$reference['connectionId'] . ':' . $operation['operationId']] = $operation;
            }
            foreach ($state['operations'] ?? [] as $legacyOperation) {
                if (($legacyOperation['pbxId'] ?? '') !== $identity || isset($legacyOperation['connectionId'])) { continue; }
                $operationId = is_string($legacyOperation['operationId'] ?? null) ? $legacyOperation['operationId'] : null;
                $operations['legacy:' . ($operationId ?? bin2hex(random_bytes(4)))] = ['operationId' => $operationId,
                    'pbxId' => $identity, 'state' => 'unknown', 'quarantined' => true,
                    'localKnowledge' => 'unscoped_requires_reconciliation', 'connectionId' => null];
            }
            $legacy = [];
            foreach ($state['legacyQuarantine'] ?? [] as $kind => $records) {
                foreach ($records as $id => $record) {
                    if (($record['pbxId'] ?? null) !== $identity && isset($record['pbxId'])) { continue; }
                    $legacy[$kind][$id] = ['recordId' => (string) $id, 'pbxIdentityKnown' => isset($record['pbxId']),
                        'state' => 'unscoped_requires_reconciliation', 'actionable' => false,
                        'reasonCode' => 'LEGACY_PROVIDER_CONNECTION_UNKNOWN'];
                }
            }
            $scope = self::scope($connectionId, $identity);
            return ['connectionId' => $connectionId, 'connections' => $connections, 'localSubmissions' => $submissions,
                'operations' => array_values($operations), 'localPublication' => $state['localPublications'][$scope] ?? null,
                'legacyQuarantine' => $legacy, 'providerStatus' => 'pending'];
        });
    }

    private function status(?Provider $current, string $connectionId, string $identity, string $label): array
    {
        $local = $this->localStatus($connectionId, $identity);
        $live = [];
        $currentStatus = null;
        foreach ($local['connections'] as $record) {
            try {
                $provider = $record['connectionId'] === $connectionId && $current !== null ? $current : $this->providerFor($record['connectionId']);
                $status = $provider->status();
                foreach ($status['operations'] ?? [] as $operation) {
                    if (($operation['pbxId'] ?? '') !== $identity || ($operation['connectionId'] ?? '') !== $record['connectionId']) {
                        throw new RuntimeException('PROVIDER_OPERATION_IDENTITY_MISMATCH');
                    }
                    $key = $record['connectionId'] . ':' . $operation['operationId'];
                    $previous = $this->store->update(static fn (array &$state): ?array => $state['operationRefs'][$key]['operation'] ?? null);
                    if ($previous !== null && ($operation['revision'] ?? 0) < ($previous['revision'] ?? 0)) {
                        $operation = $previous;
                        $operation['readinessAssessment'] = ['state' => 'invalid'];
                        $operation['quarantined'] = true;
                        $operation['quarantineCode'] = 'REVISION_CONFLICT';
                    }
                    $this->store->update(static function (array &$state) use ($key, $record, $operation): void {
                        $state['operationRefs'][$key] = ['connectionId' => $record['connectionId'], 'pbxId' => $record['pbxId'],
                            'operation' => $operation, 'lastSeenAt' => gmdate(DATE_ATOM)];
                    });
                    $operation['connectionId'] = $record['connectionId'];
                    $operation['localKnowledge'] = 'authoritative';
                    $live[$key] = $operation;
                }
                foreach ($status['quarantinedOperations'] ?? [] as $operation) {
                        $operationId = $operation['operationId'] ?? null;
                        if (is_string($operationId)) {
                            $known = $this->operationReference($record['connectionId'], $identity, $operationId);
                        } else { $known = null; }
                        if ($known !== null) {
                            $known['quarantined'] = true;
                            $known['quarantineCode'] = $operation['code'] ?? 'PROVIDER_OPERATION_INVALID';
                            $known['localKnowledge'] = 'provider_record_quarantined';
                            $live[$record['connectionId'] . ':' . $operationId] = $known;
                        } else {
                            $safeId = is_string($operationId) ? $operationId : bin2hex(random_bytes(4));
                            $live[$record['connectionId'] . ':quarantine:' . $safeId] = ['operationId' => is_string($operationId) ? $operationId : null,
                                'connectionId' => $record['connectionId'], 'pbxId' => $identity, 'state' => 'unknown',
                                'quarantined' => true, 'localKnowledge' => 'provider_record_quarantined',
                                'quarantineCode' => $operation['code'] ?? 'PROVIDER_OPERATION_INVALID'];
                        }
                }
                if ($record['connectionId'] === $connectionId) { $currentStatus = $status; }
            } catch (\Throwable $exception) {
                foreach ($local['operations'] as $operation) {
                    if (($operation['connectionId'] ?? '') === $record['connectionId']) {
                        $operation['localKnowledge'] = 'stale_provider_unavailable';
                        $live[$record['connectionId'] . ':' . $operation['operationId']] = $operation;
                    }
                }
            }
        }
        foreach ($local['operations'] as $operation) {
            $key = $operation['connectionId'] . ':' . $operation['operationId'];
            if (!isset($live[$key])) { $operation['localKnowledge'] = 'stale_not_reconfirmed'; $live[$key] = $operation; }
        }
        $local['operations'] = array_values($live);
        $local['providerStatus'] = $currentStatus === null ? 'unavailable_or_unverified' : 'fresh_authenticated_response';
        $local['label'] = $label;
        if ($currentStatus !== null) {
            foreach (['publication','capabilities','authorised','quarantinedLegacy','quarantinedOperations'] as $field) {
                if (array_key_exists($field, $currentStatus)) { $local[$field] = $currentStatus[$field]; }
            }
        }
        return $local;
    }

    private function statusWithFallback(?Provider $provider, string $connectionId, string $identity, string $label): array
    {
        try { return $this->status($provider, $connectionId, $identity, $label); }
        catch (\Throwable $exception) {
            $local = $this->localOnlyStatus($connectionId, $identity, $label);
            $local['providerDiagnostic'] = Diagnostic::forThrowable($exception, 'status');
            return $local;
        }
    }

    private function manageOperation(string $action, array $arguments, string $identity): array
    {
        $id = $arguments['id'] ?? '';
        if (!is_string($id) || !preg_match('/^op-[a-zA-Z0-9._:-]{1,100}$/D', $id)) { throw new RuntimeException('OPERATION_ID_INVALID'); }
        $reference = $this->store->update(function (array &$state) use ($id, $identity): ?array {
            foreach ($state['operationRefs'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? '') === $identity && ($entry['operation']['operationId'] ?? '') === $id) { return $entry; }
            }
            return null;
        });
        if ($reference === null) { throw new RuntimeException('OPERATION_NOT_FOUND_OR_CONNECTION_UNRESOLVED'); }
        if (!empty($reference['operation']['quarantined'])) { throw new RuntimeException('REVISION_CONFLICT'); }
        $provider = $this->providerFor($reference['connectionId']);
        $result = match ($action) {
            'operation' => $provider->getOperation($id),
            'cancel' => $provider->cancel($id),
            'teardown' => $provider->teardown($id, $arguments['disposition'] ?? null),
            'activate' => $provider->activate($id, (int) ($arguments['revision'] ?? 0), $arguments['confirmation'] ?? ''),
            default => throw new RuntimeException('OPERATION_ACTION_UNSUPPORTED'),
        };
        if (in_array($action, ['cancel','teardown','activate'], true) && isset($result['operationId'])) {
            $connectionId = $reference['connectionId'];
            $this->store->update(static function (array &$state) use ($connectionId, $identity, $result): void {
                $state['operationRefs'][$connectionId . ':' . $result['operationId']] = ['connectionId' => $connectionId,
                    'pbxId' => $identity, 'operation' => $result, 'lastSeenAt' => gmdate(DATE_ATOM)];
            });
        }
        return $result;
    }

    private function operationReference(string $connectionId, string $identity, string $operationId): ?array
    {
        return $this->store->update(static function (array &$state) use ($connectionId, $identity, $operationId): ?array {
            $reference = $state['operationRefs'][$connectionId . ':' . $operationId] ?? null;
            return $reference !== null && ($reference['pbxId'] ?? '') === $identity ? $reference['operation'] : null;
        });
    }

    private function assertNoUnresolvedOtherConnection(string $identity, string $connectionId, string $key): void
    {
        $conflicts = $this->store->update(function (array &$state) use ($identity, $connectionId, $key): array {
            $conflicts = [];
            foreach ($state['submissions'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? '') !== $identity || ($entry['connectionId'] ?? '') === $connectionId) { continue; }
                $submissionState = $entry['state'] ?? '';
                $operationId = $entry['operationId'] ?? null;
                $knownOperation = $operationId === null ? null : ($state['operationRefs'][($entry['connectionId'] ?? '') . ':' . $operationId]['operation'] ?? null);
                $unresolved = in_array($submissionState, ['submitted_outcome_unknown','launch_outcome_unknown'], true)
                    || ($submissionState === 'authoritative_response_received'
                        && ($knownOperation === null || !in_array($knownOperation['state'] ?? '', ['torn_down','rolled_back','authoritative_not_found'], true)));
                if (($entry['idempotencyKey'] ?? '') === $key && $unresolved) {
                    $conflicts[] = ['submissionId' => $entry['submissionId'] ?? null, 'connectionId' => $entry['connectionId'] ?? null];
                } elseif ($unresolved) {
                    $conflicts[] = ['submissionId' => $entry['submissionId'] ?? null, 'connectionId' => $entry['connectionId'] ?? null];
                }
            }
            foreach ($state['operationRefs'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? '') !== $identity || ($entry['connectionId'] ?? '') === $connectionId) { continue; }
                if (!in_array($entry['operation']['state'] ?? '', ['torn_down','rolled_back'], true)) {
                    $conflicts[] = ['operationId' => $entry['operation']['operationId'] ?? null, 'connectionId' => $entry['connectionId']];
                }
            }
            foreach ($state['legacyQuarantine']['operations'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? null) === $identity || !isset($entry['pbxId'])) { $conflicts[] = ['legacy' => true]; }
            }
            foreach ($state['legacyQuarantine']['submissions'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? null) === $identity || !isset($entry['pbxId'])) { $conflicts[] = ['legacy' => true]; }
            }
            foreach ($state['legacyQuarantine']['publications'] ?? [] as $entry) {
                if (($entry['pbxId'] ?? null) === $identity || !isset($entry['pbxId'])) { $conflicts[] = ['legacy' => true]; }
            }
            return $conflicts;
        });
        if ($conflicts !== []) { throw new RuntimeException('PROVIDER_SWITCH_REQUIRES_RECONCILIATION'); }
    }
}