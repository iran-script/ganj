# NEXO Games and Sessions — Phase 1.4

The canonical HTTP contract remains [specs/03_openapi.yaml](specs/03_openapi.yaml). This document describes the implementation, not a replacement frozen specification.

## Boundaries

Game is reusable and organization-owned. Game Session is one execution of that Game in the same organization. The seven canonical tables are games, game_sessions, game_session_teams, game_session_players, game_session_roster_snapshots, game_session_state_transitions and session_ready_events. No pricing, payment, Scenario, Runtime, GPS, QR, AR, scoring or Store tables/services are implemented.

Game starts DRAFT unless an authorized creation request selects ACTIVE. PATCH may activate DRAFT, edit safe profile fields, or leave status unchanged. ACTIVE cannot return to DRAFT. Archive is dedicated and preserves historical Sessions.

Session status is stored as the canonical database field **status**. Only SessionService commands change lifecycle state. FINISHED and CANCELLED are terminal. Configuration updates are pre-start only. All timestamps are server UTC; timezone is a separate display/scheduling setting. Registration close must follow open, and scheduled Start cannot precede registration close.

## Production dependencies deliberately fail closed

Five injectable ports live in app/Services/Games:

- BillingGate evaluates PASS / FAIL / PENDING for preparation, Player Ready, Start and frozen-roster late join. A Start PASS must include an immutable billable-participation reference for every roster User, including unready Players.
- ScenarioAssignmentReadiness returns a validated ScenarioVersion reference for every active Session Team.
- PricingSnapshotProvider checks registration pricing and freezes immutable pricing references at Start.
- RuntimeBootstrap receives immutable roster/scenario/pricing facts and the stable command ID start:{session public ID}. It must return one public runtime reference per Team.
- CancellationResolution resolves financial/runtime obligations with the stable cancel:{session public ID} command ID.

UnavailableSessionDependencies is the production binding for every port. Billing returns PENDING; pricing registration readiness is false; other unavailable operations throw canonical readiness errors. There is no configuration toggle that silently supplies PASS.

Consequently, opening registration requires a real pricing adapter, closing registration requires real Billing/Scenario adapters, Ready requires valid billing participation, and Start requires all Start dependencies. Cancelling a DRAFT with no registrations needs no financial resolution; cancellation with any registration history, or after Start, requires the resolution adapter.

Tests inject deterministic adapters from tests/GameSessionFixtures.php. Test runtime work writes an audit marker in the caller transaction, so rollback is verified using persistent effects. Those adapters are never production container bindings.

Future adapters MUST perform writes on the caller database connection/transaction, be retry-safe using the stable command ID, and must not perform irreversible external network side effects before commit. Use the existing transactional outbox for external delivery. This phase does not claim distributed atomicity for arbitrary external systems.

## Authorization and tenancy

Game creation accepts an organization public UUID and re-resolves it through OrganizationScope/OrganizationContext and policy authorization. Session creation accepts only a Game public UUID; ownership is derived from that authorized Game. PATCH cannot reassign ownership or directly set Session state.

Own OrganizationAdmin manages Games/Sessions. GameMaster requires an active organization membership plus a non-revoked game or game_session resource assignment in the existing role_assignments table. GameMasterAssignments is an audited, service-only assignment/revocation boundary. PostgreSQL checks that the resource belongs to the assignment organization. No second assignment framework or assignment HTTP route was introduced.

Global SuperAdmin permission is exceptional, reasoned and audited. Game administration is permitted; Session Start, pause/resume, device rebind and emergency cancellation are explicitly allowed. A global role alone does not grant Session create, finish or ordinary operations absent from the permission matrix. An independently held own-organization administrator membership retains its own capabilities. No GameMaster emergency capability is fabricated.

Captain can register/withdraw their Team and select its pre-start roster. Staff overrides require Session scope and a reason. A Team's organization may be null; a non-null organization must match the Session. Cross-tenant administrative resources return RESOURCE_NOT_FOUND. Ordinary Players can see active playable Games and available REGISTRATION Sessions, plus their own participation history. Operational users remain scoped to their organizations. Player projections omit other Teams' Players, device identifiers and operational reasons.

## Registration, roster and readiness

Registration copies 4–8 current active Team members into Session Player rows. The Session lock protects the 5-Team capacity. Unique constraints preserve one Team registration and one User identity per Session, including removed history. Removed participation is terminal and cannot be recreated to erase history. Persistent Team membership is unchanged.

SessionService syncRoster is an authorized, service-only pre-start operation accepting Team public ID and user_ids. No undocumented public roster route is added. It preserves removed rows and rejects additions that are not current active Team members. Before Start a roster can temporarily have fewer than four selected Players; prepare/Start reject that roster.

POST /ready accepts ready: true or false. It requires the active bound Device's token and X-Device-Id, plus active roster participation. Repeated unchanged readiness writes do not duplicate Ready records. Rebinding invalidates pre-start Ready state and records old/new device public references. A Team becomes READY at four valid READY Players; Start does not require Players five through eight to be ready.

Start rechecks every account and READY Device under row locks. It freezes all roster Players, including unready Players, but activates only READY Players. Existing frozen unready Players can use the service-only lateJoin boundary while RUNNING after valid Device/billing checks. There is no new late-join HTTP endpoint.

## Atomic Start and lifecycle

Mutations lock actor, Organization, Game and Session; roster operations then lock Team or roster accounts/devices. The Organization lock matches the upstream tenant mutation boundary and is intentionally conservative. Transactions retry PostgreSQL deadlocks up to three times.

Start validates READY, 1–5 Teams, 4–8 Players per Team, at least four READY Players with valid Devices, Billing PASS and every Scenario reference. Pricing references and immutable roster snapshots are frozen, RuntimeBootstrap runs transactionally, Players/Teams activate, then the RUNNING transition and canonical started event commit. Failure rolls back all Start effects; canonical start_failed records contain safe failure codes. Same-key retries return the stored logical result without repeating bootstrap/snapshots/success events; another key cannot start RUNNING again.

Required Idempotency-Key operations: Start, rebind, pause, resume, finish, cancel. Keys are actor-scoped; changed payload/action/resource yields IDEMPOTENCY_CONFLICT. Other mutations accept optional keys.

Session pause/resume changes the Session lifecycle and emits its integration events. It does not implement Runtime timer mechanics. Finish sets Session and participating Team/Player terminal states and emits finalization events for future consumers. It does not calculate scores, billing summaries or entitlements.

Post-start cancel requires explicit emergency: true, an OrganizationAdmin or exceptional SuperAdmin, mandatory reason, and successful cancellation resolution. No reopen or normal post-start Team removal exists.

## History, audit and outbox

Roster snapshots, state transitions and Ready events are append-only PostgreSQL records. Roster identity and frozen timestamps cannot be rewritten. Foreign keys preserve references; Game/Session composite ownership and Session/Team composite references prevent cross-resource association.

Canonical event taxonomy comes from the Event Catalog. All emitted domain events have audit records and transactional outbox entries. Profile configuration updates have audit action game_session.update; no undocumented game_session.updated event was invented. Resource role grants/revocations use the upstream role_assignment.granted / role_assignment.revoked audit action convention.

Timeline reads retained append-only audit events, not disposable outbox delivery state. It orders by occurred_at and event UUID with cursor pagination. Players receive lifecycle event names/timestamps only. Operations receive reason/request correlation. No payment, Scenario content, tokens or private Device data are exposed.

## Authority resolutions and deferred work

Database specification Domain C item 15 omits PAUSED from game_session_teams, while State Machine section 4 and the Game/Session domain specification section 16 describe team-specific pause. The higher-priority database enum is preserved. Whole-Session pause leaves individual Team/Player statuses intact; team-specific pause has no public route in this phase.

scenario_assignment_id is retained as a nullable canonical boundary column and constrained to NULL until its owning domain supplies the referenced table and foreign key. No orphan future-domain ID is accepted. Current frozen references live in the canonical snapshot JSON.

No next-phase implementation is included. The frozen Master sequence recommends Phase 1.5 — Billing & Payments next.
