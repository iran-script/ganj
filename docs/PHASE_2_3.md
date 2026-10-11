# Phase 2.3 — Scoring / Hints / Leaderboard

Status: **PARTIAL — OFFICE DEVELOPMENT COMPLETE / HOME VERIFICATION PENDING**

## Office implementation

- Added append-only `score_events`.
- Added rebuildable `team_scores` projection.
- Connected the Runtime `ScoreAction` boundary to server-side scoring.
- Added completion, first-attempt, no-hint, time, and hint-penalty scoring boundaries.
- Added delta-only audited score adjustments.
- Added progressive built-in hint consumption for hints 1–3.
- Extra hints fail closed because the canonical Entitlement/Commerce domain is not implemented.
- Added deterministic leaderboard ranking and Team score/event reads.
- Reused Runtime transactions, locks, idempotency, Scenario pins, and outbox infrastructure.

## Boundaries

Score never marks Runtime Nodes complete. Runtime remains progression authority. Clients cannot set score, penalties, hint counts, elapsed time, rank, or completion rewards. Purchased hints provide information only and do not bypass Runtime, QR, GPS, role, or AR rules.

## HOME VERIFICATION PENDING

1. Phase 2.1 final verification.
2. Phase 2.2 final verification.
3. Phase 2.3 PostgreSQL full suite.
4. `migrate:fresh` against the isolated test database.
5. Scoring/hint migration rollback and reapply.
6. PostgreSQL contention tests: completion retry, simultaneous hints, projection updates, entitlement single-credit boundary, and finalization races.
7. Docker build.
8. `/api/v1/live`.
9. `/api/v1/ready`.
10. `/api/v1/health`.
11. Queue smoke.
12. Phase 0–2.2 regression suite.

No release commit or push was created in office mode.

## Final home verification — 2026-10-11

Status: **COMPLETE** for the implemented backend phase. The office checklist above is historical.

Canonical full suite: 555 passed / 9,108 assertions; zero failures, errors or skips. HintConsumptionTest passed 2 cases and ScoringConcurrencyTest passed all 8 cases: sequential hints, hint retries, simultaneous deltas, duplicate completion, finalize, finalize versus delta, manual retry and projection rebuild. Scoring/hint migration fresh and rollback/reapply passed on urban_ar_test. Deferred commerce/entitlement behavior remains fail-closed.
