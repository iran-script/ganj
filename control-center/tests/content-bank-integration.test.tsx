import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ContentBank } from '@/components/content-bank';
import { contentApi } from '@/lib/content-api';
import { storeToken } from '@/lib/api-client';
import { queries } from '@/lib/queries';
import { snapshotLocation } from '@/lib/location-state';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));
const qr = { id: 'qr', organization_id: 'authorized-org', name: 'Square QR', status: 'ACTIVE', label: 'SQ', location_description: '', notes: '', usage_count: 0 };
const location = { id: 'point', organization_id: 'authorized-org', name: 'Square', status: 'ACTIVE', latitude: 35.6, longitude: 51.4, default_radius_m: 20, accuracy_m: null, dwell_seconds: null, category: '', description: '' };
const fetchMock = vi.fn();
beforeEach(() => { vi.stubGlobal('fetch', fetchMock); vi.stubEnv('NEXT_PUBLIC_API_BASE_URL', 'http://localhost:18000/api/v1'); vi.spyOn(window.localStorage, 'getItem').mockReturnValue('test-only-token'); storeToken('test-only-token'); });
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); vi.unstubAllEnvs(); fetchMock.mockReset(); });

it.each([['qr-bank', qr], ['locations', location]] as const)('renders canonical %s data using authenticated organization scope', async (bank, item) => {
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [item] })));
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ContentBank bank={bank} /></QueryClientProvider>);
  expect(await screen.findByRole('heading', { name: item.name })).toBeInTheDocument();
  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe(`http://localhost:18000/api/v1/content/${bank}`);
  expect(init.headers.get('Authorization')).toBe('Bearer test-only-token');
  expect(init.headers.get('Accept')).toBe('application/json');
  // These endpoints derive all permitted organizations from the authenticated actor.
  expect(url).not.toContain('organization_id');
});

it('shares canonical bank data with pickers while keeping the two resources separate', async () => {
  const cache = new QueryClient();
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [qr] })));
  await cache.fetchQuery(queries.qrBank());
  expect(cache.getQueryData(queries.qrTriggers().queryKey)).toEqual([qr]);
  expect(cache.getQueryData(queries.locations().queryKey)).toBeUndefined();
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [location] })));
  const [point] = await cache.fetchQuery(queries.locations());
  expect(snapshotLocation(point)).toMatchObject({ latitude: 35.6, longitude: 51.4, radius_m: 20 });
  cache.clear();
});

it.each([401, 403, 404, 422, 500])('preserves canonical HTTP %s errors without showing backend details', async (status) => {
  fetchMock.mockImplementation(async () => new Response(JSON.stringify({ error: { code: 'TEST_ERROR', message: 'private backend detail', details: {} } }), { status }));
  await expect(contentApi.qr()).rejects.toMatchObject({ status, code: 'TEST_ERROR' });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ContentBank bank="qr-bank" /></QueryClientProvider>);
  expect(await screen.findByText('بارگذاری بانک انجام نشد.')).toBeInTheDocument();
  expect(document.body.textContent).not.toContain('private backend detail');
  expect(screen.queryByText('موردی یافت نشد.')).not.toBeInTheDocument();
});

it('distinguishes malformed bank data from an empty database and network failures', async () => {
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: { items: [] } })));
  await expect(contentApi.locations()).rejects.toMatchObject({ code: 'SCHEMA_PARSE_ERROR', status: 200 });
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [] })));
  await expect(contentApi.locations()).resolves.toEqual([]);
  fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
  await expect(contentApi.locations()).rejects.toBeInstanceOf(TypeError);
});

it('logs only safe diagnostic metadata in development', async () => {
  vi.stubEnv('NODE_ENV', 'development');
  const warning = vi.spyOn(console, 'warn').mockImplementation(() => {});
  fetchMock.mockResolvedValue(new Response(JSON.stringify({ error: { code: 'NOT_FOUND', message: 'sensitive detail' } }), { status: 404 }));
  await expect(contentApi.qr()).rejects.toMatchObject({ status: 404 });
  expect(warning).toHaveBeenCalledWith('Content bank request failed', { path: '/content/qr-bank', method: 'GET', status: 404, category: 'HTTP_404' });
  expect(JSON.stringify(warning.mock.calls)).not.toMatch(/sensitive detail|test-only-token/);
});
