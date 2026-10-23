<?php
declare(strict_types=1);

use Respawn\Application;
use Respawn\Provider\Provider;
use Respawn\Provider\MockProvider;
use Respawn\Storage\JsonStateStore;
use Respawn\Firewall\FixtureFirewallSource;

$applicationStore = new JsonStateStore($directory . '/application');
$underlying = new MockProvider($applicationStore, 'pbx-alpha');
$interrupted = new class($underlying) implements Provider {
    public bool $loseResponse = true;
    public function __construct(private Provider $delegate) {}
    public function connectionIdentity(): string { return $this->delegate->connectionIdentity(); }
    public function capabilities(): array { return $this->delegate->capabilities(); }
    public function status(): array { return $this->delegate->status(); }
    public function publish(string $pbxId, array $intent, bool $force = false): array { return $this->delegate->publish($pbxId, $intent, $force); }
    public function launch(array $request): array {
        $result = $this->delegate->launch($request);
        if ($this->loseResponse) { $this->loseResponse = false; throw new RuntimeException('Simulated lost response after acceptance.'); }
        return $result;
    }
    public function getOperation(string $operationId): array { return $this->delegate->getOperation($operationId); }
    public function reconcile(string $idempotencyKey): array { return $this->delegate->reconcile($idempotencyKey); }
    public function advance(string $operationId, ?string $failAt = null): array { return $this->delegate->advance($operationId, $failAt); }
    public function cancel(string $operationId): array { return $this->delegate->cancel($operationId); }
    public function activate(string $operationId, int $revision, string $confirmation): array { return $this->delegate->activate($operationId, $revision, $confirmation); }
    public function teardown(string $operationId, ?string $routingDisposition = null): array { return $this->delegate->teardown($operationId, $routingDisposition); }
};
$settings = ['identity' => 'pbx-alpha', 'mode' => 'provider', 'intent' => 'rehearsal', 'activationPolicy' => 'none'];
$firewallSource = new FixtureFirewallSource(__DIR__ . '/fixtures/firewall.json');
$application = new Application($settings, $applicationStore, $firewallSource, $interrupted, fn (): array => $blueprint);
$application->run('publish');
$launchArguments = ['key' => 'application-lost-response-001'];
rejects(fn () => $application->run('respawn', $launchArguments), 'accepted operation with lost response remains unknown locally');
$submissions = $application->run('status')['localSubmissions'];
$journal = current(array_filter($submissions, static fn (array $entry): bool => ($entry['idempotencyKey'] ?? '') === $launchArguments['key']));
if (!is_array($journal)) { throw new RuntimeException('Local submission journal entry missing.'); }
check($journal['state'] === 'launch_outcome_unknown', 'launch request remains journalled as outcome-unknown after response loss');
$changed = $blueprint;
$newSettings = array_replace($settings, ['intent' => 'standby']);
$refreshed = new Application($newSettings, $applicationStore, $firewallSource, $interrupted, fn (): array => $changed);
$recovered = $refreshed->run('retrySubmission', ['submissionId' => array_key_first($submissions)]);
check($recovered['request']['intent'] === 'rehearsal', 'retry retains original policy rather than changed GUI settings');
check(count($underlying->status()['operations']) === 1, 'application retry creates no duplicate provider operation');
$underlying->cancel($recovered['operationId']);
$underlying->teardown($recovered['operationId']);
$changedGroups = $groups;
$changedGroups[0]['description'] = 'Pending change';
$changed = Respawn\Reconstruction\Blueprint::create('pbx-alpha', $changedGroups);
$pendingApplication = new Application($settings, $applicationStore, $firewallSource, $underlying, fn (): array => $changed);
rejects(fn () => $pendingApplication->run('respawn', ['key' => 'new-unacknowledged-launch-001']), 'new launch blocked for changed unacknowledged reconstruction');
$bravoDocument = Application::fixtureBlueprint('pbx-bravo');
$bravoApplication = new Application(array_replace($settings, ['identity' => 'pbx-bravo']), $applicationStore,
    $firewallSource, new MockProvider($applicationStore, 'pbx-bravo'), fn (): array => $bravoDocument);
$bravoApplication->run('publish');
$bravoLaunch = $bravoApplication->run('respawn', $launchArguments);
check($bravoLaunch['pbxId'] === 'pbx-bravo' && $bravoLaunch['operationId'] !== $recovered['operationId'], 'same operator key on another PBX cannot reuse foreign local submission');
check(count($bravoApplication->run('status')['localSubmissions']) === 1, 'local status exposes only this PBX submission journal');
foreach (glob($directory . '/application/*') as $path) { unlink($path); }
rmdir($directory . '/application');