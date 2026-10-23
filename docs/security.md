# Security And Trust Foundations

Experimental, unsigned development software, not a production security assurance.

PBXs publish only their own authorised intent. Provider authentication must bind
PBX identity, operation and resource scope. Controllers own policy authority and
SIP edges trust authorised controller policy, not direct arbitrary PBX changes.
Source and replacement require authority epochs/fencing, so stale updates cannot
make both active. The mock tests identity scoping and revision checks, not real
cryptographic authentication, controller policy enforcement or SBC fencing.

Transport authentication, intent signatures and policy signatures are different.
No signed artefacts are verified here, so no signature-failure test is claimed.
A future verifier must use a maintained JOSE/COSE implementation, explicitly define
canonical signed bytes, algorithms, trusted key IDs, issuer, audience, operation
binding, not-before/expiry, clock skew, nonce and durable replay rules, and fail
closed on unknown keys or algorithms. Do not roll custom crypto or call a hash a
signature. Production keys, rotation, revocation and signed policy are release gates.

The FreePBX page checks FREEPBX_IS_AUTH, the AMP_user respawn section and a
session-bound random CSRF token. It accepts enumerated actions only, limits body
size and escapes output. This path is syntax-reviewed, not a real FreePBX session/
RBAC/CSRF integration test. The GUI offers the neutral HTTPS profile, allowlisted
endpoint and protected credential-file reference, never a token field.
Public website pages expose no private controls, credentials or PBX data.

Typed reconstruction executes no shell commands, raw SQL writes or arbitrary dialplan. Native
read-back uses documented prepared read-only users/devices/sip snapshots to avoid
side-effecting/cached getters. It
rejects unknown actions/adapters, invalid identifiers, dependencies and unsupported
fields. It stops on target conflicts. Supported FreePBX interfaces perform writes
only after explicit CLI target confirmation; no live calls were made here.

State directories must be asterisk-owned/private mode 0700 with trusted parents;
files are mode 0600. Existing non-private directories and symlink lock/state files
are rejected. V2 builds checkpoint each operation using file fsync, atomic rename
and directory fsync, with a target-wide flock across the build. A hostile local
user with parent-directory write access remains outside this threat boundary.
Actual child-process interruption/restart is tested; hardware/filesystem power-loss
guarantees remain unverified. Corrupt
state stops processing without overwriting evidence. Preserve it securely for review.

No production secret was used in this work. Protected-file credential retrieval
and HTTPS transport are implemented, but actual TLS/network behaviour is unverified.
The standalone Pond encrypts retained credentials using OpenSSL AES-256-GCM,
random nonces and tenant/PBX/reference-bound associated data. Its private key is
held on the independent Pond; a separately controlled offline backup is required.
Recovery-only scoped tokens are distinct from source publication tokens. Replay
IDs, request freshness, immutable references and local revocation are implemented.
Loopback tests cover source loss, exact recovered values, wrong keys, ciphertext
tampering, tenant/PBX rejection and log hygiene. Public TLS deployment, power-loss
durability, automated master-key rotation and production security assurance are
not established. See [Pond operations](../pond/README.md). Raw upstream exception bodies are not printed
in the GUI or FreePBX CLI. Stable code/stage/outcome/retryability/correlation fields
and a safe next action are stored and written to PHP's configured `error_log`; no
stable FreePBX logger accessor was verified in the pinned framework source. A host
integration may route this sanitized event to its supported logger. Actual cURL
failure matrices and malicious real-server tests remain unverified. Do not
publish private blueprints, tokens, recordings or populated configuration files.

Connection IDs derive from mode, adapter, endpoint and trust policy, excluding
bearer tokens and credential-file paths. Old connections remain available for
operation management. Readiness expiry blocks activation but does not hide a
freshly identified operation; malformed or regressed revisions are quarantined
from management. Session-independent reconciliation accepts only an opaque locally
stored submission ID. Retry is explicit and uses its stored original request/key.

Original PJSIP secrets are resolved only from identity-bound protected manifests
and immutable versioned references. Source export checks equality with the source
credential; a changed revision changes blueprint identity. No password or password
hash appears in public blueprints. Missing/mismatched original credentials block
recovery. Standalone Pond enforces reference immutability and explicitly enrolled
credential access. An optional `activeReferences` map selects the current reference
by extension number; old references remain resolvable for queued revisions.
Unknown/cross-extension active references fail rather than choosing a password.

`FileSecrets` validates absolute canonical paths, regular-file type, final-file
permissions, size and content format; it rejects symlinked paths and
group/world-writable ancestors, except root-owned sticky directories such as
`/tmp`. It does not verify file or parent ownership, nor require every parent to
be mode `0700`. The operator must still stage manifests and secret files in
dedicated trusted directories with restrictive ownership/permissions. Do not
interpret these checks as protection against an untrusted owner or privileged
local process.

## Reporting

Do not create public issues containing credentials, customer identities or live
network details. Contact the maintainer through an agreed private channel before
disclosing exploitable findings. No new security-reporting service is invented.