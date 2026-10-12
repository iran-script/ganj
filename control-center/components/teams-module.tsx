'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, ChevronLeft, Search, Users } from 'lucide-react';
import { queries } from '@/lib/queries';
import { filterTeams } from '@/lib/office-utils';
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '@/components/ui';
import type { SessionPlayer, Team } from '@/types/api';

function memberName(member: SessionPlayer): string { return member.name ?? member.user?.name ?? 'بازیکن بدون نام'; }

export function TeamsPage() {
  const teams = useQuery(queries.teams());
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('ALL');
  if (teams.isPending) return <LoadingState label="در حال دریافت تیم‌ها..." />;
  if (teams.isError) return <ErrorState message="اطلاعات تیم‌ها در دسترس نیست." onRetry={() => void teams.refetch()} />;
  const filtered = filterTeams(teams.data, search, status);
  return <><PageHeader eyebrow="TEAMS / OPERATIONS" title="تیم‌ها" description="وضعیت آمادگی و حضور تیم‌های سازمان" /><div className="mb-5 flex flex-wrap gap-3"><label className="relative min-w-[240px] flex-1"><span className="sr-only">جست‌وجوی تیم</span><Search size={16} className="absolute right-3 top-3.5 text-[var(--nexo-muted)]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جست‌وجوی نام تیم" className="nexo-input pr-10" /></label><label className="min-w-40"><span className="sr-only">فیلتر وضعیت</span><select value={status} onChange={(event) => setStatus(event.target.value)} className="nexo-input"><option value="ALL">همه وضعیت‌ها</option><option value="READY">آماده</option><option value="ACTIVE">فعال</option><option value="INACTIVE">غیرفعال</option></select></label></div>{teams.data.length === 0 ? <EmptyState title="تیمی ثبت نشده است" description="تیم‌های ثبت‌شده از API سازمان در اینجا نمایش داده می‌شوند." /> : filtered.length === 0 ? <EmptyState title="تیمی مطابق فیلتر پیدا نشد" description="فیلترها یا عبارت جست‌وجو را تغییر دهید." /> : <DataTable headers={['تیم', 'اعضا', 'آمادگی', 'دستگاه', 'جلسه', 'امتیاز', 'وضعیت']}><>{filtered.map((team) => <TeamRow key={team.id} team={team} />)}</></DataTable>}</>;
}

function TeamRow({ team }: { team: Team }) { return <tr className="hover:bg-white/[2%]"><td className="px-5 py-4"><Link href={`/control/teams/${team.id}`} className="font-semibold text-white hover:text-[var(--nexo-amber)]">{team.name ?? 'تیم بدون نام'}</Link><div className="mt-1 text-xs text-[var(--nexo-muted)]">{team.id}</div></td><td className="px-5 py-4 text-[var(--nexo-muted)]">{team.member_count ?? team.members?.length ?? team.players?.length ?? '—'}</td><td className="px-5 py-4">{team.readiness ? <StatusBadge status={team.readiness} /> : '—'}</td><td className="px-5 py-4 text-xs text-[var(--nexo-muted)]">{team.device_status ?? 'اطلاعات ندارد'}</td><td className="px-5 py-4 text-xs text-[var(--nexo-muted)]">{team.session?.title ?? team.session_id ?? '—'}</td><td className="px-5 py-4 text-[var(--nexo-amber)]">{team.score?.score ?? '—'}</td><td className="px-5 py-4"><StatusBadge status={team.status ?? 'UNKNOWN'} /></td></tr>; }

export function TeamDetailPage({ id }: { id: string }) {
  const team = useQuery(queries.team(id));
  if (team.isPending) return <LoadingState label="در حال دریافت جزئیات تیم..." />;
  if (team.isError) return <ErrorState message="جزئیات تیم در دسترس نیست." onRetry={() => void team.refetch()} />;
  const members = team.data.members ?? team.data.players ?? [];
  return <><PageHeader eyebrow="TEAM DETAIL" title={team.data.name ?? 'جزئیات تیم'} description={`شناسه تیم: ${team.data.id}`} actions={team.data.session_id ? <Link href={`/control/live/${team.data.session_id}`} className="inline-flex min-h-10 items-center rounded-lg bg-[var(--nexo-amber)] px-4 text-sm font-semibold text-[#17120b]">کنترل زنده</Link> : undefined} /><div className="grid gap-5 xl:grid-cols-[1.2fr_.8fr]"><section className="nexo-surface rounded-xl p-5"><div className="flex flex-wrap items-center gap-3"><div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--nexo-amber-soft)] text-[var(--nexo-amber)]"><Users size={22} /></div><div><h2 className="font-bold">{team.data.name ?? 'تیم بدون نام'}</h2><div className="mt-1 text-xs text-[var(--nexo-muted)]">{members.length || team.data.member_count || 0} عضو · جلسه {team.data.session?.title ?? team.data.session_id ?? 'ثبت نشده'}</div></div><div className="mr-auto"><StatusBadge status={team.data.status ?? 'UNKNOWN'} /></div></div><div className="mt-6 grid gap-3 sm:grid-cols-3"><Stat label="آمادگی" value={team.data.readiness ?? 'نامشخص'} /><Stat label="وضعیت دستگاه" value={team.data.device_status ?? 'نامشخص'} /><Stat label="امتیاز" value={String(team.data.score?.score ?? '—')} /></div></section><section className="nexo-surface rounded-xl p-5"><div className="flex items-center gap-2"><Activity size={18} className="text-[var(--nexo-info)]" /><h2 className="font-bold">وضعیت Runtime</h2></div><p className="mt-4 text-sm text-[var(--nexo-muted)]">گره فعلی</p><p className="mt-1 font-semibold">{team.data.runtime?.current_node ?? 'اطلاعات در دسترس نیست'}</p><p className="mt-4 text-sm text-[var(--nexo-muted)]">وضعیت اجرا</p><p className="mt-1 font-semibold">{team.data.runtime?.status ?? 'اطلاعات در دسترس نیست'}</p></section></div><section className="nexo-surface mt-5 rounded-xl p-5"><div className="mb-4 flex items-center justify-between"><h2 className="font-bold">اعضای تیم</h2><span className="text-xs text-[var(--nexo-muted)]">{members.length} نفر</span></div>{members.length === 0 ? <EmptyState title="عضوی در payload تیم وجود ندارد" description="برای نمایش اعضا، API باید members یا players را برگرداند." /> : <div className="grid gap-3 md:grid-cols-2">{members.map((member) => <div key={member.id} className="rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-4"><div className="flex items-center justify-between"><div><div className="font-medium">{memberName(member)}</div><div className="mt-1 text-xs text-[var(--nexo-muted)]">{member.status ?? 'وضعیت نامشخص'} · {member.presence ?? 'حضور نامشخص'}</div></div><ChevronLeft size={16} className="text-[var(--nexo-muted)]" /></div><div className="mt-3 text-xs text-[var(--nexo-muted)]">دستگاه: {member.device_bound ? 'متصل' : 'ثبت نشده'}</div></div>)}</div>}</section></>;
}

function Stat({ label, value }: { label: string; value: string }) { return <div className="rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-bg)] p-3"><div className="text-xs text-[var(--nexo-muted)]">{label}</div><div className="mt-2 text-sm font-semibold">{value}</div></div>; }
