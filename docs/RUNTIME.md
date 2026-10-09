# NEXO Runtime Engine

Phase 1.6 implements PostgreSQL-authoritative execution of pinned, published ScenarioVersions. Redis and realtime delivery are not required to recover gameplay.

## Persistence and authority

The Database Specification names the canonical node table **team_node_instances**. The execution prompt's NodeInstance concept maps to TeamNodeInstance and that table. No competing node_instances table is created.

Seven canonical tables persist current state and history: team_scenario_instances, team_node_instances, runtime_commands, runtime_events, runtime_variables, runtime_timers, and player_role_assignments. Existing role_assignments remains the authorization-role store. Runtime role history is separate from captaincy and organization membership.

Every Team registration has at most one immutable runtime pin and stable random seed. Nodes have unique instance/template pairs, optimistic version counters, explicit states and timestamps. PostgreSQL enforces same-version nodes, same-team scoped references, focus-pointer ownership, allowed states/transitions, four active primary slots, typed variable contexts, unique commands and append-only events.

Attempts and barrier contributions are reconstructable from append-only runtime.node_submission_received.v1 events. The canonical DB defines no separate attempt/contribution table. Attempt counters change in the same transaction. A partial unique index prevents two accepted contributions from the same Player to the same barrier. Duplicate contributions under a new command are recorded as DUPLICATE without increasing barrier progress.

pause_sources is a small operational extension recording independent Session/Team holds. It is not a progression blob. Runtime command request_hash/actor_id, timer public_id/finished_at and role public_id support idempotency, safe identifiers and retained timing.

## Bootstrap and lifecycle

RuntimeInitializer implements the Phase 1.4 RuntimeBootstrap port. It shares the caller's Session Start transaction, resolves every real published version in the Session tenant, creates all node instances eagerly, initializes typed variables per scope, starts the Team timer and automatically executes the Start/control path. Repeated bootstrap returns existing references without replacing the pin or seed.

Only RuntimeBootstrap's production binding changes. BillingGate, ScenarioAssignmentReadiness, PricingSnapshotProvider and CancellationResolution remain fail-closed. Successful gameplay tests provide deterministic adapters for those unimplemented dependencies and use real published ScenarioVersions.

Team lifecycle: NOT_STARTED → ACTIVE; ACTIVE ↔ PAUSED; ACTIVE → COMPLETED through validated Finish; nonterminal → CANCELLED on Session closure. FAILED is reserved for a frozen Scenario failure-outcome contract; the current Scenario schema has no such outcome configuration, so no generic team-failure shortcut is exposed.

Node lifecycle: LOCKED → AVAILABLE → ACTIVE → COMPLETED. Timer configuration may produce ACTIVE → FAILED. Authorized skip permits AVAILABLE/ACTIVE → SKIPPED, requires a reason and audit, and cannot bypass Finish validation. Terminal instances are not reopened.

Session Pause/Resume and closure invoke RuntimeEngine within the existing Session transaction. An operational Session Finish cancels unresolved Team runtimes; it does not fabricate successful scenario completion. Already terminal runtimes are preserved. A valid Runtime Finish finalizes that Session Team and its participants with canonical events, but never automatically finishes the whole Session. Authenticated completed participants can replay retained commands; new gameplay commands remain blocked by terminal runtime guards.

## Commands and concurrency

Player/service commands include start_node, submit_action, complete_node, skip_node, assign_role, release_role, pause_team, resume_team, disconnect_player and reconnect_player. Bootstrap, Session transitions and timer expiry also retain durable command records.

The pipeline authenticates/scopes the actor, locks the actor and existing Organization/Game/Session boundary, then locks the Team runtime and Node. Device ownership, revocation, frozen participation and current binding are checked. Operational commands require an assigned GameMaster or same-tenant administrator via existing GameScope authorization. Captaincy supplies no runtime capability.

Client idempotency keys are stored in a client-prefixed SHA-256 namespace; system command keys cannot be reserved by a Player. Canonical request hashes bind actor, Device, command, target and input. Same key/request returns the retained result. Changed payload/actor/Device returns IDEMPOTENCY_CONFLICT. Replay response object keys are normalized after PostgreSQL JSONB storage.

An outer transaction retains command outcome; an inner savepoint isolates gameplay mutation. Business failures become REJECTED. Technical failures roll back gameplay/events/outbox and become FAILED with a safe error. Evidence rejection retains its attempt and rejection history. Authentication/scope/device failures occur before command reservation.

All progression, timer and role mutations serialize with Session Pause/Resume. PostgreSQL two-connection tests verify lock contention and post-commit guard rechecks. Optimistic node counters supplement row locks.

## Registered handlers and graph execution

NodeTypeRegistry binds runtime handlers. Controllers contain no scenario-specific branching.

Baseline handlers cover Start, Finish, Message, Branch, Condition, Wait, Timer, Puzzle, Password, Text Answer, Multiple Choice, Assign Roles, Swap Roles, Specific Player, Random Player, Team Together and baseline Split Team parallel flow.

Start/control nodes execute server-side. Branch/Condition evaluates allow-listed typed conditions without eval. Conditions in one group are ANDed in declared position order. Eligible conditional edges take precedence over unconditional fallback; priority and edge_key break ties. Branch selects one path. Explicit Split Team flow unlocks multiple outgoing paths. AVAILABLE/ACTIVE rows, not current_node_instance_id, define the authoritative active set.

Finish rejects completion while other revealed AVAILABLE/ACTIVE flow remains. Unchosen locked branches are not mandatory active work. Advanced Split Team subtasks, Subflow execution and media handlers remain outside the limited baseline handler set; unsupported handlers fail closed.

Answers use only the configured whitespace/case normalization. Multiple Choice compares server-owned option references. Neither correct answers nor hidden conditions reach Player projections. complete accepts the same validated evidence flow as submit; completed/reached/score/current_node/next_node/arbitrary variables/client-clock fields are rejected.

## Variables and deterministic selection

RuntimeVariables initializes SCENARIO/TEAM defaults per Team instance, PLAYER defaults per frozen participant and NODE defaults per node instance. STRING, INTEGER, DECIMAL, BOOLEAN, DATETIME and extension JSON are checked against immutable template definitions. Trusted handler/system writes require a transaction, a writable definition and the exact scope. No public arbitrary-variable mutation API exists.

Gameplay randomness uses HMAC-SHA256 over the persisted seed and stable context. Random Player selection is stored in node extension state and revealed safely. Role rotation uses least-recently-used history with seeded deterministic tie-breaking. Future-domain condition operands such as scoring/location fail closed rather than inventing domain truth.

## Timers

Team and gameplay timers persist timestamps, accumulated paused seconds, duration and status. Server timestamps determine elapsed time. Nested Team/Session holds do not double-count pauses; Session Resume cannot clear an independent Team hold.

The pre-existing Scenario Timer pause_behavior=CONTINUE configuration denotes an explicit wall-clock timer. Its clock continues through pauses, but gameplay completion is still blocked until the Session and Team resume. Team effective gameplay time and normal PAUSE timers stop during holds.

runtime:expire-timers is scheduled each minute. It locks Session/Team/Node, executes a durable expiry command and atomically records completion/failure events. Duplicate invocations do not repeat transitions. Failures retain REJECTED/FAILED commands; exponential backoff and the configured attempt limit apply. Defaults: RUNTIME_TIMER_MAX_ATTEMPTS=3, RUNTIME_TIMER_RETRY_SECONDS=60. Exhausted attempts remain queryable with retry_exhausted=true for operational investigation.

The API reports authoritative elapsed time; clients may animate countdowns. A scheduler tick may deliver the expiry transition up to one minute after the deadline. Evidence submission also checks the authoritative timer immediately.

## Roles and cooperative progress

player_role_assignments retains stage/node context, Session Player, role, slot, assignment source and release time. At most four primary assignments are active for a Team; additional Players are Support. A new Assign/Swap Roles node rotates participants and preserves prior assignments. Least-recently-used selection prevents permanent exclusion of Players 5–8.

SCANNER, NAVIGATOR, DECODER and AR_OPERATOR map to SCAN_QR, NAVIGATE, DECODE and OPERATE_AR. Specific Player requires its configured role. When role assignments are active, corresponding puzzle/verification handlers enforce the role, including Support restrictions. Privileged assign/release commands require reasons and audit. A released slot can be reassigned.

Team Together counts distinct persisted Player contributions up to the frozen minimum. Safe projections expose durable contribution counts, so reconnect does not depend on missed WebSocket messages.

## Recovery, API and privacy

Canonical HTTP operations:

- GET /api/v1/game-sessions/{session_id}/runtime
- GET /api/v1/game-sessions/{session_id}/teams/{team_id}/runtime
- GET /api/v1/game-sessions/{session_id}/players/me/runtime
- POST /api/v1/runtime/nodes/{node_instance_id}/start
- POST /api/v1/runtime/nodes/{node_instance_id}/submit
- POST /api/v1/runtime/nodes/{node_instance_id}/complete
- POST /api/v1/game-sessions/{session_id}/teams/{team_id}/pause
- POST /api/v1/game-sessions/{session_id}/teams/{team_id}/resume

Mutation Idempotency-Key is required. Player node commands and self context require X-Device-Id. Runtime submission has an independent per-user rate limit. All public resource IDs are opaque UUIDs. Other-Team and cross-tenant references return not-found/denial.

Skip, role changes and disconnect/reconnect are service commands because the supplied frozen API defines no corresponding public routes. No competing endpoints are invented. A future transport calls these same command boundaries.

Disconnect changes participation only. Reconnect validates token context, frozen participation and the existing bound Device, then returns current PostgreSQL state. It never rebinds silently. Safe read projections include revealed nodes, attempts, contribution counts, runtime roles, selected Player context and timers, with no locked future content, hidden variables, expected answers, branch rules or internal sequence IDs.

## External boundaries and events

LocationVerification, QRVerification, ARVerification, ScoreAction, HintAction and LiveChallengeTrigger are explicit runtime ports. All production implementations throw a canonical business rejection until their domains exist. Ports must use the caller transaction/outbox and stable command ID, never irreversible inline network effects. Tests may inject deterministic adapters.

Canonical runtime events retain the standard envelope, public context, actor, version, UTC time and request correlation. State, runtime timeline, audit and outbox commit together. Template nodes have no public UUID in the canonical schema; their pinned node_key is the external node reference. Correct answer/evidence secrets are not copied into event payloads.

Outbox delivery remains at-least-once; downstream consumers must deduplicate event_id. This phase adds no speculative external-domain consumers or realtime truth store. Existing foundation outbox deduplication and queue health regressions remain applicable.

No GPS, QR, AR, Score, Hint commerce, Billing, Store, Live Challenge engine, Anti-Cheat, UI or analytics domain is implemented.
