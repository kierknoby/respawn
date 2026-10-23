# Operation State Machine And Runbooks

## State Model

```text
local submission -> unknown until provider receipt
accepted -> provisioning -> configuration -> connectivity
 -> routing_prepared -> ready -> [activating] -> active
unfinished -> [cancelling] -> cancelled
failed / cancelled / ready / explicitly-disposed active
 -> [tearing_down] -> torn_down OR teardown_partial -> retry
```

Brackets denote production intermediate states not persisted by the synchronous
mock. A provider must also represent unknown outcomes, rollback_pending and
rolled_back where supported; the mock does not implement real rollback. A stage
timestamp alone is not a receipt. Real providers must return per-stage evidence.
The mock's configuration stage actually applies the structured instructions to
a fixture target, but its readiness/activation never verifies live infrastructure.

## Launch And Refresh

Review source identity, retained blueprint version/hash, coverage, intent and
activation policy. Confirm capabilities and prerequisites. Use one stable key per
deliberate launch. Duplicate same-key/same-request submissions retrieve the same
operation; conflicts fail. A second in-flight operation for the same source is
blocked in the mock. Refresh retrieves persisted provider state. An outage or
expired observation must be shown as unavailable/stale, never successful.

Do not infer provisioning from local HTTP completion, publication acknowledgement
or a spinner. The application persists pending submission and lookup keys before
network I/O. Unknown outcomes retain the original request/key for reconciliation.
The local mock does not simulate every failure mode of distributed submission.

## Revisions And Local Repair

Revision capture, comparison and preview describe only the selected supported
configuration scope. **Revert** plans convergence to an earlier retained
revision. **Regenerate** plans convergence to a selected retained blueprint.
Synthetic targets execute both operations and verify saved state; native FreePBX
revision execution is currently blocked where updates, removals, verified
ownership or an apply callback are required. Do not remove capability checks to
make an execution control appear available. Neither local action changes provider
routing, authority, billing or replacement resources.

**Respawn** is a distinct provider request for a replacement PBX. It requires a
current acknowledged blueprint and provider policy; reconstruction, runtime
readiness, routing preparation, activation and independent call/audio evidence
remain separate gates.

## Activation

Rehearsal and standby require none. Recovery can request manual activation after
ready. Current controls require operation ID, current revision and exact
`activate:OPERATION_ID` confirmation. Production automatic policies are not shipped.
The provider must verify fresh configuration, registration, inbound/outbound
routing and received audio, fence source authority, activate, then query effective
policy. If outcome is unknown, reconcile before retrying or tearing down.

## Cancellation

Cancel unfinished work by operation ID. The mock immediately records cancelled;
a real provider must acknowledge the cancellation request and reconcile whether
in-flight actions already completed. Cancel is not teardown. Retain resource
ownership until cleanup receipts arrive. Active operations reject cancellation.

## Teardown

Cancel unfinished operations first. Review resource dependencies and ownership.
Remove resources in reverse order, only where owner equals the exact operation
and shared is false. A blocked dependency prevents deleting its ancestors.
Report remaining resources and retry only those still owned. An old delayed request
is scoped to its old operation and must not resolve resources by reusable PBX name.

Active routing needs an explicit disposition. The mock accepts
operator-confirmed-deactivate, which does not imply failback or a healthy source.
A real provider must first obtain an authorised traffic disposition and receipt,
then remove resources. Reconcile WHMCS/service records and residual charges as a
separate result. Never mark a partial cleanup complete or silently discard costs.

## Failure And Rollback

Keep errors, partial outcomes and remaining resources visible. Reconstruct retries
are create-if-missing and verify-if-identical, not destructive rollback. Production
rollback needs independently verified destination health, explicit authority and
provider-specific compensation; the scaffold cannot perform it. An unhealthy source
must never become the automatic fallback merely because replacement work failed.