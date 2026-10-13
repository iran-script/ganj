'use client';

import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '@/components/ui';
import { NODE_CATALOG, RUNTIME_ROLES } from '@/lib/node-catalog';
import { contentApi } from '@/lib/content-api';
import type { ScenarioNode, ScenarioVariable } from '@/types/scenario';

export function AdvancedNodeInspector({ selected, readOnly, variables, onChange }: { selected: ScenarioNode; readOnly: boolean; variables: ScenarioVariable[]; onChange: (node: ScenarioNode) => void }) {
  const [draft, setDraft] = useState(selected);
  const [error, setError] = useState('');
  const media = useQuery({ queryKey: ['content-media'], queryFn: contentApi.media, enabled: selected.node_type === 'MediaReveal' });
  useEffect(() => { setDraft(selected); setError(''); }, [selected]);
  const definition = NODE_CATALOG[draft.node_type];
  const config = draft.config;
  const set = (key: string, value: unknown) => setDraft((current) => ({ ...current, config: { ...current.config, [key]: value } }));
  const fields = definition.fields.filter((field) => !field.modes || field.modes.includes(String(config.mode)));
  const valueType = variables.find((variable) => variable.key === (config.variable ?? String(config.operand).replace('variables.', '')))?.type ?? (config.operand === 'player.role' ? 'STRING' : draft.node_type === 'Condition' ? 'INTEGER' : 'STRING');
  const typedValue = (value: string) => valueType === 'INTEGER' ? value === '' ? '' : Number(value) : valueType === 'BOOLEAN' ? value === 'true' : value;
  return <form className="space-y-4 p-5" onSubmit={(event) => {
    event.preventDefault();
    const clean = { ...config };
    for (const field of definition.fields) {
      if (field.modes && !field.modes.includes(String(config.mode)) || field.optional && (clean[field.key] === '' || clean[field.key] === undefined)) delete clean[field.key];
    }
    if (fields.some((field) => !field.optional && !['boolean', 'roles'].includes(field.kind ?? '') && (clean[field.key] === '' || clean[field.key] === undefined))) { setError('فیلدهای الزامی را کامل کنید.'); return; }
    onChange({ ...draft, config: clean }); setError('');
  }}>
    <h2 className="text-lg font-bold">تنظیمات {definition.label}</h2>
    {error && <p role="alert" className="text-[var(--nexo-danger)]">{error}</p>}
    <label className="block text-sm">عنوان مرحله<input className="nexo-input mt-2" value={draft.title ?? ''} maxLength={255} disabled={readOnly} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
    {fields.map((field) => {
      const value = config[field.key];
      if (field.kind === 'roles' || field.kind === 'mime') return <fieldset key={field.key} disabled={readOnly} className="space-y-2 text-sm"><legend className="mb-2">{field.label}</legend>{(field.kind === 'roles' ? RUNTIME_ROLES : ['application/pdf', 'image/jpeg', 'image/png', 'audio/wav', 'audio/mpeg']).map((option) => <label key={option} className="flex gap-2"><input type="checkbox" checked={(value as string[] ?? []).includes(option)} onChange={(event) => set(field.key, event.target.checked ? [...(value as string[] ?? []), option] : (value as string[] ?? []).filter((item) => item !== option))} />{option}</label>)}{draft.node_type === 'Relay' && <p className="text-xs text-[var(--nexo-muted)]">ترتیب انتخاب: {(value as string[] ?? []).join(' ← ')}</p>}</fieldset>;
      if (field.kind === 'boolean') return <label key={field.key} className="flex gap-2 text-sm"><input type="checkbox" disabled={readOnly} checked={value === true} onChange={(event) => set(field.key, event.target.checked)} />{field.label}</label>;
      const options = field.kind === 'variable' ? variables.filter((item) => draft.node_type !== 'RandomPlayer' || item.type === 'STRING').map((item) => item.key) : field.options;
      return <label key={field.key} className="block text-sm"><span className="mb-2 block">{field.label}{field.optional ? ' (اختیاری)' : ''}</span>{options ? <select className="nexo-input" disabled={readOnly} value={String(value ?? '')} onChange={(event) => set(field.key, event.target.value)}><option value="">انتخاب</option>{options.map((option) => <option key={option}>{option}</option>)}</select>
        : field.key === 'operand' ? <select className="nexo-input" value={String(value ?? '')} disabled={readOnly} onChange={(event) => set(field.key, event.target.value)}>{['score', 'elapsed_time', 'team.member_count', 'player.role', ...variables.map((item) => `variables.${item.key}`)].map((option) => <option key={option}>{option}</option>)}</select>
          : field.kind === 'value' && valueType === 'BOOLEAN' ? <select className="nexo-input" disabled={readOnly} value={String(value)} onChange={(event) => set(field.key, typedValue(event.target.value))}><option value="true">درست</option><option value="false">نادرست</option></select>
            : <input className="nexo-input" type={field.kind === 'number' || field.kind === 'value' && valueType === 'INTEGER' ? 'number' : 'text'} step="any" min={field.min} max={field.max} maxLength={10000} disabled={readOnly} value={String(value ?? '')} onChange={(event) => set(field.key, field.kind === 'number' ? event.target.value === '' ? '' : Number(event.target.value) : field.kind === 'value' ? typedValue(event.target.value) : event.target.value)} />}</label>;
    })}
    {draft.node_type === 'MediaReveal' && <label className="block text-sm">انتخاب از کتابخانه رسانه<select className="nexo-input mt-2" disabled={readOnly || media.isPending} value={String(config.media_asset_id ?? '')} onChange={(event) => set('media_asset_id', event.target.value)}><option value="">انتخاب رسانه</option>{media.data?.map((asset) => <option key={asset.id} value={asset.id}>{asset.name} • {asset.kind}</option>)}</select>{media.isError && <span role="alert">بارگذاری رسانه انجام نشد.</span>}</label>}
    {draft.node_type === 'SplitInformation' && <fieldset className="space-y-3" disabled={readOnly}><legend>پیام خصوصی هر نقش</legend>{RUNTIME_ROLES.map((role) => <label key={role} className="block text-sm">{role}<textarea className="nexo-input mt-2" value={(config.role_content as Record<string, { text: string }> ?? {})[role]?.text ?? ''} onChange={(event) => { const content = { ...(config.role_content as Record<string, { text: string }> ?? {}) }; if (event.target.value) content[role] = { text: event.target.value }; else delete content[role]; set('role_content', content); }} /></label>)}</fieldset>}
    {draft.node_type === 'Barrier' && <fieldset disabled={readOnly}><legend>متغیرهایی که باید درست باشند</legend>{variables.filter((item) => item.type === 'BOOLEAN').map((variable) => <label key={variable.key} className="flex gap-2"><input type="checkbox" checked={(config.required_variables as string[] ?? []).includes(variable.key)} onChange={(event) => set('required_variables', event.target.checked ? [...(config.required_variables as string[] ?? []), variable.key] : (config.required_variables as string[] ?? []).filter((key) => key !== variable.key))} />{variable.key}</label>)}</fieldset>}
    {!readOnly && <Button type="submit">اعمال تغییرات</Button>}
  </form>;
}

export function VariableEditor({ variables, onChange, readOnly }: { variables: ScenarioVariable[]; onChange: (variables: ScenarioVariable[]) => void; readOnly: boolean }) {
  return <details className="nexo-surface rounded-lg p-4"><summary className="cursor-pointer font-bold">متغیرهای سناریو ({variables.length})</summary><p className="my-3 text-xs text-[var(--nexo-muted)]">متغیرها متعلق به تیم هستند؛ نام با حرف انگلیسی شروع شود.</p>{variables.map((variable, index) => <div key={index} className="mb-3 grid gap-2 sm:grid-cols-4"><input aria-label={`نام متغیر ${index + 1}`} pattern="[A-Za-z][A-Za-z0-9_]{0,99}" className="nexo-input" disabled={readOnly} value={variable.key} onChange={(event) => onChange(variables.map((item, i) => i === index ? { ...item, key: event.target.value } : item))} /><select aria-label={`نوع متغیر ${index + 1}`} className="nexo-input" disabled={readOnly} value={variable.type} onChange={(event) => onChange(variables.map((item, i) => i === index ? { ...item, type: event.target.value as ScenarioVariable['type'], default_value: event.target.value === 'INTEGER' ? 0 : event.target.value === 'BOOLEAN' ? false : '' } : item))}>{['STRING', 'INTEGER', 'BOOLEAN'].map((type) => <option key={type}>{type}</option>)}</select><input aria-label={`مقدار اولیه ${index + 1}`} className="nexo-input" disabled={readOnly} type={variable.type === 'BOOLEAN' ? 'checkbox' : variable.type === 'INTEGER' ? 'number' : 'text'} checked={variable.default_value === true} value={String(variable.default_value)} onChange={(event) => onChange(variables.map((item, i) => i === index ? { ...item, default_value: item.type === 'BOOLEAN' ? event.target.checked : item.type === 'INTEGER' ? Number(event.target.value) : event.target.value } : item))} /><Button variant="ghost" disabled={readOnly} onClick={() => onChange(variables.filter((_, i) => i !== index))}>حذف متغیر</Button></div>)}<Button variant="secondary" disabled={readOnly} onClick={() => onChange([...variables, { key: `variable_${variables.length + 1}`, type: 'STRING', scope: 'TEAM', default_value: '', writable: true }])}>افزودن متغیر</Button></details>;
}
