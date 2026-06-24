# Respawn for FreePBX 17

Respawn is an experimental FreePBX recovery and SIP edge control-plane project.

At its simplest, Respawn is intended to become a small, controlled set of commands that allow a FreePBX system to report its current recovery and routing intent to an external controller known as **The Pond**.

The goal is that a PBX can be enrolled, described, monitored, and eventually regenerated through a predictable command-led workflow, rather than through a collection of manual recovery steps.

The current direction is to collapse complex PBX recovery and SIP rerouting into a simple operational model:

```text
PBX → Respawn Agent → The Pond → Signed Edge Policy → SIP Edge Enforcement
```

The intended operator experience is deliberately simple:

```bash
respawn-agent publish
```

Or, for a specific area of PBX intent:

```bash
respawn-agent publish firewall
```

The command should collect the relevant local PBX state, produce a deterministic intent document, and publish that intent to The Pond. The Pond then validates, signs, stores, and distributes the resulting policy or blueprint.

This repository is currently informational. It documents what has been proven in the prototype, what Respawn is intended to become, and what remains to be built. It does not currently contain production-ready code or public installation instructions.

## Purpose

Respawn is intended to explore whether a FreePBX system can continuously report enough trusted operational state to an external controller to support controlled recovery, replacement, and SIP edge rerouting.

Respawn is not intended to replace normal FreePBX backups. It is intended to sit above and around them.

The project treats recovery as a control-plane problem involving:

- PBX identity
- PBX state export
- Backup and blueprint references
- Firewall and trusted-source intent
- SIP edge policy
- DNS or routing changes
- Replacement PBX provisioning
- Signed policy enforcement

The long-term aim is controlled regeneration of a failed PBX, followed by policy-driven traffic rerouting.

## Current Status

Respawn is in prototype development.

The current prototype has demonstrated a limited but working control-plane path:

```text
FreePBX Firewall Trusted Zone
→ PBX-Side Respawn Agent
→ Canonical Policy Candidate
→ Controller-Side Validation
→ Signed SIP Edge Policy
→ Kamailio Policy Enforcement
```

The current prototype focuses on one specific type of PBX intent:

- Trusted SIP source addresses derived from the FreePBX Firewall Trusted Zone

This is not a full PBX recovery system yet. It is a working building block for the wider Respawn architecture.

## What Has Already Been Achieved

The prototype has already proven the following behaviours in a live development lab:

- Multiple FreePBX systems can publish separate intent.
- Each PBX can be represented as a separate identity.
- Each PBX can publish through a restricted per-PBX inbox.
- FreePBX Firewall Trusted Zone changes can be exported.
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

## Prototype Command Model

The prototype has already been collapsed into a small PBX-side command surface.

Current examples include:

```bash
respawn-agent export firewall
```

Exports the relevant FreePBX Firewall state.

```bash
respawn-agent publish firewall
```

Exports the firewall state, builds a policy candidate, detects whether the canonical intent has changed, and publishes only when required.

```bash
respawn-agent publish firewall --force
```

Forces a publish even when the canonical intent hash has not changed.

The target direction is that Respawn becomes command-driven, predictable, and automatable.

The larger recovery model should eventually be expressed through commands such as:

```bash
respawn-agent enrol
respawn-agent publish
respawn-agent status
respawn-agent blueprint
respawn-agent verify
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
8. A new signed or versioned policy is written.
9. The SIP edge policy apply process updates the active policy.
10. Kamailio enforces the generated routing include.
11. Drift and effective-policy checks confirm the applied state.

## Current Prototype Components

The current prototype covers:

- PBX-Side FreePBX Firewall Trusted-Source Export
- PBX-Side Respawn Agent
- Canonical Intent Hashing
- Change And No-Change Detection
- Restricted Publish Path
- Per-PBX Identity Separation
- Controller-Side Candidate Validation
- Full Edge Policy Regeneration
- Signed Or Versioned Policy Output
- Kamailio Generated Route Include
- Policy Drift Checking
- Effective Policy Reporting

The current prototype does not yet cover:

- Full PBX Blueprint Generation
- Replacement PBX Provisioning
- Trunk Migration
- Endpoint Migration
- DNS Failover Orchestration
- Production Enrolment
- Production Key Rotation
- Production Revocation
- Customer-Facing Installation

## Architecture

The intended architecture has three main roles:

- PBX System
- The Pond
- SIP Edge Node

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

- PBX Enrolment
- PBX Identity Validation
- Intent Validation
- Policy Generation
- Policy Signing
- Blueprint Storage
- Replacement PBX Orchestration
- Edge Policy Distribution
- Key Rotation And Revocation

The Pond should be the trust authority.

SIP edge nodes should trust signed controller policy, not individual PBX systems directly.

In the current development prototype, a temporary controller/mock performs part of this role.

### SIP Edge Node

The SIP edge node enforces signed routing policy.

In the current prototype, Kamailio is used as the SIP edge enforcement layer.

The edge policy is generated into routing rows containing:

- Domain
- Mode
- Allowed Source
- Backend Address
- Backend Port

The current prototype supports IP-gated routing behaviour for PBX domains.

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

Only the currently implemented prototype behaviour should be treated as tested.

## Identity And Trust Model

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

## Recovery Model

The long-term Respawn recovery model is expected to involve a PBX maintaining a current external blueprint with The Pond.

A blueprint may eventually include:

- FreePBX Version And Module State
- Trunk Configuration
- Extension And Endpoint State
- Routing State
- Firewall And Trusted-Source Policy
- SIP Edge Policy
- DNS Or Routing Dependencies
- Backup References
- Provisioning Metadata

The exact blueprint format is not yet defined.

The recovery target is controlled regeneration of a replacement PBX, followed by policy-driven traffic rerouting.

## Current Limitations

Respawn is not currently production-ready.

Known limitations include:

- No Production Pond API
- No Production Enrolment Flow
- No Production Key Rotation
- No Production Revocation Model
- No Full FreePBX Blueprint Format
- No Replacement PBX Build Process
- No Trunk Migration Process
- No Endpoint Migration Process
- No Production DNS Orchestration
- No Customer-Facing Installer

The current development flow uses a temporary controller/mock implementation. It is suitable for proving control-plane behaviour, not for production deployment.

## Repository Status

This repository currently exists to document the concept, prototype status, and development direction.

It should not be treated as installable production software.

No guarantees are made about interface stability, schema stability, deployment safety, or compatibility.

## Development Direction

Planned areas of work include:

- Formal Pond Enrolment Flow
- Signed PBX Intent
- Controller-Side Policy Authority
- Key Rotation And Revocation
- Defined Respawn Blueprint Schema
- Expanded FreePBX State Export
- Replacement PBX Provisioning
- Kamailio Edge Policy Hardening
- Failure Detection
- Controlled Reroute Workflow
- Test Fixtures And Repeatable Lab Deployment

## Licence

GPLv3+. See LICENSE.

## AI Disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. Changes should still be reviewed, tested, and accepted by a human maintainer before deployment.

## Author

@kierknoby, Kieran Knowles-Byrne // FreePBX UK
