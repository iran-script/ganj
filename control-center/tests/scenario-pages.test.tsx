import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { Suspense } from 'react';
import ScenarioDetailPage from '@/app/control/scenarios/[id]/page';
import VersionsPage from '@/app/control/scenarios/[id]/versions/page';
import { scenarioApi } from '@/lib/scenario-api';

const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('@/lib/scenario-api', () => ({ scenarioApi: { get: vi.fn(), versions: vi.fn(), ensureDraft: vi.fn(), update: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: {
  scenario: (id: string) => ({ queryKey: ['scenarios', id], queryFn: () => scenarioApi.get(id) }),
  scenarioVersions: (id: string) => ({ queryKey: ['scenarios', id, 'versions'], queryFn: () => scenarioApi.versions(id) }),
  capabilities: () => ({ queryKey: ['me', 'capabilities'], queryFn: async () => ({ global_roles: ['SUPER_ADMIN'], organizations: [], capabilities: [] }) }),
} }));

beforeEach(() => {
  vi.resetAllMocks();
  vi.stubGlobal('crypto', { randomUUID: vi.fn(() => 'draft-key') });
  vi.mocked(scenarioApi.get).mockResolvedValue({ id: 'scenario', title: 'تقسیم مأموریت', status: 'PUBLISHED' });
  vi.mocked(scenarioApi.versions).mockResolvedValue([{ id: 'published', scenario_id: 'scenario', version_no: 1, revision_token: 'r1', status: 'PUBLISHED' }]);
  vi.mocked(scenarioApi.ensureDraft).mockResolvedValue({ id: 'draft', scenario_id: 'scenario', version_no: 2, revision_token: 'revision', status: 'DRAFT' });
});
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

async function mount(Page: typeof ScenarioDetailPage) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const params = Promise.resolve({ id: 'scenario' });
  await act(async () => { render(<QueryClientProvider client={client}><Suspense fallback="Loading"><Page params={params} /></Suspense></QueryClientProvider>); });
}

it('creates a new draft from the latest published version with an audited reason', async () => {
  await mount(ScenarioDetailPage);
  expect(await screen.findByRole('button', { name: 'ساخت نسخه جدید' })).toBeInTheDocument();
  await userEvent.type(screen.getByPlaceholderText('مثلاً آماده‌سازی نسخه دوم سناریو'), 'آماده‌سازی نسخه دوم');
  await userEvent.click(screen.getByRole('button', { name: 'ساخت نسخه جدید' }));
  await waitFor(() => expect(scenarioApi.ensureDraft).toHaveBeenCalledWith('scenario', { reason: 'آماده‌سازی نسخه دوم' }, 'draft-key'));
  expect(push).toHaveBeenCalledWith('/control/scenarios/scenario/builder?version=draft');
});

it('continues an existing draft instead of creating a duplicate', async () => {
  vi.mocked(scenarioApi.versions).mockResolvedValue([
    { id: 'draft', scenario_id: 'scenario', version_no: 2, revision_token: 'r2', status: 'DRAFT' },
    { id: 'published', scenario_id: 'scenario', version_no: 1, revision_token: 'r1', status: 'PUBLISHED' },
  ]);
  await mount(ScenarioDetailPage);
  expect(await screen.findByRole('button', { name: 'ادامه ویرایش Draft 2' })).toBeInTheDocument();
  await userEvent.type(screen.getByPlaceholderText('مثلاً آماده‌سازی نسخه دوم سناریو'), 'ادامه ویرایش');
  await userEvent.click(screen.getByRole('button', { name: 'ادامه ویرایش Draft 2' }));
  await waitFor(() => expect(scenarioApi.ensureDraft).toHaveBeenCalledTimes(1));
  expect(push).toHaveBeenCalledWith('/control/scenarios/scenario/builder?version=draft');
});

it('shows draft and published history with metadata and distinct actions', async () => {
  vi.mocked(scenarioApi.versions).mockResolvedValue([
    { id: 'draft', scenario_id: 'scenario', version_no: 2, revision_token: 'r2', status: 'DRAFT' },
    { id: 'published', scenario_id: 'scenario', version_no: 1, revision_token: 'r1', status: 'PUBLISHED', published_at: '2026-10-13T10:00:00Z', published_by: 'operator', usage_count: 4 },
  ]);
  await mount(VersionsPage);
  expect(await screen.findByText('Draft · در حال ویرایش')).toBeInTheDocument();
  expect(screen.getByText('Published · فقط خواندنی')).toBeInTheDocument();
  expect(screen.getByText('operator')).toBeInTheDocument();
  expect(screen.getByText('4')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'مشاهده نسخه منتشرشده' })).toHaveAttribute('href', '/control/scenarios/scenario/builder?version=published');
  expect(screen.queryByRole('button', { name: 'انتشار نسخه جدید' })).not.toBeInTheDocument();
});
