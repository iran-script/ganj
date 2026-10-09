# RTK.md — Urban AR Team Adventure Game

Status: AUTHORITATIVE LOCAL SPEC INDEX

## Required read order
1. 01_Product_Blueprint_v1.md
2. 02_API_Contract_v1.md
3. 03_openapi.yaml
4. 04_Database_Data_Model_Spec_v1.md
5. 05_State_Machine_Spec_v1.md
6. 06_Event_Catalog_Spec_v1.md
7. 07_Permission_Matrix_Spec_v1.md
8. 08_Codex_Master_Implementation_Spec_v1.md
9. 09_Codex_Phase_0_Execution_Prompt.md

## Authority precedence
1. Business/domain rules → Product Blueprint
2. HTTP semantics → API Contract
3. Machine-readable paths/schemas → OpenAPI
4. Schema/constraints/indexes → Database Specification
5. State transitions → State Machine Specification
6. Events → Event Catalog
7. Authorization/scopes → Permission Matrix
8. Engineering sequence/gates → Codex Master Implementation Specification
9. Current task scope → Phase Execution Prompt

If there is a true conflict, report the exact files and sections. Do not invent a new rule.

## Current scope
Phase 0 — Foundation only.

## Workspace placement
Copy this whole directory to:
C:\Users\HadNegaran\Desktop\ganj\docs\specs\
