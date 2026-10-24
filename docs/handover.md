# Respawn 0.1.0 Development Handover

## Current Disposition

Respawn is an experimental FreePBX reconstruction foundation, not a production
recovery service. Its intended alternative to a continuously maintained warm spare
is to retain a validated blueprint and deploy a compatible replacement when
needed. That proposition remains an objective: complete source-to-target recovery,
provider routing takeover, live telephony and recovery timing have not been
established end to end.

The working tree is intended to remain local. Do not stage, commit, push, publish,
deploy or run against a live PBX/provider without separate authorisation. Synthetic
and contract tests do not establish live FreePBX, carrier, SBC or call behaviour.

Respawn 0.1.0 includes public Stream and routing-control contracts with separate
my-connect-style and Kamailio reference archives. These are provider-neutral
examples, not dependencies or private-service compatibility claims. The
Kamailio reference always blocks activation. Rebuild packages with
`python3 tools/build_site.py && python3 tools/package.py`; no GitHub release or
external deployment has been performed.

## Operation Status

| Operation | Implemented behaviour | Current boundary |
| --- | --- | --- |
| Revert | Capture/retain revisions, compare, plan; synthetic target executes the selected older state | Native FreePBX updates/removals and verified ownership are unavailable. Native plans are non-executable where those capabilities are required. |
| Regenerate | Plan selected desired state; synthetic target repairs drift, verifies read-back and preserves foreign/shared objects | Native FreePBX target is create-only and ownership-unverified. Updates/removals/apply through the revision flow remain blocked. |
| Respawn | Provider-neutral application flow, mock orchestration, persistent local simulation and explicit policy gates | Real provider provisioning/connectivity/SBC operations, native replacement reconstruction and live activation are unverified. |

The separate native reconstruction CLI has selected create operations and a fixed
normal framework reload callback. It does not make native Revert/Regenerate
available. Local revision publication is bound to the exact retained submitted
blueprint, PBX identity, provider connection, content hash and positive version.

## Protection Lifecycle

`fwconsole respawn protection-reconcile` runs a configurable, disabled-by-default
coordinator with a 300-second default interval. It validates the current export,
creates local revisions, pins and queues exact snapshots, retries FIFO with a
stable connection-scoped key and bounded backoff, and requires independent
retrieval/hash verification before marking a revision locally acknowledged.
`protection-status` reports local observation, submission, provider acknowledgement,
retrieval verification, retry state, error, schedule due time and credential
readiness. The module does not install a scheduler; the host operator must manage
cron. Polling can miss changes made after a check and before source loss.

The HTTPS client now requires a durable-retention receipt and exact-revision
retrieval. The separate experimental `pond/` service now implements SQLite
retention and AES-GCM escrow with a Pond-held key, scoped local enrolment and
recovery-only credentials. Reconciliation verifies exact password retrieval;
the recovery CLI writes the existing native-compatible manifest. The independent
service test restarts the Pond, deletes source files and verifies a simulated
target with exact recovered passwords. Development Pond/MockProvider remain simulated.
Public TLS, actual separate-host deployment, FreePBX cron and native execution
have not been tested; no operational source-loss claim is authorised.

The synthetic protection suite exercises unchanged suppression, A/B/C FIFO retry,
lost response, crash recovery, A/B/A history, supported changes, retention repair,
provider changes, export failure and deletion of source-local state before
controller-fixture retrieval. This is not a real FreePBX, external controller,
encrypted vault or off-site test. See [initial-test.md](initial-test.md).

## Reconstruction Scope

The current v2 profile reconstructs selected no-voicemail Core users, fixed PJSIP
devices and dependent Ring Groups, with original immutable credential references.
It requires exact compatible modules, global SIP configuration and securely staged
original secrets. It does not cover trunks, inbound/outbound routes, broader
numbering/destination continuity, voicemail, licences, media/history, all modules
or all PBX configuration. See [the v2 profile](reconstruction-v2.md).

Native updates/removals need allowlisted supported module writes, partial-write
recovery and read-back. Destructive reconciliation additionally needs independent
ownership evidence; a local journal or matching object is not enough. Trunk and
route modules, credential continuity, provider connectivity/SBC work, endpoint
registration, independent inbound/outbound calls and bidirectional audio remain
engineering gates in the [roadmap](capabilities-and-roadmap.md).
The bounded first source-to-target procedure is [initial-test.md](initial-test.md).
The peer federation prototype and its evidence boundary are in
[federation-testing.md](federation-testing.md).

## Validation

Run:

```sh
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
```

The check command includes PHP syntax, synthetic PHP suites (including
`tests/revisions.php`), provider/corrective regressions, schema and hash checks,
static site generation, module/site packaging and browser tests when Playwright
Chromium can launch. Local browser unavailability is reported as a skip; CI
installs Chromium and treats browser failures as failures. Record actual command
results for the checkout being handed over; older browser screenshots, source
archives and exact counts are not assumed to be present or current.

The Development Pond demonstration uses synthetic identities and data, loopback HTTP,
a simulated target and a local routing model. It does not prove FreePBX runtime,
provider compatibility, SIP registration, live calls/audio or takeover. The static
site and module packages can be regenerated under ignored `build/` and `artifacts/`
with the documented tools; generated evidence is not source evidence.

## Next Gates

1. Install and exercise the module on disposable FreePBX 17 hosts, including
   permissions, settings, CLI discovery and the supported create/reload/read-back
   path.
2. Prove native updates and independent object ownership before enabling local
   Revert/Regenerate execution.
3. Securely stage and resolve original credential versions and test continuity.
4. Extend supported reconstruction to required trunks, inbound/outbound routes,
   numbering and destination modules.
5. Validate provider-owned provisioning, connectivity, SBC preparation, authority
   fencing, activation and cleanup.
6. Verify endpoint registration and independent inbound/outbound calls with
   bidirectional audio; measure full recovery milestones and failure cases.

No recovery-time objective, universal warm-spare replacement, zero downtime or
active-call migration is claimed. See [operational path](operational-path.md) for
provider dependencies and measurement evidence.
