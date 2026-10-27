import { request } from '@/lib/api-client';
import type { ApiEnvelope, Organization, PaginationMeta } from '@/types/api';

export type OrganizationMemberRole = 'ORGANIZATION_ADMIN' | 'GAMEMASTER' | 'STAFF';
export type OrganizationMemberStatus = 'ACTIVE' | 'SUSPENDED' | 'LEFT' | 'REMOVED';
export type OrganizationMemberView = { user_id: string; name: string; role: OrganizationMemberRole; status: OrganizationMemberStatus; joined_at: string; left_at?: string | null };
export type CursorPage<T> = { data: T[]; meta?: { pagination?: PaginationMeta } };

function pageUrl(path: string, cursor?: string, q?: string, role?: string, status?: string): string {
  const query = new URLSearchParams({ limit: '25' });
  if (cursor) query.set('cursor', cursor);
  if (q) query.set('q', q);
  if (role) query.set('role', role);
  if (status) query.set('status', status);
  return `${path}?${query.toString()}`;
}

export const organizationMembersApi = {
  organizations: (cursor?: string) => request<ApiEnvelope<Organization[]>>(pageUrl('/organizations', cursor), {}, false) as Promise<CursorPage<Organization>>,
  members: (organizationId: string, cursor?: string, q?: string, role?: string, status = 'ACTIVE,SUSPENDED,REMOVED') => request<ApiEnvelope<OrganizationMemberView[]>>(pageUrl(`/organizations/${organizationId}/members`, cursor, q, role, status), {}, false) as Promise<CursorPage<OrganizationMemberView>>,
  add: (organizationId: string, user_id: string, role: OrganizationMemberRole, reason: string) => request(`/admin/organizations/${organizationId}/members`, { method: 'POST', body: JSON.stringify({ user_id, role, reason }) }),
  changeRole: (organizationId: string, userId: string, role: OrganizationMemberRole, reason: string) => request(`/admin/organizations/${organizationId}/members/${userId}/role`, { method: 'PATCH', body: JSON.stringify({ role, reason }) }),
  remove: (organizationId: string, userId: string, reason: string) => request<void>(`/admin/organizations/${organizationId}/members/${userId}`, { method: 'DELETE', body: JSON.stringify({ reason }) }),
  setStatus: (organizationId: string, userId: string, status: 'ACTIVE' | 'SUSPENDED', reason: string) => request<OrganizationMemberView>(`/admin/organizations/${organizationId}/members/${userId}/status`, { method: 'PATCH', body: JSON.stringify({ status, reason }) }),
};
