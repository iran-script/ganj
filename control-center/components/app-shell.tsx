'use client';

import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { Activity, BarChart3, Boxes, FileText, Gamepad2, LayoutDashboard, LogOut, PanelRightClose, PanelRightOpen, Settings2, ShieldCheck, Sparkles, Users, type LucideIcon } from 'lucide-react';
import { clearStoredToken } from '@/lib/api-client';
import { queries } from '@/lib/queries';
import { Button, ErrorState, LoadingState, SidebarLink } from '@/components/ui';

const links: Array<[string, string, LucideIcon]> = [
  ['/submissions', 'بررسی مدارک', FileText],
  ['/', 'داشبورد', LayoutDashboard], ['/games', 'بازی‌ها', Gamepad2], ['/sessions', 'جلسه‌ها', FileText], ['/live/demo', 'کنترل زنده', Activity], ['/teams', 'تیم‌ها', Users], ['/players', 'بازیکنان', ShieldCheck], ['/scenarios', 'سناریوها', Sparkles], ['/content', 'محتوا', Boxes], ['/reports', 'گزارش‌ها', BarChart3], ['/admin', 'مدیریت', Settings2],
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [collapsed, setCollapsed] = useState(false);
  const me = useQuery({ ...queries.me(), retry: false });
  useEffect(() => { if (me.isError) router.replace('/login'); }, [me.isError, router]);
  if (me.isPending) return <LoadingState label="در حال آماده‌سازی مرکز کنترل..." />;
  if (me.isError) return <div className="flex min-h-screen items-center justify-center p-6"><div className="w-full max-w-md"><ErrorState message="برای ورود به مرکز کنترل، ابتدا احراز هویت کنید." /><div className="mt-4 text-center"><Button href="/login">ورود به مرکز کنترل</Button></div></div></div>;
  const current = pathname.replace('/control', '') || '/';
  const logout = async () => { try { await fetch(`${(process.env.NEXT_PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '')}/auth/logout`, { method: 'POST', headers: { Authorization: `Bearer ${localStorage.getItem('nexo.control.token') ?? ''}` } }); } finally { clearStoredToken(); router.replace('/login'); } };
  return <div className="min-h-screen bg-[var(--nexo-bg)]">
    <aside className={`fixed inset-y-0 right-0 z-20 hidden border-l border-[var(--nexo-border)] bg-[#0e141b] px-3 py-5 transition-[width] duration-200 lg:block ${collapsed ? 'w-20' : 'w-64'}`}>
      <div className={`mb-9 flex items-center gap-3 px-2 ${collapsed ? 'justify-center' : ''}`}><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--nexo-amber)] font-black text-[#17120b]">N</div>{!collapsed && <div><div className="font-black tracking-[0.2em] text-white">NEXO</div><div className="text-[10px] text-[var(--nexo-muted)]">GAME MASTER</div></div>}</div>
      {!collapsed && <div className="mb-3 px-3 text-[10px] font-semibold tracking-[0.15em] text-[var(--nexo-muted)]">مرکز عملیات</div>}
      <nav aria-label="ناوبری اصلی" className="space-y-1">{links.map(([href, label, Icon]) => <SidebarLink key={href} href={`/control${href === '/' ? '' : href}`} label={label} collapsed={collapsed} icon={<Icon aria-hidden="true" size={16} strokeWidth={1.8} />} active={href === '/' ? current === '/' : current.startsWith(href)} />)}</nav>
      {!collapsed && <div className="absolute bottom-5 right-3 left-3 rounded-lg border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-3"><div className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-[var(--nexo-success)]" /><span className="text-xs text-[var(--nexo-muted)]">سیستم عملیاتی</span></div><div className="mt-2 text-xs text-[var(--nexo-muted)]">نسخه کنترل ۰.۱</div></div>}
    </aside>
    <div className={collapsed ? 'lg:mr-20' : 'lg:mr-64'}><header className="sticky top-0 z-10 flex min-h-16 items-center justify-between border-b border-[var(--nexo-border)] bg-[var(--nexo-bg)]/90 px-5 backdrop-blur-lg lg:px-8"><div className="flex items-center gap-3"><button aria-label={collapsed ? 'باز کردن نوار کناری' : 'جمع کردن نوار کناری'} onClick={() => setCollapsed((value) => !value)} className="rounded-lg p-2 text-[var(--nexo-muted)] transition hover:bg-white/5 hover:text-white"><span className="hidden lg:block">{collapsed ? <PanelRightOpen size={18} /> : <PanelRightClose size={18} />}</span></button><div><div className="text-xs text-[var(--nexo-muted)]">سازمان / مرکز عملیات</div><div className="mt-1 text-sm font-semibold">مرکز بازی نکسو</div></div></div><div className="flex items-center gap-4"><div className="hidden items-center gap-2 text-xs text-[var(--nexo-muted)] sm:flex"><span className="h-2 w-2 animate-pulse rounded-full bg-[var(--nexo-success)]" />همه سرویس‌ها سالم</div><div className="h-7 w-px bg-[var(--nexo-border)]" /><div className="text-left"><div className="text-sm font-semibold">{me.data.name}</div><div className="text-[11px] text-[var(--nexo-muted)]">اپراتور کنترل</div></div><button aria-label="خروج" onClick={logout} className="rounded-lg p-2 text-[var(--nexo-muted)] transition hover:bg-white/5 hover:text-white"><LogOut size={17} /></button></div></header><main className="nexo-grid min-h-[calc(100vh-4rem)] p-5 lg:p-8"><div className="mx-auto max-w-[1500px]">{children}</div></main></div>
  </div>;
}
