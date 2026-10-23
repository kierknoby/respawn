<?php
declare(strict_types=1);
namespace Respawn\Firewall;

use RuntimeException;

final class FreePbxFirewallSource implements FirewallSource
{
    public function __construct(private object $firewall) {}

    public function trustedIpv4Sources(): array
    {
        if (!method_exists($this->firewall, 'isEnabled') || !method_exists($this->firewall, 'getTrustedZone')) {
            throw new RuntimeException('Incompatible or unavailable Firewall module.');
        }
        if (!$this->firewall->isEnabled()) {
            throw new RuntimeException('Firewall disabled: refusing to infer empty or disabled remote intent.');
        }
        $raw = $this->firewall->getTrustedZone('trusted');
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new RuntimeException('Unsupported Firewall response.');
        }
        return array_values(array_filter(explode("\n", trim($raw)), static fn (string $entry): bool => $entry !== ''));
    }

    public function availability(): array
    {
        return ['state' => 'source-inspected', 'integrationTested' => false,
            'gaps' => ['trusted interfaces', 'dynamic sources', 'IPv6', 'CIDRs other than /32']];
    }
}