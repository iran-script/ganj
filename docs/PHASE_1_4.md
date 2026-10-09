# NEXO — Phase 1.4 Game & Game Session

## Status and workspace

Status: **COMPLETE / VERIFIED**. Final verification: 2026-10-09 (Asia/Tehran).
Workspace: C:\Users\HadNegaran\Desktop\ganj.
Laravel: 13.32.0; PHP runtime: 8.4; PHPUnit: 12.5.
Specs: docs/specs. Canonical OpenAPI: docs/specs/03_openapi.yaml.
Starting Git status/SHA: unavailable; no repository was initialized.

The restored NEXO — Game & Game Session Domain Specification v1.md was verified and read before coding. Upstream checkpoints are docs/PHASE_1_2.md and docs/PHASE_1_3.md. Historical phase-number and coding-gate notes do not supersede this execution request.

## Delivered implementation

Seven persistent canonical entities support Game administration, Session configuration/lifecycle, organization ownership, Team registration, separate roster persistence, Device binding/rebinding, Ready/Unready, immutable roster snapshots and append-only transition/readiness history.

Twenty-three canonical HTTP operations are implemented. Strict request schemas and safe responses are documented in the existing OpenAPI. Roster synchronization, resource-scoped GameMaster assignment/revocation and frozen-roster late join are service-only boundaries, without invented routes.

Start is locked, transactional and idempotent. Production external dependencies fail closed. Deterministic test adapters verify successful Start and partial-failure rollback; no production-successful Billing, Scenario or Runtime implementation is claimed.

See [GAMES_AND_SESSIONS.md](GAMES_AND_SESSIONS.md) for API behavior, authorization, concurrency, dependency contracts and authority resolutions.

## Files added (45)

- app/Models/Game.php
- app/Models/GameSession.php
- app/Models/GameSessionTeam.php
- app/Models/GameSessionPlayer.php
- app/Models/GameSessionRosterSnapshot.php
- app/Models/GameSessionStateTransition.php
- app/Models/SessionReadyEvent.php
- app/Policies/GamePolicy.php
- app/Policies/GameSessionPolicy.php
- app/Services/Games/BillingGate.php
- app/Services/Games/CancellationResolution.php
- app/Services/Games/GameEvents.php
- app/Services/Games/GameInput.php
- app/Services/Games/GameMasterAssignments.php
- app/Services/Games/GameScope.php
- app/Services/Games/GameService.php
- app/Services/Games/PricingSnapshotProvider.php
- app/Services/Games/RuntimeBootstrap.php
- app/Services/Games/ScenarioAssignmentReadiness.php
- app/Services/Games/SessionDependencies.php
- app/Services/Games/SessionRoster.php
- app/Services/Games/SessionService.php
- app/Services/Games/UnavailableSessionDependencies.php
- app/Http/Controllers/Api/GameController.php
- app/Http/Controllers/Api/GameSessionController.php
- app/Http/Requests/GameRequest.php
- database/factories/GameFactory.php
- database/factories/GameSessionFactory.php
- database/factories/GameSessionTeamFactory.php
- database/factories/GameSessionPlayerFactory.php
- database/factories/GameSessionRosterSnapshotFactory.php
- database/factories/GameSessionStateTransitionFactory.php
- database/factories/SessionReadyEventFactory.php
- database/seeders/GameSeeder.php
- database/migrations/2026_10_08_212356_create_game_session_foundation_tables.php
- tests/GameSessionFixtures.php
- tests/Feature/GameApiTest.php
- tests/Feature/GameSessionApiTest.php
- tests/Feature/GameSessionConcurrencyTest.php
- tests/Feature/GameSessionContractTest.php
- tests/Feature/GameSessionDatabaseTest.php
- tests/Feature/GameSessionSecurityTest.php
- tests/Feature/GameSessionServiceTest.php
- docs/GAMES_AND_SESSIONS.md
- docs/PHASE_1_4.md

## Existing files changed (5)

- app/Providers/AppServiceProvider.php — policies and production fail-closed interface bindings.
- routes/api.php — canonical Game/Session routes.
- app/Services/Organizations/GlobalRoleService.php — global bootstrap checks global assignments, allowing pre-existing scoped GameMaster assignments without weakening global-role authorization.
- tests/Feature/TeamContractTest.php — the old prohibition on Game/Session routes is advanced to the current prohibition on Runtime routes; Team contract assertions remain.
- docs/specs/03_openapi.yaml — implementation schemas, responses, public UUID parameters and idempotency/device headers for existing canonical Game/Session operations only.

## Migration and models

One migration adds the seven canonical tables, FKs, unique/index/status constraints, Game/Session same-organization constraint, Session/Team roster consistency, append-only history and frozen identity guards. It extends existing role_assignments to scoped GAMEMASTER records with a database resource-organization guard. Global SUPER_ADMIN scope is unchanged.

All seven new models have factories. The explicitly invoked local/testing GameSeeder creates a DRAFT Game fixture; it does not supply future-domain readiness or auto-start Sessions.

## Events, audit and security

Canonical Game/Session, Session Team, Player Session and Device events are transactional with outbox and audit. Transition history is append-only. Timeline uses safe chronological retained audit projections. Failed Start emits failure rather than success; rollback leaves no snapshots, activation or test-runtime writes.

Authorization covers own organization, assigned GameMaster resources, derived Team Captain and Player self-service Device/Ready actions. Initial Device binding is self-only; staff rebind is reasoned and audited. Global administration is limited to actions expressly allowed by the Permission Matrix. Cross-tenant administrative lists, reads, mutations and direct scoped queries are tested.

## Tests and verification

Final full suite: **290 tests passed, 7,584 assertions, 0 failures, 0 errors, 0 skipped**. JUnit evidence: storage/phase14-tests.xml.

- Phase 1.4: 84 tests / 503 assertions, including 12 PostgreSQL two-connection race cases.
- Phase 0 foundation/queue/audit/outbox/idempotency: 4 tests / 21 assertions.
- Phase 1.1 Identity/Device: 27 tests / 3,138 assertions.
- Phase 1.2 Organization/Tenant: 85 tests / 521 assertions.
- Phase 1.3 Teams: 87 tests / 3,398 assertions.
- Other baseline tests: 3 tests / 3 assertions.

Verification:

- composer validate --strict: PASS.
- php artisan test --compact --log-junit storage/phase14-tests.xml: PASS.
- vendor/bin/pint --test: PASS, 150 PHP files.
- php artisan migrate:fresh --force --no-interaction: PASS, all 10 migrations, urban_ar_test only.
- php artisan migrate:rollback --step=1 --force --no-interaction: PASS.
- php artisan migrate --force --no-interaction: PASS on reapplication.
- docker compose build app worker scheduler: PASS. Build log: storage/phase14-docker-build.log.
- Application image: sha256:686d4f8bd3b478660776bbaa72e6e335e0743cf436cda3f5f8c83ae08808532b.
- php artisan route:list --path=api/v1/game --except-vendor: PASS, 23 routes.
- Local application forward migration: PASS, Phase 1.4 is batch 5; upstream batches 1–4 remain intact in urban_ar.
- Local stack: all six services running; PostgreSQL and Redis healthy. Named app container confirms DB_DATABASE=urban_ar and APP_ENV=local.
- Supplemental real-queue smoke: PASS, 1 test / 13 assertions; storage/phase14-queue-smoke.xml.
- GET /api/v1/live: HTTP 200, status ok.
- GET /api/v1/ready: HTTP 200, status ok; database/PostGIS/Redis/queue/worker all ok.
- GET /api/v1/health: HTTP 200, status ok; all checks ok.
- Final HTTP evidence and request correlation: storage/phase14-health.json, request ID phase14-final-verification.

The unchanged Phase 0 worker heartbeat expires after 120 idle seconds. An initial post-restart check reported worker unknown; the existing real-queue regression processed a job successfully, and all final HTTP checks then reported ok. No health check or heartbeat code was weakened.

Commands use the Docker PHP runtime. Tests and destructive migration checks explicitly select urban_ar_test with APP_ENV=testing and CACHE_STORE=array. The application database urban_ar is never refreshed or rolled back.

The configured Pint --dirty mode cannot operate without Git. Explicit path formatting and repository-wide Pint verification are used instead. No static-analysis tool is configured; no dependencies were added.

## Known limitations and contract deviations

Real Billing Gate, pricing, Scenario Assignment Readiness, Runtime Bootstrap and cancellation resolution remain deferred. Production readiness operations are deliberately blocked until real adapters exist. Pause/finish produce integration events but do not implement Runtime timers, scoring, entitlements or financial finalization.

The database-specified Session Team status enum is preserved; whole-Session pause leaves child statuses intact. Team-specific pause remains outside the current API. The nullable scenario_assignment_id boundary remains constrained to NULL until its owning schema and FK exist. The canonical snapshot JSON holds immutable external references.

Contract deviations: NONE. Authority-order resolutions are documented in GAMES_AND_SESSIONS.md; no alternate routes, tables, status aliases or role framework were introduced.

## Next phase

Phase 1.5 — Billing & Payments, following the frozen Master Implementation Specification. It has not been started.
