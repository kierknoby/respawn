<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class ConfigurationCapture
{
    public static function create(string $source, array $modules, array $collections, array $credentials, array $gaps): array
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $source)
            || array_diff(array_keys($credentials), ['state','references','recoveryReady','reasonCode'])
            || !in_array($credentials['state'] ?? '', ['not_required','not_configured','verified_locally','incomplete'], true)
            || ($credentials['recoveryReady'] ?? null) !== false || !is_array($credentials['references'] ?? null)
            || !array_is_list($credentials['references']) || !is_array($gaps) || !array_is_list($gaps)
            || array_filter($gaps, static fn ($gap): bool => !is_string($gap)
                || !preg_match('/^[a-zA-Z0-9_:.\-]{3,192}$/D', $gap))
            || array_filter($credentials['references'], static fn ($reference): bool => !is_string($reference)
                || !preg_match('/^secret:' . preg_quote($source, '/') . ':[0-9]{2,8}:[a-zA-Z0-9._-]{1,64}$/D', $reference))
            || (isset($credentials['reasonCode']) && (!is_string($credentials['reasonCode'])
                || !preg_match('/^[A-Z0-9_]{3,64}$/D', $credentials['reasonCode'])))) {
            throw new RuntimeException('PUDDLE_CAPTURE_METADATA_INVALID');
        }
        foreach ($modules as $name => $module) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $name) || !is_array($module)
                || !is_bool($module['available'] ?? null)
                || array_diff(array_keys($module), ['available','version','discoveryState'])
                || (($module['version'] ?? null) !== null && !is_string($module['version']))
                || !in_array($module['discoveryState'] ?? 'known', ['known','unknown'], true)) {
                throw new RuntimeException('PUDDLE_MODULE_METADATA_INVALID');
            }
        }
        foreach ($collections as $name => $collection) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $name) || !is_array($collection)
                || !in_array($collection['state'] ?? '', ['captured','incomplete','unavailable'], true)
                || array_diff(array_keys($collection), ['state','reasonCode','modules','objects'])
                || !is_array($collection['objects'] ?? null) || !array_is_list($collection['objects'])
                || (isset($collection['reasonCode']) && (!is_string($collection['reasonCode'])
                    || !preg_match('/^[A-Z0-9_]{3,64}$/D', $collection['reasonCode'])))
                || (isset($collection['modules']) && (!is_array($collection['modules']) || !array_is_list($collection['modules'])
                    || array_filter($collection['modules'], static fn ($module): bool => !is_string($module)
                        || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $module))))) {
                throw new RuntimeException('PUDDLE_COLLECTION_INVALID');
            }
            foreach ($collection['objects'] as $object) {
                if (!is_array($object) || !is_string($object['id'] ?? null)
                    || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $object['id'])
                    || !is_string($object['type'] ?? null) || !is_array($object['fields'] ?? null)
                    || !in_array($object['state'] ?? '', ['captured','unsupported'], true)
                    || array_diff(array_keys($object), ['id','type','state','fields'])) {
                    throw new RuntimeException('PUDDLE_OBJECT_INVALID');
                }
                self::rejectSecrets($object['fields']);
            }
        }
        sort($credentials['references'], SORT_STRING);
        sort($gaps, SORT_STRING);
        return self::seal(['schema'=>'respawn.configuration-capture.v1','sourcePbxId'=>$source,
            'modules'=>$modules,'collections'=>$collections,'credentials'=>$credentials,'blockingGaps'=>$gaps]);
    }

    public static function verifyHash(array $capture): void
    {
        $expected = $capture['contentHash'] ?? '';
        unset($capture['contentHash']);
        if (array_diff(array_keys($capture), ['schema','sourcePbxId','modules','collections','credentials','blockingGaps'])) {
            throw new RuntimeException('PUDDLE_CAPTURE_FIELDS_INVALID');
        }
        $rebuilt = self::create($capture['sourcePbxId'] ?? '', $capture['modules'] ?? [],
            $capture['collections'] ?? [], $capture['credentials'] ?? [], $capture['blockingGaps'] ?? []);
        if (($capture['schema'] ?? '') !== 'respawn.configuration-capture.v1' || !is_string($expected)
            || !hash_equals($rebuilt['contentHash'], $expected)) { throw new RuntimeException('PUDDLE_CAPTURE_HASH_MISMATCH'); }
    }

    public static function canonicalBytes(array $capture): string
    {
        unset($capture['contentHash']);
        return json_encode(self::canonical($capture), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function seal(array $capture): array
    {
        $capture = self::canonical($capture);
        $capture['contentHash'] = hash('sha256', self::canonicalBytes($capture));
        return $capture;
    }

    private static function rejectSecrets(array $fields): void
    {
        foreach ($fields as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['secret','password','authpassword','token','credentialvalue'], true)) {
                throw new RuntimeException('PUDDLE_SECRET_MATERIAL_PROHIBITED');
            }
            if (is_array($value)) { self::rejectSecrets($value); }
        }
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$entry) {
            if (is_array($entry)) { $entry = self::canonical($entry); }
            elseif (!(is_string($entry) || is_bool($entry) || $entry === null
                || (is_int($entry) && abs($entry) <= 9007199254740991))) {
                throw new RuntimeException('PUDDLE_CAPTURE_VALUE_UNSUPPORTED');
            }
        }
        return $value;
    }
}