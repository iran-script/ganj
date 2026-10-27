import type { NextRequest } from 'next/server';

export const CONTROL_TOKEN_COOKIE = 'nexo_control_session';

export function backendUrl(path: string): string {
  const base = (process.env.CONTROL_CENTER_BACKEND_API_URL ?? '').replace(/\/$/, '');
  if (!base) throw new Error('CONTROL_CENTER_BACKEND_API_URL is required');
  return `${base}${path}`;
}

export function isTrustedMutationOrigin(request: NextRequest): boolean {
  const rawOrigin = request.headers.get('origin');
  const rawHost = request.headers.get('host');
  if (!rawOrigin || !rawHost) return false;

  let origin: URL;
  try {
    origin = new URL(rawOrigin);
  } catch {
    return false;
  }
  if (origin.origin !== rawOrigin || origin.host.toLowerCase() !== rawHost.toLowerCase()) return false;
  if (process.env.NODE_ENV === 'production' && origin.protocol !== 'https:') return false;

  const allowedOrigins = (process.env.CONTROL_CENTER_ALLOWED_ORIGINS ?? '')
    .split(',')
    .map((value) => {
      try {
        return new URL(value.trim()).origin;
      } catch {
        return '';
      }
    })
    .filter(Boolean);
  return allowedOrigins.includes(origin.origin);
}

export function csrfDeniedResponse(): Response {
  return Response.json({ error: { code: 'CSRF_VALIDATION_FAILED' } }, { status: 403 });
}

export async function requestCapabilities(token: string): Promise<Response> {
  return fetch(backendUrl('/me/capabilities'), {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    cache: 'no-store',
  });
}

export function sessionToken(request: NextRequest): string | undefined {
  return request.cookies.get(CONTROL_TOKEN_COOKIE)?.value;
}
