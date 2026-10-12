import type { ApiError } from '@/types/api';

export function normalizeApiError(error: unknown, fallback = 'ارتباط با سرور برقرار نشد.'): ApiError {
  if (error instanceof ApiErrorResponse) {
    return { code: error.code, message: error.message, status: error.status, details: error.details };
  }

  if (error instanceof TypeError) {
    return { code: 'NETWORK_ERROR', message: fallback, status: 0 };
  }

  return { code: 'UNKNOWN_ERROR', message: fallback, status: 0 };
}

export class ApiErrorResponse extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details?: Record<string, unknown>,
  ) {
    super(message);
    this.name = 'ApiErrorResponse';
  }
}
