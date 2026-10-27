# NEXO Release Candidate Operations

This file prepares a release layout; it is not authorization to deploy. The Compose project is deliberately separate from the existing mixed production Compose projects. It defines only Backend, Control Center, and the public TLS edge. It does not define PostgreSQL, Redis, queue, or scheduler services.

## HTTPS prerequisites

Before any approved rollout, provide the verified production DNS name, an A/AAAA record pointing at the authorized server, and a valid certificate/key pair at a protected server path. The certificate files must be named `fullchain.pem` and `privkey.pem`. No domain or certificate location is assumed here. HTTP is redirected to HTTPS; unknown Host values are rejected. Both `/api/` and Control Center routes share the HTTPS origin, while Laravel-FPM and Next.js have no published host ports. Port 18100 is not present in the release Compose file.

Production and staging use separate env examples. Replace every `__REQUIRED_...__` marker with values confirmed for that environment and keep runtime env files and TLS keys outside Git. Staging must have its own DNS, certificate, Docker network, database, storage volume, and secrets. Production must use the database name confirmed from the read-only production connection. Do not run the release Compose file until those values and external network/volume identities are verified.

For Flutter, build a production APK with an explicit HTTPS API URL and variant, for example:

```powershell
flutter build apk --release --flavor production `
  --dart-define=API_BUILD_VARIANT=production `
  --dart-define=API_BASE_URL=https://<verified-production-domain>/api/v1
```

For staging, use `--flavor staging`, `API_BUILD_VARIANT=staging`, and the separately verified staging HTTPS URL. Production builds with a missing or HTTP URL fail closed in `ApiClient`. Debug builds may use HTTP only on local loopback; the emulator alias is not an allowed default.

## Candidate rollout layout

After a separately approved release, archive the exact source and Compose file under `/opt/nexo/releases/<release-id>/`. `/opt/nexo/current` is a proposed pointer to the approved release directory; it has not been created or changed. Preserve the existing Compose files, all current image tags/IDs, and every PostgreSQL/Redis volume. Build and tag the three candidate images independently as `nexo-app:<release-id>`, `nexo-control:<release-id>`, and `nexo-nginx:<release-id>`, then record each resulting image ID in the release manifest. Never use a mutable `latest` tag.

The current server has multiple historical Compose project/config origins. Do not run a repository-wide `docker compose down`, do not adopt the production database/Redis services into this project, and do not remove or rename any existing container or volume. Before a rollout, verify the audited external Docker network and app-storage volume names against read-only `docker inspect` output. Candidate services use unique network aliases so they can coexist with the currently running services. Only the new edge publishes ports 80/443; the PHP-FPM API is reachable through that edge, not through a host-published API port.

Roll out one service at a time with the approved immutable environment file and saved Compose file, using `docker compose ... up -d --no-deps <service>` and never `down`. Verify that service health and its expected upstream behavior before proceeding. PostgreSQL and Redis are not members of this Compose project and are not restarted by these commands.

## Independent rollback plan

Keep the existing image references and IDs recorded in the preflight manifest. Use a separately protected rollback env file that overrides exactly one image variable (`NEXO_APP_IMAGE`, `NEXO_CONTROL_IMAGE`, or `NEXO_NGINX_IMAGE`) with the retained prior immutable tag, then update only the corresponding service with `up -d --no-deps <service>`. Do not add `--build` during rollback. Leave migration history and additive tables intact; the `team_join_requests` migration is additive and is not reversed during an ordinary code rollback. Do not restore the production database as a routine rollback step.

The currently retained Control Center and Nginx images predate the new BFF authentication and TLS edge. They are image-preservation targets, but **not safe public rollback targets by themselves**. If Control Center must be rolled back, first put its public routes into maintenance mode. The prepared rollback Nginx image keeps HTTPS/API routing to the retained Backend and returns 503 for `/control`, `/login`, `/api/control`, and `/api/backend`; invite web routes return 404. This avoids exposing the old player-capable web login. If the new TLS edge itself must be replaced, use the prebuilt rollback edge image with the same verified certificate mount and verified old Backend alias. Never expose the old HTTP-only Nginx image on a public interface. If that rollback edge image and its config are not built and validated before an approved release, the release remains NO-GO.

The old edge currently publishes port 18100 broadly. Do not stop, reconfigure, or recreate it as part of this preparation. A future rollout needs an approved change window and a reviewed handoff plan for preventing direct access to that existing listener; this candidate Compose file does not remove the existing exposure.

## Scope and release-candidate controls

The current branch contains one commit ahead of `origin/main` plus uncommitted work. The 89 committed-ahead paths include Team Lobby, invitations, join requests, Booking/Payment work, documentation, and direct deployment/migration helpers. Booking/Payment code and its migration, direct operational scripts and backup copies, the zero-byte `mobile/curl.exe`, local logs, and preview-only files are not automatically included in this security release. They must remain preserved locally and be excluded or separately reviewed before constructing the immutable build context. The 29 previously recorded local changes overlap identity, routes, auth tests, and client adjustments. This candidate manifest hashes the complete changed working tree while marking scope exclusions; it is not a deployment archive.

The Dockerfile currently builds from the whole repository. Therefore, do not build release images from the unfiltered working tree until the scope review has produced an exact, approved source archive. This is a remaining release blocker; no local changes have been removed to resolve it.
