NEXO — Scoring, Hints & Leaderboard Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define deterministic, server-authoritative Team scoring, progressive hints, purchased extra-hint entitlement integration, leaderboard ranking, visibility, auditability and anti-double-spend rules without allowing client score manipulation or Pay-to-Win behavior.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Team Management  
\- Game & Game Session  
\- Scenario Core  
\- Runtime Engine  
\- Co-op Roles  
\- Commerce/Entitlement contract  
\- Event Catalog  
\- Permission Matrix  
\- Database Specification

Provides upstream capability for:  
\- Team competition  
\- final Session ranking  
\- hint penalties  
\- live challenge rewards/penalties  
\- GameMaster score adjustments  
\- analytics  
\- post-game results

2\. CORE PRINCIPLE — SCORE IS SERVER-AUTHORITATIVE

Client may display score.  
Client may request valid gameplay actions.  
Client may not:  
\- set score  
\- submit final score  
\- overwrite Team ranking  
\- directly set hint count  
\- choose penalty values  
\- alter tie-break data

Score changes are produced by validated server-side events/actions.

3\. SCORE MODEL

NEXO competitive baseline is Team score.

Player actions may contribute to Team score.

Individual score may be added later as separate projection, but must not replace Team score semantics.

4\. SCORE EVENT MODEL

Canonical score\_events is append-only.

Fields:  
\- game\_session\_id  
\- team\_id  
\- player\_id nullable  
\- node\_instance\_id nullable  
\- source\_type  
\- source\_id nullable  
\- delta  
\- reason\_code  
\- metadata  
\- occurred\_at

No destructive mutation of historical score events.

5\. TEAM SCORE PROJECTION

team\_scores is a read model/projection.

Fields:  
\- game\_session\_id  
\- team\_id  
\- score  
\- effective\_elapsed\_seconds  
\- hints\_used  
\- finished\_at nullable  
\- updated\_at

Constraint:  
\- unique(game\_session\_id, team\_id)

Projection can be rebuilt from durable events where needed.

6\. ALLOWED SCORE SOURCES

Baseline:  
\- NODE\_COMPLETION  
\- TIME\_BONUS  
\- FIRST\_ATTEMPT\_BONUS  
\- NO\_HINT\_BONUS  
\- HINT\_PENALTY  
\- LIVE\_CHALLENGE\_REWARD  
\- LIVE\_CHALLENGE\_PENALTY  
\- MANUAL\_ADJUSTMENT  
\- ANTI\_CHEAT\_PENALTY only if policy later permits

No generic arbitrary client source.

7\. NODE COMPLETION SCORE

Scenario/Node may define:  
\- base completion reward  
\- bonus conditions  
\- penalty conditions

Runtime confirms completion.  
Scoring consumes completion fact and creates score event.

8\. FIRST ATTEMPT BONUS

If configured:  
\- only awarded when valid completion occurs on first accepted attempt  
\- server attempt\_count authoritative  
\- retry/idempotency must not duplicate reward

9\. NO-HINT BONUS

If configured:  
\- based on server hint consumption history  
\- must evaluate before/final completion consistently  
\- purchased and default hints both count according to Game/Scenario rule

10\. TIME BONUS

Time bonus may depend on:  
\- node duration  
\- stage duration  
\- effective Team elapsed time

Server timestamps only.  
Paused duration excluded where policy defines effective time.

11\. SCORE SNAPSHOT POLICY

Scoring rules should be pinned/snapshotted for active Session where required.

Mutable future Game/Scenario changes must not alter active/historical score calculation.

12\. MANUAL ADJUSTMENT

No SET\_SCORE.

GameMaster/Admin may create delta adjustment:  
\- positive or negative  
\- reason mandatory  
\- actor recorded  
\- before/after projection available for audit  
\- event emitted

13\. MANUAL ADJUSTMENT PERMISSION

Allowed:  
\- assigned GameMaster  
\- OrganizationAdmin  
\- privileged SuperAdmin exceptional

Not allowed:  
\- Player  
\- Captain by captaincy alone  
\- unassigned GameMaster

14\. MANUAL ADJUSTMENT IDEMPOTENCY

Sensitive mutation:  
\- Idempotency-Key required/recommended per API contract  
\- retry cannot apply delta twice

15\. SCORE EVENT CONCURRENCY

Appending score event and updating Team projection:  
\- same transaction or reliable projection pattern  
\- no lost updates  
\- lock/atomic increment

16\. SCORE FINALIZATION

At Session/Team finish:  
\- score projection finalized/snapshotted  
\- effective time finalized  
\- hints\_used finalized  
\- finish timestamp stored

After FINISHED:  
\- normal gameplay score changes denied  
\- explicit audited adjustment may exist without reopening Runtime

17\. HINT MODEL

Each stage/node may have built-in progressive hints.

Maximum default built-in hints:  
\- 3

Sequence:  
\- Hint 1  
\- Hint 2  
\- Hint 3

Order is server-controlled.

18\. HINT DEFINITION

Canonical fields:  
\- scenario\_node\_id  
\- hint\_no  
\- content  
\- penalty\_score nullable  
\- penalty\_seconds nullable  
\- created\_at

Constraint:  
\- unique(node\_id, hint\_no)  
\- CHECK hint\_no between 1 and 3 for built-in hints

19\. PROGRESSIVE HINT BEHAVIOR

Hint 1:  
\- light guidance

Hint 2:  
\- more explicit

Hint 3:  
\- strongest built-in help

Direct final answer should only appear if content designer intentionally defines that behavior under approved scenario policy.

20\. HINT REQUEST

Player requests hint for active Node.

Server validates:  
\- Session  
\- Team  
\- Player  
\- Device if sensitive policy requires  
\- Node ACTIVE  
\- next hint availability  
\- entitlement availability if built-ins exhausted

21\. DEFAULT HINT CONSUMPTION

If next built-in hint 1..3 exists:  
\- create hint\_consumption  
\- apply score/time penalty  
\- emit event  
\- return content

22\. EXTRA HINT

After built-in hints exhausted:  
\- valid Entitlement required

Default commercial scope:  
\- Team \+ Game Session

Whole Team may use entitlement credit according to policy.

23\. EXTRA HINT ENTITLEMENT

Commerce owns:  
\- entitlement validity  
\- remaining\_quantity  
\- expiry  
\- revocation

Hint domain consumes through atomic entitlement operation.

24\. ATOMIC EXTRA HINT FLOW

1\. validate active Node  
2\. confirm built-ins exhausted  
3\. find valid Team/Session entitlement  
4\. lock entitlement  
5\. ensure remaining\_quantity \> 0  
6\. create entitlement consumption  
7\. decrement quantity atomically  
8\. create hint consumption  
9\. apply penalty  
10\. emit events  
11\. commit

No double-spend.

25\. HINT CONSUMPTION MODEL

Fields:  
\- game\_session\_id  
\- team\_id  
\- player\_id  
\- node\_instance\_id  
\- hint\_definition\_id nullable  
\- entitlement\_consumption\_id nullable  
\- source  
\- penalty\_score  
\- penalty\_seconds  
\- idempotency\_key  
\- consumed\_at

Source:  
\- DEFAULT  
\- PURCHASED  
\- GAMEMASTER

26\. GAMEMASTER HINT

GameMaster may grant/help via:  
\- explicit hint action  
\- source GAMEMASTER

Policy decides:  
\- whether penalty applies  
\- whether it consumes entitlement

Must be auditable if fairness-impacting.

27\. HINT IDEMPOTENCY

Same Idempotency-Key:  
\- same logical hint  
\- no duplicate penalty  
\- no duplicate entitlement consumption

Concurrent requests:  
\- cannot consume same next built-in hint twice as distinct progression  
\- cannot spend one credit twice

28\. FAIRNESS — NO PAY-TO-WIN

Purchased hint may provide information only.

It may not:  
\- skip Node  
\- mark completion  
\- bypass GPS  
\- bypass QR  
\- bypass AR  
\- bypass role requirements  
\- manipulate score positively  
\- unlock hidden route directly outside normal runtime rule

29\. HINT PENALTY

Scenario/Game may define:  
\- score penalty  
\- time penalty  
\- both  
\- no penalty for noncompetitive mode

Penalty rules pinned for Session/ScenarioVersion.

30\. PENALTY EVENT

Hint consumption generates:  
\- hint event  
\- score event where score penalty  
\- effective time adjustment or penalty record where configured

31\. HINT VISIBILITY

Player may see:  
\- number of hints used  
\- whether next hint is available  
\- entitlement requirement

Do not reveal future hint content before consumption.

32\. LEADERBOARD CORE

Leaderboard ranks Teams in a Game Session.

Primary default:  
1\. higher score  
2\. lower effective elapsed time  
3\. fewer hints used  
4\. earlier finish timestamp

This tie-break order is frozen as default baseline.

33\. LEADERBOARD SNAPSHOT

Session should retain ranking configuration/snapshot so historical ranking does not change after future default changes.

34\. EFFECTIVE ELAPSED TIME

Server-derived.

Uses:  
\- Team runtime/session timestamps  
\- paused duration subtraction  
\- configured time penalties

Client clock ignored.

35\. HINTS USED

Leaderboard hints\_used:  
\- server count from hint consumption according to ranking policy  
\- built-in and purchased hints normally both count unless specific Game config says otherwise

36\. FINISH TIMESTAMP

Earlier finish timestamp used only after previous tie-break dimensions equal.

37\. LEADERBOARD VISIBILITY MODES

Configurable:  
\- FULL  
\- TOP\_N  
\- OWN\_RANK\_ONLY  
\- HIDDEN\_UNTIL\_FINISH

38\. FULL

Players can see permitted full Team ranking.

Do not expose private operational data.

39\. TOP\_N

Only top configured N plus own Team context where useful.

40\. OWN\_RANK\_ONLY

Player sees:  
\- own Team rank  
\- own score  
\- possibly adjacent rank summary if policy allows

41\. HIDDEN\_UNTIL\_FINISH

During game:  
\- ranking hidden

After finish:  
\- final leaderboard visible according to policy

42\. REALTIME LEADERBOARD

Redis/realtime may provide fast view.

PostgreSQL projection remains source of truth.

Realtime event:  
leaderboard.changed.v1 / score.changed equivalent according Event Catalog.

43\. SCORE REALTIME

Compact payload:  
\- Team ref  
\- new score  
\- rank if visibility permits  
\- occurred\_at

Do not expose hidden competitor details when leaderboard visibility restricts them.

44\. LEADERBOARD API

Canonical:  
GET /api/v1/game-sessions/{session\_id}/leaderboard  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score-events  
POST /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score-adjustments

45\. HINT API

Canonical:  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/hints  
POST /api/v1/runtime/nodes/{node\_instance\_id}/hints/consume

46\. PERMISSIONS — SCORE READ

Team member:  
\- own Team score

Leaderboard:  
\- according Session visibility

GameMaster:  
\- assigned Session operational score

OrganizationAdmin:  
\- own Organization Session

47\. PERMISSIONS — SCORE MUTATION

Only Domain/System scoring operations.

Manual:  
\- assigned GameMaster/Admin with reason

No Player direct mutation.

48\. PERMISSIONS — HINT

Eligible Team Player:  
\- consume hint

GameMaster:  
\- grant hint only if policy permits

OrgAdmin:  
\- operational override

49\. EVENTS — HINT

Canonical:  
\- hint.requested.v1  
\- hint.default\_consumed.v1  
\- hint.purchased\_consumed.v1  
\- hint.consumption\_rejected.v1  
\- hint.limit\_reached.v1

50\. EVENTS — SCORE

Canonical:  
\- score.event\_recorded.v1  
\- score.team\_changed.v1  
\- score.manual\_adjustment\_created.v1  
\- score.finalized.v1  
\- leaderboard.changed.v1

51\. SCORE EVENT PAYLOAD

score.team\_changed.v1 includes:  
\- session\_id  
\- team\_id  
\- previous\_score  
\- delta  
\- new\_score  
\- source\_type  
\- source\_id  
\- effective\_elapsed\_seconds  
\- hints\_used

52\. HINT EVENT PAYLOAD

Default consumed:  
\- session\_id  
\- team\_id  
\- player\_id  
\- node\_instance\_id  
\- hint\_no  
\- penalty\_score  
\- penalty\_seconds

Purchased consumed additionally:  
\- entitlement\_id  
\- remaining\_quantity

53\. AUDIT

Mandatory:  
\- manual score adjustment  
\- GameMaster free hint override  
\- compensation/correction  
\- privileged entitlement/hint repair

54\. CORRECTIONS

Do not delete historical score/hint events.

Correction:  
\- compensating event  
\- explicit reason  
\- actor  
\- audit

55\. REFUND / ENTITLEMENT INTERACTION

If Store purchase refunded:  
\- entitlement reconciliation required  
\- historical consumed hints remain historical  
\- do not erase past consumption

Future policy determines compensation/refund financial effects.

56\. ANTI-CHEAT BOUNDARY

Suspicious scoring patterns may generate signal.

Anti-Cheat may apply penalty only through defined audited score event if policy allows.

No hidden direct projection overwrite.

57\. RUNTIME BOUNDARY

Runtime confirms:  
\- Node state  
\- completion  
\- attempt count  
\- eligibility

Scoring does not independently mutate Runtime progression.

58\. CO-OP BOUNDARY

Role/contribution may affect score only if Scenario rule explicitly defines it.

Default:  
\- Team completion reward  
\- not individual competitive score

59\. SESSION PAUSE

Paused time:  
\- excluded from effective gameplay elapsed where Runtime defines  
\- real audit wall-clock still retained

60\. SESSION CANCEL

Cancelled Session:  
\- leaderboard normally non-final/void  
\- scoring records preserved for audit  
\- commercial/statistical reporting distinguishes cancelled Session

61\. PILOT v0.1 REQUIRED

Pilot includes:  
\- Team score  
\- completion reward  
\- hint penalty  
\- default hints 1–3  
\- leaderboard  
\- tie-break  
\- GameMaster manual adjustment  
\- score/hint events  
\- final results

62\. PILOT MAY DEFER

\- public paid Hint Pack purchase UI  
\- advanced achievements  
\- badges  
\- complex reward economy  
\- advanced anti-cheat penalties  
\- seasonal/global leaderboards

Underlying entitlement boundary remains production-compatible.

63\. CONCURRENCY TESTS

\- same Node completion event cannot score twice  
\- two hint requests cannot consume same hint twice  
\- one entitlement credit cannot double-spend  
\- simultaneous manual adjustment safe  
\- projection update no lost delta

64\. IDEMPOTENCY TESTS

\- completion retry no duplicate score  
\- hint retry same result  
\- manual adjustment retry no duplicate delta  
\- same key different payload conflict

65\. LEADERBOARD TESTS

\- higher score ranks higher  
\- score tie \=\> lower effective time  
\- time tie \=\> fewer hints  
\- hint tie \=\> earlier finish  
\- deterministic exact ties handled consistently

66\. VISIBILITY TESTS

\- FULL  
\- TOP\_N  
\- OWN\_RANK\_ONLY  
\- HIDDEN\_UNTIL\_FINISH  
\- unauthorized competitor details not leaked

67\. SECURITY TESTS

\- Player cannot POST arbitrary score  
\- Player cannot alter penalty  
\- Player cannot decrement entitlement directly  
\- cross-tenant leaderboard denied  
\- unassigned GameMaster adjustment denied  
\- adjustment without reason denied

68\. HINT SECURITY TESTS

\- future hint content hidden  
\- fourth default hint unavailable  
\- purchased hint requires entitlement  
\- exhausted entitlement rejected  
\- wrong Team entitlement rejected

69\. REBUILD TEST

Team score projection can be recalculated from append-only score events consistently.

70\. DEFINITION OF DONE

Scoring/Hint/Leaderboard design is frozen when:  
\- append-only score event model explicit  
\- no SET\_SCORE principle explicit  
\- scoring sources explicit  
\- manual adjustment rules explicit  
\- default 3 progressive hints explicit  
\- extra entitlement atomic flow explicit  
\- no Pay-to-Win boundary explicit  
\- leaderboard tie-break frozen  
\- visibility modes defined  
\- event/audit/concurrency/idempotency explicit  
\- Pilot uses same production architecture  
