import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ReportsDashboard } from '@/components/reports-dashboard';

const { sessions, games, controlRoom, leaderboard } = vi.hoisted(() => ({ sessions: vi.fn(), games: vi.fn(), controlRoom: vi.fn(), leaderboard: vi.fn() }));
vi.mock('@/lib/queries', () => ({ queries: { sessions: () => ({ queryKey: ['sessions'], queryFn: sessions }), games: () => ({ queryKey: ['games'], queryFn: games }), controlRoom: (id: string) => ({ queryKey: ['control-room', id], queryFn: () => controlRoom(id) }), leaderboard: (id: string) => ({ queryKey: ['leaderboard', id], queryFn: () => leaderboard(id) }) } }));

const data = [{ id: 's1', title: 'جلسه یک', status: 'FINISHED', game_id: 'g1', teams_count: 2 }];
function mount() { const client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); return render(<QueryClientProvider client={client}><ReportsDashboard /></QueryClientProvider>); }

describe('reports dashboard', () => {
  beforeEach(() => { vi.resetAllMocks(); sessions.mockResolvedValue(data); games.mockResolvedValue([{ id: 'g1', title: 'بازی یک' }]); controlRoom.mockResolvedValue({ teams: [{ id: 't1', team_id: 't1', name: 'تیم یک', players: [], runtime: { current_node: 'node-1' }, score: { score: 20 } }] }); leaderboard.mockResolvedValue({ rankings: [{ rank: 1, team_id: 't1', score: 20, effective_elapsed_seconds: 120, hints_used: 0 }] }); });
  afterEach(cleanup);

  it('maps real session and leaderboard data into KPI and operational views', async () => { mount(); expect(await screen.findByText('جلسه یک')).toBeInTheDocument(); expect(screen.getByText('تیم‌های ثبت‌شده')).toBeInTheDocument(); expect(await screen.findByText('تیم یک')).toBeInTheDocument(); expect(screen.getAllByText('20')).toHaveLength(2); });
  it('applies status filters and exposes backend errors', async () => { const user = userEvent.setup(); mount(); await screen.findByText('جلسه یک'); await user.selectOptions(screen.getByLabelText('فیلتر وضعیت'), 'RUNNING'); expect(screen.getByText('session انتخاب نشده است')).toBeInTheDocument(); sessions.mockRejectedValueOnce(new Error('offline')); cleanup(); mount(); expect(await screen.findByRole('alert')).toBeInTheDocument(); });
});
