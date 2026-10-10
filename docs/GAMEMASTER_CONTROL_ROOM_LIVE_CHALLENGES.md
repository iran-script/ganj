# GameMaster Control Room / Live Challenges

## Scope

The control room is an operational projection for an assigned GameMaster or OrganizationAdmin. `GameScope` enforces Organization, Game, and Session assignment before any read or mutation. Players may only read Challenges targeted to their Session, Team, or Player identity.

## Live Challenge lifecycle

Challenges are persisted as `DRAFT`, `SCHEDULED`, `ACTIVE`, `COMPLETED`, `EXPIRED`, or `CANCELLED`. The Pilot creates an immediately `ACTIVE` `MESSAGE` or `TEAM_OBJECTIVE` Challenge with default `PARALLEL` interrupt policy. Each target has its own `SENT`, `SEEN`, `ACKED`, `STARTED`, `COMPLETED`, `FAILED`, `EXPIRED`, or `CANCELLED` state.

Completion is server-side: the target must be eligible, the Challenge must be active and unexpired, and the Pilot acknowledgement evidence must be true. A reward is sent through `ScoringService`; `team_scores.score` is never mutated directly. Completion and reward use Challenge-scoped idempotency keys.

## Control actions

Messages, emergency messages, device rebind, disqualification, and Challenge cancellation require the assigned operational scope. Sensitive actions require a reason and are audited. Team pause/resume reuses Runtime commands and timer semantics. Existing Runtime and Scoring endpoints remain the authority for role operations, node skipping, hints, and score adjustments.

## Safe projection and realtime

Control-room and Player projections exclude raw QR credentials, tokens, hidden Scenario graph, answers, and unrelated Player secrets. Outbox topics use `challenge.*`, `message.received`, and `gamemaster.emergency`; API refresh remains the fallback when realtime delivery is unavailable.

## Home verification pending

PostgreSQL migration, append-only/history constraints, authorization, tenant isolation, idempotency, expiry/cancel races, duplicate submission, reward-once behavior, realtime delivery, queue smoke, Docker health checks, and Phase 0–2.4 regressions remain pending. No Phase 2.6 implementation is included.
