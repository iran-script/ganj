import { api } from '@/lib/api-client';
import type { Scenario, ScenarioGraphResponse, ScenarioSimulation, ScenarioValidation, ScenarioVersion } from '@/types/scenario';

const key = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-nexo`);
export const scenarioApi = {
  list: () => api.get<Scenario[]>('/scenarios'),
  get: (id: string) => api.get<Scenario>(`/scenarios/${id}`),
  create: (body: { organization_id: string; title: string; description?: string; theme?: string }) => api.post<Scenario>('/scenarios', body, { headers: { 'Idempotency-Key': key() } }),
  versions: (id: string) => api.get<ScenarioVersion[]>(`/scenarios/${id}/versions`),
  version: (id: string) => api.get<ScenarioVersion>(`/scenario-versions/${id}`),
  graph: (id: string) => api.get<ScenarioGraphResponse>(`/scenario-versions/${id}/graph`),
  createVersion: (id: string) => api.post<ScenarioVersion>(`/scenarios/${id}/versions`, {}, { headers: { 'Idempotency-Key': key() } }),
  saveGraph: (id: string, body: { revision_token: string; nodes: unknown[]; edges: unknown[]; variables: unknown[]; conditions: unknown[]; subflows: unknown[] }) => api.put<ScenarioGraphResponse>(`/scenario-versions/${id}/graph`, body),
  validate: (id: string, revision_token: string) => api.post<ScenarioValidation>(`/scenario-versions/${id}/validate`, { revision_token }),
  publish: (id: string, revision_token: string) => api.post<ScenarioVersion>(`/scenario-versions/${id}/publish`, { revision_token }),
  simulate: (id: string, revision_token: string) => api.post<ScenarioSimulation>(`/scenario-versions/${id}/simulate`, { revision_token }),
};
