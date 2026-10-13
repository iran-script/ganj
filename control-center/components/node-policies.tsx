'use client';

import { useEffect, useState } from 'react';
import type { ScenarioNode } from '@/types/scenario';
import { Button } from '@/components/ui';

const supported = ['Message', 'Puzzle', 'GPS / Location', 'QR', 'MediaReveal', 'PhotoSubmission', 'AudioSubmission', 'FileSubmission', 'TeamTogether', 'SplitInformation', 'Barrier', 'Relay', 'LiveChallenge'];
const scoreFields = { base: 'امتیاز تکمیل', first_attempt_bonus: 'پاداش تلاش اول', no_hint_bonus: 'پاداش بدون راهنمایی' };

export function NodePolicies({ node, readOnly, onChange }: { node: ScenarioNode; readOnly: boolean; onChange: (node: ScenarioNode) => void }) {
  const [config, setConfig] = useState(node.config);
  useEffect(() => setConfig(node.config), [node]);
  if (!supported.includes(node.node_type)) return null;
  const score = config.score as Record<string, unknown> | undefined;
  const hints = config.hints as string[] ?? [];
  const set = (key: string, value: unknown) => setConfig((current) => ({ ...current, [key]: value }));
  const number = (key: string, label: string, min: number, max: number) => <label className="block text-sm" key={key}>{label}<input className="nexo-input mt-2" type="number" min={min} max={max} disabled={readOnly} value={String(config[key] ?? '')} onChange={(event) => set(key, event.target.value ? Number(event.target.value) : '')} /></label>;
  return <details className="border-t border-[var(--nexo-border)] p-5">
    <summary className="cursor-pointer text-sm font-bold">زمان، پیام و امتیاز مرحله</summary>
    <form className="mt-4 space-y-4" onSubmit={(event) => {
      event.preventDefault();
      const clean = { ...config };
      Object.keys(clean).forEach((key) => { if (clean[key] === '') delete clean[key]; });
      if (Array.isArray(clean.hints)) clean.hints = clean.hints.filter((hint) => typeof hint === 'string' && hint.trim());
      onChange({ ...node, config: clean });
    }}>
      {number('timeout_seconds', 'مهلت مرحله (ثانیه، اختیاری)', 1, 86400)}
      <label className="block text-sm">راهنمای بازیکن<textarea className="nexo-input mt-2" maxLength={2000} disabled={readOnly} value={String(config.guidance_text ?? '')} onChange={(event) => set('guidance_text', event.target.value)} /></label>
      <label className="block text-sm">پیام موفقیت<input className="nexo-input mt-2" maxLength={2000} disabled={readOnly} value={String(config.success_message ?? '')} onChange={(event) => set('success_message', event.target.value)} /></label>
      {node.node_type === 'QR' && <label className="block text-sm">پیام QR نامعتبر<input className="nexo-input mt-2" maxLength={2000} disabled={readOnly} value={String(config.invalid_message ?? '')} onChange={(event) => set('invalid_message', event.target.value)} /></label>}
      {Object.entries(scoreFields).filter(([key]) => key === 'base' || node.node_type === 'Puzzle').map(([key, label]) => <label key={key} className="block text-sm">{label}<input className="nexo-input mt-2" type="number" min={0} max={1000000} disabled={readOnly} value={String(score?.[key] ?? '')} onChange={(event) => {
        const next = { ...score };
        if (event.target.value) next[key] = Number(event.target.value); else delete next[key];
        set('score', next);
      }} /></label>)}
      {['Puzzle', 'QR', 'GPS / Location'].includes(node.node_type) && <>
        <label className="flex gap-2 text-sm"><input type="checkbox" checked={config.allow_retry !== false} disabled={readOnly} onChange={(event) => set('allow_retry', event.target.checked)} />تلاش دوباره مجاز</label>
        {number('retry_delay', 'فاصله تلاش (ثانیه)', 0, 3600)}
        {node.node_type !== 'Puzzle' && number('max_attempts', 'حداکثر تلاش', 1, 1000)}
      </>}
      {node.node_type === 'Puzzle' && <fieldset className="space-y-3"><legend className="text-sm">راهنمایی‌های معما</legend>{[0, 1, 2].map((index) => <label key={index} className="block text-sm">راهنمایی {index + 1}<textarea className="nexo-input mt-2" maxLength={2000} disabled={readOnly} value={hints[index] ?? ''} onChange={(event) => {
        const next = [...hints]; next[index] = event.target.value; set('hints', next);
      }} /></label>)}</fieldset>}
      <p className="text-xs leading-6 text-[var(--nexo-muted)]">با افزودن مهلت یا قانون شکست، مسیر برچسب‌دار مربوط را در گراف وصل کنید.</p>
      {!readOnly && <Button type="submit">اعمال سیاست مرحله</Button>}
    </form>
  </details>;
}
