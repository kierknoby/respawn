<?php
declare(strict_types=1);

namespace Respawn\Domain;

use InvalidArgumentException;

final class FirewallIntent
{
    /** @param list<string> $sources */
    public static function create(string $pbxId, bool $enabled, array $sources, string $generatedAt): array
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $pbxId)) {
            throw new InvalidArgumentException('PBX identity has an invalid format.');
        }

        $canonicalSources = self::canonicalSources($sources);
        $intent = [
            'enabled' => $enabled,
            'sources' => $canonicalSources,
        ];

        return [
            'schema' => 'respawn.firewall-intent.v1',
            'pbxId' => $pbxId,
            'generatedAt' => $generatedAt,
            'intent' => $intent,
            'contentHash' => hash('sha256', self::canonicalJson($intent)),
        ];
    }

    /** @param list<string> $sources
     *  @return list<string>
     */
    public static function canonicalSources(array $sources): array
    {
        $normalised = [];
        foreach ($sources as $source) {
            if (!is_string($source)) {
                throw new InvalidArgumentException('Trusted sources must be strings.');
            }
            $host = str_ends_with($source, '/32') ? substr($source, 0, -3) : $source;
            $packed = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($packed === false || $host !== trim($host)) {
                throw new InvalidArgumentException('Only IPv4 /32 trusted-source entries are supported.');
            }
            $normalised[] = $host . '/32';
        }

        $normalised = array_values(array_unique($normalised));
        sort($normalised, SORT_STRING);
        return $normalised;
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}