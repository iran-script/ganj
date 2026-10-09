NEXO — Game & Game Session Domain Specification v1

STATUS  
FROZEN DOMAIN BASELINE  
Purpose: define the reusable Game definition and the real execution lifecycle of Game Session before implementation.

1\. POSITION IN ARCHITECTURE

Depends on:  
\- Phase 0 Foundation  
\- Identity & Player Device  
\- Organizations/Tenant  
\- Team Management  
\- Product Blueprint  
\- API Contract  
\- Database Specification  
\- State Machine Specification  
\- Event Catalog  
\- Permission Matrix

Provides upstream capability for:  
\- Billing Gate  
\- Scenario Assignment  
\- Runtime bootstrap  
\- GPS/Checkpoint  
\- QR  
\- Live GameMaster  
\- Scoring/Leaderboard  
\- Analytics

2\. GAME DEFINITION

Game is a reusable commercial/content container.

Game is NOT a live execution.

Game contains:  
\- organization ownership  
\- name/slug  
\- description  
\- status  
\- default timezone  
\- pricing references  
\- general rules/configuration  
\- future store/commerce availability  
\- future scenario eligibility/rules

Game status:  
\- DRAFT  
\- ACTIVE  
\- ARCHIVED

Rules:  
\- only ACTIVE Game may be used for new Game Session unless privileged internal workflow explicitly allows otherwise  
\- archive preserves historical Sessions  
\- no destructive delete for Games with history

3\. GAME SESSION DEFINITION

Game Session is one concrete execution of a Game.

A Game Session owns:  
\- organization\_id  
\- game\_id  
\- lifecycle state  
\- registration window  
\- scheduled start  
\- registered teams  
\- Session-specific Player roster  
\- pricing snapshot references  
\- scenario assignments  
\- runtime instances  
\- operational timeline  
\- finalization/cancellation data

Session is the commercial/operational boundary for one real event.

4\. SESSION CAPACITY

Each Game Session:  
\- minimum 1 active Team  
\- maximum 5 active Teams

Each registered Team:  
\- 4 to 8 eligible roster Players at Session readiness/start validation

Maximum theoretical players per Session:  
5 Teams × 8 Players \= 40 Players

Capacity enforcement:  
\- server-authoritative  
\- transaction \+ lock  
\- sixth active Team rejected  
\- concurrent registration cannot exceed capacity

5\. SESSION STATES

Canonical states:  
\- DRAFT  
\- REGISTRATION  
\- READY  
\- RUNNING  
\- PAUSED  
\- FINISHED  
\- CANCELLED

Terminal:  
\- FINISHED  
\- CANCELLED

No generic reopen.

6\. DRAFT

DRAFT means Session is being configured.

Allowed:  
\- update safe Session configuration  
\- set registration window  
\- configure schedule/timezone  
\- prepare pricing references  
\- prepare scenario assignment policy  
\- operational setup

Transition:  
DRAFT \-\> REGISTRATION

Action:  
open\_registration

Guards:  
\- Game valid  
\- actor authorized  
\- Session config valid  
\- pricing resolvable enough for registration policy

7\. REGISTRATION

REGISTRATION allows Team enrollment.

Allowed:  
\- Team registration  
\- Team removal before Start  
\- roster updates according to policy  
\- player/device preparation  
\- scenario assignment preparation  
\- billing/top-up preparation

Transition:  
REGISTRATION \-\> READY

Action:  
close\_registration / prepare\_session

Guards:  
\- 1..5 active Teams  
\- each Team Session roster 4..8  
\- scenario assignment exists for every Team  
\- billing preconditions satisfiable

8\. READY

READY means the Session is configured and waiting for operational start.

Start preconditions are checked again at execution time.

READY \-\> RUNNING only via start\_session.

9\. RUNNING

RUNNING means gameplay is active.

Effects:  
\- roster frozen  
\- pricing frozen  
\- scenario versions pinned  
\- runtime Team instances active  
\- device-sensitive gameplay allowed  
\- location tracking policy may activate  
\- Session timeline operational

Allowed transitions:  
RUNNING \-\> PAUSED  
RUNNING \-\> FINISHED  
RUNNING \-\> CANCELLED only Emergency Cancel

10\. PAUSED

PAUSED preserves runtime state while stopping effective gameplay timers.

Pause does NOT:  
\- delete runtime  
\- reset node progress  
\- alter audit wall-clock timestamps

Resume:  
PAUSED \-\> RUNNING

Finish:  
PAUSED \-\> FINISHED allowed

Emergency cancel:  
PAUSED \-\> CANCELLED with elevated authorization

11\. FINISHED

FINISHED is terminal.

Finish produces:  
\- final score snapshot/reference  
\- runtime closure  
\- final Session/team/player statuses  
\- consumed entitlement summary  
\- billing summary  
\- timeline closure  
\- finish timestamp

After FINISHED:  
\- no normal runtime mutation  
\- no normal score mutation  
\- corrections only via explicit audited adjustment mechanisms without reopening Session

12\. CANCELLED

CANCELLED is terminal.

Before Start:  
\- DRAFT/REGISTRATION/READY may cancel  
\- billing reservations/top-ups resolved according to policy

After Start:  
\- only Emergency Cancel  
\- elevated permission  
\- reason mandatory  
\- financial resolution required  
\- audit/event required

No generic reopen.

13\. REGISTRATION WINDOW

Session may define:  
\- registration\_opens\_at  
\- registration\_closes\_at

Rules:  
\- register/remove Team only within allowed window by default  
\- authorized override may exist  
\- override requires reason \+ audit  
\- client clock not authoritative

14\. TEAM REGISTRATION

Register Team into Session requires:  
\- Session REGISTRATION  
\- actor authorized  
\- Team ACTIVE  
\- Team eligible for game  
\- capacity below 5  
\- Team not already registered  
\- no conflicting User roster participation once roster generated

Creates:  
\- game\_session\_team record  
\- registration timestamp  
\- later roster linkage

Event:  
game\_session.team\_registered.v1

15\. TEAM REMOVAL PRE-START

Before Start:  
\- Captain own Team may withdraw where policy permits  
\- GameMaster/OrgAdmin may remove with reason

Effect:  
\- Session team registration becomes REMOVED  
\- persistent Team remains unchanged  
\- billing/reservation reconciliation triggered later by Billing domain

After Start:  
\- normal removal not allowed  
\- live disqualification is separate Session Team transition

16\. SESSION TEAM STATES

Canonical:  
\- REGISTERED  
\- READY  
\- ACTIVE  
\- PAUSED  
\- FINISHED  
\- REMOVED  
\- DISQUALIFIED

REGISTERED \-\> READY  
when Team readiness criteria satisfied

READY \-\> ACTIVE  
on Session Start

ACTIVE \-\> PAUSED  
team-specific pause

PAUSED \-\> ACTIVE  
resume

ACTIVE \-\> FINISHED  
team completes runtime or Session finalizes

REGISTERED/READY \-\> REMOVED  
pre-start removal

ACTIVE/PAUSED \-\> DISQUALIFIED  
authorized reason \+ audit

17\. SESSION PLAYER PARTICIPATION

Canonical participation states:  
\- JOINED  
\- READY  
\- ACTIVE  
\- DISCONNECTED  
\- REMOVED  
\- DISQUALIFIED  
\- FINISHED

This state is separate from User account status.

18\. ROSTER MODEL

Persistent Team membership is NOT the same as Session roster.

Session roster represents Players participating in this specific Session.

Before Start:  
\- roster may track allowed membership changes  
\- billing may adjust accordingly  
\- readiness/device state changes

At Start:  
\- roster snapshot becomes immutable for current execution

Persistent Team changes after Start:  
\- do not rewrite frozen Session roster

19\. ROSTER SNAPSHOT

At Start, each Team receives immutable roster snapshot containing at minimum:  
\- Team reference  
\- participating Users  
\- membership context  
\- Session Player IDs  
\- active/bound Device references  
\- readiness state  
\- billable participation references  
\- pricing snapshot references  
\- eligibility metadata  
\- frozen\_at  
\- snapshot version

Snapshot exists for audit/recovery/historical integrity.

20\. READY RULE

A Team may have 4..8 roster members.

Session Start requires:  
\- at least 4 READY Players per Team

Not every 5th–8th Player must be Ready.

READY action requires:  
\- authenticated User  
\- User belongs to Session roster  
\- valid Device Binding  
\- valid Session state  
\- valid billing participation/state when Billing domain active

21\. LATE JOIN

Default:  
ENABLED only for Users already in frozen pre-start roster.

After Session Start:  
\- no new User may be added to frozen roster  
\- a frozen roster Player who was not active at Start may Late Join after:  
  \- auth validation  
  \- Device Binding  
  \- billing coverage  
  \- Session RUNNING/PAUSED rules

Late Join does not create new billable identity if already covered by frozen billable roster.

22\. DEVICE BINDING

Per Game Session Player:  
\- one active bound Device

Start validation:  
\- at least 4 valid bound Devices per Team corresponding to ready Players

Sensitive actions later require bound Device:  
\- Ready  
\- GPS submit  
\- QR scan  
\- runtime submission  
\- AR completion

Rebind:  
\- explicit audited action  
\- old/new device recorded  
\- reason required for staff-driven rebind

23\. SCENARIO ASSIGNMENT BOUNDARY

Before Start:  
\- each registered Team must have exactly one valid ScenarioVersion assignment

Assignment mode:  
\- MANUAL  
\- AUTOMATIC\_SHUFFLE

At Start:  
\- ScenarioVersion pinned  
\- assignment frozen into runtime instance

New Scenario publish must not affect active Session.

Scenario design itself belongs to Scenario domain.

24\. BILLING GATE BOUNDARY

Session domain does not calculate provider payment truth.

It consumes a Billing Gate result.

Start requires:  
\- Billing Gate PASS

Billing Gate may be satisfied by:  
\- verified payment  
\- valid credit  
\- approved alternative settlement according to policy

No client-provided “paid=true”.

25\. PRICING FREEZE

Before Start:  
\- current applicable pricing resolved

At Start:  
\- pricing snapshots frozen per billable participation/session policy

After Start:  
\- mutable current Game price cannot alter current Session financial facts

26\. ATOMIC START

start\_session is a critical command.

Must be:  
\- transactional  
\- idempotent  
\- concurrency protected

Start algorithm:  
1\. lock Session  
2\. verify state \= READY  
3\. verify 1..5 active Teams  
4\. verify each Team roster 4..8  
5\. verify \>=4 Ready Players per Team  
6\. verify required Device Bindings  
7\. verify Billing Gate PASS  
8\. verify ScenarioVersion assignment for each Team  
9\. freeze Team roster snapshots  
10\. freeze pricing snapshots/references  
11\. pin scenario assignments  
12\. create/activate Session Player runtime state  
13\. create Team Scenario runtime instances  
14\. emit game\_session.started.v1  
15\. transition Session to RUNNING  
16\. commit

Any failure:  
\- full rollback  
\- no partially running Session  
\- idempotent retry returns consistent result

27\. DOUBLE START

Duplicate start request:  
\- must not create duplicate runtime  
\- must not charge again  
\- must not duplicate roster snapshots  
\- must not emit conflicting state history

Same Idempotency-Key \+ same request:  
\- same logical result

Different payload with same key:  
\- IDEMPOTENCY\_CONFLICT

28\. PAUSE SESSION

pause\_session:  
RUNNING \-\> PAUSED

Actor:  
\- assigned GameMaster  
\- OrganizationAdmin  
\- privileged SuperAdmin exceptional

Effects:  
\- gameplay timers pause  
\- runtime states preserved  
\- realtime update  
\- timeline/audit event

29\. RESUME SESSION

resume\_session:  
PAUSED \-\> RUNNING

Guards:  
\- no blocking incident/hold  
\- actor authorized

Effects:  
\- gameplay timers resume using server timestamps  
\- no timer reset

30\. TEAM-SPECIFIC PAUSE

GameMaster may pause one Session Team independently where supported.

Team:  
ACTIVE \-\> PAUSED

Other Teams may continue.

Session may remain RUNNING.

Runtime timer handling must isolate Team pause.

31\. FINISH SESSION

finish\_session permitted from:  
\- RUNNING  
\- PAUSED

May be triggered when:  
\- operational completion criteria satisfied  
\- authorized GameMaster/Admin chooses valid finish action

Effects:  
\- finalize Teams  
\- finalize Player participation  
\- close runtime  
\- score finalization interface  
\- entitlement closure interface  
\- billing summary  
\- final timestamps  
\- event/timeline

32\. EMERGENCY CANCEL

Post-start cancellation:  
\- not ordinary cancel  
\- explicit emergency action

Required:  
\- elevated capability  
\- reason  
\- actor  
\- financial resolution state  
\- timeline/audit  
\- runtime cancellation

33\. GAME SESSION TIMELINE

Append-only operational history should include relevant events:  
\- session.created  
\- registration.opened  
\- registration.closed  
\- team.registered  
\- team.removed  
\- player.ready/unready  
\- scenario.assigned  
\- billing.validated  
\- session.started  
\- team.paused/resumed  
\- session.paused/resumed  
\- player.late\_joined  
\- player.disconnected/reconnected  
\- player.disqualified  
\- session.finished  
\- session.cancelled

Canonical event names use Event Catalog versioned taxonomy.

34\. GAME SESSION DB ENTITIES

Relevant canonical tables:  
\- games  
\- game\_prices  
\- game\_sessions  
\- game\_session\_teams  
\- game\_session\_players  
\- game\_session\_roster\_snapshots  
\- game\_session\_state\_transitions  
\- session\_ready\_events

Foreign domains referenced:  
\- pricing\_snapshots  
\- billable\_participations  
\- scenario\_assignments  
\- team\_scenario\_instances

35\. CRITICAL CONSTRAINTS

game\_sessions:  
\- organization\_id required  
\- game\_id required  
\- valid state

game\_session\_teams:  
\- unique(game\_session\_id, team\_id)  
\- max 5 active Team via domain lock strategy

game\_session\_players:  
\- unique(game\_session\_id, user\_id)  
\- one User in one Session only one Team roster

roster snapshots:  
\- unique(team registration, version)

36\. AUTHORIZATION

session.create  
\- OrganizationAdmin  
\- GameMaster only if organization policy permits  
\- SuperAdmin scoped/exceptional

session.read  
\- OrgAdmin own org  
\- assigned GameMaster  
\- participating Player limited

session.update\_prestart  
\- OrgAdmin  
\- assigned GameMaster

session.open/close\_registration  
\- OrgAdmin  
\- assigned GameMaster

session.register\_team  
\- Captain own Team  
\- operational override GameMaster/OrgAdmin

session.start  
\- assigned GameMaster  
\- OrgAdmin  
\- SuperAdmin exceptional

session.pause/resume  
\- assigned GameMaster  
\- OrgAdmin  
\- SuperAdmin exceptional

session.finish  
\- assigned GameMaster  
\- OrgAdmin

post-start emergency cancel  
\- explicit elevated permission \+ reason

37\. CROSS-TENANT RULE

Session always belongs to Organization.

All Game/Session queries:  
\- organization scoped server-side  
\- Game must belong to same Organization as Session  
\- GameMaster must be assigned/authorized  
\- no client organization\_id may bypass scope

38\. PUBLIC IDENTIFIERS

API uses:  
\- game public\_id  
\- session public\_id  
\- session team/player public identifiers where exposed

Internal sequence IDs are not client resource identifiers.

39\. API CONTRACT

Canonical endpoints include:

Games:  
POST /api/v1/games  
GET /api/v1/games  
GET /api/v1/games/{game\_id}  
PATCH /api/v1/games/{game\_id}  
POST /api/v1/games/{game\_id}/archive

Sessions:  
POST /api/v1/game-sessions  
GET /api/v1/game-sessions  
GET /api/v1/game-sessions/{session\_id}  
PATCH /api/v1/game-sessions/{session\_id}  
POST /api/v1/game-sessions/{session\_id}/open-registration  
POST /api/v1/game-sessions/{session\_id}/close-registration  
POST /api/v1/game-sessions/{session\_id}/register-team  
DELETE /api/v1/game-sessions/{session\_id}/teams/{team\_id}  
POST /api/v1/game-sessions/{session\_id}/bind-device  
POST /api/v1/game-sessions/{session\_id}/rebind-device  
POST /api/v1/game-sessions/{session\_id}/ready  
POST /api/v1/game-sessions/{session\_id}/start  
POST /api/v1/game-sessions/{session\_id}/pause  
POST /api/v1/game-sessions/{session\_id}/resume  
POST /api/v1/game-sessions/{session\_id}/finish  
POST /api/v1/game-sessions/{session\_id}/cancel  
GET /api/v1/game-sessions/{session\_id}/state  
GET /api/v1/game-sessions/{session\_id}/timeline

40\. EVENTS

Canonical:  
game\_session.created.v1  
game\_session.registration\_opened.v1  
game\_session.registration\_closed.v1  
game\_session.team\_registered.v1  
game\_session.team\_removed.v1  
game\_session.player\_added\_to\_roster.v1  
game\_session.player\_removed\_from\_roster.v1  
game\_session.player\_ready.v1  
game\_session.player\_unready.v1  
game\_session.scenario\_assigned.v1  
game\_session.billing\_validated.v1  
game\_session.start\_failed.v1  
game\_session.started.v1  
game\_session.paused.v1  
game\_session.resumed.v1  
game\_session.finished.v1  
game\_session.cancelled.v1

Session Team:  
session\_team.ready.v1  
session\_team.activated.v1  
session\_team.paused.v1  
session\_team.resumed.v1  
session\_team.finished.v1  
session\_team.removed.v1  
session\_team.disqualified.v1

Player:  
player\_session.joined.v1  
player\_session.ready.v1  
player\_session.activated.v1  
player\_session.disconnected.v1  
player\_session.reconnected.v1  
player\_session.late\_joined.v1  
player\_session.removed.v1  
player\_session.disqualified.v1  
player\_session.finished.v1

41\. AUDIT

Mandatory audit/reason for:  
\- registration override  
\- Team removal override  
\- Device rebind by staff  
\- post-start cancel  
\- player/team disqualification  
\- manual operational recovery

State transitions themselves retained append-only.

42\. CONCURRENCY HOTSPOTS

Must protect:  
\- Session Team capacity 5  
\- Team roster updates  
\- one User per Session  
\- Ready state changes  
\- Device binding/rebind  
\- Session Start  
\- Cancel/Finish race  
\- Team registration/removal race

43\. IDEMPOTENCY

Required:  
\- start\_session  
\- device rebind  
\- high-risk mutation commands

Recommended:  
\- Team register/remove  
\- ready action where retries likely

44\. SESSION TIME

All timestamps:  
\- server UTC

Session timezone:  
\- stored separately for display/scheduling

Client clock:  
\- never authoritative for state transition

45\. PILOT v0.1

Pilot must use real Game/Game Session domain.

Allowed simplifications:  
\- only one active Game/municipality initially  
\- manually configured Session  
\- scenario assignment via simple admin/seed  
\- no public payment flow

Not allowed:  
\- temporary “demo\_session” table  
\- bypassing state machine  
\- manually setting RUNNING in DB  
\- hardcoding Team count  
\- skipping roster freeze  
\- client-controlled Start

46\. TEST REQUIREMENTS

Game:  
\- create/update/archive scope  
\- cross-tenant denial

Capacity:  
\- 1 Team works  
\- 5 Teams works  
\- 6th rejected  
\- concurrent registration cannot exceed 5

Roster:  
\- Team with 3 rejected for Ready/start eligibility  
\- Team 4..8 valid  
\- 9 impossible from Team domain  
\- duplicate User Session participation rejected

Ready:  
\- \<4 Ready blocks Team/session readiness  
\- exactly 4 works  
\- additional roster players may remain unready

Device:  
\- Ready from wrong/unbound device rejected  
\- valid bound device accepted  
\- rebind audited

Start:  
\- missing scenario blocks  
\- billing fail blocks  
\- missing device blocks  
\- incomplete Team blocks  
\- successful start creates all runtime/snapshots atomically  
\- double start safe  
\- partial failure rolls back

Pause/Resume:  
\- states correct  
\- timers preserve semantics

Late Join:  
\- frozen roster player allowed  
\- non-roster User rejected

Finish:  
\- RUNNING/PAUSED only  
\- terminal behavior enforced

Cancel:  
\- pre-start valid  
\- post-start requires emergency capability/reason/financial resolution  
\- terminal

Authorization:  
\- Player cannot start  
\- Captain cannot start  
\- GameMaster unassigned cannot manage Session  
\- OrgAdmin cross-tenant denied

47\. DEPENDENCY FOR SCENARIO/RUNTIME

Scenario Core may rely on:  
\- Game/Session identity  
\- Organization ownership  
\- Team Session registration  
\- assignment boundary

Runtime may rely on:  
\- frozen roster  
\- pinned ScenarioVersion  
\- Session start lifecycle  
\- Team/Player participation states  
\- pause/resume state

No Scenario/Runtime coding should invent alternative Session lifecycle.

48\. DEFINITION OF DONE

Game & Game Session design is frozen when:  
\- Game vs Session distinction explicit  
\- capacity 1..5 explicit  
\- Team roster 4..8 explicit  
\- readiness \>=4 explicit  
\- Session states/transitions explicit  
\- roster freeze explicit  
\- late join roster-only explicit  
\- Device Binding boundary explicit  
\- Billing Gate boundary explicit  
\- Scenario assignment boundary explicit  
\- Atomic Start fully specified  
\- Pause/Resume/Finish/Cancel semantics explicit  
\- authorization/events/audit/concurrency specified  
\- Pilot uses same architecture  
\- no unresolved dependency with Team/Identity/Organization remains  
