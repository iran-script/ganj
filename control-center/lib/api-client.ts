import { ApiErrorResponse } from '@/lib/api-error';
import type { ApiEnvelope } from '@/types/api';

const baseUrl = () => '/api/backend';

export function createDeviceUuid(): string {
  const cryptoApi = globalThis.crypto;
  if (typeof cryptoApi?.randomUUID === 'function') {
    return cryptoApi.randomUUID();
  }

  const bytes = new Uint8Array(16);
  if (typeof cryptoApi?.getRandomValues === 'function') {
    cryptoApi.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

async function request<T>(path: string, init: RequestInit = {}, unwrap = true): Promise<T> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), init.body instanceof FormData ? 120_000 : 12_000);
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');

  try {
    const response = await fetch(`${baseUrl()}${path}`, { ...init, headers, credentials: 'same-origin', signal: controller.signal });
    const payload = (response.status === 204 ? null : await response.json().catch(() => null)) as ApiEnvelope<T> | { message?: string; code?: string; details?: Record<string, unknown> } | null;
    if (!response.ok) {
      const body = payload as { error?: { message?: string; code?: string; details?: Record<string, unknown> }; message?: string; code?: string; details?: Record<string, unknown> } | null;
      const error = body?.error ?? body;
      throw new ApiErrorResponse(response.status, error?.code ?? 'REQUEST_FAILED', error?.message ?? 'درخواست انجام نشد.', error?.details);
    }
    return (unwrap && payload && 'data' in payload ? payload.data : payload) as T;
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
  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body: body === undefined ? undefined : JSON.stringify(body) }),
  put: <T>(path: string, body: unknown, options: RequestOptions = {}) => request<T>(path, { method: 'PUT', body: JSON.stringify(body), headers: options.headers }),
};

export { request };
