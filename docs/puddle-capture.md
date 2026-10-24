# Puddle Configuration Capture 0.1.0

## Responsibility And Boundary

The Puddle performs local, read-only discovery independently of provider mode,
The Stream, The Pond, credential escrow or routing services. In 0.1.0 it
discovers the currently supported Core users, fixed PJSIP devices and Ring
Groups. A configured Ring Group scope is not required for local capture; an
available Ringgroups module with no rows produces an empty captured collection.
Each supported collection records module availability, version where reported,
objects read, unsupported objects and collection gaps.

Local captures use `respawn.configuration-capture.v1`, a separate canonical,
hash-verified local snapshot format. It is stored by the existing revision
journal with the established revision IDs, parent chain, retention policy and
record integrity hash. It is deliberately **not** a `respawn.reconstruction.v2`
blueprint: it cannot be exported for publication, used by reconstruction, marked
applied or reported recoverable. Existing v2 revisions remain readable and
executable under their existing validation rules.

Object collection is read-only. The collector uses FreePBX module metadata/status,
Core's `listUsers()`, the existing Ringgroups `get()` interface and the existing
read-only PDO/Asterisk-manager snapshot adapter for PJSIP rows and settings. It
does not write FreePBX tables, create artificial objects, run Apply Config or
reload Asterisk. Unsupported or inconsistent objects are described by stable
reason codes; secret fields are never copied into a capture.

## Credential Status

Configuration discovery, credential verification and independent recovery
readiness are separate. The capture records only credential references and a
status, never secret values. With no PJSIP devices, credential state is
`not_required`. With devices and no manifest, local non-secret discovery still
completes, the credential state is `not_configured`, and recovery readiness is
false. A protected manifest can be checked against each discovered source
credential; `verified_locally` still does not mean independently escrowed or
recoverable. Mismatch, unreadable protection or missing source secrets produce
incomplete credential status and a coverage gap. Existing AES-GCM escrow and
remote recovery verification remain unchanged and are not attempted during
manual capture.

An unsupported field or unavailable required module makes the configuration
capture incomplete. An installed module whose supported collection has zero
objects is captured successfully. If a collection fails, the prior revision is
left intact and an incomplete observation is retained separately when enough
other module data can still be collected. Identical captures are deduplicated;
collection states, gaps and credential status are hashed, so a change in
completeness creates a distinct observation. Capture completeness is not
recovery readiness.

## FreePBX 17 Test Procedure

These commands are for an authorised development FreePBX host only. Do not enable
protection or configure a provider for this test. Use the normal local Module
Admin upload/update workflow for the reviewed 0.1.0 package; do not install this
experimental build on a production PBX. The package is built in the repository:

```sh
python3 tools/build_site.py
python3 tools/package.py
(cd artifacts && sha256sum -c SHA256SUMS)
```

Transfer `artifacts/respawn-0.1.0.tgz` to the test host by the site's approved
administrative process and install the module through FreePBX Module Admin.
Confirm the installed version with Module Admin and the package's `module.xml`;
do not rely on the still-installed 0.0.1 version from the reported failure. No
remote publication, Asterisk restart or routing change is part of capture.

With the module installed and `Mode: disabled`, run as the locally approved
FreePBX CLI account (normally `asterisk`):

```sh
fwconsole respawn revision-capture
fwconsole respawn revision-list
```

Expected states are `configuration_captured` when supported collections are
complete and any required source credentials are locally verified (or there are
no devices), `configuration_captured_credentials_incomplete` when credentials
are not configured or cannot be verified, and `configuration_capture_incomplete`
when one or more collections/objects are unsupported or unavailable. Even
`verified_locally` credentials do not prove independent escrow. The result includes
`revisionId`, `contentHash`, `configurationComplete`, `credentialStatus`,
`recoveryReady: false` and sanitised `blockingGaps`. An empty Ring Group
collection is not a failure. A local revision is captured state, not a recovery
blueprint.

Copy the returned ID into:

```sh
fwconsole respawn revision-show --revision-id=REVISION_ID
fwconsole respawn revision-list
```

Repeat `revision-capture` without changing supported settings. It should return
`configuration_unchanged` with the same revision ID. Modify a supported Core
user name or PJSIP device description through the normal FreePBX interface, then
capture again and confirm a new content hash/revision. Do not create or delete an
object merely to test empty Ring Groups. Synthetic tests cover object removal.

Respawn has no persistent capture daemon. Restart the CLI/process between capture
and listing to check journal persistence; no Asterisk restart is required.
`protection-status` may report disabled/not configured and does not initiate
network activity. A failed collector reports a sanitised error; previous valid
revisions remain listable.

## Evidence And Limits

`php tests/puddle-capture.php` uses synthetic PDO and FreePBX interface doubles.
It covers zero/one/multiple Ring Groups, users/devices without groups, empty
collections, module dependencies, protected/missing/unsafe manifests,
initial/unchanged/changed/removed capture, interrupted collection, restart and
non-executable snapshot handling. It does not establish behaviour on a running
FreePBX host. Real FreePBX 17/Debian 12/Asterisk 22 verification remains pending
until an authorised host runs the procedure above and records its output. No
manual capture enables The Stream, contacts The Pond, changes PBX configuration
or claims independent recovery readiness.