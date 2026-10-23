# Respawn 0.0.1 Architecture

## Centre Of Gravity

Respawn rebuilds supported configuration on a fresh FreePBX through ordered,
typed module operations. A blueprint is executable reconstruction data, not an
inventory or backup reference. The intention is to supersede slow conventional
backup/restore for supported declarative configuration, not historical/media data.

```text
FreePBX modules -> read-only exporters -> reconstruction blueprint
 -> provider connector -> controller retention and authorisation
 -> fresh PBX provisioning -> module reconstruction executor
 -> connectivity and SBC preparation -> verification -> explicit activation
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

The Pond is our separately licensed controller implementation. Compatible
alternative providers/controllers must implement the public contracts. No private
production service is included. WHMCS, hosting and SBC details remain behind the
provider boundary. The module licence does not license those services.

## Current Implementation

The PHP `Application` shares GUI/CLI logic and accepts an injected `Provider`.
The GUI factory offers disabled, explicit mock and neutral HTTPS provider modes.
HTTPS uses a newly specified public JSON profile, not an asserted private endpoint.
Real provider compatibility and enrolment remain release gates.
`MockProvider` runs locally and never accesses a
network. Its v2 configuration stage uses BuildSpec and BuildExecutor against a
persistent clean SimulatedPbx, not a separate status-only reconstruction loop.
FreePbxBuildTarget implements the same BuildTarget contract using inspected Core
user/device and Ringgroups writes, fresh read-only snapshots and a fixed final
framework reload callback. See reconstruction-v2.md for fields and prerequisites.
Legacy v1 Executor/Target remain compatibility-only and are not the new CLI build.

There is no independent daemon, automatic provisioning, generated Asterisk-file
editing or browser-supplied shell execution. Work is synchronous and explicitly
invoked. Production orchestration must be asynchronous and reconciled via provider
operations; a FreePBX job/scheduler hook is not implemented in this scaffold.

`development/local-pond/` is a separately implemented development reference
controller, not module core and not the closed-source production controller. Its
operator UI, persistent service and worker bind only to loopback, speak the public
v2 JSON envelope over real local HTTP, and are excluded from both distributable
archives. The shared `Application` submits operations through its provider
interface; Local Pond queues persisted jobs and invokes the same v2 planner,
`BuildExecutor`, `FileSecrets` and simulated target adapter used by reconstruction
tests. Its simulated routing model can change state and read the reconstructed
Ring Groups, but never generates production registration/call/audio receipts.
Production HTTPS verification rejects explicit development/simulated evidence.
See the [Local Pond runbook](../development/local-pond/README.md) for tested
commands, recovery scenarios and the evidence boundary.

V2 builds use a target-wide advisory lock, pre-mutation per-object checkpoints,
file fsync, atomic rename and directory fsync. Process-death recovery is tested;
power-loss and filesystem guarantees still need environment-specific proof.
Host-private state roots must be shared by every cooperating reconstruction
runner. Native module writes are not transactional; partial states stop safely.

## Coverage

Count supported fields against discovered fields per module, not against an
invented percentage of the whole PBX. V2 covers a strict selected user/PJSIP/group
profile, original versioned secret references and explicit apply/read-back. Exact
installed modules and compatible global SIP settings remain prerequisites.
Unsupported selected fields stop export. No overall recovery percentage or full
module discovery is claimed. Trunks/routes/media and real service remain gaps.