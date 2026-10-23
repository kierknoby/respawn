# Local Validation Results

Date: 22 October 2026. Ubuntu 24.04.5 LTS dev container; PHP CLI 8.4.15,
Python 3.14, Node.js present, Playwright 1.64.1 Chromium build 1248,
Pillow 13.0.0, jsonschema 4.26.0. No FreePBX, Asterisk, production provider
credentials or authorised live lab. Sources inspected at exact revisions are
listed in reconstruction-v2.md; those are not tested native runtime versions.

## Executed Gate

```sh
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
XDEBUG_MODE=off python3 tests/local-pond.py
```

Current focused checks pass: existing regression suite (86 assertions), 11
focused corrective provider/diagnostic/canonicalization cases, the v1 preflight
red/green regression, native-shaped Core/PJSIP/Ringgroups continuity,
partial-write/output-containment contracts, 40 v2 executor/secret invariants,
independent Draft 2020-12 schema validation and timing-input phase rules.
The complete gate was rerun successfully: all module, test, tool and Local Pond PHP
syntax; JavaScript and lifecycle-script checks; module XML and schema JSON; ten-page
static build; module/cPanel archive root/metadata/licence/wrapper/exclusion checks;
22 extracted-package Chromium page/viewport checks; Apache 404 directive packaging;
real Local Pond loopback-process integration; and `git diff --check` all passed.
VS Code reported no diagnostics.
PHP 8.2 is not installed in this container; compatibility was exercised only on
PHP 8.4.15. The demonstration
exports two distinct simulated source user/device/group chains, reconstructs into
separate clean targets, preserves original runtime-only credentials, reconciles
interruption, applies once, compares source/target and preserves shared records
during cleanup. Generated evidence explicitly denies real takeover.

The PHP suite covers canonicalisation, unsupported IPv4/IPv6/CIDR input,
dependency order/cycles/missing refs, source/target adapter doubles, failed builds,
retry/read-back/conflicts, publication failure/no-change/force, identity spoofing,
duplicate launch/lost responses/changed policy, refresh, outages/staleness,
concurrent operation rejection, stale activation, active teardown safeguards,
partial cleanup, delayed old teardown, shared/foreign resource protection,
four-process state locking (60 updates, none lost), endpoint/credential-path
validation, HTTPS response/readiness contracts, oversized builds and corrupt state.
V2 adds exact module/capability/secret/complete-plan preflight; partial device
handling; per-operation durable pending/running/succeeded/failed/unknown; actual
child-process exit between mutation and checkpoint success; a separate process
holding the target lock; apply failure/retry and apply invalidation after object
recreation; protected manifest permissions/identity/path; secret-revision hash
changes; same-key cross-PBX local journals; source/target credential equality.

## Website Evidence

1440x1000 and 390x844 Chromium screenshots for every page are in
artifacts/screenshots/. The extracted upload ZIP, not merely source files, was
served temporarily on loopback. Tests check one H1, canonical/description,
horizontal overflow, successfully loaded image dimensions, local links/assets,
keyboard skip link, mobile menu/Escape and keyboard FAQ controls. Public schema,
robots and sitemap are packaged. Native details controls work without JavaScript.
Navigation remains visible if JavaScript is unavailable.

All ten desktop/mobile pages were reviewed via rendered contact sheets, with
full-size home/FAQ/provider screenshots additionally inspected. The mobile graph
was corrected to a two-by-two asset rather than clipping a desktop graph.
Reference home/overview/FAQ were separately rendered at both widths, not deployed.
Palette/contrast were reviewed manually; no independent automated accessibility
audit, screen-reader session or every possible viewport/browser is claimed.
The package includes `.htaccess` `ErrorDocument 404 /404.html`; browser tests use a
local host-style mapping and confirm status 404 plus the custom body. Actual
cPanel/Apache override permission, server headers and live mapping remain
unverified. The focused pass refreshed every screenshot and inspected the home
and 404 pages at desktop/mobile sizes, as well as prior full-page contact sheets.
The review-sheet helper was corrected to exclude its own generated images, which
otherwise displaced Status from the review grid. The helper itself was rerun and
syntax-checked; it does not change the deployment package.

Chromium initially failed due to missing shared libraries. Required Ubuntu packages
were downloaded and extracted **without system installation** into ignored
build/browser-deps/root. tests/site.py automatically includes that local library
path if present. For the optional reference/contact-sheet script use:

```sh
LD_LIBRARY_PATH="$PWD/build/browser-deps/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" python3 tools/visual_review.py --reference
```

On another host, supply standard Playwright OS dependencies through your approved
administration process. The website itself needs none of these development tools.

## Local Pond Evidence

`tests/local-pond.py` starts isolated PHP Local Pond/dashboard/worker processes on
random loopback ports, sends actual authenticated v2 HTTP requests, drives Chromium
through export, publication, reconstruction, activation, probe and teardown, and
then verifies duplicate submission, two-PBX isolation, connection change,
lost-response retry/reconcile, conflict, unresolved secret, apply failure, expired
simulated readiness, cancellation, partial cleanup, shared-resource preservation,
worker/service restart recovery, targeted reset and archive exclusions. Screenshots
of the running active operation are `artifacts/screenshots/local-pond-active-
desktop.png` and `artifacts/screenshots/local-pond-active-mobile.png`.

The local service performs real application/provider/executor/state work. The
replacement target, apply adapter, connectivity, routing, readiness and fallback
probe are explicitly simulated. Activation changes only the Local Pond routing
model; the probe reads actual simulated target Ring Groups and labels the assumed
primary no-answer. It is not SIP, a live call/audio test, production readiness or
provider compatibility. Production HTTPS rejects Local Pond's development markers.
Both packages were rebuilt and inspected; runtime credentials/state and the entire
Local Pond implementation are excluded.

## Explicitly Unverified

Real module installation/upgrade/uninstall, menu, BMO loading, session/RBAC/CSRF,
settings and fwconsole discovery; actual Firewall/Ringgroups defaults and API
compatibility; actual source-to-target reconstruction and Apply Config; HTTPS/TLS,
certificate/redirect/DNS failure behaviour against a real server; production
enrolment, signatures, key rotation/revocation; hosting, WHMCS, DOMAINTAINS,
SBC fencing/routing; successful registration/inbound/outbound calls and received
bidirectional audio; takeover/rollback timings; cPanel deployment/DNS/Apache.
Unavailable checks are **unverified**, not passed.

V2 is validated independently with jsonschema 4.26.0 as well as the PHP planner.
Cross-object dependency/identity/secret semantics are additionally enforced in PHP;
schema validation alone cannot prove those or real FreePBX compatibility. Signed-artefact
verification is not implemented, so no signature-failure test is claimed. Source
inspection/mocks are not evidence of live FreePBX reconstruction or takeover in minutes.