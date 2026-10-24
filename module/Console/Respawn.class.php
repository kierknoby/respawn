<?php
namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Respawn extends Command
{
    protected function configure()
    {
        $this->setName('respawn')->setDescription('Experimental Respawn configuration and operation controls')
            ->addArgument('action', InputArgument::OPTIONAL, 'status, protection-status, protection-reconcile, storage-repair, verify, export, publish, blueprint, capabilities, respawn, operation, reconcile, reconcileSubmission, retrySubmission, cancel, teardown, activate, advance (mock only), plan, export-reconstruction, reconstruct', 'status')
            ->addArgument('area', InputArgument::OPTIONAL, 'firewall or blueprint', 'blueprint')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Republish validated intent')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Operation ID')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Stable idempotency key for launch/retry')
            ->addOption('revision', null, InputOption::VALUE_REQUIRED, 'Authoritative revision')
            ->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Exact activation confirmation')
            ->addOption('disposition', null, InputOption::VALUE_REQUIRED, 'Explicit routing disposition')
            ->addOption('groups', null, InputOption::VALUE_REQUIRED, 'Comma-separated ring-group numbers for export-reconstruction')
            ->addOption('blueprint', null, InputOption::VALUE_REQUIRED, 'Private JSON blueprint path for plan/reconstruct')
            ->addOption('secrets', null, InputOption::VALUE_REQUIRED, 'Protected manifest path; no secret values in command arguments')
            ->addOption('submission-id', null, InputOption::VALUE_REQUIRED, 'Locally journalled submission ID for lookup-only reconcile or explicit original-request retry');
        $this->addOption('revision-id', null, InputOption::VALUE_REQUIRED, 'Retained local revision ID')
            ->addOption('from-revision', null, InputOption::VALUE_REQUIRED, 'Starting retained revision ID for comparison')
            ->addOption('to-revision', null, InputOption::VALUE_REQUIRED, 'Selected retained revision ID for comparison')
            ->addOption('plan-id', null, InputOption::VALUE_REQUIRED, 'Exact reviewed local plan ID')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'revision-capture origin')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'Local revision retention limit (2..500)');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $action = $input->getArgument('action');
            if ($action === 'storage-repair') {
                $result = \FreePBX::Respawn()->repairStorageOwnership();
            } elseif ($action === 'protection-reconcile') {
                $result = \FreePBX::Respawn()->reconcileProtection();
            } elseif (in_array($action, ['protection-status','protection-health'], true)) {
                $result = \FreePBX::Respawn()->protectionStatus();
            } elseif ($action === 'protection-resume') {
                $result = \FreePBX::Respawn()->resumeProtection((string) $input->getOption('revision-id'),
                    (string) $input->getOption('confirmation'));
            } elseif ($action === 'revision-capture') {
                $result = \FreePBX::Respawn()->captureLocalRevision($input->getOption('origin') ?: 'manual_capture');
            } elseif ($action === 'revision-list') {
                $result = \FreePBX::Respawn()->listLocalRevisions();
            } elseif ($action === 'revision-show') {
                $result = \FreePBX::Respawn()->inspectLocalRevision((string) $input->getOption('revision-id'));
            } elseif ($action === 'revision-compare') {
                $result = \FreePBX::Respawn()->compareLocalRevisions((string) $input->getOption('from-revision'),
                    (string) $input->getOption('to-revision'));
            } elseif (in_array($action, ['revert-plan','regenerate-plan'], true)) {
                $localAction = $action === 'revert-plan' ? 'revert' : 'regenerate';
                $result = \FreePBX::Respawn()->planLocalAction($localAction, (string) $input->getOption('revision-id'));
            } elseif (in_array($action, ['revert-execute','regenerate-execute'], true)) {
                $planId = (string) $input->getOption('plan-id');
                $result = \FreePBX::Respawn()->executeLocalPlan($planId, (string) $input->getOption('confirmation'));
            } elseif ($action === 'revision-retention') {
                $limit = filter_var($input->getOption('retention'), FILTER_VALIDATE_INT);
                if (!is_int($limit)) { throw new \RuntimeException('REVISION_RETENTION_LIMIT_INVALID'); }
                \FreePBX::Respawn()->setLocalRevisionRetention($limit);
                $result = ['state' => 'retention_updated', 'limit' => $limit];
            } elseif ($action === 'export-reconstruction') {
                $result = \FreePBX::Respawn()->exportReconstruction(explode(',', (string) $input->getOption('groups')), $input->getOption('secrets'));
            } elseif (in_array($action, ['plan','reconstruct'], true)) {
                $path = $input->getOption('blueprint');
                if (!is_string($path) || !is_file($path) || is_link($path) || filesize($path) > 65536) {
                    throw new \RuntimeException('Unsafe or oversized blueprint file.');
                }
                $document = json_decode(file_get_contents($path), true, 24, JSON_THROW_ON_ERROR);
                \Respawn\Reconstruction\Blueprint::verifyHash($document);
                if ($action === 'plan') {
                    $result = ['state' => 'planned_only', 'operations' => \Respawn\Reconstruction\BuildSpec::plan($document), 'mutations' => false];
                } else {
                    $apply = function (): int {
                        $reload = $this->getApplication()->find('reload');
                        return $reload->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'reload']),
                            new \Symfony\Component\Console\Output\NullOutput());
                    };
                    $result = \FreePBX::Respawn()->reconstruct($document, (string) $input->getOption('confirmation'),
                        $input->getOption('secrets'), $apply);
                }
            } else {
                $arguments = ['area' => $input->getArgument('area')];
                foreach (['force', 'id', 'key', 'revision', 'confirmation', 'disposition'] as $key) { $arguments[$key] = $input->getOption($key); }
                if ($input->getOption('submission-id')) { $arguments['submissionId'] = $input->getOption('submission-id'); }
                $result = \FreePBX::Respawn()->application()->runSafely($input->getArgument('action'), $arguments);
            }
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (in_array($action, ['protection-reconcile','protection-status','protection-health'], true)) {
                if ($action === 'protection-health' && (($result['schedulerState'] ?? '') !== 'recent' || empty($result['enabled']))) { return 1; }
                return in_array($result['state'] ?? '', ['protected_and_current','not_configured'], true) ? 0 : 1;
            }
            return in_array($result['state'] ?? '', ['failed','unknown','teardown_partial','configuration_export_failed','configuration_capture_incomplete',
                'publication_failed','changes_awaiting_publication','external_retention_unverified',
                'credentials_unavailable','protection_stale'], true) ? 1 : 0;
        } catch (\Throwable $exception) {
            $diagnostic = \Respawn\Diagnostic::forThrowable($exception, $input->getArgument('action'), $input->getOption('submission-id') ?: $input->getOption('id'));
            \Respawn\Diagnostic::log($diagnostic);
            $output->writeln(json_encode(['state' => $diagnostic['outcome'], 'error' => $diagnostic], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}