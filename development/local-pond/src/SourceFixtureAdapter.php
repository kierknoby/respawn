<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\FileSecrets;
use RuntimeException;

final class SourceFixtureAdapter
{
    public function __construct(private readonly string $fixturePath, private readonly string $manifestPath) {}

    public function read(string $pbxId): array
    {
        $fixture = json_decode((string) file_get_contents($this->fixturePath), true, 24, JSON_THROW_ON_ERROR);
        $source = $fixture['sources'][$pbxId] ?? null;
        if (!is_array($source) || ($source['pbxId'] ?? '') !== $pbxId) {
            throw new RuntimeException('SOURCE_FIXTURE_IDENTITY_UNKNOWN');
        }
        $secrets = new FileSecrets($this->manifestPath);
        $users = $source['users'] ?? [];
        $devices = $source['devices'] ?? [];
        $groups = $source['ringGroups'] ?? [];
        foreach ($devices as $number => &$device) {
            $reference = $secrets->reference($pbxId, (string) $number);
            $secrets->resolve($reference, $pbxId);
            $device['secretRef'] = $reference;
        }
        unset($device);
        return ['pbxId' => $pbxId, 'modules' => $fixture['modules'], 'users' => $users,
            'devices' => $devices, 'ringGroups' => $groups,
            'sourceAdapter' => 'LOCAL_FIXTURE_READ_ONLY', 'secretResolver' => $secrets];
    }

    public function export(string $pbxId): array
    {
        $snapshot = $this->read($pbxId);
        return BuildSpec::create($snapshot['pbxId'], $snapshot['modules'], $snapshot['users'],
            $snapshot['devices'], $snapshot['ringGroups']);
    }
}