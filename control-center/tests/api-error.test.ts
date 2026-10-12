import { describe, expect, it } from 'vitest';
import { ApiErrorResponse, normalizeApiError } from '@/lib/api-error';

describe('API error normalization', () => {
  it('preserves Laravel error code and status', () => {
    const error = normalizeApiError(new ApiErrorResponse(422, 'VALIDATION_FAILED', 'داده نامعتبر است.', { field: 'email' }));
    expect(error).toMatchObject({ status: 422, code: 'VALIDATION_FAILED', message: 'داده نامعتبر است.' });
  });

  it('maps network failures to a user-safe message', () => {
    expect(normalizeApiError(new TypeError('Failed to fetch')).code).toBe('NETWORK_ERROR');
  });
});
