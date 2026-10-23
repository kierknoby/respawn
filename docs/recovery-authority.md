# Recovery Authority And Fencing

## Separate Permissions

These permissions are distinct and must never be inferred from one another:

1. **Publish:** PBX may submit its own validated supported blueprint to an enrolled
   Pond.
2. **Retain:** Pond may store that blueprint under its tenant/PBX policy.
3. **Replicate:** approved Pond may copy a named immutable revision to a named peer.
4. **Read/recover:** operator or replacement identity may retrieve a named revision.
5. **Use credential:** separately authorised recipient may retrieve a secret reference
   from the credential vault.
6. **Provision:** provider adapter may create an operation-owned replacement.
7. **Prepare routing:** connectivity/routing provider may reserve a route.
8. **Activate:** designated routing authority may switch service after policy gates.

River replication is not credential use, provisioning or activation authority.
Lake visibility is not any of these permissions. Source PBX identity in a blueprint
is not the replacement PBX identity.

## Authority Records

A production recovery delegation should bind tenant, source PBX, retained revision
and content hash, initiating actor, recovery Pond, replacement identity, permitted
provider/service scopes, expiry, nonce/idempotency key, policy version and allowed
transitions. The receiver authenticates the delegation issuer and checks revocation
and replay state. Delegation is immutable for an operation; changes require a new
explicit authorisation. Hashes are content identity, not signatures or authority.

The current module's stable PBX identity and provider `connectionId` are not a
federated Pond identity or recovery delegation. No production enrollment, signed
delegation or key revocation mechanism is implemented.

## Preventing Conflicting Recoveries

Before recovery, a Pond must obtain authority from the designated recovery/control
owner and establish an operation-scoped fencing epoch/lease with the service that
controls routing or the protected resource. Each transition is conditional on the
expected revision/epoch. A newer recovery authority invalidates stale operations;
expired leases, partitions, uncertain responses or disagreement fail closed and
require reconciliation. A Pond replica alone cannot claim authority because it
possesses data.

A future recovery coordinator must account for:

- concurrent requests at two Ponds;
- duplicate/lost requests and responses;
- network partitions and stale status;
- original PBX returning after a replacement is prepared;
- old credential/key revocation;
- replacement and source route ownership;
- partially completed provisioning or routing preparation;
- authority handover and operation cancellation;
- retry, rollback and a source becoming reachable again.

The safe default is no activation until a single authoritative epoch and fresh
readiness evidence are confirmed. A missed heartbeat is not failure proof. No
automatic takeover or automatic failback is defined here. Existing explicit
activation controls remain provider guarded; the mock is not fencing evidence.

## Recovery Evidence Gates

Before marking recovery ready, require independently checked, revision-bound
receipts for blueprint coverage, saved-state reconstruction, apply, configuration
read-back, runtime, credential continuity, endpoint registration, connectivity,
routing preparation, inbound/outbound calls and bidirectional audio as applicable.
A missing/expired result blocks activation; a simulated receipt cannot satisfy a
production gate. Source-PBX loss recovery additionally requires a Pond replica and
credential retrieval path that do not consult the source.

This document is a contract/security design. No production authority service, KMS,
SBC fencing API or real cross-Pond failover has been implemented or tested.
