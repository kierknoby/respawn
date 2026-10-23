<?php
declare(strict_types=1);

use Respawn\Reconstruction\FreePbxRinggroups;
use Respawn\Reconstruction\Executor;
use Respawn\Storage\JsonStateStore;

function ringgroupsDouble(): object {
    return new class {
        public array $rows = [];
        public array $created = [];
        public function get(string $number): array { return $this->rows[$number] ?? []; }
        public function add(...$args): bool {
            $number = $args[0];
            if (isset($this->rows[$number])) { return false; }
            $fields = ['grpnum','strategy','grptime','grplist','postdest','description','grppre','annmsg_id','alertinfo',
                'needsconf','remotealert_id','toolate_id','ringing','cwignore','cfignore','changecid','fixedcid',
                'cpickup','recording','progress','elsewhere','rvolume'];
            $this->rows[$number] = array_combine($fields, array_slice($args, 0, 22));
            $this->created[] = $number;
            return true;
        }
    };
}
$sourceModule = ringgroupsDouble();
$sourceAdapter = new FreePbxRinggroups($sourceModule, 'source-double', fn (string $member): bool => true);
foreach (array_reverse($groups) as $group) { $sourceAdapter->create($group); }
$exported = $sourceAdapter->export('pbx-alpha', ['600', '601']);
check($exported === $blueprint, 'module-specific exporter produces the executable canonical blueprint');
$cleanModule = ringgroupsDouble();
$cleanAdapter = new FreePbxRinggroups($cleanModule, 'clean-double', fn (string $member): bool => in_array($member, ['1001', '1002'], true));
$cleanExecutor = new Executor($cleanAdapter, new JsonStateStore($directory . '/adapter'));
check($cleanExecutor->execute($exported, true)['state'] === 'configuration_verified', 'source adapter export reconstructs into clean target adapter double');
check($cleanModule->created === ['601', '600'], 'actual adapter call order honours no-answer dependency');
$sourceModule->rows['600']['recording'] = 'always';
rejects(fn () => $sourceAdapter->export('pbx-alpha', ['600', '601']), 'unsupported non-default module configuration is not silently lost');
foreach (glob($directory . '/adapter/*') as $path) { unlink($path); }
rmdir($directory . '/adapter');