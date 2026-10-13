import { ScenarioBuilderScreen } from '@/components/lazy-scenario-builder';
export default async function ScenarioBuilderPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<{ version?: string }> }) { const { id } = await params; const { version } = await searchParams; return <ScenarioBuilderScreen scenarioId={id} versionId={version} />; }
