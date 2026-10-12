import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it } from 'vitest';
import { StatusBadge } from '@/components/ui';

describe('StatusBadge', () => {
  it('renders canonical session status in Persian', () => {
    render(<StatusBadge status="RUNNING" />);
    expect(screen.getByText('در حال اجرا')).toBeInTheDocument();
  });
});
