NEXO — Co-op Roles & Team Mechanics Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define cooperative gameplay for NEXO teams of 4–8 players using a maximum of 4 active primary challenge slots per stage, while preserving meaningful participation for support members and future scalability.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Team Management  
\- Game & Game Session  
\- Scenario Core  
\- Runtime Engine  
\- Device Binding  
\- QR/GPS/AR handler contracts  
\- Permission Matrix  
\- Event Catalog

Provides upstream capability for:  
\- Role-based Node eligibility  
\- Team Together  
\- Split Information  
\- Split Team  
\- Chain Unlock  
\- Relay  
\- Multi-point Presence  
\- Synchronization Barrier  
\- role rotation  
\- support-player participation  
\- role-specific QR/AR/GPS behavior

2\. CORE PRINCIPLE

Important stages should not be trivially completable by one person.

A Team:  
\- contains 4–8 roster Players  
\- may have at most 4 active primary challenge slots in a stage  
\- may have additional Support Players  
\- may rotate active slots between stages

3\. ACTIVE SLOT RULE

Maximum active primary slots:  
4

This is a hard gameplay rule for important stages.

Team size:  
\- 4 players \=\> all may be active  
\- 5–8 players \=\> 4 primary slots \+ Support

Active slots are not permanent identities.

4\. SUPPORT PLAYER

Support is not inactive/disconnected.

Support Players may:  
\- help solve clues  
\- communicate  
\- receive visible context if Scenario permits  
\- receive Individual Challenge  
\- receive Live Challenge  
\- become active in later stages  
\- assist physical coordination

Support Players may not perform an action restricted to a primary role unless assigned.

5\. BASE ROLE TYPES

Initial role set:  
\- Scanner  
\- Navigator  
\- Decoder  
\- AR Operator

These are gameplay roles, not system roles.

Role codes should be extensible.

6\. ROLE CAPABILITY

Role assignment represents capability.

Example capability mappings:  
Scanner \-\> SCAN\_QR  
Navigator \-\> NAVIGATE / MAP\_FOCUS  
Decoder \-\> DECODE / PUZZLE\_PRIVILEGE  
AR Operator \-\> OPERATE\_AR

Scenario may define custom label while preserving underlying capability.

7\. ROLE ASSIGNMENT MODEL

Assignment fields:  
\- team\_scenario\_instance\_id  
\- node/stage context  
\- game\_session\_player\_id  
\- role\_code  
\- slot\_no  
\- assignment\_source  
\- assigned\_at  
\- released\_at nullable

8\. ASSIGNMENT SOURCES

Possible:  
\- ROTATION  
\- LEAST\_RECENTLY\_USED  
\- SEEDED\_RANDOM  
\- SCENARIO\_FIXED  
\- GAMEMASTER\_OVERRIDE  
\- LATE\_JOIN\_POLICY

9\. ROLE ROTATION

Default:  
fairness-aware rotation.

Goals:  
\- avoid same four players always receiving active slots  
\- distribute meaningful participation  
\- preserve scenario requirements  
\- support late joiners in future stages

10\. FAIRNESS MEMORY

Runtime should track:  
\- role history  
\- active-slot count  
\- recent participation  
\- last assigned stage

Used to improve rotation fairness.

11\. SEEDED RANDOM

If random role assignment is used:  
\- deterministic TeamScenarioInstance seed  
\- reproducible  
\- auditable

12\. LATE JOIN

Only frozen roster player may Late Join.

Late Join:  
\- can join current Team runtime  
\- does not force immediate role reshuffle  
\- becomes eligible from next stage by default  
\- current node may opt-in to immediate assignment

13\. SPECIFIC PLAYER NODE

Scenario may target a specific Player.

Source:  
\- explicit assignment  
\- seeded random selection

Runtime stores target.

Other Players cannot satisfy restricted action.

14\. RANDOM PLAYER NODE

Random Player selection:  
\- seeded deterministic  
\- selects eligible roster Player  
\- persisted at node activation  
\- reconnect does not reroll

15\. TEAM TOGETHER

Team Together requires multiple Players to satisfy a collective condition.

Possible requirements:  
\- N Players acknowledge  
\- N active slots complete subtasks  
\- N Players physically present in area  
\- required role set present

16\. TEAM TOGETHER COMPLETION

Completion:  
\- server-authoritative  
\- persistent contributions  
\- idempotent  
\- threshold evaluated transactionally

17\. CONTRIBUTION RECORD

Co-op action contribution should record:  
\- NodeInstance  
\- Player  
\- role  
\- contribution type  
\- evidence ref  
\- occurred\_at  
\- result

18\. SYNCHRONIZATION BARRIER

Barrier completes only after all required constituent conditions are satisfied.

Examples:  
\- Scanner scanned QR  
\- Navigator verified checkpoint  
\- Decoder solved code  
\- AR Operator completed AR

No one action bypasses missing required contribution.

19\. SPLIT INFORMATION

Different Players/Roles receive different safe projections.

Example:  
\- Navigator sees map clue  
\- Decoder sees cipher  
\- Scanner sees QR instruction  
\- AR Operator sees visual clue

No Player receives full secret set unless Scenario explicitly allows.

20\. INFORMATION PRIVACY WITHIN TEAM

Runtime projection can vary by Player.

Player-specific content:  
\- role-scoped  
\- node-scoped  
\- server-filtered

Client cannot request another Player's secret payload.

21\. SPLIT TEAM

Scenario may split active Players into subgroups.

Subgroup fields/concepts:  
\- subgroup id  
\- members  
\- required node/subtask  
\- synchronization condition

Pilot may defer complex authoring UI but Runtime model should remain compatible.

22\. MULTI-POINT PRESENCE

Team mechanic may require Players at multiple checkpoints simultaneously or within a synchronization window.

Uses:  
\- GPS verified evidence  
\- distinct Players  
\- optional role requirement

23\. SIMULTANEOUS ACTION

Scenario may require actions within a time window.

Server tracks:  
\- first qualifying action  
\- synchronization deadline  
\- required remaining contributions

Client clock not authoritative.

24\. CHAIN UNLOCK

Completion by one role/player unlocks next role/player subtask.

Order is server-controlled.

Example:  
Navigator \-\> Scanner \-\> Decoder \-\> AR Operator

25\. RELAY MODE

One Player completes a step then control transfers to another eligible Player.

Relay state:  
\- current assignee  
\- required next role/player  
\- progression event

26\. SECRET TASK

Player-specific task visible only to assigned Player.

Task may:  
\- contribute to Team completion  
\- remain hidden until completion  
\- have separate reward

27\. CAPTAIN BOUNDARY

Captain has no automatic gameplay advantage.

Captain does not:  
\- bypass role restrictions  
\- gain extra active slot  
\- see all hidden clues by default

28\. GAME MASTER OVERRIDE

GameMaster may:  
\- swap role  
\- assign/reassign active slot  
\- recover from device/player issue

Requirements:  
\- assigned Session  
\- reason where operationally significant  
\- audit/event

29\. ROLE SWAP

Role Swap can be triggered by:  
\- Scenario Node  
\- GameMaster  
\- stage transition

Old assignment released.  
New assignment persisted.

30\. PLAYER DISCONNECT

If active-role Player disconnects:  
\- assignment preserved for grace/reconnect period by default  
\- Runtime may permit GameMaster reassignment  
\- automatic reassignment policy configurable

Do not silently reroll roles immediately.

31\. DISQUALIFICATION / REMOVAL

Disqualified Player:  
\- cannot perform active role actions  
\- Runtime must resolve role vacancy

Replacement:  
\- eligible roster Player  
\- auditable/reproducible selection

32\. ROLE ELIGIBILITY

Role assignment requires:  
\- active/allowed Session Player  
\- compatible device/capability where required  
\- not disqualified  
\- current roster/runtime eligibility

AR Operator may require AR-capable device.

33\. DEVICE DEPENDENCY

Some role capabilities may require Device support.

Examples:  
\- AR Operator requires AR capability  
\- Scanner requires camera/QR functionality

Pre-start compatibility checks should detect blocking shortages where possible.

34\. NODE CONFIGURATION

Role-aware Node config may define:  
\- required\_role  
\- required\_roles\[\]  
\- active\_slot\_only  
\- specific\_player  
\- random\_player  
\- minimum\_contributions  
\- synchronization\_window

35\. RUNTIME AUTHORIZATION

When action submitted:  
\- resolve assigned Player  
\- resolve current role  
\- verify role capability  
\- verify Node policy

Wrong Player/Role:  
\- ACTION\_NOT\_ALLOWED / canonical role error

36\. FOUR-SLOT ENFORCEMENT

Runtime must guarantee:  
\- no more than 4 simultaneous primary slot assignments per relevant stage

Support and live challenge targets do not necessarily consume primary slot unless configured.

37\. ROLE HISTORY AND ANALYTICS

Store enough data for:  
\- participation balance  
\- role frequency  
\- active/support time  
\- fairness analysis

Heavy analytics deferred.

38\. SCORE BOUNDARY

Co-op completion may emit scoring fact.

Individual contribution may:  
\- award no direct individual score by default  
\- affect Team score

NEXO competitive baseline is Team-oriented.

39\. LIVE CHALLENGE BOUNDARY

Support Player may receive Live Challenge even when not in active primary slot.

Live Challenge:  
\- parallel overlay  
\- does not automatically change primary slot

40\. QR INTEGRATION

QR Node may require:  
\- Scanner role  
\- specific Player  
\- any Team member

QR domain checks Runtime role eligibility.

41\. GPS INTEGRATION

GPS Node may require:  
\- Navigator  
\- N Players  
\- role set  
\- all active slots

Location domain returns verified evidence.

42\. AR INTEGRATION

AR completion can require:  
\- AR Operator  
\- specific capable Device

Runtime validates assignment before consuming AR result.

43\. PUZZLE INTEGRATION

Decoder may:  
\- receive answer input capability  
\- receive role-specific clue

Other Team members may still collaborate socially but server action remains role-gated where configured.

44\. REALTIME

Role events:  
\- role assigned  
\- role released  
\- role swapped  
\- barrier progress

Realtime payload:  
\- scoped appropriately  
\- secret task data only to intended Player

45\. EVENTS

Canonical:  
\- runtime.role\_assigned.v1  
\- runtime.role\_released.v1

Additional co-op events should follow catalog revision before implementation if cross-module meaningful.

Potential:  
\- runtime.barrier\_progressed.v1  
\- runtime.barrier\_completed.v1  
\- runtime.subgroup\_assigned.v1

If not currently in Event Catalog:  
document revision required before implementation.

46\. API

Primary role data exposed through Runtime endpoints.

GameMaster role operation may use dedicated control endpoint in future.

Do not create client endpoint allowing arbitrary Player role self-assignment.

47\. CONCURRENCY

Protect:  
\- simultaneous role assignment  
\- barrier contributions  
\- disconnect/reassign race  
\- role swap during submit

Use transaction/lock/versioning.

48\. IDEMPOTENCY

Contribution:  
\- duplicate contribution does not increment twice

Role assignment commands:  
\- retry-safe

Barrier completion:  
\- exactly one completion side effect

49\. PILOT v0.1 REQUIRED

Pilot should support:  
\- 4 active slots  
\- Scanner  
\- Navigator  
\- Decoder  
\- AR Operator label/capability contract  
\- role assignment  
\- rotation  
\- Team Together  
\- role-gated QR/Puzzle/GPS  
\- Support Players

50\. PILOT MAY DEFER

\- complex Split Team UI  
\- advanced relay editor  
\- secret role authoring UI  
\- multi-location simultaneous mission beyond basic support  
\- advanced fairness analytics

51\. TESTS — SLOT LIMIT

\- Team 4 \=\> 4 active possible  
\- Team 5–8 \=\> max 4 primary  
\- 5th primary assignment rejected  
\- Support remains valid participant

52\. TESTS — ROLES

\- correct Player/role action accepted  
\- wrong role rejected  
\- role persists reconnect  
\- role swap updates eligibility  
\- GameMaster override audited

53\. TESTS — ROTATION

\- repeated stages distribute participation  
\- fixed scenario requirement respected  
\- seeded random reproducible  
\- late join enters future rotation

54\. TESTS — TEAM TOGETHER

\- threshold incomplete \=\> node remains active  
\- threshold complete \=\> one completion  
\- duplicate Player contribution not counted twice

55\. TESTS — BARRIER

\- all required role subtasks needed  
\- completion ordering irrelevant where allowed  
\- one missing contribution blocks completion

56\. TESTS — SPLIT INFORMATION

\- Player A cannot fetch Player B secret  
\- role projection correct  
\- future hidden content remains hidden

57\. TESTS — DEVICE

\- incompatible AR role assignment blocked where mandatory  
\- device rebind preserves runtime role where valid

58\. SECURITY

\- Captain cannot bypass roles  
\- Support cannot execute restricted active action  
\- client cannot self-assign  
\- other Team runtime inaccessible  
\- GameMaster unassigned cannot override

59\. DEPENDENCY FOR SCORE/HINT

Scoring may consume:  
\- Team node completion  
\- contribution-specific bonus if future rule defines it

Hints may target:  
\- Team  
\- role  
\- Player-specific clue

60\. DEFINITION OF DONE

Co-op design is frozen when:  
\- 4 active-slot rule explicit  
\- Support semantics explicit  
\- role/capability distinction explicit  
\- assignment/rotation explicit  
\- late join behavior explicit  
\- Team Together/barrier explicit  
\- Split Information privacy explicit  
\- QR/GPS/AR/Puzzle integration explicit  
\- Captain boundary explicit  
\- GameMaster override explicit  
\- concurrency/idempotency explicit  
\- Pilot uses same production co-op architecture  
