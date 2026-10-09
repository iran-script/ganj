NEXO — Organization & Tenant Domain Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define tenant ownership, organization membership, organization-scoped roles, resource isolation and administrative boundaries before Phase 1.2 implementation.

1\. CORE PRINCIPLE

NEXO is multi-tenant from the start.

Organization is the tenant boundary for:  
\- Games  
\- Game Sessions  
\- Pricing  
\- Store Products  
\- GameMaster assignments  
\- Checkpoints/content owned by organization  
\- financial/operational reporting

2\. ORGANIZATION MODEL

Canonical fields:  
\- public\_id  
\- name  
\- slug  
\- status  
\- timezone  
\- locale nullable  
\- settings reference  
\- created\_at  
\- updated\_at  
\- archived\_at nullable

Status:  
\- ACTIVE  
\- SUSPENDED  
\- ARCHIVED

3\. ORGANIZATION MEMBERSHIP

organization\_members fields:  
\- organization\_id  
\- user\_id  
\- membership\_status  
\- joined\_at  
\- removed\_at nullable  
\- created\_at

Membership status:  
\- ACTIVE  
\- SUSPENDED  
\- REMOVED

Historical membership preserved.

4\. ORGANIZATION SETTINGS

organization\_settings:  
\- organization\_id  
\- key/value or typed structured settings  
\- settings must not become an unvalidated dump for critical policy

Critical rules belong in explicit domain columns/contracts where appropriate.

5\. SYSTEM ROLE VS TENANT ROLE

Global:  
\- SuperAdmin

Tenant-scoped:  
\- OrganizationAdmin  
\- GameMaster

Player remains normal user identity and may participate across organizations through Session participation.

Captain is not an Organization role.

6\. ROLE ASSIGNMENT

Canonical role assignment must support:  
\- user  
\- role code  
\- organization scope nullable for global role  
\- optional Game/GameSession scope for GameMaster  
\- active/revoked lifecycle

7\. SUPERADMIN

SuperAdmin:  
\- global exceptional authority  
\- can access all tenants only for legitimate administration  
\- sensitive actions audited

8\. ORGANIZATION ADMIN

OrganizationAdmin:  
\- own organization only  
\- manage organization resources  
\- manage GameMaster membership/assignment according to policy  
\- manage Games/Sessions/content/pricing/store within permission matrix

Cannot cross tenant.

9\. GAMEMASTER

GameMaster:  
\- must be active organization member  
\- operational access only to assigned Game/Session resources where assignment model requires  
\- no implicit finance authority  
\- no global admin authority

10\. TENANT OWNERSHIP

Every tenant-owned resource must derive organization scope server-side.

Client organization\_id is never trusted as authorization.

11\. CROSS-TENANT RULE

If resource belongs to another organization:  
\- deny  
\- preferably return 404 when resource existence is sensitive

No query should depend only on public\_id without tenant/authorization scope where resource is tenant-owned.

12\. ORGANIZATION CREATION

Default:  
\- SuperAdmin or explicitly authorized platform onboarding operation

Player cannot create arbitrary production tenant unless product policy later enables self-service organizations.

13\. ORGANIZATION UPDATE

OrganizationAdmin may update safe fields for own organization.

Restricted fields:  
\- status  
\- ownership/security-sensitive settings  
\- platform billing configuration  
may require SuperAdmin or narrower privileged capability.

14\. MEMBERSHIP MANAGEMENT

OrganizationAdmin may:  
\- list own members  
\- invite/add eligible staff  
\- remove/revoke staff roles

Cannot:  
\- alter users in other organizations  
\- grant SuperAdmin

15\. ROLE GRANT RULES

OrganizationAdmin:  
\- may grant/revoke OrganizationAdmin only if policy explicitly allows  
\- may grant/revoke GameMaster within organization

SuperAdmin:  
\- can recover organization administration

All privileged role grants audited.

16\. ORGANIZATION ARCHIVE

Archive:  
\- blocks new operational creation  
\- preserves historical Games/Sessions/financial records  
\- active Sessions must be resolved first

No destructive cascade delete.

17\. ORGANIZATION SUSPEND

Suspended organization:  
\- new Sessions/commerce blocked  
\- existing active Session behavior follows operational policy  
\- data remains accessible to platform admins

18\. OWNERSHIP CONSTRAINTS

Game.organization\_id required.  
GameSession.organization\_id required.  
Pricing/Store Product.organization\_id required.  
Checkpoint/QR/content ownership must be same tenant when referenced.  
Scenario may be organization-owned according to DB spec.

19\. REFERENCE VALIDATION

When linking resources:  
\- Game and Session same organization  
\- Scenario/checkpoint/trigger same organization where tenant-owned  
\- GameMaster assignment belongs to same organization

20\. API

Canonical existing:  
GET /api/v1/organizations  
POST /api/v1/organizations  
GET /api/v1/organizations/{organization\_id}  
PATCH /api/v1/organizations/{organization\_id}  
GET /api/v1/organizations/{organization\_id}/members  
POST /api/v1/organizations/{organization\_id}/members  
DELETE /api/v1/organizations/{organization\_id}/members/{user\_id}

21\. AUTHORIZATION

Default deny.

Organization list:  
\- SuperAdmin global  
\- user-scoped memberships where appropriate

Organization get:  
\- member/admin within scope

Organization create:  
\- SuperAdmin/platform onboarding

Organization update:  
\- OrganizationAdmin own tenant safe fields  
\- SuperAdmin

Membership mutate:  
\- OrganizationAdmin own tenant subject to role grant rules  
\- SuperAdmin

22\. EVENTS

Use Event Catalog canonical organization events.  
Any missing new business event must be added to Event Catalog before implementation.

23\. AUDIT

Mandatory:  
\- role grant/revoke  
\- OrganizationAdmin changes  
\- organization suspend/archive  
\- privileged setting change  
\- cross-tenant recovery action

24\. CONCURRENCY

Protect:  
\- duplicate membership  
\- simultaneous role grant/revoke  
\- last-admin recovery edge cases

25\. IDEMPOTENCY

Membership add/remove and privileged role mutations should be retry-safe.

26\. TESTS

\- SuperAdmin global scope  
\- OrganizationAdmin own tenant  
\- GameMaster own assigned resources  
\- cross-tenant 404/deny  
\- duplicate membership prevention  
\- role revocation effective immediately for new requests  
\- archived/suspended tenant guards  
\- organization ownership mismatch rejected  
\- no client organization\_id privilege escalation

27\. PHASE 1.2 DEFINITION OF DONE

\- organization tables/membership/settings implemented  
\- role assignment baseline implemented  
\- tenant query scoping centralized  
\- policies/gates deny cross-tenant  
\- organization CRUD/member management  
\- events/audit/outbox  
\- cross-tenant negative tests  
\- Identity/Phase 0 regression pass

28\. DEFINITION OF DONE — DESIGN

Organization/Tenant design is frozen when:  
\- tenant boundary explicit  
\- role scopes explicit  
\- ownership rules explicit  
\- cross-tenant behavior explicit  
\- archive/suspend behavior explicit  
\- API/permission/audit/test contracts explicit  
