Urban AR Team Adventure Game — API Contract v1

وضعیت: FROZEN CONTRACT BASELINE  
مرجع: Product Blueprint v1  
هدف: قرارداد رسمی بین Laravel Backend، Flutter Mobile، Admin Web، AR Module، GameMaster Console و Codex.

1\. API Principles  
تمام APIهای عمومی محصول زیر /api/v1 قرار می‌گیرند.  
Transport فقط HTTPS در Production.  
فرمت Payload و Response: application/json; charset=utf-8.  
تمام mutationهای حساس باید Server-authoritative باشند.  
Client هرگز مجاز نیست current\_state، score، billing status، entitlement balance، scenario version یا completion result نهایی را مستقیم تعیین کند.  
Backward-compatible changes داخل v1 مجازند؛ breaking change نیازمند نسخه API جدید یا deprecation رسمی است.

2\. Authentication  
Mobile API از Bearer Token استفاده می‌کند.  
Header:  
Authorization: Bearer \<token\>

Device-aware requests باید device\_uuid معتبر نیز داشته باشند.  
پیشنهاد Header استاندارد:  
X-Device-Id: \<device\_uuid\>

برای mutationهای حساس:  
Idempotency-Key: \<opaque-client-generated-id\>

Token و Device دو مفهوم جدا هستند. Token هویت User را اثبات می‌کند؛ Device Binding اجازه اجرای عملیات حساس در Game Session را تعیین می‌کند.

3\. Standard Success Envelope  
برای object response:  
{  
  "data": {},  
  "meta": {}  
}

برای collection:  
{  
  "data": \[\],  
  "meta": {  
    "pagination": {  
      "cursor": null,  
      "next\_cursor": null,  
      "has\_more": false  
    }  
  }  
}

Endpointهایی که نیاز واقعی به meta ندارند می‌توانند meta را حذف کنند.

4\. Standard Error Contract  
تمام خطاهای Domain/API باید ساختار زیر را داشته باشند:  
{  
  "error": {  
    "code": "DEVICE\_NOT\_BOUND",  
    "message": "This device is not bound to the current game session.",  
    "details": {}  
  }  
}

error.code باید machine-readable و ثابت باشد.  
message برای نمایش یا log است و Client نباید Business Logic را بر اساس متن message بسازد.

Error classes:  
AUTHENTICATION\_REQUIRED  
AUTHORIZATION\_DENIED  
VALIDATION\_FAILED  
RESOURCE\_NOT\_FOUND  
CONFLICT  
RATE\_LIMITED  
STATE\_TRANSITION\_INVALID  
IDEMPOTENCY\_CONFLICT  
DEVICE\_NOT\_BOUND  
TEAM\_FULL  
TEAM\_NOT\_ELIGIBLE  
SESSION\_NOT\_OPEN  
SESSION\_TEAM\_CAPACITY\_REACHED  
SESSION\_ALREADY\_STARTED  
SESSION\_BILLING\_NOT\_READY  
SESSION\_NOT\_READY  
SCENARIO\_NOT\_ASSIGNED  
SCENARIO\_VERSION\_INVALID  
QR\_INVALID  
QR\_REPLAYED  
QR\_OUTSIDE\_ALLOWED\_AREA  
LOCATION\_INVALID  
LOCATION\_ACCURACY\_TOO\_LOW  
NODE\_NOT\_ACTIVE  
ACTION\_NOT\_ALLOWED  
HINT\_LIMIT\_REACHED  
ENTITLEMENT\_REQUIRED  
ENTITLEMENT\_EXHAUSTED  
PAYMENT\_REQUIRED  
PAYMENT\_VERIFICATION\_FAILED  
PAYMENT\_ALREADY\_PROCESSED  
ANTI\_CHEAT\_BLOCKED

5\. HTTP Status Mapping  
200 OK: successful read/update.  
201 Created: resource created.  
202 Accepted: async processing accepted.  
204 No Content: successful action with no body.  
400 Bad Request: malformed request.  
401 Unauthorized: authentication missing/invalid.  
403 Forbidden: authenticated but not permitted.  
404 Not Found.  
409 Conflict: domain/state/idempotency conflict.  
422 Unprocessable Entity: validation/domain input invalid.  
429 Too Many Requests.  
500 Internal Server Error.  
503 Service Unavailable for degraded critical dependency.

6\. Validation Error Details  
نمونه:  
{  
  "error": {  
    "code": "VALIDATION\_FAILED",  
    "message": "The request contains invalid fields.",  
    "details": {  
      "fields": {  
        "name": \["The name field is required."\],  
        "mobile": \["The mobile format is invalid."\]  
      }  
    }  
  }  
}

7\. Time and Timezone  
Backend همه timestampها را UTC نگه می‌دارد.  
فرمت API: ISO-8601.  
نمونه:  
2026-09-18T08:30:00Z  
Session timezone به‌صورت field جداگانه نگه‌داری می‌شود.  
Client timestamp تنها زمانی پذیرفته می‌شود که Endpoint contract آن را اجازه داده باشد.  
server\_received\_at مرجع Audit است.

8\. Public Identifiers  
شناسه‌های public باید UUID/ULID/opaque باشند.  
Auto-increment database IDs نباید برای resourceهای حساس به‌عنوان public identifier استفاده شوند.

9\. Idempotency  
الزامی برای:  
session start  
payment initiation  
payment verify callback  
store purchase  
refund  
runtime completion  
challenge completion  
hint consumption  
device rebind  
manual score adjustment

رفتار:  
همان key \+ همان payload \=\> همان logical result.  
همان key \+ payload متفاوت \=\> 409 IDEMPOTENCY\_CONFLICT.

10\. Pagination  
برای listهای رشدپذیر Cursor Pagination ترجیح داده می‌شود.  
Query:  
?cursor=\<cursor\>\&limit=50

Default limit: 25\.  
حداکثر معمول: 100 مگر Endpoint خاص.

11\. Sorting and Filtering  
Filter فقط از allow-list Endpoint استفاده می‌کند.  
Arbitrary SQL-like filtering ممنوع است.  
Sort fieldهای مجاز در Contract هر Endpoint تعریف می‌شوند.

12\. Rate Limits  
Login، Register، QR Scan، Payment Verify، Store Purchase و Runtime Submit rate limit مستقل دارند.  
429 باید در صورت امکان retry\_after\_seconds در details برگرداند.

13\. Audit Context  
Mutationهای مدیریتی حساس باید actor، reason و context قابل audit داشته باشند.  
برای Admin/GameMaster actionهای حساس reason اجباری است.

14\. Identity & Auth Endpoints  
POST /api/v1/auth/register  
POST /api/v1/auth/login  
POST /api/v1/auth/logout  
GET /api/v1/me  
PATCH /api/v1/me  
GET /api/v1/devices  
DELETE /api/v1/devices/{device\_id}

POST /api/v1/auth/register  
Auth: Public  
Idempotency: Recommended  
Request:  
name  
mobile یا email  
password  
device\_uuid  
device\_name  
platform  
app\_version

Response 201:  
user  
token  
device

POST /api/v1/auth/login  
Auth: Public  
Request:  
mobile/email  
password  
device\_uuid  
device\_name  
platform  
app\_version

Response:  
user  
token  
device

POST /api/v1/auth/logout  
Auth: Required  
Result: current token revoked.

GET /api/v1/me  
Auth: Required  
Response: current User profile \+ system role summary.

GET /api/v1/devices  
Auth: Required  
Response: registered devices.

DELETE /api/v1/devices/{device\_id}  
Auth: Required  
Constraint: user may revoke own device unless blocked by current active-session policy.

15\. Team Endpoints  
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

POST /api/v1/teams  
Auth: Player  
Request:  
name  
avatar\_media\_id optional  
Response 201:  
team  
captain  
eligibility

POST /api/v1/teams/join  
Auth: Player  
Request:  
invite\_code  
Possible errors:  
TEAM\_FULL  
INVITE\_EXPIRED  
INVITE\_REVOKED  
ALREADY\_TEAM\_MEMBER

16\. Game Endpoints  
POST /api/v1/games  
GET /api/v1/games  
GET /api/v1/games/{game\_id}  
PATCH /api/v1/games/{game\_id}  
POST /api/v1/games/{game\_id}/archive

Admin/Organization scoped.

17\. Game Session Endpoints  
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

POST /api/v1/game-sessions/{session\_id}/start  
Auth: GameMaster  
Idempotency: Required  
Preconditions:  
Session READY  
1..5 teams  
4..8 roster players per team  
minimum 4 ready players per team  
billing gate PASS  
scenario assigned per team  
required devices bound

Response:  
session  
frozen\_rosters  
runtime\_instances  
started\_at

18\. Scenario Administration Endpoints  
POST /api/v1/scenarios  
GET /api/v1/scenarios  
GET /api/v1/scenarios/{scenario\_id}  
PATCH /api/v1/scenarios/{scenario\_id}  
POST /api/v1/scenarios/{scenario\_id}/versions  
GET /api/v1/scenarios/{scenario\_id}/versions  
GET /api/v1/scenario-versions/{version\_id}  
PATCH /api/v1/scenario-versions/{version\_id}  
POST /api/v1/scenario-versions/{version\_id}/validate  
POST /api/v1/scenario-versions/{version\_id}/publish  
POST /api/v1/scenario-versions/{version\_id}/duplicate  
POST /api/v1/scenario-versions/{version\_id}/simulate

Published version immutable.

19\. Scenario Graph Endpoints  
GET /api/v1/scenario-versions/{version\_id}/graph  
PUT /api/v1/scenario-versions/{version\_id}/graph

PUT graph requires optimistic version/revision token.  
Backend performs full graph validation.  
Client graph is never trusted without validation.

20\. Scenario Assignment Endpoints  
POST /api/v1/game-sessions/{session\_id}/scenario-assignments  
GET /api/v1/game-sessions/{session\_id}/scenario-assignments  
PATCH /api/v1/game-sessions/{session\_id}/scenario-assignments/{assignment\_id}

Assignment mode:  
manual  
automatic\_shuffle

21\. Runtime Endpoints  
GET /api/v1/game-sessions/{session\_id}/runtime  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/runtime  
GET /api/v1/game-sessions/{session\_id}/players/me/runtime

POST /api/v1/runtime/nodes/{node\_instance\_id}/start  
POST /api/v1/runtime/nodes/{node\_instance\_id}/submit  
POST /api/v1/runtime/nodes/{node\_instance\_id}/complete

Client cannot send target current\_node.  
Server resolves transition from pinned ScenarioVersion.

22\. Location/GPS Endpoints  
POST /api/v1/locations  
POST /api/v1/locations/batch  
GET /api/v1/game-sessions/{session\_id}/players/me/location-policy

POST /api/v1/locations  
Auth: Player  
Device Binding: Required during active game  
Request:  
session\_id  
lat  
lng  
accuracy  
altitude optional  
speed optional  
heading optional  
client\_timestamp  
sequence

Response:  
accepted  
server\_received\_at  
location\_policy optional  
nearby\_checkpoint\_state optional

23\. Checkpoint Endpoints  
POST /api/v1/checkpoints  
GET /api/v1/checkpoints  
GET /api/v1/checkpoints/{checkpoint\_id}  
PATCH /api/v1/checkpoints/{checkpoint\_id}

Scenario/Admin scoped.

24\. QR Endpoints  
POST /api/v1/qr/scan  
Auth: Player  
Device Binding: Required  
Request:  
session\_id  
qr\_identifier  
location optional if current location evidence not recent

Response:  
trigger\_type  
node/challenge reference  
one\_time\_token optional  
expires\_at optional

Possible errors:  
QR\_INVALID  
QR\_REPLAYED  
QR\_OUTSIDE\_ALLOWED\_AREA  
NODE\_NOT\_ACTIVE  
ACTION\_NOT\_ALLOWED

25\. AR Endpoints  
GET /api/v1/ar/challenges/{challenge\_id}  
POST /api/v1/ar/challenges/{challenge\_id}/start  
POST /api/v1/ar/challenges/{challenge\_id}/complete

Completion request:  
challenge\_nonce  
asset\_version  
completion\_payload  
client\_timestamp  
evidence\_metadata

Server verifies runtime eligibility.

26\. Live Challenge Endpoints  
POST /api/v1/game-sessions/{session\_id}/live-challenges  
GET /api/v1/game-sessions/{session\_id}/live-challenges  
GET /api/v1/live-challenges/{challenge\_id}  
POST /api/v1/live-challenges/{challenge\_id}/ack  
POST /api/v1/live-challenges/{challenge\_id}/start  
POST /api/v1/live-challenges/{challenge\_id}/submit  
POST /api/v1/live-challenges/{challenge\_id}/cancel

GameMaster create request includes:  
template\_id optional  
type  
targets  
duration  
reward  
penalty  
interrupt\_policy  
completion\_rule

27\. Hint Endpoints  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/hints  
POST /api/v1/runtime/nodes/{node\_instance\_id}/hints/consume

consume is Idempotent \+ Atomic.  
Server determines whether default Hint or purchased Entitlement is consumed.

28\. Score & Leaderboard Endpoints  
GET /api/v1/game-sessions/{session\_id}/leaderboard  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score-events  
POST /api/v1/game-sessions/{session\_id}/teams/{team\_id}/score-adjustments

Score adjustment:  
GameMaster/Admin only  
reason required  
before/after audit generated server-side

29\. Billing Endpoints  
GET /api/v1/game-sessions/{session\_id}/billing  
POST /api/v1/game-sessions/{session\_id}/orders  
GET /api/v1/orders/{order\_id}  
POST /api/v1/orders/{order\_id}/pay  
POST /api/v1/payments/{payment\_id}/verify  
POST /api/v1/payments/webhooks/{provider}  
POST /api/v1/orders/{order\_id}/refund

Webhook endpoints authenticate provider signature/verification, not Bearer User token.

30\. Store Endpoints  
GET /api/v1/store/products  
GET /api/v1/store/products/{product\_id}  
POST /api/v1/store/purchases  
GET /api/v1/store/purchases/{purchase\_id}  
GET /api/v1/game-sessions/{session\_id}/teams/{team\_id}/entitlements

Store purchase is idempotent.  
Product price snapshot stored at purchase.

31\. GameMaster Operations  
GET /api/v1/game-sessions/{session\_id}/control-room  
POST /api/v1/game-sessions/{session\_id}/teams/{team\_id}/pause  
POST /api/v1/game-sessions/{session\_id}/teams/{team\_id}/resume  
POST /api/v1/game-sessions/{session\_id}/messages  
POST /api/v1/game-sessions/{session\_id}/players/{player\_id}/rebind-device  
POST /api/v1/game-sessions/{session\_id}/players/{player\_id}/disqualify

Sensitive operations require reason where applicable.

32\. Anti-Cheat Endpoints  
GET /api/v1/game-sessions/{session\_id}/anti-cheat/flags  
GET /api/v1/anti-cheat/flags/{flag\_id}  
POST /api/v1/anti-cheat/flags/{flag\_id}/review  
POST /api/v1/anti-cheat/flags/{flag\_id}/resolve

Internal anti-cheat ingestion endpoints are not public Client API.

33\. Media Endpoints  
POST /api/v1/media  
GET /api/v1/media/{media\_id}  
DELETE /api/v1/media/{media\_id}

Uploads use allow-listed MIME, size limits and ownership scope.  
Delete blocked when asset has active references unless safe archival path exists.

34\. Organization/Admin Endpoints  
GET /api/v1/organizations  
POST /api/v1/organizations  
GET /api/v1/organizations/{organization\_id}  
PATCH /api/v1/organizations/{organization\_id}  
GET /api/v1/organizations/{organization\_id}/members  
POST /api/v1/organizations/{organization\_id}/members  
DELETE /api/v1/organizations/{organization\_id}/members/{user\_id}

All requests tenant-scoped.

35\. Audit Endpoints  
GET /api/v1/audit-events  
GET /api/v1/audit-events/{event\_id}

Audit events are read-only via API.

36\. Realtime Events  
Realtime transport via WebSocket/Broadcasting.  
Core channels:  
private-user.{user\_id}  
private-session.{session\_id}  
private-session.{session\_id}.team.{team\_id}  
private-gamemaster.{session\_id}

Core events:  
session.state\_changed  
team.state\_changed  
player.presence\_changed  
runtime.node\_started  
runtime.node\_completed  
runtime.role\_assigned  
challenge.created  
challenge.updated  
score.changed  
leaderboard.changed  
hint.consumed  
payment.updated  
entitlement.updated  
anti\_cheat.flagged  
message.received

Every realtime event has:  
event\_id  
event\_type  
payload\_version  
occurred\_at  
data

37\. API Security Rules  
Authorization always server-side.  
Organization scope always server-side.  
Device Binding enforced server-side.  
Scenario current node enforced server-side.  
Payment verified server-side.  
Score calculated server-side.  
Entitlement balance calculated server-side.  
QR one-time tokens validated server-side.  
Client-provided user\_id/team\_id cannot override authenticated scope.

38\. API Observability  
Every request gets request\_id.  
Recommended response header:  
X-Request-Id

Structured logs include when available:  
request\_id  
user\_id  
organization\_id  
session\_id  
team\_id  
device\_id  
route  
status\_code  
duration\_ms

39\. Deprecation  
Deprecated Endpoint must return Sunset/Deprecation metadata where supported and stay available through documented transition period.  
Breaking removal inside v1 without migration window ممنوع است.

40\. Contract Governance  
این سند مرجع Human-readable API است.  
OpenAPI 3.1 مرجع machine-readable خواهد بود.  
اگر Blueprint، API Contract و OpenAPI اختلاف داشته باشند:  
1\) Ruleهای Freeze شده Blueprint برای Business Domain مرجع هستند.  
2\) API Contract برای behavior/semantics مرجع است.  
3\) OpenAPI برای schema/path implementation مرجع است و باید با دو سند بالا اصلاح شود.

هر تغییر Breaking نیازمند:  
ADR  
Blueprint amendment در صورت تغییر Business Rule  
API Contract revision  
OpenAPI revision  
Migration plan  
Tests  
