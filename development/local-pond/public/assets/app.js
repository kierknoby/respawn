const csrf = () => document.querySelector('meta[name="respawn-csrf"]').content;
const $ = (selector) => document.querySelector(selector);
const text = (value) => value == null ? '' : String(value);
let snapshot = null;
let currentOperation = null;
let sourcePreview = null;
let busy = false;

function selection() {
  return { pbx: $('#pbx').value, connection: $('#connection').value,
    intent: $('#intent').value, activationPolicy: $('#activation-policy').value };
}

async function request(action, details = {}) {
  const response = await fetch('/api/action', { method: 'POST', headers: {
    'Content-Type': 'application/json', 'X-Respawn-CSRF': csrf()
  }, body: JSON.stringify({ ...selection(), action, ...details }) });
  const payload = await response.json();
  if (!response.ok || payload.error) throw new Error(payload.error?.message || payload.error?.code || 'The requested action was not confirmed.');
  return payload.result;
}

async function load() {
  if (busy) return;
  const query = new URLSearchParams({ pbx: $('#pbx').value, connection: $('#connection').value });
  try {
    const response = await fetch('/api/state?' + query, { cache: 'no-store' });
    const payload = await response.json();
    if (!response.ok || payload.error) throw new Error(payload.error?.code || 'Status is unavailable.');
    document.querySelector('meta[name="respawn-csrf"]').content = payload.csrf;
    snapshot = payload;
    render();
  } catch (error) { announce(error.message, true); }
}

function render() {
  const demo = snapshot.demo;
  const app = snapshot.application;
  const connection = demo.provider.connectionId;
  $('#connection-id').textContent = connection;
  $('#launch-status').textContent = `Connected to Local Pond / ${demo.provider.enrolmentState}`;
  $('#capabilities').textContent = `Authenticated capabilities: ${(snapshot.capabilities?.capabilities || []).join(', ') || 'unavailable'}`;
  renderSource(demo.source);
  renderPublication(demo.publication, sourcePreview);
  const operations = new Map();
  for (const operation of demo.operations || []) operations.set(operation.operationId, operation);
  for (const operation of app.operations || []) operations.set(operation.operationId, operation);
  const ordered = [...operations.values()].sort((a, b) => text(a.updatedAt).localeCompare(text(b.updatedAt)));
  currentOperation = ordered.at(-1) || null;
  renderOperation(currentOperation, app.localSubmissions || {});
  renderTarget(currentOperation);
  renderRouting(currentOperation, demo.routingModel);
  renderEvents(demo.events || []);
  renderDiagnostics(currentOperation, app);
  renderRevisions(snapshot.localRecovery, demo.source);
  const appErrors = Object.values(app.localSubmissions || {});
  const latestSubmission = appErrors.at(-1);
  $('#reconcile').disabled = !latestSubmission || !['launch_outcome_unknown','authoritative_not_found'].includes(latestSubmission.state);
  $('#retry').disabled = !latestSubmission || !['launch_outcome_unknown','authoritative_not_found'].includes(latestSubmission.state);
  const state = currentOperation?.state || 'No operation';
  $('#operation-state').textContent = state.replaceAll('_',' ');
  $('#operation-state').className = 'state-pill ' + (['active','routing_prepared'].includes(state) ? 'state-ready' :
    ['failed','teardown_partial','cancelling'].includes(state) ? 'state-warning' : '');
  $('#cancel').disabled = !currentOperation || !['accepted','provisioning','configuration','connectivity','routing_prepared','cancelling'].includes(state);
  const readiness = currentOperation?.development?.readiness;
  $('#activate').disabled = !currentOperation || state !== 'routing_prepared' ||
    readiness?.activationAllowed !== true || $('#intent').value !== 'recovery' || $('#activation-policy').value !== 'manual';
  $('#teardown').disabled = !currentOperation || !['active','routing_prepared','cancelled','failed','teardown_partial'].includes(state);
  $('#respawn').disabled = Boolean(currentOperation && !['torn_down'].includes(state));
  if (state === 'torn_down') localStorage.removeItem(`respawn-key:${$('#pbx').value}:${$('#connection').value}`);
  if (latestSubmission) $('#launch-status').textContent += ` / submission ${latestSubmission.state.replaceAll('_',' ')}`;
}

function renderRevisions(local, source) {
  const revisions = local?.revisions || [];
  const select = $('#revision-select');
  const selected = select.value;
  select.innerHTML = revisions.map((revision) => `<option value="${escapeHtml(revision.revisionId)}">${escapeHtml(revision.revisionId)} / ${escapeHtml(revision.capturedAt || '')}</option>`).join('');
  if (revisions.some((revision) => revision.revisionId === selected)) select.value = selected;
  const usable = revisions.filter((revision) => revision.state !== 'quarantined');
  $('#revisions-list').innerHTML = usable.length ? `<div class="revision-table">${usable.map((revision) => `<article class="revision-row"><div><strong>${escapeHtml(revision.revisionId)}</strong><span>${escapeHtml(revision.classification)}</span></div><small>${escapeHtml(revision.contentHash.slice(0,16))} / ${revision.objects.length} objects / ${revision.pinned ? 'pinned' : 'retained'}</small><small>${revision.isLastCaptured ? 'Last captured / ' : ''}${revision.isLastAcknowledged ? 'Last acknowledged / ' : ''}${revision.isLastApplied ? 'Last applied / ' : ''}${revision.isLastVerified ? 'Supported state verified' : 'No verification claim'}</small><small>${escapeHtml(revision.captureOrigin)} / parent ${escapeHtml(revision.parentRevisionId || 'none')}</small></article>`).join('')}</div>` : '<p class="empty">No retained revisions. Capture the current supported scope to begin.</p>';
  const objects = Object.entries(source.users || {}).map(([id, user]) => ({id:`user:${id}`, name:user.name, kind:'Core user'}))
    .concat(Object.entries(source.devices || {}).map(([id, device]) => ({id:`pjsip:${id}`, name:device.description, kind:'fixed PJSIP device'})))
    .concat((source.ringGroups || []).map((group) => ({id:`ringgroup:${group.number}`, name:group.description, kind:'Ring Group'})));
  $('#local-target').innerHTML = `<p><strong>${escapeHtml(local?.targetId || 'existing PBX')}</strong> / ${escapeHtml(local?.target?.mode || 'SIMULATED')}</p><div class="local-object-list">${objects.map((object) => `<div><code>${escapeHtml(object.id)}</code><span>${escapeHtml(object.kind)} / ${escapeHtml(object.name)}</span></div>`).join('')}</div><p>Fields are read back from the supported scope. Unselected objects are outside this plan.</p>`;
}

function selectedRevisionId() { return $('#revision-select').value; }

function showPlan(plan) {
  const host = $('#plan-preview');
  if (!plan || !plan.planId) {
    host.innerHTML = `<p role="alert">${escapeHtml(plan?.error?.code || 'Plan could not be created.')}</p>`;
    return;
  }
  const counts = {create:0, update:0, remove:0};
  for (const mutation of plan.mutations || []) counts[mutation.mutation]++;
  const blocked = (plan.conflicts || []).length + (plan.unsupportedDifferences || []).length > 0;
  const rows = (plan.mutations || []).map((item) => `<li><strong>${escapeHtml(item.mutation)}</strong><code>${escapeHtml(item.id)}</code><span>${escapeHtml(item.reason || 'desired state differs')}</span><details><summary>Preconditions and desired fields</summary><pre>${escapeHtml(JSON.stringify({preconditions:item.preconditions, parameters:item.operation?.parameters || item.before?.parameters},null,2))}</pre></details></li>`).join('');
  const blockers = (plan.conflicts || []).concat(plan.unsupportedDifferences || []);
  host.innerHTML = `<div class="plan-summary"><span>${escapeHtml(plan.action.toUpperCase())} / plan ${escapeHtml(plan.planId)}</span><span>${escapeHtml(plan.revisionId)} / ${escapeHtml(plan.blueprintHash.slice(0,16))}</span></div><div class="plan-counts"><span>Create ${counts.create}</span><span>Update ${counts.update}</span><span>Remove ${counts.remove}</span><span>Already matching ${(plan.alreadyMatching || []).length}</span></div><p>Scope: ${(plan.scope?.objectIds || []).length} managed objects / ${plan.scope?.complete ? 'complete retained managed scope' : 'partial scope; removals blocked'}</p><p>Compatibility: ${plan.compatibility?.matches === false ? 'module version mismatch' : 'module prerequisites recorded'}</p><p>Provider reconciliation: ${plan.providerReconciliationRequired ? 'required separately; not changed by this local action' : 'not detected'}</p>${blocked ? `<div class="plan-blockers" role="alert"><strong>Execution blocked</strong><ul>${blockers.map((item) => `<li>${escapeHtml(item.id)} / ${escapeHtml(item.reasonCode)}</li>`).join('')}</ul></div>` : ''}<ul class="plan-mutations">${rows || '<li>No supported configuration changes are required.</li>'}</ul>${plan.capabilities?.update === false && counts.update ? '<p role="alert">Target adapter does not implement update for this profile.</p>' : ''}${plan.capabilities?.remove === false && counts.remove ? '<p role="alert">Target adapter does not implement removal for this profile.</p>' : ''}<p>Apply/reload may interrupt service. A pre-operation checkpoint is recovery information, not transactional rollback. This action does not alter routing.</p><button id="execute-local-plan" type="button" ${blocked || (plan.capabilities?.update === false && counts.update) || (plan.capabilities?.remove === false && counts.remove) ? 'disabled' : ''}>Authorize and execute this exact plan</button>`;
  $('#execute-local-plan')?.addEventListener('click', async () => {
    try {
      const result = await request('revisionExecute', {planId:plan.planId, confirmation:`execute:${plan.planId}`});
      announce(`${plan.action} execution: ${result.result?.state || result.state}`,
        !['configuration_verified'].includes(result.result?.state || result.state));
      await load();
    } catch (error) { announce(error.message, true); await load(); }
  });
}

function renderSource(source) {
  const users = Object.entries(source.users || {}).map(([number, user]) => `<tr><td>User ${escapeHtml(number)}</td><td>${escapeHtml(user.name)}</td><td>Voicemail excluded</td></tr>`).join('');
  const devices = Object.entries(source.devices || {}).map(([number, device]) => `<tr><td>PJSIP ${escapeHtml(number)}</td><td>${escapeHtml(device.authUsername)}</td><td>${escapeHtml(device.secretReference)}</td></tr>`).join('');
  const groups = (source.ringGroups || []).map((group) => `<tr><td>Ring Group ${escapeHtml(group.number)}</td><td>${escapeHtml(group.description)}</td><td>${escapeHtml(group.members.join(', '))} → ${escapeHtml(group.destination)}</td></tr>`).join('');
  $('#source').innerHTML = `<div class="identity-line"><strong>${escapeHtml(source.pbxId)}</strong><span>${escapeHtml(source.sourceAdapter)}</span></div><div class="table-scroll"><table><thead><tr><th>Object</th><th>Identity / description</th><th>Details</th></tr></thead><tbody>${users}${devices}${groups}</tbody></table></div><p class="subtle">Fixture adapter is read-only. Secret values are runtime-generated and never returned to the browser.</p>`;
}

function renderPublication(publication, preview) {
  const ack = publication?.ack;
  const blueprint = publication?.export || preview;
  if (!ack || !blueprint) {
    $('#publication').innerHTML = '<p class="empty">No accepted blueprint on this connection. Export and publish to continue.</p>';
    return;
  }
  const count = blueprint.operations?.length || 0;
  const coverage = blueprint.coverage || {};
  $('#publication').innerHTML = `<div class="metric-row"><div><span>Accepted version</span><strong>${ack.version}</strong></div><div><span>Blueprint</span><strong>${escapeHtml(ack.blueprintId)}</strong></div><div><span>Operations</span><strong>${count}</strong></div></div><p class="hash">SHA-256 ${escapeHtml(ack.hash)}</p><p>${coverage.selectedComplete ? 'Selected scope complete' : 'Coverage incomplete'} / blockers: ${(coverage.blockingGaps || []).length}</p><details><summary>Inspect published blueprint</summary><pre>${escapeHtml(JSON.stringify(blueprint, null, 2))}</pre></details>`;
}

function renderOperation(operation, submissions) {
  const host = $('#operation');
  if (!operation) { host.innerHTML = '<p class="empty">No operation is retained for this source yet.</p>'; return; }
  const dev = operation.development || {};
  const readiness = dev.readiness || {};
  const resources = (operation.resources || []).map((resource) => `<li><span>${escapeHtml(resource.kind || resource.id)}</span><b>${escapeHtml(resource.state)}</b></li>`).join('');
  const errors = (operation.errors || []).map((item) => `<p class="diagnostic-code">${escapeHtml(item.stage)} / ${escapeHtml(item.code)}</p>`).join('');
  host.innerHTML = `<div class="operation-summary"><div><span>Operation</span><strong>${escapeHtml(operation.operationId)}</strong></div><div><span>Revision</span><strong>${operation.revision}</strong></div><div><span>Identity</span><strong>${escapeHtml(operation.pbxId)}</strong></div></div><ol class="milestones">${Object.entries(operation.milestones || {}).map(([name, at]) => `<li><span>${escapeHtml(name.replaceAll('_',' '))}</span><time>${escapeHtml(at)}</time></li>`).join('')}</ol><p class="simulated-label">${escapeHtml(dev.verificationMode || 'SIMULATED')} / readiness ${escapeHtml(readiness.state || 'pending')}</p><p>${readiness.activationAllowed ? 'Manual simulated activation is available while this evidence is current.' : 'Production readiness is not asserted. Registration, calls and audio are not tested.'}</p><ul class="resource-list">${resources}</ul>${errors}`;
}

function renderTarget(operation) {
  const host = $('#target');
  if (!operation) { host.innerHTML = '<p class="empty">The replacement target has not been allocated.</p>'; return; }
  const dev = operation.development || {};
  const objects = dev.targetObjects || [];
  const order = dev.dependencyOrder || [];
  const rows = objects.map((object) => `<article class="object-row"><div><strong>${escapeHtml(object.id)}</strong><span>${object.shared ? 'shared / preserved' : escapeHtml(object.owner)}</span></div><b>${escapeHtml(object.state)}</b><details><summary>Configuration</summary><pre>${escapeHtml(JSON.stringify(object.parameters, null, 2))}</pre></details></article>`).join('');
  const leftovers = dev.preservedSharedResources || [];
  host.innerHTML = `<div class="target-meta"><strong>${escapeHtml(dev.targetId || 'Not allocated')}</strong><span>${escapeHtml(dev.replacement?.state || 'pending')}</span></div><h3>Actual executor dependency order</h3><ol class="order-list">${order.map((id) => `<li>${escapeHtml(id)}</li>`).join('')}</ol>${rows || '<p class="empty">No target object has been written.</p>'}${leftovers.length ? `<h3>Preserved foreign/shared objects</h3><ul>${leftovers.map((item) => `<li>${escapeHtml(item.id)} / ${escapeHtml(item.state)}</li>`).join('')}</ul>` : ''}`;
}

function renderRouting(operation, model) {
  const dev = operation?.development || {};
  const connectivity = dev.connectivity || {};
  const routing = dev.routing || {};
  const readiness = dev.readiness || {};
  const active = (model?.activeDestinations || {}).operationId === operation?.operationId;
  const routeState = active ? 'active' : (routing.state || 'not prepared');
  const policy = operation?.request?.activationPolicy || 'none';
  $('#routing').innerHTML = `<dl class="routing-list"><div><dt>Connectivity</dt><dd>${escapeHtml(connectivity.state || 'not prepared')} / ${escapeHtml(connectivity.mode || 'SIMULATED')}</dd></div><div><dt>Routing policy</dt><dd>${escapeHtml(routeState)} / ${escapeHtml(policy)}</dd></div><div><dt>Destination</dt><dd>${escapeHtml((model?.activeDestinations || {}).destination || routing.destination || 'source route')}</dd></div><div><dt>Readiness expires</dt><dd>${escapeHtml(readiness.expiresAt || 'no evidence')}</dd></div></dl><p class="subtle">Preparation is not activation. The call-flow probe reads persisted Ring Group objects and changes only the Local Pond routing model.</p>`;
}

function renderEvents(events) {
  $('#events').innerHTML = events.slice(-30).reverse().map((event) => `<li><time>${escapeHtml(event.at)}</time><strong>${escapeHtml(event.event.replaceAll('_',' '))}</strong><span>${escapeHtml(event.operationId || event.version || '')}</span></li>`).join('') || '<li class="empty">No events recorded.</li>';
}

function renderDiagnostics(operation, application) {
  const entries = [];
  if (operation?.errors?.length) entries.push(...operation.errors.map((error) => `${error.stage}: ${error.code}`));
  if (application.providerDiagnostic?.code) entries.push(`${application.providerDiagnostic.stage}: ${application.providerDiagnostic.code}`);
  $('#diagnostics-content').innerHTML = entries.length
    ? `<p>Sanitised diagnostic codes only:</p><ul>${entries.map((entry) => `<li>${escapeHtml(entry)}</li>`).join('')}</ul><p>Use the operation’s original connection to reconcile. Retry only the persisted original request and key.</p>`
    : '<p>Select a failure scenario before Respawn. Refresh, reconcile and retry use persisted state and the original provider connection.</p>';
}

function escapeHtml(value) {
  return text(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

function announce(message, error = false) {
  const alert = $('#alert');
  alert.hidden = false;
  alert.className = 'alert' + (error ? ' alert-error' : '');
  alert.textContent = message;
}

async function run(action, details = {}) {
  if (busy) return;
  busy = true;
  try {
    const result = await request(action, details);
    if (result?.error) {
      announce(`${result.error.code}: ${result.error.message} ${result.error.nextAction}`, true);
      await load();
      return;
    }
    announce(result?.state ? `${action}: ${result.state}` : `${action} submitted.`);
    if (action === 'export') sourcePreview = result;
    if (action === 'respawn') localStorage.setItem(`respawn-key:${$('#pbx').value}:${$('#connection').value}`, details.idempotencyKey);
    await load();
  } catch (error) { announce(error.message, true); await load(); }
  finally { busy = false; }
}

document.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => run(button.dataset.action)));
$('#respawn').addEventListener('click', () => {
  const keyName = `respawn-key:${$('#pbx').value}:${$('#connection').value}`;
  const key = localStorage.getItem(keyName) || `local-demo-${crypto.randomUUID().replaceAll('-','')}`;
  run('respawn', { idempotencyKey: key, scenario: $('#scenario').value });
});
$('#refresh').addEventListener('click', load);
$('#pbx').addEventListener('change', load);
$('#connection').addEventListener('change', load);
$('#intent').addEventListener('change', () => { if ($('#intent').value !== 'recovery') $('#activation-policy').value = 'none'; load(); });
$('#cancel').addEventListener('click', () => currentOperation && run('cancel', { operationId: currentOperation.operationId }));
$('#activate').addEventListener('click', () => currentOperation && run('activate', { operationId: currentOperation.operationId, revision: currentOperation.revision }));
$('#teardown').addEventListener('click', () => currentOperation && run('teardown', { operationId: currentOperation.operationId,
  routingDisposition: currentOperation.state === 'active' ? 'operator-confirmed-deactivate' : null }));
$('#reconcile').addEventListener('click', () => {
  const submission = Object.values(snapshot?.application?.localSubmissions || {}).at(-1);
  if (submission) run('reconcileSubmission', { submissionId: submission.submissionId });
});
$('#retry').addEventListener('click', () => {
  const submission = Object.values(snapshot?.application?.localSubmissions || {}).at(-1);
  if (submission) run('retrySubmission', { submissionId: submission.submissionId });
});
$('#probe').addEventListener('click', async () => {
  try {
    const result = await request('routingProbe', { ringGroup: $('#probe-group').value });
    $('#probe-result').hidden = false;
    $('#probe-result').textContent = JSON.stringify(result, null, 2);
  } catch (error) { announce(error.message, true); }
});
$('#capture-revision').addEventListener('click', async () => {
  try { const result = await request('revisionCapture'); announce(`Captured ${result.revisionId}: ${result.classification}`); await load(); }
  catch (error) { announce(error.message, true); }
});
$('#capture-sample-b').addEventListener('click', async () => {
  try { const result = await request('sampleRevisionB'); announce(`Sample B: ${result.result?.state || result.state}`,
    (result.result?.state || result.state) !== 'configuration_verified'); await load(); }
  catch (error) { announce(error.message, true); }
});
$('#introduce-drift').addEventListener('click', async () => {
  try { const result = await request('introduceDrift'); announce(`${result.state}: ${result.objectId} ${result.field}=${result.newValue}`); await load(); }
  catch (error) { announce(error.message, true); }
});
$('#plan-revert').addEventListener('click', async () => {
  try { showPlan(await request('revisionPlan', {localAction:'revert',revisionId:selectedRevisionId()})); }
  catch (error) { showPlan({error:{code:error.message}}); }
});
$('#plan-regenerate').addEventListener('click', async () => {
  try { showPlan(await request('revisionPlan', {localAction:'regenerate',revisionId:selectedRevisionId()})); }
  catch (error) { showPlan({error:{code:error.message}}); }
});
$('#compare-revisions').addEventListener('click', async () => {
  try {
    const revisions = snapshot?.localRecovery?.revisions || [];
    const heads = snapshot?.localRecovery?.heads || {};
    const to = selectedRevisionId();
    const selected = revisions.find((revision) => revision.revisionId === to);
    const from = selected?.parentRevisionId || heads.lastVerifiedRevisionId || revisions.at(-1)?.revisionId;
    if (!from || !to) throw new Error('Capture at least one revision before comparing.');
    const result = await request('revisionCompare', {fromRevision:from,toRevision:to});
    $('#plan-preview').innerHTML = `<div class="plan-summary"><span>REVISION COMPARISON</span><span>${escapeHtml(result.fromRevisionId)} → ${escapeHtml(result.toRevisionId)}</span></div><pre>${escapeHtml(JSON.stringify(result.changes,null,2))}</pre><p>${escapeHtml(result.message)}</p>`;
  } catch (error) { announce(error.message, true); }
});
$('#revision-select').addEventListener('change', () => { $('#plan-preview').innerHTML='<p class="empty">Revision changed. Preview the action again.</p>'; });
load();
window.setInterval(load, 1500);