# Offline, Resilience & Recovery

## Authority boundary

Offline-Lite is a transport and presentation feature. PostgreSQL-backed APIs remain authoritative for Runtime state, QR, scoring, hints, entitlements, session transitions, roles, and Live Challenge completion. The mobile app may display a safe cached projection, but it cannot manufacture success.

## Durable outbox

The Flutter app stores queueable actions in `nexo_player.db` using `sqflite`. Each row has a stable UUID `client_action_id`, monotonic per-session sequence, action context, JSON payload, timestamps, retry metadata, and one of:

`PENDING`, `SENDING`, `ACKNOWLEDGED`, `REJECTED`, `FAILED_RETRYABLE`, `FAILED_TERMINAL`.

The queue survives process restart. Acknowledged and terminal rows are pruned only after retention. Old terminal sessions are not replayed. One logical action keeps the same idempotency key across network failures.

## Retry and conflicts

Retry uses exponential backoff plus bounded jitter. Network errors remain retryable until the bounded retry policy is exhausted. Canonical conflicts such as `NODE_ALREADY_COMPLETED`, `SESSION_PAUSED`, `DEVICE_NOT_BOUND`, and `EXPIRED_ACTION` become terminal diagnostics and trigger an authoritative refresh. The client never silently turns a rejected action into success.

## Safe cache

Only visible Runtime content, revealed mission material, safe role/support state, allowed barrier progress, visible timer basis, and allowed score/leaderboard projections may be cached. Hidden future graph, hidden answers, unrevealed branches, raw bearer tokens, and other Player secrets are excluded.

## GPS and QR

GPS samples can be queued and replayed in sequence through the existing Location API. The backend still decides freshness, ordering, accuracy, checkpoint membership, and Runtime consequences; the client does not duplicate geofence logic. QR remains `ONLINE_REQUIRED`: an offline scan can only tell the Player that connectivity is required and cannot issue or consume a token.

## Lifecycle and recovery

Resume/reconnect refreshes auth/device/session context, fetches the authoritative Runtime projection, replays safe actions, fetches the projection again, and restores realtime subscriptions where available. API polling/refetch remains a valid fallback. If a token expires offline, safe cached content remains available and the queue waits for reauthentication. Logout warns before discarding meaningful pending actions and never uploads them under another account.

## Home verification pending

The office implementation is intentionally unverified against Docker/PostgreSQL. Home must run Flutter dependency resolution/tests, PostgreSQL migrations and regression tests, Redis/worker/realtime outage checks, physical-device GPS and QR checks, app restart outbox recovery, delayed puzzle replay, and server-wins reconciliation before Phase 2.6 can be marked complete.
