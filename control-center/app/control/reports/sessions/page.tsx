'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function SessionReportsPage() {
  const sessions = useQuery(queries.sessions());
  if (sessions.isPending) return <LoadingState label="در حال آماده‌سازی گزارش جلسه‌ها..." />;
  if (sessions.isError) return <ErrorState message="گزارش جلسه‌ها در دسترس نیست." onRetry={() => void sessions.refetch()} />;
  return <><PageHeader eyebrow="REPORTS / SESSIONS" title="گزارش جلسه‌ها" description="مرور وضعیت و داده‌های عملیاتی هر جلسه." />{sessions.data.length === 0 ? <EmptyState title="جلسه‌ای برای گزارش وجود ندارد" description="پس از ثبت جلسه، گزارش آن در اینجا نمایش داده می‌شود." /> : <DataTable headers={['جلسه', 'بازی', 'وضعیت', 'تیم‌ها', 'زمان پایان']}><>{sessions.data.map((session) => <tr key={session.id} className="hover:bg-white/[2%]"><td className="px-5 py-4"><Link className="font-medium text-white hover:text-[var(--nexo-amber)]" href={`/control/reports/sessions/${session.id}`}>{session.title ?? `جلسه ${session.id.slice(0, 8)}`}</Link></td><td className="px-5 py-4 text-[var(--nexo-muted)]">{session.game?.title ?? session.game?.name ?? '—'}</td><td className="px-5 py-4"><StatusBadge status={session.status} /></td><td className="px-5 py-4 text-[var(--nexo-muted)]">{session.teams_count ?? session.team_count ?? '—'}</td><td className="px-5 py-4 text-xs text-[var(--nexo-muted)]">{session.finished_at ? new Date(session.finished_at).toLocaleString('fa-IR') : 'ثبت نشده'}</td></tr>)}</></DataTable>}</>;
}
