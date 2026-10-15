# NEXO Management API & Authorization Addendum v1.1

Status: APPROVED IMPLEMENTATION ADDENDUM  
Applies to: NEXO Control Center and Laravel API  
Base contracts: RTK / API Contract v1 / OpenAPI v1 / Permission Matrix v1

This addendum authorizes the management capabilities below without modifying the frozen source specifications. Existing authentication, user identity, global role assignments, organization memberships, policies, audit storage, and team/game relationships remain authoritative.

## 1. Compatibility and scope

- This addendum extends the frozen RTK Phase 0 scope for management APIs and Control Center capabilities. It does not revise unrelated game, identity, or runtime behavior.
- Existing `/api/v1/me` and `IdentityUser` responses remain unchanged. OpenAPI v1 defines `IdentityUser.additionalProperties: false` and `roles: [PLAYER]`; management role context is returned separately by `GET /api/v1/me/capabilities`.
- New management endpoints use the existing `/api/v1` envelope and cursor pagination conventions. Additive endpoints do not change existing mobile or Control Center response contracts.
- All endpoints require the existing Sanctum device-bound authentication middleware and server-side Laravel authorization.

## 2. Roles and scope

- `SUPER_ADMIN` is a global role assignment. Only an active `SUPER_ADMIN` may read global administrative user records, administer account status, list global administrators, or grant/revoke global `SUPER_ADMIN` assignments.
- `ORGANIZATION_ADMIN` may administer memberships only in organizations where that actor has an active administrator membership. It receives no global-user or global-role authority.
- `GAMEMASTER` access is limited to operational participant fields in assigned sessions. It receives no account-status or global-user administration authority.
- `STAFF`, `PLAYER`, and derived `CAPTAIN` access remain as defined by the frozen permission baseline.
- Global user/player detail and audit queries must scope-check before lookup and return not-found outside the caller's allowed scope.
- Organization membership operations never change global account status or global role assignment.
- The management UI may use server-derived capability context for navigation only; Laravel remains authoritative on every request.

## 3. Endpoints

All collection endpoints accept `limit` (1–100) and opaque `cursor` and return `data` plus `meta.pagination`.

### Global user administration — SUPER_ADMIN only

- `GET /api/v1/admin/users`: cursor-paginated global users; supports `q` over name, email, and mobile and exact `status` filtering. Returns public ID, name, email, mobile, status, global roles, organization membership summaries, player/team association summaries, `last_login_at`, and creation/update timestamps. No credentials, token data, device secrets, or hashes.
- `GET /api/v1/admin/users/{user_id}`: the same safe account fields plus permitted memberships, team memberships, session participation summaries, and relevant audit records.
- `PATCH /api/v1/admin/users/{user_id}/status`: accepts `status` in `ACTIVE|SUSPENDED|BLOCKED` and a required non-empty `reason` (max 1000 characters). All transitions are audited. `BLOCKED` is a security/serious-policy restriction and is not equivalent to temporary `SUSPENDED`; only an authorized `SUPER_ADMIN` may unblock it.
- A `BLOCKED` account must be explicitly reactivated to `ACTIVE` through the separately audited unblock transition; it cannot be silently downgraded to `SUSPENDED`.
- The authenticated actor cannot alter their own status through this API. The last active `SUPER_ADMIN` cannot be suspended, blocked, or demoted/revoked.
- Status mutation locks the target and relevant global-role rows, revokes existing Sanctum tokens, and ensures subsequent authenticated requests are rejected while status is not `ACTIVE`.

### Global administrator management — SUPER_ADMIN only

- `GET /api/v1/admin/admins`: cursor-paginated users with an active global administrator assignment, including safe account/status fields and assignment timestamps.
- `POST /api/v1/admin/admins/{user_id}`: grants the existing `SUPER_ADMIN` assignment; requires `reason`.
- `DELETE /api/v1/admin/admins/{user_id}`: revokes the existing assignment; requires `reason` and protects the last active `SUPER_ADMIN`.
- Role history is exposed through the relevant user audit view. No dynamic role or permission editor is introduced.

### Organization membership management

- Existing `/api/v1/organizations/{organization_id}/members` GET/POST/DELETE endpoints remain the membership API and remain scoped by `OrganizationScope`.
- `POST /api/v1/admin/organizations/{organization_id}/members` and `DELETE /api/v1/admin/organizations/{organization_id}/members/{user_id}` provide the Control Center mutation contract with a required `reason`, preserving existing v1 consumers on the legacy paths.
- `PATCH /api/v1/admin/organizations/{organization_id}/members/{user_id}/role` changes the existing membership role among `ORGANIZATION_ADMIN|GAMEMASTER|STAFF`; requires `reason`. It is available to `SUPER_ADMIN` or an administrator of that organization only. It cannot assign a global role or affect another organization. This new path preserves the frozen OpenAPI v1 prohibition on adding PATCH to `/organizations/{organization_id}/members/{user_id}`.
- Membership list supports server-side `q` over name, email, and mobile, and role filtering.

### Player administration

- `GET /api/v1/admin/players` and `GET /api/v1/admin/players/{user_id}` return a read model derived from `users`, `team_members`, `game_session_players`, `game_session_teams`, sessions, and existing score/progress relations.
- `SUPER_ADMIN` may read platform-wide player data. `ORGANIZATION_ADMIN` may read only players associated with teams/sessions in that actor's authorized organizations. `GAMEMASTER` may read operational participant data only in assigned sessions. Other roles receive no administrative player access.
- Responses preserve historical participation and expose no device identifiers/secrets or authentication data. No player mutation or historical score change is authorized by this addendum.

### Capability context

- `GET /api/v1/me/capabilities` returns server-derived `global_roles`, organization role contexts, and UI capability flags. It is advisory for UI only and does not grant server authority.
- Clients must not submit roles/capabilities as authorization input.

## 4. Team archive safety

- Existing team archive permission remains governed by the frozen Team policy.
- Archive is rejected when any associated `game_session` has status `REGISTRATION`, `READY`, `RUNNING`, or `PAUSED`, regardless of registration row status.
- Archive and session registration/transition operations serialize using session-before-team lock order. Historical participation, scores, and audit history are retained.
- Existing team maximum size 8 and game eligibility 4–8 remain unchanged.

## 5. Audit and privacy

- Account status, global role, and organization membership mutations require a reason and append an audit event with actor, target, action, timestamp, and organization/session scope where applicable.
- Audit metadata may include safe before/after status or role values. It must never include passwords, password hashes, setup/reset tokens, bearer tokens, device secrets, or private keys.
- Audit reads are limited to global administrators for global account/role records and authorized organization administrators for events scoped to their organization.

## 6. Provisioning boundary

This addendum does not authorize administrator-created accounts or invitation/password-setup flows. Those remain unavailable until a separate approved provisioning contract defines expiring one-time token storage, ownership verification, rate limits, notification delivery, and audit behavior. No temporary plaintext password flow is permitted.
