export type ScenarioStatus = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
export type ScenarioNodeType = 'Start' | 'Message' | 'GPS / Location' | 'QR' | 'Puzzle' | 'Finish';

export type Scenario = { id: string; organization_id?: string; title: string; description?: string | null; theme?: string | null; status: ScenarioStatus; created_at?: string; updated_at?: string; archived_at?: string | null };
export type ScenarioVersion = { id: string; scenario_id: string; version_no: number; status: ScenarioStatus; revision_token: string; difficulty?: string | null; estimated_duration_seconds?: number | null; estimated_distance_meters?: number | null; physical_effort?: string | null; published_at?: string | null; published_by?: string | null; created_at?: string; updated_at?: string };
export type ScenarioNode = { node_key: string; node_type: ScenarioNodeType; title?: string | null; config: Record<string, unknown>; position_x?: number | null; position_y?: number | null };
export type ScenarioEdge = { edge_key: string; from_node: string; to_node: string; priority: number; condition_group_id?: string | null; metadata?: Record<string, unknown> };
export type ScenarioGraph = { nodes: ScenarioNode[]; edges: ScenarioEdge[]; variables: unknown[]; conditions: unknown[]; subflows: unknown[] };
export type ScenarioValidation = { status: 'PASS' | 'FAIL'; errors: Array<{ code: string; path: string }>; warnings: Array<{ code: string; path: string }>; revision_token?: string };
export type ScenarioGraphResponse = { revision_token: string; graph: ScenarioGraph; validation?: ScenarioValidation; randomization?: Record<string, unknown> };
export type ScenarioSimulation = ScenarioValidation & { mode: 'STRUCTURAL_ONLY'; reachable_nodes: string[]; branches: string[]; dead_ends: string[]; runtime_executed: false; revision_token: string };

export const SCENARIO_NODE_LABELS: Record<ScenarioNodeType, string> = { Start: 'شروع', Message: 'پیام', 'GPS / Location': 'نقطه مکانی', QR: 'اسکن QR', Puzzle: 'معما', Finish: 'پایان' };
export const SCENARIO_NODE_ICONS: Record<ScenarioNodeType, string> = { Start: '▶', Message: '▤', 'GPS / Location': '⌖', QR: '▣', Puzzle: '?', Finish: '■' };
