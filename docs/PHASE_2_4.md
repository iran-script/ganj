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
