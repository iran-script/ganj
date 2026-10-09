Urban AR Team Adventure Game — State Machine Specification v1

وضعیت: FROZEN STATE MACHINE BASELINE  
مرجع: Product Blueprint v1 \+ API Contract v1 \+ Database Specification v1

1\. اصل سراسری State Machine  
تمام State Transitionهای حیاتی فقط از Domain/Application Service و Guardهای Server-side انجام می‌شوند.  
Client حق set مستقیم state ندارد.  
هر transition معتبر باید:  
\- current state را validate کند  
\- authorization را بررسی کند  
\- business guardها را اجرا کند  
\- transaction/lock مناسب داشته باشد  
\- event/audit تولید کند  
\- idempotency را در عملیات حساس رعایت کند

Terminal stateها Reopen عمومی ندارند مگر Rule صریح و audited compensating operation.

2\. Game Session State Machine

States:  
DRAFT  
REGISTRATION  
READY  
RUNNING  
PAUSED  
FINISHED  
CANCELLED

Transitions:  
DRAFT \-\> REGISTRATION  
Action: open\_registration  
Guards:  
Game معتبر  
Session config معتبر  
Pricing قابل resolve  
Actor مجاز

REGISTRATION \-\> READY  
Action: close\_registration / prepare\_session  
Guards:  
حداقل 1 Team  
حداکثر 5 Team  
هر Team roster بین 4 و 8  
Scenario assignment برای همه Teamها  
Billing preconditions قابل تکمیل

READY \-\> RUNNING  
Action: start\_session  
Guards:  
Billing Gate PASS  
حداقل 4 Ready Player در هر Team  
Device Binding معتبر برای Ready playerها  
ScenarioVersion معتبر و pinned  
Roster snapshot قابل freeze  
No blocking anti-cheat/admin hold  
Atomic Start

RUNNING \-\> PAUSED  
Action: pause\_session  
Actor: GameMaster/Admin  
Effect:  
Gameplay timers pause  
Runtime state حفظ  
Realtime event  
Audit event

PAUSED \-\> RUNNING  
Action: resume\_session  
Guards:  
No blocking incident/hold

RUNNING \-\> FINISHED  
PAUSED \-\> FINISHED  
Action: finish\_session  
Effect:  
Final score snapshot  
Runtime closure  
Billing summary  
Entitlement closure policy  
Timeline closure

DRAFT \-\> CANCELLED  
REGISTRATION \-\> CANCELLED  
READY \-\> CANCELLED  
RUNNING \-\> CANCELLED فقط Emergency Cancel  
PAUSED \-\> CANCELLED فقط Emergency Cancel  
Guards:  
Reason required  
Financial resolution required where applicable  
Permission elevated for post-start cancellation

Terminal:  
FINISHED  
CANCELLED

Invalid:  
FINISHED \-\> \*  
CANCELLED \-\> \*  
except explicit audited administrative correction to related records, not session reopen.

3\. Game Session Team State Machine

States:  
REGISTERED  
READY  
ACTIVE  
PAUSED  
FINISHED  
REMOVED  
DISQUALIFIED

REGISTERED \-\> READY  
Guard:  
minimum readiness criteria satisfied

READY \-\> ACTIVE  
Triggered by Session Start

ACTIVE \-\> PAUSED  
GameMaster pause team

PAUSED \-\> ACTIVE  
GameMaster resume team

ACTIVE \-\> FINISHED  
Team runtime complete or Session Finish

REGISTERED \-\> REMOVED  
READY \-\> REMOVED  
Allowed only before Session Start unless exceptional admin resolution

ACTIVE \-\> DISQUALIFIED  
PAUSED \-\> DISQUALIFIED  
Reason \+ audit required

Terminal:  
FINISHED  
REMOVED  
DISQUALIFIED

4\. Player Session Presence / Participation State Machine

States:  
JOINED  
READY  
ACTIVE  
DISCONNECTED  
REMOVED  
DISQUALIFIED  
FINISHED

JOINED \-\> READY  
Action: player\_ready  
Guards:  
authenticated  
roster member  
device bound  
session valid  
billing participation valid

READY \-\> ACTIVE  
Session Start

ACTIVE \-\> DISCONNECTED  
Trigger:  
heartbeat timeout / websocket disconnect / app inactive threshold

DISCONNECTED \-\> ACTIVE  
Action:  
reconnect  
Guards:  
token valid  
device valid  
session running/paused  
runtime membership valid

READY \-\> REMOVED  
JOINED \-\> REMOVED  
before start only by allowed roster operation

ACTIVE \-\> DISQUALIFIED  
DISCONNECTED \-\> DISQUALIFIED  
reason \+ GameMaster/Admin authority

ACTIVE \-\> FINISHED  
DISCONNECTED \-\> FINISHED  
when team/session ends

Late Join:  
Roster member frozen before Start may transition JOINED/READY \-\> ACTIVE after Start once device binding \+ auth checks pass.  
User not present in frozen roster cannot Late Join.

5\. Device Binding State Machine

Conceptual states:  
UNBOUND  
BOUND  
REVOKED  
REPLACED

UNBOUND \-\> BOUND  
Action: bind\_device  
Guards:  
user owns device  
session participation valid

BOUND \-\> REPLACED  
Action: rebind\_device  
Guards:  
GameMaster/Admin or allowed recovery flow  
reason required  
old/new device audit

BOUND \-\> REVOKED  
if device revoked globally or security action

REVOKED cannot perform sensitive session actions.

Only one active bound device per Player per Game Session.

6\. Team Scenario Instance State Machine

States:  
NOT\_STARTED  
ACTIVE  
PAUSED  
COMPLETED  
FAILED  
CANCELLED

NOT\_STARTED \-\> ACTIVE  
Triggered by Session Start

ACTIVE \-\> PAUSED  
team/session pause

PAUSED \-\> ACTIVE  
resume

ACTIVE \-\> COMPLETED  
all required terminal scenario conditions met

ACTIVE \-\> FAILED  
Scenario-specific terminal failure only if scenario supports fail outcome

NOT\_STARTED/ACTIVE/PAUSED \-\> CANCELLED  
session/team cancellation/disqualification where policy requires

Terminal:  
COMPLETED  
FAILED  
CANCELLED

7\. Node Instance State Machine

States:  
LOCKED  
AVAILABLE  
ACTIVE  
COMPLETED  
FAILED  
SKIPPED

LOCKED \-\> AVAILABLE  
when predecessor/condition unlocks

AVAILABLE \-\> ACTIVE  
Action: start\_node  
Guards:  
current runtime path valid  
player/team permission valid

ACTIVE \-\> COMPLETED  
Action: complete\_node  
Guards:  
Node Handler validates completion  
all required subtasks/evidence satisfied

ACTIVE \-\> FAILED  
if Node supports failure

AVAILABLE \-\> SKIPPED  
ACTIVE \-\> SKIPPED  
only GameMaster/Admin or Scenario rule explicitly allowing skip  
must audit

COMPLETED/FAILED/SKIPPED terminal for same Node Instance.

No Client can transition LOCKED directly to COMPLETED.

8\. Puzzle / Submission Attempt State  
Attempt itself is append-only rather than mutable.  
Results:  
SUBMITTED  
ACCEPTED  
REJECTED  
EXPIRED  
DUPLICATE

Node attempt\_count increments transactionally.  
Correct result may complete Node depending completion rule.

9\. QR Trigger Token State Machine

States:  
ISSUED  
CONSUMED  
EXPIRED  
REVOKED

ISSUED \-\> CONSUMED  
Atomic one-time consume.

ISSUED \-\> EXPIRED  
time-based

ISSUED \-\> REVOKED  
runtime invalidation/admin/security event

CONSUMED/EXPIRED/REVOKED terminal.

Replay on terminal token returns QR\_REPLAYED or token-invalid equivalent.

10\. AR Challenge Run State Machine

States:  
CREATED  
STARTED  
COMPLETED  
FAILED  
EXPIRED  
CANCELLED

CREATED \-\> STARTED  
Guards:  
runtime node active  
player eligible  
device capability sufficient  
asset version valid

STARTED \-\> COMPLETED  
server validates nonce \+ completion payload \+ runtime state

STARTED \-\> FAILED  
challenge-defined failure

CREATED/STARTED \-\> EXPIRED  
expiry reached

CREATED/STARTED \-\> CANCELLED  
runtime/session cancellation

Terminal:  
COMPLETED  
FAILED  
EXPIRED  
CANCELLED

11\. Live Challenge State Machine

States:  
DRAFT  
SCHEDULED  
ACTIVE  
COMPLETED  
EXPIRED  
CANCELLED

DRAFT \-\> SCHEDULED  
if starts\_at future

DRAFT \-\> ACTIVE  
if immediate

SCHEDULED \-\> ACTIVE  
at starts\_at

ACTIVE \-\> COMPLETED  
completion rule met for required target set

ACTIVE \-\> EXPIRED  
expires\_at reached

DRAFT/SCHEDULED/ACTIVE \-\> CANCELLED  
GameMaster cancel

Terminal:  
COMPLETED  
EXPIRED  
CANCELLED

12\. Live Challenge Target State Machine

States:  
SENT  
SEEN  
ACKED  
STARTED  
COMPLETED  
FAILED  
EXPIRED  
CANCELLED

SENT \-\> SEEN  
delivery/open detected

SEEN \-\> ACKED  
explicit acknowledgement if required

ACKED \-\> STARTED  
or SENT/SEEN \-\> STARTED when ack optional

STARTED \-\> COMPLETED  
valid submit/completion

STARTED \-\> FAILED  
challenge failure

nonterminal \-\> EXPIRED  
parent expiry

nonterminal \-\> CANCELLED  
parent cancel

Terminal:  
COMPLETED  
FAILED  
EXPIRED  
CANCELLED

13\. Payment State Machine

States:  
CREATED  
PENDING  
VERIFIED  
FAILED  
CANCELLED  
REFUNDED  
PARTIALLY\_REFUNDED optional projection via Order

CREATED \-\> PENDING  
payment initiated with provider

PENDING \-\> VERIFIED  
server-side provider verification success

PENDING \-\> FAILED  
verification/provider failure

CREATED/PENDING \-\> CANCELLED  
allowed before successful settlement where provider permits

VERIFIED \-\> REFUNDED  
only after refund success

FAILED may have new Payment record/attempt; same payment record should not be silently reset to CREATED.

VERIFIED is the only state that passes paid-payment gate unless valid credit/alternative settlement applies.

14\. Order State Machine

States:  
CREATED  
PENDING  
PAID  
FAILED  
CANCELLED  
PARTIALLY\_REFUNDED  
REFUNDED

CREATED \-\> PENDING  
payment initiated

PENDING \-\> PAID  
covered amount verified

PENDING \-\> FAILED  
payment path fails and no successful alternate settlement

CREATED/PENDING \-\> CANCELLED  
pre-settlement cancel

PAID \-\> PARTIALLY\_REFUNDED  
partial refund

PAID/PARTIALLY\_REFUNDED \-\> REFUNDED  
full effective refund

Order financial totals never recalculated from mutable current prices after snapshot.

15\. Refund State Machine

States:  
REQUESTED  
PROCESSING  
SUCCEEDED  
FAILED  
CANCELLED

REQUESTED \-\> PROCESSING  
provider request dispatched

PROCESSING \-\> SUCCEEDED  
provider confirms

PROCESSING \-\> FAILED  
provider rejects/fails

REQUESTED \-\> CANCELLED  
before provider execution where allowed

SUCCEEDED terminal.

16\. Purchase State Machine

States:  
CREATED  
PENDING\_PAYMENT  
COMPLETED  
FAILED  
CANCELLED  
REFUNDED

CREATED \-\> PENDING\_PAYMENT  
if payment needed

PENDING\_PAYMENT \-\> COMPLETED  
verified payment then entitlement grant atomically/outbox-safe

CREATED \-\> COMPLETED  
if valid credit/free product

PENDING\_PAYMENT \-\> FAILED  
payment failure

CREATED/PENDING\_PAYMENT \-\> CANCELLED

COMPLETED \-\> REFUNDED  
refund policy \+ entitlement reconciliation required

17\. Entitlement State Machine

States:  
ACTIVE  
EXHAUSTED  
EXPIRED  
REVOKED

ACTIVE \-\> EXHAUSTED  
remaining\_quantity reaches 0

ACTIVE \-\> EXPIRED  
expires\_at passed

ACTIVE \-\> REVOKED  
refund/admin/fraud policy

No transition back to ACTIVE except explicit compensating entitlement record preferred.  
Do not mutate historical consumption away.

18\. Hint Consumption Lifecycle

Hint itself is not a long-lived mutable state.  
Consume flow atomic:  
1 validate node active  
2 determine next default hint (1..3) if available  
3 otherwise find valid purchased entitlement  
4 lock entitlement if needed  
5 create hint\_consumption  
6 decrement entitlement atomically when purchased  
7 create score/time penalty event  
8 publish realtime update

Retry same Idempotency-Key returns same logical consumption.  
Concurrent requests cannot consume same credit twice.

19\. Score State Model

Score should be event-derived.  
score\_events append-only.  
team\_scores is read model/projection.

Allowed score event types:  
NODE\_COMPLETION  
TIME\_BONUS  
FIRST\_ATTEMPT\_BONUS  
NO\_HINT\_BONUS  
HINT\_PENALTY  
LIVE\_CHALLENGE\_REWARD  
LIVE\_CHALLENGE\_PENALTY  
MANUAL\_ADJUSTMENT  
ANTI\_CHEAT\_PENALTY if policy allows

No direct SET\_SCORE API.  
Manual adjustment creates delta event \+ audit.

20\. Anti-Cheat Flag State Machine

States:  
OPEN  
UNDER\_REVIEW  
RESOLVED  
DISMISSED  
ENFORCED

OPEN \-\> UNDER\_REVIEW  
GameMaster/Admin review

OPEN/UNDER\_REVIEW \-\> DISMISSED  
false positive/no action

OPEN/UNDER\_REVIEW \-\> RESOLVED  
review complete, no ongoing enforcement

OPEN/UNDER\_REVIEW \-\> ENFORCED  
soft/hard block/disqualify action applied

ENFORCED may later receive additional action events but flag history remains.

21\. Media Asset State Machine

States:  
UPLOADING  
READY  
QUARANTINED  
ARCHIVED  
DELETED

UPLOADING \-\> READY  
validation/checksum/storage success

UPLOADING \-\> QUARANTINED  
validation/security failure

READY \-\> ARCHIVED  
logical retirement

QUARANTINED \-\> ARCHIVED  
admin resolution

ARCHIVED \-\> DELETED  
only when no protected references and retention allows

READY with active references cannot hard delete.

22\. Scenario Version State Machine

States:  
DRAFT  
PUBLISHED  
ARCHIVED

DRAFT \-\> PUBLISHED  
Guards:  
validation PASS  
graph valid  
dependencies pinned  
required assets valid

PUBLISHED \-\> ARCHIVED  
allowed for future assignment removal

PUBLISHED is immutable.  
Any edit creates new DRAFT version.  
ARCHIVED version remains readable for historical sessions.

No PUBLISHED \-\> DRAFT.

23\. Scenario Validation Run

States:  
RUNNING  
PASS  
FAIL

Each validation run tied to revision\_token.  
Publish requires PASS for current revision\_token, not stale validation.

24\. Store Product State Machine

States:  
DRAFT  
ACTIVE  
INACTIVE  
ARCHIVED

DRAFT \-\> ACTIVE  
validation \+ price/config valid

ACTIVE \-\> INACTIVE  
temporarily stop sale

INACTIVE \-\> ACTIVE

DRAFT/INACTIVE/ACTIVE \-\> ARCHIVED  
Archived cannot be newly purchased but historical purchase refs remain.

25\. Team State Machine

States:  
ACTIVE  
ARCHIVED

Team can exist with 1..8 members.  
Eligibility is derived, not Team state:  
eligible\_for\_game \= active member count between 4 and 8\.

ACTIVE \-\> ARCHIVED  
Captain/Admin action subject to no blocking active session constraints.

ARCHIVED not used for new registration.  
Historical sessions remain intact.

26\. Team Membership State

States:  
ACTIVE  
LEFT  
REMOVED

join creates ACTIVE membership history record or active record depending implementation.  
ACTIVE \-\> LEFT self leave  
ACTIVE \-\> REMOVED captain/admin removal

LEFT/REMOVED terminal historical state.  
Rejoin creates new membership period or explicitly versioned membership, not erasing old history.

27\. Organization Membership State

States:  
ACTIVE  
SUSPENDED  
LEFT  
REMOVED

Role changes are audited.  
Cross-tenant access prohibited regardless of UI.

28\. Outbox Event State Machine

States:  
PENDING  
PROCESSING  
PUBLISHED  
FAILED

PENDING \-\> PROCESSING  
worker claims with lock

PROCESSING \-\> PUBLISHED  
publish success

PROCESSING \-\> PENDING  
retryable failure with attempts/backoff

PROCESSING \-\> FAILED  
max attempts/nonretryable

FAILED can be manually retried through audited operational action creating/resetting delivery state while preserving history.

29\. Runtime Command State Machine

States:  
RECEIVED  
PROCESSING  
SUCCEEDED  
REJECTED  
FAILED

RECEIVED \-\> PROCESSING  
after idempotency/lock acquire

PROCESSING \-\> SUCCEEDED  
domain mutation committed

PROCESSING \-\> REJECTED  
business guard invalid

PROCESSING \-\> FAILED  
technical failure

Same idempotency key returns stored logical result if request equivalent.

30\. Timer State Machine

States:  
NOT\_STARTED  
RUNNING  
PAUSED  
FINISHED  
CANCELLED

NOT\_STARTED \-\> RUNNING  
RUNNING \-\> PAUSED  
PAUSED \-\> RUNNING  
RUNNING/PAUSED \-\> FINISHED  
nonterminal \-\> CANCELLED

Effective elapsed computed server-side from timestamps \+ accumulated paused duration.

31\. State Transition Error Contract

Invalid transition returns:  
HTTP 409  
code: STATE\_TRANSITION\_INVALID

details recommended:  
resource\_type  
resource\_id  
current\_state  
requested\_action  
allowed\_actions

Example:  
{  
  "error": {  
    "code": "STATE\_TRANSITION\_INVALID",  
    "message": "The requested transition is not allowed.",  
    "details": {  
      "resource\_type": "game\_session",  
      "current\_state": "FINISHED",  
      "requested\_action": "resume",  
      "allowed\_actions": \[\]  
    }  
  }  
}

32\. Concurrency Rules  
Session Start: row lock on session \+ relevant registrations/snapshots.  
Hint consume: lock entitlement / atomic remaining\_quantity update.  
Node complete: lock team runtime or optimistic version check.  
Score: append event transaction \+ projection update.  
Payment verify: provider reference/idempotency unique constraints.  
QR token: atomic consumed\_at update where consumed\_at IS NULL.  
Device rebind: lock player-session row.  
Team join: lock team/capacity check.  
Session team register: lock session active team count.

33\. Event Emission Rule  
Successful state transition produces Domain Event.  
Event name convention:  
aggregate.action.v1

Examples:  
game\_session.started.v1  
game\_session.paused.v1  
player.disconnected.v1  
runtime.node\_completed.v1  
payment.verified.v1  
entitlement.exhausted.v1  
anti\_cheat.flag\_enforced.v1

Event is written transactionally via Outbox where external/realtime delivery is needed.

34\. Definition of Done  
تمام Stateهای حیاتی تعریف شده‌اند.  
Allowed/invalid transitions مشخص‌اند.  
Terminal states مشخص‌اند.  
Guardهای اصلی مشخص‌اند.  
Concurrency hotspots مشخص‌اند.  
API error behavior مشخص است.  
Event emission rule مشخص است.  
Codex نباید state transition جدیدی خارج از این سند بدون ADR/Blueprint amendment ایجاد کند.  
