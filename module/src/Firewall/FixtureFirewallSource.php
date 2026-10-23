<?php
declare(strict_types=1);

namespace Respawn\Firewall;

use RuntimeException;

final class FixtureFirewallSource implements FirewallSource
{
    public function __construct(private readonly string $fixturePath)
    {
    }

    public function trustedIpv4Sources(): array
    {
        if (!is_file($this->fixturePath) || is_link($this->fixturePath)) {
            throw new RuntimeException('Explicit firewall fixture is missing or unsafe.');
        }

        $contents = file_get_contents($this->fixturePath);
        if ($contents === false || strlen($contents) > 65536) {
            throw new RuntimeException('Firewall fixture cannot be read or exceeds 64 KiB.');
        }

        try {
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Firewall fixture contains invalid JSON.', 0, $exception);
        }

        if (!is_array($data) || !isset($data['trustedIpv4Sources']) || !is_array($data['trustedIpv4Sources'])) {
            throw new RuntimeException('Firewall fixture must contain trustedIpv4Sources as an array.');
        }

        return $data['trustedIpv4Sources'];
    }

    public function availability(): array
    {
        return ['state' => 'fixture', 'label' => 'DEVELOPMENT FIXTURE'];
    }
}