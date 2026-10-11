# Phase 2.2 — Co-op Roles & Team Mechanics

## Status

**PARTIAL — OFFICE DEVELOPMENT COMPLETE / HOME VERIFICATION PENDING**

Phase 2.2 office implementation extends the Phase 1.6 Runtime without creating a second role system. Phase 2.1 remains implementation-complete but requires final home verification first.

## Implemented in office mode

- role assignment policy and fairness services
- LRU and seeded-random assignment selection
- Scenario-fixed assignment validation
- Support policy and subgroup action restriction
- generalized Team Together contribution evaluation
- required-role barriers
- synchronization-window validation
- role metadata on contribution events
- deterministic Split Team subgroup state in existing Node state
- Relay node baseline with server-controlled role order
- role-scoped safe Runtime projections
- device capability policy boundary that fails closed when mandatory capability metadata is unavailable
- `Relay` Scenario node registration and OpenAPI node-type declaration

Existing `player_role_assignments`, `runtime_commands`, `runtime_events`, node state, Runtime locking, idempotency, QR role eligibility, and GPS role boundaries remain authoritative.

## Database decision

No migration was added. The pilot mechanics use existing canonical persistence. A migration remains prohibited unless advanced subgroup, Relay, or synchronization requirements cannot be represented safely in existing Runtime state/events and a schema decision is approved.

## Boundaries

- Captain remains outside gameplay roles.
- Players cannot self-assign roles.
- QR remains Phase 2.1 verification authority.
- GPS remains Phase 2.0 evidence authority.
- AR remains fail-closed; only capability policy is prepared.
- Scoring, hints, billing, Flutter, Control Room, and later phases remain untouched.

## HOME VERIFICATION PENDING

Run in order:

1. Phase 2.1 final verification first.
2. Phase 2.2 PostgreSQL full suite.
3. `migrate:fresh`.
4. Rollback/reapply only if a later Phase 2.2 migration is introduced.
5. PostgreSQL contention tests.
6. Docker build.
7. `/api/v1/live`.
8. `/api/v1/ready`.
9. `/api/v1/health`.
10. Queue smoke.
11. Phase 0–2.1 regressions.

Office results do not constitute Phase 2.2 completion.

## Final home verification — 2026-10-11

Status: **COMPLETE** for the implemented backend phase. The office checklist above is historical.

The canonical full suite passed 555 tests / 9,108 assertions with zero failures, errors or skips. Co-op contract (4 cases), Runtime mechanics (25 cases), and PostgreSQL contention for final barrier contributions, fifth role-slot assignment, Relay advance, role reassignment and late join passed. Split subgroup eligibility and metadata preservation are covered. No Phase 2.2 migration was introduced. Docker build and all six canonical services passed.
