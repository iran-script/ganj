Urban AR Team Adventure Game — Codex Master Implementation Specification v1

وضعیت: FROZEN IMPLEMENTATION CONTRACT  
هدف: این سند دستور اجرایی رسمی برای Codex است. Codex حق ندارد خارج از قراردادهای Freeze شده، Business Rule جدید اختراع کند یا Rule موجود را ساده‌سازی/حذف کند.

1\. منابع مرجع رسمی  
Codex باید قبل از شروع هر Phase این اسناد را مرجع قرار دهد:  
1\) Product Blueprint v1  
2\) API Contract v1  
3\) OpenAPI 3.1 — openapi.yaml  
4\) Database & Data Model Specification v1  
5\) State Machine Specification v1  
6\) Event Catalog Specification v1  
7\) Permission Matrix Specification v1

Priority در تعارض:  
Business Rule \=\> Blueprint  
HTTP behavior \=\> API Contract  
Path/Schema machine-readable \=\> OpenAPI  
Schema/constraints \=\> Database Spec  
Transitions \=\> State Machine  
Events \=\> Event Catalog  
Authorization \=\> Permission Matrix

2\. قواعد غیرقابل مذاکره  
\- Laravel Backend با Modular Monolith  
\- PostgreSQL \+ PostGIS  
\- Redis  
\- Queue/Workers  
\- WebSocket/Broadcasting  
\- Flutter Mobile  
\- REST API versioned زیر /api/v1  
\- Server-authoritative runtime  
\- Thin Controllers  
\- Business Logic در Application/Domain Services  
\- Migration-only schema changes  
\- Test-first for critical domain rules  
\- No scenario-specific hard-coded logic in Core  
\- No client-trusted payment success  
\- No direct client score mutation  
\- No Redis-only critical state  
\- No UI-only authorization  
\- No silent cross-tenant access  
\- No unversioned breaking event or API change

3\. ساختار پیشنهادی Backend Modules  
Modules:  
Identity  
Organizations  
Teams  
Games  
Sessions  
Billing  
Commerce  
Scenarios  
Runtime  
Locations  
Triggers  
AR  
Challenges  
Scoring  
AntiCheat  
Media  
Audit  
AnalyticsSupport  
Platform

هر Module ترجیحاً:  
Domain/  
Application/  
Infrastructure/  
Http/  
Policies/  
Events/  
Tests/

Shared فقط برای cross-cutting concerns:  
Shared/Support  
Shared/Contracts  
Shared/Exceptions  
Shared/Identifiers  
Shared/Clock  
Shared/Idempotency  
Shared/Transactions

4\. Phase 0 — Foundation

هدف:  
پایه Production-grade بدون Business Feature کامل.

Deliverables:  
\- Laravel project bootstrap  
\- PHP/version constraints  
\- PostgreSQL connection  
\- PostGIS extension migration  
\- Redis connection  
\- Queue config  
\- Worker config  
\- Broadcast/WebSocket foundation  
\- API /api/v1 routing  
\- Standard success/error envelope  
\- request\_id middleware  
\- structured logging  
\- health endpoint  
\- environment configuration  
\- Docker compose for app/db/redis/worker/scheduler  
\- test infrastructure  
\- lint/format/static analysis baseline  
\- CI-ready commands  
\- migration discipline  
\- base exception mapping  
\- base idempotency infrastructure  
\- base audit/outbox abstractions

Mandatory Tests:  
database connectivity  
PostGIS enabled  
Redis connectivity  
queue job processing  
API error shape  
request id  
health endpoint  
migration up/down smoke  
idempotency primitive test

Exit Criteria:  
all tests pass  
container stack boots  
/api/v1 health works  
PostGIS query works  
queue works  
Redis works

5\. Phase 1.1 — Identity & Player Device

Implement:  
users  
user\_devices  
personal access tokens  
register/login/logout/me  
device register/list/revoke  
user status  
device binding contract hooks  
reconnect-compatible token flow

Rules:  
one user may have multiple registered devices  
one active device per player per game session  
device sensitive actions guarded  
user statuses ACTIVE/SUSPENDED/BLOCKED

Tests:  
register validation  
login success/fail/rate limit  
revoked device  
revoked token  
blocked user  
device uniqueness  
wrong device rejection contract  
auth error codes

6\. Phase 1.2 — Organizations & Tenant Baseline

Implement:  
organizations  
organization\_members  
organization\_settings  
organization scope middleware/service  
tenant-aware policies  
role assignment baseline

Rules:  
cross-tenant access denied  
SuperAdmin global  
OrganizationAdmin scoped  
GameMaster scoped  
Player scoped

Tests:  
same role wrong tenant  
cross-tenant ID guessing  
org membership removal  
role change  
tenant query scope

7\. Phase 1.3 — Teams

Implement:  
teams  
team\_members  
team\_invites  
captain rules  
invite join/leave/remove  
captain transfer  
archive  
team eligibility derived 4..8

Rules:  
team can exist 1..8  
game eligibility only 4..8  
captain is derived capability  
history not erased

Tests:  
member 9 rejected  
duplicate member  
expired/revoked invite  
captain transfer  
captain leave without transfer rejected  
archive constraints  
history preserved

8\. Phase 1.4 — Games & Sessions

Implement:  
games  
game\_prices  
game\_sessions  
game\_session\_teams  
game\_session\_players  
roster snapshots  
state transitions  
registration window  
ready flow  
late join roster-only  
atomic start  
pause/resume/finish/cancel

Session States:  
DRAFT  
REGISTRATION  
READY  
RUNNING  
PAUSED  
FINISHED  
CANCELLED

Critical transaction:  
start\_session

Start must atomically:  
validate state  
validate 1..5 teams  
validate 4..8 roster  
validate 4 ready  
validate devices  
validate billing  
validate scenario assignment  
freeze roster  
freeze pricing  
pin scenario  
create runtime instances  
emit event  
transition RUNNING

Tests:  
5 teams allowed  
6th rejected  
team below 4 rejected  
ready below 4 blocks start  
billing fail blocks start  
scenario missing blocks start  
double start idempotent  
partial start rollback  
late join non-roster rejected

9\. Phase 1.5 — Billing & Payments

Implement:  
pricing snapshots  
billable participations  
orders  
order\_items  
payments  
payment attempts  
webhook events  
refunds  
credits  
billing events  
PaymentProvider contract

Rules:  
unique player/session billable participation  
price snapshot immutable  
verified server-side only  
provider webhook idempotent  
no card raw storage  
billing gate required before session start

Tests:  
duplicate callback  
same idempotency key same result  
same key different payload conflict  
refund state  
payment verify fail  
reconnect no extra charge  
remove/readd no duplicate billable record

10\. Phase 1.6 — Store & Entitlements

Implement:  
store\_products  
purchases  
purchase\_items  
entitlements  
consumptions  
commerce events

First product:  
EXTRA\_HINT\_PACK

Rules:  
default 3 hints per stage  
extra via entitlement  
atomic consume  
no double spend  
no pay-to-win bypass  
scope support USER/TEAM/SESSION/ACCOUNT

Tests:  
consume last credit  
concurrent double consume  
expired entitlement  
wrong team entitlement  
refunded purchase entitlement reconciliation

11\. Phase 1.7 — Runtime & Event Timeline

Implement:  
team\_scenario\_instances  
team\_node\_instances  
runtime\_variables  
runtime\_commands  
runtime\_events  
roles  
timers  
outbox

Rules:  
append-only runtime events  
command-based transitions  
state reconstructable from DB  
Redis hot cache only  
pause-aware timers  
outbox at-least-once

Tests:  
duplicate command  
concurrent completion  
pause timer  
reconnect restore  
outbox retry  
event dedupe

12\. Phase 2 — GPS/PostGIS

Implement:  
checkpoints  
location\_events partitioned  
latest locations  
geofence evaluations  
route summaries  
location ingest/batch  
adaptive policy endpoint

Rules:  
SRID 4326  
GiST indexes  
accuracy-aware verification  
offline sequence replay  
late/out-of-order detection  
tracking only active session

Tests:  
inside/outside polygon  
radius  
poor accuracy uncertain  
duplicate sequence  
late sample  
partition insert  
load ingestion benchmark

13\. Phase 3 — Scenario Core

Implement:  
scenarios  
scenario\_versions  
nodes  
edges  
conditions  
variables  
subflows  
validation runs  
simulation runs  
registry-driven node types

Rules:  
published immutable  
draft editable  
version pinning  
no eval  
typed variables  
exactly one Start  
reachable End  
no dangling edges  
controlled loops

Tests:  
invalid graph  
duplicate node key  
unreachable end  
invalid condition  
published mutation rejected  
subflow version pin  
deterministic seed

14\. Phase 4 — Visual Scenario Builder Backend Contracts

Implement backend APIs:  
graph get/put  
revision token  
validation  
simulation  
publish  
duplicate

Frontend-specific builder can follow later but API/data model frozen.

Tests:  
optimistic conflict  
stale revision  
publish stale validation rejected  
simulate invalid graph

15\. Phase 5 — QR Trigger Engine

Implement:  
qr\_triggers  
trigger\_tokens  
qr\_scan\_events  
scan endpoint  
one-time token  
geofence validation  
role/player validation  
replay detection

Tests:  
copied token  
replay  
wrong role  
wrong stage  
outside area  
rate limit

16\. Phase 6 — Co-op Roles & Mechanics

Implement:  
4 active slots  
rotation policies  
role assignments  
split/team-together/barrier mechanics  
support player behavior  
late join next-stage rotation

Tests:  
team size 4..8  
exact max active slots 4  
fair rotation  
barrier completion  
role-specific action denied for wrong player

17\. Phase 7 — AR Runtime Contracts

Implement backend:  
ar\_challenges  
asset manifests  
challenge runs  
nonce validation  
asset version pin  
capability/fallback metadata  
completion verify

Flutter/Unity/native AR can be integrated after backend contract stable.

Tests:  
invalid nonce  
stale asset  
incompatible device  
duplicate completion  
fallback

18\. Phase 8 — Live Challenge Engine

Implement:  
challenge templates  
live challenges  
targets  
submissions  
realtime publish  
expiry  
reconnect semantics  
manual audited completion

Tests:  
target one player  
target team  
target all  
offline expiry  
duplicate submission  
cancel active  
fairness policy enforcement

19\. Phase 9 — Score, Hints, Leaderboard

Implement:  
score\_events  
team\_scores  
hint\_definitions  
hint\_consumptions  
leaderboard projection/cache

Tie-break:  
score desc  
effective time asc  
hints asc  
finish timestamp asc

Rules:  
score event-derived  
no set score  
manual delta \+ reason  
visibility modes

Tests:  
same score tie  
hint penalty  
manual adjustment  
leaderboard hidden  
cache rebuild from DB

20\. Phase 10 — Anti-Cheat

Implement:  
flags  
actions  
risk rules  
device/location/qr/runtime/commerce signals  
review flow  
enforcement

Rules:  
risk signal \!= automatic guilt  
allow+flag / soft block / hard block / review  
manual review available

Tests:  
false positive  
impossible travel  
QR abuse  
runtime tamper  
commerce replay  
disqualification audit

21\. Phase 11 — Admin & Media

Implement:  
admin APIs  
media assets  
media references  
safe archive/delete  
content operations  
version-safe publish  
localization-ready content

Tests:  
referenced media delete rejected  
tenant isolation  
published content protection  
upload MIME rejection

22\. Phase 12 — Analytics & Replay

Implement:  
event taxonomy projections  
session replay model  
operational reports  
commerce KPIs  
privacy-safe exports

Do not run heavy BI from hot transaction queries when avoidable.

23\. Phase 13 — Offline & Recovery

Mobile:  
durable local DB  
outbox  
retries  
cache  
session package  
reconcile

Backend:  
idempotent replay  
late metadata  
conflict response  
recovery snapshots

Tests:  
network drop  
duplicate replay  
out-of-order  
app crash  
reinstall/device replacement scenario

24\. Phase 14 — Production Hardening

Implement:  
observability  
metrics  
alerts  
queue dashboards  
backup  
restore drill  
rate limits  
security scanning  
load tests  
tenant security tests  
webhook security  
file upload hardening

Load target:  
\>1000 concurrent users  
location bursts  
concurrent session starts  
websocket fan-out  
payment callbacks

25\. Phase 15 — Field Pilot

No major new architecture.  
Execute:  
real GPS tests  
battery/network tests  
AR device matrix  
QR damage tests  
route safety  
GameMaster runbook  
support tooling  
incident handling

Pilot defects categorized and fixed before release.

26\. Phase 16 — Commercial Release

Prepare:  
production payment provider  
legal config hooks  
privacy/terms consent  
refund policy config  
feature flags  
release versions  
mobile minimum supported version  
commercial KPIs  
support operations

27\. Coding Standards

PHP:  
strict typing where practical  
DTO/Request objects for input  
Enums/value objects for stable domain concepts  
services not fat controllers  
repository abstraction only where useful, avoid ceremonial layers  
transactions explicit for critical operations  
exceptions mapped centrally

Database:  
FKs explicit  
indexes explicit  
constraints explicit  
no hidden business rule only in comments  
no mass nullable JSON for relational domain  
PostGIS native geometry

API:  
OpenAPI contract sync  
no undocumented route  
no arbitrary response shape  
errors standard

28\. Testing Standards

Every module:  
Unit  
Feature/API  
Integration where infra involved

Critical concurrency:  
parallel tests or DB lock tests

Required CI commands:  
composer validate  
php artisan test  
pint check  
static analysis if configured  
migration fresh test  
openapi validation  
docker build

29\. Documentation per Phase

Codex must update:  
README/module docs  
API/OpenAPI if changed  
migration notes  
event catalog if new event  
permission matrix if new action  
ADR if architecture decision  
known limitations

30\. ADR Requirement

ADR required for:  
new infrastructure technology  
new persistence model  
new cross-module pattern  
breaking API  
breaking event  
breaking schema  
change to frozen business rule  
service extraction/microservice

31\. No-Go Patterns

Forbidden:  
hard-coded scenario branching in controller  
direct DB state edits from controller  
client sends final score  
client says payment succeeded  
client chooses next node  
generic "admin can do anything" policy bypass  
cross-tenant unscoped query  
critical JSON blob replacing relational integrity  
Redis as only runtime truth  
queue event without outbox for critical transaction  
silent exception catch  
manual production schema change

32\. Milestone Completion Report

After each milestone Codex must output:  
Phase name  
Scope completed  
Files added/changed  
Migrations added  
API endpoints added/changed  
Domain events added  
Permissions added  
Tests run  
Test results  
Known limitations  
Security considerations  
Migration/rollback notes  
Next phase

33\. Implementation Order

Strict default order:  
Phase 0  
1.1 Identity  
1.2 Organizations  
1.3 Teams  
1.4 Games/Sessions  
1.5 Billing  
1.6 Commerce  
1.7 Runtime  
2 GPS  
3 Scenario Core  
4 Builder Contracts  
5 QR  
6 Co-op  
7 AR Contracts  
8 Live Challenges  
9 Score/Hints  
10 Anti-Cheat  
11 Admin/Media  
12 Analytics  
13 Offline  
14 Hardening  
15 Pilot  
16 Release

Parallel work فقط وقتی dependency روشن باشد.

34\. First Codex Task — Phase 0 Prompt

Task:  
Implement Phase 0 Foundation only.

Do not implement gameplay features.  
Do not create speculative tables outside Phase 0 infrastructure unless required by framework.

Required:  
Laravel project  
PostgreSQL/PostGIS  
Redis  
Queue  
Broadcast foundation  
/api/v1  
Docker  
health  
structured errors  
request id  
logging  
tests  
base audit/outbox/idempotency contracts

Validation:  
all tests passing  
containers healthy  
PostGIS check  
Redis check  
queue smoke  
migration smoke

Return milestone report.

35\. Second Codex Task  
Only after Phase 0 PASS:  
Implement Phase 1.1 Identity & Devices according to all frozen documents.

36\. Change Control  
If Codex detects contradiction:  
STOP changing rules.  
Report exact conflicting documents/sections.  
Prefer no implementation over inventing resolution.  
No hidden assumptions on commercial or security rules.

37\. Definition of Done — Master Spec  
Implementation sequence frozen.  
Module boundaries defined.  
No-Go rules defined.  
Testing gates defined.  
Documentation gates defined.  
First Codex task ready.  
All future code work must reference this Master Implementation Specification.  
