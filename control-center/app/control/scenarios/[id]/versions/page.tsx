'use client';
import { useQuery } from '@tanstack/react-query';
import { use } from 'react';
import { scenarioApi } from '@/lib/scenario-api';
import { Button, DataTable, ErrorState, LoadingState, PageHeader } from '@/components/ui';

export default function VersionsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const result = useQuery({ queryKey: ['scenarios', id, 'versions'], queryFn: () => scenarioApi.versions(id) });
  if (result.isPending) return <LoadingState />;
  if (result.isError) return <ErrorState message="تاریخچه نسخه‌ها بارگذاری نشد." onRetry={() => void result.refetch()} />;
  return <><PageHeader eyebrow="VERSION HISTORY" title="تاریخچه نسخه‌ها" description="نسخه‌های منتشرشده فقط خواندنی هستند. نشست‌ها نسخه انتخاب‌شده در زمان ثبت‌نام را حفظ می‌کنند." actions={<Button href={`/control/scenarios/${id}`}>ویرایش Draft</Button>} />
    <DataTable headers={['نسخه', 'وضعیت', 'تاریخ انتشار', 'اپراتور', 'نشست‌ها', 'عملیات']}>
      {result.data.map((version) => <tr key={version.id}>
        <td className="px-5 py-4"><strong>نسخه {version.version_no}</strong><div className="mt-1 text-xs text-[var(--nexo-muted)]" dir="ltr">{version.id}</div></td>
        <td className="px-5 py-4"><span className="rounded-full bg-white/5 px-3 py-1 text-xs">{version.status === 'DRAFT' ? 'Draft · در حال ویرایش' : version.status === 'PUBLISHED' ? 'Published · فقط خواندنی' : 'بایگانی · فقط خواندنی'}</span></td>
        <td className="px-5 py-4 text-sm">{version.published_at ? new Date(version.published_at).toLocaleString('fa-IR') : '—'}</td>
        <td className="px-5 py-4 text-xs" dir="ltr">{version.published_by ?? '—'}</td>
        <td className="px-5 py-4">{version.usage_count ?? '—'}</td>
        <td className="px-5 py-4"><Button href={`/control/scenarios/${id}/builder?version=${version.id}`} variant="ghost">{version.status === 'DRAFT' ? 'ویرایش Draft' : 'مشاهده نسخه منتشرشده'}</Button></td>
      </tr>)}
    </DataTable></>;
}
