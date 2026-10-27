'use client';

import { use, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import { Button, ErrorState, LoadingState, PageHeader } from '@/components/ui';
import { ApiErrorResponse } from '@/lib/api-error';
import type { Scenario } from '@/types/scenario';

function ScenarioMetadata({ scenario }: { scenario: Scenario }) {
  const client = useQueryClient();
  const [title, setTitle] = useState(scenario.title);
  const [description, setDescription] = useState(scenario.description ?? '');
  const [theme, setTheme] = useState(scenario.theme ?? '');
  const save = useMutation({ mutationFn: () => scenarioApi.update(scenario.id, { title, description, theme }), onSuccess: () => client.invalidateQueries({ queryKey: ['scenarios'] }) });
  return <form className="nexo-surface space-y-4 rounded-xl p-6" onSubmit={(event) => { event.preventDefault(); save.mutate(); }}>
    <h2 className="font-bold">سناریو: قابل ویرایش</h2>
    <p className="text-sm text-[var(--nexo-muted)]">مشخصات سناریو و Draft را ویرایش کنید؛ نسخه‌های Published و نشست‌های جاری تغییر نمی‌کنند.</p>
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
  const versions = useQuery(queries.scenarioVersions(id));
  const capabilities = useQuery(queries.capabilities());
  const [reason, setReason] = useState('');
  const draftKey = useRef<string | undefined>(undefined);
  const isSuperAdmin = capabilities.data?.global_roles.includes('SUPER_ADMIN') === true;
  const draftVersion = versions.data?.find((version) => version.status === 'DRAFT');
  const publishedVersion = versions.data?.find((version) => version.status === 'PUBLISHED');
  const prepareDraft = useMutation({
    mutationFn: () => scenarioApi.ensureDraft(id, reason.trim() ? { reason: reason.trim() } : {}, draftKey.current ??= crypto.randomUUID()),
    onSuccess: (version) => { void client.invalidateQueries({ queryKey: ['scenarios', id] }); void client.invalidateQueries({ queryKey: ['scenarios', id, 'versions'] }); router.push(`/control/scenarios/${id}/builder?version=${version.id}`); },
  });
  if (scenario.isPending || versions.isPending || capabilities.isPending) return <LoadingState />;
  if (scenario.isError || versions.isError || capabilities.isError) return <ErrorState onRetry={() => { void scenario.refetch(); void versions.refetch(); void capabilities.refetch(); }} />;
  const isArchived = scenario.data.status === 'ARCHIVED';
  const isPublished = scenario.data.status === 'PUBLISHED' || Boolean(publishedVersion);
  const actionLabel = draftVersion ? `ادامه ویرایش Draft ${draftVersion.version_no}` : isPublished ? 'ساخت نسخه جدید' : 'ویرایش Draft';
  return <>
    <PageHeader title={scenario.data.title} description={`وضعیت سناریو: ${isPublished ? 'Published' : 'Draft'} · نسخه منتشرشده: ${publishedVersion?.version_no ?? 'ندارد'} · Draft: ${draftVersion?.version_no ?? 'ندارد'}`} actions={<><Button onClick={() => prepareDraft.mutate()} disabled={prepareDraft.isPending || isArchived || (isSuperAdmin && !reason.trim())}>{prepareDraft.isPending ? 'در حال آماده‌سازی...' : actionLabel}</Button><Button href={`/control/scenarios/${id}/versions`} variant="secondary">تاریخچه نسخه‌ها</Button></>} />
    {isPublished && <section className="nexo-surface mb-5 space-y-3 rounded-xl p-5"><h2 className="font-bold">ساخت نسخهٔ قابل ویرایش</h2><p className="text-sm leading-6 text-[var(--nexo-muted)]">{draftVersion ? `نسخه Draft ${draftVersion.version_no} آماده است و نسخه Published ${publishedVersion?.version_no ?? ''} بدون تغییر باقی می‌ماند.` : `نسخه Draft جدید از روی Published ${publishedVersion?.version_no ?? ''} ساخته می‌شود؛ همهٔ Nodeها، مسیرها، تنظیمات و موقعیت‌ها کپی خواهند شد.`}</p><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">دلیل عملیات{!isSuperAdmin && ' (اختیاری)'}</span><textarea value={reason} onChange={(event) => setReason(event.target.value)} className="nexo-input min-h-20" required={isSuperAdmin} placeholder="مثلاً آماده‌سازی نسخه دوم سناریو" /></label>{isSuperAdmin && <p className="text-xs text-[var(--nexo-warning)]">برای Super Admin ثبت دلیل عملیات الزامی است.</p>}</section>}
    {prepareDraft.isError && <p role="alert" className="mb-5 text-sm text-[var(--nexo-danger)]">{prepareDraft.error instanceof ApiErrorResponse ? prepareDraft.error.message : 'آماده‌سازی نسخه Draft انجام نشد.'}</p>}
    <ScenarioMetadata scenario={scenario.data} />
  </>;
}
