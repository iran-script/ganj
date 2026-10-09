Urban AR Team Adventure Game — Event Catalog Specification v1

وضعیت: FROZEN EVENT CONTRACT BASELINE  
مرجع: Product Blueprint v1 \+ API Contract v1 \+ Database Specification v1 \+ State Machine Specification v1

1\. Event Architecture  
سه دسته Event داریم:  
Domain Event: رخداد داخلی معتبر پس از تغییر state.  
Outbox Event: نسخه قابل تحویل به Queue/Realtime/Integration که transactionally ثبت می‌شود.  
Realtime Event: payload حداقلی برای Clientهای متصل.

اصل:  
Domain change commit می‌شود و Event در همان transaction داخل outbox ثبت می‌شود.  
Worker بعداً آن را publish می‌کند.  
هیچ business-critical side effect نباید فقط با fire-and-forget مستقیم از request انجام شود.

2\. Naming Convention  
فرمت:  
\<aggregate\>.\<action\>.v\<major\>

نمونه:  
game\_session.started.v1  
runtime.node\_completed.v1  
payment.verified.v1

قواعد:  
lower\_snake\_case برای aggregate/action.  
Major version فقط در breaking payload change افزایش می‌یابد.  
اضافه‌شدن field اختیاری breaking محسوب نمی‌شود.  
حذف/تغییر semantics field breaking است.

3\. Standard Event Envelope  
{  
  "event\_id": "01J...",  
  "event\_type": "game\_session.started.v1",  
  "payload\_version": 1,  
  "occurred\_at": "2026-09-18T08:30:00Z",  
  "producer": "sessions",  
  "organization\_id": "org\_...",  
  "session\_id": "ses\_...",  
  "team\_id": null,  
  "player\_id": null,  
  "actor": {  
    "type": "user",  
    "id": "usr\_..."  
  },  
  "data": {}  
}

event\_id globally unique.  
occurred\_at از Server UTC.  
actor nullable برای system events.

4\. Delivery Semantics  
Outbox delivery: at-least-once.  
Consumerها باید idempotent باشند.  
event\_id برای deduplication استفاده می‌شود.  
Ordering globally تضمین نمی‌شود.  
Ordering داخل aggregate تا حد ممکن با occurred\_at \+ DB sequence/event id حفظ می‌شود.  
Consumer نباید روی cross-aggregate strict ordering حساب کند.

5\. Identity Events  
user.registered.v1  
Producer: Identity  
Consumers: Audit, Analytics  
Data:  
user\_id  
registration\_channel  
device\_id

user.logged\_in.v1  
Consumers: Audit, Security, Analytics

user.logged\_out.v1

user.status\_changed.v1  
Data:  
old\_status  
new\_status  
reason

device.registered.v1  
device.revoked.v1  
device.session\_bound.v1  
device.session\_rebound.v1  
Data rebound:  
player\_id  
session\_id  
old\_device\_id  
new\_device\_id  
reason

6\. Organization Events  
organization.created.v1  
organization.updated.v1  
organization.archived.v1  
organization.member\_added.v1  
organization.member\_role\_changed.v1  
organization.member\_removed.v1

Consumers:  
Audit  
Analytics  
Permission read models

7\. Team Events  
team.created.v1  
team.updated.v1  
team.archived.v1  
team.member\_joined.v1  
team.member\_left.v1  
team.member\_removed.v1  
team.captain\_changed.v1  
team.invite\_created.v1  
team.invite\_revoked.v1

team.member\_joined.v1 Data:  
team\_id  
user\_id  
member\_count  
eligible\_for\_game

team.captain\_changed.v1:  
old\_captain\_user\_id  
new\_captain\_user\_id  
reason nullable

8\. Game Events  
game.created.v1  
game.updated.v1  
game.activated.v1  
game.archived.v1  
game.price\_created.v1  
game.price\_changed.v1

price event carries:  
game\_id  
price\_id  
currency  
price\_per\_player  
effective\_from

9\. Game Session Events  
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

game\_session.started.v1 Data:  
session\_id  
started\_at  
team\_count  
player\_count  
roster\_snapshot\_version  
pricing\_snapshot\_ref  
scenario\_assignments\_summary

Consumers:  
Runtime bootstrap  
Realtime  
Analytics  
Audit

game\_session.cancelled.v1 Data:  
reason  
cancelled\_by  
post\_start boolean  
financial\_resolution\_status

10\. Session Team Events  
session\_team.ready.v1  
session\_team.activated.v1  
session\_team.paused.v1  
session\_team.resumed.v1  
session\_team.finished.v1  
session\_team.removed.v1  
session\_team.disqualified.v1

11\. Player Session Events  
player\_session.joined.v1  
player\_session.ready.v1  
player\_session.activated.v1  
player\_session.disconnected.v1  
player\_session.reconnected.v1  
player\_session.late\_joined.v1  
player\_session.removed.v1  
player\_session.disqualified.v1  
player\_session.finished.v1

player\_session.disconnected.v1:  
last\_seen\_at  
device\_id  
disconnect\_reason

player\_session.reconnected.v1:  
device\_id  
offline\_duration\_seconds

12\. Scenario Events  
scenario.created.v1  
scenario.updated.v1  
scenario.archived.v1  
scenario\_version.created.v1  
scenario\_version.validation\_started.v1  
scenario\_version.validation\_passed.v1  
scenario\_version.validation\_failed.v1  
scenario\_version.published.v1  
scenario\_version.archived.v1  
scenario\_version.duplicated.v1

scenario\_version.published.v1 Data:  
scenario\_id  
scenario\_version\_id  
version\_no  
revision\_token  
published\_by  
dependency\_versions  
asset\_manifest\_refs

13\. Scenario Builder Events  
scenario\_graph.saved.v1  
scenario\_graph.validation\_requested.v1  
scenario\_graph.simulation\_started.v1  
scenario\_graph.simulation\_completed.v1

Mostly Analytics/Audit, not gameplay critical.

14\. Scenario Assignment Events  
scenario\_assignment.created.v1  
scenario\_assignment.updated.v1  
scenario\_assignment.rejected.v1

Data:  
session\_id  
team\_id  
scenario\_version\_id  
mode  
seed

15\. Runtime Events  
runtime.instance\_created.v1  
runtime.started.v1  
runtime.paused.v1  
runtime.resumed.v1  
runtime.completed.v1  
runtime.failed.v1  
runtime.cancelled.v1

runtime.node\_unlocked.v1  
runtime.node\_started.v1  
runtime.node\_submission\_received.v1  
runtime.node\_submission\_rejected.v1  
runtime.node\_completed.v1  
runtime.node\_failed.v1  
runtime.node\_skipped.v1

runtime.variable\_changed.v1  
runtime.role\_assigned.v1  
runtime.role\_released.v1  
runtime.timer\_started.v1  
runtime.timer\_paused.v1  
runtime.timer\_resumed.v1  
runtime.timer\_finished.v1

runtime.node\_completed.v1 Data:  
team\_scenario\_instance\_id  
node\_instance\_id  
scenario\_node\_id  
node\_key  
node\_type  
completed\_by\_player\_id nullable  
attempt\_count  
completion\_metadata  
next\_node\_ids

Consumers:  
Runtime projection  
Score engine  
Realtime  
Analytics  
Anti-cheat if relevant

16\. Location/GIS Events  
location.sample\_accepted.v1  
location.sample\_rejected.v1  
location.batch\_accepted.v1  
location.geofence\_entered.v1  
location.geofence\_exited.v1  
location.geofence\_uncertain.v1  
location.checkpoint\_verified.v1  
location.checkpoint\_rejected.v1  
location.policy\_changed.v1

Raw location samples themselves may not all be pushed through general outbox due volume.  
High-volume location ingestion uses DB/event pipeline optimized path.  
Only meaningful derived events go to general event bus.

location.checkpoint\_verified.v1:  
player\_id  
checkpoint\_id  
node\_instance\_id nullable  
distance\_meters  
accuracy  
location\_event\_id

17\. QR Events  
qr.scanned.v1  
qr.scan\_rejected.v1  
qr.trigger\_resolved.v1  
qr.token\_issued.v1  
qr.token\_consumed.v1  
qr.token\_replay\_detected.v1

qr.scan\_rejected.v1 reason codes:  
INVALID\_IDENTIFIER  
NODE\_NOT\_ACTIVE  
OUTSIDE\_ALLOWED\_AREA  
DEVICE\_NOT\_BOUND  
RATE\_LIMIT  
REPLAY  
WRONG\_PLAYER\_ROLE

18\. AR Events  
ar.challenge\_created.v1  
ar.challenge\_started.v1  
ar.asset\_ready.v1  
ar.challenge\_completed.v1  
ar.challenge\_failed.v1  
ar.challenge\_expired.v1  
ar.challenge\_cancelled.v1  
ar.device\_incompatible.v1

ar.challenge\_completed.v1:  
challenge\_run\_id  
challenge\_id  
player\_id  
asset\_version  
completion\_metadata  
duration\_seconds

19\. Live Challenge Events  
live\_challenge.created.v1  
live\_challenge.scheduled.v1  
live\_challenge.activated.v1  
live\_challenge.cancelled.v1  
live\_challenge.expired.v1  
live\_challenge.completed.v1

live\_challenge.target\_sent.v1  
live\_challenge.target\_seen.v1  
live\_challenge.target\_acked.v1  
live\_challenge.target\_started.v1  
live\_challenge.target\_completed.v1  
live\_challenge.target\_failed.v1

Consumers:  
Realtime  
Score Engine  
Analytics  
Audit

20\. Hint Events  
hint.requested.v1  
hint.default\_consumed.v1  
hint.purchased\_consumed.v1  
hint.consumption\_rejected.v1  
hint.limit\_reached.v1

hint.default\_consumed.v1:  
session\_id  
team\_id  
player\_id  
node\_instance\_id  
hint\_no  
penalty\_score  
penalty\_seconds

hint.purchased\_consumed.v1 additionally:  
entitlement\_id  
remaining\_quantity

21\. Score Events  
score.event\_recorded.v1  
score.team\_changed.v1  
score.manual\_adjustment\_created.v1  
score.finalized.v1  
leaderboard.changed.v1

score.team\_changed.v1:  
session\_id  
team\_id  
previous\_score  
delta  
new\_score  
source\_type  
source\_id  
effective\_elapsed\_seconds  
hints\_used

leaderboard.changed.v1 realtime payload should be compact and respect visibility policy.

22\. Billing Events  
billing.participation\_reserved.v1  
billing.participation\_billed.v1  
billing.participation\_voided.v1  
billing.pricing\_snapshotted.v1  
billing.gate\_passed.v1  
billing.gate\_failed.v1

order.created.v1  
order.pending.v1  
order.paid.v1  
order.failed.v1  
order.cancelled.v1  
order.partially\_refunded.v1  
order.refunded.v1

payment.created.v1  
payment.pending.v1  
payment.verified.v1  
payment.failed.v1  
payment.cancelled.v1  
payment.refunded.v1  
payment.webhook\_received.v1  
payment.webhook\_ignored.v1

refund.requested.v1  
refund.processing.v1  
refund.succeeded.v1  
refund.failed.v1  
refund.cancelled.v1

payment.verified.v1:  
payment\_id  
order\_id  
provider  
provider\_reference  
amount  
currency  
verified\_at

Consumers:  
Billing projection  
Session Billing Gate  
Store entitlement grant  
Analytics  
Audit

23\. Commerce Events  
store.product\_created.v1  
store.product\_activated.v1  
store.product\_deactivated.v1  
store.product\_archived.v1

purchase.created.v1  
purchase.pending\_payment.v1  
purchase.completed.v1  
purchase.failed.v1  
purchase.cancelled.v1  
purchase.refunded.v1

entitlement.granted.v1  
entitlement.consumed.v1  
entitlement.exhausted.v1  
entitlement.expired.v1  
entitlement.revoked.v1

entitlement.granted.v1:  
entitlement\_id  
owner\_type  
owner\_id  
product\_id  
initial\_quantity  
scope/session/team context  
expires\_at

24\. Anti-Cheat Events  
anti\_cheat.flag\_created.v1  
anti\_cheat.flag\_updated.v1  
anti\_cheat.flag\_review\_started.v1  
anti\_cheat.flag\_dismissed.v1  
anti\_cheat.flag\_resolved.v1  
anti\_cheat.enforcement\_applied.v1

security.device\_risk\_detected.v1  
security.location\_anomaly\_detected.v1  
security.qr\_abuse\_detected.v1  
security.runtime\_tamper\_detected.v1  
security.commerce\_abuse\_detected.v1

anti\_cheat.flag\_created.v1:  
flag\_id  
category  
rule\_code  
severity  
risk\_score  
session\_id  
team\_id nullable  
player\_id nullable  
evidence\_summary

25\. Media Events  
media.upload\_created.v1  
media.ready.v1  
media.quarantined.v1  
media.archived.v1  
media.deleted.v1  
media.reference\_added.v1  
media.reference\_removed.v1

26\. Audit Events  
audit.recorded.v1 usually persisted internally and not re-emitted broadly to avoid event loops.  
Audit store itself append-only.

27\. Operational/Outbox Events  
outbox.event\_published.v1  
outbox.event\_failed.v1  
worker.job\_failed.v1  
system.degraded\_mode\_entered.v1  
system.degraded\_mode\_exited.v1

These are ops/monitoring events, not domain gameplay.

28\. Realtime Event Catalog  
Realtime event names intentionally simpler but versioned payload still applies.

session.state\_changed  
team.state\_changed  
player.presence\_changed  
runtime.node\_unlocked  
runtime.node\_started  
runtime.node\_completed  
runtime.role\_assigned  
runtime.timer\_changed  
challenge.created  
challenge.updated  
score.changed  
leaderboard.changed  
hint.consumed  
payment.updated  
entitlement.updated  
anti\_cheat.flagged  
message.received  
gamemaster.emergency

Realtime envelope:  
{  
  "event\_id": "...",  
  "event\_type": "score.changed",  
  "payload\_version": 1,  
  "occurred\_at": "...",  
  "data": {}  
}

29\. Realtime Channels  
private-user.{user\_id}  
private-session.{session\_id}  
private-session.{session\_id}.team.{team\_id}  
private-session.{session\_id}.player.{player\_id}  
private-gamemaster.{session\_id}  
private-organization.{organization\_id}.admins

Authorization for subscription server-side.

30\. Realtime Privacy Rules  
Player channel فقط اطلاعات لازم همان Player.  
Team channel فقط داده Team.  
رقیب‌ها location خام همدیگر را نمی‌گیرند مگر Game/Scenario policy صریح.  
Payment detail فقط payer/admin scopes.  
Anti-cheat detail فقط GameMaster/Admin.  
Public-ish leaderboard event باید visibility policy را رعایت کند.

31\. Producer / Consumer Matrix Summary  
Identity \-\> Audit, Security, Analytics  
Teams \-\> Sessions, Billing context, Analytics  
Sessions \-\> Runtime, Realtime, Billing, Analytics  
Scenario \-\> Runtime, Builder, Analytics  
Runtime \-\> Score, Realtime, Analytics, Anti-Cheat  
Location \-\> Geofence, Anti-Cheat, Runtime trigger  
QR \-\> Runtime, Anti-Cheat, Analytics  
AR \-\> Runtime, Score, Analytics  
Live Challenge \-\> Realtime, Score, Analytics  
Hints \-\> Score, Commerce, Realtime, Analytics  
Billing \-\> Session gate, Commerce, Analytics  
Commerce \-\> Hints, Realtime, Analytics  
Anti-Cheat \-\> GameMaster, Realtime limited, Audit

32\. Retry Rules  
Retryable:  
temporary DB/network failure  
broker unavailable  
websocket publish failure  
provider temporary error

Non-retryable:  
schema invalid  
unknown event type  
unsupported payload major version  
authorization/business rejection already committed as rejection event

Consumer retry uses exponential backoff \+ jitter.  
Dead-letter/FAILED state after configured attempts.

33\. Idempotent Consumer Rule  
Consumer stores/recognizes event\_id when side effect is non-idempotent.  
At minimum required for:  
payment verified consumer  
entitlement grant  
score projection  
runtime transition side effects  
notification send where duplicate is undesirable

34\. Event Schema Evolution  
Add optional field \=\> same v1.  
Add required field with safe default \=\> prefer optional first.  
Rename/remove/change meaning \=\> v2.  
Consumer must ignore unknown optional fields.  
Old v1 event historical records stay valid.

35\. Event Retention  
runtime\_events: operational history based on product retention.  
billing/payment events: long-term financial retention.  
audit events: policy/legal retention.  
high-volume raw location not duplicated to generic event log.  
realtime delivery logs short retention unless needed for support.

36\. Event Security  
No password/token/secret/card raw data in events.  
Payment payload stores provider refs and safe metadata only.  
Location events are sensitive and least-privilege.  
PII minimized in analytics-bound event payloads.

37\. Event Testing  
Contract test per event type.  
Producer test validates payload schema.  
Consumer idempotency tests.  
Outbox retry tests.  
Ordering-sensitive aggregate tests.  
Duplicate delivery tests.  
Unknown field compatibility tests.  
Breaking schema test requires version bump.

38\. Definition of Done  
Naming convention frozen.  
Envelope frozen.  
At-least-once semantics frozen.  
Major event groups cataloged.  
Producer/consumer expectations defined.  
Realtime channels/events defined.  
Privacy and retry rules defined.  
Codex must register new cross-module Domain Event in this catalog or an approved revision before implementation.  
