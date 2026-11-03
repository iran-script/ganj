'use client';

import Link from 'next/link';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, ArrowLeftRight, ChevronLeft, ChevronRight, Copy, Crown, Plus, Search, UserMinus } from 'lucide-react';
import { api } from '@/lib/api-client';
import { ApiErrorResponse, normalizeApiError } from '@/lib/api-error';
import { managementApi, type ManagedUser } from '@/lib/management-api';
import { teamManagementApi, type ManagedInvite, type ManagedTeam } from '@/lib/team-management-api';
import { queries } from '@/lib/queries';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

function teamListErrorMessage(error: unknown): string {
  const normalized = normalizeApiError(error);

  if (normalized.status === 401) return 'نشست مدیریتی منقضی شده است؛ دوباره وارد شوید.';
  if (normalized.status === 403) return 'حساب شما مجوز مشاهدهٔ تیم‌های این سازمان را ندارد.';
  if (normalized.status === 0) return 'اتصال به API برقرار نشد؛ شبکه و آدرس سرویس را بررسی کنید.';

  return normalized.message || 'فهرست تیم‌ها از سرور دریافت نشد.';
}

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
  if (teams.isError || !teams.data) return <ErrorState message={teamListErrorMessage(teams.error)} onRetry={() => void teams.refetch()} />;
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
      {visible.map((team) => <tr key={team.id} className="hover:bg-white/[2%]"><td className="px-4 py-4"><Link href={`/control/teams/${team.id}`} className="font-semibold hover:text-[var(--nexo-amber)]">{team.name ?? 'تیم بدون نام'}</Link><div className="mt-1 break-all text-xs text-[var(--nexo-muted)]">{team.id}</div></td><td className="px-4 py-4"><span className="flex items-center gap-2"><Crown size={16} className="text-[var(--nexo-amber)]" aria-label="کاپیتان" />{team.captain?.name ?? '—'}</span></td><td className="px-4 py-4">{team.member_count ?? team.members?.length ?? '—'} <span className="text-xs text-[var(--nexo-muted)]">/ ۸</span></td><td className="px-4 py-4">{team.organization?.name ?? 'تیم شخصی'}</td><td className="px-4 py-4"><StatusBadge status={team.status ?? 'UNKNOWN'} /></td><td className="px-4 py-4"><div className="flex flex-wrap gap-2"><Link aria-label={`جزئیات ${team.name ?? 'تیم'}`} href={`/control/teams/${team.id}`} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-white/5"><ChevronLeft size={18} /></Link><Link aria-label={`مدیریت اعضای ${team.name ?? 'تیم'}`} href={`/control/teams/${team.id}`} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-white/5"><UserMinus size={16} /></Link></div></td></tr>)}
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
  const [playerPickerOpen, setPlayerPickerOpen] = useState(false);
  const [playerSearch, setPlayerSearch] = useState('');
  const [selectedPlayers, setSelectedPlayers] = useState<string[]>([]);
  const [playerErrors, setPlayerErrors] = useState<Record<string, string>>({});
  const [captainPickerOpen, setCaptainPickerOpen] = useState(false);
  const [proposedCaptain, setProposedCaptain] = useState('');
  const [captainReason, setCaptainReason] = useState('');
  const team = useQuery({ queryKey: ['teams', id], queryFn: () => teamManagementApi.show(id) });
  const invites = useQuery({ queryKey: ['team-invites', id], queryFn: () => teamManagementApi.invites(id) });
  const me = useQuery(queries.me());
  const capabilities = useQuery(queries.capabilities());
  const eligiblePlayers = useQuery({
    queryKey: ['team-player-picker', playerSearch],
    queryFn: () => managementApi.players({ q: playerSearch, limit: 100 }),
    enabled: playerPickerOpen,
  });
  const isCaptain = Boolean(team.data && team.data.captain_user_id === me.data?.id);
  const isOrganizationAdmin = Boolean(team.data?.organization?.id && capabilities.data?.organizations.some((organization) => organization.id === team.data?.organization?.id && organization.role === 'ORGANIZATION_ADMIN'));
  const canManage = isCaptain || isOrganizationAdmin || Boolean(capabilities.data?.global_roles.includes('SUPER_ADMIN'));
  const addPlayers = useMutation({
    mutationFn: async (playerIds: string[]) => {
      const failures: Record<string, string> = {};
      for (const playerId of playerIds) {
        try {
          await managementApi.addPlayerToTeam(playerId, id, 'افزودن بازیکن از مدیریت تیم');
        } catch (error) {
          failures[playerId] = normalizeApiError(error, 'افزودن بازیکن انجام نشد.').message;
        }
      }
      return failures;
    },
    onSuccess: async (failures) => {
      setPlayerErrors(failures);
      setSelectedPlayers(Object.keys(failures));
      setMessage(Object.keys(failures).length ? 'برخی بازیکنان اضافه نشدند؛ خطای هر مورد کنار آن نمایش داده شده است.' : 'بازیکنان انتخاب‌شده با موفقیت به تیم اضافه شدند.');
      await Promise.all([cache.invalidateQueries({ queryKey: ['teams', id] }), cache.invalidateQueries({ queryKey: ['managed-teams'] }), cache.invalidateQueries({ queryKey: ['team-player-picker'] })]);
      if (!Object.keys(failures).length) setPlayerPickerOpen(false);
    },
  });
  const changeCaptain = useMutation({
    mutationFn: () => api.post(`/teams/${id}/transfer-captaincy`, { user_id: proposedCaptain, reason: captainReason.trim() }, { headers: { 'Idempotency-Key': `control-team-transfer-${crypto.randomUUID()}` } }),
    onSuccess: async () => {
      setCaptainPickerOpen(false);
      setProposedCaptain('');
      setCaptainReason('');
      setMessage('کاپیتان تیم با موفقیت تغییر کرد.');
      await Promise.all([cache.invalidateQueries({ queryKey: ['teams', id] }), cache.invalidateQueries({ queryKey: ['managed-teams'] }), cache.invalidateQueries({ queryKey: ['teams'] })]);
    },
  });
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
    {captainPickerOpen && <CaptainPicker members={members} currentCaptainId={team.data.captain_user_id} selected={proposedCaptain} reason={captainReason} submitting={changeCaptain.isPending} onSelect={setProposedCaptain} onReason={setCaptainReason} onClose={() => setCaptainPickerOpen(false)} onSave={() => changeCaptain.mutate()} />}
    {playerPickerOpen && <PlayerPicker players={eligiblePlayers.data?.data ?? []} members={members.map((member) => member.user_id)} selected={selectedPlayers} search={playerSearch} errors={playerErrors} loading={eligiblePlayers.isPending} submitting={addPlayers.isPending} onSearch={setPlayerSearch} onToggle={(playerId) => setSelectedPlayers((current) => current.includes(playerId) ? current.filter((id) => id !== playerId) : [...current, playerId])} onClose={() => setPlayerPickerOpen(false)} onSave={() => addPlayers.mutate(selectedPlayers)} onRetry={() => void eligiblePlayers.refetch()} />}
    {canManage && !archived && <section aria-label="مدیریت تیم" className="nexo-surface mt-5 rounded-xl p-5"><div className="flex flex-wrap gap-2"><Button variant="secondary" disabled={mutation.isPending} onClick={() => { setNewName(team.data?.name ?? ''); setAction({ action: 'rename' }); }}>ویرایش تیم</Button><Button variant="secondary" disabled={addPlayers.isPending} onClick={() => { setPlayerErrors({}); setPlayerPickerOpen(true); void eligiblePlayers.refetch(); }}>افزودن بازیکن</Button><Button variant="secondary" disabled={changeCaptain.isPending} onClick={() => { setProposedCaptain(''); setCaptainReason(''); setCaptainPickerOpen(true); }}>تغییر کاپیتان</Button></div></section>}
    {invitePanel}
    {canManage && !archived && <section className="nexo-surface mt-5 rounded-xl p-5"><h2 className="font-bold">مدیریت اعضا</h2><div className="mt-3 grid gap-2 sm:grid-cols-2">{members.filter((member) => member.user_id !== team.data?.captain_user_id).map((member) => <div key={member.user_id} className="flex items-center justify-between gap-3 rounded-lg border border-[var(--nexo-border)] p-3"><span className="min-w-0"><span className="block truncate font-medium">{member.name}</span><span className="block truncate text-xs text-[var(--nexo-muted)]" dir="ltr">{member.username ?? member.email ?? member.user_id}</span></span><Button variant="danger" disabled={mutation.isPending} onClick={() => setAction({ action: 'remove', userId: member.user_id })}>حذف از تیم</Button></div>)}</div></section>}
    <PageHeader eyebrow="MANAGEMENT / TEAM DETAIL" title={team.data.name ?? 'جزئیات تیم'} description={`شناسهٔ عمومی: ${team.data.id}`} actions={<Button variant="secondary" href="/control/teams">بازگشت به تیم‌ها</Button>} />
    <div className="grid gap-4 xl:grid-cols-[1.2fr_.8fr]">
      <section className="nexo-surface rounded-xl p-5"><div className="flex flex-wrap items-center gap-3"><h2 className="text-lg font-bold">{team.data.name}</h2><StatusBadge status={team.data.status ?? 'UNKNOWN'} /><span className="text-sm text-[var(--nexo-muted)]">{team.data.organization?.name ?? 'تیم شخصی'}</span></div><div className="mt-4 grid gap-3 sm:grid-cols-3"><Info label="کاپیتان" value={team.data.captain?.name ?? '—'} /><Info label="اعضای فعال" value={`${team.data.member_count ?? members.length} از ۸`} /><Info label="ایجاد" value={team.data.created_at ? new Date(team.data.created_at).toLocaleDateString('fa-IR') : 'ثبت نشده'} /></div></section>
      <section className="nexo-surface rounded-xl p-5"><h2 className="font-bold">عملیات مجاز</h2><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">Laravel هر درخواست را بر اساس نقش، کاپیتانی، سازمان و وضعیت تیم دوباره بررسی می‌کند.</p>{!canManage && <p className="mt-3 text-sm text-[var(--nexo-warning)]">این حساب مجوز مدیریت این تیم را ندارد.</p>}{canManage && !archived && <div className="mt-4 flex flex-wrap gap-2"><Button variant="secondary" disabled={mutation.isPending} onClick={() => { setNewName(team.data?.name ?? ''); setAction({ action: 'rename' }); }}><ArrowLeftRight size={16} className="ml-2" />ویرایش نام تیم</Button><Button variant="secondary" disabled={mutation.isPending} onClick={() => { setReason(''); setInviteExpiry(''); setInviteCode(''); setAction({ action: 'invite' }); }}><Plus size={16} className="ml-2" />ایجاد دعوت یک‌بارمصرف</Button><Button variant="ghost" disabled={mutation.isPending} onClick={() => { setReason(''); setAction({ action: 'archive' }); }}><Archive size={16} className="ml-2" />بایگانی تیم</Button></div>}{canManage && !archived && <p className="mt-3 text-xs text-[var(--nexo-muted)]">بایگانی در صورت اتصال به نشست ثبت‌نام، آماده، درحال‌اجرا یا متوقف‌شده رد می‌شود؛ سابقهٔ مشارکت و امتیاز حفظ خواهد شد.</p>}</section>
    </div>
    {message && <div role="status" className="mt-4 rounded-lg border border-[var(--nexo-border)] p-3 text-sm">{message}</div>}
    {mutation.isError && <div role="alert" className="mt-4 rounded-lg border border-[var(--nexo-danger)]/30 bg-[var(--nexo-danger)]/10 p-3 text-sm text-[var(--nexo-danger)]">{mutation.error instanceof ApiErrorResponse ? mutation.error.message : 'عملیات انجام نشد. مجوز و اتصال API را بررسی کنید.'}</div>}
    {inviteCode && <div className="mt-4 rounded-xl border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/5 p-4"><div className="font-semibold">کد دعوت فقط همین‌بار نمایش داده می‌شود</div><div className="mt-2 flex flex-wrap items-center gap-2"><code dir="ltr" className="select-all break-all rounded bg-black/20 p-2">{inviteCode}</code><Button variant="secondary" onClick={() => { void navigator.clipboard?.writeText(inviteCode); setMessage('کد دعوت کپی شد.'); }}><Copy size={16} className="ml-2" />کپی کد</Button></div></div>}
    {canManage && !archived && <section className="nexo-surface mt-5 rounded-xl p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-bold">افزودن بازیکن</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">همه بازیکنان واجد شرایط سازمان را جست‌وجو و چند نفر را هم‌زمان انتخاب کنید.</p></div><Button onClick={() => { setPlayerPickerOpen(true); setPlayerErrors({}); void eligiblePlayers.refetch(); }}>افزودن بازیکن</Button></div><div className="mt-3 text-sm text-[var(--nexo-muted)]">{members.length} عضو فعال از ۸ ظرفیت</div></section>}
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

function CaptainPicker({ members, currentCaptainId, selected, reason, submitting, onSelect, onReason, onClose, onSave }: { members: ManagedTeam['members']; currentCaptainId?: string; selected: string; reason: string; submitting: boolean; onSelect: (id: string) => void; onReason: (value: string) => void; onClose: () => void; onSave: () => void }) {
  const eligibleMembers = (members ?? []).filter((member) => member.user_id && member.user_id !== currentCaptainId && member.account_status !== 'DELETED');
  return <div role="dialog" aria-modal="true" aria-labelledby="captain-picker-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"><div className="w-full max-w-lg rounded-2xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5 shadow-[var(--nexo-shadow)]"><div className="flex items-center justify-between"><h2 id="captain-picker-title" className="text-lg font-bold">تغییر کاپیتان</h2><button aria-label="بستن" onClick={onClose} className="text-2xl text-[var(--nexo-muted)]">×</button></div><p className="mt-2 text-sm text-[var(--nexo-muted)]">کاپیتان فعلی: <strong>{members?.find((member) => member.user_id === currentCaptainId)?.name ?? 'نامشخص'}</strong></p><div className="mt-4 space-y-2">{eligibleMembers.length === 0 ? <EmptyState title="عضو واجد شرایط دیگری وجود ندارد" description="کاپیتان جدید باید عضو فعال همین تیم باشد." /> : eligibleMembers.map((member) => <label key={member.user_id} className="flex cursor-pointer items-center gap-3 rounded-lg border border-[var(--nexo-border)] p-3"><input type="radio" name="new-captain" checked={selected === member.user_id} onChange={() => onSelect(member.user_id)} /><span className="flex-1"><span className="block font-medium">{member.name}</span><span className="block text-xs text-[var(--nexo-muted)]" dir="ltr">{member.username ?? member.email ?? 'شناسه ثبت نشده'}</span></span><StatusBadge status={member.account_status ?? 'ACTIVE'} /></label>)}</div><label className="mt-4 block text-sm">دلیل عملیات<textarea value={reason} onChange={(event) => onReason(event.target.value)} className="nexo-input mt-2 min-h-20" maxLength={1000} required /></label><div className="mt-5 flex justify-end gap-2"><Button variant="ghost" disabled={submitting} onClick={onClose}>انصراف</Button><Button disabled={submitting || !selected || !reason.trim()} onClick={onSave}>{submitting ? 'در حال ثبت...' : 'تأیید تغییر کاپیتان'}</Button></div></div></div>;
}

function PlayerPicker({ players, members, selected, search, errors, loading, submitting, onSearch, onToggle, onClose, onSave, onRetry }: { players: ManagedUser[]; members: string[]; selected: string[]; search: string; errors: Record<string, string>; loading: boolean; submitting: boolean; onSearch: (value: string) => void; onToggle: (id: string) => void; onClose: () => void; onSave: () => void; onRetry: () => void }) {
  return <div role="dialog" aria-modal="true" aria-labelledby="player-picker-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"><div className="w-full max-w-2xl rounded-2xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5 shadow-[var(--nexo-shadow)]"><div className="flex items-center justify-between"><h2 id="player-picker-title" className="text-lg font-bold">افزودن بازیکن به تیم</h2><button aria-label="بستن" onClick={onClose} className="text-2xl text-[var(--nexo-muted)]">×</button></div><input autoFocus value={search} onChange={(event) => onSearch(event.target.value)} className="nexo-input mt-4" placeholder="جست‌وجو با نام، نام کاربری یا ایمیل" />{loading ? <LoadingState label="در حال دریافت بازیکنان..." /> : <div className="mt-4 max-h-[50vh] space-y-2 overflow-y-auto">{players.length === 0 ? <EmptyState title="بازیکنی پیدا نشد" description="عبارت جست‌وجو را تغییر دهید." /> : players.map((player) => { const member = members.includes(player.id); const checked = selected.includes(player.id); return <label key={player.id} className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 ${member ? 'border-[var(--nexo-success)]/30 opacity-70' : 'border-[var(--nexo-border)]'}`}><input type="checkbox" checked={checked || member} disabled={member} onChange={() => onToggle(player.id)} /><span className="min-w-0 flex-1"><span className="block font-medium">{player.name || 'بدون نام'}</span><span className="block truncate text-xs text-[var(--nexo-muted)]" dir="ltr">{player.email || player.id}</span></span><span className="text-xs text-[var(--nexo-muted)]">{member ? 'عضو فعلی' : player.status}</span>{errors[player.id] && <span className="text-xs text-[var(--nexo-danger)]">{errors[player.id]}</span>}</label>; })}</div>}{eligiblePageHint(players)}<div className="mt-5 flex justify-end gap-2"><Button variant="ghost" disabled={submitting} onClick={onClose}>انصراف</Button><Button disabled={submitting || selected.length === 0} onClick={onSave}>{submitting ? 'در حال افزودن...' : `افزودن ${selected.length} بازیکن`}</Button></div><button className="mt-3 text-xs text-[var(--nexo-muted)] underline" onClick={onRetry}>تلاش دوباره</button></div></div>;
}

function eligiblePageHint(players: ManagedUser[]): string { return players.length >= 25 ? 'برای نتایج بیشتر، جست‌وجوی دقیق‌تری وارد کنید.' : ''; }

function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3"><div className="text-xs text-[var(--nexo-muted)]">{label}</div><div className="mt-2 break-words text-sm font-semibold">{value}</div></div>; }
