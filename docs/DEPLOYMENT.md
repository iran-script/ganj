# NEXO production deployment

Use `docker-compose.production.yml` by itself with project name `nexo`. The existing
`docker-compose.yml` and default Dockerfile target remain the development setup.
Production uses PHP 8.4 FPM, Nginx, PostGIS 16/3.4, Redis 7, one Redis queue worker,
and one Laravel scheduler. `outbox:dispatch` is a finite command already scheduled
every ten seconds; do not run a second dispatcher service.

## Safety and configuration

Audit disk, available memory/swap, `docker ps`, `docker stats --no-stream`,
`docker system df`, `ufw status`, and `ss -lntup` before each release. Preserve all
unrelated services. Never prune Docker resources, restart Docker, reboot, or change
firewall rules as part of this deployment. Stop on migration/health failures or
severe resource pressure. Prefer at least 4 GB RAM for a VPS sharing many stacks.

Create `/opt/nexo/.env` on the server only, owned by root with mode `0600`. Generate
APP_KEY from 32 cryptographically random bytes (`base64:` encoded) and DB_PASSWORD
from at least 32 random bytes. Never rotate these values during routine updates.
Required values:

```dotenv
APP_NAME=NEXO
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generated-server-only>
APP_URL=<confirmed-HTTPS-URL-or-temporary-loopback-URL>
DB_CONNECTION=pgsql
DB_HOST=nexo-postgres
DB_PORT=5432
DB_DATABASE=nexo
DB_USERNAME=nexo
DB_PASSWORD=<generated-server-only>
REDIS_CLIENT=predis
REDIS_HOST=nexo-redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=file
SESSION_ENCRYPT=true
LOG_CHANNEL=structured
LOG_LEVEL=info
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
NEXO_HTTP_PORT=18100
```

The sole published port is `127.0.0.1:18100:80`. Check availability first; if occupied,
stop and explicitly choose an available port in 18101أ¢â‚¬â€œ18199 via NEXO_HTTP_PORT.
PostgreSQL and Redis have no published ports. All six services use only the NEXO
network, bounded memory, rotating Docker logs, and `unless-stopped` restart policies.
Storage, PostgreSQL, and Redis AOF use separate persistent named volumes. PHP runs as
www-data; the image initializes writable storage and bootstrap/cache permissions.
Configuration and route caches are generated at container startup with the actual
environment. No production secrets or generated local caches enter the image.
Nginx access logs omit query strings and authorization headers.

## First deploy

Clone `https://github.com/iran-script/ganj.git` into `/opt/nexo` if absent. Stop if
GitHub authentication fails. Confirm the approved SHA before creating resources.
Create the server-only environment above, then:

```sh
cd /opt/nexo
git fetch origin
git checkout main
git pull --ff-only origin main
git rev-parse HEAD
docker compose -f docker-compose.production.yml config --quiet
docker compose --parallel 1 -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml up -d postgres redis app
docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force --no-interaction
docker compose -f docker-compose.production.yml exec -T app php artisan view:cache --no-interaction
docker compose -f docker-compose.production.yml up -d
```

Start queue/scheduler only after successful initial migrations. Do not seed demo
records or import local smoke databases. Monitor resource usage during the build;
`--parallel 1` and serial PHP extension compilation reduce peak build demand.

## Update deploy

Back up the database before schema-sensitive releases. Record the current SHA and
image IDs. Review migration compatibility before allowing new code to serve traffic.
For backwards-compatible releases:

```sh
cd /opt/nexo
git rev-parse HEAD > .git/nexo-previous-release
git fetch origin
git pull --ff-only origin main
docker compose -f docker-compose.production.yml config --quiet
docker compose --parallel 1 -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml exec -T app php artisan migrate --force --no-interaction
docker compose -f docker-compose.production.yml exec -T app php artisan view:cache --no-interaction
```

For migrations incompatible with simultaneous old/new application execution, plan
a NEXO-only maintenance window separately; do not use the above rolling sequence.
Never stop unrelated applications. Do not use `down -v` or `--remove-orphans`.

## Health checks

```sh
curl -fsS http://127.0.0.1:18100/api/v1/live
curl -fsS http://127.0.0.1:18100/api/v1/ready
curl -fsS http://127.0.0.1:18100/api/v1/health
docker compose -f docker-compose.production.yml ps
docker compose -f docker-compose.production.yml logs --tail=100
docker compose -f docker-compose.production.yml exec -T app php artisan schedule:list --no-interaction
docker compose -f docker-compose.production.yml exec -T postgres psql -U nexo -d nexo -c 'SELECT PostGIS_Version();'
docker stats --no-stream
free -h
df -h /
```

Readiness checks PostgreSQL, PostGIS, Redis, and queue backend connectivity. Worker
health is unknown after 120 seconds without a processed job, even if an idle worker
is running normally. For an end-to-end smoke check, dispatch the existing
`App\Jobs\Phase0SmokeJob` to the Redis queue and verify its temporary Redis cache key
and the `/api/v1/health` worker result. Do not fabricate worker heartbeats. Inspect
scheduler logs for successful outbox and timer tasks. See `docs/HEALTHCHECKS.md`.

## Caddy and HTTPS

Keep NEXO internal until an explicitly chosen domain resolves to the VPS. Do not
invent a domain or modify Caddy on failed NEXO health. Back up the discovered active
Caddyfile before a minimal routing edit; validate with the running Caddy binary,
then gracefully reload only after validation succeeds. Never replace its entire
configuration or restart unrelated containers.

A bridge-networked Caddy container cannot reach the host's loopback binding through
`127.0.0.1:18100` inside the container. Confirm a separately authorized connectivity
plan before adding public routing (for example a dedicated ingress network); do not
publish NEXO to all host interfaces to work around this. Configure trusted proxy and
HTTPS forwarding behavior as part of that integration and verify client-IP-based
rate limits. After TLS verification, set APP_URL to the confirmed HTTPS origin and
recreate only NEXO application processes to refresh their configuration caches.
Flutter should use its existing build/runtime configuration for
`https://<confirmed-domain>/api/v1`; do not hardcode an IP into mobile source.

## Rollback

Read `.git/nexo-previous-release`, verify it is a known-good SHA, check out that SHA,
rebuild using the production compose, and run `up -d` for NEXO only. If the old
commit predates production deployment files, retain the reviewed deployment files
or restore the recorded image IDs instead. Never blindly roll back migrations;
confirm schema compatibility or use a reviewed database restore plan. Recheck
health, queue, scheduler, ports, and resources. Record the resulting SHA.

## Backup

Before schema-sensitive releases, create a restricted backup directory outside the
source checkout, then take a consistent PostgreSQL custom-format dump:

```sh
cd /opt/nexo
umask 077
mkdir -p /opt/nexo-backups
backup=/opt/nexo-backups/nexo-$(date +%Y%m%d-%H%M%S).dump
docker compose -f docker-compose.production.yml exec -T postgres pg_dump -U nexo -d nexo -Fc > "$backup"
test -s "$backup"
docker compose -f docker-compose.production.yml exec -T postgres pg_restore --list < "$backup" > /dev/null
```

Check every command's exit status, copy backups to restricted off-server storage,
and test restore into a separate disposable database. Listing the dump verifies
readability, not recoverability. Do not archive a live PostgreSQL volume as if it
were a consistent backup. Back up application storage and the server-only .env
securely; keep APP_KEY available for recovery. Redis AOF is persistent but does not
replace backups of PostgreSQL, application files, and critical configuration.


## Production session admission

Games require explicit `pricing` (`mode`: `FREE` or `PAID`, integer `amount`
in currency minor units, and a three-letter uppercase `currency`) and a
`scenario_version_id` public UUID. Configure these through the authorized
Game service/API. Pricing changes require an audit reason. Missing prices
never imply free admission. A FREE price must have amount zero; PAID must
have a positive amount. Paid admission remains pending until payment
infrastructure is implemented; this release does not claim paid settlement.

The additive production-session migration adds nullable game configuration
and append-only `session_configuration_snapshots`. Opening registration pins
the configured price and published, same-organization scenario version.
Subsequent game edits apply to future sessions. Billing references prefixed
`free-exemption:` refer to this persisted zero-charge configuration, not a
payment transaction. Start embeds these references in the existing immutable
roster snapshots; Runtime pins the published version through its existing
initializer. Team eligibility (4?8 members), bound devices, four ready
players, and lifecycle authorization remain enforced by existing services.
Teams join after registration opens, so roster readiness is checked at
registration/close/start rather than requiring a roster in DRAFT.

The local CLI command `php artisan nexo:operator:create <existing-mobile>
--reason="Authorized operational purpose"` grants only organization creation.
It does not create a user or grant SuperAdmin, global role administration,
archive capability, or access to other tenants. An operator becomes the
OrganizationAdmin of each organization they create. Grants are idempotent
and audited. There is no web bootstrap route. Revoke the global capability
through an explicitly authorized operational procedure; existing scoped
memberships are independent.

Back up PostgreSQL before this migration using the backup procedure above.
Existing games retain null configuration and fail closed until configured.
Rollback drops configuration/snapshot data and is intended only for empty
local validation databases. It refuses when admission snapshots or operator grant history exist.
Use a forward fix for populated production deployments; do not erase audit
history to force rollback. No production fixture or credentials are seeded.
