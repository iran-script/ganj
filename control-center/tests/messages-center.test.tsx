import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MessagesCenter } from '@/components/messages-center';
import { ApiErrorResponse } from '@/lib/api-error';
import { api } from '@/lib/api-client';

const { sessions, controlRoom } = vi.hoisted(() => ({ sessions: vi.fn(), controlRoom: vi.fn() }));

vi.mock('@/lib/queries', () => ({ queries: { sessions: () => ({ queryKey: ['sessions'], queryFn: sessions }), controlRoom: (id: string) => ({ queryKey: ['control-room', id], queryFn: () => controlRoom(id) }) } }));
vi.mock('@/lib/api-client', () => ({ api: { post: vi.fn() } }));

const session = { id: 'session-1', title: 'جلسه آزمایشی', status: 'RUNNING' };
const team = { id: 'team-1', team_id: 'team-1', name: 'تیم یک', players: [{ id: 'player-1', name: 'بازیکن یک' }] };

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><MessagesCenter /></QueryClientProvider>);
}

describe('messages center', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    sessions.mockResolvedValue([session]);
    controlRoom.mockResolvedValue({ teams: [team] });
    vi.mocked(api.post).mockResolvedValue({ message_id: 'message-1' });
  });
  afterEach(cleanup);

  it('sends a session message through the real contract with idempotency', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('جلسه آزمایشی');
    await user.type(screen.getByLabelText('متن پیام'), 'پیام عملیاتی');
    await user.click(screen.getByRole('button', { name: 'ارسال پیام' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    expect(api.post).toHaveBeenCalledWith('/game-sessions/session-1/messages', expect.objectContaining({ target_type: 'SESSION', target_id: 'session-1', body: 'پیام عملیاتی', priority: 'NORMAL' }), expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.stringMatching(/^message-center-/) }) }));
    expect(await screen.findByRole('status')).toHaveTextContent('پیام با موفقیت ثبت شد');
  });

  it('loads only backend-provided team recipients and reports authorization failures', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(new ApiErrorResponse(403, 'AUTHORIZATION_DENIED', 'denied'));
    mount();
    await screen.findByText('جلسه آزمایشی');
    await user.selectOptions(screen.getByLabelText('نوع مخاطب'), 'TEAM');
    await waitFor(() => expect(controlRoom).toHaveBeenCalledWith('session-1'));
    await user.selectOptions(await screen.findByLabelText('گیرنده پیام'), 'team-1');
    await user.type(screen.getByLabelText('متن پیام'), 'پیام تیم');
    await user.click(screen.getByRole('button', { name: 'ارسال پیام' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('برای این جلسه دسترسی GameMaster ندارید');
  });
});
