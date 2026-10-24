<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use Throwable;

final class PuddleCollector
{
    public function __construct(private readonly string $sourcePbxId) {}

    public function capture(array $modules, array $providers, callable $credentialStatus): array
    {
        $collections = [];
        $gaps = [];
        foreach ($providers as $name => $provider) {
            $requiredModules = $provider['modules'] ?? (isset($provider['module']) ? [$provider['module']] : null);
            if (!is_string($name) || !is_array($provider) || !is_array($requiredModules) || !$requiredModules
                || !array_is_list($requiredModules) || !is_callable($provider['collect'] ?? null)
                || array_filter($requiredModules, static fn ($module): bool => !is_string($module)
                    || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $module))) {
                throw new \RuntimeException('PUDDLE_PROVIDER_REGISTRY_INVALID');
            }
            $unavailable = array_values(array_filter($requiredModules,
                static fn (string $module): bool => empty($modules[$module]['available'])));
            if ($unavailable) {
                $collections[$name] = ['state'=>'unavailable','reasonCode'=>'MODULE_UNAVAILABLE',
                    'modules'=>$unavailable,'objects'=>[]];
                foreach ($unavailable as $module) { $gaps[] = $name . ':MODULE_UNAVAILABLE:' . $module; }
                continue;
            }
            try {
                $objects = ($provider['collect'])();
                if (!is_array($objects) || !array_is_list($objects)) { throw new \RuntimeException('PUDDLE_COLLECTION_RESULT_INVALID'); }
                $collections[$name] = ['state'=>'captured','objects'=>$objects];
                foreach ($objects as $object) {
                    if (($object['state'] ?? '') !== 'captured') {
                        $reason = $object['fields']['reasonCode'] ?? 'OBJECT_CONFIGURATION_UNSUPPORTED';
                        $gaps[] = $name . ':OBJECT_UNSUPPORTED:' . ($object['id'] ?? 'unknown') . ':' . $reason;
                    }
                }
            } catch (Throwable $exception) {
                $code = preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage())
                    ? $exception->getMessage() : 'PUDDLE_COLLECTION_PROVIDER_FAILED';
                $collections[$name] = ['state'=>'incomplete','reasonCode'=>$code,'objects'=>[]];
                $gaps[] = $name . ':' . $code;
            }
        }
        foreach ($modules as $name => $module) {
            if (empty($module['available'])) { $gaps[] = 'MODULE_UNAVAILABLE:' . $name; }
        }
        try { $credentials = $credentialStatus(); }
        catch (Throwable $exception) {
            $credentials = ['state'=>'incomplete','references'=>[],'recoveryReady'=>false,
                'reasonCode'=>preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage())
                    ? $exception->getMessage() : 'CREDENTIAL_PROTECTION_UNAVAILABLE'];
        }
        if (($credentials['state'] ?? '') !== 'verified_locally' && ($credentials['state'] ?? '') !== 'not_required') {
            $gaps[] = 'CREDENTIALS:' . ($credentials['reasonCode'] ?? $credentials['state'] ?? 'INCOMPLETE');
        }
        $gaps = array_values(array_unique($gaps));
        sort($gaps, SORT_STRING);
        return ConfigurationCapture::create($this->sourcePbxId, $modules, $collections, $credentials, $gaps);
    }
}