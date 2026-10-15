'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { api } from '@/lib/api-client';
import type { Organization } from '@/types/api';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, SearchInput, StatusBadge } from '@/components/ui';

export default function AdminUsersPage() {
  const [input, setInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [role, setRole] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  useEffect(() => { const timer = window.setTimeout(() => { setSearch(input.trim()); setCursor(undefined); setHistory([]); }, 300); return () => window.clearTimeout(timer); }, [input]);
  const organizations = useQuery({ queryKey: ['admin-user-organizations'], queryFn: () => api.get<Organization[]>('/organizations') });
  const users = useQuery({ queryKey: ['management-users', cursor ?? null, search, status, role, organizationId], queryFn: () => managementApi.users({ cursor, q: search, status, role, organization_id: organizationId }) });
  const pagination = users.data?.meta?.pagination;
  const resetFilters = () => { setCursor(undefined); setHistory([]); };
  return <div dir="rtl">
    <PageHeader eyebrow="ADMIN / GLOBAL USERS" title="کاربران سامانه" description="فهرست سراسری واقعی با جست‌وجوی سرور؛ مشاهده اطلاعات اداری فقط برای SUPER_ADMIN مجاز است." />
    <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <SearchInput label="جست‌وجوی نام، ایمیل یا موبایل" value={input} onChange={setInput} />
      <label className="text-sm">وضعیت حساب<select className="nexo-input mt-2" value={status} onChange={(event) => { setStatus(event.target.value); resetFilters(); }}><option value="">همه وضعیت‌ها</option><option value="ACTIVE">فعال</option><option value="SUSPENDED">معلق</option><option value="BLOCKED">مسدود</option></select></label>
      <label className="text-sm">نقش سراسری<select className="nexo-input mt-2" value={role} onChange={(event) => { setRole(event.target.value); resetFilters(); }}><option value="">همه نقش‌ها</option><option value="SUPER_ADMIN">SUPER_ADMIN</option></select></label>
      <label className="text-sm">سازمان<select className="nexo-input mt-2" value={organizationId} onChange={(event) => { setOrganizationId(event.target.value); resetFilters(); }}><option value="">همه سازمان‌ها</option>{(organizations.data ?? []).map((organization) => <option key={organization.id} value={organization.id}>{organization.name ?? organization.id}</option>)}</select></label>
    </div>
    {users.isPending ? <LoadingState label="در حال دریافت کاربران از API..." /> : users.isError ? <ErrorState message="دریافت فهرست کاربران ممکن نشد یا دسترسی مدیریتی ندارید." onRetry={() => void users.refetch()} /> : users.data.data.length === 0 ? <EmptyState title="کاربری پیدا نشد" description="جست‌وجو یا فیلترها را تغییر دهید." /> : <>
      <DataTable headers={['نام', 'ایمیل / موبایل', 'نقش سراسری', 'سازمان‌ها', 'وضعیت', 'ثبت‌نام']}>
        {users.data.data.map((user) => <tr key={user.id}><td className="px-4 py-4 font-semibold"><Link className="text-[var(--nexo-amber)] hover:underline" href={`/control/admin/users/${user.id}`}>{user.name}</Link></td><td className="px-4 py-4 text-xs text-[var(--nexo-muted)]"><div>{user.email ?? '—'}</div><div dir="ltr">{user.mobile ?? '—'}</div></td><td className="px-4 py-4">{user.global_roles?.join('، ') || '—'}</td><td className="px-4 py-4">{user.organization_memberships?.map((membership) => membership.organization.name).join('، ') || '—'}</td><td className="px-4 py-4"><StatusBadge status={user.status} /></td><td className="px-4 py-4">{user.created_at ? new Date(user.created_at).toLocaleDateString('fa-IR') : '—'}</td></tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={!history.length} onClick={() => { const previous = history.at(-1); if (previous !== undefined) { setHistory((items) => items.slice(0, -1)); setCursor(previous || undefined); } }}>صفحه قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{users.data.data.length} کاربر در این صفحه{pagination?.has_more ? ' · صفحه بعد موجود است' : ''}</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={() => { if (pagination?.next_cursor) { setHistory((items) => [...items, cursor ?? '']); setCursor(pagination.next_cursor); } }}>صفحه بعد</Button></div>
    </>}
  </div>;
}
