<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Provider\MockProvider;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\MemorySecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

$temporary = sys_get_temp_dir() . '/respawn-demo-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$output = __DIR__ . '/../artifacts/demo';
if (!is_dir($output)) { mkdir($output, 0700, true); }
$report = ['mode' => 'MOCK / SYNTHETIC TWO-PBX DEMONSTRATION', 'realTakeoverVerified' => false, 'pbxs' => []];
try {
    foreach (['pbx-alpha', 'pbx-bravo'] as $identity) {
        $store = new JsonStateStore($temporary);
        $template = SimulatedPbx::blueprint($identity);
        $values = [];
        foreach (BuildSpec::plan($template) as $instruction) {
            foreach ($instruction['secretRefs'] as $reference) { $values[$reference] = bin2hex(random_bytes(24)); }
        }
        $secrets = new MemorySecrets($values);
        $source = new SimulatedPbx($store->child('source:' . $identity), 'source-' . $identity, 'source-seed');
        foreach (BuildSpec::plan($template) as $instruction) { $source->create($instruction, $secrets, $identity); }
        $selection = array_column(BuildSpec::plan($template), 'id');
        $blueprint = $source->export($identity, $selection, $secrets);
        $targetStore = $store->child('clean-target:' . $identity);
        $target = new SimulatedPbx($targetStore, 'target-' . $identity, 'demo-' . $identity);
        $executor = new BuildExecutor($target, $store->child('checkpoints:' . $identity), $secrets);
        $target->loseResponse = $identity === 'pbx-alpha' ? 'pjsip:1001' : 'pjsip:2001';
        $interrupted = $executor->execute($blueprint, true);
        if ($interrupted['state'] !== 'unknown') { throw new RuntimeException('Demo did not exercise an unknown accepted mutation.'); }
        $target->loseResponse = null;
        $reconstructed = $executor->execute($blueprint, true);
        $matched = $target->export($identity, $selection, $secrets) === $blueprint;
        if (!$matched || $reconstructed['state'] !== 'configuration_verified' || count($target->snapshot()['creationOrder']) !== 6) {
            throw new RuntimeException('Demo reconstruction or retry failed.');
        }
        $targetStore->update(static function (array &$state): void {
            $state['objects']['pre-existing-shared'] = ['state' => 'complete', 'owner' => 'another-owner',
                'shared' => true, 'parameters' => [], 'dependsOn' => []];
        });
        $preserved = $target->cleanup();
        if ($preserved !== ['pre-existing-shared']) { throw new RuntimeException('Demo cleanup damaged shared or retained owned configuration.'); }
        $conflictStore = $store->child('conflict:' . $identity);
        $conflictTarget = new SimulatedPbx($conflictStore, 'conflict-' . $identity);
        $firstUser = array_values(array_filter(BuildSpec::plan($blueprint), static fn (array $instruction): bool => $instruction['object']['type'] === 'user'))[0];
        $conflictTarget->create($firstUser, $secrets, $identity);
        $conflictStore->update(static function (array &$state) use ($firstUser): void { $state['objects'][$firstUser['id']]['parameters']['name'] = 'Foreign configuration'; });
        $conflict = (new BuildExecutor($conflictTarget, $store->child('conflict-journal:' . $identity), $secrets))->execute($blueprint, true);
        if ($conflict['state'] !== 'failed' || count($conflictTarget->snapshot()['objects']) !== 1) { throw new RuntimeException('Demo overwrote a conflicting object.'); }
        $provider = new MockProvider($store, $identity, true, $secrets);
        $publication = $provider->publish($identity, $blueprint);
        $ack = $publication['ack'];
        $request = ['pbxId' => $identity, 'blueprintId' => $ack['blueprintId'], 'blueprintVersion' => $ack['version'],
            'intent' => 'rehearsal', 'activationPolicy' => 'none', 'idempotencyKey' => 'demo-' . $identity . '-stable-request',
            'requestedCapabilities' => ['rehearsal','reconstruction.v2','apply.freepbx']];
        $operation = $provider->launch($request);
        $duplicate = $provider->launch($request);
        $states = [$operation['state']];
        for ($stage = 0; $stage < 5; $stage++) {
            $operation = $provider->advance($operation['operationId']);
            $states[] = $operation['state'];
        }
        $ready = $operation;
        if (($ready['reconstruction']['state'] ?? '') !== 'configuration_verified'
            || ($ready['reconstruction']['apply']['state'] ?? '') !== 'succeeded'
            || ($ready['readiness']['serviceReady'] ?? true) !== false || $ready['realCallsVerified'] !== false) {
            throw new RuntimeException('Provider readiness did not preserve reconstruction/apply and live-call boundaries.');
        }
        $provider->publish($identity, $blueprint, true);
        if ($provider->launch($request)['request'] !== $request) { throw new RuntimeException('Operation changed after publication retry.'); }
        $provider->cancel($operation['operationId']);
        $cleaned = $provider->teardown($operation['operationId']);
        $report['pbxs'][$identity] = ['capabilities' => $provider->capabilities(), 'publication' => $publication,
            'noChange' => $provider->publish($identity, $blueprint)['state'],
            'duplicateSameOperation' => $duplicate['operationId'] === $operation['operationId'],
            'sourceObjectIds' => $selection, 'plannedOrder' => array_column(BuildSpec::plan($blueprint), 'id'),
            'interruptedBuild' => $interrupted, 'reconstruction' => $reconstructed, 'targetExportMatches' => $matched,
            'conflictResult' => $conflict, 'sharedRecordsPreserved' => $preserved,
            'states' => $states, 'ready' => $ready, 'cleanup' => $cleaned];
        foreach ($values as $value) {
            if (str_contains(json_encode($report), $value)) { throw new RuntimeException('Synthetic credential leaked into demo report.'); }
        }
        file_put_contents($output . '/' . $identity . '-blueprint.json', json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    file_put_contents($output . '/two-pbx-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if (array_intersect($report['pbxs']['pbx-alpha']['sourceObjectIds'], $report['pbxs']['pbx-bravo']['sourceObjectIds'])) {
        throw new RuntimeException('Distinct source identities/configuration were not demonstrated.');
    }
    echo 'PASS: two distinct simulated source PBXs exported users/PJSIP/group chains; separate clean targets reconciled lost responses, applied once, matched intent, preserved shared records and completed mock orchestration. No real takeover.' . "\n";
    echo 'Evidence: ' . realpath($output) . "\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temporary);
}