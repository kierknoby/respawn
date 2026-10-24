# Respawn for FreePBX 17
⚠️ Not currently suitable for use in production

Respawn is an experimental GPLv3+ FreePBX Connectivity module exploring rapid,
on-demand reconstruction of a replacement PBX from a retained, validated
blueprint. Conventional disaster recovery often depends on maintaining a second
PBX or warm-spare infrastructure. Respawn's objective is to make that unnecessary
for suitable deployments by rebuilding a replacement only when recovery is
needed. This objective is not yet proven end to end; the project does not claim
zero downtime, active-call migration or a measured recovery time.

Respawn builds on FreePBX module interfaces. It exports supported configuration
as typed, dependency-ordered operations and reconstructs those operations on a
compatible target. It is not a full-system image restore, and configuration
reconstruction alone is not telephony recovery.

## Ecosystem

Respawn's target architecture has five logical components: **The Puddle** captures
and tracks supported configuration on the originating PBX; **The Stream** detects
changes and securely publishes exact revisions; **The Pond** independently
retains blueprints and protected recovery information for authorised retrieval; **The River** is
peer-to-peer federation between Ponds; **The Lake** is optional fleet visibility
and coordination. The Lake is not a data plane, sole repository or recovery
dependency. River peers do not need a shared database or hosting provider.

Respawn 0.1.0 includes two public reference integrations: a my-connect-style
adapter for the existing Stream contract and a Kamailio routing-control client
that blocks activation by default. Neither depends on the named infrastructure;
the downloadable archives and limitations are documented in
[provider integrations](docs/provider-integrations.md).

The Puddle/Stream coordinator connects to an experimental standalone
[Pond service](pond/README.md): SQLite retention, AES-256-GCM credential escrow,
scoped enrolment, replay rejection and independent recovery bundles. An independent
loopback service test deletes source storage and reconstructs a simulated target
with the exact retained passwords. Operator-confirmed FreePBX capture and
separate-host HTTPS configuration retention passed on 24 October 2026; live
credential recovery and replacement reconstruction remain untested. The Puddle's
separate federation ledger is synthetic; production River
transport/security and Lake remain unimplemented. See [ecosystem architecture](docs/ecosystem-architecture.md),
[River federation](docs/river-federation.md),
[Lake fleet management](docs/lake-fleet-management.md), and
[recovery authority](docs/recovery-authority.md).

## Three Operations

| Operation | Meaning | Current evidence |
| --- | --- | --- |
| **Revert** | Plan a return to an earlier retained revision of supported local configuration. | Revision capture, compare and preview are implemented. The synthetic target executes Revert. Native FreePBX execution is blocked by unavailable update/removal and ownership capabilities. |
| **Regenerate** | Reconcile supported local configuration toward a selected retained blueprint. | Planning and synthetic execution/read-back are tested. Native FreePBX is create-only; updates, removals and verified ownership are unavailable, so plans that need them cannot execute. |
| **Respawn** | Request a provider-controlled replacement deployment, reconstruct its blueprint, prepare routing and explicitly authorise activation. | Mock orchestration and a local simulated controller are tested. Real provider compatibility, native end-to-end reconstruction, routing and live calls are unverified. |

Revert and Regenerate concern the current PBX's managed configuration. They do not
reverse provider services, billing or routing. Respawn concerns a replacement
system and requires provider interaction. None of the three restores recordings,
voicemail media, call detail records, logs or other historical data unless a
separate recovery service supplies it.

## Blueprint And Recovery Path

The initial reconstruction profile is intentionally limited. Respawn's long-term
objective is comprehensive FreePBX configuration recovery through progressively
expanded module support, not a permanent limit to the current profile. Current,
developing, planned and under-investigation coverage is listed in the
[module coverage roadmap](docs/capabilities-and-roadmap.md#freepbx-module-coverage).
No universal module compatibility or full reconstruction capability is claimed.

The Puddle independently discovers the supported local profile without an
external provider or configured Ring Group list. Empty supported collections are
valid. Local configuration capture and secret verification are separate, and a
captured revision is not an executable recovery blueprint. See the
[Puddle capture guide](docs/puddle-capture.md) for scope, safety and FreePBX test
commands.

The 0.1.0 prototype can separately publish a capture-only revision through the
existing authenticated Stream connection and read back the exact snapshot from
Pond. This retention path does not turn the snapshot into an executable
blueprint, escrow credentials or claim recovery readiness. The deployed Pond
requires the documented two-file compatibility update on older installations.
The operator's independent Pond has accepted and verified populated captures.

On 24 October 2026, operator-confirmed testing demonstrated complete capture of
two Core users, two PJSIP devices and one Ring Group on FreePBX 17 / Asterisk 22 /
Debian 12, with no configuration blocking gaps. Authenticated HTTPS publication
to an independent Debian 13 Pond, immediate read-back and subsequent verification
matched revision identifiers, content hashes and retention receipts. The result
was `configurationComplete: true`, `retentionState: independently_readback_verified`,
`credentialStatus: not_configured`, `recoveryReady: false`. Empty collections also
passed earlier testing. These are live configuration-protection results, not
native restoration, credential recovery, full PBX coverage or telephony evidence.
Credential readiness remains separate from configuration completeness. See the
[capture upgrade procedure](docs/puddle-capture.md#existing-010-capture-correction).
The live-identified `secret_origional` SIP keyword is excluded as credential
material alongside `secret` and `md5_cred`; its changes do not affect capture hashes.

The intended recovery sequence is:

1. Capture supported FreePBX module configuration with read-only exporters.
2. Validate it and create a versioned executable blueprint.
3. Retain the blueprint and detect or declare a recovery requirement.
4. Request a replacement FreePBX deployment from an authorised provider.
5. Reconstruct supported configuration in dependency order.
6. Verify saved configuration, then independently establish runtime and
   telephony readiness.
7. Prepare provider connectivity and routing without activating traffic.
8. Authorise activation under explicit policy and authority controls.
9. Confirm recovery with independent inbound/outbound calls and bidirectional
   audio evidence.

The implemented v2 profile covers selected Core users, fixed PJSIP devices and
selected Ring Groups. Exact compatible module versions and global SIP settings
are prerequisites. Trunks, inbound/outbound routes, broader numbering and
extension relationships, voicemail, licences, custom modules, media and history
are not reconstructed by this profile. See [v2 scope and dependencies](docs/reconstruction-v2.md).

Blueprint operations are structured, validated module instructions, not arbitrary
shell commands or dialplan. Exporters are read-only. Writes use supported FreePBX
interfaces; generated Asterisk files are not edited. Original endpoint credentials
are represented by immutable references and must be securely staged on source and
target. Missing or mismatched values block reconstruction rather than silently
creating replacement credentials.

## Provider Boundary

The module handles local export, blueprint validation, revision controls,
reconstruction planning/execution, diagnostics and provider-neutral requests. A
provider/controller is responsible for enrolment and authority, blueprint
retention, replacement provisioning, secret delivery, connectivity and SBC
routing, activation fencing, service lifecycle and resource cleanup. These services
remain outside module core; the public contracts do not assert compatibility with
a private provider implementation.

Submission, acceptance, configuration, saved-state verification, runtime
readiness, registration, call/audio evidence, routing preparation and activation
are separate states. A successful mock or source inspection proves none of the
live telephony stages.

## Automatic Protection Status

The module includes a configurable reconciliation command and durable local
publication outbox. When enabled with a provider configuration, it exports the
supported profile, detects canonical-hash changes, captures and pins revisions,
publishes exact snapshots with connection-scoped idempotency keys, retries with
bounded backoff, and retrieves the retained revision to verify its hash. The
default interval is five minutes. A host administrator must install the schedule;
module installation does not modify host cron or register a FreePBX scheduler
job. This polling model observes configuration only when the scheduled command
runs: changes made after a successful check may remain unpublished until the next
run. A host failure during that interval can therefore lose the unobserved change;
the reported state is current only as of its displayed last-check timestamp.

The standalone Pond implements retention and encrypted credential recovery without
source files or its token. Each publication escrows exact manifest references,
checks retrieved passwords for equality, retains the blueprint and verifies its
retrieval. A separately held recovery token and Pond-side key remain necessary.
The recovery CLI creates a protected native-compatible blueprint/secret bundle.
Only verified external transport and credential checks can report
`protected_and_current`; loopback/injected transports never do. This separate
executable-blueprint/credential workflow has no live recovery evidence; the
capture-only milestone does not establish scheduled protection. Install the supplied cron entry on the source
and deploy the independent Pond only in an authorised isolated lab. See
[the first-test procedure](docs/initial-test.md).

Pre-test corrections add chunk-backed local history (default 50, configurable
2-500 revisions), parent-conditioned Pond recovery-head promotion and bounded
protected diagnostics. The supported limit remains 100 operations and a 64 KiB
HTTPS envelope. Read-only `php tools/preflight.php --role=pond` or `--role=recovery`
checks local prerequisites; source checks require an actual FreePBX host. See
[audit dispositions](docs/pre-test-review.md) for migration and host gates.

## Current Status

| Area | Locally implemented and tested | Not established |
| --- | --- | --- |
| Configuration protection | Real FreePBX capture of five supported objects, separate-host authenticated HTTPS retention, immediate and subsequent matching read-back | Full PBX coverage, credential recovery or executable restoration |
| Reconstruction | Typed selected user/device/group profile, dependency planning, preflight, checkpoints, conflict handling, apply boundary and read-back contracts | Live replacement reconstruction or full PBX coverage |
| Revisions | Capture, comparison, planning, synthetic Revert/Regenerate execution and acknowledgement binding | Native destructive/update execution or native ownership proof |
| Provider workflow | Mock orchestration, neutral HTTPS contract tests, persistent local simulation and explicit policy gates | Real provider compatibility, provisioning, connectivity or SBC control |
| Protection lifecycle | Coordinator, pinned FIFO retry outbox, standalone SQLite Pond, AES-GCM escrow, source-free native-compatible recovery bundle and independent-process test | Host-scheduled blueprint protection, live credential recovery and hardware durability |
| Recovery evidence | Synthetic tests, schema/hash validation, package/build checks | Live registration, inbound/outbound calls, bidirectional audio or measured RTO |

The project is a working configuration-protection prototype, not a production recovery service. Native
Revert and Regenerate controls remain read-only where the adapter lacks required
capabilities. The native adapter currently permits selected creates only; its
revision target rejects updates/removals and marks ownership unverified; GUI and
revision CLI execution do not supply an apply callback. The separate
reconstruction CLI has a fixed reload callback for its selected create path.
None of this is native revision-recovery evidence. The local simulation exercises
additional behaviour but is not native FreePBX evidence.

## Development

The FreePBX Connectivity page is organised as Overview, Protection, Revisions,
Recovery and Settings. Detailed sanitised JSON remains expandable for diagnostics;
view changes are server-rendered and do not trigger provider refreshes.

Persistent Respawn state is owned by the FreePBX service account even when an
administrator runs `fwconsole` as root. The root-only `storage-repair` command is
restricted to validated Respawn files under `ASTVARLIBDIR/respawn`; credential
files and Pond keys are deliberately outside that repair scope.

The module targets FreePBX 17 and PHP 8.2+ conventions. Installation, supported
capture and independent HTTPS configuration retention have operator-confirmed
development-host evidence. It remains experimental; do not install it on a
production PBX. No production deployment or recovery execution is implied.

Install development dependencies and run the local gates:

```sh
python3 -m pip install -r requirements-dev.txt
python3 -m playwright install chromium
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
```

The check command runs PHP lint and synthetic suites, revision regressions,
provider/corrective checks, schema and hash validation, website generation and
package checks. Browser checks run when Playwright Chromium is launchable. On a
local host where it cannot launch, the script reports the browser checks as
skipped; CI provisions Chromium and treats browser-test failures as failures.

Diagnostic rotation regression checks cover the current log and three private
archives using fresh filesystem metadata, including a verified PHP 8.3.6 CI
compatibility correction. Exact reproduction and test evidence are recorded in
[validation history](docs/validation.md).

The isolated [Development Pond demonstration](development/local-pond/README.md) uses
loopback HTTP, synthetic data and a simulated target. It is excluded from the
module and website packages and does not prove FreePBX, SIP, call/audio or provider
integration. The static site is built with `python3 tools/build_site.py`; packages
are generated under ignored `artifacts/` by `python3 tools/package.py`.

The 0.1.0 website update includes the full project welcome, the dedicated
"Why Respawn?" argument, local Revert and independent-protection paths, and a
dated evidence boundary. All eleven pages plus the 404 page are checked from the
extracted static ZIP at desktop, mobile and narrow-mobile widths. No website
deployment is part of this update.

## Roadmap

1. Extend the confirmed installation/capture evidence with permission and settings
   tests, the supported create/reload/read-back profile and host-scheduled
   reconciliation on disposable FreePBX 17 hosts.
2. Implement and independently verify safe native updates, removals and ownership
   tracking before making Revert or Regenerate executable.
3. Prove the implemented standalone Pond's immutable secret-reference continuity
   over authorised separate-host HTTPS, with independent key/token custody and
   the original source unavailable; current evidence is local independent-process
   testing with synthetic PBX state. The reproducible Pond ZIP is generated under
   `artifacts/respawn-pond-0.1.0.zip` by the package command.
4. Expand module-by-module reconstruction coverage according to the published
   roadmap, with dependency, version-compatibility and coverage checks.
5. Integrate provider-controlled replacement provisioning, connectivity and SBC
   preparation/activation under explicit authority fencing.
6. Verify registration and independent inbound/outbound calls with bidirectional
   audio, then measure the complete recovery path and its failure cases.

Dependencies, proof requirements and sequencing are detailed in the
[capability roadmap](docs/capabilities-and-roadmap.md) and
[operational path](docs/operational-path.md). No recovery duration is claimed.

## Documentation

- [Architecture](docs/architecture.md)
- [Reconstruction profile and lab requirements](docs/reconstruction-v2.md)
- [Puddle discovery, capture status and FreePBX test procedure](docs/puddle-capture.md)
- [Initial FreePBX 17 source-to-target test](docs/initial-test.md)
- [Capabilities and roadmap](docs/capabilities-and-roadmap.md)
- [Provider contracts](docs/provider-contracts.md)
- [Provider-neutral integrations and 0.1.0 packages](docs/provider-integrations.md)
- [my-connect-style Stream reference](integrations/my-connect-reference/README.md)
- [Kamailio routing-control reference](integrations/kamailio-reference/README.md)
- [Complete ecosystem architecture](docs/ecosystem-architecture.md)
- [River peer federation](docs/river-federation.md)
- [Lake fleet management](docs/lake-fleet-management.md)
- [Recovery authority and fencing](docs/recovery-authority.md)
- [Federation testing and evidence](docs/federation-testing.md)
- [Operational path and measurements](docs/operational-path.md)
- [CLI and synthetic demo](docs/cli-and-demo.md)
- [FreePBX development and installation notes](docs/freepbx-development.md)
- [Security](docs/security.md)
- [Current handover](docs/handover.md)
- [Historical prototype notes](docs/history.md)

Respawn is licensed under GPLv3+; see [LICENSE](LICENSE). External providers and
controllers have their own implementation and licensing responsibilities.
