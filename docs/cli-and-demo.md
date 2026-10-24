# CLI And Two-PBX Demonstration

## Local Synthetic Checks

From repository root:

```sh
XDEBUG_MODE=off php tests/run.php
XDEBUG_MODE=off php tools/demo.php
python3 tests/measurement.py
```

The demo exports distinct simulated source users/auth identities/PJSIP devices and
groups into v2 blueprints. Separate clean targets resolve the same runtime-only
credentials, reconcile a lost mutation response, apply once and compare back to
source intent. Conflict and cleanup checks preserve pre-existing/shared records.
Both sources also publish, recover duplicate launches and execute the actual
planner/executor inside mock orchestration before simulated readiness and cleanup.
It writes only synthetic evidence under `artifacts/demo/` and never makes network
calls. The test suite adds forced publication, outages/retries, activation guards,
partial failure/cleanup and delayed old teardown. These are not two real PBXs.

## Development Pond End-To-End Demonstration

This is a separate, real-process local HTTP demonstration. Exact start, browser,
stop and marker-scoped reset commands are in
[the Development Pond runbook](../development/local-pond/README.md). From the repository
root, run `sh development/local-pond/bin/start.sh`; it prints the browser URL. The
interface drives `Application` and the development-only `LocalPondProvider` over authenticated loopback
HTTP, then a persistent worker executes the actual v2 reconstruction engine. The
replacement and routing are simulated; no local demo evidence is production call,
audio or provider readiness. `XDEBUG_MODE=off python3 tests/local-pond.py` starts
its own isolated service processes and verifies the browser flow, scenarios,
reconciliation/retry, restart and teardown.

## Local Revision Actions

The CLI also exposes `revision-capture`, `revision-list`, `revision-show`,
`revision-compare`, `revision-retention`, `revert-plan`, `regenerate-plan`,
`revert-execute` and `regenerate-execute`. Capture stores the current supported
profile; comparison and planning are local and do not contact the provider. A
plan is bound to the selected revision, current target snapshot and exact
capabilities. Execution requires the exact plan confirmation and is not a general
rollback.

The synthetic target executes tested Revert and Regenerate changes. The native
FreePBX adapter currently advertises no update, removal or Revert capability and
marks ownership unverified. Revision CLI planning/execution supplies no native
apply callback, so native plans requiring those capabilities remain blocked. Do
not interpret a successful preview or synthetic test as native repair. Respawn is
separate: it requests provider-controlled replacement work and later routing
activation under explicit policy.

## Automatic Protection Reconciliation

The module provides `fwconsole respawn protection-status` (read-only local state)
and `fwconsole respawn protection-reconcile` (one bounded reconciliation pass).
Protection is disabled by default. Configure provider mode, the supported group
selection and protected source manifest in the authenticated GUI, enable
protection, then arrange a host-managed cron entry such as:

```cron
*/5 * * * * asterisk /usr/sbin/fwconsole respawn protection-reconcile
```

The module does not install or register this job. Use the host's approved service
account and actual `fwconsole` path. The command checks the persisted interval,
uses a target-scoped lock, captures the exact selected blueprint, queues it for
provider retention, validates durable acknowledgement, retrieves the exact
revision, and verifies its hash. Publication retries use a stable key and bounded
backoff; local status distinguishes observed, local, submitted, provider-acknowledged,
locally acknowledged and retrieval-verified revisions. A non-current status exits
nonzero so the scheduler can alert. No successful state implies recoverable
credentials or full PBX restoration.

This workflow currently has only synthetic retention implementations. The HTTPS
adapter defines `retainRevision`/`retrieveRevision`, but no external compatible
controller is provided. `MockProvider` and the Development Pond are development-only. Do not
enable a real system schedule against them or describe their receipts as off-site
protection. A real HTTPS provider also requires the FreePBX CLI PHP runtime's cURL
extension and a protected bearer-credential file; no live provider test is in this
repository.

## Standalone Fixture CLI

```sh
export RESPAWN_FIXTURE_MODE=1
export RESPAWN_STATE_DIR=/tmp/respawn-disposable-alpha
export RESPAWN_PBX_ID=pbx-alpha
php tools/respawn.php export firewall
php tools/respawn.php publish firewall
php tools/respawn.php publish firewall --force
php tools/respawn.php publish blueprint
php tools/respawn.php respawn --key=deliberate-rehearsal-request-001
php tools/respawn.php status
php tools/respawn.php operation --id=OPERATION_ID
php tools/respawn.php advance --id=OPERATION_ID
php tools/respawn.php cancel --id=OPERATION_ID
php tools/respawn.php teardown --id=OPERATION_ID
```

Repeat advance explicitly until ready; no timer simulates infrastructure evidence.
Use a second private directory and pbx-bravo for an independent standalone run,
or `tools/demo.php` to demonstrate shared mock-provider identity separation.
Reusing a key after completion retrieves the old operation. A deliberate new launch
needs a new key after cleanup. Preserve the original key after an interrupted launch.

`RESPAWN_INTENT=recovery` with `RESPAWN_ACTIVATION=manual` permits mock manual
activation using `activate --id=ID --revision=N --confirmation=activate:ID`.
Active cleanup requires `--disposition=operator-confirmed-deactivate`; it does not
fail back to the source. Rehearsal/standby require activation none.

## FreePBX CLI

Use `fwconsole respawn ACTION [firewall|blueprint]` with the same operation options.
`module/bin/respawn` is an optional thin wrapper around `/usr/sbin/fwconsole`;
verify that path on the actual host and invoke with `sh` or install it executable
using the host's approved packaging procedure. It is not automatically installed
into a global path. GUI and CLI share `Application`.

Actions: status, verify, capabilities, export, blueprint, publish, respawn,
operation, reconcile, cancel, teardown, activate and mock-only advance. V2 native
export/execution uses export-reconstruction, non-mutating plan and reconstruct
with explicit target confirmation and protected secret manifests. Exact commands
and the supported profile are in reconstruction-v2.md; the older reconstruction.md
is marked legacy. Native FreePBX command
registration and permissions are not integration-tested here. Do not give a PBX
web user privileged shell access to make the CLI work.

`fwconsole respawn storage-repair` is a separate root-only recovery action for a
confirmed ownership mismatch. It accepts no path arguments; it validates the
configured `ASTVARLIBDIR/respawn` tree against managed journal/chunk/lock/log
names, locks storage, rejects symlinks, hardlinks and unexpected files, then
restores the parent service account's ownership and 0700/0600 modes without
deleting or resetting state. Stop cron/GUI writers first. Do not use shell
`chown -R`; protected secrets outside this exact tree are never touched. Verify
afterward by running status as the FreePBX service account and root.

The neutral HTTPS JSON adapter has injected-transport contract tests, not real
provider integration. See https-profile.md. Enrolment/token issuance is external.
Use `reconcileSubmission --submission-id=SUBMISSION_ID` for lookup-only
reconciliation after a lost session. It never submits a launch. The distinct
`retrySubmission --submission-id=SUBMISSION_ID` action deliberately resends only
the original saved request, key and connection when retry is safe. Unsupported
actions fail; disabled mode cannot publish or launch. Fixture data is synthetic.

No live execution is authorised by this guide. In an explicitly authorised disposable
lab only, export-reconstruction accepts `--groups=600,601 --secrets=/private/source-manifest.json`;
plan accepts `--blueprint=/private/blueprint.json`; reconstruct additionally requires
`--secrets=/private/target-manifest.json --confirmation=reconstruct:TARGET_ID` and
performs one normal final framework reload. Missing/unknown/apply/partial results
are non-success; reconcile actual target state before retrying. Module installation
and matching global SIP settings remain prerequisites, not reconstruction operations.