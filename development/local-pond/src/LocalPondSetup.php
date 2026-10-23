<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use RuntimeException;

final class LocalPondSetup
{
    public static function initialise(string $root, string $fixturePath): void
    {
        if (!str_starts_with($root, '/') || is_link($root)) { throw new RuntimeException('LOCAL_DEMO_ROOT_UNSAFE'); }
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) { throw new RuntimeException('LOCAL_DEMO_ROOT_CREATE_FAILED'); }
        chmod($root, 0700);
        $marker = $root . '/.respawn-local-demo';
        if (file_exists($marker)) {
            if (is_link($marker) || trim((string) file_get_contents($marker)) !== 'respawn-local-pond-dev-v1') {
                throw new RuntimeException('LOCAL_DEMO_MARKER_INVALID');
            }
        } elseif (count(array_diff(scandir($root) ?: [], ['.', '..'])) !== 0) {
            throw new RuntimeException('LOCAL_DEMO_NONEMPTY_UNOWNED_ROOT');
        } else {
            self::write($marker, 'respawn-local-pond-dev-v1');
        }

        $fixtures = json_decode((string) file_get_contents($fixturePath), true, 24, JSON_THROW_ON_ERROR);
        if (($fixtures['schema'] ?? '') !== 'respawn.local-source-fixtures.v1') { throw new RuntimeException('LOCAL_DEMO_FIXTURE_INVALID'); }
        $credentialRoot = $root . '/credentials';
        $secretRoot = $credentialRoot . '/source-secrets';
        foreach ([$credentialRoot, $secretRoot] as $directory) {
            if (is_link($directory)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_DIRECTORY_UNSAFE'); }
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_DIRECTORY_FAILED'); }
            chmod($directory, 0700);
        }
        $enrolments = [];
        foreach ($fixtures['sources'] as $pbxId => $source) {
            $tokenPath = $credentialRoot . '/' . $pbxId . '.token';
            $token = self::existingOrCreateToken($tokenPath);
            $enrolments[$pbxId] = ['tokenHash' => hash('sha256', $token), 'state' => 'authorised'];
            $references = [];
            foreach (array_keys($source['devices']) as $number) {
                $reference = 'secret:' . $pbxId . ':' . $number . ':v1';
                $safeName = $pbxId . '-' . $number . '.secret';
                $references[$reference] = $secretRoot . '/' . $safeName;
                if (!is_file($references[$reference])) { self::write($references[$reference], bin2hex(random_bytes(24))); }
                if (is_link($references[$reference]) || (fileperms($references[$reference]) & 0077) !== 0) {
                    throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_UNSAFE');
                }
            }
            $manifestPath = $credentialRoot . '/' . $pbxId . '.manifest.json';
            if (is_link($manifestPath)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_UNSAFE'); }
            if (!is_file($manifestPath)) {
                self::write($manifestPath, json_encode(['sourcePbxId' => $pbxId, 'references' => $references], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        }
        $enrolmentPath = $credentialRoot . '/enrolments.json';
        if (is_link($enrolmentPath)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_UNSAFE'); }
        if (!is_file($enrolmentPath)) { self::write($enrolmentPath, json_encode($enrolments, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); }
        else {
            $stored = json_decode((string) file_get_contents($enrolmentPath), true, 12, JSON_THROW_ON_ERROR);
            foreach ($enrolments as $pbxId => $record) {
                if (($stored[$pbxId]['tokenHash'] ?? '') !== $record['tokenHash']
                    || ($stored[$pbxId]['state'] ?? '') !== 'authorised') {
                    throw new RuntimeException('LOCAL_DEMO_ENROLMENT_CREDENTIAL_MISMATCH');
                }
            }
        }
        LocalRecoveryService::seedAll($root);
    }

    private static function existingOrCreateToken(string $path): string
    {
        if (!is_file($path)) { self::write($path, bin2hex(random_bytes(32))); }
        if (is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) {
            throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_UNSAFE');
        }
        $token = trim((string) file_get_contents($path));
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new RuntimeException('LOCAL_DEMO_CREDENTIAL_INVALID'); }
        return $token;
    }

    private static function write(string $path, string $contents): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents . "\n", LOCK_EX) === false) { throw new RuntimeException('LOCAL_DEMO_FILE_WRITE_FAILED'); }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { unlink($temporary); throw new RuntimeException('LOCAL_DEMO_FILE_RENAME_FAILED'); }
    }
}