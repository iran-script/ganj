'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, useRouter } from 'next/navigation';
import { managementApi } from '@/lib/management-api';
import { Button, DataTable, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function ManagedUserDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const cache = useQueryClient();
  const user = useQuery({ queryKey: ['management-user', id], queryFn: () => managementApi.user(id) });
  const [status, setStatus] = useState<'ACTIVE' | 'SUSPENDED' | 'BLOCKED'>('SUSPENDED');
  const [reason, setReason] = useState('');
  const [profile, setProfile] = useState({ name: '', email: '', mobile: '' });
  const [password, setPassword] = useState('');
  const invalidate = async () => { await Promise.all([cache.invalidateQueries({ queryKey: ['management-user', id] }), cache.invalidateQueries({ queryKey: ['management-users'] }), cache.invalidateQueries({ queryKey: ['management-admins'] })]); };
  const managedName = user.data?.name ?? '';
  const managedEmail = user.data?.email ?? null;
  const managedMobile = user.data?.mobile ?? null;
  const statusMutation = useMutation({ mutationFn: () => managementApi.setStatus(id, status, reason.trim()), onSuccess: invalidate });
  const updateMutation = useMutation({ mutationFn: () => managementApi.updateUser(id, { name: profile.name || managedName, email: profile.email || managedEmail, mobile: profile.mobile || managedMobile, reason: reason.trim() }), onSuccess: invalidate });
  const passwordMutation = useMutation({ mutationFn: () => managementApi.resetPassword(id, password, reason.trim()), onSuccess: () => { setPassword(''); void invalidate(); } });
  const deleteMutation = useMutation({ mutationFn: () => managementApi.deleteUser(id, reason.trim()), onSuccess: () => { router.push('/control/admin/users'); } });
  const restoreMutation = useMutation({ mutationFn: () => managementApi.restoreUser(id, reason.trim()), onSuccess: invalidate });
  const roleMutation = useMutation({ mutationFn: () => user.data?.global_roles?.includes('SUPER_ADMIN') ? managementApi.revokeAdministrator(id, reason.trim()) : managementApi.grantAdministrator(id, reason.trim()), onSuccess: invalidate });
  if (user.isPending) return <LoadingState label="در حال دریافت جزئیات کاربر..." />;
  if (user.isError) return <ErrorState message="جزئیات کاربر دریافت نشد." onRetry={() => void user.refetch()} />;
  const managed = user.data;
  const isAdmin = managed.global_roles?.includes('SUPER_ADMIN') === true;
  return <div dir="rtl">
    <PageHeader eyebrow="ADMIN / USER DETAIL" title={managed.name} description="تمام عملیات حساس با مجوز Backend و Audit Log محافظت می‌شوند." actions={<Button href="/control/admin/users" variant="secondary">بازگشت</Button>} />
    <section className="nexo-surface mb-5 grid gap-4 rounded-xl p-5 sm:grid-cols-2 xl:grid-cols-4"><div><span className="text-xs text-[var(--nexo-muted)]">ایمیل</span><div>{managed.email ?? '—'}</div></div><div><span className="text-xs text-[var(--nexo-muted)]">موبایل</span><div dir="ltr">{managed.mobile ?? '—'}</div></div><div><span className="text-xs text-[var(--nexo-muted)]">وضعیت</span><div><StatusBadge status={managed.status} /></div></div><div><span className="text-xs text-[var(--nexo-muted)]">نقش‌ها</span><div>{managed.global_roles?.join('، ') || '—'}</div></div></section>
    <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">ویرایش اطلاعات</h2><div className="mt-4 grid gap-3 md:grid-cols-3"><input className="nexo-input" placeholder={managed.name} value={profile.name} onChange={(event) => setProfile({ ...profile, name: event.target.value })} /><input className="nexo-input" placeholder={managed.email ?? 'ایمیل'} value={profile.email} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /><input className="nexo-input" placeholder={managed.mobile ?? 'موبایل'} value={profile.mobile} onChange={(event) => setProfile({ ...profile, mobile: event.target.value })} /></div><div className="mt-3 flex justify-end"><Button disabled={updateMutation.isPending || reason.trim().length < 3} onClick={() => updateMutation.mutate()}>ذخیره ویرایش</Button></div></section>
    <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">عملیات مدیریتی</h2><label className="mt-4 block text-sm">دلیل عملیات<textarea className="nexo-input mt-2 min-h-20" maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} /></label><div className="mt-4 flex flex-wrap gap-2"><select className="nexo-input max-w-xs" value={status} onChange={(event) => setStatus(event.target.value as typeof status)}><option value="ACTIVE">فعال‌سازی</option><option value="SUSPENDED">تعلیق</option><option value="BLOCKED">مسدودسازی</option></select><Button disabled={statusMutation.isPending || status === managed.status || reason.trim().length < 3} onClick={() => statusMutation.mutate()}>ثبت وضعیت</Button><Button variant="secondary" disabled={roleMutation.isPending || reason.trim().length < 3} onClick={() => roleMutation.mutate()}>{isAdmin ? 'لغو نقش SUPER_ADMIN' : 'اعطای SUPER_ADMIN'}</Button>{managed.deleted_at ? <Button disabled={restoreMutation.isPending || reason.trim().length < 3} onClick={() => restoreMutation.mutate()}>بازیابی کاربر</Button> : <Button variant="danger" disabled={deleteMutation.isPending || reason.trim().length < 3} onClick={() => { if (window.confirm('حذف نرم این کاربر انجام شود؟')) deleteMutation.mutate(); }}>حذف نرم</Button>}</div></section>
    <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">بازنشانی امن رمز</h2><div className="mt-4 flex flex-wrap gap-3"><input className="nexo-input max-w-sm" type="password" minLength={12} placeholder="رمز جدید حداقل ۱۲ کاراکتر" value={password} onChange={(event) => setPassword(event.target.value)} /><Button disabled={password.length < 12 || passwordMutation.isPending || reason.trim().length < 3} onClick={() => passwordMutation.mutate()}>بازنشانی رمز و لغو نشست‌ها</Button></div></section>
    <section><h2 className="mb-3 font-bold">سوابق Audit</h2>{managed.audit?.length ? <DataTable headers={['عملیات', 'عامل', 'دلیل', 'زمان']}>{managed.audit.map((event, index) => <tr key={`${event.action}-${event.occurred_at}-${index}`}><td className="px-4 py-3">{event.action}</td><td className="px-4 py-3 font-mono text-xs">{event.actor_id ?? 'سیستم'}</td><td className="px-4 py-3">{event.reason ?? '—'}</td><td className="px-4 py-3">{new Date(event.occurred_at).toLocaleString('fa-IR')}</td></tr>)}</DataTable> : <p className="text-sm text-[var(--nexo-muted)]">سابقه‌ای ثبت نشده است.</p>}</section>
    {(statusMutation.isError || updateMutation.isError || passwordMutation.isError || deleteMutation.isError || restoreMutation.isError || roleMutation.isError) && <p role="alert" className="mt-4 text-sm text-[var(--nexo-danger)]">عملیات انجام نشد؛ مجوز، دلیل و محدودیت آخرین SUPER_ADMIN را بررسی کنید.</p>}
    {reason.trim().length < 3 && <p className="mt-3 text-xs text-[var(--nexo-muted)]">برای همه عملیات مدیریتی واردکردن دلیل حداقل ۳ کاراکتری الزامی است.</p>}
  </div>;
}
