# QR Trigger Verification Contract

The QR domain has three append-only/history-preserving entities: `qr_triggers`, `trigger_tokens`, and `qr_scan_events`. Trigger lifecycle is `ACTIVE`, `INACTIVE`, or `REVOKED`; revocation does not delete history.

The scan endpoint performs server-side context resolution and consumes Location truth from Phase 2.0 `geofence_evaluations`; it does not recalculate geofences. Successful verification issues an opaque one-time token. Runtime later validates and atomically consumes the token with row locking and context checks. Reuse emits `qr.token_replay_detected.v1` and never completes Runtime.

Canonical events are `qr.scanned.v1`, `qr.scan_rejected.v1`, `qr.trigger_resolved.v1`, `qr.token_issued.v1`, `qr.token_consumed.v1`, and `qr.token_replay_detected.v1`. Raw identifiers, identifier hashes, raw tokens, token hashes, answers, hidden graph state, and future nodes are never returned or logged.

## HOME VERIFICATION PENDING

The following checks remain intentionally deferred to the canonical Docker/PostgreSQL environment: the full PostgreSQL suite, `migrate:fresh`, QR migration rollback/reapply, duplicate-scan and token-contention tests, scan versus Runtime completion and Trigger revoke races, Docker build, live/ready/health endpoints, queue smoke, and Phase 0–2.0 regressions.
