# Respawn for FreePBX 17

## 0.0.1 Development Work In Progress

### Corrective Review Pass Complete

Branch `0-0-1_Dev`, HEAD `147925b01d56896b3addc328a5e85c21d8a606b6`,
empty index and the existing unstaged work are preserved. The exact temporary
full-review ZIP outside the repository was removed as requested; no new full-tree
ZIP will be generated. This pass addresses provider-connection isolation,
historical readiness visibility, session-independent GUI reconciliation,
consistent v2 canonical hashing and actionable sanitised diagnostics. Focused
regressions include revision-regression quarantine, secret/error redaction and
lookup-only reconciliation versus explicit retry. Website typography/layout,
responsive sizing, dedicated 404 metadata and Apache ErrorDocument packaging are
validated locally. Reconstruction scope and live-integration limits remain
unchanged; host mapping is not live-tested.

### Reconstruction Strengthening Pass

Starting state: branch `0-0-1_Dev`, HEAD
`147925b01d56896b3addc328a5e85c21d8a606b6`, empty index and the existing
unstaged scaffold preserved in `artifacts/reconstruction-pass-starting-source.tgz`.
Review found that the original executor persists checkpoints only after its whole
callback returns, checks later object conflicts after earlier mutations, and has
no apply boundary. Its mock configuration stage bypasses the executor. The existing
Ring Groups doubles mirror implementation arguments rather than independently
establishing FreePBX semantics. These findings are fixed locally and documented in
[the review record](docs/review-pass.md), not presented as live failures reproduced.

Now implemented: a v2 selected Core user -> fixed PJSIP device -> fallback/primary
Ring Groups chain, original-credential references, exact module-version preflight,
file/directory-fsynced per-operation checkpoints, target locking, unknown-outcome
reconciliation and one final apply/read-back boundary. The native source/target
contract, 40 executor/secret/failure/restart/concurrency invariants and the expanded
two-PBX demo pass locally. The existing suite now includes stricter timestamp,
apply-evidence and request-binding checks and promotes warnings to failures.

Pinned source inspected: Core `fb43dea0ba9133319a21e6ea9e2ee3c6f7bc61f0`
(17.0.18.56), Ring Groups `3b6f9a90c3fe56d27d460fdc74a4dda24390fecc`
(17.0.2.8), Framework `90929231b801d865556a57875ddd25a74d283f8d`
(17.0.33.2). These are inspected source versions, not tested runtime versions.
Current documentation, independently validated v2 schema and all ten website
pages describe the same selected scope. No native
FreePBX reconstruction, reload, endpoint registration, call, audio or takeover
has been executed or verified here.

This section records the current local scaffold work. The historical prototype
description below is retained for context: its source is not available in this
repository, and its reported lab results have not been independently reproduced.

**Current architecture:** FreePBX configuration export -> versioned, executable
reconstruction blueprint -> provider-authorised Respawn operation -> fresh PBX
provisioning -> ordered module-by-module configuration reconstruction ->
connectivity and SBC routing preparation -> verification -> policy-controlled
activation. A blueprint must describe validated configuration operations, not
merely inventory, backup locations or a recovery plan. Operations must use
supported module interfaces, never unrestricted shell commands.

Respawn is the GPLv3+ open-source **Connectivity** module. A separately licensed
provider connector mediates external services. The Pond is our controller
implementation; alternative providers and controllers must remain possible.
Hosting automation, WHMCS service/billing reconciliation, DOMAINTAINS integration
and SBC orchestration belong behind that provider boundary, not in module core.
No private production implementation or credentials are available here.

### Local Progress And Evidence

| Area | Current evidence | Remaining work |
| --- | --- | --- |
| Baseline | Clean `0-0-1_Dev` branch inspected; README and GPL licence read | Preserve local, unstaged work throughout |
| Existing source | Original repository contained only README and LICENSE | No earlier prototype implementation available |
| Firewall intent | IPv4 /32 canonicalisation, deduplication, ordering, timestamp-independent hashing and mock publication regressions pass | Native Firewall and live enforcement unverified |
| Read-only adapter boundary | Interface, explicit fixture source and fail-closed unavailable source added; syntax checked | Compatible FreePBX 17 runtime verification |
| Local persistence | Per-operation fsync/rename/directory-fsync, target lock, corruption preservation and real-process interruption/concurrency checks pass | Native storage/power-loss guarantees and non-cooperating administrator writes unverified |
| Reconstruction | V2 selected users -> fixed PJSIP devices -> dependent Ring Groups; original versioned secret refs; preflight, checkpoints, apply and source/target read-back contracts pass | Real FreePBX proof; installed modules/global SIP are prerequisites; trunks/routes/voicemail remain gaps |
| Provider and operations | Persistent mock uses the actual planner/executor; connection-scoped state, retained original connections, readiness-expiry visibility, revision quarantine, explicit reconciliation/retry and sanitized diagnostics pass focused tests; neutral HTTPS profile validates original request, strict freshness and bound readiness receipts | Real TLS/provider integration, enrolment, signatures and private-service execution unverified |
| FreePBX integration | Experimental metadata, Connectivity GUI, provider settings, lifecycle class, fwconsole and explicit reconstruction commands; PHP syntax passes | Actual installation, permissions, menu, CSRF/session and CLI integration checks |
| Website | Ten static pages, 26 FAQs, public v2 schema, responsive section layouts, dedicated noindex 404 page and Apache ErrorDocument mapping in the cPanel ZIP; 22 extracted desktop/mobile HTTP/browser checks and screenshots | Not deployed; cPanel/Apache mapping, hosting and DNS behaviour unverified |
| Reference projects | Native Dial-Tone, distinct AI Dial-Tone, rendered house reference and pinned FreePBX sources recorded | Private provider/service source unavailable |

An unsigned experimental module archive and cPanel website package are built
locally. Packaging and syntax do not establish FreePBX installability, live
reconstruction, provider acceptance or recovery. This development scaffold does
not complete the wider takeover objective.
The table reflects files actually present, not intended changes. Run
`sh tools/check.sh` for the current full local gate, including native-shaped
source/target contracts, real-process checkpoint recovery and independent JSON
Schema validation. Selected users/devices no longer need to pre-exist: they are
recreated before the fallback and primary groups. Unsupported settings, missing
original credentials, partial objects and conflicts block the build. Saved state,
apply, runtime, registration and live call readiness are deliberately distinct.

### Operational Objective And Safety

The ambition is verified takeover **in minutes**, not a measured result. Record
invocation-to-provisioned, configuration-ready, connectivity-ready,
verified-takeover and successful inbound/outbound test-call milestones, including
bidirectional audio evidence. Mock timestamps are not operational measurements.
Real hosting, secrets, licences, numbering, endpoint reconnection and SBC control
remain dependencies requiring explicit contracts and separately authorised lab
testing. Existing calls and historical/media data are not recovered merely by
rebuilding declarative configuration.

Replacement creation, routing preparation and live activation are distinct.
Rehearsal and standby must not redirect production traffic. Cancellation and
operation-owned resource teardown must be retryable, preserve shared resources
and require explicit active-routing disposition. Never silently fail back to an
unhealthy source PBX or infer external success from a local submission.

All current work is local development. Nothing is to be staged, committed,
pushed, remotely published, deployed or executed against live infrastructure.
No real FreePBX installation, reconstruction or takeover has been verified here.

### Build, Demo And Review

```sh
python3 -m pip install -r requirements-dev.txt
python3 -m playwright install chromium
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
```

### Local Pond End-To-End Demonstration

The isolated development reference controller is in
[development/local-pond](development/local-pond/README.md). Start the real
loopback HTTP service, persistent worker and browser console with
`sh development/local-pond/bin/start.sh`; it prints the browser URL. Stop with
`sh development/local-pond/bin/stop.sh` and reset only the marker-owned demo with
`sh development/local-pond/bin/reset.sh`. This runs the shared Respawn Application,
v2 provider envelopes, source adapter, BuildExecutor, protected synthetic secrets,
checkpoint journal and simulated replacement target. Routing activation changes
only the local routing model. It is not a FreePBX/Asterisk runtime, real provider,
SIP, call/audio or production readiness evidence. Local Pond, its UI and credentials
are excluded from both distributable packages; see the runbook for scenarios and
the tested local-vs-simulated boundary.

Chromium needs host libraries; see [validation](docs/validation.md) for this
container's user-local library path. Open [the local site](build/site/index.html).
Local packages are [the module archive](artifacts/respawn-0.0.1.tgz) and
[the cPanel ZIP](artifacts/respawn-site-0.0.1.zip). Neither is published.

Read [architecture](docs/architecture.md), [v2 fields/dependencies/interfaces and opt-in lab steps](docs/reconstruction-v2.md),
[provider contracts](docs/provider-contracts.md), [real takeover dependencies/timings](docs/operational-path.md),
[CLI/two-PBX demo](docs/cli-and-demo.md), [operation runbooks](docs/operations.md),
[FreePBX development](docs/freepbx-development.md), [security](docs/security.md),
[cPanel upload/rollback](docs/cpanel.md), [capabilities/roadmap](docs/capabilities-and-roadmap.md)
and [reference inspection](docs/references.md). The [neutral HTTPS profile](docs/https-profile.md)
defines the implemented public transport, not private endpoints. Final evidence and inventory are
recorded in [the handover](docs/handover.md).

---

## Historical Prototype Context

Respawn is an experimental FreePBX recovery and SIP edge control-plane project.

At its simplest, Respawn is intended to become a small, controlled set of commands that allow a FreePBX system to report its current recovery and routing intent to an external controller known as **The Pond**.

The goal is that a PBX can be enrolled, described, monitored, and eventually regenerated through a predictable command-led workflow, rather than through a collection of manual recovery steps.

The current direction is to collapse complex PBX recovery and SIP rerouting into a simple operational model:

```text
PBX → Respawn Agent → The Pond → Signed Edge Policy → SIP Edge Enforcement
```

The intended operator experience is deliberately simple:

```bash
respawn publish
```

Or, for a specific area of PBX intent:

```bash
respawn publish firewall
```

The command should collect the relevant local PBX state, produce a deterministic intent document, and publish that intent to The Pond. The Pond then validates, signs, stores, and distributes the resulting policy or blueprint.

This repository is currently informational. It documents what has been proven in the prototype, what Respawn is intended to become, and what remains to be built. It does not currently contain production-ready code or public installation instructions.

> **Note on "signed" terminology.** Throughout this document, "signed" describes the *intended* production model in which The Pond cryptographically signs policy. The current prototype produces **versioned and drift-checked** policy; cryptographic signing is not yet implemented. See Current Limitations.

## Purpose

Respawn is intended to explore whether a FreePBX system can continuously report enough trusted operational state to an external controller to support controlled recovery, replacement, and SIP edge rerouting.

Respawn is not a conventional backup tool. Its long-term aim is to make full-system backup restoration less central to FreePBX recovery by regenerating PBX configuration from module-reported blueprints held by The Pond.

Traditional backups may still be useful for historical and non-declarative data such as CDRs, logs, call recordings, voicemail, custom sound files, and other artefacts that are not cleanly represented as declared configuration.

The intended recovery path is blueprint-led regeneration rather than full-system restoration. How much of a PBX can be cleanly regenerated from declared blueprints, versus recovered from backup, is itself part of what Respawn is exploring.

The project treats recovery as a control-plane problem involving:

- PBX identity
- PBX state export
- Backup and blueprint references
- Firewall and trusted-source intent
- SIP edge policy
- DNS or routing changes
- Replacement PBX provisioning
- Signed policy enforcement (intended; see terminology note)

The target outcome is controlled regeneration of a failed PBX, followed by policy-driven traffic rerouting.

## Current Status

Respawn is in prototype development.

The current prototype has demonstrated a limited but working control-plane path:

```text
FreePBX Firewall Trusted Zone
→ PBX-Side Respawn Agent
→ Canonical Policy Candidate
→ Controller-Side Validation
→ Versioned SIP Edge Policy
→ Kamailio Policy Enforcement
```

The current prototype focuses on one specific type of PBX intent:

- Trusted SIP source addresses derived from the FreePBX firewall trusted zone

This is not a full PBX recovery system yet. It is a working building block for the wider Respawn architecture.

## What Has Already Been Achieved

The prototype has already proven the following behaviours in a live development lab:

- Multiple FreePBX systems can publish separate intent.
- Each PBX can be represented as a separate identity.
- Each PBX can publish through a restricted per-PBX inbox.
- FreePBX firewall trusted zone changes can be exported.
- Trusted-source intent can be normalised into a canonical candidate.
- Candidate changes can be detected by hashing operational intent.
- Timestamp churn does not cause unnecessary republishes.
- No-change runs are suppressed cleanly.
- Added and removed trusted sources are logged clearly.
- A controller-side receiver can validate PBX candidates.
- Accepted candidates can be merged into a full SIP edge policy.
- The full policy can preserve multiple PBX domains at once.
- The generated edge policy can be applied atomically.
- Kamailio can enforce the generated source-gated routing policy.
- Drift checks can confirm applied policy matches generated policy.
- Effective policy reports can show what the edge is enforcing.

The current lab has demonstrated two separate FreePBX systems publishing intent into the same edge policy without overwriting each other.

The prototype has also demonstrated that adding or removing a trusted IP in FreePBX can be reflected into the generated SIP edge policy.

Source matching has currently been proven for individual `/32` host entries. Broader CIDR range enforcement is part of the intended policy model but has not yet been proven in this prototype.

## Prototype Command Model

The prototype has already been collapsed into a small PBX-side command surface.

The operator-facing command is `respawn`. The local component it drives is the Respawn Agent.

Current examples include:

```bash
respawn export firewall
```

Exports the relevant FreePBX Firewall state.

```bash
respawn publish firewall
```

Exports the firewall state, builds a policy candidate, detects whether the canonical intent has changed, and publishes only when required.

```bash
respawn publish firewall --force
```

Forces a publish even when the canonical intent hash has not changed.

The target direction is that Respawn becomes command-driven, predictable, and automatable.

The larger recovery model should eventually be expressed through commands such as:

```bash
respawn enrol
respawn publish
respawn status
respawn blueprint
respawn verify
```

Those commands are directional examples, not final public interfaces.

## Current Prototype Flow

The current development flow is:

1. FreePBX Firewall Trusted Sources are read from FreePBX state.
2. The PBX-side exporter writes a structured firewall export.
3. The Respawn Agent builds a policy candidate from that export.
4. The agent hashes the canonical intent.
5. If the intent has changed, the candidate is published.
6. The controller-side receiver validates the candidate.
7. The controller/mock merges the candidate into the full edge policy.
8. A new versioned policy is written. (Cryptographic signing is planned and is currently represented only by the controller/mock signing stage.)
9. The SIP edge policy apply process updates the active policy.
10. Kamailio enforces the generated routing include file.
11. Drift and effective-policy checks confirm the applied state.

## Current Prototype Components

The current prototype covers:

- PBX-side FreePBX firewall trusted-source export
- PBX-side Respawn agent
- Canonical intent hashing
- Change and no-change detection
- Restricted publish path
- Per-PBX identity separation
- Controller-side candidate validation
- Full edge policy regeneration
- Versioned policy output (signing planned; not yet implemented)
- Kamailio generated routing include file
- Policy drift checking
- Effective policy reporting

The current prototype does not yet cover:

- Full PBX blueprint generation
- Replacement PBX provisioning
- Trunk migration
- Endpoint migration
- DNS failover orchestration
- Production enrolment
- Production key rotation
- Production revocation
- Customer-facing installation

## Architecture

The intended architecture has three main roles:

- PBX system
- The Pond
- SIP edge node

### PBX System

The PBX system runs a local Respawn Agent.

The agent is responsible for:

- Reading selected FreePBX state
- Normalising that state into deterministic intent
- Detecting meaningful changes
- Publishing intent to The Pond
- Reporting local agent status
- Eventually maintaining a current Respawn blueprint

In the current prototype, the agent reads trusted sources from the FreePBX Firewall Trusted Zone.

### The Pond

The Pond is the planned external controller for Respawn.

Its expected responsibilities include:

- PBX enrolment
- PBX identity validation
- Intent validation
- Policy generation
- Policy signing
- Blueprint storage
- Replacement PBX orchestration
- Edge policy distribution
- Key rotation and revocation

The Pond should be the trust authority.

SIP edge nodes should trust signed controller policy, not individual PBX systems directly.

In the current development prototype, a temporary controller/mock performs part of this role. The policy signing stage is currently a placeholder: policy is versioned and drift-checked, but not cryptographically signed.

### SIP Edge Node

The SIP edge node enforces the active routing policy.

In the current prototype, Kamailio is used as the SIP edge enforcement layer.

The edge policy is generated into routing rows containing:

- Domain
- Mode
- Allowed source
- Backend address
- Backend port

The current prototype supports IP-gated routing behaviour for PBX domains, proven for `/32` host sources.

## Current Policy Model

The current prototype policy model is intentionally narrow.

A PBX publishes intent for a SIP domain and backend, including a set of trusted source addresses. The controller validates that candidate and produces an edge policy.

A generated policy row is conceptually:

```text
Domain | Mode | Source | Backend IP | Backend Port
```

Example modes under development include:

- `ip_gated`
- `disabled`
- `roaming_auth`

Only the currently implemented prototype behaviour should be treated as tested. In particular, `ip_gated` is proven for `/32` host sources; `roaming_auth` is represented in the model but not yet implemented as live enforcement.

## Identity and Trust Model

The current development prototype uses restricted per-PBX inboxes as a practical workaround.

The intended production trust model is:

```text
PBX Owns Its Private Key
The Pond Owns Enrolment And Trust Decisions
The Pond Validates PBX Intent
The Pond Signs Policy
SIP Edge Nodes Enforce Signed Policy
```

The PBX should not be able to directly instruct an edge node to change routing without controller validation.

The edge node should not need to trust every PBX individually. It should trust The Pond.

In the current prototype, the controller/edge-side installer issues per-PBX key material and the PBX-side installer consumes it. This is a deliberate development approximation of "The Pond owns enrolment", chosen in preference to PBXs self-generating trust and asking to be accepted. The production direction (for example PBX-generated CSR or mTLS enrolment) is not yet implemented.

## Recovery Model

The long-term Respawn recovery model is intended to be blueprint-led rather than snapshot-led.

Instead of treating a full-system backup as the primary source of PBX configuration recovery, Respawn is intended to allow a replacement PBX to be regenerated from current declared intent held by The Pond.

A conventional backup restores machine state, including accumulated drift, stale configuration, and potentially broken state from the moment the backup was captured. A Respawn blueprint should describe what the PBX configuration is intended to be, allowing a replacement system to be built from declared module state rather than restored wholesale from an old snapshot.

A blueprint may eventually include:

- FreePBX version and module state
- Trunk configuration
- Extension and endpoint state
- Routing state
- Firewall and trusted-source policy
- SIP edge policy
- DNS or routing dependencies
- Backup references
- Provisioning metadata

Traditional backups may still be used as secondary recovery sources for historical or non-declarative data, including CDRs, logs, call recordings, voicemail, custom sound files, and other artefacts that are not yet represented in a module blueprint.

The exact blueprint format is not yet defined, and the boundary between blueprint-regenerable configuration and backup-recovered data is part of what the project is intended to explore.

The recovery target is controlled regeneration of a replacement PBX, followed by policy-driven traffic rerouting.

## Current Limitations

Respawn is not currently production-ready.

Known limitations include:

- **Policy is versioned and drift-checked, but not yet cryptographically signed.** Cryptographic signing is planned and is currently represented only by the controller/mock signing stage. "Signed" elsewhere in this document refers to the intended production model.
- **Source matching is proven for individual `/32` host entries only.** Broader CIDR range enforcement is part of the intended policy model but has not yet been proven in this prototype.
- `roaming_auth` mode is represented in the policy model but is not yet implemented as live enforcement.
- No production Pond API
- No production enrolment flow
- No production key rotation
- No production revocation model
- No full FreePBX blueprint format
- No replacement PBX build process
- No trunk migration process
- No endpoint migration process
- No production DNS orchestration
- No customer-facing installer

The current development flow uses a temporary controller/mock implementation. It is suitable for proving control-plane behaviour, not for production deployment.

## Security and Repository Hygiene

This is experimental development software. When working with the prototype or its installers, do not commit or publicly host any generated output. In particular, never add the following to this repository or any public location:

- Generated private keys or per-PBX key material
- Populated agent configuration (for example `/etc/20tele/respawn-agent.json` with real values)
- Terminal logs or installer output containing key material or secrets
- Generated policy files containing live infrastructure addresses you do not wish to disclose

The installer scripts themselves are development tooling. The risk is in their *output*, not their existence. Treat any key material issued during enrolment as a secret.

## Repository Status

This repository currently exists to document the concept, prototype status, and development direction.

It should not be treated as installable production software.

No guarantees are made about interface stability, schema stability, deployment safety, or compatibility.

## Development Direction

Planned areas of work include:

- Formal Pond enrolment flow
- Signed PBX intent
- Cryptographic policy signing (replacing the current versioned-only output)
- Controller-side policy authority
- Key rotation and revocation
- Defined Respawn blueprint schema
- Expanded FreePBX state export
- Replacement PBX provisioning
- Kamailio edge policy hardening (including broader CIDR enforcement)
- Failure detection
- Controlled reroute workflow
- Test fixtures and repeatable lab deployment

## Licence

GPLv3+. See LICENSE.

## AI Disclosure

This project has been developed with AI assistance for code generation, review, testing, and documentation. Changes should still be reviewed, tested, and accepted by a human maintainer before deployment.

## Author

@kierknoby, Kieran Knowles-Byrne // FreePBX UK
