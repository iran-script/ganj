import { NextRequest, NextResponse } from 'next/server';
import { backendUrl, CONTROL_TOKEN_COOKIE, csrfDeniedResponse, isTrustedMutationOrigin } from '@/lib/backend';

export async function POST(request: NextRequest) {
  if (!isTrustedMutationOrigin(request)) return csrfDeniedResponse();
  const token = request.cookies.get(CONTROL_TOKEN_COOKIE)?.value;
  if (token) await fetch(backendUrl('/auth/logout'), { method: 'POST', headers: { Authorization: `Bearer ${token}` }, cache: 'no-store' }).catch(() => undefined);
  const response = new NextResponse(null, { status: 204 });
  response.cookies.set(CONTROL_TOKEN_COOKIE, '', { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'strict', path: '/', maxAge: 0 });
  return response;
}
