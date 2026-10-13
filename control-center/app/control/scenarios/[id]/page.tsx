'use client';
import { use, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import { Button, ErrorState, LoadingState, PageHeader } from '@/components/ui';
import type { Scenario } from '@/types/scenario';

function ScenarioMetadata({ scenario }: { scenario: Scenario }) {
  const client = useQueryClient();
  const [title, setTitle] = useState(scenario.title);
  const [description, setDescription] = useState(scenario.description ?? '');
  const [theme, setTheme] = useState(scenario.theme ?? '');
  const save = useMutation({ mutationFn: () => scenarioApi.update(scenario.id, { title, description, theme }), onSuccess: () => client.invalidateQueries({ queryKey: ['scenarios'] }) });
  return <form className="nexo-surface space-y-4 rounded-xl p-6" onSubmit={(event) => { event.preventDefault(); save.mutate(); }}>
    <h2 className="font-bold">سناریو: قابل ویرایش</h2>
    <p className="text-sm text-[var(--nexo-muted)]">تغییر مشخصات و Draft روی نسخه‌های منتشرشده و نشست‌های جاری اثر ندارد.</p>
    <label className="block">عنوان<input className="nexo-input mt-2" required maxLength={255} value={title} onChange={(event) => setTitle(event.target.value)} /></label>
    <label className="block">توضیحات<textarea className="nexo-input mt-2" maxLength={10000} value={description} onChange={(event) => setDescription(event.target.value)} /></label>
    <label className="block">موضوع<input className="nexo-input mt-2" maxLength={255} value={theme} onChange={(event) => setTheme(event.target.value)} /></label>
    <Button type="submit" disabled={save.isPending || scenario.status === 'ARCHIVED'}>ذخیره مشخصات سناریو</Button>
    {save.isSuccess && <p role="status">مشخصات ذخیره شد.</p>}
    {save.isError && <p role="alert">ذخیره مشخصات انجام نشد.</p>}
  </form>;
}

export default function ScenarioDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const client = useQueryClient();
  const scenario = useQuery(queries.scenario(id));
  const draft = useMutation({ mutationFn: () => scenarioApi.ensureDraft(id), onSuccess: (version) => { void client.invalidateQueries({ queryKey: ['scenarios', id] }); router.push(`/control/scenarios/${id}/builder?version=${version.id}`); } });
  if (scenario.isPending) return <LoadingState />;
  if (scenario.isError) return <ErrorState onRetry={() => void scenario.refetch()} />;
  return <><PageHeader title={scenario.data.title} description="Draft: در حال ویرایش · Published: قفل‌شده / فقط خواندنی" actions={<><Button onClick={() => draft.mutate()} disabled={draft.isPending || scenario.data.status === 'ARCHIVED'}>ویرایش Draft</Button><Button href={`/control/scenarios/${id}/versions`} variant="secondary">تاریخچه نسخه‌ها</Button></>} />{draft.isError && <p role="alert">آماده‌سازی Draft انجام نشد؛ دسترسی و وضعیت سناریو را بررسی کنید.</p>}<ScenarioMetadata scenario={scenario.data} /></>;
}
