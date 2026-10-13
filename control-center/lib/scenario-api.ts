import { api, request } from '@/lib/api-client';
import type { Scenario, ScenarioGraphResponse, ScenarioSimulation, ScenarioValidation, ScenarioVersion } from '@/types/scenario';

const key = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-nexo`);
async function allPages<T>(path: string): Promise<T[]> {
  const rows: T[] = [];
  let cursor: string | null = null;
  do {
    const page: { data: T[]; meta: { pagination: { next_cursor: string | null; has_more: boolean } } } = await request(`${path}?limit=100${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ''}`, {}, false);
    rows.push(...page.data);
    cursor = page.meta.pagination.has_more ? page.meta.pagination.next_cursor : null;
  } while (cursor);
  return rows;
}
export const scenarioApi = {
  list: () => allPages<Scenario>('/scenarios'),
  ensureDraft: (id: string) => api.post<ScenarioVersion>(`/scenarios/${id}/draft`, {}),
  update: (id: string, body: { title: string; description: string; theme: string }) => api.patch<Scenario>(`/scenarios/${id}`, body),
  get: (id: string) => api.get<Scenario>(`/scenarios/${id}`),
  create: (body: { organization_id: string; title: string; description?: string; theme?: string }) => api.post<Scenario>('/scenarios', body, { headers: { 'Idempotency-Key': key() } }),
  versions: (id: string) => allPages<ScenarioVersion>(`/scenarios/${id}/versions`),
  version: (id: string) => api.get<ScenarioVersion>(`/scenario-versions/${id}`),
  graph: (id: string) => api.get<ScenarioGraphResponse>(`/scenario-versions/${id}/graph`),
  createVersion: (id: string) => api.post<ScenarioVersion>(`/scenarios/${id}/versions`, {}, { headers: { 'Idempotency-Key': key() } }),
  saveGraph: (id: string, body: { revision_token: string; nodes: unknown[]; edges: unknown[]; variables: unknown[]; conditions: unknown[]; subflows: unknown[] }) => api.put<ScenarioGraphResponse>(`/scenario-versions/${id}/graph`, body),
  validate: (id: string, revision_token: string) => api.post<ScenarioValidation>(`/scenario-versions/${id}/validate`, { revision_token }),
  publish: (id: string, revision_token: string) => api.post<ScenarioVersion>(`/scenario-versions/${id}/publish`, { revision_token }),
  simulate: (id: string, revision_token: string) => api.post<ScenarioSimulation>(`/scenario-versions/${id}/simulate`, { revision_token }),
};
