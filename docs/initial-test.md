# Initial FreePBX 17 Protection And Reconstruction Test

## Purpose And Boundary

This procedure tests the current native create-only reconstruction profile on a
disposable or explicitly designated, isolated FreePBX 17 source and target. It does
not test replacement provisioning, carrier/SBC routing or production takeover.
Use provider mode only for the standalone Pond retention stage, never provisioning.
Do not use production
extensions, credentials, routes, trunks, emergency destinations or customer
traffic. No live provider operation is part of the initial test.

The required outcome is accurate reconstruction of the selected supported
configuration, one normal framework reload and successful saved-state read-back.
Registration and internal calls are optional independent lab checks, not Respawn
verification features. No external inbound/outbound call or recovery-time target
is in scope.

For independent local discovery without provider configuration, a Ring Group
scope or a secret manifest, see [Puddle configuration capture](puddle-capture.md).
That capture is distinct from executable reconstruction v2 and does not by itself
establish recoverability.

## Protection Lifecycle Readiness

The module now has a configurable reconciliation coordinator and the command
`fwconsole respawn protection-reconcile`. It defaults disabled and its interval
defaults to 300 seconds. It captures only the exported supported profile, creates
and pins a local revision, queues the exact revision for provider retention,
requires a `durably_retained` acknowledgement, retrieves that revision and
validates its canonical hash. Repeated identical exports do not create revisions;
pending revisions retry FIFO with a stable idempotency key and bounded exponential
backoff. Independent retrieval is scheduled every 24 hours. `protection-status`
reports the separate local/submission/acknowledgement/retrieval fields.

The module does not install a host scheduler entry. After enabling protection in
the authenticated GUI and configuring a compatible provider, a host administrator
must install the every-minute command below, using the system's approved
FreePBX service account and CLI path. Example `/etc/cron.d` entry (adjust the
account/path to the test host):

```cron
* * * * * asterisk /usr/sbin/fwconsole respawn protection-reconcile
```

This is an operator-installed host cron job, not an automatically registered
FreePBX scheduler. The command is safe to invoke more frequently than the chosen
interval; it checks the persisted next-check time and uses the same target lock to
prevent overlapping runs. Monitor its exit status and PHP error log; non-current
states return failure. This is polling, not event capture: a change made after one
check is not known until a later check. If the PBX fails during that interval, the
last observed revision may omit that change. Record this observation window; do
not claim zero-loss or instantaneous protection.

The corrected specimen ends with a newline and does not suppress output. Each
reconciliation also writes mode-0600 `ASTVARLIBDIR/respawn/protection.log`, rotating
at 256 KiB with three archives. Events contain only time/state, failure category
and stage, pending count and recovery marker, never payloads or passwords.
Inspect `lastInvocationCompletedAt`, `lastSuccessfulReconciliationAt`, `lastError`
and `schedulerState`. Completed activity older than three minutes is `missed`;
unfinished activity older than two minutes is `stalled`. Status reads never reset
the heartbeat. `fwconsole respawn protection-health` fails on stale/missing activity
or non-current protection. Run it from independently scheduled monitoring with
the lab's alerting policy: passive GUI status is not an external alert and a
stopped scheduler cannot report its own death.

Run the read-only preflight as each actual execution account, using the checkout
or the standalone package containing `tools/` and the relative `module/` bootstrap:

```sh
php /opt/respawn-pond/tools/preflight.php --role=source --state=/var/lib/asterisk/respawn --cron=/etc/cron.d/respawn-protection
php /opt/respawn-pond/tools/preflight.php --role=pond --state=/var/lib/respawn-pond/state --key=/etc/respawn-pond/master.key
php /opt/respawn-pond/tools/preflight.php --role=recovery --token=/private/respawn/recovery.token
```

Adjust only host-confirmed paths. Source checks must run as `asterisk` under the
same PHP CLI environment as cron: PHP 8.2+, cURL/PDO MySQL/POSIX ownership
functions, readable FreePBX config
and executable `/usr/sbin/fwconsole`. Pond needs PDO SQLite and OpenSSL AES-GCM;
recovery needs cURL. JSON/hash/filter and fsync/flock are checked too. Extension
versions follow the PHP build; no narrower vendor range is certified. Preflight
prints versions/booleans, writes nothing and makes no network calls. Its
`schedulerExecutionProven: false` is intentional: installed cron is not execution.

## Mixed-User Respawn Storage

The state directory and ordinary journals/chunks/locks/logs must be owned by the
owner/group of the configured `ASTVARLIBDIR` parent, normally `asterisk:asterisk`.
Run cron as `asterisk`; root-run `fwconsole` commands preserve this owner during
atomic journal replacement and chunk/lock creation. Root-side storage mutations
are restricted to the fixed `ASTVARLIBDIR/respawn` tree. The repair command never
touches secret manifests, Pond keys, recovery tokens or arbitrary paths.

If Respawn reports `RESPAWN_STORAGE_UNAVAILABLE`, stop the scheduled reconciliation
and avoid concurrent GUI/CLI writes. As the FreePBX administrator, first inspect:

```sh
stat -c '%U:%G %a %n' /var/lib/asterisk /var/lib/asterisk/respawn /var/lib/asterisk/respawn/state.json /var/lib/asterisk/respawn/state.lock
find /var/lib/asterisk/respawn -maxdepth 3 -printf '%y %u:%g %m %p\n'
```

Confirm the paths are the configured Respawn data root and its managed files;
preserve a private backup of the complete tree. Do **not** delete `state.json`,
reset PBX identity, use recursive shell `chown`, or touch paths outside Respawn.
Then run the constrained repair as root:

```sh
fwconsole respawn storage-repair
```

It requires root, derives the service uid/gid from the canonical `ASTVARLIBDIR`
parent, takes Respawn state/diagnostic locks, validates the entire tree against
known journal/chunk/lock/log names, rejects symlinks/hard links/unexpected files,
and only then restores owner/group and modes 0700 (directories)/0600 (files).
It preserves file contents and prints the repaired entry count and service IDs.
If preflight rejects a path, stop and retain evidence for review; do not expand
the allowlist to customer or credential files. The repair is repeatable if an
OS ownership operation is interrupted. Afterward verify:

```sh
stat -c '%U:%G %a %n' /var/lib/asterisk/respawn /var/lib/asterisk/respawn/state.json /var/lib/asterisk/respawn/state.lock
runuser -u asterisk -- fwconsole respawn status
runuser -u asterisk -- fwconsole respawn protection-status
fwconsole respawn status
```

The first service-account write/read verifies subsequent GUI accessibility; the
final root status must not change state-file ownership. Repeat a GUI page load and
revision-list check, then resume cron. Capture checksums/revision heads before and
after repair and require them unchanged. Root-only repair is a recovery action,
not a routine scheduler step.

The independent implementation is now `pond/`, not the Development Pond.
Deploy **Server B** using [the standalone runbook](../pond/README.md), its actual
init/enrol commands and nginx/PHP-FPM examples. Supply trusted TLS, SQLite/OpenSSL,
private storage, clock synchronisation and separate recovery-token/key custody.
The source client requires publicly resolved IPv4 HTTPS; private-only lab
transport remains blocked. Do not weaken TLS/SSRF controls to pass a lab test.

On **Server A**, install Respawn and prepare the supported source below. Record
its installed identity and exact module versions. Save provider mode, intent
`rehearsal`, activation `none`, Server B's `/rpc` endpoint, explicit hostname,
publication-token file, groups and secret manifest. Match Server B enrolment to
GUI adapter ID `https-json-v1` and the computed connection identity. Keep
protection disabled until enrolment is approved. Keep a different read/recover
token, source-identity provider JSON and backup Pond key independent of Server A.

Install `pond/respawn-protection.cron` as `/etc/cron.d/respawn-protection`, owned
by root, mode 0644, after checking its account and `/usr/sbin/fwconsole` path.
It invokes the CLI each minute; persisted due times enforce the five-minute
default inspection interval and retry backoff. Enable protection, close the GUI
and let cron perform the initial capture without manual publication. Inspect:

```sh
fwconsole respawn protection-status
fwconsole respawn revision-list
```

Require invocation timestamps and the private log to advance without manual
reconcile or GUI visits. Reboot disposable A, confirm the cron daemon is enabled,
then repeat that observation. Briefly stop the lab schedule: `protection-health`
must fail with `missed` even for unchanged configuration. Restore it and require
fresh activity. Temporarily deny A-to-B traffic using the lab's approved process,
change supported configuration and require failed publication, pending state,
unchanged protected head and non-sensitive log evidence. Restore traffic: the
same pending transaction must retry, verify and record recovery. No Asterisk
reload may be triggered by inspection, publication or health queries.

On the Debian-based test source, `systemctl is-enabled cron` and
`systemctl is-active cron` check service enablement/activity; they do not prove
this job ran. Require new invocation timestamps and protected log events after
the reboot before passing the scheduler stage.

Require a recent successful inspection, matching local/acknowledged/verified
revision IDs, zero pending publications, `EXTERNAL_PROVIDER`, verified credentials
and `protected_and_current`. Check cron's journal and PHP/FreePBX diagnostic log.
Change a supported Ring Group description/timeout through FreePBX and apply it
normally. Close the GUI and require the next scheduled inspection to capture,
publish and verify a new revision/hash. An unchanged interval must not create
another revision. For password rotation, stage the exact new password under a
new immutable reference and require the corresponding changed blueprint/escrow.
Keep historical entries until all dependent revisions are acknowledged; use the
manifest's optional `activeReferences` map to select each extension's current
reference while preserving old values for queued snapshots. For example,
`"activeReferences": {"1001": "secret:SOURCE_ID:1001:v2"}` selects v2 explicitly.
Protection inspection/publication must never cause an Asterisk reload.

From an independent workstation containing the Pond package and protected
recovery-only provider JSON, retrieve before source disconnection:

```sh
php /opt/respawn-pond/pond/bin/pond.php recover /private/respawn/recovery-provider.json /private/respawn/pre-loss-check
```

Require the expected latest revision/hash and all referenced credentials. Record
metadata only. Snapshot the disposable source, then power it off or deny all
access to its filesystem, database, tokens, Puddle and manifests. Do not destroy
its snapshot until the lab result has been reviewed. Restart Server B's service
under the approved lab process and retrieve to a **new** private directory:

```sh
php /opt/respawn-pond/pond/bin/pond.php recover /private/respawn/recovery-provider.json /private/respawn/recovered
```

Require `recovery_material_retrieved` and the exact recorded latest revision/hash.
This must work with only Pond storage/key and the independently held recovery
token, without source reads. Do not manually copy source passwords to pass.
Retrieval grants no execution or routing authority. Use the sensitive protected
bundle on the clean target in the next stage. Reject unknown, wrong-tenant and
revoked tokens without disclosure. Stop on missing secrets, unsupported state,
failed TLS, identity mismatch or unknown results.

Available local tests, distinct from the real two-environment test:

```sh
php tests/protection.php
php tests/revision-capacity.php
php tests/pond.php
php tests/pond-ordering.php
python3 tests/local-pond.py --contracts-only
```

The independent-service test uses real SQLite/AES-GCM, removes source files and
reconstructs a simulated target with exact recovered passwords. Public TLS,
host cron, native FreePBX installation/reconstruction and physical storage failure
have not been tested here. Keep these evidence classes separate.

## Capability Matrix

| Capability | Respawn implementation | Synthetic evidence | Native FreePBX evidence | Initial test |
| --- | --- | --- | --- | --- |
| Core users | Selected user create, strict profile inspect/read-back | Yes | None | Create users 1001/1002 |
| Fixed PJSIP extensions | Fixed one-user/one-device create; original auth identity and secret reference | Yes | None | Create devices 1001/1002 |
| Ring Groups | Selected ringall create with numeric members and hangup/group destination | Yes | None | Create fallback 601, then primary 600 |
| Trunks | Unsupported | No functional reconstruction; excluded in blueprint | None | No |
| Inbound routes | Unsupported | No functional reconstruction; excluded | None | No |
| Outbound routes | Unsupported | No functional reconstruction; excluded | None | No |
| Numbering | Stable numeric identities for selected users/groups only; no numbering/provider migration | Profile shape only | None | Use isolated test numbers only; no PSTN provisioning |
| Provider connectivity | Provider-neutral contracts and mock/local simulation | Simulated lifecycle only | None | Disabled; not tested |
| SIP registration | Not implemented as a Respawn probe | Evidence-contract validation only | None | Optional independent `pjsip show contacts` check |
| Inbound calling | Not implemented as a Respawn probe; no inbound routes/trunks | No functional call test | None | Unsupported for this test |
| Outbound calling | Not implemented as a Respawn probe; no outbound routes/trunks | No functional call test | None | Unsupported for this test |
| Two-way audio | Not implemented as a Respawn probe | Synthetic evidence rejection/shape tests only | None | Optional internal lab call check, not a Respawn pass criterion |

“Native FreePBX evidence: none” means no real FreePBX host has been used in this
workspace. Native-shaped test doubles and inspected upstream source do not change
that status.

## Required Environments

Use **Server A** (disposable FreePBX 17 source), **Server B** (independent
experimental Pond) and **Server C** (clean FreePBX 17 reconstruction target).
Keep A and its old storage inaccessible during recovery on C. Use the exact supported profile
below and a fresh target with no selected users, PJSIP devices or Ring Groups.
Disposable VMs with restorable snapshots are preferred. Put both on a test network
with no production SIP edge, carrier trunk, customer traffic or emergency calling.
Use synthetic extension identities and lab-only endpoint credentials.

Both systems must have PHP 8.2 or newer and the same enabled versions of
`framework`, `core`, `ringgroups` and `sipsettings`. The target versions must
exactly equal the source versions embedded in the exported blueprint. Record the
versions from the blueprint and each FreePBX Module Admin view; do not substitute
the synthetic `17.0.1` versions from unit tests. On the target, the Core PJSIP
driver, Asterisk Manager connection and at least one compatible PJSIP transport
must already work. The executor checks these prerequisites. Global SIP, network,
firewall, endpoint transport and licensing setup are not reconstructed.

Install and activate the unsigned development module on both systems using the
FreePBX Module Admin local-upload workflow and the locally generated
`artifacts/respawn-0.1.1.tgz`. Confirm its Connectivity menu entry and that
`fwconsole respawn status` runs. This is a test step, not evidence that packaging
or installation is generally supported. If upload, installation, activation,
menu access or CLI discovery fails, stop and record it; do not work around module
security or permissions on a production host.

Generate the local upload package from the checkout with:

```sh
python3 tools/build_site.py
python3 tools/package.py
```

The tarball contains the module source, metadata, wrapper and licence. FreePBX
provides the runtime framework and the Core, Ring Groups and SIP Settings modules;
they are prerequisites, not bundled dependencies. Successful archive checks do
not prove installation or activation.

On the target, save the module policy through the authenticated Respawn GUI with
provider mode **Disabled**, intent `rehearsal`, activation policy `none`, and the
selected Ring Groups `600,601`. Keep automatic protection **disabled** for the
native reconstruction test. Use the source's enrolled Pond policy for protection
and the independently recovered manifest on the target. Keep identities distinct; do not clone
module state or restore the source identity onto the target. The GUI displays the
identity used for the target confirmation. Disabled mode does not publish or
provision. Protection enablement requires provider mode and a valid HTTPS provider
configuration; do not enable it with a fixture or nonexistent endpoint.

## Source Configuration

In the FreePBX Connectivity menu, open **Respawn**. **Overview** is the default
dashboard; **Protection** shows Puddle, Stream and Pond state; **Settings** holds
provider, supported-scope, secret-manifest, schedule and recovery policy controls;
**Revisions** provides capture, history, comparison and Revert/Regenerate
previews; **Recovery** shows eligibility, submissions and provider operations.
Provider refresh is explicit in Overview/Recovery and never occurs on ordinary
view navigation. Detailed sanitised JSON remains under **Sanitised diagnostics**.
Settings and actions use POST with session CSRF protection and return to the
submitted view; operation confirmation requirements remain in force. Native
Revert/Regenerate execution stays disabled when update/removal/apply capabilities
are unavailable.

On the source only, create two fixed PJSIP extensions with matching no-voicemail
Core users: 1001 and 1002. Use the supported profile in
[reconstruction-v2](reconstruction-v2.md): numeric user/device relationship,
PJSIP transport defaults, `from-internal` context, supported codecs and booleans,
one contact, and stable authentication usernames. Stage each extension's existing
PJSIP authentication password through the authorised secret-handling process. Do
not create a new password merely to make reconstruction pass.

Create Ring Group 601 as a `ringall` group containing 1002 with a hangup no-answer
destination. Create Ring Group 600 as a `ringall` group containing 1001 with
Ring Group 601 as its no-answer destination. Use test-only numbering. Remove or
avoid conflicting users, devices, groups, voicemail boxes, emergency/custom
extensions and other module state that falls outside the selected profile.

Prepare a protected secret directory outside web roots, the repository and shared
temporary directories. Require an operator-controlled parent directory (mode
`0700`) and secret files/manifests mode `0600`; verify ownership and permissions
before use. `FileSecrets` checks absolute canonical paths, regular files,
permissions, size, manifest identity and secret format; it rejects symlinked paths
and group/world-writable ancestors except root-owned sticky directories. It does
not verify file or directory ownership or require every parent to be mode `0700`,
so the operator must ensure the staging tree is trusted. Never put secret values
in shell arguments, blueprints, logs, screenshots, tickets or this repository.

For every extension, create an immutable reference such as
`secret:SOURCE_ID:1001:TEST_REVISION_1`. The source manifest uses the source
Respawn identity as `sourcePbxId` and maps each reference to a protected file
containing that extension's exact existing password. The Pond recovery command
creates the target manifest with the same source identity, reference keys and
decrypted original values; hand-copying source passwords is not recovery evidence.
The manifest contains paths, not passwords. A
credential rotation requires a new immutable reference and a new blueprint hash.
If source and target cannot securely resolve the same original values, stop; the
test cannot claim credential continuity.

## Optional Pre-Loss Capture Diagnostics

Run commands using the host's approved FreePBX CLI execution role. On the source:

```sh
fwconsole respawn revision-capture
fwconsole respawn revision-list
fwconsole respawn revision-show --revision-id=REVISION_ID
umask 077
fwconsole respawn export-reconstruction --groups=600,601 --secrets=/private/respawn/source-manifest.json > /private/respawn/source-blueprint.json
```

On the disposable source only, retention can be smoke-tested before the export:

```sh
fwconsole respawn revision-retention --retention=2
fwconsole respawn revision-capture
fwconsole respawn revision-capture
fwconsole respawn revision-capture
fwconsole respawn revision-list
fwconsole respawn revision-retention --retention=50
```

With no provider operation or plan pins on this new test identity, `revision-list`
should show two retained revisions and the latest captured ID should still be
selectable. This changes only the disposable PBX's local revision cache. Do not
run this pruning exercise on a PBX whose retained history must be preserved. Set
the chosen retention limit back to the intended test value after the smoke check.

Replace `REVISION_ID` with the ID returned by `revision-list`; use the exact
protected paths prepared for the test. Revision capture and export are separate
reads. Do not change source configuration between them. Compare the revision
`contentHash` from `revision-show` with the blueprint `contentHash`; if they differ,
stop, recapture and export again from a stable source state.

On a trusted development workstation with this repository and its pinned
development requirements installed, validate JSON syntax and the independent v2
schema:

```sh
python3 -m json.tool /private/respawn/source-blueprint.json > /dev/null
python3 -c 'import json,jsonschema; b=json.load(open("/private/respawn/source-blueprint.json")); s=json.load(open("contracts/v2.schema.json")); jsonschema.validate(b,s); print(b["contentHash"], b["sourcePbxId"], b["modules"])'
```

The command prints only the hash, PBX identity and module versions. Then inspect
the executable plan without mutation:

```sh
fwconsole respawn plan --blueprint=/private/respawn/source-blueprint.json
```

Pass this stage only if the CLI exits successfully, the hash verifies, selected
coverage is complete for this profile, all four required module versions are
present, and the six operations are dependency ordered: both users before their
devices, fallback group 601 before primary group 600. The blueprint must contain
secret references only, not secret values. `plan` validates the blueprint; it
does not check target module versions, target conflicts or target readiness.
Transfer the protected blueprint to the target using the lab's approved channel.

## Reconstruct On The Target

On Server C, validate the independently retrieved bundle before planning:

```sh
php /opt/respawn-pond/tools/preflight.php --role=recovery --bundle=/private/respawn/recovered --token=/private/respawn/recovery.token
```

Require `bundle_integrity_and_credentials: true` and the recorded current Pond
revision/hash. `tests/pond-ordering.php` checks A/B/C, duplicates and delayed
history; never bypass a `PROTECTION_HEAD_LINEAGE_CONFLICT`. If later restoring
a source from older state, keep protection disabled and resolve pending work;
explicitly authorise continuation from the verified current head:

```sh
fwconsole respawn protection-resume --revision-id=CURRENT_POND_REVISION --confirmation=resume:SOURCE_ID:CURRENT_POND_REVISION
```

Replace placeholders with exact recorded values. A stale head or pending outbox
is rejected. This grants no reconstruction, routing or activation authority.
Do not rebind an already retained historical transaction to a different parent.

Before writing, verify the target is the intended disposable PBX, its identity is
the target identity shown in the Respawn GUI, its four required module versions
exactly match the blueprint, and it has no selected IDs or conflicting partial
objects. Confirm the target secret manifest has the source identity and original
immutable references described above.

Run the supported reconstruction command once:

```sh
fwconsole respawn plan --blueprint=/private/respawn/recovered/blueprint.json
fwconsole respawn reconstruct --blueprint=/private/respawn/recovered/blueprint.json --secrets=/private/respawn/recovered/secrets.json --confirmation=reconstruct:TARGET_ID
```

Replace `TARGET_ID` with the target's installed Respawn identity. This command
performs executor preflight, creates only absent supported objects through Core
and Ring Groups interfaces, reads each object back, invokes one normal framework
reload, and performs post-reload saved-state read-back. It does not update or
remove objects and does not activate provider routing. Do not run the command if
the target identity confirmation, hashes, versions or secrets are uncertain.

## Expected Results And Verification

For configuration reconstruction to pass, the command must exit successfully and
report `configuration_verified`, `savedConfigurationVerified: true`, succeeded
object checkpoints, and a succeeded apply receipt. The target must contain users
1001/1002, their fixed PJSIP devices, group 601 before group 600, and the expected
member/no-answer relationships. Inspect these values in the FreePBX Extensions
and Ring Groups interfaces. Do not display or copy authentication secret fields.
The executor's result confirms saved-state comparison against the blueprint and
checks the resolved target password; it does not confirm Asterisk runtime or
registration.

For an optional runtime check, provision two isolated test softphones with the
same original credentials and target address using the lab's approved process.
Check endpoint registrations with the read-only Asterisk CLI command:

```sh
asterisk -rx "pjsip show contacts"
```

Confirm the expected contacts are present without recording private addresses or
credentials. Respawn does not provide a registration probe. An optional internal
call test can call Ring Group 600, verify 1001 rings, allow the no-answer path to
reach 1002, then answer and confirm two-way audio. This is a manually observed
lab call, not independent Respawn readiness evidence. No trunk, PSTN inbound or
PSTN outbound test is supported by this profile.

Record separate elapsed durations for source capture/export, schema validation,
target plan/preflight, reconstruction/apply/read-back, and optional registration
and internal call checks. On GNU/Linux, wrap each command with GNU `time`; for
example:

```sh
/usr/bin/time -f 'export_seconds=%e' fwconsole respawn export-reconstruction --groups=600,601 --secrets=/private/respawn/source-manifest.json > /private/respawn/source-blueprint.json
/usr/bin/time -f 'schema_validation_seconds=%e' python3 -c 'import json,jsonschema; jsonschema.validate(json.load(open("/private/respawn/source-blueprint.json")), json.load(open("contracts/v2.schema.json")))'
/usr/bin/time -f 'target_plan_seconds=%e' fwconsole respawn plan --blueprint=/private/respawn/source-blueprint.json
/usr/bin/time -f 'reconstruction_apply_readback_seconds=%e' fwconsole respawn reconstruct --blueprint=/private/respawn/recovered/blueprint.json --secrets=/private/respawn/recovered/secrets.json --confirmation=reconstruct:TARGET_ID
```

Record transfer start/end and optional runtime/call start/end with a monotonic
clock on the operator workstation; retain command and apply timestamps. Record
actual measurements only, without a pass threshold or implied number of minutes.
Reconstruction is one CLI operation, so its wall time includes preflight, writes,
reload and final read-back. If GNU `time` is unavailable, use an approved monotonic
timer and identify how it was measured.

## Pass, Fail And Recovery

Record each completed stage and its duration separately: installation, genuine
cron execution after reboot, initial capture, extension/group change, ordered
publication, Pond restart/retrieval, exact original/rotated credentials with A
absent, C preflight/create/reload/read-back, runtime assessment and optional
registration. Mark failed/skipped stages explicitly; do not report an aggregate
full-PBX disaster-recovery time. Retain actual module/host versions and the
source-loss boundary. Dispose of recovered bundles and snapshots using the
encrypted lab workspace's approved destruction process after review. File deletion
or `shred` alone does not establish secure erasure on SSD/COW storage. Never put
bundles in web roots, tickets, ordinary log collection or review archives.

Configuration pass requires all object operations to read back, one confirmed
reload, post-reload comparison success, and no secret values in command output or
retained evidence. Runtime/registration and internal-call outcomes are recorded as
separate optional results. A successful configuration pass is not full PBX
recovery.

Fail and stop on module-version mismatch, unsupported source state, missing or
ambiguous secret reference, source/target hash mismatch, identity collision,
partial object, unknown mutation/apply outcome, reload failure or read-back
mismatch. Preserve the exact blueprint hash, sanitized command output, revision
ID, module versions, checkpoint/error codes, relevant FreePBX logs and elapsed
durations. Store these artifacts privately; redact identities/addresses before
sharing. Never include secret values or unredacted PJSIP settings.

Do not blindly retry creates or attempt Revert/Regenerate as rollback: native
revision updates/removals and independent ownership are not implemented. Inspect
the target through supported FreePBX interfaces, preserve its state for diagnosis,
and reconcile partial writes with an administrator. If recovery is required, use
the pre-test hypervisor snapshot or discard/recreate only the isolated target
under the lab owner's procedure. Leave the source untouched. Repeat only after the
target is known clean and the same blueprint/credential references have been
reviewed.

## Explicitly Out Of Scope

The initial test does not install missing FreePBX modules, configure global SIP,
restore trunks/routes/numbering, transfer voicemail/media/history, provision
phones, change DNS/firewall/carrier/SBC routing, activate a provider operation, or
verify external calls. It does not execute native Revert/Regenerate. Provider
integration testing is a separate, explicitly authorised exercise against a
compatible non-production service; this procedure makes no provider request.

Later full-PBX restoration depends on safe native update/removal and ownership
proof, immutable credential-service integration, trunk and inbound/outbound route
modules, numbering/destination continuity, provider provisioning/connectivity/SBC
contracts, authority fencing, and independent registration/call/audio verification.
See the [capability roadmap](capabilities-and-roadmap.md).