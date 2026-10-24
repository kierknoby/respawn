# my-connect-reference 0.1.1

This is a provider-neutral reference adapter for an external recovery-control
service. It demonstrates how a controller can use Respawn's existing Stream
retention and credential-vault interfaces without importing proprietary
my-connect code, private API details, or a dependency on my-connect itself.

The working default remains Respawn's `HttpsProvider` and standalone Pond over
the public `respawn.provider-rpc.v2` HTTPS contract. `MyConnectStyleStream`
composes that client and implements the existing
`RetainedBlueprintProvider`, `OrderedRetainedBlueprintProvider` and
`RecoverableCredentialVault` interfaces used by the protection coordinator.
An operator can place this adapter at the boundary of their own control service;
the controller must still implement the documented HTTPS contract and all
durable-acknowledgement, scope, integrity, replay and TLS requirements. This
reference does not claim compatibility with any private my-connect endpoint.

## Contract

The Stream posts a JSON envelope containing `schema`, `connectionId`, `method`,
`pbxId`, `parameters`, a fresh random `requestId` and RFC3339 `sentAt`. The
version is `respawn.provider-rpc.v2`. Responses bind the same schema, connection,
method and PBX identity and include `ok`, `observedAt`, `expiresAt` and a typed
result object. Requests and responses are bounded to 64 KiB. TLS certificate and
hostname verification are mandatory; redirects and ambient proxies are rejected.

For retained revisions, a controller must validate canonical blueprint bytes,
identity and idempotency, persist the full revision before returning
`durably_retained`, and return a receipt bound to the exact PBX, connection,
revision, hash, publication and idempotency key. Retries use the same stable
transaction identity and fresh request IDs. The client then retrieves the exact
revision and verifies its hash. Credential escrow is scoped to an immutable
versioned reference; the source requires encrypted-at-rest acknowledgement and
read-back of the exact value. Replayed/stale requests, conflicts and unknown
outcomes must fail closed. Transport completion alone is never acknowledgement.

The machine-readable envelope is
[`provider-rpc.v2.schema.json`](../../contracts/provider-rpc.v2.schema.json).
Method semantics and TLS policy are specified in
[`docs/https-profile.md`](../../docs/https-profile.md) and
[`docs/provider-contracts.md`](../../docs/provider-contracts.md).

## Configuration

Copy `config.example.json` to a host-protected file and replace the example
hostname, source identity and token-file path. Do not add a token value to JSON.
Enrolment and key custody are performed by the independently installed Pond; no
controller identity, address or credential in this template is deployment data.

The adapter is instantiated inside the existing Respawn PHP runtime after
loading the module bootstrap and validated configuration:

```php
require '/opt/respawn-my-connect-reference/module/bootstrap.php';
require '/opt/respawn-my-connect-reference/integrations/my-connect-reference/src/MyConnectStyleStream.php';

$configuration = json_decode(file_get_contents('/etc/respawn/provider.json'), true, 16, JSON_THROW_ON_ERROR);
$stream = new Respawn\Integration\MyConnectReference\MyConnectStyleStream(
	new Respawn\Provider\HttpsProvider($configuration)
);
```

The protection coordinator supplies captured blueprints, revision IDs,
idempotency keys and parent lineage to these interfaces. Do not call the adapter
with hand-authored or unvalidated production recovery state.

## Installation And Test

Requirements: PHP 8.2+, the Respawn module source/interfaces, and PHP cURL for
real HTTPS. The reference archive includes the matching Respawn module source
and GPLv3+ licence. Extract it in a private development directory, review the
configuration, then run the adapter contract test:

```sh
unzip -q respawn-my-connect-reference-0.1.1.zip -d /opt
php /opt/respawn-my-connect-reference/integrations/my-connect-reference/tests/adapter.php
```

The test uses an injected synthetic transport and confirms the adapter delegates
the standard ordered-retention contract. It makes no network request and proves
neither remote durability nor private-service compatibility. End-to-end default
transport/storage tests are `php tests/pond.php` in the Respawn repository; they
use a separate local HTTP process, real SQLite/AES-GCM and synthetic PBX data.
For a real external controller, configure a trusted HTTPS endpoint and use the
shared Stream conformance suite. Never use simulated receipts to claim protection.

## Limitations

This package is a reusable protocol adapter, not an implementation of a private
control plane, provisioning service, billing system, connectivity service or
SBC. The default contract client currently supports the repository's documented
public IPv4 HTTPS profile; private-address and IPv6 endpoints are rejected.
Separate-host TLS, real FreePBX capture and external-controller compatibility
remain integration tests, not results established by this reference.