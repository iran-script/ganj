'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { normalizeApiError } from '@/lib/api-error';
import { availableSessionActions, performSessionAction, sessionActionLabels } from '@/lib/session-actions';
import { queries } from '@/lib/queries';
import type { GameSession, SessionAction, SessionPlayer, SessionTeam } from '@/types/api';
import { Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, SectionHeader, StatusBadge, TimelineItem } from '@/components/ui';

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('fa-IR') : 'ارائه نشده';
}

function canOperateSession(session: GameSession, context?: { global_roles?: string[]; capabilities?: string[]; organizations?: Array<{ id: string; role: string }> }): boolean {
  if (!context) {
    return false;
  }
  if (context.global_roles?.includes('SUPER_ADMIN') || context.capabilities?.includes('session.players.operate')) {
    return true;
  }
  return Boolean(session.organization_id && context.organizations?.some((organization) => organization.id === session.organization_id && organization.role === 'ORGANIZATION_ADMIN'));
}

function playerName(player: SessionPlayer): string {
  return player.name ?? player.user?.name ?? player.id;
}

function TeamCard({ team }: { team: SessionTeam }) {
  const players = team.players ?? [];
  return (
    <div className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <div className="font-semibold">{team.name ?? team.team_id ?? team.id}</div>
          <div className="mt-1 text-xs text-[var(--nexo-muted)]">شناسه تیم: {team.team_id ?? team.id}</div>
        </div>
        <StatusBadge status={team.status ?? 'UNKNOWN'} />
      </div>
      <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        <div><span className="text-[var(--nexo-muted)]">تعداد اعضا: </span>{team.player_count ?? team.member_count ?? players.length}</div>
        <div><span className="text-[var(--nexo-muted)]">آمادگی: </span>{team.readiness ?? 'ارائه نشده'}</div>
      </div>
      {players.length > 0 ? (
        <div className="mt-4 border-t border-[var(--nexo-border)] pt-3">
          <div className="mb-2 text-xs text-[var(--nexo-muted)]">شرکت‌کنندگان</div>
          <div className="space-y-2">
            {players.map((player) => (
              <div key={player.id} className="flex items-center justify-between text-sm">
                <span>{playerName(player)}</span>
                <span className="text-xs text-[var(--nexo-muted)]">{player.presence ?? player.status ?? 'وضعیت ارائه نشده'}</span>
              </div>
            ))}
          </div>
        </div>
      ) : (
        <p className="mt-4 text-xs text-[var(--nexo-muted)]">فهرست شرکت‌کنندگان برای این کاربر در پاسخ API ارائه نشده است.</p>
      )}
    </div>
  );
}

export function SessionDetail() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? '';
  const queryClient = useQueryClient();
  const [confirmation, setConfirmation] = useState<SessionAction | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const sessionQuery = useQuery({ ...queries.session(id), enabled: Boolean(id) });
  const timelineQuery = useQuery({ ...queries.timeline(id), enabled: Boolean(id) });
  const capabilitiesQuery = useQuery({ ...queries.capabilities(), enabled: Boolean(id) });
  const gameQuery = useQuery({ ...queries.game(sessionQuery.data?.game_id ?? ''), enabled: Boolean(sessionQuery.data?.game_id) });
  const actionMutation = useMutation({
    mutationFn: (action: SessionAction) => performSessionAction(id, action),
    onSuccess: async () => {
      setConfirmation(null);
      setActionError(null);
      setNotice('عملیات جلسه با موفقیت ثبت شد.');
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['sessions', id] }),
        queryClient.invalidateQueries({ queryKey: ['sessions'] }),
        queryClient.invalidateQueries({ queryKey: ['sessions', id, 'timeline'] }),
        queryClient.invalidateQueries({ queryKey: ['control-room', id] }),
      ]);
    },
    onError: (error) => {
      setConfirmation(null);
      setActionError(normalizeApiError(error, 'ثبت عملیات جلسه انجام نشد.').message);
    },
  });

  const session = sessionQuery.data;
  const actions = useMemo(() => (session ? availableSessionActions(session.status) : []), [session]);
  const canOperate = session ? canOperateSession(session, capabilitiesQuery.data) : false;
  const game = gameQuery.data;
  const title = session?.title ?? game?.title ?? game?.name ?? 'جزئیات جلسه';

  if (sessionQuery.isPending) {
    return <LoadingState label="در حال دریافت اطلاعات جلسه..." />;
  }
  if (sessionQuery.isError || !session) {
    return <ErrorState message={normalizeApiError(sessionQuery.error, 'دریافت اطلاعات جلسه انجام نشد.').message} onRetry={() => sessionQuery.refetch()} />;
  }

  return (
    <div dir="rtl">
      <PageHeader
        eyebrow="جلسه بازی"
        title={title}
        description={'شناسه جلسه: ' + session.id}
        actions={(
          <div className="flex flex-wrap gap-2">
            {session.game_id && <Button href={'/control/games/' + session.game_id} variant="secondary">مشاهده بازی</Button>}
            {['READY', 'RUNNING', 'PAUSED'].includes(session.status) && <Button href={'/control/live/' + session.id} variant="secondary">اتاق کنترل زنده</Button>}
            <Button href="/control/sessions" variant="ghost">بازگشت به جلسات</Button>
          </div>
        )}
      />

      {notice && <div role="status" className="mb-5 rounded-lg border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/10 p-4 text-sm text-[var(--nexo-success)]">{notice}</div>}
      {actionError && <ErrorState message={actionError} onRetry={() => setActionError(null)} />}

      <section className="mb-6 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3"><span className="text-sm text-[var(--nexo-muted)]">وضعیت فعلی</span><StatusBadge status={session.status} /></div>
          {canOperate && actions.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              {actions.map((action) => <Button key={action} variant={action === 'cancel' ? 'ghost' : 'secondary'} onClick={() => setConfirmation(action)} disabled={actionMutation.isPending}>{sessionActionLabels[action]}</Button>)}
            </div>
          ) : (
            <span className="text-xs text-[var(--nexo-muted)]">{capabilitiesQuery.isPending ? 'در حال بررسی سطح دسترسی...' : 'این جلسه برای شما در حالت فقط‌خواندنی است.'}</span>
          )}
        </div>
      </section>

      <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
        <div className="space-y-6">
          <section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
            <SectionHeader title="اطلاعات جلسه" description="اطلاعاتی که مستقیماً از قرارداد Session API دریافت شده است." />
            <dl className="grid gap-4 text-sm sm:grid-cols-2">
              <div><dt className="text-[var(--nexo-muted)]">بازی</dt><dd className="mt-1">{game ? <Link className="text-[var(--nexo-amber)] hover:underline" href={'/control/games/' + game.id}>{game.title ?? game.name ?? game.id}</Link> : session.game_id ?? 'ارائه نشده'}</dd></div>
              <div><dt className="text-[var(--nexo-muted)]">سناریو / نسخه</dt><dd className="mt-1">{game?.scenario ? <Link className="text-[var(--nexo-amber)] hover:underline" href={'/control/scenarios/' + game.scenario.id}>{game.scenario.title ?? ('نسخه ' + (game.scenario.version_no ?? game.scenario.version_id ?? ''))}</Link> : 'در API جلسه یا بازی ارائه نشده است'}</dd></div>
              <div><dt className="text-[var(--nexo-muted)]">سازمان</dt><dd className="mt-1">{session.organization_id ?? game?.organization?.name ?? 'ارائه نشده'}</dd></div>
              <div><dt className="text-[var(--nexo-muted)]">منطقه زمانی</dt><dd className="mt-1">{session.timezone ?? 'ارائه نشده'}</dd></div>
            </dl>
            <div className="mt-5 border-t border-[var(--nexo-border)] pt-4">
              <div className="mb-3 text-sm font-semibold">زمان‌های پشتیبانی‌شده</div>
              <dl className="grid gap-3 text-xs sm:grid-cols-2">
                <div><dt className="text-[var(--nexo-muted)]">شروع ثبت‌نام</dt><dd className="mt-1">{formatDate(session.registration_opens_at)}</dd></div>
                <div><dt className="text-[var(--nexo-muted)]">پایان ثبت‌نام</dt><dd className="mt-1">{formatDate(session.registration_closes_at)}</dd></div>
                <div><dt className="text-[var(--nexo-muted)]">شروع برنامه‌ریزی‌شده</dt><dd className="mt-1">{formatDate(session.scheduled_start_at)}</dd></div>
                <div><dt className="text-[var(--nexo-muted)]">شروع واقعی</dt><dd className="mt-1">{formatDate(session.started_at)}</dd></div>
                <div><dt className="text-[var(--nexo-muted)]">پایان</dt><dd className="mt-1">{formatDate(session.finished_at ?? session.cancelled_at)}</dd></div>
                <div><dt className="text-[var(--nexo-muted)]">آخرین به‌روزرسانی</dt><dd className="mt-1">{formatDate(session.updated_at)}</dd></div>
              </dl>
            </div>
          </section>

          <section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
            <SectionHeader title="تیم‌ها و شرکت‌کنندگان" description="فقط روابطی نمایش داده می‌شوند که Session API در اختیار این کاربر قرار داده است." />
            {session.teams?.length ? <div className="grid gap-4">{session.teams.map((team) => <TeamCard key={team.team_id ?? team.id} team={team} />)}</div> : <EmptyState title="تیمی برای این جلسه ثبت نشده است" description="رابطه اتاق بازی با جلسه در Session API ارائه نشده است." />}
          </section>
        </div>

        <div className="space-y-6">
          <section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
            <SectionHeader title="نمای عملیاتی" />
            <div className="grid gap-3 text-sm">
              <div className="flex justify-between"><span className="text-[var(--nexo-muted)]">تیم‌های ثبت‌شده</span><span>{session.teams?.length ?? session.teams_count ?? session.team_count ?? 0}</span></div>
              <div className="flex justify-between"><span className="text-[var(--nexo-muted)]">شرکت‌کنندگان قابل مشاهده</span><span>{session.teams?.reduce((count, team) => count + (team.player_count ?? team.players?.length ?? 0), 0) ?? 'ارائه نشده'}</span></div>
              <div className="flex justify-between"><span className="text-[var(--nexo-muted)]">ظرفیت</span><span className="text-[var(--nexo-muted)]">در API جلسه ارائه نشده است</span></div>
              <div className="mt-2 border-t border-[var(--nexo-border)] pt-3 text-xs leading-6 text-[var(--nexo-muted)]">آمادگی عملیاتی و ظرفیت اتاق فقط در صورت ارائه داده معتبر از Backend قابل محاسبه است.</div>
            </div>
          </section>

          <section className="rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-5">
            <SectionHeader title="تاریخچه جلسه" />
            {timelineQuery.isPending ? <LoadingState label="در حال دریافت تاریخچه..." /> : timelineQuery.isError ? <ErrorState message="دریافت تاریخچه جلسه انجام نشد." onRetry={() => timelineQuery.refetch()} /> : timelineQuery.data?.length ? <div>{timelineQuery.data.map((event, index) => <TimelineItem key={event.event_id ?? event.event_type + '-' + index} event={event} />)}</div> : <EmptyState title="رویدادی ثبت نشده است" description="برای این جلسه تاریخچه‌ای از Backend دریافت نشد." />}
          </section>
        </div>
      </div>

      {confirmation && <ConfirmDialog title={sessionActionLabels[confirmation]} description={'آیا از اجرای «' + sessionActionLabels[confirmation] + '» برای این جلسه مطمئن هستید؟ مجوز نهایی توسط Backend بررسی می‌شود.'} confirmLabel="تأیید عملیات" onClose={() => { if (!actionMutation.isPending) setConfirmation(null); }} onConfirm={() => { if (!actionMutation.isPending) actionMutation.mutate(confirmation); }} />}
    </div>
  );
}
