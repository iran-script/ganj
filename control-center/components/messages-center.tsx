'use client';

import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';
import { normalizeApiError } from '@/lib/api-error';
import { queries } from '@/lib/queries';
import { Button, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import type { SessionPlayer, SessionTeam } from '@/types/api';

type RecipientType = 'SESSION' | 'TEAM' | 'PLAYER';
type Priority = 'NORMAL' | 'EMERGENCY';

function errorText(reason: unknown, fallback: string): string {
  const error = normalizeApiError(reason, fallback);
  if (error.status === 403 || error.code === 'AUTHORIZATION_DENIED') return 'برای این جلسه دسترسی GameMaster ندارید.';
  if (error.status === 422 || error.code === 'VALIDATION_FAILED') return 'اطلاعات گیرنده یا متن پیام معتبر نیست.';
  if (error.status === 0 || error.code === 'NETWORK_ERROR') return 'ارتباط با Backend برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.';
  return error.message || fallback;
}

function playerLabel(player: SessionPlayer): string {
  return player.name ?? player.user?.name ?? `بازیکن ${player.id}`;
}

export function MessagesCenter() {
  const client = useQueryClient();
  const sessionsQuery = useQuery(queries.sessions());
  const [sessionId, setSessionId] = useState('');
  const [recipientType, setRecipientType] = useState<RecipientType>('SESSION');
  const [targetId, setTargetId] = useState('');
  const [body, setBody] = useState('');
  const [priority, setPriority] = useState<Priority>('NORMAL');
  const [reason, setReason] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const sessions = useMemo(() => sessionsQuery.data ?? [], [sessionsQuery.data]);
  const roomQuery = useQuery({ ...queries.controlRoom(sessionId), enabled: Boolean(sessionId), retry: false });
  const teams = useMemo(() => roomQuery.data?.teams ?? [], [roomQuery.data?.teams]);
  const players = useMemo(() => teams.flatMap((team: SessionTeam) => (team.players ?? []).map((player) => ({ player, teamName: team.name ?? 'تیم بدون نام' }))), [teams]);

  useEffect(() => {
    if (!sessionId && sessions[0]?.id) setSessionId(sessions[0].id);
  }, [sessionId, sessions]);

  useEffect(() => {
    setTargetId(recipientType === 'SESSION' ? sessionId : '');
  }, [recipientType, sessionId]);

  const mutation = useMutation({
    mutationFn: async () => {
      if (!sessionId || !targetId || !body.trim()) throw new Error('invalid-form');
      return api.post<{ message_id?: string }>(`/game-sessions/${sessionId}/messages`, {
        target_type: recipientType,
        target_id: targetId,
        body: body.trim(),
        priority,
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      }, { headers: { 'Idempotency-Key': `message-center-${crypto.randomUUID()}` } });
    },
    onSuccess: async (response) => {
      setFormError(null);
      setNotice(`پیام با موفقیت ثبت شد${response.message_id ? `؛ شناسه: ${response.message_id}` : ''}.`);
      setBody('');
      setReason('');
      await client.invalidateQueries({ queryKey: ['control-room', sessionId] });
    },
    onError: (error) => {
      setNotice(null);
      setFormError(error instanceof Error && error.message === 'invalid-form' ? 'جلسه، گیرنده و متن پیام الزامی هستند.' : errorText(error, 'ارسال پیام انجام نشد.'));
    },
  });

  const targetOptions = recipientType === 'TEAM'
    ? teams.map((team) => ({ id: team.team_id ?? team.id, label: team.name ?? team.id }))
    : players.map(({ player, teamName }) => ({ id: player.id, label: `${playerLabel(player)} — ${teamName}` }));
  const canSubmit = Boolean(sessionId && targetId && body.trim()) && !mutation.isPending;

  if (sessionsQuery.isPending) return <LoadingState label="در حال دریافت جلسات..." />;
  if (sessionsQuery.isError) return <ErrorState message={errorText(sessionsQuery.error, 'فهرست جلسات دریافت نشد.')} onRetry={() => void sessionsQuery.refetch()} />;
  if (!sessions.length) return <EmptyState title="جلسه‌ای برای پیام‌رسانی وجود ندارد" description="ارسال پیام فقط برای یک جلسهٔ واقعی و از مسیر مجاز Backend انجام می‌شود." />;

  return <div dir="rtl">
    <PageHeader eyebrow="MESSAGING / GAME MASTER" title="پیام‌ها و اعلان‌ها" description="ارسال پیام عملیاتی به جلسه، تیم یا بازیکن با مجوز نهایی Backend." actions={sessionId ? <div className="flex gap-2"><Button variant="secondary" href={`/control/sessions/${sessionId}`}>جزئیات جلسه</Button><Button variant="ghost" href={`/control/live/${sessionId}`}>اتاق کنترل زنده</Button></div> : undefined} />
    <div className="mb-6 rounded-xl border border-[var(--nexo-info)]/30 bg-[var(--nexo-info)]/8 p-4 text-sm text-[var(--nexo-info)]">Backend فقط scopeهای SESSION، TEAM و PLAYER را برای این endpoint می‌پذیرد. مجوز GameMaster برای جلسه در سرور بررسی می‌شود و تحویل/خوانده‌شدن پیام در API فعلی قابل مشاهده نیست.</div>
    {notice && <div role="status" className="mb-4 rounded-lg border border-[var(--nexo-success)]/40 bg-[var(--nexo-success)]/10 p-4 text-sm text-[var(--nexo-success)]">{notice}</div>}
    {formError && <div role="alert" className="mb-4 rounded-lg border border-[var(--nexo-danger)]/40 bg-[var(--nexo-danger)]/10 p-4 text-sm text-[var(--nexo-danger)]">{formError}</div>}
    <div className="grid gap-6 xl:grid-cols-[1.2fr_.8fr]">
      <section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
        <h2 className="font-bold">ارسال پیام</h2>
        <p className="mt-1 text-xs text-[var(--nexo-muted)]">ارسال تکراری هنگام انتظار درخواست مسدود است و برای هر ارسال Idempotency-Key ساخته می‌شود.</p>
        <form className="mt-5 space-y-4" onSubmit={(event) => { event.preventDefault(); if (canSubmit) mutation.mutate(); }}>
          <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">جلسه</span><select aria-label="جلسه پیام" value={sessionId} onChange={(event) => setSessionId(event.target.value)} className="nexo-input w-full">{sessions.map((session) => <option key={session.id} value={session.id}>{session.title ?? session.game?.title ?? session.id} — {session.status}</option>)}</select></label>
          <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">مخاطب</span><select aria-label="نوع مخاطب" value={recipientType} onChange={(event) => setRecipientType(event.target.value as RecipientType)} className="nexo-input w-full"><option value="SESSION">کل جلسه</option><option value="TEAM">تیم</option><option value="PLAYER">بازیکن</option></select></label><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">گیرنده</span>{recipientType === 'SESSION' ? <input aria-label="گیرنده پیام" value={sessionId} readOnly className="nexo-input w-full opacity-70" /> : <select aria-label="گیرنده پیام" value={targetId} onChange={(event) => setTargetId(event.target.value)} className="nexo-input w-full"><option value="">انتخاب کنید</option>{targetOptions.map((target) => <option key={target.id} value={target.id}>{target.label}</option>)}</select>}</label></div>
          {recipientType !== 'SESSION' && roomQuery.isPending && <div className="text-xs text-[var(--nexo-muted)]">در حال دریافت گیرنده‌های مجاز...</div>}
          {recipientType !== 'SESSION' && roomQuery.isError && <ErrorState message="اطلاعات تیم‌ها و بازیکنان دریافت نشد." onRetry={() => void roomQuery.refetch()} />}
          {recipientType !== 'SESSION' && !roomQuery.isPending && !roomQuery.isError && !targetOptions.length && <EmptyState title="گیرنده‌ای در snapshot جلسه نیست" description="فقط گیرنده‌هایی که Backend در اتاق کنترل برمی‌گرداند قابل انتخاب‌اند." />}
          <label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">متن پیام</span><textarea aria-label="متن پیام" value={body} onChange={(event) => setBody(event.target.value)} maxLength={10000} rows={5} className="nexo-input w-full" placeholder="متن پیام را وارد کنید..." /></label>
          <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">اولویت</span><select aria-label="اولویت پیام" value={priority} onChange={(event) => setPriority(event.target.value as Priority)} className="nexo-input w-full"><option value="NORMAL">عادی</option><option value="EMERGENCY">اضطراری</option></select></label><label className="block text-sm"><span className="mb-2 block text-[var(--nexo-muted)]">دلیل (اختیاری)</span><input aria-label="دلیل پیام" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={1000} className="nexo-input w-full" /></label></div>
          <Button type="submit" disabled={!canSubmit}>{mutation.isPending ? 'در حال ارسال...' : 'ارسال پیام'}</Button>
        </form>
      </section>
      <aside className="space-y-6"><section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><h2 className="font-bold">وضعیت جلسه</h2><div className="mt-4 flex items-center justify-between"><span className="text-sm text-[var(--nexo-muted)]">{sessions.find((session) => session.id === sessionId)?.title ?? sessionId}</span><StatusBadge status={sessions.find((session) => session.id === sessionId)?.status ?? 'DRAFT'} /></div><p className="mt-4 text-xs leading-6 text-[var(--nexo-muted)]">دسترسی ارسال پیام با نقش محلی حدس زده نمی‌شود؛ Backend هنگام ارسال، انتساب GameMaster و مالکیت گیرنده را قطعی می‌کند.</p></section><section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5"><h2 className="font-bold">اعلان‌ها و تاریخچه</h2><p className="mt-2 text-sm leading-7 text-[var(--nexo-muted)]">Endpoint خواندن تاریخچه، رسید تحویل یا inbox اعلان‌ها در قرارداد فعلی وجود ندارد. این صفحه فقط نتیجهٔ واقعی ارسال را نمایش می‌دهد.</p></section></aside>
    </div>
  </div>;
}
