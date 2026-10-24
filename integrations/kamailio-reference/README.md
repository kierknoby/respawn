# kamailio-reference 0.1.1

This reference defines the boundary between Respawn recovery control and an
operator's routing authority. Kamailio is one possible implementation; it is not
a Respawn dependency. The example contains no private IP addresses, credentials,
tenant identifiers or existing KAM1 configuration.

`RoutingControlClient` sends the public
[`respawn.routing-control.v1`](../../contracts/routing-control.v1.schema.json)
request to an explicitly configured, trusted HTTPS control endpoint. A controller
in front of Kamailio is responsible for authenticating the operator, validating
scope and evidence, enforcing revision/authority-epoch fencing, coordinating
the route change and returning a bound receipt or a safe failure. The adapter
supports prepare/verify requests and **always blocks activation**. It has no
Kamailio RPC credentials and makes no direct routing changes. This is an
interface example, not automatic rerouting or a production-ready Kamailio
integration.

## Request And Receipt

Requests bind action, operation, PBX, target, blueprint hash, expected revision,
authority epoch, idempotency key and readiness evidence. Evidence is explicitly
`passed`, `failed` or `unknown`, with observation and expiry times. A routing
authority must independently validate evidence and its source; a JSON field or
hash is not proof. Responses echo all identity and version bindings and report
`prepared`, `verified`, `blocked`, `unavailable` or `unknown`. Only the authority
may report `active`, and this sample never asks it to do so.

Authentication uses a mode-protected bearer-file reference over verified HTTPS.
Requests are bounded to 64 KiB, redirects and ambient proxies are disabled,
responses are identity-bound and timestamp-checked. Timeout or malformed
response means unknown; the caller must reconcile using the same idempotency key
before requesting another operation. Do not retry an unknown route mutation as
a new request.

## Configuration

Copy `config.example.json` into an operator-controlled directory, set the actual
trusted endpoint, token-file reference and explicitly allowlisted DNS name. Keep
`activationEnabled` false. Do not store credentials in JSON, Kamailio config,
source control or logs. `kamailio.cfg.example` is a fail-closed routing fragment,
not a complete Kamailio configuration; it returns 503 and changes no route.

The PHP control adapter is used by an operator-owned recovery controller after
that controller has authenticated the Respawn request and assembled evidence:

```php
require '/opt/respawn-kamailio-reference/integrations/kamailio-reference/src/RoutingControlClient.php';

$configuration = json_decode(file_get_contents('/etc/respawn/routing-control.json'), true, 16, JSON_THROW_ON_ERROR);
$routing = new Respawn\Integration\KamailioReference\RoutingControlClient($configuration);
$receipt = $routing->request($controllerValidatedPrepareRequest);
```

The controller must persist its own idempotency and authority-epoch state and
reconcile an unknown outcome. This sample does not implement that controller or
translate a receipt into a Kamailio route mutation.

## Install And Test

Requirements: PHP 8.2+, PHP cURL, a trusted TLS certificate and an
operator-managed control endpoint. The reference client is standalone and does
not require the Respawn module or Kamailio to run its contract tests.

```sh
unzip -q respawn-kamailio-reference-0.1.1.zip -d /opt
php /opt/respawn-kamailio-reference/integrations/kamailio-reference/tests/routing-control.php
```

The test injects a synthetic prepare/verify controller response and confirms the
adapter refuses activation without contacting it. It proves no Kamailio syntax,
route outcome, call continuity, production authority or live recovery. Before an
operator deployment, validate the fragment with the installed Kamailio version,
test the external controller in an isolated environment, exercise stale/unknown
receipt and outage cases, and obtain independent operator approval. Production
activation remains deliberately unimplemented here.