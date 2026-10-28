import { request } from '@/lib/api-client';
import type { ApiEnvelope, PaginationMeta, User } from '@/types/api';

export type ManagementPage<T> = { data: T[]; meta?: { pagination?: PaginationMeta } };
export type ManagementListOptions = { cursor?: string; q?: string; status?: string; role?: string; organization_id?: string };
export type ManagedUserInput = { name: string; email?: string | null; mobile?: string | null; password?: string; reason: string };
export type ManagedMembership = { organization: { id: string; name: string }; role: string; status: string; joined_at?: string | null; left_at?: string | null };
export type ManagedUser = Omit<User, 'status'> & {
  status: string;
  created_at?: string | null;
  updated_at?: string | null;
  last_login_at?: string | null;
  deleted_at?: string | null;
  active_team_memberships_count?: number;
  global_roles?: string[];
  organization_memberships?: ManagedMembership[];
  team_memberships?: Array<{ team_id: string; team_name: string; status: string; joined_at?: string | null; left_at?: string | null; organization?: { id: string; name: string } | null }>;
  participation?: Array<{ session_id: string; session_status: string; team_id: string; team_name: string; participation_status: string }>;
  audit?: Array<{ actor_id?: string | null; action: string; reason?: string | null; occurred_at: string; organization_id?: string | null }>;
};
export type PlayerDetail = { user: Pick<ManagedUser, 'id' | 'name' | 'email' | 'mobile' | 'status' | 'deleted_at'>; audit?: ManagedUser['audit']; team_memberships: ManagedUser['team_memberships']; participation: Array<{ player_id: string; session_id: string; session_status: string; team_id: string; team_name: string; participation_status: string; joined_runtime_at?: string | null; last_seen_at?: string | null; roster_frozen_at?: string | null; score?: { score: number; effective_elapsed_seconds: number; hints_used: number; finalized: boolean } | null; organization?: { id: string; name: string } }> };
export type CapabilityContext = { global_roles: string[]; organizations: Array<{ id: string; name: string; role: string }>; capabilities: string[] };
export type ManagedRole = { code: string; scope: string; description: string; assignments_count: number; resource_assignments_count?: number };
export type ManagedRoleOverview = { roles: ManagedRole[]; audit: Array<{ actor_id?: string | null; action: string; resource_type: string; resource_id: string; target_id?: string | null; organization_id?: string | null; reason?: string | null; occurred_at: string }> };

function pagePath(path: string, options: ManagementListOptions = {}): string {
  const query = new URLSearchParams({ limit: '25' });
  Object.entries(options).forEach(([key, value]) => { if (value) query.set(key, value); });
  return `${path}?${query.toString()}`;
}

export const managementApi = {
  capabilities: () => request<CapabilityContext>('/me/capabilities'),
  users: (options: Parameters<typeof pagePath>[1] = {}) => request<ApiEnvelope<ManagedUser[]>>(pagePath('/admin/users', options), {}, false) as Promise<ManagementPage<ManagedUser>>,
  createUser: (input: ManagedUserInput) => request<ManagedUser>('/admin/users', { method: 'POST', body: JSON.stringify(input) }),
  user: (id: string) => request<ManagedUser>(`/admin/users/${id}`),
  updateUser: (id: string, input: Omit<ManagedUserInput, 'password'>) => request<ManagedUser>(`/admin/users/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
  setStatus: (id: string, status: 'ACTIVE' | 'SUSPENDED' | 'BLOCKED', reason: string) => request<ManagedUser>(`/admin/users/${id}/status`, { method: 'PATCH', body: JSON.stringify({ status, reason }) }),
  resetPassword: (id: string, password: string, reason: string) => request<void>(`/admin/users/${id}/password`, { method: 'POST', body: JSON.stringify({ password, reason }) }),
  deleteUser: (id: string, reason: string) => request<void>(`/admin/users/${id}`, { method: 'DELETE', body: JSON.stringify({ reason }) }),
  restoreUser: (id: string, reason: string) => request<ManagedUser>(`/admin/users/${id}/restore`, { method: 'POST', body: JSON.stringify({ reason }) }),
  administrators: (options: Pick<ManagementListOptions, 'cursor' | 'q'> = {}) => request<ApiEnvelope<ManagedUser[]>>(pagePath('/admin/admins', options), {}, false) as Promise<ManagementPage<ManagedUser>>,
  grantAdministrator: (id: string, reason: string) => request(`/admin/admins/${id}`, { method: 'POST', body: JSON.stringify({ reason }) }),
  revokeAdministrator: (id: string, reason: string) => request<void>(`/admin/admins/${id}`, { method: 'DELETE', body: JSON.stringify({ reason }) }),
  roles: () => request<ManagedRoleOverview>('/admin/roles'),
  players: (options: Pick<ManagementListOptions, 'cursor' | 'q' | 'status'> = {}) => request<ApiEnvelope<ManagedUser[]>>(pagePath('/admin/players', options), {}, false) as Promise<ManagementPage<ManagedUser>>,
  player: (id: string) => request<PlayerDetail>(`/admin/players/${id}`),
};
