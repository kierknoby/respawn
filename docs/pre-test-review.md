# Final Pre-Test Corrections, 23 October 2026

Version remains 0.0.1. This is an experimental-source correction pass, not a
production certification. No live FreePBX, external provider or deployment is
part of the work. Earlier validation history is preserved.

## Finding Dispositions

| Finding | Correction and evidence |
| --- | --- |
| F1, cron newline | Newline-terminated `/etc/cron.d` specimen, every minute, no output suppression. Source and packaged-file tests assert fields/newline. Host path/account/PHP, reboot persistence and executed heartbeat remain lab checks. |
| F2, revision capacity | Immutable hash-addressed chunks and atomic reference journal; existing API/records preserved. Fifty changed 81-operation revisions pass with retrieval, comparison, Revert/Regenerate plans, pruning, pins/acknowledgements, restart, concurrent/killed writers, OS-limited writes, failed migration and corruption recovery. A 100-operation blueprint and 500 pending outbox records are tested. |
| F3, diagnostics | Private rotating log, persisted invocation/success/failure/recovery fields, read-only missed/stalled health and strict non-current CLI exit status. Failure/retry/recovery tests retain the protected head. Status reads do not refresh heartbeat. External alerting needs independent monitoring. |
| F4, ordering | Only a newly retained child of the current recovery head promotes. Historical/unordered requests retain without promotion; duplicates, conflicting lineage, A/B/C delayed arrivals, restart and explicit restored-source continuation are tested. Cached receipts require current-head read-back. |
| F5, documentation | Development Pond, experimental standalone SQLite/AES-GCM Pond and production-verified deployment are distinguished. River/Lake scope is unchanged. |
| F6, prerequisites | Read-only preflight checks PHP/extensions, optional permissions, source cron/account/path and recovered bundle integrity. Development Pond/recovery dependencies pass; real-host/public HTTPS checks remain outstanding. Key restoration and bundle disposal are documented. |

## Storage And Migration

`JsonStateStore` keeps its lock/callback API. Journals above 512 KiB use a
`chunks.v1` marker and immutable SHA-256 JSON chunks; nested arrays split at
32 KiB. Blueprints, metadata, revision IDs, hashes and parent relationships are
not discarded or renumbered. New captures add a monotonic source sequence; old
records retain their original hashes. Publication journals and saved plans use
the same storage path. The 1 MiB per-journal/chunk safety bound is unchanged,
not a shared history ceiling.

Files/directories are fsynced before atomic journal commit. Unreachable chunks
are collected afterwards under the same lock. Interruptions can leave orphan
files, not partially committed history; a later successful commit cleans them.
Missing/corrupt reachable chunks fail closed without resetting evidence. Failed
writes preserve the previous committed history. If diagnostic storage also fails,
the command fails with a safe PHP-log fallback. Hardware power-loss guarantees
still depend on host filesystem/device behaviour and remain unproven.

Legacy inline journals migrate on a successful large update. Failed migration
preserves the old journal. Pins, heads, acknowledgements, plans, retention and
pending retries remain unchanged values. Stop all runners and back up the entire
state directory before upgrade. After migration, always back up chunks/journal
together. Do not downgrade in place or copy only `state.json`; restore a complete
pre-upgrade backup for rollback.

Retention remains **2-500 revisions**, default **50**, per source. Pins and
selected heads cannot be silently evicted. When all capacity is protected,
capture fails visibly rather than losing pending revisions. The blueprint limit
remains **100 operations**, not 100 extensions: user/device pairs consume two,
plus groups. The HTTPS envelope remains **64 KiB including metadata**. Operation
count alone is not a byte-size guarantee. Provision disk/PHP memory for the
chosen history; unlimited history/disk/fleet capacity is not promised.

## Ordering And Compatibility

Acknowledgements remain compatible. Optional `ordering.parentRevisionId` freezes
the expected recovery head for each transaction. Only a newly retained revision
whose parent equals the current head (both null for initial capture) advances it.
The response reports the current head, which the ordered client verifies.
Pre-extension database heads remain selected; unordered legacy submissions are
historical only. Lost-response retries retain revision/key/parent and receipt.
Retained historical transactions cannot be rebound/promoted. A restored source
must explicitly verify and authorise continuation from the current Pond head,
with no unresolved pending publications. Concurrent writers are fenced by the
atomic parent comparison; there is no automatic branch selection or rollback.

## Changed Surfaces

Storage/revisions: `JsonStateStore`, `RevisionStore`, capacity regression.
Protection/CLI/GUI: coordinator, module/console classes, status page, cron and
protection regressions. Pond/client: `Pond`, `RecoveryBundle`, ordered-provider
interface, HTTPS adapter and ordering/service tests. Operations: preflight,
main gate, package builder/tests. Documentation: README, architecture, ecosystem,
federation evidence, contracts, Pond runbook, initial-test, validation and website.

## Remaining Host Gates

Use Server A (disposable FreePBX 17), Server B (independent experimental Pond),
Server C (clean compatible FreePBX 17). Follow `initial-test.md`. Require actual
installation, CLI/PHP/account permissions, executed cron heartbeat after reboot,
failure/retry evidence, ordered retention, independent key/token custody, source
loss retrieval and exact original/rotated passwords. Then attempt create-only
native reconstruction, one reload and configuration read-back. Evaluate runtime
and optional endpoint registration separately. No trunks, carrier routes, customer
numbers, production activation or full-PBX recovery claim is permitted.

Verdict: **READY WITH EXPLICIT LIMITATIONS**, contingent on the recorded complete
local gate and on satisfying these host prerequisites before the real test.