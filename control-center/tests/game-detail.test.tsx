import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GameDetail } from '@/components/game-detail';
import { gameApi } from '@/lib/game-api';
import { ApiErrorResponse } from '@/lib/api-error';

const { game, sessions, capabilities } = vi.hoisted(() => ({ game: vi.fn(), sessions: vi.fn(), capabilities: vi.fn() }));
vi.mock('next/navigation', () => ({ useParams: () => ({ id: 'game-1' }) }));
vi.mock('@/lib/game-api', () => ({ gameApi: { update: vi.fn(), archive: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: { game: () => ({ queryKey: ['games', 'game-1'], queryFn: game }), sessions: () => ({ queryKey: ['sessions'], queryFn: sessions }), capabilities: () => ({ queryKey: ['capabilities'], queryFn: capabilities }) } }));

const baseGame = { id: 'game-1', organization_id: 'org-1', organization: { id: 'org-1', name: 'سازمان نکسو' }, name: 'بازی شهر', slug: 'city-game', description: 'توضیح', city: 'تهران', default_timezone: 'Asia/Tehran', status: 'DRAFT' as const, pricing: { mode: 'FREE' as const, amount: 0, currency: 'IRR' }, scenario: { id: 'scenario-1', title: 'سناریوی اصلی', status: 'PUBLISHED', version_no: 1 } };
const baseSessions = [{ id: 'session-1', game_id: 'game-1', title: 'جلسه صبح', status: 'READY', teams_count: 2, scheduled_start_at: '2026-10-18T08:00:00Z' }];

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><GameDetail /></QueryClientProvider>);
}

describe('game detail management', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    game.mockResolvedValue(baseGame);
    sessions.mockResolvedValue(baseSessions);
    capabilities.mockResolvedValue({ global_roles: [], organizations: [{ id: 'org-1', role: 'ORGANIZATION_ADMIN' }] });
    vi.mocked(gameApi.update).mockResolvedValue({ ...baseGame, name: 'بازی ویرایش‌شده' });
    vi.mocked(gameApi.archive).mockResolvedValue({ ...baseGame, status: 'ARCHIVED' });
  });
  afterEach(cleanup);

  it('edits supported fields and shows related sessions', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'ویرایش بازی' }));
    await user.clear(screen.getByLabelText('نام بازی'));
    await user.type(screen.getByLabelText('نام بازی'), 'بازی ویرایش‌شده');
    await user.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }));
    await waitFor(() => expect(gameApi.update).toHaveBeenCalledWith('game-1', expect.objectContaining({ name: 'بازی ویرایش‌شده', reason: expect.any(String) })));
    expect(await screen.findByText('جلسه صبح')).toBeInTheDocument();
  });

  it('blocks invalid slug before calling the API', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'ویرایش بازی' }));
    await user.clear(screen.getByLabelText('شناسه فنی'));
    await user.type(screen.getByLabelText('شناسه فنی'), 'Bad Slug');
    await user.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }));
    expect(await screen.findByText('شناسه باید با حروف انگلیسی کوچک، عدد و خط تیره باشد.')).toBeInTheDocument();
    expect(gameApi.update).not.toHaveBeenCalled();
  });

  it('archives only after confirmation', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'بایگانی بازی' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'تأیید بایگانی' }));
    await waitFor(() => expect(gameApi.archive).toHaveBeenCalledWith('game-1', expect.any(String)));
  });

  it('hides management actions for unauthorized users', async () => {
    capabilities.mockResolvedValue({ global_roles: [], organizations: [{ id: 'org-1', role: 'GAMEMASTER' }] });
    mount();
    await screen.findByText('بازی شهر');
    expect(screen.queryByRole('button', { name: 'ویرایش بازی' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'بایگانی بازی' })).not.toBeInTheDocument();
    expect(screen.getByText(/دسترسی ویرایش/)).toBeInTheDocument();
  });

  it('renders API errors with a retry action', async () => {
    game.mockRejectedValueOnce(new Error('offline'));
    mount();
    expect(await screen.findByText('اطلاعات بازی دریافت نشد یا دسترسی لازم وجود ندارد.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeInTheDocument();
  });

  it('keeps the edit form open and shows save errors', async () => {
    const user = userEvent.setup();
    vi.mocked(gameApi.update).mockRejectedValueOnce(new ApiErrorResponse(422, 'VALIDATION_FAILED', 'نام بازی معتبر نیست.'));
    mount();
    await user.click(await screen.findByRole('button', { name: 'ویرایش بازی' }));
    await user.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }));
    expect(await screen.findByText('نام بازی معتبر نیست.')).toBeInTheDocument();
    expect(screen.getByRole('form', { name: 'فرم ویرایش بازی' })).toBeInTheDocument();
  });
});
