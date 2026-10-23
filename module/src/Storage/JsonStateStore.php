<?php
declare(strict_types=1);

namespace Respawn\Storage;

use RuntimeException;

final class JsonStateStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the Respawn state directory.');
        }
        if (is_link($directory) || !is_writable($directory) || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Respawn state directory is unsafe or not writable.');
        }
    }

    public function update(callable $callback): mixed
    {
        $lockPath = $this->directory . '/state.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Unsafe Respawn lock file.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock Respawn state.');
        }
        chmod($lockPath, 0600);

        try {
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
                chmod($temporary, 0600);
                $stream = fopen($temporary, 'wb');
                if ($stream === false) { throw new RuntimeException('Could not open temporary state.'); }
                try {
                    if (fwrite($stream, $encoded . "\n") !== strlen($encoded) + 1 || !fflush($stream) || !fsync($stream)) {
                        throw new RuntimeException('Could not flush durable Respawn checkpoint.');
                    }
                } finally { fclose($stream); }
                if (!rename($temporary, $path)) {
                    throw new RuntimeException('Could not atomically replace Respawn state.');
                }
                $directory = fopen($this->directory, 'r');
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
        if (is_link($lockPath)) { throw new RuntimeException('Unsafe Respawn lock file.'); }
        $statePath = $this->directory . '/state.json';
        if (!file_exists($lockPath)) {
            if (file_exists($statePath) || is_link($statePath)) { throw new RuntimeException('Respawn state lock is missing.'); }
            return $callback(['publications'=>[], 'operations'=>[], 'idempotency'=>[]]);
        }
        $lock = fopen($lockPath, 'rb');
        if ($lock === false || !flock($lock, LOCK_SH)) { throw new RuntimeException('Could not read-lock Respawn state.'); }
        try { return $callback($this->readFile($statePath)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function exclusive(string $scope, callable $callback): mixed
    {
        $path = $this->directory . '/build-' . hash('sha256', $scope) . '.lock';
        if (is_link($path)) { throw new RuntimeException('Unsafe target lock.'); }
        $lock = fopen($path, 'c');
        if ($lock === false) { throw new RuntimeException('Target lock unavailable.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('TARGET_BUILD_BUSY'); }
            chmod($path, 0600);
            return $callback();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function child(string $scope): self
    {
        return new self($this->directory . '/children/' . hash('sha256', $scope));
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
                    if (is_file($from) && !rename($from, $path . '.' . ($index + 1))) { throw new RuntimeException('PROTECTION_LOG_ROTATION_FAILED'); }
                }
            }
            $handle = fopen($path, 'ab');
            if ($handle === false) { throw new RuntimeException('PROTECTION_LOG_FAILED'); }
            try {
                $line = json_encode($event, JSON_THROW_ON_ERROR) . "\n";
                if (!chmod($path, 0600) || fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('PROTECTION_LOG_FAILED');
                }
            } finally { fclose($handle); }
        });
    }

    private function readFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['publications' => [], 'operations' => [], 'idempotency' => []];
        }
        if (is_link($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Respawn state file is unsafe or exceeds 1 MiB.');
        }
        $contents = file_get_contents($path);
        try {
            $state = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Respawn state is corrupt; it was not overwritten.', 0, $exception);
        }
        if (is_array($state) && isset($state['__respawnStorage'])) {
            if ($state['__respawnStorage'] !== 'chunks.v1') { throw new RuntimeException('STATE_STORAGE_FORMAT_UNSUPPORTED'); }
            unset($state['__respawnStorage']);
            $state = $this->unpack($state);
        }
        if (!is_array($state) || !isset($state['publications'], $state['operations'], $state['idempotency'])
            || !is_array($state['publications']) || !is_array($state['operations']) || !is_array($state['idempotency'])) {
            throw new RuntimeException('Respawn state has an unsupported format; it was not overwritten.');
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
        if (is_link($directory) || (fileperms($directory) & 0077) !== 0) { throw new RuntimeException('STATE_CHUNK_DIRECTORY_UNSAFE'); }
        $path = $directory . '/' . $hash . '.json';
        if (file_exists($path)) {
            if (is_link($path) || (fileperms($path) & 0077) !== 0 || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('STATE_CHUNK_INTEGRITY_FAILED');
            }
            return ['$respawnChunk'=>$hash];
        }
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) { throw new RuntimeException('STATE_CHUNK_WRITE_FAILED'); }
        try {
            chmod($temporary, 0600);
            $stream = fopen($temporary, 'wb');
            if ($stream === false) { throw new RuntimeException('STATE_CHUNK_WRITE_FAILED'); }
            try {
                if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException('STATE_CHUNK_WRITE_FAILED');
                }
            } finally { fclose($stream); }
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
            $contents = file_get_contents($path);
            if ($contents === false || !hash_equals($hash, hash('sha256', $contents))) { throw new RuntimeException('STATE_CHUNK_INTEGRITY_FAILED'); }
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
}