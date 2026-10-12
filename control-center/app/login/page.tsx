'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { api, storeToken } from '@/lib/api-client';
import { ApiErrorResponse } from '@/lib/api-error';

const loginSchema = z.object({ identifier: z.string().min(1, 'موبایل یا ایمیل را وارد کنید.'), password: z.string().min(1, 'رمز عبور را وارد کنید.') });
type LoginValues = z.infer<typeof loginSchema>;

export default function LoginPage() {
  const router = useRouter(); const [serverError, setServerError] = useState('');
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginValues>({ resolver: zodResolver(loginSchema) });
  const onSubmit = async ({ identifier, password }: LoginValues) => { setServerError(''); try { const data = await api.post<{ token: string }>('/auth/login', { email: identifier.includes('@') ? identifier : undefined, mobile: identifier.includes('@') ? undefined : identifier, password, device_uuid: crypto.randomUUID(), device_name: 'NEXO Control Center', platform: 'web', app_version: '0.1.0' }); storeToken(data.token); router.replace('/control'); } catch (error) { setServerError(error instanceof ApiErrorResponse && error.status === 401 ? 'اطلاعات ورود صحیح نیست.' : 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.'); } };
  return <main className="nexo-grid flex min-h-screen items-center justify-center p-5"><div className="w-full max-w-[440px]"><div className="mb-8 text-center"><div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--nexo-amber)] text-xl font-black text-[#17120b] shadow-[0_0_40px_rgb(230_173_66/20%)]">N</div><div className="text-xl font-black tracking-[0.22em]">NEXO</div><p className="mt-2 text-sm text-[var(--nexo-muted)]">ورود به مرکز کنترل بازی</p></div><form onSubmit={handleSubmit(onSubmit)} className="rounded-2xl border border-[var(--nexo-border)] bg-[var(--nexo-surface)] p-6 shadow-[var(--nexo-shadow)]"><div className="mb-6"><h1 className="text-lg font-bold">خوش آمدید</h1><p className="mt-1 text-sm text-[var(--nexo-muted)]">با حساب اپراتور سازمان وارد شوید.</p></div><label className="mb-2 block text-sm font-medium" htmlFor="identifier">موبایل یا ایمیل</label><input id="identifier" autoComplete="username" {...register('identifier')} className="mb-1 h-11 w-full rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-3 text-sm outline-none transition focus:border-[var(--nexo-amber)]" placeholder="مثلاً ۰۹۱۲..." />{errors.identifier && <p className="mb-3 text-xs text-[var(--nexo-danger)]">{errors.identifier.message}</p>}<label className="mb-2 mt-4 block text-sm font-medium" htmlFor="password">رمز عبور</label><input id="password" type="password" autoComplete="current-password" {...register('password')} className="mb-1 h-11 w-full rounded-lg border border-[var(--nexo-border)] bg-[#0c1219] px-3 text-sm outline-none transition focus:border-[var(--nexo-amber)]" placeholder="رمز عبور" />{errors.password && <p className="mb-3 text-xs text-[var(--nexo-danger)]">{errors.password.message}</p>}{serverError && <div role="alert" className="mt-4 rounded-lg border border-[var(--nexo-danger)]/30 bg-[var(--nexo-danger)]/10 p-3 text-sm text-[var(--nexo-danger)]">{serverError}</div>}<button disabled={isSubmitting} className="mt-6 flex h-11 w-full items-center justify-center rounded-lg bg-[var(--nexo-amber)] font-bold text-[#17120b] transition hover:bg-[#f0bd58] disabled:cursor-wait disabled:opacity-60">{isSubmitting ? 'در حال ورود...' : 'ورود به مرکز کنترل'}</button></form><p className="mt-6 text-center text-xs text-[var(--nexo-muted)]">دسترسی‌ها توسط سرور و بر اساس سازمان مدیریت می‌شوند.</p></div></main>;
}
