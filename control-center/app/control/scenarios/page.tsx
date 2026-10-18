'use client';
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ResourceLink } from '@/components/resource-link';
import { ConfirmDialog, Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import { queries } from '@/lib/queries';
import { scenarioApi } from '@/lib/scenario-api';
import { normalizeApiError } from '@/lib/api-error';

export default function ScenariosPage() {
  const result = useQuery(queries.scenarios());
  const client = useQueryClient();
  const [selected, setSelected] = useState<{ id: string; title: string } | null>(null);
  const archive = useMutation({ mutationFn: (item: { id: string; title: string }) => scenarioApi.archive(item.id, `بایگانی سناریو «${item.title}» از مرکز کنترل`), onSuccess: async () => { setSelected(null); await client.invalidateQueries({ queryKey: ['scenarios'] }); } });
  if (result.isPending) return <LoadingState label="در حال دریافت سناریوها..." />;
  if (result.isError) return <ErrorState message="فهرست سناریوها بارگذاری نشد." onRetry={() => void result.refetch()} />;
  return <>
    <PageHeader eyebrow="SCENARIO CONTROL" title="سناریوها" description="سناریوها و تاریخچه نسخه‌های آن‌ها" actions={<Button href="/control/scenarios/new">ایجاد سناریو</Button>} />
    {archive.isError && <p role="alert" className="mb-4 text-sm text-[var(--nexo-danger)]">{normalizeApiError(archive.error).message}</p>}
    {result.data.length === 0 ? <EmptyState title="سناریویی ثبت نشده است" description="برای شروع، یک سناریوی پیش‌نویس ایجاد کنید." action={<Button href="/control/scenarios/new">ایجاد اولین سناریو</Button>} /> : <DataTable headers={['سناریو', 'وضعیت', 'آخرین تغییر', 'عملیات']}>
      <>{result.data.map((scenario) => <tr key={scenario.id} className="hover:bg-white/[2%]"><td className="px-5 py-4"><ResourceLink resource="scenario" resourceId={scenario.id} href={`/control/scenarios/${scenario.id}`} className="font-semibold hover:text-[var(--nexo-amber)]">{scenario.title}</ResourceLink><div className="mt-1 text-xs text-[var(--nexo-muted)]">{scenario.description || 'بدون توضیح'}</div></td><td className="px-5 py-4"><StatusBadge status={scenario.status} /></td><td className="px-5 py-4 text-xs text-[var(--nexo-muted)]">{scenario.updated_at ? new Date(scenario.updated_at).toLocaleDateString('fa-IR') : '—'}</td><td className="px-5 py-4"><div className="flex flex-wrap gap-2"><Button href={`/control/scenarios/${scenario.id}`} variant="secondary">ویرایش / نسخه جدید</Button><Button href={`/control/scenarios/${scenario.id}/versions`} variant="ghost">تاریخچه نسخه‌ها</Button>{scenario.status !== 'ARCHIVED' && <Button variant="danger" onClick={() => setSelected({ id: scenario.id, title: scenario.title })}>حذف / بایگانی</Button>}</div></td></tr>)}</>
    </DataTable>}
    {selected && <ConfirmDialog title="بایگانی سناریو" description={`سناریوی «${selected.title}» بایگانی می‌شود. نسخه‌ها، گراف و سوابق حفظ می‌شوند و اگر اجرای فعال داشته باشد عملیات رد خواهد شد.`} confirmLabel={archive.isPending ? 'در حال بایگانی...' : 'تأیید بایگانی'} onClose={() => { if (!archive.isPending) setSelected(null); }} onConfirm={() => archive.mutate(selected)} />}
  </>;
}
