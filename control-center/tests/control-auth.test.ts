import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NextRequest } from 'next/server';
import { POST as login } from '@/app/api/control/login/route';
import { POST as logout } from '@/app/api/control/logout/route';
import { POST as backendPost } from '@/app/api/backend/[...path]/route';
import { GET as session } from '@/app/api/control/session/route';
import { middleware } from '@/middleware';
import InviteHelpPage from '@/app/invite/[code]/page';
import { renderToStaticMarkup } from 'react-dom/server';

const fetchMock = vi.fn();

describe('Control Center server authentication', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    vi.stubEnv('CONTROL_CENTER_BACKEND_API_URL', 'http://backend.test/api/v1');
    vi.stubEnv('CONTROL_CENTER_ALLOWED_ORIGINS', 'http://control.test');
    vi.stubEnv('NODE_ENV', 'test');
  });

  it('does not create a browser session when backend rejects a player login', async () => {
    fetchMock.mockResolvedValueOnce(Response.json({ error: { code: 'AUTHORIZATION_DENIED' } }, { status: 403 }));
    const response = await login(new NextRequest('http://control.test/api/control/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'http://control.test', Host: 'control.test' }, body: JSON.stringify({ identifier: 'player@nexo.test', password: 'password' }),
    }));
    expect(response.status).toBe(403);
    expect(response.headers.get('set-cookie')).toBeNull();
    expect(fetchMock.mock.calls[0][0]).toBe('http://backend.test/api/v1/auth/login');
  });

  it('sets an HttpOnly browser cookie only after current management permissions validate', async () => {
    fetchMock
      .mockResolvedValueOnce(Response.json({ data: { token: 'operator-token', user: { id: 'operator' } } }))
      .mockResolvedValueOnce(Response.json({ data: { global_roles: ['SUPER_ADMIN'], organizations: [] } }));
    const response = await login(new NextRequest('http://control.test/api/control/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'http://control.test', Host: 'control.test' }, body: JSON.stringify({ identifier: 'admin@nexo.test', password: 'password' }),
    }));
    expect(response.status).toBe(200);
    const cookie = response.headers.get('set-cookie') ?? '';
    expect(cookie).toContain('HttpOnly');
    expect(cookie).toContain('SameSite=strict');
    expect(cookie).not.toContain('Secure');
    expect(await response.json()).not.toHaveProperty('data.token');
    expect(fetchMock.mock.calls[1][0]).toBe('http://backend.test/api/v1/me/capabilities');
  });

  it('invalidates the browser session when its live permission check fails', async () => {
    fetchMock.mockResolvedValueOnce(Response.json({ error: { code: 'AUTHORIZATION_DENIED' } }, { status: 403 }));
    const request = new NextRequest('http://control.test/api/control/session', { headers: { cookie: 'nexo_control_session=revoked-token' } });
    const response = await session(request);
    expect(response.status).toBe(401);
    expect(fetchMock.mock.calls[0][0]).toBe('http://backend.test/api/v1/me/capabilities');
  });

  it('redirects direct page requests without a valid server session', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 401 }));
    const response = await middleware(new NextRequest('http://control.test/control/admin'));
    expect(response.headers.get('location')).toBe('http://control.test/login?next=%2Fcontrol');
  });

  it('offers only an app deep link on the web invite route', async () => {
    const html = renderToStaticMarkup(await InviteHelpPage({ params: Promise.resolve({ code: 'invite-code-123456' }) }));
    expect(html).toContain('href="nexo://invite/invite-code-123456"');
    expect(html).not.toContain('<form');
    expect(html).not.toContain('/api/');
  });

  it('rejects a cross-origin login before contacting the backend', async () => {
    const response = await login(new NextRequest('http://control.test/api/control/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'http://evil.test' }, body: JSON.stringify({ identifier: 'admin@nexo.test', password: 'password' }),
    }));
    expect(response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('rejects missing origins and host mismatches on state-changing requests', async () => {
    const missingOrigin = await logout(new NextRequest('http://control.test/api/control/logout', { method: 'POST' }));
    const hostMismatch = await logout(new NextRequest('http://control.test/api/control/logout', {
      method: 'POST', headers: { Origin: 'http://control.test', Host: 'attacker.test' },
    }));
    expect(missingOrigin.status).toBe(403);
    expect(hostMismatch.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('rejects unsafe BFF mutations before forwarding a player or admin cookie', async () => {
    const response = await backendPost(
      new NextRequest('http://control.test/api/backend/teams', { method: 'POST', headers: { cookie: 'nexo_control_session=secret' }, body: '{}' }),
      { params: Promise.resolve({ path: ['teams'] }) },
    );
    expect(response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards an allowed BFF mutation with the server-held token only', async () => {
    fetchMock.mockResolvedValueOnce(Response.json({ data: { id: 'team-1' } }));
    const response = await backendPost(
      new NextRequest('http://control.test/api/backend/teams', {
        method: 'POST',
        headers: {
          Origin: 'http://control.test',
          Host: 'control.test',
          Cookie: 'nexo_control_session=operator-token',
          'Content-Type': 'application/json',
        },
        body: '{"name":"Team"}',
      }),
      { params: Promise.resolve({ path: ['teams'] }) },
    );
    expect(response.status).toBe(200);
    const forwardedHeaders = new Headers(fetchMock.mock.calls[0][1].headers);
    expect(forwardedHeaders.get('authorization')).toBe('Bearer operator-token');
    expect(forwardedHeaders.has('cookie')).toBe(false);
  });

  it('marks the production admin cookie Secure', async () => {
    vi.stubEnv('NODE_ENV', 'production');
    vi.stubEnv('CONTROL_CENTER_ALLOWED_ORIGINS', 'https://control.test');
    fetchMock
      .mockResolvedValueOnce(Response.json({ data: { token: 'operator-token', user: { id: 'operator' } } }))
      .mockResolvedValueOnce(Response.json({ data: { global_roles: ['SUPER_ADMIN'], organizations: [] } }));
    const response = await login(new NextRequest('https://control.test/api/control/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://control.test', Host: 'control.test' }, body: JSON.stringify({ identifier: 'admin@nexo.test', password: 'password' }),
    }));
    expect(response.headers.get('set-cookie')).toContain('Secure');
    expect(response.headers.get('set-cookie')).toContain('HttpOnly');
    expect(response.headers.get('set-cookie')).toContain('SameSite=strict');
  });
});
