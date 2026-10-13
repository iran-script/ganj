import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ContentBank, BankEditor } from '@/components/content-bank';
import { MediaLibrary } from '@/components/media-library';
import { SubmissionReview } from '@/components/submission-review';
import { contentApi, downloadContent } from '@/lib/content-api';
import { QrPrintable } from '@/components/qr-printable';
import { api } from '@/lib/api-client';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock('@/lib/api-client', () => ({ api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), upload: vi.fn() } }));
vi.mock('@/lib/queries', async (importOriginal) => ({ queries: { ...(await importOriginal<typeof import('@/lib/queries')>()).queries, organizations: () => ({ queryKey: ['organizations'], queryFn: async () => [{ id: 'org', name: 'Organization' }] }), sessions: () => ({ queryKey: ['sessions'], queryFn: async () => [{ id: 'session', title: 'Session' }] }) } }));
vi.mock('@/lib/content-api', () => ({ contentApi: { locations: vi.fn(), qr: vi.fn(), media: vi.fn(), submissions: vi.fn(), review: vi.fn() }, downloadContent: vi.fn() }));
const asset = { id: 'asset', organization_id: 'org', name: 'Evidence', kind: 'FILE' as const, mime_type: 'application/pdf', size_bytes: 100 };
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(contentApi.locations).mockResolvedValue([{ id: 'location', organization_id: 'org', name: 'Square', latitude: 35, longitude: 51, default_radius_m: 20, accuracy_m: 10, dwell_seconds: 5, category: '', description: '', status: 'ACTIVE' }]);
  vi.mocked(contentApi.qr).mockResolvedValue([{ id: 'qr1', organization_id: 'org', name: 'Square QR', label: 'SQ1', location_description: '', notes: '', status: 'ACTIVE', usage_count: 2 }, { id: 'qr2', organization_id: 'org', name: 'Old QR', label: 'OLD', location_description: '', notes: '', status: 'INACTIVE', usage_count: 1 }]);
  vi.mocked(contentApi.media).mockResolvedValue([asset]);
  vi.mocked(contentApi.submissions).mockResolvedValue([{ id: 'submission', session_id: 'session', team_id: 'team', player_id: 'player', node_id: 'node', node_title: 'Photo proof', type: 'PhotoSubmission', status: 'PENDING', asset, submitted_at: '2026-10-13T00:00:00Z', location_verified: true }]);
  vi.mocked(api.post).mockResolvedValue({ id: 'created' });
  vi.mocked(api.upload).mockResolvedValue(asset);
});
afterEach(cleanup);
it('clears QR notes and refreshes printable availability after deactivation', async () => {
  vi.mocked(api.get).mockResolvedValue({ id: 'qr1', name: 'Square QR', label: 'SQ1', status: 'ACTIVE', notes: 'Old notes', printable_available: true });
  vi.mocked(api.patch).mockImplementation(async () => {
    vi.mocked(api.get).mockResolvedValue({ id: 'qr1', name: 'Square QR', label: 'SQ1', status: 'INACTIVE', notes: '', printable_available: false });
    return { id: 'qr1' } as never;
  });
  mount(<BankEditor bank="qr-bank" id="qr1" />);
  await screen.findByDisplayValue('Old notes');
  fireEvent.change(screen.getByLabelText('یادداشت (اختیاری)'), { target: { value: '' } });
  fireEvent.change(screen.getByLabelText('وضعیت'), { target: { value: 'INACTIVE' } });
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره' }));
  await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/content/qr-bank/qr1', expect.objectContaining({ notes: null, status: 'INACTIVE' })));
  await waitFor(() => expect(screen.queryByRole('button', { name: 'دریافت SVG برای چاپ' })).not.toBeInTheDocument());
});

it('downloads authorized printable SVG without a secret text field', async () => {
  mount(<QrPrintable id="qr1" available />);
  await userEvent.click(screen.getByRole('button', { name: 'دریافت SVG برای چاپ' }));
  await waitFor(() => expect(downloadContent).toHaveBeenCalledWith('/content/qr-bank/qr1/printable', 'nexo-qr-qr1.svg'));
  expect(screen.getByRole('status')).toHaveTextContent('فایل چاپی دریافت شد.');
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});
function mount(component: React.ReactNode) { render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>{component}</QueryClientProvider>); }

it('searches and filters the QR bank without showing any secret', async () => {
  mount(<ContentBank bank="qr-bank" />);
  await screen.findByRole('heading', { name: 'Square QR' });
  await userEvent.click(screen.getByLabelText('فقط فعال'));
  expect(screen.queryByRole('heading', { name: 'Old QR' })).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('جستجو در بانک'), { target: { value: 'missing' } });
  expect(screen.getByText('موردی یافت نشد.')).toBeInTheDocument();
  expect(document.body.textContent).not.toContain('identifier');
});

it('submits typed coordinates and optional policy through the Location Bank API', async () => {
  mount(<BankEditor bank="locations" />);
  await screen.findByRole('option', { name: 'Organization' });
  fireEvent.change(screen.getByLabelText('سازمان'), { target: { value: 'org' } });
  fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'Square' } });
  fireEvent.change(screen.getByLabelText('عرض جغرافیایی'), { target: { value: '35.6892' } });
  fireEvent.change(screen.getByLabelText('طول جغرافیایی'), { target: { value: '51.389' } });
  fireEvent.change(screen.getByLabelText('حداکثر خطا (متر) (اختیاری)'), { target: { value: '10' } });
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره' }));
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/content/locations', expect.objectContaining({ latitude: 35.6892, longitude: 51.389, accuracy_m: 10, default_radius_m: 20 })));
});

it('uploads media as multipart data and retains references', async () => {
  mount(<MediaLibrary />);
  await screen.findByRole('heading', { name: 'Evidence' });
  fireEvent.change(screen.getByLabelText('سازمان'), { target: { value: 'org' } });
  fireEvent.change(screen.getByLabelText('نام رسانه'), { target: { value: 'Clue' } });
  fireEvent.change(screen.getByLabelText('نوع رسانه'), { target: { value: 'FILE' } });
  await userEvent.upload(screen.getByLabelText('فایل'), new File(['%PDF-1.4'], 'clue.pdf', { type: 'application/pdf' }));
  expect((screen.getByLabelText('فایل') as HTMLInputElement).files).toHaveLength(1);
  expect(screen.getByLabelText('نام رسانه')).toHaveValue('Clue');
  expect(screen.getByLabelText('سازمان')).toHaveValue('org');
  // JSDOM leaves valueMissing true for a populated synthetic FileList.
  fireEvent.submit(screen.getByRole('button', { name: 'بارگذاری رسانه' }).closest('form')!);
  await waitFor(() => expect(api.upload).toHaveBeenCalled());
  const body = vi.mocked(api.upload).mock.calls[0][1];
  expect(body).toBeInstanceOf(FormData);
  expect(body.get('name')).toBe('Clue');
  expect(body.get('file')).toBeInstanceOf(File);
});

it('reviews pending evidence with a required message', async () => {
  mount(<SubmissionReview />);
  await screen.findByRole('option', { name: 'Session' });
  fireEvent.change(screen.getByLabelText('جلسه'), { target: { value: 'session' } });
  await userEvent.click(await screen.findByRole('button', { name: 'بررسی / پیام' }));
  expect(screen.getByRole('button', { name: 'تأیید مدرک' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('پیام بررسی'), { target: { value: 'Valid evidence' } });
  await userEvent.click(screen.getByRole('button', { name: 'تأیید مدرک' }));
  await waitFor(() => expect(contentApi.review).toHaveBeenCalledWith('submission', 'APPROVED', 'Valid evidence'));
});

it('sends review messages through the existing GameMaster API', async () => {
  mount(<SubmissionReview />);
  await screen.findByRole('option', { name: 'Session' });
  fireEvent.change(screen.getByLabelText('جلسه'), { target: { value: 'session' } });
  await userEvent.click(await screen.findByRole('button', { name: 'بررسی / پیام' }));
  fireEvent.change(screen.getByLabelText('پیام بررسی'), { target: { value: 'Please retry' } });
  await userEvent.click(screen.getByRole('button', { name: 'ارسال پیام' }));
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/game-sessions/session/messages', expect.objectContaining({ target_type: 'PLAYER', target_id: 'player', body: 'Please retry' }), expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) })));
});
