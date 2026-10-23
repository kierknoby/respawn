<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class BuildSpec
{
    public const ADAPTERS = ['core.user.v1' => 'user', 'core.pjsip.v1' => 'pjsip', 'ringgroups.v1' => 'ringgroup'];

    public static function create(string $source, array $modules, array $users, array $devices, array $groups): array
    {
        $operations = [];
        foreach ($users as $number => $parameters) {
            $operations[] = self::instruction('core.user.v1', (string) $number, $parameters, [], []);
        }
        foreach ($devices as $number => $device) {
            $reference = $device['secretRef'];
            unset($device['secretRef']);
            $operations[] = self::instruction('core.pjsip.v1', (string) $number, $device,
                ['user:' . $device['user']], ['authPassword' => $reference]);
        }
        foreach ($groups as $group) {
            $dependencies = array_map(static fn (string $member): string => 'pjsip:' . $member, $group['members']);
            if ($group['destination'] !== 'hangup') { $dependencies[] = 'ringgroup:' . $group['destination']; }
            $number = $group['number'];
            unset($group['number']);
            $operations[] = self::instruction('ringgroups.v1', $number, $group, $dependencies, []);
        }
        usort($operations, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        ksort($modules);
        $document = ['schema' => 'respawn.reconstruction.v2', 'sourcePbxId' => $source,
            'modules' => $modules, 'coverage' => ['selectedComplete' => true, 'blockingGaps' => [],
                'excluded' => ['trunks', 'routes', 'voicemail', 'media/history', 'all unselected configuration']],
            'operations' => $operations, 'apply' => 'freepbx.reload.v1'];
        self::plan($document);
        return self::seal($document);
    }

    public static function instruction(string $adapter, string $number, array $parameters, array $dependencies, array $secrets): array
    {
        sort($dependencies, SORT_STRING);
        return ['id' => self::ADAPTERS[$adapter] . ':' . $number, 'adapter' => $adapter, 'schemaVersion' => 1,
            'object' => ['type' => self::ADAPTERS[$adapter], 'identity' => $number], 'action' => 'ensure',
            'parameters' => $parameters, 'dependsOn' => $dependencies, 'requiredCapabilities' => [$adapter],
            'preconditions' => ['absent_or_equivalent', 'no_foreign_owner'], 'secretRefs' => $secrets,
            'postconditions' => ['saved_state_matches', 'secret_identity_preserved'], 'verification' => 'readback.v1'];
    }

    public static function seal(array $document): array
    {
        unset($document['contentHash']);
        $document = self::canonical($document);
        $document['contentHash'] = hash('sha256', self::canonicalBytes($document));
        return $document;
    }

    public static function canonicalBytes(array $document): string
    {
        unset($document['contentHash']);
        return json_encode(self::canonical($document), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function plan(array $document): array
    {
        self::keys($document, ['schema','sourcePbxId','modules','coverage','operations','apply'], ['contentHash']);
        if ($document['schema'] !== 'respawn.reconstruction.v2' || !is_string($document['sourcePbxId'])
            || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $document['sourcePbxId'])
            || $document['apply'] !== 'freepbx.reload.v1' || !is_array($document['operations'])
            || !array_is_list($document['operations']) || count($document['operations']) < 1 || count($document['operations']) > 100) {
            throw new RuntimeException('Unsupported build specification or operation limit.');
        }
        self::keys($document['modules'], ['framework','core','ringgroups','sipsettings']);
        foreach ($document['modules'] as $version) {
            if (!is_string($version) || !preg_match('/^17\.[0-9]+(?:\.[0-9]+){0,4}$/D', $version)) {
                throw new RuntimeException('Only explicit FreePBX 17 module versions are supported.');
            }
        }
        self::keys($document['coverage'], ['selectedComplete','blockingGaps','excluded']);
        if ($document['coverage']['selectedComplete'] !== true || $document['coverage']['blockingGaps'] !== []
            || !is_array($document['coverage']['excluded'])) { throw new RuntimeException('Selected blueprint coverage is incomplete.'); }
        $byId = [];
        $dialIdentities = [];
        foreach ($document['operations'] as $operation) {
            self::keys($operation, ['id','adapter','schemaVersion','object','action','parameters','dependsOn',
                'requiredCapabilities','preconditions','secretRefs','postconditions','verification']);
            $adapter = $operation['adapter'];
            if (!is_string($adapter) || !isset(self::ADAPTERS[$adapter])) { throw new RuntimeException('Unsupported build adapter.'); }
            self::keys($operation['object'], ['type','identity']);
            $number = $operation['object']['identity'];
            self::number($number);
            $type = self::ADAPTERS[$adapter];
            if ($operation['id'] !== $type . ':' . $number || $operation['object']['type'] !== $type
                || $operation['action'] !== 'ensure' || $operation['schemaVersion'] !== 1
                || $operation['requiredCapabilities'] !== [$adapter]
                || $operation['preconditions'] !== ['absent_or_equivalent','no_foreign_owner']
                || $operation['postconditions'] !== ['saved_state_matches','secret_identity_preserved']
                || $operation['verification'] !== 'readback.v1' || isset($byId[$operation['id']])) {
                throw new RuntimeException('Invalid instruction contract or duplicate identity.');
            }
            $parameters = $operation['parameters'];
            $dependencies = [];
            if ($type === 'user') {
                self::keys($parameters, ['name','voicemail']);
                self::label($parameters['name']);
                if ($parameters['voicemail'] !== 'novm') { throw new RuntimeException('Voicemail is a blocking recovery gap.'); }
            } elseif ($type === 'pjsip') {
                self::keys($parameters, ['user','description','authUsername','settings']);
                self::number($parameters['user']);
                self::label($parameters['description']);
                if ($parameters['user'] !== $number || !is_string($parameters['authUsername'])
                    || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $parameters['authUsername'])) {
                    throw new RuntimeException('Only fixed one-user/one-device identity is supported.');
                }
                self::settings($parameters['settings']);
                $dependencies = ['user:' . $number];
                self::keys($operation['secretRefs'], ['authPassword']);
                if (!is_string($operation['secretRefs']['authPassword']) || !preg_match('/^' . preg_quote('secret:' . $document['sourcePbxId'] . ':' . $number . ':', '/') . '[a-zA-Z0-9._-]{1,64}$/D', $operation['secretRefs']['authPassword'])) {
                    throw new RuntimeException('Missing or cross-PBX authentication secret reference.');
                }
            } else {
                self::keys($parameters, ['description','strategy','timeout','members','destination']);
                self::label($parameters['description']);
                if ($parameters['strategy'] !== 'ringall' || !is_int($parameters['timeout'])
                    || $parameters['timeout'] < 1 || $parameters['timeout'] > 120 || !is_array($parameters['members'])
                    || !array_is_list($parameters['members']) || !$parameters['members'] || count($parameters['members']) > 50
                    || count(array_unique($parameters['members'], SORT_REGULAR)) !== count($parameters['members'])) {
                    throw new RuntimeException('Unsupported Ring Groups parameters.');
                }
                foreach ($parameters['members'] as $member) { self::number($member); $dependencies[] = 'pjsip:' . $member; }
                if ($parameters['destination'] !== 'hangup') {
                    self::number($parameters['destination']);
                    $dependencies[] = 'ringgroup:' . $parameters['destination'];
                }
            }
            if ($type !== 'pjsip' && $operation['secretRefs'] !== []) { throw new RuntimeException('Unexpected secret references.'); }
            sort($dependencies, SORT_STRING);
            if ($operation['dependsOn'] !== $dependencies) { throw new RuntimeException('Dependencies do not match actual object references.'); }
            if ($type !== 'pjsip') {
                if (isset($dialIdentities[$number])) { throw new RuntimeException('User and Ring Group numbers collide.'); }
                $dialIdentities[$number] = true;
            }
            $byId[$operation['id']] = $operation;
        }
        ksort($byId, SORT_STRING);
        $ordered = [];
        while ($byId) {
            $progress = false;
            foreach ($byId as $id => $operation) {
                if (!array_diff($operation['dependsOn'], array_keys($ordered))) {
                    $ordered[$id] = $operation; unset($byId[$id]); $progress = true;
                }
            }
            if (!$progress) { throw new RuntimeException('Missing dependency or dependency cycle.'); }
        }
        return array_values($ordered);
    }

    public static function settings(array $settings): void
    {
        self::keys($settings, ['context','transport','dtmfmode','allow','disallow','direct_media','rtp_symmetric',
            'force_rport','rewrite_contact','max_contacts','remove_existing','media_encryption']);
        if ($settings['context'] !== 'from-internal' || $settings['transport'] !== '' || $settings['disallow'] !== 'all'
            || !in_array($settings['dtmfmode'], ['rfc4733','auto','inband'], true)
            || !is_string($settings['allow']) || !preg_match('/^(ulaw|alaw|g722)(,(ulaw|alaw|g722)){0,2}$/D', $settings['allow'])
            || !is_int($settings['max_contacts']) || $settings['max_contacts'] < 1 || $settings['max_contacts'] > 10
            || $settings['media_encryption'] !== 'no') { throw new RuntimeException('Unsupported PJSIP settings profile.'); }
        foreach (['direct_media','rtp_symmetric','force_rport','rewrite_contact','remove_existing'] as $key) {
            if (!in_array($settings[$key], ['yes','no'], true)) { throw new RuntimeException('Invalid PJSIP boolean setting.'); }
        }
        if ($settings['max_contacts'] === 1 && $settings['remove_existing'] !== 'yes') {
            throw new RuntimeException('Core forces remove_existing=yes for one contact.');
        }
    }

    public static function keys(mixed $value, array $required, array $optional = []): void
    {
        if (!is_array($value) || array_diff($required, array_keys($value))
            || array_diff(array_keys($value), array_merge($required, $optional))) { throw new RuntimeException('Unknown or missing structured fields.'); }
    }
    private static function number(mixed $number): void
    {
        if (!is_string($number) || !preg_match('/^[0-9]{2,8}$/D', $number)) { throw new RuntimeException('Invalid numeric identity or destination.'); }
    }
    private static function label(mixed $label): void
    {
        if (!is_string($label) || !preg_match('/^[a-zA-Z0-9 ._-]{1,64}$/D', $label)) { throw new RuntimeException('Unsupported descriptive field.'); }
    }
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$entry) {
            if (is_array($entry)) { $entry = self::canonical($entry); }
            elseif (!(is_string($entry) || is_bool($entry) || $entry === null || (is_int($entry) && abs($entry) <= 9007199254740991))) {
                throw new RuntimeException('UNSUPPORTED_CANONICAL_VALUE');
            }
        }
        return $value;
    }
}