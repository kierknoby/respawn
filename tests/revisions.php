<?php
declare(strict_types=1);

require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\DesiredStatePlanner;
use Respawn\Reconstruction\MemorySecrets;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function revisionCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}

function revisionReject(callable $callback, string $code, string $message): void
{
    try { $callback(); }
    catch (Throwable $exception) {
        if (!str_contains($exception->getMessage(), $code)) { throw $exception; }
        revisionCheck(true, $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

function revisionVariant(array $blueprint, string $variant): array
{
    $users = []; $devices = []; $groups = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $type = $operation['object']['type'];
        $number = $operation['object']['identity'];
        if ($type === 'user') {
            $parameters = $operation['parameters'];
            if ($variant === 'B' && $number === '1001') { $parameters['name'] = 'Alpha 1001 Operations'; }
            $users[$number] = $parameters;
        } elseif ($type === 'pjsip') {
            $parameters = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']];
            if ($variant === 'B' && $number === '1001') {
                $parameters['description'] = 'Alpha 1001 Operations';
                $parameters['authUsername'] = 'Alpha-1001-Operations';
            }
            $devices[$number] = $parameters;
        } else {
            $group = ['number' => $number] + $operation['parameters'];
            if ($variant === 'B' && $number === '600') { $group['timeout'] = 25; }
            if ($variant === 'C' && $number === '600') { $group['destination'] = 'hangup'; }
            if ($variant === 'C' && $number === '601') { continue; }
            if ($variant === 'C' && $number === '600') { $group['members'] = ['1001']; }
            $groups[] = $group;
        }
    }
    if ($variant === 'C') { unset($users['1002'], $devices['1002']); }
    return BuildSpec::create($blueprint['sourcePbxId'], $blueprint['modules'], $users, $devices, $groups);
}

function revisionRemoveTree(string $path): void
{
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) { if (file_exists($path)) { unlink($path); } return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { revisionRemoveTree($path . '/' . $entry); }
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/respawn-revisions-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $blueprintA = SimulatedPbx::blueprint('pbx-alpha');
    $secrets = [];
    foreach (BuildSpec::plan($blueprintA) as $operation) {
        foreach ($operation['secretRefs'] as $reference) { $secrets[$reference] = bin2hex(random_bytes(24)); }
    }
    $resolver = new MemorySecrets($secrets);
    $owner = 'local-managed:pbx-alpha';
    $target = new SimulatedPbx(new JsonStateStore($root . '/objects'), 'existing-pbx-alpha', $owner);
    $executor = new BuildExecutor($target, new JsonStateStore($root . '/checkpoints'), $resolver);
    $bootstrap = $executor->execute($blueprintA, true);
    revisionCheck($bootstrap['state'] === 'configuration_verified', 'initial existing-PBX fixture uses the shared BuildExecutor');
    $target->snapshot();
    $foreign = ['state' => 'complete', 'parameters' => ['name' => 'Unmanaged object', 'voicemail' => 'novm'],
        'owner' => 'unrelated-administrator', 'shared' => true, 'dependsOn' => [], 'secretRefs' => [], 'revision' => 1];
    (new JsonStateStore($root . '/objects'))->update(static function (array &$state) use ($foreign): void {
        $state['objects']['user:9000'] = $foreign;
    });

    $revisions = new RevisionStore(new JsonStateStore($root . '/revision-cache'), 10);
    $revisionA = $revisions->capture($blueprintA);
    $revisions->markApplied('pbx-alpha', $revisionA['revisionId'], $revisionA['managedScope'], $bootstrap);
    revisionCheck($revisionA['classification'] === 'captured_not_known_good', 'capture does not claim a revision is known good');
    $heads = $revisions->heads('pbx-alpha');
    revisionCheck(($heads['lastAppliedRevisionId'] ?? '') === $revisionA['revisionId']
        && ($heads['lastVerifiedRevisionId'] ?? '') === $revisionA['revisionId'], 'last applied and supported-configuration-verified heads are explicit');

    (new JsonStateStore($root . '/objects'))->update(static function (array &$state): void {
        $state['objects']['pjsip:1001']['parameters']['settings']['allow'] = 'alaw';
    });
    $current = $target->reconciliationSnapshot($resolver, 'pbx-alpha');
    $planA = DesiredStatePlanner::plan($blueprintA, $current, $revisionA['managedScope'], $target->identity(),
        $revisionA['revisionId'], 'regenerate', $target->reconciliationCapabilities(), $owner);
    revisionCheck(($planA['mutations'][0]['mutation'] ?? '') === 'update', 'Regenerate plans an owned drift repair instead of replaying creates');
    $revisions->savePlan('pbx-alpha', $planA);
    $resultA = $executor->executeDesiredState($blueprintA, $revisions->getPlan('pbx-alpha', $planA['planId']), $planA['planId'], true);
    $revisions->markPlanExecuted('pbx-alpha', $planA['planId'], $resultA);
    $revisions->markApplied('pbx-alpha', $revisionA['revisionId'], $revisionA['managedScope'], $resultA);
    revisionCheck($resultA['state'] === 'configuration_verified'
        && $target->reconciliationSnapshot($resolver, 'pbx-alpha')['pjsip:1001']['parameters']['settings']['allow'] === 'ulaw,alaw',
        'Regenerate repairs supported configuration and verifies its read-back');
    revisionCheck(isset($target->snapshot()['objects']['user:9000']), 'Regenerate preserves an unrelated foreign/shared object');

    $blueprintB = revisionVariant($blueprintA, 'B');
    $revisionB = $revisions->capture($blueprintB);
    $comparison = $revisions->compare('pbx-alpha', $revisionA['revisionId'], $revisionB['revisionId']);
    revisionCheck(count($comparison['changes']['update']) >= 3, 'revision comparison includes user, dependent device and group changes');
    $snapshot = $target->reconciliationSnapshot($resolver, 'pbx-alpha');
    $planB = DesiredStatePlanner::plan($blueprintB, $snapshot, $revisionB['managedScope'], $target->identity(),
        $revisionB['revisionId'], 'regenerate', $target->reconciliationCapabilities(), $owner);
    $revisions->savePlan('pbx-alpha', $planB);
    $resultB = $executor->executeDesiredState($blueprintB, $revisions->getPlan('pbx-alpha', $planB['planId']), $planB['planId'], true);
    revisionCheck($resultB['state'] === 'configuration_verified', 'Regenerate applies a captured dependency-spanning revision');

    $snapshotB = $target->reconciliationSnapshot($resolver, 'pbx-alpha');
    $revertA = DesiredStatePlanner::plan($blueprintA, $snapshotB,
        $revisionA['managedScope'], $target->identity(), $revisionA['revisionId'], 'revert',
        $target->reconciliationCapabilities(), $owner);
    revisionCheck($revertA['action'] === 'revert' && count($revertA['mutations']) >= 3, 'Revert is a deliberate selected-state plan distinct from Regenerate');
    $revertResult = $executor->executeDesiredState($blueprintA, $revertA, $revertA['planId'], true);
    revisionCheck($revertResult['state'] === 'configuration_verified'
        && $target->reconciliationSnapshot($resolver, 'pbx-alpha')['user:1001']['parameters']['name'] === 'Alpha 1001',
        'Revert converges existing target to the selected earlier revision');

    $planBeforeDrift = DesiredStatePlanner::plan($blueprintB, $target->reconciliationSnapshot($resolver, 'pbx-alpha'),
        $revisionB['managedScope'], $target->identity(), $revisionB['revisionId'], 'regenerate',
        $target->reconciliationCapabilities(), $owner);
    (new JsonStateStore($root . '/objects'))->update(static function (array &$state): void {
        $state['objects']['user:1001']['parameters']['name'] = 'Concurrent administrator edit';
    });
    revisionReject(fn () => $executor->executeDesiredState($blueprintB, $planBeforeDrift,
        $planBeforeDrift['planId'], true), 'DESIRED_STATE_PLAN_STALE', 'a target change after preview rejects the stale plan');

    $snapshot = $target->reconciliationSnapshot($resolver, 'pbx-alpha');
    $partialScope = $revisionA['managedScope']; $partialScope['complete'] = false;
    $partialPlan = DesiredStatePlanner::plan($blueprintVariantC = revisionVariant($blueprintA, 'C'), $snapshot,
        $partialScope, $target->identity(), $revisionA['revisionId'], 'regenerate',
        $target->reconciliationCapabilities(), $owner);
    revisionCheck(count($partialPlan['unsupportedDifferences']) > 0,
        'a partial selected blueprint does not authorise absence-based deletion');

    $revisionC = $revisions->capture($blueprintVariantC);
    $snapshot = $target->reconciliationSnapshot($resolver, 'pbx-alpha');
    $removePlan = DesiredStatePlanner::plan($blueprintVariantC, $snapshot, $revisionC['managedScope'],
        $target->identity(), $revisionC['revisionId'], 'regenerate', $target->reconciliationCapabilities(), $owner);
    $removeIds = array_values(array_map(static fn (array $item): string => $item['id'],
        array_filter($removePlan['mutations'], static fn (array $item): bool => ($item['mutation'] ?? '') === 'remove')));
    revisionCheck($removeIds === ['ringgroup:601','pjsip:1002','user:1002'],
        'removals respect dependants: group, device, then user');
    $removed = $executor->executeDesiredState($blueprintVariantC, $removePlan, $removePlan['planId'], true);
    revisionCheck($removed['state'] === 'configuration_verified'
        && !isset($target->snapshot()['objects']['ringgroup:601'])
        && isset($target->snapshot()['objects']['user:9000']), 'owned removal converges while preserving shared/foreign state');

    echo "Revision, Revert and Regenerate tests passed. Synthetic target only; no FreePBX or provider calls.\n";
} finally { revisionRemoveTree($root); }