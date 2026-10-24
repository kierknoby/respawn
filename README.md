# Respawn for FreePBX 17

**Experimental; not suitable for production use.**

Respawn is developing an alternative to maintaining a permanent standby PBX:
independently protect supported configuration and ultimately reconstruct a
compatible replacement on demand. Its protection path is **The Puddle -> The
Stream -> The Pond**: the Puddle captures local configuration, the Stream
publishes exact revisions, and the Pond retains them independently. Configuration
capture and independent retention are demonstrated; executable recovery remains
in development. Respawn is scoped configuration reconstruction, not a full-system
restore or proof of telephony recovery.

The working prototype's 0.1.0 milestone included a successful, operator-confirmed
live configuration-protection test. Version 0.1.1 is a maintenance release for
website packaging: the deployable site ZIP now records the current packaging time
for cPanel/LiteSpeed extraction. Application behaviour and website content were
not changed by that release.

## The 0.1.0 Milestone

On 24 October 2026, the operator confirmed complete capture of two Core users,
two PJSIP devices and one Ring Group on FreePBX 17 / Asterisk 22 / Debian 12, with
no configuration-blocking gaps. The captured configuration was published over
authenticated HTTPS to an independently hosted Debian 13 Pond. Immediate
read-back and subsequent independent verification matched revision identifiers,
content hashes and retention receipts. Empty configuration collections had also
passed earlier live testing.

The final revision reported `configurationComplete: true`,
`retentionState: independently_readback_verified`,
`credentialStatus: not_configured` and `recoveryReady: false`. This was
configuration-only publication: the captured snapshot was not an executable
reconstruction blueprint, no credential manifest was configured, and no SIP
credentials were recovered. The test does not establish executable Revert or
Regenerate, replacement reconstruction, independent credential recovery,
registration, calls, audio, host-scheduled protection, a recovery duration or
production readiness. It does not establish full PBX coverage.

No private identities, network addresses, secrets or revision payloads are
published. The detailed record is in [validation history](docs/validation.md).

## Working Components

- **The Puddle** captures supported local configuration and tracks revisions.
  Exporters are read-only. The current profile covers selected Core users,
  fixed PJSIP devices and simple Ring Groups.
- **The Stream** detects supported changes and securely publishes exact
  revisions to an authorised Pond. The 0.1.0 live test exercised capture-only
  configuration publication and read-back.
- **The Pond** independently retains revisions for retrieval. The standalone
  Pond is experimental and also implements a separate encrypted credential
  escrow and recovery-bundle workflow.

Capture-only snapshots and executable-blueprint protection are distinct paths.
The live 0.1.0 test did not publish an executable blueprint or exercise credential
escrow. Synthetic independent-process tests cover the separate Pond's exact
credential retrieval and simulated source-loss reconstruction; they do not
establish live FreePBX credential recovery. The executable-blueprint protection
coordinator is disabled by default, uses a five-minute default interval, and
requires an administrator-managed schedule. It polls configuration and can miss
changes made after a check and before source loss.

**The River** and **The Lake** are future developments. River's current contracts
and local ledger tests do not implement production peer authentication,
encryption, transport or cross-Pond recovery authority. The Lake schema defines
inventory vocabulary; no Lake service is implemented. Neither is required for
ordinary Pond retention or the documented reconstruction path.

## Three Operations

The operations have separate responsibilities:

| Operation | Responsibility | Current implementation boundary |
| --- | --- | --- |
| **Revert** | Plan a return to an earlier retained revision on the current PBX. It uses local Puddle history, not the Stream or Pond. | Synthetic execution is tested. Native execution is blocked where updates, removals, verified ownership or an apply path are required. |
| **Regenerate** | Reconcile supported configuration on an existing PBX towards a selected executable blueprint. | Planning and synthetic execution/read-back are tested. The native adapter is create-only; updates, removals and verified ownership are unavailable. |
| **Respawn** | Request a provider-controlled replacement, reconstruct its blueprint, prepare routing and explicitly authorise activation. | Mock orchestration and a local simulation are tested. Real provider compatibility, native replacement reconstruction, routing and live calls are unverified. |

The separate native reconstruction CLI has a fixed framework-reload callback for
its selected create path; this does not make native Revert or Regenerate
executable. A successful mock or source inspection proves none of the live
replacement or telephony stages. These operations do not restore recordings,
voicemail media, call detail records, logs or other history unless a separate
service provides it.

## Scope And Safety

The executable v2 profile covers selected Core users, fixed one-user/one-device
PJSIP endpoints and selected simple Ring Groups. Exact compatible module versions
and global SIP settings are prerequisites. Trunks, inbound/outbound routes,
broader numbering and destination relationships, voicemail, licences, custom
modules, media and history are outside this profile. Unsupported state remains
visible and can block an operation; full PBX reconstruction is not claimed. See
the [module coverage roadmap](docs/capabilities-and-roadmap.md) and
[reconstruction profile](docs/reconstruction-v2.md).

Blueprints contain validated, typed module operations, not arbitrary shell
commands or dialplan. Respawn uses supported FreePBX interfaces and does not edit
generated Asterisk files. Original endpoint credentials use immutable references
and must be securely available at source and target; missing or mismatched values
block reconstruction rather than silently creating replacement credentials.

The module owns local export, validation, planning, reconstruction controls and
diagnostics. Providers own enrolment and authority, retention, replacement
provisioning, secret delivery, connectivity, SBC routing, activation fencing and
resource cleanup. Submission, acceptance, configuration, saved-state
verification, runtime readiness, registration, call/audio evidence, routing
preparation and activation are separate states. See the
[security guidance](docs/security.md) and [provider contracts](docs/provider-contracts.md).

## Get Started

- [Project website](https://respawn.in1.click/)
- [FreePBX 17 installation and capture guide](docs/puddle-capture.md)
- [Initial isolated source-to-target test](docs/initial-test.md)
- [Development and packaging notes](docs/freepbx-development.md)
- [Roadmap and capability evidence](docs/capabilities-and-roadmap.md)
- [Detailed architecture](docs/architecture.md)

The module targets FreePBX 17 and PHP 8.2 or newer. Use an authorised isolated
development host; do not install this experimental project on a production PBX.
The installation and test guides distinguish capture, executable reconstruction
and provider-controlled recovery.

## Development

Install the development dependencies and run the local checks and synthetic demo:

```sh
python3 -m pip install -r requirements-dev.txt
python3 -m playwright install chromium
sh tools/check.sh
XDEBUG_MODE=off php tools/demo.php
```

The check suite includes lint, synthetic regressions, schema validation, static
site generation and packaging. Browser checks require launchable Playwright
Chromium; unavailable browser checks are reported as skipped. To build the static
site and local release archives:

```sh
python3 tools/build_site.py
python3 tools/package.py
```

Generated files are kept under ignored `build/` and `artifacts/` directories.
These local checks do not establish live FreePBX reconstruction, credential
recovery, provider compatibility or telephony behaviour.

## Further Reading

- [Puddle capture and its evidence boundary](docs/puddle-capture.md)
- [Provider-neutral integrations](docs/provider-integrations.md)
- [CLI and synthetic demo](docs/cli-and-demo.md)
- [Recovery authority and fencing](docs/recovery-authority.md)
- [Operational path and measurement requirements](docs/operational-path.md)
- [Dated validation history](docs/validation.md)
- [Historical prototype notes](docs/history.md)

Respawn is licensed under GPLv3+; see [LICENSE](LICENSE). External providers and
controllers have their own implementation and licensing responsibilities.