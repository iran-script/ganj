# Scenario authoring expansion

Status: all 23 authoring families, secure QR provisioning/printing, and Flutter
media/evidence interactions are implemented. Final local verification is recorded below.
No AR, Phase 3.0, recognition, transcription, video submission, payment provider,
or unrestricted scripting is implemented here.

## Existing domain boundaries

The implementation extends `NodeTypeRegistry`, `GraphValidator`, `ScenarioGraph`,
`RuntimeEngine`, and `RuntimeProjectionPolicy`. It reuses the session/instance/node
lock order, immutable published versions, runtime commands, variables, timers,
role assignments, contribution/barrier evaluation, relay progression, append-only
scoring, hints, QR verification, PostGIS verification, audit, and outbox services.
Captain membership confers no extra gameplay role.

`AuthoringNodeCatalog` defines the new config rules and outcome semantics.
Authenticated `GET /api/v1/scenario-node-catalog` returns the authoring catalog,
config validation rules, categories, runtime handlers, and inspector identifiers.
The Control Center's `lib/node-catalog.ts` supplies typed form descriptors,
defaults, Persian labels, and palette grouping. Backend validation remains final.

## Node catalog and execution

- **Start / Finish**: one Start, at least one reachable Finish. Finish cannot have
  outgoing edges or close an instance while another active path remains unresolved.
- **Message**: text and acknowledgement. Optional guidance, deadline, and completion score.
- **Puzzle**: prompt, expected answer, case/whitespace rules, optional attempt limit
  and existing hints. The expected answer and hints are not exposed in projection.
- **GPS / Location**: inline `latitude`, `longitude`, positive `radius_m`; optional
  `accuracy_m`, `dwell_seconds`, and existing location participation policies.
  Coordinates are in WGS84. Measured location ingestion and PostGIS geography
  acceptance remain server-owned. No checkpoint is required.
- **QR**: a tenant-owned active `trigger_id`, plus existing QR role/session/device
  and token policies. Public trigger UUIDs are references, not scan secrets.
- **Time**: DELAY waits for durable server timer expiry; TIMEOUT accepts an
  acknowledgement before expiry and selects TIMEOUT at expiry; TIME_WINDOW accepts
  actions only between explicit timezone-qualified ISO timestamps and expires at
  its end; ELAPSED_CHECK compares runtime elapsed seconds and selects TRUE/FALSE.
  Delay and relative timeouts pause with the runtime; absolute windows use wall time.
  Late actions are rejected even if the scheduler has not processed expiry yet.
- **Condition**: typed comparisons against `score`, `elapsed_time`,
  `team.member_count`, `player.role`, or `variables.<key>`. Legacy condition groups
  remain supported. Inline conditions route TRUE/FALSE. There is no expression evaluator.
- **SetVariable / CheckVariable**: safe identifiers, declared TEAM variables of
  STRING, INTEGER, or BOOLEAN type. Writes require `writable=true`; checks compare
  without mutation. Initial values are snapshotted in the graph.
- **Score**: ADD or SUBTRACT, bounded positive amount, required reason. Uses an
  idempotent append-only score event. SET and client-written scores are unavailable.
- **RoleCheck**: compares the player whose preceding action caused activation.
  Without an acting player the result is FALSE. It does not choose an arbitrary
  team member as the acting player.
- **AssignRole**: distinct existing role codes, at most four primary roles,
  LEAST_RECENTLY_USED or SEEDED_RANDOM policy. Uses RuntimeRoles and the existing
  assignment policy. Too few eligible participants prevents execution.
- **RandomPlayer**: selects an eligible READY/ACTIVE roster member using the pinned
  instance seed and node key. Persists the choice in node state and a declared
  STRING variable; reconnect does not reroll it. Optional private content is
  projected only to the selected player.
- **SplitInformation**: role-keyed private text and contribution requirements.
  The server projects only the current player's role content.
- **TeamTogether**: minimum participants, optional all-active policy, required
  roles, synchronization window, and existing active-slot/support policies.
- **Barrier**: the same co-op contribution mechanics plus declared boolean
  variables that must be true. Duplicate acknowledgements do not add participants.
- **Relay**: the existing ordered-role relay and contention protection.
- **LiveChallenge**: waits for a completed TEAM-targeted GameMaster challenge in
  the same session, category MESSAGE or TEAM_OBJECTIVE, created after activation.
  The graph cannot create a challenge or assign a reward directly. Challenge reward
  and penalty policies remain in the GameMaster domain.
- **MediaReveal**: immutable library `media_asset_id`, description, CTA label,
  acknowledgement policy, and optional guidance. No binary or base64 is stored in graphs.
- **PhotoSubmission**: instruction, camera policy, size limit, optional fresh
  location and capture timestamp requirements, review policy, and resubmission policy.
- **AudioSubmission**: instruction, record policy, size and duration limits,
  review policy, and resubmission policy. Evidence recordings initially use PCM WAV;
  duration is calculated from validated PCM format/data, not a client-supplied duration.
- **FileSubmission**: instruction, allowed MIME list, size limit, review and
  resubmission policies. Supported content is JPEG, PNG, PDF, WAV, and MP3; HTML,
  scripts, executables, and arbitrary MIME types are rejected.

Evidence capture-source metadata enforces workflow policy, not hardware
attestation. A client cannot prove camera provenance merely by naming a source.

## Edges and graph validation

Edge `metadata` accepts only `outcome` and an optional operator-facing `label`.
Outcomes are SUCCESS, FAILURE, TIMEOUT, TRUE, FALSE, APPROVED, and REJECTED.
Only outcomes supported by the source node/config are valid. Attempt limits and
retry delays are declared only for Puzzle, QR, and GPS; evidence uses its distinct
review/resubmission policy rather than accepting ignored retry fields. An outcome cannot
also carry a legacy condition-group filter. Duplicate outcome routing and mixing
labeled and unlabeled routes on the same node are rejected.

Branch nodes require both TRUE and FALSE. Evidence nodes require APPROVED and
REJECTED routes. Deadlines require TIMEOUT routes. A single ordinary SUCCESS route
may remain unlabeled. Existing linear and legacy conditional graphs remain readable.
Legacy puzzle attempt limits without a FAILURE edge retain their existing blocked
state; an explicit FAILURE edge enables terminal failure routing.

The validator also rejects missing/duplicate starts, missing/unreachable finishes,
unreachable nodes, dangling edges, cycles, undefined variables, incompatible types,
invalid roles, minimum participant counts exceeding four when support players are denied, invalid time windows, bad
coordinates, inactive/cross-tenant QR references, unavailable media references,
unsafe file limits, and unknown config fields. All cycles remain unsupported.

## Resource banks

Control Center routes:

- `/control/content/qr-bank`, `/new`, and `/[id]`
- `/control/content/locations`, `/new`, and `/[id]`
- `/control/content/media`
- `/control/submissions`

QR Bank uses the existing QR trigger domain, organization authorization, lifecycle
revocation, and public UUIDs. Its payloads expose name, operational label, location
description, notes, status, timestamps, and version-node usage count. They do not
expose raw identifiers or hashes. The legacy one-time provisioning API remains a
separate compatibility surface; the legacy browser creation page redirects to the bank.
Bank creation generates an opaque 256-bit identity in the existing QR service.
Its SHA-256 hash remains the scan lookup; an encrypted, serialization-hidden copy
supports authorized reprinting. Keep the application encryption key in the normal
secret-management system. Neither bank list/detail responses nor audit payloads
contain the identity. The dedicated administrator-only printable endpoint returns
an SVG attachment rendered by BaconQrCode, with black modules, white background,
a four-module quiet zone, and a 1024-unit scalable canvas. It is no-store and
nosniff. The printable image is inherently scannable and must be handled as a game
resource. Inactive items cannot be printed. Legacy hash-only items cannot be
reconstructed: provision a replacement if the original printable copy is lost.

The bank provides creation, editing, active filters, usage counts, and SVG download.
An inactive item's saved state immediately refreshes download availability.

Location Bank reuses POINT checkpoints and PostGIS geometry. Its friendly API uses
latitude, longitude, default radius, optional accuracy/dwell, category, description,
and status. The Builder copies coordinates and policy into the GPS config and
removes a checkpoint dependency when direct coordinates are applied. Later bank
edits cannot change that published snapshot. Legacy published checkpoint references
keep their existing immutability constraints.

Resource APIs are under `/api/v1/content/qr-bank`, `/content/locations`, and
`/content/media`. Lists and mutations are tenant-scoped and server-authorized.

## Media, evidence, and review

Media assets have immutable metadata and randomized private object keys. Files are
validated by content MIME, size, image dimensions, and, for audio evidence, PCM
duration. Graphs reference immutable LIBRARY assets; EVIDENCE assets cannot be used
as scenario library references. Downloads have private/no-store, nosniff, attachment,
and restrictive CSP headers. Images/audio may be previewed only after authenticated
fetches; other files expose metadata and a download, with no executable inline rendering.

Player uploads use `POST /api/v1/runtime/nodes/{id}/evidence`, multipart `file`,
`capture_source`, optional `captured_at`, and an `Idempotency-Key`. Uploads require
the bound active device, frozen roster membership, eligible runtime role, active node,
running session/instance, and an unexpired server deadline. Ownership is derived
server-side. Reusing a key with different content conflicts.

AUTO_ACCEPT advances after validation and storage. GAMEMASTER_REVIEW remains
pending until an authorized operator reviews it through
`POST /api/v1/submissions/{id}/review` with status and message. Approval advances
exactly once. Rejection either selects REJECTED or permits a new upload according
to `allow_resubmit`. Runtime acknowledgement cannot bypass review. Audit/outbox
events record decisions without embedding binaries. Review messages use the
existing GameMaster messaging API.

Location-required evidence checks for a fresh eligible server-recorded location;
it does not invent a GPS checkpoint requirement. The timestamp must fall within
the active node interval, with the configured server tolerance.

## Storage and operations

`filesystems.media_disk` defaults to the private local disk and is configurable with
`MEDIA_DISK`. Production already mounts `app_storage` across app, queue, and
scheduler. Local Compose now shares `media_storage` at `storage/app/private` across
the same services. No public storage symlink is needed. PHP upload maximum is 25 MB,
with a 26 MB request limit in PHP/nginx to allow multipart overhead.

The Storage abstraction and stored disk/object keys allow a later S3 disk without
changing graph references. Installing/configuring an S3 driver, migrating objects,
retention policies, and operating backups are separate operational work; no cloud
bucket or production service is provisioned by this task.

## Builder, simulation, and versions

The Builder uses NEXO cards, eight Persian palette groups, search, drag/drop and
button creation, typed inspectors, resource pickers, variable forms, edge outcomes,
node summaries, dirty/save state, validation, publication, minimap/zoom, and full-width
mode. Published versions are read-only. Draft saves use revision tokens.

Simulation is explicitly labeled “پیش‌نمایش ساختاری — اجرای واقعی Runtime نیست”.
It previews selected paths, variables, roles/player context, scores, and elapsed
time. It makes no runtime calls, consumes no QR tokens, validates no GPS samples,
uploads no files, and writes no rewards. Branch choices are operator-selected
previews; they are not authoritative condition evaluations.

Published versions preserve GPS coordinates, QR/media references, variable schema,
logic, timing, and team rules. Media objects cannot be replaced in place. QR lifecycle
status remains a live security control even for a published reference.

## Player and offline support

Existing Message, GPS, QR, Puzzle, and Finish renderers remain. This work adds Time,
private SplitInformation, TeamTogether/Barrier/Relay waiting states, logic transition
states, private random-player messages, authenticated image reveal, and evidence
pending/approved/rejected feedback. Evidence status widgets cannot bypass review.
Photo evidence uses the camera or the system gallery according to CAMERA_ONLY or
CAMERA_OR_GALLERY. CAMERA_OR_LIBRARY remains accepted for older drafts. Audio
recording requests microphone access only after the Record action, records bounded
PCM WAV, stops at the configured limit or on backgrounding, and supports playback
and re-recording. File selection uses the system picker with a MIME-derived
extension filter; the backend still validates actual MIME, size, and authorization.
Uploads retain a stable retry key and display pending/approved/rejected state.
Rejected submissions may be replaced only when the published policy permits it.

MediaReveal plays authenticated audio through just_audio and opens FILE assets
only after a successful authenticated, MIME-checked download. open_filex receives
only server-authorized bytes saved under a fixed safe extension in app-private
cache; it is not an authorization or validation boundary. Binary downloads have
a 25 MB streaming cap and are discarded if authentication changes. Native recording
and playback files are removed when their owning flow is disposed. No credentials,
QR secrets, or signed access URLs are persisted in these temporary files.

Media uploads require an online connection and never enter the offline outbox.
Evidence widgets show “برای ارسال این مرحله به اینترنت نیاز دارید.”
Existing small-action offline policies are retained. Client timer displays, role
controls, file metadata, and simulation state never determine canonical progression.

## Extension boundaries

Future AR support must add a validated schema, runtime handler, projection policy,
device capability checks, and tested Flutter support before entering the authoring
catalog. Future AI evidence validation must be an audited backend decision that
feeds the existing review transition, with explicit failure/manual-review policy.
No recognition, speech-to-text, client scripting, or AI approval is present now.

## Verification

Focused backend tests live in `AuthoringLogicTest`, `AuthoringResourcesTest`,
`AuthoringTeamTest`, `AuthoringValidationTest`, and `AuthoringAcceptanceTest`.
The acceptance test creates a canonical 23-node graph with inline GPS, secure QR
reference, immutable media, variables, score branching, roles/team mechanics,
evidence, Time/LiveChallenge fallback, and Finish. It saves, validates, publishes,
initializes Runtime, verifies published mutation rejection, and executes both
branches through Finish. The evidence branch performs PostGIS GPS acceptance,
secure QR token consumption, role contributions/relay, and operator approval of
photo, WAV audio, and PDF evidence. The fallback exercises server timer expiry
and a separately authorized GameMaster challenge.

Run the full backend suite with the repository's CI convention: a fresh isolated
PostGIS database before each test file. Never point these tests at production data.
Control Center has additional form, bank, multipart upload, review, and structural
simulation tests. Flutter renderer tests cover the currently implemented states.
Device plugins are exercised through injected test adapters; real camera, microphone,
and external-viewer behavior still warrants a physical-device smoke test before rollout.

## Approved dependencies and platform permissions

Only the task-approved packages were added. PHP uses bacon/bacon-qr-code 3.1.1
and its required dasprid/enum 1.0.7 dependency. Existing Composer packages were not
upgraded. Flutter resolves image_picker 1.2.4, file_picker 11.0.3, record 7.1.2,
just_audio 0.10.6, path_provider 2.1.6, and open_filex 4.7.0. The existing secure
storage/scanner dependencies are preserved. The application pubspec.lock is now
tracked rather than ignored.

With Flutter 3.47 / Dart 3.13 / AGP 9.1 / Gradle 9.3.1, file_picker 11 needs a
scoped Kotlin Gradle plugin application because this project's Flutter template
disables built-in Kotlin. Its Java/Kotlin targets are both 17. Newer file_picker
major versions require a win32 major incompatible with the existing secure-storage
package; this task deliberately retains that dependency rather than upgrading it.

The merged Android manifest permits Internet, network state, camera, foreground
coarse/fine location, and audio recording, plus AndroidX's app-local signature
permission. Broad storage, READ_MEDIA_*, and package-install permissions contributed
by plugins are explicitly removed. Gallery/files use system selection grants.
Microphone permission is requested only for recording; location permission only
for GPS or location-required evidence. iOS purpose strings cover camera, selected
photos, microphone, and the existing foreground location flow; no iOS build was
performed on this Windows workstation.

### Local verification snapshot - 2026-10-13

- Starting commit: `eef118873c4a2318c78233883eaa64854561f6a9` on `main`.
- Full backend: 65 files, **639 tests, 9,519 assertions, 0 failures, 0 errors,
  0 skipped**. Totals use the final affected-file reruns after review fixes.
- Focused authoring: 43 tests / 248 assertions; QR suites: 26 tests / 177 assertions;
  inline GPS: 20 tests / 69 assertions. These are included in the full totals.
- All three new migrations rolled back and reapplied on the isolated test database.
- Local JUnit evidence: `storage/nexo-authoring-junit/summary.json` and its XML files
  (generated verification artifacts, intentionally excluded from Git).
- Composer strict validation and Pint passed; PHP syntax checks passed for 324 files.
- Control Center: 42 tests in 13 files; typecheck, lint, and production build passed.
  Existing locked npm dependencies were reused; npm ci was not needed.
- Flutter: 58 tests; analysis, format check (42 files, no changes), and debug APK build passed.
- Complex acceptance: two canonical 23-node graphs exercise the evidence route and
  Time/LiveChallenge fallback through Finish; 41 assertions pass.
- PostgreSQL accepts connections to `urban_ar_test`; PostGIS 3.4 and geography distance
  return the expected result; Redis PING returns PONG. The dedicated test container
  uses APP_ENV=testing and a configured test-only key. No production data is used.
- Compose configuration validates. A dedicated local volume retained a file across
  test-container recreation. Production's existing app_storage is reused; no deployment
  or production service recreation was performed.
- No physical-device camera, microphone, or external-viewer smoke test was performed.
  Client flows use injected adapters in widget/API tests; native plugins passed
  Android compilation. This does not replace physical-device testing.
- Non-failing build notices: Next.js detects an existing parent-directory lockfile;
  file_picker/mobile_scanner use KGP compatibility supported by this pinned toolchain.
- Composer audit reports two pre-existing league/commonmark 2.10.1 advisories:
  GHSA-97jj-33gv-5xf9 (medium) and GHSA-3q6v-r5mr-hxv8 (high). They were not introduced
  by the approved packages; remediation needs a separately scoped maintenance update.
- No push or deployment. AR/Phase 3.0 remains out of scope.

Next operational slice: physical Android capture/playback/file-viewer and printed-QR
smoke testing, plus a scoped CommonMark security update. Do not start AR as part of
that verification.
