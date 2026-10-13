import { ApiErrorResponse } from '@/lib/api-error';
import type { ApiEnvelope } from '@/types/api';

const TOKEN_KEY = 'nexo.control.token';

export function getStoredToken(): string | null {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function storeToken(token: string): void {
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearStoredToken(): void {
  window.localStorage.removeItem(TOKEN_KEY);
}

const baseUrl = () => (process.env.NEXT_PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '');

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), init.body instanceof FormData ? 120_000 : 12_000);
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  const token = getStoredToken();
  if (token) headers.set('Authorization', `Bearer ${token}`);

  try {
    const response = await fetch(`${baseUrl()}${path}`, { ...init, headers, signal: controller.signal });
    const payload = (response.status === 204 ? null : await response.json().catch(() => null)) as ApiEnvelope<T> | { message?: string; code?: string; details?: Record<string, unknown> } | null;
    if (!response.ok) {
      const error = payload as { message?: string; code?: string; details?: Record<string, unknown> } | null;
      throw new ApiErrorResponse(response.status, error?.code ?? 'REQUEST_FAILED', error?.message ?? 'درخواست انجام نشد.', error?.details);
    }
    return (payload && 'data' in payload ? payload.data : payload) as T;
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new ApiErrorResponse(408, 'TIMEOUT', 'زمان پاسخ‌گویی سرور به پایان رسید.');
    }
    throw error;
  } finally {
    window.clearTimeout(timeout);
  }
}

type RequestOptions = { headers?: HeadersInit };

export const api = {
  upload: <T>(path: string, body: FormData) => request<T>(path, { method: 'POST', body }),
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown, options: RequestOptions = {}) => request<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined, headers: options.headers }),
  patch: <T>(path: string, body: unknown) => request<T>(path, { method: 'PATCH', body: JSON.stringify(body) }),
  put: <T>(path: string, body: unknown, options: RequestOptions = {}) => request<T>(path, { method: 'PUT', body: JSON.stringify(body), headers: options.headers }),
};

export { request };
