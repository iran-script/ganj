'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/ui';

export default function TeamReportsPage() {
  const sessions = useQuery(queries.sessions());
  if (sessions.isPending) return <LoadingState label="در حال آماده‌سازی گزارش تیم‌ها..." />;
  if (sessions.isError) return <ErrorState message="گزارش تیم‌ها در دسترس نیست." onRetry={() => void sessions.refetch()} />;
  const teams = sessions.data.flatMap((session) => (session.teams ?? []).map((team) => ({ ...team, sessionId: session.id, sessionTitle: session.title ?? session.id.slice(0, 8) })));
  return <><PageHeader eyebrow="REPORTS / TEAMS" title="گزارش تیم‌ها" description="داده‌های تیمی موجود در payload جلسه‌ها." />{teams.length === 0 ? <EmptyState title="داده تیمی برای گزارش وجود ندارد" description="برای گزارش مستقل تیم‌ها به endpoint اختصاصی نیاز است." /> : <DataTable headers={['تیم', 'جلسه', 'اعضا', 'امتیاز', 'وضعیت']}><>{teams.map((team) => <tr key={`${team.sessionId}-${team.id}`} className="hover:bg-white/[2%]"><td className="px-5 py-4"><Link className="font-medium text-white hover:text-[var(--nexo-amber)]" href={`/control/reports/teams/${team.id}`}>{team.name ?? 'تیم بدون نام'}</Link></td><td className="px-5 py-4 text-[var(--nexo-muted)]">{team.sessionTitle}</td><td className="px-5 py-4 text-[var(--nexo-muted)]">{team.member_count ?? team.player_count ?? '—'}</td><td className="px-5 py-4 text-[var(--nexo-amber)]">{team.score?.score ?? '—'}</td><td className="px-5 py-4 text-[var(--nexo-muted)]">{team.status ?? '—'}</td></tr>)}</></DataTable>}</>;
}
