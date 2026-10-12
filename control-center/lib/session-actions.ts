import { api } from '@/lib/api-client';
import type { GameSession, SessionAction } from '@/types/api';

const transitions: Record<string, SessionAction[]> = {
  DRAFT: ['open-registration', 'cancel'],
  REGISTRATION: ['close-registration', 'cancel'],
  READY: ['start', 'cancel'],
  RUNNING: ['pause', 'finish', 'cancel'],
  PAUSED: ['resume', 'finish', 'cancel'],
  FINISHED: [],
  CANCELLED: [],
};

export const sessionActionLabels: Record<SessionAction, string> = {
  'open-registration': 'باز کردن ثبت‌نام', 'close-registration': 'بستن ثبت‌نام', ready: 'بررسی آمادگی', start: 'شروع جلسه', pause: 'توقف موقت', resume: 'ادامه جلسه', finish: 'پایان جلسه', cancel: 'لغو جلسه',
};

export function availableSessionActions(status: GameSession['status']): SessionAction[] { return transitions[status] ?? []; }

export async function performSessionAction(id: string, action: SessionAction, reason?: string): Promise<GameSession> {
  const body = action === 'ready' ? { ready: true } : action === 'cancel' ? { reason: reason ?? 'لغو توسط اپراتور' } : reason ? { reason } : undefined;
  const needsKey = ['start', 'pause', 'resume', 'finish', 'cancel'].includes(action);
  const headers = needsKey ? { 'Idempotency-Key': `control-${action}-${id}-${crypto.randomUUID()}` } : undefined;
  return api.post<GameSession>(`/game-sessions/${id}/${action}`, body, { headers });
}
