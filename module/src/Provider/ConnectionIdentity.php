<?php
declare(strict_types=1);
namespace Respawn\Provider;

use RuntimeException;

final class ConnectionIdentity
{
    public static function forConfiguration(array $configuration, string $mode = 'provider'): string
    {
        if ($mode === 'mock') {
            return 'mock-v1:' . hash('sha256', json_encode(['mode' => 'mock', 'profile' => 'synthetic-v2'], JSON_THROW_ON_ERROR));
        }
        if ($mode === 'disabled') { return 'disabled-v1:' . hash('sha256', 'respawn-provider-execution-disabled-v1'); }
        if ($mode !== 'provider') { throw new RuntimeException('Unsupported provider connection mode.'); }
        Configuration::validate($configuration);
        $url = parse_url($configuration['endpoint']);
        if (!is_array($url)) { throw new RuntimeException('Invalid provider endpoint.'); }
        $host = strtolower($url['host']);
        $port = $url['port'] ?? 443;
        $path = rtrim($url['path'] ?? '/', '/') ?: '/';
        $allowedHosts = array_values(array_unique(array_map('strtolower', $configuration['allowedHosts'])));
        sort($allowedHosts, SORT_STRING);
        $publicContext = ['mode' => 'provider', 'adapterId' => $configuration['adapterId'],
            'scheme' => 'https', 'host' => $host, 'port' => $port, 'path' => $path,
            'allowedHosts' => $allowedHosts, 'tlsProfile' => 'verified-public-ipv4-no-redirect-v1'];
        return 'https-v1:' . hash('sha256', json_encode($publicContext, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}