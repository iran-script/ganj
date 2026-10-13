import { api } from '@/lib/api-client';
import { queryPolicy } from '@/lib/query';
import { contentApi } from '@/lib/content-api';
import { scenarioApi } from '@/lib/scenario-api';
import type { QrTrigger } from '@/types/resources';
import type { ControlRoom, Device, Game, GameSession, Health, Organization, Team, TimelineEvent, User } from '@/types/api';

export const queries = {
  me: () => ({ ...queryPolicy.identity, queryKey: ['me'], queryFn: () => api.get<User>('/me') }),
  health: () => ({ ...queryPolicy.health, queryKey: ['health'], queryFn: () => api.get<Health>('/health') }),
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
  locations: () => ({ queryKey: ['content-bank', 'locations'], queryFn: contentApi.locations }),
  qrBank: () => ({ queryKey: ['content-bank', 'qr-bank'], queryFn: contentApi.qr }),
  scenario: (id: string) => ({ queryKey: ['scenarios', id], queryFn: () => scenarioApi.get(id) }),
  scenarioVersions: (id: string) => ({ queryKey: ['scenarios', id, 'versions'], queryFn: () => scenarioApi.versions(id) }),
  qrTriggers: () => ({ queryKey: ['content-bank', 'qr-bank'], queryFn: contentApi.qr, select: (items: Awaited<ReturnType<typeof contentApi.qr>>): QrTrigger[] => items.map((item) => ({ ...item, status: item.status as 'ACTIVE' | 'INACTIVE' | 'REVOKED', metadata: { label: item.name } })) }),
};
