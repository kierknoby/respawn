<?php
declare(strict_types=1);
namespace Respawn\Pond;

use Respawn\Provider\HttpsProvider;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\FileSecrets;
use RuntimeException;

final class RecoveryBundle
{
    public static function write(HttpsProvider $provider, string $sourcePbxId, string $output): array
    {
        Pond::privatePath(dirname($output), true);
        if (file_exists($output) || is_link($output) || !mkdir($output, 0700)) { throw new RuntimeException('RECOVERY_DIRECTORY_MUST_BE_NEW'); }
        Pond::privatePath($output, true);
        $retained = $provider->retrieveLatestRevision($sourcePbxId);
        $blueprint = $retained['blueprint'];
        $manifest = ['sourcePbxId'=>$sourcePbxId, 'references'=>[]];
        foreach (BuildSpec::plan($blueprint) as $operation) {
            foreach ($operation['secretRefs'] as $reference) {
                if (isset($manifest['references'][$reference])) { continue; }
                $credential = $provider->retrieveCredential($sourcePbxId, $reference);
                $path = $output . '/' . hash('sha256', $reference) . '.secret';
                self::writePrivate($path, $credential['secret']);
                $manifest['references'][$reference] = $path;
            }
        }
        self::writePrivate($output . '/secrets.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $resolver = new FileSecrets($output . '/secrets.json');
        foreach ($manifest['references'] as $reference => $path) { $resolver->resolve($reference, $sourcePbxId); }
        self::writePrivate($output . '/blueprint.json', json_encode($blueprint, JSON_THROW_ON_ERROR));
        $directory = fopen($output, 'r');
        if ($directory === false) { throw new RuntimeException('RECOVERY_SYNC_FAILED'); }
        try { if (!fsync($directory)) { throw new RuntimeException('RECOVERY_SYNC_FAILED'); } }
        finally { fclose($directory); }
        return ['state'=>'recovery_material_retrieved', 'revisionId'=>$retained['revisionId'],
            'contentHash'=>$blueprint['contentHash'], 'telephonyVerified'=>false];
    }

    private static function writePrivate(string $path, string $contents): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) { throw new RuntimeException('RECOVERY_WRITE_FAILED'); }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fsync($handle)) {
                throw new RuntimeException('RECOVERY_WRITE_FAILED');
            }
        } finally { fclose($handle); }
    }
}