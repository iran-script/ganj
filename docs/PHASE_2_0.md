# Phase 2.0 — GPS / Location / Checkpoints

## 1. Phase
Phase 2.0 — GPS / Location / Checkpoints only.

## 2. Status
COMPLETE / VERIFIED on 2026-10-10. Phase 2.0 implementation and all required automated verification gates passed.

## 3. Workspace verification
C:\Users\HadNegaran\Desktop\ganj. Canonical specifications are in docs/specs and OpenAPI is docs/specs/03_openapi.yaml. Laravel 13.32.0, PHPUnit 12.5.35 and Pint 1.32.1 were confirmed from installed packages. PostgreSQL/PostGIS runs through the existing Docker Compose stack. No dependencies were added. No .ai/rules directory or root RTK.md exists; docs/specs/RTK.md was used.

The default execution sandbox failed before creating processes. Authorized elevated execution was used for workspace reads, writes and verification.

## 4. Git status / SHA
Unavailable: this workspace is not a Git repository. No repository was initialized. File lists below are this task's inventory, not a Git diff.

## 5. Files added
- app/Console/Commands/MaintainLocationHistory.php
- app/Http/Controllers/Api/CheckpointController.php
- app/Http/Controllers/Api/LocationController.php
- app/Models/Checkpoint.php
- app/Services/Locations/CheckpointService.php
- app/Services/Locations/GeofenceVerifier.php
- app/Services/Locations/LocationEvents.php
- app/Services/Locations/LocationInput.php
- app/Services/Locations/LocationService.php
- app/Services/Locations/RuntimeLocationVerification.php
- config/location.php
- database/factories/CheckpointFactory.php
- database/migrations/2026_10_09_203508_create_location_checkpoint_tables.php
- database/seeders/CheckpointSeeder.php
- tests/LocationFixtures.php
- tests/Feature/CheckpointServiceTest.php
- tests/Feature/LocationConcurrencyTest.php
- tests/Feature/LocationContractTest.php
- tests/Feature/LocationDatabaseTest.php
- tests/Feature/LocationRetentionTest.php
- tests/Feature/LocationRuntimeTest.php
- tests/Feature/LocationSecurityTest.php
- tests/Feature/LocationServiceTest.php
- docs/GPS_LOCATION_CHECKPOINTS.md
- docs/PHASE_2_0.md

Generated logs and JUnit XML under storage are verification artifacts.

## 6. Files changed
- app/Models/GameSessionPlayer.php
- app/Providers/AppServiceProvider.php
- app/Services/Runtime/RuntimeProjection.php
- app/Services/Scenarios/GraphValidator.php
- app/Services/Scenarios/NodeTypeRegistry.php
- routes/api.php
- tests/Feature/TeamContractTest.php
- routes/console.php
- docs/specs/03_openapi.yaml

## 7. Migrations added
2026_10_09_203508_create_location_checkpoint_tables.php. Creates the five canonical GIS tables, monthly/default partitions, spatial and stream indexes, coordinate/type/radius constraints, Device/Session context validation, append-only update protection and retention-only deletion guards. Rollback removes only this phase's tables/functions.

## 8. Models added / changed
Checkpoint added with guarded attributes, public UUID, organization relation and factory. GameSessionPlayer gains its Session relation. High-volume observation/latest/evaluation persistence uses the query builder and parameterized SQL.

The explicit CheckpointSeeder provides an idempotent INACTIVE local/testing authoring example only. It is not part of the production DatabaseSeeder.

## 9. PostGIS / spatial schema
EPSG:4326 geometry; POINT and ZONE checkpoints; geography meter distance; ST_Covers zone boundary policy. GiST and Session/Player/time indexes are tested. location_events is partitioned by server_received_at.

## 10. Services / policies / verification engine
LocationInput strictly validates observations and policies. CheckpointService uses existing organization authorization. LocationService handles locked ingestion/replay/latest state. GeofenceVerifier incrementally persists accuracy-aware dwell evidence. RuntimeLocationVerification checks fresh team evidence and gameplay roles/capabilities.

## 11. API added / changed
Seven canonical operations: single ingest, batch ingest, own location policy, checkpoint create/list/read/update. OpenAPI was tightened for these operations without adding alternate routes. Revealed Runtime checkpoint data is projected safely through existing Runtime APIs.

## 12. Checkpoint model
Canonical organization-owned public_id/name/type/geom/radius_meters/config/status/timestamps. POINT requires a positive radius; ZONE uses Polygon/MultiPolygon and no radius. Published geometry/policy cannot be edited through content administration. Inactive/archived references fail closed.

## 13. Location ingest
Authenticated self-only observations; bound active Device; relevant active Session/Team/Player states; server UTC receipt; append-only geometry history. Precise raw history is not exposed.

## 14. Sequence / replay / offline
Per-Device/Session Player sequence and normalized payload hash dedupe. Conflicting retained sequence reuse returns IDEMPOTENCY_CONFLICT. Delayed/lower observations remain late history without replacing current location or progressing offline gameplay. Batches are bounded, sequence-ordered and atomic. Replay/retention defaults are documented in GPS_LOCATION_CHECKPOINTS.md.

## 15. Accuracy / classification
INSIDE, OUTSIDE and UNCERTAIN are calculated by PostGIS plus uncertainty bounds. Poor accuracy and boundary overlap cannot yield confirmed presence. Point and polygon boundary behavior is tested.

## 16. Dwell
Server receipt evidence chain, minimum sample count, duration and maximum gap. Exit, uncertainty, gaps and pause/resume reset continuity. Dwell survives model/process reload and cannot accrue solely through repeated verification.

## 17. Multi-player / role requirements
Distinct same-Team eligible Players only; stale, poor-quality, removed, disqualified, finished and invalid-device evidence excluded. Requirements use unreleased Runtime role assignments and the existing capability map. Captaincy is not a gameplay role.

## 18. Runtime LocationVerification integration
Only the Location production port becomes real. Location returns verification; Runtime retains completion, idempotency and progression authority. Safe revealed checkpoint projection includes public identifier, name, geometry and radius only. Hidden targets and admin policy stay private.

## 19. Tenant / Device / privacy security
Tenant ownership follows checkpoint/scenario/session context. User/Session/Device locks recheck binding and revocation. No silent rebind, client organization override, arbitrary Player context, raw export or competitor history endpoint.

## 20. Events / audit / outbox
Canonical geofence and checkpoint verification events, correlated transactional outbox, deduped batch events and accepted raw-sample metadata. Checkpoint privileged changes are audited; raw pings are not copied into human audit/general outbox. No manual override or punitive anti-cheat behavior is added.

## 21. Performance / indexes / retention
Durable latest projection; indexed stream lookup and bounded lateral latest-evaluation queries. Hourly partition/retention command with bounded purge and off-ingest route summaries. Default raw retention is 30 days. Detailed partition recovery and backlog caveats are in GPS_LOCATION_CHECKPOINTS.md.

## 22. Tests
Final full suite: **494 passed, 8,649 assertions, zero failures/errors/skips**. Artisan duration: 659.62 seconds. JUnit evidence: storage/phase20-verified.xml.

- Phase 0: 4 tests / 21 assertions, PASS.
- Phase 1.1: 27 tests / 3,264 assertions, PASS.
- Phase 1.2: 85 tests / 521 assertions, PASS.
- Phase 1.3: 87 tests / 3,524 assertions, PASS.
- Phase 1.4: 84 tests / 501 assertions, PASS.
- Phase 1.5: 80 tests / 343 assertions, PASS.
- Phase 1.6: 69 tests / 286 assertions, PASS.
- Phase 2.0: 55 tests / 186 assertions, PASS.
- Existing general/unit tests: 3 tests / 3 assertions, PASS.

A separate final existing queue/health smoke passed: 1 test / 13 assertions (storage/phase20-health-smoke.xml); this is a rerun, not an additional unique test.

The first complete verification caught an integral-radius JSON replay type mismatch and an obsolete no-location Phase 1.3 boundary assertion. Both were corrected, the focused recheck passed 11 tests / 3,139 assertions, and the full suite was rerun successfully. Earlier XML files remain historical verification artifacts; phase20-verified.xml is the authoritative final result.

## 23. Verification
- composer validate --strict: PASS.
- vendor/bin/pint --dirty --format agent: unavailable without Git; full vendor/bin/pint --format agent used successfully.
- vendor/bin/pint --test: PASS.
- migrate:fresh --force --no-interaction: PASS on newly created urban_ar_phase20_verify_20261010 only.
- Phase 2.0 rollback --step=1 / reapply: PASS on that isolated database.
- Application database: forward migration only; Phase 2.0 applied as batch 8.
- Docker app/worker/scheduler build: PASS. Final app image sha256:bac4409966999f55743583af38d9cab0aa595516fe861ad0c98adf63cc4b0d6f. Build evidence: storage/phase20-docker-build.log.
- HTTP live/ready: 200, database/PostGIS/Redis/queue healthy and request correlation preserved.
- HTTP health/live/ready: PASS, all 200/status ok immediately after the real queue smoke; database/PostGIS/Redis/queue/worker all ok. Request correlation preserved. Evidence: storage/phase20-health.json (2026-10-10 01:02:01 +03:30).
- Full Phase 0 through 1.6 regression: PASS; exact totals above.
- Static analysis: not configured; no tool/dependency added.

## 24. Known limitations
No Flutter app exists in this workspace: offline queue and tracking work here is the server contract. Automated contention coverage is not a throughput benchmark. Field GPS/battery/network validation remains pilot work. Raw export, live-map UI, full Anti-Cheat and later domain implementations are not added. Partition and retention backlog operations require monitoring. The existing Phase 0 aggregate health check reports an idle worker as unknown once its last processed-job heartbeat is over 120 seconds old; final health verification is performed immediately after the queue smoke test.

## 25. Contract deviations
NONE in the implemented Phase 2.0 API/domain scope. Configurable thresholds, batch atomicity and retention defaults are explicitly documented implementation choices.

Roadmap numbering is inconsistent in the frozen documentation: the Master specification still lists Scenario Core as Phase 3, while the supplied verified execution history completed it as Phase 1.5. This phase does not rewrite the frozen roadmap.

## 26. Next recommended phase
Follow the frozen Master order: its next listed item after GPS is Phase 3 — Scenario Core, then Phase 4 — Visual Scenario Builder Backend Contracts, then Phase 5 — QR Trigger Engine. Scenario Core is already reported complete as Phase 1.5. Reconcile the numbering and confirm the remaining Builder contract gate before advancing to QR. No later phase was implemented.
