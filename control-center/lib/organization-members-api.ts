import { request } from '@/lib/api-client';
import type { ApiEnvelope, Organization, PaginationMeta } from '@/types/api';

export type OrganizationMemberView = { user_id: string; name: string; role: 'ORGANIZATION_ADMIN' | 'GAMEMASTER' | 'STAFF'; status: string; joined_at: string; left_at?: string | null };
export type CursorPage<T> = { data: T[]; meta?: { pagination?: PaginationMeta } };

function pageUrl(path: string, cursor?: string, q?: string, role?: string): string {
  const query = new URLSearchParams({ limit: '25' });
  if (cursor) query.set('cursor', cursor);
  if (q) query.set('q', q);
  if (role) query.set('role', role);
  return `${path}?${query.toString()}`;
}

export const organizationMembersApi = {
  organizations: (cursor?: string) => request<ApiEnvelope<Organization[]>>(pageUrl('/organizations', cursor), {}, false) as Promise<CursorPage<Organization>>,
  members: (organizationId: string, cursor?: string, q?: string, role?: string) => request<ApiEnvelope<OrganizationMemberView[]>>(pageUrl(`/organizations/${organizationId}/members`, cursor, q, role), {}, false) as Promise<CursorPage<OrganizationMemberView>>,
  add: (organizationId: string, user_id: string, role: OrganizationMemberView['role'], reason: string) => request(`/admin/organizations/${organizationId}/members`, { method: 'POST', body: JSON.stringify({ user_id, role, reason }) }),
  changeRole: (organizationId: string, userId: string, role: OrganizationMemberView['role'], reason: string) => request(`/admin/organizations/${organizationId}/members/${userId}/role`, { method: 'PATCH', body: JSON.stringify({ role, reason }) }),
  remove: (organizationId: string, userId: string, reason: string) => request<void>(`/admin/organizations/${organizationId}/members/${userId}`, { method: 'DELETE', body: JSON.stringify({ reason }) }),
};
