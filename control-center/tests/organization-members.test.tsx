import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { OrganizationMembersPage } from '@/components/organization-members';
import { organizationMembersApi } from '@/lib/organization-members-api';

vi.mock('@/lib/organization-members-api', () => ({ organizationMembersApi: { organizations: vi.fn(), members: vi.fn() } }));
const organization = { id: 'org-1', name: 'سازمان یک' };
const members = [
  { user_id: 'user-1', name: 'مدیر سازمان', role: 'ORGANIZATION_ADMIN' as const, status: 'ACTIVE', joined_at: '2026-01-01T00:00:00Z' },
  { user_id: 'user-2', name: 'کارشناس', role: 'STAFF' as const, status: 'ACTIVE', joined_at: '2026-01-02T00:00:00Z' },
];

function mount(mode: 'users' | 'admins') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><OrganizationMembersPage mode={mode} /></QueryClientProvider>);
}

describe('organization member management views', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(organizationMembersApi.organizations).mockResolvedValue({ data: [organization] });
    vi.mocked(organizationMembersApi.members).mockImplementation(async (_organizationId, _cursor, query, role) => {
      const filtered = members.filter((member) => (!query || `${member.name} ${member.user_id}`.includes(query))
        && (!role || role.split(',').includes(member.role)));
      return { data: filtered, meta: { pagination: { has_more: false } } };
    });
  });
  afterEach(cleanup);

  it('loads real organization memberships and supports search', async () => {
    const user = userEvent.setup();
    mount('users');
    expect(await screen.findByText('کارشناس')).toBeInTheDocument();
    await user.type(screen.getByRole('textbox', { name: /جست‌وجوی سروری/ }), 'user-1');
    await waitFor(() => expect(organizationMembersApi.members).toHaveBeenLastCalledWith('org-1', undefined, 'user-1', ''));
    expect(screen.getAllByText('مدیر سازمان').some((element) => element.tagName === 'TD')).toBe(true);
    expect(screen.queryByText('کارشناس')).not.toBeInTheDocument();
  });

  it('limits the administrator view to administrative organization roles', async () => {
    mount('admins');
    await waitFor(() => expect(organizationMembersApi.members).toHaveBeenLastCalledWith('org-1', undefined, '', 'ORGANIZATION_ADMIN,GAMEMASTER'));
    expect(screen.getAllByText('مدیر سازمان').some((element) => element.tagName === 'TD')).toBe(true);
    expect(screen.queryByText('کارشناس')).not.toBeInTheDocument();
  });
});
