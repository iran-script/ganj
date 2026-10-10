# Phase 2.1 — QR Trigger & Verification

QR values are opaque, cryptographically random trigger identifiers. The database stores only their SHA-256 hash; the raw value is returned only when an authorized Organization administrator creates a trigger. A physical QR never carries an answer, score, next-node pointer, or completion result.

`POST /api/v1/qr/scan` is authenticated, requires `X-Device-Id` and `Idempotency-Key`, and is `ONLINE_REQUIRED`. The server resolves the Session, roster Player, bound Device, Team Scenario Instance, active QR Node, trigger, Runtime role/specific-player policy, and optional recent Phase 2.0 geofence evidence. Clients cannot supply Team, Player, Node, organization, answer, score, or progression authority.

Successful scans return safe content plus a short-lived, Session/Team/Player/Device/Node/Trigger-bound one-time token. Only its hash is persisted. The existing Runtime QR adapter consumes that token atomically inside the Runtime command transaction; Runtime alone performs Node progression.

Scan history and the canonical QR outbox events are transactional. Unknown identifiers, wrong-stage scans, inactive/revoked triggers, wrong devices, stale/outside location evidence, role mismatches, and token replays are rejected with safe public errors. QR-specific rate limiting is separate from Runtime and Location limits.

Deferred boundaries remain fail-closed: AR, scoring, hints, live challenges, offline QR, and Co-op Roles are not implemented in this phase.

## HOME VERIFICATION PENDING

Office verification intentionally excludes Docker and PostgreSQL because the local machine cannot sustain the canonical database workload. Home verification must still run:

- PostgreSQL full suite and Phase 0–2.0 regressions
- `migrate:fresh`
- QR migration rollback and reapply
- PostgreSQL duplicate-scan and token-contention tests
- scan versus Runtime completion contention
- scan versus Trigger revoke contention
- canonical Docker build
- `/api/v1/live`
- `/api/v1/ready`
- `/api/v1/health`
- queue smoke
