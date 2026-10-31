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
export type ManagedInvite = { id: string; expires_at?: string | null; max_uses?: number | null; uses_count: number; revoked_at?: string | null; created_at?: string | null };
export type ManagedInvitePage = { data: ManagedInvite[]; meta?: { pagination?: PaginationMeta } };
export type TeamListOptions = { q?: string; status?: 'ACTIVE' | 'ARCHIVED' };

export const teamManagementApi = {
  list: async (cursor?: string, options: TeamListOptions = {}): Promise<ManagedTeamPage> => {
    const query = new URLSearchParams({ limit: '25' });
    if (cursor) query.set('cursor', cursor);
    if (options.q) query.set('q', options.q);
    if (options.status) query.set('status', options.status);
    return request<ApiEnvelope<ManagedTeam[]>>(`/teams?${query.toString()}`, {}, false);
  },
  create: (name: string) => request<ManagedTeam>('/teams', { method: 'POST', body: JSON.stringify({ name }), headers: { 'Idempotency-Key': `control-team-create-${crypto.randomUUID()}` } }),
  show: (id: string) => request<ManagedTeam>(`/teams/${id}`),
  invites: async (teamId: string, cursor?: string): Promise<ManagedInvitePage> => { const query = new URLSearchParams({ limit: '25' }); if (cursor) query.set('cursor', cursor); return request<ApiEnvelope<ManagedInvite[]>>(`/teams/${teamId}/invites?${query.toString()}`, {}, false); },
  revokeInvite: (teamId: string, inviteId: string, reason?: string) => request<void>(`/teams/${teamId}/invites/${inviteId}`, { method: 'DELETE', body: JSON.stringify(reason ? { reason } : {}) }),
};
