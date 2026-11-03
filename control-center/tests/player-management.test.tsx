import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PlayersPage from '@/app/control/players/page';
import PlayerDetailPage from '@/app/control/players/[id]/page';
import { managementApi } from '@/lib/management-api';

vi.mock('next/navigation', () => ({ useParams: () => ({ id: 'player-1' }) }));
vi.mock('@/lib/management-api', () => ({ managementApi: {
  players: vi.fn(), player: vi.fn(), updatePlayer: vi.fn(), setPlayerStatus: vi.fn(), resetPlayerPassword: vi.fn(), deletePlayer: vi.fn(), restorePlayer: vi.fn(), addPlayerToTeam: vi.fn(), removePlayerFromTeam: vi.fn(),
} }));
vi.mock('@/lib/queries', () => ({ queries: { capabilities: () => ({ queryKey: ['capabilities'], queryFn: async () => ({ global_roles: ['SUPER_ADMIN'], organizations: [], capabilities: ['admin.players.view', 'admin.players.manage'] }) }) } }));

const listPlayer = { id: 'player-1', name: 'بازیکن تست', email: 'player@nexo.test', mobile: '+989121234567', status: 'ACTIVE', last_login_at: null, active_team_memberships_count: 1 };
const detailPlayer = { user: listPlayer, team_memberships: [{ team_id: 'team-1', team_name: 'تیم تست', status: 'ACTIVE', organization: { id: 'org-1', name: 'NEXO Pilot' } }], participation: [] };
function mount(component: React.ReactNode) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{component}</QueryClientProvider>); }

describe('player management module', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(managementApi.players).mockResolvedValue({ data: [listPlayer], meta: { pagination: { has_more: false } } });
    vi.mocked(managementApi.player).mockResolvedValue(detailPlayer);
    vi.mocked(managementApi.updatePlayer).mockResolvedValue(listPlayer);
    vi.mocked(managementApi.setPlayerStatus).mockResolvedValue(listPlayer);
    vi.mocked(managementApi.resetPlayerPassword).mockResolvedValue(undefined);
  });
  afterEach(cleanup);

  it('lists real player data and sends server-side search and filters', async () => {
    const user = userEvent.setup();
    mount(<PlayersPage />);
    expect(await screen.findByText('بازیکن تست')).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText('جست‌وجوی نام، ایمیل، نام کاربری یا تلفن'), 'player');
    await waitFor(() => expect(managementApi.players).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'player' })));
  });

  it('updates profile and password through the management endpoints', async () => {
    const user = userEvent.setup();
    mount(<PlayerDetailPage />);
    expect(await screen.findByText('بازیکن تست')).toBeInTheDocument();
    await user.type(screen.getAllByRole('textbox')[0], ' جدید');
    await user.type(screen.getByLabelText('دلیل عملیات'), 'اصلاح حساب');
    await user.click(screen.getByRole('button', { name: 'ذخیرهٔ اطلاعات' }));
    await waitFor(() => expect(managementApi.updatePlayer).toHaveBeenCalled());
    await user.type(screen.getByPlaceholderText('رمز جدید، حداقل ۱۲ نویسه'), 'secure-password-123');
    await user.click(screen.getByRole('button', { name: 'تغییر رمز' }));
    await waitFor(() => expect(managementApi.resetPlayerPassword).toHaveBeenCalledWith('player-1', 'secure-password-123', 'اصلاح حساب'));
  });
});
