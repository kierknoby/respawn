# Standalone Pond 0.0.1

An experimental, independently deployable retention service, not the Development
Pond JSON ledger. PHP 8.2+, PDO SQLite, OpenSSL and a local POSIX filesystem are
required; recovery additionally needs PHP cURL. No cloud or proprietary service
is required. This is ready for an isolated deployment test, not production use.

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

Build `artifacts/respawn-pond-0.0.1.tgz` with `python3 tools/package.py` after
building the website. The archive contains this directory, shared module code
and LICENSE, with no runtime data or keys. Extract under `/opt`, resulting in
`/opt/respawn-pond`. Keep application code owned by the administrator and
non-writable by the service account.

As the lab host administrator, create an unprivileged `respawn-pond` account and
prepare these directories (adapt service-account creation to the host):

```sh
install -d -m 0700 -o respawn-pond -g respawn-pond /var/lib/respawn-pond
install -d -m 0700 -o respawn-pond -g respawn-pond /etc/respawn-pond
runuser -u respawn-pond -- php /opt/respawn-pond/pond/bin/pond.php init /var/lib/respawn-pond/state /etc/respawn-pond/master.key
```

Use a dedicated PHP-FPM pool based on `php-fpm.conf.example` and an nginx TLS
virtual host based on `nginx.conf.example`. Supply a certificate trusted by the
PBX operating system, real lab hostname, and correct local FPM socket paths.
The current source client permits publicly resolved IPv4 HTTPS addresses only;
private/loopback destinations are intentionally rejected. Do not weaken that
guard for testing: use an authorised isolated public-address lab endpoint with
firewall restrictions, or record this as a blocked transport prerequisite.
Run `nginx -t` and the installed PHP-FPM binary's configuration test before
starting/reloading services under the host's normal change process. Those actions
have not been executed in this workspace.

Serve only the exact `/rpc` route. Never expose the checkout, SQLite directory,
key, tokens or recovery bundles as static content. Do not trust forwarded HTTPS
headers. Terminate TLS in the shown virtual host and pass `HTTPS=on` only from
that TLS listener. Do not enable `RESPAWN_POND_LOOPBACK_TEST` in a deployed pool;
it exists solely for the automated local loopback test. Set request/body timeouts,
rate limits and source firewall policy. Do not log request/response bodies or
Authorization headers. `index.php` returns only generic failures; PHP display
errors must remain off. No network enrolment or routing/activation API exists.

## Enrolment And Recovery Authority

An administrator approves enrolment locally on Server B. Generate separate
random bearer tokens of at least 32 characters using the lab's secret-management
tool; store them in canonical absolute files, mode 0600, beneath trusted private
directories. Never pass a token value as a command argument. Do not use sample
values as deployed credentials. Tokens are stored only as SHA-256 digests in the
Pond database; possession grants the explicitly enrolled scope. Use TLS and
rotate/revoke tokens through this local workflow.

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
php -r 'require "/opt/respawn-pond/module/bootstrap.php"; echo Respawn\Provider\ConnectionIdentity::forConfiguration(json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR)), PHP_EOL;' /private/respawn/provider.json
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
php /opt/respawn-pond/pond/bin/pond.php enrol /var/lib/respawn-pond/state /etc/respawn-pond/master.key /private/respawn/source-enrolment.json /private/respawn/source.token
php /opt/respawn-pond/pond/bin/pond.php enrol /var/lib/respawn-pond/state /etc/respawn-pond/master.key /private/respawn/recovery-enrolment.json /private/respawn/recovery.token
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
php /opt/respawn-pond/pond/bin/pond.php recover /private/respawn/recovery-provider.json /private/respawn/recovered
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
Use `php /opt/respawn-pond/tools/preflight.php --role=recovery --bundle=/private/respawn/recovered`
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