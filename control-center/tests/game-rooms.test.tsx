import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GameRooms } from '@/components/game-rooms';
import { roomApi } from '@/lib/room-api';

const { rooms, me, teams } = vi.hoisted(() => ({ rooms: vi.fn(), me: vi.fn(), teams: vi.fn() }));
vi.mock('@/lib/room-api', () => ({ roomApi: { list: vi.fn(), get: vi.fn(), create: vi.fn(), registerTeam: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: {
  rooms: () => ({ queryKey: ['games', 'game-1', 'rooms'], queryFn: rooms }),
  room: () => ({ queryKey: ['rooms', 'room-1'], queryFn: () => roomApi.get('room-1') }),
  me: () => ({ queryKey: ['me'], queryFn: me }),
  teams: () => ({ queryKey: ['teams'], queryFn: teams }),
  game: () => ({ queryKey: ['games', 'game-1'], queryFn: vi.fn() }),
} }));

const game = { id: 'game-1', organization_id: 'org-1', status: 'ACTIVE' };
const room = { id: 'room-1', game_id: 'game-1', name: 'اتاق مرکزی', status: 'OPEN', capacity: 5, team_count: 1, teams: [{ id: 'team-1', name: 'تیم آفتاب', captain: 'علی', member_count: 4 }] };
const captainTeam = { id: '11111111-1111-4111-8111-111111111111', name: 'تیم آفتاب', status: 'ACTIVE', captain_user_id: 'user-1', eligible_for_game: true, organization: { id: 'org-1' } };

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><GameRooms game={game} /></QueryClientProvider>);
}

describe('game rooms management', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    rooms.mockResolvedValue({ data: [room], meta: { pagination: { has_more: false } } });
    me.mockResolvedValue({ id: 'user-1' });
    teams.mockResolvedValue([captainTeam]);
    vi.mocked(roomApi.get).mockResolvedValue(room);
    vi.mocked(roomApi.create).mockResolvedValue(room);
    vi.mocked(roomApi.registerTeam).mockResolvedValue({ ...room, team_count: 2, teams: [...room.teams, { id: captainTeam.id, name: captainTeam.name, captain: 'علی', member_count: 4 }] });
  });
  afterEach(cleanup);

  it('lists rooms and loads details from the room endpoint', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: /اتاق مرکزی/ }));
    await waitFor(() => expect(roomApi.get).toHaveBeenCalledWith('room-1'));
    expect(await screen.findByRole('dialog', { name: 'جزئیات اتاق' })).toBeInTheDocument();
    expect(within(screen.getByRole('dialog', { name: 'جزئیات اتاق' })).getAllByText('تیم آفتاب')[0]).toBeInTheDocument();
  });

  it('creates a room with the selected eligible captain team', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'ساخت اتاق' }));
    await user.type(await screen.findByLabelText('نام اتاق'), 'اتاق جدید');
    await user.selectOptions(screen.getByLabelText('تیم اولیه'), captainTeam.id);
    await user.click(within(screen.getByRole('dialog', { name: 'ساخت اتاق' })).getAllByRole('button', { name: 'ساخت اتاق' }).at(-1)!);
    await waitFor(() => expect(roomApi.create).toHaveBeenCalledWith('game-1', { name: 'اتاق جدید', team_id: captainTeam.id }));
  });

  it('registers an eligible captain team and refreshes room data', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: /اتاق مرکزی/ }));
    await user.click(await screen.findByRole('button', { name: 'ثبت تیم' }));
    await waitFor(() => expect(roomApi.registerTeam).toHaveBeenCalledWith('room-1', captainTeam.id));
    await waitFor(() => expect(roomApi.get).toHaveBeenCalledWith('room-1'));
  });

  it('shows validation and does not create without valid fields', async () => {
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: 'ساخت اتاق' }));
    await user.click(within(screen.getByRole('dialog', { name: 'ساخت اتاق' })).getAllByRole('button', { name: 'ساخت اتاق' }).at(-1)!);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(roomApi.create).not.toHaveBeenCalled();
  });

  it('reflects backend creation permissions for non-captains', async () => {
    me.mockResolvedValue({ id: 'other-user' });
    mount();
    await screen.findByText('اتاق مرکزی');
    await userEvent.setup().click(screen.getByRole('button', { name: 'ساخت اتاق' }));
    expect(await screen.findByText(/کاپیتان/)).toBeInTheDocument();
    expect(screen.queryByLabelText('نام اتاق')).not.toBeInTheDocument();
  });

  it('does not expose team registration to a non-captain', async () => {
    me.mockResolvedValue({ id: 'other-user' });
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: /اتاق مرکزی/ }));
    expect(await screen.findByText(/مدیران دارای دسترسی مشاهده/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'ثبت تیم' })).not.toBeInTheDocument();
  });

  it('shows full-room capacity and handles registration failures', async () => {
    rooms.mockResolvedValueOnce({ data: [{ ...room, status: 'FULL', capacity: 1, team_count: 1 }], meta: { pagination: { has_more: false } } });
    vi.mocked(roomApi.get).mockResolvedValueOnce({ ...room, status: 'FULL', capacity: 1, team_count: 1 });
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: /اتاق مرکزی/ }));
    expect(await screen.findByText('این اتاق در حال حاضر تیم جدید نمی‌پذیرد.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'ثبت تیم' })).not.toBeInTheDocument();
  });

  it('shows registration API errors', async () => {
    vi.mocked(roomApi.registerTeam).mockRejectedValueOnce(new Error('denied'));
    const user = userEvent.setup();
    mount();
    await user.click(await screen.findByRole('button', { name: /اتاق مرکزی/ }));
    await user.click(await screen.findByRole('button', { name: 'ثبت تیم' }));
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });

  it('shows retryable listing and creation API failures', async () => {
    rooms.mockRejectedValueOnce(new Error('offline'));
    mount();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    vi.mocked(roomApi.create).mockRejectedValueOnce(new Error('denied'));
  });
});
