import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SessionDetail } from '@/components/session-detail';

const { session, timeline, capabilities, game, perform } = vi.hoisted(() => ({
  session: vi.fn(),
  timeline: vi.fn(),
  capabilities: vi.fn(),
  game: vi.fn(),
  perform: vi.fn(),
}));

vi.mock('next/navigation', () => ({ useParams: () => ({ id: 'session-1' }) }));
vi.mock('@/lib/queries', () => ({
  queries: {
    session: () => ({ queryKey: ['sessions', 'session-1'], queryFn: session }),
    timeline: () => ({ queryKey: ['sessions', 'session-1', 'timeline'], queryFn: timeline }),
    capabilities: () => ({ queryKey: ['me', 'capabilities'], queryFn: capabilities }),
    game: () => ({ queryKey: ['games', 'game-1'], queryFn: game }),
  },
}));
vi.mock('@/lib/session-actions', () => ({
  availableSessionActions: (status: string) => status === 'RUNNING' ? ['pause', 'finish', 'cancel'] : [],
  sessionActionLabels: { pause: 'Pause', finish: 'Finish', cancel: 'Cancel' },
  performSessionAction: perform,
}));

const baseSession = {
  id: 'session-1',
  organization_id: 'org-1',
  game_id: 'game-1',
  status: 'RUNNING' as const,
  timezone: 'Asia/Tehran',
  started_at: '2026-10-17T08:00:00Z',
  teams: [{ id: 'team-1', name: 'Team One', status: 'READY', player_count: 1, players: [{ id: 'player-1', name: 'Player One', presence: 'ONLINE' }] }],
};
const baseGame = { id: 'game-1', name: 'City Game', organization_id: 'org-1', scenario: { id: 'scenario-1', title: 'Scenario One', version_no: 2 } };

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><SessionDetail /></QueryClientProvider>);
}

describe('session detail', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    session.mockResolvedValue(baseSession);
    timeline.mockResolvedValue([{ event_id: 'event-1', event_type: 'started', occurred_at: '2026-10-17T08:00:00Z' }]);
    capabilities.mockResolvedValue({ global_roles: [], capabilities: [], organizations: [{ id: 'org-1', role: 'ORGANIZATION_ADMIN' }] });
    game.mockResolvedValue(baseGame);
    perform.mockResolvedValue({ ...baseSession, status: 'PAUSED' });
  });
  afterEach(cleanup);

  it('renders identity, game/scenario navigation, team and participant data', async () => {
    mount();
    expect(await screen.findByRole('heading', { name: 'City Game' })).toBeInTheDocument();
    expect(screen.getByText('Scenario One')).toBeInTheDocument();
    expect(screen.getByText('Team One')).toBeInTheDocument();
    expect(screen.getByText('Player One')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'مشاهده بازی' })).toHaveAttribute('href', '/control/games/game-1');
  });

  it('confirms an authorized lifecycle action and refreshes session data', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'Pause' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'تأیید عملیات' }));
    await waitFor(() => expect(perform).toHaveBeenCalledWith('session-1', 'pause'));
    expect(await screen.findByRole('status')).toHaveTextContent('عملیات جلسه با موفقیت ثبت شد.');
  });

  it('hides lifecycle actions for a user without an available operator permission', async () => {
    capabilities.mockResolvedValue({ global_roles: [], capabilities: [], organizations: [{ id: 'org-1', role: 'GAMEMASTER' }] });
    mount();
    await screen.findByRole('heading', { name: 'City Game' });
    expect(screen.queryByRole('button', { name: 'Pause' })).not.toBeInTheDocument();
    expect(screen.getByText('این جلسه برای شما در حالت فقط‌خواندنی است.')).toBeInTheDocument();
  });

  it('shows a retry state when the session API fails', async () => {
    session.mockRejectedValueOnce(new Error('offline'));
    mount();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeInTheDocument();
  });

  it('shows an error when the lifecycle API rejects an authorized action', async () => {
    const user = userEvent.setup();
    perform.mockRejectedValueOnce(new Error('operation failed'));
    mount();
    await user.click(await screen.findByRole('button', { name: 'Pause' }));
    await user.click(screen.getByRole('button', { name: 'تأیید عملیات' }));
    expect(await screen.findByText('ثبت عملیات جلسه انجام نشد.')).toBeInTheDocument();
  });

  it('documents the unsupported room relationship instead of fabricating one', async () => {
    session.mockResolvedValue({ ...baseSession, teams: [] });
    mount();
    expect(await screen.findByText((_, element) => element?.tagName === 'P' && element.textContent?.includes('رابطه اتاق بازی') === true)).toBeInTheDocument();
  });
});
