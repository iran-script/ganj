'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, SearchInput, StatusBadge } from '@/components/ui';

export default function AdminAdministratorsPage() {
  const cache = useQueryClient();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  const [userId, setUserId] = useState('');
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState<{ id: string; action: 'grant' | 'revoke' } | null>(null);
  useEffect(() => { const timer = window.setTimeout(() => { setSearch(q.trim()); setCursor(undefined); setHistory([]); }, 300); return () => window.clearTimeout(timer); }, [q]);
  const admins = useQuery({ queryKey: ['management-admins', cursor ?? null, search], queryFn: () => managementApi.administrators({ cursor, q: search }) });
  const refresh = async () => { await cache.invalidateQueries({ queryKey: ['management-admins'] }); await cache.invalidateQueries({ queryKey: ['management-users'] }); };
  const grant = useMutation({ mutationFn: () => managementApi.grantAdministrator(userId.trim(), reason.trim()), onSuccess: async () => { setUserId(''); setReason(''); await refresh(); } });
  const revoke = useMutation({ mutationFn: (id: string) => managementApi.revokeAdministrator(id, reason.trim()), onSuccess: async () => { setConfirm(null); setReason(''); await refresh(); } });
  const pagination = admins.data?.meta?.pagination;
  return <div dir="rtl">
    <PageHeader eyebrow="ADMIN / GLOBAL ADMINISTRATORS" title="مدیران سراسری" description="این صفحه فقط انتساب SUPER_ADMIN سراسری را نمایش می‌دهد؛ نقش‌های سازمانی در صفحه عضویت همان سازمان مدیریت می‌شوند." />
    <form className="nexo-surface mb-5 grid gap-3 rounded-xl p-4 md:grid-cols-[1fr_2fr_auto]" onSubmit={(event) => { event.preventDefault(); setConfirm({ id: userId.trim(), action: 'grant' }); }}><label className="text-sm">شناسه کاربر موجود<input className="nexo-input mt-2" value={userId} onChange={(event) => setUserId(event.target.value)} required pattern="[0-9a-fA-F-]{36}" placeholder="UUID کاربر موجود" /></label><label className="text-sm">دلیل واگذاری<textarea className="nexo-input mt-2 min-h-10" value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={1000} /></label><Button type="submit" disabled={!userId.trim() || reason.trim().length < 3 || grant.isPending}>واگذاری SUPER_ADMIN</Button></form>
    {(grant.isError || revoke.isError) && <p role="alert" className="mb-4 text-sm text-[var(--nexo-danger)]">عملیات نقش انجام نشد. مجوز، شناسه و شرط آخرین مدیر فعال را بررسی کنید.</p>}
    <div className="mb-4"><SearchInput label="جست‌وجوی نام، ایمیل یا موبایل" value={q} onChange={setQ} /></div>
    {admins.isPending ? <LoadingState label="در حال دریافت مدیران سراسری..." /> : admins.isError ? <ErrorState message="دریافت مدیران سراسری ممکن نشد یا دسترسی ندارید." onRetry={() => void admins.refetch()} /> : admins.data.data.length === 0 ? <EmptyState title="مدیر فعالی پیدا نشد" description="این فهرست فقط مدیران سراسری فعال را نشان می‌دهد." /> : <>
      <DataTable headers={['مدیر', 'ایمیل / موبایل', 'نقش سراسری', 'سازمان‌ها', 'وضعیت', 'عملیات']}>
        {admins.data.data.map((admin) => <tr key={admin.id}><td className="px-4 py-4 font-semibold">{admin.name}<div className="mt-1 font-mono text-[10px] text-[var(--nexo-muted)]">{admin.id}</div></td><td className="px-4 py-4 text-xs"><div>{admin.email ?? '—'}</div><div dir="ltr">{admin.mobile ?? '—'}</div></td><td className="px-4 py-4">{admin.global_roles?.join('، ')}</td><td className="px-4 py-4">{admin.organization_memberships?.map((membership) => `${membership.organization.name} (${membership.role})`).join('، ') || '—'}</td><td className="px-4 py-4"><StatusBadge status={admin.status} /></td><td className="px-4 py-4"><Button variant="secondary" onClick={() => { setReason(''); setConfirm({ id: admin.id, action: 'revoke' }); }}>لغو نقش</Button></td></tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={!history.length} onClick={() => { const prev = history.at(-1); if (prev !== undefined) { setHistory((items) => items.slice(0, -1)); setCursor(prev || undefined); } }}>صفحه قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{admins.data.data.length} مدیر در این صفحه</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={() => { if (pagination?.next_cursor) { setHistory((items) => [...items, cursor ?? '']); setCursor(pagination.next_cursor); } }}>صفحه بعد</Button></div>
    </>}
    {confirm && <div role="dialog" aria-modal="true" aria-labelledby="admin-role-confirm" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-5"><div className="w-full max-w-md rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><h2 id="admin-role-confirm" className="font-bold">تأیید {confirm.action === 'grant' ? 'واگذاری' : 'لغو'} نقش سراسری</h2><p className="mt-3 break-all font-mono text-xs">{confirm.id}</p><label className="mt-4 block text-sm">دلیل<textarea autoFocus className="nexo-input mt-2 min-h-20" value={reason} onChange={(event) => setReason(event.target.value)} required minLength={3} maxLength={1000} /></label><div className="mt-5 flex justify-end gap-2"><Button variant="secondary" onClick={() => setConfirm(null)}>انصراف</Button><Button disabled={reason.trim().length < 3 || grant.isPending || revoke.isPending} onClick={() => confirm.action === 'grant' ? grant.mutate() : revoke.mutate(confirm.id)}>تأیید عملیات</Button></div></div></div>}
  </div>;
}
