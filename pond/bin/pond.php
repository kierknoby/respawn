<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/module/bootstrap.php';
require dirname(__DIR__) . '/Pond.php';
require dirname(__DIR__) . '/RecoveryBundle.php';

use Respawn\Pond\Pond;
use Respawn\Provider\HttpsProvider;

umask(0077);
try {
    $action = $argv[1] ?? '';
    if ($action === 'init' && count($argv) === 4) {
        [$script, $action, $directory, $keyFile] = $argv;
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('POND_DIRECTORY_FAILED'); }
        Pond::privatePath($directory, true);
        $handle = fopen($keyFile, 'x');
        if ($handle === false) { throw new RuntimeException('POND_KEY_EXISTS_OR_UNWRITABLE'); }
        try {
            if (!chmod($keyFile, 0600)) { throw new RuntimeException('POND_KEY_PERMISSIONS_FAILED'); }
            if (fwrite($handle, random_bytes(32)) !== 32 || !fsync($handle)) { throw new RuntimeException('POND_KEY_WRITE_FAILED'); }
        } finally { fclose($handle); }
        new Pond($directory, $keyFile);
        echo "Pond initialised. Back up the key separately; never store its only copy on the source PBX.\n";
    } elseif ($action === 'status' && count($argv) === 4) {
        [, , $directory, $keyFile] = $argv;
        echo json_encode(Pond::health($directory, $keyFile), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    } elseif (in_array($action, ['enrol','revoke'], true) && count($argv) === 6) {
        [$script, $action, $directory, $keyFile, $enrolmentFile, $tokenFile] = $argv;
        Pond::privatePath($enrolmentFile, false);
        Pond::privatePath($tokenFile, false);
        $record = json_decode(file_get_contents($enrolmentFile), true, 16, JSON_THROW_ON_ERROR);
        $token = trim(file_get_contents($tokenFile));
        $pond = new Pond($directory, $keyFile);
        if ($action === 'revoke') { $pond->revoke($token); }
        else { $pond->enrol($token, $record['tenantId'], $record['pbxId'], $record['connectionId'], $record['permissions']); }
        echo "Enrolment updated locally; no recovery activation authority granted.\n";
    } elseif ($action === 'recover' && count($argv) === 4) {
        [$script, $action, $configurationFile, $output] = $argv;
        Pond::privatePath($configurationFile, false);
        $configuration = json_decode(file_get_contents($configurationFile), true, 16, JSON_THROW_ON_ERROR);
        $provider = new HttpsProvider($configuration);
        echo json_encode(\Respawn\Pond\RecoveryBundle::write($provider, $configuration['sourcePbxId'], $output), JSON_THROW_ON_ERROR) . "\n";
    } else {
        throw new RuntimeException('Usage: pond.php init STATE KEY | status STATE KEY | enrol|revoke STATE KEY ENROLMENT_JSON TOKEN_FILE | recover PROVIDER_CONFIG NEW_PRIVATE_DIRECTORY');
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, "Pond command failed; check protected inputs, permissions, enrolment and service availability.\n");
    exit(1);
}