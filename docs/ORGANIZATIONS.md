# Organizations and tenant authorization

NEXO is the product name. Existing Urban AR internal names are retained. This module implements the master specification's Phase 1.2 only.

## HTTP contract

All operations use Bearer authentication, public UUIDs, the existing error envelope, UTC timestamps, X-Request-Id and Cache-Control: no-store.

- GET /api/v1/organizations: SuperAdmin lists all organizations; OrganizationAdmin lists only organizations with its active administrator membership. GameMaster, Staff and Player cannot use the list.
- POST /api/v1/organizations: SuperAdmin only. Required name and unique lowercase slug; optional timezone_default (IANA timezone, default UTC) and reason. Returns 201 with data containing organization metadata. Creates the unique empty settings row in the same transaction.
- GET /api/v1/organizations/{organization_id}: SuperAdmin and own OrganizationAdmin receive organization metadata. An active own GAMEMASTER membership permits only id, name, slug, status and timezone_default. Settings, contacts and membership details are excluded.
- PATCH /api/v1/organizations/{organization_id}: SuperAdmin or own OrganizationAdmin; name, slug and timezone_default only, plus optional audit reason. At least one editable field is required. Unknown fields, including status, organization_id and role_code, are rejected.
- GET /api/v1/organizations/{organization_id}/members: SuperAdmin or own OrganizationAdmin; active members only. Response fields are public user_id, name, role, status, joined_at and left_at. No email, mobile or internal membership IDs.
- POST /api/v1/organizations/{organization_id}/members: same management permission; existing user public UUID and role required. Role values are exactly ORGANIZATION_ADMIN, GAMEMASTER and STAFF. Optional reason. Returns 201. Duplicate active membership returns 409 CONFLICT, including retries; it does not change the existing role.
- DELETE /api/v1/organizations/{organization_id}/members/{user_id}: same management permission. Optional JSON reason. Returns 204 after marking the current membership REMOVED and recording left_at. No active membership returns 404; repeated removal has no new side effects.

Collections accept cursor and limit (default 25, maximum 100), with meta.pagination.cursor, next_cursor and has_more. Cursors contain public identifiers only and never widen server scope. Unknown filters/sorts and malformed cursors return 422.

Wrong-tenant, missing and malformed resource identifiers produce the same 404 RESOURCE_NOT_FOUND. Known own-organization actions without sufficient capability return 403 AUTHORIZATION_DENIED. Validation uses 422 VALIDATION_FAILED. Archived organization mutations use 409 STATE_TRANSITION_INVALID. Internal numeric IDs are never accepted as public resource IDs.

The OpenAPI source is docs/specs/03_openapi.yaml. Creation responses use the API Contract section 5's 201 and removal uses its permitted 204; generic baseline stubs are clarified accordingly. No paths have been added for archive, settings management, role changes or global-role administration.

## Services and tenant boundaries

OrganizationPolicy explicitly authorizes each capability; there is no global Gate::before bypass. OrganizationScope resolves visible organizations using current database grants/memberships. OrganizationContext exposes the actor, organization and membership snapshot; its scope method re-authorizes when a tenant-owned query executes and applies organization_id as a grouped Eloquent scope. This also protects queries containing OR conditions. A cached context or query does not preserve permission after revocation. The membership snapshot is informational; it must never be treated as authorization. Tenant filtering is not a grant of resource-specific permissions: future modules still require their own policies and data projections.

Controllers delegate to OrganizationService. Every write re-resolves the actor from the database, locks the actor and organization rows, then rechecks policy and lifecycle. The organization lock serializes member add/remove/role changes with organization writes. PostgreSQL enforces unique active organization/user membership and unique slugs as the final concurrency boundary. A user can hold memberships in several organizations; each membership authorizes only its own organization.

OrganizationService::changeRole is a service-only operation with an allow-listed role and required reason. It writes old_role/new_role to the canonical event and audit. OrganizationService::archive is service-only, SuperAdmin-only, and requires a reason. No commercial policy authorizes OrganizationAdmin archive in this phase, so it is denied. Archived organizations retain history and remain readable within authorized scope; they cannot be mutated or publicly reopened.

Membership states are ACTIVE, SUSPENDED, LEFT and REMOVED. Only ACTIVE contributes permissions. Removal is implemented; no new suspend/leave endpoints are introduced. Rejoining creates a new membership period without overwriting the removed period. Role changes preserve their old/new values in append-only audit and outbox records. Models reject destructive organization, membership and role-assignment deletion; history-bearing foreign keys use RESTRICT.

## Global role baseline

organization_members is the sole tenant role store. role_assignments currently supports only global SUPER_ADMIN with all three scope fields null. Database checks reject tenant/resource-scoped SuperAdmin grants and duplicate active global grants. Canonical nullable scope columns are present for future phases, but enabling additional role codes/scopes requires a migration and explicit policy; they are not a second active RBAC system.

The first global operator is initialized once with an existing active user's public UUID:

~~~powershell
php artisan organizations:bootstrap-super-admin USER_PUBLIC_UUID --reason="Initial platform operator" --no-interaction
~~~

Run in the configured app environment (or through docker compose exec -T app). This command requires trusted local CLI access, creates no user or password, and records a system audit. It refuses once any role-assignment history exists, including revoked history. It is not a public API and was not run against real user data during implementation.

Subsequent grants/revocations use GlobalRoleService with an authorized active SuperAdmin actor and a required reason. The dedicated roles.manageGlobal gate does not confer unrelated permissions. Revocation preserves the row and immediately removes effective global privilege. There is no last-admin retention rule in the frozen contract; revoking the final grant requires a controlled operator recovery process, not rerunning bootstrap.

The existing /me PLAYER role summary is unchanged and is not used for tenant authorization. Clients should rely on authorized organization endpoints, not infer global privilege from that legacy summary.

## Settings, audit and delivery

organization_settings retains canonical branding_json, commercial_settings_json, invoice_profile_json and payment_config_ref columns. Defaults are empty objects and a null provider reference. Settings have no public write API in this phase and never grant permissions. OrganizationSettingsSeeder can idempotently fill missing default rows for existing organizations without creating demo tenants or granting roles; it is not wired into the existing demo DatabaseSeeder.

All six registered organization events use the Event Catalog names: organization.created.v1, organization.updated.v1, organization.archived.v1, organization.member_added.v1, organization.member_role_changed.v1 and organization.member_removed.v1. Their envelopes carry event_id, payload_version, server occurred_at, producer, public organization and actor references, null session/team/player context, request_id and safe data. Member events use public user UUIDs; no token hashes, contact details or settings secrets are emitted.

Each mutation records its event in the existing transactional OutboxService and records audit in the same database transaction. Either persistence failure rolls back the domain write. Audit adds an indexed nullable organization FK while retaining Phase 0's context layout and internal actor reference convention. Before/after values and role changes are stored in context. PostgreSQL rejects UPDATE/DELETE on audit_events. Privileged global grants/revocations are internal audit actions (role_assignment.granted/revoked), not invented Event Catalog aliases.

Outbox records retain the existing lowercase pending state, stable event_id and at-least-once/deduplication delivery contract. This phase adds organization producers; it does not introduce a broker, analytics/permission consumers or a new outbox publisher. The existing foundation currently persists pending outbox records; external publication is not demonstrated by the queue smoke test.

## Migration and recovery

The additive migration creates four domain tables, their explicit indexes/FKs/checks, the audit organization column, and the append-only audit trigger. No Phase 0/1.1 table is recreated. CREATE OR REPLACE handles PostgreSQL functions surviving migrate:fresh. Down removes only the Phase 1.2 schema/trigger additions; it is destructive to Phase 1.2 data and must not be used on a populated environment without backup/export.

Use migrate:fresh only with the isolated urban_ar_test database. Apply normal migrate --force to an existing backend. No billing, games, teams, gameplay roles or Phase 1.3 logic is included.
