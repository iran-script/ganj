import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import ScenariosPage from '@/app/control/scenarios/page';
import { scenarioApi } from '@/lib/scenario-api';

vi.mock('@/lib/scenario-api', () => ({ scenarioApi: { list: vi.fn(), get: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: {
  scenarios: () => ({ queryKey: ['scenarios'], queryFn: () => scenarioApi.list() }),
  scenario: (id: string) => ({ queryKey: ['scenarios', id], queryFn: () => scenarioApi.get(id) }),
} }));

afterEach(cleanup);

it('routes an existing scenario through draft preparation before opening the builder', async () => {
  vi.mocked(scenarioApi.list).mockResolvedValue([{ id: 'legacy-scenario', title: 'سناریوی اولیه', status: 'PUBLISHED' }]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><ScenariosPage /></QueryClientProvider>);

  const editLink = await screen.findByRole('link', { name: 'ویرایش / نسخه جدید' });
  expect(editLink).toHaveAttribute('href', '/control/scenarios/legacy-scenario');
  expect(editLink).not.toHaveAttribute('href', '/control/scenarios/legacy-scenario/builder');
});
