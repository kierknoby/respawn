# Legacy v1 Reconstruction Adapter Notes

**Current implementation:** use [the v2 selected-chain guide](reconstruction-v2.md)
for user/PJSIP/group reconstruction, secret continuity, checkpoints, apply and the
current opt-in lab procedure. This document describes the retained v1 compatibility
slice only. Its historical lab commands below are not the current CLI and must
not be used for v2 reconstruction.

## Retained v1 Slice Only

`ringgroups.v1` exports and recreates two or more simple Ring Groups, including a
no-answer dependency. Example: group 600 rings extension 1001, then falls through
to group 601, which rings extension 1002 and terminates at hangup. Group 601 is
created before 600. Devices and extensions are prerequisites, **not reconstructed**.
This demonstrates a clean target for the covered Ring Groups objects, not a fully
clean, call-ready PBX. A completely empty PBX still needs member/device adapters.

The source adapter reads `FreePBX::Ringgroups()->get(number)` and uses the module's
`add(...)` on the target. These FreePBX release/17.0 interfaces were source-inspected,
not executed here. No generated Asterisk configuration is edited. Module `add`
may write database/AstDB through its normal implementation. Export reads only.
Core `getUser` was rejected for this slice because its implementation can write
AstDB during a read. `Core::listUsers()` is used only to check member prerequisites.

Supported fields: number, description (restricted plain ASCII), ringall strategy,
timeout 1..120 seconds, numeric member list, no-answer hangup or covered group.
Supported default settings for all other fields are checked explicitly. Non-default
recording, announcements, caller-ID manipulation and other options stop export.
Neither unknown modules nor unsupported fields are silently declared recoverable.

## Blueprint And Executor

The schema is `respawn.reconstruction.v1`; module compatibility is FreePBX 17 and
Ring Groups 17. Operations have id, adapter version, action, dependsOn and typed
parameters. Unknown adapters/actions, bad references, cycles, duplicates, unsafe
input and excessive operation/member counts fail. Group operations and member
lists are sorted; duplicate members collapse. Group order from the source does
not affect the generated hash. Timestamp churn is excluded from blueprint content.

Hash bytes: PHP `json_encode` of the generated document without contentHash using
JSON_UNESCAPED_SLASHES; generated key order is fixed. This is a restricted v1
canonical profile, not a universal JSON canonicalisation standard. Cross-language
implementers must reproduce the fixture bytes; a future interoperable profile
should adopt a maintained canonicalisation library. A SHA-256 hash is not a signature.

`Executor` validates before mutation, locks the private build state and reads
target objects. Missing objects are created. Identical objects are read-back
verified; conflicting ones stop without overwriting. Checkpoints bind target
identity and blueprint hash. Each verified operation records a parameter hash and
event. Crash before checkpoint persistence is reconciled by reading the target on
retry; do not trust a checkpoint alone. No automatic destructive rollback occurs.

A failure returns the failed operation and correction guidance. Raw module errors
are not exposed because they can contain secrets. Exact upstream diagnosis remains
a local operator task. The scaffold records checkpoints at the state transaction
boundary; it does not fsync a durable journal per operation. Target read-back is
therefore essential after interruption. A final configuration_verified result has
callReadinessVerified=false. Apply Config/reload and real calls are separate gates.

## Adding An Adapter

Implement a read-only exporter, strict versioned parameter validator, dependencies,
target create/read/prerequisite interface and field-by-field comparison. Reject
unsupported non-default state. Define conflict handling, secret references, module
compatibility, checkpoint scope, retries and readiness checks. Add source-to-clean-
target contract tests, interruption/conflict tests and separately authorised live
tests. Never export raw SQL, dialplan or shell commands as reconstruction operations.
The current `Target` interface is deliberately a narrow Ring Groups slice; a
multi-module registry and extension/trunk/destination/route reference types are
the next architectural increment, not implemented coverage.

## Historical v1 Lab Notes (Do Not Run)

**Do not run these without separate explicit lab authorisation.** Use isolated
FreePBX 17 source/target VMs, no production trunks, numbering or routing. Record
Debian, PHP, framework, core, firewall and ringgroups exact versions and AMI state.

1. Install the local unsigned experimental package through FreePBX Module Admin
   or an approved local module-install procedure. Check Connectivity menu, section
   permissions, CSRF failures, CLI discovery and asterisk-owned private state.
2. On source, configure lab extensions/devices 1001 and 1002 and simple groups
   601 then 600 with supported defaults and 600's no-answer destination set to 601.
3. On target, configure only prerequisite lab extensions/devices. Confirm no 600
   or 601 objects and no colliding numbers in any other module.
4. Export source with `fwconsole respawn export-reconstruction --groups=600,601`
   to a private file. Inspect coverage and hash; transfer securely to target.
5. Obtain the target Respawn identity from status/settings, then run
   `fwconsole respawn reconstruct --blueprint=/private/blueprint.json --confirmation=reconstruct:TARGET_ID`.
   Check creation order, exact supported fields and configuration_verified.
6. Run the same command again. Confirm no duplicate or overwritten objects.
   Simulate interruption after creating 601, then retry; confirm reconciliation.
7. In FreePBX Apply Config using normal supported facilities. Check generated
   dialplan through approved read-only tools; never edit it directly. Place
   authorised internal lab calls to 600 and verify fallback to 601. This is not
   yet a trunk or SBC takeover test.
8. Compare fresh target export to intended supported source fields; account for
   different source identity in the envelope. Introduce a target conflict and
   prove reconstruction stops. Exercise unsupported options and missing members.
9. Retain sanitised outputs and module versions. Only then mark this slice
   integration-tested. Live provider tests remain a separate authorisation gate.