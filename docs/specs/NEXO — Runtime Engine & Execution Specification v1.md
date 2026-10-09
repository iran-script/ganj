NEXO — Runtime Engine & Execution Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define the authoritative runtime execution model for Team and Player gameplay after Game Session Start. This specification connects frozen Session, ScenarioVersion, Device, Event, Score, GPS/QR/AR and Realtime contracts without allowing client-owned progression.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Phase 0 Foundation  
\- Identity & Player Device  
\- Organizations/Tenant  
\- Team Management  
\- Game & Game Session  
\- Scenario Core & Versioning  
\- Product Blueprint  
\- API Contract  
\- Database Specification  
\- State Machine Specification  
\- Event Catalog  
\- Permission Matrix

Provides upstream capability for:  
\- GPS node execution  
\- QR node execution  
\- Puzzle submissions  
\- Co-op roles  
\- Team Together / Split Team  
\- Score  
\- Hints  
\- AR  
\- Live Challenges  
\- Reconnect/Recovery  
\- GameMaster control  
\- Analytics replay

2\. CORE PRINCIPLE — SERVER AUTHORITATIVE

Runtime truth lives on Server.

Client may:  
\- request start/submit/acknowledge actions  
\- send evidence  
\- render state  
\- cache safe projections

Client may not:  
\- set current node  
\- mark node complete directly  
\- set score  
\- set timer state  
\- choose unauthorized next edge  
\- mutate runtime variables arbitrarily  
\- bypass required evidence  
\- alter another Player/Team runtime

3\. RUNTIME TEMPLATE VS INSTANCE

ScenarioVersion \= immutable template.  
TeamScenarioInstance \= one Team's real execution.

Every Team registered in a running Session receives:  
\- one pinned ScenarioVersion  
\- one TeamScenarioInstance  
\- its own node instances  
\- its own variable state  
\- its own timers  
\- its own role assignments  
\- its own score/hint/challenge state references  
\- deterministic random seed

Teams never share mutable runtime state.

4\. TEAM SCENARIO INSTANCE

Canonical fields:  
\- public\_id  
\- game\_session\_id  
\- game\_session\_team\_id  
\- scenario\_version\_id  
\- status  
\- current\_node\_instance\_id nullable  
\- random\_seed  
\- score\_cached/read-model ref  
\- effective\_elapsed\_seconds  
\- started\_at nullable  
\- completed\_at nullable  
\- timestamps

Constraint:  
\- unique(game\_session\_team\_id)

5\. INSTANCE STATES

Canonical:  
\- NOT\_STARTED  
\- ACTIVE  
\- PAUSED  
\- COMPLETED  
\- FAILED  
\- CANCELLED

Transitions:  
NOT\_STARTED \-\> ACTIVE  
ACTIVE \-\> PAUSED  
PAUSED \-\> ACTIVE  
ACTIVE \-\> COMPLETED  
ACTIVE \-\> FAILED only if scenario supports failure outcome  
NOT\_STARTED/ACTIVE/PAUSED \-\> CANCELLED

Terminal:  
\- COMPLETED  
\- FAILED  
\- CANCELLED

6\. NODE INSTANCE MODEL

Every executable Scenario Node is represented as runtime NodeInstance.

Canonical fields:  
\- public\_id  
\- team\_scenario\_instance\_id  
\- scenario\_node\_id  
\- status  
\- attempt\_count  
\- started\_at nullable  
\- completed\_at nullable  
\- state\_json extension-only  
\- version\_no optimistic lock  
\- timestamps

Constraint:  
\- unique(team\_scenario\_instance\_id, scenario\_node\_id)

7\. NODE INSTANCE STATES

Canonical:  
\- LOCKED  
\- AVAILABLE  
\- ACTIVE  
\- COMPLETED  
\- FAILED  
\- SKIPPED

Transitions:  
LOCKED \-\> AVAILABLE  
AVAILABLE \-\> ACTIVE  
ACTIVE \-\> COMPLETED  
ACTIVE \-\> FAILED if node supports failure  
AVAILABLE/ACTIVE \-\> SKIPPED only authorized GameMaster/Admin or explicit Scenario rule

Terminal per NodeInstance:  
\- COMPLETED  
\- FAILED  
\- SKIPPED

No:  
LOCKED \-\> COMPLETED directly by Client.

8\. INITIALIZATION AT SESSION START

Atomic Session Start creates Runtime bootstrap.

For each Session Team:  
1\. create TeamScenarioInstance  
2\. pin ScenarioVersion  
3\. freeze random\_seed  
4\. create required NodeInstances or lazy-create safely  
5\. mark Start node AVAILABLE/ACTIVE according to handler contract  
6\. initialize default variables  
7\. initialize role/timer state  
8\. emit runtime.instance\_created.v1  
9\. emit runtime.started.v1 when activated

All bootstrap must be part of the Session Start transaction or reliably coupled using transactional outbox without partial playable state.

9\. LAZY VS EAGER NODE INSTANCE CREATION

Allowed implementation choices:  
\- eager create all node instances at runtime bootstrap  
\- lazy create on unlock

Requirement:  
\- semantics identical  
\- duplicate creation impossible  
\- runtime graph references pinned ScenarioVersion only  
\- historical status reconstructable

Prefer implementation that keeps operational complexity low for initial release.

10\. CURRENT NODE MODEL

Do not rely solely on one current\_node pointer for branching/co-op scenarios.

TeamScenarioInstance.current\_node\_instance\_id:  
\- may represent primary focus for simple UI  
\- is not the sole source of truth

Authoritative active set:  
\- NodeInstances with AVAILABLE/ACTIVE state  
\- graph/condition state

This avoids future redesign for parallel branches.

11\. RUNTIME COMMAND MODEL

All mutations are Command-based.

Canonical commands include:  
\- start\_node  
\- submit\_action  
\- complete\_node internal/validated  
\- fail\_node  
\- skip\_node  
\- assign\_role  
\- release\_role  
\- consume\_hint  
\- pause\_team  
\- resume\_team  
\- reconnect\_player  
\- finish\_team

Additional Node-specific commands registered through handler contracts.

12\. RUNTIME COMMAND RECORD

Canonical fields:  
\- command\_id  
\- game\_session\_id  
\- team\_scenario\_instance\_id nullable  
\- player\_id nullable  
\- command\_type  
\- idempotency\_key  
\- request\_payload  
\- result\_payload  
\- status  
\- created\_at  
\- completed\_at nullable

Unique:  
\- idempotency\_key where applicable  
\- command\_id

13\. COMMAND STATES

Canonical:  
\- RECEIVED  
\- PROCESSING  
\- SUCCEEDED  
\- REJECTED  
\- FAILED

RECEIVED \-\> PROCESSING  
PROCESSING \-\> SUCCEEDED  
PROCESSING \-\> REJECTED  
PROCESSING \-\> FAILED

REJECTED:  
\- business guard failed

FAILED:  
\- technical failure

Retry behavior follows idempotency rules.

14\. COMMAND PROCESSING PIPELINE

Every sensitive command:  
1\. authenticate  
2\. resolve Player/User/Device  
3\. resolve Organization/Session/Team scope  
4\. verify Session state  
5\. verify Team runtime state  
6\. verify Node/Role eligibility  
7\. acquire idempotency reservation  
8\. acquire transaction/lock  
9\. re-check mutable guards  
10\. execute Node/Domain handler  
11\. persist state  
12\. write domain/runtime event  
13\. write outbox where needed  
14\. commit  
15\. return safe projection

15\. IDEMPOTENCY

Required for:  
\- node completion  
\- puzzle submission where retry can duplicate effect  
\- QR-trigger completion  
\- AR completion  
\- hint consume  
\- GameMaster skip  
\- role change when retryable  
\- manual score-affecting runtime action

Same key \+ same request:  
\- same logical result

Same key \+ different request:  
\- IDEMPOTENCY\_CONFLICT

16\. CONCURRENCY

Hotspots:  
\- same Node completion from multiple Players  
\- simultaneous Team Together submissions  
\- role assignment  
\- branch unlock  
\- score/hint side effects  
\- pause during submit  
\- reconnect during command execution

Required:  
\- DB transaction  
\- row lock or optimistic version  
\- deterministic conflict behavior  
\- no duplicated completion side effects

17\. NODE START

start\_node allowed when:  
\- Node AVAILABLE  
\- actor/role eligible  
\- TeamScenarioInstance ACTIVE  
\- Session RUNNING or policy allows PAUSED read-only only

Effects:  
\- AVAILABLE \-\> ACTIVE  
\- started\_at set  
\- runtime.node\_started.v1  
\- Node handler may initialize timer/state

18\. NODE SUBMISSION

submit\_action:  
\- accepts evidence/input only  
\- does not accept final state authority

Examples:  
\- answer text  
\- selected option  
\- QR token  
\- location evidence reference  
\- AR evidence payload  
\- photo/media ref  
\- acknowledgement

Handler determines:  
\- accepted  
\- rejected  
\- progress update  
\- completion eligibility

19\. NODE COMPLETION

Completion must be decided server-side.

Completion pipeline:  
1\. validate Node ACTIVE  
2\. validate actor/device/role  
3\. validate evidence/subtasks  
4\. update attempt/progress  
5\. set COMPLETED  
6\. emit runtime.node\_completed.v1  
7\. evaluate outgoing edges  
8\. unlock next Node(s)  
9\. emit unlock events  
10\. trigger score/hint/side effects through contracts

20\. NODE FAILURE

Node may fail only if node type/config supports failure.

Failure:  
\- ACTIVE \-\> FAILED  
\- emit runtime.node\_failed.v1  
\- evaluate failure route if graph defines one

Failure is not generic for all nodes.

21\. NODE SKIP

Only:  
\- GameMaster/OrganizationAdmin according to Permission Matrix  
\- or explicit Scenario rule

Requirements:  
\- reason  
\- audit  
\- runtime.node\_skipped.v1  
\- evaluate next path

Player cannot skip directly.

22\. EDGE RESOLUTION

After Node terminal transition:  
\- resolve outgoing edges from pinned ScenarioVersion  
\- evaluate conditions server-side  
\- deterministic order by priority  
\- allow one or multiple next nodes according to node/graph semantics

Client never selects next node.

23\. BRANCHING

Branch Node may unlock:  
\- one edge  
\- multiple edges if semantics explicitly support parallel paths

Condition evaluation:  
\- typed  
\- deterministic  
\- based on current Runtime state  
\- no eval/arbitrary code

24\. PARALLEL ACTIVE NODES

Runtime supports multiple AVAILABLE/ACTIVE nodes for:  
\- split information  
\- parallel subtasks  
\- Team Together  
\- multi-point challenges  
\- live overlays

This capability is mandatory in data model even if Pilot UI initially focuses on one primary mission.

25\. RUNTIME VARIABLES

Template definitions from ScenarioVersion.  
Runtime values stored separately.

Canonical runtime variable context:  
\- team\_scenario\_instance\_id  
\- player\_id nullable  
\- node\_instance\_id nullable  
\- key  
\- typed value  
\- updated\_at

Rules:  
\- type validated  
\- server write authorization  
\- no arbitrary client mutation

26\. VARIABLE UPDATE

Allowed only through:  
\- Node handler  
\- Runtime command  
\- system rule  
\- explicit privileged GameMaster operation if later approved

Emit runtime.variable\_changed.v1 when operationally meaningful.

27\. RANDOMNESS

Use TeamScenarioInstance.random\_seed.

All gameplay-affecting random selection:  
\- deterministic  
\- reproducible  
\- event/audit friendly

Examples:  
\- Random Player  
\- randomized branch  
\- shuffled clue ordering

28\. PLAYER RUNTIME CONTEXT

Player runtime context includes:  
\- game\_session\_player  
\- Team  
\- TeamScenarioInstance  
\- bound Device  
\- participation state  
\- runtime role assignments  
\- visible/unlocked node projection  
\- pending challenges  
\- presence state

29\. PLAYER PRESENCE

Presence is distinct from runtime truth.

Presence states/concepts:  
\- online  
\- offline/disconnected  
\- last\_seen

Eventual consistency acceptable for presence.

Completion/score/state transitions require strong consistency.

30\. DISCONNECT

ACTIVE Player may become DISCONNECTED.

Disconnect does not:  
\- delete runtime state  
\- reset current mission  
\- invalidate Team progress  
\- change billing participation

Event:  
player\_session.disconnected.v1

31\. RECONNECT

Reconnect:  
\- valid token  
\- valid/recoverable Device binding  
\- valid Session participation  
\- Session RUNNING/PAUSED

Effects:  
\- participation returns ACTIVE where allowed  
\- latest safe runtime projection returned  
\- pending realtime state reconciled  
\- player\_session.reconnected.v1

32\. RECOVERY SOURCE OF TRUTH

Recovery must be possible from PostgreSQL durable state.

Redis may cache:  
\- presence  
\- latest projections  
\- short-lived locks  
\- realtime fan-out data

Redis must not be sole source for:  
\- node completion  
\- score  
\- hint consumption  
\- payment  
\- entitlement  
\- runtime progression

33\. TIMER MODEL

Canonical runtime timers:  
\- NOT\_STARTED  
\- RUNNING  
\- PAUSED  
\- FINISHED  
\- CANCELLED

Fields:  
\- team\_scenario\_instance\_id  
\- node\_instance\_id nullable  
\- timer\_type  
\- started\_at  
\- paused\_at nullable  
\- accumulated\_paused\_seconds  
\- duration\_seconds nullable  
\- status

34\. TIMER AUTHORITY

Server time only.

Effective elapsed:  
\- derived from timestamps  
\- subtract accumulated paused duration

Client countdown:  
\- presentation only

35\. TEAM/SESSION PAUSE EFFECT

Session Pause:  
\- all relevant Team/runtime gameplay timers pause

Team Pause:  
\- only that Team's timers pause

Audit wall-clock:  
\- continues normally

Runtime state preserved.

36\. ROLE ASSIGNMENTS

Runtime roles are separate from Team Captain and system roles.

Canonical fields:  
\- team\_scenario\_instance\_id  
\- node\_instance\_id/stage context  
\- game\_session\_player\_id  
\- role\_code  
\- slot\_no  
\- assigned\_at  
\- released\_at nullable  
\- assignment\_source

37\. ACTIVE CHALLENGE SLOTS

Per important Stage:  
\- maximum 4 active primary challenge slots

Team may have:  
\- 4–8 Players

Additional members:  
\- Support

Runtime must support:  
\- assignment  
\- release  
\- rotation  
\- role eligibility  
\- late-join participation from future stage

38\. ROLE CAPABILITY

Role code is not just presentation.

Node handlers may require capability:  
\- SCAN\_QR  
\- NAVIGATE  
\- DECODE  
\- OPERATE\_AR  
or equivalent registry-defined capability.

Wrong role:  
\- ACTION\_NOT\_ALLOWED / canonical error

39\. ROLE ROTATION

Rotation policy belongs to Runtime/Scenario config.

Possible:  
\- least recently used  
\- seeded random  
\- explicit mapping  
\- GameMaster override

Fairness history must be recoverable.

40\. TEAM TOGETHER / BARRIER

Barrier-style Node supports multiple required contributions.

Completion occurs only after required set satisfied.

Examples:  
\- all four active roles acknowledge  
\- four Players reach positions  
\- parallel subtasks complete

Each contribution:  
\- idempotent  
\- attributable  
\- persisted

41\. ATTEMPTS

Submission attempts are append-only or reconstructable.

NodeInstance.attempt\_count:  
\- updated transactionally

Attempt result concepts:  
\- SUBMITTED  
\- ACCEPTED  
\- REJECTED  
\- EXPIRED  
\- DUPLICATE

Correct answer may complete node according to handler rule.

42\. SAFE PLAYER PROJECTION

Player-facing Runtime response may include:  
\- current/revealed mission  
\- own/team-safe roles  
\- safe node config  
\- attempts remaining where allowed  
\- hints available  
\- timer  
\- score/leaderboard according to visibility policy

Must not include:  
\- correct answers  
\- hidden conditions  
\- future locked content  
\- secret admin config  
\- competitor private state

43\. RUNTIME API

Canonical reads:  
GET /api/v1/game-sessions/{session\_id}/runtime  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/runtime  
GET /api/v1/game-sessions/{session\_id}/players/me/runtime

Canonical mutations:  
POST /api/v1/runtime/nodes/{node\_instance\_id}/start  
POST /api/v1/runtime/nodes/{node\_instance\_id}/submit  
POST /api/v1/runtime/nodes/{node\_instance\_id}/complete

Important:  
Public complete endpoint does not mean trust client complete=true.  
It represents submission of completion evidence/command and server validation.

44\. RUNTIME EVENTS

Canonical Team runtime:  
\- runtime.instance\_created.v1  
\- runtime.started.v1  
\- runtime.paused.v1  
\- runtime.resumed.v1  
\- runtime.completed.v1  
\- runtime.failed.v1  
\- runtime.cancelled.v1

Node:  
\- runtime.node\_unlocked.v1  
\- runtime.node\_started.v1  
\- runtime.node\_submission\_received.v1  
\- runtime.node\_submission\_rejected.v1  
\- runtime.node\_completed.v1  
\- runtime.node\_failed.v1  
\- runtime.node\_skipped.v1

Other:  
\- runtime.variable\_changed.v1  
\- runtime.role\_assigned.v1  
\- runtime.role\_released.v1  
\- runtime.timer\_started.v1  
\- runtime.timer\_paused.v1  
\- runtime.timer\_resumed.v1  
\- runtime.timer\_finished.v1

45\. RUNTIME EVENT RECORD

Append-only:  
\- event\_id  
\- aggregate\_type  
\- aggregate\_id  
\- game\_session\_id  
\- team\_id nullable  
\- player\_id nullable  
\- event\_type  
\- payload\_version  
\- payload  
\- actor\_type  
\- actor\_id nullable  
\- occurred\_at  
\- created\_at

Unique:  
\- event\_id

46\. OUTBOX

Any Runtime event requiring:  
\- Realtime  
\- Queue  
\- external integration  
\- score projection  
\- analytics  
\- anti-cheat

must use transactional outbox where critical.

47\. REALTIME

Realtime is delivery, not truth.

Channels follow Permission Matrix/Event Catalog.

Reconnect must recover from API/DB even if realtime events were missed.

48\. SCORE BOUNDARY

Runtime emits score-affecting facts/actions.

Runtime does not expose direct SET\_SCORE.

Scoring domain:  
\- creates append-only score\_events  
\- maintains Team score projection

Node completion can trigger scoring contract.

49\. HINT BOUNDARY

Runtime validates:  
\- active Node  
\- eligible Player/Team  
\- next available hint

Hint domain/commerce determines:  
\- default built-in hint  
\- purchased entitlement  
\- atomic consumption  
\- penalties

Same hint credit cannot double-consume.

50\. GPS BOUNDARY

Runtime requests/consumes verified location result.

GPS domain verifies:  
\- coordinates  
\- accuracy  
\- checkpoint/geofence  
\- sequence  
\- Device/Session

Runtime must not calculate location truth from client-provided reached=true.

51\. QR BOUNDARY

QR domain returns validated trigger result/token.

Runtime verifies:  
\- current Node context  
\- token/trigger binding  
\- Team/Player/Device  
\- replay status

52\. AR BOUNDARY

AR domain validates:  
\- challenge nonce  
\- asset version  
\- evidence  
\- device compatibility  
\- runtime eligibility

Runtime consumes verified result.

53\. LIVE CHALLENGE BOUNDARY

Live Challenge is parallel overlay by default.

It should not reset main Runtime node.

Runtime may:  
\- expose challenge context  
\- accept challenge outcome signal

Challenge lifecycle remains separate domain.

54\. GAME MASTER OPERATIONS

Runtime-sensitive GameMaster actions:  
\- pause/resume Team  
\- skip Node  
\- assign/swap role  
\- give hint  
\- send message  
\- controlled recovery

Requirements:  
\- assignment/authorization  
\- reason where sensitive  
\- audit  
\- event  
\- no hidden direct DB mutation

55\. FAILURE HANDLING

Technical command failure:  
\- no partial state  
\- transaction rollback  
\- command FAILED  
\- retry if safe

Business rejection:  
\- command REJECTED  
\- stable error  
\- no hidden mutation

56\. RETRY / WORKER MODEL

Workers consuming runtime/outbox events:  
\- at-least-once  
\- idempotent  
\- exponential backoff  
\- failed/dead-letter state after limit

57\. STATE RECONSTRUCTION

Critical Runtime state should be reconstructable from:  
\- durable current-state tables  
\- append-only events where necessary

Event sourcing is not mandatory.

Hybrid model:  
\- normalized current state \+ append-only timeline

58\. SNAPSHOT / REPLAY

Operational replay later uses:  
\- Runtime events  
\- Session timeline  
\- Node states  
\- role changes  
\- timers  
\- simplified GPS route

Raw event history should support support/audit use.

59\. DATA OWNERSHIP

Runtime records inherit:  
\- Organization via Session  
\- Session  
\- Team  
\- Player where relevant

Cross-tenant access prohibited.

60\. PUBLIC IDENTIFIERS

All client-facing Runtime resources use public opaque IDs.

No internal sequence ID dependency in Flutter/Admin.

61\. PERFORMANCE

Runtime command path must stay lightweight.

Avoid:  
\- loading entire Scenario graph on every submit if cache/read model possible  
\- N+1 queries  
\- synchronous heavy analytics  
\- expensive cross-team aggregation in command transaction

62\. REDIS USAGE

Allowed:  
\- presence  
\- caches  
\- distributed short locks  
\- realtime support  
\- hot projections

Not allowed as only durable store:  
\- completion  
\- score  
\- variables  
\- role assignment  
\- timers  
\- critical command result

63\. PILOT v0.1

Pilot must use real Runtime architecture.

Required:  
\- TeamScenarioInstance  
\- NodeInstance  
\- commands/idempotency  
\- branching  
\- variables/conditions  
\- timer  
\- role assignment  
\- reconnect  
\- event timeline  
\- GPS/QR integration contracts  
\- score/hint integration

Allowed simplification:  
\- limited Node handlers  
\- simple Admin  
\- no full Visual Builder  
\- no advanced AR  
\- no advanced Live Challenge

Forbidden:  
\- hardcoded numeric stage progression  
\- client-side progression truth  
\- one giant JSON progress blob as sole state  
\- temporary demo runtime tables

64\. TEST REQUIREMENTS — BOOTSTRAP

\- Session Start creates one TeamScenarioInstance per Team  
\- pinned ScenarioVersion correct  
\- same Session Start retry does not duplicate runtime  
\- seeds stable

65\. TEST REQUIREMENTS — NODE STATE

\- LOCKED cannot start  
\- AVAILABLE starts  
\- ACTIVE completes with valid evidence  
\- invalid evidence rejected  
\- terminal Node cannot complete twice  
\- skip requires permission/reason

66\. TEST REQUIREMENTS — BRANCHING

\- condition true selects expected edge  
\- false selects fallback  
\- priority deterministic  
\- parallel unlock works  
\- hidden future nodes not exposed

67\. TEST REQUIREMENTS — CONCURRENCY

\- two Players completing same Node concurrently \=\> one completion effect  
\- simultaneous barrier contributions safe  
\- pause vs submit race safe  
\- duplicate command same key same result  
\- same key different payload conflict

68\. TEST REQUIREMENTS — ROLES

\- correct role allowed  
\- wrong role rejected  
\- max 4 primary slots  
\- rotation history preserved  
\- Support player restrictions correct

69\. TEST REQUIREMENTS — TIMER

\- start  
\- pause  
\- resume  
\- finish  
\- Session pause affects timer  
\- Team-only pause isolation  
\- client clock ignored

70\. TEST REQUIREMENTS — RECONNECT

\- disconnect preserves runtime  
\- reconnect returns same state  
\- missed realtime does not lose progression  
\- revoked/wrong Device rejected according to binding policy

71\. TEST REQUIREMENTS — SECURITY

\- Player cannot set current Node  
\- Player cannot set score  
\- Player cannot mutate variable directly  
\- Player cannot access other Team runtime  
\- cross-tenant runtime denied  
\- future answers/conditions hidden  
\- unbound Device sensitive submit rejected

72\. TEST REQUIREMENTS — EVENTS/OUTBOX

\- completion writes runtime event  
\- event/outbox in same transaction where required  
\- retry consumer idempotent  
\- duplicate delivery safe

73\. DEPENDENCY FOR GPS / QR / SCORE

GPS implementation may start when:  
\- Runtime Node handler contract stable  
\- Session/Player/Device context stable

QR may start when:  
\- Node state and submit flow stable  
\- trigger result interface stable

Score/Hint may start when:  
\- completion event/action contract stable  
\- Team/runtime identifiers stable

74\. DEFINITION OF DONE

Runtime design is frozen when:  
\- Team runtime isolation explicit  
\- Node state machine explicit  
\- command processing explicit  
\- idempotency/concurrency explicit  
\- branching/parallel nodes supported  
\- variable/randomness model explicit  
\- timer semantics explicit  
\- roles/4-slot architecture explicit  
\- reconnect/recovery explicit  
\- Redis vs PostgreSQL responsibility explicit  
\- GPS/QR/AR/Score boundaries explicit  
\- events/realtime/audit explicit  
\- Pilot uses same production Runtime architecture  
\- no unresolved dependency with Session/Scenario remain

CONSISTENCY SYNC ADDENDUM v1

Database naming:  
\- Runtime role assignment persistence must use the canonical Database Specification entities role\_assignments / player\_role\_assignments according to scope.  
\- runtime\_role\_assignments in this document is a conceptual label, not authorization to introduce a duplicate table.

All Runtime API/Event/State semantics remain unchanged.  
s  
