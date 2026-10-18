import { api, getApiBaseUrl, getStoredToken } from '@/lib/api-client';
import { ApiErrorResponse, normalizeApiError } from '@/lib/api-error';
import { z } from 'zod';

const bankItem = z.object({ id: z.string(), organization_id: z.string(), name: z.string(), status: z.string() });
const locationSchema = bankItem.extend({ latitude: z.number(), longitude: z.number(), default_radius_m: z.number(), accuracy_m: z.number().nullable(), dwell_seconds: z.number().nullable(), category: z.string(), description: z.string() });
const qrSchema = bankItem.extend({ label: z.string(), location_description: z.string(), notes: z.string(), usage_count: z.number(), created_at: z.string().optional(), updated_at: z.string().optional(), printable_available: z.boolean().optional() });

async function loadBank<T>(path: string, schema: z.ZodType<T>): Promise<T[]> {
  try {
    const result = z.array(schema).safeParse(await api.get<unknown>(path));
    if (!result.success) throw new ApiErrorResponse(200, 'SCHEMA_PARSE_ERROR', 'پاسخ بانک معتبر نیست.');
    return result.data;
  } catch (error) {
    if (process.env.NODE_ENV === 'development') {
      const diagnostic = normalizeApiError(error);
      // Never log response bodies, headers, error messages or validation input.
      console.warn('Content bank request failed', { path, method: 'GET', status: diagnostic.status, category: diagnostic.status === 200 ? 'SCHEMA_PARSE_ERROR' : diagnostic.status ? `HTTP_${diagnostic.status}` : diagnostic.code });
    }
    throw error;
  }
}

export type LocationItem = { id: string; organization_id: string; name: string; latitude: number; longitude: number; default_radius_m: number; accuracy_m: number | null; dwell_seconds: number | null; category: string; description: string; status: string };
export type QrItem = { id: string; organization_id: string; name: string; label: string; location_description: string; notes: string; status: string; usage_count: number; created_at?: string; updated_at?: string; printable_available?: boolean };
export type MediaItem = { id: string; organization_id: string; name: string; kind: 'IMAGE' | 'AUDIO' | 'FILE'; mime_type: string; size_bytes: number; duration_seconds?: number };
export type SubmissionItem = { id: string; session_id: string; team_id: string; player_id: string; node_id: string; node_title: string; type: string; status: string; asset: MediaItem; submitted_at: string; location_verified: boolean; review_message?: string };
export const contentApi = {
  locations: () => loadBank('/content/locations', locationSchema),
  qr: () => loadBank('/content/qr-bank', qrSchema),
  media: () => api.get<MediaItem[]>('/content/media'),
  submissions: (sessionId: string) => api.get<SubmissionItem[]>(`/submissions?session_id=${encodeURIComponent(sessionId)}`),
  review: (id: string, status: string, message: string) => api.post<SubmissionItem>(`/submissions/${id}/review`, { status, message }),
};
export async function fetchContentBlob(path: string): Promise<Blob> {
  const response = await fetch(`${getApiBaseUrl()}${path}`, { headers: { Authorization: `Bearer ${getStoredToken() ?? ''}` } });
  if (!response.ok) throw new Error('دریافت فایل انجام نشد.');
  return response.blob();
}
export async function downloadContent(path: string, filename: string): Promise<void> {
  const blob = await fetchContentBlob(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a'); link.href = url; link.download = filename; link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
