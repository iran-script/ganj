import { api, getStoredToken } from '@/lib/api-client';

export type LocationItem = { id: string; organization_id: string; name: string; latitude: number; longitude: number; default_radius_m: number; accuracy_m: number | null; dwell_seconds: number | null; category: string; description: string; status: string };
export type QrItem = { id: string; organization_id: string; name: string; label: string; location_description: string; notes: string; status: string; usage_count: number };
export type MediaItem = { id: string; organization_id: string; name: string; kind: 'IMAGE' | 'AUDIO' | 'FILE'; mime_type: string; size_bytes: number; duration_seconds?: number };
export type SubmissionItem = { id: string; session_id: string; team_id: string; player_id: string; node_id: string; node_title: string; type: string; status: string; asset: MediaItem; submitted_at: string; location_verified: boolean; review_message?: string };
export const contentApi = {
  locations: () => api.get<LocationItem[]>('/content/locations'),
  qr: () => api.get<QrItem[]>('/content/qr-bank'),
  media: () => api.get<MediaItem[]>('/content/media'),
  submissions: (sessionId: string) => api.get<SubmissionItem[]>(`/submissions?session_id=${encodeURIComponent(sessionId)}`),
  review: (id: string, status: string, message: string) => api.post<SubmissionItem>(`/submissions/${id}/review`, { status, message }),
};
export async function fetchContentBlob(path: string): Promise<Blob> {
  const base = (process.env.NEXT_PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '');
  const response = await fetch(`${base}${path}`, { headers: { Authorization: `Bearer ${getStoredToken() ?? ''}` } });
  if (!response.ok) throw new Error('دریافت فایل انجام نشد.');
  return response.blob();
}
export async function downloadContent(path: string, filename: string): Promise<void> {
  const blob = await fetchContentBlob(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a'); link.href = url; link.download = filename; link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
