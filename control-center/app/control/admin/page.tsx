'use client';

import { useQuery } from '@tanstack/react-query';
import { ClipboardList, Database, MonitorSmartphone, ServerCog, type LucideIcon } from 'lucide-react';
import { queries } from '@/lib/queries';
import { EmptyState, ErrorState, HealthIndicator, LoadingState, PageHeader, SectionHeader, StatusBadge } from '@/components/ui';

const modules: Array<[string, string, LucideIcon]> = [
  ['دستگاه‌ها', 'شناسه، آخرین مشاهده و آمادگی دستگاه‌ها', MonitorSmartphone],
  ['گزارش حسابرسی', 'خط زمانی actor، action و entity', ClipboardList],
];

export default function AdminPage() {
  const me = useQuery(queries.me());
  const health = useQuery(queries.health());
  if (me.isPending || health.isPending) return <LoadingState label="در حال آماده‌سازی مدیریت..." />;
  if (me.isError || health.isError) return <ErrorState message="اطلاعات مدیریت در دسترس نیست." onRetry={() => { void me.refetch(); void health.refetch(); }} />;
  return <><PageHeader eyebrow="ADMINISTRATION" title="مدیریت و سلامت سیستم" description="مدیریت سازمانی و مشاهده وضعیت سرویس‌ها؛ اختیار نهایی با Laravel است." /><section className="nexo-surface rounded-xl p-5"><SectionHeader title="زمینه اپراتور" description="اطلاعات هویتی فعلی از endpoint احراز هویت خوانده می‌شود." /><div className="flex flex-wrap items-center gap-4"><div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[var(--nexo-amber-soft)] font-bold text-[var(--nexo-amber)]">{me.data.name.slice(0, 1)}</div><div><div className="font-semibold">{me.data.name}</div><div className="mt-1 text-xs text-[var(--nexo-muted)]">{me.data.email ?? me.data.mobile ?? 'اطلاعات تماس ثبت نشده'}</div></div><StatusBadge status={me.data.status} /></div></section><div className="mt-6 grid gap-4 md:grid-cols-2">{modules.map(([title, description, Icon]) => <div key={String(title)} className="nexo-surface nexo-hover rounded-xl p-5"><Icon size={21} className="text-[var(--nexo-amber)]" /><h2 className="mt-4 font-bold">{title}</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">{description}</p><span className="mt-4 inline-flex rounded-full bg-white/5 px-3 py-1 text-xs text-[var(--nexo-muted)]">در انتظار API اختصاصی</span></div>)}</div><section className="mt-6 nexo-surface rounded-xl p-5"><SectionHeader title="سلامت سرویس‌ها" description="فقط checkهای ارائه‌شده توسط endpoint سلامت نمایش داده می‌شوند." /><div className="grid gap-3 md:grid-cols-2">{Object.entries(health.data.checks ?? {}).map(([key, value]) => <HealthIndicator key={key} label={key} status={value === 'ok' ? 'ok' : 'degraded'} />)}{!health.data.checks && <EmptyState title="جزئیات سلامت ارائه نشده" description="endpoint فعلی فقط وضعیت کلی API را برمی‌گرداند." />}</div><div className="mt-4 flex items-center gap-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] px-4 py-3 text-sm"><ServerCog size={18} className="text-[var(--nexo-info)]" />وضعیت کلی API: <StatusBadge status={health.data.status === 'ok' ? 'ACTIVE' : 'PAUSED'} /><Database size={18} className="mr-auto text-[var(--nexo-muted)]" /></div></section></>;
}
