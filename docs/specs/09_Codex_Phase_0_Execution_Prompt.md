Urban AR Team Adventure Game — Codex Phase 0 Execution Prompt

ROLE  
You are implementing Phase 0 Foundation of a production-grade urban AR team adventure platform.

AUTHORITATIVE DOCUMENTS  
Use these as binding references:  
1\) Urban AR Team Adventure Game — Product Blueprint v1  
2\) Urban AR Team Adventure Game — API Contract v1  
3\) openapi.yaml (OpenAPI 3.1)  
4\) Urban AR Team Adventure Game — Database & Data Model Specification v1  
5\) Urban AR Team Adventure Game — State Machine Specification v1  
6\) Urban AR Team Adventure Game — Event Catalog Specification v1  
7\) Urban AR Team Adventure Game — Permission Matrix Specification v1  
8\) Urban AR Team Adventure Game — Codex Master Implementation Specification v1

SCOPE  
Implement Phase 0 Foundation only.

DO NOT implement:  
\- Teams business logic  
\- Game Session business logic  
\- Billing business logic  
\- Scenario gameplay  
\- GPS ingestion  
\- QR  
\- AR  
\- Live Challenges  
\- Scoring  
\- Anti-Cheat  
\- Store/Hint Packs  
unless a minimal interface/contract is explicitly needed for Phase 0 infrastructure.

STACK  
\- Laravel  
\- PostgreSQL  
\- PostGIS  
\- Redis  
\- Laravel Queue  
\- Scheduler  
\- WebSocket/Broadcasting foundation  
\- REST API under /api/v1  
\- Docker for local/dev and production-compatible topology  
\- Automated tests

ARCHITECTURE  
Use Modular Monolith.

Create module boundaries:  
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
Platform

For Phase 0 these may contain only foundation contracts/placeholders where necessary.  
Do not create speculative business logic.

REQUIRED DELIVERABLES

1\. Laravel Bootstrap  
\- Production-ready Laravel project  
\- Correct PHP version constraints  
\- Composer configuration  
\- Environment examples  
\- App key handling  
\- UTC default timezone  
\- API versioning baseline

2\. Database Foundation  
\- PostgreSQL connection  
\- PostGIS enabled through migration or controlled bootstrap  
\- Verify SELECT PostGIS\_Version() in integration test  
\- No SQLite substitution for integration behavior  
\- Migrations must be the only schema mutation path

3\. Redis Foundation  
\- Redis cache connection  
\- Redis queue connection  
\- Redis health verification  
\- Do not store critical durable business state only in Redis

4\. Queue Foundation  
\- Laravel Queue with Redis  
\- Worker service in Docker  
\- Retry/backoff baseline  
\- failed\_jobs support  
\- queue smoke test

5\. Scheduler Foundation  
\- Scheduler service/container  
\- Health/smoke setup  
\- No business schedules yet

6\. Realtime Foundation  
\- Broadcasting/WebSocket foundation only  
\- Authenticated/private channel infrastructure  
\- Do not implement gameplay events yet  
\- Keep realtime replaceable/configurable

7\. API Foundation  
Base prefix:  
/api/v1

Implement:  
GET /api/v1/health

Recommended health response:  
{  
  "data": {  
    "status": "ok",  
    "checks": {  
      "database": "ok",  
      "postgis": "ok",  
      "redis": "ok",  
      "queue": "ok"  
    }  
  }  
}

Health endpoint must not leak credentials, host secrets, connection strings or stack traces.

8\. Standard API Response Contract  
Success object:  
{  
  "data": {},  
  "meta": {}  
}

Standard error:  
{  
  "error": {  
    "code": "ERROR\_CODE",  
    "message": "Human-readable message",  
    "details": {}  
  }  
}

Centralize exception-to-response mapping.  
Do not handcraft inconsistent error shapes in controllers.

9\. Request ID  
Generate/accept a request correlation ID safely.  
Recommended response header:  
X-Request-Id

Structured logs must include request\_id.

10\. Structured Logging  
JSON/structured logging suitable for production.  
Include when available:  
request\_id  
user\_id  
organization\_id  
session\_id  
team\_id  
device\_id  
route  
status\_code  
duration\_ms

Do not log:  
passwords  
tokens  
raw secrets  
payment card data

11\. Idempotency Foundation  
Create reusable infrastructure for future sensitive mutations.

Requirements:  
\- Idempotency-Key support contract  
\- storage interface  
\- request fingerprinting strategy  
\- same key \+ same request \=\> same logical result  
\- same key \+ different request \=\> conflict  
\- expiry/retention strategy  
\- concurrency-safe reservation/lock behavior

Do not wire every route yet.  
Provide tests for the primitive.

12\. Audit Foundation  
Create reusable Audit contract/service and persistence foundation only if consistent with Database Spec Phase 0 boundary.

Requirements:  
\- append-only concept  
\- actor/context model  
\- resource/action/reason support  
\- request\_id correlation

Do not add gameplay audit records yet.

13\. Outbox Foundation  
Implement reusable transactional Outbox infrastructure.

Requirements:  
\- outbox\_events schema if appropriate for Phase 0  
\- unique event\_id  
\- status lifecycle  
\- retries  
\- worker publishing abstraction  
\- payload\_version  
\- producer/topic/aggregate metadata  
\- no business-specific event catalog implementation yet

Test:  
database transaction \+ outbox record persistence  
idempotent publish behavior

14\. Health / Readiness  
Create:  
\- simple liveness  
\- readiness checks  
\- DB  
\- PostGIS  
\- Redis

Queue readiness should be designed carefully; do not falsely mark healthy merely because Redis responds if worker availability cannot be reliably proven.

Document exact semantics.

15\. Docker  
Provide production-compatible local topology:  
\- app  
\- nginx/web server if architecture uses it  
\- postgres  
\- redis  
\- queue worker  
\- scheduler

Optional:  
\- websocket service if chosen solution needs separate process

Requirements:  
\- persistent DB/Redis volumes where appropriate  
\- no DB/Redis public exposure by default in production topology  
\- healthchecks  
\- env-driven configuration  
\- no secrets committed

16\. Security Baseline  
\- password hashing config  
\- restrictive CORS baseline  
\- rate-limit infrastructure  
\- trusted proxies strategy if needed  
\- secure headers where appropriate  
\- environment secret handling  
\- debug off in production  
\- do not expose stack traces in API production mode

17\. Testing Foundation  
Set up:  
Unit tests  
Feature tests  
Integration tests

Mandatory Phase 0 tests:  
\- /api/v1/health returns expected shape  
\- standard error envelope  
\- request ID header exists  
\- DB connection works  
\- PostGIS enabled  
\- Redis connection works  
\- Queue job can process  
\- Outbox persistence works  
\- Idempotency primitive works  
\- migrations fresh successfully  
\- authorization foundation defaults safely where relevant

18\. Code Quality  
Run and pass:  
composer validate  
php artisan test  
Laravel Pint check/fix  
static analysis if configured  
migration fresh test  
Docker build

Do not claim a check passed unless it was actually run.

19\. Documentation  
Create/update:  
README.md  
docs/ARCHITECTURE.md  
docs/DEVELOPMENT.md  
docs/PHASE\_0.md  
docs/DEPLOYMENT.md or equivalent  
docs/HEALTHCHECKS.md

Document:  
startup  
environment variables  
Docker commands  
test commands  
queue/scheduler commands  
health semantics  
known limitations

20\. No-Go Rules  
DO NOT:  
\- hard-code scenario logic  
\- build business tables beyond Phase 0 without a dependency reason  
\- trust client payment state  
\- trust client score  
\- trust client runtime state  
\- use Redis as only durable source  
\- add generic admin bypass  
\- use unscoped tenant queries  
\- mutate schema manually  
\- hide failing tests  
\- mark TODO implementation as completed  
\- silently change frozen contracts

21\. Conflict Handling  
If authoritative documents conflict:  
Do not invent a new rule.  
Report:  
\- exact documents  
\- exact conflicting requirements  
\- safest implementation-neutral interpretation

Continue only where conflict does not block Phase 0\.

22\. Definition of Done  
Phase 0 is complete only when:  
\- Laravel app boots cleanly  
\- Docker topology boots  
\- PostgreSQL healthy  
\- PostGIS verified  
\- Redis healthy  
\- Queue processing verified  
\- Scheduler process configured  
\- /api/v1/health works  
\- standard errors work  
\- request IDs work  
\- structured logging exists  
\- idempotency foundation exists  
\- audit foundation exists  
\- transactional outbox foundation exists  
\- tests pass  
\- lint/format pass  
\- Composer validation passes  
\- Docker build passes  
\- documentation updated

23\. Final Report Format

Return exactly these sections:

1\) Phase  
Phase 0 — Foundation

2\) Status  
COMPLETE / PARTIAL / BLOCKED

3\) Files Added  
List

4\) Files Changed  
List

5\) Migrations Added  
List each migration \+ purpose

6\) Infrastructure  
PostgreSQL  
PostGIS  
Redis  
Queue  
Scheduler  
Realtime/Broadcasting  
Docker

7\) API Added  
List routes

8\) Tests  
Commands run  
Exact pass/fail counts  
Assertions if available

9\) Verification  
composer validate  
pint  
static analysis  
migration fresh  
docker build  
health checks

10\) Security Notes

11\) Known Limitations  
Only real limitations, no invented future work.

12\) Contract Deviations  
Must be empty unless a deviation was unavoidable.  
If not empty, explain why and do not silently continue.

13\) Next Recommended Phase  
Phase 1.1 — Identity & Player Device

IMPORTANT  
Do not implement Phase 1.1 in this task.  
Stop after Phase 0 is fully verified.  
