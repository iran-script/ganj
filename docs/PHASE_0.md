# Phase 0 Foundation

Implemented: Laravel bootstrap, PostgreSQL/PostGIS migration, Redis cache/queue configuration, queue and failed-job migrations, API versioning, request IDs, JSON structured errors/logging, idempotency storage, append-only audit storage, transactional outbox storage, private-channel-ready broadcasting configuration, and Docker services for app, nginx, PostgreSQL/PostGIS, Redis, worker, and scheduler.

The health endpoint reports database, PostGIS, Redis, and queue configuration. The readiness endpoint returns 503 when database, PostGIS, or Redis is unavailable. Queue readiness intentionally reports configured; worker liveness must be monitored by process/container health rather than inferred from Redis alone.
