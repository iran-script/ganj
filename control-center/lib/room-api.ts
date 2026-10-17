import { api, request } from '@/lib/api-client';
import type { ApiEnvelope, PaginationMeta, Room } from '@/types/api';

export type RoomPage = { data: Room[]; meta?: { pagination?: PaginationMeta } };
export type CreateRoomInput = { name: string; team_id: string };

export const roomApi = {
  list: (gameId: string, cursor?: string | null) => {
    const params = new URLSearchParams({ limit: '25' });
    if (cursor) params.set('cursor', cursor);
    return request<ApiEnvelope<Room[]>>('/games/' + gameId + '/rooms?' + params.toString(), {}, false) as Promise<RoomPage>;
  },
  get: (id: string) => api.get<Room>('/rooms/' + id),
  create: (gameId: string, input: CreateRoomInput) => api.post<Room>('/games/' + gameId + '/rooms', input, { headers: { 'Idempotency-Key': 'control-center-room-' + crypto.randomUUID() } }),
  registerTeam: (roomId: string, teamId: string) => api.post<Room>('/rooms/' + roomId + '/teams', { team_id: teamId }, { headers: { 'Idempotency-Key': 'control-center-room-team-' + crypto.randomUUID() } }),
};
