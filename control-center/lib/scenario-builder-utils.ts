import type { ScenarioEdge, ScenarioNode, ScenarioNodeType } from '@/types/scenario';

export function defaultScenarioConfig(type: ScenarioNodeType): Record<string, unknown> {
  if (type === 'Message') return { text: '' };
  if (type === 'Puzzle') return { prompt: '', expected_answer: '', case_sensitive: false, trim_whitespace: true };
  if (type === 'GPS / Location') return { latitude: '', longitude: '', radius_m: '', accuracy_m: '', dwell_seconds: 0 };
  return {};
}

export function hasValidEdgeReferences(nodes: ScenarioNode[], edges: ScenarioEdge[]): boolean {
  const keys = new Set(nodes.map((node) => node.node_key));
  return edges.every((edge) => edge.from_node !== edge.to_node && keys.has(edge.from_node) && keys.has(edge.to_node));
}

export function canPublish(validation?: { status?: string }, versionStatus = 'DRAFT'): boolean {
  return versionStatus === 'DRAFT' && validation?.status === 'PASS';
}
