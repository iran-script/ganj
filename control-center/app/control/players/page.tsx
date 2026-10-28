'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { queries } from '@/lib/queries';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, SearchInput, StatusBadge } from '@/components/ui';

export default function PlayersPage() {
  const cache = useQueryClient();
  const [input, setInput] = useState('');
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  const capabilities = useQuery(queries.capabilities());
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', mobile: '', password: '', reason: '' });
  const [notice, setNotice] = useState<string | null>(null);
  const canSearchContact = capabilities.data?.capabilities.includes('admin.users.view') || capabilities.data?.capabilities.includes('organization.members.manage');
  useEffect(() => { const timer = window.setTimeout(() => { setQ(input.trim()); setCursor(undefined); setHistory([]); }, 300); return () => window.clearTimeout(timer); }, [input]);
  const players = useQuery({ queryKey: ['management-players', cursor ?? null, q, status], queryFn: () => managementApi.players({ cursor, q, status }) });
  const create = useMutation({ mutationFn: () => managementApi.createUser({ ...form, email: form.email || null, mobile: form.mobile || null }), onSuccess: async () => { setCreateOpen(false); setForm({ name: '', email: '', mobile: '', password: '', reason: '' }); setNotice('بازیکن با موفقیت افزوده شد.'); await cache.invalidateQueries({ queryKey: ['management-players'] }); } });
  const pagination = players.data?.meta?.pagination;
  const isSuperAdmin = capabilities.data?.global_roles.includes('SUPER_ADMIN') === true;
  const canCreate = form.name.trim().length >= 2 && form.password.length >= 12 && form.reason.trim().length >= 3 && (form.email.trim().length > 0 || form.mobile.trim().length > 0);
  return <div dir="rtl">
    <PageHeader eyebrow="PLAYER ADMINISTRATION" title="بازیکنان" description="فهرست بازیکنان از حساب‌های پیوندخورده به تیم‌ها و مشارکت‌های نشست ساخته می‌شود؛ داده‌ها به محدوده مجاز سرور محدودند." actions={isSuperAdmin ? <Button onClick={() => setCreateOpen(true)}>افزودن بازیکن</Button> : undefined} />
    {notice && <p role="status" className="mb-4 rounded-lg border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/10 p-3 text-sm text-[var(--nexo-success)]">{notice}</p>}
    <div className={`mb-5 grid gap-3 ${canSearchContact ? 'sm:grid-cols-[1fr_220px]' : ''}`}><SearchInput label={canSearchContact ? 'جست‌وجوی نام، ایمیل یا موبایل' : 'جست‌وجوی نام یا شناسه عمومی'} value={input} onChange={setInput} />{canSearchContact && <label className="text-sm">وضعیت حساب<select className="nexo-input mt-2" value={status} onChange={(event) => { setStatus(event.target.value); setCursor(undefined); setHistory([]); }}><option value="">همه وضعیت‌ها</option><option value="ACTIVE">فعال</option><option value="SUSPENDED">معلق</option><option value="BLOCKED">مسدود</option></select></label>}</div>
    {players.isPending ? <LoadingState label="در حال دریافت بازیکنان..." /> : players.isError ? <ErrorState message="فهرست بازیکنان دریافت نشد یا دسترسی محدوده‌ای ندارید." onRetry={() => void players.refetch()} /> : players.data.data.length === 0 ? <EmptyState title="بازیکنی پیدا نشد" description="حسابی با مشارکت تیمی یا نشست در محدوده مجاز وجود ندارد." action={isSuperAdmin ? <Button onClick={() => setCreateOpen(true)}>افزودن اولین بازیکن</Button> : undefined} /> : <>
      <DataTable headers={['بازیکن', 'شناسه حساب', 'وضعیت حساب', 'ارتباط / مشارکت', 'عملیات']}>
        {players.data.data.map((player) => <tr key={player.id}><td className="px-4 py-4 font-semibold">{player.name}</td><td className="px-4 py-4 font-mono text-xs text-[var(--nexo-muted)]">{player.id}</td><td className="px-4 py-4">{player.status ? <StatusBadge status={player.status} /> : '—'}</td><td className="px-4 py-4">{player.active_team_memberships_count ?? '—'}</td><td className="px-4 py-4"><Link className="text-[var(--nexo-amber)] hover:underline" href={`/control/players/${player.id}`}>جزئیات</Link></td></tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={!history.length} onClick={() => { const previous = history.at(-1); if (previous !== undefined) { setHistory((items) => items.slice(0, -1)); setCursor(previous || undefined); } }}>صفحه قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{players.data.data.length} بازیکن در این صفحه</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={() => { if (pagination?.next_cursor) { setHistory((items) => [...items, cursor ?? '']); setCursor(pagination.next_cursor); } }}>صفحه بعد</Button></div>
    </>}
    {createOpen && <div role="dialog" aria-modal="true" aria-labelledby="create-player-title" className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/70 p-5"><form className="w-full max-w-xl rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5" onSubmit={(event) => { event.preventDefault(); if (canCreate) create.mutate(); }}><h2 id="create-player-title" className="text-lg font-bold">افزودن بازیکن</h2><p className="mt-2 text-sm text-[var(--nexo-muted)]">حساب جدید ایجاد می‌شود و سابقه بازی مستقل آن حفظ خواهد شد.</p><div className="mt-4 grid gap-3 sm:grid-cols-2"><label className="text-sm">نام<input required minLength={2} maxLength={255} className="nexo-input mt-2" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label><label className="text-sm">ایمیل<input type="email" className="nexo-input mt-2" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label><label className="text-sm">موبایل<input className="nexo-input mt-2" value={form.mobile} onChange={(event) => setForm({ ...form, mobile: event.target.value })} /></label><label className="text-sm">رمز عبور<input required minLength={12} type="password" className="nexo-input mt-2" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /></label><label className="text-sm sm:col-span-2">دلیل عملیات<textarea required minLength={3} maxLength={1000} className="nexo-input mt-2 min-h-20" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} /></label></div>{!form.email.trim() && !form.mobile.trim() && <p className="mt-3 text-xs text-[var(--nexo-warning)]">حداقل یکی از ایمیل یا موبایل الزامی است.</p>}{create.isError && <p role="alert" className="mt-3 text-sm text-[var(--nexo-danger)]">افزودن بازیکن انجام نشد؛ اطلاعات ورود و مجوز را بررسی کنید.</p>}{create.isSuccess && <p role="status" className="mt-3 text-sm text-[var(--nexo-success)]">بازیکن با موفقیت افزوده شد.</p>}<div className="mt-5 flex justify-end gap-2"><Button variant="secondary" onClick={() => setCreateOpen(false)}>انصراف</Button><Button type="submit" disabled={create.isPending || !canCreate}>{create.isPending ? 'در حال افزودن...' : 'افزودن بازیکن'}</Button></div></form></div>}
  </div>;
}
