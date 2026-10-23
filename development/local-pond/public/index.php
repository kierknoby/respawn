<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$root = localDemoRoot();
if (!is_dir($root . '/sessions')) { mkdir($root . '/sessions', 0700, true); }
chmod($root . '/sessions', 0700);
session_save_path($root . '/sessions');
session_name('respawn_local_demo');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => false, 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function localJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

function localInput(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 8192) { throw new RuntimeException('OPERATOR_REQUEST_TOO_LARGE'); }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    $decoded = json_decode(is_string($body) ? $body : '', true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) { throw new RuntimeException('OPERATOR_ACTION_INVALID'); }
    return $decoded;
}

function localApi(string $root): never
{
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { localJson(['error' => ['code' => 'LOOPBACK_ONLY']], 403); }
    $fixturePath = localFixturePath();
    $pond = new Respawn\LocalPond\LocalPondService($root);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        if ($method === 'GET' && parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/api/state') {
            $pbxId = $_GET['pbx'] ?? 'pbx-alpha';
            $profile = $_GET['connection'] ?? 'primary';
            $app = Respawn\LocalPond\LocalDemoApplication::build($root, $pbxId, $profile);
            $status = $app->runSafely('status');
            $capabilities = $app->runSafely('capabilities');
            $provider = new Respawn\LocalPond\LocalPondProvider(['endpoint' => 'http://127.0.0.1:' . localPondPort()
                . ($profile === 'alternate' ? '/alternate/rpc' : '/rpc'),
                'credentialRef' => $root . '/credentials/' . $pbxId . '.token', 'sourcePbxId' => $pbxId]);
            $snapshot = $pond->dashboardSnapshot($pbxId, $provider->connectionIdentity(), $fixturePath,
                $root . '/credentials/' . $pbxId . '.manifest.json');
            $localRecovery = new Respawn\LocalPond\LocalRecoveryService($root, $pbxId);
            $localRecovery->ensureSeeded();
            $localRecovery->syncRespawnOperations($status['operations'] ?? []);
            $localState = $localRecovery->revisionList();
            $snapshot['source'] = $localRecovery->sourceSnapshot();
            localJson(['csrf' => $_SESSION['csrf'], 'application' => $status, 'capabilities' => $capabilities,
              'demo' => $snapshot, 'localRecovery' => $localState,
                'server' => ['pond' => 'loopback HTTP', 'worker' => 'persistent process', 'environment' => 'DEVELOPMENT ONLY']]);
        }
        if ($method !== 'POST' || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/api/action') {
            localJson(['error' => ['code' => 'OPERATOR_ACTION_INVALID']], 405);
        }
        $input = localInput();
        $csrf = $_SERVER['HTTP_X_RESPAWN_CSRF'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) { throw new RuntimeException('OPERATOR_CSRF_INVALID'); }
        $action = $input['action'] ?? '';
        $pbxId = $input['pbx'] ?? 'pbx-alpha';
        $profile = $input['connection'] ?? 'primary';
        $intent = $input['intent'] ?? 'recovery';
        $policy = $input['activationPolicy'] ?? 'manual';
        if (!in_array($action, ['export','publish','respawn','status','operation','reconcileSubmission',
          'retrySubmission','cancel','activate','teardown','scenario','routingProbe','revisionCapture',
          'revisionCompare','revisionPlan','revisionExecute','introduceDrift','sampleRevisionB'], true)) {
            throw new RuntimeException('OPERATOR_ACTION_INVALID');
        }
        $localRecovery = new Respawn\LocalPond\LocalRecoveryService($root, $pbxId);
          if ($action === 'revisionCapture') { localJson(['result' => $localRecovery->capture()]); }
          if ($action === 'revisionCompare') {
            localJson(['result' => $localRecovery->compare($input['fromRevision'] ?? '', $input['toRevision'] ?? '')]);
          }
          if ($action === 'revisionPlan') {
            localJson(['result' => $localRecovery->plan($input['localAction'] ?? '', $input['revisionId'] ?? '')]);
          }
          if ($action === 'revisionExecute') {
            localJson(['result' => $localRecovery->execute($input['planId'] ?? '', $input['confirmation'] ?? '')]);
          }
          if ($action === 'introduceDrift') { localJson(['result' => $localRecovery->introduceSupportedDrift()]); }
          if ($action === 'sampleRevisionB') { localJson(['result' => $localRecovery->stageSupportedRevisionB()]); }
        $provider = new Respawn\LocalPond\LocalPondProvider(['endpoint' => 'http://127.0.0.1:' . localPondPort()
            . ($profile === 'alternate' ? '/alternate/rpc' : '/rpc'),
            'credentialRef' => $root . '/credentials/' . $pbxId . '.token', 'sourcePbxId' => $pbxId,
            'dropNextLaunchResponse' => $action === 'respawn' && ($input['scenario'] ?? '') === 'accepted-response-lost']);
        if ($action === 'scenario') {
            $pond->setNextScenario($pbxId, $provider->connectionIdentity(), $input['scenario'] ?? 'normal');
            localJson(['result' => ['state' => 'scenario_armed', 'scenario' => $input['scenario'] ?? 'normal']]);
        }
        if ($action === 'routingProbe') {
            localJson(['result' => $pond->routingProbe($pbxId, $input['ringGroup'] ?? '600')]);
        }
        if ($action === 'respawn') {
          $pond->setNextScenario($pbxId, $provider->connectionIdentity(), $input['scenario'] ?? 'normal');
        }
        $app = Respawn\LocalPond\LocalDemoApplication::build($root, $pbxId, $profile, $intent, $policy,
            $action === 'respawn' && ($input['scenario'] ?? '') === 'accepted-response-lost');
        $arguments = match ($action) {
            'respawn' => ['key' => $input['idempotencyKey'] ?? ''],
            'operation','cancel','teardown','activate' => ['id' => $input['operationId'] ?? ''],
            'reconcileSubmission','retrySubmission' => ['submissionId' => $input['submissionId'] ?? ''],
            default => [],
        };
        if (in_array($action, ['operation','cancel','teardown','activate'], true)) { $app->runSafely('status'); }
        if ($action === 'activate') {
            $arguments['revision'] = $input['revision'] ?? 0;
            $arguments['confirmation'] = 'activate:' . ($input['operationId'] ?? '');
        }
        if ($action === 'teardown') { $arguments['disposition'] = $input['routingDisposition'] ?? null; }
        $applicationAction = $action === 'export' ? 'blueprint' : $action;
        $result = $app->runSafely($applicationAction, $arguments);
        if ($action === 'publish' && isset($result['ack'])) { $localRecovery->recordPublicationAcknowledgement($result['ack']); }
        if ($action === 'respawn' && isset($result['operationId'])) { $localRecovery->pinRespawnOperation($result); }
        localJson(['result' => $result]);
    } catch (\Throwable $exception) {
        $diagnostic = Respawn\Diagnostic::forThrowable($exception, 'local_demo');
        Respawn\Diagnostic::log($diagnostic);
        localJson(['error' => $diagnostic], 400);
    }
}

if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/')) { localApi($root); }
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$csrfValue = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="respawn-csrf" content="<?= $csrfValue ?>">
  <title>Respawn / Local Pond demonstration</title>
  <link rel="stylesheet" href="/assets/style.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
  <header class="topbar">
    <a class="brand" href="/" aria-label="Respawn local demonstration"><span class="brand-mark">R</span><span>RESPAWN</span></a>
    <div class="environment"><span class="live-dot"></span>LOCAL POND / DEVELOPMENT ONLY</div>
  </header>
  <main class="shell">
    <section class="intro">
      <div><p class="eyebrow">SOURCE PBX → RECONSTRUCTION → ROUTING MODEL</p><h1>Local operation console</h1>
      <p>Revert selects an earlier supported state. Regenerate repairs supported state on this PBX. Respawn reconstructs it on a replacement. The local target and provider infrastructure are simulated.</p></div>
      <div class="environment-note"><strong>Not production evidence</strong><span>No Asterisk, SIP registration, live call, audio, hosting or external provider is involved.</span></div>
    </section>
    <section class="three-rs" aria-label="Three recovery actions">
      <article><span class="eyebrow">01 / EXISTING PBX</span><h2>Revert</h2><p>Select an earlier retained revision and converge only its explicitly managed supported scope.</p></article>
      <article><span class="eyebrow">02 / EXISTING PBX</span><h2>Regenerate</h2><p>Repair missing or changed supported objects toward the selected revision. It does not require Local Pond.</p></article>
      <article><span class="eyebrow">03 / REPLACEMENT PBX</span><h2>Respawn</h2><p>Ask the provider to build a replacement, prepare simulated routing, then explicitly activate or tear down.</p></article>
    </section>
    <section class="revision-workspace" aria-labelledby="revisions-heading">
      <div class="revision-heading"><div><span class="eyebrow">LOCAL RECOVERY / PROVIDER INDEPENDENT</span><h2 id="revisions-heading">Revert and Regenerate</h2><p>Protected local revisions and simulated existing-PBX state. These actions do not call the Pond or change routing.</p></div><span class="simulated-label">SIMULATED EXISTING PBX</span></div>
      <div class="revision-controls">
        <label>Selected revision<select id="revision-select"></select></label>
        <button id="capture-revision" type="button">Capture supported state</button>
        <button id="capture-sample-b" type="button">Apply sample revision B</button>
        <button id="introduce-drift" type="button">Introduce supported drift</button>
      </div>
      <div class="revision-layout">
        <div><h3>Retained revisions</h3><div id="revisions-list"></div><h3>Current supported configuration</h3><div id="local-target"></div></div>
        <div class="plan-column"><div class="plan-actions"><button id="plan-revert" type="button">Preview Revert</button><button id="plan-regenerate" type="button">Preview Regenerate</button><button id="compare-revisions" type="button">Compare revisions</button></div><div id="plan-preview"><p class="empty">Select a revision and preview a local action. Previewing does not mutate configuration.</p></div></div>
      </div>
      <p class="provider-warning">Provider reconciliation required when the selected local revision differs from the acknowledged provider blueprint. Local Revert/Regenerate never changes Pond routing, billing, connectivity or controller authority.</p>
    </section>
    <section class="controls" aria-label="Demonstration controls">
      <label>Source PBX<select id="pbx"><option value="pbx-alpha">PBX Alpha</option><option value="pbx-bravo">PBX Bravo</option></select></label>
      <label>Local Pond connection<select id="connection"><option value="primary">Primary local path</option><option value="alternate">Alternate local path</option></select></label>
      <label>Intent<select id="intent"><option value="recovery">Recovery</option><option value="rehearsal">Rehearsal</option><option value="standby">Standby</option></select></label>
      <label>Activation policy<select id="activation-policy"><option value="manual">Manual, simulated</option><option value="none">No activation</option></select></label>
      <label>Next scenario<select id="scenario"><option value="normal">Normal run</option><option value="accepted-response-lost">Accepted, response lost</option><option value="target-conflict">Conflicting target object</option><option value="unresolved-secret">Unresolved original secret</option><option value="apply-failure">Apply failure</option><option value="worker-interruption">Worker interruption</option><option value="expired-readiness">Expired simulated readiness</option><option value="cancel-during-work">Cancellation at next checkpoint</option><option value="partial-teardown">Partial teardown</option><option value="shared-resource">Preserve shared target object</option></select></label>
      <button class="icon-button" id="refresh" type="button" title="Refresh persisted status" aria-label="Refresh persisted status">↻</button>
    </section>
    <section class="launch-row">
      <div><span class="eyebrow">PERSISTED OPERATION</span><strong id="launch-status">Waiting for source selection</strong><span id="connection-id" class="mono"></span><span id="capabilities" class="mono"></span></div>
      <button class="primary-button" id="respawn" type="button">Respawn selected source <span aria-hidden="true">→</span></button>
    </section>
    <div id="alert" class="alert" role="status" aria-live="polite" hidden></div>
    <div class="dashboard-grid">
      <section class="panel source-panel"><div class="panel-heading"><div><span class="eyebrow">01 / SOURCE PBX</span><h2>Supported configuration</h2></div><button class="text-button" data-action="export">Export blueprint</button></div><div id="source" class="source-content"></div></section>
      <section class="panel blueprint-panel"><div class="panel-heading"><div><span class="eyebrow">02 / PUBLICATION</span><h2>Blueprint and coverage</h2></div><button class="text-button" data-action="publish">Publish to Local Pond</button></div><div id="publication"></div></section>
      <section class="panel operation-panel"><div class="panel-heading"><div><span class="eyebrow">03 / LOCAL POND</span><h2>Authoritative operation</h2></div><span id="operation-state" class="state-pill">No operation</span></div><div id="operation"></div><div class="actions"><button id="cancel" type="button" disabled>Cancel work</button><button id="activate" type="button" disabled>Activate simulated route</button><button id="teardown" type="button" disabled>Tear down</button><button id="reconcile" type="button" disabled>Reconcile</button><button id="retry" type="button" disabled>Retry original</button></div></section>
      <section class="panel target-panel"><div class="panel-heading"><div><span class="eyebrow">04 / REPLACEMENT</span><h2>Target objects and order</h2></div><span class="simulated-label">SIMULATED TARGET</span></div><div id="target"></div></section>
      <section class="panel routing-panel"><div class="panel-heading"><div><span class="eyebrow">05 / CONNECTIVITY + ROUTING</span><h2>Preparation and model</h2></div><span class="simulated-label">NO SIP OR SBC</span></div><div id="routing"></div><div class="probe-controls"><label>Ring Group<input id="probe-group" value="600" inputmode="numeric" maxlength="8"></label><button id="probe" type="button">Run routing-model probe</button></div><pre id="probe-result" class="probe-result" hidden></pre></section>
      <section class="panel events-panel"><div class="panel-heading"><div><span class="eyebrow">06 / AUDIT TRAIL</span><h2>Sanitised events</h2></div></div><ol id="events" class="events"></ol></section>
    </div>
    <details class="diagnostics"><summary>Failure recovery and diagnostics</summary><div id="diagnostics-content"><p>Select a failure scenario before Respawn. Refresh, reconcile and retry use persisted state and the original provider connection.</p></div></details>
  </main>
  <footer><span>Respawn development reference / GPL module not modified by this interface</span><span>Local only / credentials generated in private runtime state</span></footer>
</body>
</html>