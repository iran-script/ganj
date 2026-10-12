# NEXO Office Backend Gaps

This document records frontend-only placeholders added while backend verification is unavailable in the office environment. It contains no credentials or production connection details.

| Feature | Frontend need | Current gap | Home verification required |
| --- | --- | --- | --- |
| Reports analytics | Completion rates, score distributions, durations, team results | Current client exposes games and sessions, but no aggregate analytics contract | Confirm Laravel report endpoints, organization scoping, pagination and canonical calculations |
| Session report detail | Final score, duration, completion summary and warnings | Session detail and timeline are available, but no dedicated canonical final-results payload is wired | Confirm finalized-session result endpoint and immutable result fields |
| Standalone team report | Team progress, members, score and timeline independent of a session list | No dedicated team-report endpoint; the UI can only read teams embedded in session payloads | Confirm organization-scoped team report contract and authorization |
| Scenario-version metadata | Show pinned published version in game/session review and reports | Existing list/detail payloads do not consistently expose version number, publish state and author metadata | Confirm canonical scenario-version relation fields in game/session responses |
| Pricing details | Review FREE/PAID mode and supported amount/currency fields | Basic game pricing is supported; payment-provider state and settlement details are not part of the frontend contract | Confirm supported pricing fields and explicitly document payment-provider availability |
| Users | Searchable operator/user list with organization context | Only current-user (`/me`) is currently used by the Control Center | Confirm user listing endpoint, filters, pagination and authorization policy |
| Roles / permissions | Read-only role and permission presentation, later safe management | No dedicated role/permission payload is wired in the current client | Confirm policy-aware endpoints and SuperAdmin/operator boundaries |
| Devices | Device identity, platform, readiness and last-seen data | No Control Center device listing contract is currently exposed | Confirm device endpoint, tenant scope and supported status fields |
| Audit log | Actor/action/entity timeline with metadata | No generic audit listing endpoint is currently wired | Confirm audit endpoint, retention, pagination and sensitive-field redaction |
| Content workflows | Media/assets, message templates and puzzle assets | Checkpoints and QR triggers exist; broader content APIs are not exposed | Confirm canonical asset/template models, upload flow and organization scope |
| System health detail | Database, Redis, queue, scheduler and outbox indicators | Current health contract provides overall status and optional checks only | Confirm health payload names, refresh policy and operator visibility |

These gaps are intentionally left as UI states. No backend behavior, migrations, runtime semantics or authorization rules were changed in the office slice.
