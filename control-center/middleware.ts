import { NextRequest, NextResponse } from 'next/server';
import { CONTROL_TOKEN_COOKIE, requestCapabilities } from '@/lib/backend';

export async function middleware(request: NextRequest) {
  const token = request.cookies.get(CONTROL_TOKEN_COOKIE)?.value;
  if (token) {
    const check = await requestCapabilities(token).catch(() => null);
    if (check?.ok) {
      const payload = await check.json().catch(() => null);
      const roles = payload?.data?.global_roles ?? [];
      const memberships = payload?.data?.organizations ?? [];
      if (roles.includes('SUPER_ADMIN') || memberships.some((membership: { role?: string }) => ['ORGANIZATION_ADMIN', 'GAMEMASTER'].includes(membership.role ?? ''))) {
        return NextResponse.next();
      }
    }
  }
  const login = new URL('/login', request.url);
  login.searchParams.set('next', '/control');
  return NextResponse.redirect(login);
}

export const config = { matcher: ['/control/:path*'] };
