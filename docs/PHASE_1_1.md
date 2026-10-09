# Phase 1.1 — Identity & Player Device

Scope: identity, Bearer authentication, safe self-profile updates, registered devices and reusable device context. No Phase 1.2+ business features are implemented.

## Implementation

Seven API operations are implemented under `/api/v1`: register, login, logout, GET/PATCH me, GET devices and DELETE devices/{device_id}. Public user/device `id` fields contain UUIDs. Database sequential identifiers remain internal. See [AUTHENTICATION.md](AUTHENTICATION.md) and [DEVICES.md](DEVICES.md).

PostgreSQL migrations rename the existing password column without discarding hashes, backfill UUIDs, add mobile/status/last-login fields, and enforce unique lower(email), unique mobile, an identifier-presence CHECK and a status CHECK. Existing framework email_verified_at and remember_token columns are retained to avoid destructive migration. Case-colliding legacy email data causes migration failure rather than silent account merging. The device/token migration adds ownership FKs, device uniqueness and hashed-token uniqueness. Rolling back identity alignment retains nullable email because mobile-only users cannot safely be converted to mandatory email.

Identity mutations write the frozen event envelope and audit in the same transaction. Registered event types are user.registered.v1, user.logged_in.v1, user.logged_out.v1, device.registered.v1 and device.revoked.v1. Profile identifier changes use the audit action user.identifiers_updated; this is not a new domain event. No status-changing API or user.status_changed.v1 event is introduced. Events use public actor/resource IDs, server UTC timestamps and request_id; audit uses the existing internal actor FK convention. Existing Outbox delivery infrastructure is unchanged.

## Verification commands

From the application root in PowerShell, start the existing dependencies and create a dedicated test database once:

```powershell
docker compose up -d postgres redis
docker compose exec -T postgres createdb -U urban_ar urban_ar_test
composer validate --strict
php vendor/bin/pint --test
docker compose run --rm --no-deps -v "${PWD}:/var/www/html" -e DB_CONNECTION=pgsql -e DB_DATABASE=urban_ar_test -e CACHE_STORE=array -e APP_ENV=testing --entrypoint php app artisan test --compact
docker compose run --rm --no-deps -v "${PWD}:/var/www/html" -e DB_CONNECTION=pgsql -e DB_DATABASE=urban_ar_test -e APP_ENV=testing --entrypoint php app artisan migrate:fresh --force
docker compose build
docker compose up -d
```

`migrate:fresh` is destructive: the command above deliberately targets only urban_ar_test. The normal application database receives additive migrations through the existing container startup command. PHPUnit uses PostgreSQL and forces isolated array-cache settings so authentication rate-limit tests do not use shared production counters.

Coverage includes API/security failures, database constraints, rollback on outbox failure, token persistence/revocation, event envelope references, request IDs, actual Redis queue processing and the unchanged Phase 0 tests. Static analysis is not configured.

## Contract clarifications

API Contract v1 section 14 requires registration HTTP 201; the initial OpenAPI /auth/register stub incorrectly declared 200. It is corrected to 201. Logout and device revocation use the API Contract section 5 allowed 204 response. Phase 1.1 paths/schemas now describe concrete request and response fields; unrelated routes remain unchanged.

The execution prompt explicitly defers session bind/rebind business logic despite the broader Blueprint section 30 wording. RequireDevice and DeviceResolver provide the reusable identity boundary only. A valid device header does not prove session binding or physical device attestation.

Registration Idempotency-Key response replay is not implemented (recommended, not mandatory in API Contract section 14). Unique constraints and transactions prevent duplicate users/devices on retry; duplicates return 422, and clients may log in to obtain a new token. Previously issued token secrets are never retrieved or replayed.

Current policy denies SUSPENDED and BLOCKED accounts at login and on all protected identity routes. No administrative status mutation, organizations, teams, billing or gameplay flow is added. PLAYER is a baseline self-profile role summary, not a stored or client-editable role assignment.

## Executed verification results

Verified 2026-09-26 (Asia/Tehran):

- `composer validate --strict`: PASS.
- `php vendor/bin/pint --test`: PASS after the final PHP edit.
- `php artisan test --compact`, through the documented Docker command on PostgreSQL: **34 passed, 0 failed, 3,105 assertions**. This includes the unchanged Phase 0 tests plus the added real queue/health regression test.
- `php artisan migrate:fresh --force`, with DB_DATABASE=urban_ar_test: PASS, all seven migrations.
- `docker compose build`: PASS; final source rebuilt after the SQL-log redaction change.
- `docker compose up -d`: PASS; app, nginx, PostgreSQL/PostGIS, Redis, worker and scheduler running.
- Running application `php artisan migrate:status`: all seven migrations applied; Phase 1.1 additions are batch 2, preserving Phase 0 batch 1.
- Live HTTP `/api/v1/live`, `/api/v1/ready`, `/api/v1/health`: all 200, all retain X-Request-Id=phase11-live-verification. Database, PostGIS, Redis, queue and worker report ok after dispatching the existing Phase0SmokeJob to the running worker.

Final SQL exception reporting on identity routes logs only request_id and SQLSTATE, preventing password/token hashes in query bindings from reaching logs. A negative test covers this failure path.

The workspace has no Git repository, so there is no Git diff/commit to deliver. No static analysis tool is configured. No Phase 1.2 implementation was added.
