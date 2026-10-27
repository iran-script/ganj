import { NextRequest, NextResponse } from 'next/server';
import { requestCapabilities, sessionToken } from '@/lib/backend';

export async function GET(request: NextRequest) {
  const token = sessionToken(request);
  if (!token) return new NextResponse(null, { status: 401 });
  const response = await requestCapabilities(token).catch(() => null);
  if (!response?.ok) return new NextResponse(null, { status: 401 });
  const payload = await response.json().catch(() => null);
  const roles = payload?.data?.global_roles ?? [];
  const memberships = payload?.data?.organizations ?? [];
  const authorized = roles.includes('SUPER_ADMIN') || memberships.some((membership: { role?: string }) => ['ORGANIZATION_ADMIN', 'GAMEMASTER'].includes(membership.role ?? ''));
  return new NextResponse(null, { status: authorized ? 204 : 403, headers: { 'Cache-Control': 'no-store' } });
}
