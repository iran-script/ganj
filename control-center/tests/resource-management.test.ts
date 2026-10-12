import { describe, expect, it } from 'vitest';
import { activeCheckpoints, activeQrTriggers, dependencyStatus } from '@/lib/resource-utils';

describe('resource management pickers', () => {
  it('only offers active checkpoints and QR triggers', () => {
    expect(activeCheckpoints([{ id: 'cp-1', organization_id: 'org', name: 'ورودی', type: 'POINT', status: 'ACTIVE' }, { id: 'cp-2', organization_id: 'org', name: 'قدیمی', type: 'POINT', status: 'INACTIVE' }])).toHaveLength(1);
    expect(activeQrTriggers([{ id: 'qr-1', organization_id: 'org', status: 'ACTIVE' }, { id: 'qr-2', organization_id: 'org', status: 'REVOKED' }])).toHaveLength(1);
  });

  it('marks missing and inactive graph dependencies distinctly', () => {
    expect(dependencyStatus(undefined, ['cp-1'])).toBe('missing');
    expect(dependencyStatus('cp-2', ['cp-1'])).toBe('inactive');
    expect(dependencyStatus('cp-1', ['cp-1'])).toBe('ready');
  });
});
