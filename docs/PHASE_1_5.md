# NEXO — Phase 1.5 Scenario Core & Versioning

## Status and workspace

Status: **COMPLETE / VERIFIED**. Final verification: 2026-10-09 (Asia/Tehran).
Workspace: C:\Users\HadNegaran\Desktop\ganj.
Laravel 13.32.0; PHP 8.4; PHPUnit 12.5.35.
Mandatory Scenario Core & Versioning Domain Specification v1.md: present and read.
Spec root: docs/specs. Canonical OpenAPI: docs/specs/03_openapi.yaml.
Starting Git status/SHA: unavailable; no repository initialized.

## Implementation

Eleven canonical models/tables establish Scenario identity, immutable versions, normalized graph definitions, typed conditions/variables, exact Subflow pinning, validation history and structural simulation history. Fourteen canonical API operations use existing organization scoping, policies, audit, outbox and idempotency services.

Draft graph/config edits rotate revision_token. Publish requires current expected revision, latest PASS against the same revision, and current dependency validation. Published content is protected in the application and PostgreSQL. Duplicate allocates a new version under lock and preserves source history. Archive is service-only because no canonical public route exists.

Production Game Session dependency adapters are unchanged and remain fail closed. No Runtime execution, Scenario assignment, Billing, GPS, QR, AR, scoring, Store, live challenge execution, anti-cheat or Visual Builder UI is implemented.

See [SCENARIOS.md](SCENARIOS.md) for schema decisions, API behavior, validation rules, permissions, dependency contracts and deferred responsibilities.

## Files added (41)

- app/Models/Scenario.php
- app/Models/ScenarioVersion.php
- app/Models/ScenarioNode.php
- app/Models/ScenarioEdge.php
- app/Models/ScenarioCondition.php
- app/Models/ScenarioVariable.php
- app/Models/ScenarioSubflow.php
- app/Models/ScenarioSubflowVersion.php
- app/Models/ScenarioSubflowBinding.php
- app/Models/ScenarioValidationRun.php
- app/Models/ScenarioSimulationRun.php
- database/factories/ScenarioFactory.php
- database/factories/ScenarioVersionFactory.php
- database/factories/ScenarioNodeFactory.php
- database/factories/ScenarioEdgeFactory.php
- database/factories/ScenarioConditionFactory.php
- database/factories/ScenarioVariableFactory.php
- database/factories/ScenarioSubflowFactory.php
- database/factories/ScenarioSubflowVersionFactory.php
- database/factories/ScenarioSubflowBindingFactory.php
- database/factories/ScenarioValidationRunFactory.php
- database/factories/ScenarioSimulationRunFactory.php
- app/Services/Scenarios/ScenarioScope.php
- app/Services/Scenarios/ScenarioInput.php
- app/Services/Scenarios/ScenarioEvents.php
- app/Services/Scenarios/ScenarioService.php
- app/Services/Scenarios/ScenarioGraph.php
- app/Services/Scenarios/GraphValidator.php
- app/Services/Scenarios/NodeTypeRegistry.php
- app/Policies/ScenarioPolicy.php
- app/Http/Controllers/Api/ScenarioController.php
- database/migrations/2026_10_08_225127_create_scenario_core_tables.php
- database/seeders/ScenarioSeeder.php
- tests/Feature/ScenarioApiTest.php
- tests/Feature/ScenarioValidationTest.php
- tests/Feature/ScenarioDatabaseTest.php
- tests/Feature/ScenarioConcurrencyTest.php
- tests/Feature/ScenarioContractTest.php
- tests/ScenarioFixtures.php
- docs/PHASE_1_5.md
- docs/SCENARIOS.md

## Files changed (4)

- app/Providers/AppServiceProvider.php — register ScenarioPolicy.
- routes/api.php — fourteen canonical Scenario operations.
- docs/specs/03_openapi.yaml — concrete Scenario schemas and operation semantics.
- tests/Feature/GameSessionContractTest.php — allow the newly authorized Scenario phase while preserving later-domain exclusions.

## Migration and models

Migration: 2026_10_08_225127_create_scenario_core_tables.php.
Tables: scenarios, scenario_versions, scenario_nodes, scenario_edges, scenario_conditions, scenario_variables, scenario_subflows, scenario_subflow_versions, scenario_subflow_bindings, scenario_validation_runs, scenario_simulation_runs.

Constraints include opaque public UUID uniqueness, version/key uniqueness, same-version node foreign keys, typed/status checks, append-only run history, published graph/version immutability and same-tenant Subflow bindings. Foreign keys preserve historical references. No runtime tables are introduced.

## Verification evidence

- composer validate --strict: PASS.
- php artisan test: **370 passed; 8,053 assertions; 0 failures; 0 errors; 0 skipped**. Final duration 330.36 seconds. Evidence: storage/phase15-tests.xml and storage/phase15-tests.log.
- vendor/bin/pint --test: PASS, 189 PHP files. Git-dependent --dirty was attempted and unavailable; direct formatting was used.
- php artisan migrate:fresh --force: PASS, all 11 migrations on urban_ar_test.
- migrate:rollback --step=1 followed by migrate: PASS on urban_ar_test. Evidence: storage/phase15-migrations.log.
- Docker build: PASS for app, worker and scheduler. Evidence: storage/phase15-docker-build.log.
- Final app image: sha256:3fde4909ea045a1249f50b24e07903e704cedf802171fd0870f025745d9af783.
- Local app, worker, scheduler, nginx, PostgreSQL and Redis: running. Application environment verified as local / urban_ar; Scenario migration applied forward as batch 6. No application-database fresh/rollback operation was performed.
- Live route inspection: all 14 canonical Scenario operations present.
- HTTP http://localhost:18000/api/v1/live, /ready and /health: all 200, status ok. Database, PostGIS, Redis, queue and worker checks ok. Request correlation: phase15-final-verification. Evidence: storage/phase15-health.json.
- Static analysis: not configured; no new dependency introduced.

Full-suite phase groups (all passed):

- Phase 0 foundation: 4 tests / 21 assertions, including the real queue regression.
- Phase 1.1 identity/device: 27 tests / 3,201 assertions.
- Phase 1.2 organization/tenant: 85 tests / 521 assertions.
- Phase 1.3 Team Management: 87 tests / 3,461 assertions.
- Phase 1.4 Game/Session: 84 tests / 502 assertions, including production fail-closed dependency guards.
- Phase 1.5 Scenario Core: 80 tests / 344 assertions.
- Other baseline tests: 3 tests / 3 assertions.

Scenario-specific breakdown: API/security 16 tests / 121 assertions; concurrency 7 / 20; contract 1 / 85; database 13 / 13; graph/config/typed-value/dependency validation 43 / 105.

Identity and Team contract assertion counts include the expanded canonical OpenAPI checks. The prior Game/Session contract test removes only its obsolete exclusion of the newly authorized Scenario routes.

## Known limitations and scope

Simulation is structural only and declares runtime_executed=false. Required external media/GPS/QR/AR/live-challenge resources fail publication closed until real validators exist. Runtime owns actual seed creation and deterministic execution. Controlled loops need a frozen termination/config contract; current cycles are conservatively rejected. Subflow pinning is implemented without a new public authoring API. GameMaster source grants remain unavailable until a canonical Scenario assignment/content authorization path exists.

The current execution request and Dependency Map final addendum put Scenario Core before Runtime; historical Master phase numbers remain preserved. No frozen business, API, schema or event contract is replaced.

Contract deviations: **NONE**.

Next recommended phase: **Phase 1.6 — Runtime Engine**, per this execution request. Not implemented.
