# NEXO Management Release Candidate

## Identity and source state

- Candidate: `nexo-management-2026.10.15-rc1`
- Candidate commit: not created; this manifest records the reviewed working tree only.
- Local base: `74b553918c04e386d1963ed7605c0014d5302f7c` (`main`).
- Production source reported for review: `253952f0dedf978240c1d30140c8495515b13b38`.
- `HEAD` is four commits ahead of the reported production SHA. `origin/main` is `05950ae3b8075808fa7af40e58362f2afeca47be`, three commits ahead of production and one commit behind local `HEAD`.
- The 43 pre-manifest working-tree paths are all feature implementation, relevant regression coverage, or the approved management addendum. No environment file, secret, generated build output, test data, or unrelated path is included.
- Freeze source specifications remain unchanged. The new management authorization addendum is additive and does not revise the frozen mobile, game, runtime, or identity contracts.

## Verification recorded

- Backend Laravel suite: 664 passed, 0 failed, 9,769 assertions.
- Frontend suite: 96 passed, 0 failed (result supplied as confirmed for this phase).
- `GameJourneyApiTest`: passed; isolated run 1 test and 47 assertions.
- Outbox and Phase 0 targeted tests: 3 passed, 27 assertions.
- Test services: isolated PostgreSQL 16.4/PostGIS 3.4.3 and Redis 7, bound to loopback. No production service was changed.

## Change inventory for the proposed release commit

### Backend API and domain (16 paths)

- `app/Http/Controllers/Api/AdminManagementController.php`
- `app/Http/Controllers/Api/OrganizationController.php`
- `app/Http/Controllers/Api/PlayerManagementController.php`
- `app/Http/Requests/AdminManagementRequest.php`
- `app/Http/Requests/OrganizationRequest.php`
- `app/Models/GameSessionTeam.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Management/ManagementService.php`
- `app/Services/Organizations/GlobalRoleService.php`
- `app/Services/Organizations/OrganizationInput.php`
- `app/Services/Organizations/OrganizationService.php`
- `app/Services/Scoring/HintService.php`
- `app/Services/Scoring/LeaderboardService.php`
- `app/Services/Teams/TeamService.php`
- `routes/api.php`

### Control Center (21 paths)

- `control-center/app/control/admin/admins/page.tsx`
- `control-center/app/control/admin/members/page.tsx`
- `control-center/app/control/admin/org-admins/page.tsx`
- `control-center/app/control/admin/page.tsx`
- `control-center/app/control/admin/roles/page.tsx`
- `control-center/app/control/admin/users/[id]/page.tsx`
- `control-center/app/control/admin/users/page.tsx`
- `control-center/app/control/players/[id]/page.tsx`
- `control-center/app/control/players/page.tsx`
- `control-center/app/control/teams/[id]/page.tsx`
- `control-center/app/control/teams/page.tsx`
- `control-center/components/app-shell.tsx`
- `control-center/components/organization-members.tsx`
- `control-center/components/team-management.tsx`
- `control-center/lib/api-client.ts`
- `control-center/lib/management-api.ts`
- `control-center/lib/organization-members-api.ts`
- `control-center/lib/queries.ts`
- `control-center/lib/team-management-api.ts`
- `control-center/tests/organization-members.test.tsx`
- `control-center/tests/team-management.test.tsx`

### Regression coverage (4 paths)

- `tests/Feature/GameJourneyApiTest.php`
- `tests/Feature/ManagementApiTest.php`
- `tests/Feature/ManagementConcurrencyTest.php`
- `tests/Feature/TeamServiceTest.php`

### Contract and migration safety (2 paths)

- `docs/specs/10_Management_API_Authorization_Addendum_v1_1.md`
- `database/migrations/2026_09_18_000003_enable_postgis.php`

The PostGIS migration is historical and already exists at the reported production source SHA. Its `up()` behavior is unchanged (`CREATE EXTENSION IF NOT EXISTS postgis`); the candidate changes `down()` to a no-op so an application rollback cannot drop PostGIS with `CASCADE`. This is not a new schema migration and does not require a production migration when the existing migration row is present. Keep this safety change in the candidate; never use `migrate:rollback` as the release rollback procedure. Verify the production migration row before release. If it is unexpectedly absent, stop and separately review extension ownership/privileges before running migrations.

## Runtime compatibility

- Dockerfile production PHP base is `php:8.4-fpm-bookworm`; Composer requires PHP `^8.3`, so PHP 8.4 is compatible.
- The production image installs `pdo_pgsql`, `pcntl`, `opcache`, and `zip`. Composer production requirements pass without `ext-intl`; application source has no `Number::format` call. The local `artisan db:show` diagnostic did fail because that Laravel command requires `intl`; operational tooling that uses that command must add the extension or use another verified check.
- Composer requires `predis/predis` outside `require-dev`, so it is included by the Dockerfile's `composer install --no-dev`. The image does not install the PHP `redis` extension and Laravel config defaults to `phpredis`; the production environment must explicitly set `REDIS_CLIENT=predis`. Confirm that value in the server-only environment without printing its contents before deployment.
- The production Compose database image is `postgis/postgis:16-3.4`, which supplies PostgreSQL/PostGIS. No PHP PostGIS extension is required by this application.
- No locally tagged `nexo-app:production` image was available for inspection. These findings validate the Dockerfile recipe, not the digest or extension list of the image currently running on the server.

## Deployment and rollback plan

1. Before authorization to deploy, verify the running image digest, server-only `REDIS_CLIENT=predis`, PostgreSQL migration row, free disk space, healthy database/Redis/queue, and the server's current Compose file.
2. Preserve the server's operational Compose changes verbatim, including the currently audited bind `0.0.0.0:18100` and its reverse-proxy arrangement. Do not replace it with the repository Compose file. Record a protected copy and checksum of the exact server Compose file. Changing the bind to `127.0.0.1:18100` is a proposed future hardening change only and requires separate explicit approval after confirming the reverse proxy's network path.
3. Take a provider-consistent PostgreSQL backup and a filesystem/volume snapshot as applicable. Verify the backup by restoring it to an isolated temporary database and checking PostGIS plus representative game, team, membership, score, and audit rows. Do not restore over production during verification.
4. Build and retain immutable backend, Control Center, and Nginx images from the eventual release commit. Record their digests and retain the current production image digests for rollback.
5. Deploy only after separate approval. Since this candidate adds no schema migration, do not run migrations unless the migration preflight shows an approved, specifically reviewed pending migration. Keep the PostGIS migration applied; do not roll it back.
6. Smoke-test HTTPS health/readiness, login over HTTPS only, capability-scoped admin navigation, global user status/audit, organization membership changes, player scope, a game-session journey, leaderboard, hints, queue processing, Redis, and Nginx/PostgreSQL health. Then test the game with four physical phones.
7. Roll back by restoring the prior immutable application/control images and the exact pre-release server Compose file while preserving the existing `0.0.0.0:18100` bind and reverse proxy. Do not run destructive/down migrations or flush Redis. Restore PostgreSQL only if data integrity requires it and after a separately approved recovery decision.

## Proposed commit

- Suggested message: `feat(management): add scoped administration and control center`
- Proposed contents: exactly the 43 paths in the inventory above, plus this manifest as the 44th path.
- Expected parent: `74b553918c04e386d1963ed7605c0014d5302f7c`.
- Commit SHA is pending; no commit or push has been made.

## Remaining release gates

- Verify `REDIS_CLIENT=predis` and the exact production image digest/configuration without exposing secrets.
- Verify the production PostGIS migration row and complete an independently verified backup/restore drill.
- Obtain explicit deployment approval; deployment and four-phone smoke testing have not occurred.
