# NEXO - Phase 1.6 Runtime Engine

## 1. Phase

Phase 1.6 - Runtime Engine only. No Phase 2.0 domain implementation.

## 2. Status

**COMPLETE / VERIFIED**. All required Phase 1.6 verification gates passed; Phase 2.0 was not started.

## 3. Workspace verification

Workspace: C:\Users\HadNegaran\Desktop\ganj. Specifications: docs/specs; canonical OpenAPI: docs/specs/03_openapi.yaml. Mandatory references and upstream checkpoints were read. PHP 8.4, Laravel 13.32.0, PHPUnit 12.5.35, PostgreSQL 16. Installed dependencies were preserved. Verification date: 2026-10-09 (Asia/Tehran).

## 4. Git status / SHA

Unavailable: this directory is not a Git repository. No repository was initialized. File inventories below describe this implementation, not a Git diff.

## 5. Files added (38)

- app/Console/Commands/ExpireRuntimeTimers.php
- app/Http/Controllers/Api/RuntimeController.php
- app/Models/TeamScenarioInstance.php
- app/Models/TeamNodeInstance.php
- app/Models/RuntimeCommand.php
- app/Models/RuntimeEvent.php
- app/Models/RuntimeVariable.php
- app/Models/RuntimeTimer.php
- app/Models/PlayerRoleAssignment.php
- app/Services/Runtime/RuntimeInitializer.php
- app/Services/Runtime/RuntimeEngine.php
- app/Services/Runtime/RuntimeHandlers.php
- app/Services/Runtime/RuntimeConditions.php
- app/Services/Runtime/RuntimeEvents.php
- app/Services/Runtime/RuntimeProjection.php
- app/Services/Runtime/RuntimeVariables.php
- app/Services/Runtime/RuntimeTimers.php
- app/Services/Runtime/RuntimeRoles.php
- app/Services/Runtime/LocationVerification.php
- app/Services/Runtime/QRVerification.php
- app/Services/Runtime/ARVerification.php
- app/Services/Runtime/ScoreAction.php
- app/Services/Runtime/HintAction.php
- app/Services/Runtime/LiveChallengeTrigger.php
- app/Services/Runtime/UnavailableRuntimeDomains.php
- config/runtime.php
- database/factories/TeamScenarioInstanceFactory.php
- database/factories/TeamNodeInstanceFactory.php
- database/migrations/2026_10_08_234715_create_runtime_engine_tables.php
- tests/RuntimeFixtures.php
- tests/Feature/RuntimeContractTest.php
- tests/Feature/RuntimeEngineTest.php
- tests/Feature/RuntimeMechanicsTest.php
- tests/Feature/RuntimeSecurityTest.php
- tests/Feature/RuntimeDatabaseTest.php
- tests/Feature/RuntimeConcurrencyTest.php
- docs/RUNTIME.md
- docs/PHASE_1_6.md

Generated verification logs/XML under storage are evidence artifacts, not application source.

## 6. Files changed (11)

- app/Providers/AppServiceProvider.php
- app/Services/Games/SessionService.php
- app/Services/Games/GameEvents.php
- app/Services/Scenarios/NodeTypeRegistry.php
- routes/api.php
- routes/console.php
- docs/specs/03_openapi.yaml
- tests/Feature/TeamContractTest.php
- tests/Feature/GameSessionContractTest.php
- tests/Feature/ScenarioContractTest.php
- tests/Feature/ScenarioValidationTest.php

Upstream tests were updated only where their previous phase boundary prohibited runtime tables/routes/handlers. The Session route contract counts its own named routes; the new Runtime contract covers all eight Runtime routes. Existing tests were retained.

## 7. Migration added

2026_10_08_234715_create_runtime_engine_tables.php creates seven canonical tables: team_scenario_instances, team_node_instances, runtime_commands, runtime_events, runtime_variables, runtime_timers, player_role_assignments. Explicit FKs, public identifiers, state checks, unique contexts, partial indexes, immutable pins/history and PostgreSQL context/state triggers protect durable truth. No duplicate runtime_role_assignments table exists.

The higher-authority Database Specification uses team_node_instances. Attempts and contributions are retained in append-only canonical runtime events; no speculative attempt table is added.

## 8. Models added / changed

Seven new models match the seven tables: TeamScenarioInstance, TeamNodeInstance, RuntimeCommand, RuntimeEvent, RuntimeVariable, RuntimeTimer and PlayerRoleAssignment. Runtime aggregate/node factories build useful pinned execution fixtures. Existing domain models remain unchanged.

## 9. Runtime services / handlers / policies

RuntimeEngine coordinates commands, locks, guards and lifecycle; supporting services own initialization, handlers, conditions, variables, timers, roles, events and safe projections. NodeTypeRegistry supplies handler bindings. Existing GameScope and tenant/session policy checks are reused; controllers contain no scenario-specific gameplay decisions.

Baseline handlers: Start, Finish, Message, Branch, Condition, Wait, Timer, Puzzle, Password, Text Answer, Multiple Choice, Assign Roles, Swap Roles, Specific Player, Random Player, Team Together and explicit Split Team parallel flow.

## 10. Runtime bootstrap integration

Only RuntimeBootstrap's production binding becomes real. RuntimeInitializer runs in Session Start's transaction, validates every real published pin, creates one isolated runtime per registration, persists the seed, eagerly creates nodes/default variables, starts timing and executes Start. Retries preserve the original runtime; partial initialization rolls back the whole start. BillingGate, ScenarioAssignmentReadiness, PricingSnapshotProvider and CancellationResolution remain fail-closed.

## 11. API added / changed

Exactly eight frozen paths are implemented and specified:

- GET /api/v1/game-sessions/{session_id}/runtime
- GET /api/v1/game-sessions/{session_id}/teams/{team_id}/runtime
- GET /api/v1/game-sessions/{session_id}/players/me/runtime
- POST /api/v1/runtime/nodes/{node_instance_id}/start
- POST /api/v1/runtime/nodes/{node_instance_id}/submit
- POST /api/v1/runtime/nodes/{node_instance_id}/complete
- POST /api/v1/game-sessions/{session_id}/teams/{team_id}/pause
- POST /api/v1/game-sessions/{session_id}/teams/{team_id}/resume

Strict evidence schemas reject client-owned completion, next-node, score, variables and clock fields. Mutations require Idempotency-Key. Player node operations/self context require bound X-Device-Id. Operational commands without frozen routes remain service-only.

## 12. TeamScenarioInstance lifecycle

NOT_STARTED -> ACTIVE; ACTIVE <-> PAUSED; validated Finish -> COMPLETED; Session closure cancels unresolved nonterminal instances. Terminal runtimes remain terminal. FAILED is reserved for a future frozen Scenario failure-outcome contract, not made a generic command. Runtime Finish finalizes its Session Team/participants with canonical events and does not automatically finish the whole Session.

## 13. NodeInstance lifecycle

LOCKED -> AVAILABLE -> ACTIVE -> COMPLETED. Supported Timer failure produces FAILED. Authorized reasoned skip allows AVAILABLE/ACTIVE -> SKIPPED, except Finish. Completion is evidence-driven and server-owned. Unique instance/template pairs and optimistic versions supplement locking. Start/control nodes execute only inside the engine.

## 14. Command model / idempotency

Durable command records retain request/hash, actor/device context, result and RECEIVED/PROCESSING/SUCCEEDED/REJECTED/FAILED status. Client and system idempotency namespaces are separate. Same request replays the original logical result; changed input/context conflicts. An outer transaction retains command outcome and an inner savepoint rolls back failed gameplay/events/outbox. Session locks serialize mutable guards, role/timer changes, pause and progression. Technical failure does not leave partial success.

## 15. Branching / parallel nodes

Pinned outgoing edges use typed server conditions, conditional-before-fallback selection and deterministic priority/key ordering. Branch selects one path; explicit Split Team enables multiple paths. AVAILABLE/ACTIVE node rows are authoritative. Finish waits for unresolved revealed parallel flow. Locked unchosen branch content stays hidden.

## 16. Variables / randomness

Separate typed values initialize SCENARIO/TEAM/PLAYER/NODE contexts. Writes require trusted transactional server code, writable template permission and matching scope/type. HMAC-SHA256(seed, stable context) drives reproducible selection and role tie-breaking. Random Player selection is persisted for recovery. No client arbitrary mutation API exists.

## 17. Timers

PostgreSQL timestamps, duration, accumulated pauses and state drive elapsed time. Session and Team holds compose without losing independent pauses. Explicit pre-existing CONTINUE timers retain wall-clock time while paused progression remains blocked. The per-minute expiry command uses locks, retained outcomes, bounded exponential retries and an exhaustion marker. Duplicate delivery cannot repeat terminal effects.

## 18. Roles / contributions

player_role_assignments retains stage context and release history. At most four active primary slots are enforced in PostgreSQL; other participants are Support. Rotation uses least-recently-used history and deterministic ties; release/reassignment preserves history. Captaincy adds no capability. Distinct attributable Team Together contributions are append-only, idempotent and completion-safe under contention.

## 19. Reconnect / recovery

Disconnect changes participation without resetting runtime or timers. Reconnect validates the existing binding and retrieves safe PostgreSQL state. Lost Redis/realtime delivery cannot lose progression. No silent Device rebind occurs. The frozen API provides reads; reconnect/disconnect mutation commands remain service-only because no canonical public routes are supplied.

## 20. External ports

LocationVerification, QRVerification, ARVerification, ScoreAction, HintAction and LiveChallengeTrigger exist. All six production adapters fail closed. Test adapters are deterministic. Ports receive a stable command ID and must participate through the caller transaction/outbox; no irreversible inline side effects or later-domain implementation is added.

## 21. Tenant / authorization / Device security

Participating Players access only their own Team's safe runtime. Assigned GameMasters and tenant administrators use canonical operational checks; cross-tenant/other-Team access is denied. Device ownership, revocation, frozen participation and current binding are checked. Player projections exclude correct answers, hidden conditions/variables, future locked content and internal sequence IDs.

## 22. Events / audit / outbox

Canonical events use public context, actor, version, correlation and UTC timing. Runtime timeline, state, audit and outbox commit atomically. Append-only submission events reconstruct attempts/contributions. Failed commands roll back success events. Existing outbox deduplication remains; no speculative external consumer is added. Delivery is at-least-once and future consumers must deduplicate event_id.

## 23. Tests

Baseline: 370 passed, 8053 assertions. Phase 1.6 adds 69 test cases including eight real two-connection PostgreSQL contention scenarios: completion, barrier, pause/submit, duplicate command, conflicting payload, role assignment, bootstrap retry and Finish. Contenders hit row locks, then retry after commit and re-check guards; this is deterministic lock-contention coverage, not a throughput/load benchmark.

Final suite: **439 passed, 0 failed, 0 errors, 0 skipped; 8421 assertions**. Duration: 567.47 seconds. Command: php artisan test --compact --log-junit storage/phase16-tests.xml, executed in PHP 8.4 through Docker with the workspace mounted and PHPUnit forcing urban_ar_test.

- Other: 3 passed, 3 assertions, 0 failures.
- Phase 0: 4 passed, 21 assertions, 0 failures.
- Phase 1.1: 27 passed, 3243 assertions, 0 failures.
- Phase 1.2: 85 passed, 521 assertions, 0 failures.
- Phase 1.3: 87 passed, 3503 assertions, 0 failures.
- Phase 1.4: 84 passed, 501 assertions, 0 failures.
- Phase 1.5: 80 passed, 343 assertions, 0 failures.
- Phase 1.6: 69 passed, 286 assertions, 0 failures.

## 24. Verification

- composer validate --strict: PASS.
- vendor/bin/pint --test: PASS, 225 files.
- Required dirty formatting was attempted; Git is absent, so vendor/bin/pint --format agent was used across the workspace successfully before the final check.
- php artisan migrate:fresh --force --no-interaction: PASS on urban_ar_test only.
- Runtime migration rollback --step=1 / reapply: PASS on urban_ar_test.
- Application database: normal forward migration only; runtime migration applied successfully.
- Docker build app/worker/scheduler: PASS. App image bee845c2fd309013ba7a70acd644085e9b056fe069ace05aefc7e26c819e7c54.
- Rebuilt app/worker/scheduler/nginx running; PostgreSQL/Redis healthy.
- HTTP live/ready/health: PASS, all HTTP 200 with status ok, worker ok and X-Request-Id preserved after the existing Redis queue regression.
- Full Phase 0, 1.1, 1.2, 1.3, 1.4 and 1.5 regressions: PASS (exact per-phase counts above).
- Static analysis: not configured; no dependency/tool added.

Evidence: storage/phase16-baseline-tests.xml, storage/phase16-tests.xml, storage/phase16-tests.log, storage/phase16-migrations.log, storage/phase16-docker-build.log, storage/phase16-health.json.

## 25. Known limitations

- Production Session Start still requires the previously unavailable Billing/Scenario Assignment/Pricing domain adapters; this phase deliberately preserves those gates.
- Advanced Subflow execution, advanced Split Team subtasks and media handlers remain outside the allowed limited baseline handler set; unsupported execution fails closed.
- Generic Team FAILED outcomes cannot be enabled until the frozen Scenario contract defines support; Timer node failure is implemented.
- Reconnect, skip and role mutation transports are service-only where no frozen public API exists.
- Timer expiry dispatch has scheduler granularity of up to one minute; server-side evidence validation checks the current deadline immediately.
- Idle workers may show an unknown heartbeat after the pre-existing 120-second job-processing heartbeat expires. Verification uses the existing real Redis queue smoke regression; no health semantics were weakened.
- No later-domain consumers, advanced realtime transport or load benchmark are introduced.

## 26. Contract deviations

NONE. Higher-authority canonical team_node_instances naming, existing player_role_assignments scope, exact event names and frozen public routes take precedence over conceptual shorthand. Small operational extension columns are documented in RUNTIME.md; no alternate truth store or role model is introduced.

## 27. Next recommended phase

Phase 2.0 - GPS / Location / Checkpoints. Not implemented or started in this change.

See [RUNTIME.md](RUNTIME.md) for runtime operations and implementation semantics.
## Existing implementation reverified - 2026-10-09

This execution found Phase 1.6 already implemented. No application source, migration, dependency or frozen specification was changed. Git remains unavailable.

Fresh verification: composer validate --strict PASS; php vendor/bin/pint --test PASS; full php artisan test --compact --log-junit storage/phase16-reverification-tests.xml PASS: 439 tests, 8421 assertions, zero failures/errors/skips (533.88 seconds). Runtime subset: 69 tests, 286 assertions, including eight PostgreSQL contention cases. All Phase 0 through 1.5 regression groups passed with the totals recorded above.

On urban_ar_test only: migrate:fresh --force --no-interaction PASS; migrate:rollback --step=1 --force --no-interaction PASS; migrate --force --no-interaction PASS. The application database was not refreshed or rolled back. Docker build app worker scheduler PASS; rebuilt services started successfully.

Final consecutive queue/HTTP verification: Phase0RegressionTest PASS (1 test, 13 assertions); live, ready and health all HTTP 200/status ok, including worker ok and preserved request correlation. The heartbeat expires after 120 idle seconds, so standalone later health checks can show worker unknown. Evidence: storage/phase16-reverification-tests.xml, storage/phase16-reverification-queue.xml, storage/phase16-reverification-health.json.

Status: COMPLETE / VERIFIED. Contract deviations: NONE. Existing documented limitations remain. Phase 2.0 was not started.
