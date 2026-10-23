# River Federation

## Design Choice

River is a **peer-to-peer application protocol** between independently operated
Ponds. It is not a central service. Ponds may connect directly; an authorised
relay/gateway may forward opaque messages where network policy blocks direct
connections, but it is not a trust authority and must not change application
identity, scope or integrity semantics. No relay is implemented.

The application message schema is `respawn.river-federation.v1` in
[`contracts/river-federation.v1.schema.json`](../contracts/river-federation.v1.schema.json).
The current Development Pond ledger is a local synthetic implementation, not a
network listener or production security protocol.

## Stable Identities And Agreements

Each Pond persists its `pondId` separately from endpoint/connection IDs. A Pond
migration preserves its ID and requires an authenticated key/credential rotation
or re-enrollment. `sourcePbxId` remains the identity already embedded in the
blueprint; replica arrival does not rewrite it. `tenantId`, optional `fleetId`,
`federationId`, revision ID, content hash, transfer transaction ID and recovery
operation ID are distinct values.

A bilateral federation relationship requires both Ponds to approve matching
federation and tenant IDs, peer Pond identity, permitted PBX IDs, allowed methods,
operator approval and validity/revocation state. Knowing a peer URL, a Lake listing,
or a previous publication receipt does not establish trust. Permissions are
scoped per tenant/PBX/method; no relationship grants blanket access to the Pond.
The local prototype models bilateral agreement records but its `approvedBy` field
is synthetic and is not an authenticated operator signature.

## Federation Establishment Lifecycle

The contract-defined lifecycle is:

1. Each Pond publishes or presents its stable Pond identity and key IDs through an
   authenticated channel.
2. Each operator verifies the other Pond identity out of band and explicitly
   approves the relationship.
3. Both sides record the same federation ID, tenant/resource scope, allowed
   methods, expiry/renewal policy and key IDs.
4. Capabilities and protocol versions are negotiated; unknown required versions
   fail closed.
5. The relationship is usable only after both sides have recorded approval.
6. Key rotation updates the relationship's key set while preserving Pond identity.
7. Revocation stops new transfers and recovery delegation; immutable audit history
   remains. Revocation does not silently delete existing replicas.

Cryptographic establishment is not implemented. Production transport should use
mutual TLS with certificate/key identity mapped to `pondId`, or an established
signed-message profile using maintained JOSE/COSE libraries. Do not invent custom
cryptography. HTTPS bearer credentials in the current PBX profile are not a
Pond-to-Pond federation identity.

## Message And Replication Contract

The envelope carries schema/version, message ID, sender and recipient Pond IDs,
federation ID, tenant/fleet scope, method, idempotency key, correlation ID,
created/expiry timestamps and a structured payload. The transport must provide
confidentiality and authenticated peer identity. The application validates both
identities, bilateral scope, expiry, replay/idempotency and payload integrity.

For `blueprint.replicate`, payload fields bind source PBX ID, immutable revision
ID, canonical content hash and complete v2 blueprint. The receiver:

- requires an approved relationship authorising this PBX and method;
- checks sender is the approved peer and recipient is its own Pond ID;
- validates v2 schema, source identity and canonical hash;
- stores an immutable replica keyed by tenant/source/revision;
- rejects the same revision ID with different bytes;
- returns a receipt bound to sender, recipient, federation, tenant, PBX, revision,
  hash, transaction, idempotency key and destination replica ID.

The sender records replication confirmed only after validating the complete
receipt. A transport success is not a retention receipt. On timeout, the sender
retries the same immutable transaction/key; the receiver returns the prior receipt
when the exact transaction already exists. Reconciliation queries transaction ID
and verifies the stored hash. Acknowledgement loss cannot create a second replica.

The development ledger implements the immutable payload, bilateral scope,
content-hash verification, local JSON persistence, idempotent duplicate handling,
receipt replay and source-Pond deletion test. Its receipts say
`durably_retained_simulated`; no digital signature, encryption or remote transport
is implemented.

## Disconnection, Ordering And Reconciliation

Each sender maintains a durable transfer journal with queued, sending,
receipt-pending, confirmed, retryable, failed and revoked states. Failed transfers
keep the exact revision pinned. Retry uses the same transaction and idempotency
key. Per-source revisions retain their origin order; delivery order is not proof
of freshness. The receiver compares source revision metadata and content hashes,
not arrival time, and retains immutable revisions rather than overwriting a
mutable `current` pointer. Current preferred revision is policy-selected from
verified source history, not inferred from the most recent packet.

A network partition does not interrupt local Stream capture or another Pond's
independent operation. When connectivity returns, peers reconcile journals and
receipts. Missing/conflicting hashes require attention; do not silently choose
one side. Revoked relationships reject new work but do not mutate already retained
blueprints.

## Credential And Recovery Delegation Boundary

River's blueprint replication does not copy plaintext credentials or claim a
replica is reconstructable. Credential escrow needs a separate provider-neutral
vault protocol, tenant/PBX/ref scope, authenticated recipient authorisation,
immutable version, encrypted-at-rest storage, recipient-specific access, audited
retrieval and key revocation. Key recovery cannot depend on only the source PBX or
source Pond. No key hierarchy, recipient encryption or credential replication is
implemented here. Replica status must be `credentials_unavailable` until an
independent authorised vault path verifies retrieval.

Recovery delegation is a separate permission from replication. A replica Pond may
store encrypted material without decrypt permission and may not initiate recovery
unless a scoped recovery delegation is granted. River replication never grants
routing authority or activation permission.

## Topology And Scale

Direct Pond-to-Pond paths are the default contract. A relay may be added as an
opaque transport hop while preserving end-to-end authenticated identities. The
protocol assumes independent storage; it does not require a shared database,
filesystem, provider or IP range. Transaction logs and revisions are immutable and
bounded by explicit retention/capacity policy. No throughput, fleet-size or RTO
claim is made. Multi-region replication, back-pressure, streaming large payloads
and relay operations remain future engineering.
