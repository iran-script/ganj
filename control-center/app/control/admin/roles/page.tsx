'use client';

import { useQuery } from '@tanstack/react-query';
import { KeyRound, ShieldCheck, Users } from 'lucide-react';
import { managementApi } from '@/lib/management-api';
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/ui';

export default function AdminRolesPage() {
  const roles = useQuery({ queryKey: ['management-roles'], queryFn: managementApi.roles });
  if (roles.isPending) return <LoadingState label="در حال دریافت مدل نقش‌ها..." />;
  if (roles.isError) return <ErrorState message="مدل نقش‌ها دریافت نشد یا دسترسی مدیریتی ندارید." onRetry={() => void roles.refetch()} />;
  return <div dir="rtl">
    <PageHeader eyebrow="ADMIN / AUTHORIZATION" title="نقش‌ها و محدوده دسترسی" description="نمایش مدل واقعی مجوزها از API؛ Policy و Scope سمت Laravel مرجع تصمیم نهایی است." />
    <div className="mb-5 grid gap-3 sm:grid-cols-2"><div className="nexo-surface rounded-xl p-4"><div className="flex items-center gap-2 font-semibold"><ShieldCheck size={18} className="text-[var(--nexo-amber)]" />نقش سراسری</div><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">SUPER_ADMIN از role_assignments می‌آید. فقط مدیر سراسری می‌تواند آن را واگذار یا لغو کند؛ آخرین مدیر فعال محافظت می‌شود.</p></div><div className="nexo-surface rounded-xl p-4"><div className="flex items-center gap-2 font-semibold"><Users size={18} className="text-[var(--nexo-info)]" />نقش سازمانی</div><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">ORGANIZATION_ADMIN، GAMEMASTER و STAFF در عضویت سازمان هستند؛ دسترسی هر نقش به سازمان یا نشست وابسته است.</p></div></div>
    {roles.data.roles.length === 0 ? <EmptyState title="نقشی در دسترس نیست" description="API هیچ نقش تعریف‌شده‌ای برنگرداند." /> : <DataTable headers={['نقش ثابت محصول', 'محدوده', 'توضیح', 'انتساب فعال']}>
      {roles.data.roles.map((role) => <tr key={role.code}><td className="px-4 py-4 font-bold"><div className="flex items-center gap-2"><KeyRound size={15} className="text-[var(--nexo-amber)]" />{role.code}</div></td><td className="px-4 py-4">{role.scope}</td><td className="px-4 py-4 text-sm text-[var(--nexo-muted)]">{role.description}{role.resource_assignments_count !== undefined && <div className="mt-1 text-xs">تخصیص بازی/نشست: {role.resource_assignments_count.toLocaleString('fa-IR')}</div>}</td><td className="px-4 py-4">{role.assignments_count.toLocaleString('fa-IR')}</td></tr>)}
    </DataTable>}
    <section className="mt-6"><h2 className="mb-3 font-bold">تاریخچه تغییر نقش</h2>{roles.data.audit.length ? <DataTable headers={['عامل', 'عملیات', 'هدف', 'سازمان', 'دلیل', 'زمان']}>{roles.data.audit.map((event, index) => <tr key={`${event.action}-${event.occurred_at}-${index}`}><td className="px-4 py-3 font-mono text-xs">{event.actor_id ?? 'سیستم'}</td><td className="px-4 py-3">{event.action}</td><td className="px-4 py-3 font-mono text-xs">{event.target_id ?? event.resource_id}</td><td className="px-4 py-3 font-mono text-xs">{event.organization_id ?? 'سراسری'}</td><td className="px-4 py-3">{event.reason ?? '—'}</td><td className="px-4 py-3">{new Date(event.occurred_at).toLocaleString('fa-IR')}</td></tr>)}</DataTable> : <p className="text-sm text-[var(--nexo-muted)]">تغییر نقشی ثبت نشده است.</p>}</section>
    <aside className="mt-5 rounded-xl border border-[var(--nexo-info)]/30 bg-[var(--nexo-info)]/5 p-4 text-sm leading-6 text-[var(--nexo-muted)]"><strong className="text-white">ویرایش permission پویا یا ساخت role دلخواه فعال نیست.</strong> نقش‌ها و مجوزها ثابت‌اند؛ API برای مشاهده همین مدل و انتساب‌های مجاز استفاده می‌شود.</aside>
  </div>;
}
