# NEXO — Teams

Phase 1.3 implements persistent Teams independently of Game Sessions. The authoritative API remains [docs/specs/03_openapi.yaml](specs/03_openapi.yaml). No Game, Session, roster, Ready, billing, runtime, GPS, QR or score functionality is introduced.

## Persistence and lifecycle

The migration creates canonical `teams`, `team_members`, `team_invites` and append-only `team_member_events`. Teams begin ACTIVE with the creator as captain and first ACTIVE member. They may contain 1–8 active members; `eligible_for_game` is derived from ACTIVE status and a member count of 4–8. It is not stored.

Captaincy is a capability derived from `captain_user_id`, never a system role. Transfer requires an ACTIVE member of the same Team. PostgreSQL deferred constraint triggers require an ACTIVE Team's captain to have an ACTIVE membership at transaction commit. Creation inserts both records in one transaction.

Leave writes LEFT; removal writes REMOVED with `removed_by`. Both retain the original membership period. Rejoin inserts a new period. A partial unique index prevents duplicate ACTIVE Team/User memberships. Terminal history cannot be rewritten or deleted, and Team/member-event deletion is prohibited by database triggers. Team archive is terminal; no unarchive operation exists.

An archived Team retains its memberships and invites for history, but new joins/invites and profile/membership changes are rejected. Repeated archive/revocation is safe. Former members can read archived Team context; active Teams are restricted to current members and explicitly privileged support scope. No historical membership list is exposed publicly.

## API

All routes use authenticated ACTIVE identity and the existing device/token revocation checks. Resource IDs in requests and responses are UUID public IDs; internal keys never serve as API resource identifiers.

- `POST /api/v1/teams`: name, optional null avatar_media_id; 201 Team projection.
- `GET /api/v1/teams`: scoped cursor-paginated Team list.
- `GET /api/v1/teams/{team_id}`: safe Team projection.
- `PATCH /api/v1/teams/{team_id}`: name, optional reason; 200.
- `POST /api/v1/teams/{team_id}/invites`: optional expires_at, max_uses, reason; 201.
- `GET /api/v1/teams/{team_id}/invites`: authorized cursor-paginated invite list.
- `DELETE /api/v1/teams/{team_id}/invites/{invite_id}`: optional reason; 204.
- `POST /api/v1/teams/join`: invite_code; 200 Team projection.
- `POST /api/v1/teams/{team_id}/leave`: empty body; 204.
- `DELETE /api/v1/teams/{team_id}/members/{user_id}`: optional reason; 204.
- `POST /api/v1/teams/{team_id}/transfer-captaincy`: user_id, optional reason; 200.
- `POST /api/v1/teams/{team_id}/archive`: optional reason; 200.

Success responses use `data`; lists include `meta.pagination`. Lists default to 25 entries, maximum 100. Unknown fields and malformed cursors are rejected. No arbitrary sorting/filtering is accepted. Responses set Cache-Control: no-store.

Team projection includes id, name, status, public captain_user_id, safe captain and active-member projections, member_count, eligible_for_game, optional safe organization reference and timestamps. Member email/mobile, device/token data, numeric IDs and invite hashes are excluded.

Errors retain the existing envelope. Join uses TEAM_FULL, INVITE_EXPIRED, INVITE_REVOKED, ALREADY_TEAM_MEMBER, ACTION_NOT_ALLOWED for exhausted invites, STATE_TRANSITION_INVALID for archived resources, and RESOURCE_NOT_FOUND for unknown invites or inaccessible tenants. Forbidden captain operations return AUTHORIZATION_DENIED; inaccessible Team identifiers return RESOURCE_NOT_FOUND.

## Invites and idempotency

Invites use 32 cryptographically random bytes encoded as 64 hexadecimal characters. Only SHA-256 code_hash is stored. Raw invite_code is returned only at creation, never in lists, audit or outbox. Omitted expiry/max_uses mean no expiry/use limit; Team capacity still applies. Max-use bounds are checked in the locked transaction and in PostgreSQL.

Optional Idempotency-Key is supported for Team mutations except invite creation. Keys are actor-scoped with a 24-hour replay window; operation, Team, target and sorted validated input form the fingerprint. Same key with different input/operation returns IDEMPOTENCY_CONFLICT. Reservation, result, state changes, history, audit and outbox commit atomically using the existing idempotency foundation. Current identity/resource access is rechecked before replay; revoked access cannot replay private Team context.

Invite creation rejects Idempotency-Key because its one-time secret is not persisted for replay. Join retries without a key reject duplicate ACTIVE membership and never consume another invite use. Leave/remove retries may return a safe not-found/authorization error once membership/capability is gone. A successful keyed captain transfer can be replayed by the former captain while that actor retains Team read access.

## Authorization and tenant scope

TeamService and TeamPolicy enforce authorization on direct service calls as well as HTTP. TeamScope applies current identity, membership and tenant restrictions as query scopes.

Normal API creation always produces a personal Team with null organization_id. Client organization_id is rejected, including generic PATCH. An explicit service-only `createForOrganization` accepts an OrganizationContext, re-resolves it for the actual actor, requires the existing organization update permission, and locks the tenant. A caller cannot borrow another actor's context.

Organization-associated Teams reuse Phase 1.2 OrganizationScope/OrganizationContext. Current tenant access is required, including invite joins. Existing Phase 1.2 role restrictions remain intact. OrganizationAdmin sees/manages only authorized organization Teams; GameMaster gains no persistent membership management capability. A GameMaster who independently becomes captain has the same captain capability as any other member.

Captain may update, manage invites, remove non-captain members, transfer and archive. OrganizationAdmin can update own organization Teams and perform support invite/transfer/archive operations. SuperAdmin has exceptional invite/transfer/archive support authority, not generic personal-Team profile or member-removal authority. Every non-captain intervention requires a reason and audit. Member removal remains captain-only.

## Transactions, concurrency and delivery

Mutation lock order is actor, organization when present, Team, then invite/membership. Join discovers the hashed invitation, locks the Team, reloads/locks the invitation, and rechecks status, expiration, duplicates, remaining uses and capacity before inserting membership. All Team membership/captain/archive and invite-revocation operations use the same Team lock.

The two-connection PostgreSQL tests hold a winning operation uncommitted, verify the competing operation blocks (55P03 under a short test lock timeout), commit the winner, and retry the competing operation to prove it rechecks the committed state. Cases cover capacity, final invite use, duplicate join, transfer/leave, transfer/remove, transfer/transfer and revoke/join. No SQLite substitution or mock locking is used.

All nine canonical Team events are emitted through the transactional outbox and audited:
team.created.v1, team.updated.v1, team.archived.v1, team.member_joined.v1, team.member_left.v1, team.member_removed.v1, team.captain_changed.v1, team.invite_created.v1, team.invite_revoked.v1.

Events use the catalog envelope with public actor/Team/organization/target references, request_id and version 1. Membership and captain changes additionally append team_member_events. Transfer records old/new captains. A failed audit write rolls back Team state and outbox. External event publication remains the existing foundation's delivery responsibility; this phase adds no publisher or consumers.

## Media and future boundaries

The canonical nullable avatar_media_id column is present, constrained to null until the Media subsystem can validate references and supply its FK. Non-null API values are rejected. No temporary media table or unvalidated asset reference is introduced.

Future Session registration must create its own roster and freeze it at Start. Team services operate exclusively on persistent Team data, so Team changes cannot rewrite Session state. A future Session guard belongs at the application-service boundary; no Session stub or speculative runtime dependency was added.

## Verification and operations

See [PHASE_1_3.md](PHASE_1_3.md) for exact results. Run tests and destructive migration checks only against urban_ar_test, following the established Phase 1.2 container pattern. The application database receives only the forward Team migration.

TeamSeeder is an explicit local/testing demo seeder; it is not added to DatabaseSeeder. Factories create captain and initial membership atomically. Dependencies, upstream domain behavior and frozen business specifications remain unchanged.
