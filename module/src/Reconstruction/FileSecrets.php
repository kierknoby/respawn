<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class FileSecrets implements SecretResolver
{
    public function __construct(private string $manifest) {}

    public function reference(string $sourcePbxId, string $number): string
    {
        if ($this->manifest === '' || !file_exists($this->manifest)) { throw new RuntimeException('SECRET_MANIFEST_MISSING'); }
        try { $map = json_decode($this->readProtected($this->manifest, 65536, 'SECRET_MANIFEST_MISSING', 'SECRET_MANIFEST_UNSAFE'), true, 12, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new RuntimeException('SECRET_MANIFEST_INVALID', 0, $exception); }
        if (($map['sourcePbxId'] ?? '') !== $sourcePbxId || !is_array($map['references'] ?? null)) { throw new RuntimeException('SECRET_SCOPE_MISMATCH'); }
        $prefix = 'secret:' . $sourcePbxId . ':' . $number . ':';
        if (array_key_exists('activeReferences', $map)) {
            if (!is_array($map['activeReferences'])) { throw new RuntimeException('SECRET_MANIFEST_INVALID'); }
            if (array_key_exists($number, $map['activeReferences'])) {
                $active = $map['activeReferences'][$number];
                if (!is_string($active) || !str_starts_with($active, $prefix) || !isset($map['references'][$active])) {
                    throw new RuntimeException('SECRET_REVISION_UNRESOLVED');
                }
                return $active;
            }
        }
        $matches = array_values(array_filter(array_keys($map['references']), static fn (string $key): bool => str_starts_with($key, $prefix)));
        if (count($matches) !== 1) { throw new RuntimeException('SECRET_REVISION_UNRESOLVED'); }
        return $matches[0];
    }

    public function resolve(string $reference, string $sourcePbxId): string
    {
        if (!str_starts_with($reference, 'secret:' . $sourcePbxId . ':')) {
            throw new RuntimeException('SECRET_SCOPE_MISMATCH');
        }
        if ($this->manifest === '' || !file_exists($this->manifest)) { throw new RuntimeException('SECRET_MANIFEST_MISSING'); }
        try { $map = json_decode($this->readProtected($this->manifest, 65536, 'SECRET_MANIFEST_MISSING', 'SECRET_MANIFEST_UNSAFE'), true, 12, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new RuntimeException('SECRET_MANIFEST_INVALID', 0, $exception); }
        if (($map['sourcePbxId'] ?? '') !== $sourcePbxId || !isset($map['references'][$reference])
            || !is_string($map['references'][$reference])) { throw new RuntimeException('SECRET_UNRESOLVED'); }
        $secret = rtrim($this->readProtected($map['references'][$reference], 256, 'SECRET_REFERENCE_UNRESOLVED', 'SECRET_REFERENCE_FILE_UNSAFE'), "\r\n");
        if (strlen($secret) < 16 || strlen($secret) > 128 || !preg_match('/^[a-zA-Z0-9._~!@#$%^&*+=:-]+$/D', $secret)) {
            throw new RuntimeException('SECRET_FORMAT_UNSUPPORTED');
        }
        return $secret;
    }

    private function readProtected(string $path, int $limit, string $missingCode, string $unsafeCode): string
    {
        if (!file_exists($path)) { throw new RuntimeException($missingCode); }
        $parent = dirname($path);
        $realParent = realpath($parent);
        if (!str_starts_with($path, '/') || str_contains($path, '..') || !is_file($path) || is_link($path)
            || $realParent === false || $realParent !== $parent || (fileperms($path) & 0077) !== 0 || filesize($path) > $limit) {
            if ((fileperms($path) & 0077) !== 0 || is_link($path) || !is_file($path)) { throw new RuntimeException($unsafeCode); }
            throw new RuntimeException($unsafeCode);
        }
        for ($directory = $realParent; $directory !== '/'; $directory = dirname($directory)) {
            $permissions = fileperms($directory);
            $owner = fileowner($directory);
            if ($permissions === false || $owner === false
                || ((($permissions & 0022) !== 0) && ((($permissions & 01000) === 0) || $owner !== 0))) {
                throw new RuntimeException($unsafeCode);
            }
        }
        $contents = file_get_contents($path);
        if ($contents === false) { throw new RuntimeException('SECRET_FILE_UNREADABLE'); }
        return $contents;
    }
}