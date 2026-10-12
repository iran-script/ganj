'use client';

import '@xyflow/react/dist/style.css';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { addEdge, Background, Controls, Handle, MarkerType, MiniMap, Position, ReactFlow, ReactFlowProvider, useEdgesState, useNodesState, useReactFlow, type Connection, type Edge, type Node, type NodeProps } from '@xyflow/react';
import { Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import { ApiErrorResponse } from '@/lib/api-error';
import { canPublish, defaultScenarioConfig, nodeSummary } from '@/lib/scenario-builder-utils';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import type { ScenarioGraph, ScenarioNode, ScenarioNodeType, ScenarioSimulation, ScenarioValidation } from '@/types/scenario';
import { SCENARIO_NODE_ICONS, SCENARIO_NODE_LABELS } from '@/types/scenario';

const palette: ScenarioNodeType[] = ['Start', 'Message', 'GPS / Location', 'QR', 'Puzzle', 'Finish'];
const optionalNumber = (schema: z.ZodNumber) => z.preprocess((value) => value === '' ? undefined : value, schema.optional());
const nodeSchema = z.object({
  title: z.string().max(255, 'عنوان بیش از حد طولانی است'),
  text: z.string().max(10000, 'متن بیش از حد طولانی است').optional(),
  prompt: z.string().max(10000).optional(), expected_answer: z.string().max(1000).optional(),
  latitude: optionalNumber(z.number().min(-90).max(90)), longitude: optionalNumber(z.number().min(-180).max(180)),
  radius_m: optionalNumber(z.number().positive().max(100000)), accuracy_m: optionalNumber(z.number().positive().max(1000)),
  dwell_seconds: optionalNumber(z.number().int().min(0).max(86400)), max_attempts: optionalNumber(z.number().int().min(1).max(1000)),
  trigger_id: z.string().uuid('شناسه QR باید UUID باشد').optional().or(z.literal('')), case_sensitive: z.boolean().optional(), trim_whitespace: z.boolean().optional(),
});
type NodeForm = z.infer<typeof nodeSchema>;
type BuilderNodeData = { node: ScenarioNode; validation?: 'error' | 'warning' | 'ok' };

function NexoNode({ data, selected }: NodeProps<Node<BuilderNodeData>>) {
  const node = data.node;
  const tone = data.validation === 'error' ? 'border-[var(--nexo-danger)]' : data.validation === 'warning' ? 'border-[var(--nexo-warning)]' : selected ? 'border-[var(--nexo-amber)]' : 'border-[var(--nexo-border)]';
  return <div role="group" aria-label={`${SCENARIO_NODE_LABELS[node.node_type]}: ${node.title || SCENARIO_NODE_LABELS[node.node_type]}`} className={`min-w-[210px] rounded-xl border bg-[var(--nexo-elevated)] p-3 shadow-[var(--nexo-shadow)] ${selected ? 'ring-2 ring-[var(--nexo-amber)]/25' : ''} ${tone}`}>
    <Handle type="target" position={Position.Left} className="!h-2.5 !w-2.5 !border-2 !border-[var(--nexo-elevated)] !bg-[var(--nexo-info)]" />
    <div className="flex items-center gap-2"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--nexo-amber-soft)] text-[var(--nexo-amber)]">{SCENARIO_NODE_ICONS[node.node_type]}</span><div className="min-w-0"><div className="text-[10px] tracking-wider text-[var(--nexo-muted)]">{SCENARIO_NODE_LABELS[node.node_type]}</div><div className="truncate font-bold">{node.title || SCENARIO_NODE_LABELS[node.node_type]}</div></div></div>
    <div className="mt-3 truncate text-xs text-[var(--nexo-muted)]">{nodeSummary(node)}</div>
    <div className="mt-3 flex items-center justify-between text-[10px]"><span className="text-[var(--nexo-muted)]">{node.node_key}</span>{data.validation === 'error' && <span className="rounded-full bg-[var(--nexo-danger)]/12 px-2 py-1 text-[var(--nexo-danger)]">خطا</span>}{data.validation === 'warning' && <span className="rounded-full bg-[var(--nexo-warning)]/12 px-2 py-1 text-[var(--nexo-warning)]">هشدار</span>}{data.validation === 'ok' && <span className="text-[var(--nexo-success)]">آماده</span>}</div>
    <Handle type="source" position={Position.Right} className="!h-2.5 !w-2.5 !border-2 !border-[var(--nexo-elevated)] !bg-[var(--nexo-amber)]" />
  </div>;
}

function toFlow(graph: ScenarioGraph, validation?: ScenarioValidation) {
  const errorKeys = new Set((validation?.errors ?? []).map((item) => item.path.match(/nodes\.(\d+)/)?.[1]).filter(Boolean).map((index) => graph.nodes[Number(index)]?.node_key));
  return {
    nodes: graph.nodes.map((node, index) => ({ id: node.node_key, type: 'nexo', position: { x: node.position_x ?? index * 260, y: node.position_y ?? 120 + (index % 2) * 160 }, data: { node, validation: errorKeys.has(node.node_key) ? 'error' as const : 'ok' as const } })),
    edges: graph.edges.map((edge) => ({ id: edge.edge_key, source: edge.from_node, target: edge.to_node, label: edge.priority ? `اولویت ${edge.priority}` : undefined, markerEnd: { type: MarkerType.ArrowClosed, color: '#e6ad42' }, style: { stroke: '#e6ad42', strokeWidth: 1.5 } })),
  };
}

function fieldsFor(type: ScenarioNodeType): Array<{ name: keyof NodeForm; label: string; type?: string }> {
  if (type === 'Message') return [{ name: 'text', label: 'متن پیام' }];
  if (type === 'GPS / Location') return [{ name: 'latitude', label: 'عرض جغرافیایی', type: 'number' }, { name: 'longitude', label: 'طول جغرافیایی', type: 'number' }, { name: 'radius_m', label: 'شعاع مجاز (متر)', type: 'number' }, { name: 'accuracy_m', label: 'حداکثر خطای GPS (متر)', type: 'number' }, { name: 'dwell_seconds', label: 'زمان ماندن در محدوده (ثانیه)', type: 'number' }];
  if (type === 'QR') return [{ name: 'trigger_id', label: 'تریگر QR' }];
  if (type === 'Puzzle') return [{ name: 'prompt', label: 'صورت معما' }, { name: 'expected_answer', label: 'پاسخ مورد انتظار' }, { name: 'max_attempts', label: 'حداکثر تلاش', type: 'number' }];
  return [];
}

function Inspector({ selected, readOnly, onChange }: { selected?: ScenarioNode; readOnly: boolean; onChange: (node: ScenarioNode) => void }) {
  const fields = selected ? fieldsFor(selected.node_type) : [];
  const form = useForm<NodeForm>({ resolver: zodResolver(nodeSchema), defaultValues: { title: selected?.title ?? '', ...(selected?.config as Partial<NodeForm>) } });
  const triggers = useQuery({ ...queries.qrTriggers(), enabled: selected?.node_type === 'QR' });
  const checkpoints = useQuery({ ...queries.checkpoints(), enabled: selected?.node_type === 'GPS / Location' });
  useEffect(() => { form.reset({ title: selected?.title ?? '', ...(selected?.config as Partial<NodeForm>) }); }, [selected, form]);
  if (!selected) return <div className="flex h-full items-center justify-center p-6 text-center text-sm text-[var(--nexo-muted)]">برای ویرایش، یک گره را انتخاب کنید.</div>;
  const submit = (values: NodeForm) => {
    if (selected.node_type === 'GPS / Location' && (values.latitude === undefined || values.longitude === undefined || values.radius_m === undefined)) {
      if (values.latitude === undefined) form.setError('latitude', { message: 'عرض جغرافیایی الزامی است.' });
      if (values.longitude === undefined) form.setError('longitude', { message: 'طول جغرافیایی الزامی است.' });
      if (values.radius_m === undefined) form.setError('radius_m', { message: 'شعاع مجاز الزامی است.' });
      return;
    }
    const config: Record<string, unknown> = {};
    fields.forEach(({ name }) => { const value = values[name]; if (value !== '' && value !== undefined) config[name] = value; });
    if (selected.node_type === 'Puzzle') Object.assign(config, { case_sensitive: values.case_sensitive ?? false, trim_whitespace: values.trim_whitespace ?? true });
    onChange({ ...selected, title: values.title || null, config });
  };
  const triggerSelect = <select {...form.register('trigger_id')} disabled={readOnly || triggers.isPending} className="nexo-input"><option value="">انتخاب تریگر فعال</option>{triggers.data?.filter((item) => item.status === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{typeof item.metadata?.label === 'string' ? item.metadata.label : `تریگر ${item.id.slice(0, 8)}`}</option>)}</select>;
  const checkpointSelect = <select aria-label="انتخاب از نقاط ذخیره‌شده" disabled={readOnly || checkpoints.isPending} className="nexo-input" value="" onChange={(event) => { const checkpoint = checkpoints.data?.find((item) => item.id === event.target.value); const coordinates = checkpoint?.geom?.coordinates; if (!checkpoint || !Array.isArray(coordinates) || coordinates.length < 2) return; form.setValue('longitude', Number(coordinates[0])); form.setValue('latitude', Number(coordinates[1])); form.setValue('radius_m', Number(checkpoint.radius_meters ?? 1)); }}><option value="">انتخاب از نقاط ذخیره‌شده (اختیاری)</option>{checkpoints.data?.filter((item) => item.status === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>;
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4 p-5"><div><div className="text-xs text-[var(--nexo-amber)]">{SCENARIO_NODE_LABELS[selected.node_type]}</div><h2 className="mt-1 text-lg font-bold">تنظیمات گره</h2><p className="mt-1 text-xs leading-6 text-[var(--nexo-muted)]">مقادیر پس از ذخیره در پیش‌نویس ثبت می‌شوند.</p></div><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">عنوان مرحله</span><input {...form.register('title')} disabled={readOnly} className="nexo-input" /></label>{selected.node_type === 'GPS / Location' && <div className="space-y-2 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3"><div className="text-xs text-[var(--nexo-muted)]">مختصات مستقیم</div>{checkpointSelect}<div className="text-[11px] leading-5 text-[var(--nexo-muted)]">انتخاب نقطه ذخیره‌شده فقط مختصات را پر می‌کند و برای GPS الزامی نیست.</div></div>}{fields.map((field) => <label key={field.name} className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">{field.label}</span>{field.name === 'trigger_id' ? triggerSelect : <input {...form.register(field.name)} type={field.type ?? 'text'} step={field.type === 'number' ? 'any' : undefined} disabled={readOnly} className="nexo-input" />}{form.formState.errors[field.name] && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{String(form.formState.errors[field.name]?.message)}</span>}</label>)}{selected.node_type === 'Puzzle' && <div className="space-y-2 text-sm"><label className="flex gap-2"><input type="checkbox" {...form.register('case_sensitive')} disabled={readOnly} />حساس به بزرگی حروف</label><label className="flex gap-2"><input type="checkbox" {...form.register('trim_whitespace')} disabled={readOnly} />حذف فاصله‌های ابتدا و انتها</label></div>}{!readOnly && <Button type="submit">اعمال تغییرات</Button>}</form>;
}

export function ScenarioBuilder({ scenarioId, versionId, readOnly = false }: { scenarioId: string; versionId?: string; readOnly?: boolean }) {
  const client = useQueryClient();
  const scenario = useQuery({ queryKey: ['scenarios', scenarioId], queryFn: () => scenarioApi.get(scenarioId) });
  const versions = useQuery({ queryKey: ['scenarios', scenarioId, 'versions'], queryFn: () => scenarioApi.versions(scenarioId) });
  const activeVersion = versionId ?? versions.data?.[0]?.id;
  const version = useQuery({ queryKey: ['scenario-version', activeVersion], queryFn: () => scenarioApi.version(activeVersion as string), enabled: Boolean(activeVersion) });
  const graph = useQuery({ queryKey: ['scenario-graph', activeVersion], queryFn: () => scenarioApi.graph(activeVersion as string), enabled: Boolean(activeVersion) });
  const [nodes, setNodes, onNodesChange] = useNodesState<Node<BuilderNodeData>>([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
  const [selectedId, setSelectedId] = useState<string>();
  const [dirty, setDirty] = useState(false);
  const [validation, setValidation] = useState<ScenarioValidation>();
  const [simulation, setSimulation] = useState<ScenarioSimulation>();
  const [simulationIndex, setSimulationIndex] = useState(0);
  const [confirmPublish, setConfirmPublishState] = useState(false);
  const [message, setMessage] = useState<string>();
  const rf = useReactFlow();
  useEffect(() => { if (graph.data) { const next = toFlow(graph.data.graph, graph.data.validation); setNodes(next.nodes); setEdges(next.edges); setValidation(graph.data.validation); setDirty(false); } }, [graph.data, setEdges, setNodes]);
  const selected = nodes.find((node) => node.id === selectedId)?.data.node;
  const updateNode = (updated: ScenarioNode) => { setNodes((current) => current.map((node) => node.id === updated.node_key ? { ...node, data: { ...node.data, node: updated } } : node)); setDirty(true); };
  const addNode = (type: ScenarioNodeType) => { if (readOnly) return; const id = `${type.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${Date.now()}`; const node: ScenarioNode = { node_key: id, node_type: type, title: null, config: defaultScenarioConfig(type), position_x: 140 + nodes.length * 35, position_y: 100 + (nodes.length % 4) * 120 }; setNodes((current) => [...current, { id, type: 'nexo', position: { x: node.position_x ?? 0, y: node.position_y ?? 0 }, data: { node } }]); setSelectedId(id); setDirty(true); };
  const save = useMutation({ mutationFn: () => scenarioApi.saveGraph(activeVersion as string, { revision_token: version.data?.revision_token ?? graph.data?.revision_token ?? '', nodes: nodes.map((node) => ({ ...node.data.node, position_x: node.position.x, position_y: node.position.y })), edges: edges.map((edge, index) => ({ edge_key: edge.id, from_node: edge.source, to_node: edge.target, priority: index, condition_group_id: null, metadata: {} })), variables: graph.data?.graph.variables ?? [], conditions: graph.data?.graph.conditions ?? [], subflows: graph.data?.graph.subflows ?? [] }), onSuccess: (result) => { setValidation(result.validation); setDirty(false); client.setQueryData(['scenario-graph', activeVersion], result); setMessage('پیش‌نویس ذخیره شد.'); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'ذخیره پیش‌نویس انجام نشد.') });
  const validate = useMutation({ mutationFn: () => scenarioApi.validate(activeVersion as string, version.data?.revision_token ?? ''), onSuccess: (result) => { setValidation(result); setMessage(result.status === 'PASS' ? 'اعتبارسنجی موفق بود.' : 'گراف نیاز به اصلاح دارد.'); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'اعتبارسنجی انجام نشد.') });
  const publish = useMutation({ mutationFn: () => scenarioApi.publish(activeVersion as string, version.data?.revision_token ?? ''), onSuccess: () => { setConfirmPublishState(false); setMessage('نسخه منتشر شد.'); client.invalidateQueries({ queryKey: ['scenario-version', activeVersion] }); client.invalidateQueries({ queryKey: ['scenarios', scenarioId] }); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'انتشار نسخه انجام نشد.') });
  const simulate = useMutation({ mutationFn: () => scenarioApi.simulate(activeVersion as string, version.data?.revision_token ?? ''), onSuccess: (result) => { setSimulation(result); setSimulationIndex(0); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'شبیه‌سازی انجام نشد.') });
  if (scenario.isPending || versions.isPending || (activeVersion && (version.isPending || graph.isPending))) return <LoadingState label="در حال آماده‌سازی سازنده سناریو..." />;
  if (scenario.isError || versions.isError || version.isError || graph.isError) return <ErrorState message="اطلاعات سناریو بارگذاری نشد." onRetry={() => { void scenario.refetch(); void versions.refetch(); void graph.refetch(); }} />;
  if (!activeVersion) return <EmptyState title="نسخه پیش‌نویس وجود ندارد" description="برای این سناریو ابتدا یک نسخه پیش‌نویس ایجاد کنید." />;
  const isPublished = version.data?.status !== 'DRAFT';
  const publishable = canPublish(validation, version.data?.status);
  return <div className="-mx-5 -mt-2 lg:-mx-8"><PageHeader eyebrow="SCENARIO CONTROL" title={scenario.data?.title ?? 'سناریو'} description={`نسخه ${version.data?.version_no ?? '—'} · ${isPublished ? 'نسخه فقط‌خواندنی' : dirty ? 'تغییرات ذخیره‌نشده' : 'پیش‌نویس همگام'}`} actions={<div className="flex flex-wrap gap-2"><Button variant="secondary" onClick={() => rf.fitView({ padding: 0.2 })}>نمای کامل</Button><Button variant="secondary" onClick={() => validate.mutate()}>{validate.isPending ? '...' : 'اعتبارسنجی'}</Button><Button variant="secondary" onClick={() => simulate.mutate()}>شبیه‌سازی</Button><Button onClick={() => save.mutate()} disabled={readOnly || isPublished || !dirty}>{save.isPending ? 'در حال ذخیره...' : 'ذخیره پیش‌نویس'}</Button><Button variant="primary" onClick={() => setConfirmPublishState(true)} disabled={isPublished || !publishable}>{isPublished ? 'منتشرشده' : 'انتشار'}</Button></div>} />{message && <div role="status" className="mb-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] px-4 py-3 text-sm text-[var(--nexo-muted)]">{message}</div>}<div className="grid min-h-[700px] min-w-0 grid-cols-[190px_minmax(0,1fr)_310px] border-y border-[var(--nexo-border)] bg-[#0d151f] max-lg:grid-cols-[160px_minmax(0,1fr)_280px]"><aside className="border-l border-[var(--nexo-border)] p-3"><div className="mb-4 text-xs font-bold tracking-widest text-[var(--nexo-muted)]">کتابخانه گره‌ها</div><div className="space-y-2">{palette.map((type) => <button key={type} disabled={readOnly || isPublished} onClick={() => addNode(type)} className="flex w-full items-center gap-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-3 text-right text-sm transition hover:border-[var(--nexo-amber)] disabled:cursor-not-allowed disabled:opacity-50"><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-[var(--nexo-amber-soft)] text-[var(--nexo-amber)]">{SCENARIO_NODE_ICONS[type]}</span><span className="truncate">{SCENARIO_NODE_LABELS[type]}</span></button>)}</div><div className="mt-6 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3 text-xs leading-6 text-[var(--nexo-muted)]">اعتبارسنجی نهایی، دسترسی منابع و انتشار فقط توسط Laravel تعیین می‌شود.</div></aside><main className="nexo-grid relative min-w-0"><ReactFlow nodes={nodes} edges={edges} nodeTypes={{ nexo: NexoNode }} onNodesChange={(changes) => { onNodesChange(changes); if (!readOnly && changes.some((change) => change.type === 'position' && change.dragging === false)) setDirty(true); }} onEdgesChange={(changes) => { onEdgesChange(changes); if (!readOnly) setDirty(true); }} onConnect={(connection: Connection) => { if (!readOnly) { setEdges((current) => addEdge({ ...connection, markerEnd: { type: MarkerType.ArrowClosed, color: '#e6ad42' }, style: { stroke: '#e6ad42', strokeWidth: 1.5 } }, current)); setDirty(true); } }} onNodeClick={(_, node) => setSelectedId(node.id)} fitView proOptions={{ hideAttribution: true }}><Background color="#253342" gap={32} /><Controls position="bottom-left" /><MiniMap nodeColor="#e6ad42" maskColor="rgb(11 15 20 / 75%)" position="bottom-right" /></ReactFlow>{validation && <div className={`absolute bottom-4 right-4 max-w-md rounded-lg border px-4 py-3 text-xs ${validation.status === 'PASS' ? 'border-[var(--nexo-success)]/40 bg-[var(--nexo-success)]/10 text-[var(--nexo-success)]' : 'border-[var(--nexo-danger)]/40 bg-[var(--nexo-danger)]/10 text-[var(--nexo-danger)]'}`}>{validation.status === 'PASS' ? 'گراف آماده بررسی انتشار است.' : `${validation.errors.length} خطا · ${validation.warnings.length} هشدار`}</div>}</main><aside className="border-r border-[var(--nexo-border)] bg-[#101923]"><Inspector selected={selected} readOnly={readOnly || isPublished} onChange={updateNode} /></aside></div>{simulation && <div className="mx-5 mt-4 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><div className="flex items-center justify-between"><h2 className="font-bold">پیش‌نمایش ساختاری</h2><StatusBadge status={simulation.status} /></div><p className="mt-3 text-sm text-[var(--nexo-muted)]">پیش‌نمایش ساختاری — اجرای واقعی Runtime نیست؛ هیچ رویداد، امتیاز یا پاداشی ایجاد نمی‌شود.</p><div className="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div>گره‌های قابل دسترس: {simulation.reachable_nodes.length}</div><div>شاخه‌ها: {simulation.branches.length}</div><div>بن‌بست‌ها: {simulation.dead_ends.length}</div></div><div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--nexo-border)] pt-4 text-sm"><span className="text-[var(--nexo-muted)]">گام {simulation.reachable_nodes.length ? simulationIndex + 1 : 0} از {simulation.reachable_nodes.length}</span><div className="flex gap-2"><Button variant="ghost" onClick={() => setSimulationIndex((index) => Math.max(0, index - 1))} disabled={simulationIndex === 0}>قبلی</Button><Button variant="secondary" onClick={() => setSimulationIndex((index) => Math.min(Math.max(simulation.reachable_nodes.length - 1, 0), index + 1))} disabled={!simulation.reachable_nodes.length || simulationIndex >= simulation.reachable_nodes.length - 1}>بعدی</Button><Button variant="ghost" onClick={() => setSimulationIndex(0)} disabled={simulationIndex === 0}>شروع دوباره</Button></div></div>{simulation.reachable_nodes[simulationIndex] && <div className="mt-3 rounded-lg bg-[var(--nexo-bg)] p-3 text-sm">گره فعلی: <span className="font-semibold text-[var(--nexo-amber)]">{simulation.reachable_nodes[simulationIndex]}</span></div>}</div>}{confirmPublish && <ConfirmDialog title="انتشار نسخه سناریو" description="پس از انتشار، این نسخه فقط‌خواندنی است. ابتدا مطمئن شوید آخرین اعتبارسنجی موفق است." confirmLabel="انتشار نسخه" onClose={() => setConfirmPublishState(false)} onConfirm={() => publish.mutate()} />}</div>;
}

export function ScenarioBuilderScreen(props: { scenarioId: string; versionId?: string; readOnly?: boolean }) { return <ReactFlowProvider><ScenarioBuilder {...props} /></ReactFlowProvider>; }
