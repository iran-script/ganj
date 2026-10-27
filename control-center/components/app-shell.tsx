'use client';

import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Activity, BarChart3, Boxes, FileText, Gamepad2, KeyRound, LayoutDashboard, LogOut, PanelRightClose, PanelRightOpen, Settings2, ShieldCheck, Sparkles, Users, type LucideIcon } from 'lucide-react';
import { ContentNavigation } from '@/components/content-navigation';
import { queries } from '@/lib/queries';
import { Button, ErrorState, LoadingState, SidebarLink, ThemeSwitcher } from '@/components/ui';

const links: Array<[string, string, LucideIcon]> = [
  ['/admin/members', 'اعضای سازمان', Users], ['/admin/org-admins', 'مدیران سازمانی', ShieldCheck], ['/submissions', 'بررسی مدارک', FileText],
  ['/', 'داشبورد', LayoutDashboard], ['/games', 'بازی‌ها', Gamepad2], ['/sessions', 'جلسه‌ها', FileText], ['/live/demo', 'کنترل زنده', Activity],
  ['/teams', 'تیم‌ها', Users], ['/players', 'بازیکنان', ShieldCheck], ['/scenarios', 'سناریوها', Sparkles], ['/content', 'محتوا', Boxes],
  ['/reports', 'گزارش‌ها', BarChart3], ['/admin', 'مدیریت', Settings2], ['/admin/users', 'اعضای سازمان', Users], ['/admin/admins', 'مدیران سراسری', ShieldCheck], ['/admin/roles', 'نقش‌ها', KeyRound],
];

links.push(['/admin/audit', 'Audit Log', Activity]);

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname(); const router = useRouter(); const cache = useQueryClient(); const [collapsed, setCollapsed] = useState(false);
  const me = useQuery({ ...queries.me(), retry: false }); const capabilities = useQuery({ ...queries.capabilities(), enabled: !me.isPending && !me.isError });
  useEffect(() => { if (me.isError) router.replace('/login'); }, [me.isError, router]);
  if (me.isPending) return <LoadingState label="در حال آماده‌سازی مرکز کنترل..." />;
  if (me.isError) return <div className="flex min-h-screen items-center justify-center p-6"><div className="w-full max-w-md"><ErrorState message="برای ورود به مرکز کنترل، ابتدا احراز هویت کنید." /><div className="mt-4 text-center"><Button href="/login">ورود به مرکز کنترل</Button></div></div></div>;
  const allowed = new Set(capabilities.data?.capabilities ?? []);
  const visibleLinks = links.filter(([href]) => { if (href === '/admin/users' || href === '/admin/admins') return allowed.has('admin.users.view'); if (href === '/admin/roles') return allowed.has('admin.roles.view'); if (href === '/admin/audit') return allowed.has('admin.audit.view'); if (href === '/admin/members' || href === '/admin/org-admins') return allowed.has('organization.members.manage'); if (href === '/players') return allowed.has('admin.players.view'); if (href === '/admin') return allowed.has('admin.users.view') || allowed.has('organization.members.manage'); return true; });
  const current = pathname.replace('/control', '') || '/';
  const logout = async () => { try { await fetch('/api/control/logout', { method: 'POST', credentials: 'same-origin' }); } finally { cache.clear(); router.replace('/login'); } };
  return <div className="min-h-screen bg-[var(--nexo-bg)]">
    <aside className={`fixed inset-y-0 right-0 z-20 hidden overflow-y-auto border-l border-[var(--nexo-border)] bg-[var(--nexo-sidebar)] px-3 py-5 transition-[width] duration-200 lg:block ${collapsed ? 'w-20' : 'w-64'}`}>
      <div className={`mb-9 flex items-center gap-3 px-2 ${collapsed ? 'justify-center' : ''}`}><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--nexo-primary)] font-black text-white shadow-[0_0_24px_rgb(139_92_246/30%)]">N</div>{!collapsed && <div><div className="font-black tracking-[0.2em] text-[var(--nexo-text)]">NEXO</div><div className="text-[10px] text-[var(--nexo-muted)]">GAME MASTER</div></div>}</div>
      {!collapsed && <div className="mb-3 px-3 text-[10px] font-semibold tracking-[0.15em] text-[var(--nexo-muted)]">مرکز عملیات</div>}
      <nav aria-label="ناوبری اصلی" className="space-y-1">{visibleLinks.map(([href, label, Icon]) => <SidebarLink key={href} href={`/control${href === '/' ? '' : href}`} label={label} collapsed={collapsed} icon={<Icon aria-hidden="true" size={16} strokeWidth={1.8} />} active={href === '/' || href === '/admin' ? current === href || (href === '/' && current === '/') : current.startsWith(href)} />)}</nav>
      {!collapsed && <div className="mt-4"><ThemeSwitcher /><div className="mt-3"><ContentNavigation current={pathname} /></div></div>}
      {!collapsed && <div className="mt-5 rounded-xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-3"><div className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-[var(--nexo-success)]" /><span className="text-xs text-[var(--nexo-muted)]">سیستم عملیاتی</span></div><div className="mt-2 text-xs text-[var(--nexo-muted)]">نسخه کنترل ۰.۱</div></div>}
    </aside>
    <div className={collapsed ? 'lg:mr-20' : 'lg:mr-64'}><header className="sticky top-0 z-10 flex min-h-16 items-center justify-between border-b border-[var(--nexo-border)] bg-[var(--nexo-bg)]/90 px-5 backdrop-blur-lg lg:px-8"><div className="flex items-center gap-3"><button aria-label={collapsed ? 'باز کردن نوار کناری' : 'جمع کردن نوار کناری'} onClick={() => setCollapsed((value) => !value)} className="rounded-lg p-2 text-[var(--nexo-muted)] transition hover:bg-[var(--nexo-hover)] hover:text-[var(--nexo-text)]"><span className="hidden lg:block">{collapsed ? <PanelRightOpen size={18} /> : <PanelRightClose size={18} />}</span></button><div><div className="text-xs text-[var(--nexo-muted)]">سازمان / مرکز عملیات</div><div className="mt-1 text-sm font-semibold">مرکز بازی نکسو</div></div></div><div className="flex items-center gap-3"><div className="hidden items-center gap-2 text-xs text-[var(--nexo-muted)] xl:flex"><span className="h-2 w-2 rounded-full bg-[var(--nexo-success)]" />همه سرویس‌ها سالم</div><div className="h-7 w-px bg-[var(--nexo-border)]" /><div className="text-left"><div className="text-sm font-semibold">{me.data.name}</div><div className="text-[11px] text-[var(--nexo-muted)]">اپراتور کنترل</div></div><button aria-label="خروج" onClick={logout} className="rounded-lg p-2 text-[var(--nexo-muted)] transition hover:bg-[var(--nexo-hover)] hover:text-[var(--nexo-text)]"><LogOut size={17} /></button></div></header><main className="nexo-grid min-h-[calc(100vh-4rem)] p-5 lg:p-8"><div className="mx-auto max-w-[1500px]"><div className="mb-5 lg:hidden"><ContentNavigation current={pathname} /></div>{children}</div></main></div>
  </div>;
}
