<?php
declare(strict_types=1);

namespace Respawn\Firewall;

interface FirewallSource
{
    /** @return list<string> */
    public function trustedIpv4Sources(): array;

    public function availability(): array;
}