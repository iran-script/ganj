# Flutter Gameplay Integration

## Configuration

Set the backend with `--dart-define=API_BASE_URL=https://host.example/api/v1`. The default Android emulator URL is `http://10.0.2.2:8000/api/v1`. No credentials are embedded in the app.

## Architecture

- `core/api`: JSON envelope parsing, bearer/device/idempotency headers, canonical error codes.
- `core/auth`: secure token restore, login, logout, and expiry handling.
- `core/device`: one installation UUID stored in secure storage.
- `features/runtime`: authoritative projection refresh and safe Node renderer registry.
- `features/location`: foreground permission and location submission.
- `features/qr`: opaque QR capture and server scan/token handoff.
- `features/scoring`, `features/leaderboard`: read-only Team score, hints, and visibility-aware ranking views.

## Gameplay rules

Runtime projection drives the UI. Support, role, subgroup, Relay, barrier, and Player-specific content are displayed only when returned for the current Player. The client does not merge secret projections or infer future Nodes.

## Deferred scope

Durable offline mutation queue is deferred to Phase 2.6. AR remains fail-closed for the later AR phase. Billing, Store, purchase flow, anti-cheat UI, and GameMaster Control Room are not implemented.

## HOME VERIFICATION PENDING

Complete Phase 2.1, 2.2, and 2.3 backend verification first, then run Flutter dependency/analyzer/tests, real-backend login, device binding, Runtime, GPS, physical QR, role/co-op, hints, scoring, leaderboard, reconnect, and Android physical-device smoke checks.
