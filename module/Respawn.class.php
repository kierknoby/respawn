<?php
namespace FreePBX\modules;

require_once __DIR__ . '/bootstrap.php';

class Respawn extends \FreePBX_Helpers implements \BMO
{
    public function __construct($freepbx = null)
    {
        if ($freepbx === null) { throw new \RuntimeException('FreePBX container required.'); }
        $this->FreePBX = $freepbx;
    }
    public function install()
    {
        if (!$this->getConfig('settings')) {
            $this->setConfig('settings', ['mode' => 'disabled', 'identity' => 'pbx-' . bin2hex(random_bytes(8)),
                'intent' => 'rehearsal', 'activationPolicy' => 'none', 'protectionEnabled' => false,
                'protectionIntervalSeconds' => 300]);
        }
    }
    public function uninstall() {}
    public function backup() { return $this->getConfig('settings'); }
    public function restore($backup) { throw new \RuntimeException('Automatic identity restore is unsafe; re-enrol the target explicitly.'); }

    public function settings(): array { return $this->getConfig('settings') ?: []; }

    public function reconstructionAdapter(): \Respawn\Reconstruction\FreePbxRinggroups
    {
        $members = array_map(static fn (array $user): string => (string) $user[0], $this->FreePBX->Core->listUsers());
        return new \Respawn\Reconstruction\FreePbxRinggroups($this->FreePBX->Ringgroups,
            $this->settings()['identity'], static fn (string $member): bool => in_array($member, $members, true));
    }

    public function buildTarget(?\Closure $apply = null): \Respawn\Reconstruction\FreePbxBuildTarget
    {
        $snapshot = new \Respawn\Reconstruction\FreePbxSnapshot($this->FreePBX->Database, $this->FreePBX->astman);
        $environment = function (): array {
            $versions = [];
            foreach (['framework','core','ringgroups','sipsettings'] as $name) {
                $info = $this->FreePBX->Modules->getInfo($name);
                if ($this->FreePBX->Modules->checkStatus($name)) { $versions[$name] = (string) ($info[$name]['version'] ?? ''); }
            }
            return ['mode' => 'FREEPBX', 'modules' => $versions, 'capabilities' => array_keys(\Respawn\Reconstruction\BuildSpec::ADAPTERS)];
        };
        $preflight = function (array $operations): void {
            $driver = $this->FreePBX->Core->getDriver('pjsip');
            if (!$this->FreePBX->astman->connected() || !$driver
                || !in_array($this->FreePBX->Config->get('ASTSIPDRIVER'), ['chan_pjsip','both'], true)) {
                throw new \RuntimeException('PJSIP_OR_MANAGER_UNAVAILABLE');
            }
            if (!method_exists($driver, 'getTransportConfigs') || !$driver->getTransportConfigs()) {
                throw new \RuntimeException('PJSIP_TRANSPORT_CONFIGURATION_MISSING');
            }
            foreach ($operations as $operation) {
                if ($operation['object']['type'] === 'user'
                    && $this->FreePBX->Modules->moduleHasMethod('Voicemail','getMailbox')
                    && $this->FreePBX->Voicemail->getMailbox($operation['object']['identity']) !== null) {
                    throw new \RuntimeException('VOICEMAIL_IS_BLOCKING_RECOVERY_GAP');
                }
            }
        };
        return new \Respawn\Reconstruction\FreePbxBuildTarget($this->FreePBX->Core, $this->reconstructionAdapter(),
            $this->settings()['identity'], $snapshot->read(...), $environment,
            $apply ?? static function (): int { throw new \RuntimeException('EXPLICIT_CLI_APPLY_CONTEXT_REQUIRED'); }, $preflight,
            $apply !== null);
    }

    public function exportReconstruction(array $numbers, ?string $manifest = null): array
    {
        $resolver = new \Respawn\Reconstruction\FileSecrets($manifest ?: ($this->settings()['secretManifest'] ?? ''));
        return $this->buildTarget()->export($this->settings()['identity'], $numbers, $resolver);
    }

    public function reconstruct(array $blueprint, string $confirmation, ?string $manifest = null, ?\Closure $apply = null): array
    {
        if ($confirmation !== 'reconstruct:' . $this->settings()['identity']) {
            throw new \RuntimeException('Explicit target identity confirmation required.');
        }
        $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
        if (($blueprint['schema'] ?? '') !== 'respawn.reconstruction.v2') {
            throw new \RuntimeException('V2 blueprint required: legacy Ring Groups plans omit device prerequisites and apply semantics.');
        }
        return (new \Respawn\Reconstruction\BuildExecutor($this->buildTarget($apply),
            new \Respawn\Storage\JsonStateStore($directory), new \Respawn\Reconstruction\FileSecrets(
                $manifest ?: ($this->settings()['secretManifest'] ?? ''))))->execute($blueprint, true);
    }

        public function revisionStore(): \Respawn\Reconstruction\RevisionStore
        {
            $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
            return new \Respawn\Reconstruction\RevisionStore(new \Respawn\Storage\JsonStateStore($directory . '/revision-cache'));
        }

        public function captureLocalRevision(string $origin = 'manual_capture', ?array $publicationAcknowledgement = null): array
        {
            $settings = $this->settings();
            if (!preg_match('/^[0-9]{2,8}(,[0-9]{2,8}){0,99}$/D', $settings['groups'] ?? '')) {
                throw new \RuntimeException('EXPLICIT_SUPPORTED_SCOPE_REQUIRED');
            }
            $blueprint = $this->exportReconstruction(explode(',', $settings['groups']), $settings['secretManifest'] ?? null);
            return $this->revisionStore()->capture($blueprint, $origin, $publicationAcknowledgement);
        }

        public function recordPublicationRevision(array $acknowledgement): array
        {
            $store = $this->revisionStore();
            $source = $this->settings()['identity'];
            $publication = $this->application()->publicationSnapshot($acknowledgement);
            $blueprint = $publication['blueprint'];
            if (($blueprint['sourcePbxId'] ?? '') !== $source) {
                throw new \RuntimeException('REVISION_PUBLICATION_ACK_INVALID');
            }
            foreach ($store->list($source) as $revision) {
                if (($revision['contentHash'] ?? '') !== $blueprint['contentHash']) { continue; }
                $store->markPublished($source, $revision['revisionId'], $acknowledgement, $publication['connectionId']);
                return $store->get($source, $revision['revisionId']);
            }
            return $store->capture($blueprint, 'configured_publication', $acknowledgement,
                null, true, $publication['connectionId']);
        }

        public function listLocalRevisions(): array
        {
            return ['heads' => $this->revisionStore()->heads($this->settings()['identity']),
                'revisions' => $this->revisionStore()->list($this->settings()['identity']),
                'retentionLimit' => $this->revisionStore()->retentionLimit()];
        }

        public function inspectLocalRevision(string $revisionId): array
        {
            $revision = $this->revisionStore()->get($this->settings()['identity'], $revisionId);
            unset($revision['blueprint'], $revision['pins'], $revision['recordHash']);
            return $revision;
        }

        public function setLocalRevisionRetention(int $limit): void
        {
            $this->revisionStore()->setRetentionLimit($limit);
        }

        public function protectionStatus(): array
        {
            $settings = $this->settings();
            $enabled = ($settings['protectionEnabled'] ?? false) === true;
            $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
            $record = (new \Respawn\Storage\JsonStateStore($directory))
                ->update(static fn (array &$state): array => $state['protection'][$settings['identity']] ?? []);
            $status = \Respawn\Protection\ProtectionReconciler::formatStatus($record, $enabled);
            if ($enabled) {
                try {
                    if (($settings['mode'] ?? '') !== 'provider') { throw new \RuntimeException('PROTECTION_PROVIDER_NOT_CONFIGURED'); }
                    \Respawn\Provider\Configuration::validate($settings['providerConfiguration'] ?? []);
                    if (!preg_match('/^[0-9]{2,8}(,[0-9]{2,8}){0,99}$/D', $settings['groups'] ?? '')
                        || !is_string($settings['secretManifest'] ?? null) || !str_starts_with($settings['secretManifest'], '/')) {
                        throw new \RuntimeException('PROTECTION_CONFIGURATION_INCOMPLETE');
                    }
                } catch (\Throwable $exception) {
                    $status['state'] = 'protection_configuration_required';
                    $status['lastError'] = preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage())
                        ? $exception->getMessage() : 'PROTECTION_CONFIGURATION_INCOMPLETE';
                }
            }
            return $status;
        }

        public function reconcileProtection(?int $now = null): array
        {
            $settings = $this->settings();
            $enabled = ($settings['protectionEnabled'] ?? false) === true;
            if (!$enabled) { return $this->protectionStatus(); }
            if (($settings['mode'] ?? '') !== 'provider') { throw new \RuntimeException('PROTECTION_PROVIDER_NOT_CONFIGURED'); }
            $provider = new \Respawn\Provider\HttpsProvider($settings['providerConfiguration'] ?? []);
            if (!$provider instanceof \Respawn\Provider\RetainedBlueprintProvider) {
                throw new \RuntimeException('PROTECTION_RETENTION_INTERFACE_UNAVAILABLE');
            }
            $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
            $reconciler = new \Respawn\Protection\ProtectionReconciler(new \Respawn\Storage\JsonStateStore($directory),
                $this->revisionStore(), $provider, $settings['identity'], (int) ($settings['protectionIntervalSeconds'] ?? 300),
                new \Respawn\Reconstruction\FileSecrets($settings['secretManifest'] ?? ''));
            $exporter = fn (): array => $this->exportReconstruction(explode(',', $settings['groups'] ?? ''),
                $settings['secretManifest'] ?? null);
            return $reconciler->reconcile($exporter, true, $now);
        }

        public function resumeProtection(string $revisionId, string $confirmation): array
        {
            $settings = $this->settings();
            if ($confirmation !== 'resume:' . $settings['identity'] . ':' . $revisionId
                || ($settings['mode'] ?? '') !== 'provider') { throw new \RuntimeException('PROTECTION_RESUME_CONFIRMATION_REQUIRED'); }
            $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
            $coordinator = new \Respawn\Protection\ProtectionReconciler(new \Respawn\Storage\JsonStateStore($directory),
                $this->revisionStore(), new \Respawn\Provider\HttpsProvider($settings['providerConfiguration']), $settings['identity']);
            $coordinator->authoriseResume($revisionId);
            return ['state'=>'protection_resume_authorised', 'parentRevisionId'=>$revisionId, 'mutations'=>false];
        }

        public function syncLocalRevisionPins(array $operations): void
        {
            $store = $this->revisionStore();
            $source = $this->settings()['identity'];
            $revisions = $store->list($source);
            foreach ($operations as $operation) {
                $operationId = $operation['operationId'] ?? null;
                $request = $operation['request'] ?? [];
                $blueprintId = $request['blueprintId'] ?? null;
                $version = $request['blueprintVersion'] ?? null;
                if (!is_string($operationId) || !is_string($blueprintId) || !str_starts_with($blueprintId, 'bp-') || !is_int($version)) { continue; }
                foreach ($revisions as $revision) {
                    if (!str_starts_with($revision['contentHash'] ?? '', substr($blueprintId, 3))
                        || (int) ($revision['publicationAcknowledgement']['version'] ?? 0) !== $version) { continue; }
                    if (in_array($operation['state'] ?? '', ['torn_down','rolled_back'], true)) {
                        $store->unpin($source, $revision['revisionId'], $operationId);
                    } else {
                        $store->pin($source, $revision['revisionId'], $operationId, 'Respawn operation dependency');
                    }
                    break;
                }
            }
        }

        public function compareLocalRevisions(string $fromRevision, string $toRevision): array
        {
            return $this->revisionStore()->compare($this->settings()['identity'], $fromRevision, $toRevision);
        }

        public function planLocalAction(string $action, string $revisionId): array
        {
            $settings = $this->settings();
            $this->assertNoConflictingProviderOperation($settings['identity']);
            $revisions = $this->revisionStore();
            $revision = $revisions->get($settings['identity'], $revisionId);
            $target = $this->buildTarget();
            $catalog = $revisions->operationCatalogForRevision($settings['identity'], $revisionId);
            $snapshot = $target->reconciliationSnapshot(
                new \Respawn\Reconstruction\FileSecrets($settings['secretManifest'] ?? ''), $settings['identity'], $catalog);
            $plan = \Respawn\Reconstruction\DesiredStatePlanner::plan($revision['blueprint'], $snapshot,
                $revision['managedScope'], $target->identity(), $revisionId, $action,
                $target->reconciliationCapabilities(), $target->managedOwner());
            $environment = $target->environment();
            $plan['compatibility'] = ['requiredModules' => $revision['moduleVersions'],
                'observedModules' => $environment['modules'] ?? [],
                'matches' => ($environment['modules'] ?? []) === $revision['moduleVersions']];
            $plan['providerReconciliationRequired'] = $this->providerRevisionDiffers($settings['identity'], $revision['contentHash']);
            $plan = \Respawn\Reconstruction\DesiredStatePlanner::reseal($plan);
            $revisions->savePlan($settings['identity'], $plan);
            $revisions->pin($settings['identity'], $revisionId, $plan['planId'], 'Previewed local ' . ucfirst($action) . ' plan');
            return $plan;
        }

        public function getLocalPlan(string $planId): array
        {
            return $this->revisionStore()->getPlan($this->settings()['identity'], $planId);
        }

        public function executeLocalPlan(string $planId, string $confirmation, ?\Closure $apply = null): array
        {
            $settings = $this->settings();
            if ($confirmation !== 'execute:' . $planId) { throw new \RuntimeException('LOCAL_PLAN_CONFIRMATION_INVALID'); }
            $this->assertNoConflictingProviderOperation($settings['identity']);
            $revisions = $this->revisionStore();
            $plan = $revisions->getPlan($settings['identity'], $planId);
            if (empty($plan['scope']['complete'])) { throw new \RuntimeException('PRE_OPERATION_SCOPE_INCOMPLETE'); }
            if (($plan['compatibility']['matches'] ?? false) !== true) { throw new \RuntimeException('MODULE_VERSION_MISMATCH'); }
            $revision = $revisions->get($settings['identity'], $plan['revisionId']);
            $target = $this->buildTarget($apply);
            $catalog = $revisions->operationCatalogForRevision($settings['identity'], $revision['revisionId']);
            $secretResolver = new \Respawn\Reconstruction\FileSecrets($settings['secretManifest'] ?? '');
            $snapshot = $target->reconciliationSnapshot($secretResolver, $settings['identity'], $catalog);
            $checkpointId = $revisions->checkpointCurrentState($settings['identity'], $revision['revisionId'],
                $plan['scope'], $snapshot, $planId);
            $revisions->pin($settings['identity'], $revision['revisionId'], $planId, 'Executing local recovery plan');
            $executor = new \Respawn\Reconstruction\BuildExecutor($target,
                new \Respawn\Storage\JsonStateStore($this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn'), $secretResolver);
            $result = $executor->executeDesiredState($revision['blueprint'], $plan, $planId, true);
            $revisions->markPlanExecuted($settings['identity'], $planId, $result);
            if (($result['state'] ?? '') === 'configuration_verified') {
                $revisions->markApplied($settings['identity'], $revision['revisionId'], $plan['scope'], $result);
                $revisions->unpin($settings['identity'], $revision['revisionId'], $planId);
            }
            return ['state' => $result['state'], 'action' => $plan['action'], 'revisionId' => $revision['revisionId'],
                'planId' => $planId, 'plan' => $plan, 'preOperationCheckpointId' => $checkpointId, 'result' => $result,
                'providerReconciliationRequired' => $plan['providerReconciliationRequired'] ?? true,
                'message' => 'Only supported configuration was changed. Provider routing, authority, billing and services were not changed.'];
        }

        private function assertNoConflictingProviderOperation(string $identity): void
        {
            $state = (new \Respawn\Storage\JsonStateStore($this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn'))
                ->update(static fn (array &$state): array => $state);
            foreach ($state['submissions'] ?? [] as $submission) {
                if (($submission['pbxId'] ?? '') !== $identity) { continue; }
                if (in_array($submission['state'] ?? '', ['submitted_outcome_unknown','launch_outcome_unknown'], true)) {
                    throw new \RuntimeException('PROVIDER_RECONCILIATION_REQUIRED');
                }
            }
            foreach ($state['operationRefs'] ?? [] as $reference) {
                if (($reference['pbxId'] ?? '') !== $identity) { continue; }
                if (!in_array($reference['operation']['state'] ?? '', ['torn_down','rolled_back'], true)) {
                    throw new \RuntimeException('PROVIDER_RECONCILIATION_REQUIRED');
                }
            }
        }

        private function providerRevisionDiffers(string $identity, string $revisionHash): bool
        {
            $connectionId = ($this->settings()['providerConfiguration'] ?? null) !== null
                ? \Respawn\Provider\ConnectionIdentity::forConfiguration($this->settings()['providerConfiguration'])
                : \Respawn\Provider\ConnectionIdentity::forConfiguration([], $this->settings()['mode'] ?? 'disabled');
            $scope = hash('sha256', $connectionId . "\0" . $identity);
            $entry = (new \Respawn\Storage\JsonStateStore($this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn'))
                ->update(static fn (array &$state): ?array => $state['localPublications'][$scope]['ack'] ?? null);
            return $entry !== null && ($entry['hash'] ?? '') !== $revisionHash;
        }

    public function saveSettings(array $settings): void
    {
        if (!in_array($settings['mode'] ?? '', ['disabled', 'mock', 'provider'], true)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $settings['identity'] ?? '')
            || !in_array($settings['intent'] ?? '', ['rehearsal', 'standby', 'recovery'], true)
            || !in_array($settings['activationPolicy'] ?? '', ['none', 'manual'], true)
            || ($settings['intent'] !== 'recovery' && $settings['activationPolicy'] !== 'none')) {
            throw new \RuntimeException('Invalid or unsafe Respawn settings.');
        }
        if (!is_bool($settings['protectionEnabled'] ?? false)
            || !is_int($settings['protectionIntervalSeconds'] ?? 300)
            || $settings['protectionIntervalSeconds'] < 300 || $settings['protectionIntervalSeconds'] > 86400
            || $settings['protectionIntervalSeconds'] % 300 !== 0
            || (!empty($settings['protectionEnabled']) && ($settings['mode'] ?? '') !== 'provider')) {
            throw new \RuntimeException('PROTECTION_SETTINGS_INVALID');
        }
        $old = $this->settings();
        if ($old && ($old['identity'] ?? '') !== $settings['identity']) {
            throw new \RuntimeException('PBX identity is immutable after installation; use an explicit future re-enrolment workflow.');
        }
        if (($settings['mode'] ?? '') === 'provider') {
            \Respawn\Provider\Configuration::validate($settings['providerConfiguration'] ?? []);
            if (!preg_match('/^[0-9]{2,8}(,[0-9]{2,8}){0,99}$/D', $settings['groups'] ?? '')) {
                throw new \RuntimeException('Explicit supported Ring Groups selection is required.');
            }
            if (!is_string($settings['secretManifest'] ?? null) || !str_starts_with($settings['secretManifest'], '/')
                || str_contains($settings['secretManifest'], '..')) { throw new \RuntimeException('Protected secret manifest reference required.'); }
        }
        $this->setConfig('settings', array_intersect_key($settings, array_flip(['mode', 'identity', 'intent',
            'activationPolicy', 'providerConfiguration', 'groups', 'secretManifest', 'protectionEnabled',
            'protectionIntervalSeconds'])));
    }

    public function application(): \Respawn\Application
    {
        $settings = $this->settings();
        $directory = $this->FreePBX->Config->get('ASTVARLIBDIR') . '/respawn';
        if (($settings['mode'] ?? '') === 'mock') {
            $firewall = new \Respawn\Firewall\FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
        } else {
            try { $firewall = new \Respawn\Firewall\FreePbxFirewallSource($this->FreePBX->Firewall); }
            catch (\Throwable $exception) { $firewall = new \Respawn\Firewall\UnavailableFirewallSource(); }
        }
        $provider = ($settings['mode'] ?? '') === 'provider'
            ? new \Respawn\Provider\HttpsProvider($settings['providerConfiguration']) : null;
        $exporter = fn (): array => $this->exportReconstruction(explode(',', $settings['groups'] ?? ''));
        $resolver = static function (array $record) use ($directory): \Respawn\Provider\Provider {
            $configuration = $record['configuration'] ?? [];
            if (($configuration['mode'] ?? '') === 'mock') {
                return new \Respawn\Provider\MockProvider(new \Respawn\Storage\JsonStateStore($directory),
                    $record['pbxId'], true, null, $record['connectionId']);
            }
            if (($configuration['mode'] ?? '') === 'provider' && is_array($configuration['providerConfiguration'] ?? null)) {
                return new \Respawn\Provider\HttpsProvider($configuration['providerConfiguration']);
            }
            throw new \RuntimeException('ORIGINAL_PROVIDER_CONNECTION_UNAVAILABLE');
        };
        return new \Respawn\Application($settings, new \Respawn\Storage\JsonStateStore($directory),
            $firewall, $provider, $exporter, $resolver);
    }
}