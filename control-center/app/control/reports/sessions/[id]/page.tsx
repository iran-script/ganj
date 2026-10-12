'use client';

import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge, TimelineItem } from '@/components/ui';
import { ReviewRow } from '@/components/wizard';

export default function SessionReportDetailPage() {
  const { id } = useParams<{ id: string }>();
  const session = useQuery(queries.session(id));
  const timeline = useQuery(queries.timeline(id));
  if (session.isPending) return <LoadingState label="در حال دریافت گزارش جلسه..." />;
  if (session.isError) return <ErrorState message="جزئیات گزارش جلسه در دسترس نیست." onRetry={() => void session.refetch()} />;
  return <><PageHeader eyebrow="REPORTS / SESSION DETAIL" title={session.data.title ?? 'گزارش جلسه'} description="این صفحه فقط داده‌های ارائه‌شده توسط API را نمایش می‌دهد و نتیجه‌ای را در مرورگر محاسبه نمی‌کند." /><div className="grid gap-5 xl:grid-cols-[1.2fr_.8fr]"><section className="nexo-surface rounded-xl p-5"><div className="mb-5 flex items-center justify-between"><h2 className="font-bold">خلاصه جلسه</h2><StatusBadge status={session.data.status} /></div><dl className="grid gap-3 sm:grid-cols-2"><ReviewRow label="بازی" value={session.data.game?.title ?? session.data.game?.name ?? '—'} /><ReviewRow label="تعداد تیم‌ها" value={String(session.data.teams_count ?? session.data.team_count ?? '—')} /><ReviewRow label="شروع" value={session.data.started_at ? new Date(session.data.started_at).toLocaleString('fa-IR') : 'ثبت نشده'} /><ReviewRow label="پایان" value={session.data.finished_at ? new Date(session.data.finished_at).toLocaleString('fa-IR') : 'ثبت نشده'} /></dl></section><section className="nexo-surface rounded-xl p-5"><h2 className="font-bold">نتیجه نهایی</h2><p className="mt-3 text-sm leading-7 text-[var(--nexo-muted)]">تجمیع امتیاز، مدت زمان و هشدارهای نهایی به endpoint گزارش رسمی نیاز دارد.</p><EmptyState title="نتیجه تجمیعی در دسترس نیست" description="داده خام جلسه نمایش داده می‌شود؛ محاسبه نتیجه در فرانت‌اند انجام نمی‌شود." /></section></div><section className="nexo-surface mt-5 rounded-xl p-5"><h2 className="mb-5 font-bold">خط زمانی</h2>{timeline.isPending ? <LoadingState label="در حال دریافت رویدادها..." /> : timeline.isError ? <ErrorState message="خط زمانی در دسترس نیست." onRetry={() => void timeline.refetch()} /> : timeline.data.length === 0 ? <EmptyState title="رویدادی ثبت نشده" /> : <div>{timeline.data.map((event, index) => <TimelineItem key={event.event_id ?? index} event={event} />)}</div>}</section></>;
}
