'use client';

import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { roomApi } from '@/lib/room-api';
import { normalizeApiError } from '@/lib/api-error';
import { queries } from '@/lib/queries';
import { Button, EmptyState, ErrorState, LoadingState, StatusBadge } from '@/components/ui';
import type { Game, Room } from '@/types/api';
import { useParams } from 'next/navigation';
import { GameDetail } from '@/components/game-detail';

const roomSchema = z.object({
  name: z.string().trim().min(2, 'نام اتاق حداقل باید ۲ نویسه باشد.').max(120, 'نام اتاق بیش از حد طولانی است.'),
  team_id: z.string().uuid('تیم انتخاب‌شده معتبر نیست.'),
});

function RoomRegistration({ room }: { room: Room }) {
  const client = useQueryClient();
  const me = useQuery({ ...queries.me() });
  const teams = useQuery({ ...queries.teams() });
  const registration = useMutation({
    mutationFn: (teamId: string) => roomApi.registerTeam(room.id, teamId),
    onSuccess: async (updatedRoom) => {
      client.setQueryData(['rooms', room.id], updatedRoom);
      await client.invalidateQueries({ queryKey: ['rooms', room.id] });
      await client.invalidateQueries({ queryKey: ['games', room.game_id, 'rooms'] });
    },
  });
  const registeredIds = new Set(room.teams?.map((team) => team.id) ?? []);
  const availableTeams = teams.data?.filter((team) => team.status === 'ACTIVE' && team.captain_user_id === me.data?.id && team.eligible_for_game === true && !registeredIds.has(team.id)) ?? [];
  const canAcceptTeams = room.status === 'OPEN' && !['READY', 'RUNNING', 'PAUSED', 'FINISHED', 'CANCELLED'].includes(room.session_status ?? '');
  return <div className="mt-6 border-t border-[var(--nexo-border)] pt-5"><h4 className="font-semibold">ثبت تیم در اتاق</h4>{!canAcceptTeams ? <p className="mt-3 text-sm text-[var(--nexo-warning)]">این اتاق در حال حاضر تیم جدید نمی‌پذیرد.</p> : room.capacity <= room.team_count ? <p className="mt-3 text-sm text-[var(--nexo-warning)]">ظرفیت این اتاق تکمیل شده است.</p> : me.isPending || teams.isPending ? <LoadingState label="در حال بررسی تیم‌های قابل ثبت..." /> : me.isError || teams.isError ? <ErrorState message="اطلاعات تیم‌ها یا مجوز ثبت دریافت نشد." onRetry={() => { void me.refetch(); void teams.refetch(); }} /> : availableTeams.length === 0 ? <p className="mt-3 text-sm text-[var(--nexo-muted)]">برای این اقدام تیم واجد شرایطی که کاپیتان آن باشید پیدا نشد. مدیران دارای دسترسی مشاهده، مجوز ثبت تیم ندارند.</p> : <div className="mt-4 space-y-3">{availableTeams.map((team) => <div key={team.id} className="flex items-center justify-between gap-3 rounded-lg border border-[var(--nexo-border)] p-3"><span className="text-sm">{team.name ?? team.id}</span><Button onClick={() => registration.mutate(team.id)} disabled={registration.isPending}>{registration.isPending ? 'در حال ثبت...' : 'ثبت تیم'}</Button></div>)}{registration.isError && <ErrorState message={normalizeApiError(registration.error).message} />}{registration.isSuccess && <p role="status" className="text-sm text-[var(--nexo-success)]">تیم با موفقیت در اتاق ثبت شد.</p>}</div>}</div>;
}

function RoomDetails({ room, onClose }: { room: Room; onClose: () => void }) {
  return <div role="dialog" aria-label="جزئیات اتاق" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
    <div className="nexo-surface max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl p-6" dir="rtl">
      <div className="flex items-start justify-between gap-4"><div><h3 className="text-lg font-bold">{room.name}</h3><p className="mt-1 text-xs text-[var(--nexo-muted)]">شناسه: <span dir="ltr">{room.id}</span></p></div><Button variant="ghost" onClick={onClose}>بستن</Button></div>
      <dl className="mt-6 grid gap-4 sm:grid-cols-3"><div><dt className="text-xs text-[var(--nexo-muted)]">وضعیت</dt><dd className="mt-2"><StatusBadge status={room.status} /></dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">ظرفیت</dt><dd className="mt-2 text-sm">{room.team_count} از {room.capacity} تیم</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">وضعیت جلسه</dt><dd className="mt-2 text-sm">{room.session_status ?? 'شروع نشده'}</dd></div></dl>
      <div className="mt-6 border-t border-[var(--nexo-border)] pt-5"><h4 className="font-semibold">تیم‌های ثبت‌شده</h4>{room.teams?.length ? <div className="mt-3 space-y-3">{room.teams.map((team) => <div key={team.id} className="rounded-lg border border-[var(--nexo-border)] p-3"><div className="flex items-center justify-between gap-3"><span>{team.name ?? 'تیم بدون نام'}</span>{team.eligible === false && <span className="text-xs text-[var(--nexo-danger)]">واجد شرایط نیست</span>}</div><p className="mt-1 text-xs text-[var(--nexo-muted)]">{team.member_count ?? 0} عضو · کاپیتان: {team.captain ?? 'ثبت نشده'}</p></div>)}</div> : <p className="mt-3 text-sm text-[var(--nexo-muted)]">تیمی برای این اتاق ثبت نشده است.</p>}</div>
      <RoomRegistration room={room} />
    </div>
  </div>;
}

export function GameRooms({ game }: { game: Game }) {
  const client = useQueryClient();
  const rooms = useQuery(queries.rooms(game.id));
  const [selectedRoomId, setSelectedRoomId] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [values, setValues] = useState({ name: '', team_id: '' });
  const [validationError, setValidationError] = useState('');
  const me = useQuery({ ...queries.me(), enabled: createOpen });
  const teams = useQuery({ ...queries.teams(), enabled: createOpen });
  const detail = useQuery({ ...queries.room(selectedRoomId ?? ''), enabled: Boolean(selectedRoomId) });
  const create = useMutation({
    mutationFn: () => roomApi.create(game.id, values),
    onSuccess: async (room) => { setCreateOpen(false); setValues({ name: '', team_id: '' }); setValidationError(''); await client.invalidateQueries({ queryKey: ['games', game.id, 'rooms'] }); setSelectedRoomId(room.id); },
  });
  const eligibleTeams = teams.data?.filter((team) => team.status === 'ACTIVE' && team.captain_user_id === me.data?.id && team.eligible_for_game === true && (!team.organization || team.organization.id === game.organization_id)) ?? [];
  const submit = (event: FormEvent) => { event.preventDefault(); const result = roomSchema.safeParse(values); if (!result.success) { setValidationError(result.error.issues[0]?.message ?? 'اطلاعات اتاق معتبر نیست.'); return; } setValidationError(''); create.mutate(); };
  const page = rooms.data;
  return <section aria-label="اتاق‌های بازی" className="nexo-surface mt-5 rounded-xl p-5" dir="rtl">
    <div className="mb-4 flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-bold">اتاق‌های بازی</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">اتاق‌های فعال این بازی و تیم‌های ثبت‌شده در آن‌ها.</p></div><Button onClick={() => setCreateOpen(true)} disabled={game.status !== 'ACTIVE'} title={game.status !== 'ACTIVE' ? 'ساخت اتاق فقط برای بازی فعال ممکن است.' : undefined}>ساخت اتاق</Button></div>
    {game.status !== 'ACTIVE' && <p className="mb-4 rounded-lg border border-[var(--nexo-info)]/30 bg-[var(--nexo-info)]/10 p-3 text-sm text-[var(--nexo-info)]">اتاق‌ها فقط برای بازی فعال در Backend در دسترس هستند.</p>}
    {rooms.isPending ? <LoadingState label="در حال دریافت اتاق‌های بازی..." /> : rooms.isError ? <ErrorState message="دریافت اتاق‌های بازی انجام نشد؛ وضعیت بازی یا دسترسی خود را بررسی کنید." onRetry={() => void rooms.refetch()} /> : page?.data.length ? <div className="grid gap-3 md:grid-cols-2">{page.data.map((room) => <button key={room.id} type="button" onClick={() => setSelectedRoomId(room.id)} className="rounded-lg border border-[var(--nexo-border)] p-4 text-right transition hover:border-[var(--nexo-amber)]"><div className="flex items-center justify-between gap-3"><span className="font-semibold">{room.name}</span><StatusBadge status={room.status} /></div><p className="mt-3 text-sm text-[var(--nexo-muted)]">ظرفیت: {room.team_count} از {room.capacity} تیم</p><p className="mt-1 text-xs text-[var(--nexo-muted)]">{room.teams?.map((team) => team.name).filter(Boolean).join('، ') || 'تیمی ثبت نشده است'}</p></button>)}</div> : <EmptyState title="هنوز اتاقی برای این بازی ساخته نشده است." description="برای ساخت اتاق باید کاپیتان یک تیم فعال و واجد شرایط باشید." />}
    {createOpen && <div role="dialog" aria-label="ساخت اتاق" className="mt-5 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-elevated)] p-5"><div className="flex items-center justify-between gap-3"><h3 className="font-semibold">ساخت اتاق بازی</h3><Button variant="ghost" onClick={() => setCreateOpen(false)}>انصراف</Button></div>{me.isPending || teams.isPending ? <LoadingState label="در حال بررسی مجوز و تیم‌های شما..." /> : me.isError || teams.isError ? <ErrorState message="اطلاعات مجوز یا تیم‌ها دریافت نشد." onRetry={() => { void me.refetch(); void teams.refetch(); }} /> : eligibleTeams.length === 0 ? <p className="mt-4 rounded-lg border border-[var(--nexo-warning)]/30 bg-[var(--nexo-warning)]/10 p-3 text-sm text-[var(--nexo-warning)]">برای ساخت اتاق، باید کاپیتان یک تیم فعال و واجد شرایط در این سازمان باشید.</p> : <form onSubmit={submit} className="mt-4 space-y-4"><label className="block text-sm">نام اتاق<input aria-label="نام اتاق" className="nexo-input mt-2" value={values.name} onChange={(event) => setValues({ ...values, name: event.target.value })} /></label><label className="block text-sm">تیم اولیه<select aria-label="تیم اولیه" className="nexo-input mt-2" value={values.team_id} onChange={(event) => setValues({ ...values, team_id: event.target.value })}><option value="">انتخاب تیم</option>{eligibleTeams.map((team) => <option key={team.id} value={team.id}>{team.name ?? team.id}</option>)}</select></label>{validationError && <p role="alert" className="text-sm text-[var(--nexo-danger)]">{validationError}</p>}{create.isError && <ErrorState message={normalizeApiError(create.error).message} /> }<div className="flex justify-end"><Button type="submit" disabled={create.isPending}>{create.isPending ? 'در حال ساخت...' : 'ساخت اتاق'}</Button></div></form>}</div>}
    {create.isSuccess && <p role="status" className="mt-4 text-sm text-[var(--nexo-success)]">اتاق با موفقیت ساخته شد.</p>}
    {detail.data && <RoomDetails room={detail.data} onClose={() => setSelectedRoomId(null)} />}{selectedRoomId && detail.isPending && <div className="mt-4"><LoadingState label="در حال دریافت جزئیات اتاق..." /></div>}{selectedRoomId && detail.isError && <div className="mt-4"><ErrorState message="جزئیات اتاق دریافت نشد." onRetry={() => void detail.refetch()} /></div>}
  </section>;
}
export function GameDetailsWithRooms() {
  const { id } = useParams<{ id: string }>();
  const game = useQuery(queries.game(id));
  return <><GameDetail />{game.data && <GameRooms game={game.data} />}</>;
}
