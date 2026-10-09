NEXO — Documentation Consistency Review & Sync Decisions v1

STATUS  
CONSISTENCY REVIEW — PASS 1 COMPLETE  
Purpose: reconcile new domain specifications with the pre-existing canonical API, Database, State Machine, Event Catalog, Permission Matrix and OpenAPI contracts before any new coding phase begins.

1\. REVIEWED CANONICAL SOURCES

\- Product Blueprint v1  
\- API Contract v1  
\- Database & Data Model Specification v1  
\- State Machine Specification v1  
\- Event Catalog Specification v1  
\- Permission Matrix Specification v1  
\- Codex Master Implementation Specification v1  
\- openapi.yaml baseline

Reviewed against the new frozen domain specifications:  
\- Team Management  
\- Game & Game Session  
\- Scenario Core & Versioning  
\- Runtime Engine  
\- GPS / Location / Checkpoint  
\- QR Trigger & Verification  
\- Co-op Roles & Team Mechanics  
\- Scoring / Hints / Leaderboard  
\- AR Runtime & Content Delivery  
\- Live GameMaster & Challenge Engine  
\- Anti-Cheat & Trust  
\- Offline / Resilience / Recovery  
\- Billing / Payment / Participation  
\- Store / Products / Entitlements

2\. AUTHORITY RULE APPLIED

Existing authority precedence remains:  
1\) Product Blueprint — frozen business/domain rules  
2\) API Contract — canonical HTTP paths/semantics  
3\) OpenAPI — machine-readable representation of API Contract  
4\) Database Specification — canonical schema/table names/constraints  
5\) State Machine — lifecycle/transitions  
6\) Event Catalog — canonical event names/envelopes  
7\) Permission Matrix — authorization  
8\) Master Implementation Spec — phase sequencing  
9\) Domain/Phase specs — detailed implementation requirements inside the above contracts

Decision:  
New domain specs do not silently replace canonical API/DB/Event naming where an equivalent canonical concept already exists. The canonical source is preserved unless this review explicitly approves a revision.

3\. HIGH-LEVEL RESULT

Core product rules are consistent.

No blocking contradiction was found in:  
\- Team size 4–8 for game eligibility  
\- Session 1–5 Teams / max 40 Players  
\- minimum 4 Ready Players per Team  
\- frozen roster at Start  
\- one active bound Device per Session Player  
\- ScenarioVersion pinning  
\- server-authoritative Runtime  
\- GPS/QR/AR verification boundaries  
\- four active primary co-op slots  
\- Team-oriented scoring  
\- max 3 built-in progressive hints  
\- Billing Gate before Start  
\- one User \+ one Session \= one billable participation  
\- offline client never becoming source of truth  
\- tenant isolation  
\- no-pay-to-win policy

4\. STATE MACHINE REVIEW

Result:  
PASS — no structural state-machine rewrite required.

Existing State Machine already contains the major lifecycles required by the newer domain specs:  
\- Game Session  
\- Session Team  
\- Session Player  
\- Team Scenario Instance  
\- Node Instance  
\- Live Challenge  
\- Payment/Order/Refund/Entitlement  
\- Scenario Version  
\- Team/Membership

Required sync:  
\- Anti-Cheat case lifecycle is additive and should be added explicitly.  
\- Any new Store Purchase lifecycle must map to existing purchase/payment lifecycle rather than create a competing state model.

5\. DATABASE NAMING CONFLICTS FOUND

The newer domain specs introduced several descriptive names that duplicate existing canonical Database Spec entities.

Existing canonical DB names to preserve:  
\- role\_assignments  
\- player\_role\_assignments  
\- challenge\_templates  
\- orders  
\- payments  
\- refunds  
\- credits  
\- anti\_cheat\_flags  
\- anti\_cheat\_actions  
\- entitlements  
\- entitlement\_consumptions

New-spec names that must be treated as conceptual aliases unless formally migrated:  
\- runtime\_role\_assignments \-\> player\_role\_assignments / role\_assignments depending scope  
\- live\_challenge\_templates \-\> challenge\_templates  
\- billing\_orders \-\> orders  
\- payment\_transactions \-\> payments  
\- credit\_accounts / credit\_ledger \-\> existing credits model must be extended, not duplicated  
\- anti\_cheat\_signals \-\> anti\_cheat\_flags unless a separate signal table is formally introduced  
\- store\_purchases \-\> existing purchase/order domain must be extended, not duplicated

Decision:  
Preserve existing canonical table names for implementation stability.  
New domain specs will receive sync notes clarifying these aliases.

6\. API PATH CONFLICTS FOUND

Existing API Contract already defines canonical billing paths:  
\- POST /api/v1/game-sessions/{session\_id}/orders  
\- GET /api/v1/orders/{order\_id}  
\- POST /api/v1/orders/{order\_id}/pay  
\- POST /api/v1/payments/{payment\_id}/verify  
\- POST /api/v1/payments/webhooks/{provider}  
\- POST /api/v1/orders/{order\_id}/refund

New Billing spec introduced alternate paths such as:  
\- /billing/orders  
\- /billing/payments/...  
\- /billing/provider/...  
\- /billing/refunds/...

Decision:  
Existing API Contract paths remain canonical.  
Do NOT create duplicate billing APIs.

Existing Anti-Cheat API Contract uses:  
\- GET /api/v1/game-sessions/{session\_id}/anti-cheat/flags  
\- GET /api/v1/anti-cheat/flags/{flag\_id}  
\- POST /api/v1/anti-cheat/flags/{flag\_id}/review  
\- POST /api/v1/anti-cheat/flags/{flag\_id}/resolve

New Anti-Cheat spec introduced signals/cases paths.

Decision:  
Pilot/core implementation preserves Flags as canonical evidence record.  
A Case aggregation layer may be added later as additive API if/when implemented; it must not replace Flags silently.

7\. GENUINELY MISSING API CAPABILITIES

The review identified capabilities in the new specs that are not yet explicit in API Contract and should be added before their implementation phase:

Pricing:  
\- GET Game pricing  
\- create/update Game price

Billing:  
\- explicit Session Billing Gate read  
\- optional prepare/recompute billing operation

Store/Admin:  
\- Product create/update/activate/archive  
\- Entitlement admin grant/revoke  
\- broader entitlement read where needed

Anti-Cheat:  
\- Case endpoints only if Case aggregate is implemented as a real feature; Pilot may stay Flags-only.

Decision:  
Add these as contract extensions only when the corresponding implementation phase is scheduled. They are not required to unblock Phase 1.2 Organizations.

8\. EVENT NAMING CONFLICTS FOUND

Existing Event Catalog already has canonical financial events:  
\- order.\*  
\- payment.\*  
\- refund.\*  
\- billing.participation\_\*  
\- billing.pricing\_snapshotted.\*  
\- billing.gate\_\*  
\- store.product\_\*  
\- purchase.\*  
\- entitlement.\*

New Billing/Store specs introduced alternate event families such as:  
\- billing.order\_created  
\- billing.payment\_succeeded  
\- billing.refund\_completed  
\- store.purchase\_created  
\- store.purchase\_paid  
\- store.purchase\_fulfilled

Decision:  
Do NOT create duplicate event families for the same business fact.  
Existing Event Catalog names remain canonical.  
Domain specs must map their conceptual events to the existing catalog.

9\. ANTI-CHEAT EVENT DECISION

Existing canonical family:  
\- anti\_cheat.flag\_\*

New spec introduced:  
\- anti\_cheat.signal\_\*  
\- anti\_cheat.case\_\*

Decision:  
For Pilot/core:  
\- anti\_cheat.flag\_\* remains canonical detection/evidence family.  
\- anti\_cheat.enforcement\_applied.v1 remains canonical enforcement outcome.  
\- Case events are additive only if the Case aggregate is implemented later.  
\- No duplicate signal \+ flag event should be emitted for the same detection fact.

10\. PERMISSION MATRIX REVIEW

Overall role model is consistent:  
\- SuperAdmin  
\- OrganizationAdmin  
\- GameMaster  
\- Player  
\- Captain as derived Team capability

Required clarifications to add:  
\- GameMaster may not issue refunds by default.  
\- GameMaster may not create paid Entitlements.  
\- Financial manual approval/refund/credit adjustment requires restricted finance/admin capability.  
\- Anti-Cheat global account restriction is not a normal GameMaster permission.  
\- Node Skip is sensitive, reason \+ audit required.  
\- Store Product administration is Organization-scoped.  
\- Entitlement grant/revoke is privileged and audited.

11\. OPENAPI REVIEW

Current OpenAPI is a baseline generated from API Contract and is broad but intentionally generic in schemas.

Observed:  
\- Core resources/tags already include AR, AntiCheat, Billing, Checkpoints, GameMaster, GameSessions, Hints, LiveChallenges, Locations, QR, Runtime, Scores, Store, Teams.  
\- Several new domain-detail endpoints are not represented.  
\- Existing billing/anti-cheat paths follow the old canonical API Contract naming, not newer domain-spec aliases.

Decision:  
Do not patch OpenAPI with conflicting alternate paths.  
First sync human canonical contracts.  
Then regenerate/update OpenAPI once naming decisions are frozen.

12\. DOCUMENTS REQUIRING SYNC NOTES

Required now:  
\- Billing, Payment & Participation Specification  
\- Store, Products & Entitlements Specification  
\- Anti-Cheat & Trust Specification  
\- Runtime Engine specification for role-assignment DB naming  
\- Live GameMaster specification for challenge-template DB naming

Canonical docs requiring additive clarification:  
\- API Contract  
\- Database Specification  
\- Event Catalog  
\- Permission Matrix  
\- State Machine only for Anti-Cheat Case if Case is promoted to core

13\. PHASE 1.2 IMPACT

Phase 1.2 Organizations & Tenant Baseline is NOT blocked by Billing/Store/Anti-Cheat naming reconciliation.

However:  
No coding should start yet because the user requested a full Documentation Freeze first.

14\. REMAINING DOCUMENTATION WORK

Before coding:  
A) apply sync notes to affected domain specs  
B) add canonical clarifications to API/DB/Event/Permission docs  
C) reconcile OpenAPI with final API Contract  
D) update Master Dependency Map with the complete new document set  
E) update Codex Master Implementation phases if needed  
F) create final Documentation Freeze Manifest  
G) issue next Phase execution prompt only after Freeze

15\. CONSISTENCY DECISION SUMMARY

KEEP:  
\- existing canonical API route families  
\- existing canonical DB table names where equivalent  
\- existing Event Catalog names where equivalent  
\- existing State Machine transitions

EXTEND:  
\- pricing/admin APIs  
\- financial permissions  
\- entitlement admin permissions  
\- Anti-Cheat Case only if implemented as separate aggregate  
\- documentation dependency map

DO NOT DUPLICATE:  
\- billing/order APIs  
\- payment APIs  
\- refund APIs  
\- financial event families  
\- anti-cheat flag/signal facts  
\- role assignment tables  
\- challenge template tables

16\. REVIEW STATUS

Pass 1:  
COMPLETE

Blocking contradictions:  
0

Naming/contract sync items:  
FOUND AND RESOLVED BY DECISION

Next action:  
Apply these decisions to the affected documents, then perform OpenAPI/Dependency Map/Master Spec final sync.  
