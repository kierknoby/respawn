# Selected Configuration Reconstruction v2

Respawn reconstructs supported PBX configuration on a fresh system through ordered
module-specific operations, then works with provider services to make the
replacement operational. The long-term aim is to supersede conventional restore
for supported configuration, not the separate retention of history/media.

## Exact Scope And Fresh Target

The implemented chain is two Core users, their two fixed PJSIP devices, a fallback
ringall Ring Group containing a device, and a primary ringall group containing
the other device with its no-answer destination referencing the fallback group.
Ring Groups really supports `ext-group,NUMBER,1`; this is not an invented nested
member relationship. Numeric members reference the recreated PJSIP devices.

Fresh means a disposable FreePBX 17 installation with framework, core, ringgroups
and sipsettings already installed/enabled at the **exact versions recorded in the
source blueprint**. Asterisk/AMI and chan_pjsip must work, and compatible global
SIP/network/transport settings must already be provisioned. No selected users,
devices, SIP rows or groups need pre-exist. Module installation and global SIP
configuration are not reconstructed by this slice. A matching version is a
precondition, not proof that two hosts' global configurations are equivalent.
Emergency/custom extensions, commercial modules and external hooks may create
additional conflicts; assess them in the disposable lab, not production.

The synthetic example uses alpha 1001/1002 and 600/601; bravo uses 2001/2002 and
700/701 with different names/auth identities and independent target stores.
Synthetic module versions 17.0.1 are **not real tested versions**.

## Field And Dependency Coverage

| Object/profile | Reproduced or explicitly constrained | Dependency / exclusion |
| --- | --- | --- |
| Modules | Exact framework/core/ringgroups/sipsettings versions in blueprint | Must already be installed/enabled; no install/update operation |
| Core user | Stable numeric extension, name, voicemail=novm | No existing mailbox; no user/Ring Group number collision |
| User fixed defaults | Empty password/outbound CID/sipname/custom destinations; ringtimer=0, cfringtimer=0, concurrency_limit=0; call waiting enabled; recording dontcare/ondemand disabled; standard CID/device AstDB relationships | These defaults are checked, not ignored. Non-default supported-profile state blocks export. Voicemail, custom destinations and user-feature migration are not generally covered |
| Core device | Same stable ID/user/account, fixed type, pjsip technology, PJSIP/number dial string, description, caller ID, mailbox reference and DEVICE AstDB relationships | Depends on its Core user; no adhoc or multiple-user mapping |
| PJSIP identity | Explicit auth username (including nonnumeric identity), original auth password through immutable versioned secret ref | Depends on resolvable matching source secret; never silently generates a real endpoint password |
| PJSIP selected settings | context from-internal; default transport only; rfc4733/auto/inband DTMF; ordered ulaw/alaw/g722 codec list; disallow all; direct_media, rtp_symmetric, force_rport, rewrite_contact; max_contacts 1..10; remove_existing; media_encryption=no | One contact forces remove_existing=yes, matching Core. Custom contexts, explicit transports, WebRTC, SRTP, MD5 auth and arbitrary codecs are blocking gaps |
| PJSIP fixed profile | NativeProfile enumerates source-inspected default media/timer/MWI/caller-ID fields, empty proxy/match/account groups and standard account/dial/mailbox | Unknown fields or non-default fixed values block export; these are not permissively dropped |
| Ring Group | Number, description, ringall strategy, timeout 1..120, nonempty numeric member list, hangup or another selected group's no-answer destination | Depends on each device and destination group; unsupported options/announcements/recording stop export |
| Apply | One normal framework reload after saved-state read-back | Command success is reported generation/reload, not independent runtime, registration or call proof |
| Verification | Fresh target snapshot compared to exported supported intent and resolved original password in constant-time equality | Password never appears in blueprint/checkpoint/report; runtime/calls remain false |

`coverage.selectedComplete` means complete **for this strict selected profile**,
not all fields/modules on a PBX. Trunks, inbound/outbound routes, voicemail,
licences, numbering, unselected user/device options, media/history and provider
services remain explicit exclusions. Out-of-profile sources must be rejected,
not advertised as equivalent. Global SIP and unsupported user-feature state still
need a separately checked compatibility/runbook boundary.

## Source Interfaces And Evidence

| Public source inspected | Exact revision / source version | Interface and semantics |
| --- | --- | --- |
| FreePBX/core | fb43dea0ba9133319a21e6ea9e2ee3c6f7bc61f0 / 17.0.18.56 | Core generateDefaultUserSettings, addUser(extension,settings,false): true or exception; user SQL then AstDB/hooks. No transaction across steps |
| FreePBX/core | Same revision | addDevice(id,'pjsip',key/value settings,false): metadata then AstDB then driver; one contact forces remove_existing=yes; true/exception/legacy fatal paths; partial metadata possible |
| Core PJSip/Sip drivers | Same revision, functions.inc/drivers | PJSip inherits Sip addDevice/getDevice, backed by `sip`; generateEndpoint uses account and username for endpoint/auth identity. Default-device helper generates a secret and is intentionally not used |
| FreePBX/ringgroups | 3b6f9a90c3fe56d27d460fdc74a4dda24390fecc / 17.0.2.8 | get reads SQL/AstDB; add positional parameters returns true/false. `ext-group,N,1` is a supported destination. Existing defaults validated |
| FreePBX/framework | 90929231b801d865556a57875ddd25a74d283f8d / 17.0.33.2 | Modules getInfo/checkStatus; Symfony reload command drives generation/reload, exceptions signal failure. CLI invokes fixed command via its Application, not shell input |

Sources and commit metadata are retained only under ignored build/freepbx-source/.
No real version was installed or run here. These BMO methods are version-sensitive
module interfaces, not a stable cross-version external API. Export avoids getUser
(incidental AstDB writes) and cached/swallowed-error getters. FreePbxSnapshot uses
prepared **read-only** SELECTs of users/devices/sip and AMI database_get to detect
actual partial state. This deliberately schema-sensitive exception is documented;
all writes remain Core/Ringgroups methods. No direct database writes or generated
Asterisk-file edits are implemented. Native adapters remain contract-tested only.

## Specification, Planning And Identity

The v2 JSON schema and PHP BuildSpec define stable IDs (`user:N`, `pjsip:N`,
`ringgroup:N`), adapter/schema versions, object identities, validated parameters,
exact dependencies, capabilities, preconditions, secret refs, postconditions and
readback.v1 verification. IDs are preserved, not remapped. Unknown fields/actions,
duplicate/colliding identities, bad destinations, missing/cyclic dependencies,
unresolved secrets and incomplete selected coverage fail before mutation.

The executor requires exact module versions and runtime preflight (AMI/PJSIP,
at least one enabled transport configuration through inspected getTransportConfigs,
mailbox/number conflicts), resolves every secret and inspects every selected target
object before the first write. Topological order is deterministic, including stable
ID order for otherwise independent operations. Hash bytes are sorted-key JSON with
preserved list order; contentHash is excluded when hashing. A hash is not a signature.

## Original Credentials And Secret Handling

References have form `secret:SOURCE_ID:EXTENSION:IMMUTABLE_REVISION`. Rotation must
use a new revision, so it changes the blueprint hash. Providers must enforce
reference immutability. The source exporter resolves the staged reference and
checks it equals the existing source password before exporting the reference.
It never exports plaintext or a password hash into the blueprint.

The operator/private secret service must securely stage the original secret and
a manifest outside web roots, repository, logs and screenshots. Source and target
need separately authorised access to the same immutable value. FileSecrets reads
only absolute protected regular files (mode 0600, trusted parents, no file symlink,
bounded size); manifest identity must match the source. Only one selected revision
per source extension may be present for export; historical target resolution can
use the exact ref in its blueprint. No encryption, remote secret transport,
enrolment or private vault integration is claimed. If secure original-secret
staging is unavailable, export/reconstruction is blocked.

Manifest structure, with **references only and no credentials**:

```json
{
  "sourcePbxId": "SOURCE_ID",
  "references": {
    "secret:SOURCE_ID:1001:IMMUTABLE_REVISION": "/private/protected-file-reference"
  }
}
```

The real manifest must cover every selected device. Never pass secret values in
CLI arguments. MemorySecrets is a synthetic runtime-only test helper. The default
mock generates runtime-only synthetic credential material because it has no real
endpoints; the two-PBX source/target demonstration injects the **same** generated
source credentials into its targets and checks continuity without publishing values.

## Executor, Checkpoints And Apply

BuildExecutor locks the canonical target state directory across the entire build,
not just individual writes. All module execution must use that same directory;
advisory locks do not fence unrelated administrator changes or foreign processes.
Snapshots still recheck conflicts before each mutation. Native interfaces provide
no distributed ownership tags; local ownership is a journal claim, not proof of
foreign infrastructure authority. Native automatic object deletion is not provided.

Pending/running/succeeded/failed/unknown states are persisted per operation.
Before mutation, the running checkpoint and ownership claim are atomically renamed
after file fsync, then the directory is fsynced. Success requires read-back.
The tests terminate a real child process after target mutation but before succeeded
checkpoint, then reconstruct with a new executor. The persisted running state and
actual target state reconcile without duplicate creates. Power-loss/storage-system
guarantees beyond the tested local filesystem remain unverified.

Equivalent pre-existing objects are verified and marked pre_existing_preserved;
different or foreign-owned objects are rejected, never updated. A partial Core
metadata/AstDB/SIP write stops with a specific failed operation and requires manual
reconciliation; it is not blindly recreated. A timeout/exception during mutation
is unknown, not proof of failed mutation. Raw errors are sanitised.

After all saved objects match, one final apply runs. A confirmed retry does not
repeat apply; an unknown apply may be safely re-requested after read-back. If a
missing object is recreated, old apply evidence is invalidated. Final read-back
follows apply. Saved objects, generation/reload receipts, runtime, registration,
calls and audio are separate states. The executor never activates provider routing
and always leaves runtimeVerified/callsVerified false without independent probes.

## Exact Opt-In Disposable Lab Procedure

**Not executed here. Requires separate explicit authorisation.**

1. Prepare isolated source/target FreePBX 17 VMs with no production numbering,
   trunks, SBC policy or customer credentials. Record exact OS/PHP/Asterisk and
   framework/core/ringgroups/sipsettings versions. Install identical compatible
   module versions; provision matching global SIP transports/network settings.
2. Install the local experimental module with the approved unsigned-development
   procedure. Check Connectivity placement, RBAC/CSRF, settings, BMO loading and
   fwconsole command discovery. Use the normal asterisk-owned private state root.
3. On source, create supported 1001 and 1002 fixed PJSIP extensions/users with no
   voicemail and the exact supported profile. Create group 601 -> hangup containing
   1002, then group 600 -> group 601 containing 1001. Do not assume ordinary GUI
   defaults match NativeProfile; inspect and set the supported values deliberately.
4. Through a separately approved secret-management workflow, securely stage each
   original source auth password in mode-0600 files and identity-bound versioned
   manifests on source and target. Never print/copy secrets into this repository,
   shell history, reports or screenshots. Missing safe staging is a hard blocker.
5. Confirm target has no selected users/devices/SIP rows/groups and no colliding
   emergency/custom extensions. Modules/global SIP configuration must already work.
   Record target Respawn identity; it differs from the source identity.
6. On source, run `fwconsole respawn export-reconstruction --groups=600,601 --secrets=/private/source-manifest.json`
   to a private blueprint file. Inspect coverage/refs only and transfer through an
   approved secure channel. Preserve its exact hash and source identity.
7. On target, run `fwconsole respawn plan --blueprint=/private/blueprint.json`.
   Confirm users precede devices, 601 precedes 600, no arbitrary operations and all
   refs/module versions are expected. Plan is non-mutating; runtime preflight still follows.
8. Run `fwconsole respawn reconstruct --blueprint=/private/blueprint.json --secrets=/private/target-manifest.json --confirmation=reconstruct:TARGET_ID`.
   This deliberately writes selected application configuration and invokes one final
   normal framework reload. Retain sanitised checkpoints/results. It is not a live
   takeover action and must still be confined to the authorised disposable target.
9. Re-export the target's selected objects with its own identity-bound secret
   manifest. Compare operations/versions/profile and protected credential equality;
   source identity/ref namespace differs in the envelope. Verify generated and
   effective runtime configuration through approved read-only facilities. Never edit it.
10. Repeat the original reconstruction, simulate a process interruption after the
    first user/device, and retry the same blueprint. Prove no duplicate objects,
    conflicts are rejected and partial objects are not blindly recreated. Exercise
    apply failure and exact module-version mismatch on disposable snapshots.
11. Register authorised lab endpoints using the original credentials, place lab
    calls to 600, verify no-answer fallback to 601 and two-way audio. Only these
    receipts can establish runtime/service evidence. This alone does not prove
    external inbound/outbound trunk/SBC takeover.
12. Retain sanitised versioned evidence; tear down only the isolated owned VMs and
    lab resources through approved procedures. Real provider/SBC takeover needs
    separate authorisation and the operational-path runbook. No timing claim until measured.