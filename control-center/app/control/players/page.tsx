'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { queries } from '@/lib/queries';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, SearchInput, StatusBadge } from '@/components/ui';

export default function PlayersPage() {
  const [input, setInput] = useState('');
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [teamId, setTeamId] = useState('');
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  const capabilities = useQuery(queries.capabilities());
  useEffect(() => {
    const timer = window.setTimeout(() => { setQ(input.trim()); setCursor(undefined); setHistory([]); }, 300);
    return () => window.clearTimeout(timer);
  }, [input]);
  const players = useQuery({ queryKey: ['management-players', cursor ?? null, q, status, organizationId, teamId], queryFn: () => managementApi.players({ cursor, q, status, organization_id: organizationId || undefined, team_id: teamId || undefined }) });
  const pagination = players.data?.meta?.pagination;
  const organizations = capabilities.data?.organizations ?? [];
  const canCreate = capabilities.data?.global_roles.includes('SUPER_ADMIN') === true;

  return <div dir="rtl">
    <PageHeader eyebrow="PLAYER MANAGEMENT" title="مدیریت بازیکنان" description="فهرست، جست‌وجو و مدیریت امن حساب‌های بازیکن در محدودهٔ دسترسی شما." actions={canCreate ? <Button href="/control/admin/users">ایجاد حساب</Button> : undefined} />
    <div className="mb-5 grid gap-3 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-4 md:grid-cols-2 lg:grid-cols-4">
      <SearchInput label="جست‌وجوی نام، ایمیل، نام کاربری یا تلفن" value={input} onChange={setInput} />
      <label className="text-sm">وضعیت<select className="nexo-input mt-2" value={status} onChange={(event) => { setStatus(event.target.value); setCursor(undefined); setHistory([]); }}><option value="">همه</option><option value="ACTIVE">فعال</option><option value="SUSPENDED">معلق</option><option value="BLOCKED">مسدود</option></select></label>
      {organizations.length > 0 && <label className="text-sm">سازمان<select className="nexo-input mt-2" value={organizationId} onChange={(event) => { setOrganizationId(event.target.value); setCursor(undefined); setHistory([]); }}><option value="">همهٔ سازمان‌های مجاز</option>{organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}</select></label>}
      <label className="text-sm">شناسهٔ تیم (اختیاری)<input className="nexo-input mt-2" value={teamId} onChange={(event) => { setTeamId(event.target.value); setCursor(undefined); setHistory([]); }} placeholder="UUID تیم" /></label>
    </div>
    {players.isPending ? <LoadingState label="در حال دریافت بازیکنان…" /> : players.isError ? <ErrorState message="دریافت فهرست بازیکنان انجام نشد یا دسترسی کافی ندارید." onRetry={() => void players.refetch()} /> : players.data.data.length === 0 ? <EmptyState title="بازیکنی پیدا نشد" description="فیلترها را تغییر دهید یا بازیکن موردنظر را به یک تیم/جلسهٔ مجاز اضافه کنید." /> : <>
      <DataTable headers={['بازیکن', 'ایمیل / تلفن', 'وضعیت', 'تیم‌های فعال', 'آخرین ورود', 'عملیات']}>
        {players.data.data.map((player) => <tr key={player.id}><td className="px-4 py-4"><div className="font-semibold">{player.name}</div><div className="mt-1 font-mono text-xs text-[var(--nexo-muted)]">{player.id}</div></td><td className="px-4 py-4 text-xs text-[var(--nexo-muted)]">{player.email ?? '—'}<br />{player.mobile ?? '—'}</td><td className="px-4 py-4"><StatusBadge status={player.status} /></td><td className="px-4 py-4">{player.active_team_memberships_count ?? 0}</td><td className="px-4 py-4 text-xs text-[var(--nexo-muted)]">{player.last_login_at ? new Date(player.last_login_at).toLocaleString('fa-IR') : 'ثبت نشده'}</td><td className="px-4 py-4"><Link className="text-[var(--nexo-amber)] hover:underline" href={`/control/players/${player.id}`}>مشاهده و مدیریت</Link></td></tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={!history.length} onClick={() => { const previous = history.at(-1); if (previous !== undefined) { setHistory((items) => items.slice(0, -1)); setCursor(previous || undefined); } }}>صفحهٔ قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{players.data.data.length} بازیکن در این صفحه</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={() => { if (pagination?.next_cursor) { setHistory((items) => [...items, cursor ?? '']); setCursor(pagination.next_cursor ?? undefined); } }}>صفحهٔ بعد</Button></div>
    </>}
  </div>;
}
