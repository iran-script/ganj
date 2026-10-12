'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { queries } from '@/lib/queries';
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function LiveSessionsPage() {
  const query = useQuery(queries.sessions());
  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  const sessions = query.data.filter((session) => ['READY', 'RUNNING', 'PAUSED'].includes(session.status));
  return <><PageHeader eyebrow="LIVE CONTROL" title="کنترل زنده" description="جلسه‌های آماده یا در حال اجرای سازمان" />{sessions.length === 0 ? <EmptyState title="جلسه عملیاتی فعالی وجود ندارد" description="پس از آماده یا شروع شدن یک جلسه، کنترل زنده از اینجا در دسترس است." /> : <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{sessions.map((session) => <Link key={session.id} href={`/control/live/${session.id}`} className="rounded-[var(--nexo-radius)] border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5 transition hover:border-[var(--nexo-amber)]/60"><div className="flex items-center justify-between"><StatusBadge status={session.status} /><span className="text-xs text-[var(--nexo-muted)]">اتاق کنترل ←</span></div><h2 className="mt-6 font-bold">{session.title ?? session.game?.title ?? session.game?.name ?? 'جلسه بدون عنوان'}</h2><p className="mt-2 text-xs text-[var(--nexo-muted)]">{session.teams_count ?? session.team_count ?? '—'} تیم · polling هر ۱۵ ثانیه</p></Link>)}</div>}</>;
}
