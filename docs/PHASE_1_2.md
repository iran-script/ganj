# Phase 1.2 — Organizations & Tenant Baseline

Workspace: C:\Users\HadNegaran\Desktop\ganj. Product: NEXO; historical internal name: Urban AR Team Adventure. Git repository: unavailable. Git was not initialized.

Implemented: canonical organizations, organization_members, organization_settings and minimal global role_assignments; seven authenticated organization APIs; policy-driven SuperAdmin/OrganizationAdmin/GameMaster boundaries; reusable server-side query scoping; UUID resources and cursor pagination; membership history; service-only role changes/archive; canonical events; transactional audit/outbox integration; PostgreSQL constraints and concurrency regression coverage.

See [Organizations](ORGANIZATIONS.md) for API behavior, operator bootstrap, settings scope, idempotency behavior, permissions, migration/recovery, and delivery limitations.

## Contract interpretation

The current execution prompt and Master Implementation Specification section 6 govern the phase number. Product Blueprint section 36 governs tenant business behavior; its older Phase 1.6 label and section 31's Team Management Phase 1.2 label do not change this task's scope. RTK's historical Phase 0 current-scope note is superseded by the current execution instruction. Frozen business documents remain untouched.

Database member roles retain GAMEMASTER even though the permission matrix calls the capability GAME_MASTER. OpenAPI generic organization request/response stubs are clarified without adding paths. POST uses 201 and DELETE uses 204 per API Contract section 5. No archive or role-change public endpoint is invented.

Contract deviations: NONE.

## Verification

Verified 2026-10-08 (Asia/Tehran):

- composer validate --strict: PASS.
- php vendor/bin/pint --dirty --format agent: unavailable because Git is absent. Explicit source-path formatting was applied instead; php vendor/bin/pint --test: PASS.
- Full PostgreSQL suite: 119 passed, 0 failed, 0 skipped; 3,617 assertions. Machine-readable result: storage/phase12-tests.xml.
- Phase 1.2: 85 tests, 521 assertions across API, database, service, contract and two-connection concurrency tests.
- Phase 1.1: 27 tests, 3,072 assertions including registration/login/logout/me, device and token ownership/revocation, status denial and contract checks.
- Phase 0 foundation/regression: 4 tests, 21 assertions, including real Redis queue processing, health, idempotency and outbox persistence.
- The remaining 3 existing smoke/unit tests also pass.
- migrate:fresh --force --no-interaction: PASS on urban_ar_test, all eight migrations.
- migrate:rollback --step=1 --force --no-interaction followed by migrate --force --no-interaction: PASS on urban_ar_test.
- docker compose build: PASS for app, worker and scheduler. docker compose up -d: PASS; all six services running, PostgreSQL and Redis healthy.
- Existing application database migration status: old Phase 0 batch 1 and Phase 1.1 batch 2 retained; only the new tenant migration applied as batch 3.
- route:list --path=api/v1/organizations: exactly seven operations; no Phase 1.3 API added.
- OpenAPI parses successfully; local references resolve, route/method/schema checks pass, and unrelated paths/identity schemas remain unchanged.
- No static-analysis stack is configured or added.

Full test command (PowerShell):

~~~powershell
docker compose run --rm --no-deps -v "${PWD}:/var/www/html" -e DB_CONNECTION=pgsql -e DB_DATABASE=urban_ar_test -e CACHE_STORE=array -e APP_ENV=testing --entrypoint php app artisan test --compact --log-junit storage/phase12-tests.xml
~~~

The same container prefix with APP_ENV=testing and DB_DATABASE=urban_ar_test was used for the migration fresh/rollback/forward commands. Never substitute the application database for urban_ar_test when running destructive migration verification.

Rebuilt-image smoke command:

~~~powershell
docker compose run --rm --no-deps -v "${PWD}:/var/www/html" -e DB_CONNECTION=pgsql -e DB_DATABASE=urban_ar_test -e CACHE_STORE=array -e APP_ENV=testing --entrypoint php app artisan test --compact --filter=Phase0RegressionTest
~~~

Result: 1 passed, 0 failed, 13 assertions. The test executes a real Redis queue job using its test worker. The running app/worker/scheduler use the rebuilt image; PHP reports 8.4.26 in the live HTTP stack.

Live HTTP /api/v1/live, /api/v1/ready and /api/v1/health all returned 200 and data.status=ok. Ready/health reported database, postgis, redis, queue and worker=ok. All three retained X-Request-Id=phase12-live-verification. The unauthenticated live organization endpoint returned 401 AUTHENTICATION_REQUIRED with the standard envelope.

The existing worker health indicator is activity-based and expires after 120 seconds without a processed job. Consequently an idle healthy process may later report worker=unknown and health.status=degraded; readiness still reports dependency availability. This behavior predates Phase 1.2 and was not changed.

The initial failing checks uncovered second-precision timestamp test expectations, a PostgreSQL trigger function surviving migrate:fresh, and JSONB key reordering in a test assertion. All were corrected and verified by the final full suite. Artisan test forwards a trailing --no-interaction to PHPUnit, which does not accept it; the successful noninteractive test commands above omit that flag. Other Artisan commands use --no-interaction.

## Files added

- app/Console/Commands/BootstrapSuperAdmin.php
- app/Enums/OrganizationMemberStatus.php
- app/Enums/OrganizationRole.php
- app/Enums/OrganizationStatus.php
- app/Http/Controllers/Api/OrganizationController.php
- app/Http/Requests/OrganizationRequest.php
- app/Models/Organization.php
- app/Models/OrganizationMember.php
- app/Models/OrganizationSetting.php
- app/Models/RoleAssignment.php
- app/Policies/OrganizationPolicy.php
- app/Services/Organizations/GlobalRoleService.php
- app/Services/Organizations/OrganizationContext.php
- app/Services/Organizations/OrganizationEvents.php
- app/Services/Organizations/OrganizationInput.php
- app/Services/Organizations/OrganizationScope.php
- app/Services/Organizations/OrganizationService.php
- database/factories/OrganizationFactory.php
- database/factories/OrganizationMemberFactory.php
- database/factories/OrganizationSettingFactory.php
- database/factories/RoleAssignmentFactory.php
- database/migrations/2026_10_08_114505_create_organization_tenant_tables.php
- database/seeders/OrganizationSettingsSeeder.php
- docs/ORGANIZATIONS.md
- docs/PHASE_1_2.md
- tests/Feature/OrganizationApiTest.php
- tests/Feature/OrganizationConcurrencyTest.php
- tests/Feature/OrganizationContractTest.php
- tests/Feature/OrganizationDatabaseTest.php
- tests/Feature/OrganizationServiceTest.php

## Files changed

- app/Providers/AppServiceProvider.php
- app/Services/Audit/AuditService.php
- bootstrap/app.php
- routes/api.php
- docs/specs/03_openapi.yaml
- README.md

Generated verification artifacts: storage/phase12-tests.xml and the normal .phpunit.result.cache. No dependency manifests, frozen business specifications, Docker service names or existing Phase 0/1.1 tests were changed.

## Completion and limitations

Status: COMPLETE for Phase 1.2. Archive and membership-role changes are service-only because the canonical API has no corresponding paths. Settings are a storage baseline. The pre-existing /me PLAYER summary is unchanged. Existing outbox records are persisted transactionally; no new external publisher or consumers are introduced, and external delivery is not claimed. All implemented organization event production, audit atomicity, tenant authorization and concurrency checks pass.

## Scope boundary

Phase 1.3 is not implemented. Next recommended phase: Phase 1.3 — Team Management.
