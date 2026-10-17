import { api } from '@/lib/api-client';
import { scenarioApi } from '@/lib/scenario-api';
import type { Scenario, ScenarioGraph, ScenarioNode, ScenarioVersion } from '@/types/scenario';

export type NodeCatalogItem = { type: string; category: string; config_schema: Record<string, string>; runtime_handler: string | null; builder_inspector: string; player_facing: boolean; outgoing_semantics: string[]; supports_timeout: boolean };
export type PuzzleContentItem = { scenario: Scenario; version: ScenarioVersion; draftVersionId?: string; node: ScenarioNode & { node_type: string }; graph: ScenarioGraph };
export type PuzzleContentSnapshot = { catalog: NodeCatalogItem[]; items: PuzzleContentItem[] };
const puzzleTypes = new Set(['Puzzle', 'Password', 'Text Answer', 'Multiple Choice']);

async function graphFor(scenario: Scenario, version: ScenarioVersion, draftVersionId?: string): Promise<PuzzleContentItem[]> {
  const response = await scenarioApi.graph(version.id);
  return response.graph.nodes.filter((node) => puzzleTypes.has(node.node_type)).map((node) => ({ scenario, version, draftVersionId, node, graph: response.graph }));
}

export const puzzleContentApi = {
  catalog: () => api.get<NodeCatalogItem[]>('/scenario-node-catalog'),
  async snapshot(): Promise<PuzzleContentSnapshot> {
    const [catalog, scenarios] = await Promise.all([puzzleContentApi.catalog(), scenarioApi.list()]);
    const versionGroups = await Promise.all(scenarios.map(async (scenario) => ({ scenario, versions: await scenarioApi.versions(scenario.id) })));
    const items = (await Promise.all(versionGroups.flatMap(({ scenario, versions }) => { const draftVersionId = versions.find((version) => version.status === 'DRAFT')?.id; return versions.map((version) => graphFor(scenario, version, draftVersionId)); }))).flat();
    return { catalog, items };
  },
};
export { puzzleTypes };
