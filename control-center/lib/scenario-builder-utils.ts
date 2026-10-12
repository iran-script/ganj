import type { ScenarioEdge, ScenarioNode, ScenarioNodeType } from '@/types/scenario';
import { SCENARIO_NODE_LABELS } from '@/types/scenario';

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

export function nodeSummary(node: ScenarioNode): string {
  const config = node.config;
  if (node.node_type === 'Message') {
    return typeof config.text === 'string' && config.text.trim() ? config.text : 'پیام هنوز تنظیم نشده';
  }
  if (node.node_type === 'GPS / Location') {
    const latitude = typeof config.latitude === 'number' || typeof config.latitude === 'string' ? config.latitude : null;
    const longitude = typeof config.longitude === 'number' || typeof config.longitude === 'string' ? config.longitude : null;
    const radius = typeof config.radius_m === 'number' || typeof config.radius_m === 'string' ? config.radius_m : null;
    return latitude !== null && longitude !== null && radius !== null ? `${latitude}, ${longitude} • شعاع ${radius} متر` : 'نیازمند مختصات و شعاع';
  }
  if (node.node_type === 'QR') return config.trigger_id ? 'QR فعال' : 'نیازمند تنظیم QR';
  if (node.node_type === 'Puzzle') return config.expected_answer ? `پاسخ متنی • ${config.max_attempts ?? 'بدون محدودیت'} تلاش` : 'معما هنوز تنظیم نشده';
  return SCENARIO_NODE_LABELS[node.node_type];
}
