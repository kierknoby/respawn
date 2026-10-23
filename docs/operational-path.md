# Real Takeover Dependency And Measurement Plan

The goal is takeover in minutes. **No real takeover or timing has been measured.**
All live tests need separate explicit authorisation. No live infrastructure was
modified during this scaffold work. Mock lifecycle completion is not this goal.

Respawn explores an alternative to maintaining a second PBX as a continuously
ready warm spare: retain validated supported configuration and deploy a
replacement when required. The intended objective is to make warm-spare operation
unnecessary for suitable deployments, but only after the complete recovery chain
and its timing have been proven. Revert and Regenerate concern retained local
configuration; Respawn requests provider-controlled replacement and routing work.
These operations are not interchangeable, and configuration readiness alone is
not completed recovery.

| Stage | Private service must implement / supply | Blocking evidence and dependencies |
| --- | --- | --- |
| Authorise | Bind operator, source PBX, retained blueprint and service entitlement; return operation receipt and stable lookup | Approved identity/enrolment mechanism, permissions, blueprint retention/freshness, idempotency scope |
| Provision | Existing FreePBX UK hosting automation creates compatible target with operation ownership | Actual automation API/queue contract, capacity/image IDs, network allocation, boot/health receipts, failure cleanup; interfaces unavailable here |
| Reconstruct | Deliver blueprint to authorised target runner; resolve secret refs through private secret service | Compatible module versions, target credentials, licensed features, secure short-lived secrets, checkpoints, target identity; no secrets in blueprint |
| Connectivity | DOMAINTAINS or equivalent prepares service and numbering connectivity | Actual API/auth scopes, number/trunk ownership, reservation semantics, propagation/readiness receipts; unavailable |
| SBC routing | Reserve target path, validate routes, enforce controller authority epochs | SBC API/transaction model, policy signer, effective-route query, NAT/media paths, collision handling and source fencing; unavailable |
| Verify | Independent probes check registration, inbound/outbound calls and received audio in both directions | Authorised test numbers/destinations, test endpoint identities, media evidence retention, emergency-call restrictions and rollback health criteria |
| Activate | Apply explicit takeover policy only after fresh evidence; fence old authority | Manual/automatic policy agreement, cutover receipt, effective policy read-back, failed/partial/unknown/rollback states |
| Reconcile | WHMCS service/billing lifecycle records target resources, entitlement, charges, teardown and residual cost | Actual service identifiers, lifecycle hooks, billing idempotency, partial-failure compensation; stays entirely inside private integration |

## Timings

Use an invocation correlation ID and monotonic clock at the orchestrator. Retain UTC
timestamps for cross-service correlation and clock uncertainty. Each milestone
requires an authoritative receipt, not when an API request was sent.

| Metric | End evidence | Current real result |
| --- | --- | --- |
| Invocation to provisioned | Target exists, boots, management health is ready | Unmeasured |
| Invocation to configuration ready | All supported operations read-back verified; reload confirmed | Unmeasured |
| Configuration reconstruction duration | First authorised target mutation to all supported saved objects verified | Unmeasured |
| Apply and verification duration | Final framework generation/reload invocation to post-apply configuration comparison | Unmeasured |
| Connectivity and routing preparation duration | Provider preparation request to fresh connectivity and reserved-route receipts | Unmeasured |
| Invocation to connectivity ready | Required services and SBC prepared path acknowledge readiness | Unmeasured |
| Invocation to verified takeover | Activated authority and effective live route independently confirmed | Unmeasured |
| Invocation to successful inbound/outbound calls with bidirectional audio | Both calls answered, both directions receive expected audio; probe receipts retained | Unmeasured |

`tools/measure.py` validates real evidence input and derives durations; synthetic
operations are rejected. It does not create evidence or validate telephony by itself.
Define whether test calls occur before/after cutover and include both timestamps.
The evidence document requires intervals.reconstruction, intervals.apply_verification
and intervals.connectivity_routing, each with startedAt, completedAt, authority and
evidenceRef. Their durations are distinct from invocation-to-ready milestones.
Timeouts, unknown results and rollback durations must be retained, not excluded
from a favourable average. No benchmark, percentile or SLA is claimed.

Potential bottlenecks: image provisioning/boot, first package update, module install,
licence activation, configuration reload, secret retrieval, carrier propagation,
endpoint registration timers, SBC policy distribution and independent call probes.
An immutable prepared FreePBX image and warm capacity may be necessary; validate
image age, security updates, module compatibility and licensing before choosing.

## Continuity Limits

Existing Asterisk calls ordinarily cannot be migrated; expect interruption unless
a separately verified edge strategy proves otherwise. Endpoints need reconnection,
appropriate credentials and NAT/DNS/registration behaviour. Trunks and numbering
may be tied to provider identity, source IP or licences. Emergency routing must be
explicitly verified without unauthorised emergency test calls. Commercial module
licences may need reassignment and may delay startup. Recordings, voicemail media,
CDRs, logs and custom sounds need independent recovery; a new configuration cannot
invent historical data. Rollback must not reactivate an unhealthy source.