import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import NexoMap from '@/components/gis/nexo-map';
import { LocationBank } from '@/components/gis/location-bank';
import { BankEditor } from '@/components/content-bank';
import { SavedLocationPicker } from '@/components/gis/saved-location-picker';
import { createMapController, type MapCallbacks, type MapState } from '@/components/gis/map-controller';
import { contentApi, type LocationItem } from '@/lib/content-api';
import { api } from '@/lib/api-client';
import type { NexoMapProps } from '@/components/gis/nexo-map';

const fake = vi.hoisted(() => ({ callbacks: undefined as MapCallbacks | undefined, update: vi.fn(), dispose: vi.fn(), fit: vi.fn() }));
vi.mock('@/components/gis/map-controller', () => ({ createMapController: vi.fn((_target, callbacks) => { fake.callbacks = callbacks; return { update: fake.update, dispose: fake.dispose, fit: fake.fit }; }) }));
vi.mock('@/components/gis/lazy-map', () => ({ LazyMap: (props: NexoMapProps) => <NexoMap {...props} /> }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock('@/lib/content-api', () => ({ contentApi: { locations: vi.fn() } }));
vi.mock('@/lib/api-client', () => ({ api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));
vi.mock('@/lib/queries', () => ({ queries: { organizations: () => ({ queryKey: ['organizations'], queryFn: async () => [{ id: 'org', name: 'Organization' }] }) } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const location: LocationItem = { id: 'a', organization_id: 'org', name: 'Square', latitude: 35, longitude: 51, default_radius_m: 20, accuracy_m: null, dwell_seconds: null, category: 'Park', description: '', status: 'ACTIVE' };
function mount(component: React.ReactNode) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{component}</QueryClientProvider>); }

it('synchronizes list and marker selection, filters markers, and exposes invalid records', async () => {
  vi.mocked(contentApi.locations).mockResolvedValue([location, { ...location, id: 'b', name: 'Old', status: 'INACTIVE' }, { ...location, id: 'bad', name: 'Broken', latitude: 100 }]);
  mount(<LocationBank />);
  await screen.findByText('Square');
  fireEvent.click(screen.getByRole('button', { name: /Square/ }));
  expect(fake.update).toHaveBeenLastCalledWith(expect.objectContaining({ selectedId: 'a', locations: expect.arrayContaining([expect.objectContaining({ id: 'a' })]) }));
  expect((fake.update.mock.lastCall![0] as MapState).locations).toHaveLength(2);
  fireEvent.click(screen.getByRole('button', { name: 'پاک کردن انتخاب' }));
  fake.callbacks!.select('b');
  await waitFor(() => expect(screen.getByRole('button', { name: /Old/ })).toHaveAttribute('aria-pressed', 'true'));
  fireEvent.change(screen.getByLabelText('وضعیت'), { target: { value: 'ACTIVE' } });
  expect((fake.update.mock.lastCall![0] as MapState).locations).toHaveLength(1);
  fireEvent.change(screen.getByLabelText('جستجو در بانک مکان'), { target: { value: 'missing' } });
  expect(screen.getByText('موردی با این فیلتر پیدا نشد.')).toBeInTheDocument();
  expect((fake.update.mock.lastCall![0] as MapState).locations).toEqual([]);
});
it.each(['loading', 'error', 'empty'])('renders the %s bank query state honestly', async state => {
  vi.mocked(contentApi.locations).mockImplementation(() => state === 'loading' ? new Promise(() => {}) : state === 'error' ? Promise.reject(new Error('HTTP 401')) : Promise.resolve([]));
  mount(<LocationBank />);
  if (state === 'loading') expect(screen.queryByText('هنوز موقعیتی ثبت نشده است.')).not.toBeInTheDocument();
  if (state === 'error') expect(await screen.findByText('بارگذاری بانک انجام نشد.')).toBeInTheDocument();
  if (state === 'empty') expect(await screen.findByText('هنوز موقعیتی ثبت نشده است.')).toBeInTheDocument();
});
it('updates create fields from map movement and preserves manual changes when tiles fail', async () => {
  vi.mocked(api.post).mockResolvedValue({ id: 'new' });
  mount(<BankEditor bank="locations" />);
  await screen.findByRole('option', { name: 'Organization' });
  fireEvent.change(screen.getByLabelText('سازمان'), { target: { value: 'org' } });
  fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'Created' } });
  fireEvent.click(screen.getByRole('button', { name: 'انتخاب روی نقشه' }));
  fake.callbacks!.move({ latitude: 35.5, longitude: 51.5 });
  await waitFor(() => expect(screen.getByLabelText('عرض جغرافیایی')).toHaveValue(35.5));
  fireEvent.change(screen.getByLabelText('شعاع پیش‌فرض (متر)'), { target: { value: '45' } });
  fireEvent.change(screen.getByLabelText('طول جغرافیایی'), { target: { value: '52' } });
  expect(fake.update).toHaveBeenLastCalledWith(expect.objectContaining({ locations: [expect.objectContaining({ longitude: 52, radius_m: 45 })] }));
  fake.callbacks!.tileError();
  expect(await screen.findByText(/نقشه زمینه بارگذاری نشد/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }));
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/content/locations', expect.objectContaining({ latitude: 35.5, longitude: 52, default_radius_m: 45 })));
  expect(createMapController).toHaveBeenCalledTimes(1);
});
it('loads an existing location into the editor and saves map movement through PATCH', async () => {
  vi.mocked(api.get).mockResolvedValue(location);
  vi.mocked(api.patch).mockResolvedValue({ id: 'a' });
  mount(<BankEditor bank="locations" id="a" />);
  await screen.findByDisplayValue('Square');
  fireEvent.click(screen.getByRole('button', { name: 'انتخاب روی نقشه' }));
  expect(fake.update).toHaveBeenLastCalledWith(expect.objectContaining({ locations: [expect.objectContaining({ latitude: 35, longitude: 51 })] }));
  fake.callbacks!.move({ latitude: 36, longitude: 52 });
  await waitFor(() => expect(screen.getByLabelText('عرض جغرافیایی')).toHaveValue(36));
  fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }));
  await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/content/locations/a', expect.objectContaining({ latitude: 36, longitude: 52 })));
});
it('keeps manual entry usable when map initialization fails', async () => {
  vi.mocked(createMapController).mockImplementationOnce(() => { throw new Error('canvas unavailable'); });
  mount(<BankEditor bank="locations" />);
  await screen.findByRole('option', { name: 'Organization' });
  fireEvent.click(screen.getByRole('button', { name: 'انتخاب روی نقشه' }));
  expect(await screen.findByText('نقشه در دسترس نیست؛ از ورود دستی مختصات استفاده کنید.')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('عرض جغرافیایی'), { target: { value: '35' } });
  expect(screen.getByLabelText('عرض جغرافیایی')).toHaveValue(35);
});
it('shows inactive saved locations without allowing accidental selection', () => {
  const pick = vi.fn();
  render(<SavedLocationPicker items={[location, { ...location, id: 'old', name: 'Old', status: 'INACTIVE' }]} onPick={pick} />);
  expect(screen.queryByRole('option', { name: 'Old' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox'));
  expect(screen.getByRole('option', { name: 'Old' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('انتخاب موقعیت از بانک مکان'), { target: { value: 'a' } });
  expect(pick).toHaveBeenCalledWith(location);
});
