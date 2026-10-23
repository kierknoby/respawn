# Respawn Ecosystem Architecture

## Purpose

Respawn is one recovery ecosystem with five logical components. It can begin with
one PBX/Puddle/Stream/Pond. River and Lake are optional. Component names describe
responsibilities, not five required products or five always-running services.

```text
FreePBX modules
   | read-only supported-scope inspection
   v
THE PUDDLE -- local blueprint/revision state
   | THE STREAM -- scheduled reconciliation, outbox, retries, verification
   v
THE POND -- independent controller and durable retained-revision service
   |                    |
   |             THE RIVER -- authorised peer replication
   |                    |
   +------ THE LAKE -- optional fleet inventory, policy and coordination
                        |
                        v
        authorised recovery request to a Pond
                        |
                        v
 fresh FreePBX -> same typed reconstruction engine -> verify
                        |
            provider routing authority / activation
```

The repository includes an independently deployable **experimental standalone
Pond** under `pond/`: SQLite retention, scoped enrolment/authentication, replay
checks, AES-GCM escrow and source-independent recovery bundles. It is separate
from the **Development Pond** under `development/local-pond/`, which remains a
reference/simulation environment. Neither is a **production-verified Pond**;
actual separate-host TLS, FreePBX and resilience tests remain outstanding.

## Components

| Component | Owns | Must not imply |
| --- | --- | --- |
| **The Puddle** | Local current valid supported blueprint, retained revisions, observation state, pins and pending outbox | The only copy needed for recovery; its state survives loss of the PBX |
| **The Stream** | Scheduled inspection, canonical hash comparison, capture trigger, durable pending publication, retry, acknowledgement/retrieval verification and protection status | A permanent daemon, instant change events, or that an HTTP success means durable storage |
| **The Pond** | Stable Pond identity, authenticated PBX enrollment, immutable retained revisions, credential policy and authorised recovery operations | A one-PBX-per-Pond restriction, provider-specific infrastructure, or complete PBX protection without scope evidence |
| **The River** | Bilateral Pond federation, explicit scopes, immutable replica transactions, receipts, recovery delegation and reconciliation | Shared databases, a mandatory central relay, or permission to decrypt every tenant's secrets |
| **The Lake** | Optional tenant/fleet inventory, delegated read and operator roles, policies and cross-Pond coordination | Sole storage, sole recovery authority, or a requirement for ordinary Pond/Stream operation |

The existing `development/local-pond/` is the **Development Pond**: a loopback-only
synthetic reference controller. It is not The Puddle and not a production Pond.

## Identities

- `tenantId`: administrative/data-isolation boundary.
- `fleetId`: optional grouping under a tenant; not a trust grant.
- `pondId`: permanent controller identity, independent of hostname, IP, region or
  hosting provider. Migration preserves it; re-enrollment/key rotation changes
  credentials, not identity. Retirement/revocation is explicit.
- `sourcePbxId`: existing Respawn identity used by existing blueprint and
  publication schemas. Do not silently rename or reassign it.
- `replacementPbxId`: the replacement's own Respawn identity, distinct from the
  source identity. It does not replace the source identity embedded in the
  recovery blueprint.
- `blueprint contentHash`: immutable canonical content identity already defined
  by reconstruction v2.
- `revisionId`: local retained-revision identity (`rev-` plus 32 hex characters).
  It is not the blueprint hash and is not re-generated when replicating.
- `operationId`: provider/recovery operation identity, scoped by its Pond and
  authenticated source PBX.
- `federationId`: explicit bilateral relationship identity, not a Pond identity.
- `transactionId` / `idempotencyKey`: stable replication transaction identity and
  retry key. They do not grant access.
- `provider connectionId`: existing transport/trust-profile identity. It is
  separate from permanent `pondId` and must not be used as Pond identity.

Pond identity metadata schema is [`contracts/pond-identity.v1.schema.json`](../contracts/pond-identity.v1.schema.json).
River agreements, messages, receipts and recovery delegation are described in
[`contracts/river-agreement.v1.schema.json`](../contracts/river-agreement.v1.schema.json),
[`contracts/river-federation.v1.schema.json`](../contracts/river-federation.v1.schema.json),
[`contracts/river-receipt.v1.schema.json`](../contracts/river-receipt.v1.schema.json) and
[`contracts/recovery-delegation.v1.schema.json`](../contracts/recovery-delegation.v1.schema.json).
Lake inventory vocabulary is [`contracts/lake-fleet.v1.schema.json`](../contracts/lake-fleet.v1.schema.json).
These are public contract foundations, not identity issuance, key storage or a
production service.

## Trust And Data Flow

1. The Puddle exporter reads only the supported selected FreePBX scope.
2. The Stream validates the existing v2 blueprint, compares its canonical hash,
   records a local revision, pins it in a durable local FIFO outbox and submits
   that exact revision through a configured provider interface.
3. A Pond authenticates the PBX, validates tenant/PBX/scope and blueprint bytes,
   persists the immutable revision and only then returns a receipt binding Pond,
   PBX, connection, revision, publication, version and hash.
4. The Stream independently retrieves that revision over the authenticated
   provider contract, rechecks identity and hash, then marks local acknowledged
   and verified heads. A later scheduled retrieval verifies continuing
   availability. Local/Mock receipt modes cannot report protected-and-current.
5. An authorised Pond may queue the immutable source revision to a permitted peer
   Pond over River. The receiver validates the peer agreement and full blueprint,
   persists its own replica, and returns a receipt. The sender records confirmed
   replication only after receipt binding succeeds.
6. A Pond, not the Lake, accepts a separately authorised recovery request and
   invokes the existing reconstruction engine. Routing preparation and activation
   remain governed by the relevant provider/routing authority.

Credentials are not blueprint fields. They remain protected references. The
source module resolves original values from a protected manifest and escrows them
to the standalone Pond's AES-GCM vault. Independent recovery writes a protected
native-compatible manifest without source access. This is independently tested
over loopback with synthetic PBX state, not real FreePBX or public TLS. No secret
is replicated by River's current synthetic blueprint method; River credential
recovery remains unimplemented.

## Topologies

- **A / Individual PBX:** one Puddle, Stream and Pond; no River/Lake required.
- **B / Shared Pond:** many PBX identities in one Pond, each with separate tenant,
  scope, revision and credential boundaries.
- **C / Interconnected Ponds:** bilateral River agreements; each Pond remains
  independently usable.
- **D / Geographic recovery:** selected revisions replicated to an independently
  operated region; region loss does not revoke authority automatically.
- **E / Multi-provider:** peer contracts do not depend on shared hosting or
  telecommunications vendors.
- **F / Managed fleet:** Lake presents delegated fleet metadata and actions;
  operators only see or act on explicitly granted resources.

## Current Evidence And Gaps

The local Stream coordinator/outbox has synthetic tests. The standalone Pond has
real SQLite/AES-GCM independent-process loopback tests, including source-file
loss, exact-password recovery and a simulated reconstruction target. Parent-head
conditions distinguish retained history from the current recovery head.
`FederatedPondLedger` still uses independent synthetic JSON stores. These results
do not establish public TLS deployment, real FreePBX, production tenant security,
River mTLS/signatures, cross-region replication, Lake availability or cross-Pond
recovery authority. No external service was contacted. See [River federation](river-federation.md),
[Lake fleet management](lake-fleet-management.md), [recovery authority](recovery-authority.md)
and [federation tests](federation-testing.md).
