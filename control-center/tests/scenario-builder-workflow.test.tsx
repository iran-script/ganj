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
vi.mock('@/lib/queries', async (importOriginal) => ({ queries: { ...(await importOriginal<typeof import('@/lib/queries')>()).queries, checkpoints: vi.fn(() => ({ queryKey: ['checkpoints'], queryFn: async () => [] })) } }));
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
it('references an active Location Bank point without copying coordinates into the stage', async () => {
  vi.mocked(contentApi.locations).mockResolvedValue([{ id: '11111111-1111-4111-8111-111111111111', organization_id: 'org', name: 'North Point', latitude: 35.7, longitude: 51.4, default_radius_m: 40, accuracy_m: null, dwell_seconds: null, category: '', description: '', status: 'ACTIVE' }]);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select gps' }));
  const picker = await screen.findByRole('combobox', { name: 'انتخاب موقعیت از بانک مکان' });
  await userEvent.selectOptions(picker, '11111111-1111-4111-8111-111111111111');
  expect(picker).toHaveValue('11111111-1111-4111-8111-111111111111');
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await screen.findByText(/تغییرات ذخیره‌نشده/);
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const saved = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1] as ScenarioGraphResponse['graph'];
  const config = saved.nodes.find((node) => node.node_key === 'gps')!.config;
  expect(config).toMatchObject({ checkpoint_id: '11111111-1111-4111-8111-111111111111', radius_m: 20 });
  expect(config).not.toHaveProperty('latitude');
  expect(config).not.toHaveProperty('longitude');
});

it('keeps Location Bank policy on the reference while the stage owns its radius override', async () => {
  vi.mocked(contentApi.locations).mockResolvedValue([{ id: '22222222-2222-4222-8222-222222222222', organization_id: 'org', name: 'Saved square', latitude: 35.5, longitude: 51.5, default_radius_m: 30, accuracy_m: 5, dwell_seconds: 10, category: '', description: '', status: 'ACTIVE' }]);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select gps' }));
  await screen.findByRole('option', { name: 'Saved square' });
  const picker = screen.getByRole('combobox', { name: 'انتخاب موقعیت از بانک مکان' });
  await userEvent.selectOptions(picker, '22222222-2222-4222-8222-222222222222');
  expect(picker).toHaveValue('22222222-2222-4222-8222-222222222222');
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await screen.findByText(/تغییرات ذخیره‌نشده/);
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const saved = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1] as ScenarioGraphResponse['graph'];
  const config = saved.nodes.find((node) => node.node_key === 'gps')!.config;
  expect(config).toMatchObject({ checkpoint_id: '22222222-2222-4222-8222-222222222222', radius_m: 20, accuracy_m: 5, dwell_seconds: 10 });
  expect(config).not.toHaveProperty('latitude');
  expect(config).not.toHaveProperty('longitude');
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
  expect(screen.getByRole('option', { name: 'Saved trigger' })).toHaveProperty('selected', true);
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

it('renders auto layout controls, marks the draft dirty, restores positions, and saves unchanged graph semantics', async () => {
  mountBuilder();
  await screen.findByRole('button', { name: 'select start' });
  expect(screen.getByRole('button', { name: /مرتب‌سازی خودکار/ })).toBeInTheDocument();
  expect(screen.getByRole('combobox', { name: 'جهت چیدمان' })).toHaveValue('TREE_VERTICAL');
  await userEvent.selectOptions(screen.getByRole('combobox', { name: 'جهت چیدمان' }), 'TREE_HORIZONTAL');
  await userEvent.click(screen.getByRole('button', { name: /مرتب‌سازی خودکار/ }));
  expect(screen.getByText(/تغییرات ذخیره‌نشده/)).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: /بازگردانی چیدمان قبلی/ }));
  await userEvent.click(screen.getByRole('button', { name: /ذخیره Draft/ }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const saved = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1] as ScenarioGraphResponse['graph'];
  expect(saved.nodes.map((node) => node.node_key)).toEqual(graph.graph.nodes.map((node) => node.node_key));
  expect(saved.nodes.map((node) => node.config)).toEqual(graph.graph.nodes.map((node) => node.config));
  expect(saved.edges).toHaveLength(graph.graph.edges.length);
  expect(saved.edges[0]).toMatchObject(graph.graph.edges[0]);
});

it('edits Password and Text Answer nodes with the shared Backend puzzle schema', async () => {
  graph.graph.nodes = [
    { node_key: 'password', node_type: 'Password', title: 'کلمه عبور', config: { prompt: 'قدیمی', expected_answer: 'نکسو', case_sensitive: false, trim_whitespace: true, offline_submission_allowed: true, custom_policy: 'preserve' } },
    { node_key: 'answer', node_type: 'Text Answer', title: 'پاسخ متنی', config: { prompt: 'پاسخ بده', expected_answer: 'تهران', case_sensitive: true, trim_whitespace: true, hints: ['راهنما'] } },
  ];
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select password' }));
  fireEvent.change(screen.getByLabelText('صورت سؤال'), { target: { value: 'صورت جدید' } });
  fireEvent.change(screen.getByLabelText('پاسخ مورد انتظار'), { target: { value: 'پاسخ جدید' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  const saved = vi.mocked(scenarioApi.saveGraph).mock.calls[0][1];
  expect(saved.nodes).toEqual(expect.arrayContaining([expect.objectContaining({ node_key: 'password', node_type: 'Password', config: expect.objectContaining({ prompt: 'صورت جدید', expected_answer: 'پاسخ جدید', custom_policy: 'preserve', offline_submission_allowed: true }) })]));

  vi.clearAllMocks();
  cleanup();
  vi.mocked(scenarioApi.graph).mockResolvedValue(graph);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select answer' }));
  expect(screen.getByLabelText('راهنمایی 1')).toHaveValue('راهنما');
  fireEvent.change(screen.getByLabelText('صورت سؤال'), { target: { value: '' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  expect(screen.getByText('صورت سؤال الزامی است.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'ذخیره Draft' })).toBeDisabled();
});

it('edits Multiple Choice options and serializes the Backend options map', async () => {
  graph.graph.nodes = [{ node_key: 'choice', node_type: 'Multiple Choice', config: { prompt: 'انتخاب کن', options: { A: 'اول', B: 'دوم' }, correct_option_refs: ['A'], multi_select: false, offline_submission_allowed: false } }];
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select choice' }));
  fireEvent.change(screen.getAllByLabelText('متن گزینه')[1], { target: { value: 'دوم ویرایش‌شده' } });
  await userEvent.click(screen.getByRole('button', { name: 'اعمال تغییرات' }));
  await userEvent.click(screen.getByRole('button', { name: 'ذخیره Draft' }));
  await waitFor(() => expect(scenarioApi.saveGraph).toHaveBeenCalled());
  expect(vi.mocked(scenarioApi.saveGraph).mock.calls[0][1].nodes).toEqual(expect.arrayContaining([expect.objectContaining({ node_type: 'Multiple Choice', config: { prompt: 'انتخاب کن', options: { A: 'اول', B: 'دوم ویرایش‌شده' }, correct_option_refs: ['A'], multi_select: false, offline_submission_allowed: false } })]));
});

it('keeps all puzzle editor controls read-only for a published version', async () => {
  graph.graph.nodes = [{ node_key: 'password', node_type: 'Password', config: { prompt: 'قفل', expected_answer: 'پاسخ', case_sensitive: false, trim_whitespace: true } }];
  const published = { id: 'version', status: 'PUBLISHED', version_no: 1, revision_token: 'old-revision' } as ScenarioVersion;
  vi.mocked(scenarioApi.version).mockResolvedValue(published);
  vi.mocked(scenarioApi.versions).mockResolvedValue([published]);
  mountBuilder();
  await userEvent.click(await screen.findByRole('button', { name: 'select password' }));
  expect(screen.getByLabelText('صورت سؤال')).toBeDisabled();
  expect(screen.getByLabelText('پاسخ مورد انتظار')).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'اعمال تغییرات' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'ذخیره Draft' })).not.toBeInTheDocument();
});
