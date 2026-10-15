import { request } from '@/lib/api-client';
import type { ApiEnvelope, PaginationMeta, Team } from '@/types/api';

export type ManagedTeam = Omit<Team, 'members'> & {
  captain?: { id: string; name: string };
  captain_user_id?: string;
  organization?: { id: string; name: string } | null;
  created_at?: string | null;
  archived_at?: string | null;
  members?: Array<{ user_id: string; name: string; status: string; joined_at: string }>;
};

export type ManagedTeamPage = { data: ManagedTeam[]; meta?: { pagination?: PaginationMeta } };

export const teamManagementApi = {
  list: async (cursor?: string): Promise<ManagedTeamPage> => {
    const query = new URLSearchParams({ limit: '25' });
    if (cursor) query.set('cursor', cursor);
    return request<ApiEnvelope<ManagedTeam[]>>(`/teams?${query.toString()}`, {}, false);
  },
  show: (id: string) => request<ManagedTeam>(`/teams/${id}`),
};
