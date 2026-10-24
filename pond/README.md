# Standalone Pond 0.1.0

An experimental, independently deployable retention service, not the Development
Pond JSON ledger. PHP 8.2+, PDO SQLite, OpenSSL and a local POSIX filesystem are
required; recovery additionally needs PHP cURL. No cloud or proprietary service
is required. Neither my-connect nor Kamailio is required. This is ready for an
isolated deployment test, not production use.

The service uses SQLite transactions (`synchronous=FULL`, rollback journal),
immutable tenant/PBX/revision records, a persisted Pond identity, and AES-256-GCM
credential encryption with random 96-bit nonces and tenant/PBX/reference-bound
associated data. Credentials are immutable per versioned reference. Changing a
password requires a new reference. Keep old entries in the source manifest for
outstanding revisions; select the new one explicitly, for example
`"activeReferences": {"1001": "secret:SOURCE_ID:1001:v2"}` alongside `references`.
Existing manifests with one reference per extension still work unchanged.
No password or password digest enters a
blueprint or acknowledgement. A receipt is returned only after transaction commit.
Read-back checks canonical integrity; hashes are not signatures.

## Installation On A Separate Lab Server

Build `artifacts/respawn-pond-0.1.0.zip` with `python3 tools/build_site.py` and
`python3 tools/package.py`. Verify the ZIP from `artifacts/` before transfer:

```sh
(cd artifacts && sha256sum -c respawn-pond-0.1.0.zip.sha256)
```

The installer supports Debian 13 (including 13.7) only. It installs Nginx,
PHP 8.4 CLI/FPM, PDO SQLite, PHP cURL for the recovery CLI, OpenSSL and CA
certificates. Kamailio, RTPengine, FreePBX, Asterisk and other Respawn reference
integrations are not installed or modified. For an isolated lab, allow one CPU,
1 GiB RAM and local storage for the database, credentials and backups; this is
not a capacity certification. Use a local filesystem for SQLite.

Extract the ZIP to `/opt/respawn-pond` and run preflight before installation:

```sh
unzip -q respawn-pond-0.1.0.zip -d /opt
sh /opt/respawn-pond/pond/install.sh --preflight
```

Preflight is read-only. Exit status 1 means an unsupported OS, host conflict or
other blocking prerequisite; missing PHP/Nginx packages are listed and are
installed by the explicit install action. Prepare a DNS hostname and an
administrator-managed certificate/key trusted by the PBX. The installer checks
the certificate name and private-key file, installs dependencies, creates the
unprivileged `respawn-pond` account, a dedicated PHP-FPM service/pool and
private storage, then initializes SQLite and a random 32-byte Pond key if and
only if neither already exists:

```sh
sh /opt/respawn-pond/pond/install.sh --install \
  --hostname pond.example.invalid \
  --tls-cert /etc/ssl/certs/pond-fullchain.pem \
  --tls-key /etc/ssl/private/pond-key.pem
```

Replace the example host and certificate paths. The installer creates the
unprivileged `respawn-pond` account, a versioned root-owned application release,
`/opt/respawn-pond/current`, `/var/lib/respawn-pond/state`, a protected
`/etc/respawn-pond/master.key`, the SQLite store, and an isolated systemd
PHP-FPM master/pool. It preserves existing database/key state during repeat
installs/upgrades and rejects incomplete state instead of reinitialising it.
PHP-FPM listens on a private Unix socket. Nginx configuration is written to
`sites-available` but stays disabled; Nginx is not started by installation.

Only after reviewing the generated config and explicitly authorising HTTPS
exposure, enable the site:

```sh
sh /opt/respawn-pond/pond/install.sh --enable-https
```

This validates and starts/reloads Nginx as needed. It does not change firewall
rules; apply host network restrictions through the administrator's normal
process. Disable only the Pond site, without stopping shared Nginx, with
`sh /opt/respawn-pond/pond/install.sh --disable-https`.

The installer creates no public health route. `/rpc` requires HTTPS, a scoped
bearer token and a fresh authenticated envelope. TLS terminates in the configured
Nginx site, which supplies `HTTPS=on` only to the isolated Pond PHP-FPM service.
Only `/rpc` is forwarded. Keep the database, key, tokens and recovery bundles
outside the document root; never log request bodies or Authorization headers.
Do not set `RESPAWN_POND_LOOPBACK_TEST` on a deployed service.

## Service Status And Logs

The local status command checks SQLite integrity, key-ID consistency, retained
revision/credential counts and whether recovery material is available. It opens
the database in query-only mode and requires the private key file:

```sh
systemctl status respawn-pond-php-fpm.service
runuser -u respawn-pond -- php /opt/respawn-pond/current/pond/bin/pond.php status /var/lib/respawn-pond/state /etc/respawn-pond/master.key
journalctl -u respawn-pond-php-fpm.service --since today
journalctl -u nginx --since today
```

The installer `--status` action also reports package preflight and Pond state.
The authenticated Stream `status` RPC reports the source-bound latest retained
revision/hash and credential availability. No unauthenticated health URL is
exposed. `fwconsole respawn protection-status` on Server A reports local capture,
pending publications, acknowledgement and independent retrieval verification.
Unavailable transport is not successful retention.

Restart or stop only the isolated Pond PHP-FPM service; Nginx may serve other
sites and must not be stopped globally just for Respawn:

```sh
systemctl restart respawn-pond-php-fpm.service
systemctl stop respawn-pond-php-fpm.service
systemctl start respawn-pond-php-fpm.service
```

HTTPS exposure remains controlled by the enabled Nginx site and host firewall
policy. Use `--disable-https` to remove only the Pond site's symlink.

### Existing Manual Pond Installation

The capture RPC additions require two PHP source files only:
`pond/Pond.php` and `module/src/Reconstruction/ConfigurationCapture.php`.
`pond/public/index.php`, Nginx/FPM configuration, the SQLite schema, Pond
identity, enrolments and encryption key do not change. The existing generic
`provider-rpc.v2` dispatcher handles these methods, and existing `objects`
storage holds them under a new `configurationCapture` kind. There is no database
migration and do not run `init` on an existing Pond.

For the earlier manual archive layout installed at `/opt/respawn-pond`, back up
and replace only those source files during an operator-approved short PHP-FPM
maintenance window. Set `POND_PACKAGE` to the extracted new package root:

```sh
POND_PACKAGE=/secure/staging/respawn-pond
BACKUP=/root/respawn-pond-code-backup
install -d -m 0700 "$BACKUP/pond" "$BACKUP/module/src/Reconstruction"
cp -a /opt/respawn-pond/pond/Pond.php "$BACKUP/pond/Pond.php"
cp -a /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php "$BACKUP/module/src/Reconstruction/ConfigurationCapture.php"
install -o root -g root -m 0644 "$POND_PACKAGE/pond/Pond.php" /opt/respawn-pond/pond/Pond.php
install -d -o root -g root -m 0755 /opt/respawn-pond/module/src/Reconstruction
install -o root -g root -m 0644 "$POND_PACKAGE/module/src/Reconstruction/ConfigurationCapture.php" /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php
php -l /opt/respawn-pond/pond/Pond.php
php -l /opt/respawn-pond/module/src/Reconstruction/ConfigurationCapture.php
```

Restart only the currently active Pond PHP-FPM unit to clear OPcache; do not
restart Kamailio, RTPengine or Nginx unless the existing host's deployment
procedure specifically requires it. Check the active PHP-FPM unit name before
the change. Verify `systemctl is-active` and `pond.php status` afterwards. To
roll back, restore the two backed-up files with their previous owner/mode and
restart that same PHP-FPM unit. Do not restore, replace or reinitialise the
database or key. This compatibility update has not been performed on KAM1 in
this workspace.

## Updates, Rollback And Removal

Verify and extract a newer version to a staging directory, run its read-only
preflight, then run that package's installer with the same explicit hostname and
TLS paths. It creates a new versioned release and switches `current`; state and
key paths are not replaced:

```sh
unzip -q respawn-pond-0.1.1.zip -d /tmp/respawn-pond-update
sh /tmp/respawn-pond-update/respawn-pond/pond/install.sh --preflight
sh /tmp/respawn-pond-update/respawn-pond/pond/install.sh --install \
  --hostname pond.example.invalid \
  --tls-cert /etc/ssl/certs/pond-fullchain.pem \
  --tls-key /etc/ssl/private/pond-key.pem
sh /opt/respawn-pond/current/pond/install.sh --status
```

Use the actual release name and host-managed TLS paths. Existing release
directories are immutable; installation refuses an unmarked conflicting release.
Keep old releases until status and recovery checks pass. Roll back application
code without changing the database or key:

```sh
sh /opt/respawn-pond/current/pond/install.sh --rollback --release-version 0.1.0
```

For removal, first disable only the Pond HTTPS site and stop its isolated FPM
unit. Remove the managed configuration only after verifying its marker and
backing it up:

```sh
sh /opt/respawn-pond/current/pond/install.sh --disable-https
systemctl disable --now respawn-pond-php-fpm.service
rm /etc/nginx/sites-available/respawn-pond.conf
rm /etc/respawn-pond/pool.conf /etc/respawn-pond/php-fpm.conf
rm /etc/systemd/system/respawn-pond-php-fpm.service
systemctl daemon-reload
```

This leaves Nginx itself running and retains `/var/lib/respawn-pond` and
`/etc/respawn-pond/master.key`. Keep those recovery data/key files inaccessible
until they are backed up and their owner separately authorises disposal. No
installer action silently deletes retained recovery information.

## Enrolment And Recovery Authority

Use separate files and enrolment scopes. Do not overwrite an existing token
file; generate a fresh filename, enrol it, and revoke the old principal during
rotation. Keep the recovery token off Server A.

Prepare a private provider JSON matching the source GUI exactly:

```json
{
  "schema": "respawn.provider-config.v1",
  "adapterId": "https-json-v1",
  "endpoint": "https://pond.example.invalid/rpc",
  "credentialRef": "/private/respawn/source.token",
  "sourcePbxId": "REPLACE_WITH_INSTALLED_SOURCE_ID",
  "allowedHosts": ["pond.example.invalid"]
}
```

Replace hostname, source identity and protected token path with the lab values.
Compute the non-secret connection identity from that file:

```sh
php -r 'require "/opt/respawn-pond/current/module/bootstrap.php"; echo Respawn\Provider\ConnectionIdentity::forConfiguration(json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR)), PHP_EOL;' /private/respawn/provider.json
```

Create a private enrolment JSON file with that exact connection identity:

```json
{
  "tenantId": "lab-tenant",
  "pbxId": "REPLACE_WITH_INSTALLED_SOURCE_ID",
  "connectionId": "REPLACE_WITH_COMPUTED_CONNECTION_ID",
  "permissions": ["retain", "read", "escrow", "recover"]
}
```

Enrol the publication token, then a **different** independently held recovery
token using a second enrolment file with permissions `["read", "recover"]`.
Run as the Pond service account, with all input files readable only by it:

```sh
php /opt/respawn-pond/current/pond/bin/pond.php enrol /var/lib/respawn-pond/state /etc/respawn-pond/master.key /private/respawn/source-enrolment.json /private/respawn/source.token
php /opt/respawn-pond/current/pond/bin/pond.php enrol /var/lib/respawn-pond/state /etc/respawn-pond/master.key /private/respawn/recovery-enrolment.json /private/respawn/recovery.token
```

The source needs `recover` to compare retrieved secrets with their original
values before declaring readiness. Recovery principals cannot publish or escrow.
Scopes bind tenant, stable source PBX and connection; callers cannot choose a
different tenant in a request. No permission grants native execution, provisioning
or routing authority. Replay IDs and 60-second request freshness are persisted;
retries use fresh RPC IDs with stable revision transaction keys. Synchronise clocks.

Revoke with the same command layout and action `revoke`; re-enrol a fresh token
before removing the old one during rotation. Deleting a principal never deletes
retained data. Keep recovery tokens and their source identity/configuration
independent of Server A. Source loss must not require restoring its token file.

## Keys, Storage And Recovery

`retainRevision` may include `ordering: {"parentRevisionId": "rev-..."}` (null
for initial capture). Only a new child of the current recovery head advances it.
Delayed/unordered legacy submissions remain historical. Duplicate receipt and
lineage are immutable. Existing databases/heads/receipts are preserved. The source
requires the returned head and latest read-back to match its publication; receipt
`version` is acceptance identity, not source chronology. Restored sources need
the explicit `protection-resume` workflow in the initial-test guide. River/Lake
remain unchanged.

The 32-byte master key is generated on Server B, mode 0600, not sent to the PBX.
Back it up under independently controlled, encrypted offline custody. Losing it
loses credentials; anyone with it and the database can decrypt them. This is
single-master-key custody, not an HSM/KMS, automated master-key rotation or an
independent security certification. Do not replace the key in place. Credential
rotation is supported through new versioned references; a future master-key
rotation needs an audited decrypt/re-encrypt migration, not a renamed key file.

Generate separate bearer tokens with an approved secret tool, for example
`openssl rand -hex 32` redirected directly to a new file beneath a mode-0700
directory, then `chmod 0600`. Never print them or place values in arguments.
Keep the recovery token/configuration and encrypted master-key backup off Server A.
To restore B, stop its pool and restore the consistent database and exact matching
master key, service-account-owned mode 0600 beneath mode-0700 directories. Run
preflight and verify a recovery bundle before reopening access. Do not run `init`
over a restored key. Key loss without backup irretrievably loses passwords;
token rotation is new enrolment plus revocation, not master-key rotation.

Back up SQLite using its online backup API or while PHP-FPM is stopped, together
with the correct key and enrolment records under separate access controls. Do
not copy an actively changing database alone. Avoid network filesystems. Full
sync depends on the filesystem/device honouring flushes; process restart is
tested, power-loss durability and separate-host deployment are not. Monitor disk
capacity, certificate expiry, key custody and service errors. There is no pruning
API: all accepted revisions and credential versions remain retained.

From a recovery workstation or clean target containing this package, use a
protected provider config with the **source PBX identity**, same endpoint/adapter
and independently held recovery token path. The target's own Respawn identity
remains different. Under a trusted private parent, run:

```sh
php /opt/respawn-pond/current/pond/bin/pond.php recover /private/respawn/recovery-provider.json /private/respawn/recovered
```

The new output directory contains `blueprint.json`, `secrets.json` and mode-0600
credential files. It is sensitive recovery material, not a public artefact. The
command independently retrieves the latest acknowledged revision, verifies it,
retrieves/decrypts every referenced password, validates the native `FileSecrets`
manifest and fsyncs output before reporting success. Failed output is incomplete:
do not use it; investigate and use a new private directory. Reconstruct only after
review, using the existing explicitly confirmed FreePBX command documented in
`../docs/initial-test.md`. Retrieval itself never writes PBX configuration.

The writer validates a canonical private parent before creating a new destination,
rejects existing/symlinked destinations and creates/fsyncs private files exclusively.
Use `php /opt/respawn-pond/current/tools/preflight.php --role=recovery --bundle=/private/respawn/recovered`
for read-only bundle validation. Dispose of the encrypted lab volume and its
snapshots through the approved process after review; ordinary deletion or `shred`
is not proof of erasure on SSD/COW storage. Never archive recovered bundles.

## Evidence

`php tests/pond.php` starts a separate loopback HTTP process, uses real SQLite and
AES-GCM, captures and changes synthetic configuration, rotates a password,
restarts the service, deletes the entire source directory, retrieves recovery
material using another token, and runs the existing executor on a clean simulated
target. Password equality is asserted without printing values. Isolation, replay,
idempotency, immutability and revocation are tested. It does not prove real
FreePBX, public HTTPS deployment, physical storage failure, or live telephony.
River and Lake are unchanged; independent tenant/PBX/revision identities remain
available for future separately authorised federation.