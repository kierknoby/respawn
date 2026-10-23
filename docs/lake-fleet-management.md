# Lake Fleet Management

## Role

The Lake is an optional fleet-wide visibility and coordination layer above
independent Ponds. It is not a blueprint repository, replication transport, Pond
identity authority or required recovery dependency. River moves permitted
information directly between Ponds (or through a future opaque relay); Lake
metadata can describe the resulting relationships without carrying blueprint
payloads.

No Lake service, UI or API is implemented. The current contract foundation is
[`contracts/lake-fleet.v1.schema.json`](../contracts/lake-fleet.v1.schema.json),
which describes inventory metadata only.

## Object Model

- **Tenant:** customer/organisation security boundary.
- **Fleet:** tenant-owned grouping of PBXs and Ponds; membership is not permission.
- **Pond:** stable controller identity, endpoint/key references and health.
- **PBX:** existing source identity, optional replacement instance identity, tenant
  and fleet membership.
- **Replica:** source PBX/revision/hash, destination Pond, replication state and
  verified receipt reference.
- **Policy:** delegated rule for visibility, replication or recovery action, scoped
  to named tenant/fleet/PBX/Pond resources and a version.
- **Audit event:** actor, delegated authority, action, scope, timestamp, outcome and
  correlation ID; never a secret or blueprint body.

Do not copy full blueprints into Lake inventory by default. A Pond remains the
owner of retained data and serves authorised retrieval through its contract.

## Roles And Delegation

Future roles include platform operator, tenant administrator, fleet administrator,
Pond operator, recovery-authorised operator and read-only auditor. Role names alone
are insufficient: every action also requires explicit tenant/resource scope and
an auditable delegation. A reseller or hosting operator does not automatically
receive customer blueprint, credential or routing access.

- Read fleet/health metadata: delegated read scope only.
- Create/revoke a River relationship: explicit admin consent at both Ponds.
- Request replication: authorised policy for named source/revision/recipient.
- Retrieve blueprint: recovery/read scope from the retaining Pond.
- Retrieve credentials: separate vault role and recipient authorisation.
- Request recovery: scoped recovery authority and an operation audit record.
- Activate routing: authority from the designated routing/service owner, never a
  Lake dashboard action alone.

Tenant IDs and permissions must be checked by every Pond endpoint, not trusted
because a Lake forwarded the request. Cross-tenant lookups must be indistinguishable
from not-found to callers lacking permission.

## Fleet Visibility

A future Lake view can aggregate signed or authenticated Pond reports for:

- Pond connectivity/check-in and last successful observation.
- PBX tenant/fleet membership and primary Pond.
- Latest local, submitted, acknowledged and retrieved revision IDs/hashes.
- Replica locations and per-replica confirmed/failed/unknown state.
- Credential recovery readiness (boolean/state and evidence reference only).
- Supported reconstruction profile/capabilities and known excluded modules.
- Last successful restore rehearsal reference and separate telephony evidence.
- Pending reconciliation, replication failures and next expected checks.

A missed heartbeat means stale/unreachable, not confirmed PBX failure. `last seen`
is not proof the latest revision is retained. A verified replica is not proof its
credentials or reconstruction profile are usable. The Lake must show evidence
age and uncertainty rather than synthesize a green status.

## Availability And Limits

Ponds and Streams continue their permitted work when Lake is unavailable. River
replication continues between approved peers where possible. Existing replicas
remain retrievable from their Pond, and authorised Pond recovery cannot require a
Lake call. The Lake cannot be the only copy of a blueprint, decryption key or
recovery authorisation.

No Lake deployment, database, scaling guarantee, discovery service or dashboard is
implemented. The inventory schema is a data contract only. Federation status and
fleet operations remain future work requiring a real identity, consent, policy,
audit and tenant-isolation design.
