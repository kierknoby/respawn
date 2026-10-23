# Focused Reconstruction Review And Fixes

## Starting State

Branch 0-0-1_Dev, HEAD 147925b01d56896b3addc328a5e85c21d8a606b6, empty
index, existing README modification and 65 other unstaged scaffold files. No
unrelated changes were reset or cleaned. The complete starting source was archived
before edits at artifacts/reconstruction-pass-starting-source.tgz. Final inventory
compares source against that snapshot, not just HEAD (which does not contain the
scaffold). LICENSE remains unchanged.

## Findings And Fixes

| Severity / finding | Evidence | Fix / meaningful check |
| --- | --- | --- |
| High: later conflict could follow earlier mutation | Legacy Executor checked objects only as it iterated. New regression first failed after 601 was created before conflict at 600 | Full v1 conflict preflight; v2 preflights modules, capabilities, all secrets, objects, dependencies and ownership before mutation. Regression proves no earlier create |
| High: checkpoint only persisted after the whole callback returned | Original Executor used one state update around all work | V2 per-operation pending/running/succeeded/failed/unknown, file fsync, rename, directory fsync. Actual child process exits after mutation; new executor reconciles without duplicates |
| High: no final apply or active-state boundary | Original configuration_verified only meant saved Ring Groups read-back | Fixed framework reload after all objects; saved/apply/post-apply distinguished. Apply failure remains incomplete; object recreation invalidates old receipt |
| High: selected prerequisites were not reconstructed | V1 required memberExists and excluded users/devices | V2 creates Core users, fixed PJSIP devices and dependent groups on an initially clean selected target. Contracts check stable identity, original secret and relationships |
| Medium: mock bypassed the executor | MockProvider used its own create/read loop | V2 mock invokes actual BuildExecutor and retains its result, apply and separate service-readiness data |
| Medium: doubles mirrored argument structure | Ringgroups double combined adapter positional arguments | Independent Core row/AstDB/SIP semantics, partial write, explicit identity/credential assertions, source/target comparison, real-process death and locking tests. Not real integration proof |
| Medium: two PBXs used identical selected objects | Old demo changed identity envelopes but not configuration | Distinct alpha/bravo users/auth identities/groups, separate clean targets and matching original runtime-only synthetic credentials; isolation and redaction checked |
| Medium: local journal was keyed only by operator key | Application used submissions[key] | Namespace by PBX identity, safely read old same-source entries and filter local status. Same-key/two-PBX regression proves separate original requests |
| Medium: freshness accepted relative timestamp strings | strtotime accepted now within the freshness window | Strict RFC3339 parser; relative input regression rejected |
| Medium: readiness omitted apply and per-check binding | Old ready check allowed configuration/call receipts without apply or operation/version binding | Coverage/reconstruction/apply/configuration/connectivity/routing plus call/audio receipts bind operation/blueprint/version and individual freshness. Missing apply/wrong version rejected |
| Medium: reconcile did not bind requested launch key | Operation shape was checked, original key was not | Wrong-key authoritative response now rejected |
| Medium: cleanup warnings did not fail the suite | New nested provider data produced warnings while assertion count still passed | Recursive removal of owned temporary trees; warnings promoted to exceptions |

Additional continuity design: immutable versioned secret references make credential
rotation change blueprint identity without exposing a password or password hash.
Source export verifies staged credentials against the source. Unsafe, missing or
mismatched references block the selected build, never generate real endpoint secrets.

## Actual Execution Trace

1. GUI/CLI selects groups and a protected manifest reference. Native export reads
   supported state, checks original credentials and emits executable v2 operations.
2. PHP planner checks semantics and dependencies. Independent JSON Schema checks
   generated structure. Exact enabled module versions and runtime preflight follow.
3. Publication retains export/attempt/ack separately. Launch journals the original
   request and binds retained blueprint version/policy. Acceptance is not readiness.
4. Real provisioning remains a private provider interface. Mock provisioning is
   explicitly simulated and owns only synthetic resources.
5. Native/simulated BuildTarget uses the same BuildExecutor: preflight, durable
   checkpoints, mutations, read-back, final apply and post-apply comparison. Native
   methods were source-inspected and contract-tested, not run on FreePBX.
6. Mock status retains reconstruction/apply evidence; serviceReady/callsVerified
   stay false. HTTPS receipt handling is a tested proposed protocol, not proof of
   private-service compatibility or authentic real-call evidence.
7. GUI renders checkpoints, ownership, failures and saved/apply/runtime boundaries.
   CLI returns nonzero on failed/unknown/partial results. Native BMO/session/CLI
   integration remains an explicitly unverified gate.

## Residual Risks

No actual FreePBX mutation, framework reload, endpoint registration, call/audio,
hosting, WHMCS, DOMAINTAINS or SBC change was executed. Inspected source versions
are not tested runtime versions. Strict defaults can reject ordinary GUI-created
extensions until field coverage is broadened. Global SIP, custom user features,
external hooks and module installation remain prerequisite/compatibility boundaries.

Local advisory locks coordinate only runners sharing the protected canonical state
root, not unrelated administrators. Native Core writes can partially complete or
terminate through legacy fatal paths; the engine reconciles conservatively, not
transactionally. Native object deletion is absent. Private resource/VM teardown
must independently enforce ownership and active-routing disposition.

See reconstruction-v2.md for exact fields, interface revisions and opt-in lab
steps; operational-path.md and https-profile.md for private dependencies and
timing/readiness contracts. Real acceptance still requires original-credential
registration, successful inbound/outbound calls and bidirectional received audio.