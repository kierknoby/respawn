<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use PDO;
use RuntimeException;

final class FreePbxSnapshot
{
    public function __construct(private PDO $database, private object $manager) {}

    public function read(string $type, string $number): array
    {
        if (!$this->manager->connected()) { throw new RuntimeException('ASTERISK_MANAGER_UNAVAILABLE'); }
        if (!preg_match('/^[0-9]{2,8}$/D', $number)) { throw new RuntimeException('INVALID_READ_IDENTITY'); }
        if ($type === 'user') {
            $statement = $this->database->prepare('SELECT * FROM users WHERE extension = ?');
            if (!$statement->execute([$number])) { throw new RuntimeException('USER_SNAPSHOT_FAILED'); }
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            $ast = [];
            foreach (NativeProfile::userAst($number, $row['name'] ?? '') as $key => $unused) {
                $ast[$key] = (string) $this->manager->database_get('AMPUSER', $number . '/' . $key);
            }
            return ['row' => $row, 'ast' => $ast, 'callwaiting' => (string) $this->manager->database_get('CW', $number)];
        }
        if ($type !== 'pjsip') { throw new RuntimeException('UNSUPPORTED_SNAPSHOT_TYPE'); }
        $statement = $this->database->prepare('SELECT * FROM devices WHERE id = ?');
        if (!$statement->execute([$number])) { throw new RuntimeException('DEVICE_SNAPSHOT_FAILED'); }
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        $statement = $this->database->prepare('SELECT keyword, data FROM sip WHERE id = ?');
        if (!$statement->execute([$number])) { throw new RuntimeException('PJSIP_SNAPSHOT_FAILED'); }
        $settings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            if (array_key_exists($setting['keyword'], $settings)) { throw new RuntimeException('DUPLICATE_PJSIP_KEY'); }
            $settings[$setting['keyword']] = $setting['data'];
        }
        $ast = [];
        foreach (['user','tech','dial','type','default_user'] as $key) {
            $ast[$key] = (string) $this->manager->database_get('DEVICE', $number . '/' . $key);
        }
        return ['row' => $row, 'settings' => $settings, 'ast' => $ast];
    }
}