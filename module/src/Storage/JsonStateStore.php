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

        try {
            $path = $this->directory . '/state.json';
            $state = $this->read($path);
            $result = $callback($state);
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
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
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

    private function read(string $path): array
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
        if (!is_array($state) || !isset($state['publications'], $state['operations'], $state['idempotency'])
            || !is_array($state['publications']) || !is_array($state['operations']) || !is_array($state['idempotency'])) {
            throw new RuntimeException('Respawn state has an unsupported format; it was not overwritten.');
        }
        return $state;
    }
}