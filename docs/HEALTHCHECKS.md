# Health checks

GET /api/v1/live is process liveness and does not inspect dependencies.

GET /api/v1/ready checks PostgreSQL, PostGIS, Redis, and the Redis-backed queue connection. It returns HTTP 503 when a required dependency or queue backend is unavailable. It does not claim a worker is available solely because Redis is reachable.

GET /api/v1/health returns the stable Phase 0 response shape. The queue check means the queue backend is reachable. The worker check is ok only when a queue worker has processed a job and emitted a heartbeat within the last 120 seconds; otherwise it is unknown and health is degraded. This is intentionally conservative and avoids inventing a false worker-health signal.
