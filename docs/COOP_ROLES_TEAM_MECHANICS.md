# Co-op Roles & Team Mechanics

Phase 2.2 extends the existing Runtime architecture with capability-based cooperative mechanics.

## Role and Support model

Teams contain 4–8 frozen Session Players. A stage has at most four active primary assignments. Other eligible Players are Support, not inactive. Captaincy is never a gameplay capability.

Canonical role codes remain:

- `SCANNER` → `SCAN_QR`
- `NAVIGATOR` → `NAVIGATE` / `MAP_FOCUS`
- `DECODER` → `DECODE` / `PUZZLE_PRIVILEGE`
- `AR_OPERATOR` → `OPERATE_AR`

Assignments remain in `player_role_assignments`. Release preserves history. Assignment policies are server-selected and include rotation, least-recently-used, seeded random, Scenario-fixed, GameMaster override, and late-join policy.

## Cooperative mechanics

Team Together now supports minimum distinct Players, required role sets, active-slot-only policy, and optional synchronization windows. Contributions remain attributable Runtime events and are evaluated under the existing Runtime transaction/idempotency boundary.

Split Team assigns deterministic subgroup membership into existing Node `state_json`. Relay uses existing Node state with a server-controlled ordered role chain. Both remain subject to Runtime locks and command idempotency.

Role-scoped content is filtered by the authenticated Player’s current role. A generic Team projection never returns the union of role-specific secrets.

## External domains

QR verification remains owned by Phase 2.1 and only consumes Runtime role truth. GPS evidence remains owned by Phase 2.0 and is not recalculated here. AR capability checks fail closed when required metadata is absent; the AR domain is not implemented.

## Security and concurrency

Clients cannot choose role, slot, subgroup, assignee, next Node, or completion result. GameMaster overrides require existing scope, reason, audit, event, and idempotency boundaries. Role assignment, subgroup creation, contribution evaluation, Relay advancement, and Runtime completion must be verified with PostgreSQL lock contention tests at home.

## Verification state

This office implementation has not passed the canonical Docker/PostgreSQL verification gates. Phase 2.1 home verification must pass before Phase 2.2 is released.
