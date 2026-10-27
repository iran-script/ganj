'use client';

import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/ui';

export default function AdminAuditPage() {
  const capabilities = useQuery({ queryKey: ['me', 'capabilities'], queryFn: managementApi.capabilities });
  const [input, setInput] = useState('');
  const [search, setSearch] = useState('');
  const [action, setAction] = useState('');
  const [resourceType, setResourceType] = useState('');
  const [actorId, setActorId] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => {
    const timer = window.setTimeout(() => { setSearch(input.trim()); setPage(1); }, 300);
    return () => window.clearTimeout(timer);
  }, [input]);

  const events = useQuery({
    queryKey: ['management-audit', page, search, action, resourceType, actorId, organizationId, from, to],
    queryFn: () => managementApi.auditEvents({ page, limit: 25, q: search, action, resource_type: resourceType, actor_id: actorId, organization_id: organizationId, from, to }),
    enabled: capabilities.data?.capabilities.includes('admin.audit.view') === true,
  });
  const canViewAudit = capabilities.data?.capabilities.includes('admin.audit.view') === true;
  const pagination = events.data?.meta.pagination;
  const resetPage = () => setPage(1);

  return <div dir="rtl">
    <PageHeader eyebrow="ADMIN / AUDIT" title="گزارش رویدادهای مدیریتی" description="جست‌وجو و بررسی تاریخچهٔ عملیات مدیریتی با پالایش و صفحه‌بندی. داده‌های حساس نمایش داده نمی‌شوند." />
    {capabilities.isPending ? <LoadingState label="در حال بررسی دسترسی مدیریتی…" /> : capabilities.isError ? <ErrorState message="بررسی دسترسی مدیریتی انجام نشد." onRetry={() => void capabilities.refetch()} /> : !capabilities.data.capabilities.includes('admin.audit.view') ? <ErrorState message="این گزارش فقط برای SUPER_ADMIN در دسترس است." /> : null}
    {capabilities.isPending || capabilities.isError || !canViewAudit ? null : <>
    <section aria-label="Audit filters" className="mb-5 grid gap-3 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-4 sm:grid-cols-2 xl:grid-cols-4">
      <label className="text-sm">جست‌وجو<input aria-label="جست‌وجوی رویدادها" className="nexo-input mt-2" value={input} onChange={(event) => setInput(event.target.value)} placeholder="عملیات، نوع منبع یا شناسه" /></label>
      <label className="text-sm">عملیات<input aria-label="پالایش بر اساس عملیات" className="nexo-input mt-2" value={action} onChange={(event) => { setAction(event.target.value); resetPage(); }} placeholder="user.created" /></label>
      <label className="text-sm">نوع منبع<input aria-label="پالایش بر اساس نوع منبع" className="nexo-input mt-2" value={resourceType} onChange={(event) => { setResourceType(event.target.value); resetPage(); }} placeholder="user" /></label>
      <label className="text-sm">شناسهٔ عامل<input aria-label="پالایش بر اساس عامل" className="nexo-input mt-2" value={actorId} onChange={(event) => { setActorId(event.target.value); resetPage(); }} placeholder="شناسهٔ عمومی کاربر" /></label>
      <label className="text-sm">شناسهٔ سازمان<input aria-label="پالایش بر اساس سازمان" className="nexo-input mt-2" value={organizationId} onChange={(event) => { setOrganizationId(event.target.value); resetPage(); }} placeholder="شناسهٔ عمومی سازمان" /></label>
      <label className="text-sm">از تاریخ<input aria-label="از تاریخ" type="date" className="nexo-input mt-2" value={from} onChange={(event) => { setFrom(event.target.value); resetPage(); }} /></label>
      <label className="text-sm">تا تاریخ<input aria-label="تا تاریخ" type="date" className="nexo-input mt-2" value={to} onChange={(event) => { setTo(event.target.value); resetPage(); }} /></label>
    </section>

    {events.isPending ? <LoadingState label="در حال دریافت تاریخچه…" /> : events.isError ? <ErrorState message="دریافت تاریخچه انجام نشد." onRetry={() => void events.refetch()} /> : events.data.data.length === 0 ? <EmptyState title="رویدادی پیدا نشد" description="عبارت جست‌وجو یا پالایه‌ها را تغییر دهید." /> : <>
      <DataTable headers={['عامل', 'عملیات', 'منبع', 'سازمان', 'زمان']}>
        {events.data.data.map((event) => <tr key={`${event.occurred_at}-${event.action}-${event.resource_id}`}>
          <td className="px-4 py-4 font-mono text-xs">{event.actor_id ?? event.actor_type}</td>
          <td className="px-4 py-4 font-semibold">{event.action}</td>
          <td className="px-4 py-4"><div>{event.resource_type}</div><div className="font-mono text-xs text-[var(--nexo-muted)]">{event.resource_id}</div></td>
          <td className="px-4 py-4 font-mono text-xs">{event.organization_id ?? 'سراسری'}</td>
          <td className="px-4 py-4 text-xs">{new Date(event.occurred_at).toLocaleString('fa-IR')}</td>
        </tr>)}
      </DataTable>
      <div className="mt-4 flex items-center justify-between">
        <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>قبلی</Button>
        <span className="text-xs text-[var(--nexo-muted)]">صفحهٔ {pagination?.page ?? page} از {pagination?.last_page ?? 1} · {pagination?.total ?? 0} رویداد</span>
        <Button variant="secondary" disabled={!pagination || page >= pagination.last_page} onClick={() => setPage((value) => value + 1)}>بعدی</Button>
      </div>
    </>}
    </>}
  </div>;
}
