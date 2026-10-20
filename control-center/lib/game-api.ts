import { api } from '@/lib/api-client';
import type { Game } from '@/types/api';

export type CreateGameInput = {
  organization_id: string;
  name: string;
  slug: string;
  description?: string;
  city?: string;
  default_timezone: string;
  status: 'DRAFT' | 'ACTIVE';
  pricing?: {
    mode: 'FREE' | 'PAID' | 'PER_ROOM' | 'PER_PLAYER';
    amount?: number;
    currency?: string;
    booking_available?: boolean;
    payment_deadline_minutes?: number | null;
    max_active_reservations?: number | null;
    refund_policy?: string | null;
    cancellation_policy?: string | null;
  };
  scenario_version_id?: string;
  reason: string;
};

export const gameApi = {
  create: (body: CreateGameInput) => api.post<Game>('/games', body, { headers: { 'Idempotency-Key': `control-game-${crypto.randomUUID()}` } }),
  update: (id: string, body: Partial<CreateGameInput>) => api.patch<Game>(`/games/${id}`, body),
  archive: (id: string, reason: string) => api.post<Game>(`/games/${id}/archive`, { reason }, { headers: { 'Idempotency-Key': `control-game-archive-${id}-${crypto.randomUUID()}` } }),
};
