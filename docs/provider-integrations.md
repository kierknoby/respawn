# Provider-Neutral Integrations 0.1.1

Respawn defines configuration, recovery data, contracts and evidence boundaries;
operators provide hosting, telecoms infrastructure and routing
services. This is the BYOT principle: neither my-connect nor KAM1/Kamailio is a
required runtime dependency. The reference packages contain no proprietary code,
private addresses, credentials or deployment-specific identifiers.

## The Stream

The Stream is the existing Puddle-to-Pond transport and retry path. Its default
implementation is PHP `HttpsProvider` using the versioned
`respawn.provider-rpc.v2` JSON envelope over authenticated TLS. The
machine-readable contract is
[`provider-rpc.v2.schema.json`](../contracts/provider-rpc.v2.schema.json); exact
method/result semantics and security limits are in
[`https-profile.md`](https-profile.md).

Every request binds a configured connection ID and source PBX, method, structured
parameters, fresh request ID and timestamp. A response must bind the same
identities/method, have fresh timestamps and fit the body limit. Bearer
credentials come from protected files. The client verifies certificates,
rejects redirects and proxies, bounds time/size, and rejects unknown outcomes.
For protection, the outbox stores immutable revision IDs and idempotency keys;
retries use fresh RPC IDs with the same revision transaction. The Pond returns a
durable receipt only after storage commits. The source retrieves the exact
revision and verifies its canonical hash and credentials before marking it
verified. A transmitted request or HTTP response alone is not an acknowledgement.

Puddle `configuration-capture.v1` snapshots use separate
`retainConfigurationCapture` and `retrieveConfigurationCapture` methods and a
separate immutable object kind. The Pond retains the exact content-hash-bound
snapshot; the source verifies the durable acknowledgement and exact read-back.
These RPCs do not publish a reconstruction blueprint, escrow credentials or
claim recovery readiness. Existing Pond tables are reused, so the feature needs
no database schema, identity or encryption-key migration.

The current network profile supports explicitly allowlisted, globally routable
IPv4 HTTPS endpoints. Private-address and IPv6 destinations are deliberately
unsupported by this client profile; the guard has not been weakened for local
testing. Extending that profile requires a reviewed versioned transport change.

[`my-connect-reference`](../integrations/my-connect-reference/README.md) is a
public, reusable adapter over these existing interfaces. It composes the tested
HTTPS provider and demonstrates the shape an external control service can
implement. It does not include or claim compatibility with a private my-connect
implementation. The standalone Pond implements the same contract and has no
dependency on either reference package.

## Routing Control

Routing is outside the Stream and reconstruction module. A recovery controller
may ask an operator's routing authority to prepare or verify a change using
[`routing-control.v1.schema.json`](../contracts/routing-control.v1.schema.json).
Requests bind operation, source and target, exact blueprint hash, expected
revision, authority epoch, idempotency key and evidence references. The routing
authority must authenticate the caller, independently assess the freshness and
scope of evidence, fence stale authority epochs, and return a bound receipt or a
specific safe failure. Hashes and supplied evidence references are not
signatures or proof that a call occurred.

The schema requires all ten readiness categories to be passed before an
activation request is valid. An active receipt must bind an activation receipt
and evidence reference. These are contract requirements, not a production
activation implementation. The
[`kamailio-reference`](../integrations/kamailio-reference/README.md) contains a
PHP HTTPS adapter for prepare/verify and an illustrative fail-closed Kamailio
fragment. Its adapter always blocks activation and never sends a routing
mutation. Automatic trunk, endpoint or production call rerouting is not
implemented or demonstrated.

## Conformance And Packages

Run the repository gates from its root:

```sh
sh tools/check.sh
php integrations/my-connect-reference/tests/adapter.php
php integrations/kamailio-reference/tests/routing-control.php
```

`tests/schema.py` checks positive/negative examples for both public JSON Schema
contracts. The reference tests use injected synthetic transports. They check
binding and fail-closed behaviour but cannot prove remote durability, public TLS,
Kamailio syntax, real call readiness, or external-provider compatibility.

Build reproducible, versioned distributions with:

```sh
python3 tools/build_site.py
python3 tools/package.py
```

The output includes `respawn-pond-0.1.1.zip`,
`respawn-my-connect-reference-0.1.1.zip` and
`respawn-kamailio-reference-0.1.1.zip`, with individual `.sha256` files and a
combined `SHA256SUMS`. The standalone Pond archive contains neither reference
integration. Verify each package before extracting it. The local artifact build
is not a GitHub release; publication remains a separate authorised step.