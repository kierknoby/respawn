<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use RuntimeException;

final class Blueprint
{
    public static function create(string $source, array $groups): array
    {
        $operations = [];
        foreach ($groups as $group) {
            if (!is_array($group) || array_diff(array_keys($group), ['number', 'description', 'strategy', 'timeout', 'members', 'destination'])
                || !is_array($group['members'] ?? null)) {
                throw new RuntimeException('Unsupported or malformed Ring Groups export.');
            }
            $members = array_values(array_unique($group['members'], SORT_REGULAR));
            sort($members, SORT_STRING);
            $group = ['number' => $group['number'] ?? '', 'description' => $group['description'] ?? '',
                'strategy' => $group['strategy'] ?? '', 'timeout' => $group['timeout'] ?? null,
                'members' => $members, 'destination' => $group['destination'] ?? ''];
            $destination = $group['destination'];
            $operations[] = ['id' => 'ringgroup:' . $group['number'], 'adapter' => 'ringgroups.v1',
                'action' => 'ensure', 'dependsOn' => $destination === 'hangup' ? [] : ['ringgroup:' . $destination],
                'parameters' => $group];
        }
        usort($operations, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        $document = ['schema' => 'respawn.reconstruction.v1', 'sourcePbxId' => $source,
            'compatibility' => ['freepbx' => '17', 'ringgroups' => '17'], 'operations' => $operations,
            'coverage' => ['supported' => ['ringgroups: number, description, ringall, timeout, members, no-answer destination'],
                'gaps' => ['member devices', 'trunks', 'extensions', 'voicemail', 'recordings', 'CDRs', 'custom sounds', 'all other ring-group options']]];
        self::ordered($document);
        $document['contentHash'] = hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $document;
    }

    public static function ordered(array $document): array
    {
        if (($document['schema'] ?? '') === 'respawn.reconstruction.v2') { return BuildSpec::plan($document); }
        if (($document['schema'] ?? '') !== 'respawn.reconstruction.v1'
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $document['sourcePbxId'] ?? '')
            || ($document['compatibility'] ?? []) !== ['freepbx' => '17', 'ringgroups' => '17']
            || !is_array($document['operations'] ?? null) || count($document['operations']) < 1 || count($document['operations']) > 100) {
            throw new RuntimeException('Unsupported reconstruction blueprint or size.');
        }
        $byId = [];
        foreach ($document['operations'] as $operation) {
            $parameters = $operation['parameters'] ?? [];
            if (($operation['adapter'] ?? '') !== 'ringgroups.v1' || ($operation['action'] ?? '') !== 'ensure'
                || array_diff(array_keys($operation), ['id', 'adapter', 'action', 'dependsOn', 'parameters'])
                || array_diff(array_keys($parameters), ['number', 'description', 'strategy', 'timeout', 'members', 'destination'])
                || !preg_match('/^[0-9]{2,8}$/D', $parameters['number'] ?? '')
                || !preg_match('/^[a-zA-Z0-9 ._-]{1,64}$/D', $parameters['description'] ?? '')
                || ($parameters['strategy'] ?? '') !== 'ringall'
                || !is_int($parameters['timeout'] ?? null) || $parameters['timeout'] < 1 || $parameters['timeout'] > 120
                || !is_array($parameters['members'] ?? null) || count($parameters['members']) > 50
                || !is_array($operation['dependsOn'] ?? null)) {
                throw new RuntimeException('Invalid or unsupported reconstruction operation.');
            }
            foreach ($parameters['members'] as $member) {
                if (!is_string($member) || !preg_match('/^[0-9]{2,8}$/D', $member)) {
                    throw new RuntimeException('Only numeric internal members are supported.');
                }
            }
            $destination = $parameters['destination'] ?? '';
            $dependencies = $destination === 'hangup' ? [] : ['ringgroup:' . $destination];
            if (($destination !== 'hangup' && !preg_match('/^[0-9]{2,8}$/D', $destination))
                || $operation['dependsOn'] !== $dependencies || $operation['id'] !== 'ringgroup:' . $parameters['number']
                || isset($byId[$operation['id']])) {
                throw new RuntimeException('Invalid destination dependency or duplicate object.');
            }
            $byId[$operation['id']] = $operation;
        }
        $ordered = [];
        while ($byId) {
            $progress = false;
            foreach ($byId as $id => $operation) {
                if (!array_diff($operation['dependsOn'], array_keys($ordered))) {
                    $ordered[$id] = $operation;
                    unset($byId[$id]);
                    $progress = true;
                }
            }
            if (!$progress) {
                throw new RuntimeException('Missing or cyclic reconstruction dependency.');
            }
        }
        return array_values($ordered);
    }

    public static function verifyHash(array $document): void
    {
        $expected = $document['contentHash'] ?? '';
        if (($document['schema'] ?? '') === 'respawn.reconstruction.v2') {
            BuildSpec::plan($document);
            if (!is_string($expected) || !hash_equals(hash('sha256', BuildSpec::canonicalBytes($document)), $expected)) {
                throw new RuntimeException('BLUEPRINT_HASH_MISMATCH');
            }
            return;
        }
        unset($document['contentHash']);
        if (!is_string($expected) || !hash_equals(hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $expected)) {
            throw new RuntimeException('Blueprint hash mismatch; this hash is not a signature.');
        }
    }
}