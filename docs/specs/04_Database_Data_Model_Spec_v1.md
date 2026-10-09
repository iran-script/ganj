Urban AR Team Adventure Game — Database & Data Model Specification v1

وضعیت: FROZEN SCHEMA BASELINE  
Database: PostgreSQL \+ PostGIS  
مرجع بالادستی: Product Blueprint v1 \+ API Contract v1 \+ OpenAPI 3.1

1\. اصول سراسری دیتابیس  
کلیدهای public ترجیحاً ULID/UUID هستند. کلید داخلی می‌تواند bigint/uuid باشد ولی API نباید به sequence قابل حدس متکی باشد.  
تمام timestampها UTC.  
Soft-delete فقط جایی استفاده می‌شود که واقعاً لازم است؛ History مهم با status/archived\_at/left\_at نگه‌داری می‌شود.  
Business invariantهای حیاتی فقط در Service Layer رها نمی‌شوند و تا حد ممکن با UNIQUE/CHECK/FK/EXCLUDE/partial index نیز enforce می‌شوند.  
تمام جداول tenant-sensitive دارای organization\_id یا مسیر مالکیت قابل‌اثبات هستند.  
Schema فقط با Migration تغییر می‌کند.  
JSONB فقط برای extension/config/versioned payload مناسب است؛ داده relational اصلی نباید بی‌دلیل داخل JSON دفن شود.

2\. برآورد نهایی جدول‌ها  
نسخه Production حدود 72 جدول Core/Operational خواهد داشت. برخی جداول Analytics یا materialized/read-modelها بعداً ممکن است جدا شوند.

3\. Domain A — Identity, Access & Organizations

1\) users  
هدف: هویت اصلی کاربر.  
Fields:  
id  
public\_id  
name  
mobile nullable  
email nullable  
password\_hash  
status: ACTIVE|SUSPENDED|BLOCKED  
last\_login\_at  
created\_at  
updated\_at

Constraints:  
حداقل یکی از mobile/email باید موجود باشد.  
mobile در صورت وجود unique.  
email در صورت وجود unique case-insensitive.

Indexes:  
unique mobile  
unique lower(email)  
status

2\) user\_devices  
id  
public\_id  
user\_id FK users  
device\_uuid  
device\_name  
platform  
app\_version  
last\_seen\_at  
last\_ip  
revoked\_at  
created\_at  
updated\_at

Constraints:  
unique(user\_id, device\_uuid)

Indexes:  
user\_id  
last\_seen\_at  
revoked\_at

3\) personal\_access\_tokens  
Laravel/Sanctum token store یا معادل.  
Index:  
tokenable\_type, tokenable\_id  
unique token hash

4\) organizations  
id  
public\_id  
name  
slug  
status  
timezone\_default  
created\_at  
updated\_at  
archived\_at nullable

Constraints:  
unique slug

5\) organization\_members  
id  
organization\_id  
user\_id  
role: ORGANIZATION\_ADMIN|GAMEMASTER|STAFF  
status  
joined\_at  
left\_at nullable

Constraints:  
unique active membership per organization/user

Indexes:  
organization\_id,user\_id  
role  
status

6\) organization\_settings  
id  
organization\_id unique  
branding\_json  
commercial\_settings\_json  
invoice\_profile\_json  
payment\_config\_ref nullable  
created\_at  
updated\_at

7\) role\_assignments  
برای scopeهای قابل‌گسترش در صورت نیاز beyond organization\_members.  
id  
user\_id  
organization\_id nullable  
resource\_type nullable  
resource\_id nullable  
role\_code  
created\_at  
revoked\_at nullable

Indexes:  
user\_id  
organization\_id  
resource\_type,resource\_id

4\. Domain B — Teams

8\) teams  
id  
public\_id  
organization\_id nullable  
name  
slug nullable  
captain\_user\_id  
status ACTIVE|ARCHIVED  
avatar\_media\_id nullable  
created\_at  
updated\_at  
archived\_at nullable

Indexes:  
captain\_user\_id  
organization\_id  
status

9\) team\_members  
id  
team\_id  
user\_id  
status ACTIVE|LEFT|REMOVED  
joined\_at  
left\_at nullable  
removed\_by nullable  
created\_at

Constraints:  
unique active membership per team/user

Indexes:  
team\_id,status  
user\_id,status

10\) team\_invites  
id  
public\_id  
team\_id  
code\_hash  
created\_by  
expires\_at nullable  
max\_uses nullable  
uses\_count  
revoked\_at nullable  
created\_at

Constraints:  
unique code\_hash  
uses\_count \>= 0

Indexes:  
team\_id  
expires\_at  
revoked\_at

11\) team\_member\_events  
append-only business history.  
id  
team\_id  
user\_id  
event\_type  
actor\_id nullable  
payload jsonb  
occurred\_at

Index:  
team\_id,occurred\_at  
user\_id,occurred\_at

5\. Domain C — Games & Sessions

12\) games  
id  
public\_id  
organization\_id  
name  
slug  
description nullable  
status DRAFT|ACTIVE|ARCHIVED  
default\_timezone  
created\_at  
updated\_at  
archived\_at nullable

Constraints:  
unique(organization\_id, slug)

13\) game\_prices  
id  
game\_id  
currency  
price\_per\_player numeric  
effective\_from  
effective\_to nullable  
status  
created\_at

Indexes:  
game\_id,effective\_from

14\) game\_sessions  
id  
public\_id  
organization\_id  
game\_id  
status DRAFT|REGISTRATION|READY|RUNNING|PAUSED|FINISHED|CANCELLED  
timezone  
registration\_opens\_at nullable  
registration\_closes\_at nullable  
scheduled\_start\_at nullable  
started\_at nullable  
finished\_at nullable  
cancelled\_at nullable  
created\_by  
created\_at  
updated\_at

Indexes:  
organization\_id,status  
game\_id,status  
scheduled\_start\_at

15\) game\_session\_teams  
id  
game\_session\_id  
team\_id  
status REGISTERED|READY|ACTIVE|FINISHED|REMOVED|DISQUALIFIED  
registered\_at  
removed\_at nullable  
scenario\_assignment\_id nullable  
created\_at  
updated\_at

Constraints:  
unique(game\_session\_id, team\_id)  
حداکثر 5 active teams با Domain guard \+ locking.

Indexes:  
game\_session\_id,status  
team\_id

16\) game\_session\_players  
id  
public\_id  
game\_session\_id  
game\_session\_team\_id  
user\_id  
participation\_status JOINED|READY|ACTIVE|DISCONNECTED|REMOVED|DISQUALIFIED|FINISHED  
active\_device\_id nullable  
roster\_frozen\_at nullable  
joined\_runtime\_at nullable  
last\_seen\_at nullable  
created\_at  
updated\_at

Constraints:  
unique(game\_session\_id,user\_id)  
یک User در یک Session فقط در یک Team.

Indexes:  
game\_session\_id,game\_session\_team\_id  
user\_id  
participation\_status

17\) game\_session\_roster\_snapshots  
id  
game\_session\_team\_id  
version  
snapshot\_json  
frozen\_at  
created\_at

Constraints:  
unique(game\_session\_team\_id,version)

18\) game\_session\_state\_transitions  
append-only.  
id  
game\_session\_id  
from\_state  
to\_state  
actor\_type  
actor\_id nullable  
reason nullable  
occurred\_at

Index:  
game\_session\_id,occurred\_at

19\) session\_ready\_events  
id  
game\_session\_player\_id  
device\_id  
ready  
occurred\_at

Index:  
game\_session\_player\_id,occurred\_at

6\. Domain D — Pricing, Billing & Payments

20\) pricing\_snapshots  
id  
game\_session\_id  
game\_session\_player\_id  
game\_price\_id nullable  
currency  
unit\_price  
pricing\_rule\_json  
frozen\_at

Constraints:  
unique(game\_session\_id,game\_session\_player\_id)

21\) billable\_participations  
id  
game\_session\_id  
user\_id  
game\_session\_player\_id  
pricing\_snapshot\_id  
status RESERVED|BILLED|CREDITED|REFUNDED|VOID  
amount  
currency  
created\_at  
updated\_at

Constraints:  
unique(game\_session\_id,user\_id)

22\) orders  
id  
public\_id  
organization\_id  
game\_session\_id nullable  
payer\_user\_id nullable  
payer\_type  
status CREATED|PENDING|PAID|FAILED|CANCELLED|REFUNDED|PARTIALLY\_REFUNDED  
subtotal  
discount\_total  
total  
currency  
idempotency\_key  
created\_at  
updated\_at

Constraints:  
unique idempotency\_key where not null

Indexes:  
game\_session\_id  
payer\_user\_id  
status

23\) order\_items  
id  
order\_id  
item\_type PLAYER\_PARTICIPATION|STORE\_PRODUCT|ADJUSTMENT  
reference\_type  
reference\_id  
title\_snapshot  
quantity  
unit\_price  
total  
currency  
metadata jsonb

Index:  
order\_id

24\) payments  
id  
public\_id  
order\_id  
provider  
provider\_reference nullable  
status CREATED|PENDING|VERIFIED|FAILED|CANCELLED|REFUNDED  
amount  
currency  
idempotency\_key nullable  
initiated\_at  
verified\_at nullable  
failed\_at nullable  
metadata jsonb  
created\_at  
updated\_at

Constraints:  
unique(provider,provider\_reference) where provider\_reference not null  
unique idempotency\_key where not null

25\) payment\_attempts  
id  
payment\_id  
attempt\_no  
request\_payload jsonb  
response\_payload jsonb  
result  
occurred\_at

Index:  
payment\_id,attempt\_no

26\) payment\_webhook\_events  
id  
provider  
provider\_event\_id  
signature\_valid  
payload jsonb  
processed\_at nullable  
processing\_result  
received\_at

Constraints:  
unique(provider,provider\_event\_id)

27\) refunds  
id  
public\_id  
order\_id  
payment\_id nullable  
amount  
currency  
reason  
status REQUESTED|PROCESSING|SUCCEEDED|FAILED|CANCELLED  
requested\_by  
provider\_reference nullable  
created\_at  
updated\_at

28\) credits  
id  
organization\_id nullable  
user\_id nullable  
team\_id nullable  
amount  
currency  
source\_type  
source\_id  
expires\_at nullable  
created\_at  
consumed\_at nullable

29\) billing\_events  
append-only.  
id  
game\_session\_id nullable  
order\_id nullable  
payment\_id nullable  
event\_type  
payload jsonb  
occurred\_at

7\. Domain E — Store & Entitlements

30\) store\_products  
id  
public\_id  
organization\_id  
code  
type EXTRA\_HINT\_PACK|BONUS\_HINT\_TOKEN|GENERIC\_ADDON  
title  
description  
status DRAFT|ACTIVE|INACTIVE|ARCHIVED  
price  
currency  
credit\_quantity nullable  
scope PLAYER|TEAM|GAME\_SESSION|ACCOUNT  
rules\_json  
created\_at  
updated\_at

Constraints:  
unique(organization\_id,code)

31\) purchases  
id  
public\_id  
order\_id  
buyer\_user\_id  
game\_session\_id nullable  
team\_id nullable  
status  
created\_at  
completed\_at nullable

32\) purchase\_items  
id  
purchase\_id  
store\_product\_id  
product\_snapshot jsonb  
quantity  
unit\_price  
total

33\) entitlements  
id  
public\_id  
owner\_type USER|TEAM|SESSION  
owner\_id  
game\_session\_id nullable  
product\_id  
entitlement\_type  
initial\_quantity  
remaining\_quantity  
status ACTIVE|EXHAUSTED|EXPIRED|REVOKED  
expires\_at nullable  
created\_at  
updated\_at

Constraints:  
remaining\_quantity \>= 0

Indexes:  
owner\_type,owner\_id,status  
game\_session\_id

34\) entitlement\_consumptions  
id  
entitlement\_id  
game\_session\_id  
team\_id nullable  
player\_id nullable  
node\_instance\_id nullable  
quantity  
source  
idempotency\_key  
consumed\_at

Constraints:  
unique idempotency\_key  
quantity \> 0

35\) commerce\_events  
id  
purchase\_id nullable  
entitlement\_id nullable  
event\_type  
payload jsonb  
occurred\_at

8\. Domain F — Scenarios & Builder

36\) scenarios  
id  
public\_id  
organization\_id  
title  
description nullable  
theme nullable  
status DRAFT|PUBLISHED|ARCHIVED  
created\_by  
created\_at  
updated\_at  
archived\_at nullable

Indexes:  
organization\_id,status

37\) scenario\_versions  
id  
public\_id  
scenario\_id  
version\_no  
status DRAFT|PUBLISHED|ARCHIVED  
revision\_token  
difficulty nullable  
estimated\_duration\_seconds nullable  
estimated\_distance\_meters nullable  
physical\_effort nullable  
challenge\_mix jsonb  
published\_at nullable  
published\_by nullable  
created\_at  
updated\_at

Constraints:  
unique(scenario\_id,version\_no)

38\) scenario\_nodes  
id  
scenario\_version\_id  
node\_key  
node\_type  
title nullable  
config jsonb  
position\_x nullable  
position\_y nullable  
created\_at  
updated\_at

Constraints:  
unique(scenario\_version\_id,node\_key)

39\) scenario\_edges  
id  
scenario\_version\_id  
edge\_key  
from\_node\_id  
to\_node\_id  
priority  
condition\_group\_id nullable  
metadata jsonb

Constraints:  
unique(scenario\_version\_id,edge\_key)

Indexes:  
from\_node\_id  
to\_node\_id

40\) scenario\_conditions  
id  
scenario\_version\_id  
condition\_group\_id  
left\_operand  
operator  
right\_operand\_json  
value\_type  
position  
created\_at

Index:  
condition\_group\_id,position

41\) scenario\_variables  
id  
scenario\_version\_id  
key  
type  
scope SCENARIO|TEAM|PLAYER|NODE  
default\_value jsonb  
writable  
created\_at

Constraints:  
unique(scenario\_version\_id,key)

42\) scenario\_subflows  
id  
public\_id  
organization\_id  
name  
status  
created\_at  
updated\_at

43\) scenario\_subflow\_versions  
id  
scenario\_subflow\_id  
version\_no  
status  
graph\_json  
published\_at nullable

Constraints:  
unique(scenario\_subflow\_id,version\_no)

44\) scenario\_subflow\_bindings  
id  
scenario\_version\_id  
node\_id  
subflow\_version\_id

45\) scenario\_validation\_runs  
id  
scenario\_version\_id  
revision\_token  
status PASS|FAIL  
errors jsonb  
warnings jsonb  
created\_by  
created\_at

46\) scenario\_simulation\_runs  
id  
scenario\_version\_id  
input\_config jsonb  
result jsonb  
created\_by  
created\_at

9\. Domain G — Scenario Assignment & Runtime

47\) scenario\_assignments  
id  
game\_session\_id  
game\_session\_team\_id  
scenario\_version\_id  
mode MANUAL|AUTOMATIC\_SHUFFLE  
assigned\_by nullable  
assigned\_at  
seed nullable

Constraints:  
unique(game\_session\_team\_id)

48\) team\_scenario\_instances  
id  
public\_id  
game\_session\_id  
game\_session\_team\_id  
scenario\_version\_id  
status NOT\_STARTED|ACTIVE|PAUSED|COMPLETED|FAILED|CANCELLED  
current\_node\_instance\_id nullable  
random\_seed  
score\_cached  
effective\_elapsed\_seconds  
started\_at nullable  
completed\_at nullable  
created\_at  
updated\_at

Constraints:  
unique(game\_session\_team\_id)

49\) team\_node\_instances  
id  
public\_id  
team\_scenario\_instance\_id  
scenario\_node\_id  
status LOCKED|AVAILABLE|ACTIVE|COMPLETED|FAILED|SKIPPED  
attempt\_count  
started\_at nullable  
completed\_at nullable  
state\_json  
version\_no  
created\_at  
updated\_at

Constraints:  
unique(team\_scenario\_instance\_id,scenario\_node\_id)

Indexes:  
team\_scenario\_instance\_id,status

50\) runtime\_variables  
id  
team\_scenario\_instance\_id  
player\_id nullable  
node\_instance\_id nullable  
key  
value jsonb  
value\_type  
updated\_at

Unique strategy بر اساس scope.

51\) runtime\_commands  
id  
command\_id  
game\_session\_id  
team\_scenario\_instance\_id nullable  
player\_id nullable  
command\_type  
idempotency\_key  
request\_payload jsonb  
result\_payload jsonb  
status  
created\_at  
completed\_at nullable

Constraints:  
unique idempotency\_key

52\) runtime\_events  
append-only.  
id  
event\_id  
aggregate\_type  
aggregate\_id  
game\_session\_id  
team\_id nullable  
player\_id nullable  
event\_type  
payload\_version  
payload jsonb  
actor\_type  
actor\_id nullable  
occurred\_at  
created\_at

Constraints:  
unique event\_id

Indexes:  
game\_session\_id,occurred\_at  
team\_id,occurred\_at  
aggregate\_type,aggregate\_id,occurred\_at

53\) player\_role\_assignments  
id  
team\_scenario\_instance\_id  
node\_instance\_id  
game\_session\_player\_id  
role\_code  
slot\_no  
assigned\_at  
released\_at nullable  
assignment\_source

Constraints:  
حداکثر 4 active slot per stage با Domain lock/constraint strategy.

54\) runtime\_timers  
id  
team\_scenario\_instance\_id  
node\_instance\_id nullable  
timer\_type  
started\_at  
paused\_at nullable  
accumulated\_paused\_seconds  
duration\_seconds nullable  
status

10\. Domain H — GPS, GIS & Checkpoints

55\) checkpoints  
id  
public\_id  
organization\_id  
name  
type POINT|ZONE  
geom geometry(Geometry,4326)  
radius\_meters nullable  
config jsonb  
status  
created\_at  
updated\_at

Indexes:  
GiST geom  
organization\_id,status

56\) location\_events  
high-volume partitioned table.  
id bigint  
game\_session\_id  
game\_session\_player\_id  
device\_id  
geom geometry(Point,4326)  
accuracy  
altitude nullable  
speed nullable  
heading nullable  
client\_timestamp  
server\_received\_at  
sequence  
is\_late  
metadata jsonb

Partition:  
range by server\_received\_at (مثلاً monthly/daily بر اساس حجم)

Indexes per partition:  
GiST geom  
(game\_session\_player\_id,server\_received\_at)  
(game\_session\_id,server\_received\_at)

57\) player\_latest\_locations  
read model / cache-persisted fallback.  
game\_session\_player\_id PK  
geom geometry(Point,4326)  
accuracy  
client\_timestamp  
server\_received\_at  
sequence

58\) geofence\_evaluations  
id  
game\_session\_id  
player\_id  
checkpoint\_id  
location\_event\_id  
result INSIDE|OUTSIDE|UNCERTAIN  
distance\_meters nullable  
accuracy  
evaluated\_at

Indexes:  
game\_session\_id,checkpoint\_id  
player\_id,evaluated\_at

59\) route\_summaries  
id  
game\_session\_player\_id  
simplified\_geom geometry(LineString,4326)  
distance\_meters  
started\_at  
ended\_at  
generated\_at

11\. Domain I — QR & Trigger Engine

60\) qr\_triggers  
id  
public\_id  
organization\_id  
identifier\_hash  
checkpoint\_id nullable  
status ACTIVE|INACTIVE|REVOKED  
metadata jsonb  
created\_at

Constraints:  
unique identifier\_hash

61\) trigger\_tokens  
id  
token\_hash  
game\_session\_id  
team\_id  
player\_id  
node\_instance\_id nullable  
trigger\_type  
expires\_at  
consumed\_at nullable  
created\_at

Constraints:  
unique token\_hash

Indexes:  
expires\_at  
game\_session\_id,team\_id

62\) qr\_scan\_events  
id  
game\_session\_id  
team\_id  
player\_id  
qr\_trigger\_id nullable  
node\_instance\_id nullable  
location\_event\_id nullable  
result  
reason\_code nullable  
occurred\_at

Index:  
game\_session\_id,occurred\_at  
player\_id,occurred\_at

12\. Domain J — AR

63\) ar\_challenges  
id  
public\_id  
scenario\_version\_id nullable  
node\_id nullable  
scene\_id  
asset\_bundle\_version  
supported\_platforms jsonb  
required\_capabilities jsonb  
entry\_contract jsonb  
completion\_contract jsonb  
fallback\_policy jsonb  
created\_at  
updated\_at

64\) ar\_asset\_manifests  
id  
scene\_id  
asset\_bundle\_version  
manifest\_json  
checksum  
status  
created\_at

Constraints:  
unique(scene\_id,asset\_bundle\_version)

65\) ar\_challenge\_runs  
id  
game\_session\_id  
team\_id  
player\_id  
ar\_challenge\_id  
node\_instance\_id  
challenge\_nonce  
asset\_version  
status STARTED|COMPLETED|FAILED|EXPIRED  
completion\_payload jsonb nullable  
evidence\_metadata jsonb nullable  
started\_at  
completed\_at nullable

Constraints:  
unique challenge\_nonce

13\. Domain K — Live Challenges

66\) challenge\_templates  
id  
public\_id  
organization\_id  
name  
type  
config jsonb  
status  
version\_no  
created\_at  
updated\_at

67\) live\_challenges  
id  
public\_id  
game\_session\_id  
template\_id nullable  
type  
priority  
status DRAFT|SCHEDULED|ACTIVE|COMPLETED|EXPIRED|CANCELLED  
starts\_at nullable  
expires\_at nullable  
duration\_seconds nullable  
reward\_config jsonb  
penalty\_config jsonb  
interrupt\_policy  
completion\_rule jsonb  
fairness\_class  
created\_by  
created\_at  
updated\_at

68\) live\_challenge\_targets  
id  
live\_challenge\_id  
target\_type PLAYER|TEAM|SESSION  
target\_id  
status SENT|SEEN|ACKED|STARTED|COMPLETED|FAILED|EXPIRED|CANCELLED  
seen\_at nullable  
acked\_at nullable  
started\_at nullable  
completed\_at nullable

Constraints:  
unique(live\_challenge\_id,target\_type,target\_id)

69\) live\_challenge\_submissions  
id  
live\_challenge\_target\_id  
player\_id nullable  
payload jsonb  
status  
submitted\_at  
reviewed\_at nullable

14\. Domain L — Scoring, Hints & Leaderboard

70\) score\_events  
append-only.  
id  
game\_session\_id  
team\_id  
player\_id nullable  
node\_instance\_id nullable  
source\_type  
source\_id nullable  
delta  
reason\_code  
metadata jsonb  
occurred\_at

Indexes:  
game\_session\_id,team\_id,occurred\_at

71\) team\_scores  
read model.  
game\_session\_id  
team\_id  
score  
effective\_elapsed\_seconds  
hints\_used  
finished\_at nullable  
updated\_at

Constraints:  
unique(game\_session\_id,team\_id)

72\) hint\_definitions  
id  
scenario\_node\_id  
hint\_no  
content  
penalty\_score nullable  
penalty\_seconds nullable  
created\_at

Constraints:  
unique(scenario\_node\_id,hint\_no)  
CHECK hint\_no between 1 and 3 برای Hintهای پایه.

73\) hint\_consumptions  
id  
game\_session\_id  
team\_id  
player\_id  
node\_instance\_id  
hint\_definition\_id nullable  
entitlement\_consumption\_id nullable  
source DEFAULT|PURCHASED|GAMEMASTER  
penalty\_score  
penalty\_seconds  
idempotency\_key  
consumed\_at

Constraints:  
unique idempotency\_key

15\. Domain M — Anti-Cheat & Trust

74\) anti\_cheat\_flags  
id  
public\_id  
game\_session\_id  
team\_id nullable  
player\_id nullable  
device\_id nullable  
category DEVICE|LOCATION|QR|RUNTIME|COMMERCE  
rule\_code  
severity LOW|MEDIUM|HIGH|CRITICAL  
status OPEN|UNDER\_REVIEW|RESOLVED|DISMISSED|ENFORCED  
risk\_score nullable  
evidence jsonb  
created\_at  
updated\_at  
resolved\_at nullable

Indexes:  
game\_session\_id,status  
player\_id,status  
severity

75\) anti\_cheat\_actions  
id  
anti\_cheat\_flag\_id  
action\_type ALLOW\_FLAG|SOFT\_BLOCK|HARD\_BLOCK|DISQUALIFY|MANUAL\_REVIEW  
actor\_type  
actor\_id nullable  
reason  
occurred\_at

76\) security\_events  
id  
user\_id nullable  
device\_id nullable  
organization\_id nullable  
event\_type  
ip nullable  
metadata jsonb  
occurred\_at

16\. Domain N — Audit, Outbox, Media & Platform

77\) audit\_events  
append-only.  
id  
event\_id  
organization\_id nullable  
actor\_type  
actor\_id nullable  
action  
resource\_type  
resource\_id nullable  
before\_json nullable  
after\_json nullable  
reason nullable  
request\_id nullable  
occurred\_at

Constraints:  
unique event\_id

Indexes:  
organization\_id,occurred\_at  
resource\_type,resource\_id  
actor\_id,occurred\_at

78\) outbox\_events  
id  
event\_id  
topic  
aggregate\_type  
aggregate\_id  
payload\_version  
payload jsonb  
status PENDING|PROCESSING|PUBLISHED|FAILED  
attempts  
available\_at  
published\_at nullable  
last\_error nullable  
created\_at

Constraints:  
unique event\_id

Indexes:  
status,available\_at

79\) media\_assets  
id  
public\_id  
organization\_id nullable  
owner\_user\_id nullable  
storage\_disk  
storage\_key  
mime\_type  
size\_bytes  
checksum  
status UPLOADING|READY|QUARANTINED|ARCHIVED|DELETED  
metadata jsonb  
created\_at  
updated\_at

Indexes:  
organization\_id,status  
checksum

80\) media\_references  
id  
media\_asset\_id  
resource\_type  
resource\_id  
purpose  
created\_at

Constraints:  
unique(media\_asset\_id,resource\_type,resource\_id,purpose)

81\) feature\_flags  
id  
organization\_id nullable  
key  
enabled  
rules jsonb  
created\_at  
updated\_at

Constraints:  
unique(organization\_id,key)

82\) system\_settings  
id  
scope\_type GLOBAL|ORGANIZATION  
scope\_id nullable  
key  
value jsonb  
updated\_at

Unique:  
(scope\_type,scope\_id,key)

17\. جدول‌های Framework / Infrastructure  
جداول زیر ممکن است توسط Laravel یا ابزارهای زیرساخت ایجاد شوند و در شمارش Domain جدا هستند:  
migrations  
jobs  
job\_batches  
failed\_jobs  
cache  
cache\_locks  
sessions در صورت نیاز  
notifications در صورت استفاده

18\. تعداد نهایی  
Core/Operational domain tables: حدود 82 جدول.  
با جداول Framework/Infrastructure مجموع واقعی Schema احتمالاً حدود 88 تا 92 جدول خواهد شد.

این افزایش نسبت به تخمین اولیه 60–80 طبیعی است، چون اکنون Commerce، Tenant، Runtime Eventing، AR assets، Anti-Cheat، Media و Audit به‌صورت Production-grade جدا شده‌اند.

19\. Constraints حیاتی که باید حتماً در DB enforce شوند  
unique(game\_session\_id,user\_id) در game\_session\_players  
unique(game\_session\_id,user\_id) در billable\_participations  
unique(game\_session\_team\_id) در team\_scenario\_instances  
unique(game\_session\_team\_id) در scenario\_assignments  
unique(scenario\_id,version\_no)  
unique(scenario\_version\_id,node\_key)  
unique payment provider reference  
unique idempotency keys برای عملیات حساس  
remaining\_quantity \>= 0  
hint\_no بین 1 و 3 برای Hint پایه  
geometry SRID \= 4326  
published ScenarioVersion immutable از طریق Domain \+ policy \+ trigger/guard strategy در صورت نیاز

20\. Concurrency Hotspots  
Session Start  
Team capacity 8  
Session team capacity 5  
Ready transition  
Score mutation  
Hint consumption  
Entitlement consumption  
Payment verify  
Refund  
QR one-time token consume  
Node completion  
Device rebind

همه این عملیات باید Transaction \+ Lock/atomic update داشته باشند.

21\. Partitioning Strategy  
location\_events: partition by server\_received\_at  
runtime\_events: در scale بالا partition by occurred\_at یا game\_session range/hash در صورت نیاز  
audit\_events: partition by occurred\_at در حجم بالا  
score\_events: ابتدا عادی، بعداً partition در صورت نیاز

Partitioning از ابتدا برای location\_events الزامی است؛ برای بقیه migration-ready طراحی می‌شود.

22\. Index Strategy  
هر FK پرتکرار index می‌شود.  
GiST برای geometry.  
Partial indexes برای active/open states در صورت نیاز.  
Composite indexes بر اساس query pattern، نه صرفاً هر field.  
Indexهای بدون مصرف با pg\_stat\_user\_indexes بررسی و حذف می‌شوند.

23\. Delete / Retention Rules  
Financial records Hard Delete نمی‌شوند.  
Audit و Runtime Eventهای حقوقی/عملیاتی طبق Retention Policy نگه‌داری می‌شوند.  
Raw GPS محدود و قابل purge/archive است.  
User deletion ممکن است PII را anonymize کند ولی financial/audit integrity را حفظ کند.  
Scenario Published Version و Session snapshots نباید با حذف Source entity خراب شوند.

24\. Foreign Key Policy  
FKهای Core فعال هستند.  
ON DELETE CASCADE فقط برای childهای واقعاً وابسته و بدون ارزش تاریخی.  
برای History/Financial/Audit بیشتر RESTRICT یا SET NULL.  
حذف Organization/Game/Scenario عمدتاً Archive است نه physical delete.

25\. Migration Rules for Codex  
هر migration کوچک، reversible تا حد ممکن، و با index/constraint explicit.  
تغییر column پرحجم در Production باید zero/low-downtime strategy داشته باشد.  
هیچ migration نباید silently data loss ایجاد کند.  
Seed data فقط برای catalog/default/config، نه business demo data در Production.

26\. Definition of Done Database v1  
Table catalog کامل.  
FK/Unique/Checkهای حیاتی مشخص.  
PostGIS strategy مشخص.  
Partitioning برای GPS مشخص.  
Billing/Commerce consistency مشخص.  
Runtime append-only/event model مشخص.  
Anti-cheat/audit separation مشخص.  
Codex می‌تواند Phase-by-Phase migrationها را از این سند استخراج کند بدون اینکه schema core را حدس بزند.  
