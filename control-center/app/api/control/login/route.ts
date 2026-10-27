import { NextRequest, NextResponse } from 'next/server';
import { backendUrl, CONTROL_TOKEN_COOKIE, csrfDeniedResponse, isTrustedMutationOrigin, requestCapabilities } from '@/lib/backend';

export async function POST(request: NextRequest) {
  if (!isTrustedMutationOrigin(request)) return csrfDeniedResponse();
  const input = await request.json().catch(() => null);
  if (!input || typeof input.identifier !== 'string' || typeof input.password !== 'string') {
    return NextResponse.json({ error: { code: 'INVALID_CREDENTIALS', message: 'اطلاعات ورود معتبر نیست.' } }, { status: 422 });
  }

  const login = await fetch(backendUrl('/auth/login'), {
    method: 'POST', cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: input.identifier.includes('@') ? input.identifier : undefined,
      mobile: input.identifier.includes('@') ? undefined : input.identifier,
      password: input.password, device_uuid: crypto.randomUUID(), device_name: 'NEXO Control Center', platform: 'web', app_version: '0.1.0',
    }),
  });
  const payload = await login.json().catch(() => null);
  if (!login.ok) return NextResponse.json(payload ?? { error: { code: 'LOGIN_FAILED' } }, { status: login.status });
  const token = payload?.data?.token;
  if (typeof token !== 'string') return NextResponse.json({ error: { code: 'LOGIN_FAILED' } }, { status: 502 });

  const capabilities = await requestCapabilities(token);
  const data = await capabilities.json().catch(() => null);
  const roles = data?.data?.global_roles ?? [];
  const memberships = data?.data?.organizations ?? [];
  const authorized = capabilities.ok && (roles.includes('SUPER_ADMIN') || memberships.some((membership: { role?: string }) => ['ORGANIZATION_ADMIN', 'GAMEMASTER'].includes(membership.role ?? '')));
  if (!authorized) {
    await fetch(backendUrl('/auth/logout'), { method: 'POST', headers: { Authorization: `Bearer ${token}` }, cache: 'no-store' }).catch(() => undefined);
    return NextResponse.json({ error: { code: 'AUTHORIZATION_DENIED', message: 'دسترسی اتاق کنترل مجاز نیست.' } }, { status: 403 });
  }

  const response = NextResponse.json({ data: { user: payload.data.user } });
  response.cookies.set(CONTROL_TOKEN_COOKIE, token, { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'strict', path: '/', maxAge: 60 * 60 * 8 });
  return response;
}
