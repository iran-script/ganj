# GPS, Location and Checkpoints

Phase 2.0 implements only the Location domain. PostgreSQL/PostGIS is authoritative. Clients submit observations; Runtime remains the only node progression authority.

## Contracts and scope

The canonical routes are POST /api/v1/locations, POST /api/v1/locations/batch, GET /api/v1/game-sessions/{session_id}/players/me/location-policy, and checkpoint POST/GET collection plus GET/PATCH item. The schemas in docs/specs/03_openapi.yaml describe their strict payloads. No raw route/history export, competitor location API, override endpoint, or duplicate checkpoint route is added.

Location submissions contain session_id, lat, lng, accuracy, client_timestamp and sequence; altitude, speed and heading are optional. Context comes from the authenticated User, X-Device-Id and Session roster. Client organization/player/team identifiers, containment flags and completion claims are rejected. Public resource identifiers are UUIDs.

A batch contains samples, at most 100, from one Session. Each observation is validated, sorted by sequence, and ingested in one all-or-nothing transaction. Any validation, authorization or sequence conflict rolls back the entire batch. Exact retries preserve their original server_received_at. A successful new batch emits location.batch_accepted.v1; an exact retry does not.

## PostGIS schema

Canonical tables are checkpoints, location_events, player_latest_locations, geofence_evaluations and route_summaries. Geometry uses explicit EPSG:4326. Checkpoints support POINT and ZONE (Polygon/MultiPolygon); observation and latest geometry is Point, and route summaries use LineString. Latitude/longitude bounds, valid nonempty geometry, type/radius consistency and Device/Session context are database constrained.

GiST indexes cover checkpoint, observation and latest geometry. Observation indexes cover Session/time, Player/time and Player/Device/sequence. Geofence evaluations have an index on node/player/id descending for the latest evidence lookup. Runtime uses a lateral LIMIT 1 lookup per eligible Player, bounded by the eight-player roster; it never scans a full route to verify a checkpoint.

location_events is RANGE-partitioned by server_received_at from its initial migration. Monthly partitions cover the previous, current and next two months, with a default partition for availability. location:maintain-history prepares the month two months ahead. Monitor the default partition; if maintenance has been offline long enough to accumulate rows in a future partition's range, move those rows during a controlled maintenance window before attaching that partition. Never silently discard them.

The partitioned primary key includes id and server_received_at. Application ingestion takes User, Session and Device locks and checks the indexed stream identity before insertion; this enforces cross-partition sequence deduplication. The per-partition unique constraint is supplementary, not a claim of global sequence uniqueness.

## Accuracy and geofence semantics

Distances are server-calculated ST_Distance over geography, in meters. No degree-to-meter approximation is used for gameplay.

For a point checkpoint with distance d, radius r and reported accuracy a:

- Accuracy above min_accuracy yields UNCERTAIN. Despite its canonical name, min_accuracy is the maximum acceptable reported uncertainty in meters.
- d + a <= r yields INSIDE.
- d - a > r yields OUTSIDE.
- Otherwise the result is UNCERTAIN.

For zones, ST_Covers includes the boundary. With zero uncertainty, an exact boundary point is INSIDE. Positive uncertainty touching/overlapping the boundary yields UNCERTAIN. Otherwise containment decides INSIDE/OUTSIDE, subject to the accuracy threshold. Geometry is validated by PostGIS before persistence.

Canonical results are INSIDE, OUTSIDE and UNCERTAIN. Poor quality evidence never independently completes a checkpoint.

## Time, sequence and offline replay

server_received_at is assigned by the server in UTC. Current receipt/dwell calculations use whole seconds; client_timestamp is metadata, normalized to UTC. It cannot grant dwell or backdate progression.

Streams are keyed by Session Player and bound Device. Exact sequence/payload retries return the original result. A changed payload using a retained sequence returns 409 IDEMPOTENCY_CONFLICT. Lower sequences, delayed timestamps and non-increasing client time are retained as late observations. They do not overwrite a newer current projection or grant retroactive progression. Stream high-water marks survive delayed delivery and Device changes in the durable latest record. There is no MAX(client_timestamp) location authority.

Defaults in config/location.php are implementation policies expressly left configurable by the GPS specification:

- Freshness: 30 seconds, checked against both server receipt and observation time.
- Future timestamp tolerance: 30 seconds; larger offsets return LOCATION_INVALID.
- Raw replay/retention window: 30 days; older observations return LOCATION_INVALID.
- Maximum continuous dwell sampling gap: 15 seconds.
- Maximum acceptable accuracy: 30 meters unless checkpoint/node policy says otherwise.
- Batch maximum: 100. Rate limit: 120 location requests/minute/User.
- Suggested movement / near checkpoint / stationary cadence: 10 / 3 / 25 seconds.

Mobile observations are OFFLINE_QUEUEABLE. A mobile outbox may retry the same sequence and payload after connectivity returns. The repository contains no Flutter client; this phase implements its server contract. Offline clients cannot assert inside, dwell completion or node completion.

## Durable dwell and team presence

Ingestion evaluates active GPS/Checkpoint nodes for the current Team. geofence_evaluations records the observation reference, classification, policy hash, server evaluation time, dwell start and evidence count. This durable chain survives process restart.

Positive dwell requires at least two qualifying observations and enough time between their server receipts. Repeated verification without another observation cannot accrue dwell. Exit, uncertainty, excessive sampling gap, non-increasing receipt time, policy change or pause/resume breaks continuity. Paused telemetry is accepted but cannot progress gameplay; resumed gameplay requires fresh post-resume evidence.

Runtime verification counts distinct eligible Players in the same Session and Session Team, with active User, valid current Device, fresh evidence, accurate INSIDE result and completed dwell. Removed, disqualified, finished, foreign-Team, stale and inaccurate Players do not count. Active/disconnected roster participation follows the existing Session policy.

Checkpoint config supports required_player_count, required_roles and required_capabilities. GPS node policy can specify these requirements using the existing canonical field names. Roles come exclusively from unreleased player_role_assignments and RuntimeRoles::CAPABILITIES: SCANNER/SCAN_QR, NAVIGATOR/NAVIGATE, DECODER/DECODE, AR_OPERATOR/OPERATE_AR. Captaincy and administrative roles confer no gameplay role.

## Runtime and content integration

RuntimeLocationVerification implements the existing LocationVerification port and returns the accepted/complete verification result. It never writes a Runtime node status. Runtime's existing transaction, command idempotency and transition guards remain authoritative.

Scenario validation resolves only ACTIVE same-tenant checkpoint references. Published checkpoint geometry and verification policy cannot be edited through the administration API; create a new checkpoint reference instead. Status changes preserve historical references and make unavailable checkpoints fail closed.

Only LocationVerification's production binding changes. QR, AR, Score, Hint and LiveChallenge adapters remain fail closed; Billing and Store are untouched.

## Security, events and audit

Ingestion rechecks authentication, active User, Session state, roster, current Device ownership/binding and revocation while locked. It never silently rebinds a Device. Context is server derived. Checkpoint administration reuses OrganizationScope and OrganizationContext authorization. Hidden checkpoint source is unavailable to Players and unrelated tenants.

Raw observation history is sensitive and has no public read/export endpoint. Responses are no-store. Derived events carry public references and minimal evidence summaries, not raw route coordinates or Device secrets.

Raw accepted samples retain location.sample_accepted.v1 and correlation metadata on the optimized history record; raw pings do not create generic outbox or human audit entries. Derived state transitions use location.geofence_entered.v1, location.geofence_exited.v1 and location.geofence_uncertain.v1. Runtime verification uses location.checkpoint_verified.v1 / location.checkpoint_rejected.v1. State and derived outbox events commit together; a technical failure rolls them back. Checkpoint administration is audited. This phase implements no verification override or punitive anti-cheat action.

## Retention and operations

The hourly location:maintain-history command takes an advisory lock, prepares a future partition, creates simplified route summaries for at most 20 closed-session Players, then purges expired geofence/observation/latest/summary rows in batches of at most 10,000 per table. Route summaries never authorize gameplay; their distance is computed from original geography and only presentation geometry is simplified.

Observation and evaluation updates are forbidden by database triggers. Deletes require the maintenance transaction's retention cutoff and cannot delete unexpired history. Raw GPS and audit/financial retention are separate. A busy deployment should monitor purge backlog and run the command more often until caught up.

Verification uses the existing isolated urban_ar_test database through Docker. migrate:fresh and rollback checks must target an isolated verification database only (this run used urban_ar_phase20_verify_20261010). The application database receives forward migrations only.

## Practical limits

Automated database contention tests prove lock/recheck behavior, not a production throughput benchmark. GPS field accuracy, battery/network behavior and mobile UI remain field-pilot/client work. No full Anti-Cheat, route analytics UI, raw export, new realtime consumer, QR or later domain implementation is included.
