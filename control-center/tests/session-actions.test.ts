import { beforeEach, describe, expect, it, vi } from 'vitest';
import { availableSessionActions, performSessionAction } from '@/lib/session-actions';
import { api } from '@/lib/api-client';

vi.mock('@/lib/api-client', () => ({ api: { post: vi.fn() } }));

describe('session lifecycle actions', () => {
  beforeEach(() => vi.mocked(api.post).mockReset());

  it('exposes only server-valid actions for each canonical state', () => {
    expect(availableSessionActions('DRAFT')).toEqual(['open-registration', 'cancel']);
    expect(availableSessionActions('RUNNING')).toEqual(['pause', 'finish', 'cancel']);
    expect(availableSessionActions('FINISHED')).toEqual([]);
  });

  it('sends lifecycle transitions through the API with idempotency', async () => {
    vi.mocked(api.post).mockResolvedValue({ status: 'RUNNING' });
    await performSessionAction('session-id', 'start');
    expect(api.post).toHaveBeenCalledWith('/game-sessions/session-id/start', undefined, { headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) });
  });
});
