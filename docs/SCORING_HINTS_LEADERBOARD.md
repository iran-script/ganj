# Scoring, Hints & Leaderboard

## Score authority

Team score is derived from append-only `score_events`. `team_scores` is a rebuildable projection. Runtime completion is the only progression authority; scoring observes the validated completion fact and cannot advance or complete a Node.

Supported server sources are `NODE_COMPLETION`, `TIME_BONUS`, `FIRST_ATTEMPT_BONUS`, `NO_HINT_BONUS`, `HINT_PENALTY`, live-challenge boundaries, manual delta adjustment, and the future anti-cheat boundary.

Manual changes are delta-only, require a reason and authorized operator, and produce an auditable compensating event. There is no SET_SCORE operation.

## Hints

Scenario Node hints are progressive and limited to hint 1, hint 2, and hint 3. Consumption is locked, idempotent, recorded append-only, and applies server-derived penalties exactly once. Future hint content is not returned.

After built-ins are exhausted, the Entitlement boundary is required. Because Commerce/Entitlement truth is not part of Phase 2.3, extra-hint requests fail closed. Purchased hints cannot add score or bypass Runtime, QR, GPS, role, or AR requirements.

## Leaderboard

Ranking order is:

1. Higher score.
2. Lower effective elapsed seconds.
3. Fewer hints used.
4. Earlier finish time.
5. Stable internal session-team ordering only as a deterministic non-business fallback.

Team and leaderboard responses are tenant/session scoped. Competitor identifiers are withheld from non-operator rows unless visibility policy permits them.

## Events and audit

Scoring and hint events use the frozen Event Catalog names and the existing transactional outbox. Manual adjustments and privileged operations are audited. Historical facts are never updated or deleted.

## HOME VERIFICATION PENDING

Phase 2.1 verification must complete first, followed by Phase 2.2 and Phase 2.3 PostgreSQL full suites, migration fresh/rollback/reapply, contention tests, Docker build, live/ready/health checks, Queue smoke, and Phase 0–2.2 regressions.
