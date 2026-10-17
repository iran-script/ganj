'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { gameApi } from '@/lib/game-api';
import { normalizeApiError } from '@/lib/api-error';
import { queries } from '@/lib/queries';
import { Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import type { Game } from '@/types/api';

const gameFormSchema = z.object({
  name: z.string().trim().min(2, 'نام بازی حداقل باید ۲ نویسه باشد.').max(255, 'نام بازی بیش از حد طولانی است.'),
  slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'شناسه باید با حروف انگلیسی کوچک، عدد و خط تیره باشد.'),
  description: z.string().max(10000, 'توضیحات بیش از حد طولانی است.'),
  city: z.string().max(120, 'نام شهر بیش از حد طولانی است.'),
  default_timezone: z.string().min(1, 'منطقه زمانی الزامی است.'),
  status: z.enum(['DRAFT', 'ACTIVE']),
  pricing_mode: z.enum(['FREE', 'PAID']),
  amount: z.string(),
  currency: z.string().regex(/^[A-Z]{3}$/, 'واحد پول باید سه حرف بزرگ باشد.'),
});

type GameForm = z.infer<typeof gameFormSchema>;

function initialForm(game: Game): GameForm {
  return { name: game.name ?? game.title ?? '', slug: game.slug ?? '', description: game.description ?? '', city: game.city ?? '', default_timezone: game.default_timezone ?? 'UTC', status: game.status === 'ACTIVE' ? 'ACTIVE' : 'DRAFT', pricing_mode: game.pricing?.mode === 'PAID' ? 'PAID' : 'FREE', amount: String(game.pricing?.amount ?? 0), currency: game.pricing?.currency ?? 'IRR' };
}

function canManageGame(game: Game, capabilities?: { global_roles?: string[]; organizations?: Array<{ id: string; role: string }> }): boolean {
  if (capabilities?.global_roles?.includes('SUPER_ADMIN')) return true;
  return Boolean(game.organization_id && capabilities?.organizations?.some((item) => item.id === game.organization_id && item.role === 'ORGANIZATION_ADMIN'));
}

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('fa-IR') : 'ثبت نشده';
}

function EditGameForm({ game, onSaved, onCancel }: { game: Game; onSaved: (game: Game) => void; onCancel: () => void }) {
  const [values, setValues] = useState<GameForm>(() => initialForm(game));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const mutation = useMutation({ mutationFn: (input: GameForm) => gameApi.update(game.id, { name: input.name, slug: input.slug, description: input.description || undefined, city: input.city || undefined, default_timezone: input.default_timezone, status: input.status, pricing: { mode: input.pricing_mode, amount: input.pricing_mode === 'FREE' ? 0 : Number(input.amount), currency: input.currency }, reason: 'ویرایش بازی در مرکز کنترل' }), onSuccess: onSaved });
  const update = (key: keyof GameForm, value: string) => setValues((current) => ({ ...current, [key]: value }));
  const submit = (event: FormEvent) => { event.preventDefault(); const result = gameFormSchema.safeParse(values); if (!result.success) { setErrors(Object.fromEntries(result.error.issues.map((issue) => [String(issue.path[0]), issue.message]))); return; } setErrors({}); mutation.mutate(result.data); };
  return <form onSubmit={submit} className="nexo-surface space-y-5 rounded-xl p-5" aria-label="فرم ویرایش بازی">
    <div className="grid gap-4 sm:grid-cols-2">
      <label className="text-sm">نام بازی<input aria-label="نام بازی" className="nexo-input mt-2" value={values.name} onChange={(event) => update('name', event.target.value)} />{errors.name && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{errors.name}</span>}</label>
      <label className="text-sm">شناسه فنی<input aria-label="شناسه فنی" dir="ltr" className="nexo-input mt-2 text-left" value={values.slug} onChange={(event) => update('slug', event.target.value)} />{errors.slug && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{errors.slug}</span>}</label>
      <label className="text-sm">شهر<input aria-label="شهر" className="nexo-input mt-2" value={values.city} onChange={(event) => update('city', event.target.value)} />{errors.city && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{errors.city}</span>}</label>
      <label className="text-sm">منطقه زمانی<input aria-label="منطقه زمانی" dir="ltr" className="nexo-input mt-2 text-left" value={values.default_timezone} onChange={(event) => update('default_timezone', event.target.value)} />{errors.default_timezone && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{errors.default_timezone}</span>}</label>
    </div>
    <label className="block text-sm">توضیحات<textarea aria-label="توضیحات" className="nexo-input mt-2 min-h-24" value={values.description} onChange={(event) => update('description', event.target.value)} /></label>
    <div className="grid gap-4 sm:grid-cols-3">
      <label className="text-sm">وضعیت<select aria-label="وضعیت بازی" className="nexo-input mt-2" value={values.status} onChange={(event) => update('status', event.target.value)}><option value="DRAFT">پیش‌نویس</option><option value="ACTIVE">فعال</option></select></label>
      <label className="text-sm">قیمت‌گذاری<select aria-label="نوع قیمت‌گذاری" className="nexo-input mt-2" value={values.pricing_mode} onChange={(event) => update('pricing_mode', event.target.value)}><option value="FREE">رایگان</option><option value="PAID">پولی</option></select></label>
      <label className="text-sm">مبلغ<input aria-label="مبلغ" disabled={values.pricing_mode === 'FREE'} type="number" min="0" className="nexo-input mt-2" value={values.amount} onChange={(event) => update('amount', event.target.value)} /></label>
    </div>
    <label className="block text-sm">واحد پول<input aria-label="واحد پول" dir="ltr" className="nexo-input mt-2 text-left" value={values.currency} onChange={(event) => update('currency', event.target.value.toUpperCase())} />{errors.currency && <span className="mt-1 block text-xs text-[var(--nexo-danger)]">{errors.currency}</span>}</label>
    {mutation.isError && <ErrorState message={normalizeApiError(mutation.error).message} />}
    <div className="flex justify-end gap-2"><Button variant="ghost" onClick={onCancel}>انصراف</Button><Button type="submit" disabled={mutation.isPending}>{mutation.isPending ? 'در حال ذخیره...' : 'ذخیره تغییرات'}</Button></div>
  </form>;
}

export function GameDetail() {
  const { id } = useParams<{ id: string }>();
  const client = useQueryClient();
  const game = useQuery(queries.game(id));
  const sessions = useQuery(queries.sessions());
  const capabilities = useQuery({ ...queries.capabilities(), retry: false });
  const [editing, setEditing] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [notice, setNotice] = useState('');
  const archive = useMutation({ mutationFn: (reason: string) => gameApi.archive(id, reason), onSuccess: async (result) => { setArchiveOpen(false); setNotice('بازی با موفقیت بایگانی شد.'); client.setQueryData(['games', id], result); await client.invalidateQueries({ queryKey: ['games'] }); } });
  if (game.isPending) return <LoadingState label="در حال دریافت اطلاعات بازی..." />;
  if (game.isError) return <ErrorState message="اطلاعات بازی دریافت نشد یا دسترسی لازم وجود ندارد." onRetry={() => void game.refetch()} />;
  const data = game.data;
  const manageable = canManageGame(data, capabilities.data);
  const archived = data.status === 'ARCHIVED';
  const relatedSessions = sessions.data?.filter((session) => session.game_id === data.id) ?? [];
  const save = async (updated: Game) => { setEditing(false); setNotice('تغییرات بازی با موفقیت ذخیره شد.'); client.setQueryData(['games', id], updated); await client.invalidateQueries({ queryKey: ['games'] }); };
  return <div dir="rtl">
    <PageHeader eyebrow="مدیریت بازی" title={data.name ?? data.title ?? 'جزئیات بازی'} description="اطلاعات، وضعیت و جلسه‌های مرتبط با بازی" actions={<Button variant="secondary" href="/control/games">بازگشت به بازی‌ها</Button>} />
    {notice && <div role="status" className="mb-5 rounded-lg border border-[var(--nexo-success)]/30 bg-[var(--nexo-success)]/10 p-4 text-sm text-[var(--nexo-success)]">{notice}</div>}
    {!capabilities.isPending && !manageable && !archived && <div role="status" className="mb-5 rounded-lg border border-[var(--nexo-info)]/30 bg-[var(--nexo-info)]/10 p-4 text-sm text-[var(--nexo-info)]">این حساب دسترسی ویرایش یا بایگانی این بازی را ندارد؛ اطلاعات به‌صورت فقط‌خواندنی نمایش داده می‌شود.</div>}
    {editing ? <EditGameForm game={data} onSaved={save} onCancel={() => setEditing(false)} /> : <><div className="mb-5 grid gap-5 xl:grid-cols-3">
      <section className="nexo-surface rounded-xl p-5 xl:col-span-2"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-bold">اطلاعات بازی</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">شناسه: <span dir="ltr">{data.id}</span></p></div><StatusBadge status={data.status ?? 'DRAFT'} /></div><dl className="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><dt className="text-xs text-[var(--nexo-muted)]">نام فنی</dt><dd className="mt-2 text-sm" dir="ltr">{data.slug ?? '—'}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">شهر</dt><dd className="mt-2 text-sm">{data.city || 'ثبت نشده'}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">منطقه زمانی</dt><dd className="mt-2 text-sm" dir="ltr">{data.default_timezone ?? '—'}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">قیمت‌گذاری</dt><dd className="mt-2 text-sm">{data.pricing?.mode === 'PAID' ? data.pricing.amount + ' ' + (data.pricing.currency ?? '') : 'رایگان'}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">آخرین تغییر</dt><dd className="mt-2 text-sm">{formatDate(data.updated_at)}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">بایگانی</dt><dd className="mt-2 text-sm">{formatDate(data.archived_at)}</dd></div></dl>{data.description && <p className="mt-6 border-t border-[var(--nexo-border)] pt-5 text-sm leading-7">{data.description}</p>}</section>
      <section className="nexo-surface rounded-xl p-5"><h2 className="font-bold">سازمان و سناریو</h2><dl className="mt-5 space-y-4 text-sm"><div><dt className="text-xs text-[var(--nexo-muted)]">سازمان</dt><dd className="mt-1">{data.organization?.name ?? data.organization_id ?? 'ثبت نشده'}</dd><dd className="mt-1 text-xs text-[var(--nexo-muted)]" dir="ltr">{data.organization_id ?? '—'}</dd></div><div><dt className="text-xs text-[var(--nexo-muted)]">سناریو</dt><dd className="mt-1">{data.scenario?.title ?? 'تخصیص نشده'}</dd><dd className="mt-1 text-xs text-[var(--nexo-muted)]">{data.scenario ? 'نسخه ' + (data.scenario.version_no ?? '—') + ' · ' + (data.scenario.status === 'PUBLISHED' ? 'منتشرشده' : data.scenario.status) : 'برای اجرای جلسه نیاز به نسخه منتشرشده دارد.'}</dd></div></dl>{data.scenario?.id && <Link className="mt-5 inline-block text-sm text-[var(--nexo-amber)] hover:underline" href={'/control/scenarios/' + data.scenario.id}>مشاهده سناریو ←</Link>}</section>
    </div>
    {sessions.isError ? <ErrorState message="جلسه‌های مرتبط بارگذاری نشدند." onRetry={() => void sessions.refetch()} /> : <section className="nexo-surface rounded-xl p-5"><div className="mb-4 flex items-center justify-between gap-3"><div><h2 className="font-bold">جلسه‌های مرتبط</h2><p className="mt-1 text-xs text-[var(--nexo-muted)]">جلسه‌هایی که با همین بازی ساخته شده‌اند.</p></div><span className="text-sm text-[var(--nexo-muted)]">{sessions.isPending ? 'در حال بارگذاری...' : relatedSessions.length + ' جلسه'}</span></div>{sessions.isPending ? <LoadingState label="در حال دریافت جلسه‌های مرتبط..." /> : relatedSessions.length === 0 ? <EmptyState title="هنوز جلسه‌ای برای این بازی ثبت نشده است" description="پس از ایجاد جلسه، آن در این بخش نمایش داده می‌شود." action={<Button href="/control/sessions">مشاهده جلسه‌ها</Button>} /> : <div className="overflow-x-auto"><table className="w-full min-w-[620px] text-right text-sm"><thead className="border-b border-[var(--nexo-border)] text-xs text-[var(--nexo-muted)]"><tr><th className="px-4 py-3">جلسه</th><th className="px-4 py-3">وضعیت</th><th className="px-4 py-3">تیم‌ها</th><th className="px-4 py-3">شروع برنامه‌ریزی‌شده</th><th className="px-4 py-3">عملیات</th></tr></thead><tbody className="divide-y divide-[var(--nexo-border)]">{relatedSessions.map((session) => <tr key={session.id}><td className="px-4 py-3">{session.title ?? 'جلسه ' + session.id.slice(0, 8)}</td><td className="px-4 py-3"><StatusBadge status={session.status} /></td><td className="px-4 py-3">{session.teams_count ?? session.team_count ?? session.teams?.length ?? '—'}</td><td className="px-4 py-3 text-xs">{formatDate(session.scheduled_start_at)}</td><td className="px-4 py-3"><Link className="text-[var(--nexo-amber)] hover:underline" href={'/control/sessions/' + session.id}>جزئیات</Link></td></tr>)}</tbody></table></div>}</section>}
    <div className="mt-5 flex flex-wrap justify-end gap-2">{manageable && !archived && <><Button onClick={() => setEditing(true)}>ویرایش بازی</Button><Button variant="secondary" onClick={() => setArchiveOpen(true)}>بایگانی بازی</Button></>}</div></>}
    {archiveOpen && <ConfirmDialog title="بایگانی بازی" description="این بازی بایگانی می‌شود و دیگر قابل ویرایش یا استفاده برای جلسه جدید نخواهد بود. این عملیات قابل بازگشت نیست." confirmLabel={archive.isPending ? 'در حال بایگانی...' : 'تأیید بایگانی'} onClose={() => { if (!archive.isPending) setArchiveOpen(false); }} onConfirm={() => archive.mutate('بایگانی بازی از مرکز کنترل')} />}
    {archive.isError && <div role="alert" className="mt-4"><ErrorState message={normalizeApiError(archive.error).message} /></div>}
  </div>;
}
