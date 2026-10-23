# Respawn for FreePBX

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

Respawn's target architecture has five logical components: **The Puddle** is the
PBX-local supported-state and revision cache; **The Stream** detects changes and
publishes exact revisions; **The Pond** independently retains blueprints and
coordinates authorised recovery; **The River** is peer-to-peer federation between
Ponds; **The Lake** is optional fleet visibility and coordination. The Lake is
not a data plane, sole repository or recovery dependency. River peers do not need
a shared database or hosting provider.

The Puddle/Stream coordinator connects to an experimental standalone
[Pond service](pond/README.md): SQLite retention, AES-256-GCM credential escrow,
scoped enrolment, replay rejection and independent recovery bundles. An independent
loopback service test deletes source storage and reconstructs a simulated target
with the exact retained passwords. Public TLS deployment and real FreePBX remain
untested. Local Pond's separate federation ledger is synthetic; production River
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
`protected_and_current`; loopback/injected transports never do. This is not yet
real off-site or FreePBX evidence. Install the supplied cron entry on the source
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
| Reconstruction | Typed selected user/device/group profile, dependency planning, preflight, checkpoints, conflict handling, apply boundary and read-back contracts | Real FreePBX installation/reconstruction or full PBX coverage |
| Revisions | Capture, comparison, planning, synthetic Revert/Regenerate execution and acknowledgement binding | Native destructive/update execution or native ownership proof |
| Provider workflow | Mock orchestration, neutral HTTPS contract tests, persistent local simulation and explicit policy gates | Real provider compatibility, provisioning, connectivity or SBC control |
| Protection lifecycle | Coordinator, pinned FIFO retry outbox, standalone SQLite Pond, AES-GCM escrow, source-free native-compatible recovery bundle and independent-process test | Host cron installation, public TLS deployment, hardware durability and real FreePBX testing |
| Recovery evidence | Synthetic tests, schema/hash validation, package/build checks | Live registration, inbound/outbound calls, bidirectional audio or measured RTO |

The project is a development foundation, not a production recovery service. Native
Revert and Regenerate controls remain read-only where the adapter lacks required
capabilities. The native adapter currently permits selected creates only; its
revision target rejects updates/removals and marks ownership unverified; GUI and
revision CLI execution do not supply an apply callback. The separate
reconstruction CLI has a fixed reload callback for its selected create path.
None of this is native revision-recovery evidence. The local simulation exercises
additional behaviour but is not native FreePBX evidence.

## Development

The module targets FreePBX 17 and PHP 8.2+ conventions. It is experimental and
has not been established as installable on a real FreePBX host. Do not install it
on a production PBX. No deployment, live provider test or production change is
part of this repository workflow.

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

The isolated [Local Pond development demo](development/local-pond/README.md) uses
loopback HTTP, synthetic data and a simulated target. It is excluded from the
module and website packages and does not prove FreePBX, SIP, call/audio or provider
integration. The static site is built with `python3 tools/build_site.py`; packages
are generated under ignored `artifacts/` by `python3 tools/package.py`.

## Roadmap

1. Prove module installation, permissions, settings, CLI discovery and the
   supported create/reload/read-back profile and host-scheduled reconciliation on
   disposable FreePBX 17 hosts.
2. Implement and independently verify safe native updates, removals and ownership
   tracking before making Revert or Regenerate executable.
3. Prove the implemented standalone Pond's immutable secret-reference continuity
   over authorised separate-host HTTPS, with independent key/token custody and
   the original source unavailable; current evidence is local independent-process
   testing with synthetic PBX state.
4. Expand supported reconstruction to trunks, inbound/outbound routes, numbering,
   destinations and other required modules, with dependency and coverage checks.
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
- [Initial FreePBX 17 source-to-target test](docs/initial-test.md)
- [Capabilities and roadmap](docs/capabilities-and-roadmap.md)
- [Provider contracts](docs/provider-contracts.md)
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
