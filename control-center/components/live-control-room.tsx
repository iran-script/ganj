'use client';

import { useEffect, useState } from 'react';
import { useIsMutating, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'next/navigation';
import { z } from 'zod';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { api } from '@/lib/api-client';
import { normalizeApiError } from '@/lib/api-error';
import { queryPolicy } from '@/lib/query';
import { queries } from '@/lib/queries';
import { Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, StatusBadge, TimelineItem } from '@/components/ui';
import type { LiveChallenge, SessionPlayer, SessionTeam } from '@/types/api';

const challengeSchema = z.object({ type: z.enum(['MESSAGE', 'TEAM_OBJECTIVE']), target_id: z.string().uuid('تیم را انتخاب کنید.'), message: z.string().min(1, 'متن چالش الزامی است.').max(10000), duration_seconds: z.coerce.number().int().min(1).max(86400).optional() });
const messageSchema = z.object({ body: z.string().min(1, 'متن پیام الزامی است.').max(10000), priority: z.enum(['NORMAL', 'EMERGENCY']) });

function useSmoothElapsed(startedAt?: string | null, finishedAt?: string | null): number | null {
  const [now, setNow] = useState(() => Date.now());
  const baseline = startedAt ? new Date(startedAt).getTime() : null;
  useEffect(() => {
    if (!baseline || finishedAt) {
      return;
    }
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [baseline, finishedAt]);
  const end = finishedAt ? new Date(finishedAt).getTime() : now;
  return baseline ? Math.max(0, Math.floor((end - baseline) / 1000)) : null;
}

function duration(seconds: number | null): string {
  if (seconds === null) {
    return 'ارائه نشده';
  }
  return [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60].map((part) => String(part).padStart(2, '0')).join(':');
}

function ChallengeForm({ teams, sessionId }: { teams: SessionTeam[]; sessionId: string }) {
  const client = useQueryClient();
  const [error, setError] = useState('');
  const form = useForm<z.infer<typeof challengeSchema>>({ resolver: zodResolver(challengeSchema), defaultValues: { type: 'MESSAGE' } });
  const mutation = useMutation({
    mutationFn: (values: z.infer<typeof challengeSchema>) => api.post<LiveChallenge>('/game-sessions/' + sessionId + '/live-challenges', { type: values.type, targets: [{ type: 'TEAM', id: values.target_id }], message: values.message, objective: values.message, duration_seconds: values.duration_seconds }, { headers: { 'Idempotency-Key': 'live-challenge-' + crypto.randomUUID() } }),
    onSuccess: async () => { await client.invalidateQueries({ queryKey: ['control-room', sessionId] }); form.reset({ type: 'MESSAGE', target_id: '', message: '', duration_seconds: undefined }); },
    onError: (reason) => setError(normalizeApiError(reason, 'ارسال چالش انجام نشد.').message),
  });
  return <form onSubmit={form.handleSubmit((values) => { if (!mutation.isPending) { setError(''); mutation.mutate(values); } })} className="space-y-3">
    <div className="grid gap-3 sm:grid-cols-2"><select {...form.register('type')} className="h-10 rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-2 text-sm"><option value="MESSAGE">پیام عملیاتی</option><option value="TEAM_OBJECTIVE">هدف تیمی</option></select><select {...form.register('target_id')} className="h-10 rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-2 text-sm"><option value="">تیم هدف</option>{teams.map((team) => <option key={team.team_id} value={team.team_id}>{team.name}</option>)}</select></div>
    <textarea {...form.register('message')} rows={3} className="w-full rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] p-3 text-sm" placeholder="پیام یا هدف را وارد کنید..." />
    <input type="number" {...form.register('duration_seconds')} className="h-10 w-full rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-3 text-sm" placeholder="مدت (ثانیه، اختیاری)" />
    {error && <div role="alert" className="text-xs text-[var(--nexo-danger)]">{error}</div>}
    <Button type="submit" disabled={mutation.isPending}>{mutation.isPending ? 'در حال ارسال...' : 'ارسال چالش'}</Button>
  </form>;
}

function MessageForm({ team, sessionId }: { team: SessionTeam; sessionId: string }) {
  const client = useQueryClient();
  const [error, setError] = useState('');
  const form = useForm<z.infer<typeof messageSchema>>({ resolver: zodResolver(messageSchema), defaultValues: { priority: 'NORMAL' } });
  const mutation = useMutation({
    mutationFn: (values: z.infer<typeof messageSchema>) => api.post('/game-sessions/' + sessionId + '/messages', { target_type: 'TEAM', target_id: team.team_id, body: values.body, priority: values.priority }, { headers: { 'Idempotency-Key': 'message-' + crypto.randomUUID() } }),
    onSuccess: async () => { form.reset({ body: '', priority: 'NORMAL' }); await client.invalidateQueries({ queryKey: ['control-room', sessionId] }); },
    onError: (reason) => setError(normalizeApiError(reason, 'ارسال پیام انجام نشد.').message),
  });
  return <form onSubmit={form.handleSubmit((values) => { if (!mutation.isPending) { setError(''); mutation.mutate(values); } })} className="mt-4 space-y-2">
    <textarea {...form.register('body')} rows={2} className="w-full rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] p-3 text-sm" placeholder={'پیام برای ' + (team.name ?? 'تیم') + '...'} />
    <div className="flex gap-2"><select {...form.register('priority')} className="h-10 rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-2 text-xs"><option value="NORMAL">عادی</option><option value="EMERGENCY">اضطراری</option></select><Button type="submit" disabled={mutation.isPending}>{mutation.isPending ? 'در حال ارسال...' : 'ارسال پیام'}</Button></div>
    {error && <div role="alert" className="text-xs text-[var(--nexo-danger)]">{error}</div>}
  </form>;
}

function TeamDrawer({ team, sessionId, onClose, onDisqualify }: { team: SessionTeam; sessionId: string; onClose: () => void; onDisqualify: (player: SessionPlayer) => void }) {
  return <div className="fixed inset-0 z-40 bg-black/60" onClick={onClose}><aside role="dialog" aria-label={'جزئیات ' + (team.name ?? 'تیم')} className="absolute inset-y-0 left-0 w-full max-w-lg overflow-y-auto border-r border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-6" onClick={(event) => event.stopPropagation()}>
    <div className="flex items-center justify-between"><div><div className="text-xs text-[var(--nexo-amber)]">TEAM DETAIL</div><h2 className="mt-1 text-xl font-bold">{team.name ?? 'تیم بدون نام'}</h2></div><button aria-label="بستن" onClick={onClose} className="text-2xl text-[var(--nexo-muted)]">×</button></div>
    <div className="mt-6 grid grid-cols-3 gap-3"><MetricCard label="امتیاز" value={team.score?.score ?? 'ارائه نشده'} tone="amber" /><MetricCard label="بازیکنان" value={team.players?.length ?? 0} /><MetricCard label="وضعیت" value={team.status ?? 'ارائه نشده'} tone="success" /></div>
    <section className="mt-6"><h3 className="font-bold">بازیکنان و دستگاه‌ها</h3><div className="mt-3 space-y-2">{team.players?.map((player) => <div key={player.id} className="rounded-lg border border-[var(--nexo-border)] p-3"><div className="flex items-center justify-between"><div><div className="font-medium">{player.name ?? player.user?.name ?? 'بازیکن'}</div><div className="mt-1 text-xs text-[var(--nexo-muted)]">{player.status ?? 'ارائه نشده'} · {player.device_bound ? 'دستگاه متصل' : 'دستگاه متصل نیست'}</div></div><button onClick={() => onDisqualify(player)} className="text-xs text-[var(--nexo-danger)]">حذف از بازی</button></div><div className="mt-2 text-[11px] text-[var(--nexo-muted)]">آخرین حضور: {player.presence ? new Date(player.presence).toLocaleString('fa-IR') : 'ثبت نشده'}</div></div>)}</div></section>
    <section className="mt-6"><h3 className="font-bold">ارسال پیام</h3><MessageForm team={team} sessionId={sessionId} /></section>
  </aside></div>;
}

export function LiveControlRoom() {
  const { id } = useParams<{ id: string }>();
  const client = useQueryClient();
  const activeMutations = useIsMutating();
  const [selectedTeam, setSelectedTeam] = useState<SessionTeam | null>(null);
  const [disqualifyPlayer, setDisqualifyPlayer] = useState<{ team: SessionTeam; player: SessionPlayer } | null>(null);
  const [operationError, setOperationError] = useState<string | null>(null);
  const query = useQuery({ ...queries.controlRoom(id), ...queryPolicy.live, refetchInterval: activeMutations > 0 ? false : queryPolicy.live.refetchInterval });
  const data = query.data;
  const elapsed = useSmoothElapsed(data?.session?.started_at, data?.session?.finished_at);
  const disqualifyMutation = useMutation({
    mutationFn: ({ player }: { player: SessionPlayer }) => api.post('/game-sessions/' + id + '/players/' + player.id + '/disqualify', { reason: 'اقدام GameMaster از اتاق کنترل' }, { headers: { 'Idempotency-Key': 'disqualify-' + crypto.randomUUID() } }),
    onSuccess: async () => { setDisqualifyPlayer(null); setOperationError(null); await client.invalidateQueries({ queryKey: ['control-room', id] }); },
    onError: (reason) => { setDisqualifyPlayer(null); setOperationError(normalizeApiError(reason, 'حذف بازیکن انجام نشد.').message); },
  });
  if (query.isPending) return <LoadingState label="در حال اتصال به اتاق کنترل..." />;
  if (query.isError || !data) return <ErrorState message={normalizeApiError(query.error, 'اطلاعات اتاق کنترل دریافت نشد.').message} onRetry={() => void query.refetch()} />;
  const teams = data.teams ?? [];
  return <div dir="rtl">
    <PageHeader eyebrow="LIVE CONTROL / POLLING ۱۵s" title="کنترل زنده" description={(data.session?.game?.title ?? data.session?.game?.name ?? 'بازی') + ' · ' + (data.session?.id ?? id)} actions={<div className="flex gap-2"><Button variant="secondary" href={'/control/sessions/' + id}>جزئیات جلسه</Button><Button variant="ghost" href="/control/live">فهرست کنترل زنده</Button></div>} />
    {operationError && <ErrorState message={operationError} onRetry={() => setOperationError(null)} />}
    <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><MetricCard label="وضعیت جلسه" value={data.session?.status ?? 'ارائه نشده'} tone="success" /><MetricCard label="زمان سپری‌شده" value={duration(elapsed)} detail="مبنای زمان: timestamp سرور" tone="amber" /><MetricCard label="تیم‌های فعال" value={teams.length} tone="info" /><MetricCard label="وضعیت runtime" value={data.runtime?.status ?? 'ارائه نشده'} /></div>
    <div className="mb-6 flex items-center gap-3 rounded-lg border border-[var(--nexo-info)]/30 bg-[var(--nexo-info)]/8 p-4 text-sm text-[var(--nexo-info)]"><span className="h-2 w-2 animate-pulse rounded-full bg-current" />آخرین snapshot از API کنترل اتاق دریافت می‌شود · polling هر ۱۵ ثانیه{activeMutations > 0 ? ' · تا پایان عملیات متوقف است' : ''}</div>
    <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]"><section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><div className="mb-5 flex items-center justify-between"><h2 className="font-bold">پیشرفت تیم‌ها</h2><span className="text-xs text-[var(--nexo-muted)]">برای جزئیات انتخاب کنید</span></div>{teams.length ? <div className="space-y-3">{teams.map((team) => <button key={team.team_id} onClick={() => setSelectedTeam(team)} className="w-full rounded-lg border border-[var(--nexo-border)] bg-[#0d141c] p-4 text-right transition hover:border-[var(--nexo-amber)]/60"><div className="flex items-center justify-between"><div className="font-medium">{team.name ?? 'تیم بدون نام'}</div><StatusBadge status={team.status ?? 'DRAFT'} /></div><div className="mt-4 grid grid-cols-3 gap-3 text-xs"><span className="text-[var(--nexo-muted)]">امتیاز <strong className="mr-1 text-lg text-[var(--nexo-amber)]">{team.score?.score ?? 'ارائه نشده'}</strong></span><span className="text-[var(--nexo-muted)]">بازیکن <strong className="mr-1 text-white">{team.players?.length ?? 0}</strong></span><span className="text-[var(--nexo-muted)]">نود <strong className="mr-1 text-white">{typeof team.runtime?.current_node === 'string' ? team.runtime.current_node : 'ارائه نشده'}</strong></span></div></button>)}</div> : <EmptyState title="تیمی در runtime نیست" description="اطلاعات تیم‌ها از snapshot واقعی Backend نمایش داده می‌شود." />}</section>
      <aside className="space-y-6"><section className="nexo-surface rounded-xl p-5" aria-label="نقشه زنده"><h2 className="font-bold">نقشه زنده</h2><p className="mt-2 text-sm text-[var(--nexo-muted)]">موقعیت تیم‌ها و بازیکنان از API فعلی در دسترس نیست.</p></section><section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><h2 className="font-bold">Live Challenge</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">ایجاد چالش فقط با اعتبارسنجی و مجوز Backend انجام می‌شود.</p><div className="mt-4"><ChallengeForm teams={teams} sessionId={id} /></div>{data.live_challenges?.length ? <div className="mt-5 space-y-2">{data.live_challenges.map((challenge) => <div key={challenge.id} className="rounded-lg border border-[var(--nexo-border)] p-3"><div className="flex justify-between"><span className="text-sm">{challenge.config?.message ?? challenge.type}</span><StatusBadge status={challenge.status} /></div></div>)}</div> : <div className="mt-4 text-xs text-[var(--nexo-muted)]">چالش فعالی گزارش نشده است.</div>}</section><section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><h2 className="mb-5 font-bold">جلسه‌نامه عملیاتی</h2>{data.timeline?.length ? data.timeline.slice(0, 10).map((event, index) => <TimelineItem key={(event.event_type ?? 'event') + '-' + (event.occurred_at ?? index)} event={event} />) : <div className="text-sm text-[var(--nexo-muted)]">رویداد اخیری گزارش نشده است.</div>}<p className="mt-4 text-xs text-[var(--nexo-muted)]">پیام‌های ارسالی endpoint ثبت دارند، اما endpoint خواندن پیام‌ها در snapshot فعلی ارائه نشده است.</p></section></aside>
    </div>
    {selectedTeam && <TeamDrawer team={selectedTeam} sessionId={id} onClose={() => setSelectedTeam(null)} onDisqualify={(player) => setDisqualifyPlayer({ team: selectedTeam, player })} />}
    {disqualifyPlayer && <ConfirmDialog title="حذف بازیکن از جلسه" description={'بازیکن «' + (disqualifyPlayer.player.name ?? disqualifyPlayer.player.user?.name ?? '') + '» با اعتبارسنجی سرور از جلسه حذف می‌شود.'} confirmLabel={disqualifyMutation.isPending ? 'در حال ثبت...' : 'تأیید حذف'} onClose={() => { if (!disqualifyMutation.isPending) setDisqualifyPlayer(null); }} onConfirm={() => { if (!disqualifyMutation.isPending) disqualifyMutation.mutate({ player: disqualifyPlayer.player }); }} />}
  </div>;
}
