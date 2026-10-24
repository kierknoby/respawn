# Neutral HTTPS JSON Profile v2

This is a **new public Respawn contract**, not an invented description of an
existing private endpoint. A provider must implement or adapt this profile and
pass conformance tests before use. No private service was contacted by the module.

One explicitly configured HTTPS endpoint receives POST JSON. The profile version
is `respawn.provider-rpc.v2`, and every envelope includes the stable, non-secret
connectionId described in provider-contracts.md:

```json
{
  "schema": "respawn.provider-rpc.v2",
  "connectionId": "https-v1:opaque-public-context-hash",
  "method": "capabilities",
  "pbxId": "pbx-alpha",
  "parameters": {}
}
```

The host-protected credential file supplies a bearer token; no browser field
accepts a token. Local source bearer files require host protection; standalone
Pond credential escrow uses AES-GCM with a Pond-held key. TLS verifies host and
certificate, refuses redirects/proxy, uses pinned globally routable IPv4 DNS
results and bounds connect/request time to 5/20 seconds. No development TLS bypass,
private-address or IPv6 endpoint support is shipped. Responses are capped at 64 KiB.

Required response envelope:

```json
{
  "schema": "respawn.provider-rpc.v2",
  "connectionId": "https-v1:opaque-public-context-hash",
  "method": "capabilities",
  "pbxId": "pbx-alpha",
  "ok": true,
  "observedAt": "2026-10-22T20:00:00Z",
  "expiresAt": "2026-10-22T20:01:00Z",
  "result": {
    "identity": "pbx-alpha",
    "authorised": true,
    "capabilities": ["rehearsal", "standby", "recovery", "manual_activation"]
  }
}
```

Example dates are not current observations. The client requires observedAt no
older than 60 seconds, at most 30 seconds future skew, expiry in the future and
no later than five minutes after observation. Identity, method and schema must
match; ok=false never implies success. Raw upstream errors are not exposed.

Methods: capabilities, status, publish, retainRevision, retrieveRevision,
retainConfigurationCapture, retrieveConfigurationCapture,
escrowCredential, retrieveCredential, launch, operation, reconcile, cancel,
activate and teardown. `advance` is prohibited. Status supplies publication
{export, attempt, ack} and operations; ack has blueprintId, version, hash, at.
Publication parameters contain pbxId, payload, force and idempotencyKey. Its result
must be acknowledged with the exact hash and blueprint identity/version.
Launch uses the operation request schema and its result must echo the exact
request. Reconciliation uses the original idempotency key. Operation lookup uses
operationId. Cancellation/activation/teardown include expectedRevision, and
activation additionally requires exact confirmation. Teardown includes the
explicit routingDisposition. Provider services still enforce ownership, authority
and real readiness independently of module checks.

Current GUI launch keys are session-persisted, but pending submission rows retain an
opaque local submission ID. After a lost session, use the GUI's Reconcile Original
Launch action (or `reconcileSubmission --submission-id=...`); it performs lookup
only. Retry is a separate explicit `retrySubmission` action and uses the exact
saved request/key/connection. Refresh never launches. The GUI also keeps operations
from older connections visible and routes their controls through the stored original
connection. Provider outages show stale local operations/submissions, not an empty
authoritative list.

Ready/active service operations require passed checks for blueprintCoverage,
reconstruction, apply, configurationMatch, connectivity, routingPreparation,
registration, inboundCall, outboundCall and bidirectionalAudio. Each receipt needs
evidenceRef, operationId, blueprintId, blueprintVersion, observedAt and expiresAt,
bound to the original request and individually fresh under the envelope time rules.
Timestamps are strict RFC3339 seconds, not relative date strings. Reconcile results
must echo the requested original idempotency key. Active additionally requires
authorityEpoch and activationReceipt. Receipt presence is contract validation, not
independent verification of a real call or cryptographic signature. This tightened
experimental profile requires provider conformance before real use.

Mock readiness is separate and explicitly SIMULATED: selected coverage, executor
result, apply result, configuration comparison and simulated connectivity/routing
are retained, but registration/calls/audio remain unverified and serviceReady=false.
`development/local-pond/` is a separate loopback-only controller reference that
uses real local HTTP and the same public v2 request envelope. Its worker calls the
shared Respawn application reconstruction planner/executor against a `SIMULATED`
target, and its routing-model activation is not production readiness. The HTTPS
adapter rejects explicit development/simulated operation and receipt markers;
Development Pond evidence cannot satisfy its production activation checks.

The application persists launch intent before I/O. Unknown responses leave the
original request/key retryable, even after local policy changes. CLI supports
`reconcileSubmission --submission-id=...`; `retrySubmission --submission-id=...`
is a separate deliberate resubmission of the original immutable request/key. The
GUI reconciles by its locally stored opaque submission ID after refresh/new session.

Local publication journal retains export, attempt and acknowledgement separately.
Unacknowledged attempts are not suppressed. Forced publication remains validated;
its transport key is new for each explicit forced call. Manual Application
publication has no automatic retry/backoff. The protection coordinator uses a
separate durable outbox with bounded exponential backoff. A retry of forced
publication may create another accepted version;
reconcile unknown results before forcing again. Normal content-addressed publication
reuses its key. Operations must retain idempotency records for their entire lifecycle.

Protection RPC methods are implemented by the experimental standalone `pond/`
service. `retainRevision` requires `durably_retained`, a receipt, revision ID, exact
hash, connection and idempotency key. `retrieveRevision` returns the same revision
and publication binding with the complete blueprint; the client revalidates its
canonical hash. Credential escrow/retrieval requires authenticated identity/ref
binding and an encrypted-at-rest/key-ID receipt. The service uses real SQLite and
AES-GCM; local independent-process tests are not public TLS deployment evidence.
Requests now include a fresh random `requestId` and RFC3339 `sentAt`; the Pond
rejects duplicate IDs and requests outside a 60-second window. Revision retry
keys remain stable across fresh requests. `latestRevision` supports recovery-only
retrieval of the authoritative recovery head without reading the source's outbox.
The optional `ordering.parentRevisionId` condition allows promotion only for a
new child of the current head; null bootstraps an empty head. Valid delayed or
unordered legacy submissions are retained historically without promotion.
Acknowledgement fields remain unchanged; `recoveryHeadRevisionId` in the result
is required by the ordered source path. Retries freeze the same parent/key/ID.
Older servers lacking that guarantee fail closed for ordered protection, not
silently fall back to acceptance order. Existing stored heads remain selected.

Capture-only Puddle snapshots use separate `retainConfigurationCapture` and
`retrieveConfigurationCapture` methods. They bind the source PBX, connection,
local revision ID, content hash and stable idempotency key. The Pond stores the
exact `respawn.configuration-capture.v1` value in a separate object kind and
returns `durably_retained_capture` only after commit. Identical retries return
the immutable receipt; conflicting content for the same revision is rejected.
The source retrieves the capture and revalidates its canonical hash and receipt
before recording local read-back verification. These methods do not escrow
credentials, change the executable recovery head, or assert recovery readiness;
`recoveryReady` remains false.

GUI provider mode exports the explicitly selected real Ring Groups/users/devices;
it cannot
silently send fixture blueprints. Launch blocks changed/unacknowledged configuration.
Enrolment and revocation are local administrator commands in `pond/bin/pond.php`.
Real public TLS, DNS and FreePBX service permissions remain unverified; the new
service test uses authenticated loopback HTTP, not production TLS.