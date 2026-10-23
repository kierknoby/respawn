# Public Contracts v1

Reconstruction now uses `respawn.reconstruction.v2` and contracts/v2.schema.json;
see reconstruction-v2.md for exact fields and source versions. Transport uses
the experimental provider-rpc.v2 profile with tightened readiness evidence in
https-profile.md. Neither schema implies private-service compatibility.

## Provider Connection Identity And Retention

A stable public `connectionId` is a SHA-256-derived opaque identifier over the
mode, adapter profile, normalized HTTPS scheme/host/port/path, normalized allowed
host set, and TLS policy version. The PBX identity scopes all local state in
addition to that connection ID. The ID contains no bearer token or credential-file
path. Credential rotation at the same endpoint/trust profile preserves connection
identity, because the latest protected credential reference is saved in the private
connection record and re-resolved for future calls. Changing endpoint, host/path,
allowed host set, mode or TLS policy creates a different ID.

Connection records are retained for original operation management and reconciliation.
Old provider status is queried through its stored configuration, not sent to the
currently selected endpoint. A provider change never transfers acknowledgements or
launch journals. New launch is blocked while another connection has unknown work or
an operation not authoritatively torn down/rolled back. Rehearsal/standby being
non-active does not prove infrastructure was cleaned up.

Pre-connection-ID records cannot be assigned reliably. They remain in protected
state and appear as redacted `unscoped_requires_reconciliation` quarantine. Their
raw legacy payload is not returned by the GUI/status contract and does not authorize
management. Ambiguous legacy state blocks new launches until an explicit operator
reconciliation policy is implemented. No record is deleted or guessed onto a host.

## Lookup And Retry

`reconcileSubmission(submissionId)` is lookup-only and resolves the original request
and connection from local state; it never accepts a caller-supplied request body.
It returns either an operation, provider-authoritative not_found bound to exact PBX,
connection and idempotency key, or an error/unknown result. Unavailable, malformed,
identity-mismatched or stale responses are not not_found. An explicit
`retrySubmission(submissionId)` is a separate action permitted only for a journalled
launch_outcome_unknown or authoritative_not_found. It resends the byte-equivalent
stored request with the same key through the original connection. Ordinary Respawn
cannot reuse an existing key or silently retry. Duplicate repeated reconciliation
does not send launch.

Historical readiness and current status freshness are separate. A correctly bound
operation with expired or missing readiness stays visible and may be reconciled,
cancelled where valid, or explicitly torn down; expired evidence blocks activation.
Malformed or identity-mismatched operations are quarantined individually. A lower
revision than the last accepted revision is also quarantined and cannot authorize
management. Outages retain locally known operations and submissions as stale or
unverified rather than returning an authoritative empty list.

Operator diagnostics expose stable code/stage/outcome/retryability/correlation,
a sanitized explanation and next action. Free-form upstream errors are discarded.
The current implementation writes sanitized fields through PHP's configured
`error_log`; a pinned FreePBX logger accessor was not verified.

The separate Local Pond reference runs only on loopback HTTP for local development
and uses runtime-generated protected synthetic tokens. Its operation/evidence is
explicitly marked development/simulated and is rejected by the production HTTPS
adapter. It is not a compatible private-service implementation or production
enrolment design.

## Canonical v2 Blueprint Bytes

v2 continues the existing SHA-256 contract without changing hash algorithm or
schema version. `contentHash` is omitted. PHP recursively sorts every JSON object
key by bytewise string order; arrays retain their order, including operations,
members, capabilities, dependencies and exclusions. Producers must sort semantic
sets before constructing the document; reordering a list changes its hash even if
a consumer might regard that list as set-like. `json_encode` uses UTF-8,
JSON_UNESCAPED_SLASHES, no whitespace, standard JSON booleans/null and integer
decimal notation. Unicode is allowed only where field validators allow it; the
selected v2 labels/identities are ASCII. Floats, non-finite values, objects/resources
and integers outside the exact interoperable range are rejected. The independent
Python canonical-vector test must match PHP output. Formatting and object-key order
do not change hash; semantic field, secret revision, dependency or array order does.
Hash is change/integrity detection, never a signature or authorisation.
Status: experimental public adapter contract, not an assertion about any private
API. No proprietary endpoints, credentials or transport are provided. PHP
interfaces are in the module; JSON vocabulary is described below and in
`contracts/v1.schema.json`. Changes to required semantics require a new major
schema; unknown major versions fail closed. Negotiate optional capabilities before
use. Do not silently ignore unknown reconstruction operations or activation modes.

## Common Rules

All identifiers are opaque strings, maximum 128 characters. Use UTF-8 JSON, bounded
depth (24), 64 KiB request/response bodies for the initial transport profile,
maximum 100 reconstruction operations and 50 members per ring group. Required
timestamps use UTC RFC3339; authoritative status includes increasing revision,
observedAt and freshness/expiry. Stale or unavailable status is unknown, not ready.

Production transport: HTTPS only, verified certificates and hostnames, no
redirects, userinfo, fragments or ambient proxy credentials. Configure an explicit
allowed endpoint/host; reject DNS rebinding and unintended link-local/metadata
destinations. Private service addresses need an administrator-managed allowlist,
not browser input. No development TLS exception is implemented. Prefer a
root/asterisk-readable credential file reference outside the web root with mode
0600; do not put bearer secrets in FreePBX GUI output, exports or logs. Host
protection is not encryption. HttpsProvider enforces verified HTTPS, no redirects
or proxy, public IPv4 resolution pinned into cURL, private credential-file
permissions and bounded payload/timeouts. Private-address and IPv6 endpoints are
not supported. TLS/network behaviour is not live-tested.

Use 5-second connection and 20-second request deadlines for a future adapter,
bounded exponential backoff (1, 2, 4, 8 seconds with jitter), at most four automatic
attempts, respect Retry-After and surface persistent failure. Do not retry an
unauthorised request. Reconcile an unknown submission by idempotency key before
creating anything new. Never infer success from HTTP transport completion alone.

## Contract Catalogue

| Contract | Required fields / result | Optional / semantics |
| --- | --- | --- |
| Provider configuration | schema, adapterId, endpoint, credentialRef, sourcePbxId, allowedHosts | operation policy saved separately; no embedded secrets; explicit GUI configuration |
| Capabilities | schema, providerId, authenticatedPbxId, authorised, capabilities, observedAt, expiresAt | limits, adapter versions; URL presence is not a connectivity test |
| Enrolment | challengeId, sourcePbxId, identity proof reference, requested scope | method is provider-negotiated; approval binds identity and resources; production enrolment unresolved |
| Canonical firewall intent | schema, pbxId, generatedAt, intent.enabled, intent.sources, contentHash | only IPv4 /32; disabled must be deliberate, not inferred from missing module |
| Publication | publicationId/idempotencyKey, pbxId, schema/version, contentHash, payload | forced republishes still validate and authorise; no-change is based on acknowledged content |
| Acknowledgement | publicationId, authenticatedPbxId, contentHash, blueprintId, version, acceptedAt, retention receipt | rejected/partial must list reasons; acknowledgement matching is mandatory |
| Reconstruction blueprint | schema, sourcePbxId, compatibility, operations, coverage, contentHash | dependencies, secretRefs and readiness policies for future adapters; not arbitrary scripts |
| Operation request | pbxId, blueprintId, blueprintVersion, intent, activationPolicy, idempotencyKey, requestedCapabilities | required capabilities must all be satisfied; bound to authenticated identity |
| Operation response | operationId, source identity, request binding, state, revision, timestamps, resources, errors, milestones | provider receipt, per-stage outcomes, reconciliation links; mock fields differ from the future transport envelope |
| Lookup / reconciliation | operationId or source identity + idempotencyKey, authenticated identity | monotonic revision; same key and different request is conflict; retention must outlive retries and cleanup |
| Verification | operationId, blueprint hash/version, target identity, checks, observedAt, expiresAt | each check has pass/fail/unknown, evidenceRef and checker identity; no boolean shorthand for whole recovery |
| Connectivity preparation | operationId, source/target refs, requested service scope, ownership token | capability may name DOMAINTAINS or equivalent; receipt and resource refs required |
| Routing preparation | operationId, route intent, target ref, ownership token, dependencies | reservation receipt, readiness signals, policy generation; no traffic activation |
| Activation | operationId, expectedRevision, controller authority epoch, activationPolicy, readiness evidence | explicit confirmation for manual policy; receipt from routing authority; fence older epochs |
| Cancellation | operationId, expectedRevision, idempotencyKey | acknowledge request, then report cancelled/too-late/partial; cancellation is not cleanup |
| Teardown | operationId, expectedRevision, ownership token, idempotencyKey, active routing disposition | preserve shared/pre-existing resources; reverse dependency order; retry remaining resources |
| Error | code, stage, retryable, sanitised message, correlationId, authoritative outcome | remaining resources, dependency failures; secrets and raw upstream bodies excluded |

## Exact Current PHP Mock Profile

`Provider::capabilities/status/publish/launch/getOperation/advance/activate/cancel/teardown`
are implemented by `MockProvider`. `advance` is **harness-only**, never a production
progress control. Mock identity binding is constructor scope, not cryptographic
authentication. `publish` accepts firewall intent or a reconstruction blueprint.
The last acknowledgement is per identity in this minimal store; publishing firewall
replaces the current blueprint publication slot, so launch is blocked until the
reconstruction blueprint is republished. Separate per-area retention is a next step.

Mock responses use `mode: MOCK`; operation `simulated: true` and
`realCallsVerified: false` are mandatory. Hash/order and mocks are not signatures.
Idempotency is the exact JSON request representation in this profile: callers must
reuse the same serialised request. Production adapters must specify canonical bytes.

## Empty And Disabled Intent

An enabled empty list removes all explicitly declared trusted hosts; a disabled
intent asks the controller to disable this policy scope, not open access. Controllers
must reject unsafe interpretations. Missing/disabled/incompatible Firewall errors
do not publish empty or disabled intent. The exporter currently emits enabled
intent only; an explicit disabled-intent GUI control is not implemented.

## DOMAINTAINS And Equivalent Services

Required request: operation/source/target identity, blueprint hash/version,
connectivity intent, numbering references, requested capabilities, ownership token
and idempotency key. Required acknowledgement: request binding, accepted/rejected
scope, owned versus shared resource refs, dependencies and estimated readiness
conditions. Required readiness: service state, SBC route preparation, registration
checks, inbound/outbound test-call refs and two-way received-audio evidence.
Required cleanup: operation token, explicit routing disposition, remaining refs
and service/billing reconciliation receipt. Exact endpoints, credentials, quota,
number/trunk ownership, callbacks and authority fencing are unresolved private
integration inputs. Fixtures represent contracts only, not a claimed integration.