import type { ScenarioEdge, ScenarioNode, ScenarioNodeType, ScenarioValidation } from '@/types/scenario';
import { SCENARIO_NODE_LABELS } from '@/types/scenario';
import { NODE_CATALOG } from '@/lib/node-catalog';

export function defaultScenarioConfig(type: ScenarioNodeType): Record<string, unknown> {
  return structuredClone(NODE_CATALOG[type].defaults);
}

export function hasValidEdgeReferences(nodes: ScenarioNode[], edges: ScenarioEdge[]): boolean {
  const keys = new Set(nodes.map((node) => node.node_key));
  return edges.every((edge) => edge.from_node !== edge.to_node && keys.has(edge.from_node) && keys.has(edge.to_node));
}

export function canPublish(validation?: { status?: string }, versionStatus = 'DRAFT'): boolean {
  return versionStatus === 'DRAFT' && validation?.status === 'PASS';
}

export function nodeValidation(nodeKey: string, nodes: ScenarioNode[], edges: ScenarioEdge[], validation?: ScenarioValidation): 'error' | 'warning' | 'ok' | undefined {
  if (!validation) return undefined;
  const applies = ({ path }: { path: string }) => {
    const [collection, key] = path.split('.');
    if (collection === 'nodes') return key === nodeKey || (/^\d+$/.test(key ?? '') && nodes[Number(key)]?.node_key === nodeKey);
    if (collection === 'edges' && /^\d+$/.test(key ?? '')) {
      const edge = edges[Number(key)];
      return edge?.from_node === nodeKey || edge?.to_node === nodeKey;
    }
    return false;
  };
  if (validation.errors.some(applies)) return 'error';
  if (validation.warnings.some(applies)) return 'warning';
  return validation.status === 'PASS' ? 'ok' : undefined;
}

export function nodeSummary(node: ScenarioNode): string {
  const config = node.config;
  if (node.node_type === 'Time') return config.mode === 'ELAPSED_CHECK' ? `زمان ${config.operator} ${config.value}` : config.mode === 'DELAY' ? `تأخیر ${config.duration_seconds} ثانیه` : config.mode === 'TIMEOUT' ? `مهلت ${config.duration_seconds} ثانیه` : `بازه زمانی تا ${config.ends_at ?? '—'}`;
  if (node.node_type === 'Condition') return `${config.operand} ${config.operator} ${config.value}`;
  if (node.node_type === 'SetVariable' || node.node_type === 'CheckVariable') return `${config.variable || 'متغیر'} ${config.operator ?? '='} ${config.value}`;
  if (node.node_type === 'Score') return `${config.operation} ${config.amount}`;
  if (node.node_type === 'RoleCheck') return String(config.role ?? 'نقش مشخص نشده');
  if (node.node_type === 'RandomPlayer') return 'یک بازیکن تصادفی';
  if (node.node_type === 'AssignRole' || node.node_type === 'Relay') return (config.roles as string[] ?? []).join(' ← ');
  if (node.node_type === 'PhotoSubmission') return `۱ عکس • ${config.approval_mode === 'AUTO_ACCEPT' ? 'پذیرش خودکار' : 'تأیید GameMaster'}`;
  if (node.node_type === 'AudioSubmission') return `حداکثر ${config.max_duration_seconds} ثانیه`;
  if (node.node_type === 'FileSubmission') return `${(config.allowed_mime_types as string[] ?? []).map((mime) => mime.split('/')[1]).join('/')} • ${config.max_size_mb}MB`;
  if (node.node_type === 'Message') {
    return typeof config.text === 'string' && config.text.trim() ? config.text : 'پیام هنوز تنظیم نشده';
  }
  if (node.node_type === 'GPS / Location') {
    const latitude = typeof config.latitude === 'number' || typeof config.latitude === 'string' ? config.latitude : null;
    const longitude = typeof config.longitude === 'number' || typeof config.longitude === 'string' ? config.longitude : null;
    const radius = typeof config.radius_m === 'number' || typeof config.radius_m === 'string' ? config.radius_m : null;
    return latitude !== null && longitude !== null && radius !== null ? `${latitude}, ${longitude} • شعاع ${radius} متر` : 'نیازمند مختصات و شعاع';
  }
  if (node.node_type === 'QR') return config.trigger_id ? node.title || 'QR انتخاب‌شده' : 'نیازمند تنظیم QR';
  if (node.node_type === 'Puzzle') return config.expected_answer ? `پاسخ متنی • ${config.max_attempts ?? 'بدون محدودیت'} تلاش` : 'معما هنوز تنظیم نشده';
  return SCENARIO_NODE_LABELS[node.node_type];
}
