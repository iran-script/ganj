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
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  const capabilities = useQuery(queries.capabilities());
  const canSearchContact = capabilities.data?.capabilities.includes('admin.users.view') || capabilities.data?.capabilities.includes('organization.members.manage');
  useEffect(() => { const timer = window.setTimeout(() => { setQ(input.trim()); setCursor(undefined); setHistory([]); }, 300); return () => window.clearTimeout(timer); }, [input]);
  const players = useQuery({ queryKey: ['management-players', cursor ?? null, q, status], queryFn: () => managementApi.players({ cursor, q, status }) });
  const pagination = players.data?.meta?.pagination;
  return <div dir="rtl">
    <PageHeader eyebrow="PLAYER ADMINISTRATION" title="بازیکنان" description="فهرست بازیکنان از حساب‌های پیوندخورده به تیم‌ها و مشارکت‌های نشست ساخته می‌شود؛ داده‌ها به محدوده مجاز سرور محدودند." />
    <div className={`mb-5 grid gap-3 ${canSearchContact ? 'sm:grid-cols-[1fr_220px]' : ''}`}><SearchInput label={canSearchContact ? 'جست‌وجوی نام، ایمیل یا موبایل' : 'جست‌وجوی نام یا شناسه عمومی'} value={input} onChange={setInput} />{canSearchContact && <label className="text-sm">وضعیت حساب<select className="nexo-input mt-2" value={status} onChange={(event) => { setStatus(event.target.value); setCursor(undefined); setHistory([]); }}><option value="">همه وضعیت‌ها</option><option value="ACTIVE">فعال</option><option value="SUSPENDED">معلق</option><option value="BLOCKED">مسدود</option></select></label>}</div>
    {players.isPending ? <LoadingState label="در حال دریافت بازیکنان..." /> : players.isError ? <ErrorState message="فهرست بازیکنان دریافت نشد یا دسترسی محدوده‌ای ندارید." onRetry={() => void players.refetch()} /> : players.data.data.length === 0 ? <EmptyState title="بازیکنی پیدا نشد" description="حسابی با مشارکت تیمی یا نشست در محدوده مجاز وجود ندارد." /> : <>
      <DataTable headers={['بازیکن', 'شناسه حساب', 'وضعیت حساب', 'ارتباط / مشارکت', 'عملیات']}>
        {players.data.data.map((player) => <tr key={player.id}><td className="px-4 py-4 font-semibold">{player.name}</td><td className="px-4 py-4 font-mono text-xs text-[var(--nexo-muted)]">{player.id}</td><td className="px-4 py-4">{player.status ? <StatusBadge status={player.status} /> : '—'}</td><td className="px-4 py-4">{player.active_team_memberships_count ?? '—'}</td><td className="px-4 py-4"><Link className="text-[var(--nexo-amber)] hover:underline" href={`/control/players/${player.id}`}>جزئیات</Link></td></tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={!history.length} onClick={() => { const previous = history.at(-1); if (previous !== undefined) { setHistory((items) => items.slice(0, -1)); setCursor(previous || undefined); } }}>صفحه قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{players.data.data.length} بازیکن در این صفحه</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={() => { if (pagination?.next_cursor) { setHistory((items) => [...items, cursor ?? '']); setCursor(pagination.next_cursor); } }}>صفحه بعد</Button></div>
    </>}
  </div>;
}
