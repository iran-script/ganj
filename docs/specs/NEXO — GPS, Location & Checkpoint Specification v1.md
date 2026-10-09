NEXO — GPS, Location & Checkpoint Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define NEXO location ingestion, PostGIS checkpoint/geofence verification, adaptive tracking, privacy, offline replay and runtime integration without trusting client location truth.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Phase 0 Foundation  
\- Identity & Player Device  
\- Organizations/Tenant  
\- Team Management  
\- Game & Game Session  
\- Scenario Core  
\- Runtime Engine  
\- Database Specification  
\- Event Catalog  
\- Permission Matrix

Provides upstream capability for:  
\- GPS/Location scenario nodes  
\- checkpoint verification  
\- QR geofence support  
\- anti-cheat location signals  
\- live map  
\- route replay  
\- GameMaster operations

2\. CORE PRINCIPLE

Client provides evidence.  
Server decides location truth.

Client may submit:  
\- lat  
\- lng  
\- accuracy  
\- altitude optional  
\- speed optional  
\- heading optional  
\- client\_timestamp  
\- sequence

Server assigns:  
\- server\_received\_at  
\- acceptance/rejection  
\- late/out-of-order flags  
\- geofence/checkpoint result  
\- anti-cheat signals

3\. COORDINATE SYSTEM

Canonical storage:  
\- EPSG:4326

Location point:  
\- geometry(Point, 4326\)

Checkpoint geometry:  
\- Point or Zone/Polygon where supported

Spatial indexes:  
\- GiST

4\. LOCATION EVENT MODEL

Canonical location event fields:  
\- id / public reference if exposed  
\- game\_session\_id  
\- game\_session\_player\_id  
\- device\_id  
\- geom  
\- accuracy  
\- altitude nullable  
\- speed nullable  
\- heading nullable  
\- client\_timestamp  
\- server\_received\_at  
\- sequence  
\- is\_late  
\- metadata

High-volume table:  
\- partition by server\_received\_at

5\. LATEST LOCATION

Maintain durable read model:  
player\_latest\_locations

Purpose:  
\- quick current position  
\- reconnect fallback  
\- live map  
\- runtime proximity checks

Redis may cache latest position.  
PostgreSQL remains durable fallback.

6\. LOCATION SUBMISSION API

POST /api/v1/locations

Required:  
\- auth  
\- X-Device-Id  
\- valid Session participation  
\- bound Device for active gameplay  
\- valid numeric coordinate ranges  
\- acceptable timestamp format  
\- sequence

Response:  
\- accepted  
\- server\_received\_at  
\- policy update optional  
\- nearby checkpoint state optional

7\. BATCH API

POST /api/v1/locations/batch

Purpose:  
\- offline replay  
\- short network outages  
\- mobile outbox flush

Rules:  
\- ordered by client sequence  
\- duplicate-safe  
\- each sample independently validated  
\- batch must not bypass Session/Device authorization

8\. SEQUENCE

Each Device/Session Player stream uses monotonic sequence.

Server detects:  
\- duplicate sequence  
\- lower/out-of-order sequence  
\- gaps

Duplicate exact sample:  
\- idempotent/ignored according to ingestion design

Out-of-order sample:  
\- may be persisted as late  
\- must not silently overwrite newer latest state

9\. CLIENT TIMESTAMP

Client timestamp is evidence only.

Server uses:  
\- server\_received\_at for audit/order of receipt  
\- client\_timestamp for route/replay and late detection

Future timestamp beyond tolerance:  
\- reject or flag according to policy

Stale timestamp:  
\- may persist but marked late

10\. ACCURACY

GPS accuracy is first-class evidence.

Each sample includes accuracy meters.

Node/checkpoint policy may define:  
\- min\_accuracy  
\- max acceptable uncertainty

Poor accuracy must not independently complete sensitive node.

Possible result:  
\- UNCERTAIN

11\. CHECKPOINT MODEL

Checkpoint fields:  
\- public\_id  
\- organization\_id  
\- name  
\- type  
\- geom  
\- radius\_meters nullable  
\- config  
\- status  
\- timestamps

Types:  
\- POINT  
\- ZONE

12\. POINT CHECKPOINT

For POINT:  
\- geom Point  
\- radius\_meters required

Distance:  
\- server-side spatial calculation

Completion:  
distance \<= configured radius  
subject to accuracy policy

13\. ZONE CHECKPOINT

For ZONE:  
\- geometry polygon/multipolygon where supported  
\- point-in-polygon server-side

Accuracy may create boundary uncertainty.

14\. GEOFENCE RESULT

Canonical:  
\- INSIDE  
\- OUTSIDE  
\- UNCERTAIN

Store geofence evaluation:  
\- session  
\- player  
\- checkpoint  
\- location\_event  
\- result  
\- distance\_meters nullable  
\- accuracy  
\- evaluated\_at

15\. ACCURACY-AWARE POINT RULE

Recommended verification:  
\- compute measured distance d  
\- consider reported accuracy a

If d clearly within radius with acceptable accuracy:  
INSIDE

If d clearly outside:  
OUTSIDE

If uncertainty overlaps boundary:  
UNCERTAIN

Exact thresholds configurable and documented in implementation.

16\. DWELL TIME

Location node may require dwell\_time.

Completion requires:  
\- qualifying presence over duration  
\- server-side evidence chain  
\- no single stale point

Dwell resets or degrades according to node policy when evidence leaves/uncertain.

17\. REQUIRED PLAYER COUNT

Node may require:  
\- one player  
\- N players  
\- active-role players  
\- entire team threshold

Runtime combines verified player checkpoint states.

18\. REQUIRED ROLES

Location node may require specific runtime roles.

Example:  
Navigator \+ Scanner present.

Role truth comes from Runtime role assignments.

19\. ADAPTIVE TRACKING

Tracking cadence is policy-driven.

Conceptual defaults:  
\- normal movement: \~8–10s  
\- near checkpoint: \~2–3s  
\- stationary: \~20–30s

These are configurable, not hardcoded business invariants.

20\. LOCATION POLICY ENDPOINT

GET /api/v1/game-sessions/{session\_id}/players/me/location-policy

May return:  
\- active  
\- interval\_seconds  
\- near\_checkpoint\_interval  
\- stationary\_interval  
\- min\_accuracy guidance  
\- background tracking requirement  
\- policy version

21\. POLICY CHANGES

Server may change tracking policy based on:  
\- Session state  
\- runtime node  
\- proximity  
\- battery/network mode  
\- operational config

Event:  
location.policy\_changed.v1

22\. BACKGROUND TRACKING

Only active when:  
\- Player is in relevant active Session  
\- permissions/consent available  
\- game policy requires it

When no active gameplay:  
\- game location tracking should stop

23\. PRIVACY

Raw location is sensitive.

Rules:  
\- least privilege  
\- no competitor raw location by default  
\- retention limited  
\- purpose-bound processing  
\- export restricted  
\- player location outside active game not collected for game purposes

24\. LOCATION VISIBILITY

Player:  
\- own location  
\- Team context only if game/scenario allows

GameMaster:  
\- assigned Session operational locations

OrganizationAdmin:  
\- own Organization operational access

Competitor:  
\- no raw location by default

25\. RETENTION

Raw location events:  
\- configurable retention  
\- archive/purge supported

Route summaries:  
\- may retain longer with simplified geometry

Audit/legal requirements:  
\- separated from raw GPS history

26\. ROUTE SUMMARY

Generate:  
\- simplified LineString  
\- distance\_meters  
\- started\_at  
\- ended\_at  
\- generated\_at

Used for:  
\- replay  
\- analytics  
\- post-event reporting

Do not rely on route summary for authoritative checkpoint completion.

27\. OFFLINE MOBILE OUTBOX

Flutter stores:  
\- client\_action\_id  
\- sequence  
\- sample payload  
\- created\_at

On reconnect:  
\- batch replay

Server:  
\- detects late  
\- preserves event time  
\- prevents duplicate latest overwrite

28\. LATE / OUT-OF-ORDER

Late samples:  
\- can be stored  
\- may inform route history  
\- do not retroactively rewrite completed sensitive gameplay unless explicit reconciliation policy allows

29\. SESSION STATE GUARD

Location submission accepted for gameplay only in relevant Session states:  
\- RUNNING  
\- PAUSED may accept presence/telemetry according to policy, but must not progress gameplay if paused

Pre-start:  
\- limited device/location validation only if explicitly allowed

Finished/Cancelled:  
\- gameplay location submit rejected

30\. DEVICE BINDING

Active gameplay location submit requires:  
\- authenticated User  
\- registered non-revoked Device  
\- bound to current game\_session\_player

Wrong Device:  
DEVICE\_NOT\_BOUND

31\. RUNTIME INTEGRATION

GPS node handler requests verified checkpoint state.

Runtime does not trust:  
\- client reached flag  
\- raw distance from client

Location domain returns authoritative result/evidence reference.

32\. CHECKPOINT COMPLETION EVENT

Canonical:  
location.checkpoint\_verified.v1  
location.checkpoint\_rejected.v1  
location.geofence\_entered.v1  
location.geofence\_exited.v1  
location.geofence\_uncertain.v1

Meaningful derived events go to event bus.

33\. RAW LOCATION EVENTS AND OUTBOX

Do not publish every raw GPS sample through generic outbox at high volume.

Raw samples:  
\- optimized persistence path

Derived meaningful events:  
\- general event/outbox/realtime as needed

34\. ANTI-CHEAT SIGNALS

Location layer may flag:  
\- impossible jump  
\- unrealistic speed  
\- stale location abuse  
\- repeated poor accuracy  
\- sequence anomalies  
\- mock-location signal if available  
\- checkpoint mismatch

Signal \!= guilt.  
Anti-Cheat domain decides enforcement.

35\. SPEED / IMPOSSIBLE TRAVEL

Server compares:  
\- prior accepted evidence  
\- time delta  
\- distance  
\- reported speed

Thresholds:  
\- configurable by game/context

Do not hardcode one global human speed as proof.

36\. MOBILE BATTERY

Tracking must be battery-conscious.

Use:  
\- adaptive cadence  
\- significant movement awareness where available  
\- pause/stop tracking outside game

Battery optimization must not reduce sensitive checkpoint verification below required evidence quality.

37\. MAP UI

Flutter map may show:  
\- own position  
\- allowed teammates  
\- revealed checkpoints  
\- current mission geometry/context

Do not expose:  
\- hidden future checkpoints  
\- competitor raw positions  
\- admin-only zones

38\. LIVE GAME MASTER MAP

Operational map may show:  
\- Team/player latest location  
\- freshness  
\- accuracy  
\- connection/presence  
\- checkpoint states

Access:  
\- assigned GameMaster  
\- scoped admin

39\. CHECKPOINT ADMIN

Canonical endpoints:  
POST /api/v1/checkpoints  
GET /api/v1/checkpoints  
GET /api/v1/checkpoints/{checkpoint\_id}  
PATCH /api/v1/checkpoints/{checkpoint\_id}

Organization/content scoped.

40\. CHECKPOINT STATUS

Recommended:  
\- ACTIVE  
\- INACTIVE  
\- ARCHIVED

Inactive/archived:  
\- not usable for new scenario config  
\- historical refs remain readable

41\. CROSS-TENANT

Checkpoint organization must match Scenario/Game/Session ownership path.

No cross-organization checkpoint reference.

42\. PUBLIC IDENTIFIERS

Client uses:  
\- checkpoint public\_id  
\- session public\_id  
\- player public refs where applicable

No internal sequence exposure.

43\. EVENTS

Canonical:  
location.sample\_accepted.v1  
location.sample\_rejected.v1  
location.batch\_accepted.v1  
location.geofence\_entered.v1  
location.geofence\_exited.v1  
location.geofence\_uncertain.v1  
location.checkpoint\_verified.v1  
location.checkpoint\_rejected.v1  
location.policy\_changed.v1

44\. REALTIME

Realtime may send:  
\- compact latest Team/player state  
\- checkpoint verified  
\- policy changed

Do not stream all raw GPS events indiscriminately.

45\. ERROR CONTRACT

Relevant:  
LOCATION\_INVALID  
LOCATION\_ACCURACY\_TOO\_LOW  
DEVICE\_NOT\_BOUND  
ACTION\_NOT\_ALLOWED  
RESOURCE\_NOT\_FOUND  
RATE\_LIMITED

Stable standard error envelope.

46\. RATE LIMITING

Location ingestion uses dedicated rate limit/burst policy.

Must support adaptive cadence without rejecting legitimate near-checkpoint bursts.

47\. DATABASE INDEXES

location\_events partitions:  
\- GiST geom  
\- game\_session\_player\_id \+ server\_received\_at  
\- game\_session\_id \+ server\_received\_at

checkpoints:  
\- GiST geom  
\- organization\_id \+ status

48\. PARTITIONING

Mandatory from initial production schema:  
\- location\_events partition by server\_received\_at

Partition interval:  
\- daily/monthly based on expected volume and operational testing

Partition management documented.

49\. CONCURRENCY

Latest location update:  
\- only newer accepted sequence/time replaces current projection

Checkpoint dwell:  
\- concurrent samples safe  
\- no duplicate completion event

50\. IDEMPOTENCY

Single sample:  
\- sequence/client action identity prevents duplicate effect

Batch:  
\- repeated batch safe

Checkpoint completion:  
\- same evidence cannot create duplicate runtime completion side effects

51\. PILOT v0.1

Pilot requires real:  
\- PostGIS  
\- location ingestion  
\- checkpoint Point radius  
\- accuracy  
\- sequence  
\- latest location  
\- runtime GPS node integration  
\- live map basic  
\- offline short replay

Pilot may defer:  
\- Polygon zones UI  
\- complex dwell analytics  
\- advanced anti-cheat  
\- sophisticated route analytics

Architecture remains compatible.

52\. TESTS — LOCATION INGESTION

\- valid location accepted  
\- invalid lat/lng rejected  
\- wrong Device rejected  
\- finished Session rejected  
\- duplicate sequence safe  
\- out-of-order marked late  
\- latest projection remains newest

53\. TESTS — POSTGIS

\- Point inside radius  
\- Point outside radius  
\- boundary uncertainty  
\- SRID enforced  
\- GiST query path  
\- polygon inside/outside if implemented

54\. TESTS — ACCURACY

\- good accuracy permits verify  
\- poor accuracy returns uncertain/reject based policy  
\- client cannot spoof reached=true

55\. TESTS — DWELL

\- enough continuous presence verifies  
\- insufficient time rejects  
\- exit resets/degrades according policy

56\. TESTS — MULTI-PLAYER

\- required\_player\_count met  
\- not met  
\- required role presence  
\- duplicate player samples do not inflate count

57\. TESTS — OFFLINE

\- queued batch replay  
\- duplicate replay  
\- late sample storage  
\- no retroactive invalid progression

58\. TESTS — SECURITY

\- Player cannot read competitor location  
\- GameMaster unassigned denied  
\- cross-tenant checkpoint denied  
\- raw export restricted  
\- hidden checkpoint not leaked

59\. DEPENDENCY FOR QR

QR may consume recent location evidence/checkpoint state.

QR implementation must not reinvent geofence calculations.

60\. DEFINITION OF DONE

GPS/Location design is frozen when:  
\- authoritative server verification explicit  
\- PostGIS/SRID/index strategy explicit  
\- location event schema explicit  
\- sequence/timestamps explicit  
\- accuracy-aware geofence explicit  
\- Point/Zone checkpoint model explicit  
\- dwell/player-count/role requirements explicit  
\- adaptive tracking explicit  
\- offline replay explicit  
\- privacy/retention explicit  
\- Runtime integration explicit  
\- anti-cheat boundary explicit  
\- Pilot uses same production architecture  
