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

Capture compatibility is not reconstruction compatibility. Core user fields now
retain allowlisted SQL row values (including `mohclass`), non-secret AMPUSER
values (including `concurrency_limit`) and call-waiting state. PJSIP fields retain
allowlisted device row values, actual SIP settings and DEVICE relationships.
Ring Groups retain allowlisted `get()` fields, including the original member list
and destination. Settings are not translated to reconstruction defaults or cast
to different scalar types. Optional missing PJSIP keys remain absent, including
`force_callerid`, `mailbox`, `vmexten`, `webrtc` and `username`; no authentication
username is inferred. Non-default `hint_override`, `aggregate_mwi` and `elsewhere`
values are retained when safely representable.

Only reviewed non-secret fields are accepted. Unknown fields, nested/non-scalar
values, credential-bearing URIs, missing required identity/profile fields and
inconsistent fixed-device bindings remain unsupported. SQL/AMPUSER passwords,
SIP secrets, `secret_origional` and `md5_cred` never enter snapshots. This is the supported discovery
scope, not an inventory of every AMPUSER key or every FreePBX module. Capture
does not assess or certify executable reconstruction compatibility; all such
revisions remain non-executable. The reconstruction validators are unchanged.

The existing capture schema and canonical hashing algorithm are unchanged. New
captures include more source state and therefore have different hashes from the
earlier reduced representation. Existing retained snapshots and their hashes are
not rewritten. A comparison with an earlier representation may report updated
fields for the same object identities without indicating a source-side change.

## Credential Status

Configuration discovery, credential verification and independent recovery
readiness are separate. The capture records only credential references and a
status, never secret values. With no PJSIP devices, credential state is
`not_required`. With devices and no manifest, local non-secret discovery still
completes, the credential state is `not_configured`, and recovery readiness is
false. A protected manifest can be checked against each discovered source
credential; `verified_locally` still does not mean independently escrowed or
recoverable. Mismatch, unreadable protection or missing source secrets produce
incomplete credential status and a credential reason code. Existing AES-GCM escrow and
remote recovery verification remain unchanged and are not attempted during
manual capture. Credential status and its reason code do not add configuration
blocking gaps; missing or mismatched credentials never imply recovery readiness.

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
Admin upload/update workflow for the reviewed 0.1.1 package; do not install this
experimental build on a production PBX. The package is built in the repository:

```sh
python3 tools/build_site.py
python3 tools/package.py
(cd artifacts && sha256sum -c SHA256SUMS)
```

Transfer `artifacts/respawn-0.1.1.tgz` to the test host by the site's approved
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

## Independent Pond Retention Prototype

The 0.1.0 capture-retention RPC is separate from `retainRevision`, which accepts
only executable reconstruction v2 blueprints. To publish a capture, configure an
authorised HTTPS provider connection in Settings. An empty Ring Group scope and
empty secret manifest are valid settings for this capture test. The Pond
principal needs only the enrolled `retain` and `read` permissions for these two
RPC methods; no escrow or recovery permission is used by capture-only retention.

After a local capture, run as the FreePBX service account:

```sh
fwconsole respawn revision-list
fwconsole respawn revision-publish --revision-id=REVISION_ID
fwconsole respawn revision-show --revision-id=REVISION_ID
fwconsole respawn revision-verify --revision-id=REVISION_ID
```

`revision-publish` sends the exact snapshot with a deterministic idempotency key,
requires a durable receipt, immediately retrieves the same revision and verifies
its canonical content hash, then records the acknowledgement/read-back on the
local capture record. Retrying the same revision is safe. `revision-verify`
performs read-back only and requires the current configured connection to match
the stored acknowledgement. Both operations report `recoveryReady: false`; they
do not escrow credentials, move executable recovery heads, invoke a reconstruction
executor, alter FreePBX configuration or enable automatic protection. A
`configuration_retained_and_readback_verified` result proves only independent
retention of that exact local snapshot, not complete recovery.

### Existing Pond Compatibility Update

Older Ponds may implement the generic `provider-rpc.v2` dispatcher without
recognising the two capture methods. The compatibility change is limited to
`pond/Pond.php` and the new autoloaded
`module/src/Reconstruction/ConfigurationCapture.php`. It stores captures as a
new object kind in the existing SQLite `objects` table. It requires no table
migration and does not change existing rows, the Pond identity, enrolments, master
key, Nginx configuration or PHP-FPM pool.

For the earlier manual archive layout with application root
`/opt/respawn-pond`, make a protected code backup, install only those files from
the 0.1.0 package, lint them, and restart only the already-running Pond PHP-FPM
unit using its actual service name:

```sh
install -d -m 0700 /root/respawn-pond-code-backup
cp -a /opt/respawn-pond/pond/Pond.php /root/respawn-pond-code-backup/Pond.php
cp -a /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php /root/respawn-pond-code-backup/ConfigurationCapture.php
install -o root -g root -m 0644 /staging/respawn-pond/pond/Pond.php /opt/respawn-pond/pond/Pond.php
install -o root -g root -m 0644 /staging/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php
php -l /opt/respawn-pond/pond/Pond.php
php -l /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php
systemctl list-units --type=service 'php*-fpm.service'
```

`/staging/respawn-pond` is the extracted ZIP's `respawn-pond/` directory;
replace it with the actual staging path. Identify the running PHP-FPM unit used
by the existing Pond pool from that output and restart that exact unit to clear
OPcache. This procedure does not install the new Debian 13 service unit on KAM1.
To roll back, restore the two backed-up files and restart the same PHP-FPM unit.
Never run `init`, replace `pond.sqlite`, regenerate the key or change the
deployed HTTPS service during this code-only update.

## Existing 0.1.0 Capture Correction

This correction needs no Pond update, enrolment change, secret manifest, module
database migration or Asterisk reload. Keep the existing provider connection and
revision journal. Do not enable protection or change routing for this test.

Build the reviewed corrected `0-1-0_Dev` workspace with the commands below.
This procedure remains available for older installed modules; the operator's
updated development module has now passed populated capture and retention:

```sh
test "$(git branch --show-current)" = 0-1-0_Dev
sh tools/check.sh
php tools/demo.php
(cd artifacts && sha256sum -c SHA256SUMS)
```

Transfer `artifacts/respawn-0.1.0.tgz` and `artifacts/SHA256SUMS` through the
approved administrative channel to `/root/respawn-capture-update/` on the
development PBX. The version stays 0.1.0; use the checksum to identify the build.
Validate it against the reviewed workspace value, not just an untrusted
accompanying checksum file.

Run the following as root in the development host's administrative console. It
backs up and replaces only the two collector files in the already installed
module, preserving their owner/group/mode. If the installed module path differs,
set `MODULE` to its actual path. Stop on any failure. No uninstall, settings reset
or deletion of revision storage is needed.

```sh
set -eu
MODULE=/var/www/html/admin/modules/respawn
UPDATE=/root/respawn-capture-update
cd "$UPDATE"
sha256sum -c --ignore-missing SHA256SUMS
STAGE=$(mktemp -d "$UPDATE/extracted.XXXXXXXX")
tar -xzf respawn-0.1.0.tgz -C "$STAGE"
php -l "$STAGE/respawn/src/Reconstruction/FreePbxConfigurationCollector.php"
php -l "$STAGE/respawn/src/Reconstruction/PuddleCollector.php"
BACKUP=$(mktemp -d /root/respawn-capture-backup.XXXXXXXX)
for FILE in FreePbxConfigurationCollector.php PuddleCollector.php; do
	TARGET="$MODULE/src/Reconstruction/$FILE"
	cp -a "$TARGET" "$BACKUP/$FILE"
done
for FILE in FreePbxConfigurationCollector.php PuddleCollector.php; do
	TARGET="$MODULE/src/Reconstruction/$FILE"
	install -o "$(stat -c %u "$TARGET")" -g "$(stat -c %g "$TARGET")" \
		-m "$(stat -c %a "$TARGET")" "$STAGE/respawn/src/Reconstruction/$FILE" "$TARGET"
	php -l "$TARGET"
	cmp "$STAGE/respawn/src/Reconstruction/$FILE" "$TARGET"
done
printf 'Collector code backup: %s\n' "$BACKUP"
```

Each new `fwconsole` process loads the corrected CLI code. No PHP-FPM, Asterisk
or other service restart is required for these CLI tests. Use the existing
approved FreePBX CLI account:

```sh
fwconsole respawn revision-capture
fwconsole respawn revision-list
```

Expect `configurationComplete: true`, `credentialStatus: not_configured` and
`recoveryReady: false` without a manifest. The state can correctly be
`configuration_captured_credentials_incomplete`: supported non-secret
configuration is complete, but credentials are not recovery-ready. Copy the
returned revision ID into the following commands:

```sh
REVISION_ID=rev_REPLACE_WITH_RETURNED_ID
fwconsole respawn revision-show --revision-id="$REVISION_ID"
fwconsole respawn revision-capture
```

Before publication, confirm the revision's `objects` include `user:7201`,
`user:7202`, `pjsip:7201`, `pjsip:7202` and `ringgroup:7290`, and its
`managedScope.complete` is true. Stop on an incomplete capture or missing object.
With no supported source changes, the repeat capture must report unchanged with
the same revision ID/hash. Do not recreate the test objects. Then run:

```sh
fwconsole respawn revision-publish --revision-id="$REVISION_ID"
fwconsole respawn revision-verify --revision-id="$REVISION_ID"
fwconsole respawn revision-show --revision-id="$REVISION_ID"
```

Publication must report `configuration_retained_and_readback_verified` and
subsequent verification must confirm the same revision ID/content hash. The final
revision shows `retentionState: independently_readback_verified`, with
`recoveryReady: false`. Keep the output as populated live-test evidence. This
demonstrates retained configuration, not executable reconstruction, credential
recovery or live calls.

If code rollback is needed, restore the two files from the printed `BACKUP`
directory using `cp -a` to their original paths. Do not replace or delete the
revision journal, provider credentials or Pond storage.

## GUI Compatibility Diagnostic

The reported 7201/7202/7290 failure is traced to capture calling reconstruction
validators (`NativeProfile::userParameters`, the fixed PJSIP profile and
`FreePbxRinggroups::read`). The operator has now confirmed non-default concurrency,
`mohclass`, hint/MWI fields, optional absent PJSIP fields and `elsewhere` as the
relevant differences. Concealed values have not been inferred. Capture no longer
uses those reconstruction validators; the validators themselves remain unchanged.
The following original diagnostic is retained for reference and can run without
upgrading the installed 0.1.0 module.

Run this single read-only command on the existing development PBX as the normal
FreePBX administrative CLI account, using the currently installed 0.1.0 module.
It does not capture a revision, read a manifest, publish, reload or write PBX
configuration. It reads the existing snapshot interfaces and Ringgroups `get`.
Output contains field names, match status and only explicitly allowed enum/numeric
values. Passwords, hashes, free-form strings, destinations and unknown field
values are never printed. Do not substitute a SQL dump or raw snapshot dump.

```sh
php <<'PHP'
<?php
ini_set('display_errors', '0');
error_reporting(0);
try {
	require '/etc/freepbx.conf';
	require_once '/var/www/html/admin/modules/respawn/bootstrap.php';
	$freePbx = \FreePBX::Create();
	$snapshot = new \Respawn\Reconstruction\FreePbxSnapshot($freePbx->Database, $freePbx->astman);
	$report = static function (array $actual, array $expected): array {
		$result = [];
		$enumFields = ['voicemail','callwaiting','devicetype','tech','sipdriver','dtmfmode',
			'direct_media','rtp_symmetric','force_rport','rewrite_contact','remove_existing',
			'media_encryption','strategy','ringing','recording','progress','changecid',
			'needsconf','cwignore','cfignore','cpickup','trustrpid','sendrpid','avpf','timers',
			'icesupport','send_connected_line','user_eq_phone','webrtc','rtcp_mux','bundle'];
		$enums = ['','novm','ENABLED','DISABLED','fixed','adhoc','pjsip','chan_pjsip',
			'yes','no','auto','enabled','disabled','dontcare','always','never','default',
			'rfc4733','rfc2833','inband','info','auto_info','ringall','Ring','pai','rpid',
			'sdes','dtls'];
		$numericFields = ['ringtimer','cfringtimer','concurrency_limit','qualifyfreq',
			'max_contacts','grptime','annmsg_id','remotealert_id','toolate_id',
			'minimum_expiration','maximum_expiration','device_state_busy_at','rtp_timeout',
			'rtp_timeout_hold','timers_min_se','max_audio_streams','max_video_streams'];
		$keys = array_unique(array_merge(array_keys($actual), array_keys($expected)));
		sort($keys, SORT_STRING);
		foreach ($keys as $key) {
			if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_\/.-]{1,80}$/D', $key)) {
				$result['unrepresentableFieldName'] = true;
				continue;
			}
			if (preg_match('/secret|password|credential|token|md5|auth/i', $key)) { continue; }
			$present = array_key_exists($key, $actual);
			$known = array_key_exists($key, $expected);
			$value = $present && is_scalar($actual[$key]) ? (string) $actual[$key] : null;
			$entry = ['present'=>$present, 'profileField'=>$known];
			if ($known && $expected[$key] !== null) {
				$entry['matchesReconstructionDefault'] = $present && $value === (string) $expected[$key];
			}
			if ((in_array($key, $enumFields, true) && in_array($value, $enums, true))
				|| (in_array($key, $numericFields, true) && is_string($value)
					&& preg_match('/^[0-9]{1,8}$/D', $value))) {
				$entry['value'] = $value;
			}
			$result[$key] = $entry;
		}
		return $result;
	};
	$output = [];
	foreach (['7201','7202'] as $number) {
		$user = $snapshot->read('user', $number);
		$userDefaults = ['extension'=>null,'name'=>null,'voicemail'=>'novm','password'=>'',
			'ringtimer'=>'0','noanswer'=>'','recording'=>'','outboundcid'=>'','sipname'=>'',
			'noanswer_cid'=>'','busy_cid'=>'','chanunavail_cid'=>'','noanswer_dest'=>'',
			'busy_dest'=>'','chanunavail_dest'=>''];
		$output['users'][$number] = ['row'=>$report($user['row'] ?? [], $userDefaults),
			'ast'=>$report($user['ast'], \Respawn\Reconstruction\NativeProfile::userAst(
				$number, (string) ($user['row']['name'] ?? ''))),
			'callwaiting'=>$report(['callwaiting'=>$user['callwaiting']], ['callwaiting'=>'ENABLED'])];
		$device = $snapshot->read('pjsip', $number);
		$deviceDefaults = ['id'=>$number,'tech'=>'pjsip','dial'=>'PJSIP/' . $number,
			'devicetype'=>'fixed','user'=>$number,'description'=>null,'emergency_cid'=>'','hint_override'=>''];
		$settingsDefaults = \Respawn\Reconstruction\NativeProfile::deviceFixed(
			$number, (string) ($device['row']['description'] ?? ''));
		foreach (['context','transport','dtmfmode','allow','disallow','direct_media','rtp_symmetric',
			'force_rport','rewrite_contact','max_contacts','remove_existing','media_encryption','username'] as $key) {
			$settingsDefaults[$key] = null;
		}
		$output['pjsip'][$number] = ['row'=>$report($device['row'] ?? [], $deviceDefaults),
			'settings'=>$report($device['settings'], $settingsDefaults),
			'ast'=>$report($device['ast'], ['user'=>$number,'tech'=>'pjsip','dial'=>'PJSIP/' . $number,
				'type'=>'fixed','default_user'=>$number])];
	}
	$groupDefaults = ['grpnum'=>'7290','description'=>null,'strategy'=>'ringall','grptime'=>'20',
		'grplist'=>null,'postdest'=>'app-blackhole,hangup,1','grppre'=>'','annmsg_id'=>'0',
		'alertinfo'=>'','needsconf'=>'','remotealert_id'=>'0','toolate_id'=>'0','ringing'=>'Ring',
		'cwignore'=>'','cfignore'=>'','cpickup'=>'','recording'=>'dontcare','progress'=>'yes',
		'elsewhere'=>'','rvolume'=>'','changecid'=>'default','fixedcid'=>''];
	$output['ringgroups']['7290'] = $report($freePbx->Ringgroups->get('7290') ?: [], $groupDefaults);
	echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (\Throwable $exception) {
	fwrite(STDERR, "CAPTURE_DIAGNOSTIC_FAILED: no exception details printed\n");
	exit(1);
}
PHP
```

Values outside the safe enum/numeric sets remain redacted even for known fields.
The correction's regressions use the reported field presence/absence and known
concurrency value, with explicit synthetic examples for concealed values. They
do not claim to reproduce undisclosed values or prove the live populated round-trip.

## Evidence And Limits

`php tests/puddle-capture.php` uses synthetic PDO and FreePBX interface doubles.
It covers zero/one/multiple Ring Groups, users/devices without groups, empty
collections, module dependencies, protected/missing/unsafe manifests,
initial/unchanged/changed/removed capture, interrupted collection, restart and
non-executable snapshot handling. It does not establish behaviour on a running
FreePBX host. On 24 October 2026 the operator confirmed populated FreePBX
17/Debian 12/Asterisk 22 capture of two users, two PJSIP devices and one Ring
Group with no configuration gaps, authenticated HTTPS retention on an independent
Debian 13 Pond, immediate read-back and subsequent verification with matching
revision identifiers, content hashes and receipts. Empty collections also passed.
Configuration was complete; credentials were not configured and recovery
readiness remained false. Executable restoration and credential recovery were
not tested. See [dated evidence](validation.md). No
manual capture enables The Stream, contacts The Pond, changes PBX configuration
or claims independent recovery readiness.