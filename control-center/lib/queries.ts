import { api } from '@/lib/api-client';
import type { QrTrigger } from '@/types/resources';
import type { ControlRoom, Device, Game, GameSession, Health, Organization, Team, TimelineEvent, User } from '@/types/api';

export const queries = {
  me: () => ({ queryKey: ['me'], queryFn: () => api.get<User>('/me') }),
  health: () => ({ queryKey: ['health'], queryFn: () => api.get<Health>('/health') }),
  games: () => ({ queryKey: ['games'], queryFn: () => api.get<Game[]>('/games') }),
  game: (id: string) => ({ queryKey: ['games', id], queryFn: () => api.get<Game>(`/games/${id}`) }),
  sessions: () => ({ queryKey: ['sessions'], queryFn: () => api.get<GameSession[]>('/game-sessions') }),
  session: (id: string) => ({ queryKey: ['sessions', id], queryFn: () => api.get<GameSession>(`/game-sessions/${id}`) }),
  teams: () => ({ queryKey: ['teams'], queryFn: () => api.get<Team[]>('/teams') }),
  team: (id: string) => ({ queryKey: ['teams', id], queryFn: () => api.get<Team>(`/teams/${id}`) }),
  devices: () => ({ queryKey: ['devices'], queryFn: () => api.get<Device[]>('/devices') }),
  controlRoom: (id: string) => ({ queryKey: ['control-room', id], queryFn: () => api.get<ControlRoom>(`/game-sessions/${id}/control-room`) }),
  timeline: (id: string) => ({ queryKey: ['sessions', id, 'timeline'], queryFn: () => api.get<TimelineEvent[]>(`/game-sessions/${id}/timeline`) }),
  organizations: () => ({ queryKey: ['organizations'], queryFn: () => api.get<Organization[]>('/organizations') }),
  scenarios: () => ({ queryKey: ['scenarios'], queryFn: () => import('@/lib/scenario-api').then(({ scenarioApi }) => scenarioApi.list()) }),
  checkpoints: () => ({ queryKey: ['checkpoints'], queryFn: () => import('@/lib/resource-api').then(({ resourceApi }) => resourceApi.checkpoints()) }),
  qrTriggers: () => ({ queryKey: ['qr-triggers'], queryFn: (): Promise<QrTrigger[]> => import('@/lib/content-api').then(async ({ contentApi }) => (await contentApi.qr()).map((item) => ({ ...item, status: item.status as 'ACTIVE' | 'INACTIVE' | 'REVOKED', metadata: { label: item.name } }))) }),
};
