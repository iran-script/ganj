'use client';

import Link from 'next/link';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, ArrowLeftRight, ChevronLeft, ChevronRight, Copy, Plus, Search, UserMinus } from 'lucide-react';
import { api } from '@/lib/api-client';
import { ApiErrorResponse } from '@/lib/api-error';
import { teamManagementApi, type ManagedInvite, type ManagedTeam } from '@/lib/team-management-api';
import { queries } from '@/lib/queries';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export function TeamManagementPage() {
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<string[]>([]);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState<'' | 'ACTIVE' | 'ARCHIVED'>('');
  const [createOpen, setCreateOpen] = useState(false);
  const [teamName, setTeamName] = useState('');
  const [createError, setCreateError] = useState('');
  useEffect(() => { const timer = window.setTimeout(() => { setQuery(search.trim()); setCursor(undefined); setHistory([]); }, 300); return () => window.clearTimeout(timer); }, [search]);
  const teams = useQuery({ queryKey: ['managed-teams', cursor ?? null, query, status], queryFn: () => teamManagementApi.list(cursor, { q: query, status: status || undefined }) });
  const visible = useMemo(() => teams.data?.data ?? [], [teams.data]);
  const createTeam = async (event: FormEvent) => { event.preventDefault(); if (!teamName.trim()) return; setCreateError(''); try { await teamManagementApi.create(teamName.trim()); setCreateOpen(false); setTeamName(''); await teams.refetch(); } catch (error) { setCreateError(error instanceof Error ? error.message : 'ساخت تیم انجام نشد.'); } };
  if (teams.isPending) return <LoadingState label="در حال دریافت تیم‌ها از سرور..." />;
  if (teams.isError || !teams.data) return <ErrorState message="فهرست تیم‌ها دریافت نشد. دسترسی و اتصال API را بررسی کنید." onRetry={() => void teams.refetch()} />;
  const pagination = teams.data.meta?.pagination;
  const next = () => { if (pagination?.next_cursor) { setHistory((value) => [...value, cursor ?? '']); setCursor(pagination.next_cursor ?? undefined); } };
  const previous = () => { const previousCursor = history.at(-1); if (previousCursor !== undefined) { setHistory((value) => value.slice(0, -1)); setCursor(previousCursor || undefined); } };
  return <div dir="rtl">
    <PageHeader eyebrow="MANAGEMENT / TEAMS" title="مدیریت تیم‌ها" description="تیم‌های قابل‌دسترسی حساب فعلی از API صفحه‌بندی‌شده و فیلتر سروری نمایش داده می‌شوند." actions={<Button onClick={() => setCreateOpen(true)}>افزودن تیم</Button>} />
    <div className="mb-5 flex flex-wrap items-center gap-3">
      <label className="relative min-w-[240px] flex-1"><span className="sr-only">جست‌وجوی تیم در این صفحه</span><Search size={16} aria-hidden="true" className="absolute right-3 top-3.5 text-[var(--nexo-muted)]" /><input value={search} onChange={(event) => setSearch(event.target.value)} className="nexo-input pr-10" placeholder="نام تیم، شناسه یا کاپیتان" /></label>
      <label className="text-sm"><span className="sr-only">فیلتر وضعیت</span><select className="nexo-input" value={status} onChange={(event) => { setStatus(event.target.value as typeof status); setCursor(undefined); setHistory([]); }}><option value="">همه وضعیت‌ها</option><option value="ACTIVE">فعال</option><option value="ARCHIVED">آرشیو شده</option></select></label><span className="text-sm text-[var(--nexo-muted)]">{visible.length} تیم در این صفحه</span>
    </div>
    {teams.data.data.length === 0 ? <EmptyState title="تیمی در محدودهٔ دسترسی نیست" description="تیم‌های قابل مشاهده پس از بررسی دسترسی حساب نمایش داده می‌شوند." /> : visible.length === 0 ? <EmptyState title="نتیجه‌ای در این صفحه پیدا نشد" description="عبارت جست‌وجو را تغییر دهید یا به صفحهٔ بعد بروید." /> : <DataTable headers={['تیم', 'کاپیتان', 'اعضا', 'سازمان', 'وضعیت', 'جزئیات']}>
      {visible.map((team) => <tr key={team.id} className="hover:bg-white/[2%]"><td className="px-4 py-4"><Link href={`/control/teams/${team.id}`} className="font-semibold hover:text-[var(--nexo-amber)]">{team.name ?? 'تیم بدون نام'}</Link><div className="mt-1 break-all text-xs text-[var(--nexo-muted)]">{team.id}</div></td><td className="px-4 py-4">{team.captain?.name ?? '—'}</td><td className="px-4 py-4">{team.member_count ?? team.members?.length ?? '—'} <span className="text-xs text-[var(--nexo-muted)]">/ ۸</span></td><td className="px-4 py-4">{team.organization?.name ?? 'تیم شخصی'}</td><td className="px-4 py-4"><StatusBadge status={team.status ?? 'UNKNOWN'} /></td><td className="px-4 py-4"><Link aria-label={`جزئیات ${team.name ?? 'تیم'}`} href={`/control/teams/${team.id}`} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-white/5"><ChevronLeft size={18} /></Link></td></tr>)}
    </DataTable>}
    <div className="mt-4 flex items-center justify-between"><Button variant="secondary" disabled={history.length === 0} onClick={previous}><ChevronRight size={16} className="ml-2" />صفحهٔ قبل</Button><span className="text-xs text-[var(--nexo-muted)]">{pagination?.has_more ? 'صفحه‌های بعدی موجود است' : 'پایان فهرست'}</span><Button variant="secondary" disabled={!pagination?.has_more} onClick={next}>صفحهٔ بعد<ChevronLeft size={16} className="mr-2" /></Button></div>
    {createOpen && <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-5"><form className="w-full max-w-md rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5" onSubmit={createTeam}><h2 className="text-lg font-bold">افزودن تیم</h2><p className="mt-2 text-sm text-[var(--nexo-muted)]">حساب فعلی به‌عنوان کاپیتان اولیه ثبت می‌شود.</p><label className="mt-4 block text-sm">نام تیم<input required maxLength={255} className="nexo-input mt-2" value={teamName} onChange={(event) => setTeamName(event.target.value)} /></label>{createError && <p role="alert" className="mt-3 text-sm text-[var(--nexo-danger)]">{createError}</p>}<div className="mt-5 flex justify-end gap-2"><Button variant="secondary" type="button" onClick={() => setCreateOpen(false)}>انصراف</Button><Button type="submit">ایجاد تیم</Button></div></form></div>}
  </div>;
}

type TeamAction = { action: 'rename' | 'remove' | 'transfer' | 'invite' | 'archive'; userId?: string } | null;

function inviteStatus(invite: ManagedInvite): string {
  if (invite.revoked_at) return 'لغوشده';
  if (invite.expires_at && new Date(invite.expires_at).getTime() <= Date.now()) return 'منقضی‌شده';
  if (invite.max_uses !== null && invite.max_uses !== undefined && invite.uses_count >= invite.max_uses) return 'تکمیل‌شده';
  return 'فعال';
}

export function TeamManagementDetailPage() {
  const { id } = useParams<{ id: string }>();
  const cache = useQueryClient();
  const [action, setAction] = useState<TeamAction>(null);
  const [reason, setReason] = useState('');
  const [newName, setNewName] = useState('');
  const [inviteExpiry, setInviteExpiry] = useState('');
  const [inviteCode, setInviteCode] = useState('');
  const [message, setMessage] = useState('');
  const team = useQuery({ queryKey: ['teams', id], queryFn: () => teamManagementApi.show(id) });
  const invites = useQuery({ queryKey: ['team-invites', id], queryFn: () => teamManagementApi.invites(id) });
  const me = useQuery(queries.me());
  const capabilities = useQuery(queries.capabilities());
  useEffect(() => { if (action?.action === 'transfer' && !action.userId) setAction({ action: 'rename' }); }, [action]);
  const isCaptain = Boolean(team.data && team.data.captain_user_id === me.data?.id);
  const isOrganizationAdmin = Boolean(team.data?.organization?.id && capabilities.data?.organizations.some((organization) => organization.id === team.data?.organization?.id && organization.role === 'ORGANIZATION_ADMIN'));
  const canManage = isCaptain || isOrganizationAdmin || Boolean(capabilities.data?.global_roles.includes('SUPER_ADMIN'));
  const mutation = useMutation({
    mutationFn: async (variables: { action: 'rename' | 'invite' | 'remove' | 'transfer' | 'archive' | 'revokeInvite'; name?: string; userId?: string; inviteId?: string; reason?: string }) => {
      const base = `/teams/${id}`;
      if (variables.action === 'rename') return api.patch(base, { name: variables.name, reason: reason.trim() || undefined });
      if (variables.action === 'invite') return api.post<{ invite_code: string }>(`${base}/invites`, { max_uses: 1, expires_at: inviteExpiry || undefined, reason: reason.trim() });
      if (variables.action === 'archive') return api.post(base + '/archive', { reason: reason.trim() });
      if (variables.action === 'revokeInvite') return teamManagementApi.revokeInvite(id, variables.inviteId ?? '', variables.reason ?? reason.trim());
      if (variables.action === 'remove') return api.delete(`${base}/members/${variables.userId}`, { reason: reason.trim() });
      return api.post(base + '/transfer-captaincy', { user_id: variables.userId, reason: reason.trim() }, { headers: { 'Idempotency-Key': `control-team-transfer-${crypto.randomUUID()}` } });
    },
    onSuccess: async (result, variables) => {
      setMessage('تغییر با موفقیت ثبت شد.');
      setAction(null);
      setReason('');
      if (variables.action === 'invite' && result && typeof result === 'object' && 'invite_code' in result) setInviteCode(String(result.invite_code));
      await Promise.all([cache.invalidateQueries({ queryKey: ['teams', id] }), cache.invalidateQueries({ queryKey: ['managed-teams'] }), cache.invalidateQueries({ queryKey: ['teams'] })]);
    },
  });
  if (team.isPending || me.isPending || capabilities.isPending) return <LoadingState label="در حال دریافت جزئیات تیم..." />;
  if (team.isError || me.isError || capabilities.isError || !team.data) return <ErrorState message="جزئیات تیم دریافت نشد. دسترسی یا اتصال API را بررسی کنید." onRetry={() => { void team.refetch(); void me.refetch(); void capabilities.refetch(); }} />;
  const members = team.data.members ?? [];
  const archived = team.data.status === 'ARCHIVED';
  const invitePanel = <section className="nexo-surface mt-5 rounded-xl p-5"><div className="mb-4 flex items-center justify-between"><div><h2 className="font-bold">دعوت بازیکنان</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">افزودن مستقیم بازیکن در API موجود نیست؛ بازیکن با کد دعوت به تیم می‌پیوندد.</p></div><span className="text-sm text-[var(--nexo-muted)]">{invites.data?.data.length ?? 0} دعوت</span></div>{invites.isError ? <ErrorState message="فهرست دعوت‌نامه‌ها بارگذاری نشد." onRetry={() => void invites.refetch()} /> : !invites.data?.data.length ? <EmptyState title="دعوت‌نامه‌ای ثبت نشده است" description="از عملیات «ایجاد دعوت یک‌بارمصرف» استفاده کنید." /> : <DataTable headers={['وضعیت', 'ایجاد', 'انقضا', 'مصرف', 'عملیات']}>{invites.data.data.map((invite) => { const status = inviteStatus(invite); return <tr key={invite.id}><td className="px-4 py-3 font-medium">{status}</td><td className="px-4 py-3 text-xs">{invite.created_at ? new Date(invite.created_at).toLocaleString('fa-IR') : '—'}</td><td className="px-4 py-3 text-xs">{invite.expires_at ? new Date(invite.expires_at).toLocaleString('fa-IR') : 'بدون انقضا'}</td><td className="px-4 py-3 text-xs">{invite.uses_count}{invite.max_uses ? ` / ${invite.max_uses}` : ''}</td><td className="px-4 py-3">{status === 'فعال' && canManage && !archived ? <Button variant="danger" onClick={() => { if (window.confirm('این دعوت‌نامه لغو شود؟')) { const reason = window.prompt('دلیل لغو دعوت (اختیاری):') ?? ''; mutation.mutate({ action: 'revokeInvite', inviteId: invite.id }); setReason(reason); } }}>لغو دعوت</Button> : '—'}</td></tr>; })}</DataTable>}</section>;
  const submit = (selected: NonNullable<TeamAction>) => {
    if (!reason.trim()) { setMessage('برای این عملیات، واردکردن دلیل الزامی است.'); return; }
    mutation.mutate({ action: selected.action, userId: selected.userId });
  };
  return <div dir="rtl">
    {invitePanel}
    <PageHeader eyebrow="MANAGEMENT / TEAM DETAIL" title={team.data.name ?? 'جزئیات تیم'} description={`شناسهٔ عمومی: ${team.data.id}`} actions={<Button variant="secondary" href="/control/teams">بازگشت به تیم‌ها</Button>} />
    <div className="grid gap-4 xl:grid-cols-[1.2fr_.8fr]">
      <section className="nexo-surface rounded-xl p-5"><div className="flex flex-wrap items-center gap-3"><h2 className="text-lg font-bold">{team.data.name}</h2><StatusBadge status={team.data.status ?? 'UNKNOWN'} /><span className="text-sm text-[var(--nexo-muted)]">{team.data.organization?.name ?? 'تیم شخصی'}</span></div><div className="mt-4 grid gap-3 sm:grid-cols-3"><Info label="کاپیتان" value={team.data.captain?.name ?? '—'} /><Info label="اعضای فعال" value={`${team.data.member_count ?? members.length} از ۸`} /><Info label="ایجاد" value={team.data.created_at ? new Date(team.data.created_at).toLocaleDateString('fa-IR') : 'ثبت نشده'} /></div></section>
      <section className="nexo-surface rounded-xl p-5"><h2 className="font-bold">عملیات مجاز</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">Laravel هر درخواست را بر اساس نقش، کاپیتانی، سازمان و وضعیت تیم دوباره بررسی می‌کند.</p>{!canManage && <p className="mt-3 text-sm text-[var(--nexo-warning)]">این حساب مجوز مدیریت این تیم را ندارد.</p>}{canManage && !archived && <div className="mt-4 flex flex-wrap gap-2"><Button variant="secondary" disabled={mutation.isPending} onClick={() => { setNewName(team.data?.name ?? ''); setAction({ action: 'transfer' }); }}><ArrowLeftRight size={16} className="ml-2" />ویرایش نام تیم</Button><Button variant="secondary" disabled={mutation.isPending} onClick={() => { setReason(''); setInviteExpiry(''); setInviteCode(''); setAction({ action: 'invite' }); }}><Plus size={16} className="ml-2" />ایجاد دعوت یک‌بارمصرف</Button><Button variant="ghost" disabled={mutation.isPending} onClick={() => { setReason(''); setAction({ action: 'archive' }); }}><Archive size={16} className="ml-2" />بایگانی تیم</Button></div>}{canManage && !archived && <p className="mt-3 text-xs text-[var(--nexo-muted)]">بایگانی در صورت اتصال به نشست ثبت‌نام، آماده، درحال‌اجرا یا متوقف‌شده رد می‌شود؛ سابقهٔ مشارکت و امتیاز حفظ خواهد شد.</p>}</section>
    </div>
    {message && <div role="status" className="mt-4 rounded-lg border border-[var(--nexo-border)] p-3 text-sm">{message}</div>}
    {mutation.isError && <div role="alert" className="mt-4 rounded-lg border border-[var(--nexo-danger)]/30 bg-[var(--nexo-danger)]/10 p-3 text-sm text-[var(--nexo-danger)]">{mutation.error instanceof ApiErrorResponse ? mutation.error.message : 'عملیات انجام نشد. مجوز و اتصال API را بررسی کنید.'}</div>}
    {inviteCode && <div className="mt-4 rounded-xl border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/5 p-4"><div className="font-semibold">کد دعوت فقط همین‌بار نمایش داده می‌شود</div><div className="mt-2 flex flex-wrap items-center gap-2"><code dir="ltr" className="select-all break-all rounded bg-black/20 p-2">{inviteCode}</code><Button variant="secondary" onClick={() => { void navigator.clipboard?.writeText(inviteCode); setMessage('کد دعوت کپی شد.'); }}><Copy size={16} className="ml-2" />کپی کد</Button></div></div>}
    <section className="nexo-surface mt-5 rounded-xl p-5"><div className="mb-4 flex items-center justify-between"><h2 className="font-bold">اعضای فعال تیم</h2><span className="text-sm text-[var(--nexo-muted)]">{members.length} عضو</span></div>{members.length === 0 ? <EmptyState title="عضو فعالی برای این تیم ثبت نشده است" description="عضویت‌های پایان‌یافته در تاریخچهٔ سرور باقی می‌مانند." /> : <DataTable headers={['بازیکن', 'وضعیت عضویت', 'تاریخ پیوستن', 'عملیات']}>{members.map((member) => <tr key={member.user_id}><td className="px-4 py-3">{member.name}</td><td className="px-4 py-3"><StatusBadge status={member.status} /></td><td className="px-4 py-3">{member.joined_at ? new Date(member.joined_at).toLocaleDateString('fa-IR') : '—'}</td><td className="px-4 py-3">{isCaptain && member.user_id !== team.data?.captain_user_id && !archived ? <div className="flex flex-wrap gap-2"><Button variant="ghost" disabled={mutation.isPending} onClick={() => setAction({ action: 'transfer', userId: member.user_id })}>انتقال کاپیتانی</Button><Button variant="ghost" disabled={mutation.isPending} onClick={() => setAction({ action: 'remove', userId: member.user_id })}><UserMinus size={15} className="ml-1" />حذف عضو</Button></div> : member.user_id === team.data?.captain_user_id ? 'کاپیتان' : '—'}</td></tr>)}</DataTable>}</section>
    {action && <div role="dialog" aria-modal="true" aria-labelledby="team-action-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"><div className="w-full max-w-lg rounded-2xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5 shadow-[var(--nexo-shadow)]"><h2 id="team-action-title" className="text-lg font-bold">{action.action === 'remove' ? 'حذف عضو از تیم' : action.action === 'invite' ? 'ساخت دعوت یک‌بارمصرف' : action.action === 'archive' ? 'تأیید بایگانی تیم' : action.userId ? 'انتقال کاپیتانی' : 'تغییر نام تیم'}</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">تغییر از طریق API انجام و در سابقهٔ ممیزی ثبت می‌شود. تیم یا سابقهٔ عضویت حذف دائمی نخواهد شد.</p>{action.action === 'rename' && <label className="mt-4 block text-sm">نام جدید تیم<input value={newName} onChange={(event) => setNewName(event.target.value)} className="nexo-input mt-2" maxLength={255} /></label>}{action.action === 'invite' && <label className="mt-4 block text-sm">انقضای دعوت (اختیاری)<input type="datetime-local" value={inviteExpiry} onChange={(event) => setInviteExpiry(event.target.value ? new Date(event.target.value).toISOString() : '')} className="nexo-input mt-2" /></label>}{action.action === 'archive' && <p className="mt-3 text-sm leading-6 text-[var(--nexo-warning)]">بایگانی تیم قابل بازگشت نیست. نشست‌های فعال یا درحال‌ثبت‌نام مانع این عملیات می‌شوند.</p>}<label className="mt-4 block text-sm">دلیل عملیات<textarea value={reason} onChange={(event) => setReason(event.target.value)} className="nexo-input mt-2 min-h-24" maxLength={1000} required /></label><div className="mt-5 flex justify-end gap-2"><Button variant="ghost" disabled={mutation.isPending} onClick={() => setAction(null)}>انصراف</Button><Button disabled={mutation.isPending || !reason.trim() || (action.action === 'rename' && !newName.trim())} onClick={() => {
      if (action.action === 'transfer' && !action.userId && !newName.trim()) { setMessage('نام جدید تیم را وارد کنید.'); return; }
      if (action.action === 'remove') { submit(action); return; }
      if (action.action === 'invite') { submit(action); return; }
      if (action.action === 'archive') { submit(action); return; }
      if (action.userId) { submit(action); return; }
      mutation.mutate({ action: 'rename', name: newName.trim() });
    }}>{mutation.isPending ? 'در حال ثبت...' : 'تأیید و ثبت'}</Button></div></div></div>}
  </div>;
}

function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3"><div className="text-xs text-[var(--nexo-muted)]">{label}</div><div className="mt-2 break-words text-sm font-semibold">{value}</div></div>; }
