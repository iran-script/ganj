import { afterEach, expect, it, vi } from 'vitest';
import { scenarioApi } from '@/lib/scenario-api';

afterEach(() => vi.unstubAllGlobals());
it('loads every version history page in server order', async () => {
  const fetch = vi.fn()
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 'new' }], meta: { pagination: { has_more: true, next_cursor: 'next==' } } })))
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 'old' }], meta: { pagination: { has_more: false, next_cursor: null } } })));
  vi.stubGlobal('fetch', fetch);
  expect(await scenarioApi.versions('scenario')).toEqual([{ id: 'new' }, { id: 'old' }]);
  expect(fetch.mock.calls[1][0]).toContain('cursor=next%3D%3D');
});
