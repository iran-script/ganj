# Authentication

The mobile API accepts only `Authorization: Bearer <token>`. Sanctum's web/session guard fallback and stateful domains are disabled. Tokens are cryptographically random opaque secrets stored as SHA-256 hashes in Sanctum's personal_access_tokens table, with an internal user_device_id FK. Token scopes do not grant domain permissions. Tokens expire after 30 days and are returned exactly once at creation. Reconnect reuses an unexpired active token; there is no token-refresh endpoint in this phase.

POST `/api/v1/auth/register` requires name, password, device_uuid (UUID), device_name, platform, app_version, and at least one of email/mobile. Passwords require 12 characters and at most 72 UTF-8 bytes; Laravel's default hashing applies. Email is trimmed and lowercased; PostgreSQL independently enforces case-insensitive uniqueness. Mobile accepts an optional leading plus followed by 7–15 digits and is unique as stored; no country-code inference is performed. Unknown fields, including user_id, roles and status, are rejected.

The HTTP 201 response is `{ "data": { "user": {...}, "token": "...", "device": {...} } }`. All three records and their audit/outbox records are committed together. Do not log or persist the token response in telemetry. Authentication/profile responses specify Cache-Control: no-store.

POST `/api/v1/auth/login` takes the same fields except name. Both identifiers may be supplied but must identify the same account. Wrong credentials return HTTP 401 AUTHENTICATION_REQUIRED without account existence details. SUSPENDED/BLOCKED users return HTTP 403 AUTHORIZATION_DENIED with details.status. A revoked device cannot be silently reactivated. Existing active device metadata and last_login_at are refreshed server-side; every successful login issues a new token.

POST `/api/v1/auth/logout` deletes only the current token and returns 204. A repeated request with that token returns 401. Other active device tokens remain valid. Suspended/blocked users cannot use stale tokens; status is checked on every protected request.

GET `/api/v1/me` returns `{ "data": <public profile> }`, including UUID id, name, email, mobile, status, baseline roles and UTC timestamps. PATCH accepts only name/email/mobile. Identifier removal must preserve at least one identifier; updates are serialized under a user row lock and uniqueness is rechecked. Unknown fields are rejected. Passwords, token hashes and internal IDs are absent from public profiles.

Registration allows five requests per minute per IP; login allows ten independently. HTTP 429 uses RATE_LIMITED, Retry-After, and details.retry_after_seconds. Other errors use the existing central mapper: AUTHENTICATION_REQUIRED, AUTHORIZATION_DENIED, VALIDATION_FAILED and RESOURCE_NOT_FOUND. Legacy Phase 0 unknown-route NOT_FOUND behavior is preserved.

Audit and frozen outbox events record server-authoritative identity and request_id without passwords or token material. Identifier-change audits contain changed field names rather than copying personal identifiers. There is no self-service password, status or role mutation in this phase.
