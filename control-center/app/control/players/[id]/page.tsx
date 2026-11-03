'use client';

import { useParams } from 'next/navigation';
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { normalizeApiError } from '@/lib/api-error';
import { queries } from '@/lib/queries';
import { Button, ConfirmDialog, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

function ValidationError({ error }: { error: unknown }) {
  if (!error) return null;
  const normalized = normalizeApiError(error);
  const fields = normalized.details?.fields;
  const messages = fields && typeof fields === 'object'
    ? Object.entries(fields as Record<string, unknown>).flatMap(([field, value]) => {
      const values = Array.isArray(value) ? value : [value];
      return values.filter((message): message is string => typeof message === 'string').map((message) => `${field}: ${message}`);
    })
    : [];

  return <div role="alert" className="mt-3 rounded-lg border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-700 dark:text-red-300">{messages.length ? messages.join(' ') : normalized.message}</div>;
}

export default function PlayerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const cache = useQueryClient();
  const player = useQuery({ queryKey: ['management-player', id], queryFn: () => managementApi.player(id) });
  const capabilities = useQuery(queries.capabilities());
  const [profile, setProfile] = useState({ name: '', email: '', mobile: '' });
  const [password, setPassword] = useState('');
  const [teamId, setTeamId] = useState('');
  const [reason, setReason] = useState('');
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const canManage = capabilities.data?.capabilities.includes('admin.players.manage') === true;
  const refresh = async () => { await Promise.all([cache.invalidateQueries({ queryKey: ['management-player', id] }), cache.invalidateQueries({ queryKey: ['management-players'] })]); };
  const update = useMutation({ mutationFn: () => managementApi.updatePlayer(id, { name: profile.name.trim() || player.data?.user.name || '', email: profile.email.trim() || player.data?.user.email || null, mobile: profile.mobile.trim() || player.data?.user.mobile || null, reason: reason.trim() }), onSuccess: async () => { setNotice('اطلاعات بازیکن ذخیره شد.'); await refresh(); } });
  const changeStatus = useMutation({ mutationFn: (status: 'ACTIVE' | 'SUSPENDED' | 'BLOCKED') => managementApi.setPlayerStatus(id, status, reason.trim()), onSuccess: async () => { setNotice('وضعیت حساب تغییر کرد و نشست‌های قبلی لغو شد.'); await refresh(); } });
  const changePassword = useMutation({ mutationFn: () => managementApi.resetPlayerPassword(id, password, reason.trim()), onSuccess: async () => { setPassword(''); setNotice('رمز عبور تغییر کرد.'); await refresh(); } });
  const remove = useMutation({ mutationFn: () => managementApi.deletePlayer(id, reason.trim()), onSuccess: async () => { setConfirmDelete(false); setNotice('حذف نرم بازیکن انجام شد.'); await refresh(); } });
  const restore = useMutation({ mutationFn: () => managementApi.restorePlayer(id, reason.trim()), onSuccess: async () => { setNotice('بازیکن بازیابی شد.'); await refresh(); } });
  const addTeam = useMutation({ mutationFn: () => managementApi.addPlayerToTeam(id, teamId.trim(), reason.trim()), onSuccess: async () => { setTeamId(''); setNotice('بازیکن به تیم اضافه شد.'); await refresh(); } });
  const removeTeam = useMutation({ mutationFn: (teamIdToRemove: string) => managementApi.removePlayerFromTeam(id, teamIdToRemove, reason.trim()), onSuccess: async () => { setNotice('عضویت تیمی حذف شد.'); await refresh(); } });
  const validReason = reason.trim().length >= 3;
  if (player.isPending) return <LoadingState label="در حال دریافت جزئیات بازیکن…" />;
  if (player.isError) return <ErrorState message="جزئیات بازیکن دریافت نشد یا خارج از محدودهٔ مجاز است." onRetry={() => void player.refetch()} />;
  const current = player.data.user;
  return <div dir="rtl">
    <PageHeader eyebrow="PLAYER PROFILE" title={current.name} description="اطلاعات حساس مانند hash رمز و token هرگز در این صفحه نمایش داده نمی‌شود." actions={<Button href="/control/players" variant="secondary">بازگشت به فهرست</Button>} />
    <section className="nexo-surface mb-5 grid gap-4 rounded-xl p-5 sm:grid-cols-4"><div><div className="text-xs text-[var(--nexo-muted)]">شناسه</div><div className="mt-1 break-all font-mono text-xs">{current.id}</div></div><div><div className="text-xs text-[var(--nexo-muted)]">ایمیل</div><div className="mt-1">{current.email ?? '—'}</div></div><div><div className="text-xs text-[var(--nexo-muted)]">تلفن</div><div className="mt-1">{current.mobile ?? '—'}</div></div><div><div className="text-xs text-[var(--nexo-muted)]">وضعیت</div><div className="mt-1"><StatusBadge status={current.status} /></div></div></section>
    {canManage && <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">ویرایش حساب</h2><div className="mt-4 grid gap-3 md:grid-cols-3"><label className="text-sm">نام نمایشی<input className="nexo-input mt-2" value={profile.name} placeholder={current.name} onChange={(event) => setProfile({ ...profile, name: event.target.value })} /></label><label className="text-sm">ایمیل<input type="email" className="nexo-input mt-2" value={profile.email} placeholder={current.email ?? 'ایمیل'} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /></label><label className="text-sm">تلفن<input className="nexo-input mt-2" value={profile.mobile} placeholder={current.mobile ?? 'تلفن'} onChange={(event) => setProfile({ ...profile, mobile: event.target.value })} /></label></div><label className="mt-3 block text-sm">دلیل عملیات<textarea required minLength={3} className="nexo-input mt-2 min-h-20" value={reason} onChange={(event) => setReason(event.target.value)} /></label><div className="mt-4 flex flex-wrap gap-2"><Button disabled={update.isPending || !validReason} onClick={() => update.mutate()}>ذخیرهٔ اطلاعات</Button>{current.status === 'ACTIVE' ? <Button variant="secondary" disabled={changeStatus.isPending || !validReason} onClick={() => changeStatus.mutate('SUSPENDED')}>تعلیق حساب</Button> : <Button variant="secondary" disabled={changeStatus.isPending || !validReason} onClick={() => changeStatus.mutate('ACTIVE')}>فعال‌سازی حساب</Button>}{current.deleted_at ? <Button disabled={restore.isPending || !validReason} onClick={() => restore.mutate()}>بازیابی</Button> : <Button variant="danger" disabled={remove.isPending || !validReason} onClick={() => setConfirmDelete(true)}>حذف نرم</Button>}</div></section>}
    {canManage && <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">تغییر رمز عبور</h2><p className="mt-2 text-xs text-[var(--nexo-muted)]">رمز باید حداقل ۱۲ نویسه داشته باشد؛ رمز قبلی قابل مشاهده نیست.</p><div className="mt-4 grid gap-3 md:grid-cols-[1fr_2fr_auto]"><input type="password" minLength={12} className="nexo-input" placeholder="رمز جدید، حداقل ۱۲ نویسه" value={password} onChange={(event) => setPassword(event.target.value)} /><div className="hidden md:block" /><Button disabled={changePassword.isPending || password.length < 12 || !validReason} onClick={() => changePassword.mutate()}>تغییر رمز</Button></div></section>}
    {canManage && <section className="nexo-surface mb-5 rounded-xl p-5"><h2 className="font-bold">مدیریت عضویت تیمی</h2><div className="mt-4 flex flex-wrap gap-2"><input className="nexo-input min-w-72 flex-1" placeholder="شناسهٔ تیم برای افزودن" value={teamId} onChange={(event) => setTeamId(event.target.value)} /><Button disabled={addTeam.isPending || !teamId.trim() || !validReason} onClick={() => addTeam.mutate()}>افزودن به تیم</Button></div><div className="mt-4">{player.data.team_memberships?.length ? <DataTable headers={['تیم', 'سازمان', 'وضعیت', 'عملیات']}>{player.data.team_memberships.map((member, index) => <tr key={`${member.team_id}-${index}`}><td className="px-4 py-3">{member.team_name}<div className="font-mono text-xs text-[var(--nexo-muted)]">{member.team_id}</div></td><td className="px-4 py-3">{member.organization?.name ?? '—'}</td><td className="px-4 py-3"><StatusBadge status={member.status} /></td><td className="px-4 py-3"><Button variant="danger" disabled={removeTeam.isPending || !validReason} onClick={() => removeTeam.mutate(member.team_id)}>حذف عضویت</Button></td></tr>)}</DataTable> : <EmptyState title="عضویت تیمی ثبت نشده" description="برای افزودن، شناسهٔ یک تیم مجاز و دلیل عملیات را وارد کنید." />}</div></section>}
    {update.isError && <ValidationError error={update.error} />}
    {changePassword.isError && <ValidationError error={changePassword.error} />}
    {changeStatus.isError && <ValidationError error={changeStatus.error} />}
    {addTeam.isError && <ValidationError error={addTeam.error} />}
    {removeTeam.isError && <ValidationError error={removeTeam.error} />}
    {remove.isError && <ValidationError error={remove.error} />}
    {restore.isError && <ValidationError error={restore.error} />}
    {notice && <p role="status" className="mb-5 rounded-lg border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/10 p-3 text-sm text-[var(--nexo-success)]">{notice}</p>}
    {player.data.participation.length > 0 && <section className="mb-6"><h2 className="mb-3 font-bold">مشارکت در بازی‌ها و جلسات</h2><DataTable headers={['جلسه', 'وضعیت جلسه', 'تیم', 'وضعیت مشارکت', 'آخرین فعالیت']}>{player.data.participation.map((item) => <tr key={item.player_id}><td className="px-4 py-3 font-mono text-xs">{item.session_id}</td><td className="px-4 py-3">{item.session_status}</td><td className="px-4 py-3">{item.team_name}</td><td className="px-4 py-3">{item.participation_status}</td><td className="px-4 py-3 text-xs">{item.last_seen_at ? new Date(item.last_seen_at).toLocaleString('fa-IR') : '—'}</td></tr>)}</DataTable></section>}
    {confirmDelete && <ConfirmDialog title="حذف نرم بازیکن" description="حساب غیرفعال می‌شود، اما سابقهٔ بازی و داده‌های تاریخی حذف نمی‌شود. این اقدام فقط با دلیل ثبت‌شده انجام می‌شود." confirmLabel={remove.isPending ? 'در حال انجام…' : 'تأیید حذف نرم'} onClose={() => setConfirmDelete(false)} onConfirm={() => remove.mutate()} />}
  </div>;
}
