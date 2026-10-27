import { NextRequest, NextResponse } from 'next/server';
import { backendUrl, csrfDeniedResponse, isTrustedMutationOrigin, sessionToken } from '@/lib/backend';

async function proxy(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  if (!['GET', 'HEAD'].includes(request.method) && !isTrustedMutationOrigin(request)) return csrfDeniedResponse();
  const token = sessionToken(request);
  if (!token) return NextResponse.json({ error: { code: 'AUTHENTICATION_REQUIRED' } }, { status: 401 });
  const { path } = await context.params;
  const headers = new Headers(request.headers);
  headers.delete('cookie'); headers.delete('host'); headers.delete('authorization');
  headers.set('Authorization', `Bearer ${token}`);
  const body = ['GET', 'HEAD'].includes(request.method) ? undefined : await request.arrayBuffer();
  const upstream = await fetch(`${backendUrl(`/${path.join('/')}`)}${request.nextUrl.search}`, { method: request.method, headers, body, cache: 'no-store', redirect: 'manual' });
  const responseHeaders = new Headers(upstream.headers);
  responseHeaders.delete('set-cookie');
  responseHeaders.set('Cache-Control', 'no-store');
  return new NextResponse(upstream.body, { status: upstream.status, headers: responseHeaders });
}

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
