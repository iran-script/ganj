import { describe, expect, it } from 'vitest';
import { createDeviceUuid } from '@/lib/api-client';

describe('createDeviceUuid', () => {
  it('creates a UUID on runtimes without randomUUID', () => {
    const originalRandomUuid = globalThis.crypto.randomUUID;
    Object.defineProperty(globalThis.crypto, 'randomUUID', { configurable: true, value: undefined });

    expect(createDeviceUuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);

    Object.defineProperty(globalThis.crypto, 'randomUUID', { configurable: true, value: originalRandomUuid });
  });
});
