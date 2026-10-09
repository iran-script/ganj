NEXO — Specification Dependency Map & Documentation Index v1

STATUS  
DOCUMENTATION GOVERNANCE BASELINE  
No implementation change is authorized by this document.  
Purpose: make all NEXO specifications, dependencies, precedence rules, and phase entry criteria explicit before further coding.

1\. CANONICAL DOCUMENT SET

A. Product Blueprint v1  
Role:  
\- Highest-level product/domain authority.  
\- Defines product vision, frozen business rules, team/session limits, scenario philosophy, commercial model, architecture direction, pilot and production roadmap.

Depends on:  
\- None.

Downstream documents:  
\- API Contract  
\- Database Specification  
\- State Machine Specification  
\- Event Catalog  
\- Permission Matrix  
\- Codex Master Implementation Specification

If conflict:  
\- Frozen business/domain rule in Blueprint wins.

B. API Contract v1  
Role:  
\- Human-readable HTTP/API behavior and semantics.  
\- Defines /api/v1 behavior, request/response envelopes, error semantics, endpoint intent, idempotency expectations, auth/device headers.

Depends on:  
\- Product Blueprint.

Downstream:  
\- OpenAPI  
\- Permission Matrix  
\- Phase execution specs  
\- Flutter/Admin contracts

If conflict:  
\- Blueprint wins for business rules.  
\- API Contract wins for HTTP behavior/semantics.

C. openapi.yaml  
Role:  
\- Machine-readable API path/schema contract.  
\- Used for implementation and contract validation.

Depends on:  
\- Blueprint  
\- API Contract

Downstream:  
\- Backend controllers/requests/resources  
\- Flutter client  
\- Admin client  
\- Contract tests

If conflict:  
\- Correct OpenAPI to match Blueprint \+ API Contract.  
\- Do not alter business behavior merely to preserve stale OpenAPI.

D. Database & Data Model Specification v1  
Role:  
\- Canonical relational/storage schema baseline.  
\- Defines entities, constraints, indexes, PostGIS strategy, retention, append-only records, partitioning.

Depends on:  
\- Blueprint  
\- API Contract

Downstream:  
\- Migrations  
\- Repository/query services  
\- State Machine persistence  
\- Event/Audit persistence

If conflict:  
\- Blueprint wins for business rule.  
\- Database Spec is authoritative for schema/constraints unless formally revised.

E. State Machine Specification v1  
Role:  
\- Canonical lifecycle and transition rules.  
\- Defines valid states, terminal states, transition guards, concurrency requirements.

Depends on:  
\- Blueprint  
\- API Contract  
\- Database Specification

Downstream:  
\- Application/Domain Services  
\- API actions  
\- Runtime Engine  
\- Tests  
\- Event emission

If conflict:  
\- Blueprint business rules win.  
\- State Machine is authoritative for transition behavior.

F. Event Catalog Specification v1  
Role:  
\- Canonical domain/outbox/realtime event taxonomy.  
\- Defines event names, envelope, delivery semantics, versioning and privacy.

Depends on:  
\- Blueprint  
\- API Contract  
\- Database Specification  
\- State Machine Specification

Downstream:  
\- Outbox  
\- Queue workers  
\- Realtime  
\- Audit/analytics  
\- Notifications

If conflict:  
\- State Machine determines when a state event is valid.  
\- Event Catalog determines event naming/payload semantics.

G. Permission Matrix Specification v1  
Role:  
\- Canonical authorization rules.  
\- Defines roles, scopes, resource access, sensitive operations and cross-tenant policy.

Depends on:  
\- Blueprint  
\- API Contract  
\- State Machine Specification  
\- Organization/Team/Session concepts from Database Spec

Downstream:  
\- Laravel Policies/Gates  
\- Realtime channel authorization  
\- API security tests

If conflict:  
\- Blueprint wins for product role model.  
\- Permission Matrix is authoritative for authorization.

H. Codex Master Implementation Specification v1  
Role:  
\- Engineering execution contract.  
\- Converts frozen design into phase-by-phase implementation order, quality gates, test requirements, and no-go rules.

Depends on:  
\- All canonical design documents A–G.

Downstream:  
\- All phase execution prompts  
\- Codex implementation work  
\- milestone reports

I. Phase Execution Specifications  
Role:  
\- Narrow implementation task contract for one phase only.

Existing:  
\- Phase 0 Foundation  
\- Phase 1.1 Identity & Player Device  
\- Phase 1.2 Organizations & Tenant Baseline

Depends on:  
\- Codex Master Spec  
\- All relevant canonical domain documents

Rule:  
\- A phase prompt may narrow scope.  
\- It may not override a frozen business rule.  
\- Any true conflict requires documentation revision before coding.

J. RTK.md / Local Spec Pack  
Role:  
\- Local workspace discovery/index for Codex.  
\- Mirrors authoritative specifications for offline/local implementation.

Depends on:  
\- Current canonical document revisions.

Rule:  
\- It is an index/distribution artifact, not a new source of business truth.

2\. DOCUMENT AUTHORITY PRECEDENCE

When a conflict exists:

1\) Product/domain rule  
→ Product Blueprint

2\) API semantics/HTTP behavior  
→ API Contract

3\) Machine-readable path/schema  
→ OpenAPI

4\) Database/table/index/constraint  
→ Database Specification

5\) State transitions/guards  
→ State Machine Specification

6\) Events/realtime/outbox semantics  
→ Event Catalog

7\) Authorization/resource scope  
→ Permission Matrix

8\) Implementation sequencing/quality gates  
→ Codex Master Implementation Specification

9\) Current implementation scope  
→ Phase Execution Specification

No lower-level document may silently override a higher-level authority.

3\. PRODUCT IMPLEMENTATION DEPENDENCY GRAPH

Phase 0 — Foundation  
No domain dependency.  
Provides:  
\- Laravel  
\- PostgreSQL/PostGIS  
\- Redis  
\- Queue  
\- Scheduler  
\- Realtime foundation  
\- API/error/request-id  
\- Idempotency  
\- Audit  
\- Outbox  
\- Docker/testing

↓

Phase 1.1 — Identity & Player Device  
Depends on:  
\- Phase 0  
\- API auth contract  
\- Identity DB schema  
\- Identity events  
\- Permission self/device rules

Provides:  
\- User  
\- Bearer Auth  
\- Device registration  
\- Device context  
\- Token/device revocation

↓

Phase 1.2 — Organizations & Tenant Baseline  
Depends on:  
\- Phase 0  
\- Phase 1.1 User/Auth  
\- Organization DB schema  
\- Permission Matrix tenant roles

Provides:  
\- Organization  
\- Organization membership  
\- OrganizationAdmin / GameMaster tenant context  
\- cross-tenant isolation

↓

Phase 1.3 — Team Management  
Depends on:  
\- Identity  
\- Tenant baseline where organization-owned context applies  
\- Team DB schema  
\- Team events  
\- Team permission rules

Provides:  
\- Team  
\- Captain capability  
\- Membership history  
\- Invite/Join  
\- 4–8 eligibility

↓

Phase 1.4 — Game & Game Session  
Depends on:  
\- Organization  
\- Team  
\- Identity/Device  
\- State Machine session/player/team  
\- Session DB schema  
\- Session permissions/events

Also references but does not fully implement:  
\- Billing Gate interface  
\- Scenario Assignment interface  
\- Runtime bootstrap interface

Provides:  
\- Game  
\- Session  
\- Registration  
\- Ready  
\- Roster snapshot  
\- Start/Pause/Resume/Finish/Cancel

↓

Phase 1.5 — Billing & Payments  
Depends on:  
\- Organization  
\- Game/Session  
\- Identity  
\- Idempotency/Outbox/Audit

Provides:  
\- price snapshots  
\- billable participation  
\- orders/payments/refunds/credits  
\- Billing Gate

↓

Phase 1.6 — Commerce & Entitlements  
Depends on:  
\- Billing  
\- Session  
\- Team/User ownership scopes

Provides:  
\- Store Products  
\- Purchases  
\- Entitlements  
\- Atomic consumption

↓

Phase 1.7 — Runtime & Event Timeline  
Depends on:  
\- Game Session  
\- Scenario Assignment contract  
\- Team  
\- Identity/Device  
\- Outbox/Event Catalog

Provides:  
\- Team Scenario Instance  
\- Node Instance  
\- Commands  
\- Runtime Events  
\- Timers  
\- Reconnect state

↓

Phase 2 — GPS / PostGIS  
Depends on:  
\- Session  
\- Player Session  
\- Device Binding  
\- Runtime trigger contract  
\- Organization scope

Provides:  
\- Checkpoints  
\- Location events  
\- Geofence verification  
\- Location policy

↓

Phase 3 — Scenario Core  
Depends on:  
\- Organization  
\- versioning/storage foundation  
\- Runtime interface contract

Provides:  
\- Scenario  
\- ScenarioVersion  
\- Nodes/Edges  
\- Variables  
\- Conditions  
\- Validation  
\- deterministic seed

Important:  
For implementation sequencing, Scenario Core and Runtime have a circular conceptual relationship.  
Resolve as:  
1\. Implement Scenario template/version model and graph validation first.  
2\. Implement Runtime persistence/handlers against published ScenarioVersion.  
3\. Add richer node handlers incrementally.  
Do not make either module directly depend on UI Builder.

↓

Phase 4 — Visual Scenario Builder  
Depends on:  
\- Scenario Core  
\- Validation APIs  
\- revision tokens  
No Runtime redesign allowed.

↓

Phase 5 — QR  
Depends on:  
\- Runtime  
\- Scenario Node  
\- Session/Team/Player  
\- Device  
\- optional GPS evidence

↓

Phase 6 — Co-op Roles  
Depends on:  
\- Team  
\- Runtime  
\- Scenario Nodes  
\- Session Player

↓

Phase 7 — AR  
Depends on:  
\- Scenario  
\- Runtime  
\- Device  
\- Media/asset contract

↓

Phase 8 — Live GameMaster Challenges  
Depends on:  
\- Session  
\- Runtime  
\- Realtime  
\- Score interface  
\- Permission Matrix

↓

Phase 9 — Score / Hints / Leaderboard  
Depends on:  
\- Runtime  
\- Team/Session  
\- Commerce entitlement interface for extra hints  
\- Event Catalog

↓

Phase 10 — Anti-Cheat  
Depends on:  
\- Device  
\- GPS  
\- QR  
\- Runtime  
\- Commerce/Billing signals  
\- GameMaster review

↓

Phase 11+ follow the frozen Master Spec.

4\. NEXO PILOT RELEASE v0.1 DEPENDENCY PROFILE

Pilot is NOT a separate architecture.  
It is a release slice of the production architecture.

Pilot-required:  
\- Phase 0 ✅  
\- Phase 1.1 ✅  
\- Phase 1.2  
\- Team Management  
\- Game/Session  
\- Scenario Core subset  
\- Runtime  
\- GPS/Checkpoint  
\- QR  
\- Puzzle/Password/Multiple Choice  
\- Role assignment subset  
\- Score/Hint/Leaderboard  
\- Flutter gameplay flow  
\- Admin/GameMaster operational subset  
\- Reconnect/basic resilience

Pilot-deferred UI/features:  
\- Visual Scenario Builder  
\- Advanced AR/Unity  
\- Payment UI / public commerce  
\- Advanced Live Challenge  
\- Full Anti-Cheat  
\- BI/Analytics  
\- Full Offline  
\- Production-scale hardening

Critical rule:  
Deferred features must be omitted, not replaced with incompatible temporary architecture.

5\. PHASE ENTRY DOCUMENT CHECKLIST

Before Phase 1.2:  
Must read:  
\- Blueprint tenant sections  
\- Database Identity/Organization domain  
\- Permission Matrix Organization/Identity  
\- Event Catalog Organization events  
\- API Organization endpoints  
\- Master Spec Phase 1.2

Before Team phase:  
Must read:  
\- Blueprint Team Management  
\- DB Team tables  
\- Team State/Membership State  
\- Team Events  
\- Team Permissions  
\- Team API endpoints

Before Game/Session:  
Must read:  
\- Blueprint Game Session section  
\- DB Game/Session tables  
\- State Machine Game Session \+ Team \+ Player  
\- Session Events  
\- Session Permissions  
\- API Session endpoints

Before Scenario:  
Must read:  
\- Blueprint Scenario/Builder/Versioning  
\- DB Scenario tables  
\- Scenario Version State Machine  
\- Scenario Events  
\- Scenario Permissions  
\- API Scenario endpoints

Before Runtime:  
Must read:  
\- Blueprint Runtime  
\- DB Runtime tables  
\- Runtime/Node/Timer states  
\- Runtime events  
\- Runtime permissions  
\- Runtime API

Before GPS:  
Must read:  
\- Blueprint GPS  
\- DB GIS domain  
\- location events  
\- permissions/privacy  
\- API location/checkpoints

Before QR:  
Must read:  
\- Blueprint QR  
\- DB QR tables  
\- QR token state  
\- QR events  
\- QR permissions/API

6\. CHANGE CONTROL

A change requires documentation update before code when it changes:  
\- frozen business rule  
\- state transition  
\- database invariant  
\- API breaking behavior  
\- event name/payload semantics  
\- authorization rule  
\- module dependency  
\- public identifier strategy

Minor implementation detail that does not alter contracts can be documented in phase docs/ADR after implementation.

7\. CODING GATE

No new phase coding starts until:  
\- its upstream dependencies are COMPLETE  
\- its canonical documents are consistent  
\- its phase execution spec exists  
\- unresolved conflicts are zero

8\. CURRENT PROJECT STATUS

Documentation:  
\- Core canonical document set: complete  
\- Dependency Map: complete  
\- Phase 0 execution spec: complete  
\- Phase 1.1 execution spec: complete  
\- Phase 1.2 execution spec: drafted/ready for documentation review

Implementation:  
\- Phase 0: COMPLETE  
\- Phase 1.1: COMPLETE  
\- Phase 1.2: NOT STARTED

Next documentation task:  
Review and freeze Phase 1.2, then prepare Team Management specification with explicit dependencies.  
No coding should begin until documentation pass is complete.

9\. DEFINITION OF DONE — DOCUMENTATION BASELINE

Documentation baseline is ready when:  
\- every canonical document has a defined authority  
\- conflicts have a resolution hierarchy  
\- every implementation phase has explicit upstream dependencies  
\- Pilot scope maps to production phases  
\- phase entry reading checklist exists  
\- change-control rules are explicit  
\- no temporary MVP architecture can bypass canonical contract

DOCUMENT SET EXPANSION & FINAL DEPENDENCY SYNC v1

The following domain specifications are now part of the canonical documentation pack:  
\- Team Management Domain Specification v1  
\- Game & Game Session Domain Specification v1  
\- Scenario Core & Versioning Domain Specification v1  
\- Runtime Engine & Execution Specification v1  
\- GPS, Location & Checkpoint Specification v1  
\- QR Trigger & Verification Specification v1  
\- Co-op Roles & Team Mechanics Specification v1  
\- Scoring, Hints & Leaderboard Specification v1  
\- AR Runtime & Content Delivery Specification v1  
\- Live GameMaster & Challenge Engine Specification v1  
\- Anti-Cheat & Trust Specification v1  
\- Offline, Resilience & Recovery Specification v1  
\- Billing, Payment & Participation Specification v1  
\- Store, Products & Entitlements Specification v1  
\- Documentation Consistency Review & Sync Decisions v1

Machine-readable API:  
\- openapi.yaml remains the historical baseline.  
\- openapi.sync-v1.yaml is the documentation-sync candidate reflecting approved additive API extensions.  
\- After final freeze, the sync candidate becomes the implementation input and the old baseline is retained only for traceability.

Implementation dependency chain:  
Foundation  
\-\> Identity/Device  
\-\> Organization/Tenant  
\-\> Team  
\-\> Game/Session  
\-\> Scenario Core  
\-\> Runtime  
\-\> GPS/Checkpoint  
\-\> QR  
\-\> Co-op Roles  
\-\> Score/Hint/Leaderboard  
\-\> AR  
\-\> Live GameMaster  
\-\> Anti-Cheat  
\-\> Offline hardening  
\-\> Billing/Participation  
\-\> Store/Entitlement  
\-\> Production hardening

Important:  
Billing and Store can be implemented later than Pilot gameplay, but their interfaces and Session Start gate contracts are frozen now so no future core redesign is needed.

Coding Gate remains CLOSED until Documentation Freeze Manifest is issued.

FINAL DOCUMENT SET ADDENDUM v1

Additional canonical domain specifications:  
\- Organization & Tenant Domain Specification v1  
\- Media, File & Asset Specification v1  
\- Admin, Operations & Observability Specification v1

Updated pre-code dependency:  
Phase 1.2 Organizations must read Organization & Tenant Domain Specification before execution.  
All later content/runtime phases must respect Media/Asset contract.  
Pilot/Admin implementation must respect Admin/Operations/Observability contract.

Documentation Consistency Review remains the conflict-resolution record for naming sync.  
Coding Gate remains CLOSED until Freeze Manifest is issued.  
s  
