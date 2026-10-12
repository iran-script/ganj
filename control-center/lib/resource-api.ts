import { api } from '@/lib/api-client';
import type { Checkpoint, QrCreateResult, QrTrigger } from '@/types/resources';

export const resourceApi = {
  checkpoints: () => api.get<Checkpoint[]>('/checkpoints'),
  checkpoint: (id: string) => api.get<Checkpoint>(`/checkpoints/${id}`),
  createCheckpoint: (body: { organization_id: string; name: string; type: 'POINT'; geom: { type: 'Point'; coordinates: [number, number] }; radius_meters: number; config?: Record<string, unknown> }) => api.post<Checkpoint>('/checkpoints', body),
  updateCheckpoint: (id: string, body: Partial<Pick<Checkpoint, 'name' | 'status' | 'radius_meters' | 'config'>>) => api.patch<Checkpoint>(`/checkpoints/${id}`, body),
  qrTriggers: () => api.get<QrTrigger[]>('/qr/triggers'),
  qrTrigger: (id: string) => api.get<QrTrigger>(`/qr/triggers/${id}`),
  createQrTrigger: (body: { organization_id: string; checkpoint_id?: string; metadata?: Record<string, unknown> }) => api.post<QrCreateResult>('/qr/triggers', body),
  qrLifecycle: (id: string, action: 'activate' | 'deactivate' | 'revoke') => api.post<QrTrigger>(`/qr/triggers/${id}/${action}`),
};
