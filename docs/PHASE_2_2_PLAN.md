# Phase 2.2 — Co-op Roles & Team Mechanics Plan

## Status and scope

This document began as preparation-only and now records the office implementation slice. Phase 2.2 remains unreleased pending canonical home verification; no release commit or push is authorized in office mode.

Phase 2.1 remains **PARTIAL — HOME VERIFICATION PENDING**. Phase 2.2 implementation may proceed in office mode, but cannot be released or marked complete until the Phase 2.1 PostgreSQL, Docker, health, queue, and regression gates pass.

The plan follows the existing Runtime Engine and `player_role_assignments`. It does not introduce a second role system or a `runtime_role_assignments` table.

## 1. Existing reusable Runtime components

The implementation already provides the correct foundation:

- `RuntimeEngine` — authenticated, device-bound, idempotent command pipeline with Session/Team/Node locks.
- `RuntimeHandlers` — registered handler boundary for node execution.
- `RuntimeRoles` — role assignment, release, rotation, capability checks, and player eligibility.
- `RuntimeEvents` — transactional runtime events, audit records, and outbox records.
- `RuntimeProjection` — safe player/team runtime projection including active roles, support state, nodes, and Team Together progress.
- `RuntimeConditions` and persisted `random_seed` — deterministic server-side selection.
- `TeamScenarioInstance` / `TeamNodeInstance` — pinned scenario execution and authoritative node state.
- `RuntimeCommand` — durable command idempotency and conflict detection.
- `player_role_assignments` — current assignment plus immutable release history.
- Existing `Assign Roles`, `Swap Roles`, `Specific Player`, `Random Player`, `Team Together`, and baseline `Split Team` handlers.

## 2. Existing role assignment schema

The canonical persistence is `player_role_assignments` with:

- `team_scenario_instance_id`
- `node_instance_id`
- `game_session_player_id`
- `role_code`
- `slot_no`
- `assignment_source`
- `assigned_at`
- `released_at`

PostgreSQL currently enforces:

- slot numbers 1–4
- maximum four active slots per Team Scenario Instance
- one active Player per Team Instance
- one active assignment per role code
- same-Team Player and Node context
- immutable released history

`role_assignments` remains the authorization/system-role store. It must not be reused as gameplay state.

## 3. Existing role-related services/classes

`RuntimeRoles` already owns:

- capability mapping: `SCANNER`, `NAVIGATOR`, `DECODER`, `AR_OPERATOR`
- assignment and release
- least-recently-used rotation with seeded tie-breaking
- active-slot eligibility
- role-gated QR, GPS, puzzle, and AR boundaries
- Specific Player eligibility
- released-role rejection

`GameScope` and existing authorization services remain the source of GameMaster/session authorization. Captaincy remains a Team capability, never a gameplay role.

## 4. Existing Team Together and contribution support

Team Together is already a baseline Runtime handler. Contributions are reconstructed from append-only `runtime.node_submission_received.v1` events. The existing partial unique index prevents multiple accepted contributions from the same Player to the same barrier. Duplicate submissions return `DUPLICATE` without increasing progress.

`RuntimeProjection` already exposes safe contribution progress, and `RuntimeEngine` performs completion, event, outbox, and idempotency work inside the Runtime transaction.

This is sufficient for the pilot barrier baseline. It is not yet a complete generalized synchronization/barrier framework.

## 5. Phase 1.6 capability audit

| Capability | Status | Evidence / boundary |
|---|---|---|
| `player_role_assignments` | ALREADY IMPLEMENTED | Canonical migration/model and Runtime service |
| Maximum four primary slots | ALREADY IMPLEMENTED | PostgreSQL partial unique slot index and slot validation |
| Support representation | ALREADY IMPLEMENTED | Players without active assignments project as `support=true` |
| Support restrictions | PARTIALLY IMPLEMENTED | Restricted role actions, subgroup boundaries, support policy, and capability checks are implemented; richer support-targeted challenge content remains future work |
| Role release | ALREADY IMPLEMENTED | `RuntimeRoles::release`, immutable history, event/audit |
| Role reassignment | ALREADY IMPLEMENTED | `assign`, `release`, `Assign Roles`, `Swap Roles` |
| Rotation history | ALREADY IMPLEMENTED | Assignment history and LRU seeded rotation |
| Seeded random Player selection | ALREADY IMPLEMENTED | Persisted `state_json.selected_player_id`; no reroll on reconnect |
| Explicit Scenario-defined assignment | PARTIALLY IMPLEMENTED | Policy service validates Scenario-fixed Player lists; complete authoring contract and full override workflow remain bounded by existing Runtime commands |
| Team Together contributions | ALREADY IMPLEMENTED | Distinct persisted Runtime event contributions and threshold completion |
| Contribution idempotency | ALREADY IMPLEMENTED | Runtime command idempotency plus unique accepted contribution index |
| Generalized role capability checking | PARTIALLY IMPLEMENTED | Canonical capabilities and mandatory-device fail-closed boundary are implemented; canonical persisted Device capability metadata is not yet available |
| Late join from future stage | MISSING | Reconnect exists, but future-stage late-join policy is not a complete Runtime mechanic |
| Split Information privacy | PARTIALLY IMPLEMENTED | Role-scoped projections are server-filtered; richer per-player secret authoring remains future work |
| Generalized Barrier / required-role synchronization | PARTIALLY IMPLEMENTED | Required roles, active-slot-only, distinct-player, and synchronization-window evaluation are implemented; PostgreSQL contention verification remains pending |
| Split Team advanced subgroups | PARTIALLY IMPLEMENTED | Deterministic subgroup membership persists in Node state; a dedicated durable subgroup model is intentionally deferred |
| Relay mechanics | PARTIALLY IMPLEMENTED | Ordered role Relay baseline persists progress in Node state; broader assignee/player authoring remains deferred |
| Multi-point presence / simultaneous windows | MISSING | Location domain exists, but co-op synchronization policy does not |

## 6. What Phase 2.2 already has from Phase 1.6

The first implementation slice can reuse the production Runtime architecture immediately:

1. Keep the Session/Team/Node transaction and lock order.
2. Keep `player_role_assignments` as the only gameplay-role truth.
3. Keep `RuntimeCommand` for every retryable mutation.
4. Keep append-only Runtime events for contribution history.
5. Extend registered handlers through `RuntimeHandlers` and `NodeTypeRegistry`.
6. Use `RuntimeProjection` for safe role/support/progress output.
7. Use `RuntimeRoles::eligible()` before every role-gated action.
8. Use the existing deterministic `TeamScenarioInstance.random_seed` for all gameplay randomness.

## 7. What is actually missing

The missing work is cooperative policy and durable execution semantics, not a new role table:

- a normalized role/capability policy contract for Scenario nodes
- explicit support participation and support-targeted challenge policy
- assignment policy selection: LRU, seeded random, fixed, GameMaster override, and late join
- role history/fairness decisions across stages
- generalized multi-contribution barriers and required-role sets
- Split Information server-side projection boundaries
- richer Split Team subgroup semantics beyond Node state
- broader Relay current/next Player authoring beyond the role-chain baseline
- late-join eligibility beginning at the next stage
- device capability checks for role assignment where required
- Event Catalog decisions for any future barrier, subgroup, relay, and contribution events
- concurrency tests for role changes during submissions and barrier completion

## 8. Proposed implementation sequence

### Slice A — role policy and fairness

- formalize role labels versus immutable capabilities
- add a `RoleAssignmentPolicy` service around existing `RuntimeRoles`
- preserve LRU history and seeded deterministic tie-breaking
- support `ROTATION`, `LEAST_RECENTLY_USED`, `SEEDED_RANDOM`, `SCENARIO_FIXED`, `GAMEMASTER_OVERRIDE`, and `LATE_JOIN_POLICY`
- preserve active-slot uniqueness and Support classification

### Slice B — Support and late join

- define Support as active roster participation without a primary assignment
- permit only explicitly configured Support actions
- preserve active assignments through disconnect grace periods
- make a reconnecting/late-joining Player eligible from the next stage by default
- allow immediate assignment only when a Node policy explicitly permits it

### Slice C — generalized barriers and contributions

- extract contribution validation from the current Team Together handler
- support minimum count, required roles, required Players, and ordered or unordered subtasks
- persist contribution identity in Runtime events and use command idempotency
- complete a barrier exactly once after all requirements are satisfied

### Slice D — Split Information, subgroups, and Relay

- add role/player-scoped safe projection rules
- model Split Team subgroups through Runtime state only after the contract is frozen
- add Relay current assignee and next eligibility
- keep hidden information and future branches server-filtered

## 9. Proposed services

Names are provisional and must be reconciled with the frozen contracts before coding:

- `RoleAssignmentPolicy` — selects and validates assignment policy using `RuntimeRoles`.
- `RoleFairnessService` — computes historical participation and deterministic tie-breaks.
- `SupportParticipationPolicy` — determines allowed Support actions and visibility.
- `ContributionService` — validates, records, and deduplicates attributable contributions.
- `BarrierEvaluator` — evaluates thresholds, required roles, required Players, and synchronization windows.
- `SubgroupService` — only if Split Team durable subgroup semantics are approved.
- `RelayPolicy` — only if Relay state and transition rules are frozen.
- `RuntimeProjectionPolicy` — filters role/player-specific information without exposing another Player’s secret.

Existing `RuntimeRoles`, `RuntimeEngine`, `RuntimeHandlers`, `RuntimeEvents`, and `RuntimeProjection` remain the integration points; these proposed services must not bypass them.

## 10. Proposed Runtime handlers

Reuse existing handlers where possible:

- extend `Assign Roles` / `Swap Roles` for explicit policy sources and audited overrides
- extend `Team Together` into a generalized barrier handler
- extend baseline `Split Team` only after subgroup semantics are frozen
- add a barrier/synchronization handler only if it cannot be represented safely by the extended Team Together contract
- add a Relay handler only after current/next assignment persistence is approved
- keep QR, GPS, and AR as external verification ports; Co-op supplies eligibility policy, not duplicate verification

No handler may directly update score, bypass Runtime transitions, or mutate another Team’s state.

## 11. Proposed authorization rules

- Players may submit only for their authenticated Session Player and bound Device.
- Active role actions require current assignment, capability, Node policy, and participation eligibility.
- Support may act only where the Node explicitly allows Support participation.
- Captaincy grants no gameplay bypass, slot, or hidden-information access.
- GameMaster role override requires assignment to the Session, reason where required, tenant scope, audit, event, and idempotency.
- Organization administrators cannot silently impersonate a Player.
- Other-Team and cross-tenant access remains not-found/denied.
- Client payload cannot choose role, slot, subgroup, target Player, next Node, or completion result.

## 12. Proposed events

Existing canonical events to preserve:

- `runtime.role_assigned.v1`
- `runtime.role_released.v1`
- `runtime.node_submission_received.v1`
- `runtime.node_completed.v1`

Potential new events require Event Catalog approval before implementation:

- `runtime.role_swapped.v1`
- `runtime.barrier_progressed.v1`
- `runtime.barrier_completed.v1`
- `runtime.contribution_recorded.v1`
- `runtime.subgroup_assigned.v1`
- `runtime.relay_transferred.v1`
- `runtime.support_challenge_assigned.v1`

If a new event is not already frozen in the Event Catalog, Phase 2.2 must first revise the catalog and corresponding outbox/event tests. No aliases should be introduced.

## 13. Proposed API impact

The current frozen Runtime endpoints should remain the primary transport:

- `GET .../runtime` exposes safe current roles, Support state, and barrier progress.
- Runtime node submission/complete endpoints remain the mutation boundary.
- Existing service-only `assign_role` and `release_role` commands remain internal until a frozen public transport is approved.
- No Player self-assignment endpoint is allowed.
- A future GameMaster control endpoint may expose audited role override, but it is not part of this preparation and must not be added now.

OpenAPI changes are therefore **not planned for preparation**. Any later API change must be contract-first and version-compatible.

## 14. Database decision

**No migration is required for the Phase 2.2 pilot baseline** if the scope is limited to existing role assignment, Support classification, LRU/seeded rotation, Team Together, and role-gated QR/GPS/Puzzle/AR contracts.

The existing `player_role_assignments`, `runtime_events`, `runtime_commands`, `team_node_instances.state_json`, and Runtime indexes are sufficient for that slice.

A migration may become necessary for advanced Split Team subgroups, Relay state, multi-point synchronization windows, or dedicated contribution projections. That decision must follow a frozen schema amendment and must not introduce `runtime_role_assignments` or a duplicate contribution truth table.

## 15. QR integration

Phase 2.1 remains the QR verification authority. Phase 2.2 only supplies Runtime eligibility:

- `Scanner` maps to `SCAN_QR`.
- QR resolves Session, Team, Player, Device, Node, Trigger, and token context first.
- Runtime role policy then accepts or rejects the verified QR evidence.
- Support cannot scan a role-restricted QR unless the Node explicitly permits Support/any-member access.
- QR never assigns roles, completes nodes directly, or selects the next Node.

Phase 2.1’s final home verification remains a prerequisite.

## 16. GPS integration

Phase 2.2 consumes Phase 2.0 verified evidence through the existing `LocationVerification` port:

- `Navigator` and required-role policies are evaluated by Runtime.
- multi-player presence requires distinct Session Players and server-side evidence freshness/checkpoint checks.
- no co-op service reimplements PostGIS or geofence calculations.
- GPS evidence never becomes client authority for role or completion.

## 17. AR future boundary

AR remains fail-closed until its later phase. Phase 2.2 may define the capability requirement (`OPERATE_AR`) and device-compatibility contract, but must not implement AR handlers, AR APIs, assets, or completion verification.

## 18. Tests required

### Existing regression coverage to preserve

- four-slot database enforcement
- assignment/release/reassignment and immutable role history
- LRU seeded rotation
- Specific Player and Random Player persistence
- Team Together threshold and duplicate contribution behavior
- role-gated QR/GPS/Puzzle paths
- Captain non-privilege
- Runtime command idempotency and device binding

### Phase 2.2 tests

- Team sizes 4 through 8
- fifth primary assignment rejected
- Support remains active and safe
- role capability acceptance/rejection
- role release and reassign history
- repeated-stage fairness distribution
- seeded reproducibility
- explicit Scenario-fixed assignment
- GameMaster override authorization, reason, audit, and idempotency
- late join begins at the next stage
- Specific Player and Random Player cannot be bypassed
- Team Together incomplete/complete/duplicate contribution
- required-role barrier and missing-contribution rejection
- Split Information cannot leak another Player’s secret
- Split Team subgroup isolation
- Relay transfer ordering
- device capability restrictions for AR role boundaries
- QR role integration and GPS role integration
- cross-tenant and other-Team denial
- concurrent role assignment, role swap, submission, disconnect, and barrier completion

PostgreSQL two-connection tests must be used for lock/contention behavior. No SQLite substitute is permitted.

## 19. Concurrency and idempotency plan

All co-op mutations must run inside the existing Runtime transaction and lock order:

1. authenticate and resolve Device/Player
2. resolve Session and Team scope
3. lock Team Scenario Instance
4. lock relevant Node
5. lock role/contribution rows or use protected unique indexes
6. re-check status, assignment, participation, and policy
7. mutate current state and append events/outbox atomically

Required race handling:

- simultaneous role assignment: slot/role/player uniqueness and retry-safe command result
- role swap during submit: submit observes the locked pre-swap or post-swap state, never a mixed state
- disconnect/reassign: preserve or explicitly release assignment under the same Team lock
- simultaneous contributions: one accepted contribution per Player/Node requirement
- barrier completion: exactly one terminal transition and completion event
- repeated command key with changed payload: `IDEMPOTENCY_CONFLICT`

## 20. Security boundaries

- Runtime is the only progression authority.
- Role and Support state are derived from server persistence, never client claims.
- Safe projections reveal only the authenticated Player’s permitted role/content.
- Hidden branch conditions, other Player secrets, future nodes, and internal IDs remain excluded.
- All queries remain Session, Team, ScenarioVersion, and Organization scoped.
- Audit privileged role overrides and recovery actions; normal contributions remain Runtime history/events.
- Outbox delivery remains at-least-once and consumers deduplicate by event ID.

## 21. Expected files for a later implementation

Likely additions, subject to the frozen contract:

- `app/Services/Runtime/RoleAssignmentPolicy.php`
- `app/Services/Runtime/RoleFairnessService.php`
- `app/Services/Runtime/SupportParticipationPolicy.php`
- `app/Services/Runtime/ContributionService.php`
- `app/Services/Runtime/BarrierEvaluator.php`
- focused Runtime handler classes only where the existing handler cannot be safely extended
- focused Feature/Unit/PostgreSQL contention tests

Likely modifications:

- `app/Services/Runtime/RuntimeRoles.php`
- `app/Services/Runtime/RuntimeHandlers.php`
- `app/Services/Runtime/RuntimeEngine.php`
- `app/Services/Runtime/RuntimeProjection.php`
- `app/Services/Runtime/RuntimeEvents.php`
- `app/Services/Scenarios/NodeTypeRegistry.php`
- relevant Runtime/Scenario tests
- OpenAPI/Event Catalog only if a later approved contract requires it

These are expected scopes, not files to create during preparation.

## 22. Exact Definition of Done

Phase 2.2 implementation may be considered complete only when:

- Phase 2.1 home verification is complete and pushed first.
- No duplicate role or contribution truth store exists.
- Teams of 4–8 obey the four-primary-slot rule.
- Support is active, safe, and meaningfully represented.
- Role labels map to extensible capabilities.
- LRU, seeded random, fixed, override, and late-join policies are deterministic and audited.
- Role history survives reconnect and supports fairness.
- Specific Player and Random Player mechanics are server-authoritative.
- Team Together and generalized barriers are persistent, idempotent, and concurrency-safe.
- Split Information is Player/role scoped with no secret leakage.
- Split Team and Relay behavior is explicitly frozen and tested, or clearly deferred.
- QR uses Phase 2.1 verification and Runtime role truth.
- GPS uses Phase 2.0 evidence and does not duplicate geofence logic.
- AR remains behind its fail-closed boundary.
- Runtime command idempotency, locks, events, audit, and outbox remain intact.
- Full Phase 0–2.1 regression suite passes, including PostgreSQL contention tests.

## 23. Current blocker

The only release/verification gate is the outstanding Phase 2.1 home verification. Phase 2.2 office implementation may proceed under the execution prompt, but it must not be released or marked complete until that gate is closed.

## 24. Office implementation discoveries

The pilot baseline remained representable without a migration. Existing Node `state_json` safely stores deterministic Split Team subgroup membership and Relay progress; existing Runtime events store role-attributed contributions; existing command locks and unique indexes remain the concurrency boundary. Device capability metadata is not present in the canonical device schema, so mandatory capability checks fail closed through a policy boundary rather than inventing new columns.

The office implementation added generalized barrier evaluation, role-aware contribution metadata, deterministic subgroup assignment, Relay progression, role-scoped projections, and assignment/fairness policy services. These changes remain **PARTIAL — HOME VERIFICATION PENDING** until PostgreSQL contention, migrations, Docker, health, queue, and full regression checks pass.
