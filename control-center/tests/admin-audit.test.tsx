import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminAuditPage from '@/app/control/admin/audit/page';
import { managementApi } from '@/lib/management-api';

vi.mock('@/lib/management-api', () => ({ managementApi: { auditEvents: vi.fn(), capabilities: vi.fn() } }));

const event = {
  actor_type: 'user', actor_id: 'actor-public-id', action: 'user.updated', resource_type: 'user',
  resource_id: 'target-public-id', organization_id: null, occurred_at: '2026-10-01T12:00:00Z',
  reason: 'private reason payload', context: { token: 'private token payload', password: 'private password payload' },
};

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AdminAuditPage /></QueryClientProvider>);
}

describe('admin audit log page', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(managementApi.capabilities).mockResolvedValue({ global_roles: ['SUPER_ADMIN'], organizations: [], capabilities: ['admin.audit.view'] });
    vi.mocked(managementApi.auditEvents).mockResolvedValue({
      data: [event], meta: { pagination: { page: 1, per_page: 1, total: 2, last_page: 2 } },
    });
  });
  afterEach(cleanup);

  it('renders safe event fields, filters by action, and paginates', async () => {
    const user = userEvent.setup();
    mount();
    expect(await screen.findByText('user.updated')).toBeInTheDocument();
    expect(screen.getByText('target-public-id')).toBeInTheDocument();
    expect(screen.queryByText(/private (reason|token|password) payload/i)).not.toBeInTheDocument();

    await user.type(screen.getByLabelText('پالایش بر اساس عملیات'), 'user.updated');
    await waitFor(() => expect(managementApi.auditEvents).toHaveBeenLastCalledWith(expect.objectContaining({ action: 'user.updated' })));
    await user.click(screen.getByRole('button', { name: 'بعدی' }));
    await waitFor(() => expect(managementApi.auditEvents).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })));
  });

  it('shows an empty state when no events match', async () => {
    vi.mocked(managementApi.auditEvents).mockResolvedValue({
      data: [], meta: { pagination: { page: 1, per_page: 25, total: 0, last_page: 1 } },
    });
    mount();
    expect(await screen.findByText('رویدادی پیدا نشد')).toBeInTheDocument();
  });

  it('does not query audit events for an organization administrator', async () => {
    vi.mocked(managementApi.capabilities).mockResolvedValue({ global_roles: [], organizations: [], capabilities: ['admin.players.view'] });
    mount();
    expect(await screen.findByText('این گزارش فقط برای SUPER_ADMIN در دسترس است.')).toBeInTheDocument();
    expect(managementApi.auditEvents).not.toHaveBeenCalled();
  });
});
