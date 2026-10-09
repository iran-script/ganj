# NEXO — Phase 1.3 Team Management

## Phase and status

Phase: 1.3 — Team Management.
Status: COMPLETE / VERIFIED. Final verification: 2026-10-09 (Asia/Tehran).
Workspace: C:\Users\HadNegaran\Desktop\ganj.
Laravel: 13.32.0. Runtime image: PHP 8.4.
Spec root: docs/specs. OpenAPI: docs/specs/03_openapi.yaml.
Starting Git status/SHA: unavailable; workspace is not a Git repository. Git was not initialized.

All four restored NEXO references were verified and read. The latest user execution instruction supersedes historical Phase 0/Phase 1.2 status and coding-gate notes. Canonical precedence was applied. The Phase 1.2 checkpoint is docs/PHASE_1_2.md.

## Implementation

Persistent Teams, active memberships, membership history, secure invites, join/leave/remove, captain transfer, safe update, archive, derived eligibility, tenant scoping, policy enforcement, idempotency, audit and outbox are implemented. The twelve canonical endpoints are wired. No Phase 1.4 implementation exists.

See [TEAMS.md](TEAMS.md) for the API, authorization, service-only organization creation, errors, concurrency strategy, idempotency behavior and future boundaries.

## Files added

- app/Models/Team.php
- app/Models/TeamMember.php
- app/Models/TeamInvite.php
- app/Policies/TeamPolicy.php
- app/Services/Teams/TeamService.php
- app/Services/Teams/TeamScope.php
- app/Services/Teams/TeamInput.php
- app/Services/Teams/TeamEvents.php
- app/Http/Controllers/Api/TeamController.php
- app/Http/Requests/TeamRequest.php
- database/factories/TeamFactory.php
- database/factories/TeamMemberFactory.php
- database/factories/TeamInviteFactory.php
- database/seeders/TeamSeeder.php
- database/migrations/2026_10_08_164623_create_team_management_tables.php
- tests/Feature/TeamApiTest.php
- tests/Feature/TeamServiceTest.php
- tests/Feature/TeamDatabaseTest.php
- tests/Feature/TeamConcurrencyTest.php
- tests/Feature/TeamContractTest.php
- docs/TEAMS.md
- docs/PHASE_1_3.md

## Files changed

- routes/api.php — twelve authenticated Team routes.
- app/Providers/AppServiceProvider.php — TeamPolicy registration.
- docs/specs/03_openapi.yaml — Team stubs clarified with request/response schemas, public UUIDs, pagination, idempotency and canonical HTTP statuses. Unrelated operations were not changed.

Generated artifacts: storage/phase13-tests.xml and the normal PHPUnit result cache.

No dependencies, existing tests, upstream business-domain implementations or frozen business specs were modified. The four restored specification documents were user-provided inputs, not agent-authored changes.

## Migration and models

One migration adds canonical teams, team_members, team_invites and team_member_events. Explicit foreign keys cover available User/Team/Organization references. Partial ACTIVE membership uniqueness, public-ID/hash uniqueness, lifecycle/period/max-use checks, append-only history and deferred captain membership checks are enforced in PostgreSQL.

Models: Team, TeamMember and TeamInvite. Member events use append-only relational inserts in TeamEvents. Factories create valid records; TeamFactory commits captain and membership together. TeamSeeder is explicit and local/testing only.

## Membership, captaincy and invite security

ACTIVE Teams exist with 1–8 members. Eligibility requires ACTIVE status and 4–8 active members. Leave/remove preserve terminal membership periods, and rejoin creates a new period. Captain is derived, must remain an ACTIVE member, cannot leave/remove self, and transfers only to an ACTIVE same-Team member.

Invites use 256-bit random secrets; only SHA-256 hashes are stored. Secret appears only in the create response. Expiry, revocation, max-use and duplicate/capacity checks run inside the transaction. Actor/organization/Team/invite locking serializes mutations and prevents capacity/use overruns.

Seven two-connection race tests exercise concurrent joins at capacity, final invite use, duplicate join, transfer/leave, transfer/remove, transfer/transfer and revoke/join. Each verifies blocking and the loser rechecking committed state.

## Tenant, authorization, events and delivery

Personal creation always has null organization association. Arbitrary ownership injection is rejected. Organization-associated creation is service-only through a freshly authorized OrganizationContext; Phase 1.2 OrganizationScope is reused. Cross-tenant access is hidden with 404. Captain-only actions, GameMaster denials, OrganizationAdmin scope and audited SuperAdmin support overrides are tested.

All nine canonical Team events are recorded in the outbox transactionally with audit. Membership/captain events additionally append team_member_events. Sensitive overrides require a reason. Events and audit retain public resource context and request_id. Audit failure rolls back the domain change and outbox.

## Tests

Full PostgreSQL suite: 206 tests passed, 6,925 assertions, 0 failures, 0 errors, 0 skipped.

- Phase 1.3: 87 tests, 3,320 assertions.
- Phase 1.2: 85 tests, 521 assertions.
- Phase 1.1: 27 tests, 3,060 assertions.
- Phase 0 foundation/regression: 4 tests, 21 assertions.
- Remaining existing smoke/unit tests: 3 tests, 3 assertions.

The existing Identity contract test counts OpenAPI references across the specification; its assertion total changes with clarified Team schemas even though the test and Identity behavior are unchanged.

Full command (PowerShell):

~~~powershell
docker compose run --rm --no-deps -v "${PWD}:/var/www/html" -e DB_CONNECTION=pgsql -e DB_DATABASE=urban_ar_test -e CACHE_STORE=array -e APP_ENV=testing --entrypoint php app artisan test --compact --log-junit storage/phase13-tests.xml
~~~

Artisan test omits --no-interaction because the existing test wrapper forwards that flag to PHPUnit. Other Artisan commands use --no-interaction.

## Verification

- composer validate --strict: PASS.
- php vendor/bin/pint --dirty --format agent: unavailable without Git. Explicit changed-source-path formatting applied successfully.
- php vendor/bin/pint --test: PASS.
- php artisan migrate:fresh --force --no-interaction: PASS on urban_ar_test, all nine migrations.
- php artisan migrate:rollback --step=1 --force --no-interaction: PASS on urban_ar_test.
- php artisan migrate --force --no-interaction after rollback: PASS on urban_ar_test.
- docker compose build: PASS for app, worker and scheduler.
- docker compose up -d: PASS.
- Application database: upstream batches 1–3 preserved; only Team migration applied as batch 4.
- route:list --path=api/v1/teams: exactly 12 operations.
- OpenAPI parse, local references, route and schema checks: PASS.
- Phase 0, 1.1 and 1.2 regressions: PASS.
- Static analysis: no existing configured PHPStan/Psalm stack; none added.
- Live /api/v1/live, /api/v1/ready and /api/v1/health: HTTP 200, data.status=ok, X-Request-Id=phase13-live-verification. Database, PostGIS, Redis, queue and worker checks were all ok after a genuine Redis queue smoke test.
- Rebuilt-image Phase0RegressionTest: 1 passed, 13 assertions; an additional smoke run, separate from the 206-test full-suite total.
- Live unauthenticated /api/v1/teams: HTTP 401.
- Named application container verified directly: APP_ENV=local, DB_DATABASE=urban_ar, PHP 8.4.26. Upstream migration batches 1–3 intact; Team migration batch 4.

Destructive migration commands used only urban_ar_test. The application database was not refreshed or rolled back.

## Known limitations and scope decisions

- Media is outside this phase. The canonical nullable avatar_media_id column is constrained to null until Media supplies valid references and its FK; non-null API values are rejected.
- Organization ownership is service-only; no extra public ownership API was invented.
- External outbox publication is unchanged from the foundation. This phase guarantees atomic persistence, not new delivery workers/consumers.
- Invite creation does not accept Idempotency-Key so a raw secret is not retained for replay; create Team, join, transfer and other supported mutations use the existing idempotency foundation.
- The existing worker health indicator expires after 120 seconds without job activity. An idle worker can report unknown/degraded even when its process is running.
- No Session guard/runtime/roster implementation is added. Future Session snapshots remain a separate domain.

## Contract deviations

NONE. Implementation clarifies intentionally generic Team OpenAPI stubs using the frozen API/domain contracts. HTTP 201 for creation and 204 for no-body operations follow API Contract section 5. Canonical member_count is retained. No alternate tables, event aliases, role models or routes were introduced.

## Next recommended phase

Phase 1.4 — Game & Game Session. Not started.

Final review caught and corrected YAML formatting that changed empty Bearer scope lists from [] to {}. TeamContractTest now verifies the actual YAML sequence type; the final full suite passed after that correction. The API document contains no competing alternate paths.

During overlapping Compose operations, a service-level exec reported the test environment. Final container recreation and direct inspection of ganj-app-1 verified the normal application environment and untouched upstream migration batches. Run test-container verification and application recreation sequentially with the installed Compose v2.13.0.

All required Phase 1.3 work is complete. Phase 1.4 was not started.