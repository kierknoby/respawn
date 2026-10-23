<?php
declare(strict_types=1);

namespace Respawn\Storage;

use RuntimeException;

final class JsonStateStore
{
    private int $ownerUid;
    private int $ownerGid;

    public function __construct(private readonly string $directory)
    {
        self::assertNoSymlinkPath($directory);
        if (!is_dir($directory)) { self::createDirectoryTree($directory); }
        if (is_link($directory) || !is_writable($directory) || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('RESPAWN_STATE_DIRECTORY_UNSAFE_OR_UNWRITABLE');
        }
        $parent=dirname($directory);
        $parentMode=fileperms($parent);
        if ((fileowner($parent)!==0 || ($parentMode & 01000)===0)
            && (fileowner($directory)!==fileowner($parent) || filegroup($directory)!==filegroup($parent))) {
            throw new RuntimeException('RESPAWN_STATE_DIRECTORY_OWNER_MISMATCH');
        }
        $this->ownerUid = fileowner($directory);
        $this->ownerGid = filegroup($directory);
    }

    public function update(callable $callback): mixed
    {
        $lockPath = $this->directory . '/state.lock';
        if (is_link($lockPath)) { throw new RuntimeException('RESPAWN_STATE_LOCK_UNSAFE'); }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('RESPAWN_STATE_LOCK_UNAVAILABLE');
        }
        try {
            $this->setManagedPermissions($lockPath, 0600);
            $path = $this->directory . '/state.json';
            $state = $this->readFile($path);
            $result = $callback($state);
            if (isset($state['__respawnStorage'])) { throw new RuntimeException('STATE_RESERVED_FIELD'); }
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $reachable = [];
            if (strlen($encoded) > 524288) {
                $packed = [];
                foreach ($state as $key => $value) { $packed[$key] = $this->pack($value, $reachable); }
                $packed['__respawnStorage'] = 'chunks.v1';
                $encoded = json_encode($packed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            }
            if (strlen($encoded) + 1 > 1048576) {
                throw new RuntimeException('Respawn state capacity exceeded; retain evidence and reconcile before manual archival.');
            }
            $temporary = tempnam($this->directory, '.respawn-');
            if ($temporary === false) {
                throw new RuntimeException('Could not create temporary state file.');
            }
            try {
                $this->setManagedPermissions($temporary, 0600);
                $stream = @fopen($temporary, 'wb');
                if ($stream === false) { throw new RuntimeException('RESPAWN_STATE_TEMPORARY_UNAVAILABLE'); }
                try {
                    if (fwrite($stream, $encoded . "\n") !== strlen($encoded) + 1 || !fflush($stream) || !fsync($stream)) {
                        throw new RuntimeException('Could not flush durable Respawn checkpoint.');
                    }
                } finally { fclose($stream); }
                $this->alignManagedOwner($temporary);
                if (!rename($temporary, $path)) {
                    throw new RuntimeException('RESPAWN_STATE_ATOMIC_REPLACE_FAILED');
                }
                $directory = @fopen($this->directory, 'r');
                if ($directory === false) { throw new RuntimeException('Could not open checkpoint directory.'); }
                try {
                    if (!fsync($directory)) { throw new RuntimeException('Could not flush checkpoint directory.'); }
                } finally { fclose($directory); }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            $this->collectChunks($reachable);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function inspect(callable $callback): mixed
    {
        $lockPath = $this->directory . '/state.lock';
        if (is_link($lockPath)) { throw new RuntimeException('RESPAWN_STATE_LOCK_UNSAFE'); }
        $statePath = $this->directory . '/state.json';
        if (!file_exists($lockPath)) {
            if (file_exists($statePath) || is_link($statePath)) { throw new RuntimeException('Respawn state lock is missing.'); }
            return $callback(['publications'=>[], 'operations'=>[], 'idempotency'=>[]]);
        }
        $lock = @fopen($lockPath, 'rb');
        if ($lock === false || !flock($lock, LOCK_SH)) { throw new RuntimeException('RESPAWN_STATE_LOCK_UNAVAILABLE'); }
        try { return $callback($this->readFile($statePath)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function exclusive(string $scope, callable $callback): mixed
    {
        $path = $this->directory . '/build-' . hash('sha256', $scope) . '.lock';
        if (is_link($path)) { throw new RuntimeException('RESPAWN_STATE_TARGET_LOCK_UNSAFE'); }
        $lock = @fopen($path, 'c');
        if ($lock === false) { throw new RuntimeException('RESPAWN_STATE_TARGET_LOCK_UNAVAILABLE'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('TARGET_BUILD_BUSY'); }
            $this->setManagedPermissions($path, 0600);
            return $callback();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function child(string $scope): self
    {
        return new self($this->directory . '/children/' . hash('sha256', $scope));
    }

    public static function repairOwnership(string $directory, string $expectedParent): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('RESPAWN_STORAGE_REPAIR_REQUIRES_ROOT');
        }
        $expectedParent = rtrim($expectedParent, '/');
        $directory = rtrim($directory, '/');
        $parent = dirname($directory);
        if ($directory !== $expectedParent . '/respawn' || !str_starts_with($directory, '/')
            || is_link($directory) || is_link($parent)
            || realpath($parent) !== $parent || realpath($directory) !== $directory || !is_dir($directory)) {
            throw new RuntimeException('RESPAWN_STORAGE_REPAIR_PATH_INVALID');
        }
        $uid = fileowner($parent); $gid = filegroup($parent);
        if (!is_int($uid) || !is_int($gid) || $uid === 0) { throw new RuntimeException('RESPAWN_STORAGE_SERVICE_OWNER_INVALID'); }

        $stateLockPath=$directory.'/state.lock';
        if (is_link($stateLockPath) || !is_file($stateLockPath)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_LOCK_MISSING'); }
        $stateLock=@fopen($stateLockPath,'r+');
        if ($stateLock===false || !flock($stateLock,LOCK_EX)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_LOCK_UNAVAILABLE'); }
        $diagnosticLockPath=$directory.'/build-'.hash('sha256','protection-diagnostics').'.lock';
        $diagnosticLock=null;
        try {
            if (is_link($diagnosticLockPath)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_LOCK_INVALID'); }
            if (is_file($diagnosticLockPath)) {
                $diagnosticLock=@fopen($diagnosticLockPath,'r+');
                if ($diagnosticLock===false || !flock($diagnosticLock,LOCK_EX)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_LOCK_UNAVAILABLE'); }
            } elseif (is_file($directory.'/protection.log')) {
                throw new RuntimeException('RESPAWN_STORAGE_REPAIR_LOCK_MISSING');
            }

            $entries = [[$directory, true]];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,
                \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $path = $entry->getPathname();
                if ($entry->isLink()) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_SYMLINK_REJECTED'); }
                $relative = substr($path, strlen($directory) + 1);
                $isDirectory = $entry->isDir();
                if (!self::isManagedRelativePath($relative, $isDirectory)) {
                    throw new RuntimeException('RESPAWN_STORAGE_REPAIR_UNEXPECTED_PATH');
                }
                $stat = @lstat($path);
                if (!is_array($stat) || (!$isDirectory && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1))) {
                    throw new RuntimeException('RESPAWN_STORAGE_REPAIR_FILE_TYPE_REJECTED');
                }
                $entries[] = [$path, $isDirectory];
            }
            usort($entries, static fn (array $left, array $right): int => strlen($left[0]) <=> strlen($right[0]));
            $changed = 0;
            foreach ($entries as [$path, $isDirectory]) {
                $mode = $isDirectory ? 0700 : 0600;
                if (fileowner($path) !== $uid && !@chown($path, $uid)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_CHOWN_FAILED'); }
                if (filegroup($path) !== $gid && !@chgrp($path, $gid)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_CHGRP_FAILED'); }
                if ((fileperms($path) & 0777) !== $mode && !@chmod($path, $mode)) { throw new RuntimeException('RESPAWN_STORAGE_REPAIR_CHMOD_FAILED'); }
                $changed++;
            }
            return ['state'=>'storage_ownership_repaired', 'managedEntries'=>$changed,
                'serviceUid'=>$uid, 'serviceGid'=>$gid, 'path'=>$directory];
        } finally {
            if (is_resource($diagnosticLock)) { flock($diagnosticLock,LOCK_UN); fclose($diagnosticLock); }
            flock($stateLock,LOCK_UN); fclose($stateLock);
        }
    }

    public function protectionEvent(array $status): void
    {
        $this->exclusive('protection-diagnostics', function () use ($status): void {
            $states = ['not_configured','initial_capture_pending','configuration_export_failed','publication_failed',
                'changes_awaiting_publication','external_retention_unverified','credentials_unavailable',
                'protection_stale','protected_and_current','reconciliation_failed'];
            $event = ['at'=>gmdate(DATE_ATOM), 'state'=>in_array($status['state'] ?? '', $states, true)
                ? $status['state'] : 'reconciliation_failed', 'pending'=>max(0, (int) ($status['pendingRevisions'] ?? 0))];
            if (in_array($status['failureStage'] ?? '', ['inspection','revision_storage','publication','retention'], true)) {
                $event['stage'] = $status['failureStage'];
            }
            if (!empty($status['lastRecoveryAt']) && $status['lastRecoveryAt'] === ($status['lastInvocationCompletedAt'] ?? null)) { $event['recovered'] = true; }
            $error = is_string($status['lastError'] ?? null) ? $status['lastError'] : '';
            $event['reason'] = match (true) {
                ($event['stage'] ?? '') === 'revision_storage' => 'revision_storage_failed',
                str_contains($error, 'CREDENTIAL'), str_contains($error, 'SECRET') => 'credentials_unavailable',
                str_contains($error, 'ACK') => 'acknowledgement_invalid',
                ($event['stage'] ?? '') === 'inspection' => 'inspection_or_blueprint_failed',
                ($event['stage'] ?? '') === 'retention' => 'retention_verification_failed',
                $event['state'] === 'publication_failed' => 'publication_failed',
                default => $event['state'],
            };
            $path = $this->directory . '/protection.log';
            foreach ([$path, $path . '.1', $path . '.2', $path . '.3'] as $file) {
                if (is_link($file)) { throw new RuntimeException('PROTECTION_LOG_UNSAFE'); }
            }
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) >= 262144) {
                if (is_file($path . '.3') && !unlink($path . '.3')) { throw new RuntimeException('PROTECTION_LOG_ROTATION_FAILED'); }
                for ($index = 2; $index >= 0; $index--) {
                    $from = $path . ($index === 0 ? '' : '.' . $index);
                    if (is_file($from)) { $this->setManagedPermissions($from, 0600); }
                    if (is_file($from) && !rename($from, $path . '.' . ($index + 1))) { throw new RuntimeException('PROTECTION_LOG_ROTATION_FAILED'); }
                }
            }
            $handle = @fopen($path, 'ab');
            if ($handle === false) { throw new RuntimeException('PROTECTION_LOG_FAILED'); }
            try {
                $line = json_encode($event, JSON_THROW_ON_ERROR) . "\n";
                $this->setManagedPermissions($path, 0600);
                if (fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('PROTECTION_LOG_FAILED');
                }
            } finally { fclose($handle); }
        });
    }

    private function readFile(string $path): array
    {
        if (is_link($path)) { throw new RuntimeException('RESPAWN_STATE_FILE_UNSAFE'); }
        if (!file_exists($path)) {
            return ['publications' => [], 'operations' => [], 'idempotency' => []];
        }
        $size = @filesize($path);
        if ($size === false) { throw new RuntimeException('RESPAWN_STATE_FILE_UNREADABLE'); }
        if ($size > 1048576) {
            throw new RuntimeException('RESPAWN_STATE_FILE_EXCEEDS_LIMIT');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) { throw new RuntimeException('RESPAWN_STATE_FILE_UNREADABLE'); }
        try {
            $state = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('RESPAWN_STATE_CORRUPT', 0, $exception);
        }
        if (is_array($state) && isset($state['__respawnStorage'])) {
            if ($state['__respawnStorage'] !== 'chunks.v1') { throw new RuntimeException('STATE_STORAGE_FORMAT_UNSUPPORTED'); }
            unset($state['__respawnStorage']);
            $state = $this->unpack($state);
        }
        if (!is_array($state) || !isset($state['publications'], $state['operations'], $state['idempotency'])
            || !is_array($state['publications']) || !is_array($state['operations']) || !is_array($state['idempotency'])) {
            throw new RuntimeException('RESPAWN_STATE_FORMAT_UNSUPPORTED');
        }
        return $state;
    }

    private function pack(mixed $value, array &$reachable, bool $force = false): mixed
    {
        if (!is_array($value)) {
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 1048576) { throw new RuntimeException('STATE_VALUE_CAPACITY_EXCEEDED'); }
            return $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$force && strlen($encoded) <= 32768) { return $value; }
        $packed = [];
        foreach ($value as $key => $entry) { $packed[$key] = $this->pack($entry, $reachable); }
        $encoded = json_encode($packed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 524288) {
            foreach ($value as $key => $entry) {
                if (is_array($entry)) { $packed[$key] = $this->pack($entry, $reachable, true); }
            }
            $encoded = json_encode($packed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (strlen($encoded) > 1048576) { throw new RuntimeException('STATE_CHUNK_CAPACITY_EXCEEDED'); }
        $hash = hash('sha256', $encoded);
        $reachable[$hash] = true;
        $directory = $this->directory . '/chunks';
        if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new RuntimeException('STATE_CHUNK_DIRECTORY_FAILED'); }
        $this->alignManagedOwner($directory);
        $this->setManagedPermissions($directory, 0700);
        if (is_link($directory) || (fileperms($directory) & 0077) !== 0) { throw new RuntimeException('STATE_CHUNK_DIRECTORY_UNSAFE'); }
        $path = $directory . '/' . $hash . '.json';
        if (file_exists($path)) {
            if (is_link($path) || (fileperms($path) & 0077) !== 0 || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('STATE_CHUNK_INTEGRITY_FAILED');
            }
            $this->alignManagedOwner($path);
            return ['$respawnChunk'=>$hash];
        }
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) { throw new RuntimeException('STATE_CHUNK_WRITE_FAILED'); }
        try {
            $this->setManagedPermissions($temporary, 0600);
            $stream = @fopen($temporary, 'wb');
            if ($stream === false) { throw new RuntimeException('STATE_CHUNK_WRITE_FAILED'); }
            try {
                if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException('STATE_CHUNK_WRITE_FAILED');
                }
            } finally { fclose($stream); }
            $this->alignManagedOwner($temporary);
            if (!rename($temporary, $path)) { throw new RuntimeException('STATE_CHUNK_WRITE_FAILED'); }
            $handle = fopen($directory, 'r');
            if ($handle === false) { throw new RuntimeException('STATE_CHUNK_SYNC_FAILED'); }
            try { if (!fsync($handle)) { throw new RuntimeException('STATE_CHUNK_SYNC_FAILED'); } }
            finally { fclose($handle); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
        return ['$respawnChunk'=>$hash];
    }

    private function unpack(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) { throw new RuntimeException('STATE_CHUNK_DEPTH_EXCEEDED'); }
        if (!is_array($value)) { return $value; }
        if (count($value) === 1 && isset($value['$respawnChunk'])) {
            $hash = $value['$respawnChunk'];
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new RuntimeException('STATE_CHUNK_REFERENCE_INVALID'); }
            $path = $this->directory . '/chunks/' . $hash . '.json';
            if (is_link(dirname($path)) || !is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0
                || filesize($path) > 1048576) { throw new RuntimeException('STATE_CHUNK_UNAVAILABLE'); }
            $contents = @file_get_contents($path);
            if ($contents === false) { throw new RuntimeException('STATE_CHUNK_UNAVAILABLE'); }
            if (!hash_equals($hash, hash('sha256', $contents))) { throw new RuntimeException('STATE_CHUNK_INTEGRITY_FAILED'); }
            $value = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            return $this->unpack($value, $depth + 1);
        }
        foreach ($value as $key => $entry) { $value[$key] = $this->unpack($entry, $depth + 1); }
        return $value;
    }

    private function collectChunks(array $reachable): void
    {
        $directory = $this->directory . '/chunks';
        if (!is_dir($directory) || is_link($directory)) { return; }
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $hash = basename($path, '.json');
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) && !isset($reachable[$hash]) && !is_link($path)) { unlink($path); }
        }
        foreach (array_merge(glob($directory . '/.pending-*') ?: [], glob($this->directory . '/.respawn-*') ?: []) as $path) {
            if (is_file($path) && !is_link($path)) { unlink($path); }
        }
    }

    private static function createDirectoryTree(string $directory): void
    {
        self::assertNoSymlinkPath($directory);
        $parent = dirname($directory);
        if (!is_dir($parent)) { self::createDirectoryTree($parent); }
        $parentUid = fileowner($parent); $parentGid = filegroup($parent);
        if (!@mkdir($directory, 0700) && !is_dir($directory)) { throw new RuntimeException('RESPAWN_STATE_DIRECTORY_CREATE_FAILED'); }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $parentUid !== 0) {
            if (!@chown($directory, $parentUid) || !@chgrp($directory, $parentGid)) {
                throw new RuntimeException('RESPAWN_STATE_DIRECTORY_OWNER_FAILED');
            }
        }
        if (!@chmod($directory, 0700)) { throw new RuntimeException('RESPAWN_STATE_DIRECTORY_MODE_FAILED'); }
    }

    private static function assertNoSymlinkPath(string $path): void
    {
        if (!str_starts_with($path, '/')) { throw new RuntimeException('RESPAWN_STATE_PATH_INVALID'); }
        if (in_array('..', explode('/', $path), true)) { throw new RuntimeException('RESPAWN_STATE_PATH_INVALID'); }
        for ($current=rtrim($path,'/'); $current!=='' && $current!=='/'; $current=dirname($current)) {
            if (is_link($current)) { throw new RuntimeException('RESPAWN_STATE_PATH_SYMLINK_REJECTED'); }
        }
    }

    private function alignManagedOwner(string $path): void
    {
        if (fileowner($path) === $this->ownerUid && filegroup($path) === $this->ownerGid) { return; }
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
            || !@chown($path, $this->ownerUid) || !@chgrp($path, $this->ownerGid)) {
            throw new RuntimeException('RESPAWN_STATE_OWNER_MISMATCH');
        }
    }

    private function setManagedPermissions(string $path, int $mode): void
    {
        if (is_link($path) || !@chmod($path, $mode)) { throw new RuntimeException('RESPAWN_STATE_MODE_UPDATE_FAILED'); }
        $this->alignManagedOwner($path);
    }

    private static function isManagedRelativePath(string $relative, bool $directory): bool
    {
        $parts = explode('/', $relative);
        $index = 0;
        if (($parts[0] ?? '') === 'revision-cache') { $index = 1; }
        while ($index < count($parts)) {
            if ($parts[$index] === 'chunks') {
                $remaining = count($parts) - $index;
                if ($remaining === 1) { return $directory; }
                if ($remaining !== 2 || $directory) { return false; }
                return preg_match('/^(?:[a-f0-9]{64}\.json|\.pending-[A-Za-z0-9]{6})$/D', $parts[$index + 1]) === 1;
            }
            if ($parts[$index] === 'children') {
                if (!isset($parts[$index + 1])) { return $directory; }
                if (!isset($parts[$index + 1]) || !preg_match('/^[a-f0-9]{64}$/D', $parts[$index + 1])) { return false; }
                $index += 2;
                if ($index === count($parts)) { return $directory; }
                continue;
            }
            if ($index !== count($parts) - 1) { return false; }
            if ($directory) { return false; }
            return in_array($parts[$index], ['state.json','state.lock'], true)
                || preg_match('/^build-[a-f0-9]{64}\.lock$/D', $parts[$index]) === 1
                || preg_match('/^protection\.log(?:\.[1-3])?$/D', $parts[$index]) === 1
                || preg_match('/^\.respawn-[A-Za-z0-9]{6}$/D', $parts[$index]) === 1;
        }
        return $directory;
    }
}