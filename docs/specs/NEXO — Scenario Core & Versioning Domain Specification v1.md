NEXO — Scenario Core & Versioning Domain Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define NEXO's scenario/content model before Runtime implementation so that Visual Builder, GPS, QR, AR, puzzle nodes, role mechanics, hints and live gameplay all operate on one stable production architecture.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Phase 0 Foundation  
\- Identity & Device  
\- Organization/Tenant  
\- Team Management  
\- Game & Game Session  
\- Product Blueprint  
\- API Contract  
\- Database Specification  
\- State Machine Specification  
\- Event Catalog  
\- Permission Matrix

Provides upstream capability for:  
\- Scenario Assignment  
\- Runtime Engine  
\- Visual Scenario Builder  
\- GPS/Checkpoint nodes  
\- QR nodes  
\- AR nodes  
\- Puzzle/Answer nodes  
\- Role/Co-op nodes  
\- Hint/Score actions  
\- simulation  
\- validation  
\- version pinning

2\. CORE PRINCIPLE — SCENARIO IS CONTENT, NOT CODE

Scenario-specific behavior must not be implemented as hardcoded controller/service branching.

Adding a new Scenario must not require:  
\- backend deployment  
\- schema rewrite  
\- code change in core runtime

Scenario is represented by:  
\- Scenario  
\- ScenarioVersion  
\- Nodes  
\- Edges  
\- Conditions  
\- Variables  
\- Subflow references  
\- metadata  
\- validation state  
\- versioned dependencies

3\. SCENARIO ENTITY

Scenario is a reusable content container.

Fields:  
\- public\_id  
\- organization\_id  
\- title  
\- description nullable  
\- theme nullable  
\- status  
\- created\_by  
\- created\_at  
\- updated\_at  
\- archived\_at nullable

Scenario status:  
\- DRAFT  
\- PUBLISHED  
\- ARCHIVED

Important:  
The Scenario entity itself is the logical content identity.  
Actual executable content lives in ScenarioVersion.

4\. SCENARIO VERSION

ScenarioVersion is the executable immutable snapshot.

Fields:  
\- public\_id  
\- scenario\_id  
\- version\_no  
\- status  
\- revision\_token  
\- difficulty nullable  
\- estimated\_duration\_seconds nullable  
\- estimated\_distance\_meters nullable  
\- physical\_effort nullable  
\- challenge\_mix jsonb  
\- published\_at nullable  
\- published\_by nullable  
\- timestamps

Version states:  
\- DRAFT  
\- PUBLISHED  
\- ARCHIVED

5\. VERSIONING RULE

DRAFT:  
\- editable  
\- validate/simulate allowed

PUBLISHED:  
\- immutable  
\- executable  
\- assignable to future Sessions unless archived

ARCHIVED:  
\- no longer selected for new assignment  
\- remains readable for historical Sessions

No PUBLISHED \-\> DRAFT.

Any modification after publish:  
\- create a new DRAFT version

6\. REVISION TOKEN

Each editable DRAFT version has revision\_token.

Purpose:  
\- optimistic concurrency  
\- validation freshness  
\- builder save conflict detection

Graph/config edit:  
\- changes revision\_token

Publish requires:  
\- latest validation PASS  
\- validation revision\_token \== current revision\_token

Stale validation cannot authorize publish.

7\. NODE MODEL

ScenarioVersion contains Nodes.

Required fields:  
\- node\_key unique within version  
\- node\_type  
\- title nullable  
\- config  
\- presentation metadata  
\- canvas position metadata optional

node\_key:  
\- stable inside one ScenarioVersion  
\- not a public DB sequence

8\. INITIAL NODE TYPES

Pilot/production baseline Node Types:

Control:  
\- Start  
\- Finish  
\- Branch  
\- Condition  
\- Wait  
\- Timer  
\- Subflow

Presentation:  
\- Message  
\- Show Image  
\- Show Video

Location/Trigger:  
\- GPS / Location  
\- Checkpoint  
\- QR

Puzzle:  
\- Puzzle  
\- Password  
\- Multiple Choice  
\- Text Answer

Co-op:  
\- Assign Roles  
\- Swap Roles  
\- Specific Player  
\- Random Player  
\- Team Together  
\- Split Team

Actions:  
\- Add Score  
\- Subtract Score  
\- Give Hint  
\- Live Challenge Trigger

AR:  
\- AR Challenge

9\. NODE TYPE REGISTRY

Core Runtime must use a Node Type Registry.

Each Node Type contract defines:  
\- type code  
\- config schema  
\- validator  
\- runtime handler  
\- completion contract  
\- presentation metadata  
\- offline classification where applicable  
\- required capability references

No central giant switch with scenario-specific rules.

10\. START NODE

Each published ScenarioVersion must have exactly one Start node.

Validation:  
\- exactly one Start  
\- no incoming edge unless explicitly allowed by future migration  
\- at least one valid outgoing path

11\. FINISH NODE

ScenarioVersion must have at least one reachable Finish.

A Scenario may have multiple terminal outcomes in future, but current baseline requires at least one reachable successful Finish.

12\. EDGES

Fields:  
\- edge\_key  
\- scenario\_version\_id  
\- from\_node\_id  
\- to\_node\_id  
\- priority  
\- condition\_group\_id nullable  
\- metadata

Rules:  
\- no dangling node references  
\- from/to belong to same ScenarioVersion  
\- edge\_key unique inside version  
\- priority deterministic where multiple edges exist

13\. GRAPH VALIDATION

Mandatory validation:  
\- exactly one Start  
\- \>=1 Finish  
\- Finish reachable  
\- no dangling edges  
\- no duplicate node keys  
\- no duplicate edge keys  
\- valid node config  
\- valid condition references  
\- supported node types  
\- valid subflow dependencies  
\- loop policy valid  
\- required assets/dependencies resolvable  
\- role references valid where applicable

14\. LOOP POLICY

Loops are not globally forbidden.

Allowed only where:  
\- explicitly represented  
\- validation can prove termination/guard intent  
\- loop is not an accidental graph cycle

Builder/validator must distinguish:  
\- intended controlled loop  
\- invalid accidental cycle

15\. CONDITION ENGINE

No eval.  
No arbitrary code execution.

Condition is typed and allow-listed.

Condition model supports:  
\- left operand  
\- operator  
\- right operand  
\- value type  
\- group/position

Examples:  
\- score \> 500  
\- attempt\_count \>= 2  
\- player role \== SCANNER  
\- team member count \>= 4  
\- variable value equals expected  
\- checkpoint status verified  
\- elapsed time \< threshold

16\. CONDITION OPERATORS

Initial allow-list may include:  
\- \==  
\- \!=  
\- \>  
\- \>=  
\- \<  
\- \<=  
\- IN  
\- NOT\_IN  
\- EXISTS  
\- NOT\_EXISTS

String-specific or spatial operators must be registered explicitly later, not inferred dynamically.

17\. VARIABLES

Scenario variables are typed.

Fields:  
\- key  
\- type  
\- scope  
\- default\_value  
\- writable

Scopes:  
\- SCENARIO  
\- TEAM  
\- PLAYER  
\- NODE

Initial types:  
\- STRING  
\- INTEGER  
\- DECIMAL  
\- BOOLEAN  
\- DATETIME  
\- JSON for explicit extension-only use

Core relational facts must not be hidden in arbitrary variables.

18\. VARIABLE RULES

Variables:  
\- key unique within version  
\- server-authoritative  
\- write scope explicit  
\- type checked  
\- runtime value separate from template definition

Client cannot directly mutate arbitrary runtime variables.

19\. DYNAMIC CONTENT

Content may reference safe template variables.

Examples:  
\- team.name  
\- player.scanner  
\- game.time\_remaining  
\- scenario variable values

Rendering:  
\- allow-listed placeholders  
\- escaping/sanitization  
\- missing variable behavior defined

No template expression executes code.

20\. RANDOMIZATION

Any gameplay-affecting randomization must be reproducible.

At Team Scenario Instance creation:  
\- random\_seed frozen

Random Player / randomized branch uses:  
\- instance seed  
\- deterministic algorithm

Purpose:  
\- audit  
\- replay  
\- reproducibility

21\. SUBFLOWS

Subflow is reusable scenario content.

Entities:  
\- scenario\_subflows  
\- scenario\_subflow\_versions  
\- scenario\_subflow\_bindings

Subflow versions are immutable after publish.

ScenarioVersion publish pins:  
\- exact SubflowVersion

Future subflow edits cannot alter existing published ScenarioVersion.

22\. SCENARIO ASSIGNMENT

Assignment is not part of Scenario template editing.

Game Session assigns one ScenarioVersion per Team.

Assignment modes:  
\- MANUAL  
\- AUTOMATIC\_SHUFFLE

At Session Start:  
\- assignment pinned  
\- seed pinned  
\- runtime instance created

23\. ANTI-REPEAT ASSIGNMENT

Automatic assignment should support avoiding scenarios previously played by Team when alternatives exist.

This is assignment-service logic, not ScenarioVersion logic.

Fallback:  
If all eligible scenarios were previously played, system may reuse according to configured policy.

24\. FAIRNESS METADATA

ScenarioVersion metadata includes:  
\- difficulty  
\- estimated duration  
\- estimated distance  
\- physical effort  
\- challenge mix

Before Session Start:  
assignment layer may compare selected scenarios and warn on significant imbalance.

Fairness metadata is advisory unless Game policy makes it blocking.

25\. CHALLENGE MIX

Challenge mix may summarize:  
\- GPS  
\- QR  
\- Puzzle  
\- AR  
\- Physical  
\- Co-op  
\- Individual  
\- Live interaction

Used for:  
\- balancing  
\- search/filter  
\- reporting

26\. GPS NODE CONTRACT

Scenario config defines requirements only.

Example:  
\- checkpoint\_id  
\- min\_accuracy  
\- required\_player\_count  
\- dwell\_time  
\- required\_roles

Actual geospatial verification belongs to Location/GPS domain.

Scenario cannot trust client “inside=true”.

27\. QR NODE CONTRACT

Scenario references trigger/checkpoint policy.

QR contains identifier, not answer.

Actual scan:  
\- server validates Session  
\- Team  
\- Player  
\- Device  
\- current active node  
\- optional location  
\- replay protection

28\. AR NODE CONTRACT

Scenario AR node references:  
\- challenge definition  
\- scene  
\- required capabilities  
\- fallback policy

Actual AR completion:  
\- Runtime \+ AR domain verifies evidence/nonce

Client boolean completion is insufficient.

29\. PUZZLE NODE CONTRACT

Puzzle configuration may include:  
\- prompt/content  
\- answer type  
\- normalized expected answer  
\- max attempts nullable  
\- hint references  
\- reward/penalty rules

Sensitive expected answer must never be sent to unauthorized client response.

30\. PASSWORD/TEXT ANSWER

Normalization rules must be explicit:  
\- whitespace  
\- case sensitivity  
\- locale-specific normalization where needed

Do not implement fuzzy acceptance unless configured.

31\. MULTIPLE CHOICE

Config:  
\- options  
\- correct option refs  
\- multi-select boolean if supported  
\- attempt policy

Correct answer references remain server-side.

32\. TIMER NODE

Timer config:  
\- duration  
\- behavior on expire  
\- pause behavior  
\- completion/failure route

Runtime timer uses server timestamps.

33\. ROLE NODES

Assign Roles / Swap Roles operate on runtime role assignments.

Scenario defines:  
\- role codes/capabilities  
\- assignment policy

Runtime enforces:  
\- eligible players  
\- max four active challenge slots where applicable  
\- deterministic rotation/randomness

34\. TEAM TOGETHER

Config may require:  
\- minimum player count  
\- role set  
\- spatial presence  
\- synchronization window

Completion is server-authoritative.

35\. SCORE ACTIONS

Scenario may define score deltas/reasons.

No client-set score.

Runtime emits Score event/action to scoring domain.

36\. HINT ACTIONS

Scenario defines base hints and penalties.

Base Hint limit:  
\- maximum 3 built-in progressive hints per Stage/Node where applicable

Extra paid entitlement belongs to Commerce/Hint domain.

37\. LIVE CHALLENGE TRIGGER

Scenario may request a live challenge trigger.

Live challenge lifecycle belongs to GameMaster/Challenge domain.

Scenario cannot directly mutate target players outside authorized engine flow.

38\. MEDIA REFERENCES

Scenario may reference media assets.

Media dependency must include:  
\- asset reference  
\- version/checksum where relevant

Published Scenario should not silently change due to replacing mutable asset content.

39\. PUBLISH

Publish command:  
1\. lock/version check  
2\. verify DRAFT  
3\. verify current revision\_token  
4\. require latest validation PASS  
5\. verify dependencies  
6\. verify assets  
7\. freeze version  
8\. set PUBLISHED  
9\. event  
10\. audit

40\. PUBLISH EVENT

scenario\_version.published.v1

Payload includes at minimum:  
\- scenario\_id  
\- scenario\_version\_id  
\- version\_no  
\- revision\_token  
\- published\_by  
\- dependency versions  
\- asset refs/manifest refs where applicable

41\. ARCHIVE

Published version may be ARCHIVED.

Archive:  
\- prevents new assignment  
\- does not break existing Session/runtime references

42\. DUPLICATE

ScenarioVersion may be duplicated into a new DRAFT.

Duplicate:  
\- new IDs  
\- same logical graph/config snapshot  
\- independent revision\_token  
\- dependencies revalidated before future publish

43\. SIMULATION

Simulation is non-production runtime.

Purpose:  
\- test branches  
\- mock inputs  
\- detect dead ends  
\- inspect variable transitions

Simulation must not create real billing/location/score facts.

44\. VISUAL BUILDER BOUNDARY

Visual Builder is presentation/editor UI.

Backend remains authoritative.

Builder must:  
\- load graph  
\- save draft graph  
\- use revision\_token  
\- request validation  
\- request simulation  
\- publish explicitly

Builder cannot bypass validation.

45\. API CONTRACT

Canonical endpoints:

POST /api/v1/scenarios  
GET /api/v1/scenarios  
GET /api/v1/scenarios/{scenario\_id}  
PATCH /api/v1/scenarios/{scenario\_id}

POST /api/v1/scenarios/{scenario\_id}/versions  
GET /api/v1/scenarios/{scenario\_id}/versions

GET /api/v1/scenario-versions/{version\_id}  
PATCH /api/v1/scenario-versions/{version\_id}

POST /api/v1/scenario-versions/{version\_id}/validate  
POST /api/v1/scenario-versions/{version\_id}/publish  
POST /api/v1/scenario-versions/{version\_id}/duplicate  
POST /api/v1/scenario-versions/{version\_id}/simulate

GET /api/v1/scenario-versions/{version\_id}/graph  
PUT /api/v1/scenario-versions/{version\_id}/graph

46\. GRAPH SAVE

PUT graph:  
\- DRAFT only  
\- revision token required  
\- optimistic concurrency  
\- full graph validation at save level for structural integrity  
\- semantic full validation may remain explicit validate command

Stale revision:  
\- conflict  
\- no silent overwrite

47\. AUTHORIZATION

Scenario create:  
\- OrganizationAdmin  
\- explicit Content Editor capability if introduced  
\- SuperAdmin

Read draft:  
\- authorized organization content users  
\- assigned GameMaster only if explicitly permitted

Read published:  
\- Admin/GameMaster  
\- Player only runtime-safe projection, never full secret source

Update draft:  
\- authorized content role

Publish:  
\- OrganizationAdmin or explicit Publisher capability

GameMaster:  
\- publish denied by default

Cross-tenant:  
\- deny/not found

48\. PLAYER DATA EXPOSURE

Player must never receive:  
\- correct puzzle answers  
\- hidden branch conditions  
\- unrevealed nodes  
\- secret variables  
\- future content not unlocked  
\- admin-only metadata

Runtime API produces safe node projection.

49\. EVENTS

Canonical:  
scenario.created.v1  
scenario.updated.v1  
scenario.archived.v1  
scenario\_version.created.v1  
scenario\_version.validation\_started.v1  
scenario\_version.validation\_passed.v1  
scenario\_version.validation\_failed.v1  
scenario\_version.published.v1  
scenario\_version.archived.v1  
scenario\_version.duplicated.v1  
scenario\_graph.saved.v1  
scenario\_graph.validation\_requested.v1  
scenario\_graph.simulation\_started.v1  
scenario\_graph.simulation\_completed.v1

50\. AUDIT

Audit:  
\- publish  
\- archive  
\- privileged content changes  
\- ownership transfer  
\- dependency override if ever supported

Draft autosaves need not create full human audit record for every minor edit if domain event/revision history covers it, but publish history must be complete.

51\. DATABASE CONSTRAINTS

Critical:  
\- unique(scenario\_id, version\_no)  
\- unique(scenario\_version\_id, node\_key)  
\- unique(scenario\_version\_id, edge\_key)  
\- valid FK node references  
\- revision\_token present  
\- published immutability via service/policy and optional defensive DB strategy

52\. CONCURRENCY

Hotspots:  
\- graph save  
\- duplicate  
\- publish  
\- archive

Publish/save race prevented with:  
\- revision\_token  
\- transaction  
\- row lock or optimistic concurrency

53\. IDEMPOTENCY

Publish:  
\- duplicate identical publish request should not produce duplicate version/event

Duplicate:  
\- idempotency recommended for retryable admin API

Validate/simulate:  
\- each run may create new run record, but client retry semantics must not corrupt Version.

54\. PILOT v0.1

Pilot must use real ScenarioVersion architecture.

Pilot may simplify authoring:  
\- seed  
\- JSON import  
\- basic admin forms

Pilot must NOT:  
\- hardcode route in Laravel controller  
\- store whole scenario as unvalidated opaque JSON only  
\- skip versioning  
\- mutate published scenario  
\- let client determine next node

55\. PILOT NODE SET

Pilot release required:  
\- Start  
\- Finish  
\- Message  
\- GPS/Checkpoint  
\- QR  
\- Puzzle  
\- Password  
\- Multiple Choice  
\- Timer  
\- Assign Roles  
\- Team Together  
\- Branch/Condition  
\- Add/Subtract Score  
\- Hint

May defer UI/handler:  
\- Advanced AR  
\- Split Team advanced  
\- Subflow authoring UI  
\- Live Challenge advanced

Architecture contracts remain.

56\. TEST REQUIREMENTS

Versioning:  
\- create draft  
\- publish valid version  
\- published immutable  
\- edit creates new draft  
\- archived published remains readable

Validation:  
\- no Start  
\- two Starts  
\- no Finish  
\- unreachable Finish  
\- dangling edge  
\- duplicate key  
\- unknown node type  
\- invalid config  
\- invalid condition  
\- invalid subflow dependency  
\- accidental cycle

Concurrency:  
\- stale revision rejected  
\- simultaneous save conflict safe  
\- publish against stale validation rejected

Security:  
\- cross-tenant denied  
\- player cannot fetch answers  
\- player cannot fetch unrevealed graph  
\- GameMaster publish denied by default

Random:  
\- same seed reproducible

Dependencies:  
\- pinned subflow version remains stable  
\- published asset/dependency references stable

57\. DEPENDENCY FOR RUNTIME

Runtime Engine may begin only after:  
\- ScenarioVersion lifecycle frozen  
\- Node Type Registry contract frozen  
\- graph validation model frozen  
\- variable/condition model frozen  
\- published immutability frozen  
\- scenario assignment pinning rule frozen  
\- safe player projection rule frozen

58\. DEFINITION OF DONE

Scenario Core design is frozen when:  
\- Scenario vs ScenarioVersion explicit  
\- published immutability explicit  
\- revision concurrency explicit  
\- graph/node/edge contracts explicit  
\- Node Type Registry explicit  
\- variables/conditions typed and safe  
\- deterministic randomization explicit  
\- subflow pinning explicit  
\- validation/publish flow explicit  
\- permissions/events/audit defined  
\- pilot uses same production Scenario architecture  
\- Runtime dependency contract complete  
