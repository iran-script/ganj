import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TeamManagementDetailPage, TeamManagementPage } from '@/components/team-management';
import { api } from '@/lib/api-client';
import { teamManagementApi } from '@/lib/team-management-api';

vi.mock('next/navigation', () => ({ useParams: () => ({ id: 'team-1' }) }));
vi.mock('@/lib/api-client', () => ({ api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
vi.mock('@/lib/team-management-api', () => ({ teamManagementApi: { list: vi.fn(), show: vi.fn(), invites: vi.fn(), revokeInvite: vi.fn() } }));
vi.mock('@/lib/queries', () => ({
  queries: {
    me: () => ({ queryKey: ['me'], queryFn: async () => ({ id: 'captain-1', name: 'کاپیتان', status: 'ACTIVE' }) }),
    capabilities: () => ({ queryKey: ['me', 'capabilities'], queryFn: async () => ({ global_roles: [], organizations: [], capabilities: [] }) }),
  },
}));

const team = {
  id: 'team-1', name: 'تیم سپهر', status: 'ACTIVE', captain_user_id: 'captain-1',
  captain: { id: 'captain-1', name: 'کاپیتان' }, member_count: 2,
  members: [
    { user_id: 'captain-1', name: 'کاپیتان', status: 'ACTIVE', joined_at: '2026-01-01T00:00:00Z' },
    { user_id: 'player-2', name: 'بازیکن دوم', status: 'ACTIVE', joined_at: '2026-01-02T00:00:00Z' },
  ],
};

function mount(component: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{component}</QueryClientProvider>);
}

describe('team management screens', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(teamManagementApi.list).mockResolvedValue({ data: [team], meta: { pagination: { has_more: true, next_cursor: 'next' } } });
    vi.mocked(teamManagementApi.show).mockResolvedValue(team);
    vi.mocked(teamManagementApi.invites).mockResolvedValue({ data: [], meta: { pagination: { has_more: false } } });
    vi.mocked(teamManagementApi.revokeInvite).mockResolvedValue(undefined);
    vi.mocked(api.patch).mockResolvedValue(team);
    vi.mocked(api.post).mockResolvedValue({ invite_code: 'once-only-code' });
    vi.mocked(api.delete).mockResolvedValue(null);
  });
  afterEach(cleanup);

  it('renders real server data and requests the next cursor page', async () => {
    const user = userEvent.setup();
    mount(<TeamManagementPage />);
    expect(await screen.findByText('تیم سپهر')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /صفحهٔ بعد/ }));
    await waitFor(() => expect(teamManagementApi.list).toHaveBeenCalledWith('next', { q: '', status: undefined }));
  });

  it('renders an API failure state with retry', async () => {
    vi.mocked(teamManagementApi.list).mockRejectedValueOnce(new Error('offline'));
    mount(<TeamManagementPage />);
    expect(await screen.findByText('فهرست تیم‌ها دریافت نشد. دسترسی و اتصال API را بررسی کنید.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeInTheDocument();
  });

  it('requires a reason before removing a member', async () => {
    const user = userEvent.setup();
    mount(<TeamManagementDetailPage />);
    await user.click(await screen.findByRole('button', { name: /حذف عضو/ }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.type(screen.getByLabelText('دلیل عملیات'), 'پایان فعالیت');
    await user.click(screen.getByRole('button', { name: 'تأیید و ثبت' }));
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/teams/team-1/members/player-2', { reason: 'پایان فعالیت' }));
  });

  it('requires confirmation and a reason before archiving a team', async () => {
    const user = userEvent.setup();
    mount(<TeamManagementDetailPage />);
    await user.click(await screen.findByRole('button', { name: /بایگانی تیم/ }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.type(screen.getByLabelText(/دلیل عملیات/), 'پایان عمر تیم');
    await user.click(screen.getByRole('button', { name: /تأیید و ثبت/ }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/teams/team-1/archive', { reason: 'پایان عمر تیم' }));
  });

  it('does not offer member removal to a non-captain administrator', async () => {
    vi.mocked(teamManagementApi.show).mockResolvedValue({ ...team, captain_user_id: 'another-user' });
    vi.mocked(api.get).mockResolvedValue({ id: 'admin-1', name: 'مدیر', status: 'ACTIVE' });
    mount(<TeamManagementDetailPage />);
    await screen.findByText('بازیکن دوم');
    expect(screen.queryByRole('button', { name: /حذف عضو/ })).not.toBeInTheDocument();
  });
});
