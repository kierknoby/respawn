#!/usr/bin/env python3
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import hashlib
import tempfile
import time
import xml.etree.ElementTree as ET
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
VERSION = ET.parse(ROOT / 'module/module.xml').getroot().findtext('version')
LOCAL = ROOT / 'development/local-pond'
ARTIFACTS = ROOT / 'artifacts/screenshots'


def free_port(excluded=()):
    while True:
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        if port not in excluded:
            return port


class Demo:
    def __init__(self, root, pond_port, dashboard_port):
        self.root = root
        self.pond_port = pond_port
        self.dashboard_port = dashboard_port
        self.base = f'http://127.0.0.1:{dashboard_port}'
        self.cookiejar = http.cookiejar.CookieJar()
        self.http = build_opener(HTTPCookieProcessor(self.cookiejar))
        self.csrf = None

    def state(self, pbx='pbx-alpha', connection='primary'):
        query = urlencode({'pbx': pbx, 'connection': connection})
        try:
            with self.http.open(self.base + '/api/state?' + query, timeout=5) as response:
                result = json.loads(response.read())
        except HTTPError as error:
            payload = json.loads(error.read())
            diagnostic = payload.get('error', {})
            raise RuntimeError(f'HTTP {error.code} {diagnostic.get("code", "UNKNOWN")}: {diagnostic.get("message", "")}'.strip()) from None
        self.csrf = result['csrf']
        return result

    def action(self, action, pbx='pbx-alpha', connection='primary', **details):
        if self.csrf is None:
            self.state(pbx, connection)
        body = json.dumps({'action': action, 'pbx': pbx, 'connection': connection,
            'intent': 'recovery', 'activationPolicy': 'manual', **details}).encode()
        request = Request(self.base + '/api/action', data=body, headers={
            'Content-Type': 'application/json', 'X-Respawn-CSRF': self.csrf})
        try:
            with self.http.open(request, timeout=10) as response:
                return json.loads(response.read())
        except HTTPError as error:
            return json.loads(error.read())

    def operation(self, operation_id=None, pbx='pbx-alpha', connection='primary'):
        state = self.state(pbx, connection)
        operations = state['demo']['operations']
        if operation_id:
            return next((operation for operation in operations if operation['operationId'] == operation_id), None), state
        return (operations[-1] if operations else None), state

    def wait_operation(self, operation_id, states, pbx='pbx-alpha', connection='primary', timeout=20):
        deadline = time.monotonic() + timeout
        last = None
        while time.monotonic() < deadline:
            try:
                last, _ = self.operation(operation_id, pbx, connection)
                if last and last['state'] in states:
                    return last
            except (URLError, TimeoutError):
                pass
            time.sleep(0.05)
        raise AssertionError(f'operation {operation_id} did not reach {states}; last={last}')

    def rpc(self, method, pbx, connection, parameters):
        state = self.state(pbx, connection)
        connection_id = state['demo']['provider']['connectionId']
        token = (self.root / 'credentials' / f'{pbx}.token').read_text().strip()
        path = '/alternate/rpc' if connection == 'alternate' else '/rpc'
        envelope = {'schema': 'respawn.provider-rpc.v2', 'connectionId': connection_id,
            'method': method, 'pbxId': pbx, 'parameters': parameters}
        request = Request(f'http://127.0.0.1:{self.pond_port}{path}', data=json.dumps(envelope).encode(), headers={
            'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
        try:
            with self.http.open(request, timeout=5) as response:
                return json.loads(response.read())['result']
        except HTTPError as error:
            raise AssertionError(f'{method} RPC failed: HTTP {error.code}: {error.read().decode(errors="replace")}') from error

    def rejected_rpc(self, envelope, token):
        request = Request(f'http://127.0.0.1:{self.pond_port}/rpc', data=json.dumps(envelope).encode(), headers={
            'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
        try:
            self.http.open(request, timeout=5)
        except HTTPError as error:
            return error.code, json.loads(error.read())
        raise AssertionError('invalid Development Pond RPC request was accepted')


def wait_dashboard(demo, timeout=10):
    deadline = time.monotonic() + timeout
    last_error = ''
    while time.monotonic() < deadline:
        try:
            demo.state()
            return
        except Exception as error:
            last_error = str(error)
            time.sleep(0.05)
    logs = {name: (demo.root / 'logs' / f'{name}.log').read_text()[-3000:]
        for name in ['pond','dashboard','worker'] if (demo.root / 'logs' / f'{name}.log').exists()}
    raise AssertionError(f'dashboard did not become available: {last_error}; logs={logs}')


def verify_loopback_contract(demo):
    state = demo.state()
    provider = state['demo']['provider']
    token = (demo.root / 'credentials/pbx-alpha.token').read_text().strip()
    envelope = {'schema': 'respawn.provider-rpc.v2', 'connectionId': provider['connectionId'],
        'method': 'status', 'pbxId': 'pbx-alpha', 'parameters': {}}
    status, body = demo.rejected_rpc(envelope, '0' * 64)
    assert status == 401 and body['error']['code'] == 'LOCAL_POND_AUTH_FAILED'
    wrong_connection = dict(envelope, connectionId='local-pond-dev-v1:' + '0' * 64)
    status, body = demo.rejected_rpc(wrong_connection, token)
    assert status == 409 and body['error']['code'] == 'PROVIDER_CONNECTION_IDENTITY_MISMATCH'
    unknown_parameter = dict(envelope, parameters={'callerSupplied': 'ignored-data-is-not-allowed'})
    status, body = demo.rejected_rpc(unknown_parameter, token)
    assert status == 409 and body['error']['code'] == 'PROVIDER_REQUEST_INVALID'
    code = ('require ' + repr(str(LOCAL / 'bootstrap.php')) + '; try { new \\Respawn\\LocalPond\\LocalPondProvider('
        + "['endpoint'=>'http://0.0.0.0:8731/rpc','credentialRef'=>'/unused','sourcePbxId'=>'pbx-alpha']); exit(1);"
        + '} catch (RuntimeException $e) { exit(0); }')
    result = subprocess.run(['php', '-r', code], cwd=ROOT, capture_output=True)
    assert result.returncode == 0, result.stderr.decode(errors='replace')
    export_code = ('require ' + repr(str(LOCAL / 'bootstrap.php')) + '; $service = new \\Respawn\\LocalPond\\LocalRecoveryService('
        + 'getenv("RESPAWN_LOCAL_DEMO_ROOT"), "pbx-alpha"); echo json_encode($service->exportBlueprint(), JSON_THROW_ON_ERROR);')
    exported = subprocess.run(['php', '-r', export_code], cwd=ROOT,
        env=dict(os.environ, RESPAWN_LOCAL_DEMO_ROOT=str(demo.root)), capture_output=True)
    assert exported.returncode == 0, exported.stderr.decode(errors='replace')
    blueprint = json.loads(exported.stdout)
    revision_id = 'rev-' + 'd' * 32
    idempotency_key = hashlib.sha256(('pbx-alpha:' + revision_id + ':' + blueprint['contentHash']).encode()).hexdigest()
    retained = demo.rpc('retainRevision', 'pbx-alpha', 'primary', {
        'pbxId': 'pbx-alpha', 'revisionId': revision_id, 'contentHash': blueprint['contentHash'],
        'payload': blueprint, 'idempotencyKey': idempotency_key})
    assert retained['state'] == 'durably_retained' and retained['ack']['revisionId'] == revision_id
    assert retained['ack']['hash'] == blueprint['contentHash'] and retained['ack']['mode'] == 'SIMULATED'
    recovered = demo.rpc('retrieveRevision', 'pbx-alpha', 'primary', {'pbxId': 'pbx-alpha', 'revisionId': revision_id})
    assert recovered['blueprint'] == blueprint and recovered['publicationId'] == retained['ack']['publicationId']
    print('PASS: authenticated Development Pond retain/retrieve returns the exact revision (SIMULATED; not external storage)')
    print('PASS: loopback-only configuration, bearer enrolment, endpoint-bound connection ID and strict v2 parameters')


def start(env):
    result = subprocess.run(['sh', str(LOCAL / 'bin/start.sh')], cwd=ROOT, env=env,
        check=True, text=True, capture_output=True)
    assert 'DEVELOPMENT ONLY' in result.stdout or 'Browser:' in result.stdout
    return result


def stop(env):
    subprocess.run(['sh', str(LOCAL / 'bin/stop.sh')], cwd=ROOT, env=env,
        check=False, text=True, capture_output=True)


def browser_happy_path(demo):
    ARTIFACTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as playwright:
        environment = dict(os.environ)
        local_libraries = ROOT / 'build/browser-deps/root/usr/lib/x86_64-linux-gnu'
        if local_libraries.exists():
            environment['LD_LIBRARY_PATH'] = str(local_libraries) + (
                ':' + environment['LD_LIBRARY_PATH'] if environment.get('LD_LIBRARY_PATH') else '')
        browser = playwright.chromium.launch(env=environment)
        page = browser.new_page(viewport={'width': 1440, 'height': 1000})
        errors = []
        api_responses = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('response', lambda response: api_responses.append((response.url, response.status)) if '/api/' in response.url else None)
        page.goto(demo.base + '/', wait_until='networkidle')
        page.get_by_role('button', name='Capture supported state').click()
        page.wait_for_function("document.querySelectorAll('.revision-row').length === 1")
        revision_a = page.locator('#revision-select').input_value()
        assert revision_a.startswith('rev-')
        page.get_by_role('button', name='Introduce supported drift').click()
        page.get_by_role('button', name='Preview Regenerate').click()
        try:
            page.wait_for_function("document.querySelector('#plan-preview').textContent.length > 0 && !document.querySelector('#plan-preview').textContent.includes('Select a revision')", timeout=7000)
        except Exception as error:
            raise AssertionError(f'Regenerate preview did not settle; plan={page.locator("#plan-preview").inner_text()}; alert={page.locator("#alert").inner_text()}; errors={errors}') from error
        assert 'UPDATE' in page.locator('#plan-preview').inner_text(), page.locator('#plan-preview').inner_text()
        assert 'pjsip:1001' in page.locator('#plan-preview').inner_text()
        page.get_by_role('button', name='Authorize and execute this exact plan').click()
        page.wait_for_function("document.querySelector('#alert').textContent.includes('configuration_verified')")
        page.get_by_role('button', name='Apply sample revision B').click()
        page.wait_for_function("document.querySelectorAll('.revision-row').length === 2")
        page.wait_for_function("document.querySelector('#alert').textContent.includes('Sample B: configuration_verified')")
        revisions = page.locator('#revision-select option').evaluate_all('(items) => items.map(item => ({value:item.value,text:item.textContent}))')
        revision_b = next(item['value'] for item in revisions if item['value'] != revision_a)
        page.locator('#revision-select').select_option(revision_b)
        page.get_by_role('button', name='Compare revisions').click()
        page.wait_for_function("document.querySelector('#plan-preview').textContent.includes('REVISION COMPARISON')")
        comparison = json.loads(page.locator('#plan-preview pre').inner_text())
        assert 'ringgroup:601' in comparison['remove'] and 'pjsip:1002' in comparison['remove']
        page.locator('#revision-select').select_option(revision_a)
        page.get_by_role('button', name='Preview Revert').click()
        page.wait_for_function("document.querySelector('#plan-preview').textContent.includes('REVERT')")
        assert 'ringgroup:601' in page.locator('#plan-preview').inner_text()
        page.get_by_role('button', name='Authorize and execute this exact plan').click()
        page.wait_for_function("document.querySelector('#alert').textContent.includes('configuration_verified')")
        page.get_by_role('button', name='Export blueprint').click()
        try:
            page.wait_for_function("document.querySelector('#alert').textContent.includes('export submitted') || document.querySelector('#alert').textContent.includes('ACTION_FAILED')", timeout=7000)
        except Exception as error:
            raise AssertionError(f'export action did not settle; alert={page.locator("#alert").inner_text()!r}; api={api_responses}; pageErrors={errors}') from error
        if 'export submitted' not in page.locator('#alert').inner_text():
            code = ('require ' + repr(str(LOCAL / 'bootstrap.php')) + '; try { $s = new \\Respawn\\LocalPond\\LocalRecoveryService(getenv("RESPAWN_LOCAL_DEMO_ROOT"), "pbx-alpha"); '
                + '$b = $s->exportBlueprint(); echo $b["contentHash"]; } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }')
            probe = subprocess.run(['php','-r',code], cwd=ROOT, env=dict(os.environ, RESPAWN_LOCAL_DEMO_ROOT=str(demo.root)), capture_output=True)
            assert False, f'{page.locator("#alert").inner_text()}; local source export probe={probe.stderr.decode(errors="replace")}'
        page.get_by_role('button', name='Publish to Development Pond').click()
        page.wait_for_function("document.querySelector('#publication').textContent.includes('Accepted version')")
        page.get_by_role('button', name=re.compile('Respawn selected source')).click()
        try:
            page.wait_for_function("['routing prepared','failed'].includes(document.querySelector('#operation-state').textContent)", timeout=10000)
        except Exception as error:
            current = demo.state()
            worker_log = (demo.root / 'logs/worker.log').read_text()[-4000:]
            pond_state_path = demo.root / 'pond-state/state.json'
            pond_state = json.loads(pond_state_path.read_text()) if pond_state_path.exists() else {}
            pid_path = demo.root / 'processes/worker.pid'
            pid = pid_path.read_text().strip() if pid_path.exists() else ''
            command_line = Path(f'/proc/{pid}/cmdline').read_bytes().replace(b'\0', b' ').decode(errors='replace') if pid.isdigit() and Path(f'/proc/{pid}/cmdline').exists() else 'not running'
            raise AssertionError(f'worker did not finish; operation={json.dumps(current["demo"]["operations"][-1:] or [])}; jobs={json.dumps(pond_state.get("jobs",{}))}; process={command_line}; worker={worker_log}; api={api_responses}; pageErrors={errors}') from error
        badge = page.locator('#operation-state').inner_text().lower()
        if badge != 'routing prepared':
            current = demo.state()
            raise AssertionError(f'operation badge={badge}; pond={[item["state"] for item in current["demo"]["operations"]]}; app={[item["state"] for item in current["application"].get("operations",[])]}; providerDiagnostic={current["application"].get("providerDiagnostic")}; panel={page.locator("#operation").inner_text()}')
        page.wait_for_function("document.querySelector('#activate').disabled === false")
        page.locator('#probe').click()
        page.wait_for_function("document.querySelector('#probe-result').textContent.length > 0")
        # Before activation the model must retain the source route.
        probe = json.loads(page.locator('#probe-result').inner_text())
        assert probe['state'] == 'source_route_retained'
        page.locator('#activate').click()
        page.wait_for_function("document.querySelector('#operation-state').textContent === 'active'")
        page.locator('#probe').click()
        page.wait_for_function("document.querySelector('#probe-result').textContent.includes('model_route_resolved')")
        probe = json.loads(page.locator('#probe-result').inner_text())
        assert probe['mode'] == 'ROUTING-MODEL TEST' and probe['extensionsAttempted'] == ['1001','1002']
        assert probe['simulatedPrimaryOutcome'] == 'no_answer'
        assert probe['fallbackRingGroup'] == '601' and probe['selectedExtension'] == '1002'
        content = page.content()
        for secret in (demo.root / 'credentials/source-secrets').glob('*.secret'):
            assert secret.read_text().strip() not in content, 'raw synthetic credential appeared in browser content'
        for token in (demo.root / 'credentials').glob('*.token'):
            assert token.read_text().strip() not in content, 'provider token appeared in browser content'
        assert not errors, errors
        page.screenshot(path=str(ARTIFACTS / 'local-pond-active-desktop.png'), full_page=True)
        page.reload(wait_until='networkidle')
        page.wait_for_function("document.querySelector('#operation-state').textContent === 'active'")
        page.set_viewport_size({'width': 390, 'height': 844})
        page.reload(wait_until='networkidle')
        page.wait_for_function("document.querySelector('#operation-state').textContent === 'active'")
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.screenshot(path=str(ARTIFACTS / 'local-pond-active-mobile.png'), full_page=True)
        page.locator('#teardown').click()
        page.wait_for_function("document.querySelector('#operation-state').textContent === 'torn down'")
        torn_down, final_state = demo.operation()
        assert torn_down['development']['targetObjects'] == []
        assert torn_down['development']['remainingResources'] == []
        assert final_state['demo']['routingModel']['activeDestinations'] == []
        source_route = demo.action('routingProbe', 'pbx-alpha', 'primary', ringGroup='600')
        assert source_route['result']['state'] == 'source_route_retained'
        assert not errors, errors
        browser.close()


def main():
    root = Path(tempfile.mkdtemp(prefix='respawn-local-demo-integration-'))
    os.chmod(root, 0o700)
    pond_port = free_port()
    dashboard_port = free_port([pond_port])
    env = dict(os.environ, RESPAWN_LOCAL_DEMO_ROOT=str(root),
        RESPAWN_LOCAL_POND_PORT=str(pond_port), RESPAWN_LOCAL_DASHBOARD_PORT=str(dashboard_port), XDEBUG_MODE='off')
    demo = Demo(root, pond_port, dashboard_port)
    try:
        start(env)
        wait_dashboard(demo)
        initial = demo.state()
        assert initial['capabilities']['authorised'] is True
        assert 'reconstruction.v2' in initial['capabilities']['capabilities']
        verify_loopback_contract(demo)
        if '--contracts-only' in sys.argv:
            return
        browser_happy_path(demo)

        alpha, state = demo.operation(pbx='pbx-alpha')
        assert alpha['development']['verificationMode'] == 'SIMULATED'
        assert alpha['state'] == 'torn_down'
        assert len(alpha['development']['dependencyOrder']) == 6
        assert alpha['development']['apply']['state'] == 'succeeded'
        assert alpha['development']['reconstruction']['savedConfigurationVerified'] is True
        assert not any(key in alpha for key in ['verification','authorityEpoch','activationReceipt','readinessAssessment'])
        request = alpha['request']
        duplicate_a = demo.rpc('launch', 'pbx-alpha', 'primary', request)
        duplicate_b = demo.rpc('launch', 'pbx-alpha', 'primary', request)
        assert duplicate_a['operationId'] == duplicate_b['operationId'] == alpha['operationId']
        print('PASS: browser export/publish/Respawn/reconstruction/apply/activation/model-probe/teardown')
        print('PASS: duplicate launch idempotency and no production readiness receipts')

        # A second source identity must not see Alpha's operation or publication.
        bravo_state = demo.state('pbx-bravo', 'primary')
        assert bravo_state['demo']['operations'] == [] and bravo_state['demo']['publication'] is None
        demo.action('publish', 'pbx-bravo', 'primary')
        bravo_launch = demo.action('respawn', 'pbx-bravo', 'primary', idempotencyKey='local-demo-bravo-001')
        bravo_id = bravo_launch['result']['operationId']
        bravo = demo.wait_operation(bravo_id, {'routing_prepared'}, 'pbx-bravo')
        assert bravo['pbxId'] == 'pbx-bravo' and 'user:2001' in bravo['development']['dependencyOrder']
        bravo_done = demo.action('teardown', 'pbx-bravo', 'primary', operationId=bravo_id)
        assert bravo_done['result']['state'] == 'torn_down'
        print('PASS: two source PBXs remain identity- and operation-scoped')

        # A changed endpoint path creates a separate provider connection.
        primary_id = state['demo']['provider']['connectionId']
        alternate_state = demo.state('pbx-alpha', 'alternate')
        alternate_id = alternate_state['demo']['provider']['connectionId']
        assert primary_id != alternate_id
        assert len(alternate_state['application']['connections']) >= 2
        demo.action('publish', 'pbx-alpha', 'alternate')
        print('PASS: connection profile change retains the original connection record')

        # Remote acceptance with a dropped response survives a browser-session loss.
        lost = demo.action('respawn', 'pbx-alpha', 'alternate', idempotencyKey='local-demo-lost-response-01', scenario='accepted-response-lost')
        assert lost['result']['state'] == 'unknown'
        new_session = Demo(root, pond_port, dashboard_port)
        recovered_state = new_session.state('pbx-alpha', 'alternate')
        pending = list(recovered_state['application']['localSubmissions'].values())
        lost_submission = next(entry for entry in pending if entry.get('idempotencyKey') == 'local-demo-lost-response-01')
        assert lost_submission['state'] == 'launch_outcome_unknown'
        retried = new_session.action('retrySubmission', 'pbx-alpha', 'alternate', submissionId=lost_submission['submissionId'])
        operation_id = retried['result']['operationId']
        reconciled = new_session.action('reconcileSubmission', 'pbx-alpha', 'alternate', submissionId=lost_submission['submissionId'])
        assert reconciled['result']['state'] == 'authoritative_response_received'
        assert reconciled['result']['operationId'] == operation_id
        assert len(demo.state('pbx-alpha', 'alternate')['demo']['operations']) == 1
        demo.wait_operation(operation_id, {'routing_prepared'}, connection='alternate')
        reconciled_twice = new_session.action('reconcileSubmission', 'pbx-alpha', 'alternate', submissionId=lost_submission['submissionId'])
        assert reconciled_twice['result']['operationId'] == operation_id
        demo.action('teardown', 'pbx-alpha', 'alternate', operationId=operation_id)
        print('PASS: accepted/lost response reconciles after a new browser session without duplicate launch')

        def failed_scenario(name, key):
            launched = demo.action('respawn', 'pbx-alpha', 'alternate', idempotencyKey=key, scenario=name)
            operation_id = launched['result']['operationId']
            operation = demo.wait_operation(operation_id, {'failed','routing_prepared'}, connection='alternate')
            if name == 'expired-readiness':
                assert operation['state'] == 'routing_prepared' and operation['development']['readiness']['activationAllowed'] is False
                refusal = demo.action('activate', 'pbx-alpha', 'alternate', operationId=operation_id, revision=operation['revision'])
                assert refusal['result']['error']['code'] == 'READINESS_EXPIRED'
            elif name in {'shared-resource','partial-teardown'}:
                assert operation['state'] == 'routing_prepared'
            else:
                assert operation['state'] == 'failed', (name, operation['state'], operation.get('errors'))
            teardown = demo.action('teardown', 'pbx-alpha', 'alternate', operationId=operation_id)
            assert teardown['result']['state'] in {'torn_down','teardown_partial'}
            if name == 'partial-teardown':
                assert teardown['result']['state'] == 'teardown_partial'
            if teardown['result']['state'] == 'teardown_partial':
                retry = demo.action('teardown', 'pbx-alpha', 'alternate', operationId=operation_id)
                assert retry['result']['state'] == 'torn_down'
            return operation

        conflict = failed_scenario('target-conflict', 'local-demo-conflict-0001')
        assert any(error['code'] == 'TARGET_CONFIGURATION_CONFLICT' for error in conflict['errors']), conflict['errors']
        secret = failed_scenario('unresolved-secret', 'local-demo-secret-00001')
        assert any(error['code'] == 'SECRET_MANIFEST_MISSING' for error in secret['errors'])
        apply = failed_scenario('apply-failure', 'local-demo-apply-fail-001')
        assert any(error['code'] == 'SIMULATED_APPLY_FAILED' for error in apply['errors'])
        expired = failed_scenario('expired-readiness', 'local-demo-expired-0001')
        assert expired['development']['readiness']['state'] == 'SIMULATED_ONLY'
        shared = failed_scenario('shared-resource', 'local-demo-shared-0001')
        shared, _ = demo.operation(shared['operationId'], connection='alternate')
        assert any(item['shared'] for item in shared['development'].get('preservedSharedResources', []))
        partial = failed_scenario('partial-teardown', 'local-demo-partial-0001')
        assert partial['state'] == 'routing_prepared'
        print('PASS: conflict, unresolved secret, apply failure, expired simulated evidence, partial cleanup and shared preservation')

        # Cancellation is issued at the real post-provisioning worker checkpoint.
        cancelled_launch = demo.action('respawn', 'pbx-alpha', 'alternate',
            idempotencyKey='local-demo-cancel-checkpoint', scenario='cancel-during-work')
        cancelled_id = cancelled_launch['result']['operationId']
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            cancelled, _ = demo.operation(cancelled_id, connection='alternate')
            if cancelled and cancelled['development'].get('workerState') == 'paused_at_operator_cancellation_checkpoint': break
            time.sleep(0.05)
        assert cancelled and cancelled['state'] == 'provisioning'
        cancelled_result = demo.action('cancel', 'pbx-alpha', 'alternate', operationId=cancelled_id)
        assert cancelled_result['result']['state'] == 'cancelled'
        assert demo.action('teardown', 'pbx-alpha', 'alternate', operationId=cancelled_id)['result']['state'] == 'torn_down'
        print('PASS: cancellation during work is handled at a persisted worker checkpoint')

        # Kill the worker after its first real target mutation; restart all named
        # services and prove BuildExecutor resumes from its durable target/journal.
        interrupted_launch = demo.action('respawn', 'pbx-alpha', 'alternate',
            idempotencyKey='local-demo-worker-interrupt', scenario='worker-interruption')
        interrupted_id = interrupted_launch['result']['operationId']
        deadline = time.monotonic() + 10
        interrupted = None
        while time.monotonic() < deadline:
            interrupted, _ = demo.operation(interrupted_id, connection='alternate')
            if interrupted and interrupted['development'].get('workerInterruptionInjected'): break
            time.sleep(0.05)
        assert interrupted and interrupted['state'] == 'configuration'
        stop(env)
        start(env)
        wait_dashboard(demo)
        recovered = demo.wait_operation(interrupted_id, {'routing_prepared'}, connection='alternate')
        assert recovered['development']['reconstruction']['state'] == 'configuration_verified'
        assert len(recovered['development']['dependencyOrder']) == 6
        demo.action('teardown', 'pbx-alpha', 'alternate', operationId=interrupted_id)
        print('PASS: Development Pond/worker restart recovers interrupted reconstruction without duplicate objects')

        stop(env)
        unrelated = root / 'operator-note.txt'
        unrelated.write_text('preserve this unrelated runtime-root file\n')
        prior_token = (root / 'credentials/pbx-alpha.token').read_text()
        reset_env = dict(env, RESPAWN_LOCAL_DEMO_ROOT=str(root))
        reset = subprocess.run(['php', str(LOCAL / 'bin/reset.php'), '--confirm=LOCAL-DEMO-RESET'],
            cwd=ROOT, env=reset_env, check=True, text=True, capture_output=True)
        assert 'Reset only the marker-owned' in reset.stdout
        assert unrelated.read_text() == 'preserve this unrelated runtime-root file\n'
        assert (root / 'credentials/pbx-alpha.token').read_text() != prior_token
        assert not (root / 'pond-state/state.json').exists()
        print('PASS: targeted reset rotates credentials, clears owned state and preserves unrelated files')

        # Verify every generated credential is absent from distribution packages.
        secret_values = [path.read_text().strip() for path in (root / 'credentials').glob('*.token')]
        secret_values += [path.read_text().strip() for path in (root / 'credentials/source-secrets').glob('*.secret')]
        for archive_path in [ROOT / 'artifacts' / f'respawn-{VERSION}.tgz', ROOT / 'artifacts' / f'respawn-site-{VERSION}.zip']:
            raw = archive_path.read_bytes()
            assert all(value.encode() not in raw for value in secret_values)
            assert b'LocalPondProvider.php' not in raw and b'development/local-pond' not in raw
        print('PASS: generated credentials and Development Pond implementation are excluded from both packages')
        print('PASS: screenshots: artifacts/screenshots/local-pond-active-{desktop,mobile}.png')
    finally:
        stop(env)
        marker = root / '.respawn-local-demo'
        if marker.is_file():
            subprocess.run(['php', str(LOCAL / 'bin/reset.php'), '--confirm=LOCAL-DEMO-RESET'],
                cwd=ROOT, env=env, check=False, capture_output=True)
            for name in ['operator-note.txt', '.respawn-local-demo']:
                path = root / name
                if path.is_file() and not path.is_symlink(): path.unlink()
            try: root.rmdir()
            except OSError: pass
        else:
            try: root.rmdir()
            except OSError: pass


if __name__ == '__main__':
    main()