# Local Validation Results

## Diagnostic Rotation CI Correction (23 October 2026)

The failed `Respawn Scaffold Checks` run for commit `ebb8bb6` used Ubuntu 24.04
and PHP 8.3.6. Its combined diagnostic-rotation assertion did not identify the
failed condition. Splitting archive count, permissions and credential category
reproduced the failure in a disposable non-root PHP 8.3.6 CLI container: four
files were present, but cached `fileperms()` reported 0644. Descriptor-level
`fstat()` and `fileperms()` after `clearstatcache(true, $path)` both reported
the actual mode 0600. Credential categorisation was correct on all five rotations.
PHP 8.3.33 did not exhibit this cached-mode discrepancy locally. The ownership
helper's `fileowner()`/`filegroup()` checks after chmod can leave the pathname
stat metadata cached on the older runtime; rotation also replaces the file at
that same pathname. This was a stale test observation, not on-disk permission
weakening or loss of archives.

Only the protection test changed: it clears PHP's stat cache before checking
each log's permissions, independently verifies four files (current plus three
archives), and parses the event JSON to assert the exact safe credential-failure
category. Numeric mode/file-count and category-match diagnostics disclose no
credential values. The logger's restrictive modes, rotation and ownership logic
are unchanged; no delay, permission relaxation or skipped assertion was added.

After the correction, the protection test passed nine consecutive runs on
PHP 8.3.6 with umasks 0002/0022/0077 (three each), and five on local PHP 8.3.33.
Before correction, 18 local runs across those umasks with inherited and removed
default ACLs passed, establishing why local execution alone missed the issue.
`sh tools/check.sh`, `XDEBUG_MODE=off php tools/demo.php` and `git diff --check`
passed locally: 97 checks, 41 reconstruction invariants and all non-browser gates.
The mixed-root ownership test skipped on this non-root runner; browser suites
skipped because Chromium cannot load `libatk-1.0.so.0`. The PHP 8.3.6 container
verified the protection regression, not the complete hosted Ubuntu workflow.
No commit, push or hosted run of the uncommitted correction was performed.

## Persistent Storage Ownership Correction (23 October 2026)

Source correction adds service-directory owner/group inheritance for new state
directories, state/build locks, atomic temporary files, journal replacements,
chunk directories/files and diagnostic logs. Root-created replacements are
chowned to the existing Respawn state-directory owner before rename; modes remain
0700 for directories and 0600 for files. Read-only disabled CLI status now uses
the shared-lock path without connection-registration writes. Repeated provider
registration checks current state before opening a write transaction. Provider
response operation updates remain genuine mutations.

`fwconsole respawn storage-repair` is root-only and has no path option. It is
bound to the canonical configured `ASTVARLIBDIR/respawn`, derives service
ownership from its parent, locks state/diagnostic writers, preflights all entries,
rejects symlinks/hardlinks/unexpected filenames and preserves contents while
restoring restrictive modes. It never enters secret-manifest, Pond-key, token or
recovery-bundle locations. The GUI checks both journals through `inspect()` before
rendering; inaccessible/corrupt storage returns a sanitized diagnostic rather than
an unhandled filesystem warning. CLI error logging remains payload-free.

`tests/storage-ownership.php` covers restrictive permissions, inaccessible-state
failure preservation and symlink-path rejection. When run with root and a local
service account, it additionally tests root initialization/publication, service
account read/update, subsequent root update, immutable chunks, scoped repair and
data preservation. After the correction, `php tests/storage-ownership.php`,
`php tests/freepbx-gui.php`, `sh tools/check.sh` and `XDEBUG_MODE=off php
tools/demo.php` ran successfully. The complete gate passed 97 checks and 41
reconstruction invariants. The root-to-service branch explicitly skipped because
the runner is non-root; the live FreePBX procedure below remains required.
Browser checks were skipped for missing `libatk-1.0.so.0`.

Source review further confirms the earlier root-write path: `Application::run()`
called `registerConnection()` for every command, which used `JsonStateStore::update()`.
Root-run `fwconsole respawn status` could therefore atomically replace `state.json`
with a root-owned temporary file. The earlier installed `protectionStatus()` also
used `update()` as a read; the current source uses `inspect()`. The live stack only
shows the later GUI read failing and cannot alone prove which command performed
the earlier ownership change. Root-run state-changing commands were also covered
by the atomic ownership correction.

The dedicated ownership test now simulates root-to-service writes and service
read/update if executed with root privileges and a local `nobody` account. It also
checks root-equivalent publication/status, chunk ownership, symlink rejection,
permission failure preservation, repair refusal for unexpected files and state
preservation. Its root-to-service portion requires root privileges and was
explicitly skipped here. A real server must still verify that `ASTVARLIBDIR` is
owned by the intended FreePBX service account, execute the repair procedure only
if needed, run CLI checks as root and `asterisk`, and load the GUI again.

## FreePBX GUI Correction Pass (23 October 2026)

Added the BMO `doConfigPageInit($page)` lifecycle hook for the Respawn page. It
initialises the existing session CSRF/launch keys, checks the FreePBX Respawn
section permission, enforces request-size/CSRF limits, dispatches each POST once,
stores one-time feedback and redirects with 303 to a fixed local view (PRG). The
render file is GET-only. The browser refresh path no longer repeats a submitted
action. The previously operator-reported FreePBX 17 warning and successful 43-file
installation are recorded separately above; this source update has not yet been
reinstalled on that server.

The page now uses server-rendered Overview, Protection, Revisions, Recovery and
Settings views, with expandable sanitised diagnostics. Cached GET status uses
`Application::cachedStatus()`/`JsonStateStore::inspect()`; provider queries remain
explicit refresh actions. `protectionStatus`, recovery-conflict inspection and
publication-difference checks are read-only. Revision rows provide Revert and
Regenerate previews; unsupported native mutations remain disabled by the existing
target capability checks. Disabled-provider recovery rejection reports that no
operation was submitted rather than an ambiguous outcome-unknown message.

`php tests/freepbx-gui.php` covers BMO signature/lifecycle, one-time POST
dispatch, view-preserving PRG target, section authorization, CSRF, request size,
disabled-provider rejection, action availability and byte-identical state after
repeated protection/conflict/publication/cached-status reads. It renders all five
views with minimal FreePBX doubles, checking headings/CSRF forms without browser
runtime. Full `sh tools/check.sh` and the synthetic demo pass; 97 application /
provider checks and 41 reconstruction invariants pass. Browser and live FreePBX
rendering, Bootstrap behavior on the installed host, keyboard/mobile screenshots
remain unverified because Chromium lacks `libatk-1.0.so.0`. The updated module
must be installed and exercised on the reported FreePBX development host before
claiming the original warning is fixed there.

## First FreePBX Installation (User-Reported, 23 October 2026)

The operator reports installation on a real FreePBX 17 development server. The
reported checks are: all 43 module files matched an independently reviewed source
fingerprint; `fwconsole ma install respawn` completed; Respawn is enabled and
recognised; `fwconsole chown` and `fwconsole reload` completed; `fwconsole respawn
status` and `fwconsole respawn protection-status` returned valid state; and the
GUI rendered configuration, protection, revision and recovery controls. The GUI
reported `Page Respawn doesn't implement doConfigPageInit, this is bad.` This is
operator-provided evidence, not independently reproduced in this checkout. It
establishes installation/rendering only, not cron execution, automatic protection,
Pond connectivity, native reconstruction, SIP registration or calls.

The current source adds BMO page initialization, POST/Redirect/GET, cached GET
rendering, the five server-rendered Overview/Protection/Revisions/Recovery/Settings
views and expandable sanitised diagnostics. The update itself has **not** been
reinstalled or rechecked on that FreePBX host; that is the next integration step.

## Independent Audit Correction Pass (23 October 2026)

F1-F6 are reconciled in [the disposition report](pre-test-review.md). Version
remains 0.0.1. The full local gate and synthetic demo were rerun with the new
capacity, ordering, diagnostics, preflight and packaged-cron regressions enabled.
Earlier evidence below is retained as history, not rewritten as real-host proof.

Storage checks pass for 40 users/40 PJSIP devices/one group across 50 revisions,
50 revisions at the unchanged 100-operation boundary, and the configured maximum
500 revisions with the synthetic small profile. All revisions remain retrievable.
Pruning/pins/acknowledgements, compare, Revert/Regenerate plans, source sequence,
simultaneous writers, process kill, OS-enforced write exhaustion, legacy migration,
failed migration, corrupt-chunk recovery and 500 pending journal entries pass.
The aggregate 1 MiB failure is removed without increasing the per-file bound.

Pond tests pass for A/B/C promotion, duplicate A, delayed history, unordered legacy
retention, immutable lineage conflicts, restart, pre-extension head preservation,
cached/current head verification and explicitly authorised source resumption.
Unchanged restored state creates a new child rather than replaying an old receipt.
The separate HTTP service still passes SQLite/AES-GCM source-loss recovery with
exact original/rotated passwords and a simulated target; new destination
overwrite/symlink rejection tests pass. A broken-link fixture cleanup warning
found in the first full run was corrected and the affected test rerun cleanly.

Protection tests pass for returned publication-failure diagnostics, preserved
protected head, FIFO/backoff/restart, recovery timestamps, no heartbeat refresh
on status reads, missed scheduler state and three-archive private log rotation.
Existing application/provider, 41 reconstruction invariants, revisions,
federation, schema/hash, local Pond, module/Pond/site package, extracted static
links/assets and synthetic demo checks remain enabled and pass.

Read-only Pond/recovery preflights pass locally: PHP 8.3.33, OpenSSL 3.0.20,
cURL 8.5.0, SQLite 3.45.1 and durable-file functions. Source preflight correctly
fails: no `fwconsole`, FreePBX configuration, PDO MySQL, `asterisk` execution role
or installed/running cron. This is an unavailable host-test environment, not a
claimed source-host pass. Preflight writes nothing and makes no network calls.

Browser tests were attempted; Chromium still cannot load `libatk-1.0.so.0`.
Site and Development Pond browser checks are skipped, not passed. No download,
external search/contact, cron installation, deployment, staging, commit or push
was performed. Native FreePBX installation/reboot scheduling, separate-host TLS,
physical power-loss durability, runtime/registration/calls remain unverified.

Assessment: **READY WITH EXPLICIT LIMITATIONS** for the three-server isolated
procedure in `initial-test.md`, subject to its dependency, permission, transport,
key/token custody and real-execution gates. Not production readiness.

## Core Protection Lifecycle Pass (23 October 2026)

The standalone `pond/` service is now implemented separately from the Development
Pond. Existing Puddle revision storage, Stream hash detection/FIFO retry and native
reconstruction were retained, not replaced. New work adds SQLite persistence,
local scoped enrolment/revocation, request replay/freshness checks, AES-256-GCM
credential escrow with an independent key, exact-password read-back, latest
revision retrieval and a native-compatible protected recovery bundle. Explicit
active secret references support rotation while retaining old queued versions.
The supplied cron entry is administrator-installed; it was not installed here.

`sh tools/check.sh` and `XDEBUG_MODE=off php tools/demo.php` both passed after
this implementation. All existing reconstruction, revision, publication, provider,
protection, federation, schema/hash, package and static-site checks remain enabled.
The additional `tests/pond.php` starts a separate authenticated loopback service
using real SQLite and OpenSSL encryption. It automatically captures synthetic
configuration, detects a change and password rotation, verifies retained copies,
deletes the entire source directory, restarts the Pond, retrieves with a different
recovery token and reconstructs a clean simulated target with the exact original
version-specific passwords. The test uses the same recovery-bundle writer as
the CLI and the existing `FileSecrets`/`BuildExecutor`, not a substitute engine.

Negative tests cover missing credentials before acknowledgement, tenant/PBX
isolation, replay, request expiry, unknown/revoked tokens, immutable passwords,
recovery-only permissions, wrong decryption keys, tampered GCM tags and password
absence from database plaintext and service logs. Packages include a separate
`respawn-pond-0.0.1.tgz`; runtime data/keys are excluded. Editor checks are clear.

This is independent-process storage/encryption evidence, not independent-host,
public TLS or FreePBX evidence. Browser checks were attempted and skipped because
Chromium cannot load `libatk-1.0.so.0`; desktop/mobile rendering and keyboard
behaviour remain unverified. No external endpoint was contacted, no packages
were downloaded, no cron/services were installed and no deployment occurred.
No FreePBX installation, scheduled host execution, native saved-state recovery,
registration, calls/audio or hardware power-loss durability has been established.
Earlier sections below are historical evidence and have not been overwritten.

Assessment: **READY WITH EXPLICIT LIMITATIONS** for an authorised isolated
FreePBX 17/independent Pond test following `docs/initial-test.md`, not production.
Prerequisites are disposable compatible FreePBX hosts, trusted public-IPv4 HTTPS
Pond transport, verified service/storage permissions, host cron installation and
independent recovery-token/key custody. Private-only HTTPS remains blocked by the
existing source transport policy; do not weaken it merely to pass a test.

## Ecosystem Federation Pass (23 October 2026)

The current full gate includes schema validation for stable Pond identity, River
agreements/messages/receipts, recovery delegation and Lake inventory. The local
`tests/federation.php` suite uses two or three separate temporary JSON stores and
tests bilateral scope, immutable revisions and parent history, idempotent
replication, receipt replay, tamper rejection, revocation, restart, tenant
non-enumeration and retrieval after deleting the source Pond store. These are
synthetic ledger tests. The caller-supplied peer identity is not cryptographic
authentication; no River transport, mTLS/signatures, encryption, key rotation,
production Lake or cross-Pond activation fencing is implemented.

The Local Pond retain/retrieve RPC test uses authenticated loopback HTTP and
synthetic bearer credentials. It is Development Pond evidence, not external
retention. No live provider, Pond, River peer, Lake, FreePBX or Asterisk was
contacted or tested.

## Final Readiness Audit (23 October 2026)

Environment observed: PHP CLI 8.3.33, Python 3.14.2 and Node.js 24.21.0. No
`fwconsole`, FreePBX or Asterisk installation is available in this workspace.
No live systems, providers, routing services or external test endpoints were
contacted.

`sh tools/check.sh` and `XDEBUG_MODE=off php tools/demo.php` completed
successfully. The gate passed PHP lint; application/provider (97 checks),
preflight, native-shaped reconstruction, build-chain (41 invariants), corrective,
revision and protection suites; the authenticated Local Pond retention/retrieval
contract; measurement input checks; independent schema/hash validation; site
build; module/site package checks; extracted-package static links/assets; and
`git diff --check`. Protection tests cover unchanged suppression, supported
extension/PJSIP/credential-reference changes, A-to-B-to-A revisions, FIFO outages,
crash/idempotency retry, provider changes, source-store deletion, and missing
retention repair. These use synthetic stores/providers only. HTTPS retention and
credential-vault tests use injected transport and reject simulated evidence. The
Local Pond contract test uses separate loopback controller state and reports
simulated storage. No host cron was installed; no real FreePBX, controller or
vault was available. The demo remains synthetic.

The module tarball contains exactly module source and its licence; the site ZIP
contains no module PHP or Local Pond implementation. This verifies package
construction and exclusions, not FreePBX installation or activation.

Playwright Chromium could not start because `libatk-1.0.so.0` is missing. The
repository's ignored browser-library bundle is absent, and this audit did not
install system packages or contact external services to obtain it. Static site
generation and local package links/assets passed. Desktop/mobile rendering,
keyboard interaction, browser-driven Local Pond and visual screenshot checks
remain unverified for this audit.

## Correction Pass (23 October 2026)

`sh tools/check.sh` completed successfully after the acknowledgement, revision,
documentation and website corrections. PHP lint and synthetic application,
preflight, native-shaped reconstruction, build-chain, corrective/provider and
revision suites passed; the application suite reported 88 checks and the revision
suite passed its acknowledgement-binding, rotated-secret, selected-catalogue,
Revert and Regenerate regressions. Independent schema validation, canonical hash
checks, measurement-input validation, static site generation, module/site package
checks, extracted-package local links/assets and `git diff --check` passed.

`XDEBUG_MODE=off php tools/demo.php` passed for two distinct synthetic PBXs and
separate simulated targets. This is not native FreePBX or live provider evidence.

The browser tests did not run: Playwright Chromium was downloaded, but its process
could not start because this container lacks `libatk-1.0.so.0`; the documented
ignored `build/browser-deps/` bundle is absent. Static link/asset validation still
ran. The Local Pond browser integration was also skipped because Chromium was not
launchable. Desktop/mobile rendering, keyboard interactions and browser-driven
Local Pond behavior therefore remain unverified in this pass. No FreePBX,
Asterisk, provider, SBC or live call/audio test was performed.

## Historical Record (22 October 2026)

The following records the earlier run without treating it as a result of this
corrective pass.

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