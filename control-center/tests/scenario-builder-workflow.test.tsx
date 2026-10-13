import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ScenarioGraphResponse, ScenarioVersion } from '@/types/scenario';
import { ScenarioBuilderScreen } from '@/components/scenario-builder';
import { scenarioApi } from '@/lib/scenario-api';
import { queries } from '@/lib/queries';
import { contentApi } from '@/lib/content-api';

vi.mock('@xyflow/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@xyflow/react')>();
  return { ...actual, ReactFlow: ({ nodes, onNodeClick }: { nodes: Array<{ id: string }>; onNodeClick: (event: null, node: { id: string }) => void }) => <div>{nodes.map((node) => <button key={node.id} onClick={() => onNodeClick(null, node)}>select {node.id}</button>)}</div> };
});
vi.mock('@/lib/scenario-api', () => ({ scenarioApi: { get: vi.fn(), versions: vi.fn(), version: vi.fn(), graph: vi.fn(), saveGraph: vi.fn(), validate: vi.fn(), publish: vi.fn(), simulate: vi.fn() } }));
vi.mock('@/lib/queries', async (importOriginal) => ({ queries: { ...(await importOriginal<typeof import('@/lib/queries')>()).queries, checkpoints: () => ({ queryKey: ['checkpoints'], queryFn: async () => [] }) } }));
vi.mock('@/lib/content-api', () => ({ contentApi: { qr: vi.fn(async () => []), locations: vi.fn(async () => []), media: vi.fn(async () => []) } }));

const validation = { status: 'PASS' as const, errors: [], warnings: [] };
let graph: ScenarioGraphResponse;
beforeEach(() => {
  vi.resetAllMocks();
  graph = { revision_token: 'old-revision', validation, graph: {
    nodes: [{ node_key: 'start', node_type: 'Start', config: {} }, { node_key: 'gps', node_type: 'GPS / Location', config: { latitude: 35, longitude: 51, radius_m: 50, required_player_count: 2 } }, { node_key: 'finish', node_type: 'Finish', config: {} }],
    edges: [{ edge_key: 'first', from_node: 'start', to_node: 'gps', priority: 7, condition_group_id: 'a355c253-9c45-49c4-b8c1-383c265b80f1', metadata: {} }, { edge_key: 'last', from_node: 'gps', to_node: 'finish', priority: 8 }], variables: [], conditions: [], subflows: [],
  } };
  const version = { id: 'version', status: 'DRAFT', version_no: 1, revision_token: 'old-revision' } as ScenarioVersion;
  vi.mocked(scenarioApi.get).mockResolvedValue({ id: 'scenario', title: 'Verification' } as Awaited<ReturnType<typeof scenarioApi.get>>);
  vi.mocked(scenarioApi.versions).mockResolvedValue([version]);
  vi.mocked(scenarioApi.version).mockResolvedValue(version);
  vi.mocked(scenarioApi.graph).mockResolvedValue(graph);
  vi.mocked(scenarioApi.saveGraph).mockImplementation(async (_, body) => ({ ...graph, graph: body as ScenarioGraphResponse['graph'], revision_token: 'saved-revision' }));
  vi.mocked(scenarioApi.validate).mockResolvedValue(validation);
  vi.mocked(scenarioApi.publish).mockResolvedValue({ ...version, status: 'PUBLISHED' });
  vi.mocked(scenarioApi.simulate).mockResolvedValue({ ...validation, mode: 'STRUCTURAL_ONLY', runtime_executed: false, revision_token: 'saved-revision', reachable_nodes: [], branches: [], dead_ends: [] });
});
afterEach(cleanup);

it('copies bank coordinates and policy into an independent GPS snapshot', async () => {
  vi.mocked(contentApi.locations).mockResolvedValue([{ id: 'location', organization_id: 'org', name: 'Saved square', latitude: 35.5, longitude: 51.5, default_radius_m: 30, accuracy_m: 5, dwell_seconds: 10, category: '', description: '', status: 'ACTIVE' }]);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select gps' }));
  await screen.findByRole('option', { name: 'Saved square' });
  fireEvent.change(screen.getByLabelText('انتخاب از نقاط ذخیره‌شده'), { target: { value: 'location' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const saved = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1] as ScenarioGraphResponse['graph'];
  const config = saved.nodes.find((node) => node.node_key === 'gps')!.config;
  expect(config).toMatchObject({ latitude: 35.5, longitude: 51.5, radius_m: 30, accuracy_m: 5, dwell_seconds: 10 });
  expect(config).not.toHaveProperty('checkpoint_id');
});

it('searches the grouped palette and creates an advanced node', async () => {
  mountBuilder();
  await screen.findByRole('button', { name: 'select start' });
  fireEvent.change(screen.getByLabelText('جستجوی گره'), { target: { value: 'Time' } });
  await userEvent.click(screen.getByRole('button', { name: 'زمان' }));
  expect(screen.getByLabelText('حالت زمان')).toHaveValue('DELAY');
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  expect(vi.mocked(scenarioApi.saveGraph).mock.calls[0][1].nodes).toEqual(expect.arrayContaining([expect.objectContaining({ node_type: 'Time', config: { mode: 'DELAY', duration_seconds: 30 } })]));
});

it('retains the saved QR selection when trigger options load after the inspector opens', async () => {
  const user = userEvent.setup();
  const triggerId = 'a355c253-9c45-49c4-b8c1-383c265b80f1';
  type Triggers = Awaited<ReturnType<ReturnType<typeof queries.qrTriggers>['queryFn']>>;
  let resolveTriggers!: (value: Triggers) => void;
  const response = new Promise<Triggers>((resolve) => { resolveTriggers = resolve; });
  vi.mocked(contentApi.qr).mockReturnValue(response);
  graph.graph.nodes.push({ node_key: 'qr', node_type: 'QR', config: { trigger_id: triggerId } });
  mountBuilder();
  await user.click(await screen.findByRole('button', { name: 'select qr' }));
  resolveTriggers([{ id: triggerId, organization_id: 'organization', status: 'ACTIVE', name: 'Saved trigger', label: 'Saved trigger', location_description: '', notes: '', usage_count: 0 }]);
  await screen.findByRole('option', { name: 'Saved trigger' });
  expect(screen.getByRole('combobox')).toHaveValue(triggerId);
  await user.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await user.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  expect(vi.mocked(scenarioApi.saveGraph).mock.calls[0][1].nodes.at(-1)).toMatchObject({ config: { trigger_id: triggerId } });
});

function mountBuilder() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><ScenarioBuilderScreen scenarioId="scenario" /></QueryClientProvider>);
}

it('saves typed GPS coordinates and preserves existing policy and edge conditions', async () => {
  const user = userEvent.setup();
  mountBuilder();
  await user.click(await screen.findByRole('button', { name: 'select gps' }));
  fireEvent.change(screen.getByLabelText('عرض جغرافیایی'), { target: { value: '35.6892' } });
  fireEvent.change(screen.getByLabelText('طول جغرافیایی'), { target: { value: '51.389' } });
  fireEvent.change(screen.getByLabelText('شعاع مجاز (متر)'), { target: { value: '25' } });
  await user.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await user.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const payload = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1];
  expect(payload.nodes[1]).toMatchObject({ config: { latitude: 35.6892, longitude: 51.389, radius_m: 25, required_player_count: 2 } });
  expect(payload.edges[0]).toEqual(graph.graph.edges[0]);
});

it('uses the saved revision for validation simulation and publish', async () => {
  const user = userEvent.setup();
  mountBuilder();
  await user.click(await screen.findByRole('button', { name: 'select start' }));
  await user.type(screen.getByLabelText('عنوان مرحله'), 'Updated');
  await user.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(screen.getByRole('button', { name: 'انتشار نسخه جدید' })).toBeDisabled();
  await user.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await screen.findByText('پیش‌نویس ذخیره شد.');
  await user.click(screen.getByRole('button', { name: 'اعتبارسنجی' }));
  await waitFor(() => expect(scenarioApi.validate).toHaveBeenCalledWith('version', 'saved-revision'));
  await user.click(screen.getByRole('button', { name: 'شبیه‌سازی' }));
  await waitFor(() => expect(scenarioApi.simulate).toHaveBeenCalledWith('version', 'saved-revision'));
  await user.click(screen.getByRole('button', { name: 'انتشار نسخه جدید' }));
  await user.click(screen.getByRole('button', { name: 'انتشار نسخه' }));
  await waitFor(() => expect(scenarioApi.publish).toHaveBeenCalledWith('version', 'saved-revision'));
});

vi.mock('@/components/gis/lazy-map', () => ({ LazyMap: (props: import('@/components/gis/nexo-map').NexoMapProps) => <div><output data-testid="gps-map-state">{JSON.stringify(props.locations)}</output><button type="button" disabled={!props.editable} onClick={() => props.onMove?.({ latitude: 36.25, longitude: 52.75 })}>test map move</button></div> }));

it('keeps the GPS map and direct fields synchronized and saves an independent config', async () => {
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select gps' }));
  await userEvent.click(screen.getByRole('button', { name: 'انتخاب روی نقشه' }));
  expect(screen.getByTestId('gps-map-state')).toHaveTextContent('"latitude":35');
  await userEvent.click(screen.getByRole('button', { name: 'test map move' }));
  expect(screen.getByLabelText('عرض جغرافیایی')).toHaveValue(36.25);
  expect(screen.getByLabelText('طول جغرافیایی')).toHaveValue(52.75);
  fireEvent.change(screen.getByLabelText('شعاع مجاز (متر)'), { target: { value: '75' } });
  expect(screen.getByTestId('gps-map-state')).toHaveTextContent('"radius_m":75');
  fireEvent.change(screen.getByLabelText('عرض جغرافیایی'), { target: { value: '91' } });
  expect(screen.getByTestId('gps-map-state')).toHaveTextContent('[]');
  fireEvent.change(screen.getByLabelText('عرض جغرافیایی'), { target: { value: '36.5' } });
  expect(screen.getByTestId('gps-map-state')).toHaveTextContent('"latitude":36.5');
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  expect((vi.mocked(scenarioApi.saveGraph).mock.calls[0][1] as ScenarioGraphResponse['graph']).nodes.find(node => node.node_key === 'gps')!.config).toMatchObject({ latitude: 36.5, longitude: 52.75, radius_m: 75 });
});

it('keeps a published GPS map read-only', async () => {
  vi.mocked(scenarioApi.version).mockResolvedValue({ id: 'version', status: 'PUBLISHED', version_no: 1, revision_token: 'old-revision' } as ScenarioVersion);
  vi.mocked(scenarioApi.versions).mockResolvedValue([{ id: 'version', status: 'PUBLISHED', version_no: 1, revision_token: 'old-revision' } as ScenarioVersion]);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select gps' }));
  await userEvent.click(screen.getByRole('button', { name: 'انتخاب روی نقشه' }));
  expect(screen.getByRole('button', { name: 'test map move' })).toBeDisabled();
  expect(screen.getByLabelText('عرض جغرافیایی')).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'ذخیره Draft' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'انتشار نسخه جدید' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'اعمال تغییرات' })).not.toBeInTheDocument();
  expect(screen.getByText(/Published · قفل‌شده/)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'تاریخچه نسخه‌ها' })).toHaveAttribute('href', '/control/scenarios/scenario/versions');
});

it('selects the editable draft even when a published version is listed first', async () => {
  vi.mocked(scenarioApi.versions).mockResolvedValue([
    { id: 'published', status: 'PUBLISHED', version_no: 1 } as ScenarioVersion,
    { id: 'version', status: 'DRAFT', version_no: 2 } as ScenarioVersion,
  ]);
  mountBuilder();
  await screen.findByRole('button', { name: 'ذخیره Draft' });
  expect(scenarioApi.version).toHaveBeenCalledWith('version');
  expect(screen.getByText(/Draft · در حال ویرایش/)).toBeInTheDocument();
});
