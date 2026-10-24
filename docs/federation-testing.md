# Federation Testing Plan And Results

## Executed Synthetic Test

Run from repository root:

```sh
php tests/federation.php
python3 tests/schema.py
```

`tests/federation.php` creates four independent markerless temporary Pond state
roots and uses synthetic PBX identities/data. It establishes bilateral agreements
at the ledger API, transfers immutable v2 blueprints, checks receipts, replays a
lost acknowledgement, rejects unauthorised scope and altered bytes, adds a third
Pond, restarts a receiver from its JSON state, deletes the sender store and retrieves
the replica. The test labels receipts and results `SIMULATED` and makes no claim
about authenticated operators, encrypted transport, durable hardware or production
Pond security.

The contract schemas in `contracts/pond-identity.v1.schema.json`,
`contracts/river-agreement.v1.schema.json`, `contracts/river-federation.v1.schema.json`,
`contracts/river-receipt.v1.schema.json`, `contracts/recovery-delegation.v1.schema.json`
and `contracts/lake-fleet.v1.schema.json` are independently checked by the repository
schema suite. They define vocabulary, not protocol conformance or a running Lake.

Production deployment must authenticate stable Pond identities with an established
mutual-TLS or signed-message implementation, encrypt transport, protect/rotate/revoke
keys, bind federation consent to tenant/PBX/method scope, and enforce replay/expiry.
These are required before exposing River to any network. An optional relay may forward
opaque authenticated messages but must not receive blueprint plaintext or authority
it was not granted.

## Scenario Results

| Scenario | Required behaviour | Status/evidence |
| --- | --- | --- |
| A / Individual PBX | Puddle/Stream/Pond operate without River or Lake | Standalone experimental Pond implemented; SQLite/AES-GCM loopback process and synthetic source-loss reconstruction tests pass; no real-host test |
| B / Shared Pond | PBXs retain separate identity, revision and tenant scopes | Existing mock/Development Pond two-source tests; simulated |
| C / Two Ponds | Authorised bilateral peers replicate exact immutable revision and receipt | Implemented in `FederatedPondLedger`; synthetic independent JSON stores tested |
| D / Pond failure | Surviving authorised replica retrieves without source/Pond A storage | Sender state deleted and Pond B retrieval tested; synthetic only |
| E / Network partition | Durable sender journal retries same transaction after reconnect | Local synthetic retry/idempotency behaviour tested; no network partition or real transport |
| F / Credential recovery | Replica is usable only with independently authorised credential escrow | Single standalone Pond exact-password/source-loss tests pass; credential replication and recovery from a River replica remain unimplemented |
| G / Fleet expansion | Third Pond joins without changing PBX/revision identity | Third synthetic Pond test passes |
| H / Lake failure | Ponds and River continue without Lake | Lake is not implemented; architecture says optional, but no running outage test |
| I / Conflicting recovery requests | Only one authorised epoch can activate; stale/competing requests fenced | Not implemented/tested. Existing provider/mock activation guards are not cross-Pond fencing |
| J / Tenant isolation | Unauthorised tenant cannot inspect/replicate/recover another tenant | Ledger checks bilateral tenant/PBX/method scope synthetically; no cryptographic or production tenant security test |

## Contract And Trust Limits

The test calls ledger methods directly; it does not exercise a River network protocol,
TLS/mTLS, digital signatures, operator identity, key rotation, replay windows,
expiry, revocation propagation, relay behaviour, data residency, or encryption at
rest. `approvedBy` in the local model is synthetic metadata, not an authenticated
approval. The ledger accepts an asserted peer ID; it does not authenticate a key.
The Development Pond HTTP service remains loopback-development only and its
bearer token is not a federation trust design.

Blueprint-only River replication excludes plaintext credential material. The
standalone experimental Pond tests original-password recovery after source file
loss and integration with the existing secret resolver on a simulated target.
That is not recovery from a federated replica: recipient-specific encryption,
cross-Pond key recovery and production River transport remain blockers. The
Development Pond remains simulation-only; no deployment is production-verified.

## Required Future Integration Tests

Before production federation, run separately authorised tests with at least two
independently deployed Ponds and a maintained identity/security implementation:

1. Establish and revoke bilateral relationships; verify both sides require approval.
2. Exercise direct and relayed transport while preserving end-to-end peer identity.
3. Replicate A/B/C during partition; lose responses/restart either Pond; verify
   immutable FIFO transactions and no duplicate/conflicting replicas.
4. Tamper payload, hash, sender, recipient, tenant, federation, expiry and key ID;
   verify rejection and sanitised audit records.
5. Rotate/revoke keys and operator delegation while existing replicas remain
   retrievable only under current policy.
6. Store encrypted credential references under approved key management; disable
   source PBX and source Pond; recover credentials from an authorised surviving
   Pond without plaintext in blueprints, journals, logs or Lake inventory.
7. Run concurrent recovery requests from two Ponds; prove routing authority fences
   stale epochs and refuses activation absent unique current authority.
8. Disable Lake; verify Pond-local protection, River replication and pre-authorised
   recovery remain available.
9. Cross tenant/fleet boundaries with positive and negative cases, including guessed
   PBX/revision IDs; prove no metadata, blueprint or credential leakage.
10. Reconstruct a selected profile using the existing executor on disposable
   FreePBX 17, then perform separately authorised provider/routing/call verification.

No external integration tests have been run. A passing synthetic ledger test is not
production federation, security certification, off-site durability or live failover.
