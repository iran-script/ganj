NEXO — Team Management Domain Specification v1

STATUS  
FROZEN DOMAIN BASELINE  
Purpose: define the Team domain before implementation so API, database, authorization, events and future Game Session behavior remain consistent.

1\. POSITION IN ARCHITECTURE

Team Management depends on:  
\- Phase 0 Foundation  
\- Identity & Player Device  
\- Organization/Tenant baseline where organization-owned context applies  
\- Product Blueprint  
\- API Contract  
\- Database Specification  
\- State Machine Specification  
\- Event Catalog  
\- Permission Matrix

Team Management provides upstream capability for:  
\- Game Session registration  
\- roster creation  
\- billing participation  
\- runtime role assignment  
\- scoring  
\- leaderboard  
\- live challenges

Team Management must not contain Game Session runtime logic.

2\. TEAM DEFINITION

A Team is a persistent, reusable social/game entity.

A Team:  
\- may participate in multiple Game Sessions over time  
\- exists independently from a specific Session  
\- preserves membership history  
\- has exactly one active Captain while ACTIVE  
\- is not itself a runtime object  
\- may be organization-associated where business context requires it

3\. TEAM SIZE

A Team may exist with fewer than 4 members.

Allowed active member count:  
\- minimum existence: 1  
\- maximum active members: 8

Game eligibility:  
\- eligible\_for\_game \= active member count between 4 and 8 inclusive

Rules:  
\- team capacity is enforced server-side  
\- 9th active member must be rejected  
\- eligibility is derived, not stored as an authoritative mutable state  
\- reducing membership below 4 does not delete/archive the Team; it only makes it temporarily ineligible for new Game Session registration

4\. TEAM STATES

Team lifecycle:  
\- ACTIVE  
\- ARCHIVED

ACTIVE:  
\- can manage membership  
\- can create invites  
\- can be evaluated for Game Session registration

ARCHIVED:  
\- cannot be used for new Session registration  
\- historical Session references remain valid  
\- history remains readable  
\- no destructive delete for Teams with history

Transition:  
ACTIVE \-\> ARCHIVED

Guard:  
\- no blocking active Game Session relationship  
\- actor authorized

No generic ARCHIVED \-\> ACTIVE unless later explicitly approved as a business rule.

5\. CAPTAIN

Captain is NOT a system role.

Captain is a derived Team capability based on:  
captain\_user\_id

Rules:  
\- exactly one active Captain per ACTIVE Team  
\- Captain must be an active Team member  
\- Captain has pre-game Team management capabilities  
\- Captain receives no special gameplay authority merely because of captaincy  
\- gameplay roles such as Scanner/Navigator/Decoder are separate runtime roles

Captain capabilities:  
\- update permitted Team profile fields  
\- create/revoke invites  
\- remove members subject to guards  
\- initiate own Team leave only after captain transfer if required  
\- transfer captaincy  
\- archive Team where allowed  
\- register Team into Game Session in later phase  
\- manage pre-start Session roster in later phase

6\. CAPTAIN TRANSFER

Captaincy may only transfer to:  
\- an ACTIVE member of the same Team

Required:  
\- current Captain authorization OR privileged audited admin override  
\- transaction/lock on Team and membership  
\- old/new captain recorded  
\- event emitted  
\- audit record

If current Captain wants to leave:  
\- captaincy transfer must complete first  
\- cannot leave if no valid replacement exists unless privileged administrative resolution is explicitly supported

7\. TEAM MEMBERSHIP

Membership states:  
\- ACTIVE  
\- LEFT  
\- REMOVED

ACTIVE:  
\- member currently belongs to Team

LEFT:  
\- member left voluntarily

REMOVED:  
\- Captain/Admin removed member

Historical states are terminal for that membership period.

Rejoin:  
\- must create a new membership period/record or equivalent versioned history  
\- must not erase prior LEFT/REMOVED history

8\. MEMBERSHIP HISTORY

Required data:  
\- team\_id  
\- user\_id  
\- status  
\- joined\_at  
\- left\_at nullable  
\- removed\_by nullable  
\- created\_at

History must support:  
\- who joined  
\- when joined  
\- when left/removed  
\- reason/actor where relevant through event/audit history

Hard deletion of historical membership is prohibited for normal operations.

9\. USER MEMBERSHIP RULES

A User:  
\- may have historical membership in multiple Teams  
\- may be an active member of multiple persistent Teams only if product policy later explicitly allows it

For current baseline:  
\- avoid introducing a global “one Team forever” rule  
\- the critical frozen invariant is:  
  one User may belong to only one Team roster within the same Game Session

This Session-specific invariant is enforced in Game Session domain, not Team domain.

10\. TEAM CREATION

Create Team requires:  
\- authenticated ACTIVE User  
\- valid name  
\- no blocked account  
\- transaction

On creation:  
\- Team status \= ACTIVE  
\- creator becomes Captain  
\- creator receives ACTIVE Team membership  
\- team.created.v1 emitted  
\- team.member\_joined.v1 emitted if event implementation represents the initial membership separately  
\- audit where applicable

Organization association:  
\- optional unless invoking an organization-owned creation flow  
\- cannot be trusted from arbitrary client organization\_id

11\. TEAM PROFILE

Core Team fields:  
\- public\_id  
\- organization\_id nullable  
\- name  
\- slug nullable  
\- captain\_user\_id  
\- status  
\- avatar\_media\_id nullable  
\- created\_at  
\- updated\_at  
\- archived\_at nullable

Public API must not rely on internal numeric IDs.

Editable by Captain:  
\- name  
\- avatar where media subsystem allows  
\- other explicitly safe presentation fields

Not directly editable by Captain:  
\- status through generic update  
\- captain\_user\_id through generic PATCH  
\- organization ownership  
\- internal identifiers

12\. INVITES

Team Invite represents controlled membership entry.

Fields:  
\- public\_id  
\- team\_id  
\- code\_hash  
\- created\_by  
\- expires\_at nullable  
\- max\_uses nullable  
\- uses\_count  
\- revoked\_at nullable  
\- created\_at

Rules:  
\- invite secret/code is generated securely  
\- only hash stored where practical  
\- expired invite rejected  
\- revoked invite rejected  
\- max uses enforced atomically  
\- uses\_count cannot exceed max\_uses  
\- Team must be ACTIVE  
\- Team active membership count must be below 8  
\- joining User must be ACTIVE  
\- duplicate active membership rejected

Do not place sensitive business meaning inside a predictable invite code.

13\. INVITE CREATION

Captain can create invite.

Optional controls:  
\- expiry  
\- max uses

System defaults may exist but must be configurable.

Creating invite:  
\- authorization  
\- validation  
\- secure code generation  
\- persistence  
\- team.invite\_created.v1  
\- audit if policy requires

14\. INVITE REVOCATION

Captain may revoke invite belonging to own Team.

Revocation:  
\- idempotent  
\- preserves historical record  
\- sets revoked\_at  
\- team.invite\_revoked.v1

Revoked invite cannot be reused.

15\. JOIN TEAM

Join flow:  
1\. authenticate user  
2\. resolve invite securely  
3\. validate invite active/not expired/not exhausted  
4\. validate Team ACTIVE  
5\. lock Team/capacity  
6\. validate active members \< 8  
7\. validate no duplicate active membership  
8\. create active membership  
9\. increment invite usage atomically  
10\. emit event  
11\. return Team state

Potential canonical errors:  
\- TEAM\_FULL  
\- INVITE\_EXPIRED  
\- INVITE\_REVOKED  
\- ALREADY\_TEAM\_MEMBER  
\- RESOURCE\_NOT\_FOUND  
\- ACTION\_NOT\_ALLOWED

Exact API error code must follow API Contract/OpenAPI.

16\. LEAVE TEAM

Non-Captain active member:  
\- may leave Team if no blocking Game Session constraint exists  
\- membership becomes LEFT  
\- history preserved  
\- event emitted

Captain:  
\- cannot leave until captaincy transferred  
\- unless explicit privileged administrative recovery process applies

Future Session guards:  
\- if Team membership is already frozen into a running Session, persistent Team leave must not mutate that frozen Session roster

17\. REMOVE MEMBER

Captain may remove an ACTIVE member from own Team subject to:  
\- target is not Captain  
\- Team ownership/scope valid  
\- no blocking Session rule  
\- transaction

Effect:  
\- membership \-\> REMOVED  
\- record remover  
\- team.member\_removed.v1  
\- audit

GameMaster:  
\- cannot modify persistent Team membership merely due to live Session authority

18\. ARCHIVE TEAM

Captain may archive own Team when:  
\- Team ACTIVE  
\- no blocking active Session  
\- actor authorized

Effect:  
\- Team status ARCHIVED  
\- archived\_at set  
\- team.archived.v1  
\- invites should become unusable  
\- history remains

No hard delete.

19\. ORGANIZATION RELATIONSHIP

Team.organization\_id is nullable in canonical DB.

Meaning:  
\- a Team may exist independently as a user-created Team  
\- future organization-owned Teams may associate to Organization

Rules:  
\- adding/changing organization ownership is not a generic Captain action  
\- organization scope must be server-authorized  
\- cross-tenant leakage prohibited

Pilot requirement:  
\- persistent player-created Teams must work without forcing every Team to be owned by municipality/Organization

20\. GAME SESSION BOUNDARY

Team domain does NOT own:  
\- Session roster  
\- Ready status  
\- Session billing  
\- Session scenario assignment  
\- active device binding  
\- runtime roles  
\- score

When Team registers for Session:  
\- Session creates its own registration and player roster context  
\- Team membership may remain mutable before Start according to Session policy  
\- at Start, Session roster snapshot freezes  
\- later Team membership changes do not rewrite the frozen Session roster

This separation is mandatory.

21\. BILLING BOUNDARY

Team Management does not charge users.

Later Billing uses:  
\- Session roster  
\- unique User \+ Session participation

Team remove/rejoin must never be capable of bypassing billable participation.

22\. RUNTIME ROLE BOUNDARY

Captain \!= gameplay role.

Runtime roles:  
\- Scanner  
\- Navigator  
\- Decoder  
\- AR Operator / other scenario-defined capabilities

These are assigned by Runtime Engine and do not alter Team captaincy.

23\. API CONTRACT

Canonical Team endpoints:

POST /api/v1/teams  
GET /api/v1/teams  
GET /api/v1/teams/{team\_id}  
PATCH /api/v1/teams/{team\_id}

POST /api/v1/teams/{team\_id}/invites  
GET /api/v1/teams/{team\_id}/invites  
DELETE /api/v1/teams/{team\_id}/invites/{invite\_id}

POST /api/v1/teams/join  
POST /api/v1/teams/{team\_id}/leave  
DELETE /api/v1/teams/{team\_id}/members/{user\_id}  
POST /api/v1/teams/{team\_id}/transfer-captaincy  
POST /api/v1/teams/{team\_id}/archive

All public resource references use public IDs.

24\. AUTHORIZATION

team.create  
\- authenticated eligible Player/User

team.read  
\- Team member: allowed Team-safe/full profile  
\- non-member: only minimal/public view if endpoint/product requires

team.update  
\- Captain for own Team permitted fields  
\- authorized OrganizationAdmin where organization ownership rules explicitly apply

team.invite.create/revoke  
\- Captain  
\- privileged support override only with authorization/audit

team.member.remove  
\- Captain own Team subject to guards

team.transfer\_captaincy  
\- current Captain to active member

team.archive  
\- Captain subject to no blocking Session

Default deny.

25\. PRIVACY

Do not expose:  
\- internal IDs  
\- invite hash  
\- private member data beyond purpose  
\- device/token information  
\- unrelated Team history

Non-members must not receive sensitive member metadata.

26\. EVENTS

Required event taxonomy:

team.created.v1  
team.updated.v1  
team.archived.v1  
team.member\_joined.v1  
team.member\_left.v1  
team.member\_removed.v1  
team.captain\_changed.v1  
team.invite\_created.v1  
team.invite\_revoked.v1

Payloads must follow Event Catalog envelope.

No alternate undocumented names.

27\. AUDIT

Audit-sensitive Team operations:  
\- Captain transfer  
\- member removal  
\- Team archive  
\- privileged override  
\- organization ownership/admin intervention

Invite creation may rely on domain event/logging unless audit policy requires more.

28\. DATABASE CONSTRAINTS

teams:  
\- public\_id unique  
\- captain\_user\_id FK users  
\- status constrained  
\- organization\_id FK nullable

team\_members:  
\- Team/User FK  
\- valid status  
\- only one ACTIVE membership period per team/user

team\_invites:  
\- code\_hash unique  
\- uses\_count \>= 0  
\- max\_uses validation

Critical capacity 8:  
\- domain transaction \+ lock required  
\- do not rely on UI count

29\. CONCURRENCY

Hotspots:  
\- simultaneous joins  
\- invite max-use consumption  
\- Team member capacity  
\- captain transfer  
\- member remove vs leave

Required:  
\- transaction  
\- Team or relevant membership row lock  
\- capacity re-check after lock

30\. IDEMPOTENCY

Create Team:  
\- recommended where API infrastructure supports it

Join:  
\- retry must not create duplicate active membership or increment invite use twice

Leave/remove:  
\- duplicate request returns same logical state or safe no-op/error per API contract

Captain transfer:  
\- sensitive mutation should support idempotency if exposed as retryable mobile action

31\. TEST REQUIREMENTS

Creation:  
\- active user creates Team  
\- creator becomes Captain/member  
\- blocked user rejected  
\- public ID returned

Capacity:  
\- active count 8 allowed  
\- 9th join rejected  
\- concurrent joins cannot exceed 8

Invites:  
\- valid invite  
\- expired invite  
\- revoked invite  
\- exhausted invite  
\- max uses concurrent safety

Membership:  
\- duplicate active membership rejected  
\- leave preserves history  
\- remove preserves history  
\- rejoin does not erase previous membership period

Captain:  
\- Captain transfer success  
\- transfer to non-member rejected  
\- transfer to inactive member rejected  
\- Captain cannot leave before transfer  
\- Captain cannot remove self through member removal endpoint

Authorization:  
\- member cannot perform Captain actions  
\- Captain cannot alter another Team  
\- GameMaster cannot alter persistent Team membership by default  
\- OrganizationAdmin cross-tenant denial

Archive:  
\- archived Team rejects new invites/join  
\- history readable  
\- no hard delete

Regression:  
\- Identity/Device  
\- Organization/Tenant  
\- Phase 0 infrastructure

32\. PILOT v0.1 REQUIREMENT

Pilot requires full Team domain, not a temporary simplified Team table.

Pilot UI may expose only:  
\- create Team  
\- invite  
\- join  
\- members  
\- captain  
\- eligibility

But underlying domain must remain production-compatible.

33\. DEPENDENCIES FOR NEXT PHASE

Game & Game Session may rely on Team only after:  
\- Team persistence frozen  
\- Team capacity enforced  
\- Captain capability stable  
\- membership history stable  
\- Team APIs stable  
\- invite/join tested  
\- public identifiers stable

34\. DEFINITION OF DONE

Team Management design is frozen when:  
\- persistent Team vs Session roster separation is explicit  
\- Team size/capacity rules are explicit  
\- Captain behavior is explicit  
\- membership history is preserved  
\- invite lifecycle is defined  
\- API endpoints are defined  
\- permissions are defined  
\- events are defined  
\- concurrency/idempotency rules are defined  
\- Pilot uses same production Team architecture  
\- no unresolved dependency with Identity/Organization/Session remains  
