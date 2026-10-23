<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use RuntimeException;

final class FreePbxRinggroups implements Target
{
    public function __construct(private object $ringgroups, private string $targetId, private \Closure $memberExists)
    {
    }

    public function identity(): string { return $this->targetId; }

    public function export(string $pbxId, array $numbers): array
    {
        $groups = [];
        foreach ($numbers as $number) {
            if (!is_string($number) || !preg_match('/^[0-9]{2,8}$/D', $number)) {
                throw new RuntimeException('Invalid ring-group number.');
            }
            $group = $this->read($number);
            if ($group === null) {
                throw new RuntimeException('Requested ring group does not exist.');
            }
            $groups[] = $group;
        }
        return Blueprint::create($pbxId, $groups);
    }

    public function read(string $number): ?array
    {
        $raw = $this->ringgroups->get($number);
        if (!$raw) {
            return null;
        }
        $defaults = ['grppre' => '', 'annmsg_id' => '0', 'alertinfo' => '', 'needsconf' => '',
            'remotealert_id' => '0', 'toolate_id' => '0', 'ringing' => 'Ring', 'cwignore' => '',
            'cfignore' => '', 'cpickup' => '', 'recording' => 'dontcare', 'progress' => 'yes',
            'elsewhere' => '', 'rvolume' => '', 'changecid' => 'default', 'fixedcid' => ''];
        foreach ($defaults as $field => $expected) {
            if ((string) ($raw[$field] ?? '') !== $expected) {
                throw new RuntimeException('Unsupported non-default ring-group field: ' . $field);
            }
        }
        $destination = (string) $raw['postdest'];
        if ($destination === 'app-blackhole,hangup,1') {
            $destination = 'hangup';
        } elseif (preg_match('/^ext-group,([0-9]{2,8}),1$/D', $destination, $match)) {
            $destination = $match[1];
        } else {
            throw new RuntimeException('Unsupported no-answer destination.');
        }
        $members = $raw['grplist'] === '' ? [] : explode('-', (string) $raw['grplist']);
        return ['number' => (string) $raw['grpnum'], 'description' => (string) $raw['description'],
            'strategy' => (string) $raw['strategy'], 'timeout' => (int) $raw['grptime'],
            'members' => $members, 'destination' => $destination];
    }

    public function prerequisites(array $operations): void
    {
        if (!method_exists($this->ringgroups, 'get') || !method_exists($this->ringgroups, 'add')) {
            throw new RuntimeException('Incompatible Ringgroups interface.');
        }
        foreach ($operations as $operation) {
            if (($this->memberExists)($operation['parameters']['number'])) {
                throw new RuntimeException('Ring-group number conflicts with an existing Core extension.');
            }
            foreach ($operation['parameters']['members'] as $member) {
                if (!(($this->memberExists)($member))) {
                    throw new RuntimeException('Missing member extension prerequisite: ' . $member);
                }
            }
        }
    }

    public function create(array $parameters): void
    {
        $destination = $parameters['destination'] === 'hangup'
            ? 'app-blackhole,hangup,1' : 'ext-group,' . $parameters['destination'] . ',1';
        $created = $this->ringgroups->add($parameters['number'], 'ringall', (string) $parameters['timeout'],
            implode('-', $parameters['members']), $destination, $parameters['description'],
            '', '0', '', '', '0', '0', 'Ring', '', '', 'default', '', '', 'dontcare', 'yes', '', '', 1);
        if ($created !== true) {
            throw new RuntimeException('Ringgroups module rejected creation.');
        }
    }
}