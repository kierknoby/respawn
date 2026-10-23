<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Application;
use Respawn\Diagnostic;
use Respawn\OperatorController;
use Respawn\Firewall\FixtureFirewallSource;
use Respawn\Provider\HttpsProvider;
use Respawn\Provider\MockProvider;
use Respawn\Provider\ConnectionIdentity;
use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function correctiveAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function correctiveConfig(string $host = 'provider-a.example.test'): array {
    return ['schema' => 'respawn.provider-config.v1', 'adapterId' => 'https-json-v1',
        'endpoint' => 'https://' . $host . '/rpc', 'credentialRef' => '/private/provider-reference',
        'sourcePbxId' => 'pbx-alpha', 'allowedHosts' => [$host]];
}
function correctiveEnvelope(string $method, array $result, string $connectionId): array {
    return ['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $connectionId, 'pbxId' => 'pbx-alpha', 'method' => $method,
        'ok' => true, 'observedAt' => gmdate(DATE_ATOM), 'expiresAt' => gmdate(DATE_ATOM, time() + 30), 'result' => $result];
}
function correctiveOperation(string $state = 'active', bool $expired = true): array {
    $request = ['pbxId' => 'pbx-alpha', 'blueprintId' => 'bp-alpha', 'blueprintVersion' => 1,
        'intent' => 'recovery', 'activationPolicy' => 'manual', 'idempotencyKey' => 'original-corrective-key-0001', 'requestedCapabilities' => []];
    $connection = ConnectionIdentity::forConfiguration(correctiveConfig());
    $result = ['operationId' => 'op-corrective', 'pbxId' => 'pbx-alpha', 'connectionId' => $connection, 'state' => $state,
        'revision' => 3, 'updatedAt' => gmdate(DATE_ATOM), 'request' => $request,
        'resources' => [], 'authorityEpoch' => 'epoch-test', 'activationReceipt' => 'receipt-test', 'verification' => []];
    foreach (['blueprintCoverage','reconstruction','apply','configurationMatch','connectivity','routingPreparation',
        'registration','inboundCall','outboundCall','bidirectionalAudio'] as $check) {
        $result['verification'][$check] = ['passed' => true, 'operationId' => 'op-corrective',
            'blueprintId' => 'bp-alpha', 'blueprintVersion' => 1, 'evidenceRef' => 'synthetic-receipt',
                'observedAt' => gmdate(DATE_ATOM, time() - ($expired ? 600 : 0)),
                'expiresAt' => gmdate(DATE_ATOM, time() + ($expired ? -300 : 30))];
    }
    return $result;
}

$root = sys_get_temp_dir() . '/respawn-corrective-' . bin2hex(random_bytes(8));
$results = [];
$cases = [
    '1-provider-connection-isolation' => function () use ($root): void {
        $shared = new JsonStateStore($root . '/shared');
        $shared = new JsonStateStore($root . '/shared');
        $providerA = new MockProvider($shared, 'pbx-alpha', true, null, 'mock-connection-A');
        $providerB = new MockProvider($shared, 'pbx-alpha', true, null, 'mock-connection-B');
        $settings = ['identity' => 'pbx-alpha', 'mode' => 'provider', 'intent' => 'rehearsal',
            'activationPolicy' => 'none', 'providerConfiguration' => correctiveConfig()];
        $source = new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
        $blueprint = SimulatedPbx::blueprint('pbx-alpha');
        $first = new Application($settings, $shared, $source, $providerA, fn (): array => $blueprint);
        $first->run('publish');
        $settings['providerConfiguration'] = correctiveConfig('provider-b.example.test');
        $second = new Application($settings, $shared, $source, $providerB, fn (): array => $blueprint);
        $result = $second->run('publish');
        correctiveAssert($result['state'] === 'acknowledged' && $providerB->status()['publication'] !== null,
            'Provider B must receive its own first publication of identical content.');
        correctiveAssert($providerA->status()['publication']['ack']['connectionId'] !== $providerB->status()['publication']['ack']['connectionId'],
            'Provider acknowledgements must bind PBX and provider connection.');
        $rotated = correctiveConfig(); $rotated['credentialRef'] = '/private/provider-reference-rotated';
        correctiveAssert(ConnectionIdentity::forConfiguration(correctiveConfig()) === ConnectionIdentity::forConfiguration($rotated),
            'Credential-file rotation preserves the stable public connection identity.');
        correctiveAssert(ConnectionIdentity::forConfiguration(correctiveConfig()) !== ConnectionIdentity::forConfiguration(correctiveConfig('provider-b.example.test')),
            'Endpoint changes create a separate provider connection identity.');
        correctiveAssert(ConnectionIdentity::forConfiguration([], 'mock') !== ConnectionIdentity::forConfiguration(correctiveConfig()),
            'Mock/real mode changes cannot share a provider connection identity.');
    },
    '2-historical-readiness-visibility' => static function (): void {
        $operation = correctiveOperation();
        $provider = new HttpsProvider(correctiveConfig(), fn (array $request): array => correctiveEnvelope($request['method'], $operation, $request['connectionId']));
        $result = $provider->getOperation('op-corrective');
        correctiveAssert($result['operationId'] === 'op-corrective' && ($result['readinessAssessment']['state'] ?? '') === 'expired',
            'An active operation must remain visible when historical call evidence expires.');
        $transport = static function (array $request) use ($operation): array {
            if ($request['method'] === 'status') {
                $bad = $operation; $bad['pbxId'] = 'pbx-foreign';
                return correctiveEnvelope('status', ['operations' => [$operation, $bad]], $request['connectionId']);
            }
            if ($request['method'] === 'operation') { return correctiveEnvelope('operation', $operation, $request['connectionId']); }
            if ($request['method'] === 'teardown') {
                $done = $operation; $done['state'] = 'torn_down'; $done['revision']++;
                return correctiveEnvelope('teardown', $done, $request['connectionId']);
            }
            throw new RuntimeException('Unexpected fake method.');
        };
        $mixed = new HttpsProvider(correctiveConfig(), $transport);
        $status = $mixed->status();
        correctiveAssert(count($status['operations']) === 1 && count($status['quarantinedOperations']) === 1,
            'One malformed foreign operation must not invalidate the mixed status response.');
        $torn = $mixed->teardown('op-corrective', 'operator-confirmed-deactivate');
        correctiveAssert($torn['state'] === 'torn_down', 'Expired active readiness does not prevent explicitly routed teardown.');
        $ready = correctiveOperation('ready', true);
        $cancelTransport = static function (array $request) use ($ready): array {
            if ($request['method'] === 'operation') { return correctiveEnvelope('operation', $ready, $request['connectionId']); }
            $cancelled = $ready; $cancelled['state'] = 'cancelled'; $cancelled['revision']++;
            return correctiveEnvelope('cancel', $cancelled, $request['connectionId']);
        };
        $cancelProvider = new HttpsProvider(correctiveConfig(), $cancelTransport);
        correctiveAssert($cancelProvider->cancel('op-corrective')['state'] === 'cancelled', 'Expired readiness does not block safe cancellation of an unfinished ready operation.');
        $alreadyTorn = correctiveOperation('torn_down', true);
        $idempotentProvider = new HttpsProvider(correctiveConfig(), static fn (array $request): array => correctiveEnvelope('operation', $alreadyTorn, $request['connectionId']));
        correctiveAssert($idempotentProvider->teardown('op-corrective')['state'] === 'torn_down', 'Repeated teardown of terminal operation is idempotent.');
        $revisionSource = correctiveOperation('ready', false);
        $revisionConflict = new HttpsProvider(correctiveConfig(), static function (array $request) use ($revisionSource): array {
            if ($request['method'] === 'operation') { return correctiveEnvelope('operation', $revisionSource, $request['connectionId']); }
            $stale = $revisionSource; $stale['state'] = 'cancelled';
            return correctiveEnvelope('cancel', $stale, $request['connectionId']);
        });
        try { $revisionConflict->cancel('op-corrective'); throw new RuntimeException('Non-advancing transition revision accepted.'); }
        catch (RuntimeException $exception) { correctiveAssert($exception->getMessage() === 'REVISION_CONFLICT', 'Transition revision must strictly advance.'); }
        $ready = correctiveOperation('ready', true);
        $readyProvider = new HttpsProvider(correctiveConfig(), fn (array $request): array => correctiveEnvelope($request['method'], $ready, $request['connectionId']));
        $visibleReady = $readyProvider->getOperation('op-corrective');
        correctiveAssert(($visibleReady['readinessAssessment']['state'] ?? '') === 'expired', 'A ready operation with old evidence remains visible.');
        try { $readyProvider->activate('op-corrective', 3, 'activate:op-corrective'); throw new RuntimeException('Expired ready operation activated.'); }
        catch (RuntimeException $exception) { correctiveAssert($exception->getMessage() === 'READINESS_EXPIRED', 'Expired readiness blocks activation with stable code.'); }
    },
    '3-session-independent-reconciliation' => function () use ($root): void {
        $store = new JsonStateStore($root . '/gui');
        $provider = new MockProvider(new JsonStateStore($root . '/gui-provider'), 'pbx-alpha');
        $settings = ['identity' => 'pbx-alpha', 'mode' => 'provider', 'intent' => 'rehearsal',
            'activationPolicy' => 'none', 'providerConfiguration' => correctiveConfig()];
        $source = new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
        $blueprint = SimulatedPbx::blueprint('pbx-alpha');
        $first = new Application($settings, $store, $source, $provider, fn (): array => $blueprint);
        $first->run('publish');
        $operation = $first->run('respawn', ['key' => 'session-lost-corrective-key-0001']);
        $entries = $first->run('status')['localSubmissions'];
        $entry = reset($entries);
        $submissionId = $entry['submissionId'] ?? throw new RuntimeException('Submission identifier missing from local journal.');
        $freshSession = new Application($settings, $store, $source, $provider, fn (): array => $blueprint);
        $controller = new OperatorController();
        try { $controller->handle($freshSession, 'pbx-alpha', 'pbx-alpha', ['action' => 'reconcileSubmission', 'submissionId' => $submissionId], 'csrf-token-from-another-session', 100); }
        catch (RuntimeException $exception) { correctiveAssert($exception->getMessage() === 'OPERATOR_CSRF_INVALID', 'Another administrator session must use its own CSRF token.'); }
        try { $controller->handle($freshSession, 'pbx-bravo', 'pbx-alpha', ['action' => 'reconcileSubmission', 'submissionId' => $submissionId, 'csrf' => 'second-session-token'], 'second-session-token', 100); }
        catch (RuntimeException $exception) { correctiveAssert($exception->getMessage() === 'OPERATOR_PBX_IDENTITY_MISMATCH', 'Foreign PBX identity cannot reconcile the submission.'); }
        $csrf = 'fresh-admin-session-csrf-token';
        $reconciled = $controller->handle($freshSession, 'pbx-alpha', 'pbx-alpha',
            ['action' => 'reconcileSubmission', 'submissionId' => $submissionId, 'csrf' => $csrf], $csrf, 100);
        correctiveAssert($reconciled['operationId'] === $operation['operationId'], 'Protected local submission must reconcile without a session launch key or resubmission.');
        $again = $controller->handle($freshSession, 'pbx-alpha', 'pbx-alpha',
            ['action' => 'reconcileSubmission', 'submissionId' => $submissionId, 'csrf' => $csrf], $csrf, 100);
        correctiveAssert($again['operationId'] === $operation['operationId'] && count($provider->status()['operations']) === 1,
            'Repeated reconciliation clicks recover the same operation without another launch.');
        try { $freshSession->run('respawn', ['key' => 'session-lost-corrective-key-0001']); throw new RuntimeException('Respawn silently retried an existing uncertain submission.'); }
        catch (RuntimeException $exception) { correctiveAssert(str_contains($exception->getMessage(), 'SUBMISSION_ALREADY_EXISTS'), 'Ordinary Respawn cannot silently submit an old request again.'); }
        try { $controller->handle($freshSession, 'pbx-alpha', 'pbx-alpha',
            ['action' => 'reconcileSubmission', 'submissionId' => 'sub-' . str_repeat('0', 64), 'csrf' => $csrf], $csrf, 100); }
        catch (RuntimeException $exception) { correctiveAssert(str_contains($exception->getMessage(), 'SUBMISSION_'), 'Foreign/missing submission identifier is not trusted.'); }
        $offline = new Application($settings, $store, $source, null, fn (): array => $blueprint,
            static function (): never { throw new RuntimeException('provider unavailable'); });
        $offlineStatus = $offline->run('status');
        correctiveAssert(count($offlineStatus['localSubmissions']) === 1 && $offlineStatus['providerStatus'] === 'unavailable_or_unverified',
            'Provider outage retains local uncertain submissions as stale/unverified.');
        correctiveAssert(count($offlineStatus['operations']) === 1 && $offlineStatus['operations'][0]['localKnowledge'] === 'stale_provider_unavailable',
            'Provider outage retains the locally known operation as stale, not an empty list.');
    },
    '6-provider-change-keeps-original-launch-and-management-connection' => function () use ($root): void {
        $store = new JsonStateStore($root . '/switch');
        $source = new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
        $blueprint = SimulatedPbx::blueprint('pbx-alpha');
        $configA = correctiveConfig('provider-a.example.test');
        $configB = correctiveConfig('provider-b.example.test');
        $connectionA = ConnectionIdentity::forConfiguration($configA);
        $connectionB = ConnectionIdentity::forConfiguration($configB);
        $rawA = new MockProvider($store, 'pbx-alpha', true, null, $connectionA);
        $rawB = new MockProvider($store, 'pbx-alpha', true, null, $connectionB);
        $providerA = new class($rawA) implements \Respawn\Provider\Provider {
            public bool $lose = true;
            public function __construct(private \Respawn\Provider\Provider $inner) {}
            public function connectionIdentity(): string { return $this->inner->connectionIdentity(); }
            public function capabilities(): array { return $this->inner->capabilities(); }
            public function status(): array { return $this->inner->status(); }
            public function publish(string $pbxId, array $intent, bool $force = false): array { return $this->inner->publish($pbxId, $intent, $force); }
            public function launch(array $request): array { $r = $this->inner->launch($request); if ($this->lose) { $this->lose = false; throw new RuntimeException('lost response'); } return $r; }
            public function getOperation(string $id): array { return $this->inner->getOperation($id); }
            public function reconcile(string $key): array { return $this->inner->reconcile($key); }
            public function advance(string $id, ?string $failAt = null): array { return $this->inner->advance($id, $failAt); }
            public function cancel(string $id): array { return $this->inner->cancel($id); }
            public function activate(string $id, int $revision, string $confirmation): array { return $this->inner->activate($id, $revision, $confirmation); }
            public function teardown(string $id, ?string $routingDisposition = null): array { return $this->inner->teardown($id, $routingDisposition); }
        };
        $resolver = static fn (array $record): \Respawn\Provider\Provider => $record['connectionId'] === $connectionA ? $providerA : $rawB;
        $settingsA = ['identity' => 'pbx-alpha', 'mode' => 'provider', 'intent' => 'rehearsal', 'activationPolicy' => 'none', 'providerConfiguration' => $configA];
        $appA = new Application($settingsA, $store, $source, $providerA, fn (): array => $blueprint, $resolver);
        $appA->run('publish');
        $key = 'uncertain-before-provider-switch-0001';
        try { $appA->run('respawn', ['key' => $key]); } catch (RuntimeException) {}
        $submissionId = array_key_first($appA->run('status')['localSubmissions']);
        $settingsB = array_replace($settingsA, ['providerConfiguration' => $configB]);
        $appB = new Application($settingsB, $store, $source, $rawB, fn (): array => $blueprint, $resolver);
        try { $appB->run('respawn', ['key' => $key]); throw new RuntimeException('New connection reused an uncertain launch.'); }
        catch (RuntimeException $exception) { correctiveAssert(str_contains($exception->getMessage(), 'PROVIDER_SWITCH_REQUIRES_RECONCILIATION'), 'Provider switch blocks new launch while A is uncertain.'); }
        $retried = $appB->run('reconcileSubmission', ['submissionId' => $submissionId]);
        correctiveAssert($retried['connectionId'] === $connectionA && $retried['operationId'] !== '', 'Lost-response reconciliation remains bound to A.');
        $cancelled = $appB->run('cancel', ['id' => $retried['operationId']]);
        correctiveAssert($cancelled['connectionId'] === $connectionA && $cancelled['state'] === 'cancelled', 'Old operation management routes to A after selecting B.');
        $cleaned = $appB->run('teardown', ['id' => $retried['operationId']]);
        correctiveAssert($cleaned['connectionId'] === $connectionA && $cleaned['state'] === 'torn_down', 'Old teardown routes to A, not selected provider B.');
        $appB->run('publish');
        $new = $appB->run('respawn', ['key' => 'new-operation-on-provider-b-0001']);
        correctiveAssert($new['connectionId'] === $connectionB && $rawB->status()['publication']['ack']['connectionId'] === $connectionB,
            'After A is reconciled and cleaned, B publishes and launches independently.');
    },
    '9-mock-real-mode-transition-preserves-old-operation' => function () use ($root): void {
        $store = new JsonStateStore($root . '/mode-switch');
        $source = new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
        $blueprint = SimulatedPbx::blueprint('pbx-alpha');
        $config = correctiveConfig(); $realConnection = ConnectionIdentity::forConfiguration($config);
        $mockConnection = ConnectionIdentity::forConfiguration([], 'mock');
        $realProvider = new MockProvider($store, 'pbx-alpha', true, null, $realConnection);
        $mockProvider = new MockProvider($store, 'pbx-alpha', true, null, $mockConnection);
        $resolver = static fn (array $record): \Respawn\Provider\Provider =>
            $record['connectionId'] === $realConnection ? $realProvider : $mockProvider;
        $realSettings = ['identity' => 'pbx-alpha', 'mode' => 'provider', 'intent' => 'rehearsal',
            'activationPolicy' => 'none', 'providerConfiguration' => $config];
        $realApp = new Application($realSettings, $store, $source, $realProvider, fn (): array => $blueprint, $resolver);
        $realApp->run('publish');
        $operation = $realApp->run('respawn', ['key' => 'real-mode-operation-key-00001']);
        $mockSettings = array_replace($realSettings, ['mode' => 'mock']); unset($mockSettings['providerConfiguration']);
        $mockApp = new Application($mockSettings, $store, $source, $mockProvider, fn (): array => $blueprint, $resolver);
        $status = $mockApp->run('status');
        $found = array_values(array_filter($status['operations'], static fn (array $entry): bool => $entry['operationId'] === $operation['operationId']));
        correctiveAssert(count($found) === 1 && $found[0]['connectionId'] === $realConnection
            && $found[0]['localKnowledge'] === 'authoritative', 'Mock/real selection change keeps the old operation visible on its original connection.');
        try { $mockApp->run('respawn', ['key' => 'new-mock-operation-key-000001']); throw new RuntimeException('Mode switch bypassed active operation.'); }
        catch (RuntimeException $exception) { correctiveAssert(str_contains($exception->getMessage(), 'PROVIDER_SWITCH_REQUIRES_RECONCILIATION'), 'Mode switch cannot start a replacement while old operation remains.'); }
        $mockApp->run('cancel', ['id' => $operation['operationId']]);
        $mockApp->run('teardown', ['id' => $operation['operationId']]);
        $mockApp->run('publish');
        $new = $mockApp->run('respawn', ['key' => 'new-mock-operation-key-000001']);
        correctiveAssert($new['connectionId'] === $mockConnection, 'A deliberate mock operation uses its separate mock connection after old cleanup.');
    },
    '10-legacy-provider-state-quarantine' => function () use ($root): void {
        $store = new JsonStateStore($root . '/legacy-state');
        $store->update(static function (array &$state): void {
            $state['localPublications']['pbx-alpha'] = ['ack' => ['hash' => str_repeat('a', 64), 'blueprintId' => 'old']];
            $state['submissions']['legacy-key'] = ['request' => ['pbxId' => 'pbx-alpha', 'idempotencyKey' => 'legacy-key'], 'secret' => 'LEGACY_SECRET_SENTINEL'];
            $state['operations']['op-legacy'] = ['operationId' => 'op-legacy', 'pbxId' => 'pbx-alpha', 'state' => 'active', 'secret' => 'LEGACY_SECRET_SENTINEL'];
        });
        $config = correctiveConfig(); $provider = new MockProvider($store, 'pbx-alpha');
        $application = new Application(['identity' => 'pbx-alpha', 'mode' => 'mock', 'intent' => 'rehearsal', 'activationPolicy' => 'none'],
            $store, new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json'), $provider,
            fn (): array => SimulatedPbx::blueprint('pbx-alpha'));
        $status = $application->run('status');
        correctiveAssert(!empty($status['legacyQuarantine']) && !str_contains(json_encode($status), 'LEGACY_SECRET_SENTINEL'),
            'Ambiguous previous publication/submission/operation are quarantined without exposing raw legacy state.');
        $application->run('publish');
        try { $application->run('respawn', ['key' => 'new-after-legacy-ambiguous-001']); throw new RuntimeException('Launch proceeded despite ambiguous legacy infrastructure.'); }
        catch (RuntimeException $exception) { correctiveAssert(str_contains($exception->getMessage(), 'PROVIDER_SWITCH_REQUIRES_RECONCILIATION'), 'Ambiguous legacy ownership blocks a new launch without guessing its provider.'); }
    },
    '11-status-revision-regression-quarantine' => function () use ($root): void {
        $store = new JsonStateStore($root . '/revision-status');
        $config = correctiveConfig(); $connection = ConnectionIdentity::forConfiguration($config);
        $old = correctiveOperation('active', true); $old['revision'] = 8;
        $store->update(static function (array &$state) use ($connection, $old): void {
            $state['operationRefs'][$connection . ':op-corrective'] = ['connectionId' => $connection,
                'pbxId' => 'pbx-alpha', 'operation' => $old, 'lastSeenAt' => gmdate(DATE_ATOM)];
        });
        $regressed = correctiveOperation('active', true); $regressed['revision'] = 7;
        $https = new HttpsProvider($config, static fn (array $request): array => correctiveEnvelope($request['method'],
            $request['method'] === 'status' ? ['operations' => [$regressed]] : $regressed, $request['connectionId']));
        $app = new Application(['identity' => 'pbx-alpha', 'mode' => 'provider', 'providerConfiguration' => $config],
            $store, new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json'), $https);
        $status = $app->run('status');
        correctiveAssert(count($status['operations']) === 1 && !empty($status['operations'][0]['quarantined'])
            && $status['operations'][0]['revision'] === 8, 'Revision regression preserves the last known good operation but quarantines it.');
        try { $app->run('cancel', ['id' => 'op-corrective']); throw new RuntimeException('Quarantined stale operation was manageable.'); }
        catch (RuntimeException $exception) { correctiveAssert($exception->getMessage() === 'REVISION_CONFLICT', 'Regressed revision cannot authorize transitions.'); }
    },
    '4-canonical-v2-verification' => static function (): void {
        $document = SimulatedPbx::blueprint('pbx-alpha');
        $pretty = json_decode(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true, 64, JSON_THROW_ON_ERROR);
        Blueprint::verifyHash($pretty);
        $document = array_reverse($document, true);
        $document['operations'][0] = array_reverse($document['operations'][0], true);
        Blueprint::verifyHash($document);
        $changes = [];
        $changed = $pretty; $changed['operations'][0]['parameters']['description'] .= ' changed'; $changes['parameter'] = $changed;
        $changed = $pretty; $changed['operations'][0]['dependsOn'][] = 'ringgroup:999'; $changes['dependency'] = $changed;
        $changed = $pretty; $changed['operations'][0]['secretRefs']['authPassword'] .= '-rotated'; $changes['secret revision'] = $changed;
        $changed = $pretty; $changed['operations'] = array_reverse($changed['operations']); $changes['operation list array order'] = $changed;
        foreach ($changes as $label => $changedDocument) {
            try { Blueprint::verifyHash($changedDocument); throw new RuntimeException('Changed ' . $label . ' was accepted.'); }
            catch (RuntimeException $exception) {
                $message = strtolower($exception->getMessage());
                correctiveAssert(str_contains($message, 'hash') || str_contains($message, 'dependencies')
                    || str_contains($message, 'missing'), 'Canonical v2 detects ' . $label);
            }
        }
    },
    '5-specific-secret-diagnostics' => static function (): void {
        try { (new FileSecrets('/nonexistent/respawn-corrective-manifest.json'))->resolve('secret:pbx-alpha:1001:v1', 'pbx-alpha'); }
        catch (Throwable $exception) {
            correctiveAssert($exception->getMessage() === 'SECRET_MANIFEST_MISSING', 'Missing manifest needs a distinct stable operator diagnostic, not an ambiguous generic file error.');
            return;
        }
        throw new RuntimeException('Missing manifest was accepted.');
    },
    '7-sanitised-diagnostic-boundaries' => function () use ($root): void {
        $secret = 'SYNTHETIC_BEARER_TOKEN_GUI_TEST_ONLY';
        $diagnostic = Diagnostic::forThrowable(new RuntimeException('upstream raw token=' . $secret), 'publish', 'corr-safe-test');
        correctiveAssert($diagnostic['code'] === 'PUBLICATION_OUTCOME_UNKNOWN' && $diagnostic['correlationId'] === 'corr-safe-test'
            && !str_contains(json_encode($diagnostic), $secret), 'Stable code/stage/outcome/reference is returned without raw exception content.');
        $logPath = $root . '/safe.log'; $previous = ini_get('error_log'); ini_set('error_log', $logPath);
        Diagnostic::log($diagnostic); ini_set('error_log', $previous);
        $log = file_get_contents($logPath);
        correctiveAssert(str_contains($log, 'PUBLICATION_OUTCOME_UNKNOWN') && !str_contains($log, $secret), 'Detailed log contains safe diagnostic fields only.');
        $state = new JsonStateStore($root . '/safe-state');
        $state->update(static function (array &$data) use ($diagnostic): void { $data['diagnostics'][$diagnostic['correlationId']] = $diagnostic; });
        $stored = file_get_contents($root . '/safe-state/state.json');
        correctiveAssert(!str_contains($stored, $secret) && !str_contains($stored, 'upstream raw token'), 'Persistent diagnostic journal excludes raw exception text and tokens.');
    },
    '8-provider-operation-error-redaction' => static function (): void {
        $secret = 'SYNTHETIC_UPSTREAM_TOKEN_NOT_FOR_OUTPUT';
        $operation = correctiveOperation('failed', false);
        $operation['errors'] = [['code' => 'PROVIDER_OPERATION_FAILED', 'stage' => 'connectivity',
            'retryable' => false, 'correlationId' => 'upstream-safe-ref', 'message' => $secret]];
        $provider = new HttpsProvider(correctiveConfig(), static fn (array $request): array => correctiveEnvelope($request['method'], $operation, $request['connectionId']));
        $safe = $provider->getOperation('op-corrective');
        correctiveAssert(!str_contains(json_encode($safe), $secret) && ($safe['errors'][0]['code'] ?? '') === 'PROVIDER_OPERATION_FAILED'
            && str_contains($safe['errors'][0]['message'] ?? '', 'failed operation stage'),
            'Upstream operation message is replaced by a safe code-specific explanation.');
    },
];
try {
    foreach ($cases as $name => $case) {
        $filters = array_values(array_filter($argv, static fn (string $argument): bool => str_starts_with($argument, '--case=')));
        if ($filters && !str_starts_with($name, substr($filters[0], 7))) { continue; }
        try { $case(); $results[$name] = ['passed' => true]; echo 'PASS: ' . $name . "\n"; }
        catch (Throwable $exception) {
            $results[$name] = ['passed' => false, 'observed' => $exception->getMessage()];
            echo 'FAIL: ' . $name . ': ' . $exception->getMessage() . "\n";
        }
    }
    if (in_array('--record-red', $argv, true)) {
        file_put_contents(__DIR__ . '/../artifacts/corrective-red.json', json_encode(['stage' => 'before corrective fixes', 'cases' => $results], JSON_PRETTY_PRINT) . "\n");
    }
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
exit(count(array_filter($results, static fn (array $result): bool => !$result['passed'])) ? 1 : 0);