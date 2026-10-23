# Respawn Development History

This document preserves the earlier prototype direction and its reported results.
It is historical context, not a description of the current implementation or
independent confirmation of the old lab evidence. Current capability and test
status are in the [README](../README.md), [handover](handover.md), and
[capability roadmap](capabilities-and-roadmap.md).

## Earlier Control-Plane Prototype

The initial project explored exporting FreePBX Firewall trusted-source intent to
an external controller and distributing a resulting SIP-edge policy. The intended
path was PBX -> Respawn agent -> controller -> signed edge policy -> SIP edge
enforcement. At that stage, cryptographic signing, production enrolment and
replacement provisioning were explicitly future work; versioning and drift checks
were not signatures.

The earlier prototype was described as supporting per-PBX identity, IPv4 `/32`
trusted-source export, canonical hashing, change/no-change detection, a restricted
publish path, controller-side validation, merged edge policy, atomic policy apply,
drift checks and effective-policy reporting. Its report described a two-FreePBX
lab and Kamailio enforcement. Those were historical claims from the earlier
prototype and have not been independently revalidated against this repository's
current source.

The old command examples included:

```sh
respawn export firewall
respawn publish firewall
respawn publish firewall --force
```

Examples such as `respawn enrol`, `status`, `blueprint` and `verify` were
directional proposals, not stable interfaces. The current command surface is
documented in [CLI and demo](cli-and-demo.md).

## Direction Changes

The project moved from a narrow trusted-source/edge-policy prototype toward a
provider-neutral FreePBX configuration reconstruction module. The current model
is read-only export -> validated executable blueprint -> authorised provider
operation -> fresh target -> dependency-ordered module reconstruction -> saved
state verification -> connectivity/routing preparation -> explicit activation.
The module does not control private provider services or directly authorise SIP
edge policy.

The current v2 implementation adds typed Core-user, fixed PJSIP-device and
selected Ring-Group operations; immutable secret references; exact module-version
preflight; dependency planning; durable per-operation checkpoints; explicit apply
and saved-state read-back. These have synthetic and native-shaped contract tests.
They do not establish native FreePBX installation, complete PBX recovery, live
provider compatibility, call continuity or an achieved recovery time.

## Retained Engineering Decisions

- Keep executable module-by-module reconstruction central; a blueprint is not
  merely an inventory, backup pointer or arbitrary script.
- Keep provider, controller, hosting, billing, connectivity and SBC services
  outside the GPLv3+ module core.
- Use supported FreePBX module interfaces for writes and read-only exporters;
  never edit generated Asterisk files.
- Reject unsupported state, conflicts, unknown outcomes and missing original
  credentials instead of silently dropping or replacing them.
- Separate submission, provider acceptance, configuration, apply, readiness,
  routing preparation, activation and independent call/audio evidence.
- Preserve foreign/shared resources; do not infer ownership or enable destructive
  reconciliation without independent ownership proof.
- Treat hashes as content-integrity checks, not signatures. Keep synthetic tests
  and source inspection distinct from live integration evidence.

## Historical Evidence Boundary

Prior development notes reported local contract suites, package checks, visual
browser reviews and synthetic multi-PBX demonstrations. The exact historical
environment and generated screenshots/archives were not included in the source
distribution. Their absence does not invalidate those prior reports, but they
must not be represented as tests rerun in a later environment. Current local
results belong in the handover and generated evidence must be recreated from the
current source when needed.