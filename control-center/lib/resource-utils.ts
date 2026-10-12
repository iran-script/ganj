import type { Checkpoint, QrTrigger } from '@/types/resources';

export function activeCheckpoints(items: Checkpoint[]): Checkpoint[] { return items.filter((item) => item.status === 'ACTIVE'); }
export function activeQrTriggers(items: QrTrigger[]): QrTrigger[] { return items.filter((item) => item.status === 'ACTIVE'); }
export function dependencyStatus(id: string | undefined, activeIds: string[]): 'ready' | 'missing' | 'inactive' { if (!id) return 'missing'; return activeIds.includes(id) ? 'ready' : 'inactive'; }
