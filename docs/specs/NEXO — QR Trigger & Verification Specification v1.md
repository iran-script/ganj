NEXO — QR Trigger & Verification Specification v1

STATUS  
FROZEN DOMAIN BASELINE

Purpose:  
Define QR as a secure, server-authoritative trigger mechanism integrated with Runtime, Device Binding, Session context and optional GPS evidence. QR must never become a trusted answer carrier or client-authoritative gameplay shortcut.

1\. ARCHITECTURAL POSITION

Depends on:  
\- Phase 0 Foundation  
\- Identity & Player Device  
\- Organizations/Tenant  
\- Team Management  
\- Game & Game Session  
\- Scenario Core  
\- Runtime Engine  
\- GPS / Location / Checkpoint  
\- API Contract  
\- Database Specification  
\- Event Catalog  
\- Permission Matrix

Provides upstream capability for:  
\- QR Scenario Nodes  
\- dynamic trigger resolution  
\- one-time challenge tokens  
\- location-aware QR validation  
\- anti-replay  
\- anti-cheat QR signals  
\- GameMaster diagnostics

2\. CORE PRINCIPLE

QR is a trigger identifier, not the answer.

QR payload must not contain:  
\- final answer  
\- secret puzzle value  
\- next node id trusted by client  
\- score value  
\- direct completion authority

QR payload may contain:  
\- opaque trigger identifier  
\- public non-sensitive metadata if needed  
\- version marker if required

Server resolves actual behavior.

3\. DYNAMIC RESOLUTION

Same physical QR may resolve differently by:  
\- Game Session  
\- Team  
\- Player  
\- ScenarioVersion  
\- current Runtime Node  
\- Role  
\- Device  
\- recent GPS/checkpoint evidence  
\- time window

This dynamic behavior is mandatory.

4\. QR TRIGGER MODEL

Canonical qr\_triggers fields:  
\- public\_id  
\- organization\_id  
\- identifier\_hash  
\- checkpoint\_id nullable  
\- status  
\- metadata  
\- created\_at

Status:  
\- ACTIVE  
\- INACTIVE  
\- REVOKED

Rules:  
\- raw identifier should not be stored unnecessarily  
\- secure hash preferred  
\- identifier unique  
\- historical references preserved

5\. IDENTIFIER

QR identifier must be:  
\- opaque  
\- non-sequential  
\- hard to guess  
\- unique

It must not encode:  
\- internal database IDs  
\- hidden scenario state  
\- direct secret content

6\. QR SCAN API

Canonical:  
POST /api/v1/qr/scan

Auth:  
\- required

Device Binding:  
\- required during active gameplay

Request:  
\- session\_id  
\- qr\_identifier  
\- optional location evidence/context if recent verified location not available

Server resolves:  
\- organization  
\- Session  
\- Team  
\- Player  
\- bound Device  
\- Runtime Node  
\- QR Trigger  
\- checkpoint/location policy  
\- role eligibility

7\. SERVER VALIDATION PIPELINE

Scan flow:  
1\. authenticate  
2\. resolve registered/bound Device  
3\. resolve Session and Player participation  
4\. verify Session RUNNING  
5\. resolve TeamScenarioInstance  
6\. resolve active/relevant Node  
7\. resolve QR trigger by secure identifier  
8\. verify organization ownership/scope  
9\. verify trigger status  
10\. verify Node expects/permits this trigger  
11\. verify role/player policy  
12\. verify rate limit  
13\. verify location/geofence if required  
14\. verify anti-replay state  
15\. emit scan event  
16\. resolve dynamic response  
17\. issue one-time token when sensitive completion/next action needs protection

8\. RUNTIME CONTEXT

QR scan is valid only when current Runtime context permits it.

Possible node policies:  
\- any active Team member  
\- specific Player  
\- specific role  
\- active slot only  
\- Captain not inherently special

Wrong context:  
\- ACTION\_NOT\_ALLOWED  
\- NODE\_NOT\_ACTIVE  
\- WRONG\_PLAYER\_ROLE or canonical equivalent

9\. QR \+ CHECKPOINT

A QR Trigger may reference Checkpoint.

If Scenario/Node requires spatial validation:  
\- recent verified GPS evidence must place Player within allowed checkpoint policy

QR domain does not recalculate geofence itself.  
It consumes Location/Checkpoint verification.

10\. RECENT LOCATION EVIDENCE

QR policy may require location evidence not older than configured threshold.

If latest location is:  
\- stale  
\- inaccurate  
\- absent  
then scan may request current location evidence or reject.

11\. LOCATION OPTIONALITY

Not every QR requires geofence.

Examples:  
\- indoor controlled venue may not require GPS  
\- remote/admin QR may use alternate verification  
\- city checkpoint usually should combine QR \+ GPS for stronger trust

Policy is scenario/config driven.

12\. ONE-TIME TOKEN

Sensitive successful scan may issue short-lived one-time token.

Token characteristics:  
\- opaque  
\- random/signed  
\- Session-bound  
\- Team-bound  
\- Player-bound where applicable  
\- Node-bound  
\- Trigger-bound  
\- expires\_at  
\- single-use

13\. TRIGGER TOKEN MODEL

Canonical fields:  
\- token\_hash  
\- game\_session\_id  
\- team\_id  
\- player\_id  
\- node\_instance\_id nullable  
\- trigger\_type  
\- expires\_at  
\- consumed\_at nullable  
\- created\_at

Unique:  
\- token\_hash

14\. TOKEN STATES

Conceptual:  
\- ISSUED  
\- CONSUMED  
\- EXPIRED  
\- REVOKED

Terminal:  
\- CONSUMED  
\- EXPIRED  
\- REVOKED

15\. TOKEN CONSUMPTION

Atomic consume:  
\- update only if consumed\_at IS NULL  
\- current time \<= expires\_at  
\- context matches Session/Team/Player/Node

Replay:  
\- rejected  
\- event emitted  
\- anti-cheat signal optional

16\. REPLAY PROTECTION

Protect against:  
\- same token reused  
\- copied QR result reused by another Team  
\- copied token used by another Player  
\- expired token  
\- old Session token  
\- token from previous Node

17\. QR PHYSICAL REUSE

Same physical QR may be reusable across:  
\- different Sessions  
\- different Teams  
\- different Scenarios  
\- different time periods

Security depends on server context, not secrecy of physical QR alone.

18\. SCAN EVENT MODEL

Canonical qr\_scan\_events fields:  
\- game\_session\_id  
\- team\_id  
\- player\_id  
\- qr\_trigger\_id nullable  
\- node\_instance\_id nullable  
\- location\_event\_id nullable  
\- result  
\- reason\_code nullable  
\- occurred\_at

19\. SCAN RESULT

Possible logical results:  
\- ACCEPTED  
\- REJECTED  
\- RESOLVED  
\- TOKEN\_ISSUED  
\- REPLAY\_DETECTED

Exact persistence enum may differ but semantics remain.

20\. REJECTION REASONS

Canonical/frozen reason concepts:  
\- INVALID\_IDENTIFIER  
\- NODE\_NOT\_ACTIVE  
\- OUTSIDE\_ALLOWED\_AREA  
\- DEVICE\_NOT\_BOUND  
\- RATE\_LIMIT  
\- REPLAY  
\- WRONG\_PLAYER\_ROLE  
\- SESSION\_NOT\_RUNNING  
\- TRIGGER\_INACTIVE

Use canonical API error codes where already defined.

21\. QR EVENTS

Canonical:  
\- qr.scanned.v1  
\- qr.scan\_rejected.v1  
\- qr.trigger\_resolved.v1  
\- qr.token\_issued.v1  
\- qr.token\_consumed.v1  
\- qr.token\_replay\_detected.v1

22\. RUNTIME INTEGRATION

QR scan success does not always mean Node completion.

Possible outcomes:  
\- reveal content  
\- issue token  
\- submit evidence to Runtime  
\- unlock secondary action  
\- verify checkpoint  
\- complete QR Node if handler policy satisfied

Runtime decides final progression.

23\. PLAYER SAFE RESPONSE

Response may include:  
\- trigger\_type  
\- runtime action reference  
\- safe content  
\- one\_time\_token  
\- expires\_at  
\- next allowed interaction

Must not include:  
\- answer  
\- hidden graph  
\- secret branch condition  
\- raw internal IDs

24\. RATE LIMITING

Dedicated QR rate limit.

Protect against:  
\- scan burst  
\- brute-force identifier guessing  
\- repeated wrong QR scans

Rate limit must not block legitimate normal scan retries after camera issues.

25\. ANTI-CHEAT SIGNALS

Flag possibilities:  
\- scan burst  
\- wrong-stage repeated scans  
\- impossible location  
\- token replay  
\- cross-team token attempt  
\- copied stale token  
\- repeated unknown identifiers

Signal \!= automatic guilt.

26\. DEVICE BINDING

Scan requires correct bound Device during active Session.

Wrong Device:  
\- DEVICE\_NOT\_BOUND

Rebound Device:  
\- allowed after valid audited rebind

27\. ROLE POLICY

Node config may specify:  
\- ANY\_TEAM\_MEMBER  
\- ACTIVE\_SLOT  
\- SPECIFIC\_ROLE  
\- SPECIFIC\_PLAYER

Runtime role truth is authoritative.

28\. SPECIFIC PLAYER

If Node selects random/specific Player:  
\- scan accepted only for assigned Player  
\- deterministic assignment already stored in Runtime

29\. TEAM TOGETHER / MULTI-SCAN

Future/advanced QR node may require multiple Players scanning separate triggers.

Architecture must support:  
\- multiple distinct contributions  
\- barrier completion  
\- idempotent contribution records

Pilot may use single-scan QR.

30\. STATIC QR MANAGEMENT

Admin/content operator can create/manage triggers.

QR lifecycle:  
\- create  
\- activate  
\- deactivate  
\- revoke

Revocation preserves history.

31\. QR PRINTING

Operational QR label may show:  
\- branding  
\- visible short label  
\- instructions  
\- QR code

Visible label is not security credential.

32\. VERSIONING

Physical QR trigger identity may outlive Scenario versions.

Scenario Version references trigger/checkpoint policy.

Published Scenario does not need QR regenerated unless trigger itself changes/revokes.

33\. ORGANIZATION SCOPE

QR Trigger belongs to Organization.

Scenario/Game/Session cannot silently use trigger from another Organization.

Cross-tenant access:  
\- deny/not found

34\. API PERMISSIONS

qr.scan:  
\- eligible Player  
\- bound Device

qr.manage:  
\- OrganizationAdmin/content editor

qr.view\_raw\_identifiers:  
\- restricted operational/content scope

qr.override:  
\- GameMaster exceptional only if explicitly implemented  
\- audited

35\. ADMIN DIAGNOSTICS

GameMaster may view:  
\- scan result  
\- Player/Team  
\- timestamp  
\- reason code  
\- checkpoint/location evidence summary  
\- replay flags

Do not expose sensitive raw token material.

36\. TOKEN STORAGE

Store token hash, not raw token, where possible.

Raw token only returned once at issuance.

37\. ERROR CONTRACT

Relevant:  
\- QR\_INVALID  
\- QR\_REPLAYED  
\- QR\_OUTSIDE\_ALLOWED\_AREA  
\- NODE\_NOT\_ACTIVE  
\- DEVICE\_NOT\_BOUND  
\- ACTION\_NOT\_ALLOWED  
\- RATE\_LIMITED

38\. OFFLINE POLICY

Sensitive QR scan is normally ONLINE\_REQUIRED.

Reason:  
\- dynamic server resolution  
\- replay protection  
\- current Runtime validation

Pilot should not implement fake offline QR success.

Possible future offline-signed triggers require separate explicit security design.

39\. PILOT v0.1

Required:  
\- QR trigger table  
\- secure identifier resolution  
\- scan endpoint  
\- Device validation  
\- Runtime Node validation  
\- optional GPS check  
\- one-time token  
\- replay protection  
\- scan event logging

May defer:  
\- multi-QR barrier  
\- advanced printed QR admin UI  
\- offline QR  
\- advanced anti-cheat review dashboard

40\. CONCURRENCY

Protect:  
\- token consume  
\- simultaneous scans same token  
\- simultaneous completion submissions  
\- max one Node completion side effect

41\. IDEMPOTENCY

Scan may be repeated due camera/network retry.

Requirements:  
\- same accepted scan must not duplicate Node completion  
\- token issuance should be stable/idempotent within configured command identity when applicable

42\. TESTS — VALID SCAN

\- active Node \+ valid QR accepted  
\- correct Team/Player/Device  
\- response safe

43\. TESTS — INVALID IDENTIFIER

\- unknown identifier rejected  
\- no resource leakage  
\- rate limiting applies

44\. TESTS — RUNTIME

\- wrong Node rejected  
\- locked/future Node cannot be bypassed  
\- QR cannot choose next Node  
\- terminal node duplicate scan does not re-complete

45\. TESTS — DEVICE

\- bound Device accepted  
\- unbound/wrong Device rejected  
\- revoked Device rejected  
\- valid rebind works

46\. TESTS — LOCATION

\- in required area accepted  
\- outside rejected  
\- stale location rejected/request refresh  
\- poor accuracy handled according GPS policy  
\- QR without GPS requirement works without geofence

47\. TESTS — TOKEN

\- issued token valid once  
\- replay rejected  
\- expired token rejected  
\- other Team rejected  
\- other Player rejected where bound  
\- other Session rejected

48\. TESTS — SECURITY

\- raw internal ID in QR cannot bypass lookup  
\- brute-force/rate behavior  
\- cross-tenant trigger denied  
\- raw token not persisted/logged  
\- answer not leaked

49\. TESTS — EVENTS

\- scanned event  
\- rejected event  
\- token issued  
\- token consumed  
\- replay event

50\. DEPENDENCY FOR CO-OP / SCORE

Co-op may use QR contributions after:  
\- Player/Role validation stable  
\- token context stable

Scoring may consume completion event only after Runtime confirms success.

51\. DEFINITION OF DONE

QR design is frozen when:  
\- QR trigger-only principle explicit  
\- dynamic server resolution explicit  
\- Session/Team/Player/Device context explicit  
\- Runtime context explicit  
\- optional GPS dependency explicit  
\- one-time token lifecycle explicit  
\- replay protection explicit  
\- events/errors/permissions explicit  
\- offline policy explicit  
\- anti-cheat boundary explicit  
\- Pilot uses same production architecture  
