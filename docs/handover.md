# Respawn 0.0.1 Local Handover

## Outcome

An experimental reconstruction-centred development foundation, not a completed
live recovery service. The module archive, provider interfaces/HTTPS profile,
mock orchestration, synthetic demo, static project website, runbooks and local
checks are present. Real FreePBX installability/reconstruction and the private
operational path remain unverified. No achieved takeover timing is claimed.

This focused pass materially extends reconstruction to a v2 selected Core-user ->
fixed PJSIP-device -> dependent Ring-Groups chain, with original versioned secret
references, exact module-version preflight, durable per-operation checkpoints,
process-death recovery, target locking and one final apply/read-back boundary.
See [findings and fixes](review-pass.md) and the [precise field/dependency table,
source revisions and opt-in live procedure](reconstruction-v2.md).

## Final Local Results

`sh tools/check.sh` and `XDEBUG_MODE=off php tools/demo.php` passed on the final
functional source. Evidence includes 86 existing/regression assertions, 11
focused corrective cases, the
preflight red/green regression, native-shaped Core/PJSIP/Ringgroups continuity and
partial-write/output-containment checks, 40 executor/secret invariants (including
real child-process interruption and competing-process locking), independent v2
JSON Schema and canonical-byte/hash validation, phase-measurement input rules,
syntax/package checks and 22 extracted-site desktop/mobile page checks, including
the packaged Apache ErrorDocument directive and a host-style HTTP 404 response.
The Local Pond real-process integration also covers authenticated v2 loopback HTTP,
the browser lifecycle, duplicate/idempotent launches, two-PBX isolation, connection
changes, lost-response retry/reconciliation, all executor/cleanup failure scenarios,
worker restart recovery, scoped reset and package exclusion. Active-operation
screenshots are retained at desktop and mobile sizes.
These establish local contract
behaviour, not native FreePBX or live network integration. No secret values are in
blueprints, reports or screenshots. All ten pages were visually reviewed again.

Final branch/HEAD match the starting state exactly; index remains empty. The
starting source snapshot, per-pass file comparison and unchanged LICENSE check
are retained. Existing unstaged work has not been staged, reset or removed. No
commit, push, branch, issue, PR, tag, release, publication, deployment, DNS change
or live PBX/provider execution occurred.

## Requirements To Evidence

| Requirement | Local evidence | Limit / next gate |
| --- | --- | --- |
| Preserve baseline and local-only work | Existing dirty 0-0-1_Dev/147925b source archived before this pass; empty index and unchanged HEAD | Starting snapshot and per-pass content comparison retained; nothing staged/committed/pushed/published/deployed |
| README/licence/instructions/reference investigation | README progress, unchanged LICENSE, AGENTS.md, docs/references.md | Earlier prototype/private source unavailable |
| Reconstruction as core | BuildSpec/BuildExecutor/BuildTarget, native/simulated adapters, independent source/target contracts | Selected Core users and fixed PJSIP devices are recreated before groups; modules/global SIP are prerequisites |
| Typed instructions, order and verification | IDs/types/schema/parameters/dependencies/capabilities/preconditions/secret refs/postconditions; complete preflight, fsynced checkpoints, read-back and final apply | Trunks/routes/voicemail, broader profiles and all-PBX coverage remain gaps |
| FreePBX Connectivity module | module.xml, page, BMO settings/lifecycle and Symfony CLI | Syntax/package checks only; isolated real install and RBAC/CSRF tests required |
| CLI/firewall exports/publication | Shared Application, fwconsole source, optional wrapper, explicit fixture CLI | Only explicit IPv4 /32 trusted hosts; native API runtime unverified |
| Provider neutrality | Provider/Configuration/HttpsProvider; public schema/profile and responsibility docs | Newly specified neutral endpoint contract, not private API compatibility |
| One-click operation | Policy preview, acknowledged/current blueprint gate, stable key/submission journal; Local Pond accepts real loopback HTTP and executes the local worker path | External production provider request/response flow needs an authorised compatible service |
| Progress/reconciliation/lost responses | Connection-bound original provider resolution; session-independent lookup by opaque submission ID; explicit original-request retry; stale visibility and revision-regression quarantine | Live provider and FreePBX session integration remain unverified |
| Local controller demo | Separate loopback-only Local Pond reference; real v2 HTTP; generated protected credentials; persistent worker/restart; actual Application/BuildExecutor; browser activation/routing-model probe/owned teardown | Target/runtime/connectivity/routing remain simulated; no private-service or production-compatibility claim |
| Connectivity/DOMAINTAINS/SBC | Public contract and actual-dependency checklist | Exact private APIs/auth/resource semantics unavailable |
| Activation/cancellation/teardown | Separate controls, manual ready recovery, active disposition, resource ownership, retry/dependency tests | Real authority epochs, signatures, traffic disposition and rollback unverified |
| Real takeover in minutes | Invocation milestones plus reconstruction/apply-verification/connectivity-routing phase intervals; bottleneck analysis | All real timings unmeasured; real registration/calls/audio require independent probes |
| Mock/two-PBX exercise | Existing mock harness plus Local Pond's Alpha/Bravo identities, actual source export/planner/executor, original synthetic credential references, interruption/retry/conflicts/apply/cleanup | Simulated targets only; no FreePBX, Asterisk, SIP or real infrastructure used |
| Public website | Ten pages/26 FAQs, updated reconstruction/readiness/secret content, public v2 schema, house palette | Static cPanel package not deployed; no private management data |
| Visual/packaging checks | Twenty-two page/viewport checks/screenshots; ZIP root, robots/sitemap, custom 404 mapping, module licence/archive checks | Live cPanel/Apache mapping, hosting headers, DNS and independent accessibility audit unverified |
| Docs/CI/contribution | Full guides, capability table, opt-in workflow, durable instructions | No remote workflow run; live gates explicitly documented |

## Architecture And Safeguards

Export -> executable retained blueprint -> provider-authorised operation -> fresh
PBX -> ordered module reconstruction -> connectivity/SBC preparation -> independent
verification -> policy-controlled takeover. Module/blueprint contracts stay GPLv3+
and provider-neutral; hosting, WHMCS, DOMAINTAINS, service/billing reconciliation and
SBC implementations remain private provider responsibilities. The Pond is one
controller implementation; alternatives remain possible.

Mock progress is explicit and never time-based. No source or browser data becomes
shell commands. Rehearsal/standby cannot activate. Unknown outcomes retain original
keys; source identity and acknowledgements are distinct from infrastructure success.
Teardown preserves shared/foreign resources and checks active routing disposition.
No automatic failback to an unhealthy source is implemented or implied.

## Commands

```sh
python3 -m pip install -r requirements-dev.txt
python3 -m playwright install chromium
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
python3 tools/inventory.py
```

See validation.md for browser OS libraries, cli-and-demo.md for the complete
operator command surface, reconstruction-v2.md for exact separately authorised lab
steps, https-profile.md for transport and operational-path.md for private-service
dependencies/timings. Live tests require separate explicit authorisation.

## Local Artefacts

- `artifacts/respawn-0.0.1.tgz`: unsigned experimental module source archive.
- `artifacts/respawn-site-0.0.1.zip`: upload-root static cPanel package.
- `artifacts/SHA256SUMS`: generated archive checksums.
- `build/site/index.html`: direct local browser preview, no server required.
- `artifacts/screenshots/`: each page at desktop/mobile, plus review sheets.
- `artifacts/screenshots/local-pond-active-desktop.png` and
  `artifacts/screenshots/local-pond-active-mobile.png`: actual running local demo.
- `artifacts/reference/`: six public house-style screenshots and inspection JSON.
- `artifacts/demo/`: both synthetic blueprints and two-PBX report.
- `artifacts/changed-files.txt` and `artifacts/worktree-review.json`: exact source
  inventory and final unstaged state, generated locally by tools/inventory.py.
- `artifacts/reconstruction-pass-starting-source.tgz`: untouched starting dirty
  source; `artifacts/reconstruction-pass-files.txt`: exact changes during this pass.

All generated artefacts are deliberately ignored, not staged or published.
Changed source areas are README, AGENTS, .github instructions/workflow, contracts,
module, tests, tools, website, docs, requirements-dev.txt and .gitignore. LICENSE
is unchanged. The inventory lists every individual file, including this handover.

## Decisions And Limitations

The selected chain now recreates user/device prerequisites through inspected Core
interfaces, original credentials via protected versioned references, and actual
Ringgroups destinations. It still does not rebuild all FreePBX configuration.
Exact installed modules/global SIP settings, real native write/apply proof,
secure original-secret transport, licences, trunks/numbering, endpoint reconnection,
existing-call continuity and historical/media recovery remain blockers to takeover.

Production enrolment, cryptographic signatures, policy fencing, full coverage
discovery, async FreePBX jobs/central logging, broader multi-module reconstruction,
per-area acknowledgement retention and actual private-service orchestration are
not complete. The HTTPS profile is contract-tested with fake transport, not proven
over a real network. A configured URL, accepted blueprint or ready mock is not
complete recoverability. See capabilities-and-roadmap.md for prioritised work.

PHP 8.2 was unavailable; PHP syntax, tests and packaging ran on PHP 8.4.15 only.
The local cPanel test verifies a host-style mapping, not live Apache configuration.

GitHub Copilot assisted code, documentation and checks. No model identifier is
invented. Human review and separately authorised integration acceptance are required.