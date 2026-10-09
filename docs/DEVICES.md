# Devices

A device is registered atomically during registration or login. A user can have multiple devices. PostgreSQL enforces unique(user_id, device_uuid), while a server-generated public UUID identifies the device resource. device_uuid is client installation identity and is distinct from the resource UUID returned as device.id.

GET `/api/v1/devices` returns only the authenticated user's devices, including revoked records, with cursor pagination (`limit` defaults to 25, maximum 100). Responses expose device metadata, UUIDs, last_seen_at and revoked_at, but not last_ip, internal IDs or token material. last_seen_at and last_ip are refreshed on successful registration/login.

DELETE `/api/v1/devices/{device_id}` uses the public resource UUID. Missing devices, sequential IDs and other users' devices return 404 RESOURCE_NOT_FOUND. Revocation records revoked_at, deletes all tokens issued for that device, and atomically writes device.revoked.v1 plus audit. Repeated revocation from another active device returns 204 without another event. A token deleted by revocation returns 401, including when it belonged to the current caller. Revocation history remains stored. Login never clears revoked_at.

`X-Device-Id: <device_uuid>` identifies the requesting installation. On protected routes, a supplied header must resolve to a registered, owned, non-revoked device; invalid/unowned/revoked values return 403 AUTHORIZATION_DENIED. Identity routes do not require the header when omitted. Token authentication still requires an active issuing device.

Future sensitive routes compose AuthenticateIdentity followed by RequireDevice. DeviceResolver::fromRequest() stores the resolved UserDevice under request attribute `identity_device`. DeviceResolver::resolve(User, uuid) is reusable outside HTTP. Token identity and header device identity are checked separately; a token does not establish a session's active_device binding. Future session services must add their own session participation/binding guards. This phase contains no session tables, active-session revocation restrictions, rebind flow or gameplay business logic.

Device UUIDs are identifiers, not hardware attestation secrets. A revoked installation requires an authorized future recovery flow or a different registered installation; this API does not resurrect a revoked record.
