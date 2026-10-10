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
