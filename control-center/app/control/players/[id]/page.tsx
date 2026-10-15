'use client';

import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { managementApi } from '@/lib/management-api';
import { Button, DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';

export default function PlayerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const player = useQuery({ queryKey: ['management-player', id], queryFn: () => managementApi.player(id) });
  if (player.isPending) return <LoadingState label="در حال دریافت سابقه بازیکن..." />;
  if (player.isError) return <ErrorState message="اطلاعات بازیکن دریافت نشد یا خارج از محدوده مجاز است." onRetry={() => void player.refetch()} />;
  return <div dir="rtl">
    <PageHeader eyebrow="PLAYER / PROFILE" title={player.data.user.name} description="نمایش فقط‌خواندنی پروفایل، عضویت‌های تاریخی و مشارکت‌های ذخیره‌شده؛ نتایج تاریخی قابل ویرایش نیستند." actions={<Button href="/control/players" variant="secondary">بازگشت</Button>} />
    <section className="nexo-surface mb-5 grid gap-4 rounded-xl p-5 sm:grid-cols-3"><div><div className="text-xs text-[var(--nexo-muted)]">شناسه حساب</div><div className="mt-1 break-all font-mono text-xs">{player.data.user.id}</div></div><div><div className="text-xs text-[var(--nexo-muted)]">ایمیل</div><div className="mt-1">{player.data.user.email ?? '—'}</div></div><div><div className="text-xs text-[var(--nexo-muted)]">وضعیت حساب</div><div className="mt-1">{player.data.user.status ? <StatusBadge status={player.data.user.status} /> : 'اطلاعات محدود به عملیات نشست'}</div></div></section>
    <section className="mb-6"><h2 className="mb-3 font-bold">تاریخچه تیم‌ها</h2>{player.data.team_memberships?.length ? <DataTable headers={['تیم', 'سازمان', 'وضعیت عضویت', 'شروع', 'پایان']}>{player.data.team_memberships.map((member, index) => <tr key={`${member.team_id}-${index}`}><td className="px-4 py-3">{member.team_name}</td><td className="px-4 py-3">{member.organization?.name ?? '—'}</td><td className="px-4 py-3"><StatusBadge status={member.status} /></td><td className="px-4 py-3">{member.joined_at ? new Date(member.joined_at).toLocaleDateString('fa-IR') : '—'}</td><td className="px-4 py-3">{member.left_at ? new Date(member.left_at).toLocaleDateString('fa-IR') : '—'}</td></tr>)}</DataTable> : <EmptyState title="عضویت تیمی در محدوده موجود نیست" description="در صورت دسترسی اپراتوری نشست، تاریخچه خارج از نشست‌های تخصیص‌یافته نمایش داده نمی‌شود." />}</section>
    <section><h2 className="mb-3 font-bold">مشارکت در بازی‌ها و نشست‌ها</h2>{player.data.participation.length ? <DataTable headers={['نشست', 'وضعیت نشست', 'سازمان', 'تیم', 'وضعیت مشارکت', 'آخرین فعالیت']}>{player.data.participation.map((item) => <tr key={item.player_id}><td className="px-4 py-3 font-mono text-xs">{item.session_id}</td><td className="px-4 py-3">{item.session_status}</td><td className="px-4 py-3">{item.organization?.name ?? '—'}</td><td className="px-4 py-3">{item.team_name}</td><td className="px-4 py-3">{item.participation_status}</td><td className="px-4 py-3">{item.last_seen_at ? new Date(item.last_seen_at).toLocaleString('fa-IR') : '—'}</td></tr>)}</DataTable> : <EmptyState title="مشارکتی ثبت نشده" description="سابقه فقط از داده‌های واقعی نشست و تیم نمایش داده می‌شود." />}</section>
  </div>;
}
