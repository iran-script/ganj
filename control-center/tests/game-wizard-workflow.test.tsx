import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { GameWizard } from '@/components/game-wizard';
import { gameApi } from '@/lib/game-api';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock('@/lib/game-api', () => ({ gameApi: { create: vi.fn().mockResolvedValue({ id: 'game' }) } }));
vi.mock('@/lib/queries', async (importOriginal) => ({ queries: { ...(await importOriginal<typeof import('@/lib/queries')>()).queries, organizations: () => ({ queryKey: ['organizations'], queryFn: async () => [{ id: 'a355c253-9c45-49c4-b8c1-383c265b80f1' }] }), scenarios: () => ({ queryKey: ['scenarios'], queryFn: async () => [] }) } }));
afterEach(cleanup);

it('submits complete free pricing and an operational reason after review', async () => {
  const user = userEvent.setup();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><GameWizard /></QueryClientProvider>);
  await user.type(await screen.findByLabelText('نام بازی'), 'Verification');
  await user.type(screen.getByLabelText('شناسه فنی'), 'verification');
  for (let step = 0; step < 4; step++) await user.click(screen.getByRole('button', { name: 'مرحله بعد' }));
  await user.click(screen.getByRole('button', { name: 'ثبت و ایجاد' }));
  await waitFor(() => expect(gameApi.create).toHaveBeenCalledWith(expect.objectContaining({
    name: 'Verification', pricing: { mode: 'FREE', amount: 0, currency: 'IRR' }, reason: expect.any(String),
  })));
});
