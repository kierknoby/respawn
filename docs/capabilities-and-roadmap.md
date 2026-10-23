# Capability Evidence And Priorities

| Capability | Implemented | Fixture/contract tested | Real integration | Status / limitation |
| --- | --- | --- | --- | --- |
| Firewall IPv4 /32 normalisation/hash | Yes | Yes | Unverified | Explicit trusted hosts only |
| FreePBX read-only Firewall adapter | Yes | Shape only | Unverified | Missing/disabled fail; broader sources rejected |
| Executable v2 reconstruction blueprint | Yes | PHP planner and independent JSON Schema | Unverified | Strict selected user/PJSIP/group profile, not whole PBX |
| Dependency-ordered target execution | Yes | Independent native-shaped source/target doubles | Unverified | Creates selected users/devices before groups; modules/global SIP are prerequisites |
| Checkpoints/conflicts/retries/read-back | Yes | Actual process death and competing process | Unverified | Per-operation file/directory fsync, target lock, partial/unknown reconciliation |
| Original credential continuity | Versioned protected references | Source/target equality and unsafe-manifest rejection | Unverified | Secure original-secret staging/transport must be supplied |
| Final apply boundary | Fixed framework reload callback | One apply, failure/retry and receipt invalidation | Unverified | Saved state/reported reload is not runtime or call proof |
| Connectivity menu/settings/lifecycle/CLI | Source implemented | Syntax and packaging | Unverified | Actual BMO/session/RBAC/CLI discovery not proven |
| Publication/acknowledgement/no-change/force | Mock | Yes | Unverified | Single current publication slot per identity |
| Provider endpoint/config and HTTPS profile | Yes | Yes, injected transport | No live network | TLS/network/provider compatibility unverified; enrolment external |
| Respawn launch/reconcile/activation guards | Mock | Yes | Unverified | No automatic production activation |
| Local Pond development controller | Yes | Real loopback HTTP, authenticated v2 envelopes, persistent worker/restart, actual Application + BuildExecutor, UI activation/routing model/teardown | No external service | Independent development reference only; all replacement/connectivity/routing evidence is SIMULATED_ONLY |
| Cancellation/owned cleanup/retry | Mock | Yes | Unverified | Real resource APIs/fencing unavailable |
| Two independent PBXs | Synthetic harness | Yes | Unverified | Not two running FreePBX instances |
| Real hosting/WHMCS/DOMAINTAINS/SBC | Contracts only | Mock boundaries | Unverified | Private service implementations required |
| Live call/audio readiness and takeover timings | Evidence contract/tool | Synthetic rejection tests | Unverified | No real result or timing claim |
| Intent/policy signatures | No | Not applicable | Unverified | Planned, hashes are not signatures |
| cPanel static site | Yes | Extracted browser checks | Not deployed | Desktop/mobile screenshots retained |

## Prioritised Next Milestones

1. Prove unsigned module packaging/install/upgrade/uninstall, section authorisation,
   settings persistence, CLI and the supported reconstruction slice in isolated
   FreePBX 17 VMs. Record exact versions and API defaults; adjust only from evidence.
2. Expand the implemented typed Core-user/PJSIP/Ring-Groups chain to broader field
   profiles, secure private secret-service integration, global SIP configuration,
   trunks and inbound/outbound routes. Discover full coverage instead of fixed gaps.
3. Prove the HTTPS profile against a separately authorised compatible service;
   add enrolment, per-area acknowledgement retention, more response/error cases,
   central logging and async FreePBX jobs. Submission journalling and protected-file
   credential retrieval are already implemented locally.
4. Privately implement hosting, WHMCS, DOMAINTAINS/equivalent and SBC contracts,
   controller authority epochs, policy signing, callback/reconciliation semantics
   and safe active-routing disposition. Never implement private billing logic in core.
5. Add independent call/audio probes and perform separately authorised takeover
   tests. Measure the full chain, compare prepared images/warm capacity, record
   partial failure, rollback, residual cost and continuity limitations.

No timetable, production readiness, recovery percentage or achieved-minute target
is invented. A polished website or successful mock is not completion of takeover.