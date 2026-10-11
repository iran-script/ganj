# NEXO Phase 2.6 — Reconnect / Offline-Lite / Field Hardening

Status: **PARTIAL — OFFICE DEVELOPMENT COMPLETE / HOME VERIFICATION PENDING**

Phase 2.6 adds a bounded, server-authoritative resilience layer to the Player client. It does not introduce offline gameplay authority, offline QR verification, or a durable offline completion path.

## Operation policy

| Operation | Policy |
| --- | --- |
| GPS sample | `OFFLINE_QUEUEABLE`; replayed in session sequence order |
| Safe challenge acknowledgement | `OFFLINE_QUEUEABLE` only when the API contract permits it |
| Puzzle/text submission | `ONLINE_PREFERRED_WITH_QUEUE_FALLBACK` only when the active Node explicitly allows delayed validation |
| Runtime, score, leaderboard reads | `CACHEABLE_READ` with safe projection cache |
| QR verification and token issue/consume | `ONLINE_REQUIRED` |
| Session transitions, device rebind, score adjustment, hints/entitlements, GameMaster commands | `ONLINE_REQUIRED` |

## Mobile persistence

The app uses one SQLite database through `sqflite`. The durable outbox stores a stable `client_action_id`, action type, session/team/node context, payload, sequence, retry metadata, status, and last error. Safe Runtime projections are stored separately and never include hidden graph data, unrevealed answers, or another Player's private projection.

The same action ID is retained after a timeout and is sent as `Idempotency-Key` where the backend supports it. Retries use bounded exponential backoff with jitter. Terminal conflicts are retained for diagnostics and are not retried forever.

## Reconnect and reconciliation

On resume/reconnect the client refreshes the authoritative projection, replays only safe queued actions, and refreshes the projection again. The server wins every conflict. A queued action never locally completes a Node, changes score, consumes an entitlement, or selects a branch.

The client exposes `ONLINE`, `RECONNECTING`, `OFFLINE`, `SYNCING`, `ACTION_PENDING`, and `SYNC_ERROR` states. API refresh remains the fallback when realtime is disconnected; Phase 2.6 does not make a websocket the source of truth.

## Field hardening

- GPS records foreground samples, preserves sequence, and reports a pending upload rather than a local verdict.
- QR scanner rejects offline verification and never mints a token locally; camera lifecycle remains safe across scan completion and network failure.
- Puzzle submissions show `PENDING_VERIFICATION` only for an explicitly delayed-validation Node policy.
- Authenticated users may view safe cached content while offline. Pending actions remain bound to the original account/session/device; logout requires an explicit discard decision.
- Device binding, session state, role policy, score, hint, and challenge completion remain server-authoritative.

## Deferred home verification

1. Phase 2.1–2.5 final backend verification and regressions.
2. Flutter dependency resolution, `flutter analyze`, and `flutter test`.
3. `migrate:fresh`, PostgreSQL full regression, and Docker build.
4. Redis, worker, realtime disconnect/reconnect, and server restart recovery smoke tests.
5. Physical-device offline GPS, app kill/restart outbox, QR online-required, and delayed puzzle replay tests.
6. Server-state reconciliation and the complete Phase 0–2.5 regression suite.

No Phase 3.0 work is included.

## Final home verification — 2026-10-11

Status: **COMPLETE — PHYSICAL PILOT SMOKE PENDING**. The office checklist above is historical.

Canonical backend: 555 passed, 0 failures, 0 errors, 0 skipped; 9,108 assertions. Flutter: 31 passed; clean, pub get, format, analyze and debug APK build passed. Automated tests cover account/device/session/team/player cache isolation, logout isolation, replay stop conditions, action expiry, stale Node rejection and stable action IDs. SQLite ownership migration and sequence allocation were reviewed; the available cache/replay tests use in-memory fakes rather than a native SQLite process restart.

**PHYSICAL SMOKE PENDING:** real app kill/restart SQLite persistence, offline GPS and delayed puzzle replay after reconnect, physical QR online-only behavior, server-state reconciliation, and device/account switching in the pilot. No native integration_test infrastructure is present. No physical smoke completion is claimed.

The historical PostgreSQL log records a backend process terminated by signal 13 (Broken pipe) followed by recovery. Its initiating cause is unknown. Current PostgreSQL remained healthy through the complete suite: **NOT REPRODUCED DURING FINAL VERIFICATION**. Logs and JUnit/XML evidence remain local and ignored.

Outbox publication ends at the internal Redis Stream; no downstream consumer is implemented. Redis Stream deduplication was verified, not end-to-end downstream consumption. Generated iOS files, local databases, credentials, signing material, build/APK output and machine-local Gradle init configuration are excluded from the repair commit. No Phase 3.0 work is included.
