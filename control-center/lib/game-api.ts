import { api } from '@/lib/api-client';
import type { Game } from '@/types/api';

export type CreateGameInput = {
  organization_id: string;
  name: string;
  slug: string;
  description?: string;
  default_timezone: string;
  status: 'DRAFT' | 'ACTIVE';
  pricing?: { mode: 'FREE' | 'PAID'; amount?: number; currency?: string };
  scenario_version_id?: string;
  reason: string;
};

export const gameApi = {
  create: (body: CreateGameInput) => api.post<Game>('/games', body, { headers: { 'Idempotency-Key': `control-game-${crypto.randomUUID()}` } }),
};
