<?php
declare(strict_types=1);

namespace Respawn\Firewall;

use RuntimeException;

final class UnavailableFirewallSource implements FirewallSource
{
    public function trustedIpv4Sources(): array
    {
        throw new RuntimeException('FreePBX Firewall adapter is not verified in this environment; no state was read.');
    }

    public function availability(): array
    {
        return [
            'state' => 'unverified',
            'reason' => 'No compatible read-only FreePBX Firewall API was available for validation.',
        ];
    }
}