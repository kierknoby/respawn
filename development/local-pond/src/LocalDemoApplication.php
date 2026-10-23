<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Application;
use Respawn\Firewall\UnavailableFirewallSource;
use Respawn\Provider\Provider;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class LocalDemoApplication
{
    public static function build(string $root, string $pbxId, string $profile, string $intent = 'recovery',
        string $activationPolicy = 'manual', bool $dropNextLaunchResponse = false): Application
    {
        if (!in_array($pbxId, ['pbx-alpha','pbx-bravo'], true)
            || !in_array($profile, ['primary','alternate'], true)
            || !in_array($intent, ['recovery','rehearsal','standby'], true)
            || !in_array($activationPolicy, ['none','manual'], true)
            || ($intent !== 'recovery' && $activationPolicy !== 'none')) {
            throw new RuntimeException('LOCAL_DEMO_CONFIGURATION_INVALID');
        }
        $pondPort = localPondPort();
        $endpoint = 'http://127.0.0.1:' . $pondPort . ($profile === 'alternate' ? '/alternate/rpc' : '/rpc');
        $tokenPath = $root . '/credentials/' . $pbxId . '.token';
        $providerConfiguration = ['endpoint' => $endpoint, 'credentialRef' => $tokenPath, 'sourcePbxId' => $pbxId];
        $provider = new LocalPondProvider($providerConfiguration + ['dropNextLaunchResponse' => $dropNextLaunchResponse]);
        $source = new LocalRecoveryService($root, $pbxId);
        $store = new JsonStateStore($root . '/operator-state');
        $settings = ['identity' => $pbxId, 'mode' => 'provider', 'intent' => $intent,
            'activationPolicy' => $activationPolicy, 'providerConfiguration' => $providerConfiguration];
        $resolver = static function (array $record) use ($pbxId): Provider {
            $configuration = $record['configuration']['providerConfiguration'] ?? [];
            if (($record['pbxId'] ?? '') !== $pbxId || !is_string($configuration['endpoint'] ?? null)
                || !str_starts_with($configuration['endpoint'], 'http://127.0.0.1:')) {
                throw new RuntimeException('LOCAL_DEMO_ORIGINAL_CONNECTION_INVALID');
            }
            return new LocalPondProvider($configuration);
        };
        return new Application($settings, $store, new UnavailableFirewallSource(), $provider,
            static function () use ($source): array { return $source->exportBlueprint(); }, $resolver);
    }
}