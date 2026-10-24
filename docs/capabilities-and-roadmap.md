# Capability Evidence And Priorities

The initial reconstruction profile is intentionally limited; the long-term
objective is comprehensive FreePBX configuration recovery through progressively
expanded, version-aware module support. This is an objective, not a claim that all
modules are currently supported or universally compatible.

## FreePBX Module Coverage

| Coverage stage | Configuration objects and boundaries |
| --- | --- |
| Currently supported | Selected Core users without voicemail, fixed one-user/one-device PJSIP endpoints with immutable credential references, and selected simple Ring Groups. Export and reconstruction remain restricted to the validated v2 profile and exact module prerequisites. |
| Being developed | Broader fields and relationships in the existing Core/PJSIP/Ring Groups profile; native FreePBX integration evidence, updates/removals and ownership safety remain prerequisites before enabling destructive reconciliation. |
| Planned | Extensions and devices beyond the fixed profile; trunks; inbound/outbound routes; IVRs; queues; time conditions and groups; announcements; Follow Me; conferences; voicemail configuration; feature codes; miscellaneous applications and destinations; custom destinations; recording configuration and associated assets; and other standard FreePBX modules. This list is not exhaustive. |
| Further investigation | Commercial modules where technically and legally feasible; version-specific interfaces and dependencies; global settings, licences, secrets, generated or non-declarative state, and which recording/media assets require separate retention. Unsupported state must remain visible and blocking where necessary. |

Each module handler must use supported FreePBX interfaces, declare schema and
version constraints, validate dependencies and secret references, and provide
read-back evidence. Coverage is measured per discovered module/object/field; a
supported subset must never be presented as complete PBX recovery.

The provider interfaces are suitable for transport and retention implementations
to be added without coupling private hosting services to module core. The current
reconstruction foundation is less extensible: `BuildSpec::ADAPTERS` is a closed
allowlist and validation/execution branches are type-specific. New module support
therefore requires controlled additions to the typed adapter and target contracts;
a general plugin registry is not implemented and is not being introduced as an
unnecessary rewrite in this milestone.

| Capability | Implemented | Fixture/contract tested | Real integration | Status / limitation |
| --- | --- | --- | --- | --- |
| Local Puddle configuration capture | Yes | Synthetic PDO/FreePBX interface doubles cover empty/group/device/credential states, unavailable dependencies, restart, mixed v2 compatibility, change/removal and failed-then-recovered collection | Not yet run on FreePBX 17 | Automatically discovers supported Core users, fixed PJSIP devices and Ring Groups. Distinct hashed capture format; empty collections are valid and capture is non-executable. Incomplete credentials never imply recovery readiness; see `docs/puddle-capture.md` |
| Firewall IPv4 /32 normalisation/hash | Yes | Yes | Unverified | Explicit trusted hosts only |
| FreePBX read-only Firewall adapter | Yes | Shape only | Unverified | Missing/disabled fail; broader sources rejected |
| Executable v2 reconstruction blueprint | Yes | PHP planner and independent JSON Schema | Unverified | Strict selected user/PJSIP/group profile, not whole PBX |
| Dependency-ordered target execution | Yes | Independent native-shaped source/target doubles | Unverified | Creates selected users/devices before groups; modules/global SIP are prerequisites |
| Checkpoints/conflicts/retries/read-back | Yes | Actual process death and competing process | Unverified | Per-operation file/directory fsync, target lock, partial/unknown reconciliation |
| Original credential continuity | Protected versioned references and standalone AES-GCM escrow/recovery bundle | Source-loss exact-password tests with independent process and simulated target | Unverified on FreePBX | Independent Pond key/recovery-token custody required |
| Final apply boundary | Fixed framework reload callback | One apply, failure/retry and receipt invalidation | Unverified | Saved state/reported reload is not runtime or call proof |
| Connectivity menu/settings/lifecycle/CLI | Source implemented | Syntax and packaging | Unverified | Actual BMO/session/RBAC/CLI discovery not proven |
| Publication/acknowledgement/no-change/force | Mock | Yes | Unverified | Single current publication slot per identity |
| Provider endpoint/config and HTTPS profile | Yes | Yes, injected transport | No live network | TLS/network/provider compatibility unverified; enrolment external |
| Respawn launch/reconcile/activation guards | Mock | Yes | Unverified | No automatic production activation |
| Revision capture/comparison/planning | Yes | Yes, including exact publication binding and selected revision catalogues | Unverified | Local cache; native Revert/Regenerate execution blocked |
| Revert / Regenerate | Planner and executor | Synthetic target updates/removals/read-back | Not available | Native adapter is create-only; ownership unverified; revision path lacks authorised apply callback |
| Native replacement reconstruction | Selected creates and fixed framework reload callback in CLI | Native-shaped source/target contract doubles | Unverified | Fresh compatible target only; no native install or runtime proof |
| Automatic supported-scope reconciliation | CLI coordinator; disabled by default; 300-second default | Synthetic unchanged suppression, FIFO A/B/C retries, crash replay, A/B/A, export failure | Not tested on FreePBX | Host cron is an operator prerequisite; no scheduler job is installed |
| Independent retained-revision verification | Experimental standalone SQLite Pond, immutable receipts and parent-conditioned recovery head | Independent HTTP process, restart/source-loss and ordered-history tests | No separate-host deployment | Public HTTPS and physical durability unverified |
| Recoverable credential escrow | AES-GCM vault, exact-password read-back and native-compatible bundle | Independent-process source-loss, rotation, wrong-key/tamper rejection | None on FreePBX | Experimental, not production-certified |
| Local revision storage | Atomic journal and immutable hash-addressed chunks | 50 large revisions, 500 retention, 100 operations, pins/plans/migration/interruption | None on FreePBX | 2-500 retention, 100 operations, 64 KiB HTTPS envelope; resource capacity remains a host prerequisite |
| Scheduler diagnostics | Bounded protected log, heartbeat and health CLI | Failure/recovery, no-refresh reads, rotation and missed/stalled state | None on host cron | External alerting needs independent monitoring |
| Independent PBX heartbeat/monitoring | Not implemented | None | None | Controller cannot distinguish recent check-in from missed check-in; no automatic takeover is implemented |
| Development Pond controller | Yes | Real loopback HTTP, authenticated v2 envelopes, persistent worker/restart, actual Application + BuildExecutor, UI activation/routing model/teardown | No external service | Independent development reference only; all replacement/connectivity/routing evidence is SIMULATED_ONLY |
| Cancellation/owned cleanup/retry | Mock | Yes | Unverified | Real resource APIs/fencing unavailable |
| Two independent PBXs | Synthetic harness | Yes | Unverified | Not two running FreePBX instances |
| Real hosting/WHMCS/DOMAINTAINS/SBC | Contracts only | Mock boundaries | Unverified | Private service implementations required |
| Live call/audio readiness and takeover timings | Evidence contract/tool | Synthetic rejection tests | Unverified | No real result or timing claim |
| Intent/policy signatures | No | Not applicable | Unverified | Planned, hashes are not signatures |
| cPanel static site | Yes | Extracted browser checks | Not deployed | Desktop/mobile screenshots retained |

## Prioritised Next Milestones

1. Prove unsigned module packaging/install/upgrade/uninstall, section authorisation,
   settings persistence, CLI and the supported reconstruction slice in isolated
   FreePBX 17 VMs. Record exact versions and API defaults; adjust only from evidence.
2. Expand the typed module catalogue incrementally: broaden the current Core-user,
   PJSIP and Ring Groups profile, then add module handlers for trunks, routes and
   the roadmap's other standard modules. Discover coverage and dependencies
   instead of treating the initial profile as the intended limit.
3. Prove the HTTPS profile against a separately authorised compatible service;
   verify standalone enrolment, durable revision retention and original-password
   recovery across hosts. Keep the explicit operator-managed cron contract and
   prove its executed heartbeat after reboot. Local implementation is complete
   enough for an isolated test; production verification remains outstanding.
4. Privately implement hosting, WHMCS, DOMAINTAINS/equivalent and SBC contracts,
   controller authority epochs, policy signing, callback/reconciliation semantics
   and safe active-routing disposition. Never implement private billing logic in core.
5. Add independent call/audio probes and perform separately authorised takeover
   tests. Measure the full chain, compare prepared images/warm capacity, record
   partial failure, rollback, residual cost and continuity limitations.

## Native Completion Requirements

The native adapter is intentionally not a general reconciliation engine. Complete
Revert and Regenerate only after each item below has a supported FreePBX interface,
disposable-host evidence and a negative test for unsafe or unknown outcomes:

- Implement allowlisted Core user, PJSIP device and Ring Group updates. Define
   field-by-field semantics, relationship validation, partial-write recovery,
   read-back and the fixed apply/reload boundary.
- Establish ownership independently of a Respawn journal. Preserve pre-existing,
   shared and administrator-created objects. Removal must remain blocked until
   ownership and reverse dependency order are verified; matching parameters or
   local revision history are not sufficient ownership evidence.
- Preserve immutable credential references across capture, retention, rotation,
   source export and target restore. Provide an authorised secret-service delivery
   path and prove endpoint authentication continuity without logging secret values.
- Expand the blueprint/module catalogue to cover trunk registration and
   authentication, inbound DIDs and destination chains, outbound patterns/routes/
   trunk selection, numbering relationships, emergency policy, and every module
   and global setting required by the chosen deployments. Record exact compatible
   versions, licences and dependencies; unsupported state must block claims of
   complete scope.
- Integrate provider-owned replacement provisioning, firewall/connectivity, DNS
   or number-provider changes and SBC route preparation behind neutral contracts.
   Require fresh receipts, authority fencing, explicit activation, reconciliation
   and safe partial cleanup.
- Independently verify target runtime and endpoint registration, inbound and
   outbound calling, destination/number continuity and bidirectional audio. Measure
   each recovery milestone on authorised disposable systems before publishing any
   recovery-time objective.

Configuration read-back alone is not runtime readiness or successful telephony
recovery. None of these native or live-provider gates is satisfied by the mock,
source inspection or contract doubles.

No timetable, production readiness, recovery percentage or achieved-minute target
is invented. A polished website or successful mock is not completion of takeover.