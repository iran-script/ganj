import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import NewScenarioPage from '@/app/control/scenarios/new/page';
import { ApiErrorResponse } from '@/lib/api-error';
import { scenarioApi } from '@/lib/scenario-api';

const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('@/lib/scenario-api', () => ({ scenarioApi: { create: vi.fn(), createVersion: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: {
  organizations: () => ({ queryKey: ['organizations'], queryFn: async () => [{ id: 'org', name: 'سازمان تست' }] }),
  capabilities: () => ({ queryKey: ['me', 'capabilities'], queryFn: async () => ({ global_roles: ['SUPER_ADMIN'], organizations: [], capabilities: [] }) }),
} }));

beforeEach(() => {
  vi.resetAllMocks();
  vi.stubGlobal('crypto', { randomUUID: vi.fn(() => 'stable-key') });
  vi.mocked(scenarioApi.create).mockResolvedValue({ id: 'scenario', title: 'سناریو', status: 'DRAFT' });
  vi.mocked(scenarioApi.createVersion).mockResolvedValue({ id: 'version', scenario_id: 'scenario', version_no: 1, status: 'DRAFT', revision_token: 'revision' });
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><NewScenarioPage /></QueryClientProvider>);
}

it('creates a super-admin scenario with an audit reason and opens its draft builder', async () => {
  const user = userEvent.setup();
  mount();
  await user.selectOptions(await screen.findByRole('combobox'), 'org');
  await user.type(screen.getByRole('textbox', { name: /عنوان/ }), 'سناریوی جدید');
  await user.type(screen.getByRole('textbox', { name: /دلیل/ }), 'ساخت سناریوی آزمایشی');
  await user.click(screen.getByRole('button', { name: /ایجاد و باز کردن Builder/ }));
  await waitFor(() => expect(scenarioApi.create).toHaveBeenCalledWith(expect.objectContaining({ organization_id: 'org', title: 'سناریوی جدید', reason: 'ساخت سناریوی آزمایشی' }), 'stable-key'));
  expect(scenarioApi.createVersion).toHaveBeenCalledWith('scenario', { reason: 'ساخت سناریوی آزمایشی' }, 'stable-key');
  expect(push).toHaveBeenCalledWith('/control/scenarios/scenario/builder?version=version');
});

it('renders Laravel validation fields in Persian and keeps the request key for retry', async () => {
  const user = userEvent.setup();
  vi.mocked(scenarioApi.create).mockRejectedValue(new ApiErrorResponse(422, 'VALIDATION_FAILED', 'The request contains invalid fields.', { fields: { reason: ['The reason field is required.'] } }));
  mount();
  await user.selectOptions(await screen.findByRole('combobox'), 'org');
  await user.type(screen.getByRole('textbox', { name: /عنوان/ }), 'سناریو');
  fireEvent.submit(screen.getByRole('button', { name: /ایجاد و باز کردن Builder/ }).closest('form')!);
  expect(await screen.findByText('دلیل عملیات برای مدیر کل الزامی است.')).toBeInTheDocument();
  expect(scenarioApi.create).toHaveBeenCalledWith(expect.anything(), 'stable-key');
});
