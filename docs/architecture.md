# Respawn 0.1.0 Architecture

## Centre Of Gravity

Respawn rebuilds supported configuration on a fresh FreePBX through ordered,
typed module operations. A blueprint is executable reconstruction data, not an
inventory or backup reference. The intention is to supersede slow conventional
backup/restore for supported declarative configuration, not historical/media data.

```text
Local protection:
FreePBX modules -> The Puddle (local supported state/revisions) -> Revert

Independent protection:
The Puddle -> The Stream (authenticated publication/read-back) -> The Pond

Recovery objective:
The Pond -> authorised reconstruction -> replacement FreePBX
 -> provider connectivity/routing -> verification -> explicit activation

Regenerate reconciles an executable blueprint on an existing FreePBX.
The Pond <-> The River (optional peer-to-peer Pond replication)

The Lake (optional fleet inventory/policy) coordinates metadata and delegated
actions; it is not required for Pond-to-Pond replication or ordinary recovery.
```

These are logical responsibilities. The module does not connect directly to an
SBC, WHMCS, hypervisor or DOMAINTAINS. A connector may mediate all controller
traffic; this diagram does not assert a private network topology.

## Ownership

| Component | Owns | Must not imply |
| --- | --- | --- |
| GPLv3+ Connectivity module | Settings, local permissions, export, normalisation, change detection, publication, operation controls, typed reconstruction, verification and diagnostics | Local submission means controller acceptance |
| Provider connector | Authenticated transport, identity binding, enrolment, capability negotiation and controller mediation | Its private business implementation belongs in module core |
| Controller | Authorisation, retention, build orchestration, policy authority, progress, fencing, reconciliation | Blueprint acceptance proves full recovery |
| Infrastructure/connectivity adapters | Provider-owned execution and resource receipts | Prepared routing is already active |
| SIP edge | Enforce authorised controller policy and report effective state | A PBX may directly authorise arbitrary routes |

The Pond is the independent retention and authorised recovery-material retrieval
service in the ecosystem; it is not bound to one hosting or telecoms provider. `development/local-pond/`
is a development-only **Development Pond** reference, not the production
controller and not The Puddle. Compatible independent providers implement the
public contracts. No
production-certified retention service is included. The separate experimental
`pond/` service implements SQLite retention and encrypted credential custody;
its independent-process evidence uses synthetic PBX state. Hosting and SBC details remain behind
the provider boundary.

## Current Implementation

The PHP `Application` shares GUI/CLI logic and accepts an injected `Provider`.
The GUI factory offers disabled, explicit mock and neutral HTTPS provider modes.
HTTPS uses a newly specified public JSON profile, not an asserted private endpoint.
Replacement-provider compatibility and its enrolment remain release gates.
`MockProvider` runs locally and never accesses a
network. Its v2 configuration stage uses BuildSpec and BuildExecutor against a
persistent clean SimulatedPbx, not a separate status-only reconstruction loop.

On 24 October 2026 the operator confirmed populated non-secret FreePBX 17 capture
and authenticated HTTPS publication to an independent Debian 13 Pond, with
matching immediate and subsequent read-back. The source ran Debian 12/Asterisk
22. Configuration was complete and independently retained; credentials were not
configured and recovery readiness remained false. This evidence does not cover
replacement-provider orchestration, executable recovery or credential retrieval.
Capture-only commands are manual; scheduled executable-blueprint protection
remains a separate workflow with stricter prerequisites.
FreePbxBuildTarget implements the same BuildTarget contract using inspected Core
user/device and Ringgroups writes, fresh read-only snapshots and a fixed final
framework reload callback. See reconstruction-v2.md for fields and prerequisites.
Legacy v1 Executor/Target remain compatibility-only and are not the new CLI build.

There is no independent daemon, automatic provisioning, generated Asterisk-file
editing or browser-supplied shell execution. Protection reconciliation is exposed
as `fwconsole respawn protection-reconcile`, with a five-minute default interval,
durable local locking/outbox, FIFO retries and independent retrieval checks. The
module does not install a host cron entry or register a verified FreePBX scheduler
job; an administrator must schedule the CLI action. Production orchestration
remains provider-driven and is not implemented here.

The operator model has three distinct actions. **Revert** selects an earlier
retained revision; **Regenerate** reconciles the local supported scope to a
selected blueprint; **Respawn** requests a provider-controlled replacement build
and later routing/activation. Revision capture, comparison and read-only planning
are local. Revert uses The Puddle without The Stream or The Pond. Capture-only
snapshots are not executable inputs to restoration. Revert and Regenerate execute against the synthetic target in tests,
but the native FreePBX reconciliation target rejects updates/removals and marks
ownership unverified. Native revision plans are therefore not executable. The
separate native reconstruction CLI has a fixed explicit framework-reload callback
for supported creates; this is not proof of a native revision recovery path.
Respawn provider lifecycle is tested only through mock/local simulation.

The new protection coordinator does not change reconstruction. It stores exact
blueprints in the local revision cache, queues revision IDs for provider retention,
requires a durable receipt and independently retrieved hash before locally
acknowledging a revision, and periodically rechecks the latest retained copy. The
HTTPS client connects to the standalone `pond/` implementation of the same
contract. SQLite commits precede receipts; exact immutable secret references are
escrowed using AES-GCM with a Pond-held key and read back before readiness.
Independent recovery credentials retrieve a protected bundle consumed by the
existing `FileSecrets` and reconstruction executor. The source-loss test uses a
separate HTTP process and real encryption/storage, but synthetic source/target.
Live credential recovery, FreePBX scheduling and native execution remain lab gates.
Mock/Development Pond/injected evidence cannot become `protected_and_current`.

The five ecosystem components and their target topology are specified in
[ecosystem-architecture.md](ecosystem-architecture.md). River federation is
peer-to-peer at the application-contract level; current local ledger tests do not
implement production peer authentication, encryption, relay transport, key
rotation or cross-Pond recovery authority. The Lake schema is inventory vocabulary
only; no Lake service is implemented. See the separate River, Lake and
[recovery-authority](recovery-authority.md) documents for explicit limits.

`development/local-pond/` is a separately implemented development reference
controller, not module core and not the standalone Pond service. Its
operator UI, persistent service and worker bind only to loopback, speak the public
v2 JSON envelope over real local HTTP, and are excluded from both distributable
archives. The shared `Application` submits operations through its provider
interface; the Development Pond queues persisted jobs and invokes the same v2 planner,
`BuildExecutor`, `FileSecrets` and simulated target adapter used by reconstruction
tests. Its simulated routing model can change state and read the reconstructed
Ring Groups, but never generates production registration/call/audio receipts.
Production HTTPS verification rejects explicit development/simulated evidence.
See the [Development Pond runbook](../development/local-pond/README.md) for tested
commands, recovery scenarios and the evidence boundary.

V2 builds use a target-wide advisory lock, pre-mutation per-object checkpoints,
file fsync, atomic rename and directory fsync. Process-death recovery is tested;
power-loss and filesystem guarantees still need environment-specific proof.
Host-private state roots must be shared by every cooperating reconstruction
runner. Native module writes are not transactional; partial states stop safely.

Large local state uses content-addressed immutable chunks beneath each private
state root's `chunks/`, with an atomic `state.json` reference journal. The API is
unchanged, so revisions, plans, pins, heads, acknowledgements and retries migrate
together without resetting identities or hashes. Small journals remain inline.
Above 512 KiB, arrays over 32 KiB are recursively externalised; files are fsynced
before the reference journal is renamed and its directory fsynced. Unreachable
chunks are removed only after successful commit. The 1 MiB per-journal/chunk
safety bound is unchanged, not an aggregate history limit. Back up the entire
directory while runners are stopped, never just `state.json`. Older software
cannot read the new marker; restore a full pre-upgrade backup before downgrade.

Configured retention remains 2-500 revisions, default 50, with pinned/head
dependencies protected. If pins block that limit, capture fails visibly rather
than discarding recovery data. Supported blueprints remain at most 100 operations;
the HTTPS envelope remains bounded to 64 KiB including blueprint and metadata.
Provision disk/memory for the chosen history and scope; resource failures leave
the last committed history intact.

The standalone Pond promotes a newly retained revision only when its explicit
parent equals the current recovery head (or both are null for initial capture).
Historical and legacy unordered submissions remain retained but cannot promote.
Pre-extension heads/receipts are preserved. A restored source must explicitly
authorise continuation from the verified current head, never infer authority from
its local sequence or receipt arrival time. River/Lake are unchanged.

## Coverage

Count supported fields against discovered fields per module, not against an
invented percentage of the whole PBX. V2 covers a strict selected user/PJSIP/group
profile, original versioned secret references and explicit apply/read-back. Exact
installed modules and compatible global SIP settings remain prerequisites.
Unsupported selected fields stop export. No overall recovery percentage or full
module discovery is claimed. Trunks/routes/media and real service remain gaps.