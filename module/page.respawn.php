<?php
if (!defined('FREEPBX_IS_AUTH') || !FREEPBX_IS_AUTH) { http_response_code(403); exit; }
if (!isset($_SESSION['AMP_user']) || !method_exists($_SESSION['AMP_user'], 'checkSection')
    || !$_SESSION['AMP_user']->checkSection('respawn')) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$_SESSION['respawn_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['respawn_launch_key'] ??= bin2hex(random_bytes(24));
$module = FreePBX::Respawn();
$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['respawn_csrf'], $_POST['csrf'])) {
        http_response_code(403); exit('Request verification failed.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { http_response_code(413); exit; }
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'settings') {
            $module->saveSettings(['mode' => $_POST['mode'] ?? '', 'identity' => $module->settings()['identity'],
                'intent' => $_POST['intent'] ?? '', 'activationPolicy' => $_POST['activationPolicy'] ?? '',
            'groups' => $_POST['groups'] ?? '', 'secretManifest' => $_POST['secretManifest'] ?? '',
            'protectionEnabled' => isset($_POST['protectionEnabled']),
            'protectionIntervalSeconds' => (int) ($_POST['protectionIntervalSeconds'] ?? 300), 'providerConfiguration' => [
                    'schema' => 'respawn.provider-config.v1', 'adapterId' => 'https-json-v1',
                    'endpoint' => $_POST['endpoint'] ?? '', 'credentialRef' => $_POST['credentialRef'] ?? '',
                    'sourcePbxId' => $module->settings()['identity'], 'allowedHosts' => [$_POST['allowedHost'] ?? '']]]);
            $result = ['state' => 'local settings saved; no external action requested'];
          } elseif ($action === 'revision-capture') {
            $result = $module->captureLocalRevision('manual_capture');
          } elseif ($action === 'revision-plan') {
            $result = $module->planLocalAction($_POST['localAction'] ?? '', $_POST['revisionId'] ?? '');
            $_SESSION['respawn_revision_plan'] = $result['planId'];
          } elseif ($action === 'revision-execute') {
            $planId = $_POST['planId'] ?? '';
            if (!is_string($planId) || !hash_equals((string) ($_SESSION['respawn_revision_plan'] ?? ''), $planId)) {
                throw new RuntimeException('DESIRED_STATE_PLAN_SESSION_MISMATCH');
            }
            $result = $module->executeLocalPlan($planId, $_POST['confirmation'] ?? '');
            unset($_SESSION['respawn_revision_plan']);
          } elseif ($action === 'revision-compare') {
            $result = $module->compareLocalRevisions($_POST['fromRevision'] ?? '', $_POST['toRevision'] ?? '');
        } else {
          $result = (new \Respawn\OperatorController())->handle($module->application(),
            $module->settings()['identity'], $module->settings()['identity'], $_POST,
            $_SESSION['respawn_csrf'], (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
        }
        if (($action === 'publish') && isset($result['ack'])) { $module->recordPublicationRevision($result['ack']); }
          if (($action === 'respawn') && isset($result['operationId'])) { $module->syncLocalRevisionPins([$result]); }
    } catch (Throwable $exception) {
      $diagnostic = \Respawn\Diagnostic::forThrowable($exception, $_POST['action'] ?? 'operator');
      \Respawn\Diagnostic::log($diagnostic);
      $error = $diagnostic;
    }
}
$settings = $module->settings();
$protection = $module->protectionStatus();
try { $status = $module->application()->runSafely('status'); }
catch (Throwable $exception) { $status = ['providerStatus' => 'unavailable_or_unverified', 'operations' => [], 'localSubmissions' => []]; }
if (isset($status['error'])) {
  $status['providerDiagnostic'] = $status['error'];
}
if (is_array($result) && isset($result['error']) && is_array($result['error'])) { $error = $result['error']; }
$operations = $status['operations'] ?? [];
$module->syncLocalRevisionPins($operations);
$revisionStore = $module->revisionStore();
$revisionSource = $settings['identity'] ?? '';
$revisionHeads = $revisionStore->heads($revisionSource);
$revisionEntries = $revisionStore->list($revisionSource);
$selectedRevisionId = $_POST['revisionId'] ?? ($_SESSION['respawn_selected_revision'] ?? ($revisionHeads['lastCapturedRevisionId'] ?? ''));
if (is_string($selectedRevisionId) && preg_match('/^rev-[a-f0-9]{32}$/D', $selectedRevisionId)) {
  $_SESSION['respawn_selected_revision'] = $selectedRevisionId;
} else { $selectedRevisionId = ''; }
$revisionPlan = null;
$revisionPlanId = $_SESSION['respawn_revision_plan'] ?? '';
if ($revisionPlanId !== '') {
  try { $revisionPlan = $revisionStore->getPlan($revisionSource, $revisionPlanId); }
  catch (Throwable) { unset($_SESSION['respawn_revision_plan']); }
}
$active = array_values(array_filter($operations, static fn (array $operation): bool => !in_array($operation['state'] ?? '', ['torn_down','rolled_back'], true)));
$hasUnresolvedSubmissions = false;
foreach ($status['localSubmissions'] ?? [] as $submission) {
  if (in_array($submission['state'] ?? '', ['submitted_outcome_unknown','launch_outcome_unknown'], true)) { $hasUnresolvedSubmissions = true; }
  if (($submission['state'] ?? '') === 'authoritative_response_received') {
    $operation = null;
    foreach ($operations as $known) {
      if (($known['operationId'] ?? '') === ($submission['operationId'] ?? '') && ($known['connectionId'] ?? '') === ($submission['connectionId'] ?? '')) { $operation = $known; }
    }
    if ($operation === null || !in_array($operation['state'] ?? '', ['torn_down','rolled_back'], true)) { $hasUnresolvedSubmissions = true; }
  }
}
if (!$active && $operations) { $_SESSION['respawn_launch_key'] = bin2hex(random_bytes(24)); }
$token = '<input type="hidden" name="csrf" value="' . $escape($_SESSION['respawn_csrf']) . '">';
$ack = $status['publication']['ack'] ?? null;
try { $currentBlueprint = $module->application()->run('blueprint'); }
catch (Throwable $exception) { $currentBlueprint = null; }
$pending = !$currentBlueprint || !$ack || ($ack['hash'] ?? '') !== ($currentBlueprint['contentHash'] ?? null);
$canLaunch = in_array($settings['mode'] ?? '', ['mock', 'provider'], true) && !$active && !$hasUnresolvedSubmissions && empty($status['legacyQuarantine']) && $ack
  && !$pending && ($status['publication']['export']['schema'] ?? '') === 'respawn.reconstruction.v2';
?>
<div class="container-fluid">
  <h1>Respawn <small>0.0.1 experimental</small></h1>
    <div class="alert alert-warning"><?= ($settings['mode'] ?? '') === 'mock' ? 'MOCK PROVIDER / SYNTHETIC DATA / NO LIVE INFRASTRUCTURE' : 'Provider integration is unverified. Disabled unless explicitly configured. No private production services are included.' ?></div>
  <p>Revert selects an earlier supported desired state. Regenerate repairs supported configuration on this PBX. Respawn reconstructs it on a replacement. Coverage is always limited to the selected Core user, fixed PJSIP device and Ring Group profile; none of these actions restores history or media.</p>
  <?php if ($error): ?><div role="alert" class="alert alert-danger"><strong><?= $escape($error['code']) ?></strong> / <?= $escape($error['outcome']) ?><p><?= $escape($error['message']) ?></p><p><?= $escape($error['nextAction']) ?></p><small>Diagnostic reference: <?= $escape($error['correlationId']) ?></small></div><?php endif ?>
  <h2>Provider And Operation Policy</h2>
  <form method="post"><?= $token ?><input type="hidden" name="action" value="settings">
    <p>PBX identity: <code><?= $escape($settings['identity'] ?? 'unconfigured') ?></code>. Enrolment must be completed externally; configuration is not proof of authorisation.</p>
    <label>Provider mode <select name="mode"><option value="disabled">Disabled</option><option value="mock" <?= ($settings['mode'] ?? '') === 'mock' ? 'selected' : '' ?>>MOCK development provider</option><option value="provider" <?= ($settings['mode'] ?? '') === 'provider' ? 'selected' : '' ?>>HTTPS public-contract provider (unverified)</option></select></label>
    <p><label>HTTPS provider endpoint <input name="endpoint" type="url" value="<?= $escape($settings['providerConfiguration']['endpoint'] ?? '') ?>"></label>
    <label>Explicit allowed hostname <input name="allowedHost" value="<?= $escape($settings['providerConfiguration']['allowedHosts'][0] ?? '') ?>"></label>
    <label>Host-protected credential file reference (not a token) <input name="credentialRef" value="<?= $escape($settings['providerConfiguration']['credentialRef'] ?? '') ?>"></label>
    <label>Protected reconstruction secret-manifest reference (not secret values) <input name="secretManifest" value="<?= $escape($settings['secretManifest'] ?? '') ?>"></label>
    <label>Supported Ring Groups, comma-separated <input name="groups" value="<?= $escape($settings['groups'] ?? '600,601') ?>"></label></p>
    <label>Operation intent <select name="intent"><?php foreach (['rehearsal', 'standby', 'recovery'] as $value): ?><option <?= ($settings['intent'] ?? '') === $value ? 'selected' : '' ?>><?= $escape($value) ?></option><?php endforeach ?></select></label>
    <label>Activation policy <select name="activationPolicy"><?php foreach (['none', 'manual'] as $value): ?><option <?= ($settings['activationPolicy'] ?? '') === $value ? 'selected' : '' ?>><?= $escape($value) ?></option><?php endforeach ?></select></label>
    <label><input type="checkbox" name="protectionEnabled" value="1" <?= !empty($settings['protectionEnabled']) ? 'checked' : '' ?>> Enable automatic supported-configuration protection</label>
    <label>Reconciliation interval <select name="protectionIntervalSeconds"><?php foreach ([300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 hour'] as $seconds => $label): ?><option value="<?= $seconds ?>" <?= (int) ($settings['protectionIntervalSeconds'] ?? 300) === $seconds ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach ?></select></label>
    <p>Automatic protection requires provider mode and a host scheduler invoking <code>fwconsole respawn protection-reconcile</code>. Installing this module does not modify the host scheduler.</p>
    <button type="submit" class="btn btn-default">Save Policy</button>
  </form>
  <h2>Blueprint Protection</h2>
  <p>State: <strong><?= $escape($protection['state']) ?></strong>. Captured means local revision; Protected means acknowledged and retrieved Pond copy; Recoverable additionally requires verified retrieval of the exact versioned credentials. None establishes telephony recovery.</p>
  <dl><dt>Last configuration check</dt><dd><?= $escape($protection['lastCheckCompletedAt'] ?? 'not checked') ?></dd>
    <dt>Scheduler activity</dt><dd><?= $escape($protection['schedulerState'] ?? 'never_observed') ?></dd>
    <dt>Last completed scheduled invocation</dt><dd><?= $escape($protection['lastInvocationCompletedAt'] ?? 'none recorded') ?></dd>
    <dt>Last successful reconciliation</dt><dd><?= $escape($protection['lastSuccessfulReconciliationAt'] ?? 'none recorded') ?></dd>
    <dt>Failure stage</dt><dd><?= $escape($protection['failureStage'] ?? 'none recorded') ?></dd>
    <dt>Last successful configuration inspection</dt><dd><?= $escape($protection['lastSuccessfulInspectionAt'] ?? 'not inspected') ?></dd>
    <dt>Last successful Pond communication</dt><dd><?= $escape($protection['lastSuccessfulPondCommunicationAt'] ?? 'not recorded') ?></dd>
    <dt>Last detected change</dt><dd><?= $escape($protection['lastDetectedChangeAt'] ?? 'none recorded') ?></dd>
    <dt>Latest local revision</dt><dd><code><?= $escape($protection['latestLocalRevisionId'] ?? 'none') ?></code></dd>
    <dt>Latest submitted revision</dt><dd><code><?= $escape($protection['latestSubmittedRevisionId'] ?? 'none') ?></code></dd>
    <dt>Latest provider acknowledgement</dt><dd><code><?= $escape($protection['latestProviderAcknowledgedRevisionId'] ?? 'none') ?></code></dd>
    <dt>Latest locally acknowledged revision</dt><dd><code><?= $escape($protection['latestAcknowledgedRevisionId'] ?? 'none') ?></code></dd>
    <dt>Latest independently retrieved revision</dt><dd><code><?= $escape($protection['latestVerifiedRevisionId'] ?? 'none') ?></code></dd>
    <dt>Retention evidence mode</dt><dd><?= $escape($protection['retentionEvidenceMode'] ?? 'not verified') ?></dd>
    <dt>Last retention verification</dt><dd><?= $escape($protection['lastRetentionVerification']['verifiedAt'] ?? 'not verified') ?></dd>
    <dt>Next retention verification</dt><dd><?= $escape($protection['nextRetentionVerificationAt'] ?? 'not scheduled') ?></dd>
    <dt>Pending revisions</dt><dd><?= $escape($protection['pendingRevisions']) ?></dd>
    <dt>Credentials independently recoverable</dt><dd><?= !empty($protection['credentialsIndependentlyRecoverable']) ? 'yes' : 'no' ?></dd>
    <dt>Last error</dt><dd><?= $escape($protection['lastError'] ?? 'none recorded') ?></dd>
    <dt>Next check due (host cron required)</dt><dd><?= $escape($protection['nextCheckAt'] ?? 'not yet checked') ?></dd></dl>
  <h2>Publication And Verification</h2>
  <p>Provider connection <code><?= $escape($status['connectionId'] ?? 'unknown') ?></code>: <?= $escape($status['providerStatus'] ?? 'unavailable_or_unverified') ?>. Pending reconstruction changes: <?= $pending ? 'yes, unavailable or not acknowledged' : 'none detected against acknowledged content' ?>. Provider outage does not prove that resources are absent.</p>
  <?php if (!empty($status['connections'])): ?><h3>Configured And Retained Provider Connections</h3><table class="table"><thead><tr><th scope="col">Connection</th><th scope="col">PBX</th><th scope="col">Mode / endpoint</th><th scope="col">Last used</th></tr></thead><tbody><?php foreach ($status['connections'] as $connection): ?><tr><th scope="row"><code><?= $escape($connection['connectionId']) ?></code></th><td><?= $escape($connection['pbxId']) ?></td><td><?= $escape($connection['configuration']['mode'] ?? 'unknown') ?> / <?= $escape($connection['configuration']['endpoint'] ?? 'local synthetic') ?></td><td><?= $escape($connection['lastUsedAt'] ?? 'unknown') ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
  <?php if (isset($status['providerDiagnostic'])): ?><p role="status"><strong><?= $escape($status['providerDiagnostic']['code']) ?></strong>: <?= $escape($status['providerDiagnostic']['message']) ?></p><?php endif ?>
  <h2>Revert, Respawn And Regenerate</h2>
  <div class="alert alert-info">Revision capture, comparison and planning are available locally. Native FreePBX execution is currently limited to creates; updates, removals, verified ownership and the apply boundary are unavailable for Revert and Regenerate, so those plans remain non-executable. The Local Pond simulation exercises supported changes through the shared planner/executor, but is not native FreePBX evidence.</div>
  <div class="row">
    <div class="col-sm-4"><h3>Revert</h3><p>Choose an earlier retained revision, review the diff and converge only the explicitly managed scope. Missing historical secret versions block the affected action. External services and routing are not reversed.</p></div>
    <div class="col-sm-4"><h3>Respawn</h3><p>Build a replacement through the selected provider policy. Configuration readiness, routing preparation and activation remain separate. Use the controls below after publication.</p></div>
    <div class="col-sm-4"><h3>Regenerate</h3><p>Repair drift or missing supported configuration on this PBX toward a selected revision. It is not a blind reload and preserves objects outside the managed scope.</p></div>
  </div>
  <h3>Local Revision Cache</h3>
  <p>Local revisions under the Asterisk variable-data directory; retention limit <?= $escape($revisionStore->retentionLimit()) ?>. Capture is manual or scheduled when protection is enabled. A capture or provider acknowledgement alone is not known-good evidence. Last captured: <?= $escape($revisionHeads['lastCapturedRevisionId'] ?? 'none') ?>; last acknowledged: <?= $escape($revisionHeads['lastAcknowledgedRevisionId'] ?? 'none') ?>; last applied: <?= $escape($revisionHeads['lastAppliedRevisionId'] ?? 'none') ?>; last supported-state verified: <?= $escape($revisionHeads['lastVerifiedRevisionId'] ?? 'none') ?>.</p>
  <form method="post" style="display:inline-block"><?= $token ?><button class="btn btn-default" name="action" value="revision-capture">Capture Current Supported State</button></form>
  <?php if ($revisionEntries): ?><table class="table"><thead><tr><th scope="col">Revision</th><th scope="col">Captured</th><th scope="col">Origin</th><th scope="col">Hash</th><th scope="col">Scope / evidence</th><th scope="col">Pins</th></tr></thead><tbody>
    <?php foreach ($revisionEntries as $revision): ?><tr><th scope="row"><code><?= $escape($revision['revisionId']) ?></code></th><td><?= $escape($revision['capturedAt'] ?? '') ?></td><td><?= $escape($revision['captureOrigin'] ?? '') ?></td><td><code><?= $escape(substr($revision['contentHash'] ?? '',0,16)) ?></code></td><td><?= count($revision['objects'] ?? []) ?> objects / <?= $escape($revision['classification'] ?? 'captured, unverified') ?></td><td><?= $escape($revision['pinCount'] ?? 0) ?> <?= !empty($revision['pinned']) ? 'pinned' : '' ?></td></tr><?php endforeach ?>
  </tbody></table><?php else: ?><p>No local revisions captured. Capture current state before selecting a recovery revision.</p><?php endif ?>
  <form method="post"><?= $token ?><label>Selected revision <select name="revisionId" required><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '') === 'quarantined') { continue; } ?><option value="<?= $escape($revision['revisionId']) ?>" <?= ($selectedRevisionId ?? '') === $revision['revisionId'] ? 'selected' : '' ?>><?= $escape($revision['revisionId']) ?> / <?= $escape(substr($revision['contentHash'],0,12)) ?> / <?= $escape($revision['classification']) ?></option><?php endforeach ?></select></label>
    <button name="action" value="revision-plan" class="btn btn-default" onclick="this.form.localAction.value='revert'">Preview Revert</button>
    <button name="action" value="revision-plan" class="btn btn-default" onclick="this.form.localAction.value='regenerate'">Preview Regenerate</button>
    <input type="hidden" name="localAction" value="regenerate"></form>
  <form method="post"><?= $token ?><label>Compare from <select name="fromRevision" required><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '') === 'quarantined') { continue; } ?><option value="<?= $escape($revision['revisionId']) ?>"><?= $escape($revision['revisionId']) ?></option><?php endforeach ?></select></label>
    <label>Compare to <select name="toRevision" required><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '') === 'quarantined') { continue; } ?><option value="<?= $escape($revision['revisionId']) ?>" <?= ($selectedRevisionId ?? '') === $revision['revisionId'] ? 'selected' : '' ?>><?= $escape($revision['revisionId']) ?></option><?php endforeach ?></select></label>
    <button name="action" value="revision-compare" class="btn btn-default">Compare Supported Scope</button></form>
  <?php if (isset($result['changes'])): ?><pre><?= $escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif ?>
  <?php if (is_array($revisionPlan)): ?><section aria-labelledby="local-plan-heading"><h3 id="local-plan-heading">Reviewed <?= $escape(strtoupper($revisionPlan['action'])) ?> Plan <?= $escape($revisionPlan['planId']) ?></h3>
    <p>Target: <code><?= $escape($revisionPlan['targetId']) ?></code>. Changes: create <?= count(array_filter($revisionPlan['mutations'], static fn (array $step): bool => ($step['mutation'] ?? '') === 'create')) ?>, update <?= count(array_filter($revisionPlan['mutations'], static fn (array $step): bool => ($step['mutation'] ?? '') === 'update')) ?>, remove <?= count(array_filter($revisionPlan['mutations'], static fn (array $step): bool => ($step['mutation'] ?? '') === 'remove')) ?>, already matching <?= count($revisionPlan['alreadyMatching']) ?>.</p>
    <p>Coverage: <?= $revisionPlan['scope']['complete'] ? 'complete retained managed scope' : 'partial; removals not authorised' ?>. Required module versions: <?= $escape(json_encode($revisionPlan['compatibility']['requiredModules'] ?? [])) ?>. Observed compatibility: <?= !empty($revisionPlan['compatibility']['matches']) ? 'matches' : 'mismatch' ?>.</p>
    <?php if (!empty($revisionPlan['providerReconciliationRequired'])): ?><p role="alert">Provider reconciliation required: this revision differs from the acknowledged provider blueprint. Local configuration changes do not reverse routing, service records, billing or controller authority.</p><?php endif ?>
    <?php if ($revisionPlan['conflicts'] || $revisionPlan['unsupportedDifferences']): ?><div role="alert" class="alert alert-danger"><strong>Execution blocked</strong><ul><?php foreach (array_merge($revisionPlan['conflicts'],$revisionPlan['unsupportedDifferences']) as $blocked): ?><li><?= $escape($blocked['id'] ?? '') ?> / <?= $escape($blocked['reasonCode'] ?? 'unsupported') ?></li><?php endforeach ?></ul></div><?php endif ?>
    <table class="table"><thead><tr><th scope="col">Mutation</th><th scope="col">Object</th><th scope="col">Reason</th><th scope="col">Preconditions</th></tr></thead><tbody><?php foreach ($revisionPlan['mutations'] as $step): ?><tr><td><?= $escape($step['mutation']) ?></td><th scope="row"><code><?= $escape($step['id']) ?></code></th><td><?= $escape($step['reason'] ?? '') ?></td><td><?= $escape(json_encode($step['preconditions'] ?? [])) ?></td></tr><?php endforeach ?></tbody></table>
    <p>Pre-operation capture is recovery information, not transactional rollback. Apply/reload may interrupt service. On interruption, reconcile the same plan; do not automatically reverse mutations.</p>
    <?php if (empty($revisionPlan['conflicts']) && empty($revisionPlan['unsupportedDifferences']) && empty($revisionPlan['mutations'])): ?><p>This supported scope already matches the selected revision.</p><?php endif ?>
    <?php if (empty($revisionPlan['conflicts']) && empty($revisionPlan['unsupportedDifferences']) && !empty($revisionPlan['compatibility']['matches']) && !empty($revisionPlan['capabilities']['apply']) && empty($revisionPlan['providerReconciliationRequired'])): ?><form method="post"><?= $token ?><input type="hidden" name="action" value="revision-execute"><input type="hidden" name="planId" value="<?= $escape($revisionPlan['planId']) ?>"><label>Confirm reviewed plan <input name="confirmation" value="execute:<?= $escape($revisionPlan['planId']) ?>" required></label><button class="btn btn-danger">Authorize And Execute Exact Plan</button></form><?php endif ?>
  </section><?php endif ?>
  <p>Coverage: selected Core users, associated fixed PJSIP devices with original authentication secret references, and Ring Groups containing those devices with a no-answer group dependency. Exact module versions and global SIP prerequisites must already be installed/configured. Trunks, routes, voicemail, unselected objects, licences, media and history remain gaps. Real FreePBX execution and live call/audio checks remain unverified.</p>
  <p>Source credentials must match a protected secret manifest before export. Missing secrets, unsupported settings, incomplete coverage or target conflicts block reconstruction; new credentials are never silently substituted for real endpoints.</p>
    <?php foreach (['capabilities' => 'Test Connection / Capabilities', 'export' => 'Export Firewall', 'publish' => 'Publish Reconstruction Blueprint', 'verify' => 'Verify Coverage'] as $action => $label): ?>
  <form method="post" style="display:inline-block"><?= $token ?><button class="btn btn-default" name="action" value="<?= $escape($action) ?>"><?= $escape($label) ?></button></form>
  <?php endforeach ?>
  <h2>Respawn</h2>
  <p>Effective blueprint: <?= $escape($ack ? $ack['blueprintId'] . ' version ' . $ack['version'] : 'none acknowledged') ?>. Intent: <strong><?= $escape($settings['intent'] ?? '') ?></strong>. Activation: <strong><?= $escape($settings['activationPolicy'] ?? '') ?></strong>.</p>
  <form method="post"><?= $token ?><input type="hidden" name="sessionLaunchKey" value="<?= $escape($_SESSION['respawn_launch_key']) ?>"><button name="action" value="respawn" class="btn btn-primary" <?= $canLaunch ? '' : 'disabled' ?>>Respawn</button></form>
  <p>Launch uses the saved policy. Rehearsal and standby never activate traffic. Refresh this page to recover persisted operation state.</p>
  <h2>Active And Recent Operations</h2>
  <?php if (!empty($status['localSubmissions'])): ?>
    <h2>Pending And Uncertain Launches</h2>
    <?php foreach ($status['localSubmissions'] as $submissionId => $submission): ?>
      <section class="respawn-submission" aria-labelledby="submission-<?= $escape($submissionId) ?>">
        <h3 id="submission-<?= $escape($submissionId) ?>">Submission <?= $escape($submissionId) ?>: <?= $escape($submission['state']) ?></h3>
        <dl><dt>Provider connection</dt><dd><code><?= $escape($submission['connectionId']) ?></code></dd>
          <dt>PBX identity</dt><dd><?= $escape($submission['pbxId']) ?></dd>
          <dt>Blueprint</dt><dd><?= $escape($submission['blueprintId']) ?> / version <?= $escape($submission['blueprintVersion']) ?></dd>
          <dt>Intent / activation</dt><dd><?= $escape($submission['intent']) ?> / <?= $escape($submission['activationPolicy']) ?></dd>
          <dt>Submitted</dt><dd><?= $escape($submission['submittedAt']) ?></dd>
          <dt>Operation</dt><dd><?= $escape($submission['operationId'] ?? 'not yet authoritatively identified') ?></dd></dl>
        <form method="post"><?= $token ?><input type="hidden" name="submissionId" value="<?= $escape($submissionId) ?>">
          <button name="action" value="reconcileSubmission" class="btn btn-default">Reconcile Original Launch</button></form>
        <?php if (in_array($submission['state'] ?? '', ['launch_outcome_unknown','authoritative_not_found'], true)): ?>
          <form method="post"><?= $token ?><input type="hidden" name="submissionId" value="<?= $escape($submissionId) ?>">
            <button name="action" value="retrySubmission" class="btn btn-warning">Retry Original Request With Same Key</button></form>
        <?php endif ?>
        <?php if (($submission['state'] ?? '') === 'authoritative_not_found'): ?><p>The original provider authoritatively reports no operation for this key. Nothing was resubmitted. Review before starting a deliberate new launch.</p><?php endif ?>
      </section>
    <?php endforeach ?>
  <?php endif ?>
  <?php if (!empty($status['legacyQuarantine'])): ?><div role="alert" class="alert alert-warning">Some older provider records have no recorded connection identity and are quarantined for explicit reconciliation. They have not been assigned to the current provider.</div><?php endif ?>
  <?php foreach (array_reverse($operations) as $operation): ?>
    <h3><?= $escape($operation['operationId'] ?? 'Unidentified provider operation') ?>: <?= $escape($operation['state'] ?? 'unknown') ?></h3>
    <?php if (!empty($operation['quarantined'])): ?><p role="alert">This malformed or identity-mismatched provider record is quarantined and cannot be managed.</p><?php continue; endif ?>
    <p>Provider connection <code><?= $escape($operation['connectionId'] ?? 'unknown/quarantined') ?></code>; local knowledge: <?= $escape($operation['localKnowledge'] ?? 'unknown') ?>.</p>
    <?php if (!empty($operation['quarantined'])): ?><p role="alert">This malformed or identity-mismatched operation is quarantined and cannot be controlled from this record.</p><?php continue; endif ?>
    <p>Revision <?= $escape($operation['revision']) ?>; provider update <?= $escape($operation['updatedAt']) ?>. <?= ($operation['simulated'] ?? false) ? 'SIMULATED: registration, calls and audio remain unverified.' : 'Readiness requires current blueprint-bound reconstruction, apply, connectivity, routing and call/audio receipts.' ?></p>
    <?php if (isset($operation['reconstruction'])): $build = $operation['reconstruction']; ?>
      <p>Reconstruction: <strong><?= $escape($build['state'] ?? 'unknown') ?></strong>. Saved configuration: <?= !empty($build['savedConfigurationVerified']) ? 'verified' : 'not verified' ?>. Apply: <?= $escape($build['apply']['state'] ?? 'unverified legacy plan') ?>. Runtime: <?= !empty($build['runtimeVerified']) ? 'verified' : 'not independently verified' ?>.</p>
      <table class="table"><thead><tr><th scope="col">Object operation</th><th scope="col">Checkpoint</th><th scope="col">Ownership</th></tr></thead><tbody>
      <?php foreach ($build['operations'] ?? [] as $id => $checkpoint): ?><tr><th scope="row"><?= $escape($id) ?></th><td><?= $escape($checkpoint['state']) ?></td><td><?= $escape($checkpoint['ownership']) ?></td></tr><?php endforeach ?>
      </tbody></table>
      <?php if (isset($build['error'])): ?><p role="alert">Build stopped at <?= $escape($build['error']['operationId'] ?? 'preflight/apply') ?>: <?= $escape($build['error']['code']) ?>. Retry the same blueprint after reconciling versions, secrets, partial objects or conflicts. Do not blindly repeat a create.</p><?php endif ?>
    <?php endif ?>
    <?php if (isset($operation['readinessAssessment'])): ?><p>Readiness evidence: <strong><?= $escape($operation['readinessAssessment']['state']) ?></strong>. Expired or missing evidence blocks activation, but does not remove this operation from reconciliation/cancel/teardown controls.</p><?php endif ?>
    <?php if (!empty($operation['quarantined'])): ?><p role="alert">This cached operation has a stale or regressing revision and is not actionable until authoritative state is reconciled.</p><?php endif ?>
    <form method="post"><?= $token ?><input type="hidden" name="id" value="<?= $escape($operation['operationId']) ?>"><input type="hidden" name="revision" value="<?= $escape($operation['revision']) ?>">
    <?php if (($settings['mode'] ?? '') === 'mock' && !isset($operation['readinessAssessment'])): ?><button name="action" value="advance" class="btn btn-default">Advance MOCK Harness</button><?php endif ?>
      <button name="action" value="cancel" class="btn btn-default" <?= (!empty($operation['quarantined']) || !in_array($operation['state'] ?? '', ['submitted','accepted','provisioning','configuration','connectivity','routing_prepared','cancelling','unknown','rollback_pending'], true)) ? 'disabled' : '' ?>>Cancel</button>
    <label>Activation confirmation <input name="confirmation" placeholder="activate:operation-id"></label><button name="action" value="activate" class="btn btn-default" <?= (!empty($operation['quarantined']) || ($operation['readinessAssessment']['state'] ?? 'current') !== 'current' || ($operation['state'] ?? '') !== 'ready') ? 'disabled' : '' ?>>Request Routing Activation</button>
      <label><input type="checkbox" name="disposition" value="operator-confirmed-deactivate"> Explicitly deactivate active routing without automatic source failback</label>
      <button name="action" value="teardown" class="btn btn-danger" <?= (!empty($operation['quarantined']) || !in_array($operation['state'] ?? '', ['ready','active','cancelled','failed','unknown','teardown_partial','rollback_pending'], true)) ? 'disabled' : '' ?>>Tear Down Owned Resources</button>
    </form>
  <?php endforeach ?>
  <h2>Sanitised Status</h2>
  <pre><?= $escape(json_encode($result ?? $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>