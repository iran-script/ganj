# Phase 2.5 — GameMaster Control Room / Live Challenges

Status: PARTIAL — OFFICE DEVELOPMENT COMPLETE / HOME VERIFICATION PENDING

This phase adds the backend Pilot control-room boundary and a simple parallel Message/Team Objective Live Challenge. GameMaster operations remain scoped through `GameScope`, use application services, emit outbox/audit records, and require idempotency keys for mutations.

## Implemented Pilot surface

- `GET /api/v1/game-sessions/{session_id}/control-room`
- Live Challenge create/list/read/ack/start/submit/cancel routes from the frozen contract
- `POST /api/v1/game-sessions/{session_id}/messages`
- Team pause/resume through the existing Runtime command boundary
- Player device rebind through the existing SessionService
- Player disqualification with role release and audit
- Message/Team Objective completion with optional server-side ScoringService reward

The control-room projection exposes session, team/player presence, device freshness, safe Runtime projections, active Challenges, score-visible data, and recent timeline events. It never returns raw credentials, tokens, hidden Scenario graph, or secret answers.

## Deferred boundaries

AR, Photo review, full Challenge Template editing, durable offline behavior, Commerce/Billing, Anti-Cheat enforcement, and a separate web admin frontend remain deferred. Runtime role swap, skip-node, hint, and score adjustment continue to use their existing Runtime/Scoring services and frozen endpoints; no duplicate orchestration engine is introduced.

## Persistence

Migration `2026_10_10_000003_create_live_challenge_tables.php` adds canonical `challenge_templates`, `live_challenges`, `live_challenge_targets`, `live_challenge_submissions`, and `session_messages`. It is intentionally unverified against PostgreSQL in Office Mode.

## Home verification pending

1. Phase 2.1–2.4 final verification first.
2. PostgreSQL migration fresh and rollback/reapply.
3. Live Challenge lifecycle, duplicate submission, cancel-vs-submit, expiry-vs-completion, and reward-once contention tests.
4. Docker build, `/live`, `/ready`, `/health`, queue and realtime smoke.
5. GameMaster control-room and end-to-end Live Challenge smoke.
6. Full Phase 0–2.4 regression suite.

## Final home verification — 2026-10-11

Status: **COMPLETE** for the implemented backend phase. The office checklist above is historical.

Canonical full suite: 555 passed / 9,108 assertions; zero failures, errors or skips. Live Challenge behavioral cases (4), GameMaster contracts (2), and duplicate/cancel/expiry contention (3) passed, including authorization/tenant boundaries, durable expiry and exactly-once reward. Live Challenge migration fresh and rollback/reapply passed on urban_ar_test.

Outbox durability/publication was exercised with scheduler and worker stopped, a committed synthetic event in a separate verification database, and a fresh dispatcher process. The pending row survived producer exit and was published. Simulating acknowledgement loss and reprocessing retained the same Redis Stream ID and unchanged entry count. Scheduler and worker were restarted. The two outbox retry/backoff/deduplication tests also passed. This implementation publishes to an internal Redis Stream; no downstream Stream consumer exists in this repository, so downstream consumption or player realtime delivery is not claimed.
