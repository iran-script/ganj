import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardContent } from '@/components/dashboard-content';

vi.mock('@tanstack/react-query', () => ({ useQuery: () => ({ isPending: true }) }));
vi.mock('@/lib/queries', () => ({ queries: { games: () => ({}), sessions: () => ({}), health: () => ({}) } }));

function renderDashboard() { return render(<DashboardContent />); }

describe('dashboard states', () => {
  it('renders loading state while queries resolve', () => {
    renderDashboard();
    expect(screen.getByText('در حال دریافت اطلاعات...')).toBeInTheDocument();
  });
});
