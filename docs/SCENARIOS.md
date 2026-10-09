# NEXO Scenario Core and Versioning

Phase 1.5 implements content authoring and immutable versions. Runtime execution, Scenario assignment, Billing, GPS/QR/AR verification, scoring, hints consumption and the Visual Builder remain deferred.

## Identity and lifecycle

A Scenario is the organization-owned content identity. Its versions contain executable definitions. The API exposes UUIDs as `id`; database sequence IDs remain internal. Nodes and edges use stable version-local keys.

Scenario metadata (`title`, `description`, `theme`) remains editable until archive. New scenarios and versions are DRAFT. Publishing a version marks its Scenario PUBLISHED. Versions transition DRAFT → PUBLISHED → ARCHIVED. There is no return to DRAFT. Archive is a service operation because the frozen HTTP contract contains no archive route.

Published and archived version metadata, nodes, edges, conditions, variables and bindings are protected by PostgreSQL triggers in addition to application guards. Historical graphs remain readable. New content changes require a new draft; duplicate copies normalized rows to new identities with a new revision token and incremented version number.

## API

The existing `docs/specs/03_openapi.yaml` contains the fourteen canonical HTTP operations:

- POST/GET `/api/v1/scenarios`
- GET/PATCH `/api/v1/scenarios/{scenario_id}`
- POST/GET `/api/v1/scenarios/{scenario_id}/versions`
- GET/PATCH `/api/v1/scenario-versions/{version_id}`
- GET/PUT `/api/v1/scenario-versions/{version_id}/graph`
- POST `/api/v1/scenario-versions/{version_id}/validate`
- POST `/api/v1/scenario-versions/{version_id}/publish`
- POST `/api/v1/scenario-versions/{version_id}/duplicate`
- POST `/api/v1/scenario-versions/{version_id}/simulate`

Creation/duplication returns 201; reads and commands return 200 with the standard data envelope. Lists use opaque cursors and limits of 1–100. Responses have Cache-Control: no-store. Unknown request fields are rejected.

Scenario creation accepts an organization UUID only after server authorization. PATCH cannot change ownership or status. Version configuration accepts difficulty, estimated duration/distance, physical effort and challenge mix. PATCH version, PUT graph and publish require the current revision_token; clients cannot choose the next token.

## Graph persistence

The normalized canonical tables are scenarios, scenario_versions, scenario_nodes, scenario_edges, scenario_conditions and scenario_variables. Nodes carry config and optional title/position_x/position_y. No competing opaque whole-Scenario table is introduced.

Graph shape uses nodes, edges, variables, conditions and subflows arrays. Edges use from_node/to_node keys in API payloads and same-version composite foreign keys in storage. Ordering is priority followed by edge_key, including ties.

The Database Specification defines condition_group_id on conditions/edges but no separate group table. It is a logical UUID group identifier scoped to a version, with unique group positions. Graph validation resolves each referenced group within that version. No alternate condition/group table is invented. Condition execution/group evaluation is deferred to Runtime.

A graph save validates the full graph and returns machine-readable semantic results. Malformed shape, duplicate identities, invalid typed configs and unresolved structural references reject the entire write. Incomplete semantic drafts, such as a missing Finish, can be saved with a FAIL result; they cannot publish. Every accepted save changes the revision token. A save result does not replace an explicit persisted validation run.

Limits are 1,000 nodes, 5,000 edges, 500 variables, 2,000 conditions and 1,000 direct binding entries. Nested Subflow validation is bounded to eight levels and 256 traversed dependencies. These are request/work limits, not gameplay limits.

## Registry and typed content

NodeTypeRegistry defines the frozen human-readable type codes, config validation rules, required capabilities, random seed requirement and an unbound future runtime_handler slot. No runtime handler is implemented.

Registered codes:

- Control: Start, Finish, Branch, Condition, Wait, Timer, Subflow.
- Presentation: Message, Show Image, Show Video.
- References: GPS / Location, Checkpoint, QR, AR Challenge, Live Challenge Trigger.
- Puzzles: Puzzle, Password, Multiple Choice, Text Answer.
- Cooperation: Assign Roles, Swap Roles, Specific Player, Random Player, Team Together, Split Team.
- Content actions: Add Score, Subtract Score, Give Hint.

Frozen documents provide human labels rather than a machine enum; these exact labels are used without alternate aliases. Finish represents the terminal-node concept also described as End in Blueprint §39. Message corresponds to the Show Message presentation concept.

Config fields are allow-listed per definition. Examples: Wait requires duration_seconds; Message requires text; puzzle/text/password definitions require prompt, expected_answer, explicit case_sensitive and trim_whitespace; Multiple Choice requires options/correct_option_refs/multi_select; media references require asset_id/checksum. Answers stay in the administrator-only source API. Configs for future capabilities are definitions only and cannot perform actions.

Variables require key, type, scope, default_value and writable. Supported types are STRING, INTEGER, DECIMAL, BOOLEAN, DATETIME and explicit JSON extension values. Scopes are SCENARIO, TEAM, PLAYER and NODE. Keys are unique per version as specified by the database. Defaults are type checked; runtime variable values are not stored here.

Conditions allow ==, !=, >, >=, <, <=, IN, NOT_IN, EXISTS and NOT_EXISTS. Operands use allow-listed runtime fact names or variables.<key>. Operator/value types are checked; no eval, function calls or arbitrary expressions are accepted. Existence checks require a null right operand.

Templates accept allow-listed placeholders only. This phase stores administrator source text and does not render or interpolate it. Future rendering must escape the destination context and define missing-value behavior; source text must never be injected into HTML as trusted markup.

## Validation and publication

Validation checks Start uniqueness, Finish existence/reachability, every node's reachability and path to a Finish, Start incoming edges, Finish outgoing edges, Branch/Condition exits, duplicate keys, dangling edges, configs, variable/condition references, dependencies and cycles. Errors contain stable code/path pairs.

The frozen schema has no executable bounded-loop/termination contract. Cycles report LOOP_GUARD_UNSUPPORTED; the validator does not interpret arbitrary metadata or expressions as proof of termination. Intentional controlled-loop authoring requires a future frozen loop contract. This is the conservative loop behavior permitted by the phase prompt.

Explicit validation stores append-only scenario_validation_runs with the tested revision and PASS/FAIL. Database storage uses PASS/FAIL per DB §45; synchronous validation-start events represent the transient RUNNING concept from State Machine §23.

Publish locks the aggregate/version, checks expected revision, requires the latest run to be PASS for that revision, locks pinned dependencies, revalidates, then publishes with actor/time and a transactional event/audit. A newer graph/config revision invalidates old validation. Dependency archive after validation also blocks publish.

Repeated publish with the same revision has no duplicate side effects. Optional Idempotency-Key applies to mutations; same key/different normalized payload raises IDEMPOTENCY_CONFLICT. Replay rechecks authorization. State changes, audit, outbox and idempotency completion commit together or roll back.

## Subflows and external references

Canonical scenario_subflows, scenario_subflow_versions and scenario_subflow_bindings persist the pinning baseline. Bindings expose Subflow public UUID plus exact version_no, never internal SubflowVersion IDs. There is no invented Subflow HTTP authoring API. Subflow authoring UI remains deferred.

A binding must reference same-tenant published content, or archived historical content when copying a draft. Published Subflow graph_json is immutable. Publication recursively validates that all required pinned versions remain published and their graphs are valid. Archived pins remain readable/copyable but fail new-publication validation. New Subflow versions cannot retarget an existing binding.

Media/checkpoint/QR/AR/live-challenge definitions validate reference shape but report DEPENDENCY_NOT_READY during semantic validation. Their resource implementations do not exist in this phase, so publication fails closed instead of fabricating asset/resource readiness. No client-provided readiness flag bypasses this.

Random Player declares that TeamScenarioInstance must own and freeze a deterministic seed at future instance creation. This phase creates no seed, runtime instance or random gameplay outcome. The registry contains no runtime handler.

## Simulation

The frozen simulate endpoint persists scenario_simulation_runs and canonical simulation events. Its result explicitly says STRUCTURAL_ONLY and runtime_executed=false. It reports structural reachability, branches, dead ends and validation errors. It does not evaluate gameplay conditions, accept mock runtime state, execute node handlers, mutate variables, score, bill or create Session facts. Full execution simulation remains a Runtime integration responsibility.

## Authorization and isolation

OrganizationAdmin manages source in their own tenant. SuperAdmin intervention requires a reason and is audited. Player and Captain capabilities grant no source access. GameMaster has no content-publish permission. There is no Scenario assignment/content-editor grant model in this phase, so GameMaster source access fails closed rather than treating organization membership or a Game assignment as a Scenario content grant.

ScenarioScope reuses OrganizationScope/OrganizationContext and rechecks fresh account/membership status. Cross-tenant/numeric/unknown IDs return 404. Retained scoped queries and idempotent retries reauthorize. Laravel policies are called by read/mutation controllers and domain services. Archived organizations block mutations.

## Concurrency and events

Mutations lock actor → organization → Scenario → version, then dependency rows. The organization lock also serializes version-number allocation and archive/publish across versions. Optimistic revision tokens reject stale saves. PostgreSQL tests use separate connections, real lock timeouts and post-commit retries for all seven required race categories.

Canonical events are scenario.created/updated/archived.v1; scenario_version.created/validation_started/validation_passed/validation_failed/published/archived/duplicated.v1; scenario_graph.saved/validation_requested/simulation_started/simulation_completed.v1. There are no aliases. Envelopes contain public IDs, actor, tenant and request_id, without graph secrets. All events use the existing transactional outbox and audit infrastructure.

## Scope and authority resolutions

The execution request names this milestone Phase 1.5, Scenario Core, and next Phase 1.6 Runtime. The Dependency Map final addendum orders Scenario Core before Runtime and Billing later. Historical Master numbered Scenario Core Phase 3; no business/API/schema rule was changed to accommodate the current milestone name. Frozen references are preserved.

The API baseline remains docs/specs/03_openapi.yaml as explicitly requested. No competing OpenAPI file was created. Validation storage, condition-group representation and service-only archive follow the higher-priority canonical contracts. Contract deviations: NONE.

The existing production Billing Gate, Scenario Assignment Readiness and Runtime Bootstrap adapters remain unavailable and fail closed. Implementing content templates does not implement Session assignment or make production Start pass.
