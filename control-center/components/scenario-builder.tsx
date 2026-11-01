'use client';

import '@xyflow/react/dist/style.css';
import { LocationPicker } from '@/components/gis/location-picker';
import { SavedLocationPicker } from '@/components/gis/saved-location-picker';
import { z } from 'zod';
import dynamic from 'next/dynamic';
const StructuralSimulation = dynamic(() => import('@/components/structural-simulation').then((module) => module.StructuralSimulation), { loading: () => <LoadingState /> });
import { NodePolicies } from '@/components/node-policies';
import { AdvancedNodeInspector, VariableEditor } from '@/components/advanced-node-inspector';
import { NODE_CATALOG, NODE_GROUPS, nodeOutcomes, OUTCOME_LABELS } from '@/lib/node-catalog';
import type { ScenarioVariable } from '@/types/scenario';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { addEdge, Background, Controls, Handle, MarkerType, MiniMap, Position, ReactFlow, ReactFlowProvider, useEdgesState, useNodesState, useReactFlow, type Connection, type Edge, type Node, type NodeProps } from '@xyflow/react';
import { Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/ui';
import { ApiErrorResponse } from '@/lib/api-error';
import { canPublish, defaultScenarioConfig, nodeSummary, nodeValidation, validationFromApiError, validationIssueText, validationIssuesForNode } from '@/lib/scenario-builder-utils';
import { captureLayout, layoutScenario, type LayoutMode, type LayoutSnapshot } from '@/lib/scenario-layout';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import type { ScenarioGraph, ScenarioNode, ScenarioNodeType, ScenarioSimulation, ScenarioValidation } from '@/types/scenario';
import { SCENARIO_NODE_ICONS, SCENARIO_NODE_LABELS } from '@/types/scenario';
import { Layout, RotateCcw } from 'lucide-react';

const palette = Object.keys(NODE_CATALOG) as ScenarioNodeType[];
const optionalNumber = (schema: z.ZodNumber) => z.preprocess((value) => value === '' || value === null ? undefined : typeof value === 'string' ? Number(value) : value, schema.optional());
const nodeSchema = z.object({
  title: z.string().max(255, 'عنوان بیش از حد طولانی است'),
  description: z.string().max(2000).optional(),
  text: z.string().max(10000, 'متن بیش از حد طولانی است').optional(),
  prompt: z.string().max(10000).optional(), expected_answer: z.string().max(1000).optional(),
  latitude: optionalNumber(z.number().min(-90).max(90)), longitude: optionalNumber(z.number().min(-180).max(180)),
  radius_m: optionalNumber(z.number().min(0.01).max(100000)), accuracy_m: optionalNumber(z.number().min(0.01).max(1000)),
  dwell_seconds: optionalNumber(z.number().int().min(0).max(86400)), max_attempts: optionalNumber(z.number().int().min(1).max(1000)),
  trigger_id: z.string().uuid('شناسه QR باید UUID باشد').optional().or(z.literal('')), checkpoint_id: z.string().uuid('شناسه نقطه باید UUID باشد').optional().or(z.literal('')), case_sensitive: z.boolean().optional(), trim_whitespace: z.boolean().optional(),
});
type NodeForm = z.infer<typeof nodeSchema>;
const puzzleEditorSchema = z.object({
  title: z.string().max(255, 'عنوان بیش از حد طولانی است'),
  description: z.string().max(2000).optional(),
  prompt: z.string().min(1, 'صورت سؤال الزامی است.').max(10000, 'صورت سؤال بیش از حد طولانی است.'),
  expected_answer: z.string().min(1, 'پاسخ مورد انتظار الزامی است.').max(1000, 'پاسخ مورد انتظار بیش از حد طولانی است.'),
  max_attempts: optionalNumber(z.number().int('تعداد تلاش باید عدد صحیح باشد.').min(1, 'حداقل تلاش ۱ است.').max(1000, 'حداکثر تلاش ۱۰۰۰ است.')),
  case_sensitive: z.boolean(), trim_whitespace: z.boolean(), hints: z.array(z.string().max(2000, 'هر راهنما حداکثر ۲۰۰۰ نویسه است.')).max(3), offline_submission_allowed: z.boolean(),
});
type PuzzleEditorForm = z.infer<typeof puzzleEditorSchema>;
const multipleChoiceEditorSchema = z.object({
  title: z.string().max(255, 'عنوان بیش از حد طولانی است'),
  description: z.string().max(2000).optional(),
  prompt: z.string().min(1, 'صورت سؤال الزامی است.').max(10000, 'صورت سؤال بیش از حد طولانی است.'),
  options: z.array(z.object({ ref: z.string().min(1, 'شناسه گزینه الزامی است.').max(100, 'شناسه گزینه بیش از حد طولانی است.'), text: z.string().min(1, 'متن گزینه الزامی است.').max(1000, 'متن گزینه بیش از حد طولانی است.') })).min(2, 'حداقل دو گزینه لازم است.').max(20, 'حداکثر ۲۰ گزینه مجاز است.'),
  correct_option_refs: z.array(z.string()).min(1, 'حداقل یک گزینه صحیح انتخاب کنید.').max(20), multi_select: z.boolean(), offline_submission_allowed: z.boolean(),
}).superRefine((value, context) => {
  const refs = value.options.map((option) => option.ref);
  if (new Set(refs).size !== refs.length) context.addIssue({ code: z.ZodIssueCode.custom, path: ['options'], message: 'شناسه گزینه‌ها باید یکتا باشد.' });
  if (value.correct_option_refs.some((ref) => !refs.includes(ref))) context.addIssue({ code: z.ZodIssueCode.custom, path: ['correct_option_refs'], message: 'گزینه صحیح باید از گزینه‌های موجود باشد.' });
  if (!value.multi_select && value.correct_option_refs.length !== 1) context.addIssue({ code: z.ZodIssueCode.custom, path: ['correct_option_refs'], message: 'در حالت تک‌انتخابی دقیقاً یک گزینه صحیح انتخاب کنید.' });
});
type MultipleChoiceEditorForm = z.infer<typeof multipleChoiceEditorSchema>;
type BuilderNodeData = { node: ScenarioNode; validation?: 'error' | 'warning' | 'ok'; validationIssues?: Array<{ code: string; path: string }> };

function NexoNode({ data, selected }: NodeProps<Node<BuilderNodeData>>) {
  const node = data.node;
  const tone = data.validation === 'error' || data.validationIssues?.length ? 'border-[var(--nexo-danger)]' : data.validation === 'warning' ? 'border-[var(--nexo-warning)]' : selected ? 'border-[var(--nexo-amber)]' : 'border-[var(--nexo-border)]';
  return <div role="group" aria-label={`${SCENARIO_NODE_LABELS[node.node_type]}: ${node.title || SCENARIO_NODE_LABELS[node.node_type]}`} className={`min-w-[210px] rounded-xl border bg-[var(--nexo-elevated)] p-3 shadow-[var(--nexo-shadow)] ${selected ? 'ring-2 ring-[var(--nexo-amber)]/25' : ''} ${tone}`}>
    <Handle type="target" position={Position.Left} className="!h-2.5 !w-2.5 !border-2 !border-[var(--nexo-elevated)] !bg-[var(--nexo-info)]" />
    <div className="flex items-center gap-2"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--nexo-amber-soft)] text-[var(--nexo-amber)]">{SCENARIO_NODE_ICONS[node.node_type]}</span><div className="min-w-0"><div className="text-[10px] tracking-wider text-[var(--nexo-muted)]">{SCENARIO_NODE_LABELS[node.node_type]}</div><div className="truncate font-bold">{node.title || SCENARIO_NODE_LABELS[node.node_type]}</div></div></div>
    <div className="mt-3 truncate text-xs text-[var(--nexo-muted)]">{nodeSummary(node)}</div>{data.validationIssues?.map((issue) => <div key={`${issue.code}-${issue.path}`} role="alert" className="mt-2 rounded bg-[var(--nexo-danger)]/10 px-2 py-1 text-[10px] leading-5 text-[var(--nexo-danger)]">{validationIssueText(issue, node.node_key, 0)} <span className="opacity-70">{issue.path}</span></div>)}
    <div className="mt-3 flex items-center justify-between text-[10px]"><span className="text-[var(--nexo-muted)]">{node.node_key}</span>{data.validation === 'error' && <span className="rounded-full bg-[var(--nexo-danger)]/12 px-2 py-1 text-[var(--nexo-danger)]">خطا</span>}{data.validation === 'warning' && <span className="rounded-full bg-[var(--nexo-warning)]/12 px-2 py-1 text-[var(--nexo-warning)]">هشدار</span>}{data.validation === 'ok' && <span className="text-[var(--nexo-success)]">آماده</span>}</div>
    <Handle type="source" position={Position.Right} className="!h-2.5 !w-2.5 !border-2 !border-[var(--nexo-elevated)] !bg-[var(--nexo-amber)]" />
  </div>;
}

const nodeTypes = { nexo: NexoNode };

function toFlow(graph: ScenarioGraph, validation?: ScenarioValidation) {
  return {
    nodes: graph.nodes.map((node, index) => ({ id: node.node_key, type: 'nexo', position: { x: node.position_x ?? index * 260, y: node.position_y ?? 120 + (index % 2) * 160 }, data: { node, validation: nodeValidation(node.node_key, graph.nodes, graph.edges, validation), validationIssues: validationIssuesForNode(node.node_key, index, graph.nodes, validation) } })),
    edges: graph.edges.map((edge) => ({ id: edge.edge_key, source: edge.from_node, target: edge.to_node, data: { metadata: edge.metadata ?? {} }, label: String(edge.metadata?.label ?? OUTCOME_LABELS[String(edge.metadata?.outcome)] ?? (edge.priority ? `اولویت ${edge.priority}` : '')), markerEnd: { type: MarkerType.ArrowClosed, color: '#e6ad42' }, style: { stroke: '#e6ad42', strokeWidth: 1.5 } })),
  };
}

function fieldsFor(type: ScenarioNodeType): Array<{ name: keyof NodeForm; label: string; type?: string }> {
  if (type === 'Message') return [{ name: 'text', label: 'متن پیام' }];
  if (type === 'GPS / Location') return [{ name: 'description', label: 'Stage description' }, { name: 'latitude', label: 'عرض جغرافیایی', type: 'number' }, { name: 'longitude', label: 'طول جغرافیایی', type: 'number' }, { name: 'radius_m', label: 'شعاع مجاز (متر)', type: 'number' }, { name: 'accuracy_m', label: 'حداکثر خطای GPS (متر)', type: 'number' }, { name: 'dwell_seconds', label: 'زمان ماندن در محدوده (ثانیه)', type: 'number' }];
  if (type === 'QR') return [{ name: 'description', label: 'Stage description' }, { name: 'trigger_id', label: 'QR trigger' }, { name: 'radius_m', label: 'Radius override (meters)', type: 'number' }];
  if (type === 'Puzzle') return [{ name: 'prompt', label: 'صورت معما' }, { name: 'expected_answer', label: 'پاسخ مورد انتظار' }, { name: 'max_attempts', label: 'حداکثر تلاش', type: 'number' }];
  return [];
}

function PuzzleNodeEditor({ selected, readOnly, onChange }: { selected: ScenarioNode; readOnly: boolean; onChange: (node: ScenarioNode) => void }) {
  const form = useForm<PuzzleEditorForm>({ resolver: zodResolver(puzzleEditorSchema), defaultValues: { title: selected.title ?? '', prompt: String(selected.config.prompt ?? ''), expected_answer: String(selected.config.expected_answer ?? ''), max_attempts: typeof selected.config.max_attempts === 'number' ? selected.config.max_attempts : undefined, case_sensitive: selected.config.case_sensitive === true, trim_whitespace: selected.config.trim_whitespace !== false, hints: Array.isArray(selected.config.hints) ? selected.config.hints.map(String).slice(0, 3) : [], offline_submission_allowed: selected.config.offline_submission_allowed === true } });
  useEffect(() => { form.reset({ title: selected.title ?? '', prompt: String(selected.config.prompt ?? ''), expected_answer: String(selected.config.expected_answer ?? ''), max_attempts: typeof selected.config.max_attempts === 'number' ? selected.config.max_attempts : undefined, case_sensitive: selected.config.case_sensitive === true, trim_whitespace: selected.config.trim_whitespace !== false, hints: Array.isArray(selected.config.hints) ? selected.config.hints.map(String).slice(0, 3) : [], offline_submission_allowed: selected.config.offline_submission_allowed === true }); }, [selected, form]);
  const submit = (values: PuzzleEditorForm) => { const config: Record<string, unknown> = { ...selected.config, prompt: values.prompt, expected_answer: values.expected_answer, case_sensitive: values.case_sensitive, trim_whitespace: values.trim_whitespace, offline_submission_allowed: values.offline_submission_allowed }; if (values.max_attempts === undefined) delete config.max_attempts; else config.max_attempts = values.max_attempts; const hints = values.hints.map((hint) => hint.trim()).filter(Boolean); if (hints.length) config.hints = hints; else delete config.hints; onChange({ ...selected, title: values.title || null, config }); };
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4 p-5"><div><div className="text-xs text-[var(--nexo-amber)]">{SCENARIO_NODE_LABELS[selected.node_type]}</div><h2 className="mt-1 text-lg font-bold">ویرایش محتوای گره</h2><p className="mt-1 text-xs leading-6 text-[var(--nexo-muted)]">فیلدها مطابق schema واقعی Backend اعتبارسنجی می‌شوند.</p></div><label className="block text-sm">عنوان مرحله<input {...form.register('title')} disabled={readOnly} className="nexo-input mt-2" />{form.formState.errors.title && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{form.formState.errors.title.message}</span>}</label><label className="block text-sm">صورت سؤال<textarea {...form.register('prompt')} disabled={readOnly} className="nexo-input mt-2 min-h-24" />{form.formState.errors.prompt && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{form.formState.errors.prompt.message}</span>}</label><label className="block text-sm">پاسخ مورد انتظار<input {...form.register('expected_answer')} disabled={readOnly} className="nexo-input mt-2" />{form.formState.errors.expected_answer && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{form.formState.errors.expected_answer.message}</span>}</label><label className="block text-sm">حداکثر تلاش (اختیاری)<input {...form.register('max_attempts')} type="number" min="1" max="1000" disabled={readOnly} className="nexo-input mt-2" />{form.formState.errors.max_attempts && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{form.formState.errors.max_attempts.message}</span>}</label><div className="space-y-2 text-sm"><label className="flex gap-2"><input type="checkbox" {...form.register('case_sensitive')} disabled={readOnly} />حساس به بزرگی حروف</label><label className="flex gap-2"><input type="checkbox" {...form.register('trim_whitespace')} disabled={readOnly} />حذف فاصله‌های ابتدا و انتها</label><label className="flex gap-2"><input type="checkbox" {...form.register('offline_submission_allowed')} disabled={readOnly} />ارسال آفلاین مجاز</label></div><fieldset className="space-y-2"><legend className="text-sm">راهنماها (اختیاری، حداکثر ۳ مورد)</legend>{[0, 1, 2].map((index) => <label key={index} className="block text-sm">راهنمایی {index + 1}<textarea {...form.register(`hints.${index}`)} disabled={readOnly} maxLength={2000} className="nexo-input mt-2" /></label>)}</fieldset>{!readOnly && <Button type="submit">اعمال تغییرات</Button>}</form>;
}

function MultipleChoiceNodeEditor({ selected, readOnly, onChange }: { selected: ScenarioNode; readOnly: boolean; onChange: (node: ScenarioNode) => void }) {
  const options = useMemo(() => selected.config.options && typeof selected.config.options === 'object' && !Array.isArray(selected.config.options) ? Object.entries(selected.config.options as Record<string, unknown>).map(([ref, text]) => ({ ref, text: String(text) })) : [{ ref: 'A', text: '' }, { ref: 'B', text: '' }], [selected]);
  const form = useForm<MultipleChoiceEditorForm>({ resolver: zodResolver(multipleChoiceEditorSchema), defaultValues: { title: selected.title ?? '', prompt: String(selected.config.prompt ?? ''), options, correct_option_refs: Array.isArray(selected.config.correct_option_refs) ? selected.config.correct_option_refs.map(String) : [], multi_select: selected.config.multi_select === true, offline_submission_allowed: selected.config.offline_submission_allowed === true } });
  const optionFields = useFieldArray({ control: form.control, name: 'options' });
  useEffect(() => { form.reset({ title: selected.title ?? '', prompt: String(selected.config.prompt ?? ''), options, correct_option_refs: Array.isArray(selected.config.correct_option_refs) ? selected.config.correct_option_refs.map(String) : [], multi_select: selected.config.multi_select === true, offline_submission_allowed: selected.config.offline_submission_allowed === true }); }, [selected, form, options]);
  const selectedRefs = form.watch('correct_option_refs');
  const toggleCorrect = (ref: string) => form.setValue('correct_option_refs', selectedRefs.includes(ref) ? selectedRefs.filter((item) => item !== ref) : [...selectedRefs, ref], { shouldValidate: true });
  const submit = (values: MultipleChoiceEditorForm) => onChange({ ...selected, title: values.title || null, config: { ...selected.config, prompt: values.prompt, options: Object.fromEntries(values.options.map((option) => [option.ref, option.text])), correct_option_refs: values.correct_option_refs, multi_select: values.multi_select, offline_submission_allowed: values.offline_submission_allowed } });
  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4 p-5"><div><div className="text-xs text-[var(--nexo-amber)]">{SCENARIO_NODE_LABELS[selected.node_type]}</div><h2 className="mt-1 text-lg font-bold">ویرایش محتوای گره</h2><p className="mt-1 text-xs leading-6 text-[var(--nexo-muted)]">کلید گزینه‌ها همان شناسه‌ای است که Backend برای پاسخ صحیح استفاده می‌کند.</p></div><label className="block text-sm">عنوان مرحله<input {...form.register('title')} disabled={readOnly} className="nexo-input mt-2" /></label><label className="block text-sm">صورت سؤال<textarea {...form.register('prompt')} disabled={readOnly} className="nexo-input mt-2 min-h-24" />{form.formState.errors.prompt && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{form.formState.errors.prompt.message}</span>}</label><fieldset className="space-y-3"><legend className="text-sm">گزینه‌ها (۲ تا ۲۰)</legend>{optionFields.fields.map((field, index) => <div key={field.id} className="grid grid-cols-[90px_1fr_auto] items-start gap-2"><label className="text-sm">شناسه<input {...form.register(`options.${index}.ref`)} disabled={readOnly} className="nexo-input mt-2" /></label><label className="text-sm">متن گزینه<textarea {...form.register(`options.${index}.text`)} disabled={readOnly} className="nexo-input mt-2" /></label><label className="mt-8 flex items-center gap-2 text-xs"><input type="checkbox" checked={selectedRefs.includes(form.watch(`options.${index}.ref`))} disabled={readOnly} onChange={() => toggleCorrect(form.getValues(`options.${index}.ref`))} />صحیح</label>{(form.formState.errors.options?.[index]?.ref || form.formState.errors.options?.[index]?.text) && <span className="col-span-3 text-xs text-[var(--nexo-danger)]">شناسه و متن گزینه الزامی است.</span>}</div>)}{form.formState.errors.options?.root && <p className="text-xs text-[var(--nexo-danger)]">{form.formState.errors.options.root.message}</p>}{!readOnly && <Button variant="secondary" onClick={() => optionFields.append({ ref: `O${optionFields.fields.length + 1}`, text: '' })}>افزودن گزینه</Button>}</fieldset>{form.formState.errors.correct_option_refs && <p className="text-xs text-[var(--nexo-danger)]">{form.formState.errors.correct_option_refs.message}</p>}<div className="space-y-2 text-sm"><label className="flex gap-2"><input type="checkbox" {...form.register('multi_select')} disabled={readOnly} />انتخاب چند پاسخ صحیح</label><label className="flex gap-2"><input type="checkbox" {...form.register('offline_submission_allowed')} disabled={readOnly} />ارسال آفلاین مجاز</label></div>{!readOnly && <Button type="submit">اعمال تغییرات</Button>}</form>;
}

function Inspector({ selected, readOnly, onChange }: { selected?: ScenarioNode; readOnly: boolean; onChange: (node: ScenarioNode) => void }) {
  const fields = selected ? fieldsFor(selected.node_type) : [];
  const form = useForm<NodeForm>({ resolver: zodResolver(nodeSchema), defaultValues: { title: selected?.title ?? '', ...(selected?.config as Partial<NodeForm>) } });
  const triggers = useQuery({ ...queries.qrTriggers(), enabled: selected?.node_type === 'QR' });
  const checkpoints = useQuery({ ...queries.locations(), enabled: ['GPS / Location', 'QR'].includes(selected?.node_type ?? '') });
  const [resourceSearch, setResourceSearch] = useState('');
  const [activeOnly, setActiveOnly] = useState(true); const selectedLocation = checkpoints.data?.find((item) => item.id === form.watch('checkpoint_id'));
  useEffect(() => { form.reset({ title: selected?.title ?? '', ...(selected?.config as Partial<NodeForm>) }); }, [selected, form]);
  if (!selected) return <div className="flex h-full items-center justify-center p-6 text-center text-sm text-[var(--nexo-muted)]">برای ویرایش، یک گره را انتخاب کنید.</div>;
  if (selected.node_type === 'Password' || selected.node_type === 'Text Answer') return <PuzzleNodeEditor selected={selected} readOnly={readOnly} onChange={onChange} />;
  if (selected.node_type === 'Multiple Choice') return <MultipleChoiceNodeEditor selected={selected} readOnly={readOnly} onChange={onChange} />;
  const submit = (values: NodeForm) => {
    if (selected.node_type === 'GPS / Location' && !values.checkpoint_id && (values.latitude === undefined || values.longitude === undefined || values.radius_m === undefined)) {
      if (values.latitude === undefined) form.setError('latitude', { message: 'عرض جغرافیایی الزامی است.' });
      if (values.longitude === undefined) form.setError('longitude', { message: 'طول جغرافیایی الزامی است.' });
      if (values.radius_m === undefined) form.setError('radius_m', { message: 'شعاع مجاز الزامی است.' });
      return;
    }
    const config: Record<string, unknown> = { ...selected.config };
    fields.forEach(({ name }) => { const value = values[name]; if (value !== '' && value !== undefined) config[name] = value; else delete config[name]; });
    if (selected.node_type === 'Puzzle') Object.assign(config, { case_sensitive: values.case_sensitive ?? false, trim_whitespace: values.trim_whitespace ?? true });
    if (['GPS / Location', 'QR'].includes(selected.node_type) && values.checkpoint_id) { config.checkpoint_id = values.checkpoint_id; delete config.latitude; delete config.longitude; }
    if (['GPS / Location', 'QR'].includes(selected.node_type) && !values.checkpoint_id && selected.config.checkpoint_id) delete config.checkpoint_id;

    onChange({ ...selected, title: values.title || null, config });
  };
  const triggerSelect = <select {...form.register('trigger_id')} value={form.watch('trigger_id') ?? ''} disabled={readOnly || triggers.isPending} className="nexo-input"><option value="">انتخاب تریگر فعال</option>{triggers.data?.filter((item) => ((!activeOnly || item.status === 'ACTIVE') && String(item.metadata?.label ?? '').toLowerCase().includes(resourceSearch.toLowerCase())) || item.id === form.watch('trigger_id')).map((item) => <option key={item.id} value={item.id} disabled={item.status !== 'ACTIVE'}>{item.status !== 'ACTIVE' ? 'غیرفعال • ' : ''}{typeof item.metadata?.label === 'string' ? item.metadata.label : `تریگر ${item.id.slice(0, 8)}`}</option>)}</select>;

  return <form onSubmit={form.handleSubmit(submit)} className="space-y-4 p-5"><div><div className="text-xs text-[var(--nexo-amber)]">{SCENARIO_NODE_LABELS[selected.node_type]}</div><h2 className="mt-1 text-lg font-bold">تنظیمات گره</h2><p className="mt-1 text-xs leading-6 text-[var(--nexo-muted)]">مقادیر پس از ذخیره در پیش‌نویس ثبت می‌شوند.</p></div><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">عنوان مرحله</span><input {...form.register('title')} disabled={readOnly} className="nexo-input" /></label>{['GPS / Location', 'QR'].includes(selected.node_type) && <div className="space-y-3"><SavedLocationPicker items={checkpoints.data ?? []} selectedId={form.watch('checkpoint_id')} disabled={readOnly || checkpoints.isPending} onPick={(location) => { form.setValue('checkpoint_id', location.id, { shouldDirty: true }); form.setValue('radius_m', 20, { shouldDirty: true }); form.setValue('latitude', undefined); form.setValue('longitude', undefined); form.setValue('accuracy_m', location.accuracy_m ?? undefined); form.setValue('dwell_seconds', location.dwell_seconds ?? undefined); }} />{checkpoints.isError && <p role="status" className="text-sm text-[var(--nexo-warning)]">بانک موقعیت‌ها بارگذاری نشد؛ ورود مستقیم مختصات فعال است.</p>}{selectedLocation && <LocationPicker latitude={selectedLocation.latitude} longitude={selectedLocation.longitude} radius={form.watch('radius_m') ?? selectedLocation.default_radius_m} readOnly onChange={() => undefined} />}{!form.watch('checkpoint_id') && <LocationPicker latitude={form.watch('latitude')} longitude={form.watch('longitude')} radius={form.watch('radius_m')} readOnly={readOnly} onChange={(point) => { form.setValue('latitude', point.latitude, { shouldDirty: true }); form.setValue('longitude', point.longitude, { shouldDirty: true }); }} /> }</div>}{fields.filter((field) => !(selected.node_type === 'GPS / Location' && form.watch('checkpoint_id') && ['latitude', 'longitude'].includes(field.name))).map((field) => <label key={field.name} className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">{field.label}</span>{field.name === 'trigger_id' ? <div className="space-y-2"><input aria-label="جستجو در بانک QR" className="nexo-input" placeholder="جستجوی QR…" value={resourceSearch} onChange={(event) => setResourceSearch(event.target.value)} /><label className="flex gap-2"><input type="checkbox" checked={activeOnly} onChange={(event) => setActiveOnly(event.target.checked)} />فقط فعال</label>{triggerSelect}<a href="/control/content/qr-bank/new" target="_blank" rel="noreferrer" className="text-xs text-[var(--nexo-amber)]">ساخت QR جدید</a>{triggers.data?.some((item) => item.id === form.watch('trigger_id') && item.status !== 'ACTIVE') && <p role="alert" className="text-xs text-[var(--nexo-warning)]">QR انتخاب‌شده غیرفعال است؛ انتشار مجاز نیست.</p>}</div> : <input {...form.register(field.name)} type={field.type ?? 'text'} step={field.type === 'number' ? 'any' : undefined} disabled={readOnly} className="nexo-input" />}{form.formState.errors[field.name] && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{String(form.formState.errors[field.name]?.message)}</span>}</label>)}{selected.node_type === 'Puzzle' && <div className="space-y-2 text-sm"><label className="flex gap-2"><input type="checkbox" {...form.register('case_sensitive')} disabled={readOnly} />حساس به بزرگی حروف</label><label className="flex gap-2"><input type="checkbox" {...form.register('trim_whitespace')} disabled={readOnly} />حذف فاصله‌های ابتدا و انتها</label></div>}{!readOnly && <Button type="submit">اعمال تغییرات</Button>}</form>;
}

export function ScenarioBuilder({ scenarioId, versionId, readOnly = false }: { scenarioId: string; versionId?: string; readOnly?: boolean }) {
  const client = useQueryClient();
  const scenario = useQuery(queries.scenario(scenarioId));
  const versions = useQuery(queries.scenarioVersions(scenarioId));
  const activeVersion = versionId ?? versions.data?.find((item) => item.status === 'DRAFT')?.id ?? versions.data?.[0]?.id;
  const version = useQuery({ queryKey: ['scenario-version', activeVersion], queryFn: () => scenarioApi.version(activeVersion as string), enabled: Boolean(activeVersion) });
  const graph = useQuery({ queryKey: ['scenario-graph', activeVersion], queryFn: () => scenarioApi.graph(activeVersion as string), enabled: Boolean(activeVersion) });
  const [nodes, setNodes, onNodesChange] = useNodesState<Node<BuilderNodeData>>([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
  const [selectedId, setSelectedId] = useState<string>();
  const [selectedEdgeId, setSelectedEdgeId] = useState<string>();
  const [search, setSearch] = useState('');
  const [fullWidth, setFullWidth] = useState(false);
  const [variables, setVariables] = useState<ScenarioVariable[]>([]);
  const [dirty, setDirty] = useState(false);
  const [validation, setValidation] = useState<ScenarioValidation>();
  const [simulation, setSimulation] = useState<ScenarioSimulation>();
  const [confirmPublish, setConfirmPublishState] = useState(false);
  const [message, setMessage] = useState<string>();
  const [layoutMode, setLayoutMode] = useState<LayoutMode>('TREE_VERTICAL');

  const [previousLayout, setPreviousLayout] = useState<LayoutSnapshot>();
  const [layoutWarning, setLayoutWarning] = useState(false);
  const rf = useReactFlow();
  const layoutHistory = useRef<LayoutSnapshot | undefined>(undefined);
  const arrangeNodes = () => {
    layoutHistory.current = captureLayout(nodes);
    const result = layoutScenario(nodes, edges, layoutMode);
    setNodes(result.nodes);
    setPreviousLayout(layoutHistory.current);
    setDirty(true);
    setLayoutWarning(result.hasCycle);
    requestAnimationFrame(() => { void rf.fitView({ padding: 0.18, duration: nodes.length <= 50 ? 250 : 0, maxZoom: 1.15 }); });
  };
  const restorePreviousLayout = () => {
    if (!layoutHistory.current) return;
    const snapshot = layoutHistory.current;
    setNodes((current) => current.map((node) => {
      const position = snapshot.get(node.id);
      return position ? { ...node, position: { ...position } } : node;
    }));
    layoutHistory.current = undefined;
    setPreviousLayout(undefined);
    setLayoutWarning(false);
    setDirty(true);
    requestAnimationFrame(() => { void rf.fitView({ padding: 0.18 }); });
  };
  useEffect(() => { if (graph.data) { const next = toFlow(graph.data.graph, graph.data.validation); setNodes(next.nodes); setEdges(next.edges); setValidation(graph.data.validation); setVariables(graph.data.graph.variables as ScenarioVariable[]); setDirty(false); } }, [graph.data, setEdges, setNodes]);
  const selected = nodes.find((node) => node.id === selectedId)?.data.node;
  const updateNode = (updated: ScenarioNode) => {
    if (readOnly || version.data?.status !== 'DRAFT') return;
    const paired = edges.find((edge) => edge.data?.metadata && (edge.data.metadata as Record<string, unknown>).verification_order === 'GPS_THEN_QR'
      && (edge.source === updated.node_key || edge.target === updated.node_key));
    if (paired && updated.node_type === 'GPS / Location') {
      const qrKey = paired.target;
      setNodes((current) => current.map((node) => {
        if (node.id === updated.node_key) return { ...node, data: { ...node.data, node: updated } };
        if (node.id === qrKey) {
          const qrConfig = Object.fromEntries(Object.entries(node.data.node.config).filter(([key]) => !['checkpoint_id', 'radius_m'].includes(key)));
          return { ...node, data: { ...node.data, node: { ...node.data.node, config: { ...qrConfig,
          ...(updated.config.checkpoint_id ? { checkpoint_id: updated.config.checkpoint_id } : {}),
          ...(updated.config.radius_m ? { radius_m: updated.config.radius_m } : {}) } } } };
        }
        return node;
      }));
    } else {
      setNodes((current) => current.map((node) => node.id === updated.node_key ? { ...node, data: { ...node.data, node: updated } } : node));
    }
    setDirty(true);
  };
  const addNode = (type: ScenarioNodeType, position?: { x: number; y: number }) => { if (readOnly || version.data?.status !== 'DRAFT') return; const id = `${type.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${crypto.randomUUID()}`; const node: ScenarioNode = { node_key: id, node_type: type, title: null, config: defaultScenarioConfig(type), position_x: position?.x ?? 140 + nodes.length * 35, position_y: position?.y ?? 100 + (nodes.length % 4) * 120 }; setNodes((current) => [...current, { id, type: 'nexo', position: { x: node.position_x ?? 0, y: node.position_y ?? 0 }, data: { node } }]); setSelectedId(id); setDirty(true); };
const addGpsThenQrStage = () => {
  if (readOnly || version.data?.status !== 'DRAFT') return;
  const id = crypto.randomUUID();
  const gpsKey = `gps-${id}`;
  const qrKey = `qr-${id}`;
  const gps: ScenarioNode = { node_key: gpsKey, node_type: 'GPS / Location', title: 'GPS verification', config: { checkpoint_id: '', radius_m: 20 }, position_x: 160 + nodes.length * 20, position_y: 120 };
  const qr: ScenarioNode = { node_key: qrKey, node_type: 'QR', title: 'Secret code QR', config: { trigger_id: '', checkpoint_id: '', radius_m: 20, max_location_age_seconds: 30 }, position_x: 420 + nodes.length * 20, position_y: 120 };
  setNodes((current) => [...current, { id: gpsKey, type: 'nexo', position: { x: gps.position_x ?? 0, y: gps.position_y ?? 0 }, data: { node: gps } }, { id: qrKey, type: 'nexo', position: { x: qr.position_x ?? 0, y: qr.position_y ?? 0 }, data: { node: qr } }]);
  setEdges((current) => [...current, { id: `gps-then-qr-${id}`, source: gpsKey, target: qrKey, label: 'GPS_THEN_QR', data: { metadata: { outcome: 'SUCCESS', verification_order: 'GPS_THEN_QR' } }, markerEnd: { type: MarkerType.ArrowClosed, color: '#e6ad42' }, style: { stroke: '#e6ad42', strokeWidth: 1.5 } }]);
  setSelectedId(gpsKey);
  setDirty(true);
};
  const save = useMutation({ mutationFn: () => scenarioApi.saveGraph(activeVersion as string, { revision_token: graph.data?.revision_token ?? version.data?.revision_token ?? '', nodes: nodes.map((node) => ({ ...node.data.node, position_x: node.position.x, position_y: node.position.y })), edges: edges.map((edge, index) => ({ edge_key: edge.id, priority: index, condition_group_id: null, ...graph.data?.graph.edges.find((saved) => saved.edge_key === edge.id), from_node: edge.source, to_node: edge.target, metadata: edge.data?.metadata ?? graph.data?.graph.edges.find((saved) => saved.edge_key === edge.id)?.metadata ?? {}, ...((edge.data?.metadata as Record<string, unknown>)?.outcome ? { condition_group_id: null } : {}) })), variables, conditions: graph.data?.graph.conditions ?? [], subflows: graph.data?.graph.subflows ?? [] }), onSuccess: (result) => { setValidation(result.validation); setDirty(false); client.setQueryData(['scenario-graph', activeVersion], result); setMessage('پیش‌نویس ذخیره شد.'); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'ذخیره پیش‌نویس انجام نشد.') });
  const validate = useMutation({ mutationFn: () => scenarioApi.validate(activeVersion as string, graph.data?.revision_token ?? version.data?.revision_token ?? ''), onSuccess: (result) => { setValidation(result); setMessage(result.status === 'PASS' ? 'اعتبارسنجی موفق بود.' : 'گراف نیاز به اصلاح دارد.'); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'اعتبارسنجی انجام نشد.') });
  const publish = useMutation({ mutationFn: () => scenarioApi.publish(activeVersion as string, graph.data?.revision_token ?? version.data?.revision_token ?? ''), onSuccess: () => { setConfirmPublishState(false); setMessage('نسخه منتشر شد.'); client.invalidateQueries({ queryKey: ['scenario-version', activeVersion] }); client.invalidateQueries({ queryKey: ['scenarios', scenarioId] }); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'انتشار نسخه انجام نشد.') });
  const simulate = useMutation({ mutationFn: () => scenarioApi.simulate(activeVersion as string, graph.data?.revision_token ?? version.data?.revision_token ?? ''), onSuccess: (result) => { setSimulation(result); }, onError: (error) => setMessage(error instanceof ApiErrorResponse ? error.message : 'شبیه‌سازی انجام نشد.') });
  useEffect(() => {
    const backendValidation = validationFromApiError(save.error) ?? validationFromApiError(validate.error);
    if (!backendValidation) return;
    setValidation(backendValidation);
    setMessage('اعتبارسنجی Backend ناموفق بود؛ خطاهای گره‌ها را بررسی کنید.');
    setNodes((current) => {
      const currentGraph = current.map((item) => item.data.node);
      return current.map((item, index) => ({ ...item, data: { ...item.data, validationIssues: validationIssuesForNode(item.id, index, currentGraph, backendValidation) } }));
    });
  }, [save.error, validate.error, setNodes]);
  if (scenario.isPending || versions.isPending || (activeVersion && (version.isPending || graph.isPending))) return <LoadingState label="در حال آماده‌سازی سازنده سناریو..." />;
  if (scenario.isError || versions.isError || version.isError || graph.isError) return <ErrorState message="اطلاعات سناریو بارگذاری نشد." onRetry={() => { void scenario.refetch(); void versions.refetch(); void graph.refetch(); }} />;
  if (!activeVersion) return <EmptyState title="نسخه پیش‌نویس وجود ندارد" description="برای این سناریو ابتدا یک نسخه پیش‌نویس ایجاد کنید." />;
  const isPublished = version.data?.status !== 'DRAFT';
  const publishable = !dirty && !save.isPending && canPublish(validation, version.data?.status);
  return <div className={fullWidth ? 'fixed inset-0 z-40 overflow-auto bg-[var(--nexo-bg)] p-4' : '-mx-5 -mt-2 lg:-mx-8'}><PageHeader eyebrow="SCENARIO CONTROL" title={scenario.data?.title ?? 'سناریو'} description={`نسخه ${version.data?.version_no ?? '—'} · ${isPublished ? 'Published · قفل‌شده / فقط خواندنی' : dirty ? 'تغییرات ذخیره‌نشده' : 'Draft · در حال ویرایش'}`} actions={<div className="flex flex-wrap gap-2"><Button variant="secondary" onClick={() => setFullWidth(!fullWidth)}>{fullWidth ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه'}</Button><Button variant="secondary" onClick={() => rf.fitView({ padding: 0.2 })}>نمای کامل</Button>{!readOnly && !isPublished && <><label className="flex min-h-10 items-center gap-2 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] px-3 text-sm"><Layout aria-hidden="true" size={16} /><span>جهت چیدمان</span><select aria-label="جهت چیدمان" className="bg-transparent text-sm" value={layoutMode} onChange={(event) => setLayoutMode(event.target.value as LayoutMode)}><option value="TREE_VERTICAL">عمودی</option><option value="TREE_HORIZONTAL">افقی</option></select></label><Button variant="secondary" onClick={arrangeNodes} title="چیدمان خودکار گراف"><Layout aria-hidden="true" size={16} /> مرتب‌سازی خودکار</Button>{previousLayout && <Button variant="secondary" onClick={restorePreviousLayout} title="بازگردانی موقعیت‌های پیشین"><RotateCcw aria-hidden="true" size={16} /> بازگردانی چیدمان قبلی</Button>}</>}{!readOnly && !isPublished && <><Button variant="secondary" disabled={dirty || save.isPending || isPublished || readOnly} onClick={() => validate.mutate()}>{validate.isPending ? '...' : 'اعتبارسنجی'}</Button><Button variant="secondary" disabled={dirty || save.isPending || isPublished} onClick={() => simulate.mutate()}>شبیه‌سازی</Button><Button onClick={() => save.mutate()} disabled={readOnly || isPublished || !dirty || save.isPending}>{save.isPending ? 'در حال ذخیره...' : 'ذخیره Draft'}</Button><Button variant="primary" onClick={() => setConfirmPublishState(true)} disabled={readOnly || isPublished || !publishable}>انتشار نسخه جدید</Button></>}<Button href={`/control/scenarios/${scenarioId}/versions`} variant="secondary">تاریخچه نسخه‌ها</Button>{isPublished && <Button href={`/control/scenarios/${scenarioId}`}>ویرایش Draft</Button>}</div>} />{layoutWarning && <div role="status" className="mb-3 rounded-lg border border-[var(--nexo-warning)]/40 bg-[var(--nexo-warning)]/10 px-4 py-3 text-sm text-[var(--nexo-warning)]">چیدمان خودکار برای گراف دارای حلقه به‌صورت محدود انجام شد.</div>}{message && <div role="status" className="mb-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] px-4 py-3 text-sm text-[var(--nexo-muted)]">{message}</div>}<VariableEditor variables={variables} readOnly={readOnly || isPublished} onChange={(value) => { setVariables(value); setDirty(true); }} /><div className="grid min-h-[700px] min-w-0 grid-cols-[190px_minmax(0,1fr)_310px] border-y border-[var(--nexo-border)] bg-[#0d151f] max-lg:grid-cols-[160px_minmax(0,1fr)_280px]"><aside className="border-l border-[var(--nexo-border)] p-3"><div className="mb-4 text-xs font-bold tracking-widest text-[var(--nexo-muted)]">کتابخانه گره‌ها</div><input aria-label="جستجوی گره" className="nexo-input mb-3" placeholder="جستجوی گره…" value={search} onChange={(event) => setSearch(event.target.value)} /><Button variant="secondary" onClick={addGpsThenQrStage}>افزودن مرحله GPS سپس QR</Button>{NODE_GROUPS.map((group) => <section key={group} className="mb-4"><h3 className="mb-2 text-xs text-[var(--nexo-muted)]">{group}</h3><div className="space-y-2">{palette.filter((type) => NODE_CATALOG[type].group === group && `${type} ${NODE_CATALOG[type].label}`.toLowerCase().includes(search.toLowerCase())).map((type) => <button key={type} draggable={!readOnly && !isPublished} onDragStart={(event) => event.dataTransfer.setData('application/nexo-node', type)} disabled={readOnly || isPublished} onClick={() => addNode(type)} className="flex w-full items-center gap-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-3 text-right text-sm transition hover:border-[var(--nexo-amber)] disabled:opacity-50"><span aria-hidden="true" className="text-[var(--nexo-amber)]">{SCENARIO_NODE_ICONS[type]}</span><span>{SCENARIO_NODE_LABELS[type]}</span></button>)}</div></section>)}<div className="mt-6 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3 text-xs leading-6 text-[var(--nexo-muted)]">اعتبارسنجی نهایی، دسترسی منابع و انتشار فقط توسط Laravel تعیین می‌شود.</div></aside><main className="nexo-grid relative min-w-0"><ReactFlow nodes={nodes.map((node) => ({ ...node, data: { ...node.data, validation: dirty ? undefined : nodeValidation(node.id, graph.data?.graph.nodes ?? [], graph.data?.graph.edges ?? [], validation) } }))} edges={edges} nodeTypes={nodeTypes} nodesDraggable={!readOnly && !isPublished} nodesConnectable={!readOnly && !isPublished} edgesReconnectable={!readOnly && !isPublished} deleteKeyCode={readOnly || isPublished ? null : ['Backspace', 'Delete']} onNodesChange={(changes) => { onNodesChange(readOnly || isPublished ? changes.filter((change) => change.type === 'select' || change.type === 'dimensions') : changes); if (!readOnly && !isPublished && changes.some((change) => change.type === 'remove' || change.type === 'position' && change.dragging === false)) setDirty(true); }} onEdgesChange={(changes) => { onEdgesChange(readOnly || isPublished ? changes.filter((change) => change.type === 'select') : changes); if (!readOnly && !isPublished && changes.some((change) => change.type !== 'select')) setDirty(true); }} onConnect={(connection: Connection) => { if (!readOnly && !isPublished) { setEdges((current) => addEdge({ ...connection, markerEnd: { type: MarkerType.ArrowClosed, color: '#e6ad42' }, style: { stroke: '#e6ad42', strokeWidth: 1.5 } }, current)); setDirty(true); } }} onDragOver={(event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; }} onDrop={(event) => { event.preventDefault(); const type = event.dataTransfer.getData('application/nexo-node') as ScenarioNodeType; if (!readOnly && !isPublished && palette.includes(type)) addNode(type, rf.screenToFlowPosition({ x: event.clientX, y: event.clientY })); }} onEdgeClick={(_, edge) => { setSelectedEdgeId(edge.id); setSelectedId(undefined); }} onNodeClick={(_, node) => { setSelectedId(node.id); setSelectedEdgeId(undefined); }} fitView proOptions={{ hideAttribution: true }}><Background color="#253342" gap={32} /><Controls position="bottom-left" showInteractive={!readOnly && !isPublished} /><MiniMap nodeColor="#e6ad42" maskColor="rgb(11 15 20 / 75%)" position="bottom-right" /></ReactFlow>{validation && <div className={`absolute bottom-4 right-4 max-w-md rounded-lg border px-4 py-3 text-xs ${validation.status === 'PASS' ? 'border-[var(--nexo-success)]/40 bg-[var(--nexo-success)]/10 text-[var(--nexo-success)]' : 'border-[var(--nexo-danger)]/40 bg-[var(--nexo-danger)]/10 text-[var(--nexo-danger)]'}`}>{validation.status === 'PASS' ? 'گراف آماده بررسی انتشار است.' : `${validation.errors.length} خطا · ${validation.warnings.length} هشدار`}</div>}</main><aside className="border-r border-[var(--nexo-border)] bg-[#101923]">{selectedEdgeId ? <div className="space-y-4 p-5"><h2 className="font-bold">تنظیمات مسیر</h2><label className="block text-sm">نتیجه مسیر<select className="nexo-input mt-2" disabled={readOnly || isPublished} value={String((edges.find((edge) => edge.id === selectedEdgeId)?.data?.metadata as Record<string, unknown>)?.outcome ?? '')} onChange={(event) => { setEdges((current) => current.map((edge) => edge.id === selectedEdgeId ? { ...edge, label: OUTCOME_LABELS[event.target.value], data: { metadata: event.target.value ? { ...(edge.data?.metadata as object ?? {}), outcome: event.target.value } : Object.fromEntries(Object.entries(edge.data?.metadata as object ?? {}).filter(([key]) => key !== 'outcome')) } } : edge)); setDirty(true); }}><option value="">مسیر پیش‌فرض</option>{(() => { const edge = edges.find((item) => item.id === selectedEdgeId); const source = nodes.find((node) => node.id === edge?.source)?.data.node; return source ? nodeOutcomes(source.node_type, source.config).map((outcome) => <option key={outcome} value={outcome}>{OUTCOME_LABELS[outcome]}</option>) : []; })()}</select></label><label className="block text-sm">عنوان مسیر<input className="nexo-input mt-2" disabled={readOnly || isPublished} value={String((edges.find((edge) => edge.id === selectedEdgeId)?.data?.metadata as Record<string, unknown>)?.label ?? '')} onChange={(event) => { setEdges((current) => current.map((edge) => edge.id === selectedEdgeId ? { ...edge, label: event.target.value, data: { metadata: { ...(edge.data?.metadata as object ?? {}), label: event.target.value } } } : edge)); setDirty(true); }} /></label></div> : selected && !['Start', 'Finish', 'Message', 'Puzzle', 'GPS / Location', 'QR'].includes(selected.node_type) ? <AdvancedNodeInspector selected={selected} variables={variables} readOnly={readOnly || isPublished} onChange={updateNode} /> : <Inspector selected={selected} readOnly={readOnly || isPublished} onChange={updateNode} />}{selected && <NodePolicies node={selected} readOnly={readOnly || isPublished} onChange={updateNode} />}</aside></div>{simulation && graph.data && <StructuralSimulation key={graph.data.revision_token} graph={graph.data.graph} />}{confirmPublish && <ConfirmDialog title="انتشار نسخه سناریو" description="پس از انتشار، این نسخه فقط‌خواندنی است. ابتدا مطمئن شوید آخرین اعتبارسنجی موفق است." confirmLabel="انتشار نسخه" onClose={() => setConfirmPublishState(false)} onConfirm={() => publish.mutate()} />}</div>;
}

export function ScenarioBuilderScreen(props: { scenarioId: string; versionId?: string; readOnly?: boolean }) { return <ReactFlowProvider><ScenarioBuilder {...props} /></ReactFlowProvider>; }
