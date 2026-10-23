# Local Pond Development Demonstration

Local Pond is a separately implemented, development-only reference controller
using Respawn's public `respawn.provider-rpc.v2` envelope and reconstruction v2
blueprint. It is not the closed-source production Pond, does not reproduce its
internals, and makes no production compatibility claim. All code, listeners, state
and credentials are outside the FreePBX module and public website source trees.

## Start, Browser, Stop, Reset

Requirements: PHP CLI with JSON and process support, Python 3 dependencies from
`requirements-dev.txt`, installed Playwright Chromium, and the browser libraries
used by `tests/site.py`. The service uses the PHP built-in HTTP server and binds
both HTTP listeners exclusively to `127.0.0.1`.

From the repository root:

```sh
sh development/local-pond/bin/start.sh
```

The command prints the browser address; defaults are
`http://127.0.0.1:8732/` for the console and `127.0.0.1:8731` for provider RPC.
The private runtime root is `build/local-demo/`. Override the ports with
`RESPAWN_LOCAL_POND_PORT` and `RESPAWN_LOCAL_DASHBOARD_PORT`; both must be distinct
unprivileged ports. Startup refuses to bind an occupied port or use runtime state
outside the named build directory.

Stop only the recorded, verified Local Pond/demo processes and retain state:

```sh
sh development/local-pond/bin/stop.sh
```

Reset must be run after stop. It checks the exact ownership marker, rejects
symlinks and unexpected roots, removes only the named demo's credentials, state,
targets, sessions, logs and process records, then generates fresh synthetic
credentials. It does not remove the runtime root or unrelated files.

```sh
sh development/local-pond/bin/reset.sh
```

The integration test uses its own marker-owned temporary directory under ignored
`build/local-demo-tests/`, starts real loopback processes, and removes only that
test directory after stopping its own processes:

```sh
XDEBUG_MODE=off python3 tests/local-pond.py
```

## Walkthrough

1. Choose PBX Alpha or Bravo. The read-only fixture adapter displays two Core
   users, two fixed PJSIP devices, protected secret-reference presence and the
   dependent primary/fallback Ring Groups. No secret value is returned to the UI.
2. Export the blueprint, then publish it. Local Pond validates the v2 schema,
   canonical hash, dependencies, source identity and selected coverage; it retains
   the accepted blueprint and version under the PBX and provider connection.
3. Choose recovery/manual policy for simulated activation, select Normal run, and
   press **Respawn selected source**. The shared Respawn `Application` journals the
   request and sends it through an actual authenticated HTTP request to Local Pond.
4. The separate worker allocates an operation-owned simulated replacement and runs
   the real `BuildExecutor`, `BuildSpec` planner, `FileSecrets`, `SimulatedPbx`,
   target-wide lock, checkpoints, object read-back and apply boundary. The screen
   shows objects and their executor dependency order as persisted work completes.
5. After configuration verification, the worker records explicitly simulated
   connectivity and routing preparation. Run the routing-model probe before
   activation to confirm the source route remains selected. Activate the simulated
   route and run it again: it follows the replacement target's persisted Ring
   Groups from the primary group through its fallback member. This is a routing-
   model test, not SIP registration, a live call or audio.
6. Tear down. The operation's simulated route is removed and `SimulatedPbx` removes
   only objects it owns. Shared/foreign objects remain visible as preserved.

Refresh and opening a new browser session reload the persisted operation. The Local
Pond and operator journal survive ordinary process restarts. No progress is advanced
by a timer; the worker performs a stage, checkpoints it, and exposes its saved state.

## Repeatable Failure Scenarios

Choose the scenario before launching. Each is consumed once by the selected PBX
and provider connection.

| Scenario | Persisted result | Safe recovery |
| --- | --- | --- |
| Accepted, response lost | The server retains the operation; the client loses only the response; local submission is outcome-unknown | Refresh or open a new session, Reconcile, then optionally Retry Original. Both lookup and retry reuse the stored key; server idempotency leaves one operation |
| Conflicting target object | The executor's complete preflight detects a synthetic foreign Core user; no later object is written | Inspect the conflicting target row, then tear down owned resources |
| Unresolved original secret | `FileSecrets` rejects the absent manifest before any Core/PJSIP/Ring Group mutation | Inspect the safe diagnostic; tear down the allocated replacement |
| Apply failure | The target contains saved objects, but the actual simulated apply call fails and configuration is not verified | Inspect apply/checkpoint state; tear down owned resources before another deliberate launch |
| Expired simulated readiness | Routing preparation is visible with expired demo evidence | Activation is rejected; reconcile, cancel if applicable, or explicitly tear down |
| Cancellation at checkpoint | Worker pauses after simulated replacement allocation with persisted `provisioning` state | Cancel, confirm `cancelled`, then tear down |
| Partial teardown | Owned simulated resource cleanup reports failed/blocked resources | Inspect remaining references and press Tear down again; cleanup is idempotent |
| Shared target object | A synthetic pre-existing shared Core user is not owned by the operation | Tear down; the shared object stays present and labelled preserved |
| Worker interruption | Worker exits after the first actual object mutation, after the executor's durable running checkpoint | Stop/restart the named processes. Startup recovers the queued job and BuildExecutor reconciles target state without duplicate objects |
| Provider connection change | Alternate RPC path produces a different connection ID; old records remain under the original path | Return to the original path to reconcile/manage its operation; publications are not transferred |

Duplicate Respawn requests with the same exact provider key/request return the same
operation ID. Reusing a key with different request content is rejected. The browser
CSRF session only protects its local operator actions; it is not a production
enrolment or authentication design.

## Evidence Boundary

| Executed locally for real | Deliberately simulated or not claimed |
| --- | --- |
| PHP built-in servers, real loopback HTTP request/response bytes, bearer-token verification, v2 envelope timestamps/schema/identity/connection checks | External provider, TLS, public DNS, enrolment service or production controller compatibility |
| Respawn `Application`, `LocalPondProvider`, source fixture adapter, canonical blueprint/hash, BuildSpec planner, BuildExecutor and protected `FileSecrets` references | FreePBX install/runtime, Asterisk execution, generated configuration, SIP registration, live calls, bidirectional audio or production call/audio readiness |
| Durable JSON state, publication/version retention, idempotency, operation lookup, reconciliation, worker queue/checkpoints, process restart and sanitised events | Replacement VM/container provisioning, real connectivity, firewall, routing/SBC enforcement or real resource receipts |
| Actual simulated target objects/apply count/read-back and ownership-aware object/resource cleanup | Production credentials, real source secrets, hosting, billing, DOMAINTAINS or private provider APIs |
| Activation changes the Local Pond routing model; the probe reads the built target's actual Ring Groups and selects the fallback extension | Production activation authority, fencing, readiness acceptance or takeover in minutes |

Local readiness is always `SIMULATED_ONLY`. It is not encoded as production
verification receipts. The production HTTPS adapter rejects the explicit Local
Pond/development markers; its activation policy still requires fresh, bound
production evidence, including registration, inbound/outbound calls and audio.

Runtime-generated PBX tokens and synthetic authentication secrets are stored in
mode-0700/0600 files beneath the ignored runtime root. Neither source fixtures nor
blueprints include secret values. Do not copy runtime state into an archive or
commit it. `tests/package.py` and the integration test assert that the module and
cPanel packages contain no Local Pond, runtime state or credentials. Package
inclusion rules are intentionally explicit: `tools/package.py` archives only
`module/` plus `LICENSE`; `tools/build_site.py` copies only `website/assets/` and
`contracts/` into the cPanel build.