<?php
if (!defined('FREEPBX_IS_AUTH') || !FREEPBX_IS_AUTH) { http_response_code(403); exit; }
if (!isset($_SESSION['AMP_user']) || !method_exists($_SESSION['AMP_user'], 'checkSection')
    || !$_SESSION['AMP_user']->checkSection('respawn')) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';

$escape=static fn ($value): string => htmlspecialchars((string) $value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$module=FreePBX::Respawn();
try { $module->assertStorageAccessible(); }
catch (Throwable $exception) {
    $diagnostic=\Respawn\Diagnostic::forThrowable($exception,'storage');
    \Respawn\Diagnostic::log($diagnostic);
    ?>
    <section class="container-fluid" aria-labelledby="respawn-storage-title">
      <h1 id="respawn-storage-title">Respawn <small>0.0.1 / Experimental</small></h1>
      <div class="alert alert-danger" role="alert"><strong>Respawn storage is unavailable.</strong>
        <p>Code: <code><?= htmlspecialchars($diagnostic['code'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></code></p>
        <p><?= htmlspecialchars($diagnostic['message'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></p>
        <p>Diagnostic reference: <code><?= htmlspecialchars($diagnostic['correlationId'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></code></p>
        <p>Preserve existing data. A FreePBX administrator can inspect and repair only the managed Respawn storage using <code>fwconsole respawn storage-repair</code>, then retry as the FreePBX service account.</p>
      </div>
    </section>
    <?php
    return;
}
$feedback=$module->consumePageFeedback();
$view=FreePBX\modules\Respawn::pageView($feedback['view'] ?? $_GET['view'] ?? ($_SESSION['respawn_page_view'] ?? 'overview'));
$_SESSION['respawn_page_view']=$view;
$settings=$module->settings();
$result=$feedback['result'] ?? null;
$error=$feedback['error'] ?? null;
$status=is_array($result) && ($feedback['action'] ?? '') === 'refresh-status'
    ? $result : $module->application()->cachedStatus();
if (($result['state'] ?? '') === 'failed' && isset($result['error'])) { $error=$result['error']; }
$operations=$status['operations'] ?? [];
$revisionStore=$module->revisionStore();
$revisionSource=$settings['identity'] ?? '';
$revisionHeads=$revisionStore->heads($revisionSource);
$revisionEntries=$revisionStore->list($revisionSource);
$selectedRevisionId=$_POST['revisionId'] ?? ($_SESSION['respawn_selected_revision'] ?? ($revisionHeads['lastCapturedRevisionId'] ?? ''));
if (is_string($selectedRevisionId) && preg_match('/^rev-[a-f0-9]{32}$/D',$selectedRevisionId)) {
    $_SESSION['respawn_selected_revision']=$selectedRevisionId;
} else { $selectedRevisionId=''; }
$revisionPlan=null;
$revisionPlanId=$_SESSION['respawn_revision_plan'] ?? '';
if ($revisionPlanId !== '') {
    try { $revisionPlan=$revisionStore->getPlan($revisionSource,$revisionPlanId); }
    catch (Throwable) { unset($_SESSION['respawn_revision_plan']); }
}
$protection=$module->protectionStatus();
$active=array_values(array_filter($operations,static fn (array $operation): bool =>
    !in_array($operation['state'] ?? '',['torn_down','rolled_back'],true)));
$hasUnresolvedSubmissions=false;
foreach ($status['localSubmissions'] ?? [] as $submission) {
    if (in_array($submission['state'] ?? '',['submitted_outcome_unknown','launch_outcome_unknown'],true)) { $hasUnresolvedSubmissions=true; }
    if (($submission['state'] ?? '') === 'authoritative_response_received') {
        $operation=null;
        foreach ($operations as $known) {
            if (($known['operationId'] ?? '') === ($submission['operationId'] ?? '')
                && ($known['connectionId'] ?? '') === ($submission['connectionId'] ?? '')) { $operation=$known; break; }
        }
        if ($operation === null || !in_array($operation['state'] ?? '',['torn_down','rolled_back'],true)) { $hasUnresolvedSubmissions=true; }
    }
}
$ack=$status['publication']['ack'] ?? $status['localPublication']['ack'] ?? null;
try { $currentBlueprint=$module->application()->currentBlueprint(); }
catch (Throwable) { $currentBlueprint=null; }
$pending=!$currentBlueprint || !$ack || ($ack['hash'] ?? '') !== ($currentBlueprint['contentHash'] ?? null);
$canLaunch=in_array($settings['mode'] ?? '',['mock','provider'],true) && !$active && !$hasUnresolvedSubmissions
    && empty($status['legacyQuarantine']) && $ack && !$pending
    && (($status['publication']['export']['schema'] ?? $currentBlueprint['schema'] ?? '') === 'respawn.reconstruction.v2');
$csrf=$_SESSION['respawn_csrf'] ?? '';
$_SESSION['respawn_csrf'] ??= bin2hex(random_bytes(32));
$csrf=$_SESSION['respawn_csrf'];
$token='<input type="hidden" name="csrf" value="'.$escape($csrf).'">'
    .'<input type="hidden" name="view" value="'.$escape($view).'">';
$lastLocal=$protection['latestLocalRevisionId'] ?? $revisionHeads['lastCapturedRevisionId'] ?? null;
$captured=$lastLocal !== null;
$published=$captured && ($protection['latestProviderAcknowledgedRevisionId'] ?? '') === $lastLocal;
$independent=$published && ($protection['latestVerifiedRevisionId'] ?? '') === $lastLocal
    && ($protection['lastRetentionVerification']['state'] ?? '') === 'verified'
    && ($protection['retentionEvidenceMode'] ?? '') === 'EXTERNAL_PROVIDER';
$recoverable=$independent && !empty($protection['credentialsIndependentlyRecoverable']);
$overall=$protection['state'] ?? 'not_configured';
$overallLabel=match ($overall) {
    'protected_and_current'=>'Protected and recoverable',
    'not_configured'=>'Protection not configured',
    'initial_capture_pending'=>'Initial capture pending',
    'changes_awaiting_publication'=>'Publication pending',
    'publication_failed'=>'Publication failed',
    'configuration_export_failed'=>'Configuration inspection failed',
    'protection_stale'=>'Protection check overdue',
    'external_retention_unverified'=>'Pond retention not verified',
    'credentials_unavailable'=>'Credential recovery unavailable',
    default=>ucfirst(str_replace('_',' ',$overall)),
};
$overallClass=match ($overall) {
    'protected_and_current'=>'success',
    'publication_failed','configuration_export_failed'=>'danger',
    'not_configured','initial_capture_pending','changes_awaiting_publication','protection_stale',
    'external_retention_unverified','credentials_unavailable'=>'warning',
    default=>'info',
};
$viewNames=['overview'=>'Overview','protection'=>'Protection','revisions'=>'Revisions','recovery'=>'Recovery','settings'=>'Settings'];
?>
<style>
.respawn-admin{max-width:1200px}.respawn-admin .respawn-heading{margin:8px 0 4px}.respawn-admin .respawn-subtitle{margin:0 0 18px;color:#666}
.respawn-admin .respawn-section{margin:18px 0 26px}.respawn-admin .respawn-status{font-weight:600}.respawn-admin .respawn-id{overflow-wrap:anywhere}
.respawn-admin .respawn-actions form{display:inline-block;margin:0 6px 6px 0}.respawn-admin .respawn-field{max-width:640px}
.respawn-admin .respawn-diagnostics{max-height:420px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}
.respawn-admin .respawn-empty{padding:14px;border-left:3px solid #999;background:#f7f7f7}
.respawn-admin .respawn-state{display:inline-block;padding:3px 8px;border-radius:3px;border:1px solid #aaa}
.respawn-admin .respawn-summary dt{margin-top:7px}.respawn-admin .respawn-summary dd{overflow-wrap:anywhere}
@media(max-width:767px){.respawn-admin .respawn-actions form{display:block}.respawn-admin h1 small{display:block;margin-top:6px}}
</style>
<div class="container-fluid respawn-admin">
  <header class="respawn-section" aria-labelledby="respawn-title">
    <h1 class="respawn-heading" id="respawn-title">Respawn <small>0.0.1 / Experimental</small></h1>
    <p class="respawn-subtitle">Automatic configuration protection and on-demand FreePBX reconstruction.</p>
    <div class="alert alert-<?= $escape($overallClass) ?>" role="status">
      <strong><?= $escape($overallLabel) ?></strong>
      <?php if ($overall === 'not_configured'): ?>
        <span> Configure an authorised Pond connection and host schedule in Settings to begin protection.</span>
      <?php elseif ($overall !== 'protected_and_current' && !empty($protection['lastError'])): ?>
        <span> <?= $escape($protection['lastError']) ?>. Review Protection for the last recorded failure.</span>
      <?php endif ?>
    </div>
    <p>Captured is local history. Protected means the matching blueprint is independently retained. Recoverable also requires the exact credentials. None proves telephony recovery.</p>
  </header>

  <nav aria-label="Respawn views"><ul class="nav nav-tabs respawn-section">
    <?php foreach ($viewNames as $key=>$label): ?><li class="<?= $view===$key?'active':'' ?>"><a href="?display=respawn&amp;view=<?= $escape($key) ?>" <?= $view===$key?'aria-current="page"':'' ?>><?= $escape($label) ?></a></li><?php endforeach ?>
  </ul></nav>

  <?php if ($error): ?><div class="alert alert-danger" role="alert"><strong><?= $escape($error['code'] ?? 'Request failed') ?></strong>
    <p><?= $escape($error['message'] ?? 'Respawn could not complete the request.') ?></p>
    <?php if (!empty($error['nextAction'])): ?><p><?= $escape($error['nextAction']) ?></p><?php endif ?>
    <?php if (!empty($error['correlationId'])): ?><small>Diagnostic reference: <?= $escape($error['correlationId']) ?></small><?php endif ?>
  </div><?php endif ?>
  <?php if (is_array($result) && empty($error)): ?><div class="alert alert-info" role="status"><?= $escape($result['state'] ?? 'Request completed') ?></div><?php endif ?>

  <?php if ($view === 'overview'): ?>
    <section class="respawn-section" aria-labelledby="overview-heading">
      <h2 id="overview-heading">Protection overview</h2>
      <div class="row">
        <div class="col-sm-3"><div class="panel panel-default"><div class="panel-heading">Local configuration / Puddle</div><div class="panel-body">
          <p class="respawn-status"><?= $captured?'Captured':'Not captured' ?></p>
          <p>Revision <code class="respawn-id"><?= $escape($lastLocal ?? 'none') ?></code></p>
          <p>Inspected <?= $escape($protection['lastSuccessfulInspectionAt'] ?? 'not recorded') ?></p>
        </div></div></div>
        <div class="col-sm-3"><div class="panel panel-default"><div class="panel-heading">Publication / Stream</div><div class="panel-body">
          <p class="respawn-status"><?= $published?'Acknowledged':($protection['pendingRevisions']?'Pending':'Not verified') ?></p>
          <p>Pending <?= $escape($protection['pendingRevisions'] ?? 0) ?> revisions</p>
          <p>Scheduler <?= $escape($protection['schedulerState'] ?? 'never observed') ?></p>
        </div></div></div>
        <div class="col-sm-3"><div class="panel panel-default"><div class="panel-heading">Independent protection / Pond</div><div class="panel-body">
          <p class="respawn-status"><?= $independent?'Verified':($captured?'Not verified':'Not configured') ?></p>
          <p>Revision <code class="respawn-id"><?= $escape($protection['latestVerifiedRevisionId'] ?? 'none') ?></code></p>
          <p>Verified <?= $escape($protection['lastRetentionVerification']['verifiedAt'] ?? 'not recorded') ?></p>
        </div></div></div>
        <div class="col-sm-3"><div class="panel panel-default"><div class="panel-heading">Recovery information</div><div class="panel-body">
          <p class="respawn-status"><?= $recoverable?'Credentials verified':($independent?'Credentials unavailable':'Not verified') ?></p>
          <p>Telephony readiness is assessed separately.</p>
        </div></div></div>
      </div>
      <?php if (!$captured): ?><p class="respawn-empty">No local revision is recorded. Configure protection and schedule in Settings, or capture supported configuration from Revisions.</p><?php endif ?>
      <?php if (!empty($protection['lastError']) || in_array($protection['schedulerState'] ?? '',['missed','stalled'],true)): ?>
        <div class="alert alert-warning" role="alert"><strong>Attention required.</strong>
          <?= $escape($protection['lastError'] ?? ('Scheduled reconciliation is '.$protection['schedulerState'].'.')) ?>
        </div>
      <?php endif ?>
      <dl class="dl-horizontal respawn-summary">
        <dt>Last change</dt><dd><?= $escape($protection['lastDetectedChangeAt'] ?? 'No change recorded') ?></dd>
        <dt>Last successful publication</dt><dd><?= $escape($protection['lastSuccessfulPublicationAt'] ?? 'Not verified') ?></dd>
        <dt>Last successful Pond communication</dt><dd><?= $escape($protection['lastSuccessfulPondCommunicationAt'] ?? 'Not recorded') ?></dd>
        <dt>Last successful reconciliation</dt><dd><?= $escape($protection['lastSuccessfulReconciliationAt'] ?? 'Not recorded') ?></dd>
        <dt>Next expected check</dt><dd><?= $escape($protection['nextCheckAt'] ?? 'Not scheduled') ?></dd>
      </dl>
      <div class="respawn-actions"><form method="post"><?= $token ?><button class="btn btn-default" name="action" value="refresh-status">Refresh provider status</button></form>
        <a class="btn btn-default" href="?display=respawn&amp;view=protection">Protection details</a></div>
    </section>
  <?php endif ?>

  <?php if ($view === 'protection'): ?>
    <section class="respawn-section" aria-labelledby="protection-heading"><h2 id="protection-heading">Protection</h2>
      <div class="row">
        <div class="col-md-4"><section class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">The Puddle</h3></div><div class="panel-body">
          <p>Local supported configuration and revision history. This cache alone does not survive loss of this server.</p>
          <dl><dt>Capture</dt><dd><?= $captured?'Available':'No revision captured' ?></dd><dt>Last inspection</dt><dd><?= $escape($protection['lastSuccessfulInspectionAt'] ?? 'Not recorded') ?></dd>
            <dt>Latest revision</dt><dd class="respawn-id"><code><?= $escape($lastLocal ?? 'none') ?></code></dd><dt>History</dt><dd><?= count($revisionEntries) ?> / <?= $escape($revisionStore->retentionLimit()) ?> revisions</dd>
            <dt>Last detected change</dt><dd><?= $escape($protection['lastDetectedChangeAt'] ?? 'None recorded') ?></dd></dl>
          <form method="post"><?= $token ?><button class="btn btn-default" name="action" value="revision-capture">Capture supported configuration</button></form>
        </div></section></div>
        <div class="col-md-4"><section class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">The Stream</h3></div><div class="panel-body">
          <p>Scheduled inspection, change detection and retryable publication.</p>
          <dl><dt>Automatic protection</dt><dd><?= !empty($protection['enabled'])?'Enabled':'Disabled' ?></dd><dt>Interval</dt><dd><?= isset($protection['intervalSeconds'])?$escape((int)$protection['intervalSeconds']/60).' minutes':'Not configured' ?></dd>
            <dt>Scheduler</dt><dd><?= $escape($protection['schedulerState'] ?? 'Never observed') ?></dd><dt>Last invocation</dt><dd><?= $escape($protection['lastInvocationCompletedAt'] ?? 'Not recorded') ?></dd>
            <dt>Last successful reconciliation</dt><dd><?= $escape($protection['lastSuccessfulReconciliationAt'] ?? 'Not recorded') ?></dd><dt>Pending</dt><dd><?= $escape($protection['pendingRevisions'] ?? 0) ?></dd>
            <dt>Last error</dt><dd><?= $escape($protection['lastError'] ?? 'None recorded') ?></dd></dl>
          <?php if (($protection['schedulerState'] ?? 'never_observed') !== 'recent'): ?><p class="alert alert-warning" role="status">Host cron has not been observed recently. Module installation does not install the schedule.</p><?php endif ?>
          <form method="post"><?= $token ?><button class="btn btn-default" name="action" value="protection-reconcile">Reconcile now</button></form>
        </div></section></div>
        <div class="col-md-4"><section class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">The Pond</h3></div><div class="panel-body">
          <p>Independent retention and authorised recovery storage.</p>
          <dl><dt>Connection</dt><dd><?= $escape($status['providerStatus'] ?? 'Not configured') ?></dd>
            <dt>Last acknowledged</dt><dd class="respawn-id"><code><?= $escape($protection['latestProviderAcknowledgedRevisionId'] ?? 'none') ?></code></dd>
            <dt>Verified revision</dt><dd class="respawn-id"><code><?= $escape($protection['latestVerifiedRevisionId'] ?? 'none') ?></code></dd>
            <dt>Retention check</dt><dd><?= $escape($protection['lastRetentionVerification']['state'] ?? 'Not verified') ?></dd>
            <dt>Credential recovery</dt><dd><?= !empty($protection['credentialsIndependentlyRecoverable'])?'Verified':'Not verified' ?></dd>
            <dt>Last communication</dt><dd><?= $escape($protection['lastSuccessfulPondCommunicationAt'] ?? 'Not recorded') ?></dd></dl>
          <?php if (($settings['mode'] ?? 'disabled') !== 'provider'): ?><p class="respawn-empty">No independent Pond connection is configured.</p><?php endif ?>
          <a class="btn btn-default" href="?display=respawn&amp;view=settings">Open provider settings</a>
        </div></section></div>
      </div>
      <p>Captured is local. Protected requires verified independent retention. Recoverable requires exact versioned credentials. These states do not prove SIP registration, calling or audio.</p>
    </section>
  <?php endif ?>

  <?php if ($view === 'revisions'): ?>
    <section class="respawn-section" aria-labelledby="revision-heading"><h2 id="revision-heading">Revisions</h2>
      <p>Revert selects an earlier revision. Regenerate plans convergence towards a selected revision. Native update/removal ownership and apply capabilities remain unavailable; unsupported plans cannot execute.</p>
      <p>Local cache: Asterisk variable-data directory. Retention <?= $escape($revisionStore->retentionLimit()) ?>. Heads: captured <code><?= $escape($revisionHeads['lastCapturedRevisionId'] ?? 'none') ?></code>; acknowledged <code><?= $escape($revisionHeads['lastAcknowledgedRevisionId'] ?? 'none') ?></code>; applied <code><?= $escape($revisionHeads['lastAppliedRevisionId'] ?? 'none') ?></code>; configuration verified <code><?= $escape($revisionHeads['lastVerifiedRevisionId'] ?? 'none') ?></code>.</p>
      <?php if (!$revisionEntries): ?><p class="respawn-empty">No revisions yet. Capture supported configuration to begin local history.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th scope="col">Revision</th><th scope="col">Captured</th><th scope="col">Origin</th><th scope="col">Publication</th><th scope="col">Scope</th><th scope="col">Retention</th><th scope="col">Available actions</th></tr></thead><tbody>
        <?php foreach ($revisionEntries as $revision): ?><tr><th scope="row"><code title="<?= $escape($revision['revisionId']) ?>"><?= $escape(substr($revision['revisionId'],0,16)) ?>…</code></th>
          <td><?= $escape($revision['capturedAt'] ?? '') ?></td><td><?= $escape($revision['captureOrigin'] ?? '') ?></td>
          <td><?= !empty($revision['publicationAcknowledgement'])?'Acknowledged':'Local only' ?></td>
          <td><?= count($revision['objects'] ?? []) ?> objects / <?= $escape($revision['classification'] ?? 'captured, unverified') ?></td>
          <td><?= !empty($revision['pinned'])?'Pinned ('.$escape($revision['pinCount'] ?? 0).')':'Retained' ?></td>
          <td><?php if (($revision['state'] ?? '') !== 'quarantined'): ?><div class="respawn-actions">
            <?php foreach (['revert'=>'Preview Revert','regenerate'=>'Preview Regenerate'] as $kind=>$label): ?><form method="post"><?= $token ?><input type="hidden" name="revisionId" value="<?= $escape($revision['revisionId']) ?>"><input type="hidden" name="localAction" value="<?= $escape($kind) ?>"><button class="btn btn-default btn-xs" name="action" value="revision-plan"><?= $escape($label) ?></button></form><?php endforeach ?>
          </div><?php else: ?>Unavailable (quarantined)<?php endif ?></td></tr><?php endforeach ?>
        </tbody></table></div>
      <?php endif ?>
      <div class="respawn-actions"><form method="post"><?= $token ?><button class="btn btn-default" name="action" value="revision-capture">Capture current supported state</button></form></div>
      <?php if ($revisionEntries): ?>
        <div class="row"><div class="col-md-6"><h3>Revert</h3><p>Plan a return to an earlier retained configuration. It does not reverse external routing.</p>
          <form method="post"><?= $token ?><input type="hidden" name="localAction" value="revert"><div class="form-group"><label for="respawn-revert">Revision</label><select class="form-control respawn-field" id="respawn-revert" name="revisionId" required><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '')==='quarantined') continue; ?><option value="<?= $escape($revision['revisionId']) ?>" <?= $selectedRevisionId===$revision['revisionId']?'selected':'' ?>><?= $escape(substr($revision['revisionId'],0,16)) ?>… / <?= $escape(substr($revision['contentHash'],0,12)) ?></option><?php endforeach ?></select></div><button class="btn btn-default" name="action" value="revision-plan">Preview Revert</button></form>
        </div><div class="col-md-6"><h3>Regenerate</h3><p>Plan supported drift repair towards a selected revision while preserving unowned configuration.</p>
          <form method="post"><?= $token ?><input type="hidden" name="localAction" value="regenerate"><div class="form-group"><label for="respawn-regenerate">Revision</label><select class="form-control respawn-field" id="respawn-regenerate" name="revisionId" required><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '')==='quarantined') continue; ?><option value="<?= $escape($revision['revisionId']) ?>" <?= $selectedRevisionId===$revision['revisionId']?'selected':'' ?>><?= $escape(substr($revision['revisionId'],0,16)) ?>… / <?= $escape(substr($revision['contentHash'],0,12)) ?></option><?php endforeach ?></select></div><button class="btn btn-default" name="action" value="revision-plan">Preview Regenerate</button></form>
        </div></div>
        <h3>Compare revisions</h3><form method="post" class="form-inline"><?= $token ?><div class="form-group"><label for="respawn-from">From</label><select id="respawn-from" class="form-control" name="fromRevision"><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '')==='quarantined') continue; ?><option value="<?= $escape($revision['revisionId']) ?>"><?= $escape(substr($revision['revisionId'],0,16)) ?>…</option><?php endforeach ?></select></div>
          <div class="form-group"><label for="respawn-to">To</label><select id="respawn-to" class="form-control" name="toRevision"><?php foreach ($revisionEntries as $revision): if (($revision['state'] ?? '')==='quarantined') continue; ?><option value="<?= $escape($revision['revisionId']) ?>" <?= $selectedRevisionId===$revision['revisionId']?'selected':'' ?>><?= $escape(substr($revision['revisionId'],0,16)) ?>…</option><?php endforeach ?></select></div>
          <button class="btn btn-default" name="action" value="revision-compare">Compare</button></form>
      <?php endif ?>
      <?php if (is_array($result) && isset($result['changes'])): ?><h3>Comparison</h3><dl class="dl-horizontal"><dt>Added</dt><dd><?= $escape(implode(', ',$result['changes']['create'] ?? [] ) ?: 'None') ?></dd><dt>Modified</dt><dd><?= $escape(implode(', ',$result['changes']['update'] ?? [] ) ?: 'None') ?></dd><dt>Removed</dt><dd><?= $escape(implode(', ',$result['changes']['remove'] ?? [] ) ?: 'None') ?></dd><dt>Unchanged</dt><dd><?= $escape(count($result['changes']['unchanged'] ?? [])) ?> objects</dd></dl><?php endif ?>
      <?php if (is_array($revisionPlan)): ?><section class="panel panel-default" aria-labelledby="plan-title"><div class="panel-heading"><h3 class="panel-title" id="plan-title">Reviewed <?= $escape(strtoupper($revisionPlan['action'])) ?> plan</h3></div><div class="panel-body">
        <p>Target <code><?= $escape($revisionPlan['targetId']) ?></code>. Create <?= count(array_filter($revisionPlan['mutations'],static fn(array $s):bool=>($s['mutation']??'')==='create')) ?>; update <?= count(array_filter($revisionPlan['mutations'],static fn(array $s):bool=>($s['mutation']??'')==='update')) ?>; remove <?= count(array_filter($revisionPlan['mutations'],static fn(array $s):bool=>($s['mutation']??'')==='remove')) ?>.</p>
        <?php if ($revisionPlan['conflicts'] || $revisionPlan['unsupportedDifferences']): ?><div class="alert alert-danger" role="alert"><strong>Execution blocked</strong><ul><?php foreach (array_merge($revisionPlan['conflicts'],$revisionPlan['unsupportedDifferences']) as $blocked): ?><li><?= $escape($blocked['id'] ?? '') ?> / <?= $escape($blocked['reasonCode'] ?? 'unsupported') ?></li><?php endforeach ?></ul></div><?php endif ?>
        <div class="table-responsive"><table class="table"><thead><tr><th scope="col">Change</th><th scope="col">Object</th><th scope="col">Reason</th></tr></thead><tbody><?php foreach ($revisionPlan['mutations'] as $step): ?><tr><td><?= $escape($step['mutation']) ?></td><th scope="row"><?= $escape($step['id']) ?></th><td><?= $escape($step['reason'] ?? '') ?></td></tr><?php endforeach ?></tbody></table></div>
        <?php if (empty($revisionPlan['capabilities']['apply'])): ?><p class="alert alert-warning">Native execution unavailable: this target does not provide the required apply and ownership capabilities. Preview remains read-only.</p><?php elseif (empty($revisionPlan['conflicts']) && empty($revisionPlan['unsupportedDifferences']) && !empty($revisionPlan['compatibility']['matches']) && empty($revisionPlan['providerReconciliationRequired'])): ?><form method="post"><?= $token ?><input type="hidden" name="action" value="revision-execute"><input type="hidden" name="planId" value="<?= $escape($revisionPlan['planId']) ?>"><div class="form-group"><label for="respawn-confirm-plan">Confirm exact plan</label><input class="form-control respawn-field" id="respawn-confirm-plan" name="confirmation" value="execute:<?= $escape($revisionPlan['planId']) ?>" required></div><button class="btn btn-danger">Authorize and execute</button></form><?php endif ?>
      </div></section><?php endif ?>
    </section>
  <?php endif ?>

  <?php if ($view === 'recovery'): ?>
    <section class="respawn-section" aria-labelledby="recovery-heading"><h2 id="recovery-heading">Recovery</h2>
      <p>Respawn requests a replacement operation. Planning, configuration read-back, runtime readiness, SIP registration, calls and audio are separate evidence.</p>
      <dl><dt>Intent</dt><dd><?= $escape($settings['intent'] ?? 'rehearsal') ?></dd><dt>Connection</dt><dd><?= $escape($status['connectionId'] ?? 'not configured') ?> / <?= $escape($status['providerStatus'] ?? 'cached, unverified') ?></dd>
        <dt>Eligible blueprint</dt><dd><?= $canLaunch?$escape($ack['blueprintId'].' / version '.$ack['version']):'No currently eligible acknowledged blueprint' ?></dd>
        <dt>Provider readiness</dt><dd><?= $escape($status['providerStatus'] ?? 'not verified') ?></dd><dt>Active operations</dt><dd><?= count($active) ?></dd>
        <dt>Uncertain submissions</dt><dd><?= $hasUnresolvedSubmissions?'Requires reconciliation':'None recorded' ?></dd></dl>
      <?php if (!$canLaunch): ?><p class="alert alert-warning" role="status">Respawn is unavailable until a current acknowledged blueprint, valid provider mode and clear operation state are confirmed.</p><?php endif ?>
      <form method="post"><?= $token ?><input type="hidden" name="sessionLaunchKey" value="<?= $escape($_SESSION['respawn_launch_key'] ?? '') ?>"><button class="btn btn-primary" name="action" value="respawn" <?= $canLaunch?'':'disabled' ?>>Request Respawn</button></form>
      <h3>Refresh provider state</h3><p>Refresh explicitly queries saved provider connections and synchronizes their operation records.</p><form method="post"><?= $token ?><button class="btn btn-default" name="action" value="refresh-status">Refresh provider status</button></form>
      <?php if (!empty($status['localSubmissions'])): ?><h3>Pending and uncertain submissions</h3><div class="table-responsive"><table class="table table-striped"><thead><tr><th scope="col">Submission</th><th scope="col">Connection</th><th scope="col">State</th><th scope="col">Blueprint</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($status['localSubmissions'] as $submissionId=>$submission): ?><tr><th scope="row"><code title="<?= $escape($submissionId) ?>"><?= $escape(substr($submissionId,0,20)) ?>…</code></th><td><?= $escape($submission['connectionId'] ?? '') ?></td><td><?= $escape($submission['state'] ?? 'unknown') ?></td><td><?= $escape(($submission['blueprintId'] ?? 'none').' / '.($submission['blueprintVersion'] ?? '')) ?></td><td>
          <form method="post"><?= $token ?><input type="hidden" name="submissionId" value="<?= $escape($submissionId) ?>"><button class="btn btn-default btn-sm" name="action" value="reconcileSubmission">Reconcile</button></form>
          <?php if (in_array($submission['state'] ?? '',['launch_outcome_unknown','authoritative_not_found'],true)): ?><form method="post"><?= $token ?><input type="hidden" name="submissionId" value="<?= $escape($submissionId) ?>"><button class="btn btn-warning btn-sm" name="action" value="retrySubmission">Retry original request</button></form><?php endif ?>
        </td></tr><?php endforeach ?>
      </tbody></table></div><?php endif ?>
      <?php if (!empty($status['legacyQuarantine'])): ?><div class="alert alert-warning" role="alert">Legacy records with unknown provider identity are quarantined and cannot be managed until explicitly reconciled.</div><?php endif ?>
      <?php if ($operations): ?><h3>Operations</h3><?php foreach (array_reverse($operations) as $operation): ?><section class="panel panel-default"><div class="panel-heading"><strong><?= $escape($operation['operationId'] ?? 'Unidentified operation') ?></strong> / <?= $escape($operation['state'] ?? 'unknown') ?> <span class="text-muted">(<?= $escape($operation['localKnowledge'] ?? 'cached') ?>)</span></div><div class="panel-body">
        <dl class="dl-horizontal"><dt>Provider connection</dt><dd class="respawn-id"><?= $escape($operation['connectionId'] ?? 'unknown') ?></dd><dt>Updated</dt><dd><?= $escape($operation['updatedAt'] ?? 'unknown') ?></dd>
          <dt>Reconstruction</dt><dd><?= $escape($operation['reconstruction']['state'] ?? 'Not reported') ?></dd><dt>Saved configuration</dt><dd><?= !empty($operation['reconstruction']['savedConfigurationVerified'])?'Verified':'Not verified' ?></dd>
          <dt>Runtime / calls</dt><dd><?= !empty($operation['runtimeVerified'])?'Verified':'Not independently verified' ?></dd><dt>Readiness</dt><dd><?= $escape($operation['readinessAssessment']['state'] ?? 'Not reported') ?></dd></dl>
        <?php if (!empty($operation['quarantined'])): ?><p class="alert alert-warning" role="alert">This operation is quarantined and cannot be controlled.</p><?php else: ?>
          <?php if (isset($operation['reconstruction'])): ?><details><summary>Reconstruction checkpoints</summary><div class="table-responsive"><table class="table"><thead><tr><th scope="col">Object</th><th scope="col">Checkpoint</th><th scope="col">Ownership</th></tr></thead><tbody><?php foreach ($operation['reconstruction']['operations'] ?? [] as $id=>$checkpoint): ?><tr><th scope="row"><?= $escape($id) ?></th><td><?= $escape($checkpoint['state'] ?? 'unknown') ?></td><td><?= $escape($checkpoint['ownership'] ?? 'unknown') ?></td></tr><?php endforeach ?></tbody></table></div><?php if (!empty($operation['reconstruction']['error'])): ?><p role="alert">Stopped: <?= $escape($operation['reconstruction']['error']['code'] ?? 'unknown') ?></p><?php endif ?></details><?php endif ?>
          <div class="respawn-actions"><form method="post"><?= $token ?><input type="hidden" name="id" value="<?= $escape($operation['operationId']) ?>"><input type="hidden" name="revision" value="<?= $escape($operation['revision'] ?? 0) ?>">
            <?php if (($settings['mode'] ?? '')==='mock' && !isset($operation['readinessAssessment'])): ?><button class="btn btn-default btn-sm" name="action" value="advance">Advance MOCK</button><?php endif ?>
            <button class="btn btn-default btn-sm" name="action" value="cancel" <?= in_array($operation['state'] ?? '',['submitted','accepted','provisioning','configuration','connectivity','routing_prepared','cancelling','unknown','rollback_pending'],true)?'':'disabled' ?>>Cancel</button>
            <label for="activate-<?= $escape($operation['operationId']) ?>">Activation confirmation</label><input id="activate-<?= $escape($operation['operationId']) ?>" name="confirmation" placeholder="activate:operation-id">
            <button class="btn btn-warning btn-sm" name="action" value="activate" <?= ($operation['state'] ?? '')==='ready' && ($operation['readinessAssessment']['state'] ?? '')==='current'?'':'disabled' ?>>Request routing activation</button>
            <label><input type="checkbox" name="disposition" value="operator-confirmed-deactivate"> Confirm active-routing disposition</label>
            <button class="btn btn-danger btn-sm" name="action" value="teardown" <?= in_array($operation['state'] ?? '',['ready','active','cancelled','failed','unknown','teardown_partial','rollback_pending'],true)?'':'disabled' ?>>Tear down owned resources</button>
          </form></div>
        <?php endif ?>
      </div></section><?php endforeach ?><?php else: ?><p class="respawn-empty">No provider operations are recorded. Refresh provider state to check saved connections.</p><?php endif ?>
    </section>
  <?php endif ?>

  <?php if ($view === 'settings'): ?>
    <section class="respawn-section" aria-labelledby="settings-heading"><h2 id="settings-heading">Settings and policy</h2>
      <form method="post"><?= $token ?><input type="hidden" name="action" value="settings">
        <fieldset><legend>PBX identity</legend><p>Installed identity: <code><?= $escape($settings['identity'] ?? 'unconfigured') ?></code>. Identity is immutable; provider enrolment is managed by the authorised Pond.</p></fieldset>
        <fieldset><legend>Provider connection</legend><div class="form-group"><label for="respawn-mode">Provider mode</label><select class="form-control respawn-field" id="respawn-mode" name="mode"><option value="disabled" <?= ($settings['mode'] ?? '')==='disabled'?'selected':'' ?>>Disabled</option><option value="mock" <?= ($settings['mode'] ?? '')==='mock'?'selected':'' ?>>MOCK development provider</option><option value="provider" <?= ($settings['mode'] ?? '')==='provider'?'selected':'' ?>>HTTPS provider contract</option></select></div>
          <div class="form-group"><label for="respawn-endpoint">HTTPS endpoint</label><input class="form-control respawn-field" id="respawn-endpoint" name="endpoint" type="url" value="<?= $escape($settings['providerConfiguration']['endpoint'] ?? '') ?>"></div>
          <div class="form-group"><label for="respawn-host">Allowed hostname</label><input class="form-control respawn-field" id="respawn-host" name="allowedHost" value="<?= $escape($settings['providerConfiguration']['allowedHosts'][0] ?? '') ?>"></div>
          <div class="form-group"><label for="respawn-provider-credential">Protected provider credential file</label><input class="form-control respawn-field" id="respawn-provider-credential" name="credentialRef" value="<?= $escape($settings['providerConfiguration']['credentialRef'] ?? '') ?>"><p class="help-block">Absolute protected file path only; no token value.</p></div>
        </fieldset>
        <fieldset><legend>Protected configuration scope</legend><div class="form-group"><label for="respawn-groups">Ring Groups</label><input class="form-control respawn-field" id="respawn-groups" name="groups" value="<?= $escape($settings['groups'] ?? '600,601') ?>"></div>
          <div class="form-group"><label for="respawn-manifest">Protected secret manifest path</label><input class="form-control respawn-field" id="respawn-manifest" name="secretManifest" value="<?= $escape($settings['secretManifest'] ?? '') ?>"><p class="help-block">Contains references and protected file paths, never secret values.</p></div>
        </fieldset>
        <fieldset><legend>Automatic protection</legend><div class="checkbox"><label><input type="checkbox" name="protectionEnabled" value="1" <?= !empty($settings['protectionEnabled'])?'checked':'' ?>> Enable scheduled reconciliation</label></div>
          <div class="form-group"><label for="respawn-interval">Inspection interval</label><select class="form-control respawn-field" id="respawn-interval" name="protectionIntervalSeconds"><?php foreach ([300=>'5 minutes',600=>'10 minutes',900=>'15 minutes',1800=>'30 minutes',3600=>'1 hour'] as $seconds=>$label): ?><option value="<?= $seconds ?>" <?= (int)($settings['protectionIntervalSeconds'] ?? 300)===$seconds?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach ?></select></div>
          <p class="help-block">Install and monitor the host cron entry separately; saving settings does not install or prove scheduler execution.</p>
        </fieldset>
        <fieldset><legend>Recovery policy</legend><div class="form-group"><label for="respawn-intent">Operation intent</label><select class="form-control respawn-field" id="respawn-intent" name="intent"><?php foreach (['rehearsal','standby','recovery'] as $value): ?><option value="<?= $escape($value) ?>" <?= ($settings['intent'] ?? 'rehearsal')===$value?'selected':'' ?>><?= $escape(ucfirst($value)) ?></option><?php endforeach ?></select></div>
          <div class="form-group"><label for="respawn-activation">Activation policy</label><select class="form-control respawn-field" id="respawn-activation" name="activationPolicy"><?php foreach (['none','manual'] as $value): ?><option value="<?= $escape($value) ?>" <?= ($settings['activationPolicy'] ?? 'none')===$value?'selected':'' ?>><?= $escape(ucfirst($value)) ?></option><?php endforeach ?></select></div>
        </fieldset>
        <button class="btn btn-primary" type="submit">Save settings</button>
      </form>
      <h3>Provider tools</h3><div class="respawn-actions"><?php foreach (['capabilities'=>'Test connection / capabilities','export'=>'Export firewall','publish'=>'Publish reconstruction blueprint','verify'=>'Verify coverage'] as $action=>$label): ?><form method="post"><?= $token ?><button class="btn btn-default" name="action" value="<?= $escape($action) ?>"><?= $escape($label) ?></button></form><?php endforeach ?></div>
    </section>
  <?php endif ?>

  <details class="respawn-section"><summary>Sanitised diagnostics</summary><p>Provider responses are cached unless refreshed. Diagnostic data is sanitised; credentials and protected payloads are not displayed.</p>
    <pre class="respawn-diagnostics"><code><?= $escape(json_encode(['view'=>$view,'result'=>$result,'protection'=>$protection,'provider'=>$status],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)) ?></code></pre>
  </details>
</div>