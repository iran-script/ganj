'use client';

import { useState } from 'react';
import type { ScenarioGraph, ScenarioVariable } from '@/types/scenario';
import { NODE_CATALOG, OUTCOME_LABELS, RUNTIME_ROLES } from '@/lib/node-catalog';
import { Button } from '@/components/ui';

export function StructuralSimulation({ graph }: { graph: ScenarioGraph }) {
  const defaults = () => Object.fromEntries((graph.variables as ScenarioVariable[]).map((variable) => [variable.key, variable.default_value]));
  const start = graph.nodes.find((node) => node.node_type === 'Start')?.node_key;
  const [current, setCurrent] = useState(start); const [variables, setVariables] = useState<Record<string, unknown>>(defaults); const [score, setScore] = useState(0); const [role, setRole] = useState('NAVIGATOR'); const [player, setPlayer] = useState('player-1'); const [elapsed, setElapsed] = useState(0); const [steps, setSteps] = useState(0);
  const node = graph.nodes.find((item) => item.node_key === current);
  const outgoing = graph.edges.filter((edge) => edge.from_node === current).sort((a, b) => a.priority - b.priority);
  const advance = (target: string) => {
    if (!node || steps >= graph.nodes.length) return;
    const config = node.config;
    if (node.node_type === 'SetVariable') setVariables({ ...variables, [String(config.variable)]: config.value });
    if (node.node_type === 'RandomPlayer') setVariables({ ...variables, [String(config.variable)]: player });
    if (node.node_type === 'AssignRole') setRole((config.roles as string[])?.[0] ?? role);
    if (node.node_type === 'Score') setScore((value) => value + Number(config.amount) * (config.operation === 'SUBTRACT' ? -1 : 1));
    if (node.node_type === 'Time' && config.mode === 'DELAY') setElapsed((value) => value + Number(config.duration_seconds));
    setCurrent(target); setSteps((value) => value + 1);
  };
  return <section className="nexo-surface mx-5 mt-4 space-y-4 rounded-xl p-5" aria-label="شبیه‌سازی ساختاری"><h2 className="font-bold">پیش‌نمایش ساختاری — اجرای واقعی Runtime نیست</h2><p className="text-sm text-[var(--nexo-muted)]">مسیرها را انتخاب کنید؛ موقعیت، QR، مدرک و پاداش واقعی بررسی یا ثبت نمی‌شوند.</p><div className="grid gap-3 sm:grid-cols-4"><label>نقش<select aria-label="نقش پیش‌نمایش" className="nexo-input" value={role} onChange={(event) => setRole(event.target.value)}>{RUNTIME_ROLES.map((item) => <option key={item}>{item}</option>)}</select></label><label>بازیکن<input aria-label="بازیکن پیش‌نمایش" className="nexo-input" value={player} onChange={(event) => setPlayer(event.target.value)} /></label><label>امتیاز<input aria-label="امتیاز پیش‌نمایش" className="nexo-input" type="number" value={score} onChange={(event) => setScore(Number(event.target.value))} /></label><label>زمان سپری‌شده<input aria-label="زمان پیش‌نمایش" className="nexo-input" type="number" min={0} value={elapsed} onChange={(event) => setElapsed(Number(event.target.value))} /></label></div><div className="rounded-lg border border-[var(--nexo-border)] p-4"><h3 className="font-bold">{node ? `${node.title || NODE_CATALOG[node.node_type]?.label} (${node.node_key})` : 'گره شروع وجود ندارد'}</h3>{node && ['Condition', 'CheckVariable', 'RoleCheck', 'Time'].includes(node.node_type) && <p className="mt-2 text-sm">{String(node.config.operand ?? node.config.variable ?? node.config.role ?? node.config.mode)} {String(node.config.operator ?? '')} {String(node.config.value ?? '')}</p>}<div className="mt-4 flex flex-wrap gap-3">{outgoing.map((edge) => <Button key={edge.edge_key} variant="secondary" disabled={steps >= graph.nodes.length} onClick={() => advance(edge.to_node)}>{String(edge.metadata?.label ?? OUTCOME_LABELS[String(edge.metadata?.outcome)] ?? 'ادامه')} ← {graph.nodes.find((item) => item.node_key === edge.to_node)?.title || edge.to_node}</Button>)}{node?.node_type === 'Finish' && <p role="status">پایان پیش‌نمایش</p>}</div></div><dl className="flex flex-wrap gap-5">{Object.entries(variables).map(([key, value]) => <div key={key}><dt className="text-xs text-[var(--nexo-muted)]">{key}</dt><dd>{String(value)}</dd></div>)}</dl><Button variant="ghost" onClick={() => { setCurrent(start); setVariables(defaults()); setScore(0); setElapsed(0); setSteps(0); }}>شروع دوباره</Button></section>;
}
