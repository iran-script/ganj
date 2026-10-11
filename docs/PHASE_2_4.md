# Phase 2.4 — Flutter Gameplay Integration

Status: **PARTIAL — OFFICE DEVELOPMENT COMPLETE / HOME VERIFICATION PENDING**

## Mobile application

The single Flutter application is under `mobile/`. It provides a Persian/RTL-ready Player shell with secure authentication, installation Device identity, Runtime projection refresh, Node rendering, foreground GPS, QR scan handoff, score, hints, and leaderboard views.

## Boundaries

The backend remains authoritative. The client never decides Runtime progression, QR meaning, GPS verdict, score, hidden graph state, future hint content, role eligibility, or leaderboard ordering. Mutations are online-only and use idempotency keys. Durable offline mutation queues, AR, Billing, Store, and GameMaster UI are deferred.

## HOME VERIFICATION PENDING

1. Phase 2.1–2.3 backend verification.
2. Flutter real-backend login and session restore.
3. Device binding and Runtime projection.
4. Foreground GPS flow.
5. Physical camera QR flow.
6. Role-gated QR and co-op actions.
7. Hint consume, score, leaderboard, and reconnect flows.
8. Android physical-device smoke verification.

Office toolchain checks are pending the local Flutter dependency/toolchain run.

## Final home verification — 2026-10-11

Status: **COMPLETE — PHYSICAL PILOT SMOKE PENDING**. The office checklist above is historical.

Flutter clean, pub get, Dart format (33 files, zero changes), analyze (no issues), and all 31 Flutter tests passed. The total includes a new timeout widget regression. Login coverage verifies the backend envelope, visible invalid-credential/network/timeout errors, token write/read, restored Bearer header, stable device UUID and the form remaining mounted during a pending request. Diagnostics do not print raw passwords or tokens.

Debug APK build passed at mobile/build/app/outputs/flutter-apk/app-debug.apk; it is an ignored local artifact. No local physical-device API IP is committed or supplied to this build. Android embedding v2, camera/location permissions and repository mirror configuration are preserved. Physical backend login, device binding, camera/GPS, role-gated gameplay and reconnect pilot checks remain pending. Dependency updates and release signing are outside this verification.
