import { render, screen } from '@testing-library/react';
import React from 'react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from '@/app/login/page';

const replace = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace }) }));

describe('login form', () => {
  beforeEach(() => replace.mockReset());

  it('shows validation messages for empty credentials', async () => {
    const user = userEvent.setup(); render(<LoginPage />);
    await user.click(screen.getByRole('button', { name: 'ورود به مرکز کنترل' }));
    expect(await screen.findByText('موبایل یا ایمیل را وارد کنید.')).toBeInTheDocument();
    expect(screen.getByText('رمز عبور را وارد کنید.')).toBeInTheDocument();
  });
});
