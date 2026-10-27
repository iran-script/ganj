'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ApiErrorResponse } from '@/lib/api-error';
import { ThemeSwitcher } from '@/components/ui';

const loginSchema = z.object({ identifier: z.string().min(1, 'موبایل یا ایمیل را وارد کنید.'), password: z.string().min(1, 'رمز عبور را وارد کنید.') });
type LoginValues = z.infer<typeof loginSchema>;

function loginErrorMessage(error: unknown): string {
  if (!(error instanceof ApiErrorResponse)) {
    return 'اتصال با سرور برقرار نشد. اتصال شبکه و آدرس صفحه را بررسی کنید.';
  }

  if (error.status === 401) return 'اطلاعات ورود صحیح نیست.';
  if (error.status === 403) return 'این حساب اجازه ورود به مرکز کنترل را ندارد.';
  if (error.status === 422) return 'اطلاعات واردشده معتبر نیست.';
  if (error.status === 429) return 'تعداد تلاش‌ها زیاد است. چند لحظه بعد دوباره تلاش کنید.';
  if (error.status >= 500) return 'سرور موقتاً در دسترس نیست. دوباره تلاش کنید.';

  return error.message || 'درخواست ورود انجام نشد.';
}

export default function LoginPage() {
  const router = useRouter(); const [serverError, setServerError] = useState('');
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginValues>({ resolver: zodResolver(loginSchema) });
  const onSubmit = async ({ identifier, password }: LoginValues) => { setServerError(''); try { const response = await fetch('/api/control/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ identifier, password }) }); if (!response.ok) throw new ApiErrorResponse(response.status, 'LOGIN_FAILED', 'ورود ناموفق بود.'); router.replace('/control'); } catch (error) { setServerError(loginErrorMessage(error)); } };
  return <main className="nexo-grid flex min-h-screen items-center justify-center p-5"><div className="w-full max-w-[460px]"><div className="mb-6 flex justify-end"><ThemeSwitcher /></div><div className="mb-8 text-center"><div className="nexo-gaming-glow mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--nexo-primary)] text-2xl font-black text-white">N</div><div className="text-2xl font-black tracking-[0.24em]">NEXO</div><p className="mt-2 text-sm text-[var(--nexo-muted)]">مرکز کنترل بازی و عملیات</p></div><form onSubmit={handleSubmit(onSubmit)} className="nexo-surface rounded-2xl p-7"><div className="mb-7"><h1 className="text-xl font-bold">خوش آمدید</h1><p className="mt-2 text-sm leading-6 text-[var(--nexo-muted)]">با حساب اپراتور سازمان وارد شوید.</p></div><label className="mb-2 block text-sm font-medium" htmlFor="identifier">موبایل یا ایمیل</label><input id="identifier" autoComplete="username" {...register('identifier')} className="nexo-input" placeholder="مثلاً ۰۹۱۲..." />{errors.identifier && <p className="mt-2 text-xs text-[var(--nexo-danger)]">{errors.identifier.message}</p>}<label className="mb-2 mt-5 block text-sm font-medium" htmlFor="password">رمز عبور</label><input id="password" type="password" autoComplete="current-password" {...register('password')} className="nexo-input" placeholder="رمز عبور" />{errors.password && <p className="mt-2 text-xs text-[var(--nexo-danger)]">{errors.password.message}</p>}{serverError && <div role="alert" className="mt-4 rounded-xl border border-[var(--nexo-danger)]/30 bg-[var(--nexo-danger)]/10 p-3 text-sm text-[var(--nexo-danger)]">{serverError}</div>}<button disabled={isSubmitting} className="mt-7 flex h-12 w-full items-center justify-center rounded-xl bg-[var(--nexo-primary)] font-bold text-white shadow-[0_10px_30px_rgb(139_92_246/20%)] transition hover:bg-[var(--nexo-primary-hover)] disabled:cursor-wait disabled:opacity-60">{isSubmitting ? 'در حال ورود...' : 'ورود به مرکز کنترل'}</button></form><p className="mt-6 text-center text-xs text-[var(--nexo-muted)]">دسترسی‌ها توسط سرور و بر اساس سازمان مدیریت می‌شوند.</p></div></main>;
}
