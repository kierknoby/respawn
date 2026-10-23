<?php
declare(strict_types=1);
namespace Respawn\Provider;

use RuntimeException;

final class Configuration
{
    public static function validate(array $configuration): array
    {
        if (array_diff(array_keys($configuration), ['schema', 'adapterId', 'endpoint', 'credentialRef', 'sourcePbxId', 'allowedHosts'])
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $configuration['adapterId'] ?? '')) {
            throw new RuntimeException('Unknown provider configuration fields; embedded credentials are prohibited.');
        }
        $url = $configuration['endpoint'] ?? '';
        $parsed = is_string($url) ? parse_url($url) : false;
        $allowed = $configuration['allowedHosts'] ?? [];
        if (($configuration['schema'] ?? '') !== 'respawn.provider-config.v1' || !$parsed
            || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment']) || isset($parsed['query'])
            || !is_array($allowed) || !in_array(strtolower($parsed['host']), array_map('strtolower', $allowed), true)
            || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 2048) {
            throw new RuntimeException('Provider endpoint must be explicit allowlisted HTTPS without credentials, query or fragment.');
        }
        $reference = $configuration['credentialRef'] ?? '';
        if (!is_string($reference) || !str_starts_with($reference, '/') || str_contains($reference, '..')
            || !preg_match('#^/[a-zA-Z0-9/_\-.]{1,255}$#D', $reference)) {
            throw new RuntimeException('Credential reference must be an absolute host-protected file path.');
        }
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $configuration['sourcePbxId'] ?? '')) {
            throw new RuntimeException('Invalid configured PBX identity.');
        }
        return $configuration;
    }
}