import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { LiveControlRoom } from '@/components/live-control-room';
import { api } from '@/lib/api-client';

const { controlRoom } = vi.hoisted(() => ({ controlRoom: vi.fn() }));

vi.mock('next/navigation', () => ({ useParams: () => ({ id: 'session-1' }) }));
vi.mock('@/lib/queries', () => ({ queries: { controlRoom: () => ({ queryKey: ['control-room', 'session-1'], queryFn: controlRoom }) } }));
vi.mock('@/lib/api-client', () => ({ api: { post: vi.fn() } }));

const baseData = {
  session: { id: 'session-1', status: 'RUNNING', started_at: '2026-10-17T08:00:00Z', game: { title: 'City Game' } },
  teams: [{ team_id: '11111111-1111-4111-8111-111111111111', name: 'Team One', status: 'ACTIVE', players: [], score: { score: 10 }, runtime: { current_node: 'node-1' } }],
  runtime: { status: 'ACTIVE' },
  live_challenges: [],
  timeline: [{ event_type: 'started', occurred_at: '2026-10-17T08:00:00Z' }],
};

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><LiveControlRoom /></QueryClientProvider>);
}

describe('live control room', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    controlRoom.mockResolvedValue(baseData);
    vi.mocked(api.post).mockResolvedValue({ id: 'challenge-1' });
  });
  afterEach(cleanup);

  it('renders the real snapshot and navigates back to session details', async () => {
    mount();
    expect(await screen.findByText(/City Game/)).toBeInTheDocument();
    expect(screen.getAllByText('Team One').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: 'جزئیات جلسه' })).toHaveAttribute('href', '/control/sessions/session-1');
    expect(screen.getByText('موقعیت تیم‌ها و بازیکنان از API فعلی در دسترس نیست.')).toBeInTheDocument();
  });

  it('prevents duplicate challenge submissions while the API request is pending', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockReturnValue(new Promise(() => undefined));
    mount();
    await screen.findByText(/City Game/);
    const fields = screen.getAllByRole('combobox');
    await user.selectOptions(fields[1], '11111111-1111-4111-8111-111111111111');
    await user.type(screen.getByPlaceholderText('پیام یا هدف را وارد کنید...'), 'Check point');
    await user.type(screen.getByPlaceholderText('مدت (ثانیه، اختیاری)'), '10');
    await user.click(screen.getByRole('button', { name: 'ارسال چالش' }));
    expect(screen.getByRole('button', { name: 'در حال ارسال...' })).toBeDisabled();
    expect(api.post).toHaveBeenCalledTimes(1);
  });

  it('shows a retryable error when the control-room API fails', async () => {
    controlRoom.mockRejectedValueOnce(new Error('offline'));
    mount();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.getByRole('button')).toBeInTheDocument();
    controlRoom.mockResolvedValueOnce(baseData);
    await userEvent.setup().click(screen.getByRole('button'));
    await waitFor(() => expect(controlRoom).toHaveBeenCalledTimes(2));
  });
});
